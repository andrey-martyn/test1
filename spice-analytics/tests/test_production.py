"""Production invariants with synthetic customers and disposable credentials."""
import asyncio
from copy import deepcopy
import json
import os
from pathlib import Path
import secrets
import sqlite3
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from auth import Auth, set_password
import sync_mysql as sync
from deploy_hosting import prepare


def snapshot():
    rows = {table:{} for table in sync.TABLES}
    order = dict.fromkeys(sync.FIELDS['oc_order'], '')
    order.update(order_id='1', customer_id='1', firstname='Тест', lastname='Покупатель',
                 email='test-buyer@example.invalid', telephone='', total='100', currency_code='UAH',
                 currency_value='1', order_status_id='2', date_added='2026-10-19 12:00:00',
                 date_modified='2026-10-19 12:00:00', shipping_city='Київ', shipping_country='Україна')
    rows['oc_order']['1'] = order
    rows['oc_order_product']['1'] = dict(order_product_id='1', order_id='1', product_id='7',
                                         name='Тестовый товар', model='test', quantity='2', price='50', total='100')
    rows['oc_order_option']['1'] = dict(order_option_id='1', order_id='1', order_product_id='1', name='Фасовка', value='200 г')
    rows['oc_order_status']['2:1'] = dict(order_status_id='2', language_id='1', name='В обработке')
    return rows


class AuthTests(unittest.TestCase):
    def test_sessions_survive_process_and_password_change_revokes_them(self):
        with tempfile.TemporaryDirectory() as t:
            directory = Path(t);password = secrets.token_urlsafe(24)
            set_password(directory/'access.json', password)
            a = Auth(directory, directory/'access.json')
            cookie = a.login(password, 'test-client')
            self.assertTrue(Auth(directory, directory/'access.json').valid(cookie))
            with sqlite3.connect(directory/'sessions.sqlite3') as con:
                self.assertNotIn(cookie, str(con.execute('SELECT * FROM sessions').fetchall()))
            set_password(directory/'access.json', secrets.token_urlsafe(24))
            self.assertFalse(a.valid(cookie))
            self.assertEqual((directory/'access.json').stat().st_mode & 0o777, 0o600)

    def test_login_throttle_is_persistent_and_logout_revokes(self):
        with tempfile.TemporaryDirectory() as t:
            directory = Path(t);password = secrets.token_urlsafe(24)
            set_password(directory/'access.json', password)
            a = Auth(directory, directory/'access.json')
            for _ in range(10):
                with self.assertRaisesRegex(PermissionError, '^password$'):
                    Auth(directory, directory/'access.json').login('incorrect-password', 'test-client')
            with self.assertRaisesRegex(PermissionError, '^rate_limit$'):
                a.login(password, 'test-client')
            cookie = a.login(password, 'another-client')
            a.logout(cookie)
            self.assertFalse(a.valid(cookie))


class SyncTests(unittest.TestCase):
    def test_atomic_reconciliation_preserves_crm_and_report_reloads(self):
        import app
        from analytics import Analytics
        with tempfile.TemporaryDirectory() as t:
            database = Path(t)/'analytics.sqlite3';rows = snapshot()
            sync.apply_snapshot(database, rows, 'verified_two_reads')
            with sqlite3.connect(database) as con:
                con.execute("INSERT INTO settings VALUES ('keep','yes')")
                con.execute("INSERT INTO manual_links VALUES ('1','2','test')")
                con.execute('CREATE TABLE customer_actions (customer_id TEXT PRIMARY KEY,payload TEXT)')
                con.execute('INSERT INTO customer_actions VALUES (?,?)', ('1', json.dumps({'stage':'do_not_contact'})))
                con.execute('CREATE TABLE ga4_records (kind TEXT,record_key TEXT,payload TEXT)')
                con.execute("INSERT INTO ga4_records VALUES ('keep','keep','{}')")
            with patch.multiple(app, DATABASE=database, store=None, store_revision=None):
                first = app.get_store()
                self.assertEqual(first.orders['1']['total'], '100')
                rows['oc_order']['1']['total'] = '150'
                rows['oc_order_product']['1']['total'] = '150'
                sync.apply_snapshot(database, rows, 'verified_two_reads')
                self.assertEqual(app.get_store().orders['1']['total'], '150')
                self.assertIsNot(first, app.get_store())
                # A different worker/CLI can edit CRM while this worker has a cached report.
                from db_state import bump_revision
                with sqlite3.connect(database) as con:
                    con.execute('UPDATE customer_actions SET payload=? WHERE customer_id=?', (json.dumps({'stage':'planned'}),'1'))
                    bump_revision(con)
                self.assertEqual(app.get_store().actions['1']['stage'],'planned')
                with sqlite3.connect(database) as con:
                    con.execute('UPDATE customer_actions SET payload=? WHERE customer_id=?', (json.dumps({'stage':'do_not_contact'}),'1'))
                    bump_revision(con)
                unchanged = sync.apply_snapshot(database, rows, 'verified_two_reads')
                self.assertFalse(unchanged['changed'])
            with sqlite3.connect(database) as con:
                self.assertEqual(con.execute("SELECT value FROM settings WHERE key='keep'").fetchone()[0], 'yes')
                self.assertEqual(con.execute('SELECT count(*) FROM manual_links').fetchone()[0], 1)
                self.assertIn('do_not_contact', con.execute('SELECT payload FROM customer_actions').fetchone()[0])
                self.assertEqual(con.execute('SELECT count(*) FROM ga4_records').fetchone()[0], 1)
                self.assertEqual(con.execute('SELECT count(*) FROM imports').fetchone()[0], 2)
            invalid = deepcopy(rows);invalid['oc_order_option']['1']['order_product_id'] = '999'
            with self.assertRaises(sync.SyncError):
                sync.apply_snapshot(database, invalid, 'verified_two_reads')
            self.assertEqual(Analytics(database).orders['1']['total'], '150')

    def test_failed_source_keeps_last_good_copy_and_safe_error(self):
        with tempfile.TemporaryDirectory() as t:
            database = Path(t)/'analytics.sqlite3';sync.apply_snapshot(database, snapshot(), 'verified_two_reads')
            with patch.object(sync, 'load_config', return_value={}), patch.object(sync, 'connect_source', side_effect=RuntimeError('SECRET_PASSWORD_AND_HOST')):
                with self.assertRaises(sync.SyncError):
                    sync.run(Path(t)/'source.json', database)
            state = sync.sync_status(database)
            self.assertEqual(state['state'], 'error')
            self.assertIn('last_success', state)
            self.assertNotIn('SECRET', json.dumps(state))
            with sqlite3.connect(database) as con:
                self.assertEqual(con.execute("SELECT count(*) FROM raw_records WHERE table_name='oc_order'").fetchone()[0], 1)

    def test_nontransactional_source_retries_unstable_snapshots(self):
        class Cursor:
            def __enter__(self): return self
            def __exit__(self, *_): pass
            def execute(self, *_): pass
            def fetchall(self): return [{'TABLE_NAME':t, 'ENGINE':'MyISAM'} for t in sync.TABLES]
        class Connection:
            def cursor(self): return Cursor()
        a = snapshot();b = deepcopy(a);b['oc_order']['1']['total'] = '101'
        with patch.object(sync, 'read_snapshot', side_effect=[a,b,b,b]) as read:
            result, consistency = sync.stable_snapshot(Connection(), {'database':'test'}, delay=0)
            self.assertEqual(read.call_count, 4)
            self.assertEqual(result['oc_order']['1']['total'], '101')
            self.assertEqual(consistency, 'verified_two_reads')
        with patch.object(sync, 'read_snapshot', side_effect=[a,b]*3):
            with self.assertRaises(sync.SyncError):
                sync.stable_snapshot(Connection(), {'database':'test'}, delay=0)

    def test_identifier_injection_and_remote_tls_are_rejected(self):
        with tempfile.TemporaryDirectory() as t:
            path = Path(t)/'source.json';path.write_text(json.dumps({'prefix':'oc_;DROP TABLE x'}))
            with self.assertRaises(sync.SyncError):sync.load_config(path)
        with patch.object(sync,'required_tls_connection') as connect:
            connect.return_value.cursor.return_value.__enter__.return_value.fetchone.return_value = {'Value':''}
            with self.assertRaises(sync.SyncError):
                sync.connect_source({'host':'db.example.invalid','database':'test','user':'readonly','password':'test'})
            context = connect.call_args.kwargs['ssl']
            self.assertTrue(context.check_hostname)
            import ssl
            self.assertEqual(context.verify_mode, ssl.CERT_REQUIRED)


class HostingTests(unittest.TestCase):
    def test_supported_wsgi_runner_uses_https_and_persistent_login(self):
        import app
        import httpx
        from wsgi_entry import application
        with tempfile.TemporaryDirectory() as t:
            data=Path(t);database=data/'analytics.sqlite3';sync.apply_snapshot(database,snapshot(),'verified_two_reads')
            password=secrets.token_urlsafe(24);set_password(data/'access.json',password)
            with patch.multiple(app,DATA=data,DATABASE=database,AUTH_FILE=data/'access.json',
                                PUBLIC_URL='https://analytics.example.invalid',store=None,store_revision=None):
                with httpx.Client(transport=httpx.WSGITransport(app=application),base_url='https://analytics.example.invalid') as client:
                    self.assertEqual(client.get('/api/meta').status_code,401)
                    self.assertEqual(client.post('/api/login',json={'password':password}).status_code,200)
                    self.assertTrue(client.get('/api/meta').json()['names_visible'])
                    response=client.get('/api/report',params={'year':2026,'as_of':'2026-10-20','currency':'UAH'})
                    self.assertEqual(response.status_code,200)
                    self.assertEqual(response.json()['metrics']['order_count'],1)

    def test_private_backup_is_consistent_and_contains_crm(self):
        from backup import backup
        with tempfile.TemporaryDirectory() as t:
            directory=Path(t);data=directory/'data';database=data/'analytics.sqlite3'
            sync.apply_snapshot(database,snapshot(),'verified_two_reads')
            set_password(data/'access.json',secrets.token_urlsafe(24))
            with sqlite3.connect(database) as con:
                con.execute('CREATE TABLE customer_actions (customer_id TEXT PRIMARY KEY,payload TEXT)')
                con.execute("INSERT INTO customer_actions VALUES ('1','{}')")
            destination=backup(data,directory/'backups')
            with sqlite3.connect(destination/'analytics.sqlite3') as con:
                self.assertEqual(con.execute('PRAGMA quick_check').fetchone()[0],'ok')
                self.assertEqual(con.execute('SELECT count(*) FROM customer_actions').fetchone()[0],1)
            self.assertTrue((destination/'access.json').exists())
            self.assertFalse((destination/'sessions.sqlite3').exists())
            self.assertEqual(destination.stat().st_mode & 0o777,0o700)

    def test_cold_cgi_login_real_names_https_and_host_validation(self):
        with tempfile.TemporaryDirectory() as t:
            directory=Path(t);data=directory/'private';public=directory/'public'
            prepare(public,data,'https://analytics.example.invalid')
            sync.apply_snapshot(data/'analytics.sqlite3',snapshot(),'verified_two_reads')
            password=secrets.token_urlsafe(24);set_password(data/'access.json',password)
            def request(path, body=None, cookie=None, **extra):
                encoded=json.dumps(body).encode() if body is not None else b''
                environment={**os.environ,'HTTPS':'on','HTTP_HOST':'analytics.example.invalid',
                             'REQUEST_METHOD':'POST' if body is not None else 'GET',
                             'REQUEST_URI':path,'QUERY_STRING':path.partition('?')[2],
                             'CONTENT_TYPE':'application/json','CONTENT_LENGTH':str(len(encoded)),
                             'REMOTE_ADDR':'test-client'}
                if cookie:environment['HTTP_COOKIE']=cookie
                environment.update(extra)
                process=subprocess.run([str(public/'analytics.cgi')],input=encoded,env=environment,capture_output=True,timeout=40)
                self.assertEqual(process.returncode,0,process.stderr.decode())
                header,_,payload=process.stdout.partition(b'\r\n\r\n')
                return header.decode(),payload
            headers,_=request('/api/meta');self.assertIn('Status: 401',headers)
            self.assertIn('no-store',headers)
            headers,_=request('/api/login',{'password':password})
            self.assertIn('Status: 200',headers);self.assertIn('Secure',headers);self.assertIn('HttpOnly',headers)
            cookie=next(line.split(': ',1)[1].split(';')[0] for line in headers.splitlines() if line.lower().startswith('set-cookie:'))
            headers,payload=request('/api/meta',cookie=cookie)
            self.assertIn('Status: 200',headers);self.assertTrue(json.loads(payload)['names_visible'])
            headers,payload=request('/api/report?year=2026&as_of=2026-10-20&currency=UAH',cookie=cookie)
            self.assertIn('Status: 200',headers)
            self.assertEqual(json.loads(payload)['customers'][0]['name'],'Тест Покупатель')
            headers,_=request('/api/meta',cookie=cookie,HTTP_HOST='evil.example.invalid')
            self.assertIn('Status: 400',headers)
            headers,_=request('/api/logout',{},cookie,HTTP_ORIGIN='https://evil.example.invalid')
            self.assertIn('Status: 403',headers)
            headers,_=request('/api/meta',cookie=cookie,HTTPS='off',REQUEST_SCHEME='http')
            self.assertIn('Status: 307',headers)
            headers,_=request('/api/logout',{},cookie)
            self.assertIn('Status: 200',headers)
            headers,_=request('/api/meta',cookie=cookie);self.assertIn('Status: 401',headers)

    def test_installer_never_overwrites_existing_shop(self):
        with tempfile.TemporaryDirectory() as t:
            directory=Path(t);shop=directory/'public';shop.mkdir();(shop/'index.php').write_text('shop')
            with self.assertRaises(ValueError):prepare(shop,directory/'private','https://analytics.example.invalid')
            self.assertEqual((shop/'index.php').read_text(),'shop')
            self.assertEqual(len(list(shop.iterdir())),1)
            with self.assertRaises(ValueError):prepare(directory/'new',directory/'new/data','https://analytics.example.invalid')


if __name__ == '__main__':
    unittest.main(verbosity=2)

import json
from pathlib import Path
import secrets
import shutil
import sqlite3
import sys
import tempfile
import unittest
from decimal import Decimal

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT))
from import_dump import literal_rows, statements, import_dump
from analytics import Analytics, email_key, phone_key, packaging, DSU

class ParserTests(unittest.TestCase):
    def test_escapes_null_and_semicolons(self):
        text = "-- no code;\nINSERT INTO `x` (`a`,`b`,`c`) VALUES ('O\\'Brien; кофе',NULL,'NULL'),('a''b',12.34,'line\\nnext');"
        stmts = list(statements(text))
        self.assertEqual(len(stmts),1)
        rows = list(literal_rows(stmts[0].split('VALUES ',1)[1]))
        self.assertEqual(rows,[["O'Brien; кофе",None,'NULL'],["a'b",'12.34','line\nnext']])

    def test_expressions_are_rejected(self):
        with self.assertRaises(ValueError):
            list(literal_rows("(LOAD_FILE('/etc/passwd'))"))

    def test_incomplete_snapshot_cannot_replace_database(self):
        with tempfile.TemporaryDirectory() as t:
            p=Path(t);db=p/'db.sqlite3';db.write_bytes(b'unchanged')
            dump=p/'bad.sql';dump.write_text('DROP TABLE oc_order; CREATE TABLE `oc_order` (`order_id` int);')
            with self.assertRaises(ValueError):
                import_dump(dump,db)
            self.assertEqual(db.read_bytes(),b'unchanged')

    def test_parameterized_repeatable_import_ignores_sql_commands(self):
        with tempfile.TemporaryDirectory() as t:
            p=Path(t);dump=p/'input.sql';db=p/'db.sqlite3'
            dump.write_text('''DROP TABLE settings;
CREATE TABLE `oc_order` (`order_id` int);
CREATE TABLE `oc_order_product` (`order_product_id` int);
CREATE TABLE `oc_order_option` (`order_option_id` int);
CREATE TABLE `oc_order_total` (`order_total_id` int);
CREATE TABLE `oc_order_status` (`order_status_id` int);
INSERT INTO `oc_order` (`order_id`,`email`) VALUES (1,'x@example.org');
INSERT INTO `oc_order_product` (`order_product_id`,`order_id`) VALUES (2,1);
INSERT INTO `oc_order_status` (`order_status_id`,`language_id`,`name`) VALUES (2,1,'В обработке');
INSERT INTO `oc_user` (`password`) VALUES ('MUST_NOT_IMPORT');
''')
            a=import_dump(dump,db)
            with sqlite3.connect(db) as con:
                con.execute("INSERT INTO settings VALUES ('keep','yes')")
            b=import_dump(dump,db)
            self.assertEqual(a,b)
            with sqlite3.connect(db) as con:
                self.assertEqual(con.execute('SELECT count(*) FROM raw_records').fetchone()[0],3)
                self.assertEqual(con.execute("SELECT value FROM settings WHERE key='keep'").fetchone()[0],'yes')

class BusinessRulesTests(unittest.TestCase):
    def test_phone_and_placeholder_email(self):
        self.assertEqual(phone_key('+38 (096) 123-45-67'),phone_key('0961234567'))
        self.assertEqual(email_key('nomail@shop.example'),'')
        self.assertEqual(email_key(' Person@Example.org '),'person@example.org')

    def test_weight_is_only_from_pack_option(self):
        self.assertEqual(packaging([{'name':'Фасовка','value':'0,5 кг'}])['grams'],500)
        self.assertEqual(packaging([{'name':'Фасовка','value':'1 шт.'}])['unit'],'pieces')
        self.assertIsNone(packaging([{'name':'Фасовка','value':'1 шт.'}])['grams'])
        self.assertIsNone(packaging([{'name':'Дозировка','value':'500 г'}])['grams'])
        self.assertIsNone(packaging([{'name':'Фасовка','value':'200 г'},{'name':'Фасовка','value':'50 г'}])['grams'])

    def test_currency_uses_saved_order_rate(self):
        self.assertEqual(Analytics.order_amount({'total':'100.1234','currency_value':'1.5'}),Decimal('150.18510'))

    def test_identity_does_not_merge_on_email_alone(self):
        s=object.__new__(Analytics);s.manual_links=[]
        orders=[{'order_id':'1','customer_id':'0','email':'a@example.org','telephone':'0961234567'},
                {'order_id':'2','customer_id':'0','email':'a@example.org','telephone':'0969876543'},
                {'order_id':'3','customer_id':'0','email':'A@example.org','telephone':'+380961234567'},
                {'order_id':'4','customer_id':'0','email':'nomail@shop.example','telephone':''}]
        dsu=s.identity(orders)
        self.assertEqual(dsu.find('1'),dsu.find('3'))
        self.assertNotEqual(dsu.find('1'),dsu.find('2'))
        self.assertNotEqual(dsu.find('1'),dsu.find('4'))

class ActualDatasetTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.s=Analytics(ROOT/'data'/'analytics.sqlite3')

    def test_independent_sum_and_weeks(self):
        from datetime import date
        r=self.s.report(2026,'2026-10-20','UAH')
        eligible=[o for o in self.s.orders.values() if o['order_status_id'] in ['2','3'] and o['date_added'][:10]<='2026-10-20' and date.fromisoformat(o['date_added'][:10]).year==2026]
        expected=sum((Decimal(o['total'])*Decimal(o['currency_value']) for o in eligible),Decimal(0))
        self.assertAlmostEqual(r['metrics']['amount'],float(expected),places=2)
        self.assertEqual(r['metrics']['order_count'],len(eligible))
        self.assertEqual(sum(w['count'] for w in r['weekly'].values()),len(eligible))
        self.assertAlmostEqual(sum(c['amount'] for c in r['customers']),r['metrics']['amount'],places=2)

    def test_six_half_kilo_packs_are_three_kilos(self):
        o=self.s.order_details('4923')
        self.assertEqual(o['amount'],3510)
        self.assertEqual(len(o['lines']),1)
        self.assertEqual(o['lines'][0]['quantity'],6)
        self.assertEqual(o['lines'][0]['pack']['grams']*o['lines'][0]['quantity'],3000)

    def test_iso_year_and_asof_no_future(self):
        r=self.s.report(2021,'2021-03-10','UAH')
        for c in r['customers']:
            self.assertLessEqual(c['last_order'],'2021-03-10')
        # ISO 2026 has 53 weeks; the calendar must retain week 53.
        self.assertEqual(self.s.report(2026,'2026-10-20','UAH')['week_count'],53)

class ApiTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        import app as module
        from fastapi.testclient import TestClient
        cls.module=module;cls.temp=tempfile.TemporaryDirectory();directory=Path(cls.temp.name)
        shutil.copy2(ROOT/'data'/'analytics.sqlite3',directory/'analytics.sqlite3')
        module.DATA=directory;module.DATABASE=directory/'analytics.sqlite3';module.AUTH_FILE=directory/'access.json';module.store=None
        cls.password=secrets.token_urlsafe(24);module.set_password(cls.password)
        cls.client=TestClient(module.app)

    @classmethod
    def tearDownClass(cls):
        cls.client.close();cls.temp.cleanup()

    def login(self):
        self.assertEqual(self.client.post('/api/login',json={'password':self.password}).status_code,200)

    def test_access_and_origin(self):
        self.client.cookies.clear()
        self.assertEqual(self.client.get('/api/meta').status_code,401)
        self.assertEqual(self.client.get('/',follow_redirects=False).status_code,303)
        self.assertEqual(self.client.post('/api/login',json={'password':self.password},headers={'Origin':'https://other.example'}).status_code,403)
        self.login()
        self.assertEqual(self.client.get('/api/meta').status_code,200)

    def test_settings_and_reversible_merge(self):
        self.login();params={'year':2026,'as_of':'2026-10-20','currency':'UAH'}
        before=self.client.get('/api/report',params=params).json()
        ids=[m['id'] for m in before['duplicates'][0]['members']]
        self.assertEqual(self.client.post('/api/identity/merge',json={'ids':ids,'as_of':'2026-10-20'}).status_code,200)
        merged=self.client.get('/api/report',params=params).json()
        self.assertLess(len(merged['customers']),len(before['customers']))
        self.assertEqual(merged['metrics']['amount'],before['metrics']['amount'])
        cid=next(c['id'] for c in merged['customers'] if c['manual'])
        self.assertEqual(self.client.post('/api/identity/unmerge',json={'id':cid,'as_of':'2026-10-20'}).status_code,200)
        after=self.client.get('/api/report',params=params).json()
        self.assertEqual(len(after['customers']),len(before['customers']))
        self.assertEqual(self.client.put('/api/settings',json={'interval_days':0,'grace_days':7,'minimum_orders':3,'status_ids':['2']}).status_code,422)
        settings=self.client.get('/api/meta').json()['settings'];settings['interval_days']=45
        self.assertEqual(self.client.put('/api/settings',json=settings).status_code,200)
        self.assertEqual(self.client.get('/api/meta').json()['settings']['interval_days'],45)
        self.assertEqual(self.client.get('/api/orders/4923').json()['lines'][0]['quantity'],6)

    def test_followup_contact_controls(self):
        self.login();params={'year':2026,'as_of':'2026-10-20','currency':'UAH'}
        report=self.client.get('/api/report',params=params).json()
        cid=next(c['id'] for c in report['customers'] if c['lifetime_count']>=5 and c['followup_eligible'])
        def update(stage,contact=None,next_date=None):
            self.assertEqual(self.client.put(f'/api/customers/{cid}/action',json={'stage':stage,'contact_date':contact,'next_contact':next_date,'note':'Private test note'}).status_code,200)
            data=self.client.get('/api/report',params=params).json()
            return next(c for c in data['customers'] if c['id']==cid)
        self.assertFalse(update('planned',next_date='2026-11-01')['followup_eligible'])
        self.assertFalse(update('do_not_contact')['followup_eligible'])
        self.assertFalse(update('declined')['followup_eligible'])
        self.assertFalse(update('contacted',contact='2026-10-19')['followup_eligible'])
        self.assertTrue(update('contacted',contact='2026-01-01')['followup_eligible'])
        self.assertTrue(update('new')['followup_eligible'])

if __name__=='__main__':
    unittest.main(verbosity=2)

"""Real MySQL 8.4: read-only grants, MyISAM/InnoDB, changes/deletes, CRM survival.

Requires Docker only in development; production hosting does not use Docker.
The container contains synthetic data and is always removed.
"""
from copy import deepcopy
import json
import os
PHP_IMAGE = os.environ.get('ANALYTICS_PHP_IMAGE', 'lavka-php-dev')
from pathlib import Path
import secrets
import sqlite3
import subprocess
import sys
import tempfile
import time
from datetime import datetime, timedelta, timezone
import ipaddress
from cryptography import x509
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import rsa
from cryptography.x509.oid import NameOID

ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT));sys.path.insert(0,str(ROOT/'tests'))
from test_production import snapshot
import pymysql
import sync_mysql as sync

image='mysql@sha256:0f51e63e537a1f176423fa03ef559aabe6fa4372a53160a44bc7e75da7d15364'
name='lavka-mysql-check-'+secrets.token_hex(5)
root_password=secrets.token_urlsafe(24);reader_password=secrets.token_urlsafe(24)
runtime=os.environ.get('ANALYTICS_TEST_RUNTIME','python')
def run_sync(config_file,database):
    if runtime!='php':return sync.run(config_file,database)
    result=subprocess.run(['docker','run','--rm','--network','host','--user',f'{os.getuid()}:{os.getgid()}',
                           '-v',str(ROOT)+':/app:ro','-v',str(config_file.parent)+':/test-data',PHP_IMAGE,
                           'php','-d','memory_limit=256M','php/bin/cron.php','--data','/test-data','--config','/test-data/source.json'],capture_output=True,text=True,timeout=60)
    if result.returncode:
        # Only report error codes from a synthetic disposable DB, never credentials.
        diagnostic=subprocess.run(['docker','run','--rm','--network','host','--user',f'{os.getuid()}:{os.getgid()}',
            '-v',str(ROOT)+':/app:ro','-v',str(config_file.parent)+':/test-data',PHP_IMAGE,'php','-r',
            'require "php/src/Sync.php"; try {\\Lavka\\source_connect(\\Lavka\\source_config("/test-data/source.json")); echo "connected";} catch (PDOException $e) {echo json_encode(["sqlstate"=>$e->getCode(),"driver_code"=>$e->errorInfo[1]??null]);}'],capture_output=True,text=True)
        raise RuntimeError('PHP MySQL sync failed: '+result.stderr[:200]+'; diagnostic='+diagnostic.stdout[:200])
    return json.loads(result.stdout)
with tempfile.TemporaryDirectory() as t:
    directory=Path(t);environment=directory/'mysql.env'
    environment.write_text('MYSQL_ROOT_PASSWORD='+root_password+'\nMYSQL_ROOT_HOST=%\nMYSQL_DATABASE=store_test\n')
    environment.chmod(0o600)
    subprocess.run(['docker','run','-d','--rm','--name',name,'--memory','1g','--cpus','1',
                    '--env-file',str(environment),'-p','127.0.0.1::3306',image],check=True,stdout=subprocess.DEVNULL)
    try:
        port=int(subprocess.check_output(['docker','inspect','--format','{{(index (index .NetworkSettings.Ports "3306/tcp") 0).HostPort}}',name],text=True).strip())
        for _ in range(120):
            try:
                admin=pymysql.connect(host='127.0.0.1',port=port,user='root',password=root_password,
                                      database='store_test',autocommit=True,connect_timeout=1)
                break
            except pymysql.MySQLError:
                time.sleep(.5)
        else:raise RuntimeError('Isolated MySQL did not become ready')
        rows=snapshot()
        with admin.cursor() as cursor:
            for table,fields in sync.FIELDS.items():
                columns=[]
                for field in fields:
                    kind='INT' if field.endswith('_id') or field in ('quantity','sort_order') else 'DECIMAL(15,4)' if field in ('total','price','value') and table!='oc_order_option' else 'VARCHAR(255)'
                    columns.append('`'+field+'` '+kind)
                pk=sync.TABLES[table]
                primary='`'+pk+'`' if pk else '`order_status_id`,`language_id`'
                cursor.execute(f'CREATE TABLE `{table}` ('+','.join(columns)+', PRIMARY KEY ('+primary+')) ENGINE=MyISAM')
                for record in rows[table].values():
                    cursor.execute('INSERT INTO `'+table+'` ('+','.join('`'+f+'`' for f in fields)+') VALUES ('+','.join(['%s']*len(fields))+')',tuple(record[f] for f in fields))
            cursor.execute('CREATE USER %s@%s IDENTIFIED BY %s',('analytics_reader','%',reader_password))
            for table in sync.TABLES:
                cursor.execute('GRANT SELECT ON `store_test`.`'+table+'` TO %s@%s',('analytics_reader','%'))
        config={'host':'127.0.0.1','port':port,'database':'store_test','user':'analytics_reader',
                'password':reader_password,'prefix':'oc_'}
        config_file=directory/'source.json';config_file.write_text(json.dumps(config));config_file.chmod(0o600)
        database=directory/'analytics.sqlite3'
        state=run_sync(config_file,database)
        assert state['state']=='ok' and state['consistency']=='verified_two_reads'
        reader=sync.connect_source(config)
        try:
            with reader.cursor() as cursor:
                try:cursor.execute('UPDATE oc_order SET total=1')
                except pymysql.MySQLError as e:assert e.args[0]==1142
                else:raise AssertionError('Reader has write permissions')
        finally:reader.close()
        with sqlite3.connect(database) as local:
            local.execute('CREATE TABLE IF NOT EXISTS customer_actions (customer_id TEXT PRIMARY KEY,payload TEXT)')
            local.execute("INSERT INTO customer_actions VALUES ('1','{}')")
        with admin.cursor() as cursor:
            cursor.execute('UPDATE oc_order SET total=150 WHERE order_id=1')
            # Deliberately leave date_modified unchanged: reconciliation must catch this.
            cursor.execute('UPDATE oc_order_product SET total=150 WHERE order_product_id=1')
        assert run_sync(config_file,database)['changed']
        with sqlite3.connect(database) as local:
            raw=json.loads(local.execute("SELECT payload FROM raw_records WHERE table_name='oc_order'").fetchone()[0])
            assert float(raw['total'])==150
            assert local.execute('SELECT count(*) FROM customer_actions').fetchone()[0]==1
        with admin.cursor() as cursor:
            cursor.execute('DELETE FROM oc_order_option')
        assert run_sync(config_file,database)['changed']
        with sqlite3.connect(database) as local:
            assert local.execute("SELECT count(*) FROM raw_records WHERE table_name='oc_order_option'").fetchone()[0]==0
        with admin.cursor() as cursor:
            for table in sync.TABLES:cursor.execute('ALTER TABLE `'+table+'` ENGINE=InnoDB')
        assert run_sync(config_file,database)['consistency']=='innodb_snapshot'
        assert not run_sync(config_file,database)['changed']
        # Real verified TLS, with a test-only local CA and an IP SAN.
        moment=datetime.now(timezone.utc)
        ca_key=rsa.generate_private_key(public_exponent=65537,key_size=2048)
        ca_name=x509.Name([x509.NameAttribute(NameOID.COMMON_NAME,'Disposable analytics test CA')])
        ca=(x509.CertificateBuilder().subject_name(ca_name).issuer_name(ca_name).public_key(ca_key.public_key())
            .serial_number(x509.random_serial_number()).not_valid_before(moment-timedelta(minutes=5))
            .not_valid_after(moment+timedelta(days=1)).add_extension(x509.BasicConstraints(ca=True,path_length=0),critical=True)
            .sign(ca_key,hashes.SHA256()))
        server_key=rsa.generate_private_key(public_exponent=65537,key_size=2048)
        server_name=x509.Name([x509.NameAttribute(NameOID.COMMON_NAME,'localhost')])
        certificate=(x509.CertificateBuilder().subject_name(server_name).issuer_name(ca_name).public_key(server_key.public_key())
                     .serial_number(x509.random_serial_number()).not_valid_before(moment-timedelta(minutes=5))
                     .not_valid_after(moment+timedelta(days=1)).add_extension(x509.BasicConstraints(ca=False,path_length=None),critical=True)
                     .add_extension(x509.SubjectAlternativeName([x509.DNSName('localhost'),x509.IPAddress(ipaddress.ip_address('127.0.0.1'))]),critical=False)
                     .sign(ca_key,hashes.SHA256()))
        for filename,contents in [('ca.pem',ca.public_bytes(serialization.Encoding.PEM)),
                                  ('server-cert.pem',certificate.public_bytes(serialization.Encoding.PEM)),
                                  ('server-key.pem',server_key.private_bytes(serialization.Encoding.PEM,serialization.PrivateFormat.TraditionalOpenSSL,serialization.NoEncryption()))]:
            path=directory/filename;path.write_bytes(contents);path.chmod(0o600)
            subprocess.run(['docker','cp',str(path),name+':/var/lib/mysql/'+filename],check=True,stdout=subprocess.DEVNULL)
            subprocess.run(['docker','exec',name,'chown','mysql:mysql','/var/lib/mysql/'+filename],check=True,stdout=subprocess.DEVNULL)
        with admin.cursor() as cursor:cursor.execute('ALTER INSTANCE RELOAD TLS')
        encrypted=sync.connect_source({**config,'ssl_ca':str(directory/'ca.pem')})
        try:
            with encrypted.cursor() as cursor:
                cursor.execute("SHOW SESSION STATUS LIKE 'Ssl_cipher'")
                assert cursor.fetchone()['Value']
                cursor.execute('SELECT count(*) AS n FROM oc_order')
                assert cursor.fetchone()['n']==1
        finally:encrypted.close()
        if runtime=='php':
            config_file.write_text(json.dumps({**config,'ssl_ca':'/test-data/ca.pem'}))
            assert run_sync(config_file,database)['state']=='ok'
        admin.close()
        print(json.dumps({'runtime':runtime,'real_mysql_8_4':'passed','reader_cannot_write':'passed',
                          'myisam_two_reads':'passed','innodb_snapshot':'passed',
                          'edits_without_date_modified':'passed','source_deletions':'passed','crm_survival':'passed',
                          'verified_tls_with_server_identity':'passed'}))
    finally:
        subprocess.run(['docker','rm','-f',name],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)

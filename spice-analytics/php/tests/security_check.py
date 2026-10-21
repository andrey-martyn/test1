"""HTTP contract with a test-only HTTPS server marker; no public deployment claim."""
import json
import os
PHP_IMAGE = os.environ.get('ANALYTICS_PHP_IMAGE', 'lavka-php-dev')
AUTH_IMAGE = os.environ.get('ANALYTICS_AUTH_CLI_IMAGE', PHP_IMAGE)
from pathlib import Path
import secrets
import socket
import sqlite3
import subprocess
import sys
import tempfile
import time
from http.cookies import SimpleCookie
import httpx

ROOT=Path(__file__).resolve().parents[2];sys.path.insert(0,str(ROOT));sys.path.insert(0,str(ROOT/'tests'))
from test_production import snapshot
from sync_mysql import apply_snapshot

name='lavka-php-security-'+secrets.token_hex(5)
with tempfile.TemporaryDirectory() as t:
    directory=Path(t);apply_snapshot(directory/'analytics.sqlite3',snapshot(),'verified_two_reads')
    password=secrets.token_urlsafe(24)
    docker=['docker','run','--rm','--user',f'{os.getuid()}:{os.getgid()}','-v',str(ROOT)+':/app:ro','-v',str(directory)+':/test-data','-e','ANALYTICS_DATA_DIR=/test-data']
    subprocess.run(docker+['-i',AUTH_IMAGE,'php','-r','require "php/src/Auth.php"; \\Lavka\\Auth::setPassword(getenv("ANALYTICS_DATA_DIR"),stream_get_contents(STDIN));'],input=password.encode(),check=True,stdout=subprocess.DEVNULL)
    (directory/'router.php').write_text("<?php $_SERVER['HTTPS']='on'; require '/app/php/public/index.php';")
    with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
    server=subprocess.Popen(docker+['--name',name,'-e','ANALYTICS_PUBLIC_URL=https://analytics.example.invalid','-p',f'127.0.0.1:{port}:8000',PHP_IMAGE,'php','-S','0.0.0.0:8000','/test-data/router.php'],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    try:
        with httpx.Client(base_url=f'http://127.0.0.1:{port}',headers={'Host':'analytics.example.invalid'},timeout=15) as client:
            for _ in range(100):
                try:
                    if client.get('/health').status_code==200:break
                except httpx.TransportError:pass
                time.sleep(.1)
            else:raise RuntimeError('PHP security test server failed to start')
            response=client.get('/api/meta');assert response.status_code==401
            assert response.headers['cache-control']=='no-store'
            assert 'noindex' in response.headers['x-robots-tag']
            assert response.headers['x-frame-options']=='DENY'
            assert client.get('/api/meta',headers={'Host':'attacker.example.invalid'}).status_code==400
            assert client.post('/api/login',json={'password':password},headers={'Origin':'https://attacker.example.invalid'}).status_code==403
            response=client.post('/api/login',json={'password':password},headers={'Origin':'https://analytics.example.invalid'})
            assert response.status_code==200
            cookie=response.headers['set-cookie'];assert 'secure' in cookie.lower() and 'httponly' in cookie.lower() and 'samesite=strict' in cookie.lower()
            parsed=SimpleCookie();parsed.load(cookie);client.headers['Cookie']='lavka_analytics_session='+parsed['lavka_analytics_session'].value
            meta=client.get('/api/meta');assert meta.status_code==200 and meta.json()['names_visible']
            report=client.get('/api/report',params={'year':2026,'as_of':'2026-10-20','currency':'UAH'}).json()
            assert report['customers'][0]['name']=='Тест Покупатель'
            # CRM mutation must take effect in a new PHP request, including cached reports.
            body={'stage':'do_not_contact','note':'Synthetic test only','contact_date':None,'next_contact':None}
            assert client.put('/api/customers/1/action',json=body).status_code==200
            report=client.get('/api/report',params={'year':2026,'as_of':'2026-10-20','currency':'UAH'}).json()
            assert report['customers'][0]['action']['stage']=='do_not_contact'
            for path in ['/data/source.json','/data/analytics.sqlite3','/src/Auth.php','/.env']:
                assert client.get(path).status_code==404
            assert client.post('/api/logout',json={}).status_code==200
            assert client.get('/api/meta').status_code==401
            for _ in range(10):assert client.post('/api/login',json={'password':'incorrect'}).status_code==401
            assert client.post('/api/login',json={'password':password}).status_code==429
        # Private backup preserves CRM and excludes live sessions.
        with tempfile.TemporaryDirectory() as bt:
            subprocess.run(docker+['-v',bt+':/test-backups',PHP_IMAGE,'php','php/bin/backup.php','--data','/test-data','--destination','/test-backups'],capture_output=True,check=True)
            copies=list(Path(bt).iterdir());assert len(copies)==1
            assert (copies[0]/'access-php.json').exists() and not (copies[0]/'sessions-php.sqlite3').exists()
            with sqlite3.connect(copies[0]/'analytics.sqlite3') as copy:
                assert copy.execute('PRAGMA quick_check').fetchone()[0]=='ok'
                assert copy.execute('SELECT count(*) FROM customer_actions').fetchone()[0]==1
        with tempfile.TemporaryDirectory() as dt:
            deployment=docker+['-v',dt+':/deployment',PHP_IMAGE,'php','php/bin/deploy.php','--webroot','/deployment/public','--data','/deployment/private','--url','https://analytics.example.invalid']
            subprocess.run(deployment,capture_output=True,check=True)
            launcher=(Path(dt)/'public/index.php').read_bytes()
            assert subprocess.run(deployment,capture_output=True).returncode==1
            assert (Path(dt)/'public/index.php').read_bytes()==launcher
            assert not (Path(dt)/'public/source.json').exists()
        print(json.dumps({'anonymous_api':'denied','canonical_host_and_origin':'passed','secure_cookie_and_names':'passed','crm_cache_invalidation':'passed','private_paths':'denied','persistent_rate_limit':'passed','private_backup':'passed','isolated_deployment':'passed'}))
    finally:
        subprocess.run(['docker','rm','-f',name],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        server.wait(timeout=15)

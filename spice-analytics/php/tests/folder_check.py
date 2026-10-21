"""Folder deployment behind a simulated OpenCart filename/directory rewrite rule.

This exercises the generated launcher and existing-file query routing, not a real
hosting vhost, Apache configuration or public TLS certificate.
"""
import json
import os
from pathlib import Path
import secrets
import socket
import subprocess
import sys
import tempfile
import time
from http.cookies import SimpleCookie

import httpx

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT))
sys.path.insert(0, str(ROOT / 'tests'))
from test_production import snapshot
from sync_mysql import apply_snapshot

container = 'lavka-php-folder-' + secrets.token_hex(5)
with tempfile.TemporaryDirectory() as private, tempfile.TemporaryDirectory() as site:
    data = Path(private)
    shop = Path(site) / 'shop'
    shop.mkdir(mode=0o755)
    (shop / 'index.php').write_text('<?php echo "EXISTING STOREFRONT";')
    (shop / '.htaccess').write_text('# Existing shop rules must remain untouched\n')
    originals = {p: p.read_bytes() for p in shop.iterdir()}
    apply_snapshot(data / 'analytics.sqlite3', snapshot(), 'verified_two_reads')
    password = secrets.token_urlsafe(24)
    docker = ['docker', 'run', '--rm', '--user', f'{os.getuid()}:{os.getgid()}',
              '-v', str(ROOT) + ':/app:ro', '-v', private + ':/test-data',
              '-v', site + ':/test-site', '-e', 'ANALYTICS_DATA_DIR=/test-data']
    subprocess.run(docker + ['-i', 'lavka-php-dev', 'php', '-r',
        'require "php/src/Auth.php"; \\Lavka\\Auth::setPassword("/test-data",stream_get_contents(STDIN));'],
        input=password.encode(), capture_output=True, check=True)
    deploy = docker + ['lavka-php-dev', 'php', 'php/bin/deploy.php',
                      '--webroot', '/test-site/shop/analytics', '--data', '/test-data',
                      '--url', 'https://www.shop.example.invalid/analytics',
                      '--shop-root', '/test-site/shop']
    subprocess.run(deploy, capture_output=True, check=True)
    assert subprocess.run(deploy, capture_output=True).returncode == 1
    assert all(p.read_bytes() == contents for p, contents in originals.items())
    assert set(p.name for p in (shop / 'analytics').iterdir()) == {'index.php', '.htaccess', 'static'}
    for destination in ['/test-site/shop', '/test-site/shop/backups']:
        mode = shop.stat().st_mode
        result = subprocess.run(docker + ['lavka-php-dev', 'php', 'php/bin/backup.php',
            '--data', '/test-data', '--destination', destination], capture_output=True)
        assert result.returncode == 1 and shop.stat().st_mode == mode
        assert not (shop / 'backups').exists()
    # A private path hidden behind a parent symlink must also be refused.
    (Path(site) / 'public-link').symlink_to(shop, target_is_directory=True)
    unsafe = deploy.copy()
    unsafe[unsafe.index('--data') + 1] = '/test-site/public-link/private'
    unsafe[unsafe.index('--webroot') + 1] = '/test-site/shop/unsafe'
    unsafe[unsafe.index('--url') + 1] = 'https://www.shop.example.invalid/unsafe'
    assert subprocess.run(unsafe, capture_output=True).returncode == 1
    assert not (shop / 'private').exists() and not (shop / 'unsafe').exists()
    (data / 'router.php').write_text('''<?php
$_SERVER['HTTPS']=isset($_SERVER['HTTP_X_TEST_INSECURE'])?'off':'on';
$path=rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$candidate='/test-site/shop'.$path;
if (is_file($candidate)) {
    if (pathinfo($candidate,PATHINFO_EXTENSION)==='php') require $candidate;
    else return false;
} elseif (is_dir($candidate) && is_file($candidate.'/index.php')) require $candidate.'/index.php';
else require '/test-site/shop/index.php';
''')
    with socket.socket() as listener:
        listener.bind(('127.0.0.1', 0))
        port = listener.getsockname()[1]
    server = subprocess.Popen(docker + ['--name', container, '-p', f'127.0.0.1:{port}:8000',
        'lavka-php-dev', 'php', '-S', '0.0.0.0:8000', '-t', '/test-site/shop', '/test-data/router.php'],
        stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    try:
        with httpx.Client(base_url=f'http://127.0.0.1:{port}',
                          headers={'Host': 'www.shop.example.invalid'}, timeout=15) as client:
            def route(path):
                return '/analytics/index.php?route=' + path
            for _ in range(100):
                try:
                    if client.get(route('/health')).status_code == 200:
                        break
                except httpx.TransportError:
                    pass
                time.sleep(.1)
            else:
                raise RuntimeError('Folder test server failed to start')
            assert client.get('/').text == 'EXISTING STOREFRONT'
            assert client.get('/some-shop-seo-page').text == 'EXISTING STOREFRONT'
            response = client.get('/analytics/')
            assert response.status_code == 303 and response.headers['location'] == '/analytics/index.php?route=%2Flogin'
            response = client.get(response.headers['location'])
            assert response.status_code == 200
            assert 'href="/analytics/static/style.css"' in response.text
            assert '__ANALYTICS_BASE__' not in response.text
            assert client.get('/analytics/static/style.css').status_code == 200
            assert client.get('/analytics/static/countries.geojson').status_code == 200
            assert client.get(route('/api/meta')).status_code == 401
            assert client.post(route('/api/login'), json={'password': password},
                headers={'Origin': 'https://attacker.example.invalid'}).status_code == 403
            response = client.post(route('/api/login'), json={'password': password},
                                  headers={'Origin': 'https://www.shop.example.invalid'})
            assert response.status_code == 200
            cookie = SimpleCookie()
            cookie.load(response.headers['set-cookie'])
            token = cookie['lavka_analytics_session']
            assert token['path'] == '/analytics/' and token['secure'] and token['httponly']
            assert token['samesite'] == 'Strict' and not token['domain']
            client.headers['Cookie'] = 'session=shop-session; PHPSESSID=shop-php; lavka_analytics_session=' + token.value
            assert client.get(route('/api/meta')).json()['names_visible']
            report = client.get(route('/api/report') + '&year=2026&as_of=2026-10-20&currency=UAH').json()
            assert report['metrics']['amount'] == 100 and report['metrics']['kilograms'] == .4
            assert client.get('/analytics/index.php?route=//attacker.example.invalid').status_code == 400
            assert client.get('/analytics/index.php?route[]=bad').status_code == 400
            for path in ['/data/source.json', '/data/analytics.sqlite3', '/src/Auth.php', '/.env']:
                assert client.get(route(path)).status_code in (400, 404)
            response = client.get(route('/api/meta'), headers={'X-Test-Insecure': '1'})
            assert response.status_code == 307
            assert response.headers['location'] == 'https://www.shop.example.invalid' + route('/api/meta')
            response = client.post(route('/api/logout'), json={}, headers={'Origin': 'https://www.shop.example.invalid'})
            assert response.status_code == 200
            expired = SimpleCookie()
            expired.load(response.headers['set-cookie'])
            assert expired['lavka_analytics_session']['path'] == '/analytics/'
            assert client.get(route('/api/meta')).status_code == 401
            assert client.get('/').text == 'EXISTING STOREFRONT'
        assert all(p.read_bytes() == contents for p, contents in originals.items())
        print(json.dumps({'folder_launcher_and_query_routes': 'passed', 'shop_files_and_routes': 'preserved',
                          'cookie_path_and_name': 'isolated', 'https_and_origin': 'passed',
                          'private_paths': 'denied', 'public_backup_and_symlink_data': 'refused'}))
    finally:
        subprocess.run(['docker', 'rm', '-f', container], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        server.wait(timeout=15)

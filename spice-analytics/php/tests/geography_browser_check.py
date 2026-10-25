"""Synthetic-only city aliases, pickup provenance, unknown order review and responsive UI."""
import hashlib
import json
import os
from pathlib import Path
import secrets
import socket
import sqlite3
import subprocess
import tempfile
import time

import httpx
from playwright.sync_api import sync_playwright

ROOT=Path(__file__).resolve().parents[2]
IMAGE=os.environ.get('ANALYTICS_PHP_IMAGE','lavka-php73-dev')
ARTIFACTS=Path(os.environ.get('ANALYTICS_TEST_OUTPUT','/tmp/lavka-geography-browser'))
ARTIFACTS.mkdir(parents=True,exist_ok=True)
SEED=r'''
define('LAVKA_GEO_FIXTURE_ONLY',true);require 'php/tests/geography_check.php';
$db=\Lavka\database('/test-data/analytics.sqlite3');\Lavka\init_schema($db);
\Lavka\apply_snapshot($db,geography_fixture(),'test');
copy('tests/fixtures/GeoIP2-City-Test.mmdb','/test-data/geoip-city.mmdb');
\Lavka\Auth::setPassword('/test-data',stream_get_contents(STDIN));
'''

def raw_hash(path):
    with sqlite3.connect(path) as connection:
        rows=connection.execute('SELECT table_name,record_id,payload FROM raw_records ORDER BY table_name,record_id').fetchall()
    return hashlib.sha256(json.dumps(rows).encode()).hexdigest()

with tempfile.TemporaryDirectory() as temporary:
    password=secrets.token_urlsafe(24)
    name='lavka-geography-browser-'+secrets.token_hex(5)
    docker=['docker','run','--rm','--user',f'{os.getuid()}:{os.getgid()}',
            '-v',str(ROOT)+':/app:ro','-v',temporary+':/test-data',
            '-e','ANALYTICS_DATA_DIR=/test-data','-e','ANALYTICS_BASE_PATH=/analytics']
    subprocess.run(docker+['-i',IMAGE,'php','-r',SEED],input=password.encode(),check=True,stdout=subprocess.DEVNULL)
    before=raw_hash(Path(temporary)/'analytics.sqlite3')
    summary=json.loads(subprocess.check_output(docker+[IMAGE,'php','php/bin/check_geography.php','--data','/test-data','--as-of','2026-12-31']))
    assert summary['state']=='ok' and summary['quality']['pickup_odesa']==2
    assert 'unknown_orders' not in summary['quality']
    assert 'example.invalid' not in json.dumps(summary) and '81.2.69.160' not in json.dumps(summary)
    with socket.socket() as listener:
        listener.bind(('127.0.0.1',0));port=listener.getsockname()[1]
    server=subprocess.Popen(docker+['--name',name,'-p',f'127.0.0.1:{port}:8000',IMAGE,
                                   'php','-S','0.0.0.0:8000','-t','php/public','php/public/index.php'],
                                   stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    origin=f'http://127.0.0.1:{port}';base=origin+'/analytics'
    try:
        for _ in range(600):
            try:
                if httpx.get(base+'/index.php?route=/health',trust_env=False,timeout=2).status_code==200:break
            except httpx.TransportError:pass
            time.sleep(.1)
        else:raise RuntimeError('Geography test server did not start')
        with sync_playwright() as playwright:
            browser=playwright.chromium.launch(executable_path='/usr/bin/chromium',args=['--no-sandbox'])
            page=browser.new_page(viewport={'width':1470,'height':1000},has_touch=True)
            errors=[];external=[]
            page.on('pageerror',lambda e:errors.append(str(e)))
            page.on('request',lambda r:external.append(r.url) if not r.url.startswith(origin+'/') else None)
            page.goto(base+'/');page.fill('#password',password);page.click('button[type=submit]')
            page.wait_for_selector('.dash-board');page.click('#navigation [data-area=sales]')
            page.wait_for_selector('.next-step');page.select_option('#year','0')
            page.wait_for_function('() => state.report?.year===0 && !ordersLoading')
            page.click('#section-navigation [data-view=geography]');page.wait_for_selector('.regional-cities>summary');page.locator('.regional-cities>summary').click();page.wait_for_selector('.geography-table')
            assert page.locator('.geo-audit-cards').count()==0
            odesa=page.evaluate('getLocations().filter(l=>l.city==="Одеса")')
            assert len(odesa)==1 and odesa[0]['count']==4 and odesa[0]['customers']==3 and odesa[0]['amount']==1000
            assert odesa[0]['sources']=={'Адрес доставки':2,'По месту самовывоза':2}
            page.wait_for_selector('#map svg')
            titles=page.locator('#map .map-region title').all_text_contents()
            assert 'Київ: 1 заказов' in titles,titles
            assert 'Київська область: 1 заказов' in titles,titles
            page.locator('.geography-table tr').filter(has_text='Одеса').first.locator('.geo-variants summary').click()
            assert 'Одесса' in page.locator('.geography-table').inner_text()
            page.fill('#search','Одесса')
            page.wait_for_function('() => state.search==="Одесса" && document.querySelectorAll(".geography-table>tbody>tr").length===1')
            assert 'Одеса' in page.locator('.geography-table>tbody').inner_text()
            page.fill('#search','Киевская')
            page.wait_for_function('() => state.search==="Киевская" && document.querySelector(".geography-table>tbody").textContent.includes("Київ")')
            page.fill('#search','');page.wait_for_function('() => state.search===""')
            page.click('#navigation [data-area=settings]');page.wait_for_selector('.geo-diagnostics>summary')
            page.locator('.geo-diagnostics>summary').click();page.locator('[data-geo-order]').first.click()
            page.wait_for_selector('#drawer .geo-order-context')
            assert 'Город доставки в заказе' in page.locator('#drawer').inner_text()
            assert 'Не заполнен' in page.locator('#drawer .geo-order-context').inner_text()
            page.click('[data-action=close-dialog]')
            assert page.evaluate('state.view')=='settings'
            page.click('#navigation [data-area=sales]');page.click('#section-navigation [data-view=geography]')
            page.locator('.regional-map-notes>summary').click()
            page.select_option('#geo-source','address')
            assert page.evaluate('getLocations().find(l=>l.city==="Одеса").count')==4
            page.select_option('#geo-source','ip')
            assert page.evaluate('getLocations().find(l=>l.country_code==="GB").count')==4
            page.select_option('#geo-source','mixed')
            page.select_option('#gender','female')
            page.wait_for_function('() => state.report.gender_filter==="female" && !ordersLoading')
            assert page.evaluate('getLocations().reduce((sum,l)=>sum+l.amount,0)===state.report.metrics.amount')
            page.select_option('#gender','all')
            page.wait_for_function('() => state.report.gender_filter==="all" && !ordersLoading')
            page.screenshot(path=str(ARTIFACTS/'geography-1470.png'),full_page=True)
            for width in [390,320]:
                page.set_viewport_size({'width':width,'height':844})
                page.evaluate('window.scrollTo(0,0)')
                assert page.evaluate('document.documentElement.scrollWidth<=innerWidth'),width
                assert page.locator('.next-step').evaluate('el=>el.scrollWidth<=el.clientWidth+1')
                page.locator('.next-step').screenshot(path=str(ARTIFACTS/f'geography-cards-{width}.png'))
                page.locator('.regional-map-notes>summary').tap()
                assert page.evaluate('document.documentElement.scrollWidth<=innerWidth'),width
                page.screenshot(path=str(ARTIFACTS/f'geography-{width}.png'),full_page=True)
            assert not errors,errors
            assert not external,external
            browser.close()
        assert raw_hash(Path(temporary)/'analytics.sqlite3')==before
        print(json.dumps({'canonical_city_and_unique_buyers':'passed','regions_map_and_sources':'passed',
                          'unknown_order_review':'passed','ip_modes_and_name_filter':'passed',
                          'mobile_390_320':'passed','source_unchanged':'passed','external_requests':external}))
    finally:
        subprocess.run(['docker','rm','-f',name],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        server.wait(timeout=15)

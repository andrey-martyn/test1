"""Local synthetic name estimates: actual report API, browser navigation and mobile layout."""
import json
import os
from pathlib import Path
import secrets
import socket
import sqlite3
import subprocess
import tempfile
import time
from urllib.parse import parse_qs, urlparse

import httpx
from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[2]
IMAGE = os.environ.get('ANALYTICS_PHP_IMAGE', 'lavka-php73-dev')
ARTIFACTS = Path(os.environ.get('ANALYTICS_TEST_OUTPUT', '/tmp/lavka-system-browser'))
ARTIFACTS.mkdir(parents=True, exist_ok=True)
SEED = r'''
define('LAVKA_GENDER_FIXTURE_ONLY',true);
require 'php/tests/gender_check.php';
$db=\Lavka\database('/test-data/analytics.sqlite3');\Lavka\init_schema($db);
\Lavka\apply_snapshot($db,gender_fixture(),'test');
\Lavka\Auth::setPassword('/test-data',stream_get_contents(STDIN));
'''

with tempfile.TemporaryDirectory() as temporary:
    password = secrets.token_urlsafe(24)
    name = 'lavka-system-browser-' + secrets.token_hex(5)
    docker = ['docker','run','--rm','--user',f'{os.getuid()}:{os.getgid()}',
              '-v',str(ROOT)+':/app:ro','-v',temporary+':/test-data',
              '-e','ANALYTICS_DATA_DIR=/test-data','-e','ANALYTICS_BASE_PATH=/analytics']
    subprocess.run(docker+['-i',IMAGE,'php','-r',SEED],input=password.encode(),check=True,stdout=subprocess.DEVNULL)
    with socket.socket() as listener:
        listener.bind(('127.0.0.1',0))
        port = listener.getsockname()[1]
    server = subprocess.Popen(docker+['--name',name,'-p',f'127.0.0.1:{port}:8000',IMAGE,
                              'php','-S','0.0.0.0:8000','-t','php/public','php/public/index.php'],
                              stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    origin = f'http://127.0.0.1:{port}'
    base = origin+'/analytics'
    try:
        for _ in range(600):
            try:
                if httpx.get(base+'/index.php?route=/health',trust_env=False,timeout=2).status_code==200:
                    break
            except httpx.TransportError:
                pass
            time.sleep(.1)
        else:
            raise RuntimeError('Gender test server did not start')
        with sync_playwright() as playwright:
            browser = playwright.chromium.launch(executable_path='/usr/bin/chromium',args=['--no-sandbox'])
            context = browser.new_context(viewport={'width':1470,'height':1000},accept_downloads=True)
            page = context.new_page()
            errors, external = [], []
            page.on('pageerror',lambda error:errors.append(str(error)))
            page.on('request',lambda request:external.append(request.url) if not request.url.startswith(origin+'/') else None)
            page.goto(base+'/')
            page.fill('#password',password)
            page.click('button[type=submit]')
            page.wait_for_selector('.dash-board')
            page.click('#navigation [data-area=sales]')
            page.wait_for_selector('.next-step')
            page.select_option('#year','0')
            page.fill('#as-of','2026-12-31')
            page.dispatch_event('#as-of','change')
            page.wait_for_function('() => state.report?.as_of==="2026-12-31" && state.report?.year===0 && !ordersLoading')
            page.locator('.gender-comparison').locator('..').locator(':scope > summary').click()
            page.locator('.gender-unknown > summary').click()
            assert 'Неоднозначное имя' in page.locator('.gender-unknown').inner_text()
            page.click('.gender-unknown [data-gender-unknown]:not([data-gender-unknown=all])')
            page.wait_for_selector('#drawer .gender-customer-note')
            assert 'не определён' in page.locator('#drawer .gender-customer-note').inner_text()
            page.click('[data-action=close-dialog]')
            page.wait_for_function('() => !document.getElementById("drawer").open')
            page.click('#navigation [data-area=settings]')
            page.wait_for_selector('#diagnostic-download')
            assert not page.is_checked('#diagnostic-names')
            assert 'В обработке' in page.locator('#content').inner_text()
            with page.expect_download() as download:
                page.click('#diagnostic-download')
            path=ARTIFACTS/'diagnostics-default.json'
            download.value.save_as(path)
            data=json.loads(path.read_text())
            assert data['schema']=='lavka-system-diagnostics'
            assert data['result']['failed']==0, data['checks']
            assert data['result']['client_failed']==0, data['client']['probes']
            assert data['privacy']['names_included'] is False
            assert not any('name_fields' in r for r in data['unknown_gender']['samples'])
            assert 'synthetic1@example.invalid' not in path.read_text()
            page.check('#diagnostic-names')
            with page.expect_download() as download:
                page.click('#diagnostic-download')
            path=ARTIFACTS/'diagnostics-with-names.json'
            download.value.save_as(path)
            assert 'Саша' in path.read_text()
            response=context.request.get(base+'/index.php?route=/api/diagnostics/export&year=0&as_of=2026-12-31&currency=UAH')
            assert response.status==405
            assert httpx.post(base+'/index.php?route=/api/diagnostics/export',json={},trust_env=False).status_code==401
            response=context.request.post(base+'/index.php?route=/api/diagnostics/export&year=0&as_of=2026-12-31&currency=UAH',data={'include_names':False},headers={'Origin':'https://foreign.example'})
            assert response.status==403
            page.click('#navigation [data-area=competitors]')
            page.wait_for_selector('.competitor-matrix')
            tabs=['products','coverage','matching','links','changes','terms','discoveries','sources','errors']
            positions=[]
            for tab in tabs:
                page.click('#competitor-tab-'+tab)
                page.wait_for_function('(tab)=>competitorState.tab===tab',arg=tab)
                page.evaluate('document.documentElement.style.scrollBehavior="auto";window.scrollTo({top:0,behavior:"instant"})')
                page.wait_for_function('()=>scrollY===0')
                position=page.locator('.competitor-nav').evaluate('(el)=>({top:el.getBoundingClientRect().top+scrollY,display:getComputedStyle(el).display,height:el.getBoundingClientRect().height,scroll:scrollY,parent:getComputedStyle(el.parentElement).display,content:document.getElementById("content").innerText.slice(0,100)})')
                assert position['height']>0,(tab,position)
                positions.append(position['top'])
                assert page.locator('.competitor-tabpanel > .next-step').count()==(1 if tab=='products' else 0)
                assert page.locator('[role=tab][aria-selected=true]').count()==1
            assert max(positions)-min(positions)<=1,positions
            page.evaluate('''() => {const target=competitorState.data.targets[0];competitorState.data.changes=[{target_id:target.id,source_id:target.source_id,kind:'price',message:'Цена укропа',occurred_at:'2026-10-25T10:00:00Z'},{target_id:target.id,source_id:target.source_id,kind:'price',message:'Цена кориандра',occurred_at:'2026-10-25T10:00:00Z'}];competitorState.data.discoveries=[{target_id:target.id,source_id:target.source_id,title:'Укроп',url:target.url,first_seen:'2026-10-25T10:00:00Z',baseline:false},{target_id:target.id,source_id:target.source_id,title:'Кориандр',url:target.url,first_seen:'2026-10-25T10:00:00Z',baseline:false}];}''')
            page.click('#competitor-tab-changes')
            page.fill('#competitor-search','укроп')
            assert page.locator('.competitor-changes article').count()==1
            assert page.locator('#competitor-archived').count()==0
            page.click('#competitor-tab-discoveries')
            assert page.input_value('#competitor-search')==''
            assert page.locator('.competitor-discovery-list article').count()==2
            page.fill('#competitor-search','кориандр')
            assert page.locator('.competitor-discovery-list article').count()==1
            page.click('#competitor-tab-changes')
            assert page.input_value('#competitor-search')=='укроп'
            assert page.locator('.competitor-changes article').count()==1
            page.click('#competitor-tab-links')
            page.click('[data-comp-action=add]')
            page.fill('#competitor-target-form [name=url]','https://deluxe.com.ua/draft')
            page.click('#competitor-tab-changes')
            assert page.locator('#competitor-target-form').count()==0,page.evaluate('({tab:competitorState.tab,formTab:competitorState.formTab,open:competitorState.formOpen})')
            page.click('#competitor-tab-links')
            assert page.input_value('#competitor-target-form [name=url]')=='https://deluxe.com.ua/draft'
            page.click('[data-comp-action=cancel-form]')
            for width in [1470,390,320]:
                page.set_viewport_size({'width':width,'height':844})
                page.click('#competitor-tab-coverage')
                page.evaluate('window.scrollTo(0,600)')
                page.wait_for_timeout(100)
                top=page.locator('.competitor-nav').bounding_box()['y']
                assert 0<=top<=10,(width,top)
                assert page.evaluate('document.documentElement.scrollWidth<=innerWidth+1'),width
                page.locator('#competitor-tab-coverage').focus()
                page.keyboard.press('End')
                page.wait_for_function('()=>competitorState.tab==="errors"')
                assert page.locator('#competitor-tab-errors').evaluate('(el)=>{const r=el.getBoundingClientRect(),p=el.parentElement.getBoundingClientRect();return r.left>=p.left-1&&r.right<=p.right+1}')
                page.screenshot(path=str(ARTIFACTS/f'competitor-tabs-{width}.png'))
            with sqlite3.connect(Path(temporary)/'analytics.sqlite3') as broken:
                broken.execute('DELETE FROM imports')
            response=context.request.post(base+'/index.php?route=/api/diagnostics/export&year=0&as_of=2026-12-31&currency=UAH',data={'include_names':False})
            assert response.status==200,response.text()
            partial=response.json()
            assert partial['partial'] is True and partial['result']['failed']==1
            assert partial['import_record_counts']['oc_order']>0
            assert not errors,errors
            assert not external,external
            browser.close()
            print(json.dumps({'diagnostics_download_privacy_and_auth':'passed','unknown_names_navigation':'passed','competitor_tabs_stable_sticky_context':'passed','draft_preserved':'passed','desktop_mobile_keyboard':'passed'}))
    finally:
        subprocess.run(['docker','rm','-f',name],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        server.wait(timeout=15)

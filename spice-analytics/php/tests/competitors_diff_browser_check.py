"""Real history API, exact event navigation, highlighted text and unchanged user data."""
import hashlib
import json
import os
from pathlib import Path
import secrets
import socket
import sqlite3
import subprocess
import sys
import tempfile
import time
from urllib.parse import parse_qs, urlparse

import httpx
from playwright.sync_api import sync_playwright

ROOT=Path(__file__).resolve().parents[2]
sys.path[:0]=[str(ROOT),str(ROOT/'tests')]
from test_production import snapshot
from sync_mysql import apply_snapshot

IMAGE=os.environ.get('ANALYTICS_PHP_IMAGE','lavka-php73-dev')
OUT=Path(os.environ.get('ANALYTICS_TEST_OUTPUT','/tmp/lavka-competitor-diffs'));OUT.mkdir(parents=True,exist_ok=True)
SEED=r'''
define('LAVKA_DIFF_FIXTURE_ONLY',true);require 'php/tests/competitors_diff_check.php';
require 'php/src/Auth.php';
[$db,$target,$changes,$texts]=diff_seed('/test-data');
\Lavka\Auth::setPassword('/test-data',stream_get_contents(STDIN));
echo json_encode(['target'=>$target['id'],'changes'=>array_map('intval',$changes)]);
'''

def logical_hash(path):
    with sqlite3.connect(f'file:{path}?mode=ro',uri=True) as connection:
        return hashlib.sha256('\n'.join(connection.iterdump()).encode()).hexdigest()

with tempfile.TemporaryDirectory() as temporary:
    data=Path(temporary);apply_snapshot(data/'analytics.sqlite3',snapshot(),'synthetic')
    (data/'identity-reviews.json').write_text('{"version":1,"pairs":[]}')
    password=secrets.token_urlsafe(24)
    docker=['docker','run','--rm','--user',f'{os.getuid()}:{os.getgid()}',
            '-v',str(ROOT)+':/app:ro','-v',temporary+':/test-data',
            '-e','ANALYTICS_DATA_DIR=/test-data','-e','ANALYTICS_BASE_PATH=/analytics']
    seed=subprocess.run(docker+['-i',IMAGE,'php','-r',SEED],input=password.encode(),capture_output=True,check=True)
    ids=json.loads(seed.stdout)
    with socket.socket() as listener:listener.bind(('127.0.0.1',0));port=listener.getsockname()[1]
    container='lavka-diff-'+secrets.token_hex(5)
    server=subprocess.Popen(docker+['--name',container,'-p',f'127.0.0.1:{port}:8000',IMAGE,'php','-S','0.0.0.0:8000','-t','php/public','php/public/index.php'],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    origin=f'http://127.0.0.1:{port}';base=origin+'/analytics'
    try:
        for _ in range(600):
            try:
                if httpx.get(base+'/index.php?route=/health',trust_env=False,timeout=2).status_code==200:break
            except httpx.TransportError:pass
            time.sleep(.1)
        else:raise RuntimeError('Saved diff browser server did not start')
        route=f'/api/competitors/targets/{ids["target"]}'
        assert httpx.get(base+'/index.php',params={'route':route,'change_id':ids['changes'][0]},trust_env=False).status_code==401
        with sync_playwright() as playwright:
            browser=playwright.chromium.launch(executable_path='/usr/bin/chromium',args=['--no-sandbox'])
            page=browser.new_page(viewport={'width':1470,'height':1000},has_touch=True)
            errors=[];external=[];mutations=[]
            page.on('pageerror',lambda error:errors.append(str(error)))
            page.on('request',lambda request:external.append(request.url) if not request.url.startswith(origin+'/') else None)
            page.goto(base+'/');page.fill('#password',password);page.click('button[type=submit]')
            page.wait_for_selector('.dash-board',timeout=45000)
            page.click('#navigation [data-area=competitors]');page.wait_for_selector('#competitor-tab-changes',timeout=30000)
            page.click('#competitor-tab-changes')
            page.wait_for_selector('[data-change-id="'+str(ids['changes'][0])+'"]')
            before={name:logical_hash(data/name) for name in ['analytics.sqlite3','competitors.sqlite3']}
            review_before=(data/'identity-reviews.json').read_bytes()
            page.on('request',lambda request:mutations.append(request.method+' '+request.url) if request.method not in ['GET','HEAD'] else None)
            # Open the older event even though two newer successful snapshots exist.
            page.click('[data-comp-action=history][data-change-id="'+str(ids['changes'][0])+'"]')
            page.wait_for_selector('.competitor-selected-change .competitor-text-diff')
            selected=page.locator('.competitor-selected-change')
            assert selected.get_attribute('data-change-id')==str(ids['changes'][0])
            assert selected.locator('.competitor-text-diff del').all_text_contents()==['100']
            assert selected.locator('.competitor-text-diff ins').all_text_contents()==['200']
            assert 'Доставка 60 грн.' in selected.locator('.competitor-text-diff').inner_text()
            assert selected.locator('.competitor-snapshot-original pre').count()==2
            assert page.locator('.competitor-history-snapshot').count()==4
            assert 'HTTP 403' in page.locator('.competitor-attempt-list').inner_text()
            for width in [1470,390,320]:
                page.set_viewport_size({'width':width,'height':960})
                page.screenshot(path=str(OUT/f'event-diff-{width}.png'))
                assert page.evaluate('document.documentElement.scrollWidth<=innerWidth+1')
                assert selected.locator('.competitor-text-diff').evaluate('e=>e.scrollWidth<=e.clientWidth+1')
            # Plain history opens the latest actual change, skipping the unchanged read.
            page.evaluate('competitorHistory('+str(ids['target'])+')')
            page.wait_for_selector('.competitor-snapshot-diff[open] .competitor-text-diff')
            assert page.locator('.competitor-selected-change').count()==0
            changed=page.locator('.competitor-snapshot-diff[open]')
            assert changed.get_attribute('data-snapshot-diff')=='1'
            assert changed.locator('.competitor-text-diff del').all_text_contents()==['60']
            assert changed.locator('.competitor-text-diff ins').all_text_contents()==['90']
            assert 'Текст не изменился' in page.locator('.competitor-history-snapshot').nth(0).inner_text()
            oldest=page.locator('.competitor-history-snapshot').last
            assert 'Предыдущего снимка' in oldest.inner_text()
            assert oldest.locator('.competitor-snapshot-diff').count()==0
            # Another diff is calculated on expansion, including keyboard/touch access.
            previous=page.locator('[data-snapshot-diff="2"]')
            previous.locator('summary').tap();page.wait_for_selector('[data-snapshot-diff="2"] .competitor-text-diff')
            assert previous.locator('.competitor-text-diff del').all_text_contents()==['100']
            assert previous.locator('.competitor-text-diff ins').all_text_contents()==['200']
            previous.locator('summary').focus();page.keyboard.press('Enter');assert not previous.evaluate('e=>e.open')
            page.keyboard.press('Enter');assert previous.evaluate('e=>e.open')
            page.screenshot(path=str(OUT/'snapshot-diffs-mobile.png'))
            for query,status in [('0',422),('-1',422),('bad',422),('99999999',404)]:
                response=page.request.get(base+'/index.php',params={'route':route,'change_id':query});assert response.status==status
            response=page.request.get(base+'/index.php',params={'route':route,'change_id[]':'1'});assert response.status==422
            # Selected historical text is also available from a dashboard event.
            page.evaluate('dashboardOpenEvent({id:"terms-1",changeId:'+str(ids['changes'][0])+',kind:"terms",title:"Условия",source:"deluxe",at:"2026-10-26T10:00:00Z",summary:"Изменились условия",details:[],beforeText:"Доставка 100 грн.",afterText:"Доставка 200 грн.",target:{id:'+str(ids['target'])+',url:"https://deluxe.com.ua/synthetic-diff"}})')
            assert page.locator('.competitor-text-diff del').all_text_contents()==['100']
            page.click('[data-dash-action=event-history]');page.wait_for_selector('.competitor-history .competitor-selected-change')
            assert page.locator('.competitor-selected-change').get_attribute('data-change-id')==str(ids['changes'][0])
            # Late history responses must not replace a different drawer.
            pending=[]
            matcher=lambda url:parse_qs(urlparse(url).query).get('route')==[route]
            page.route(matcher,lambda request:pending.append(request))
            page.evaluate('void competitorHistory('+str(ids['target'])+')');page.wait_for_function('document.querySelector("#drawer-content .loading")!==null')
            page.wait_for_timeout(100);assert len(pending)==1
            page.evaluate('openDrawer("ДРУГАЯ КАРТОЧКА");document.querySelector("#drawer-content").textContent="Другая карточка"')
            pending[0].continue_();page.unroute(matcher);page.wait_for_timeout(300)
            assert page.locator('#drawer-content').inner_text()=='Другая карточка'
            assert not mutations,mutations
            assert {name:logical_hash(data/name) for name in before}==before,'Existing decisions and saved snapshots are unchanged'
            assert (data/'identity-reviews.json').read_bytes()==review_before
            assert not errors,errors
            assert not external,external
            browser.close()
            print(json.dumps({'selected_historical_event':'passed','lazy_snapshot_highlights':'passed','unchanged_and_missing_baseline':'passed','dashboard_link':'passed','viewport_sizes':[1470,390,320],'existing_data':'unchanged'}),flush=True)
    finally:
        subprocess.run(['docker','rm','-f',container],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,check=False)
        try:server.wait(timeout=10)
        except subprocess.TimeoutExpired:server.kill();server.wait(timeout=10)

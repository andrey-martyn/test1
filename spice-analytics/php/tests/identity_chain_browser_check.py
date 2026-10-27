"""Real API provenance popover: hover, keyboard, touch, privacy, stale reads, no writes."""
import hashlib
import json
import os
from pathlib import Path
import secrets
import socket
import subprocess
import tempfile
import time
from urllib.parse import parse_qs, urlparse
import httpx
from playwright.sync_api import sync_playwright

ROOT=Path(__file__).resolve().parents[2]
IMAGE=os.environ.get('ANALYTICS_PHP_IMAGE','lavka-php73-dev')
OUT=Path(os.environ.get('ANALYTICS_TEST_OUTPUT','/tmp/lavka-identity-chain-browser'));OUT.mkdir(parents=True,exist_ok=True)
SEED=r'''
define('LAVKA_NAME_TRACE_FIXTURE_ONLY',true);
require 'php/tests/identity_name_trace_check.php';
$db=\Lavka\database('/test-data/analytics.sqlite3');\Lavka\init_schema($db);\Lavka\apply_snapshot($db,nt_fixture(),'test');
foreach([['7','8'],['3','4'],['3','9']] as $pair)$db->prepare('INSERT INTO manual_links VALUES(?,?,?)')->execute([$pair[0],$pair[1],'2026-10-20']);\Lavka\bump($db);
\Lavka\identity_review_pairs($db,'/test-data',[['5','6']],'2026-10-27','UAH');
\Lavka\Auth::setPassword('/test-data',stream_get_contents(STDIN));
'''
with tempfile.TemporaryDirectory() as temporary:
    data=Path(temporary);password=secrets.token_urlsafe(24)
    docker=['docker','run','--rm','--user',f'{os.getuid()}:{os.getgid()}', '-v',str(ROOT)+':/app:ro','-v',temporary+':/test-data','-e','ANALYTICS_DATA_DIR=/test-data','-e','ANALYTICS_BASE_PATH=/analytics']
    subprocess.run(docker+['-i',IMAGE,'php','-r',SEED],input=password.encode(),check=True,capture_output=True)
    before={name:hashlib.sha256((data/name).read_bytes()).hexdigest() for name in ['analytics.sqlite3','identity-reviews.json']}
    with socket.socket() as listener:listener.bind(('127.0.0.1',0));port=listener.getsockname()[1]
    container='lavka-identity-chain-'+secrets.token_hex(5)
    server=subprocess.Popen(docker+['--name',container,'-p',f'127.0.0.1:{port}:8000',IMAGE,'php','-S','0.0.0.0:8000','-t','php/public','php/public/index.php'],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    origin=f'http://127.0.0.1:{port}';base=origin+'/analytics'
    try:
        for _ in range(600):
            try:
                if httpx.get(base+'/index.php?route=/health',trust_env=False,timeout=2).status_code==200:break
            except httpx.TransportError:pass
            time.sleep(.1)
        else:raise RuntimeError('Identity chain browser server did not start')
        assert httpx.get(base+'/index.php?route=/api/identity/trace',trust_env=False).status_code==401
        with sync_playwright() as playwright:
            browser=playwright.chromium.launch(executable_path='/usr/bin/chromium',args=['--no-sandbox'])
            context=browser.new_context(viewport={'width':1470,'height':1000},has_touch=True)
            page=context.new_page();errors=[];requests=[];external=[]
            page.on('pageerror',lambda error:errors.append(str(error)))
            page.on('request',lambda req:requests.append(req.url))
            page.on('request',lambda req:external.append(req.url) if not req.url.startswith(origin+'/') else None)
            page.goto(base+'/');page.fill('#password',password);page.click('button[type=submit]')
            try:
                page.wait_for_selector('.dash-board',timeout=45000);page.wait_for_load_state('load')
            except Exception:
                page.screenshot(path=str(OUT/'initialization-failure.png'))
                print(json.dumps({'initialization_errors':errors,'notice':page.locator('#content').inner_text()[:300]}),flush=True)
                raise
            page.click('#navigation [data-area=customers]');page.click('#section-navigation [data-view=duplicates]');page.wait_for_selector('.identity-chain-trigger')
            page.click('#privacy');assert page.evaluate('!state.privacy')
            assert page.evaluate('state.report.duplicate_pairs.every(p=>p.matches.every(m=>m.kind!=="surname"||m.value!=="юлия"))')
            page.fill('#search','Юлия');page.wait_for_function('() => duplicateRows().length===1')
            assert page.locator('[data-pair="21:25"]').count()==1,'Only the genuine surname pair survives a shared given name'
            assert 'рябова' in page.locator('.duplicate-table').inner_text().lower()
            page.fill('#search','');page.wait_for_selector('.identity-chain-trigger')
            trigger=page.locator('.identity-chain-trigger[data-chain-left="1"][data-chain-right="2"]')
            trigger.hover();page.wait_for_selector('#identity-chain-popup .identity-chain-node')
            popup=page.locator('#identity-chain-popup');text=popup.inner_text()
            assert all(value in text for value in ['Один аккаунт магазина','Учётная запись №22','Объединено вручную','20.10.2026','Заказ №7','Заказ №8','Северная Анна']),text
            assert '21.10.2026' in page.evaluate('identityChainDate("2026-10-20T22:30:00Z")')
            assert 'предложение для проверки' in text
            assert popup.locator('.identity-chain-edge').count()==2
            assert trigger.get_attribute('aria-expanded')=='true'
            popup.hover();page.wait_for_timeout(350);assert popup.is_visible(),'Moving from the trigger to the popup keeps it open'
            page.screenshot(path=str(OUT/'chain-hover-desktop.png'))
            page.mouse.move(5,5);page.wait_for_selector('#identity-chain-popup',state='hidden')
            trace_requests=lambda:[url for url in requests if parse_qs(urlparse(url).query).get('route')==['/api/identity/trace']]
            initial_trace_requests=len(trace_requests());assert initial_trace_requests>=1
            trigger.click();page.wait_for_selector('#identity-chain-popup .identity-chain-node');assert page.evaluate('identityChain.pinned')
            assert len(trace_requests())==initial_trace_requests,'Same revision uses the in-memory explanation cache'
            assert page.locator('[data-chain-close]').evaluate('e=>e===document.activeElement')
            options=popup.locator('#identity-chain-variant option').all_text_contents()
            assert len(options)==2 and len(set(options))==2 and any('№50' in label for label in options),options
            page.select_option('#identity-chain-variant','1')
            assert 'Заказ №50' in popup.inner_text() and 'Объединено вручную' not in popup.inner_text()
            page.select_option('#identity-chain-variant','0')
            assert 'Заказ №8' in popup.inner_text() and 'Объединено вручную' in popup.inner_text()
            page.keyboard.press('Escape');page.wait_for_timeout(100)
            assert popup.is_hidden() and trigger.evaluate('e=>e===document.activeElement')
            page.locator('#search').focus();trigger.focus();page.wait_for_selector('#identity-chain-popup .identity-chain-node')
            trigger.press('Enter');page.wait_for_function('() => identityChain.pinned')
            page.locator('[data-chain-close]').click();page.wait_for_selector('#identity-chain-popup',state='hidden')
            for width in [1470,390,320]:
                page.set_viewport_size({'width':width,'height':900})
                trigger.tap() if width<=390 else trigger.click()
                page.wait_for_selector('#identity-chain-popup .identity-chain-node')
                box=popup.bounding_box();assert box['x']>=7 and box['y']>=7 and box['x']+box['width']<=width-7 and box['y']+box['height']<=893,(width,box)
                assert page.evaluate('document.documentElement.scrollWidth<=innerWidth+1'),width
                assert popup.locator('[data-chain-close]').bounding_box()['height']>=44
                assert popup.evaluate('e=>e.scrollWidth<=e.clientWidth+1')
                page.screenshot(path=str(OUT/f'chain-{width}.png'))
                if width<=390:
                    popup.evaluate('el=>el.scrollTop=el.scrollHeight');page.wait_for_timeout(100)
                    assert popup.is_visible() and popup.evaluate('el=>el.scrollTop>0'),'The full chain scrolls inside the mobile popup'
                    page.screenshot(path=str(OUT/f'chain-scrolled-{width}.png'))
                    popup.evaluate('el=>el.scrollTop=0')
                page.keyboard.press('Escape');page.wait_for_timeout(300)
                assert popup.is_hidden(),'Escape must keep the popup closed'
            page.click('#privacy');assert page.evaluate('state.privacy')
            trigger.click();page.wait_for_selector('#identity-chain-popup .identity-chain-node');text=popup.inner_text()
            assert all(value not in text for value in ['Анна','Олена','Северная','north@example.invalid','south@example.invalid','380507654321']),text
            assert 'Значение' in text or 'значение скрыто' in text
            page.keyboard.press('Escape')
            status=page.evaluate("""async()=>{
                const params=new URLSearchParams({left:'1',right:'2',as_of:state.report.as_of,currency:state.report.currency,revision:'stale'});
                return (await fetch(appUrl('/api/identity/trace?'+params))).status;
            }""")
            assert status==409
            # A response arriving after navigation must never reopen the old popup.
            page.evaluate('identityChain.cache.clear()');pending=[]
            matcher=lambda url:parse_qs(urlparse(url).query).get('route')==['/api/identity/trace']
            page.route(matcher,lambda route:pending.append(route))
            trigger.hover();page.wait_for_function('() => !identityChainPopup.hidden && !identityChain.data')
            page.wait_for_timeout(200);assert len(pending)==1
            page.evaluate('navigate("customers")');assert popup.is_hidden()
            pending[0].continue_();page.unroute(matcher);page.wait_for_timeout(200);assert popup.is_hidden()
            assert all('@' not in url and '%40' not in url for url in trace_requests()),'Contact values never enter the request URL'
            assert {name:hashlib.sha256((data/name).read_bytes()).hexdigest() for name in before}==before,'Viewing a chain must not change existing database/review bytes'
            assert not errors,errors
            assert not external,external
            browser.close()
            print(json.dumps({'name_surname_noise':'removed','real_manual_and_account_paths':'passed','hover_click_keyboard_and_touch':'passed','viewport_sizes':[1470,390,320],'privacy_and_stale_responses':'passed','original_database_and_reviews':'byte_unchanged'}))
    finally:
        subprocess.run(['docker','rm','-f',container],check=False,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        try:server.wait(timeout=10)
        except subprocess.TimeoutExpired:
            server.kill();server.wait(timeout=10)

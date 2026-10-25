"""Shared sticky section navigation, settings insets and report layout with a synthetic API fixture."""
import json
import os
from pathlib import Path
import secrets
import socket
import subprocess
import tempfile
import time

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
            styles={}
            for width in [1470,390,320]:
                page.set_viewport_size({'width':width,'height':900})
                for area,views in [('sales',['overview-report','seasonality','geography','ga4']),('customers',['customers','actions','retention','segments','opportunities','duplicates','manual'])]:
                    page.click('#navigation [data-area='+area+']')
                    page.wait_for_function('() => !ordersLoading')
                    for view in views:
                        page.locator('#section-navigation [data-view="'+view+'"],#section-navigation [data-section-view="'+view+'"]').click()
                        page.wait_for_function('(view) => state.view===view && !ordersLoading',arg=view)
                        bar=page.locator('#section-navigation')
                        assert bar.evaluate('e=>getComputedStyle(e).position')=='sticky'
                        active=bar.locator('[aria-current=page]')
                        styles[area]=active.evaluate('e=>[getComputedStyle(e).backgroundColor,getComputedStyle(e).fontSize,getComputedStyle(e).minHeight]')
                        assert styles[area][0]=='rgb(24, 88, 62)',(width,view,styles[area])
                        assert page.evaluate('document.documentElement.scrollWidth<=innerWidth+1'),(width,view,'outer overflow')
                        page.evaluate('window.scrollTo(0,600)')
                        assert abs(bar.bounding_box()['y']-(8 if width>700 else 4))<=1,(width,view,bar.bounding_box())
                        for body in page.locator('#content .panel > p,#content .panel > ol,#content .panel > ul').all():
                            if body.is_visible():
                                assert body.evaluate('e=>e.getBoundingClientRect().x-e.parentElement.getBoundingClientRect().x+parseFloat(getComputedStyle(e).paddingLeft)')>=14,(width,view,'text flush to border')
                        if view==views[0]:
                            page.locator('#section-navigation [data-view="'+views[1]+'"]').click()
                            page.wait_for_function('(view) => state.view===view && !ordersLoading',arg=views[1])
                            assert abs(bar.bounding_box()['y']-(8 if width>700 else 4))<=1,(width,area,'pinned tab switch')
                        page.evaluate('window.scrollTo(0,0)')
                page.click('#navigation [data-area=settings]');page.wait_for_selector('#diagnostic-download')
                assert page.evaluate('document.documentElement.scrollWidth<=innerWidth+1')
                for body in page.locator('.panel-content').all():
                    inset=body.evaluate('e=>{const h=e.previousElementSibling,p=e.parentElement.getBoundingClientRect();return [e.firstElementChild.getBoundingClientRect().x-p.x,h.getBoundingClientRect().x-p.x+parseFloat(getComputedStyle(h).paddingLeft),parseFloat(getComputedStyle(e).paddingRight)]}')
                    assert inset[0]>=14 and abs(inset[0]-inset[1])<=1,(width,inset)
                    assert inset[2]>=14
                page.screenshot(path=str(ARTIFACTS/f'layout-settings-{width}.png'),full_page=True)
            page.set_viewport_size({'width':1470,'height':900})
            page.click('#navigation [data-area=competitors]');page.wait_for_selector('.competitor-tabs')
            assert styles['sales']==styles['customers']==page.locator('.competitor-tabs button.active').evaluate('e=>[getComputedStyle(e).backgroundColor,getComputedStyle(e).fontSize,getComputedStyle(e).minHeight]')
            page.click('#navigation [data-area=sales]');page.wait_for_function('() => !ordersLoading')
            page.locator('#section-navigation [data-section-view]').focus();page.keyboard.press('End')
            page.wait_for_function('() => state.view==="ga4"')
            assert page.locator('#section-navigation [aria-current=page]').evaluate('e=>e===document.activeElement')
            page.click('#navigation [data-area=customers]');page.wait_for_function('() => !ordersLoading')
            page.set_viewport_size({'width':390,'height':900})
            strip=page.locator('#section-navigation .section-tabs')
            page.locator('#section-navigation [data-section-scroll="1"]').click()
            page.wait_for_function('() => document.querySelector(".section-tabs").scrollLeft>100')
            page.screenshot(path=str(ARTIFACTS/'layout-customers-390.png'),full_page=True)
            # Audit all primary areas; wide report tables scroll inside their panels.
            for area in ['overview','sales','products','customers','competitors','settings']:
                page.click('#navigation [data-area='+area+']');page.wait_for_function('() => !ordersLoading')
                assert page.evaluate('document.documentElement.scrollWidth<=innerWidth+1'),area
            assert not errors,errors
            assert not external,external
            browser.close()
            print(json.dumps({'shared_sticky_navigation':'passed','settings_body_insets':'passed','all_section_widths':'passed','widths':[1470,390,320],'keyboard_and_arrows':'passed','browser_errors':errors}))
    finally:
        subprocess.run(['docker','stop',name],check=False,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        server.wait(timeout=20)

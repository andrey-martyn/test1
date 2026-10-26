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

def assert_sidebar_geometry(page, width):
    buttons=page.locator('#navigation [data-area]')
    assert buttons.count()==6
    centers=[]
    for button in buttons.all():
        icon=button.locator('.nav-icon svg')
        assert icon.count()==1 and icon.get_attribute('viewBox')=='0 0 24 24'
        outer=button.bounding_box();box=icon.bounding_box()
        assert abs(box['width']-24)<.2 and abs(box['height']-24)<.2,(width,box)
        assert outer['height']>=44 and abs(box['y']+12-outer['y']-outer['height']/2)<.6,(width,outer,box)
        centers.append(box['x']+12)
    if width>720:
        assert max(centers)-min(centers)<.2,centers
        labels=buttons.locator('.nav-label').evaluate_all('els=>els.map(e=>e.getBoundingClientRect().left)')
        assert max(labels)-min(labels)<.2,labels


def assert_tab_geometry(page, bar, width):
    arrows=bar.locator('.competitor-tab-arrow')
    assert arrows.count()==2
    boxes=[]
    for arrow in arrows.all():
        box=arrow.bounding_box();svg=arrow.locator('svg').bounding_box()
        assert abs(box['width']-44)<.2 and abs(box['height']-48)<.2,(width,box)
        assert abs(svg['x']+svg['width']/2-box['x']-box['width']/2)<.2,(box,svg)
        assert abs(svg['y']+svg['height']/2-box['y']-box['height']/2)<.2,(box,svg)
        boxes.append(box)
    strip=bar.locator('.section-tabs,.competitor-tabs').bounding_box()
    assert abs(boxes[0]['y']-boxes[1]['y'])<.2
    assert abs(strip['y']-boxes[0]['y'])<.2 and abs(strip['height']-48)<.2,(width,strip,boxes)


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
            page.wait_for_load_state('load')
            page.wait_for_function('() => [...document.querySelectorAll("link[rel=stylesheet]")].every(e=>e.sheet)')
            styles={}
            for width in [1470,390,320]:
                page.set_viewport_size({'width':width,'height':900})
                assert_sidebar_geometry(page,width)
                for area,views in [('sales',['overview-report','seasonality','geography','ga4']),('customers',['customers','actions','retention','segments','opportunities','duplicates','manual'])]:
                    page.click('#navigation [data-area='+area+']')
                    page.wait_for_function('() => !ordersLoading')
                    for view in views:
                        page.locator('#section-navigation [data-view="'+view+'"],#section-navigation [data-section-view="'+view+'"]').click()
                        page.wait_for_function('(view) => state.view===view && !ordersLoading',arg=view)
                        bar=page.locator('#section-navigation')
                        assert bar.evaluate('e=>getComputedStyle(e).position')=='sticky',(width,view,bar.evaluate('e=>({position:getComputedStyle(e).position,html:e.outerHTML.slice(0,200),sheets:[...document.styleSheets].map(s=>s.href)})'))
                        assert_tab_geometry(page,bar,width)
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
                page.click('#navigation [data-area=competitors]');page.wait_for_selector('.competitor-tabs')
                page.wait_for_function('() => !competitorState.loading && competitorState.data')
                bar=page.locator('.competitor-nav')
                assert_tab_geometry(page,bar,width)
                strip=bar.locator('.competitor-tabs')
                strip.evaluate('e=>e.scrollLeft=0')
                bar.locator('[data-comp-action=tabs-right]').click()
                page.wait_for_function('() => document.querySelector(".competitor-tabs").scrollLeft>0')
                assert_tab_geometry(page,bar,width)
                bar.locator('[data-comp-action=tabs-left]').click()
                page.wait_for_function('() => document.querySelector(".competitor-tabs").scrollLeft===0')
                page.screenshot(path=str(ARTIFACTS/f'navigation-competitors-{width}.png'))
                page.click('#navigation [data-area=settings]');page.wait_for_selector('#diagnostic-download')
                assert page.evaluate('document.documentElement.scrollWidth<=innerWidth+1')
                for body in page.locator('.panel-content').all():
                    inset=body.evaluate('e=>{const h=e.previousElementSibling,p=e.parentElement.getBoundingClientRect();return [e.firstElementChild.getBoundingClientRect().x-p.x,h.getBoundingClientRect().x-p.x+parseFloat(getComputedStyle(h).paddingLeft),parseFloat(getComputedStyle(e).paddingRight)]}')
                    assert inset[0]>=14 and abs(inset[0]-inset[1])<=1,(width,inset)
                    assert inset[2]>=14
                page.screenshot(path=str(ARTIFACTS/f'layout-settings-{width}.png'),full_page=True)
            page.set_viewport_size({'width':1470,'height':900})
            page.click('#navigation [data-area=customers]');page.wait_for_function('() => !ordersLoading')
            active=page.locator('#navigation [data-area=customers]')
            before=active.bounding_box()
            page.locator('#navigation [data-area=products]').hover()
            page.locator('.sidebar').screenshot(path=str(ARTIFACTS/'sidebar-pointer.png'))
            page.keyboard.press('Tab');active.focus()
            assert active.evaluate('e=>e.matches(":focus-visible")')
            focus=active.evaluate('e=>({offset:parseFloat(getComputedStyle(e).outlineOffset),width:parseFloat(getComputedStyle(e).outlineWidth),shadow:getComputedStyle(e).boxShadow})')
            assert focus['offset']+focus['width']<=0 and 'inset' in focus['shadow'],focus
            assert active.bounding_box()==before,'Keyboard focus must not move the selected row'
            page.locator('.sidebar').screenshot(path=str(ARTIFACTS/'sidebar-keyboard.png'))
            page.click('#navigation [data-area=products]');page.wait_for_function('() => !ordersLoading')
            active.click();page.wait_for_function('() => !ordersLoading')
            assert active.evaluate('e=>getComputedStyle(e).outlineStyle')=='none'
            assert active.bounding_box()==before,'Pointer selection must not move the selected row'
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
                assert_sidebar_geometry(page,390)
                assert page.locator('#business-journey').is_visible()==(area!='overview')
            # Contextual routes go straight to the task, independent of old filters.
            page.evaluate('() => {state.duplicateKind="email";state.duplicateCustomer="9999";}')
            page.locator('.business-journey-links [data-business-target=duplicates]').click()
            page.wait_for_function('() => state.view==="duplicates" && !ordersLoading')
            assert page.evaluate('state.duplicateKind==="all" && state.duplicateCustomer===null')
            assert 'Общий email может принадлежать семье или компании' in page.locator('#content').inner_text()
            page.evaluate('() => {state.crmScope="stopped";state.crmTier="bronze";state.crmDuplicates="yes";}')
            page.locator('.business-journey-links [data-business-target=actions]').click()
            page.wait_for_function('() => state.view==="actions" && !ordersLoading')
            assert page.evaluate('state.crmScope==="todo" && state.crmTier==="all" && state.crmDuplicates==="all"')
            page.locator('.business-journey-links [data-business-target="manual:conversation"]').click()
            page.wait_for_function('() => state.view==="manual" && !ordersLoading')
            assert page.evaluate('state.manualTab==="conversation" && state.manualSearch===""')
            page.locator('.business-journey-links [data-business-target=settings]').click()
            page.wait_for_selector('#diagnostic-download')
            page.locator('#business-journey summary').click()
            page.locator('#business-journey [data-business-target="manual:books"]').click()
            page.wait_for_function('() => state.view==="manual" && state.manualTab==="books" && !ordersLoading')
            page.locator('#business-journey summary').click()
            page.locator('#business-journey [data-business-target=manual]').click()
            page.wait_for_function('() => state.manualTab==="start" && !ordersLoading')
            page.go_back()
            page.wait_for_function('() => state.view==="manual" && state.manualTab==="books" && !uiNavigation.restoring')
            if not page.locator('#business-journey details').evaluate('e=>e.open'):
                page.locator('#business-journey summary').click()
            page.locator('#business-journey [data-business-target="competitors:terms"]').click()
            page.wait_for_function('() => state.view==="competitors" && competitorState.tab==="terms" && !competitorState.loading')
            page.locator('#business-journey summary').click()
            page.locator('#business-journey [data-business-target="competitors:products"]').click()
            page.wait_for_selector('.competitor-quality-note')
            assert 'Название и вес не подтверждают одинаковое качество' in page.locator('.competitor-quality-note').inner_text()
            assert page.evaluate('document.documentElement.scrollWidth<=innerWidth+1')
            page.locator('#business-journey summary').click()
            page.screenshot(path=str(ARTIFACTS/'contextual-directory-390.png'),full_page=True)
            assert not errors,errors
            assert not external,external
            browser.close()
            print(json.dumps({'shared_sticky_navigation':'passed','settings_body_insets':'passed','all_section_widths':'passed','widths':[1470,390,320],'keyboard_and_arrows':'passed','sidebar_svg_alignment_and_internal_focus':'passed','centered_fixed_arrow_controls':'passed','contextual_task_routes_and_section_directory':'passed','browser_errors':errors}))
    finally:
        subprocess.run(['docker','stop',name],check=False,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        server.wait(timeout=20)

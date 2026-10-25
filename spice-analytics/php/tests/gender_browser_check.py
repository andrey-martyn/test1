"""Local synthetic name estimates: actual report API, browser navigation and mobile layout."""
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

ROOT = Path(__file__).resolve().parents[2]
IMAGE = os.environ.get('ANALYTICS_PHP_IMAGE', 'lavka-php73-dev')
ARTIFACTS = Path(os.environ.get('ANALYTICS_TEST_OUTPUT', '/tmp/lavka-gender-browser'))
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
    name = 'lavka-gender-browser-' + secrets.token_hex(5)
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
            assert page.locator('#gender-control').is_hidden()
            page.click('#navigation [data-area=sales]')
            page.wait_for_selector('.next-step')
            page.locator('.gender-comparison').locator('..').locator(':scope > summary').click()
            page.select_option('#year','0')
            page.wait_for_function('() => state.report?.year===0 && state.report.metrics.amount===890')
            assert page.evaluate('state.report.gender_filter')=='all'
            assert page.locator('.gender-card').count()==3
            page.locator('#gender-help .help-trigger').hover()
            page.wait_for_selector('#help-popup:not([hidden])')
            assert 'приблизительная' in page.locator('#help-popup').inner_text()
            assert page.locator('#help-popup').bounding_box()['width']<=370
            assert not page.locator('#drawer').evaluate('(el)=>el.open')
            page.keyboard.press('Escape')
            page.screenshot(path=str(ARTIFACTS/'sales-all-1470.png'),full_page=True)

            for gender,amount,count in [('male',300,2),('female',350,2),('unknown',240,6)]:
                page.select_option('#gender',gender)
                page.wait_for_function('(g)=>state.report?.gender_filter===g && !ordersLoading',arg=gender)
                assert page.evaluate('state.report.metrics.amount')==amount
                assert page.evaluate('state.report.metrics.order_count')==count
                assert page.evaluate('state.report.gender_breakdown.reduce((s,r)=>s+r.amount,0)')==890
                assert page.locator('#gender-filter-note').is_visible()
                assert page.locator('.gender-card.selected').count()==1
            page.click('.gender-group[data-gender=male]')
            page.wait_for_function('() => state.report.gender_filter==="male" && !ordersLoading')
            page.go_back()
            page.wait_for_function('() => state.report.gender_filter==="unknown" && !ordersLoading')
            page.go_forward()
            page.wait_for_function('() => state.report.gender_filter==="male" && !ordersLoading')
            page.click('#navigation [data-area=products]')
            page.wait_for_selector('#product-mode')
            assert page.evaluate('state.report.metrics.amount')==300
            page.click('#navigation [data-area=customers]')
            page.wait_for_selector('.customer-table')
            assert page.evaluate('customerRows().length')==1
            with page.expect_download() as download:
                page.click('[data-action=export]')
            csv_path = ARTIFACTS/'synthetic-customers.csv'
            download.value.save_as(csv_path)
            csv_text = csv_path.read_text(encoding='utf-8-sig')
            assert 'Оценка пола по имени' in csv_text and 'Мужчины' in csv_text and 'Женщины' not in csv_text
            page.locator('.customer-table [data-action=customer]').first.click()
            page.wait_for_selector('#drawer .order-detail')
            assert page.locator('#drawer .order-detail').count()==2
            page.click('[data-action=close-dialog]')
            page.wait_for_function('() => !document.getElementById("drawer").open')
            page.click('#section-navigation [data-view=actions]')
            page.wait_for_function('() => state.view==="actions" && state.report.gender_filter==="all" && !ordersLoading')
            assert page.locator('#gender-control').is_hidden()
            assert page.evaluate('state.report.customers.length')==8

            page.click('#navigation [data-area=sales]')
            page.wait_for_function('() => state.view==="overview-report" && state.report.gender_filter==="male" && !ordersLoading')
            page.click('#section-navigation [data-view=seasonality]')
            assert page.evaluate('state.report.marketing.months.reduce((s,r)=>s+r.amount,0)')==300
            page.click('#section-navigation [data-view=geography]')
            assert page.evaluate('state.report.locations.reduce((s,r)=>s+r.amount,0)')==300
            page.click('#section-navigation [data-view=ga4]')
            page.wait_for_function('() => state.view==="ga4" && state.report.gender_filter==="all" && !ordersLoading')
            assert page.locator('#gender-control').is_hidden()
            page.click('#section-navigation [data-section-view=overview-report]')
            page.wait_for_function('() => state.report.gender_filter==="male" && !ordersLoading')

            # Hold a filtered response, leave for an unfiltered page, then release it.
            page.select_option('#gender','all')
            page.wait_for_function('() => state.report.gender_filter==="all" && !ordersLoading')
            held = []
            def delay_female(route):
                params = parse_qs(urlparse(route.request.url).query)
                if params.get('route')==['/api/report'] and params.get('gender')==['female']:
                    held.append(route)
                else:
                    route.continue_()
            page.route('**/index.php?**',delay_female)
            page.select_option('#gender','female')
            page.wait_for_timeout(150)
            assert len(held)==1
            page.click('#section-navigation [data-view=ga4]')
            held[0].continue_()
            page.wait_for_function('() => !ordersLoading')
            assert page.evaluate('state.report.gender_filter')=='all'
            assert page.locator('#content .loading').count()==0
            page.unroute('**/index.php?**',delay_female)
            page.click('#section-navigation [data-section-view=overview-report]')
            page.wait_for_function('() => state.report.gender_filter==="female" && !ordersLoading')
            page.select_option('#gender','all')
            page.wait_for_function('() => state.report.gender_filter==="all" && !ordersLoading')
            held.clear()
            page.route('**/index.php?**',delay_female)
            page.select_option('#gender','female')
            page.wait_for_timeout(150)
            assert len(held)==1
            page.click('#section-navigation [data-view=ga4]')
            held[0].fulfill(status=500,content_type='application/json',body=json.dumps({'detail':'synthetic late failure'}))
            page.wait_for_function('() => !ordersLoading')
            assert page.evaluate('state.report.gender_filter')=='all'
            assert 'synthetic late failure' not in page.locator('#content').inner_text()
            page.unroute('**/index.php?**',delay_female)
            page.click('#section-navigation [data-section-view=overview-report]')
            page.wait_for_function('() => state.report.gender_filter==="female" && !ordersLoading')
            for width in [390,320]:
                page.set_viewport_size({'width':width,'height':844})
                page.evaluate('window.scrollTo(0,0)')
                assert page.evaluate('document.documentElement.scrollWidth<=innerWidth'),width
                assert page.locator('#gender').evaluate('(el)=>getComputedStyle(el).fontSize')=='16px'
                assert page.locator('#gender').bounding_box()['height']>=44
                assert page.locator('.gender-group').evaluate_all('els=>els.every(el=>el.getBoundingClientRect().height>=44)')
                assert page.locator('.gender-card').evaluate_all('els=>els.every(el=>el.scrollWidth<=el.clientWidth+1)')
                page.screenshot(path=str(ARTIFACTS/f'sales-female-{width}.png'),full_page=True)
            page.select_option('#gender','male')
            page.wait_for_function('() => state.report.gender_filter==="male" && !ordersLoading')
            result = page.evaluate('''async()=>{
                const params=new URLSearchParams(query());params.set('gender','invalid');
                const response=await fetch(appUrl('/api/report?'+params));return response.status;
            }''')
            assert result==422
            assert not errors, errors
            assert not external, external
            browser.close()
            print(json.dumps({'sales_name_groups':'passed','report_filters_and_csv':'passed',
                              'history_and_scope_isolation':'passed','late_response_guard':'passed',
                              'mobile_390_320':'passed','javascript_errors':errors}))
    finally:
        subprocess.run(['docker','rm','-f',name],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        server.wait(timeout=15)

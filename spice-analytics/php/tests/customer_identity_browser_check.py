"""Surname-only suggestions and explicit arbitrary grouping, undo, privacy and CRM return flow."""
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
define('LAVKA_IDENTITY_FIXTURE_ONLY',true);
require 'php/tests/customer_identity_check.php';
$db=\Lavka\database('/test-data/analytics.sqlite3');\Lavka\init_schema($db);
\Lavka\apply_snapshot($db,ci_fixture(),'test');
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
            raise RuntimeError('Customer identity test server did not start')
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
            with sqlite3.connect(Path(temporary)/'analytics.sqlite3') as connection:
                raw_before=connection.execute('SELECT payload FROM raw_records ORDER BY rowid').fetchall()
            anonymous=browser.new_context()
            response=anonymous.request.get(base+'/index.php?route=/api/identity/customers&as_of=2026-12-31&search=Спільна')
            assert response.status==401
            anonymous.close()
            page.click('#navigation [data-area=customers]');page.wait_for_selector('.customer-table')
            page.fill('#as-of','2026-12-31');page.dispatch_event('#as-of','change')
            page.select_option('#year','2026');page.select_option('#gender','female')
            page.wait_for_function('() => !ordersLoading && state.report.year===2026 && state.report.as_of==="2026-12-31" && state.report.gender_filter==="female"')
            page.fill('#search','Спільна');page.wait_for_function('() => state.search==="Спільна"')
            page.click('#content [data-action=manual-merge-open]')
            page.wait_for_selector('#identity-picker[open]')
            page.wait_for_function('() => identityPicker.loaded && !identityPicker.loading')
            assert len(page.locator('[data-identity-pick]').all())==3
            assert page.locator('[data-identity-pick="102"]').count()==1,'Male card is searchable despite the previous female filter'
            assert page.locator('[data-identity-pick="101"]').count()==1,'Previous-year customer is available'
            assert 'Ірина' not in page.locator('#identity-picker-selection').inner_text()
            page.check('[data-identity-pick="101"]')
            page.fill('#identity-search','Інша')
            page.wait_for_function('() => !identityPicker.loading && identityPicker.rows.length===1 && identityPicker.rows[0].id==="103"')
            assert page.evaluate('identityPicker.selected.size')==1
            page.check('[data-identity-pick="103"]');assert page.evaluate('identityPicker.selected.size')==2
            page.click('#privacy');assert page.evaluate('!state.privacy')
            assert 'Ірина' in page.locator('#identity-picker-selection').inner_text() and 'Марія' in page.locator('#identity-picker-selection').inner_text()
            for width in [1470,390,320]:
                page.set_viewport_size({'width':width,'height':900})
                assert page.evaluate('document.documentElement.scrollWidth<=innerWidth+1'),width
                assert page.locator('[data-identity-action=merge]').bounding_box()['height']>=44
            page.screenshot(path=str(ARTIFACTS/'identity-selection-320.png'),full_page=True)
            pending=[];pattern='**/index.php?route=%2Fapi%2Fidentity%2Fmerge'
            page.route(pattern,lambda route:pending.append(route))
            page.click('[data-identity-action=merge]');page.wait_for_function('() => identityPicker.busy')
            assert len(pending)==1
            assert page.locator('#identity-search').is_disabled()
            assert page.locator('[data-identity-action=merge]').is_disabled()
            assert page.locator('.duplicate-panel').evaluate('(el)=>el.inert')
            pending[0].continue_();page.unroute(pattern)
            page.wait_for_selector('#drawer[open] .identity-contacts')
            page.wait_for_function('() => !identityPicker.busy')
            page.locator('.identity-contacts>summary').click()
            text=page.locator('.identity-contacts').inner_text()
            assert all(value in text for value in ['Ірина Спільна','Ірина Попередня','Марія Інша','person1@example.invalid','person3@example.invalid']),text
            assert page.evaluate('state.customer.customer.lifetime_count')==3
            # Start another grouping from a customer drawer on the duplicate page.
            page.click('#drawer [data-action=manual-merge-open]')
            page.wait_for_function('() => !document.getElementById("drawer").open && identityPicker.selected.has("101") && identityPicker.loaded')
            page.go_back();page.wait_for_selector('#drawer[open] [data-action=unmerge]')
            page.click('[data-action=unmerge]')
            page.wait_for_function('() => !document.getElementById("drawer").open && !ordersLoading')
            assert page.evaluate('state.report.customers.some(c=>c.id==="101") && state.report.customers.some(c=>c.id==="103")')
            page.go_back();page.wait_for_selector('#identity-picker')
            assert '/analytics/' in page.url and not page.locator('#password').count()
            page.set_viewport_size({'width':1470,'height':900})
            page.fill('#search','Спільна');page.select_option('#duplicate-kind','surname')
            page.wait_for_function('() => duplicateRows().length===3')
            assert page.locator('[data-pair="101:102"]').count()==1
            page.check('[data-pair="101:102"]')
            page.locator('[data-action=merge-selected]').first.click()
            page.wait_for_function('() => !state.mergeBusy && state.report.customers.some(c=>c.id==="101" && c.names.includes("Олег Спільна"))')
            assert page.evaluate('state.report.customers.find(c=>c.id==="101").gender_estimate')=='unknown'
            # Undo the surname-only test merge, then exercise the CRM source flow.
            page.evaluate('openCustomer("101")');page.wait_for_selector('#drawer [data-action=unmerge]')
            page.click('[data-action=unmerge]');page.wait_for_function('() => !document.getElementById("drawer").open && !ordersLoading')
            page.evaluate('openCRM("101")');page.wait_for_selector('#crm-event-form')
            page.fill('#crm-event-form [name=note]','Синтетический незаписанный черновик')
            page.click('#drawer [data-action=manual-merge-open]')
            page.wait_for_selector('#identity-picker[open]')
            page.wait_for_function('() => !identityPicker.loading && identityPicker.selected.has("101")')
            page.fill('#identity-search','Інша');page.wait_for_selector('[data-identity-pick="103"]')
            page.check('[data-identity-pick="103"]');page.click('[data-identity-action=merge]')
            page.wait_for_selector('#drawer #crm-event-form')
            assert page.input_value('#crm-event-form [name=note]')=='Синтетический незаписанный черновик'
            assert page.locator('#drawer .identity-contacts').count()==1
            with sqlite3.connect(Path(temporary)/'analytics.sqlite3') as connection:
                assert connection.execute('SELECT payload FROM raw_records ORDER BY rowid').fetchall()==raw_before
                assert connection.execute('SELECT count(*) FROM manual_links').fetchone()[0]==1
            assert not errors,errors
            assert not external,external
            browser.close()
            print(json.dumps({'manual_unrelated_cards':'passed','surname_only_and_fullname_field':'passed','filters_and_currencies':'passed','undo_and_source_preservation':'passed','mobile_widths':[390,320],'crm_draft_and_return':'passed','privacy_and_auth':'passed'}))
    finally:
        subprocess.run(['docker','stop',name],check=False,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        server.wait(timeout=20)

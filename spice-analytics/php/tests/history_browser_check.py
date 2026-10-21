"""Synthetic-only browser checks for history, help, vertical charts and pair batches."""
from copy import deepcopy
import json, os, secrets, socket, subprocess, sys, tempfile, time
from pathlib import Path
import httpx
from playwright.sync_api import sync_playwright

ROOT=Path(__file__).resolve().parents[2]
sys.path[:0]=[str(ROOT),str(ROOT/'tests')]
from test_production import snapshot
from sync_mysql import apply_snapshot
IMAGE=os.environ.get('ANALYTICS_PHP_IMAGE','lavka-php73-dev')
name='lavka-history-browser-'+secrets.token_hex(5)
with tempfile.TemporaryDirectory() as temporary:
    data=Path(temporary);base_rows=snapshot();rows={t:{} for t in base_rows};rows['oc_order_status']=base_rows['oc_order_status']
    for oid,date,account,group in [('101','2021-01-01','101','A'),('102','2022-07-01','102','A'),('103','2025-12-31','103','B'),('104','2026-01-01','104','B'),('105','2025-01-01','101','A')]:
        order=deepcopy(base_rows['oc_order']['1']);order.update(order_id=oid,customer_id=account,date_added=date+' 12:00:00',date_modified=date+' 12:00:00',email='pair'+group+'@example.invalid',telephone='+38096'+account.zfill(7),firstname='Контакт',lastname='Пара'+group)
        rows['oc_order'][oid]=order
        line=deepcopy(base_rows['oc_order_product']['1']);line.update(order_product_id=oid,order_id=oid);rows['oc_order_product'][oid]=line
        option=deepcopy(base_rows['oc_order_option']['1']);option.update(order_option_id=oid,order_id=oid,order_product_id=oid);rows['oc_order_option'][oid]=option
    apply_snapshot(data/'analytics.sqlite3',rows,'test')
    password=secrets.token_urlsafe(24)
    docker=['docker','run','--rm','--user',f'{os.getuid()}:{os.getgid()}','-v',str(ROOT)+':/app:ro','-v',temporary+':/test-data','-e','ANALYTICS_DATA_DIR=/test-data','-e','ANALYTICS_BASE_PATH=/analytics']
    subprocess.run(docker+['-i',IMAGE,'php','-r','require "php/src/Auth.php"; \\Lavka\\Auth::setPassword("/test-data",stream_get_contents(STDIN));'],input=password.encode(),check=True,stdout=subprocess.DEVNULL)
    with socket.socket() as listener:listener.bind(('127.0.0.1',0));port=listener.getsockname()[1]
    server=subprocess.Popen(docker+['--name',name,'-p',f'127.0.0.1:{port}:8000',IMAGE,'php','-S','0.0.0.0:8000','-t','php/public','php/public/index.php'],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    origin=f'http://127.0.0.1:{port}';base=origin+'/analytics'
    try:
        for _ in range(600):
            try:
                if httpx.get(base+'/index.php?route=/health',trust_env=False,timeout=2).status_code==200:break
            except httpx.TransportError:pass
            time.sleep(.1)
        else:raise RuntimeError('History test server did not start')
        with sync_playwright() as playwright:
            browser=playwright.chromium.launch(executable_path='/usr/bin/chromium',args=['--no-sandbox'])
            page=browser.new_page(viewport={'width':1536,'height':1000},has_touch=True);errors=[];misplaced=[]
            page.on('pageerror',lambda error:errors.append(str(error)))
            page.on('request',lambda req:misplaced.append(req.url) if req.url.startswith(origin+'/') and not req.url.startswith(base+'/') else None)
            page.goto(base+'/');page.fill('#password',password);page.click('button[type=submit]');page.wait_for_selector('.customer-table')
            page.select_option('#year','0');page.wait_for_function('() => state.report.year===0')
            assert page.locator('.customer-years').count()==1
            assert page.evaluate('state.report.metrics.amount')==500
            assert page.locator('.annual-table tbody tr').count()==6
            assert page.evaluate('state.report.calendar.length')==6
            page.screenshot(path=str(ROOT/'test-results/history-customers.png'),full_page=True)
            page.locator('.heat.active').first.click();page.wait_for_selector('#drawer .order-detail');page.click('[data-action=close-dialog]')
            if not page.locator('#history-summary').evaluate('(el)=>el.open'):page.locator('#history-summary>summary').click()
            page.locator('[data-action=history-year][data-year="2025"]').click();page.wait_for_function('() => state.report.year===2025')
            assert page.evaluate('state.report.metrics.amount')==200
            page.select_option('#year','0');page.wait_for_function('() => state.report.year===0')
            page.click('[data-view=seasonality]');assert page.locator('[data-chart=months] [data-chart-bar]').count()==12
            page.select_option('#season-metric','amount')
            assert page.evaluate('[...document.querySelectorAll("[data-chart=months] [data-chart-bar]")].reduce((s,b)=>s+Number(b.dataset.value),0)')==500
            page.check('#season-years');assert page.locator('[data-chart=months] [data-chart-bar]').count()==72
            help_button=page.locator('.panel-heading .help-trigger[data-help-title="Месяцы года"]');help_button.hover()
            page.wait_for_selector('#help-popup:not([hidden])');assert 'Вертикальные столбики' in page.locator('#help-popup').inner_text()
            box=page.locator('#help-popup').bounding_box();assert box['x']>=0 and box['x']+box['width']<=1536
            page.screenshot(path=str(ROOT/'test-results/history-help.png'))
            page.keyboard.press('Escape');page.evaluate('window.scrollTo(0,0)')
            page.screenshot(path=str(ROOT/'test-results/history-seasonality.png'),full_page=True)
            help_button.focus();page.keyboard.press('Escape');assert page.locator('#help-popup').is_hidden()
            page.click('[data-view=duplicates]');assert page.locator('.pair-checkbox').count()==2
            page.select_option('#duplicate-kind','email');assert page.locator('.pair-checkbox').count()==2
            page.click('[data-action=duplicate-sort][data-key=left]');assert page.locator('th[aria-sort=ascending]').count()==1
            page.click('[data-action=duplicate-sort][data-key=left]');assert page.locator('th[aria-sort=descending]').count()==1
            page.check('#duplicate-select-all');assert page.evaluate('state.duplicateSelected.size')==2
            assert page.evaluate('duplicateSelectionInfo(selectedDuplicateRows()).groups')==2
            page.screenshot(path=str(ROOT/'test-results/history-duplicates.png'),full_page=True)
            page.click('[data-action=merge-selected]');page.wait_for_function('() => state.report.customers.length===2 && !state.mergeBusy')
            assert page.evaluate('state.report.metrics.amount')==500
            assert page.locator('.pair-checkbox').count()==0
            page.click('[data-view=customers]');assert page.evaluate('state.report.history_totals.customer_count')==2
            assert page.evaluate('state.report.annual.reduce((s,y)=>s+y.customer_count,0)')==5
            page.locator('[data-action=customer]').first.click();page.wait_for_selector('#drawer .order-detail')
            page.locator('#drawer .help-trigger').first.hover();assert page.locator('#drawer #help-popup').is_visible()
            page.keyboard.press('Escape');assert page.locator('#drawer').is_visible()
            page.click('[data-action=unmerge]');page.wait_for_function('() => state.report.customers.length===3')
            page.set_viewport_size({'width':390,'height':844});page.click('[data-view=seasonality]')
            assert page.evaluate('document.documentElement.scrollWidth<=innerWidth')
            period_help=page.locator('#period-help .help-trigger');period_help.tap();assert page.locator('#help-popup').is_visible();period_help.tap();assert page.locator('#help-popup').is_hidden()
            period_help.tap();page.keyboard.press('Escape');period_help.tap();assert page.locator('#help-popup').is_visible();period_help.tap();assert page.locator('#help-popup').is_hidden()
            page.screenshot(path=str(ROOT/'test-results/history-mobile.png'),full_page=True)
            page.click('[data-view=settings]');field=page.locator('label.field').first
            assert field.locator('.field-title .help-trigger').count()==1
            field.locator('.help-trigger').tap();assert page.locator('#help-popup').is_visible();page.keyboard.press('Escape')
            assert not errors,errors
            assert not misplaced,misplaced
            browser.close()
        print(json.dumps({'php_image':IMAGE,'all_time_calendar_and_year_drilldown':'passed','monthly_vertical_and_year_comparison':'passed','hover_keyboard_touch_help':'passed','independent_row_merges_and_undo':'passed','mobile_and_mount':'passed'}))
    finally:
        subprocess.run(['docker','rm','-f',name],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL);server.wait(timeout=15)

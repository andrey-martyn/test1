"""Local browser smoke test, using a random disposable login password."""
import json
import atexit
import os
PHP_IMAGE = os.environ.get('ANALYTICS_PHP_IMAGE', 'lavka-php-dev')
from pathlib import Path
import secrets
import shutil
import socket
import subprocess
import sys
import tempfile
import time
import httpx
sys.path.insert(0,str(Path(__file__).resolve().parents[1]))
import app as application
from playwright.sync_api import sync_playwright

ROOT=Path(__file__).resolve().parents[1]
password=secrets.token_urlsafe(24)
temporary=tempfile.TemporaryDirectory()
directory=Path(temporary.name)
shutil.copy2(ROOT/'data'/'analytics.sqlite3',directory/'analytics.sqlite3')
application.DATA=directory;application.AUTH_FILE=directory/'access.json'
application.set_password(password)
with socket.socket() as listener:
    listener.bind(('127.0.0.1',0));port=listener.getsockname()[1]
runtime=os.environ.get('ANALYTICS_TEST_RUNTIME','python')
mount=os.environ.get('ANALYTICS_TEST_BASE','') if runtime=='php' else ''
container=None
if runtime=='php':
    container='lavka-php-browser-'+secrets.token_hex(5)
    docker=['docker','run','--rm','--user',f'{os.getuid()}:{os.getgid()}','-v',str(ROOT)+':/app:ro','-v',str(directory)+':/test-data','-e','ANALYTICS_DATA_DIR=/test-data','-e','ANALYTICS_BASE_PATH='+mount]
    subprocess.run(docker+['-i',PHP_IMAGE,'php','-r','require "php/src/Auth.php"; \\Lavka\\Auth::setPassword(getenv("ANALYTICS_DATA_DIR"), stream_get_contents(STDIN));'],input=password.encode(),check=True,stdout=subprocess.DEVNULL)
    command=docker+['--name',container,'-p',f'127.0.0.1:{port}:8000',PHP_IMAGE,'php','-d','memory_limit=256M','-S','0.0.0.0:8000','-t','php/public','php/public/index.php']
else:
    command=[sys.executable,'-m','uvicorn','app:app','--host','127.0.0.1','--port',str(port),'--no-access-log']
server=subprocess.Popen(command,cwd=ROOT,env={**os.environ,'ANALYTICS_DATA_DIR':str(directory)},stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
def cleanup():
    if container:subprocess.run(['docker','rm','-f',container],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    else:server.terminate()
    server.wait(timeout=10);temporary.cleanup()
atexit.register(cleanup)
base=f'http://127.0.0.1:{port}'+mount
def route(path):return '/index.php?route='+path if mount else path
for attempt in range(600):
    try:
        if httpx.get(base+route('/health'),trust_env=False,timeout=2).status_code==200:break
    except httpx.TransportError:
        pass
    time.sleep(.1)
else:
    raise RuntimeError('Test server did not start')
out=ROOT/'test-results';out.mkdir(exist_ok=True)
errors=[]
checks=[]
with sync_playwright() as p:
    browser=p.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox'])
    context=browser.new_context(viewport={'width':1536,'height':1000},locale='ru-RU')
    page=context.new_page();page.on('pageerror',lambda e:errors.append(str(e)))
    misplaced=[]
    if mount:page.on('request',lambda r: misplaced.append(r.url) if r.url.startswith(f'http://127.0.0.1:{port}/') and not r.url.startswith(base+'/') else None)
    page.goto(base+'/')
    assert '/login' in page.url or 'route=%2Flogin' in page.url
    page.fill('#password',password);page.click('button[type=submit]')
    page.wait_for_selector('.customer-table tbody .identity-name')
    checks.append('password login and weekly customer table')
    page.click('[data-action=order-preset][data-min="5"]')
    expected=page.evaluate('state.report.customers.filter(c=>c.lifetime_count>=5&&c.order_count>0).length')
    assert str(expected) in page.locator('main .pagination>span').inner_text()
    page.click('[data-action=single-orders]')
    assert page.evaluate('customerRows().every(c=>c.lifetime_count===1)')
    page.click('[data-action=clear-customer-filters]')
    page.fill('#orders-min','2');page.press('#orders-min','Tab')
    page.fill('#orders-max','4');page.press('#orders-max','Tab')
    assert page.evaluate('customerRows().length>0 && customerRows().every(c=>c.lifetime_count>=2&&c.lifetime_count<=4)')
    page.click('[data-action=clear-customer-filters]')
    checks.append('minimum/maximum purchases and 5+ / one-order presets')
    page.screenshot(path=str(out/'customers.png'),full_page=True)
    page.screenshot(path=str(out/'customers-preview.png'))
    page.locator('[data-action=customer]').first.click()
    page.wait_for_selector('#drawer .order-detail')
    assert page.locator('#drawer .drawer-name').inner_text().startswith('Покупатель')
    checks.append('customer card and order lines')
    page.click('[data-action=close-dialog]')
    page.locator('.heat.active').first.click();page.wait_for_selector('#drawer .order-detail[open]')
    checks.append('week opens actual orders')
    page.click('[data-action=close-dialog]')
    page.fill('#search','__no_customer__');page.wait_for_timeout(250)
    assert 'не найдены' in page.locator('.customer-table').inner_text()
    page.fill('#search','');page.wait_for_timeout(250)
    with page.expect_download() as info:
        page.click('[data-action=export]')
    info.value.save_as(str(out/'customers.csv'))
    checks.append('search and CSV export')
    for view in ['overview','products','geography','duplicates','settings','retention','segments','actions','opportunities','seasonality','ga4']:
        page.click('[data-view='+view+']')
        page.wait_for_selector('.panel')
        if view=='geography':
            page.wait_for_selector('#map path.map-region')
            assert page.locator('#map path.map-region').count()==27
            page.screenshot(path=str(out/'geography.png'),full_page=True)
            page.screenshot(path=str(out/'geography-preview.png'))
            page.select_option('#map-mode','europe');page.wait_for_selector('#map svg')
            page.wait_for_timeout(500)
            assert page.locator('#map path.map-region').count()>100
        if view=='products':
            page.screenshot(path=str(out/'products.png'),full_page=True)
            page.screenshot(path=str(out/'products-preview.png'))
            page.select_option('#product-mode','products');assert 'вариантов' in page.locator('#content tbody').inner_text()
        if view=='settings':
            page.fill('[name=interval_days]','31');page.click('button[type=submit]')
            page.wait_for_selector('#toast:not(.hidden)')
            page.fill('[name=interval_days]','30');page.click('button[type=submit]')
            page.wait_for_timeout(300)
        if view=='retention':
            assert 'M12' in page.locator('.cohort-table thead').inner_text()
            assert page.locator('.not-observed').count()>0
            page.screenshot(path=str(out/'retention-preview.png'))
        if view=='segments':
            assert page.locator('.segment-card').count()==8
            page.screenshot(path=str(out/'segments-preview.png'))
            page.locator('.rfm-cell:not([disabled])').first.click()
            assert page.locator('#page-title').inner_text()=='Покупатели'
            page.click('[data-view=segments]')
        if view=='actions':
            page.click('[data-action=queue-min][data-min="5"]')
            assert page.evaluate('actionRows().every(q=>q.customer.lifetime_count>=5)')
            page.locator('main [data-action=customer]').first.click()
            page.wait_for_selector('#action-form')
            page.select_option('#action-form [name=stage]','do_not_contact')
            page.fill('#action-form [name=note]','Browser test on isolated copy')
            page.click('#action-form button')
            page.wait_for_function("() => document.querySelector('#action-form [name=stage]')?.value==='do_not_contact' && document.querySelector('#toast')?.textContent.includes('Отметка')")
            assert page.evaluate('state.customer.customer.followup_eligible===false')
            page.click('[data-action=close-dialog]')
            checks.append('CRM note persists and do-not-contact removes follow-up eligibility')
        if view=='seasonality':
            assert page.locator('.hours-table tbody tr').count()==7
        if view=='ga4':
            daily='date,channel,sessions,ecommercePurchases,purchaseRevenue,currencyCode\n20261019,Organic Search,100,1,3510,UAH\n20261019,Paid Search,50,0,0,UAH\n'
            page.set_input_files('#ga4-file',{'name':'daily.csv','mimeType':'text/csv','buffer':daily.encode()})
            page.click('[data-action=import-ga4]')
            page.wait_for_function('() => state.report.ga4.daily_available===true')
            assert page.evaluate('state.report.ga4.totals.sessions')==150
            page.select_option('#ga4-kind','transactions')
            tx='date,transactionId,purchaseRevenue,currencyCode\n20261019,4923,3510,UAH\n'
            page.set_input_files('#ga4-file',{'name':'transactions.csv','mimeType':'text/csv','buffer':tx.encode()})
            page.click('[data-action=import-ga4]')
            page.wait_for_function('() => state.report.ga4.transactions_available===true')
            assert page.evaluate('state.report.ga4.matched.length')==1
            checks.append('GA4 CSV import, channels and transaction-to-order reconciliation')
        checks.append(view+' view')
    page.click('[data-view=customers]')
    page.select_option('#year','2025')
    if runtime=='php':page.wait_for_function('() => state.report.year===2025')
    else:page.wait_for_timeout(500)
    assert page.evaluate('state.report.year===2025') if runtime=='php' else '2025' in page.locator('.customer-table thead').inner_text()
    page.select_option('#year','2026')
    if runtime=='php':page.wait_for_function('() => state.report.year===2026')
    else:page.wait_for_timeout(500)
    page.select_option('#orders-basis','order_count')
    page.click('[data-action=show-overdue]')
    assert page.locator('#reminder').input_value()=='overdue'
    assert not page.locator('#period-only').is_checked()
    assert page.locator('#orders-basis').input_value()=='lifetime_count'
    assert page.evaluate('customerRows().length===state.report.metrics.overdue')
    checks.append('year filter and overdue customer filter')
    page.set_viewport_size({'width':390,'height':844});page.screenshot(path=str(out/'mobile.png'),full_page=True)
    assert page.evaluate('document.documentElement.scrollWidth <= window.innerWidth')
    checks.append('mobile layout without horizontal page overflow')
    page.set_viewport_size({'width':1536,'height':1000})
    page.click('[data-view=settings]');page.click('#logout');page.wait_for_url(lambda u:'/login' in u or 'route=%2Flogin' in u)
    assert not misplaced,misplaced
    checks.append('logout')
    browser.close()
assert not errors,errors
print(json.dumps({'passed':checks,'javascript_errors':errors},ensure_ascii=False,indent=2))

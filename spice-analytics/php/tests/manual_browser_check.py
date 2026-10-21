"""Synthetic manual checks: live segments, real navigation, sources, print and mobile."""
from copy import deepcopy
from pathlib import Path
import json,os,secrets,socket,subprocess,sys,tempfile,time
import httpx
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parents[2];sys.path[:0]=[str(ROOT),str(ROOT/'tests')]
from test_production import snapshot
from sync_mysql import apply_snapshot
IMAGE=os.environ.get('ANALYTICS_PHP_IMAGE','lavka-php73-dev')
name='lavka-manual-'+secrets.token_hex(5)
with tempfile.TemporaryDirectory() as tmp:
 data=Path(tmp);fixture=snapshot();rows={k:{} for k in fixture};rows['oc_order_status']=fixture['oc_order_status'];oid=0
 dates=[['2026-10-10'],['2026-08-20'],['2026-09-30','2026-10-04','2026-10-08','2026-10-12','2026-10-19'],['2026-09-30','2026-10-10','2026-10-19'],['2026-06-01','2026-06-10','2026-06-20','2026-06-30','2026-07-10'],['2026-08-01','2026-08-10','2026-08-20'],['2026-01-01','2026-01-15'],['2026-01-01']]
 for account,days in enumerate(dates,1):
  for day in days:
   oid+=1;key=str(oid);a=str(account)
   order=deepcopy(fixture['oc_order']['1']);order.update(order_id=key,customer_id=a,firstname='Синтетический',lastname='Покупатель'+a,email='manual'+a+'@example.invalid',telephone='+38096'+a.zfill(7),date_added=day+' 12:00:00',date_modified=day+' 12:00:00');rows['oc_order'][key]=order
   line=deepcopy(fixture['oc_order_product']['1']);line.update(order_product_id=key,order_id=key);rows['oc_order_product'][key]=line
   option=deepcopy(fixture['oc_order_option']['1']);option.update(order_option_id=key,order_id=key,order_product_id=key);rows['oc_order_option'][key]=option
 apply_snapshot(data/'analytics.sqlite3',rows,'test');password=secrets.token_urlsafe(24)
 docker=['docker','run','--rm','--user',f'{os.getuid()}:{os.getgid()}','-v',str(ROOT)+':/app:ro','-v',tmp+':/test-data','-e','ANALYTICS_DATA_DIR=/test-data','-e','ANALYTICS_BASE_PATH=/analytics']
 subprocess.run(docker+['-i',IMAGE,'php','-r','require "php/src/Auth.php"; \\Lavka\\Auth::setPassword("/test-data",stream_get_contents(STDIN));'],input=password.encode(),check=True,stdout=subprocess.DEVNULL)
 with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
 server=subprocess.Popen(docker+['--name',name,'-p',f'127.0.0.1:{port}:8000',IMAGE,'php','-S','0.0.0.0:8000','-t','php/public','php/public/index.php'],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
 origin=f'http://127.0.0.1:{port}';base=origin+'/analytics'
 try:
  for _ in range(600):
   try:
    if httpx.get(base+'/index.php?route=/health',trust_env=False,timeout=2).status_code==200:break
   except httpx.TransportError:pass
   time.sleep(.1)
  else:raise RuntimeError('Manual server did not start')
  with sync_playwright() as p:
   browser=p.chromium.launch(executable_path='/usr/bin/chromium',args=['--no-sandbox']);page=browser.new_page(viewport={'width':1536,'height':1000});errors=[];outside=[];mutations=[]
   page.on('pageerror',lambda e:errors.append(str(e)));page.on('request',lambda r:outside.append(r.url) if not r.url.startswith(base+'/') else None)
   page.goto(base+'/');page.fill('#password',password);page.click('button[type=submit]');page.wait_for_selector('.customer-table')
   page.fill('#as-of','2026-10-20');page.dispatch_event('#as-of','change');page.wait_for_function('() => state.report.as_of==="2026-10-20"')
   assert page.evaluate('state.report.marketing.segments.every(s=>s.count===1)')
   page.on('request',lambda r:mutations.append(r.url) if r.method not in ['GET','HEAD'] else None)
   page.click('[data-view=manual]');page.wait_for_selector('.manual-start');assert page.locator('#metrics').is_hidden();assert page.locator('#year').is_disabled()
   assert page.locator('#page-title').inner_text()=='Практика возврата и удержания'
   out=ROOT/'test-results';out.mkdir(exist_ok=True);page.screenshot(path=str(out/'manual-start.png'),full_page=True)
   page.locator('#manual-tab-scenarios').click();assert page.locator('.manual-segment-map button').count()==8;assert page.locator('.manual-case-list>.manual-scenario').count()==8
   assert page.locator('.manual-segment-map strong').all_text_contents()==['1']*8
   page.select_option('#manual-segment','vip_risk');assert page.locator('.manual-case-list>.manual-scenario').count()==1
   page.locator('#manual-case-vip_risk [data-action=segment]').click();assert page.evaluate('state.view==="customers" && state.segment==="vip_risk" && customerRows().length===1')
   assert page.locator('#metrics').is_visible() and page.locator('#year').is_enabled()
   page.click('[data-view=manual]');page.select_option('#manual-segment','all')
   page.fill('#as-of','2026-08-15');page.dispatch_event('#as-of','change');page.wait_for_function('() => state.report.as_of==="2026-08-15"')
   assert page.locator('.manual-segment-map strong').all_text_contents()==page.evaluate('manualContent.segments.map(c=>String(manualLiveSegment(c.id).count))')
   assert page.locator('.manual-segment-map strong').all_text_contents()!=['1']*8
   page.fill('#as-of','2026-10-20');page.dispatch_event('#as-of','change');page.wait_for_function('() => state.report.as_of==="2026-10-20"')
   page.select_option('#manual-segment','loyal');page.screenshot(path=str(out/'manual-scenario.png'),full_page=True)
   page.locator('#manual-case-loyal .manual-source[data-source=avrin]').click();assert page.locator('#manual-book-avrin').is_visible()
   assert page.locator('.manual-book-status.available').count()==3;assert page.locator('.manual-book-status:not(.available)').count()==3
   assert 'Рафел' in page.locator('#manual-book-rafel').inner_text() and 'гл. 22' in page.locator('#manual-book-avrin').inner_text().lower()
   page.fill('#manual-search','дорого');page.wait_for_selector('.manual-search-results');page.get_by_role('button',name='Разговор и возражения «Дорого» Открыть →').click()
   assert page.locator('#manual-topic-price').is_visible() and page.locator('#manual-search').input_value()==''
   benefit=page.evaluate('manualContent.benefits[0]');page.fill('#manual-search',benefit);page.wait_for_selector('.manual-search-results')
   page.locator('.manual-search-results [data-id=benefits]').click();assert page.locator('#manual-topic-benefits').is_visible()
   page.fill('#manual-search','<script>window.manualInjected=true</script>');page.wait_for_selector('.manual-search-results .empty');assert not page.evaluate('!!window.manualInjected')
   page.locator('[data-action=manual-clear-search]').click();page.locator('#manual-tab-start').focus();page.keyboard.press('ArrowRight');assert page.locator('#manual-tab-scenarios').get_attribute('aria-selected')=='true'
   page.locator('#manual-tab-loyalty').click();assert page.locator('.manual-content .manual-topics>.manual-topic').count()==8
   page.screenshot(path=str(out/'manual-loyalty.png'),full_page=True)
   page.locator('.manual-content [data-action=segment][data-segment=loyal]').click();assert page.evaluate('state.view==="customers" && state.segment==="loyal" && customerRows().length===1')
   page.click('[data-view=manual]');page.locator('#manual-tab-measurement').click();page.locator('.manual-content [data-action=manual-go]').click();assert page.locator('.crm-queue').is_visible()
   page.click('[data-view=manual]');page.emulate_media(media='print');assert page.locator('.manual-print-all').is_visible() and page.locator('.manual-content').is_hidden()
   assert page.locator('.manual-print-all .manual-scenario').count()==8 and page.locator('.sidebar').is_hidden()
   printable=page.locator('.manual-print-all').inner_text()
   assert all(t in printable for t in page.evaluate('manualContent.benefits'))
   assert all(a in printable and b in printable for a,b in page.evaluate('manualContent.checklist'))
   assert 'Текст пока не предоставлен' in printable and 'Аврин · гл.' in printable
   page.pdf(path=str(out/'manual-synthetic.pdf'),format='A4',print_background=True);page.emulate_media(media='screen')
   page.set_viewport_size({'width':390,'height':844});page.locator('#manual-tab-scenarios').click();page.select_option('#manual-segment','sleeping')
   assert page.evaluate('document.documentElement.scrollWidth<=innerWidth')
   page.screenshot(path=str(out/'manual-mobile.png'),full_page=True)
   page.locator('#manual-tab-books').click();assert page.evaluate('document.documentElement.scrollWidth<=innerWidth')
   assert not errors,errors;assert not mutations,mutations;assert not outside,outside
   browser.close()
  print(json.dumps({'manual_eight_live_segments':'passed','scenario_customer_and_crm_navigation':'passed','provided_book_sources_and_search':'passed','keyboard_print_and_mobile':'passed','manual_mutations':'none','php_image':IMAGE}))
 finally:
  subprocess.run(['docker','rm','-f',name],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL);server.wait(timeout=15)

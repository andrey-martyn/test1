"""Synthetic CRM lifecycle: warning, conversation, manual AI, sync and return."""
from copy import deepcopy
from pathlib import Path
import json, os, secrets, socket, subprocess, sys, tempfile, time
import httpx
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parents[2];sys.path[:0]=[str(ROOT),str(ROOT/'tests')]
from test_production import snapshot
from sync_mysql import apply_snapshot
IMAGE=os.environ.get('ANALYTICS_PHP_IMAGE','lavka-php73-dev')
name='lavka-crm-browser-'+secrets.token_hex(5)
with tempfile.TemporaryDirectory() as tmp:
 data=Path(tmp);base_rows=snapshot();rows={t:{} for t in base_rows};rows['oc_order_status']=base_rows['oc_order_status']
 for oid,account,total in [('1','1','500'),('2','1','300'),('3','2','200'),('4','2','100'),('5','3','40'),('6','3','60')]:
  order=deepcopy(base_rows['oc_order']['1']);order.update(order_id=oid,customer_id=account,firstname='Тест',lastname='Покупатель'+account,email='shared@example.invalid' if account!='3' else 'third@example.invalid',telephone='+38096'+account.zfill(7),total=total,date_added='2025-0'+str(1+int(oid)%2)+'-01 12:00:00',date_modified='2025-02-01 12:00:00')
  rows['oc_order'][oid]=order
  line=deepcopy(base_rows['oc_order_product']['1']);line.update(order_product_id=oid,order_id=oid,name='Кориандр',total=total);rows['oc_order_product'][oid]=line
  option=deepcopy(base_rows['oc_order_option']['1']);option.update(order_option_id=oid,order_id=oid,order_product_id=oid);rows['oc_order_option'][oid]=option
 apply_snapshot(data/'analytics.sqlite3',rows,'test')
 password=secrets.token_urlsafe(24);docker=['docker','run','--rm','--user',f'{os.getuid()}:{os.getgid()}','-v',str(ROOT)+':/app:ro','-v',tmp+':/test-data','-e','ANALYTICS_DATA_DIR=/test-data','-e','ANALYTICS_BASE_PATH=/analytics']
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
  else:raise RuntimeError('CRM server did not start')
  with sync_playwright() as play:
   browser=play.chromium.launch(executable_path='/usr/bin/chromium',args=['--no-sandbox'])
   context=browser.new_context(viewport={'width':1536,'height':1000},permissions=['clipboard-read','clipboard-write'])
   page=context.new_page();errors=[];external=[]
   page.on('pageerror',lambda e:errors.append(str(e)));page.on('request',lambda r:external.append(r.url) if not r.url.startswith(origin+'/analytics/') else None)
   page.goto(base+'/');page.fill('#password',password);page.click('button[type=submit]');page.wait_for_selector('.customer-table')
   page.fill('#as-of','2026-10-20');page.dispatch_event('#as-of','change');page.wait_for_function('() => state.report.as_of==="2026-10-20"')
   page.click('#privacy');page.click('[data-view=actions]');page.select_option('#crm-scope','all')
   assert page.locator('.crm-queue tbody tr').count()==3
   assert page.locator('.crm-duplicate.strong').count()==2
   assert 'Золото' in page.locator('.crm-queue tbody tr').first.inner_text()
   page.select_option('#crm-tier','gold');assert page.locator('.crm-queue tbody tr').count()==1
   page.locator('[data-action=crm-open][data-id="1"]').click();page.wait_for_selector('#crm-event-form')
   prompt=page.locator('#crm-prompt').input_value()
   assert 'Кориандр' in prompt and '200 г' in prompt
   assert 'shared@example.invalid' not in prompt and '+38096' not in prompt and 'Тест Покупатель1' not in prompt
   page.locator('[data-action=crm-copy-prompt]').click();assert page.evaluate('navigator.clipboard.readText()')==prompt
   form=page.locator('#crm-event-form');form.locator('[name=kind]').select_option('start');form.locator('[name=occurred_at]').fill('2026-10-01T10:00');form.locator('[name=outcome]').select_option('no_answer');form.locator('[name=reasons][value=stock]').check();form.locator('[name=next_contact]').fill('2026-10-10');form.locator('[name=note]').fill('<img src=x onerror="window.__noteExecuted=true"> Синтетическая заметка');form.locator('button[type=submit]').click()
   page.wait_for_function('() => crmCard?.events.length===1 && !crmSaving')
   assert page.evaluate('state.report.crm.customers["1"].state')=='waiting'
   assert page.locator('#crm-event-form [name=next_contact]').input_value()==''
   assert page.locator('.crm-event').count()==1 and not page.evaluate('!!window.__noteExecuted')
   form=page.locator('#crm-event-form');form.locator('[name=kind]').select_option('reply');form.locator('[name=occurred_at]').fill('2026-10-02T10:00');form.locator('[name=outcome]').select_option('interested');form.locator('[name=reasons][value=stock]').check();form.locator('[name=offer]').select_option('pack');form.locator('[name=note]').fill('Просит напомнить о небольшой фасовке');form.locator('button[type=submit]').click()
   page.wait_for_function('() => crmCard?.events.length===2 && !crmSaving')
   assert 'Просит напомнить' not in page.locator('#crm-prompt').input_value()
   page.check('#crm-prompt-notes');assert 'Просит напомнить' in page.locator('#crm-prompt').input_value();page.uncheck('#crm-prompt-notes')
   ai=page.locator('#crm-ai-form');ai.locator('[name=ai_text]').fill('<script>window.__aiExecuted=true</script> Уточнить срок пополнения.');ai.locator('button[type=submit]').click();page.wait_for_function('() => crmCard?.events.length===3 && !crmSaving')
   assert not page.evaluate('!!window.__aiExecuted')
   page.locator('[data-help-title="Помощник ChatGPT"]').hover();assert page.locator('#drawer #help-popup').is_visible();page.keyboard.press('Escape')
   out=ROOT/'test-results';out.mkdir(exist_ok=True);page.screenshot(path=str(out/'crm-journal.png'),full_page=True)
   order=deepcopy(rows['oc_order']['1']);order.update(order_id='10',total='150',date_added='2026-10-15 12:00:00',date_modified='2026-10-15 12:00:00');rows['oc_order']['10']=order
   line=deepcopy(rows['oc_order_product']['1']);line.update(order_product_id='10',order_id='10',total='150');rows['oc_order_product']['10']=line
   apply_snapshot(data/'analytics.sqlite3',rows,'test')
   page.evaluate('load()');page.evaluate('openCRM("1")');page.wait_for_selector('#crm-event-form')
   assert page.evaluate('crmCard.summary.latest_attempt.first_order_id')=='10'
   assert page.evaluate('state.report.crm.stats.returned_customers')==1
   assert page.evaluate('state.report.crm.stats.first_order_amount')==150
   page.click('[data-action=close-dialog]');page.click('[data-view=actions]');page.select_option('#crm-tier','all');page.select_option('#crm-scope','returned')
   assert page.locator('.crm-queue tbody tr').count()==1 and '#10' in page.locator('.crm-queue tbody').inner_text()
   page.screenshot(path=str(out/'crm-queue.png'),full_page=True)
   page.locator('main [data-action=crm-duplicates][data-id="1"]').click();assert page.locator('.pair-checkbox').count()==1
   assert page.evaluate('duplicateRows().every(r=>r.ids.includes("1"))')
   page.locator('[data-action=clear-duplicate-customer]').click()
   page.evaluate('openCRM("1")');page.wait_for_selector('#crm-event-form');start=page.evaluate('crmCard.events.find(e=>e.kind==="start").id')
   void=page.locator('form.crm-void-form[data-id="'+start+'"]');void.locator('..').locator('summary').first.click();void.locator('[name=reason]').fill('Ошибка даты, синтетический тест');void.locator('button[type=submit]').click()
   page.wait_for_function('() => crmCard?.events.some(e=>e.kind==="start"&&e.voided_at) && !crmSaving')
   assert page.evaluate('state.report.crm.stats.returned_customers')==0
   assert page.locator('.crm-event.voided').count()==1
   page.set_viewport_size({'width':390,'height':844});assert page.evaluate('document.documentElement.scrollWidth<=innerWidth')
   page.screenshot(path=str(out/'crm-mobile.png'),full_page=True)
   assert not errors,errors;assert not external,external
   browser.close()
  print(json.dumps({'crm_warnings_tiers_and_filters':'passed','journal_checklist_and_manual_chatgpt':'passed','anonymous_prompt_and_safe_text':'passed','new_order_sync_and_return_badge':'passed','void_audit_and_mobile':'passed','php_image':IMAGE}))
 finally:
  subprocess.run(['docker','rm','-f',name],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL);server.wait(timeout=15)

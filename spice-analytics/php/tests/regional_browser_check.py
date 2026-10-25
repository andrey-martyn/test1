"""Operational advice and regional comparisons on synthetic data; no external APIs."""
import json,os,secrets,socket,sqlite3,subprocess,tempfile,time
from pathlib import Path
import httpx
from playwright.sync_api import sync_playwright

ROOT=Path(__file__).resolve().parents[2]
IMAGE=os.environ.get('ANALYTICS_PHP_IMAGE','lavka-php73-dev')
OUT=Path(os.environ.get('ANALYTICS_TEST_OUTPUT','/tmp/lavka-regional-browser'));OUT.mkdir(parents=True,exist_ok=True)
SEED=r'''
define('LAVKA_REGIONAL_FIXTURE_ONLY',true);require 'php/tests/regional_check.php';
$db=\Lavka\database('/test-data/analytics.sqlite3');\Lavka\init_schema($db);\Lavka\apply_snapshot($db,regional_fixture(),'test');
\Lavka\Auth::setPassword('/test-data',stream_get_contents(STDIN));
'''
with tempfile.TemporaryDirectory() as tmp:
 password=secrets.token_urlsafe(24);name='lavka-regional-'+secrets.token_hex(5)
 docker=['docker','run','--rm','--user',f'{os.getuid()}:{os.getgid()}','-v',str(ROOT)+':/app:ro','-v',tmp+':/test-data','-e','ANALYTICS_DATA_DIR=/test-data','-e','ANALYTICS_BASE_PATH=/analytics']
 subprocess.run(docker+['-i',IMAGE,'php','-r',SEED],input=password.encode(),check=True,stdout=subprocess.DEVNULL)
 with sqlite3.connect(Path(tmp)/'analytics.sqlite3') as db:before=db.execute('SELECT payload FROM raw_records ORDER BY table_name,record_id').fetchall()
 with socket.socket() as listener:listener.bind(('127.0.0.1',0));port=listener.getsockname()[1]
 server=subprocess.Popen(docker+['--name',name,'-p',f'127.0.0.1:{port}:8000',IMAGE,'php','-S','0.0.0.0:8000','-t','php/public','php/public/index.php'],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
 origin=f'http://127.0.0.1:{port}';base=origin+'/analytics'
 try:
  for _ in range(600):
   try:
    if httpx.get(base+'/index.php?route=/health',trust_env=False,timeout=2).status_code==200:break
   except httpx.TransportError:pass
   time.sleep(.1)
  else:raise RuntimeError('Regional browser server did not start')
  with sync_playwright() as play:
   browser=play.chromium.launch(executable_path='/usr/bin/chromium',args=['--no-sandbox'])
   page=browser.new_page(viewport={'width':1470,'height':1000},permissions=['clipboard-read','clipboard-write']);errors=[];external=[]
   page.on('pageerror',lambda e:errors.append(str(e)));page.on('request',lambda r:external.append(r.url) if not r.url.startswith(origin+'/') else None)
   page.goto(base+'/');page.fill('#password',password);page.click('button[type=submit]');page.wait_for_selector('.dash-board')
   assert page.locator('.dashboard-connections').count()==1 and not page.locator('.dashboard-connections').evaluate('el=>el.open')
   page.click('#navigation [data-area=sales]');page.wait_for_selector('.next-step')
   assert page.locator('.gender-comparison').count()==1 and page.locator('.gender-comparison').is_hidden()
   page.click('#section-navigation [data-view=geography]');page.wait_for_selector('[data-chart=regional-years]')
   assert page.locator('.geo-audit-cards').count()==0 and page.locator('.geo-diagnostics').count()==0
   assert page.locator('#metrics').is_hidden()
   assert page.evaluate('regionalCell("UA-51",2025,"same").count')==2
   assert page.evaluate('regionalCell("UA-51",2025,"full").count')==3
   assert page.evaluate('regionalTotal(2026,"same").count')==6
   assert page.evaluate('regionalRanking().find(r=>r.zone_code==="UA-51").share')==50
   assert '50%' in page.locator('.regional-ranking').inner_text()
   assert page.locator('[data-chart=regional-years] [data-regional-action=detail]').count()==8
   page.click('[data-next-action=delivery]')
   assert page.locator('#regional-channel').input_value()=='delivery'
   assert page.evaluate('regionalPeriodTotal().count')==5
   assert page.evaluate('regionalRanking().find(r=>r.zone_code==="UA-51").share')==40
   page.click('[data-next-action=regional-test]');page.wait_for_selector('#regional-test-plan')
   assert 'example.invalid' not in page.locator('#regional-test-plan').input_value()
   assert 'Контрольный регион' in page.locator('#regional-test-plan').input_value()
   page.click('[data-next-action=copy-test]');assert 'ПРОВЕРКА СПРОСА' in page.evaluate('navigator.clipboard.readText()')
   page.click('[data-action=close-dialog]')
   page.uncheck('[data-regional-year="2025"]');assert page.evaluate('state.regionalYears')==[2026]
   page.check('[data-regional-year="2025"]');assert page.evaluate('state.regionalYears')==[2025,2026]
   page.locator('.regional-region-picker>summary').click();page.check('[data-regional-region="UA-71"]')
   assert page.locator('.regional-region-picker').evaluate('el=>el.open')
   assert page.locator('[data-regional-region="UA-71"]').is_checked()
   page.select_option('#regional-measure','share')
   assert page.evaluate('regionalValue(regionalCell("UA-51",2026),regionalTotal(2026))')==40
   page.select_option('#regional-window','full')
   assert 'Текущий год не завершён' in page.locator('.regional-trends').inner_text()
   page.select_option('#regional-window','same');page.select_option('#regional-measure','count')
   page.locator('[data-chart=regional-years] [data-region="UA-51"][data-year="2026"]').click()
   page.wait_for_selector('#drawer [data-regional-action=customers]')
   assert '2 заказов' in page.locator('#drawer-content').inner_text()
   page.click('#drawer [data-regional-action=customers]');page.wait_for_selector('.customer-table')
   assert set(page.evaluate('customerRows().map(c=>c.id)'))=={'1','6'}
   assert 'История ниже включает все их заказы' in page.locator('.customer-selection-summary').inner_text()
   page.go_back();page.wait_for_function('() => state.view==="geography" && !uiNavigation.restoring')
   assert page.locator('#regional-channel').input_value()=='delivery'
   page.locator('.regional-cities>summary').click();page.fill('#search','Геронімівка')
   page.wait_for_function('() => state.search==="Геронімівка" && document.querySelectorAll(".geography-table>tbody>tr").length===1')
   assert 'Черкаська область' in page.locator('.geography-table>tbody').inner_text()
   page.fill('#search','');page.wait_for_function('() => state.search===""')
   page.select_option('#regional-channel','pickup');assert page.evaluate('regionalPeriodTotal().count')==1
   assert page.evaluate('regionalRanking()[0].share')==100
   page.select_option('#regional-channel','delivery')
   # Synthetic rule vectors: a decline is described without inventing a cause.
   outcome=page.evaluate('''()=>{const old=state.report;state.report=structuredClone(old);try{
    state.report.regional.coverage_start='2024-01-01';
    const section=state.report.regional.modes.mixed.same.delivery.regions;
    section['UA-63']={2025:{count:100,amount:1000,new_customers:60,returning_customers:30,customer_ids:[]},2026:{count:50,amount:500,new_customers:20,returning_customers:15,customer_ids:[]}};
    return nextStepRegional();
   }finally{state.report=old;}}''')
   assert 'Харківська' in outcome['title'] and 'причина ещё не установлена' in outcome['check']
   page.screenshot(path=str(OUT/'regions-1470.png'),full_page=True)
   for width in [390,320]:
    page.set_viewport_size({'width':width,'height':844});page.evaluate('window.scrollTo(0,0)')
    assert page.evaluate('document.documentElement.scrollWidth<=innerWidth'),width
    assert page.locator('.regional-chart-scroll').evaluate('el=>el.scrollWidth>el.clientWidth')
    assert page.locator('#regional-channel').evaluate('el=>parseFloat(getComputedStyle(el).fontSize)')>=16
    assert page.locator('[data-next-action]').first.bounding_box()['height']>=44
    page.locator('.next-step').screenshot(path=str(OUT/f'next-step-{width}.png'))
    page.screenshot(path=str(OUT/f'regions-{width}.png'),full_page=True)
   page.set_viewport_size({'width':1470,'height':1000})
   for area,view in [('sales','overview-report'),('products','products'),('customers','customers')]:
    page.click('#navigation [data-area='+area+']');page.wait_for_function('(view)=>state.view===view',arg=view)
    assert page.locator('.next-step').count()==1
   for view in ['retention','segments','opportunities']:
    page.click('#section-navigation [data-view='+view+']');assert page.locator('.next-step').count()==1
   page.click('#navigation [data-area=sales]');page.click('#section-navigation [data-view=seasonality]')
   page.click('[data-next-action=season-years]');assert page.evaluate('state.seasonYears')
   page.click('#section-navigation [data-view=ga4]');page.wait_for_selector('[data-next-action=integrations]')
   page.click('[data-next-action=integrations]');assert 'позиции конкурентов этот API не отдаёт' in page.locator('#drawer').inner_text()
   page.click('[data-action=close-dialog]')
   page.click('#navigation [data-area=competitors]');page.wait_for_selector('[data-next-action=competitor-matches]')
   assert page.locator('.next-step').count()==1
   page.click('#navigation [data-area=settings]');page.wait_for_selector('#settings-form')
   assert page.locator('#metrics').is_hidden() and page.locator('.report-controls').is_hidden()
   assert 'NaN' not in page.locator('#content').inner_text()
   assert not errors,errors;assert not external,external
   browser.close()
  with sqlite3.connect(Path(tmp)/'analytics.sqlite3') as db:after=db.execute('SELECT payload FROM raw_records ORDER BY table_name,record_id').fetchall()
  assert before==after
  print(json.dumps({'regional_shares_and_grouped_years':'passed','same_period_and_global_denominator':'passed','delivery_pickup_and_customer_flow':'passed','all_reports_next_step':'passed','mobile_390_320':'passed','source_unchanged':'passed','javascript_errors':errors}))
 finally:
  subprocess.run(['docker','rm','-f',name],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL);server.wait(timeout=15)

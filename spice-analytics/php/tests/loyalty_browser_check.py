"""Rounded presentation, fixed-window loyalty, and non-jar batch matching."""
import json,os,secrets,socket,sqlite3,subprocess,tempfile,time
from pathlib import Path
import httpx
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parents[2]
IMAGE=os.environ.get('ANALYTICS_PHP_IMAGE','lavka-php73-dev')
OUT=Path('/tmp/lavka-loyalty-browser');OUT.mkdir(exist_ok=True)
SEED=r'''
define('LAVKA_REGIONAL_FIXTURE_ONLY',true);require 'php/tests/regional_check.php';require_once 'php/src/Competitors.php';
$rows=regional_fixture();$rows['oc_order']['7']['total']='110.49';
foreach(['15'=>'2026-01-23','16'=>'2026-01-30'] as $id=>$date){$rows['oc_order'][$id]=$rows['oc_order']['6'];$rows['oc_order'][$id]['order_id']=(string)$id;$rows['oc_order'][$id]['date_added']=$date.' 12:00:00';$rows['oc_order'][$id]['total']='33.33';$rows['oc_order_product'][$id]=$rows['oc_order_product']['6'];$rows['oc_order_product'][$id]['order_id']=(string)$id;$rows['oc_order_product'][$id]['order_product_id']=(string)$id;$rows['oc_order_product'][$id]['total']='33.33';$rows['oc_order_option'][$id]=$rows['oc_order_option']['6'];$rows['oc_order_option'][$id]['order_id']=(string)$id;$rows['oc_order_option'][$id]['order_product_id']=(string)$id;$rows['oc_order_option'][$id]['order_option_id']=(string)$id;}
$db=\Lavka\database('/test-data/analytics.sqlite3');\Lavka\init_schema($db);\Lavka\apply_snapshot($db,$rows,'test');
\Lavka\Auth::setPassword('/test-data',stream_get_contents(STDIN));
$c=\Lavka\competitors_db('/test-data');$c->exec('UPDATE competitor_targets SET enabled=0');
foreach([['lavka','https://lavka-speciy.com/index.php?route=product/product&product_id=999981','82.49','doypack',true],['banka','https://banka-speciy.in.ua/ukrop-semena','80.49','zip',false],['banka','https://banka-speciy.in.ua/ukrop-semena-v-banke','1','unknown',true]] as $item){
 $target=\Lavka\competitors_save_target($c,['source_id'=>$item[0],'kind'=>'product','url'=>$item[1],'label'=>'Укроп семена','pack_label'=>'500 г','match_group'=>'loyalty-test-dill','match_confirmed'=>$item[4]]);
 $observation=['kind'=>'product','title'=>'Укроп семена','offers'=>[['key'=>'500','label'=>'500 г','price'=>$item[2],'currency'=>'UAH','grams'=>500,'availability'=>'in_stock','unit'=>'pack','min_quantity'=>1,'condition'=>'','packaging'=>$item[3]]],'text'=>'','links'=>[],'warnings'=>[],'method'=>'synthetic'];
 \Lavka\competitors_record_attempt($c,(int)$target['id'],$observation,null,\Lavka\utc_now(),200);
 if($item[0]==='banka')\Lavka\competitors_index_link($c,'banka',$item[1],'Укроп семена','page');
}
\Lavka\competitors_discovery_match($c,false);
'''
with tempfile.TemporaryDirectory() as tmp:
 password=secrets.token_urlsafe(24);name='lavka-loyalty-'+secrets.token_hex(5)
 docker=['docker','run','--rm','--user',f'{os.getuid()}:{os.getgid()}','-v',str(ROOT)+':/app:ro','-v',tmp+':/test-data','-e','ANALYTICS_DATA_DIR=/test-data','-e','ANALYTICS_BASE_PATH=/analytics']
 seed=subprocess.run(docker+['-i',IMAGE,'php','-r',SEED],input=password.encode(),stdout=subprocess.PIPE,stderr=subprocess.STDOUT);assert seed.returncode==0,seed.stdout.decode()
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
  else:raise RuntimeError('Loyalty browser server did not start')
  with sync_playwright() as play:
   browser=play.chromium.launch(executable_path='/usr/bin/chromium',args=['--no-sandbox']);page=browser.new_page(viewport={'width':1470,'height':1000});errors=[];external=[]
   page.on('pageerror',lambda e:errors.append(str(e)));page.on('request',lambda r:external.append(r.url) if not r.url.startswith(origin+'/') else None)
   page.goto(base+'/');page.fill('#password',password);page.click('button[type=submit]');page.wait_for_selector('.dash-board')
   page.click('#navigation [data-area=sales]');page.wait_for_selector('.next-step')
   page.locator('details[data-detail-key="overview-report:Подробно: Кто покупает: оценка по имени"]>summary').click()
   female=page.locator('.gender-card').filter(has=page.locator('[data-gender=female]'))
   assert '33%' in female.locator('.loyalty-gender').inner_text()
   assert page.evaluate('state.report.loyalty.gender.rows.female.eligible')==3
   assert page.evaluate('state.report.loyalty.gender.rows.female.returned')==1
   assert page.evaluate('state.report.loyalty.gender.rows.female.regular_rate')==50
   assert page.evaluate('num(35489.25,2)')=='35\xa0489'
   assert page.evaluate('num(17.4,1)')=='17'
   assert page.evaluate('state.report.metrics.amount')==507.15
   assert not page.locator('#metrics').evaluate('el=>/\\d[,\\.]\\d/.test(el.innerText)')
   page.screenshot(path=str(OUT/'gender-1470.png'),full_page=True)
   page.click('#section-navigation [data-view=geography]');page.wait_for_selector('.loyalty-panel')
   page.locator('.regional-loyalty-details>summary').click();assert '100%' in page.locator('.loyalty-table').inner_text()
   page.locator('[data-loyalty-key="UA-51"]').click();page.wait_for_selector('.customer-table')
   assert page.evaluate('state.ids')==['6']
   assert page.locator('#year').input_value()=='0'
   assert 'Первая покупка' in page.locator('.customer-selection-summary').inner_text()
   page.click('#navigation [data-area=competitors]');page.wait_for_selector('.competitor-matrix')
   assert page.evaluate('competitorMatrixRows().find(r=>r.grams===500).cells.banka.eligible') is False
   page.click('[data-comp-action=tab][data-tab=matching]');page.wait_for_selector('.monitoring-matches tbody tr')
   page.click('[data-comp-action=matching-exact]');assert page.evaluate('monitoringState.selected.size')==1
   page.locator('[data-comp-action=matching-confirm]').first.click()
   page.wait_for_function('()=>!monitoringState.busy&&monitoringState.selected.size===0')
   page.click('[data-comp-action=tab][data-tab=products]');page.wait_for_selector('.competitor-matrix')
   assert page.evaluate('competitorMatrixRows().find(r=>r.grams===500).cells.banka.price')==80.49
   assert page.evaluate('competitorMatrixRows().find(r=>r.grams===500).cells.banka.offer.packaging')=='zip'
   assert not page.locator('.competitor-matrix').evaluate('el=>/80[,\\.]49|82[,\\.]49/.test(el.innerText)')
   for width in [390,320]:
    page.set_viewport_size({'width':width,'height':844});page.click('#navigation [data-area=sales]');page.wait_for_selector('.gender-card')
    page.evaluate('document.querySelector(".gender-comparison").closest("details").open=true')
    assert page.locator('.loyalty-gender').first.is_visible()
    assert page.evaluate('document.documentElement.scrollWidth<=innerWidth+1')
    page.screenshot(path=str(OUT/f'gender-{width}.png'),full_page=True)
   assert not errors,errors
   assert not external,external
   browser.close()
  with sqlite3.connect(Path(tmp)/'analytics.sqlite3') as db:assert db.execute('SELECT payload FROM raw_records ORDER BY table_name,record_id').fetchall()==before
  print(json.dumps({'whole_number_presentation_exact_arithmetic':'passed','matured_gender_and_first_region_cohorts':'passed','jar_exclusion_and_batch_matching':'passed','mobile_390_320':'passed','source_unchanged':'passed','php_image':IMAGE}))
 finally:
  subprocess.run(['docker','rm','-f',name],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL);server.wait(timeout=15)

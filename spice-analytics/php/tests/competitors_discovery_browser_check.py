"""400 synthetic products: crawl visibility, batch review, pagination and layout."""
from pathlib import Path
import hashlib,json,os,secrets,socket,sqlite3,subprocess,sys,tempfile,time
import httpx
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parents[2];sys.path[:0]=[str(ROOT),str(ROOT/'tests')]
from test_production import snapshot
from sync_mysql import apply_snapshot
IMAGE=os.environ.get('ANALYTICS_PHP_IMAGE','lavka-php73-dev')
AUTOMATIC=os.environ.get('ANALYTICS_MATCH_AUTOMATIC')=='1'
OUT=Path(os.environ.get('ANALYTICS_TEST_OUTPUT','/tmp/lavka-discovery-browser'));OUT.mkdir(exist_ok=True,parents=True)
SEED=r'''
require 'php/src/Auth.php';require 'php/src/Competitors.php';
$input=json_decode(stream_get_contents(STDIN),true);\Lavka\Auth::setPassword('/test-data',$input['password']);$db=\Lavka\competitors_db('/test-data');$db->exec('UPDATE competitor_targets SET enabled=0');
$now=\Lavka\utc_now();$products=[];
function observed($name){return ['kind'=>'product','title'=>$name,'offers'=>[['key'=>'500','label'=>'500 г','price'=>'90','currency'=>'UAH','grams'=>500,'availability'=>'in_stock','unit'=>'pack','min_quantity'=>1,'condition'=>'','packaging'=>'unknown']],'links'=>[],'text'=>'','warnings'=>[],'method'=>'synthetic'];}
for($i=1;$i<=400;$i++){$label=$i===1?'Куркума молотая':($i===2?'Корица цейлонская':'Тестовая специя '.str_pad((string)$i,3,'0',STR_PAD_LEFT));
 $own=\Lavka\competitors_save_target($db,['source_id'=>'lavka','kind'=>'product','url'=>'https://lavka-speciy.com/index.php?route=product/product&product_id='.(900000+$i),'label'=>$label,'pack_label'=>'50 г, 500 г','match_group'=>'bulk-'.$i,'match_confirmed'=>true]);
 $products[]=['target_id'=>$own['id'],'product_id'=>900000+$i,'source_product_id'=>900000+$i,'label'=>$label,'url'=>$own['url'],'offers'=>array_map(function($o){$o['price']='100';return $o;},observed($label)['offers']),'warnings'=>[]];
 if($i===1){$first=$own;}if($i===2){$second=$own;}
}
$remote=\Lavka\competitors_save_target($db,['source_id'=>'deluxe','kind'=>'product','url'=>'https://deluxe.com.ua/kurkuma-molotaya.html','label'=>'Куркума молотая','match_group'=>'bulk-1','match_confirmed'=>false]);
\Lavka\competitors_record_attempt($db,$remote['id'],observed('Куркума молотая'),null,$now,200);
\Lavka\competitors_index_link($db,'deluxe',$remote['url'],'Куркума молотая','page');\Lavka\competitors_index_link($db,'banka','https://banka-speciy.in.ua/ua/koritsa-ceylonskaya','Корица цейлонская','page');
\Lavka\competitors_discovery_match($db,false);\Lavka\competitors_run_status($db,['state'=>'ok','started_at'=>$now,'finished_at'=>$now,'checked'=>1,'succeeded'=>1,'failed'=>0,'limit'=>30,'budget_seconds'=>55]);
\Lavka\atomic_write('/test-data/own-catalog.json',\Lavka\encode(['state'=>'ok','last_success'=>$now,'checked_at'=>$now,'products'=>$products,'product_count'=>400,'offer_count'=>400,'currency_decimal_places'=>2,'source_database'=>'synthetic','environment'=>'read_only']));
\Lavka\atomic_write('/test-data/competitor-full-catalog.json','{"enabled":true}');
echo json_encode(['first'=>$first['id'],'second'=>$second['id']]);
'''
with tempfile.TemporaryDirectory() as temporary:
 data=Path(temporary);apply_snapshot(data/'analytics.sqlite3',snapshot(),'synthetic');password=secrets.token_urlsafe(24)
 docker=['docker','run','--rm','--user',f'{os.getuid()}:{os.getgid()}','-v',str(ROOT)+':/app:ro','-v',temporary+':/test-data','-e','ANALYTICS_DATA_DIR=/test-data','-e','ANALYTICS_BASE_PATH=/analytics']
 seed=subprocess.run(docker+['-i',IMAGE,'php','-r',SEED],input=json.dumps({'password':password}).encode(),capture_output=True,check=True)
 with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
 name='lavka-discovery-browser-'+secrets.token_hex(5)
 server=subprocess.Popen(docker+['--name',name,'-p',f'127.0.0.1:{port}:8000',IMAGE,'php','-S','0.0.0.0:8000','-t','php/public','php/public/index.php'],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
 origin=f'http://127.0.0.1:{port}';base=origin+'/analytics'
 try:
  for _ in range(600):
   try:
    if httpx.get(base+'/index.php?route=/health',trust_env=False,timeout=2).status_code==200:break
   except httpx.TransportError:pass
   time.sleep(.1)
  else:raise RuntimeError('Synthetic server did not start')
  with sync_playwright() as play:
   browser=play.chromium.launch(executable_path='/usr/bin/chromium',args=['--no-sandbox']);page=browser.new_page(viewport={'width':1470,'height':900});errors=[];external=[];writes=[]
   page.on('pageerror',lambda e:errors.append(str(e)));page.on('request',lambda r:external.append(r.url) if r.url.startswith(('http:','https:')) and not r.url.startswith(origin+'/') else None)
   page.goto(base+'/');page.fill('#password',password);page.click('button[type=submit]');page.wait_for_selector('.dash-board')
   assert page.locator('.dash-kpi').first.evaluate('el=>getComputedStyle(el).backgroundColor')=='rgb(32, 91, 70)'
   menu_today=page.locator('#navigation button').evaluate_all('els=>els.map(e=>({y:e.getBoundingClientRect().y,height:e.getBoundingClientRect().height}))')
   page.click('[data-view=overview-report]');page.wait_for_selector('.mini-list');assert 'Покупатели, товары и доставка' in page.locator('#content').inner_text();assert 'За цифрами' not in page.locator('#content').inner_text()
   menu_sales=page.locator('#navigation button').evaluate_all('els=>els.map(e=>({y:e.getBoundingClientRect().y,height:e.getBoundingClientRect().height}))');assert menu_today==menu_sales,(menu_today,menu_sales)
   page.click('[data-view=competitors]');page.wait_for_selector('.competitor-matrix')
   page.on('request',lambda r:writes.append(r.url) if r.method in ['POST','PUT','PATCH','DELETE'] else None)
   assert 'Наши цены по сравнению с ценами других магазинов.' in page.locator('#page-description').inner_text()
   assert page.locator('.competitor-matrix tbody tr').count()==50
   assert page.locator('.monitoring-summary').count()==0
   assert '400' in page.locator('.monitoring-matrix-pagination').inner_text()
   assert page.evaluate('competitorComparisonFunnel().loaded===1 && competitorComparisonFunnel().unconfirmed===1 && competitorComparisonFunnel().ready_rows_500===0')
   assert 'соответствие не подтверждено' in page.locator('.competitor-matrix tbody').inner_text()
   assert page.locator('[data-next-action=competitor-matches-loaded]').count()==1
   assert page.evaluate('diagnosticsClient().price_comparison.unconfirmed')==1
   page.click('[data-next-action=competitor-matches-loaded]');page.wait_for_function('() => !monitoringState.loading')
   assert page.locator('.monitoring-matches tbody tr').count()==1
   assert page.locator('#monitoring-search [name=status]').input_value()=='review'
   page.click('[data-comp-action=matching-exact]')
   assert page.evaluate('monitoringState.selected.size')==1
   assert not page.evaluate('competitorMatrixRows().find(r=>r.group==="bulk-1"&&r.grams===500).comparable')
   page.click('[data-comp-action=matching-clear]')
   page.click('[data-tab=products]')

   start=time.monotonic();page.evaluate('competitorsRender()');render_seconds=time.monotonic()-start;assert render_seconds<5,render_seconds
   assert page.evaluate('competitorMatrixRows()===competitorMatrixRows()')
   page.click('[data-comp-action=matrix-next]');assert '2 / 8' in page.locator('.monitoring-matrix-pagination').inner_text()
   page.click('[data-comp-action=matrix-prev]')
   page.click('[data-tab=coverage]');page.wait_for_selector('.monitoring-sources');assert '400' in page.locator('.monitoring-summary').inner_text();assert 'каждую минуту' in page.locator('.monitoring-panel').inner_text();assert 'раз в сутки' in page.locator('.monitoring-panel').inner_text()
   assert len(page.locator('.monitoring-sources tbody tr').all())==4
   assert not writes,writes
   for width in [1470,390,320]:
    page.set_viewport_size({'width':width,'height':844});assert page.evaluate('document.documentElement.scrollWidth<=innerWidth'),width
    page.screenshot(path=str(OUT/f'coverage-{width}.png'),full_page=True)
   page.set_viewport_size({'width':1470,'height':900});page.click('[data-tab=matching]');page.wait_for_function('() => !monitoringState.loading');assert page.locator('[data-monitoring-pair]').count(),(page.evaluate('monitoringState'),errors,page.locator('#competitor-tabpanel').inner_text()[:2000])
   page.select_option('#monitoring-search [name=status]','all');page.locator('#monitoring-search button[type=submit]').click();page.wait_for_function('() => !monitoringState.loading')
   assert page.locator('[data-monitoring-pair]').count()==2
   # Unread candidates cannot be confirmed; loading is an explicit queue operation.
   unread=page.locator('.monitoring-matches tbody tr').filter(has_text='Корица цейлонская');unread.locator('input').check()
   assert page.locator('[data-comp-action=matching-confirm]').first.is_disabled()
   page.locator('[data-comp-action=matching-watch]').first.click();page.wait_for_function('() => !monitoringState.busy && !monitoringState.loading')
   assert any('discovery%2Freview' in address for address in writes)
   page.locator('[data-comp-action=matching-clear]').click() if page.locator('[data-comp-action=matching-clear]').is_enabled() else None
   loaded=page.locator('.monitoring-matches tbody tr').filter(has_text='Куркума молотая');loaded.locator('input').check()
   assert page.locator('[data-comp-action=matching-confirm]').first.is_enabled()
   page.locator('[data-comp-action=matching-confirm]').first.click();page.wait_for_function('() => !monitoringState.busy && !monitoringState.loading')
   assert 'Соответствие подтверждено' in page.locator('.monitoring-matches').inner_text()
   page.set_viewport_size({'width':390,'height':844});assert page.evaluate('document.documentElement.scrollWidth<=innerWidth');page.screenshot(path=str(OUT/'matching-390.png'),full_page=True)
   page.set_viewport_size({'width':1470,'height':900});page.click('[data-tab=products]');page.wait_for_selector('.competitor-matrix')
   assert page.evaluate('competitorMatrixRows().find(r=>r.group==="bulk-1"&&r.grams===500).comparable')
   assert page.evaluate('competitorComparisonFunnel().confirmed===1 && competitorComparisonFunnel().unconfirmed===0 && competitorComparisonFunnel().ready_rows_500===1')
   page.click('[data-comp-action=matrix-all-packs]');assert page.locator('#competitor-matrix-pack').input_value()=='all'

   page.click('[data-tab=coverage]');page.click('[data-comp-action=discovery-start]');page.wait_for_function('() => !monitoringState.busy');assert page.evaluate('competitorState.data.monitoring.discovery_enabled')
   # Reproduce the user's gap: 110 loaded cards, only 3 confirmed 500g rows.
   extra=r'''require 'php/src/Competitors.php';$db=\Lavka\competitors_db('/test-data');$now=\Lavka\utc_now();
   for($i=3;$i<=111;$i++){$own=$db->query("SELECT * FROM competitor_targets WHERE source_id='lavka' AND match_group='bulk-".$i."'")->fetch();
    $url='https://deluxe.com.ua/synthetic-flow-'.$i.'.html';
    $target=\Lavka\competitors_save_target($db,['source_id'=>'deluxe','kind'=>'product','url'=>$url,'label'=>$own['label'],'match_group'=>$own['match_group'],'match_confirmed'=>$i<=4]);
    $obs=['kind'=>'product','title'=>$own['label'],'offers'=>[['key'=>'500','label'=>'500 г','price'=>'90','currency'=>'UAH','grams'=>500,'availability'=>'in_stock','unit'=>'pack','min_quantity'=>1,'condition'=>'','packaging'=>'unknown','variant_id'=>'deluxe:'.$i.':500']],'links'=>[],'text'=>'','warnings'=>[],'method'=>'synthetic'];
    \Lavka\competitors_record_attempt($db,$target['id'],$obs,null,$now,200);\Lavka\competitors_index_link($db,'deluxe',$url,$own['label'],'page');
    $q=$db->prepare('SELECT id FROM competitor_search_index WHERE source_id=? AND url=?');$q->execute(['deluxe',$url]);$candidate=(int)$q->fetchColumn();
    $db->prepare('INSERT OR REPLACE INTO competitor_suggestions(own_id,candidate_id,score) VALUES(?,?,100)')->execute([(int)$own['id'],$candidate]);
   }'''
   if AUTOMATIC:extra=extra.replace('$i<=111','$i<=237')
   subprocess.run(docker+['-i',IMAGE,'php','-r',extra],check=True,stdout=subprocess.DEVNULL)
   def prices_digest():
    with sqlite3.connect(data/'competitors.sqlite3') as connection:
     return hashlib.sha256(repr(connection.execute('SELECT target_id,payload FROM competitor_observations ORDER BY id').fetchall()).encode()).hexdigest()
   prices_before=prices_digest()
   page.click('[data-tab=products]');page.evaluate('competitorsLoad()');page.wait_for_function('() => !competitorState.loading')
   funnel=page.evaluate('competitorComparisonFunnel()')
   total_loaded=236 if AUTOMATIC else 110
   assert (funnel['loaded'],funnel['confirmed'],funnel['unconfirmed'],funnel['ready_rows_500'])==(total_loaded,3,total_loaded-3,3),funnel
   assert page.evaluate('competitorState.data.targets.length')>=510
   if AUTOMATIC:
    reconciled=subprocess.run(docker+[IMAGE,'php','php/bin/reconcile_competitors.php','--data','/test-data'],capture_output=True,check=True)
    automatic=json.loads(reconciled.stdout)
    assert automatic['newly_confirmed']==233 and automatic['network_requests']==0 and not automatic['shop_writes'],automatic
    page.evaluate('competitorsLoad()');page.wait_for_function('() => !competitorState.loading')
    funnel=page.evaluate('competitorComparisonFunnel()')
    assert (funnel['loaded'],funnel['confirmed'],funnel['unconfirmed'],funnel['ready_rows_500'],funnel['automatic'])==(236,236,0,236,233),funnel
    assert prices_digest()==prices_before,'Automatic matching never changes observed prices'
    assert 'Автосопоставление' in page.locator('.competitor-matrix').inner_text()
    assert page.evaluate('diagnosticsClient().price_comparison.ready_rows_500')==236
    page.evaluate('() => {const t=competitorState.data.targets.find(t=>t.match_method==="auto"&&t.match_confirmed),r=competitorMatrixRows().find(r=>r.targets.some(x=>x.id===t.id));competitorMatrixDetail(r.key,t.source_id)}')
    page.wait_for_selector('#drawer .competitor-matching-note')
    assert 'Проверено автоматически' in page.locator('#drawer .competitor-matching-note').inner_text()
    page.click('[data-action=close-dialog]');page.wait_for_function('() => !document.getElementById("drawer").open')
    page.click('[data-comp-action=matrix-ready]')
    assert page.locator('#competitor-matrix-status').input_value()=='ready' and page.locator('#competitor-matrix-pack').input_value()=='all'
    assert page.evaluate('competitorVisibleRows().length===236 && competitorVisibleRows().every(r=>r.comparable)')
   else:
    page.click('[data-comp-action=matching-loaded]');page.wait_for_function('() => !monitoringState.loading')
    assert page.evaluate('monitoringState.data.total')==107
    page.click('[data-comp-action=matching-exact]');assert page.evaluate('monitoringState.selected.size')==50
    page.locator('[data-comp-action=matching-confirm]').first.click();page.wait_for_function('() => !monitoringState.busy && !monitoringState.loading')
    page.click('[data-tab=products]')
    funnel=page.evaluate('competitorComparisonFunnel()')
    assert (funnel['loaded'],funnel['confirmed'],funnel['unconfirmed'],funnel['ready_rows_500'])==(110,53,57,53),funnel
    assert prices_digest()==prices_before,'Confirming links must not change downloaded prices'
    assert page.evaluate('diagnosticsClient().price_comparison.ready_rows_500')==53
   # A watched product must remain reviewable after it disappears from discovery.
   orphan=r'''require 'php/src/Competitors.php';$db=\Lavka\competitors_db('/test-data');
   $t=$db->query("SELECT * FROM competitor_targets WHERE source_id='deluxe' AND match_group='bulk-1'")->fetch();
   \Lavka\competitors_save_target($db,['match_confirmed'=>false,'match_group'=>''],(int)$t['id']);
   $q=$db->prepare('SELECT id FROM competitor_search_index WHERE source_id=? AND url=?');$q->execute([$t['source_id'],$t['url']]);$id=$q->fetchColumn();
   if($id){$db->prepare('DELETE FROM competitor_suggestions WHERE candidate_id=?')->execute([$id]);$db->prepare('DELETE FROM competitor_search_index WHERE id=?')->execute([$id]);}
   echo json_encode(['target_id'=>(int)$t['id']]);'''
   orphan_data=json.loads(subprocess.run(docker+['-i',IMAGE,'php','-r',orphan],capture_output=True,check=True).stdout)
   page.evaluate('competitorsLoad()');page.wait_for_function('() => !competitorState.loading')
   page.evaluate('Object.assign(monitoringState,{search:"does not exist",source:"specii",own:99999,page:999,status:"confirmed"})')
   assert '${' not in page.locator('.competitor-readiness').inner_text()
   page.click('[data-comp-action=matching-loaded]');page.wait_for_function('() => !monitoringState.loading')
   assert page.locator('#monitoring-search [name=status]').input_value()=='review'
   assert page.locator('#monitoring-search [name=source]').input_value()=='all' and page.input_value('#monitoring-search [name=search]')==''
   assert page.evaluate('monitoringState.own===0 && monitoringState.page===1')
   row=page.evaluate('(id)=>monitoringState.data.rows.find(r=>r.target_id===id)',orphan_data['target_id'])
   assert row and row['candidate_id'] is None and row['own_id'] is None,row
   key=page.evaluate('(row)=>monitoringRowKey(row)',row)
   assert page.locator('[data-monitoring-pair="'+key+'"]').is_disabled()
   page.get_by_role('button',name='Выбрать наш товар',exact=True).click()
   page.fill('#competitor-target-form [name=match_group]','bulk-1')
   page.locator('#competitor-target-form button[type=submit]').click()
   page.wait_for_function('(id)=>!competitorState.saving && !monitoringState.loading && monitoringState.data.rows.some(r=>r.target_id===id && r.own_id)',arg=orphan_data['target_id'])
   assert page.locator('#competitor-target-form').count()==0
   row=page.evaluate('(id)=>monitoringState.data.rows.find(r=>r.target_id===id)',orphan_data['target_id'])
   assert row['own_id'] and row['candidate_id'] is None
   total_before=page.evaluate('monitoringState.data.total')
   key=page.evaluate('(row)=>monitoringRowKey(row)',row)
   page.check('[data-monitoring-pair="'+key+'"]')
   with page.expect_request(lambda request:request.method=='POST' and 'discovery%2Freview' in request.url) as confirming:
    page.locator('[data-comp-action=matching-confirm]').first.click()
   assert confirming.value.post_data_json['target_pairs']==[[row['own_id'],row['target_id']]]
   page.wait_for_function('() => !monitoringState.busy && !monitoringState.loading')
   assert page.evaluate('monitoringState.data.total')==total_before-1
   assert prices_digest()==prices_before
   page.select_option('#monitoring-search [name=status]','confirmed');page.locator('#monitoring-search button[type=submit]').click()
   page.wait_for_function('(id)=>!monitoringState.loading && monitoringState.data.rows.some(r=>r.target_id===id && r.match_confirmed)',arg=orphan_data['target_id'])
   page.click('[data-tab=products]')
   for width in [390,320]:
    page.set_viewport_size({'width':width,'height':844});assert page.evaluate('document.documentElement.scrollWidth<=innerWidth'),width
   page.screenshot(path=str(OUT/'comparison-funnel-320.png'),full_page=True)
   assert not errors,errors;assert not external,external
   browser.close()
   print(json.dumps({'green_kpi_and_identical_menu_layout':'passed','400_products_paged_and_cached':'passed','render_seconds':round(render_seconds,3),'runner_visibility':'passed','human_batch_confirmation_and_queue':'passed','comparison_flow':('236_loaded_3_to_236_compared_automatically' if AUTOMATIC else '110_loaded_3_to_53_manual'),'source_prices_unchanged':'passed','full_manual_review_without_search_index':'passed','mobile_320_390':'passed','external_requests':external,'javascript_errors':errors,'php_image':IMAGE}))
 finally:
  subprocess.run(['docker','rm','-f',name],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL);server.wait(timeout=15)

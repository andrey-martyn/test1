<?php

declare(strict_types=1);
define('LAVKA_GENDER_FIXTURE_ONLY',true);
require __DIR__.'/gender_check.php';
require_once dirname(__DIR__).'/src/Diagnostics.php';
require_once dirname(__DIR__).'/src/Competitors.php';
function dx_expect(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
$dir=sys_get_temp_dir().'/lavka-system-dx-'.bin2hex(random_bytes(5));\Lavka\private_dir($dir);
try{
 $db=\Lavka\database($dir.'/analytics.sqlite3');\Lavka\init_schema($db);$fixture=gender_fixture();\Lavka\apply_snapshot($db,$fixture,'test');
 foreach(['source.json','access-php.json','sandbox.json'] as $file)file_put_contents($dir.'/'.$file,'PRIVATE_SENTINEL_NEVER_READ');
 $monitor=\Lavka\competitors_db($dir);$before=(int)$monitor->query('SELECT count(*) FROM competitor_attempts')->fetchColumn();
 $response=['body'=>'<main><h1>Укроп семена</h1><span class="price">20.50 грн</span><section class="reviews"><p>PRIVATE_REVIEW +380991234567</p></section><script>PRIVATE_SCRIPT</script></main>','url'=>'https://banka-speciy.in.ua/ukrop-semena'];
 lavka_competitor_evidence_capture($monitor,['source_id'=>'banka','kind'=>'product','url'=>$response['url']],$response,'price_unknown','2026-10-25T12:00:00Z');
 $saved=['schema'=>'lavka-public-pages-v1','collected_at'=>'2026-10-25T12:00:00Z','collector_revision'=>'ab72e123456789e9','report'=>['state'=>'ok','targets'=>[['id'=>72,'source'=>'deluxe','http'=>['status'=>200],'document'=>['h1'=>'Synthetic spice'],'body'=>'PRIVATE_SENTINEL_NEVER_READ','scope'=>['id'=>'bx_1234567890_1161_price','text'=>'support@example.test +380 99 123 45 67']]]]];
 file_put_contents($dir.'/public-page-diagnostics.json',\Lavka\encode($saved));
 $cadence=\Lavka\competitors_run_cadence([['started_at'=>'2026-10-25T12:00:00Z'],['started_at'=>'2026-10-25T11:45:00Z'],['started_at'=>'2026-10-25T11:30:00Z']]);
 dx_expect($cadence['interval_seconds']===900,'Observed fifteen-minute cadence is reported');
 dx_expect(\Lavka\competitors_run_cadence([['started_at'=>'2026-10-25T12:00:00Z']])['interval_seconds']===null,'One run cannot establish a schedule');
 $missing=new PDO('sqlite::memory:');$missing->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
 $capture=lavka_competitor_evidence_capture($missing,['source_id'=>'banka','kind'=>'product','url'=>$response['url']],$response,null,'2026-10-25T12:00:00Z');
 dx_expect($capture===['state'=>'failed','error_code'=>'evidence_storage_failed'],'Evidence storage failures are observable without raw driver errors');
 $store=new \Lavka\Analytics($db,$dir);
 $client=['errors'=>[['type'=>'error','category'=>'missing_value','message'=>'PRIVATE_SENTINEL_NEVER_READ','script'=>'app.js','line'=>15]],'notes'=>'PRIVATE_SENTINEL_NEVER_READ','probes'=>[['name'=>'customers_amount','count'=>8,'passed'=>false,'samples'=>[['customer_id'=>'1','name'=>'PRIVATE_SENTINEL_NEVER_READ','email'=>'private@secret.test','amount'=>100]]]]];
 $report=\Lavka\diagnostic_build($store,0,'2026-12-31','UAH','all',false,$client);$json=\Lavka\encode($report);
 dx_expect($report['result']['failed']===0,'All synthetic reconciliation checks pass: '.\Lavka\encode(array_values(array_filter($report['checks'],function($r){return !$r['passed'];}))));
 dx_expect($report['selected']['metrics']['order_count']===10,'Actual eligible orders are exported');
 dx_expect($report['unknown_gender']['count']===5,'Unknown report covers actual unresolved profiles');
 dx_expect(!empty($report['competitors']['evidence']),'Saved parser evidence is exported');
 dx_expect($report['competitors']['collected_pages']['available']===true,'Targeted support file is included in one export');
 dx_expect($report['competitors']['collected_pages']['collected_at']==='2026-10-25T12:00:00Z','UTC support metadata survives redaction');
 dx_expect($report['competitors']['collected_pages']['collector_revision']==='ab72e123456789e9','Collector hash survives redaction');
 dx_expect($report['competitors']['collected_pages']['report']['targets'][0]['scope']['id']==='bx_1234567890_1161_price','Known public SKU ID survives redaction');
 dx_expect(strpos(\Lavka\encode($report['competitors']['collected_pages']),'support@example')===false,'Free-text contact redaction remains active');

 dx_expect(isset($report['competitors']['terms']['rows'][0]['cells']['deluxe']['page_count']),'Terms sources participate in diagnostics');
 foreach(['PRIVATE_SENTINEL','PRIVATE_REVIEW','PRIVATE_SCRIPT','private@secret.test','Саша','firstname','telephone','shipping_address','csrf'] as $private)dx_expect(strpos($json,$private)===false,'Private field omitted: '.$private);
 dx_expect(strpos($report['samples']['customers'][0]['customer_ref'],'C-')===0,'Customer IDs are pseudonymized');
 dx_expect($report['result']['client_failed']===1,'Failed browser probe stays visible');
 $orderRefs=array_column($report['samples']['orders'],'order_ref');
 dx_expect(in_array($report['unknown_gender']['samples'][0]['order_refs'][0],$orderRefs,true),'Order references are consistent across sections');
 $named=\Lavka\diagnostic_build($store,2026,'2026-12-31','UAH','male',true);
 dx_expect($named['result']['failed']===0&&$named['selected']['metrics']['amount']===200.0,'Selected gender/year reconciles separately from full history');
 dx_expect(strpos(\Lavka\encode($named['unknown_gender']),'Саша')!==false,'Explicit name opt-in includes real unresolved examples');
 dx_expect($named['samples']['customers'][0]['customer_ref']!==$report['samples']['customers'][0]['customer_ref'],'Export pseudonyms are not persistent');
 dx_expect((int)$monitor->query('SELECT count(*) FROM competitor_attempts')->fetchColumn()===$before,'Export does not fetch, queue or record observations');
 dx_expect(in_array(\Lavka\diagnostic_public_url('https://banka-speciy.in.ua/ukrop?token=secret'),['','https://banka-speciy.in.ua/ukrop'],true),'Public URLs strip unneeded queries');
 foreach(['Петренко Светлана'=>'female','Cвітлана'=>'female','Svetlana Петренко'=>'female','Olga Петренко'=>'female','Oлександр'=>'male','Ірина-Петро'=>'unknown','Саша'=>'unknown','А. К.'=>'unknown'] as $name=>$gender)dx_expect(\Lavka\name_gender_estimate($name)===$gender,'Format handling: '.$name);
 dx_expect(\Lavka\name_gender_details('Саша')['reason']==='ambiguous','Shared name has explicit reason');
 dx_expect(\Lavka\name_gender_details('А. К.')['reason']==='initials','Initials have explicit reason');
 dx_expect(\Lavka\customer_gender_details([['firstname'=>'Андрей'],['firstname'=>'Ирина']])['reason']==='conflicting_profile','Mixed profile reports conflict');
 $fallback=\Lavka\diagnostic_fallback($db,0,'2026-12-31','UAH',new RuntimeException('PRIVATE_SENTINEL_DRIVER_ERROR'),$client);
 dx_expect($fallback['partial']&&$fallback['result']['failed']===1&&strpos(\Lavka\encode($fallback),'PRIVATE_SENTINEL')===false,'Broken calculations still produce safe partial diagnostics');
 echo \Lavka\encode(['system_diagnostics'=>'passed','checks'=>$report['result']['checks'],'bytes'=>strlen($json),'php'=>PHP_VERSION])."\n";
}finally{
 $db=null;$monitor=null;
 $remove=function($path)use(&$remove){foreach(scandir($path) as $n){if($n==='.'||$n==='..')continue;$p=$path.'/'.$n;if(is_dir($p))$remove($p);else unlink($p);}rmdir($path);};$remove($dir);
}

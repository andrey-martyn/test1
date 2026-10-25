<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/Competitors.php';
function review_expect($value,$message):void{if(!$value)throw new RuntimeException($message);}
function review_remove($dir):void{foreach(scandir($dir) as $n){if($n==='.'||$n==='..')continue;$p=$dir.'/'.$n;if(is_dir($p))review_remove($p);else unlink($p);}rmdir($dir);}
$dir=sys_get_temp_dir().'/lavka-full-review-'.bin2hex(random_bytes(5));\Lavka\private_dir($dir);
try{
 $db=\Lavka\competitors_db($dir);$db->exec('UPDATE competitor_targets SET enabled=0');
 $own=\Lavka\competitors_save_target($db,['source_id'=>'lavka','kind'=>'product','url'=>'https://lavka-speciy.com/index.php?route=product/product&product_id=999981','label'=>'Наш товар','match_group'=>'review-full']);
 $other=\Lavka\competitors_save_target($db,['source_id'=>'lavka','kind'=>'product','url'=>'https://lavka-speciy.com/index.php?route=product/product&product_id=999982','label'=>'Другой товар','match_group'=>'other']);
 $obs=['kind'=>'product','title'=>'Прочитанный товар','offers'=>[['key'=>'500','label'=>'500 г','price'=>'90','grams'=>500,'unit'=>'pack','currency'=>'UAH','availability'=>'in_stock']],'links'=>[],'text'=>'','warnings'=>[],'method'=>'synthetic'];$targets=[];$now=\Lavka\utc_now();
 for($i=1;$i<=55;$i++){$t=\Lavka\competitors_save_target($db,['source_id'=>'deluxe','kind'=>'product','url'=>'https://deluxe.com.ua/unindexed-review-'.$i,'label'=>'Товар '.$i,'match_group'=>'review-full']);\Lavka\competitors_record_attempt($db,$t['id'],$obs,null,$now,200);$targets[]=$t;}
 $unread=\Lavka\competitors_save_target($db,['source_id'=>'deluxe','kind'=>'product','url'=>'https://deluxe.com.ua/unread-review','label'=>'Не загружен','match_group'=>'review-full']);
 $page=\Lavka\competitors_discovery_results($db,'','all',1,0,'review');$next=\Lavka\competitors_discovery_results($db,'','all',2,0,'review');
 review_expect($page['total']===55&&count($page['rows'])===50&&count($next['rows'])===5,'All loaded unindexed targets remain in paginated review');
 review_expect($page['rows'][0]['candidate_id']===null&&$page['rows'][0]['target_id']!==null,'Review does not manufacture a discovery candidate');
 review_expect(\Lavka\competitors_discovery_results($db,'','banka',1,0,'review')['total']===0&&\Lavka\competitors_discovery_results($db,'Товар 55','all',1,0,'review')['total']===1,'Source and literal search apply to the full review');
 $before=$db->query('SELECT target_id,payload FROM competitor_observations ORDER BY id')->fetchAll();
 foreach([[[$own['id'],$targets[0]['id']],[$other['id'],$targets[1]['id']]],[[$own['id'],$targets[0]['id']],[$own['id'],$unread['id']]]] as $pairs){try{\Lavka\competitors_discovery_review($db,['target_pairs'=>$pairs,'confirm'=>true]);throw new RuntimeException('Invalid batch accepted');}catch(\Lavka\CompetitorConflict $expected){}}
 review_expect((int)$db->query("SELECT sum(match_confirmed) FROM competitor_targets WHERE source_id<>'lavka'")->fetchColumn()===0,'Every pair is validated before any confirmation is stored');
 $pairs=array_map(function($t)use($own){return [$own['id'],$t['id']];},$targets);
 $result=\Lavka\competitors_discovery_review($db,['target_pairs'=>$pairs,'confirm'=>true]);review_expect($result['processed']===55&&!$result['shop_writes'],'Explicit batch confirms unindexed loaded targets');
 review_expect(\Lavka\competitors_discovery_results($db,'','all',1,0,'review')['total']===0,'Confirmed targets leave the manual review list');
 review_expect(\Lavka\competitors_discovery_results($db,'','all',1,0,'confirmed')['total']===55&&\Lavka\competitors_discovery_results($db,'','all',1,0,'loaded')['total']===55,'Confirmed and loaded lists retain watched targets without a search index');
 review_expect($before===$db->query('SELECT target_id,payload FROM competitor_observations ORDER BY id')->fetchAll(),'Review never modifies observed prices');
 echo \Lavka\encode(['all_loaded_targets_without_search_candidates'=>'passed','pagination_search_source'=>'passed','atomic_confirmation'=>'passed','confirmed_targets_leave_review'=>'passed','price_observations'=>'unchanged','php'=>PHP_VERSION])."\n";
}finally{review_remove($dir);}

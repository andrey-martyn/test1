<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/Competitors.php';
require_once dirname(__DIR__).'/src/CompetitorMatching.php';
function match_expect($ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function match_cleanup($dir):void{foreach(scandir($dir) as $n){if($n==='.'||$n==='..')continue;$p=$dir.'/'.$n;if(is_dir($p))match_cleanup($p);else unlink($p);}rmdir($dir);}
function match_observation(string $name,string $stock='in_stock',string $pack='zip'):array{return ['kind'=>'product','title'=>$name,'offers'=>[['key'=>'500','label'=>'500 г','grams'=>500,'price'=>'90','unit'=>'pack','currency'=>'UAH','availability'=>$stock,'packaging'=>$pack,'condition'=>'','min_quantity'=>1]],'links'=>[],'text'=>'','warnings'=>[],'method'=>'synthetic'];}
$dir=sys_get_temp_dir().'/lavka-auto-match-'.bin2hex(random_bytes(5));\Lavka\private_dir($dir);
try{
 $legacy=new PDO('sqlite::memory:');$legacy->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
 $legacy->exec("CREATE TABLE competitor_targets(id INTEGER PRIMARY KEY,source_id TEXT,kind TEXT,enabled INTEGER,match_group TEXT,match_confirmed INTEGER,created_at TEXT,updated_at TEXT);INSERT INTO competitor_targets VALUES(1,'banka','product',1,'one',1,'old','new'),(2,'banka','product',1,'two',0,'old','new')");
 \Lavka\competitors_discovery_schema($legacy);match_expect($legacy->query('SELECT match_method FROM competitor_targets WHERE id=1')->fetchColumn()==='manual'&&$legacy->query('SELECT match_method FROM competitor_targets WHERE id=2')->fetchColumn()==='unreviewed','Old queued/edited unconfirmed pages are pending, not human rejections');
 $db=\Lavka\competitors_db($dir);$db->exec('UPDATE competitor_targets SET enabled=0');$now=\Lavka\utc_now();$i=999000;
 $own=function($name,$group)use($db,&$i){return \Lavka\competitors_save_target($db,['source_id'=>'lavka','kind'=>'product','url'=>'https://lavka-speciy.com/index.php?route=product/product&product_id='.(++$i),'label'=>$name,'match_group'=>$group,'match_confirmed'=>true]);};
 $remote=function($label,$read,$group,$path='',$stock='in_stock',$pack='zip')use($db,&$i,$now){$t=\Lavka\competitors_save_target($db,['source_id'=>'banka','kind'=>'product','url'=>'https://banka-speciy.in.ua/'.($path?:'synthetic-'.(++$i)),'label'=>$label,'match_group'=>$group]);if($read!==null)\Lavka\competitors_record_attempt($db,$t['id'],match_observation($read,$stock,$pack),null,$now,200);return $t;};
 $dill=$own('Укроп семена 50 г','dill');$ground=$own('Укроп молотый','ground');$agar=$own('Агар-агар (1200)','agar');$asa=$own('Асафетида 100%','asa');$a=$own('Кориандр молотый','ambiguous-a');$b=$own('Кориандр молотый','ambiguous-b');
 $good=$remote('Название из адреса','Кріп насіння 500 г','dill');
 $wrongRead=$remote('Укроп семена','Кориандр семена','dill');
 $wrongForm=$remote('Укроп молотый','Укроп семена','ground');
 $jar=$remote('Укроп семена','Укроп семена','dill','ukrop-semena-v-banke');
 $jarOffer=$remote('Укроп семена','Укроп семена','dill','','in_stock','jar');
 $strength=$remote('Агар-агар 1200','Агар-агар (900)','agar');$concentration=$remote('Асафетида 100%','Асафетида 15%','asa');
 $ambiguous=$remote('Кориандр молотый','Кориандр молотый','ambiguous-a');$unread=$remote('Укроп семена',null,'dill');
 $manualNo=$remote('Укроп семена','Укроп семена','dill');\Lavka\competitors_save_target($db,['match_confirmed'=>false],$manualNo['id']);
 $manualYes=$remote('Ручной выбор','Совсем другой текст','dill');\Lavka\competitors_save_target($db,['match_confirmed'=>true],$manualYes['id']);
 $unknownStock=$remote('Укроп молотый','Укроп молотый','ground','','unknown');
 $archived=$remote('Укроп молотый','Укроп молотый','ground');\Lavka\competitors_save_target($db,['enabled'=>false],$archived['id']);
 $before=$db->query('SELECT target_id,payload FROM competitor_observations ORDER BY id')->fetchAll();
 $result=\Lavka\competitors_matching_reconcile($db);
 match_expect($result['automatic']===2&&$result['newly_confirmed']===2,'Read exact bilingual products are matched; unread, form, numeric, percentage, ambiguity and jar cases are held');
 match_expect(\Lavka\competitors_target($db,$good['id'])['match_confirmed'],'Actual title wins over a stale label');
 \Lavka\competitors_save_target($db,['enabled'=>false,'match_confirmed'=>true],$good['id']);
 \Lavka\competitors_save_target($db,['enabled'=>true,'match_confirmed'=>true],$good['id']);
 match_expect(\Lavka\competitors_target($db,$good['id'])['match_method']==='auto','A full-body archive/edit must not turn automatic matching into a human quality confirmation');
 foreach([$wrongRead,$wrongForm,$jar,$jarOffer,$strength,$concentration,$ambiguous,$unread,$manualNo,$archived] as $t)match_expect(!\Lavka\competitors_target($db,$t['id'])['match_confirmed'],'Unsafe or manually rejected card stays unconfirmed: '.$t['id']);
 match_expect(\Lavka\competitors_target($db,$manualYes['id'])['match_confirmed'],'Explicit human decision is retained');
 match_expect(\Lavka\competitors_target($db,$unknownStock['id'])['match_confirmed'],'Product identity does not invent variant stock; stock remains unknown for the comparison engine');
 match_expect($before===$db->query('SELECT target_id,payload FROM competitor_observations ORDER BY id')->fetchAll(),'Automatic reconciliation never changes source prices or observations');
 match_expect(\Lavka\competitors_matching_reconcile($db)['newly_confirmed']===0,'Repeated matching is idempotent');
 \Lavka\competitors_record_attempt($db,$good['id'],match_observation('Кориандр семена'),null,$now,200);
 match_expect(!\Lavka\competitors_target($db,$good['id'])['match_confirmed'],'Any new observation immediately revokes previous automatic approval');
 \Lavka\competitors_matching_reconcile($db);match_expect(!\Lavka\competitors_target($db,$good['id'])['match_confirmed'],'Changed product title cannot keep an old automatic match');
 \Lavka\competitors_record_attempt($db,$good['id'],match_observation('Укроп семена'),null,$now,200);\Lavka\competitors_matching_reconcile($db);
 match_expect(\Lavka\competitors_target($db,$good['id'])['match_confirmed'],'A later exact read can restore automatic matching');
 \Lavka\competitors_save_target($db,['match_confirmed'=>false],$good['id']);\Lavka\competitors_matching_reconcile($db);
 match_expect(!\Lavka\competitors_target($db,$good['id'])['match_confirmed'],'Human opt-out cannot be silently reversed');
 $catalog=['state'=>'ok','products'=>[['target_id'=>$dill['id'],'label'=>'Укроп семена 50 г']]];
 match_expect(\Lavka\competitors_matching_signature('Агар 1200')!==\Lavka\competitors_matching_signature('Агар 900'),'Meaningful strength numbers survive tokenization');
 match_expect(\Lavka\competitors_matching_signature('Укроп семена 50 г')===\Lavka\competitors_matching_signature('Кріп насіння 0,5 кг'),'Pack weights do not change the product identity');
 if(PHP_VERSION_ID>=80200){
  require_once dirname(__DIR__).'/bin/competitors.php';
  $cached=$remote('Укроп семена','Укроп семена','dill');$db->exec("UPDATE competitor_targets SET next_check_at='2099-01-01T00:00:00Z',queued=0");
  $calls=0;$run=lavka_competitors_run(['data'=>$dir,'limit'=>1,'seconds'=>30],function()use(&$calls){$calls++;throw new RuntimeException('Unexpected HTTP request');});
  match_expect($calls===0&&$run['automatic_matching']['newly_confirmed']===1&&\Lavka\competitors_target($db,$cached['id'])['match_confirmed'],'Ordinary worker reconciles cached products even with no page due or extra HTTP');
 }
 echo \Lavka\encode(['exact_read_products'=>'passed','wrong_form_strength_concentration_and_jar'=>'held','ambiguous_catalog'=>'held','manual_decisions'=>'preserved','observations_and_prices'=>'unchanged','new_read_revokes_old_match'=>'passed','php'=>PHP_VERSION])."\n";
}finally{match_cleanup($dir);}

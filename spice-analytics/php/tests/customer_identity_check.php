<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/App.php';
function ci_expect(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function ci_fixture():array{
 $rows=array_fill_keys(array_keys(\Lavka\TABLES),[]);
 $specs=[['101','1','Ірина','Спільна','2025-01-01','UAH','100'],['102','2','Олег','Спільна','2026-01-01','UAH','200'],['103','3','Марія','Інша','2026-02-01','UAH','300'],['104','4','Віктор','Окремий','2026-03-01','USD','40'],['105','1','Ірина','Попередня','2024-01-01','UAH','50'],['106','5','Світлана Спільна','','2026-04-01','UAH','60'],['107','6','Олена','Майбутня','2027-01-01','UAH','999']];
 foreach($specs as $v){[$id,$account,$first,$last,$date,$currency,$amount]=$v;$o=array_fill_keys(\Lavka\SOURCE_FIELDS['oc_order'],'');$rows['oc_order'][$id]=array_merge($o,['order_id'=>$id,'customer_id'=>$account,'firstname'=>$first,'lastname'=>$last,'date_added'=>$date.' 12:00:00','date_modified'=>$date.' 12:00:00','email'=>'person'.$account.'@example.invalid','telephone'=>'+38096'.str_pad($account,7,'0',STR_PAD_LEFT),'total'=>$amount,'currency_code'=>$currency,'currency_value'=>'1','order_status_id'=>'2','shipping_city'=>'Київ']);$rows['oc_order_product'][$id]=['order_product_id'=>$id,'order_id'=>$id,'product_id'=>'1','name'=>'Синтетическая специя','model'=>'test','quantity'=>'1','price'=>$amount,'total'=>$amount];$rows['oc_order_option'][$id]=['order_option_id'=>$id,'order_id'=>$id,'order_product_id'=>$id,'name'=>'Фасовка','value'=>'100 г'];}
 $rows['oc_order_status']['2:1']=['order_status_id'=>'2','language_id'=>'1','name'=>'В обработке'];return $rows;
}
if(defined('LAVKA_IDENTITY_FIXTURE_ONLY'))return;
$dir=sys_get_temp_dir().'/lavka-customer-identity-'.bin2hex(random_bytes(5));\Lavka\private_dir($dir);
try{
 $db=\Lavka\database($dir.'/analytics.sqlite3');\Lavka\init_schema($db);\Lavka\apply_snapshot($db,ci_fixture(),'test');$store=new \Lavka\Analytics($db,$dir);
 $report=$store->report(2026,'2026-12-31','UAH');$pair=array_values(array_filter($report['duplicate_pairs'],function($p){return $p['id']==='101:102';}));
 ci_expect(count($pair)===1&&$pair[0]['kinds']===['surname'],'Different first names with the same surname form a weak candidate');
 ci_expect(((array)$report['crm']['customers'])['101']['duplicates']['strong']===false,'Surname is never a strong duplicate signal');
 ci_expect(\Lavka\identity_order_surname(['firstname'=>'Лідія Коротун','lastname'=>''])==='Коротун','A complete name inside firstname still has a surname');
 ci_expect(\Lavka\identity_order_surname(['firstname'=>'Коротун Василь','lastname'=>''])==='Коротун','Surname-first combined field');
 foreach(['ТОВ Спеції','Анна Марія','Ірина Петрівна'] as $name)ci_expect(\Lavka\identity_order_surname(['firstname'=>$name,'lastname'=>''])==='','Organization, two first names and patronymic are not surnames');
 ci_expect(count(\Lavka\identity_customer_search($store,'2026-12-31','Спільна')['customers'])===3,'Dedicated surname and combined first-name field are searchable');
 ci_expect(\Lavka\identity_customer_search($store,'2026-12-31','Попередня')['customers'][0]['id']==='101','Historical name remains searchable');
 ci_expect(\Lavka\identity_customer_search($store,'2026-12-31','Окремий')['customers'][0]['id']==='104','Picker spans currencies');
 ci_expect(\Lavka\identity_customer_search($store,'2026-12-31','Майбутня')['total']===0,'Future records stay out of the picker');
 $raw=$db->query('SELECT payload FROM raw_records ORDER BY rowid')->fetchAll(PDO::FETCH_COLUMN);$before=hash('sha256',\Lavka\encode($raw));
 $result=\Lavka\merge_identity_customers($db,$dir,['101','103'],'2026-12-31');ci_expect($result['customer_id']==='101'&&$result['cards_merged']===2,'Arbitrary unrelated cards can share one history');
 $after=(new \Lavka\Analytics($db,$dir))->report(2026,'2026-12-31','UAH');$merged=array_values(array_filter($after['customers'],function($c){return $c['id']==='101';}))[0];
 ci_expect(in_array('Марія Інша',$merged['names'],true)&&in_array('Ірина Спільна',$merged['names'],true)&&count($merged['emails'])===2&&count($merged['phones'])===2,'All names and contacts survive');
 ci_expect($merged['lifetime_count']===3&&$merged['amount']===300.0&&$merged['manual'],'Complete history and selected-year amount aggregate independently');
 ci_expect($after['metrics']['amount']===$report['metrics']['amount']&&$after['metrics']['order_count']===$report['metrics']['order_count']&&$after['metrics']['customer_count']===$report['metrics']['customer_count'],'Selected-year customer count stays correct when only one card bought in that year');
 ci_expect(count((new \Lavka\Analytics($db,$dir))->report(0,'2026-12-31','UAH')['customers'])===count($store->report(0,'2026-12-31','UAH')['customers'])-1,'Whole-history customer count reflects the explicit grouping');
 $links=(int)$db->query('SELECT count(*) FROM manual_links')->fetchColumn();
 foreach([['101','103'],['101','99999'],['101','101']] as $ids){try{\Lavka\merge_identity_customers($db,$dir,$ids,'2026-12-31');throw new RuntimeException('Invalid selection accepted');}catch(\Lavka\HttpError $e){ci_expect(in_array($e->status,[409,422],true),'Invalid or stale selection rejected');}}
 ci_expect((int)$db->query('SELECT count(*) FROM manual_links')->fetchColumn()===$links,'Invalid merge is atomic');
 \Lavka\merge_identity_customers($db,$dir,['101','104'],'2026-12-31');$cross=(new \Lavka\Analytics($db,$dir))->report(2026,'2026-12-31','UAH');$card=array_values(array_filter($cross['customers'],function($c){return $c['id']==='101';}))[0];
 ci_expect(in_array('Віктор Окремий',$card['names'],true)&&$cross['metrics']['amount']===$report['metrics']['amount'],'Cross-currency contacts remain searchable without summing currencies');
 ci_expect($before===hash('sha256',\Lavka\encode($db->query('SELECT payload FROM raw_records ORDER BY rowid')->fetchAll(PDO::FETCH_COLUMN))),'Source orders are byte-for-byte unchanged');
 $db->exec('DELETE FROM manual_links');\Lavka\bump($db);$restored=(new \Lavka\Analytics($db,$dir))->report(2026,'2026-12-31','UAH');ci_expect($restored['metrics']===$report['metrics'],'Removing manual links restores original report totals and counts');
 $many=[];for($i=1;$i<=30;$i++)$many[]=['id'=>(string)$i,'name'=>'Имя '.$i,'names'=>['Имя '.$i],'surnames'=>['Спільна'],'email'=>'','phone'=>'','emails'=>[],'phones'=>[],'lifetime_count'=>1,'lifetime_amount'=>1,'city'=>''];
 $pairs=\Lavka\identity_pairs($many);$ids=[];foreach($pairs as $p)foreach($p['ids'] as $id)$ids[$id]=true;ci_expect(count($pairs)===29&&count($ids)===30,'Common surnames retain every card without a quadratic pair explosion');
 echo \Lavka\encode(['surname_candidates'=>'passed','arbitrary_manual_grouping'=>'passed','all_contact_aliases_and_currencies'=>'passed','atomic_stale_selection'=>'passed','source_records_and_totals'=>'unchanged','php'=>PHP_VERSION])."\n";
}finally{$db=null;foreach(glob($dir.'/*') as $p)if(is_file($p))unlink($p);rmdir($dir);}

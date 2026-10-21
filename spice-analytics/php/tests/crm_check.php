<?php

declare(strict_types=1);
require_once dirname(__DIR__).'/src/App.php';
function ck(bool $v,string $message):void {if(!$v)throw new RuntimeException($message);}
function data_rows(array $specs):array {
 $rows=array_fill_keys(array_keys(\Lavka\TABLES),[]);
 foreach($specs as $v){[$id,$date,$account,$amount,$currency,$status]=$v;
  $o=array_fill_keys(\Lavka\SOURCE_FIELDS['oc_order'],'');
  $rows['oc_order'][$id]=array_merge($o,['order_id'=>$id,'customer_id'=>$account,'firstname'=>'Тест','lastname'=>'Клиент'.$account,'email'=>'shared@example.invalid','telephone'=>'+38096'.str_pad($account,7,'0',STR_PAD_LEFT),'total'=>$amount,'currency_code'=>$currency,'currency_value'=>'1','order_status_id'=>$status,'date_added'=>$date,'date_modified'=>$date,'shipping_city'=>'Київ','shipping_country'=>'Україна']);
  $rows['oc_order_product'][$id]=['order_product_id'=>$id,'order_id'=>$id,'product_id'=>'7','name'=>'Кориандр','model'=>'test','quantity'=>'2','price'=>'50','total'=>$amount];
  $rows['oc_order_option'][$id]=['order_option_id'=>$id,'order_id'=>$id,'order_product_id'=>$id,'name'=>'Фасовка','value'=>'200 г'];
 }
 $rows['oc_order_status']['2:1']=['order_status_id'=>'2','language_id'=>'1','name'=>'В обработке'];return $rows;
}
function event_body(string $id,string $at,string $kind='start'):array {return ['id'=>str_pad($id,32,'0',STR_PAD_LEFT),'kind'=>$kind,'occurred_at'=>$at,'owner'=>'Таня','channel'=>'call','outcome'=>'no_answer','permission'=>'unknown','offer'=>'none','coupon'=>'','window_days'=>30,'reasons'=>['unknown'],'next_contact'=>null,'note'=>'Синтетическая запись','ai_text'=>''];}
function report_crm(PDO $db,string $dir,string $asof='2026-06-30'):array {return (new \Lavka\Analytics($db,$dir))->report(0,$asof,'UAH')['crm'];}
function cust(array $r,string $id):array {return ((array)$r['customers'])[$id];}
$dir=sys_get_temp_dir().'/crm-check-'.bin2hex(random_bytes(5));\Lavka\private_dir($dir);
try {
 $db=\Lavka\database($dir.'/analytics.sqlite3');\Lavka\init_schema($db);
 $specs=[['1','2025-01-01 12:00:00','1','100','UAH','2'],['2','2025-02-01 12:00:00','1','200','UAH','2'],['3','2025-01-01 12:00:00','2','50','UAH','2']];
 \Lavka\apply_snapshot($db,data_rows($specs),'test');$r=report_crm($db,$dir);
 ck(cust($r,'1')['duplicates']['count']===1&&cust($r,'1')['duplicates']['strong'],'Shared email is warned for each profile');
 ck(cust($r,'1')['tier']==='gold'&&cust($r,'3')['tier']==='bronze','Value tiers use lifetime amount and repeat purchases');
 $body=event_body('a','2026-01-01T12:00:00');\Lavka\crm_save_event($db,$dir,'1','2026-01-01','UAH',$body);
 ck(\Lavka\crm_save_event($db,$dir,'1','2026-01-01','UAH',$body)['repeated'],'Retry is idempotent');
 ck((int)$db->query('SELECT count(*) FROM crm_events')->fetchColumn()===1,'A retried request never duplicates a contact');
 $r=report_crm($db,$dir,'2026-01-10');ck($r['stats']['waiting']===1&&$r['stats']['matured_attempts']===0&&cust($r,'1')['state']==='waiting','An immature window is not a failed return');
 try {\Lavka\crm_save_event($db,$dir,'1','2026-01-10','UAH',event_body('b','2026-01-02T12:00:00'));throw new RuntimeException('Overlap accepted');}catch(\Lavka\HttpError $e){ck($e->status===409,'Overlapping attempt rejected');}
 $reply=event_body('c','2026-01-03T12:00:00','reply');$reply['outcome']='interested';$reply['reasons']=['stock'];$reply['offer']='pack';\Lavka\crm_save_event($db,$dir,'1','2026-01-10','UAH',$reply);
 $specs[]=['4','2026-01-01 11:59:59','1','900','UAH','2'];
 $specs[]=['5','2026-01-04 12:00:00','1','150','UAH','0'];
 $specs[]=['6','2026-01-05 12:00:00','1','100','USD','2'];
 $specs[]=['7','2026-01-07 12:00:00','1','180','UAH','2'];
 $specs[]=['8','2026-01-08 12:00:00','1','200','UAH','2'];
 \Lavka\apply_snapshot($db,data_rows($specs),'test');$r=report_crm($db,$dir,'2026-01-09');$a=cust($r,'1')['latest_attempt'];
 ck($a['first_order_id']==='7'&&$a['orders_count']===2&&$a['amount']===380.0,'Only later, eligible, same-currency orders count');
 ck($r['stats']['first_order_amount']===180.0&&$r['stats']['returned_customers']===1&&$r['stats']['replied_customers']===1,'Return, first amount and feedback linked to the attempt');
 ck($r['stats']['matured_attempts']===0&&$r['stats']['matured_rate']===null,'Early return does not enter completed-window rate yet');
 ck($r['stats']['open_windows']===1&&cust($r,'1')['last_event']['occurred_at']==='2026-01-03T12:00:00','Open windows and last actual interaction stay distinct');
 $r=report_crm($db,$dir,'2026-02-02');ck($r['stats']['matured_rate']===100.0&&$r['stats']['matured_attempts']===1,'Completed observation window has a defined denominator');
 foreach($specs as &$v)if($v[0]==='7')$v[5]='0';unset($v);\Lavka\apply_snapshot($db,data_rows($specs),'test');
 ck(cust(report_crm($db,$dir),'1')['latest_attempt']['first_order_id']==='8','Cancelled first return is replaced by the next eligible order');
 foreach($specs as &$v)if($v[0]==='8')$v[5]='0';unset($v);\Lavka\apply_snapshot($db,data_rows($specs),'test');
 ck(cust(report_crm($db,$dir),'1')['state']==='no_order','Cancellation can remove a previously observed return');
 ck((int)$db->query('SELECT count(*) FROM crm_events')->fetchColumn()===2,'Sync preserves the complete contact journal');
 $second=event_body('d','2026-03-01T12:00:00');\Lavka\crm_save_event($db,$dir,'3','2026-03-01','UAH',$second);
 $db->exec("INSERT INTO manual_links VALUES('1','3','test')");\Lavka\bump($db);
 $merged=report_crm($db,$dir);ck(cust($merged,'1')['events_count']===3&&$merged['stats']['attempts']===2,'Merge combines journals without losing attempts');
 $db->exec('DELETE FROM manual_links');\Lavka\bump($db);$split=report_crm($db,$dir);
 ck(cust($split,'1')['events_count']===2&&cust($split,'3')['events_count']===1,'Undo merge restores original journals');
 $stop=event_body('e','2026-03-02T12:00:00','reply');$stop['permission']='no';$stop['outcome']='do_not_contact';\Lavka\crm_save_event($db,$dir,'3','2026-03-02','UAH',$stop);
 try {\Lavka\crm_save_event($db,$dir,'3','2026-03-03','UAH',event_body('f','2026-03-03T12:00:00','contact'));throw new RuntimeException('Suppression ignored');}catch(\Lavka\HttpError $e){ck($e->status===409,'Opt-out blocks active outreach');}
 $ai=event_body('f','2026-03-03T12:00:00','analysis');$ai['ai_text']='<script>unsafe</script> Синтетический ответ';\Lavka\crm_save_event($db,$dir,'3','2026-03-03','UAH',$ai);
 ck((int)$db->query('SELECT count(*) FROM crm_events')->fetchColumn()===5,'Analysis is saved as data, even for a suppressed customer');
 $reply=event_body('10','2026-03-04T12:00:00','reply');$reply['outcome']='interested';$reply['permission']='yes';\Lavka\crm_save_event($db,$dir,'3','2026-03-04','UAH',$reply);
 ck(((array)(new \Lavka\Analytics($db,$dir))->report(0,'2026-03-04','UAH')['customers'][1]['action'])['stage']==='do_not_contact','A later reply never silently lifts a contact prohibition');
 [$store,$report,$c,$events,$orders]=\Lavka\crm_context($db,$dir,'3','2026-03-03','UAH');$plan=\Lavka\crm_plan($store,$c,$orders,$events,cust($report['crm'],'3'),'2026-03-03');
 ck($plan['stopped']&&$plan['products'][0]['pack']==='200 г','Plan uses known packaging and respects suppression');
 $stmt=$db->prepare('UPDATE crm_events SET voided_at=?,void_reason=? WHERE id=?');$stmt->execute(['2026-04-01T12:00:00','Test cancellation',str_pad('d',32,'0',STR_PAD_LEFT)]);
 ck(report_crm($db,$dir)['stats']['attempts']===1,'Voided attempt is preserved in history but excluded from tracking');
 $many=[];for($i=1;$i<=80;++$i)$many[]=['id'=>(string)$i,'name'=>'Общее Имя','emails'=>['shared@example.invalid'],'phones'=>[]];
 $warnings=\Lavka\crm_duplicate_index($many);ck($warnings['1']['count']>=79&&$warnings['1']['at_least']&&count($warnings['1']['ids'])<=20,'Large shared-contact groups remain warned with bounded details');
 $overlaps=[['id'=>'old','kind'=>'start','occurred_at'=>'2026-01-01T10:00:00','created_at'=>'2026-01-01T10:00:00','window_days'=>30,'owner'=>'Тест','voided_at'=>null],['id'=>'new','kind'=>'start','occurred_at'=>'2026-01-02T10:00:00','created_at'=>'2026-01-02T10:00:00','window_days'=>30,'owner'=>'Тест','voided_at'=>null]];
 $orders=[['order_id'=>'100','date_added'=>'2025-01-01 12:00:00','total'=>'10','currency_value'=>'1'],['order_id'=>'101','date_added'=>'2026-01-03 12:00:00','total'=>'100','currency_value'=>'1']];
 $windows=\Lavka\crm_attempts($overlaps,$orders,'2026-02-28');
 ck($windows[0]['superseded']&&!$windows[0]['matured']&&$windows[0]['first_order_id']===null&&$windows[1]['first_order_id']==='101','Overlapping attempts after identity merge attribute the order once');
 echo json_encode(['journal_idempotence_and_sync'=>'passed','duplicate_and_value_checks'=>'passed','time_status_currency_attribution'=>'passed','matured_denominator_and_cancellation'=>'passed','merge_unmerge_history'=>'passed','suppression_and_manual_ai'=>'passed','php'=>PHP_VERSION]),"\n";
} finally {$db=null;foreach(glob($dir.'/*')as$f)if(is_file($f))unlink($f);rmdir($dir);}

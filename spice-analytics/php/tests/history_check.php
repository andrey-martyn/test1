<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/src/App.php';
function verify(bool $ok, string $label): void { if (!$ok) throw new RuntimeException($label); }
function fixture(array $specs): array {
    $rows = array_fill_keys(array_keys(\Lavka\TABLES), []);
    foreach ($specs as $v) {
        [$id, $date, $account, $amount, $currency, $status, $email, $first, $last] = $v;
        $o = array_fill_keys(\Lavka\SOURCE_FIELDS['oc_order'], '');
        $rows['oc_order'][$id] = array_merge($o, ['order_id'=>$id,'date_added'=>$date.' 12:00:00','date_modified'=>$date.' 12:00:00','customer_id'=>$account,'total'=>$amount,'currency_code'=>$currency,'currency_value'=>'1','order_status_id'=>$status,'email'=>$email,'telephone'=>'+38096'.str_pad($id,7,'0',STR_PAD_LEFT),'firstname'=>$first,'lastname'=>$last,'shipping_city'=>'Київ','shipping_zone'=>'Київська область','shipping_country'=>'Україна']);
        $rows['oc_order_product'][$id] = ['order_product_id'=>$id,'order_id'=>$id,'product_id'=>'1','name'=>'Тестовый товар','model'=>'test','quantity'=>'2','price'=>bcdiv($amount,'2',4),'total'=>$amount];
        $rows['oc_order_option'][$id] = ['order_option_id'=>$id,'order_id'=>$id,'order_product_id'=>$id,'name'=>'Фасовка','value'=>'500 г'];
    }
    $rows['oc_order_status']['2:1'] = ['order_status_id'=>'2','language_id'=>'1','name'=>'В обработке'];
    return $rows;
}
$dir=sys_get_temp_dir().'/lavka-history-'.bin2hex(random_bytes(6));\Lavka\private_dir($dir);
try {
    $db=\Lavka\database($dir.'/analytics.sqlite3');\Lavka\init_schema($db);
    $specs=[
        ['1','2020-12-31','1','100','UAH','2','a@example.invalid','Клиент','А'],
        ['2','2021-01-01','1','50','UAH','2','a@example.invalid','Клиент','А'],
        ['3','2021-12-31','2','200','UAH','2','b@example.invalid','Клиент','Б'],
        ['4','2022-01-01','2','80','UAH','2','b@example.invalid','Клиент','Б'],
        ['5','2022-06-01','3','40','UAH','0','c@example.invalid','Клиент','В'],
        ['6','2022-07-01','4','30','USD','2','d@example.invalid','Клиент','Г'],
        ['7','2023-01-01','5','999','UAH','2','e@example.invalid','Клиент','Д'],
        ['8','2021-01-03','1','25','UAH','2','a@example.invalid','Клиент','А'],
    ];
    \Lavka\apply_snapshot($db,fixture($specs),'test');
    $store=new \Lavka\Analytics($db,$dir);$all=$store->report(0,'2022-12-31','UAH');
    verify($all['metrics']['amount']===455.0 && $all['metrics']['order_count']===5 && $all['metrics']['customer_count']===2,'All time filters statuses, currency and future orders');
    verify($all['history_totals']['customer_count']===2 && array_sum(array_column($all['annual'],'customer_count'))===4,'Unique total never sums annual customer counts');
    verify(array_column($all['annual'],'amount')===[100.0,275.0,80.0],'Calendar-year amounts include Jan 1 in its own year');
    verify(array_column($all['annual'],'new_customers')===[1,1,0] && array_column($all['annual'],'returning_customers')===[0,1,1],'First purchase and returning from previous years');
    verify($all['metrics']['kilograms']===5.0 && $all['metrics']['quantity']===10,'Packaging totals across history');
    verify(array_sum(array_column($all['products'][0]['years'],'quantity'))===10,'Product yearly quantities');
    verify(array_sum(array_column($all['locations'][0]['years'],'count'))===5,'Geography yearly order counts');
    $year=$store->report(2021,'2022-12-31','UAH');
    verify($year['metrics']['amount']===275.0 && $year['metrics']['order_count']===3,'Selected calendar-year amount');
    verify($year['calendar'][0]['start']==='2021-01-01' && end($year['calendar'])['end']==='2021-12-31','Clipped weeks cover calendar boundaries');
    verify(((array)$year['activity'])['2020-12-28']['amount']===75.0 && array_sum(array_column((array)$year['activity'],'count'))===3,'Weeks use actual Monday date, with no cross-year collision');
    foreach ($all['customers'] as $c) verify(array_sum(array_column((array)$c['activity'],'amount'))===$c['amount'],'Customer year cells sum to selected amount');
    $past=$store->report(0,'2021-01-02','UAH');verify($past['metrics']['amount']===150.0,'Past as-of hides later purchases');
    \Lavka\ga4_import($db,"date,channel,sessions,purchaseRevenue,currencyCode\n20201231,Direct,10,100,UAH\n20210101,Direct,20,50,UAH\n20230101,Direct,999,999,UAH\n",'daily','UAH');
    verify(\Lavka\ga4_report($db,0,'2022-12-31','UAH',[])['totals']['sessions']===30.0,'GA4 all-time filter');
    verify(\Lavka\ga4_report($db,2021,'2022-12-31','UAH',[])['totals']['sessions']===20.0,'GA4 calendar boundary');
    $specs=[];foreach (['21','22','23','24'] as $id) {$group=(int)$id<23?'A':'B';$specs[]=[$id,'2021-07-01',$id,'100','UAH','2','pair'.$group.'@example.invalid','Тест','Пара'.$group];}
    \Lavka\apply_snapshot($db,fixture($specs),'test');
    $before=new \Lavka\Analytics($db,$dir);$pairs=$before->report(0,'2022-12-31','UAH')['duplicate_pairs'];
    verify(count($pairs)===2 && count($pairs[0]['matches'])===3&&in_array('surname',$pairs[0]['kinds'],true),'One row per pair with full name, surname and email');
    try {\Lavka\merge_identity_pairs($db,$dir,[['21','22'],['21','24']],'2022-12-31','UAH');throw new RuntimeException('Invalid pair accepted');}
    catch (\Lavka\HttpError $e) {verify($e->status===409,'Stale pair validation');}
    verify((int)$db->query('SELECT count(*) FROM manual_links')->fetchColumn()===0,'Invalid batch leaves every row unchanged');
    $result=\Lavka\merge_identity_pairs($db,$dir,[['21','22'],['23','24']],'2022-12-31','UAH');
    verify($result['groups']===2 && $result['pairs_merged']===2,'Independent selected pairs stay separate');
    $after=(new \Lavka\Analytics($db,$dir))->report(0,'2022-12-31','UAH');
    verify(count($after['customers'])===2 && $after['metrics']['amount']===400.0,'Merge preserves all order amounts');
    try {\Lavka\merge_identity_pairs($db,$dir,[['21','22']],'2022-12-31','UAH');throw new RuntimeException('Stale pair accepted');}
    catch (\Lavka\HttpError $e) {verify($e->status===409,'Already merged pair rejected');}
    $db->exec('DELETE FROM manual_links');
    $specs=[];foreach (['31','32','33','34'] as $id) $specs[]=[$id,'2021-07-01',$id,'100','UAH','2',$id==='34'?'separate@example.invalid':'chain@example.invalid','Контакт'.$id,'Тест'];
    \Lavka\apply_snapshot($db,fixture($specs),'test');
    $result=\Lavka\merge_identity_pairs($db,$dir,[['31','32'],['32','33']],'2022-12-31','UAH');
    verify($result['groups']===1 && $result['contacts']===3,'Overlapping pairs form the displayed chain');
    verify(count((new \Lavka\Analytics($db,$dir))->report(0,'2022-12-31','UAH')['customers'])===2,'Unselected contact is never merged');
    echo json_encode(['all_time_and_calendar_boundaries'=>'passed','unique_customers_and_asof'=>'passed','ga4_history'=>'passed','pair_batches_and_atomic_validation'=>'passed','php'=>PHP_VERSION]),"\n";
} finally {
    $db=null;
    foreach (glob($dir.'/*') as $file) if (is_file($file)) unlink($file);
    rmdir($dir);
}

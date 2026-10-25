<?php

declare(strict_types=1);
require_once dirname(__DIR__).'/src/App.php';
function regional_verify(bool $condition,string $message): void{if(!$condition)throw new RuntimeException($message);}
function regional_fixture(): array
{
    $rows=array_fill_keys(array_keys(\Lavka\TABLES),[]);
    $specs=[
        ['1','2025-01-05','1','10','Киев','Киевская область','Самовывоз Одесса'],
        ['2','2025-09-10','2','100','Одесса','Одесская область','Доставка в отделение НП'],
        ['3','2025-11-20','3','200','Одеса','Одеська область','Доставка відділення НП'],
        ['4','2025-02-20','4','80','Киев','Киевская область','Новая почта'],
        ['5','2025-03-05','5','40','Чуква','Львівська область','Нова пошта'],
        ['6','2026-01-03','6','20','','','Самовивіз Одеса'],
        ['7','2026-09-12','1','110','Одеса','Одеська область','Новая почта'],
        ['8','2026-02-25','8','120','Київ','Київська область','Нова пошта'],
        ['9','2026-11-10','9','300','Львів','Львівська область','Нова пошта'],
        ['10','2026-05-10','10','999','Харьков','Харьковская область','Нова пошта'],
        ['11','2026-05-11','11','999','Харьков','Харьковская область','Нова пошта'],
        ['12','2026-08-15','6','30','Одеса','Одеська область','Нова пошта'],
        ['13','2026-06-01','13','70','Геронімівка','Черкаська область','Доставка відділення НП'],
        ['14','2026-06-02','14','90','Подгородное (Днепропетровская обл.)','','Доставка в отделение НП'],
    ];
    foreach($specs as $spec){[$id,$date,$customer,$amount,$city,$zone,$method]=$spec;$order=array_fill_keys(\Lavka\SOURCE_FIELDS['oc_order'],'');
        $rows['oc_order'][$id]=array_merge($order,['order_id'=>$id,'customer_id'=>$customer,'date_added'=>$date.' 12:00:00','date_modified'=>$date.' 12:00:00','total'=>$amount,'currency_code'=>$id==='11'?'USD':'UAH','currency_value'=>'1','order_status_id'=>$id==='10'?'5':'2','firstname'=>(int)$customer%2?'Виктор':'Светлана','lastname'=>'Проверка','email'=>'region'.$customer.'@example.invalid','shipping_city'=>$city,'shipping_zone'=>$zone,'shipping_country'=>'Украина','shipping_method'=>$method]);
        $rows['oc_order_product'][$id]=['order_product_id'=>$id,'order_id'=>$id,'product_id'=>'1','name'=>'Синтетическая специя','model'=>'test','quantity'=>'1','price'=>$amount,'total'=>$amount];
        $rows['oc_order_option'][$id]=['order_option_id'=>$id,'order_id'=>$id,'order_product_id'=>$id,'name'=>'Фасовка','value'=>'500 г'];
    }
    foreach(['2'=>'В обработке','5'=>'Отмена'] as $id=>$label)$rows['oc_order_status'][$id.':1']=['order_status_id'=>(string)$id,'language_id'=>'1','name'=>$label];
    return $rows;
}
if(defined('LAVKA_REGIONAL_FIXTURE_ONLY'))return;
$dir=sys_get_temp_dir().'/lavka-regional-'.bin2hex(random_bytes(6));\Lavka\private_dir($dir);
try{
    $db=\Lavka\database($dir.'/analytics.sqlite3');\Lavka\init_schema($db);\Lavka\apply_snapshot($db,regional_fixture(),'test');
    $analytics=new \Lavka\Analytics($db,$dir);$report=$analytics->report(2026,'2026-10-25','UAH');$data=$report['regional'];
    $model=$data['modes']['mixed'];
    regional_verify($model['full']['all']['regions']['UA-51'][2025]['count']===3&&$model['same']['all']['regions']['UA-51'][2025]['count']===2,'Comparable dates exclude last-year November');
    regional_verify($model['same']['all']['regions']['UA-51'][2026]['count']===3&&$model['same']['all']['regions']['UA-51'][2026]['amount']===160.0,'Eligible current-year scope excludes future, status and currency');
    regional_verify($model['same']['pickup']['regions']['UA-51'][2025]['count']===1,'Explicit Odesa pickup outranks retained buyer city');
    regional_verify($model['same']['delivery']['regions']['UA-51'][2026]['count']===2,'Delivery separated from collection');
    $total=$model['same']['all']['totals'][2026];regional_verify($total['count']===6&&$total['amount']===440.0,'Global denominator covers every selected order');
    regional_verify($total['customer_count']===5&&$model['same']['pickup']['totals'][2026]['customer_count']+$model['same']['delivery']['totals'][2026]['customer_count']===6,'Unique store buyers never sum overlapping channels');
    regional_verify($model['same']['all']['regions']['UA-51'][2026]['returning_customers']===1&&$model['same']['all']['regions']['UA-51'][2026]['new_customers']===1,'Globally new buyers distinguished from returning years');
    regional_verify($model['same']['all']['regions']['UA-71'][2026]['count']===1,'Unlisted Nova Poshta village uses declared destination region');
    regional_verify($model['same']['all']['regions']['UA-12'][2026]['count']===1,'Explicit parenthesized oblast is usable delivery data');
    regional_verify($report['geo_quality']['needs_review']===0,'Valid regional delivery is not an address problem');
    foreach($data['modes'] as $mode=>$windows)foreach($windows as $window=>$channels)foreach($channels as $channel=>$scope)foreach((array)$scope['totals'] as $year=>$totals){
        $count=0;$amount=0;$buyers=[];foreach($scope['regions'] as $years){$cell=$years[$year]??null;if(!$cell)continue;$count+=$cell['count'];$amount+=$cell['amount'];foreach($cell['customer_ids'] as $id)$buyers[$id]=true;}
        regional_verify($count===$totals['count']&&$amount===$totals['amount']&&count($buyers)===$totals['customer_count'],'Region totals reconcile '.$mode.' '.$window.' '.$channel.' '.$year);
    }
    $female=$analytics->report(2026,'2026-10-25','UAH','female');regional_verify($female['regional']['modes']['mixed']['full']['all']['totals'][2026]['amount']===$female['metrics']['amount'],'Name estimate filter applies to entire regional history');
    $past=$analytics->report(0,'2025-10-25','UAH');regional_verify(!in_array(2026,$past['regional']['years'],true)&&!isset($past['regional']['modes']['mixed']['full']['all']['totals'][2026]),'No future years or names leak into historical view');
    $internal=$analytics->report(2026,'2026-10-25','UAH','all',false);regional_verify($internal['regional']===null&&$internal['metrics']===$report['metrics']&&\Lavka\encode($internal['customers'])===\Lavka\encode($report['customers']),'Internal CRM reports skip unused regional series without changing buyers or metrics');
    echo json_encode(['regional_same_dates_and_channels'=>'passed','unique_buyers_and_global_denominators'=>'passed','nova_poshta_and_parenthesized_regions'=>'passed','status_currency_name_and_asof'=>'passed','php'=>PHP_VERSION]),"\n";
}finally{$db=null;foreach(glob($dir.'/*') as $file)if(is_file($file))unlink($file);rmdir($dir);}

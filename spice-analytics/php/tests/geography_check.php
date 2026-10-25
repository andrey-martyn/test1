<?php

declare(strict_types=1);
require_once dirname(__DIR__).'/src/App.php';
function geo_verify(bool $ok,string $label): void {if(!$ok)throw new RuntimeException($label);}
function geography_fixture(): array
{
    $rows=array_fill_keys(array_keys(\Lavka\TABLES),[]);
    $specs=[
        ['1','2025-01-01','1','100','Одесса','Одесская область','Украина','Курьер'],
        ['2','2026-01-01','1','200','Одеса','Одеська область','Україна','Курьер'],
        ['3','2026-02-01','2','300','','','Украина','Самовывоз (г. Одесса)'],
        ['4','2026-03-01','3','400','Київ','Київська область','Україна','Самовывоз Одесса'],
        ['5','2026-04-01','4','200','Киев','Киевская область','Украина','Курьер'],
        ['6','2026-05-01','5','50','','','Украина','Курьер'],
        ['7','2026-05-02','6','100','Бровары','','Украина','Курьер'],
        ['8','2026-05-03','7','80','Новый Городок','','Украина','Курьер'],
        ['9','2026-05-04','8','90','Миколаїв','','Україна','Курьер'],
        ['10','2026-05-05','9','110','Николаев','Львовская область','Украина','Курьер'],
        ['11','2026-05-06','10','100','Миколаїв','Миколаївська область','Україна','Курьер'],
        ['12','2026-05-07','11','70','Odessa','Texas','USA','Курьер'],
        ['13','2026-05-08','12','1','','','','Курьер'],
        ['14','2026-05-09','13','20','','Одесская область','Украина','Курьер'],
        ['15','2026-05-10','14','120','','','Украина','Самовывоз'],
        ['16','2026-05-11','15','200','','','','Курьер'],
        ['17','2026-05-12','16','999','Одесса','Одесская область','Украина','Курьер'],
        ['18','2026-05-13','17','999','Одесса','Одесская область','Украина','Курьер'],
        ['19','2027-01-01','18','999','Одесса','Одесская область','Украина','Курьер'],
    ];
    foreach($specs as $v){
        [$id,$date,$account,$amount,$city,$zone,$country,$method]=$v;
        $o=array_fill_keys(\Lavka\SOURCE_FIELDS['oc_order'],'');
        $rows['oc_order'][$id]=array_merge($o,['order_id'=>$id,'date_added'=>$date.' 12:00:00','date_modified'=>$date.' 12:00:00','customer_id'=>$account,'total'=>$amount,'currency_code'=>$id==='18'?'USD':'UAH','currency_value'=>'1','order_status_id'=>$id==='17'?'5':'2','email'=>'synthetic'.$account.'@example.invalid','firstname'=>(int)$account%2?'Виктор':'Светлана','lastname'=>'Проверка','shipping_city'=>$city,'shipping_zone'=>$zone,'shipping_country'=>$country,'shipping_method'=>$method,'ip'=>in_array($id,['1','8','13','14'],true)?'81.2.69.160':'127.0.0.1']);
        if($id==='3')$rows['oc_order'][$id]=array_merge($rows['oc_order'][$id],['payment_city'=>'Киев','payment_zone'=>'Киевская область','payment_country'=>'Украина']);
        $rows['oc_order_product'][$id]=['order_product_id'=>$id,'order_id'=>$id,'product_id'=>'1','name'=>'Синтетический товар','model'=>'fixture','quantity'=>'1','price'=>$amount,'total'=>$amount];
        $rows['oc_order_option'][$id]=['order_option_id'=>$id,'order_id'=>$id,'order_product_id'=>$id,'name'=>'Фасовка','value'=>'500 г'];
    }
    foreach(['2'=>'В обработке','5'=>'Отмена'] as $id=>$label)$rows['oc_order_status'][$id.':1']=['order_status_id'=>(string)$id,'language_id'=>'1','name'=>$label];
    return $rows;
}
if(defined('LAVKA_GEO_FIXTURE_ONLY'))return;
$directory=sys_get_temp_dir().'/lavka-geo-'.bin2hex(random_bytes(6));\Lavka\private_dir($directory);
try{
    copy(dirname(__DIR__,2).'/tests/fixtures/GeoIP2-City-Test.mmdb',$directory.'/geoip-city.mmdb');
    $geo=new \Lavka\Geo($directory);
    foreach(['Одесса'=>'Одеса','г.Одесса'=>'Одеса','м. Одеса'=>'Одеса','Киев'=>'Київ','Київ'=>'Київ','Харьков'=>'Харків','Львов'=>'Львів','Винница'=>'Вінниця','Ровно'=>'Рівне','Кривой Рог'=>'Кривий Ріг','Белая Церковь'=>'Біла Церква','Ивано-Франковск'=>'Івано-Франківськ','Днепропетровск'=>'Дніпро','Ильичевск'=>'Чорноморськ'] as $input=>$expected){
        $loc=$geo->resolve(['shipping_city'=>$input,'shipping_country'=>'Украина']);geo_verify($loc['city']===$expected,'Canonical city '.$input);
    }
    $reference=\Lavka\geo_city_reference();$aliases=0;
    foreach($reference['cities'] as $city){
        foreach($city['aliases'] as $alias){
            ++$aliases;$loc=$geo->resolve(['shipping_city'=>$alias,'shipping_zone'=>$city['zone_code'],'shipping_country'=>'UA']);
            geo_verify(!$loc['city_matched']||$loc['city']===$city['name'],'An ambiguous alias never silently becomes another city: '.$alias);
        }
        $loc=$geo->resolve(['shipping_city'=>$city['name'],'shipping_zone'=>$city['zone_code'],'shipping_country'=>'UA']);
        geo_verify($loc['city']===$city['name'],'Canonical self lookup '.$city['name']);
    }
    foreach(['Самовывоз Одесса','Самовивіз м. Одеса','Самовивіз в Одесі','Самовывоз в Одессе','Pickup Odessa','Самовивіз з Одеси'] as $method)geo_verify($geo->pickupOdesa(['shipping_method'=>$method]),'Pickup recognition '.$method);
    foreach(['Самовывоз','Курьер Одесса','Самовывоз Новая Одесса','Самовывоз в Новой Одессе','Самовивіз у Новій Одесі','Самовывоз Одесса недоступен','Не самовывоз Одесса'] as $method)geo_verify(!$geo->pickupOdesa(['shipping_method'=>$method]),'No invented pickup '.$method);
    $explicit=$geo->resolve(['shipping_city'=>'Киев','shipping_country'=>'Украина','shipping_method'=>'Самовывоз Одесса','ip'=>'81.2.69.160']);geo_verify($explicit['city']==='Київ'&&$explicit['source_kind']==='shipping','Delivery city outranks pickup and IP');
    $partial=$geo->resolve(['shipping_zone'=>'Одесская обл.','shipping_country'=>'Украина','ip'=>'81.2.69.160']);geo_verify($partial['zone_code']==='UA-51'&&$partial['city']==='Город не указан','Partial destination is not replaced by foreign IP');
    $known=$geo->resolve(['shipping_country'=>'Украина','ip'=>'81.2.69.160']);geo_verify($known['country_code']==='UA'&&$known['source_kind']==='unknown','Known country is not replaced by IP');
    geo_verify($geo->resolve(['ip'=>'81.2.69.160'])['country_code']==='GB','IP fallback when no destination is known');
    geo_verify($geo->resolve(['shipping_city'=>'Николаев','shipping_country'=>'Украина'])['zone']==='','Homonym has no guessed region');
    geo_verify($geo->resolve(['shipping_city'=>'с. Миколаїв','shipping_country'=>'Украина'])['city']==='с. Миколаїв','Village type is preserved');
    geo_verify($geo->resolve(['shipping_city'=>'Odessa','shipping_country'=>'USA','shipping_zone'=>'Texas'])['city']==='Odessa','Foreign homonym is untouched');
    geo_verify($geo->lookup('127.0.0.1')===null,'Private IP does not geolocate');
    $db=\Lavka\database($directory.'/analytics.sqlite3');\Lavka\init_schema($db);$fixture=geography_fixture();\Lavka\apply_snapshot($db,$fixture,'test');
    $s=new \Lavka\Analytics($db,$directory);$report=$s->report(0,'2026-12-31','UAH');
    $odesa=array_values(array_filter($report['locations'],function($l){return $l['city']==='Одеса';}));
    geo_verify(count($odesa)===1&&$odesa[0]['count']===3&&$odesa[0]['customers']===2&&$odesa[0]['amount']===600.0,'One city across spellings and sources with unique customers');
    geo_verify($odesa[0]['sources']===['Адрес доставки'=>2,'По месту самовывоза'=>1]&&count($odesa[0]['variants'])===2,'Sources and real original names remain visible');
    geo_verify($odesa[0]['years'][2025]['customers']===1&&$odesa[0]['years'][2026]['customers']===2,'Annual unique customers are recalculated');
    foreach($report['location_modes'] as $mode=>$locations){geo_verify(array_sum(array_column($locations,'count'))===16,'Each order occurs once '.$mode);geo_verify(array_sum(array_column($locations,'amount'))===$report['metrics']['amount'],'Amounts reconcile '.$mode);}
    $q=$report['geo_quality'];geo_verify($q['pickup_odesa']===1&&$q['delivery_without_city']===6&&$q['public_ip']===4&&($q['modes']['mixed']['sources']['ip']??0)===1,'Audit counts reflect actual fields, public IPs and recovery');
    geo_verify($s->order('3')['geography']['shipping_city']===''&&$s->order('3')['geography']['resolved']['city']==='Одеса','Order review shows raw fields and resolution');
    geo_verify($db->query("SELECT payload FROM raw_records WHERE table_name='oc_order' AND record_id='1'")->fetchColumn()!==false,'Raw snapshot remains available');
    $raw=$db->query("SELECT payload FROM raw_records WHERE table_name='oc_order'")->fetchAll(PDO::FETCH_COLUMN);geo_verify(in_array('Одесса',array_column(array_map('Lavka\\decode',$raw),'shipping_city'),true),'Original spelling is unchanged');
    $female=$s->report(2026,'2026-12-31','UAH','female');geo_verify(array_sum(array_column($female['locations'],'amount'))===$female['metrics']['amount'],'Name filter and geography share order set');
    echo json_encode(['canonical_aliases_tested'=>$aliases,'pickup_and_address_priority'=>'passed','homonyms_and_ip'=>'passed','unique_customers_and_history'=>'passed','raw_source_and_reconciliation'=>'passed','php'=>PHP_VERSION]),"\n";
}finally{
    $db=null;foreach(glob($directory.'/*') as $file)if(is_file($file))unlink($file);rmdir($directory);
}

<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/src/App.php';
function verify(bool $ok, string $label): void { if (!$ok) throw new RuntimeException($label); }
function gender_fixture(): array {
    $rows = array_fill_keys(array_keys(\Lavka\TABLES), []);
    $specs = [
        ['1','2021-01-01','1','Виктор','100'], ['2','2026-01-01','1','Viktor','200'],
        ['3','2026-02-01','2','Светлана','300'], ['4','2026-03-01','3','Саша','80'],
        ['5','2025-05-01','4','Андрей','40'], ['6','2026-06-01','4','Ірина','60'],
        ['7','2026-07-01','5','','10'], ['8','2026-08-01','6','Tetyana','50'],
        ['9','2026-08-02','7','В.','20'], ['10','2026-08-03','8','Alex','30'],
        ['11','2026-01-01','1','Анна','9999'], ['12','2026-01-01','1','Марія','900'],
        ['13','2027-01-01','1','Ольга','900'],
    ];
    foreach ($specs as $v) {
        [$id, $date, $account, $first, $amount] = $v;
        $o = array_fill_keys(\Lavka\SOURCE_FIELDS['oc_order'], '');
        $rows['oc_order'][$id] = array_merge($o, ['order_id'=>$id,'date_added'=>$date.' 12:00:00','date_modified'=>$date.' 12:00:00','customer_id'=>$account,'total'=>$amount,'currency_code'=>$id==='12'?'USD':'UAH','currency_value'=>'1','order_status_id'=>$id==='11'?'5':'2','email'=>'synthetic'.$account.'@example.invalid','firstname'=>$first,'lastname'=>'Проверка','shipping_city'=>'Київ','shipping_zone'=>'Київська область','shipping_country'=>'Україна']);
        $rows['oc_order_product'][$id] = ['order_product_id'=>$id,'order_id'=>$id,'product_id'=>$account,'name'=>'Тестовая специя '.$account,'model'=>'test'.$account,'quantity'=>'2','price'=>bcdiv($amount,'2',4),'total'=>$amount];
        $rows['oc_order_option'][$id] = ['order_option_id'=>$id,'order_id'=>$id,'order_product_id'=>$id,'name'=>'Фасовка','value'=>'500 г'];
    }
    $rows['oc_order_status']['2:1'] = ['order_status_id'=>'2','language_id'=>'1','name'=>'В обработке'];
    $rows['oc_order_status']['5:1'] = ['order_status_id'=>'5','language_id'=>'1','name'=>'Отмена'];
    return $rows;
}
if (defined('LAVKA_GENDER_FIXTURE_ONLY')) return;
foreach ([' Виктор '=>'male','ВІКТОР'=>'male','СвЕтЛаНа'=>'female','Мар’яна'=>'female',"Мар'яна"=>'female','Анна-Марія'=>'female','Oleksandr'=>'male','Svitlana'=>'female','ДМИТРО'=>'male','Серёжа'=>'male','Саша'=>'unknown','Женя'=>'unknown','Валя'=>'unknown','Alex'=>'unknown','Слава'=>'unknown','A.'=>'unknown','В.'=>'unknown',''=>'unknown','Тест'=>'unknown','Покупатель'=>'unknown','Светлана Виктор'=>'unknown','Виктор Анна'=>'unknown','ФОП Виктор'=>'unknown','ООО Анна'=>'unknown','Анна 123'=>'unknown','support@example.invalid'=>'unknown','<script>Анна</script>'=>'unknown','Неизвестная'=>'unknown'] as $name=>$expected) verify(\Lavka\name_gender_estimate($name)===$expected,'Name estimate: '.$name);
$dir=sys_get_temp_dir().'/lavka-gender-'.bin2hex(random_bytes(6));\Lavka\private_dir($dir);
try {
    $db=\Lavka\database($dir.'/analytics.sqlite3');\Lavka\init_schema($db);\Lavka\apply_snapshot($db,gender_fixture(),'test');
    $s=new \Lavka\Analytics($db,$dir);$all=$s->report(0,'2026-12-31','UAH');
    verify($all['metrics']['amount']===890.0 && $all['metrics']['order_count']===10 && $all['metrics']['customer_count']===8,'Eligible history stays complete');
    $breakdown=array_column($all['gender_breakdown'],null,'key');
    verify($breakdown['male']['amount']===300.0 && $breakdown['female']['amount']===350.0 && $breakdown['unknown']['amount']===240.0,'Unknown and mixed accounts stay in denominator');
    verify(array_sum(array_column($all['gender_breakdown'],'customer_count'))===8 && array_sum(array_column($all['gender_breakdown'],'order_count'))===10,'Each resolved customer and order occurs exactly once');
    foreach (['male'=>300.0,'female'=>350.0,'unknown'=>240.0] as $gender=>$amount) {
        $r=$s->report(0,'2026-12-31','UAH',$gender);
        verify($r['gender_filter']===$gender && $r['metrics']['amount']===$amount,'Filtered history amount '.$gender);
        verify(count(array_filter($r['customers'],function($c)use($gender){return $c['gender_estimate']!==$gender;}))===0,'Every customer belongs to group '.$gender);
        verify(array_sum(array_column($r['products'],'amount'))===$amount && array_sum(array_column((array)$r['activity'],'amount'))===$amount && array_sum(array_column($r['locations'],'amount'))===$amount && array_sum(array_column($r['annual'],'amount'))===$amount,'Products, activity, geography and years use the same order set '.$gender);
        verify($r['gender_breakdown']===$all['gender_breakdown'],'Comparison stays on all groups');
        verify(array_sum(array_column($r['marketing']['months'],'amount'))===$amount,'Seasonality uses the same order set');
        verify($r['ga4']===null,'No unsupported name segmentation of GA4 sessions');
    }
    $year=$s->report(2026,'2026-12-31','UAH','male');
    verify($year['metrics']['amount']===200.0 && $year['metrics']['average']===200.0 && $year['metrics']['quantity']===2 && $year['metrics']['kilograms']===1.0,'Selected year recalculates amounts and packaging');
    verify($year['customers'][0]['lifetime_count']===2 && $year['customers'][0]['lifetime_amount']===300.0 && $year['metrics']['repeat_customers']===1,'History and repeat purchases do not reset at period boundary');
    verify($s->report(0,'2025-12-31','UAH','male')['metrics']['amount']===140.0,'No future name leaks into historical estimate');
    verify($s->report(0,'2026-12-31','USD','female')['metrics']['amount']===900.0,'Currency isolation');
    $empty=$s->report(2021,'2026-12-31','UAH','female');verify($empty['metrics']['order_count']===0 && $empty['metrics']['average']===0,'Empty group has zero metrics');
    $cachedAll=\Lavka\cached_report($db,$dir,0,'2026-12-31','UAH');$cachedMale=\Lavka\cached_report($db,$dir,0,'2026-12-31','UAH','male');
    verify($cachedAll['metrics']['amount']===890.0 && $cachedMale['metrics']['amount']===300.0 && \Lavka\cached_report($db,$dir,0,'2026-12-31','UAH','female')['metrics']['amount']===350.0,'Report cache is isolated by filter');
    $db->exec("INSERT INTO manual_links VALUES('1','3','2026-10-25')");\Lavka\bump($db);
    $merged=(new \Lavka\Analytics($db,$dir))->report(0,'2026-12-31','UAH','unknown');
    verify($merged['metrics']['amount']===840.0 && count($merged['customers'])===6,'Manual merged mixed names move complete identity to unknown');
    $afterCache=\Lavka\cached_report($db,$dir,0,'2026-12-31','UAH','male');verify($afterCache['metrics']['amount']===0.0,'Merge invalidates cached group: '.json_encode($afterCache['metrics']));
    $_GET=['gender'=>['male']];try {\Lavka\report_gender();throw new RuntimeException('Array filter accepted');} catch (\Lavka\HttpError $e) {verify($e->status===422,'Array rejected');}
    $_GET=['gender'=>'unsupported'];try {\Lavka\report_gender();throw new RuntimeException('Unknown filter accepted');} catch (\Lavka\HttpError $e) {verify($e->status===422,'Unknown group rejected');}
    echo json_encode(['name_dictionary_and_ambiguity'=>'passed','money_identity_year_and_asof'=>'passed','products_geography_and_history'=>'passed','cache_and_merge'=>'passed','php'=>PHP_VERSION]),"\n";
} finally {
    $db=null;
    foreach (glob($dir.'/cache/*') as $file) if (is_file($file)) unlink($file);
    foreach (glob($dir.'/cache/.*') as $file) if (is_file($file)) unlink($file);
    if(is_dir($dir.'/cache')) rmdir($dir.'/cache');
    foreach (glob($dir.'/*') as $file) if (is_file($file)) unlink($file);
    rmdir($dir);
}

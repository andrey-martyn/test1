<?php

declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/src/App.php';
$args=getopt('',['data:','year:','as-of:','currency:','gender:']);
try{
    $directory=$args['data']??(getenv('ANALYTICS_DATA_DIR')?:dirname(__DIR__).'/data');
    $year=filter_var($args['year']??0,FILTER_VALIDATE_INT);$asof=$args['as-of']??date('Y-m-d');$currency=$args['currency']??'UAH';$gender=$args['gender']??'all';
    if(!is_string($directory)||!is_file($directory.'/analytics.sqlite3')||$year===false||($year!==0&&($year<2000||$year>2100))||!is_string($asof)||!\Lavka\valid_date($asof)||!is_string($currency)||!preg_match('/^[A-Z]{3}$/D',$currency)||!in_array($gender,['all','male','female','unknown'],true))throw new InvalidArgumentException('Неверные параметры или каталог данных');
    $db=\Lavka\database($directory.'/analytics.sqlite3');\Lavka\init_schema($db);
    $report=\Lavka\cached_report($db,$directory,$year,$asof,$currency,$gender);$quality=$report['geo_quality'];unset($quality['unknown_orders']);
    $merged=[];
    foreach($report['locations'] as $row)if($row['variant_count']>1||isset($row['sources']['По месту самовывоза']))$merged[]=['city'=>$row['city'],'zone'=>$row['zone'],'orders'=>$row['count'],'customers'=>$row['customers'],'amount'=>$row['amount'],'sources'=>$row['sources'],'variants'=>$row['variants']];
    echo \Lavka\encode(['state'=>'ok','year'=>$year,'as_of'=>$asof,'currency'=>$currency,'gender'=>$gender,'geoip'=>$report['geoip'],'quality'=>$quality,'merged_cities'=>$merged]),"\n";
}catch(Throwable $error){fwrite(STDERR,"Не удалось проверить географию. Проверьте каталог данных и параметры отчёта.\n");exit(1);}

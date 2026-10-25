<?php

declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/src/Competitors.php';
try{
    $args=getopt('',['data:']);$directory=$args['data']??'';
    if(!is_string($directory)||$directory===''||!is_dir($directory)||is_link($directory))throw new InvalidArgumentException('Укажите существующий закрытый каталог --data');
    $db=\Lavka\competitors_db($directory);$before=(int)$db->query("SELECT count(*) FROM competitor_targets WHERE enabled=1 AND source_id<>'lavka' AND kind='product'")->fetchColumn();
    \Lavka\competitors_discovery_start($db);$match=\Lavka\competitors_discovery_match($db,true);
    $db->prepare("UPDATE competitor_targets SET next_check_at=?,queued=1 WHERE enabled=1 AND source_id<>'lavka' AND kind='product' AND (last_success IS NULL OR last_attempt LIKE '%\"state\":\"error\"%')")->execute([\Lavka\utc_now()]);
    $db->prepare("UPDATE competitor_scan_pages SET next_check_at=? WHERE kind='product'")->execute([\Lavka\utc_now()]);
    echo \Lavka\encode(['state'=>'queued','matching'=>$match,'active_before'=>$before,'active_after'=>(int)$db->query("SELECT count(*) FROM competitor_targets WHERE enabled=1 AND source_id<>'lavka' AND kind='product'")->fetchColumn(),'network_requests'=>0,'shop_writes'=>false]),PHP_EOL;
}catch(Throwable $error){fwrite(STDERR,"Не удалось подготовить повторный поиск. Проверьте --data и доступ к закрытому каталогу.\n");exit(1);}

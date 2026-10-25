<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/src/Competitors.php';
require_once dirname(__DIR__).'/src/CompetitorOwnCatalog.php';
require_once dirname(__DIR__).'/src/CompetitorMatching.php';
try{
    $args=getopt('',['data:','dry-run']);$directory=$args['data']??'';
    if(!is_string($directory)||$directory===''||$directory[0]!=='/'||!is_dir($directory)||is_link($directory)||!is_file($directory.'/competitors.sqlite3'))throw new InvalidArgumentException('Укажите --data с существующим закрытым каталогом аналитики.');
    $db=\Lavka\competitors_db($directory);$catalog=\Lavka\competitors_own_catalog_read($directory);
    if(isset($args['dry-run'])){
        $targets=array_map('Lavka\\competitors_target_row',$db->query('SELECT * FROM competitor_targets ORDER BY id')->fetchAll());
        $plan=\Lavka\competitors_matching_plan($targets,$catalog);$result=$plan['summary']+['dry_run'=>true,'rule'=>$plan['rule'],'network_requests'=>0,'shop_writes'=>false];
    }else $result=\Lavka\competitors_matching_reconcile($db,$catalog);
    echo \Lavka\encode($result)."\n";
}catch(Throwable $error){fwrite(STDERR,($error instanceof InvalidArgumentException?$error->getMessage():'Не удалось проверить сохранённые соответствия конкурентов.')."\n");exit(1);}

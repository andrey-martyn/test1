<?php

declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/src/Competitors.php';
require_once dirname(__DIR__).'/src/CompetitorOwnCatalog.php';
$args=getopt('',['data:','status']);
try {
    if(PHP_VERSION_ID<80200)throw new RuntimeException('requires_php82_cli');
    $path=$args['data']??'';$directory=is_string($path)&&isset($path[0])&&$path[0]==='/'?realpath($path):false;
    if(!$directory||!is_dir($directory))throw new RuntimeException('private_directory_missing');
    $manifest=is_file($directory.'/deployment-php.json')?\Lavka\decode(file_get_contents($directory.'/deployment-php.json')):[];
    foreach(['document_root','shop_root'] as $key)if(!empty($manifest[$key])){$public=\Lavka\canonical_path($manifest[$key]);if($directory===$public||\Lavka\str_starts_with($directory,$public.'/'))throw new RuntimeException('private_directory_public');}
    umask(0077);$db=\Lavka\competitors_db($directory);
    if(!isset($args['status'])) {
        \Lavka\atomic_write($directory.'/competitor-full-catalog.json',\Lavka\encode(['enabled'=>true,'shop_writes'=>false]));
        $catalog=\Lavka\competitors_own_catalog_sync($directory,$directory.'/source.json');
        \Lavka\competitors_discovery_start($db);
    }
    $overview=\Lavka\competitors_overview($db,$directory);
    echo \Lavka\encode(['state'=>($overview['own_catalog']['state']??'error'),'monitoring'=>$overview['monitoring']])."\n";
    exit(($overview['own_catalog']['state']??'error')==='error'?1:0);
}catch(Throwable $error){fwrite(STDERR,"Не удалось подготовить мониторинг. Параметры доступа не выводятся; проверьте закрытый каталог и импорт каталога.\n");exit(1);}

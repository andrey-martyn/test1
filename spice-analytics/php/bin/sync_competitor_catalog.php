<?php

declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/src/CompetitorOwnCatalog.php';
$args=getopt('',['data:','requirements']);
try {
    if (isset($args['requirements'])) $result=\Lavka\competitors_own_catalog_requirements();
    else {
        $directory=$args['data']??'';
        if (!is_string($directory) || $directory==='' || $directory[0]!=='/') throw new InvalidArgumentException('Укажите --data с абсолютным путём существующего закрытого каталога.');
        $state=\Lavka\competitors_own_catalog_sync($directory,$directory.'/source.json');
        $result=array_intersect_key($state,array_flip(['state','error_code','checked_at','last_success','product_count','offer_count','shop_writes']));
    }
    echo \Lavka\encode($result)."\n";
    if (($result['state']??'error')==='error') exit(1);
} catch (Throwable $ignored) { fwrite(STDERR,"Импорт каталога не выполнен; предыдущие цены сохранены.\n"); exit(1); }

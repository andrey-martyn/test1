<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/src/Sync.php';
$args=getopt('',['data:','config:']);$directory=$args['data']??(getenv('ANALYTICS_DATA_DIR')?:dirname(__DIR__).'/data');$config=$args['config']??$directory.'/source.json';
try {$started=microtime(true);$r=\Lavka\sync_run($directory,$config);$r['duration_seconds']=round(microtime(true)-$started,3);echo \Lavka\encode($r)."\n";}
catch(\Throwable $e){fwrite(STDERR,($e instanceof \Lavka\SyncError?$e->getMessage():'Не удалось выполнить синхронизацию')."\n");exit(1);}

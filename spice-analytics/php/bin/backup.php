<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/src/common.php';$args=getopt('',['data:','destination:']);
try{$data=realpath($args['data']??'');$destination=$args['destination']??'';if(!$data||!$destination||!str_starts_with($destination,'/'))throw new InvalidArgumentException('Нужны --data и абсолютный --destination');
    \Lavka\private_dir($destination);$destination=realpath($destination);if($destination===$data||str_starts_with($destination,$data.'/'))throw new InvalidArgumentException('Копии должны храниться отдельно');
    $manifest=is_file($data.'/deployment-php.json')?\Lavka\decode(file_get_contents($data.'/deployment-php.json')):[];$webroot=$manifest['document_root']??null;if($webroot&&($destination===$webroot||str_starts_with($destination,$webroot.'/')))throw new InvalidArgumentException('Нельзя сохранять копии в публичной папке');
    if(!is_file($data.'/analytics.sqlite3'))throw new InvalidArgumentException('Нет базы для копирования');$copyDir=$destination.'/.pending-'.bin2hex(random_bytes(5));\Lavka\private_dir($copyDir);$original=new SQLite3($data.'/analytics.sqlite3',SQLITE3_OPEN_READONLY);$original->busyTimeout(15000);$copy=new SQLite3($copyDir.'/analytics.sqlite3');
    if(!$original->backup($copy)||$copy->querySingle('PRAGMA quick_check')!=='ok')throw new RuntimeException('Резервная копия не прошла проверку');$copy->close();$original->close();chmod($copyDir.'/analytics.sqlite3',0600);
    foreach(['access-php.json','source.json','deployment-php.json'] as $name)if(is_file($data.'/'.$name)){copy($data.'/'.$name,$copyDir.'/'.$name);chmod($copyDir.'/'.$name,0600);}
    $completed=$destination.'/'.gmdate('Ymd\THis\Z').'-'.bin2hex(random_bytes(3));if(!rename($copyDir,$completed))throw new RuntimeException('Не удалось сохранить копию');echo "Закрытая резервная копия создана и проверена. Активные сессии не включены.\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}

<?php
declare(strict_types=1);
namespace Lavka;
require_once __DIR__.'/common.php';
const SOURCE_FIELDS=[
 'oc_order'=>['order_id','customer_id','firstname','lastname','email','telephone','total','currency_code','currency_value','order_status_id','date_added','date_modified','shipping_city','shipping_zone','shipping_country','payment_city','payment_zone','payment_country','shipping_method','payment_method','ip'],
 'oc_order_product'=>['order_product_id','order_id','product_id','name','model','quantity','price','total'],
 'oc_order_option'=>['order_option_id','order_id','order_product_id','name','value'],
 'oc_order_total'=>['order_total_id','order_id','code','title','value','sort_order'],
 'oc_order_history'=>['order_history_id','order_id','order_status_id','date_added'],
 'oc_order_status'=>['order_status_id','language_id','name']];
final class SyncError extends \RuntimeException {}
function source_config(string $file): array {
    $c=decode(file_get_contents($file));if(!is_array($c)||!preg_match('/^[A-Za-z0-9_]{0,32}$/',$c['prefix']??'oc_'))throw new SyncError('Недопустимый префикс таблиц');
    foreach(['database','user','password'] as $key)if(empty($c[$key])||!is_string($c[$key]))throw new SyncError('Не заполнены данные отдельного пользователя БД');
    if(empty($c['host'])&&empty($c['unix_socket']))throw new SyncError('Не указан адрес БД или локальный сокет');
    foreach(['host','database','unix_socket'] as $key)if(isset($c[$key])&&(!is_string($c[$key])||strpbrk($c[$key],";\r\n\0")!==false))throw new SyncError('Некорректные параметры подключения');return $c;
}
function source_connect(array $c): \PDO {
    ini_set('mysqlnd.net_read_timeout','45');
    $socket=$c['unix_socket']??'';$host=$c['host']??'';$local=(bool)$socket||in_array($host,['localhost','127.0.0.1','::1'],true);$port=(int)($c['port']??3306);if($port<1||$port>65535)throw new SyncError('Неверный порт БД');
    $dsn='mysql:'.($socket?'unix_socket='.$socket:'host='.$host.';port='.$port).';dbname='.$c['database'].';charset=utf8mb4';
    $options=[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,\PDO::ATTR_DEFAULT_FETCH_MODE=>\PDO::FETCH_ASSOC,\PDO::ATTR_EMULATE_PREPARES=>false,\PDO::ATTR_STRINGIFY_FETCHES=>true,\PDO::ATTR_TIMEOUT=>15];
    $tls=!$local||!empty($c['ssl_ca']);if($tls){$ca=$c['ssl_ca']??'';if(!$ca){$locations=openssl_get_cert_locations();$ca=$locations['ini_cafile']?:$locations['default_cert_file'];}if(!is_file($ca))throw new SyncError('Нужен доступный CA-файл для проверяемого TLS');$options[\PDO::MYSQL_ATTR_SSL_CA]=$ca;$options[\PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT]=true;}
    $db=new \PDO($dsn,$c['user'],$c['password'],$options);if($tls){$row=$db->query("SHOW SESSION STATUS LIKE 'Ssl_cipher'")->fetch();if(empty($row['Value']))throw new SyncError('Сервер БД не установил TLS. Нужен сокет или SSH-туннель.');}return $db;
}
function read_snapshot(\PDO $source,string $prefix): array {
    $rows=[];$count=0;foreach(SOURCE_FIELDS as $table=>$fields){$rows[$table]=[];$physical=$prefix.substr($table,3);$pk=TABLES[$table];$order=$pk?'`'.$pk.'`':'`order_status_id`,`language_id`';$stmt=$source->query('SELECT '.implode(',',array_map(fn($f)=>'`'.$f.'`',$fields)).' FROM `'.$physical.'` ORDER BY '.$order);
        while($record=$stmt->fetch()){foreach($record as $key=>$value)$record[$key]=(string)($value??'');$key=$pk?$record[$pk]:$record['order_status_id'].':'.$record['language_id'];if($key===''||isset($rows[$table][$key]))throw new SyncError('Некорректные ключи исходных записей');$rows[$table][$key]=$record;if(++$count>2000000)throw new SyncError('Превышен лимит записей синхронизации');}$stmt->closeCursor();}return $rows;
}
function snapshot_digest(array $rows): string {$ctx=hash_init('sha256');foreach($rows as $table=>$records)foreach($records as $key=>$record)hash_update($ctx,$table."\0".$key."\0".encode($record)."\n");return hash_final($ctx);}
function validate_snapshot(array $rows): void {
    if(array_diff(array_keys(TABLES),array_keys($rows))||empty($rows['oc_order'])||empty($rows['oc_order_product'])||empty($rows['oc_order_status']))throw new SyncError('Неполный снимок: отсутствуют заказы, товары или статусы');
    foreach(['oc_order_product','oc_order_option','oc_order_total','oc_order_history'] as $table)foreach($rows[$table] as $r)if(!isset($rows['oc_order'][$r['order_id']]))throw new SyncError('Снимок изменился: найдены записи без заказа');
    foreach($rows['oc_order_option'] as $r)if(!isset($rows['oc_order_product'][$r['order_product_id']])||$rows['oc_order_product'][$r['order_product_id']]['order_id']!==$r['order_id'])throw new SyncError('Снимок изменился: опция без соответствующего товара');
    $productOrders=[];foreach($rows['oc_order_product'] as $r)$productOrders[$r['order_id']]=true;
    foreach($rows['oc_order'] as $o){if(!ctype_digit($o['order_id'])||!valid_source_date(substr($o['date_added'],0,10)))throw new SyncError('Некорректная дата или номер заказа');try{decimal($o['total']);if(bccomp(decimal($o['currency_value']),'0',12)<=0)throw new \InvalidArgumentException();}catch(\Throwable $e){throw new SyncError('Некорректная сумма или курс заказа');}if($o['order_status_id']!=='0'&&!isset($productOrders[$o['order_id']]))throw new SyncError('Оформленный заказ без товаров: загрузка отложена');}
}
function valid_source_date(string $s): bool {if(!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/',$s,$m))return false;return checkdate((int)$m[2],(int)$m[3],(int)$m[1]);}
function stable_snapshot(\PDO $source,array $c,int $delay=1): array {
    $prefix=$c['prefix']??'oc_';$physical=array_map(fn($t)=>$prefix.substr($t,3),array_keys(TABLES));$stmt=$source->prepare('SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME IN ('.implode(',',array_fill(0,count($physical),'?')).')');$stmt->execute([$c['database'],...$physical]);$engines=$stmt->fetchAll();if(count($engines)!==count($physical))throw new SyncError('Не найдены шесть таблиц или нет прав SELECT');$transactional=true;foreach($engines as $e)if(strtolower($e['ENGINE']??'')!=='innodb')$transactional=false;
    if($transactional){$source->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$source->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');try{$rows=read_snapshot($source,$prefix);validate_snapshot($rows);return [$rows,'innodb_snapshot'];}finally{$source->exec('ROLLBACK');}}
    for($i=0;$i<3;$i++){$first=read_snapshot($source,$prefix);$hash=snapshot_digest($first);unset($first);if($delay>0)sleep($delay);$second=read_snapshot($source,$prefix);if(hash_equals($hash,snapshot_digest($second))){validate_snapshot($second);return [$second,'verified_two_reads'];}unset($second);}throw new SyncError('Заказы менялись во время всех проверок. Сохранена предыдущая копия.');
}
function save_sync_status(\PDO $db,array $updates): void {$payload=$db->query('SELECT payload FROM sync_status WHERE id=1')->fetchColumn();$state=$payload?decode($payload):[];$stmt=$db->prepare('INSERT OR REPLACE INTO sync_status VALUES(1,?)');$stmt->execute([encode(array_merge($state,$updates))]);}
function apply_snapshot(\PDO $db,array $rows,string $consistency): array {
    validate_snapshot($rows);$hash=snapshot_digest($rows);$db->exec('PRAGMA journal_mode=WAL');$db->exec('BEGIN IMMEDIATE');try{$last=$db->query('SELECT digest FROM imports ORDER BY id DESC LIMIT 1')->fetchColumn();$changed=$last!==$hash;$counts=[];foreach($rows as $table=>$records)$counts[$table]=count($records);$summary=['source'=>'mysql','consistency'=>$consistency,'counts'=>$counts,'duplicate_rows'=>0];
        if($changed){$db->exec('DELETE FROM raw_records');$stmt=$db->prepare('INSERT INTO raw_records VALUES(?,?,?)');foreach($rows as $table=>$records)foreach($records as $key=>$r)$stmt->execute([$table,(string)$key,encode($r)]);$stmt=$db->prepare('INSERT INTO imports(imported_at,digest,summary) VALUES(?,?,?)');$stmt->execute([utc_now(),$hash,encode($summary)]);}
        $state=['state'=>'ok','last_success'=>utc_now(),'checked_at'=>utc_now(),'changed'=>$changed,'consistency'=>$consistency,'orders'=>count($rows['oc_order'])];$stmt=$db->prepare('INSERT OR REPLACE INTO sync_status VALUES(1,?)');$stmt->execute([encode($state)]);$db->exec('COMMIT');return $state;
    }catch(\Throwable $e){try{$db->exec('ROLLBACK');}catch(\Throwable $ignored){}throw $e;}
}
function sync_run(string $directory,string $configFile): array {
    private_dir($directory);$lock=fopen($directory.'/.sync-php.lock','c');if(!flock($lock,LOCK_EX|LOCK_NB))throw new SyncError('Синхронизация уже выполняется');$db=database($directory.'/analytics.sqlite3');init_schema($db);
    try{save_sync_status($db,['state'=>'running','checked_at'=>utc_now(),'error'=>null]);$c=source_config($configFile);$source=source_connect($c);[$rows,$consistency]=stable_snapshot($source,$c);$source=null;return apply_snapshot($db,$rows,$consistency);}
    catch(\Throwable $e){$message=$e instanceof SyncError?$e->getMessage():'Ошибка подключения или чтения БД. Проверьте права, TLS и закрытые настройки.';save_sync_status($db,['state'=>'error','checked_at'=>utc_now(),'error'=>$message]);throw new SyncError($message);}
    finally{flock($lock,LOCK_UN);fclose($lock);}
}

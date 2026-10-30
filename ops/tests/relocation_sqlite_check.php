<?php
declare(strict_types=1);
$helper=dirname(__DIR__).'/relocation_sqlite.php';
function check(bool $value,string $message): void {if(!$value)throw new RuntimeException($message);}
function call_helper(string $helper,array $input): array
{
    $process=proc_open([PHP_BINARY,$helper],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    fwrite($pipes[0],json_encode($input));fclose($pipes[0]);
    $out=stream_get_contents($pipes[1]);fclose($pipes[1]);
    $err=stream_get_contents($pipes[2]);fclose($pipes[2]);
    $status=proc_close($process);
    check(strpos($out,'PRIVATE_PASSWORD')===false&&strpos($err,'PRIVATE_PASSWORD')===false,'No private row values in diagnostics');
    return [$status,json_decode($out,true,512,JSON_THROW_ON_ERROR)];
}
function remove_tree(string $root): void
{
    foreach(scandir($root) as $name){if($name==='.'||$name==='..')continue;$p=$root.'/'.$name;if(is_dir($p)&&!is_link($p))remove_tree($p);else unlink($p);}rmdir($root);
}
$root=sys_get_temp_dir().'/relocation-php-'.bin2hex(random_bytes(6));mkdir($root,0700);
try {
    mkdir($root.'/analytics-private-test',0700);mkdir($root.'/www',0700);
    $file=$root.'/analytics-private-test/analytics.sqlite3';
    $db=new SQLite3($file);$db->exec('PRAGMA journal_mode=WAL');
    $db->exec('CREATE TABLE "manual""links"(id INTEGER PRIMARY KEY,a TEXT,b BLOB)');
    $db->exec("INSERT INTO \"manual\"\"links\" VALUES(1,'PRIVATE_PASSWORD · Юлія',X'000102ff')");
    $db->exec('CREATE TABLE settings(key TEXT PRIMARY KEY,value TEXT)');
    $db->exec("INSERT INTO settings VALUES('login_hash','PRIVATE_PASSWORD')");$db->close();
    [$status,$result]=call_helper($helper,['root'=>$root,'files'=>[$file]]);
    check($status===0&&$result['state']==='verified','Valid existing WAL SQLite is verified');
    check($result['fingerprints']['analytics-private-test/analytics.sqlite3']['manual"links']['count']===1,'Escaped table names and row counts');
    $db=new SQLite3($file);check($db->querySingle("SELECT value FROM settings WHERE key='login_hash'")==='PRIVATE_PASSWORD','Access hashes preserved');
    $db->exec('BEGIN');$db->querySingle('SELECT count(*) FROM settings');
    $writer=new SQLite3($file);$writer->exec("INSERT INTO settings VALUES('new','new-private-value')");
    [$status,$result]=call_helper($helper,['root'=>$root,'files'=>[$file]]);
    check($status!==0&&$result['state']==='stopped','Busy WAL snapshot refuses checkpoint');
    $db->exec('ROLLBACK');$db->close();$writer->close();
    foreach([$root.'/analytics-private-test/missing.sqlite3',$root.'/www/production.sqlite3'] as $bad){
        [$status,$result]=call_helper($helper,['root'=>$root,'files'=>[$bad]]);
        check($status!==0&&!file_exists($bad),'Missing/production databases never created');
    }
    symlink($file,$root.'/analytics-private-test/alias.sqlite3');
    [$status,$result]=call_helper($helper,['root'=>$root,'files'=>[$root.'/analytics-private-test/alias.sqlite3']]);
    check($status!==0,'Database aliases refused');
    echo json_encode(['sqlite_helper'=>'passed','wal_lock'=>'refused','private_values'=>'not_printed','missing_and_production_databases'=>'untouched']),"\n";
} finally {remove_tree($root);}

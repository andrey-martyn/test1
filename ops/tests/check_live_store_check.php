<?php
declare(strict_types=1);
define('LAVKA_LIVE_CHECK_TEST', true);
require dirname(__DIR__) . '/check_live_store.php';
function expect_live(bool $v, string $message): void { if (!$v) throw new RuntimeException($message); }
function live_remove_fixture(string $root): void
{
    foreach (scandir($root) as $name) { if ($name === '.' || $name === '..') continue; $p=$root.'/'.$name; if (is_dir($p)&&!is_link($p)) live_remove_fixture($p); else unlink($p); } rmdir($root);
}
function fixture_files(string $root): array
{
    $out=[];foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS)) as $f) if ($f->isFile()) $out[$f->getPathname()]=[hash_file('sha256',$f->getPathname()),$f->getPerms()&07777];ksort($out);return $out;
}
$root=sys_get_temp_dir().'/live-shop-check-'.bin2hex(random_bytes(5));mkdir($root,0700);
$secret='PRIVATE_PASSWORD_'.bin2hex(random_bytes(8));
try {
    foreach (['www/admin','www/catalog','www/system','www/image','lavka_storage/cache','lavka_storage/logs','lavka_storage/session','lavka_storage/upload','lavka_storage/download','lavka_storage/modification','ops/relocations/frontend-20261030T104233.152166Z'] as $p) mkdir($root.'/'.$p,0700,true);
    foreach (['www/index.php','www/admin/index.php','www/.htaccess'] as $p) file_put_contents($root.'/'.$p,'existing critical file');
    foreach (['storefront'=>'www/config.php','admin'=>'www/admin/config.php'] as $role=>$relative) {
        $base=$root.'/www';$app=$base.($role==='admin'?'/admin/':'/catalog/');
        $values=['DB_HOSTNAME'=>'localhost','DB_USERNAME'=>'PRIVATE_LOGIN','DB_PASSWORD'=>$secret,'DB_DATABASE'=>'lavkasp_lavka','DB_PREFIX'=>'oc_','DB_PORT'=>'3306',
            'DIR_APPLICATION'=>$app,'DIR_SYSTEM'=>$base.'/system/','DIR_IMAGE'=>$base.'/image/','DIR_STORAGE'=>$root.'/lavka_storage/',
            'HTTP_SERVER'=>'https://lavka-speciy.com/'.($role==='admin'?'admin/':''),'HTTPS_SERVER'=>'https://lavka-speciy.com/'.($role==='admin'?'admin/':'')];
        if ($role==='admin') $values['DIR_CATALOG']=$base.'/catalog/';
        $text="<?php\n// define('DB_PASSWORD','comment-is-not-a-value');\n";
        foreach ($values as $k=>$v) $text.='define('.var_export($k,true).','.var_export($v,true).");\n";
        $text.="define('DIR_CACHE', DIR_STORAGE . 'cache/');\nfile_put_contents(".var_export($root.'/CONFIG_EXECUTED',true).",'bad');\n";
        file_put_contents($root.'/'.$relative,$text);
    }
    $before=live_critical($root);
    file_put_contents($root.'/ops/relocations/frontend-20261030T104233.152166Z/journal.json',json_encode(['state'=>'completed','root'=>$root,'protected'=>$before]));
    $original=fixture_files($root);$dbCalls=0;$urls=[];
    $db=function($config,$role)use(&$dbCalls,$secret){
        $dbCalls++;expect_live($config['DB_PASSWORD']===$secret,'Existing credential read privately without execution');
        return ['state'=>'ok','database'=>'lavkasp_lavka','read_only_transaction'=>true,'active_admin_present'=>$role==='admin'?true:null,'product_id'=>123,'category_id'=>45];
    };
    $fetch=function($url,$asset)use(&$urls){
        $urls[]=$url;expect_live(live_url($url),'Only approved HTTPS shop URLs');
        $body=$asset?'asset':'<!doctype html><html><body>Shop <input type="password"><link href="/catalog/style.css" rel="stylesheet"><script src="/catalog/script.js"></script><img src="/image/logo.png"></body></html>';
        return ['state'=>'received','http_status'=>200,'body'=>$body,'content_type'=>$asset?'text/plain':'text/html','url'=>$url];
    };
    $result=live_audit($root,$db,$fetch);$json=json_encode($result);
    expect_live($result['ok']&&$result['checked_files_unchanged']&&$result['http_complete'],'Valid main shop passes');
    expect_live($result['baseline']['state']==='matched','Protected files match relocation baseline');
    expect_live($dbCalls===2&&count($urls)===8,'Both DB configs, five pages and three assets checked');
    expect_live(!file_exists($root.'/CONFIG_EXECUTED'),'Config PHP was not executed');
    expect_live(strpos($json,$secret)===false&&strpos($json,'PRIVATE_LOGIN')===false&&strpos($json,'<input')===false,'Credentials and HTML never printed');
    expect_live(fixture_files($root)===$original,'All existing files and modes unchanged');
    foreach (['file:///etc/passwd','http://lavka-speciy.com/','https://lavka-speciy.com.evil.example/','https://lavka-speciy.com@evil.example/','https://lavka-speciy.com:444/'] as $url) expect_live(!live_url($url),'Unsafe URL refused');
    expect_live(live_absolute('https://lavka-speciy.com/','//evil.example/asset')===null,'External redirects/assets refused');
    expect_live(live_absolute('https://lavka-speciy.com/','/catalog/a.css?v=1&amp;x=2')==='https://lavka-speciy.com/catalog/a.css?v=1&x=2','Local asset entities resolved');
    expect_live(live_absolute('https://lavka-speciy.com/uk/','catalog/a.css')==='https://lavka-speciy.com/uk/catalog/a.css','Relative directory URLs resolved');
    expect_live(live_absolute('https://lavka-speciy.com/admin/index.php','?route=common/login')==='https://lavka-speciy.com/admin/index.php?route=common/login','Relative query keeps path');
    $assets=live_assets('<base href="https://lavka-speciy.com/"><script src="/update_currency.php?token=private"></script><script src="catalog/script.js"></script><img src="/image/../update.php"><img src="image/logo.png">','https://lavka-speciy.com/uk/');
    expect_live($assets===['script'=>'https://lavka-speciy.com/catalog/script.js','image'=>'https://lavka-speciy.com/image/logo.png'],'Only static asset paths followed; action endpoints ignored');
    $bad=live_response(['state'=>'received','http_status'=>200,'body'=>'<html><body><b>Fatal error</b>: '.$secret.' in /home/private on line 5</body></html>']);
    expect_live(!$bad['ok']&&$bad['php_or_database_error_detected']&&strpos(json_encode($bad),$secret)===false,'HTML-formatted PHP error detected without disclosure');
    expect_live(!live_response(['state'=>'received','http_status'=>200,'body'=>'<html><title>Just a moment</title>cf-chl-</html>'])['ok'],'Challenge is not a healthy shop');
    $fake=new class {public $queries=[];public function query($sql){$this->queries[]=$sql;return new stdClass();}};
    live_select($fake,'SELECT 1');
    foreach (['UPDATE oc_product SET price=0','DELETE FROM oc_user','START TRANSACTION','SELECT 1; UPDATE oc_product SET price=0',"SELECT 1 INTO OUTFILE '/tmp/forbidden'"] as $sql) {
        try { live_select($fake,$sql);throw new LogicException('Write accepted'); } catch(RuntimeException $expected) {}
    }
    expect_live($fake->queries===['SELECT 1'],'Only SELECT passed to SQL executor');
    file_put_contents($root.'/www/index.php','changed after relocation');
    expect_live(live_baseline($root,live_critical($root))['state']==='different','Changed protected file reported');
    $file=$root.'/www/config.php';$old=file_get_contents($file);file_put_contents($file,str_replace('lavkasp_lavka','lavkasp_test',$old));
    $dbCalls=0;$blocked=live_audit($root,$db,$fetch);
    expect_live(!$blocked['ok']&&$dbCalls===0,'Test DB inside main config blocked before DB connection');
    file_put_contents($file,$old);rmdir($root.'/www/image');symlink($root.'/lavka_storage',$root.'/www/image');
    $dbCalls=0;$blocked=live_audit($root,$db,$fetch);
    expect_live(!$blocked['ok']&&$dbCalls===0,'Redirected shop path blocked before database access');
} finally {live_remove_fixture($root);}
echo json_encode(['live_shop_diagnostics'=>'passed','config_execution'=>'refused','private_data'=>'not_printed','files'=>'unchanged','write_sql'=>'refused','external_http'=>'refused']),"\n";

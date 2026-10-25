<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/src/common.php';
function refresh_expect(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
function refresh_run(string $script): array {
    $process=proc_open(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script),[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    fclose($pipes[0]); $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); return [proc_close($process),$out,$err];
}
function refresh_remove(string $dir): void {
    foreach(scandir($dir)?:[] as $name) if($name!=='.'&&$name!=='..') {
        $file=$dir.'/'.$name; if(is_dir($file)&&!is_link($file))refresh_remove($file);else unlink($file);
    } rmdir($dir);
}
$source=dirname(__DIR__).'/bin/refresh_sandbox.php';
if(PHP_VERSION_ID<80200){$result=refresh_run($source);refresh_expect($result[0]!==0&&strpos($result[2],'PHP 8.2')!==false,'Old CLI rejected');echo "{\"refresh_old_cli_rejected\":true}\n";exit;}
$home=sys_get_temp_dir().'/lavka-refresh-'.bin2hex(random_bytes(5));
$app=$home.'/analytics-app/spice-analytics/php';$data=$home.'/analytics-private-test';
mkdir($app.'/bin',0700,true);mkdir($app.'/src',0700,true);mkdir($data,0700);
try {
    copy($source,$app.'/bin/refresh_sandbox.php');foreach(glob(dirname(__DIR__).'/src/*.php') as $file)copy($file,$app.'/src/'.basename($file));
    file_put_contents($app.'/bin/deploy.php','<?php echo json_encode($argv)."\\n";');
    $meta=['application_root'=>$app,'data_dir'=>$data,'document_root'=>$home.'/test/analytics','shop_root'=>$home.'/test','public_url'=>'https://test.lavka-speciy.com/analytics'];
    $marker=['environment'=>'sandbox','database'=>'lavkasp_test','data_dir'=>$data];
    $write=function()use($data,&$meta,&$marker){file_put_contents($data.'/deployment-php.json',json_encode($meta));file_put_contents($data.'/sandbox.json',json_encode($marker));};
    $result=refresh_run($app.'/bin/refresh_sandbox.php');refresh_expect($result[0]!==0&&$result[1]==='','Missing installation cannot deploy');
    $write();$result=refresh_run($app.'/bin/refresh_sandbox.php');$lines=explode("\n",trim($result[1]));$args=json_decode($lines[0],true);
    refresh_expect($result[0]===0&&is_array($args)&&in_array('--refresh-assets',$args,true)&&!in_array('--switch-sandbox',$args,true),'Only refresh existing assets');
    refresh_expect(in_array($data,$args,true)&&in_array($home.'/test/analytics',$args,true),'Exact test paths passed');
    $matching=json_decode($lines[1]??'',true);refresh_expect(isset($matching['automatic_matching'])&&$matching['automatic_matching']['network_requests']===0&&!$matching['automatic_matching']['shop_writes'],'Refresh reconciles cached matches without HTTP or shop writes');
    $marker['database']='lavkasp_lavka';$write();$result=refresh_run($app.'/bin/refresh_sandbox.php');refresh_expect($result[0]!==0&&$result[1]==='','Production marker rejected before deploy');
    $marker['database']='lavkasp_test';$meta['document_root']=$home.'/www/analytics';$write();$result=refresh_run($app.'/bin/refresh_sandbox.php');refresh_expect($result[0]!==0&&$result[1]==='','Wrong public path rejected');
    $meta['document_root']=$home.'/test/analytics';$write();rename($data.'/deployment-php.json',$data.'/metadata.json');symlink($data.'/metadata.json',$data.'/deployment-php.json');
    $result=refresh_run($app.'/bin/refresh_sandbox.php');refresh_expect($result[0]!==0&&$result[1]==='','Symlink metadata rejected');
    echo "{\"existing_sandbox_refresh\":true,\"wrong_environment_and_paths_rejected\":true,\"shop_writes\":false}\n";
} finally { refresh_remove($home); }

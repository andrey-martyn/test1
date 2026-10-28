<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/SandboxEnvironment.php';
function layout_expect(bool $value,string $message): void {if(!$value)throw new RuntimeException($message);}
function layout_reject(callable $call,string $message): void
{
    try{$call();}catch(InvalidArgumentException $expected){return;}catch(\Lavka\SandboxEnvironmentError $expected){return;}
    throw new RuntimeException($message);
}
function layout_remove(string $root): void
{
    foreach(scandir($root)?:[] as $name){if($name==='.'||$name==='..')continue;$file=$root.'/'.$name;if(is_dir($file)&&!is_link($file))layout_remove($file);else unlink($file);}rmdir($root);
}
$root=sys_get_temp_dir().'/lavka-paths-'.bin2hex(random_bytes(5));mkdir($root,0700);
try{
    $before=scandir($root);$old=\Lavka\hosting_layout_paths($root);$new=\Lavka\hosting_layout_paths($root,'organized');
    layout_expect(scandir($root)===$before,'Resolving a proposed layout creates no files');
    layout_expect($new['data_dir']===$root.'/private/analytics/test'&&$new['repository']===$root.'/apps/lavka-modules','Exact new paths');
    foreach([$old,$new] as $paths){
        foreach(['application_root','previous_data_dir','data_dir','shop_root'] as $key)if(!is_dir($paths[$key]))mkdir($paths[$key],0700,true);
        $result=\Lavka\sandbox_validate_layout($paths['previous_data_dir'],$paths['data_dir'],$paths['shop_root']);
        layout_expect($result['data']===$paths['data_dir'],'Existing and organized layouts remain isolated');
        layout_expect(\Lavka\hosting_layout_for_application($paths['application_root'])['layout']===$paths['layout'],'Application location selects the matching layout');
        file_put_contents($paths['data_dir'].'/analytics.sqlite3','existing_manual_links');
        file_put_contents($paths['data_dir'].'/identity-reviews.json','existing_negative_reviews');
    }
    $hashes=[];foreach([$old,$new] as $paths)foreach(['analytics.sqlite3','identity-reviews.json'] as $name)$hashes[$paths['data_dir'].'/'.$name]=hash_file('sha256',$paths['data_dir'].'/'.$name);
    layout_reject(function()use($old,$new){\Lavka\sandbox_validate_layout($old['previous_data_dir'],$new['data_dir'],$old['shop_root']);},'Mixed layouts rejected');
    layout_reject(function()use($old,$new){\Lavka\sandbox_validate_layout($new['previous_data_dir'],$old['data_dir'],$old['shop_root']);},'Reverse mixed layout rejected');
    mkdir($root.'/www',0700);
    layout_reject(function()use($old,$root){\Lavka\sandbox_validate_layout($old['previous_data_dir'],$root.'/www',$old['shop_root']);},'Public data location rejected');
    layout_reject(function()use($new){\Lavka\sandbox_validate_layout($new['data_dir'],$new['data_dir'],$new['shop_root']);},'Shared source and sandbox data rejected');
    symlink($root.'/private',$root.'/private-alias');
    layout_reject(function()use($new,$root){\Lavka\sandbox_validate_layout($new['previous_data_dir'],$root.'/private-alias/analytics/test',$new['shop_root']);},'Ancestor symlink alias rejected');
    symlink($new['data_dir'],$root.'/data-link');
    layout_reject(function()use($new,$root){\Lavka\sandbox_validate_layout($new['previous_data_dir'],$root.'/data-link',$new['shop_root']);},'Direct data symlink rejected');
    layout_reject(function()use($root){\Lavka\hosting_layout_paths($root,'anything');},'Unknown layout rejected');
    mkdir($root.'/apps/other/spice-analytics/php',0700,true);
    layout_reject(function()use($root){\Lavka\hosting_layout_for_application($root.'/apps/other/spice-analytics/php');},'Unrecognized application layout rejected');
    mkdir($root.'/storage/test',0700,true);mkdir($root.'/storage/production',0700,true);
    $config=$root.'/test/config.php';
    $write=function($storage)use($config,$root){$constants=['DB_DATABASE'=>'lavkasp_test','DB_PREFIX'=>'oc_','DB_HOSTNAME'=>'localhost','HTTP_SERVER'=>'https://test.lavka-speciy.com/','HTTPS_SERVER'=>'https://test.lavka-speciy.com/','DIR_STORAGE'=>$storage,'DIR_APPLICATION'=>$root.'/test/catalog/','DIR_SYSTEM'=>$root.'/test/system/','DIR_IMAGE'=>$root.'/test/image/'];$text="<?php\n";foreach($constants as $key=>$value)$text.='define('.var_export($key,true).','.var_export($value,true).");\n";file_put_contents($config,$text);};
    $write($root.'/storage/test/');layout_expect(\Lavka\sandbox_store_config($config,$root.'/test')['storage']===$root.'/storage/test','Organized isolated test storage allowed');
    $write($root.'/storage/production/');layout_reject(function()use($config,$root){\Lavka\sandbox_store_config($config,$root.'/test');},'Production storage remains forbidden');
    foreach($hashes as $file=>$hash)layout_expect(hash_file('sha256',$file)===$hash,'Existing manual data never rewritten');
    echo json_encode(['php'=>PHP_VERSION,'legacy_and_organized_layouts'=>'passed','mixed_public_and_symlink_paths'=>'rejected','storage_isolation'=>'passed','manual_data'=>'unchanged']),"\n";
}finally{layout_remove($root);}

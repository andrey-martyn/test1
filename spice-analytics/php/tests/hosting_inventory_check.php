<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/bin/hosting_inventory.php';
function inventory_expect(bool $value,string $message): void {if(!$value)throw new RuntimeException($message);}
function inventory_tree(string $root): array
{
    $result=[];
    foreach(scandir($root)?:[] as $name){
        if($name==='.'||$name==='..')continue;$file=$root.'/'.$name;
        if(is_link($file))$result[$file]=['link'=>readlink($file)];
        elseif(is_dir($file))$result+=inventory_tree($file);
        else $result[$file]=[hash_file('sha256',$file),filemtime($file),fileperms($file)&0777];
    }
    return $result;
}
function inventory_remove(string $root): void
{
    foreach(scandir($root)?:[] as $name){if($name==='.'||$name==='..')continue;$file=$root.'/'.$name;if(is_dir($file)&&!is_link($file))inventory_remove($file);else unlink($file);}rmdir($root);
}
$fixture=sys_get_temp_dir().'/lavka-layout-'.bin2hex(random_bytes(5));$root=$fixture.'/site';mkdir($root,0700,true);
try{
    foreach(['www','test','test/secret-admin','test/analytics','analytics-private-test','analytics-app','review-factory-private-test','review-harvester-private'] as $dir)mkdir($root.'/'.$dir,0700,true);
    mkdir($fixture.'/lavka-frontend-work',0700);
    $secret='DO_NOT_PRINT_'.bin2hex(random_bytes(5));
    $poison=$root.'/executed-config';
    $config="<?php\ndefine('DB_PASSWORD', ".var_export($secret,true).");\n".
        "// define('DIR_UPLOAD','/fake/comment/path');\n".
        "define('DIR_STORAGE', ".var_export($root.'/test_storage/',true).");\n".
        "define('DIR_CACHE', dirname(__FILE__).'/cache/');\n".
        "file_put_contents(".var_export($poison,true).",'CONFIG_EXECUTED');\n";
    foreach(['www/config.php','test/config.php','test/secret-admin/config.php'] as $name)file_put_contents($root.'/'.$name,$config);
    file_put_contents($root.'/test/analytics/index.php',"<?php \$application=".var_export($root.'/analytics-app/spice-analytics/php',true)."; \$data=".var_export($root.'/analytics-private-test',true).";");
    file_put_contents($root.'/analytics-private-test/deployment-php.json',json_encode(['data_dir'=>$root.'/analytics-private-test','application_root'=>$root.'/analytics-app/spice-analytics/php','password'=>$secret]));
    file_put_contents($root.'/analytics-private-test/sandbox.json',json_encode(['previous_data_dir'=>$root.'/analytics-private','admin_config'=>$root.'/test/secret-admin/config.php','source_password'=>$secret]));
    file_put_contents($root.'/review-factory-private-test/instance.json',json_encode(['shop_root'=>$root.'/test','analytics_data'=>$root.'/analytics-private-test','webroot'=>$root.'/test/review-workshop','public_roots'=>[$root.'/www',$root.'/test'],'token'=>$secret]));
    foreach(['analytics.sqlite3','competitors.sqlite3','identity-reviews.json','source.json','access-php.json','.env'] as $name)file_put_contents($root.'/analytics-private-test/'.$name,$secret);
    $cron="TOKEN=".$secret."\n*/5 * * * * /usr/local/php82/bin/php ".$root.'/analytics-app/spice-analytics/php/bin/cron.php --token '.$secret.' --data '.$root."/analytics-private-test >> ".$root."/analytics-private-test/cron.log 2>&1\n";
    $before=inventory_tree($fixture);$report=hosting_inventory($root,$cron);$json=json_encode($report);
    inventory_expect(strpos($json,$secret)===false,'Passwords and environment assignments never appear');
    inventory_expect(!file_exists($poison),'Shop config files are never executed');
    inventory_expect($report['changes_made']===false&&$report['databases_opened']===false,'Explicitly read-only report');
    inventory_expect(count($report['opencart_config_paths'])===3,'Renamed admin config is discovered');
    foreach($report['opencart_config_paths'] as $entry){
        inventory_expect($entry['paths']->DIR_STORAGE===$root.'/test_storage/','Literal storage dependency preserved');
        inventory_expect($entry['paths']->DIR_CACHE==='[dynamic_path]','Dynamic paths are never guessed or executed');
        inventory_expect(!isset($entry['paths']->DB_PASSWORD)&&!isset($entry['paths']->DIR_UPLOAD),'Passwords and commented definitions excluded');
    }
    inventory_expect($report['frontend_work']['state']==='present','Sibling frontend repo is inventoried separately');
    inventory_expect(count($report['cron']['jobs'])===1&&strpos(json_encode($report['cron']['jobs']),'--token')===false,'Cron reveals paths only');
    inventory_expect(count($report['public_launchers'][0]['referenced_paths'])===2,'Public launcher dependencies detected');
    inventory_expect($report['review_manifests'][0]['paths']->analytics_data===$root.'/analytics-private-test','Review module dependency on analytics is preserved');
    inventory_expect($report['review_manifests'][0]['paths']->public_roots===[$root.'/www',$root.'/test'],'Only directory paths are read from review module settings');
    $folders=array_column($report['root_entries'],null,'name');
    inventory_expect($folders['review-harvester-private']['immediate_entries']===0,'Empty folders are reported without declaring them redundant');
    inventory_expect(in_array('competitors.sqlite3',$folders['analytics-private-test']['sample_names'],true),'Only shallow filenames, never database contents, identify data directories');
    inventory_expect(inventory_tree($fixture)===$before,'All original files, modes and modification dates unchanged');
    mkdir($root.'/apps',0700);
    rename($fixture.'/lavka-frontend-work',$root.'/apps/lavka-frontend');
    $moved=inventory_tree($fixture);$report=hosting_inventory($root,'');
    inventory_expect($report['frontend_work']['layout']==='organized'&&$report['frontend_work']['path']===$root.'/apps/lavka-frontend','Relocated frontend clone is detected');
    inventory_expect(inventory_tree($fixture)===$moved,'Detecting relocated frontend changes no files');
    mkdir($fixture.'/lavka-frontend-work',0700);
    inventory_expect(hosting_inventory($root,'')['frontend_work']['state']==='conflict','Two frontend clones are never silently selected');
    rmdir($fixture.'/lavka-frontend-work');symlink($root.'/apps/lavka-frontend',$fixture.'/lavka-frontend-work');
    inventory_expect(hosting_inventory($root,'')['frontend_work']['state']==='unsafe_path','Frontend aliases are visible as unsafe, never followed');
    symlink($root.'/analytics-private-test',$root.'/analytics-private');
    $report=hosting_inventory($root,'');inventory_expect($report['analytics_manifests'][0]['state']==='symlink_directory_not_read','Symlinked data directories are not followed');
    try{hosting_inventory($root.'/missing','');throw new RuntimeException('Missing root accepted');}catch(InvalidArgumentException $expected){}
    echo json_encode(['php'=>PHP_VERSION,'path_dependencies'=>'passed','renamed_admin'=>'passed','secret_redaction'=>'passed','no_config_execution'=>'passed','existing_files'=>'unchanged']),"\n";
}finally{inventory_remove($fixture);}

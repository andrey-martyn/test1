<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}

/** Only literal paths and directory metadata. No app bootstrap, DB or config execution. */
function hosting_inventory_path($value): ?string
{
    return is_string($value)&&preg_match('~^/[\pL\pN_./ -]+$~uD',$value)?$value:null;
}
function hosting_inventory_text(string $file,int $limit): ?string
{
    if(is_link($file)||!is_file($file)||!is_readable($file)||filesize($file)>$limit)return null;
    $text=file_get_contents($file);return $text===false?null:$text;
}
function hosting_inventory_paths(string $text,string $root): array
{
    preg_match_all('~'.preg_quote($root,'~').'(?=/|[^A-Za-z0-9_.-]|$)(?:/[A-Za-z0-9_.-]+)*~',$text,$matches);
    return array_values(array_unique($matches[0]));
}
function hosting_inventory_listing(string $root): array
{
    $items=[];
    foreach(scandir($root)?:[] as $name){
        if($name==='.'||$name==='..')continue;$file=$root.'/'.$name;
        $item=['name'=>$name,'type'=>is_link($file)?'symlink':(is_dir($file)?'directory':(is_file($file)?'file':'other'))];
        if($item['type']==='directory'){
            $handle=@opendir($file);$samples=[];$count=0;
            if($handle){
                while(($child=readdir($handle))!==false){
                    if($child==='.'||$child==='..')continue;
                    if(count($samples)<15)$samples[]=$child;
                    if(++$count>=2000)break;
                }
                closedir($handle);sort($samples);
                $item['immediate_entries']=$count;$item['count_capped']=$count>=2000;$item['sample_names']=$samples;
            }else $item['listing']='unavailable';
        }
        $items[]=$item;
    }
    return $items;
}
function hosting_inventory_manifest(string $file): array
{
    $text=hosting_inventory_text($file,65536);if($text===null)return ['file'=>$file,'state'=>'not_read'];
    $data=json_decode($text,true);if(!is_array($data))return ['file'=>$file,'state'=>'invalid_json'];
    $paths=[];
    foreach(['application_root','data_dir','document_root','shop_root','previous_data_dir','admin_config','webroot','analytics_data'] as $key){
        if(array_key_exists($key,$data))$paths[$key]=hosting_inventory_path($data[$key])??'[unresolved]';
    }
    if(isset($data['public_roots'])&&is_array($data['public_roots']))$paths['public_roots']=array_map(function($value){return hosting_inventory_path($value)??'[unresolved]';},array_values($data['public_roots']));
    return ['file'=>$file,'state'=>'read_paths_only','paths'=>(object)$paths];
}
function hosting_inventory_next(array $tokens,int &$index)
{
    while(isset($tokens[$index])&&is_array($tokens[$index])&&in_array($tokens[$index][0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true))$index++;
    return $tokens[$index]??null;
}
function hosting_inventory_literal($token): ?string
{
    if(!is_array($token)||$token[0]!==T_CONSTANT_ENCAPSED_STRING)return null;
    $value=$token[1];$body=substr($value,1,-1);
    return $value[0]==="'"?str_replace(["\\'","\\\\"],["'","\\"],$body):stripcslashes($body);
}
function hosting_inventory_store_paths(string $file): array
{
    $text=hosting_inventory_text($file,262144);if($text===null)return ['file'=>$file,'state'=>'not_read'];
    $allowed=['DIR_APPLICATION','DIR_SYSTEM','DIR_IMAGE','DIR_STORAGE','DIR_CACHE','DIR_LOGS','DIR_SESSION','DIR_MODIFICATION','DIR_DOWNLOAD','DIR_UPLOAD','DIR_CATALOG'];
    $tokens=token_get_all($text);$paths=[];
    foreach($tokens as $index=>$token){
        if(!is_array($token)||$token[0]!==T_STRING||strtolower($token[1])!=='define')continue;
        $i=$index+1;if(hosting_inventory_next($tokens,$i)!=='(')continue;$i++;
        $name=hosting_inventory_literal(hosting_inventory_next($tokens,$i));if(!in_array($name,$allowed,true))continue;$i++;
        if(hosting_inventory_next($tokens,$i)!==',')continue;$i++;
        $value=hosting_inventory_literal(hosting_inventory_next($tokens,$i));$i++;
        $paths[$name]=hosting_inventory_next($tokens,$i)===')'?(hosting_inventory_path($value)??'[unresolved]'):'[dynamic_path]';
    }
    return ['file'=>$file,'state'=>'read_DIR_constants_only','paths'=>(object)$paths];
}
function hosting_inventory_cron(string $text,string $root): array
{
    $jobs=[];
    foreach(preg_split('/\r?\n/',$text) as $line){
        $line=trim($line);if($line===''||$line[0]==='#')continue;
        $paths=hosting_inventory_paths($line,$root);if(!$paths)continue;
        $jobs[]=['number'=>count($jobs)+1,'referenced_paths'=>$paths];
    }
    return $jobs;
}
function hosting_inventory(string $root,?string $cronText=null): array
{
    $real=realpath($root);
    if(!$real||$real!==rtrim($root,'/')||is_link($root)||!is_dir($root))throw new InvalidArgumentException('Нужен существующий абсолютный --root без симлинка.');
    $manifests=[];$adminPaths=[];
    foreach(['analytics-private','analytics-private-test','private/analytics/previous','private/analytics/test'] as $name){
        if(is_link($real.'/'.$name)){$manifests[]=['file'=>$real.'/'.$name,'state'=>'symlink_directory_not_read'];continue;}
        foreach(['deployment-php.json','sandbox.json'] as $json){
            $file=$real.'/'.$name.'/'.$json;
            if(!file_exists($file)&&!is_link($file))continue;
            $entry=hosting_inventory_manifest($file);$manifests[]=$entry;
            $admin=$entry['paths']->admin_config??null;
            if(is_string($admin)&&strpos($admin,$real.'/test/')===0&&basename($admin)==='config.php')$adminPaths[]=$admin;
        }
    }
    $reviewManifests=[];
    foreach(['review-factory-private-test','review-harvester-private','private/review-factory/test'] as $name){
        $directory=$real.'/'.$name;$file=$directory.'/instance.json';
        if(is_link($directory)){$reviewManifests[]=['file'=>$directory,'state'=>'symlink_directory_not_read'];continue;}
        if(file_exists($file)||is_link($file))$reviewManifests[]=hosting_inventory_manifest($file);
    }
    $stores=[];
    foreach(['www','test'] as $shop){
        if(is_link($real.'/'.$shop))continue;
        foreach(array_merge([$real.'/'.$shop.'/config.php'],glob($real.'/'.$shop.'/*/config.php')?:[]) as $file)$adminPaths[]=$file;
    }
    foreach(array_unique($adminPaths) as $file){
        // Never follow a renamed admin directory symlink out of the store.
        if(is_link(dirname($file)))continue;
        $path=realpath($file);if(!$path||strpos($path,$real.'/')!==0)continue;
        $stores[]=hosting_inventory_store_paths($file);
    }
    $launchers=[];
    foreach(['analytics/index.php','test/analytics/index.php','www/analytics/index.php','test/review-workshop/index.php','www/review-workshop/index.php'] as $name){
        $file=$real.'/'.$name;if(is_link(dirname($file)))continue;
        $text=hosting_inventory_text($file,131072);
        if($text!==null)$launchers[]=['file'=>$file,'referenced_paths'=>hosting_inventory_paths($text,$real)];
    }
    $cronState='provided';
    if($cronText===null){
        $cronState='unavailable';$cronText='';
        if(function_exists('proc_open')){
            $process=@proc_open(['crontab','-l'],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['file','/dev/null','w']],$pipes);
            if(is_resource($process)){
                $text=stream_get_contents($pipes[1]);fclose($pipes[1]);$exit=proc_close($process);
                if($exit===0){$cronText=$text;$cronState='read_paths_only';}
            }
        }
    }
    $frontend=dirname($real).'/lavka-frontend-work';
    return ['mode'=>'inventory_only','root'=>$real,'root_entries'=>hosting_inventory_listing($real),
        'frontend_work'=>['path'=>$frontend,'state'=>is_link($frontend)?'symlink':(is_dir($frontend)?'present':'not_found')],
        'analytics_manifests'=>$manifests,'review_manifests'=>$reviewManifests,'opencart_config_paths'=>$stores,'public_launchers'=>$launchers,
        'cron'=>['state'=>$cronState,'jobs'=>hosting_inventory_cron($cronText,$real),'note'=>'Только пути, без команд, расписаний, переменных и реквизитов. Задания панели могут потребовать отдельной проверки.'],
        'changes_made'=>false,'databases_opened'=>false,
        'note'=>'Отчёт не объявляет папки лишними. Содержимое БД, source.json, access-php.json и .env не читается.'];
}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){
    if(PHP_VERSION_ID<80200){fwrite(STDERR,"Запустите команду через PHP 8.2 CLI.\n");exit(1);}
    try{
        $args=getopt('',['root:']);$root=$args['root']??'/home/lavkasp/lavka-speciy.com';
        if(!is_string($root))throw new InvalidArgumentException('Неверный --root.');
        echo json_encode(hosting_inventory($root),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),"\n";
    }catch(Throwable $error){fwrite(STDERR,"Не удалось прочитать карту каталогов. Проверьте --root и права чтения. Ничего не изменено.\n");exit(1);}
}

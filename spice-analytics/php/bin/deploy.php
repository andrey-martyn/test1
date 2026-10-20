<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/src/common.php';
function absolute_path(string $path): string {
    if(!str_starts_with($path,'/'))$path=getcwd().'/'.$path;$parts=[];foreach(explode('/',$path) as $part){if($part===''||$part==='.')continue;if($part==='..')array_pop($parts);else $parts[]=$part;}return '/'.implode('/',$parts);
}
function inside(string $path,string $parent): bool{return $path===$parent||str_starts_with($path,rtrim($parent,'/').'/');}
function copy_tree(string $from,string $to): void {if(!is_dir($to)&&!mkdir($to,0755,true))throw new RuntimeException('Не удалось создать static');foreach(new DirectoryIterator($from) as $entry){if($entry->isDot())continue;if($entry->isLink())throw new RuntimeException('Символические ссылки в static запрещены');$target=$to.'/'.$entry->getFilename();if($entry->isDir())copy_tree($entry->getPathname(),$target);else {if(!copy($entry->getPathname(),$target))throw new RuntimeException('Не удалось скопировать static');chmod($target,0644);}}}
$args=getopt('',['webroot:','data:','url:']);
try{
    foreach(['webroot','data','url'] as $key)if(empty($args[$key]))throw new InvalidArgumentException('Укажите --webroot, --data и --url');
    $root=realpath(dirname(__DIR__));$webroot=absolute_path($args['webroot']);$data=absolute_path($args['data']);$url=rtrim($args['url'],'/');$parsed=parse_url($url);
    if(($parsed['scheme']??'')!=='https'||empty($parsed['host'])||!empty($parsed['path'])||isset($parsed['user'])||isset($parsed['pass'])||isset($parsed['query'])||isset($parsed['fragment']))throw new InvalidArgumentException('Нужен HTTPS-адрес поддомена без пути');
    if(is_link($webroot)||is_link($data))throw new InvalidArgumentException('Используйте реальные каталоги, не символические ссылки');
    if(is_dir($webroot))$webroot=realpath($webroot);if(is_dir($data))$data=realpath($data);
    if(inside($root,$webroot)||inside($webroot,$root)||inside($data,$webroot)||inside($webroot,$data))throw new InvalidArgumentException('Код, данные и публичная папка должны быть отдельными каталогами');
    if(is_dir($webroot)&&array_diff(scandir($webroot),['.','..']))throw new InvalidArgumentException('Публичная папка не пуста. Файлы магазина не изменены.');
    \Lavka\private_dir($data);if(!is_dir($webroot)&&!mkdir($webroot,0755,true))throw new RuntimeException('Не удалось создать публичную папку');
    $launcher="<?php\ndeclare(strict_types=1);\nputenv('ANALYTICS_DATA_DIR='.".var_export($data,true).");\nputenv('ANALYTICS_PUBLIC_URL='.".var_export($url,true).");\nrequire ".var_export($root.'/public/index.php',true).";\n";
    file_put_contents($webroot.'/index.php',$launcher);chmod($webroot.'/index.php',0644);
    file_put_contents($webroot.'/.htaccess',"Options -Indexes\nDirectoryIndex index.php\nRewriteEngine On\nRewriteCond %{HTTPS} !=on\nRewriteRule ^ https://".$parsed['host']."%{REQUEST_URI} [R=302,L,NE]\nRewriteCond %{REQUEST_FILENAME} !-f\nRewriteRule ^ index.php [L,QSA]\n");chmod($webroot.'/.htaccess',0644);
    copy_tree($root.'/static',$webroot.'/static');\Lavka\atomic_write($data.'/deployment-php.json',\Lavka\encode(['application_root'=>$root,'document_root'=>$webroot,'data_dir'=>$data,'public_url'=>$url]));
    echo \Lavka\encode(['prepared'=>true,'runtime'=>'PHP','document_root'=>$webroot,'public_url'=>$url])."\n";
    echo "Файлы подготовлены. Далее задайте пароль, настройте SELECT-пользователя MySQL и проверьте настоящий HTTPS-адрес.\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}

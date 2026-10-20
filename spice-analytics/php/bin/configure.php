<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/src/Auth.php';require_once dirname(__DIR__).'/src/Sync.php';
function ask(string $label,string $default=''): string {fwrite(STDOUT,$label.($default!==''?' ['.$default.']':'').': ');$s=trim((string)fgets(STDIN));return $s!==''?$s:$default;}
function hidden(string $label): string {
    if(!function_exists('shell_exec')||!stream_isatty(STDIN))throw new RuntimeException('Скрытый ввод доступен в интерактивном SSH-терминале');$state=trim((string)shell_exec('stty -g'));if($state==='')throw new RuntimeException('Не удалось включить скрытый ввод');
    fwrite(STDOUT,$label.': ');shell_exec('stty -echo');try{return rtrim((string)fgets(STDIN),"\r\n");}finally{shell_exec('stty '.escapeshellarg($state));fwrite(STDOUT,"\n");}
}
$args=getopt('',['data:','password','source']);$directory=$args['data']??(getenv('ANALYTICS_DATA_DIR')?:dirname(__DIR__).'/data');
try{\Lavka\private_dir($directory);
    if(isset($args['password'])){$first=hidden('Новый пароль кабинета (минимум 12 символов)');if($first!==hidden('Повторите пароль'))throw new InvalidArgumentException('Пароли не совпадают');\Lavka\Auth::setPassword($directory,$first);echo "Пароль сохранён, старые сессии отозваны. Значение не записано в журнал.\n";}
    elseif(isset($args['source'])){if(is_file($directory.'/source.json'))throw new RuntimeException('source.json уже существует; измените его через защищённый доступ');echo "Отдельный пользователь MySQL: только SELECT на шесть таблиц заказов.\n";$c=['host'=>ask('Адрес MySQL'),'port'=>(int)ask('Порт','3306'),'unix_socket'=>ask('Локальный сокет (если используется)'),'database'=>ask('Имя базы магазина'),'prefix'=>ask('Префикс','oc_'),'user'=>ask('Отдельный пользователь SELECT'),'password'=>hidden('Пароль пользователя MySQL'),'ssl_ca'=>ask('CA-файл для удалённой БД (пусто = системные CA)')];\Lavka\atomic_write($directory.'/source.json',\Lavka\encode($c));\Lavka\source_config($directory.'/source.json');echo "Параметры записаны в закрытый source.json, в браузер они не передаются.\n";}
    else throw new InvalidArgumentException('Укажите --password или --source');
}catch(Throwable $e){fwrite(STDERR,$e instanceof PDOException?'Ошибка записи настроек\n':$e->getMessage()."\n");exit(1);}

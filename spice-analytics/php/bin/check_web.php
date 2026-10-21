<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/src/common.php';
$args = getopt('', ['webroot:', 'url:', 'ca:']);
$file = null;
$exitCode = 1;
try {
    $webroot = realpath($args['webroot'] ?? '');
    $address = \Lavka\public_address($args['url'] ?? '');
    if (!$webroot || !is_dir($webroot) || !is_writable($webroot)) throw new RuntimeException('Укажите существующий публичный каталог тестового сайта через --webroot');
    $token = bin2hex(random_bytes(24));
    $name = 'lavka-check-' . bin2hex(random_bytes(16)) . '.php';
    $file = $webroot . '/' . $name;
    $source = <<<'PHP'
<?php
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
if (time() > __DEADLINE__ || !isset($_GET['token']) || !is_string($_GET['token']) || !hash_equals(__TOKEN__, $_GET['token'])) { http_response_code(404); exit; }
$required = ['pdo_sqlite','sqlite3','bcmath','mbstring','intl','openssl'];
$missing = array_values(array_filter($required, function($name) { return !extension_loaded($name); }));
$sqlite = extension_loaded('sqlite3') ? SQLite3::version() : null;
$argon = false;
if (defined('PASSWORD_ARGON2ID')) {
    $hash = password_hash('availability-check', PASSWORD_ARGON2ID, ['memory_cost'=>8192,'time_cost'=>1,'threads'=>1]);
    $argon = $hash && password_get_info($hash)['algoName'] === 'argon2id';
}
$backup = $sqlite && (method_exists('SQLite3','backup') || $sqlite['versionNumber'] >= 3027000);
$code = @is_readable(__APPLICATION__);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['php'=>PHP_VERSION,'compatible'=>PHP_VERSION_ID>=70300 && !$missing && $argon && $backup && $code,
    'missing_extensions'=>$missing,'argon2id'=>(bool)$argon,'consistent_backup'=>(bool)$backup,
    'application_readable'=>$code,'origin_https'=>(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') || ($_SERVER['SERVER_PORT'] ?? '')==='443',
    'memory_limit'=>ini_get('memory_limit')]);
PHP;
    $source = str_replace(['__DEADLINE__','__TOKEN__','__APPLICATION__'], [(string)(time()+120),var_export($token,true),var_export(realpath(dirname(__DIR__).'/src/common.php'),true)], $source);
    $handle = fopen($file, 'x');
    if (!$handle) throw new RuntimeException('Не удалось создать временную проверку');
    try { if (fwrite($handle,$source) !== strlen($source)) throw new RuntimeException('Не удалось записать проверку'); } finally { fclose($handle); }
    chmod($file, 0644);
    if (!extension_loaded('curl')) throw new RuntimeException('Для проверки через SSH нужен curl');
    $request = curl_init($address['url'].'/'.$name.'?token='.rawurlencode($token));
    curl_setopt_array($request,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>25,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_FOLLOWLOCATION=>false]);
    if (!empty($args['ca'])) {
        $ca=realpath($args['ca']);if(!$ca)throw new RuntimeException('CA-файл не найден');
        curl_setopt($request,CURLOPT_CAINFO,$ca);
    }
    $body=curl_exec($request);$status=curl_getinfo($request,CURLINFO_HTTP_CODE);curl_close($request);
    if ($body===false || $status!==200) throw new RuntimeException('HTTPS-проверка не прошла; HTTP-код '.(int)$status.'. Настройки PHP и файлы магазина не изменены.');
    try { $report=\Lavka\decode($body); } catch (Throwable $e) { throw new RuntimeException('Сайт вернул не JSON проверки; проверьте обработчик PHP и правила сайта.'); }
    if (!is_array($report) || !isset($report['php']) || !array_key_exists('compatible',$report)) throw new RuntimeException('Неожиданный ответ проверки');
    $report['temporary_file_removed']=unlink($file);if($report['temporary_file_removed'])$file=null;
    echo \Lavka\encode($report)."\n";
    $exitCode = $report['compatible'] && $report['origin_https'] && $report['temporary_file_removed'] ? 0 : 1;
} catch (Throwable $e) {
    fwrite(STDERR,$e->getMessage()."\n");
} finally {
    if ($file && is_file($file)) unlink($file);
}
exit($exitCode);

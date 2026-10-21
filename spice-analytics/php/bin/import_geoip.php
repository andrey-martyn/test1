<?php

declare (strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/src/common.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';
$args = getopt('', ['data:', 'file:']);
try {
    $data = $args['data'] ?? (getenv('ANALYTICS_DATA_DIR') ?: dirname(__DIR__) . '/data');
    $file = $args['file'] ?? '';
    if (!is_file($file)) {
        throw new InvalidArgumentException('Укажите --file с настоящей City MMDB');
    }
    \Lavka\private_dir($data);
    $temporary = tempnam($data, '.geoip-');
    chmod($temporary, 0600);
    if (\Lavka\str_ends_with($file, '.gz')) {
        $in = gzopen($file, 'rb');
        $out = fopen($temporary, 'wb');
        $size = 0;
        while (!gzeof($in)) {
            $chunk = gzread($in, 1024 * 1024);
            if ($chunk === false) {
                throw new RuntimeException('Ошибка gzip');
            }
            $size += strlen($chunk);
            if ($size > 512 * 1024 * 1024) {
                throw new RuntimeException('Слишком большая MMDB');
            }
            fwrite($out, $chunk);
        }
        gzclose($in);
        fclose($out);
    } else {
        copy($file, $temporary);
    }
    $reader = new MaxMind\Db\Reader($temporary);
    if (stripos($reader->metadata()->databaseType, 'city') === false) {
        throw new InvalidArgumentException('Нужна GeoIP City MMDB');
    }
    $reader->close();
    if (!rename($temporary, $data . '/geoip-city.mmdb')) {
        throw new RuntimeException('Ошибка установки');
    }
    \Lavka\atomic_write($data . '/geoip-php.json', \Lavka\encode(['installed_at' => \Lavka\utc_now(), 'sha256' => hash_file('sha256', $data . '/geoip-city.mmdb')]));
    foreach (glob($data . '/cache/report-*.json') as $cache) {
        unlink($cache);
    }
    echo "Локальная City MMDB установлена. IP покупателей никуда не отправлялись.\n";
} catch (Throwable $e) {
    if (isset($temporary) && is_file($temporary)) {
        unlink($temporary);
    }
    fwrite(STDERR, 'Не удалось установить GeoIP: проверьте файл City MMDB и закрытый каталог.\n');
    exit(1);
}

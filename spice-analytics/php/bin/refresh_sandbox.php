<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/src/common.php';

// Short update command for the existing installation. This never switches an
// environment, initializes a store or runs a price job.
try {
    if (PHP_VERSION_ID < 80200) throw new RuntimeException('Запустите команду через PHP 8.2 CLI.');
    $app = realpath(dirname(__DIR__));
    $home = $app === false ? false : realpath(dirname($app, 3));
    if ($home === false) throw new RuntimeException('Не найден каталог существующей установки.');
    $data = $home . '/analytics-private-test';
    $metadataPath = $data . '/deployment-php.json';
    $sandboxPath = $data . '/sandbox.json';
    foreach ([$data, $metadataPath, $sandboxPath] as $path) {
        if (is_link($path) || !file_exists($path)) throw new RuntimeException('Не найдена ранее подключённая песочница. Подготовка новой установки не выполнялась.');
    }
    $metadata = \Lavka\decode(file_get_contents($metadataPath));
    $sandbox = \Lavka\decode(file_get_contents($sandboxPath));
    $expected = ['application_root' => $app, 'data_dir' => $data,
        'document_root' => $home . '/test/analytics', 'shop_root' => $home . '/test',
        'public_url' => 'https://test.lavka-speciy.com/analytics'];
    foreach ($expected as $key => $value) {
        if (($metadata[$key] ?? null) !== $value) throw new RuntimeException('Пути не совпадают с существующей песочницей. Обновление остановлено.');
    }
    if (($sandbox['environment'] ?? null) !== 'sandbox' || ($sandbox['database'] ?? null) !== 'lavkasp_test'
        || ($sandbox['data_dir'] ?? null) !== $data) throw new RuntimeException('Тестовая среда не подтверждена. Обновление остановлено.');
    $command = [PHP_BINARY, $app . '/bin/deploy.php', '--refresh-assets',
        '--shop-root', $expected['shop_root'], '--webroot', $expected['document_root'],
        '--data', $data, '--url', $expected['public_url']];
    $process = proc_open($command, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Не удалось запустить обновление.');
    $result=proc_close($process);
    if($result===0){
        require_once $app.'/src/Competitors.php';
        require_once $app.'/src/CompetitorOwnCatalog.php';
        require_once $app.'/src/CompetitorMatching.php';
        $matching=\Lavka\competitors_matching_reconcile(\Lavka\competitors_db($data),\Lavka\competitors_own_catalog_read($data));
        echo \Lavka\encode(['automatic_matching'=>$matching])."\n";
    }
    exit($result);
} catch (Throwable $error) {
    // Fixed messages only: no connection configuration or secrets are read.
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}

<?php

declare (strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/src/Sync.php';
require_once dirname(__DIR__) . '/src/App.php';
$args = getopt('', ['data:', 'config:']);
$directory = $args['data'] ?? (getenv('ANALYTICS_DATA_DIR') ?: dirname(__DIR__) . '/data');
try {
    $start = microtime(true);
    $state = \Lavka\sync_run($directory, $args['config'] ?? $directory . '/source.json');
    $db = \Lavka\database($directory . '/analytics.sqlite3');
    $meta = \Lavka\fast_metadata($db, $directory);
    $count = 0;
    foreach (array_slice($meta['years'], 0, 2) as $year) {
        foreach ($meta['currencies'] as $currency) {
            \Lavka\cached_report($db, $directory, $year, date('Y-m-d'), $currency);
            $count++;
        }
    }
    echo \Lavka\encode(array_merge($state, ['prepared_reports' => $count, 'seconds' => round(microtime(true) - $start, 3), 'peak_mb' => round(memory_get_peak_usage(true) / 1048576, 1)])) . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, ($e instanceof \Lavka\SyncError ? $e->getMessage() : 'Ошибка подготовки отчётов; предыдущая успешная копия сохранена') . "\n");
    exit(1);
}

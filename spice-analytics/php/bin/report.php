<?php

declare (strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/src/App.php';
$args = getopt('', ['data:', 'year:', 'as-of:', 'currency:', 'output:']);
$directory = $args['data'] ?? (getenv('ANALYTICS_DATA_DIR') ?: dirname(__DIR__) . '/data');
$year = (int) ($args['year'] ?? date('o'));
$asof = $args['as-of'] ?? date('Y-m-d');
$currency = $args['currency'] ?? 'UAH';
try {
    if ($year < 2000 || $year > 2100 || !\Lavka\valid_date($asof) || !preg_match('/^[A-Z]{3}$/', $currency)) {
        throw new \InvalidArgumentException('Неверные параметры отчёта');
    }
    $started = microtime(true);
    $db = \Lavka\database($directory . '/analytics.sqlite3');
    \Lavka\init_schema($db);
    $report = \Lavka\cached_report($db, $directory, $year, $asof, $currency);
    if (isset($args['output'])) {
        \Lavka\atomic_write($args['output'], \Lavka\encode($report));
    }
    echo \Lavka\encode(['seconds' => round(microtime(true) - $started, 3), 'peak_mb' => round(memory_get_peak_usage(true) / 1048576, 1), 'metrics' => $report['metrics']]) . "\n";
} catch (\Throwable $e) {
    fwrite(STDERR, 'Не удалось построить отчёт: ' . get_class($e) . "\n");
    exit(1);
}

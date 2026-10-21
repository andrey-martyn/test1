<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/src/SourceDiagnostics.php';
$args = getopt('', ['data:', 'config:']);
$directory = $args['data'] ?? (getenv('ANALYTICS_DATA_DIR') ?: dirname(__DIR__) . '/data');
$result = \Lavka\diagnose_source($args['config'] ?? $directory . '/source.json');
echo \Lavka\encode($result), "\n";
exit($result['state'] === 'ok' ? 0 : 1);

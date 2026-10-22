<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/src/CompetitorSandboxJobs.php';

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) return;
try {
    $args = getopt('', ['data:', 'limit:', 'seconds:']);
    $data = $args['data'] ?? '';
    $limit = filter_var($args['limit'] ?? 3, FILTER_VALIDATE_INT);
    $seconds = filter_var($args['seconds'] ?? 45, FILTER_VALIDATE_INT);
    if (!is_string($data) || $data === '' || $data[0] !== '/' || $limit === false || $seconds === false) throw new \Lavka\SandboxPricingError('sandbox_job_worker_limits');
    echo \Lavka\encode(\Lavka\sandbox_price_jobs_run($data, $limit, $seconds)) . "\n";
} catch (Throwable $error) {
    $code = $error instanceof \Lavka\SandboxPricingError ? $error->errorCode : 'sandbox_job_worker_failed';
    fwrite(STDERR, \Lavka\encode(['state' => 'blocked', 'error_code' => $code, 'database' => 'lavkasp_test']) . "\n");
    exit(1);
}

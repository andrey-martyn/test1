<?php

declare (strict_types=1);
ini_set('display_errors', '0');
require_once dirname(__DIR__) . '/src/App.php';
$directory = getenv('ANALYTICS_DATA_DIR') ?: dirname(__DIR__) . '/data';
$url = rtrim(getenv('ANALYTICS_PUBLIC_URL') ?: '', '/');
\Lavka\run_app($directory, $url, dirname(__DIR__) . '/static', getenv('ANALYTICS_BASE_PATH') ?: '');

<?php

declare (strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$webOnly=array_key_exists('web-only',getopt('', ['web-only']));
$required = array_merge($webOnly?[]:['pdo_mysql'], ['pdo_sqlite', 'sqlite3', 'bcmath', 'mbstring', 'intl', 'openssl']);
$missing = array_values(array_filter($required, function ($e) {
    return !extension_loaded($e);
}));
$argon = defined('PASSWORD_ARGON2ID');
$backup = extension_loaded('sqlite3') && (method_exists('SQLite3', 'backup') || SQLite3::version()['versionNumber'] >= 3027000);
echo json_encode(['php' => PHP_VERSION, 'php_supported' => PHP_VERSION_ID >= 70300, 'sync_cli_supported'=>PHP_VERSION_ID>=80200, 'validation_mode'=>$webOnly?'web_dependencies':'web_and_sync_cli', 'missing_extensions' => $missing, 'argon2id' => $argon, 'consistent_backup' => $backup, 'memory_limit' => ini_get('memory_limit')], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
exit($missing || PHP_VERSION_ID < 70300 || !$argon || !$backup || (!$webOnly && PHP_VERSION_ID<80200) ? 1 : 0);

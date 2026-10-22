<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/check_sandbox.php';

function lavka_sandbox_password(): string
{
    if (!function_exists('shell_exec') || !function_exists('stream_isatty') || !stream_isatty(STDIN)) throw new RuntimeException('interactive_hidden_input_required');
    $state = trim((string) shell_exec('stty -g'));
    if ($state === '') throw new RuntimeException('hidden_input_unavailable');
    fwrite(STDOUT, 'Пароль нового пользователя lavkasp_sandbox (ввод скрыт): ');
    shell_exec('stty -echo');
    try { $password = rtrim((string) fgets(STDIN), "\r\n"); }
    finally { shell_exec('stty ' . escapeshellarg($state)); fwrite(STDOUT, "\n"); }
    if ($password === '' || strlen($password) > 1024 || strpos($password, "\x00") !== false) throw new RuntimeException('sandbox_password_missing');
    return $password;
}

function lavka_sandbox_sqlite_copy(string $from, string $to): void
{
    if (!is_file($from) || is_link($from) || file_exists($to) || is_link($to)) throw new RuntimeException('sandbox_monitor_copy_invalid');
    $source = new SQLite3($from, SQLITE3_OPEN_READONLY); $target = new SQLite3($to);
    try {
        $source->busyTimeout(15000); $target->busyTimeout(15000);
        if (!method_exists($source, 'backup') || !$source->backup($target) || $target->querySingle('PRAGMA quick_check') !== 'ok') throw new RuntimeException('sandbox_monitor_copy_failed');
        chmod($to, 0600);
    } finally { $target->close(); $source->close(); }
}

function lavka_sandbox_remove_pending(string $directory): void
{
    if (!is_dir($directory) || is_link($directory) || strpos(basename($directory), '.pending-sandbox-') !== 0) return;
    foreach (scandir($directory) ?: [] as $name) if ($name !== '.' && $name !== '..' && is_file($directory . '/' . $name) && !is_link($directory . '/' . $name)) unlink($directory . '/' . $name);
    @rmdir($directory);
}

/** Prepare separate local files only; never repoint the public launcher here. */
function lavka_sandbox_setup(array $args, ?callable $passwordReader = null, ?callable $connector = null): array
{
    if (PHP_VERSION_ID < 80200) throw new RuntimeException('requires_php82_cli');
    $old = $args['from'] ?? '/home/lavkasp/lavka-speciy.com/analytics-private';
    $data = $args['data'] ?? '/home/lavkasp/lavka-speciy.com/analytics-private-test';
    $shop = $args['shop-root'] ?? '/home/lavkasp/lavka-speciy.com/test';
    foreach ([$old, $data, $shop] as $path) if (!is_string($path)) throw new RuntimeException('sandbox_path_invalid');
    $layout = \Lavka\sandbox_validate_layout($old, $data, $shop);
    $old = $layout['old']; $data = $layout['data']; $shop = $layout['shop'];
    if (file_exists($data) && (!is_dir($data) || array_diff(scandir($data), ['.', '..']))) throw new RuntimeException('sandbox_destination_not_empty');
    $admin = $args['admin-config'] ?? $shop . '/admin/config.php';
    if (!is_string($admin) || $admin === '' || $admin[0] !== '/') throw new RuntimeException('sandbox_admin_config_invalid');
    $stores = lavka_sandbox_store_checks($shop, $admin);
    $admin = realpath($admin);
    if (!$admin) throw new RuntimeException('test_shop_config_missing');
    $metadataPath = $old . '/deployment-php.json'; $authPath = $old . '/access-php.json';
    foreach ([$metadataPath, $authPath] as $file) if (!is_file($file) || is_link($file) || filesize($file) > 65536) throw new RuntimeException('existing_analytics_metadata_missing');
    $metadata = \Lavka\decode(file_get_contents($metadataPath));
    if (($metadata['shop_root'] ?? '') !== $shop || ($metadata['document_root'] ?? '') !== $shop . '/analytics'
        || ($metadata['data_dir'] ?? '') !== $old || ($metadata['public_url'] ?? '') !== 'https://test.lavka-speciy.com/analytics'
        || ($metadata['application_root'] ?? '') !== realpath(dirname(__DIR__))) throw new RuntimeException('existing_analytics_paths_mismatch');
    $auth = file_get_contents($authPath); if (!is_array(\Lavka\decode($auth))) throw new RuntimeException('existing_auth_invalid');
    $password = $passwordReader ? $passwordReader() : lavka_sandbox_password();
    if (!is_string($password) || $password === '') throw new RuntimeException('sandbox_password_missing');
    $config = ['host' => '127.0.0.1', 'port' => 3306, 'unix_socket' => '', 'database' => 'lavkasp_test', 'prefix' => 'oc_', 'user' => 'lavkasp_sandbox', 'password' => $password, 'ssl_ca' => ''];
    $sourceCheck = lavka_sandbox_source_check($config, $connector);
    unset($password);
    umask(0077);
    $pending = dirname($data) . '/.pending-sandbox-' . bin2hex(random_bytes(8));
    \Lavka\private_dir($pending);
    try {
        \Lavka\atomic_write($pending . '/access-php.json', $auth);
        if (file_exists($old . '/competitors.sqlite3')) lavka_sandbox_sqlite_copy($old . '/competitors.sqlite3', $pending . '/competitors.sqlite3');
        \Lavka\atomic_write($pending . '/source.json', \Lavka\encode($config));
        unset($config);
        $marker = ['environment' => 'sandbox', 'database' => 'lavkasp_test', 'allowed_shop_database' => 'lavkasp_test',
            'source_user' => 'lavkasp_sandbox', 'shop_root' => $shop, 'public_url' => 'https://test.lavka-speciy.com/analytics',
            'data_dir' => $data, 'previous_data_dir' => $old, 'writes_enabled' => true, 'test_store_verified' => true,
            'admin_config' => $admin, 'created_at' => \Lavka\utc_now()];
        \Lavka\atomic_write($pending . '/sandbox.json', \Lavka\encode($marker));
        $metadata['data_dir'] = $data; $metadata['environment'] = 'sandbox';
        \Lavka\atomic_write($pending . '/deployment-php.json', \Lavka\encode($metadata));
        if (is_dir($data) && !rmdir($data)) throw new RuntimeException('sandbox_destination_changed');
        if (file_exists($data) || !rename($pending, $data)) throw new RuntimeException('sandbox_prepare_failed');
        return array_merge(['state' => 'prepared', 'environment' => 'sandbox', 'data_dir' => $data,
            'shop_configs' => $stores, 'public_launcher_changed' => false, 'production_data_changed' => false,
            'cabinet_password' => 'preserved', 'sessions' => 'fresh', 'sales_snapshot' => 'requires_test_sync'], $sourceCheck);
    } finally { lavka_sandbox_remove_pending($pending); }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) return;
try {
    echo \Lavka\encode(lavka_sandbox_setup(getopt('', ['from:', 'data:', 'shop-root:', 'admin-config:']))) . "\n";
} catch (Throwable $error) {
    $code = preg_match('/^[a-z][a-z0-9_]+$/D', $error->getMessage()) ? $error->getMessage() : 'sandbox_setup_failed';
    fwrite(STDERR, \Lavka\encode(['state' => 'blocked', 'error_code' => $code, 'production_data_changed' => false, 'public_launcher_changed' => false]) . "\n");
    exit(1);
}

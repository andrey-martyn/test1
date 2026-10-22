<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/src/CompetitorOwnCatalog.php';
require_once dirname(__DIR__) . '/src/SandboxEnvironment.php';

/** Shared literal-only checks are also used before every web price change. */
function lavka_sandbox_store_config(string $file, string $shopRoot): array
{
    return \Lavka\sandbox_store_config($file, $shopRoot);
}
function lavka_sandbox_store_checks(string $shopRoot, ?string $adminConfig = null): array
{
    return \Lavka\sandbox_store_checks($shopRoot, $adminConfig);
}
function lavka_sandbox_grants(array $rows): array
{
    return \Lavka\sandbox_source_grants($rows);
}

function lavka_sandbox_source_check(array $config, ?callable $connector = null): array
{
    if (($config['database'] ?? '') !== 'lavkasp_test' || ($config['user'] ?? '') !== 'lavkasp_sandbox'
        || !in_array($config['host'] ?? '', ['127.0.0.1', 'localhost'], true) || (int) ($config['port'] ?? 3306) !== 3306
        || ($config['prefix'] ?? '') !== 'oc_' || !empty($config['unix_socket'])) throw new RuntimeException('sandbox_source_not_isolated');
    set_error_handler(function () { return true; });
    try {
        $source = $connector ? $connector($config) : \Lavka\source_connect($config);
        if (!$source instanceof PDO || $source->query('SELECT DATABASE()')->fetchColumn() !== 'lavkasp_test') throw new RuntimeException('sandbox_database_mismatch');
        $identity = (string) $source->query('SELECT CURRENT_USER()')->fetchColumn();
        if (explode('@', $identity, 2)[0] !== 'lavkasp_sandbox') throw new RuntimeException('sandbox_user_mismatch');
        $privileges = lavka_sandbox_grants($source->query('SHOW GRANTS FOR CURRENT_USER()')->fetchAll(PDO::FETCH_COLUMN));
        $isolation = \Lavka\sandbox_source_tables($source);
        foreach (\Lavka\SOURCE_FIELDS as $table => $fields) {
            $source->query('SELECT ' . implode(',', array_map(function ($field) { return '`' . $field . '`'; }, $fields)) . ' FROM `' . $table . '` LIMIT 0')->closeCursor();
        }
        foreach (\Lavka\COMPETITOR_CATALOG_FIELDS as $table => $fields) {
            $physical = 'oc_' . ($table === 'setting' ? 'analytics_catalog_settings' : $table);
            $source->query('SELECT ' . implode(',', array_map(function ($field) { return '`' . $field . '`'; }, $fields)) . ' FROM `' . $physical . '` LIMIT 0')->closeCursor();
        }
        $settings = $source->query("SELECT `key`,`value`,serialized FROM oc_analytics_catalog_settings WHERE store_id=0 AND `key` IN ('config_currency','config_language','config_customer_group_id','config_tax')")->fetchAll(PDO::FETCH_ASSOC);
        $keys = [];
        foreach ($settings as $setting) {
            if ((int) $setting['serialized'] !== 0) throw new RuntimeException('sandbox_settings_unsupported');
            if (array_key_exists($setting['key'], $keys)) throw new RuntimeException('sandbox_settings_unsupported');
            $keys[$setting['key']] = $setting['value'];
        }
        if (count($keys) !== 4 || ($keys['config_currency'] ?? '') !== 'UAH' || trim((string) ($keys['config_language'] ?? '')) === ''
            || !preg_match('/^[1-9][0-9]*$/D', (string) ($keys['config_customer_group_id'] ?? ''))
            || !in_array($keys['config_tax'] ?? '', ['0', '1'], true)) throw new RuntimeException('sandbox_catalog_settings_missing');
        return ['database' => 'lavkasp_test', 'source_user' => 'lavkasp_sandbox', 'privileges' => $privileges, 'isolation' => $isolation,
            'orders_schema' => 'ok', 'catalog_schema' => 'ok', 'shop_writes' => false];
    } catch (Throwable $error) {
        if ($error instanceof \Lavka\SandboxEnvironmentError) throw $error;
        if ($error instanceof RuntimeException && in_array($error->getMessage(), ['sandbox_database_mismatch', 'sandbox_user_mismatch', 'sandbox_settings_unsupported', 'sandbox_catalog_settings_missing'], true)) throw $error;
        throw new RuntimeException('sandbox_connection_or_schema_failed');
    } finally { restore_error_handler(); }
}

function lavka_sandbox_check(array $args, ?callable $connector = null): array
{
    if (PHP_VERSION_ID < 80200) throw new RuntimeException('requires_php82_cli');
    $data = $args['data'] ?? '';
    if (!is_string($data) || $data === '' || $data[0] !== '/' || is_link($data) || !($directory = realpath($data))) throw new RuntimeException('sandbox_directory_invalid');
    foreach (['sandbox.json', 'source.json'] as $name) if (!is_file($directory . '/' . $name) || is_link($directory . '/' . $name) || filesize($directory . '/' . $name) > 65536) throw new RuntimeException('sandbox_configuration_missing');
    $marker = \Lavka\decode(file_get_contents($directory . '/sandbox.json'));
    if (!is_array($marker) || ($marker['environment'] ?? '') !== 'sandbox' || ($marker['database'] ?? '') !== 'lavkasp_test'
        || ($marker['allowed_shop_database'] ?? '') !== 'lavkasp_test' || ($marker['source_user'] ?? '') !== 'lavkasp_sandbox'
        || ($marker['data_dir'] ?? '') !== $directory || ($marker['public_url'] ?? '') !== 'https://test.lavka-speciy.com/analytics'
        || ($marker['writes_enabled'] ?? false) !== true || ($marker['test_store_verified'] ?? false) !== true
        || !is_string($marker['admin_config'] ?? null) || ($marker['admin_config'][0] ?? '') !== '/') throw new RuntimeException('sandbox_marker_invalid');
    $shopRoot = $marker['shop_root'] ?? '';
    if (!is_string($shopRoot) || \Lavka\str_starts_with($directory, rtrim($shopRoot, '/') . '/')) throw new RuntimeException('sandbox_directory_public');
    $old = $marker['previous_data_dir'] ?? '';
    if (!is_string($old)) throw new RuntimeException('sandbox_marker_invalid');
    \Lavka\sandbox_validate_layout($old, $directory, $shopRoot);
    if (isset($args['admin-config']) && $args['admin-config'] !== $marker['admin_config']) throw new RuntimeException('sandbox_admin_config_mismatch');
    $stores = lavka_sandbox_store_checks($shopRoot, $marker['admin_config']);
    $source = lavka_sandbox_source_check(\Lavka\source_config($directory . '/source.json'), $connector);
    return array_merge(['state' => 'ok', 'environment' => 'sandbox', 'data_dir' => $directory, 'shop_configs' => $stores], $source);
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) return;
try {
    echo \Lavka\encode(lavka_sandbox_check(getopt('', ['data:', 'admin-config:']))) . "\n";
} catch (Throwable $error) {
    $code = preg_match('/^[a-z][a-z0-9_]+$/D', $error->getMessage()) ? $error->getMessage() : 'sandbox_check_failed';
    fwrite(STDERR, \Lavka\encode(['state' => 'blocked', 'error_code' => $code, 'shop_writes' => false]) . "\n");
    exit(1);
}

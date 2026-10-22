<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/setup_sandbox.php';

/** Decode one PHP string token as data; never evaluate config PHP. */
function lavka_sandbox_prepare_literal(array $token): string
{
    if ($token[0] !== T_CONSTANT_ENCAPSED_STRING) throw new RuntimeException('sandbox_test_admin_credentials_not_literal');
    $literal = $token[1]; $quote = $literal[0] ?? '';
    if (!in_array($quote, ["'", '"'], true) || substr($literal, -1) !== $quote) throw new RuntimeException('sandbox_test_admin_credentials_not_literal');
    $raw = substr($literal, 1, -1); $value = ''; $length = strlen($raw);
    $escapes = ['n' => "\n", 'r' => "\r", 't' => "\t", 'v' => "\v", 'e' => "\x1b", 'f' => "\f", '\\' => '\\', '$' => '$', '"' => '"'];
    for ($i = 0; $i < $length; $i++) {
        if ($raw[$i] !== '\\' || $i + 1 >= $length) { $value .= $raw[$i]; continue; }
        $next = $raw[$i + 1];
        if ($quote === "'") {
            if ($next === "'" || $next === '\\') { $value .= $next; $i++; }
            else $value .= '\\';
            continue;
        }
        if (isset($escapes[$next])) { $value .= $escapes[$next]; $i++; continue; }
        if ($next >= '0' && $next <= '7') {
            $digits = $next; $i++;
            for ($j = 0; $j < 2 && $i + 1 < $length && $raw[$i + 1] >= '0' && $raw[$i + 1] <= '7'; $j++) $digits .= $raw[++$i];
            $value .= chr(octdec($digits) & 255); continue;
        }
        if ($next === 'x' && preg_match('/^[0-9a-fA-F]{1,2}/', substr($raw, $i + 2), $hex)) {
            $value .= chr(hexdec($hex[0])); $i += 1 + strlen($hex[0]); continue;
        }
        if ($next === 'u' && preg_match('/^\{([0-9a-fA-F]{1,6})\}/', substr($raw, $i + 2), $unicode)) {
            $point = hexdec($unicode[1]);
            if ($point > 0x10ffff || ($point >= 0xd800 && $point <= 0xdfff)) throw new RuntimeException('sandbox_test_admin_credentials_not_literal');
            if ($point <= 0x7f) $value .= chr($point);
            elseif ($point <= 0x7ff) $value .= chr(0xc0 | ($point >> 6)) . chr(0x80 | ($point & 0x3f));
            elseif ($point <= 0xffff) $value .= chr(0xe0 | ($point >> 12)) . chr(0x80 | (($point >> 6) & 0x3f)) . chr(0x80 | ($point & 0x3f));
            else $value .= chr(0xf0 | ($point >> 18)) . chr(0x80 | (($point >> 12) & 0x3f)) . chr(0x80 | (($point >> 6) & 0x3f)) . chr(0x80 | ($point & 0x3f));
            $i += 1 + strlen($unicode[0]); continue;
        }
        // PHP preserves the slash for unknown double-quoted escapes.
        $value .= '\\';
    }
    return $value;
}

/** Credentials exist only in memory and are never returned by the CLI command. */
function lavka_sandbox_prepare_credentials(string $file): array
{
    if (!is_file($file) || is_link($file) || filesize($file) > 262144) throw new RuntimeException('sandbox_test_admin_credentials_missing');
    $contents = @file_get_contents($file);
    if ($contents === false) throw new RuntimeException('sandbox_test_admin_credentials_missing');
    \Lavka\sandbox_config_constants($contents);
    $tokens = [];
    foreach (token_get_all($contents) as $token) {
        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) continue;
        $tokens[] = $token;
    }
    unset($contents); $values = [];
    $text = function ($token): string { return is_array($token) ? $token[1] : $token; };
    for ($i = 0, $count = count($tokens); $i < $count; $i++) {
        if (!in_array(strtolower($text($tokens[$i])), ['define', '\\define'], true) || $text($tokens[$i + 1] ?? '') !== '(') continue;
        $nameToken = $tokens[$i + 2] ?? null;
        if (!is_array($nameToken) || $nameToken[0] !== T_CONSTANT_ENCAPSED_STRING) continue;
        $name = lavka_sandbox_prepare_literal($nameToken);
        if (!in_array($name, ['DB_USERNAME', 'DB_PASSWORD'], true)) continue;
        $valueToken = $tokens[$i + 4] ?? null;
        if ($text($tokens[$i + 3] ?? '') !== ',' || !is_array($valueToken)
            || $text($tokens[$i + 5] ?? '') !== ')' || $text($tokens[$i + 6] ?? '') !== ';') throw new RuntimeException('sandbox_test_admin_credentials_not_literal');
        $values[$name] = lavka_sandbox_prepare_literal($valueToken);
    }
    unset($tokens);
    if (($values['DB_USERNAME'] ?? '') !== 'lavkasp_test') throw new RuntimeException('sandbox_test_admin_required');
    if (!is_string($values['DB_PASSWORD'] ?? null) || $values['DB_PASSWORD'] === '' || strlen($values['DB_PASSWORD']) > 1024
        || strpos($values['DB_PASSWORD'], "\x00") !== false) throw new RuntimeException('sandbox_test_admin_credentials_missing');
    return ['user' => $values['DB_USERNAME'], 'password' => $values['DB_PASSWORD']];
}

/** Only the existing owner of lavkasp_test may create the two fixed views. */
function lavka_sandbox_prepare_admin_grants(array $rows): array
{
    $capabilities = ['SELECT' => false, 'CREATE VIEW' => false, 'SHOW VIEW' => false, 'TRIGGER' => false];
    if (!$rows) throw new RuntimeException('sandbox_test_admin_privileges');
    foreach ($rows as $row) {
        if (!is_string($row) || !preg_match('/^GRANT\s+(.+?)\s+ON\s+(.+?)\s+TO\s+/is', $row, $match)) throw new RuntimeException('sandbox_test_admin_privileges');
        $privileges = trim($match[1]); $object = str_replace([chr(96), '\\_'], ['', '_'], trim($match[2]));
        if ($object === '*.*') {
            if (strcasecmp($privileges, 'USAGE') !== 0 || preg_match('/\bWITH\s+GRANT\s+OPTION\b/i', $row)) throw new RuntimeException('sandbox_test_admin_privileges');
            continue;
        }
        if (!preg_match('/^lavkasp_test\.([A-Za-z0-9_*]+)$/D', $object, $target)) throw new RuntimeException('sandbox_test_admin_privileges');
        if ($target[1] !== '*') continue;
        if (strcasecmp($privileges, 'ALL PRIVILEGES') === 0 || strcasecmp($privileges, 'ALL') === 0) {
            foreach ($capabilities as &$value) $value = true; unset($value);
            continue;
        }
        foreach (preg_split('/,(?![^()]*\))/', $privileges) as $privilege) {
            $privilege = strtoupper(trim($privilege));
            if (array_key_exists($privilege, $capabilities)) $capabilities[$privilege] = true;
        }
    }
    if (in_array(false, $capabilities, true)) throw new RuntimeException('sandbox_test_admin_privileges');
    return ['test_database_only' => true, 'view_preparation' => true];
}

function lavka_sandbox_prepare_admin_config(string $shop, ?string $explicit = null): string
{
    if ($explicit !== null) {
        lavka_sandbox_store_checks($shop, $explicit);
        return (string) realpath($explicit);
    }
    $standard = $shop . '/admin/config.php';
    if (is_file($standard) || is_link($standard)) {
        lavka_sandbox_store_checks($shop, $standard);
        return (string) realpath($standard);
    }
    $candidates = [];
    foreach (glob($shop . '/*/config.php') ?: [] as $candidate) {
        if (is_link(dirname($candidate)) || is_link($candidate)) continue;
        try { lavka_sandbox_store_checks($shop, $candidate); $candidates[] = (string) realpath($candidate); }
        catch (Throwable $ignored) { continue; }
    }
    if (count($candidates) !== 1) throw new RuntimeException('test_admin_config_required');
    return $candidates[0];
}

/** Prepare just views and separate analytics files; never change shop price rows. */
function lavka_sandbox_prepare(array $args, ?callable $passwordReader = null, ?callable $adminConnector = null, ?callable $appConnector = null): array
{
    if (PHP_VERSION_ID < 80200) throw new RuntimeException('requires_php82_cli');
    $old = $args['from'] ?? '/home/lavkasp/lavka-speciy.com/analytics-private';
    $data = $args['data'] ?? '/home/lavkasp/lavka-speciy.com/analytics-private-test';
    $shop = $args['shop-root'] ?? '/home/lavkasp/lavka-speciy.com/test';
    foreach ([$old, $data, $shop] as $path) if (!is_string($path)) throw new RuntimeException('sandbox_path_invalid');
    $layout = \Lavka\sandbox_validate_layout($old, $data, $shop);
    $old = $layout['old']; $data = $layout['data']; $shop = $layout['shop'];
    if (file_exists($data) && (!is_dir($data) || array_diff(scandir($data), ['.', '..']))) throw new RuntimeException('sandbox_destination_not_empty');
    // Validate the storefront before reading credentials or opening a connection.
    \Lavka\sandbox_store_config($shop . '/config.php', $shop);
    $adminArg = $args['admin-config'] ?? null;
    if ($adminArg !== null && !is_string($adminArg)) throw new RuntimeException('sandbox_admin_config_invalid');
    $admin = lavka_sandbox_prepare_admin_config($shop, $adminArg);
    foreach (['deployment-php.json', 'access-php.json'] as $name) {
        $file = $old . '/' . $name;
        if (!is_file($file) || is_link($file) || filesize($file) > 65536) throw new RuntimeException('existing_analytics_metadata_missing');
    }
    $metadata = \Lavka\decode(file_get_contents($old . '/deployment-php.json'));
    foreach (['application_root' => realpath(dirname(__DIR__)), 'document_root' => $shop . '/analytics',
        'shop_root' => $shop, 'data_dir' => $old, 'public_url' => 'https://test.lavka-speciy.com/analytics'] as $key => $expected) {
        if (($metadata[$key] ?? null) !== $expected) throw new RuntimeException('existing_analytics_paths_mismatch');
    }
    if (!is_array(\Lavka\decode(file_get_contents($old . '/access-php.json')))) throw new RuntimeException('existing_auth_invalid');
    $credentials = lavka_sandbox_prepare_credentials($shop . '/config.php');
    $config = ['host' => '127.0.0.1', 'port' => 3306, 'database' => 'lavkasp_test', 'prefix' => 'oc_',
        'user' => 'lavkasp_test', 'password' => $credentials['password'], 'unix_socket' => '', 'ssl_ca' => ''];
    unset($credentials); $source = null;
    set_error_handler(function () { return true; });
    try {
        try { $source = $adminConnector ? $adminConnector($config) : \Lavka\source_connect($config); }
        catch (Throwable $ignored) { throw new RuntimeException('sandbox_prepare_connection_failed'); }
        if (!$source instanceof PDO) throw new RuntimeException('sandbox_prepare_connection_failed');
        try {
            if ($source->query('SELECT DATABASE()')->fetchColumn() !== 'lavkasp_test') throw new RuntimeException('sandbox_test_admin_database_mismatch');
            if (explode('@', (string) $source->query('SELECT CURRENT_USER()')->fetchColumn(), 2)[0] !== 'lavkasp_test') throw new RuntimeException('sandbox_test_admin_required');
            lavka_sandbox_prepare_admin_grants($source->query('SHOW GRANTS FOR CURRENT_USER()')->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $error) {
            if (get_class($error) === RuntimeException::class && in_array($error->getMessage(),
                ['sandbox_test_admin_database_mismatch', 'sandbox_test_admin_required', 'sandbox_test_admin_privileges'], true)) throw $error;
            throw new RuntimeException('sandbox_prepare_connection_failed');
        }
        try {
            $source->exec("CREATE OR REPLACE SQL SECURITY DEFINER VIEW lavkasp_test.oc_analytics_catalog_settings AS
                SELECT s.store_id,s.`key`,s.`value`,s.serialized FROM lavkasp_test.oc_setting s
                WHERE s.store_id=0 AND s.`key` IN ('config_currency','config_language','config_customer_group_id','config_tax')");
            $source->exec("CREATE OR REPLACE SQL SECURITY DEFINER VIEW lavkasp_test.oc_analytics_sandbox_guard AS
                SELECT t.TABLE_NAME AS table_name,t.TABLE_TYPE AS table_type,t.ENGINE AS engine,
                (SELECT COUNT(*) FROM information_schema.TRIGGERS tr WHERE tr.TRIGGER_SCHEMA='lavkasp_test' AND tr.EVENT_OBJECT_TABLE=t.TABLE_NAME) AS trigger_count,
                (SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE k
                    WHERE (k.TABLE_SCHEMA='lavkasp_test' AND k.TABLE_NAME=t.TABLE_NAME AND k.REFERENCED_TABLE_SCHEMA IS NOT NULL AND k.REFERENCED_TABLE_SCHEMA<>'lavkasp_test')
                       OR (k.REFERENCED_TABLE_SCHEMA='lavkasp_test' AND k.REFERENCED_TABLE_NAME=t.TABLE_NAME AND k.TABLE_SCHEMA<>'lavkasp_test')) AS external_fk_count,
                CURRENT_USER() AS audit_user FROM information_schema.TABLES t
                WHERE t.TABLE_SCHEMA='lavkasp_test' AND t.TABLE_NAME IN ('oc_product','oc_product_option_value')");
        } catch (Throwable $ignored) { throw new RuntimeException('sandbox_prepare_views_failed'); }
        \Lavka\sandbox_source_tables($source);
    } finally {
        $source = null; unset($config); restore_error_handler();
    }
    $prepared = lavka_sandbox_setup(['from' => $old, 'data' => $data, 'shop-root' => $shop, 'admin-config' => $admin], $passwordReader, $appConnector);
    return ['state' => $prepared['state'], 'environment' => 'sandbox', 'database' => 'lavkasp_test', 'data_dir' => $data,
        'test_views' => 'ready', 'source_user' => 'lavkasp_sandbox', 'public_launcher_changed' => false,
        'production_data_changed' => false, 'price_rows_changed' => false, 'next_step' => 'sync_test_data'];
}

/** Only fixed application codes cross the command-line boundary. */
function lavka_sandbox_prepare_error_code(Throwable $error): string
{
    if ($error instanceof PDOException) return 'sandbox_prepare_connection_failed';
    if ($error instanceof \Lavka\SandboxEnvironmentError) return $error->errorCode;
    $codes = ['requires_php82_cli', 'interactive_hidden_input_required', 'hidden_input_unavailable', 'sandbox_password_missing',
        'sandbox_monitor_copy_invalid', 'sandbox_monitor_copy_failed', 'sandbox_path_invalid', 'sandbox_destination_not_empty',
        'sandbox_admin_config_invalid', 'test_shop_config_missing', 'existing_analytics_metadata_missing',
        'existing_analytics_paths_mismatch', 'existing_auth_invalid', 'sandbox_destination_changed', 'sandbox_prepare_failed',
        'sandbox_source_not_isolated', 'sandbox_database_mismatch', 'sandbox_user_mismatch', 'sandbox_settings_unsupported',
        'sandbox_catalog_settings_missing', 'sandbox_connection_or_schema_failed', 'sandbox_test_admin_credentials_not_literal',
        'sandbox_test_admin_credentials_missing', 'sandbox_test_admin_required', 'sandbox_test_admin_privileges',
        'test_admin_config_required', 'sandbox_prepare_connection_failed', 'sandbox_prepare_views_failed',
        'sandbox_test_admin_database_mismatch'];
    return get_class($error) === RuntimeException::class && in_array($error->getMessage(), $codes, true)
        ? $error->getMessage() : 'sandbox_prepare_failed';
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) return;
try {
    echo \Lavka\encode(lavka_sandbox_prepare(getopt('', ['from:', 'data:', 'shop-root:', 'admin-config:']))) . "\n";
} catch (Throwable $error) {
    $code = lavka_sandbox_prepare_error_code($error);
    fwrite(STDERR, \Lavka\encode(['state' => 'blocked', 'error_code' => $code, 'public_launcher_changed' => false,
        'production_data_changed' => false, 'price_rows_changed' => false]) . "\n");
    exit(1);
}

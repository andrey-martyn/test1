<?php

declare(strict_types=1);
namespace Lavka;
require_once __DIR__ . '/common.php';

final class SandboxEnvironmentError extends \RuntimeException
{
    public $errorCode;
    public function __construct(string $code) { $this->errorCode = $code; parent::__construct($code); }
}

/** Tokenise configuration as data. Never include/execute storefront PHP. */
function sandbox_config_constants(string $contents): array
{
    $pathNames = ['DIR_STORAGE', 'DIR_APPLICATION', 'DIR_SYSTEM', 'DIR_IMAGE', 'DIR_CACHE', 'DIR_LOGS', 'DIR_SESSION', 'DIR_MODIFICATION'];
    $wanted = array_merge(['DB_DATABASE', 'DB_PREFIX', 'DB_HOSTNAME', 'DB_PORT', 'HTTP_SERVER', 'HTTPS_SERVER', 'HTTP_CATALOG', 'HTTPS_CATALOG'], $pathNames);
    $tokens = [];
    foreach (token_get_all($contents) as $token) {
        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) continue;
        if (is_array($token) && $token[0] === T_INLINE_HTML && trim($token[1]) === '') continue;
        $tokens[] = $token;
    }
    $text = function ($token): string { return is_array($token) ? $token[1] : $token; };
    $literal = function ($token): ?string {
        if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) return null;
        $value = $token[1];
        if (strlen($value) < 2 || !in_array($value[0], ["'", '"'], true) || substr($value, -1) !== $value[0]) return null;
        $value = substr($value, 1, -1);
        // The public DB names, hosts, URLs and filesystem paths need no PHP escapes.
        return strpbrk($value, "\\\x00\r\n") === false ? $value : null;
    };
    if (!isset($tokens[0]) || !is_array($tokens[0]) || $tokens[0][0] !== T_OPEN_TAG) throw new SandboxEnvironmentError('test_shop_config_executable');
    $values = []; $declared = [];
    for ($i = 1, $count = count($tokens); $i < $count;) {
        if (is_array($tokens[$i]) && $tokens[$i][0] === T_CLOSE_TAG && $i === $count - 1) break;
        $word = strtolower($text($tokens[$i]));
        // Standard ocStore config consists of unconditional define statements only.
        // Even unreachable code/includes are rejected instead of being interpreted.
        if ($word === '\\' && strtolower($text($tokens[$i + 1] ?? '')) === 'define') { $i++; $word = 'define'; }
        if (!in_array($word, ['define', '\\define'], true) || $text($tokens[$i + 1] ?? '') !== '(') throw new SandboxEnvironmentError('test_shop_config_executable');
        $name = $literal($tokens[$i + 2] ?? null);
        if ($name === null || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name) || $text($tokens[$i + 3] ?? '') !== ',') throw new SandboxEnvironmentError('test_shop_config_not_literal');
        if (isset($declared[$name])) throw new SandboxEnvironmentError('test_shop_config_ambiguous');
        $i += 4; $parts = []; $expectPart = true;
        while ($i < $count && $text($tokens[$i]) !== ')') {
            $token = $tokens[$i]; $value = $text($token);
            if (!$expectPart) {
                if ($value !== '.') throw new SandboxEnvironmentError('test_shop_config_not_literal');
                $expectPart = true; $i++; continue;
            }
            if (!is_array($token)) throw new SandboxEnvironmentError('test_shop_config_not_literal');
            if ($token[0] === T_CONSTANT_ENCAPSED_STRING || $token[0] === T_LNUMBER || $token[0] === T_DNUMBER) $parts[] = $token;
            elseif ($token[0] === T_STRING && (isset($declared[$value]) || in_array(strtolower($value), ['true', 'false', 'null'], true))) $parts[] = $token;
            else throw new SandboxEnvironmentError('test_shop_config_not_literal');
            $expectPart = false; $i++;
        }
        if ($expectPart || !$parts || $text($tokens[$i] ?? '') !== ')' || $text($tokens[$i + 1] ?? '') !== ';') throw new SandboxEnvironmentError('test_shop_config_not_literal');
        $i += 2;
        if (in_array($name, $wanted, true)) {
            if (in_array($name, $pathNames, true)) {
                $value = '';
                foreach ($parts as $part) {
                    $piece = $literal($part);
                    if ($piece === null && $part[0] === T_STRING && in_array($part[1], $pathNames, true) && isset($values[$part[1]])) $piece = $values[$part[1]];
                    if ($piece === null) throw new SandboxEnvironmentError('test_shop_config_not_literal');
                    $value .= $piece;
                }
            } else {
                if (count($parts) !== 1) throw new SandboxEnvironmentError('test_shop_config_not_literal');
                $value = $literal($parts[0]);
                if ($name === 'DB_PORT' && $parts[0][0] === T_LNUMBER) $value = $parts[0][1];
                if ($value === null) throw new SandboxEnvironmentError('test_shop_config_not_literal');
            }
            $values[$name] = $value;
        }
        // Unknown constants (including passwords) are validated as scalar syntax,
        // but their values are never retained or returned.
        $declared[$name] = true;
    }
    return $values;
}

function sandbox_store_config(string $file, string $shopRoot): array
{
    $root = realpath($shopRoot); $path = realpath($file);
    if (!$root || !$path || is_link($file) || !is_file($file) || filesize($file) > 262144
        || !str_starts_with($path, $root . '/')) throw new SandboxEnvironmentError('test_shop_config_missing');
    $contents = @file_get_contents($path);
    if ($contents === false) throw new SandboxEnvironmentError('test_shop_config_unreadable');
    $values = sandbox_config_constants($contents); unset($contents);
    if (($values['DB_DATABASE'] ?? '') !== 'lavkasp_test') throw new SandboxEnvironmentError('test_shop_points_elsewhere');
    if (($values['DB_PREFIX'] ?? '') !== 'oc_') throw new SandboxEnvironmentError('test_shop_prefix_mismatch');
    if (!in_array($values['DB_HOSTNAME'] ?? '', ['127.0.0.1', 'localhost', 'lavkasp.mysql.tools'], true)) throw new SandboxEnvironmentError('test_shop_host_unknown');
    if (($values['DB_PORT'] ?? '3306') !== '3306') throw new SandboxEnvironmentError('test_shop_port_mismatch');
    $required = ['HTTP_SERVER', 'HTTPS_SERVER'];
    if ($path !== $root . '/config.php') $required = array_merge($required, ['HTTP_CATALOG', 'HTTPS_CATALOG']);
    foreach ($required as $key) if (empty($values[$key])) throw new SandboxEnvironmentError('test_shop_url_unknown');
    foreach (['HTTP_SERVER', 'HTTPS_SERVER', 'HTTP_CATALOG', 'HTTPS_CATALOG'] as $key) {
        if (!isset($values[$key])) continue;
        $url = parse_url($values[$key]);
        if (!$url || strtolower($url['host'] ?? '') !== 'test.lavka-speciy.com'
            || !in_array($url['scheme'] ?? '', ['http', 'https'], true) || isset($url['user']) || isset($url['pass'])
            || isset($url['query']) || isset($url['fragment'])
            || isset($url['port']) && $url['port'] !== (($url['scheme'] ?? '') === 'https' ? 443 : 80)) throw new SandboxEnvironmentError('test_shop_url_mismatch');
    }
    if (empty($values['DIR_STORAGE'])) throw new SandboxEnvironmentError('test_shop_storage_unknown');
    $storage = canonical_path($values['DIR_STORAGE']);
    $privateStorage = [dirname($root) . '/storage-test', dirname($root) . '/test_storage'];
    if (!str_starts_with($values['DIR_STORAGE'], '/')
        || !(str_starts_with($storage, $root . '/') || in_array($storage, $privateStorage, true))) throw new SandboxEnvironmentError('test_shop_storage_not_isolated');
    foreach (['DIR_APPLICATION', 'DIR_SYSTEM', 'DIR_IMAGE'] as $key) {
        if (!isset($values[$key])) continue;
        $pathValue = canonical_path($values[$key]);
        if (!str_starts_with($values[$key], '/') || !($pathValue === $root || str_starts_with($pathValue, $root . '/'))) throw new SandboxEnvironmentError('test_shop_paths_not_isolated');
    }
    foreach (['DIR_CACHE', 'DIR_LOGS', 'DIR_SESSION', 'DIR_MODIFICATION'] as $key) {
        if (!isset($values[$key])) continue;
        $pathValue = canonical_path($values[$key]);
        if (!str_starts_with($values[$key], '/') || !($pathValue === $storage || str_starts_with($pathValue, $storage . '/'))) throw new SandboxEnvironmentError('test_shop_storage_not_isolated');
    }
    return ['file' => substr($path, strlen($root) + 1), 'database' => 'lavkasp_test', 'prefix' => 'oc_', 'port' => 3306, 'test_urls' => true, 'storage' => $storage, 'storage_isolated' => true];
}

function sandbox_store_checks(string $shopRoot, ?string $adminConfig = null): array
{
    $root = realpath($shopRoot);
    if (!$root || $root !== rtrim($shopRoot, '/') || basename($root) !== 'test' || is_link($shopRoot)) throw new SandboxEnvironmentError('test_shop_root_invalid');
    $admin = $adminConfig ?: $root . '/admin/config.php'; $adminReal = realpath($admin);
    if (!$adminReal || $adminReal === $root . '/config.php' || basename($adminReal) !== 'config.php'
        || dirname($adminReal) === $root || !str_starts_with($adminReal, $root . '/')) throw new SandboxEnvironmentError('test_admin_config_invalid');
    $checks = [sandbox_store_config($root . '/config.php', $root), sandbox_store_config($admin, $root)];
    if ($checks[0]['storage'] !== $checks[1]['storage']) throw new SandboxEnvironmentError('test_shop_storage_mismatch');
    return $checks;
}

/** Isolated siblings keep credentials outside both test/ and the live www/. */
function sandbox_validate_layout(string $old, string $data, string $shop): array
{
    foreach ([$old, $data, $shop] as $path) if ($path === '' || $path[0] !== '/' || is_link($path)) throw new SandboxEnvironmentError('sandbox_path_invalid');
    $oldReal = realpath($old); $shopReal = realpath($shop); $new = canonical_path($data);
    if (!$oldReal || !$shopReal || $oldReal !== rtrim($old, '/') || $shopReal !== rtrim($shop, '/')
        || basename($oldReal) !== 'analytics-private' || $shopReal !== dirname($oldReal) . '/test'
        || $new !== dirname($oldReal) . '/analytics-private-test') throw new SandboxEnvironmentError('sandbox_directories_not_separate');
    return ['old' => $oldReal, 'data' => $new, 'shop' => $shopReal];
}

/** SHOW GRANTS can contain secrets; return capabilities, never the raw rows. */
function sandbox_source_grants(array $rows): array
{
    $select = false; $coverage = ['oc_product' => ['price' => false], 'oc_product_option_value' => ['price' => false, 'price_prefix' => false]];
    if (!$rows) throw new SandboxEnvironmentError('sandbox_privileges_not_isolated');
    foreach ($rows as $row) {
        if (!is_string($row) || !preg_match('/^GRANT\s+(.+?)\s+ON\s+(.+?)\s+TO\s+/is', $row, $match)
            || preg_match('/\bWITH\s+GRANT\s+OPTION\b/i', $row)) throw new SandboxEnvironmentError('sandbox_privileges_not_isolated');
        $privileges = trim($match[1]); $object = str_replace(['`', '\\_'], ['', '_'], trim($match[2]));
        if ($object === '*.*') {
            if (strcasecmp($privileges, 'USAGE') !== 0) throw new SandboxEnvironmentError('sandbox_global_privileges');
            continue;
        }
        if (!preg_match('/^lavkasp_test\.([A-Za-z0-9_*]+)$/D', $object, $target)) throw new SandboxEnvironmentError('sandbox_other_database_grant');
        foreach (preg_split('/,(?![^()]*\))/', $privileges) as $privilege) {
            $privilege = trim($privilege);
            if (preg_match('/^SELECT(?:\s*\([^)]*\))?$/iD', $privilege)) { $select = true; continue; }
            if (!preg_match('/^UPDATE(?:\s*\(([^)]*)\))?$/iD', $privilege, $update)) throw new SandboxEnvironmentError('sandbox_privileges_too_broad');
            $columns = isset($update[1]) ? array_map(function ($value) { return trim($value, " `\t\r\n"); }, explode(',', $update[1])) : null;
            foreach ($coverage as $table => &$fields) if ($target[1] === '*' || $target[1] === $table) {
                foreach ($fields as $column => &$allowed) if ($columns === null || in_array($column, $columns, true)) $allowed = true;
                unset($allowed);
            }
            unset($fields);
        }
    }
    if (!$select || in_array(false, $coverage['oc_product'], true) || in_array(false, $coverage['oc_product_option_value'], true)) throw new SandboxEnvironmentError('sandbox_select_update_required');
    return ['test_database_only' => true, 'select' => true, 'update' => true, 'price_columns' => true, 'ddl' => false, 'grant_option' => false];
}

/** The app user cannot reliably see all triggers, so an admin-owned view audits them. */
function sandbox_source_tables(\PDO $source): array
{
    try {
        $physical = $source->query("SELECT TABLE_NAME,TABLE_TYPE,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA='lavkasp_test' AND TABLE_NAME IN ('oc_product','oc_product_option_value')")->fetchAll(\PDO::FETCH_ASSOC);
        $audit = $source->query('SELECT table_name,table_type,engine,trigger_count,external_fk_count,audit_user FROM oc_analytics_sandbox_guard')->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Throwable $ignored) { throw new SandboxEnvironmentError('sandbox_schema_audit_required'); }
    if (count($physical) !== 2 || count($audit) !== 2) throw new SandboxEnvironmentError('sandbox_schema_audit_required');
    $tables = [];
    foreach ($physical as $row) {
        $name = $row['TABLE_NAME'] ?? '';
        if (!in_array($name, ['oc_product', 'oc_product_option_value'], true) || isset($tables[$name])
            || strtoupper($row['TABLE_TYPE'] ?? '') !== 'BASE TABLE'
            || !in_array(strtoupper($row['ENGINE'] ?? ''), ['INNODB', 'MYISAM'], true)) throw new SandboxEnvironmentError('sandbox_price_tables_not_isolated');
        $tables[$name] = strtoupper($row['ENGINE']);
    }
    $seen = [];
    foreach ($audit as $row) {
        $name = $row['table_name'] ?? '';
        if (!isset($tables[$name]) || isset($seen[$name]) || strtoupper($row['table_type'] ?? '') !== 'BASE TABLE'
            || strtoupper($row['engine'] ?? '') !== $tables[$name]
            || !isset($row['trigger_count'], $row['external_fk_count'])
            || (string) $row['trigger_count'] !== '0' || (string) $row['external_fk_count'] !== '0') throw new SandboxEnvironmentError('sandbox_schema_not_isolated');
        $user = explode('@', (string) ($row['audit_user'] ?? ''), 2)[0];
        if ($user !== 'lavkasp_test') throw new SandboxEnvironmentError('sandbox_schema_audit_required');
        $seen[$name] = true;
    }
    return ['price_tables' => 'base_tables', 'engines' => $tables, 'triggers' => 0, 'external_foreign_keys' => 0, 'audit' => 'test_admin_view'];
}

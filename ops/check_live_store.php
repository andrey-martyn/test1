<?php
declare(strict_types=1);
// Read-only diagnostics. Never include an ocStore config or application bootstrap.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', '0');

function live_require(bool $condition, string $code): void
{
    if (!$condition) throw new RuntimeException($code);
}
function live_next(array $tokens, int &$i)
{
    while (isset($tokens[$i]) && is_array($tokens[$i]) && in_array($tokens[$i][0], [T_WHITESPACE,T_COMMENT,T_DOC_COMMENT], true)) $i++;
    return $tokens[$i] ?? null;
}
function live_string($token): ?string
{
    if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) return null;
    $body = substr($token[1], 1, -1);
    if ($token[1][0] === "'") return str_replace(["\\'", "\\\\"], ["'", "\\"], $body);
    return stripcslashes($body);
}
function live_config(string $file): array
{
    live_require(realpath($file) === $file && is_file($file) && is_readable($file) && filesize($file) <= 262144, 'config_missing_or_redirected');
    $tokens = token_get_all(file_get_contents($file)); $values = [];
    foreach ($tokens as $index => $token) {
        if (!is_array($token) || $token[0] !== T_STRING || strtolower($token[1]) !== 'define') continue;
        $i = $index + 1;
        if (live_next($tokens, $i) !== '(') continue; $i++;
        $name = live_string(live_next($tokens, $i));
        if (!is_string($name) || !preg_match('/^(?:DIR_[A-Z_]+|DB_[A-Z_]+|HTTP_SERVER|HTTPS_SERVER)$/D', $name)) continue;
        $i++; if (live_next($tokens, $i) !== ',') continue; $i++;
        $parts = []; $valid = true;
        while (true) {
            $part = live_next($tokens, $i); $literal = live_string($part);
            if ($literal !== null) $parts[] = $literal;
            elseif (is_array($part) && $part[0] === T_LNUMBER) $parts[] = $part[1];
            elseif (is_array($part) && $part[0] === T_DIR) $parts[] = dirname($file);
            elseif (is_array($part) && $part[0] === T_FILE) $parts[] = $file;
            elseif (is_array($part) && $part[0] === T_STRING && isset($values[$part[1]])) $parts[] = $values[$part[1]];
            else { $valid = false; break; }
            $i++; $following = live_next($tokens, $i);
            if ($following === ')') break;
            if ($following !== '.') { $valid = false; break; }
            $i++;
        }
        $values[$name] = $valid && $parts ? implode('', $parts) : null;
    }
    return $values;
}
function live_file_state(string $path): array
{
    live_require(is_file($path) && realpath($path) === $path, 'file_missing_or_redirected');
    $s = stat($path);
    return ['sha256' => hash_file('sha256', $path), 'mode' => $s['mode'] & 07777, 'uid' => $s['uid'], 'gid' => $s['gid']];
}
function live_critical(string $root): array
{
    $state = [];
    foreach (['www/config.php','www/admin/config.php','www/index.php','www/admin/index.php','www/.htaccess'] as $name) $state[$name] = live_file_state($root . '/' . $name);
    foreach (['www','lavka_storage'] as $name) {
        $path = $root . '/' . $name;
        live_require(is_dir($path) && realpath($path) === $path, 'shop_root_or_storage_missing');
        $s = stat($path);
        $state[$name] = ['mode' => $s['mode'] & 07777, 'uid' => $s['uid'], 'gid' => $s['gid'], 'inode' => $s['ino'], 'device' => $s['dev']];
    }
    return $state;
}
function live_baseline(string $root, array $current): array
{
    $candidates = [];
    foreach (glob($root . '/ops/relocations/*/journal.json') ?: [] as $file) {
        if (realpath($file) !== $file || !is_file($file) || filesize($file) > 16777216) continue;
        $data = json_decode(file_get_contents($file), true);
        if (!is_array($data) || ($data['state'] ?? '') !== 'completed' || ($data['root'] ?? '') !== $root || !isset($data['protected']['www/config.php'])) continue;
        $id = basename(dirname($file)); $stamp = preg_replace('/^frontend-/', '', $id);
        $candidates[$stamp . ':' . $id] = ['id' => $id, 'protected' => $data['protected']];
    }
    if (!$candidates) return ['state' => 'unavailable'];
    ksort($candidates, SORT_STRING); $baseline = reset($candidates); $different = []; $checked = 0;
    foreach ($baseline['protected'] as $name => $expected) {
        if (!isset($current[$name])) continue;
        $checked++;
        foreach (['sha256','mode','uid','gid','inode','device'] as $key) {
            if (array_key_exists($key, $expected) && ($current[$name][$key] ?? null) !== $expected[$key]) $different[] = $name . ':' . $key;
        }
    }
    return ['state' => $different ? 'different' : 'matched', 'journal_id' => $baseline['id'], 'checked' => $checked, 'differences' => $different];
}
function live_select($db, string $sql)
{
    live_require(preg_match('/\ASELECT\s/i', $sql) === 1 && strpos($sql, ';') === false
        && preg_match('/\b(?:OUTFILE|DUMPFILE)\b|\bFOR\s+UPDATE\b/i', $sql) !== 1, 'non_select_query_refused');
    $result = $db->query($sql);
    live_require($result !== false, 'select_failed');
    return $result;
}
function live_database(array $c, string $role): array
{
    live_require(extension_loaded('mysqli'), 'mysqli_missing');
    mysqli_report(MYSQLI_REPORT_OFF);
    $db = mysqli_init(); $db->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);
    try {
        live_require(@$db->real_connect($c['DB_HOSTNAME'], $c['DB_USERNAME'], $c['DB_PASSWORD'], $c['DB_DATABASE'], (int) ($c['DB_PORT'] ?? 3306)), 'database_connect_failed');
        live_require($db->set_charset('utf8mb4') && $db->begin_transaction(MYSQLI_TRANS_START_READ_ONLY), 'readonly_transaction_unavailable');
        $selected = live_select($db, 'SELECT DATABASE() AS selected_database')->fetch_assoc()['selected_database'];
        live_require($selected === $c['DB_DATABASE'] && $selected !== 'lavkasp_test', 'database_identity_mismatch');
        $prefix = $c['DB_PREFIX'];
        live_select($db, 'SELECT 1 FROM `' . $prefix . 'order` LIMIT 1');
        $active = $role === 'admin' ? is_array(live_select($db, 'SELECT 1 FROM `' . $prefix . 'user` WHERE status=1 LIMIT 1')->fetch_row()) : null;
        $product = live_select($db, 'SELECT p.product_id FROM `' . $prefix . 'product` p JOIN `' . $prefix . 'product_to_store` s ON s.product_id=p.product_id WHERE p.status=1 AND p.date_available<=NOW() AND s.store_id=0 ORDER BY p.product_id LIMIT 1')->fetch_assoc();
        $category = live_select($db, 'SELECT c.category_id FROM `' . $prefix . 'category` c JOIN `' . $prefix . 'category_to_store` s ON s.category_id=c.category_id WHERE c.status=1 AND s.store_id=0 ORDER BY c.category_id LIMIT 1')->fetch_assoc();
        return ['state' => 'ok', 'database' => $selected, 'read_only_transaction' => true, 'active_admin_present' => $active,
                'product_id' => $product ? (int) $product['product_id'] : null, 'category_id' => $category ? (int) $category['category_id'] : null];
    } finally { @$db->rollback(); $db->close(); }
}
function live_url(string $url): bool
{
    $p = parse_url($url);
    return is_array($p) && ($p['scheme'] ?? '') === 'https' && in_array(strtolower($p['host'] ?? ''), ['lavka-speciy.com','www.lavka-speciy.com'], true)
        && !isset($p['user']) && !isset($p['pass']) && (!isset($p['port']) || $p['port'] === 443);
}
function live_absolute(string $base, string $reference): ?string
{
    $reference = html_entity_decode(trim($reference), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if ($reference === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $reference)) return null;
    if (strpos($reference, '//') === 0) $url = 'https:' . $reference;
    elseif (preg_match('~^[a-z][a-z0-9+.-]*:~i', $reference)) $url = $reference;
    else {
        $p = parse_url($base); if (!is_array($p)) return null;
        $basePath = $p['path'] ?? '/';
        $directory = substr($basePath, -1) === '/' ? $basePath : rtrim(dirname($basePath), '/') . '/';
        $path = $reference[0] === '/' ? $reference : (in_array($reference[0], ['?','#'], true) ? $basePath . $reference : $directory . $reference);
        $url = 'https://' . $p['host'] . $path;
    }
    return live_url($url) ? $url : null;
}
function live_fetch(string $url, bool $asset = false): array
{
    live_require(function_exists('curl_init') && live_url($url), 'https_check_unavailable');
    $deadline = microtime(true) + 10;
    for ($redirect = 0; $redirect < 4; $redirect++) {
        if (microtime(true) >= $deadline) return ['state' => 'network_timeout'];
        $body = ''; $location = ''; $phpVersion = null; $truncated = false; $limit = $asset ? 131072 : 2097152;
        $curl = curl_init($url);
        curl_setopt_array($curl, [CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => min(8, max(1, (int) ceil($deadline - microtime(true)))),
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'Lavka-readonly-health-check/1.0', CURLOPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => function ($curl, $line) use (&$location, &$phpVersion) {
                if (stripos($line, 'Location:') === 0) $location = trim(substr($line,9));
                if (stripos($line, 'X-Powered-By:') === 0 && preg_match('~\bPHP/([0-9]+\.[0-9]+(?:\.[0-9]+)?)\b~', $line, $match)) $phpVersion = $match[1];
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($curl, $part) use (&$body, &$truncated, $limit) {
                if (strlen($body) + strlen($part) > $limit) { $truncated = true; return 0; }
                $body .= $part; return strlen($part);
            }]);
        curl_exec($curl); $code = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $type = (string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE); $error = curl_errno($curl); curl_close($curl);
        if ($error && !($truncated && $error === CURLE_WRITE_ERROR)) return ['state' => 'network_error', 'http_status' => $code, 'curl_code' => $error];
        if ($code >= 300 && $code < 400 && $location !== '') {
            $next = live_absolute($url, $location);
            if ($next === null) return ['state' => 'redirect_requires_review', 'http_status' => $code];
            $url = $next; continue;
        }
        return ['state' => 'received', 'http_status' => $code, 'content_type' => $type, 'body' => $body, 'url' => $url, 'truncated' => $truncated, 'web_php_version_header' => $phpVersion];
    }
    return ['state' => 'redirect_limit'];
}
function live_response(array $response, bool $asset = false): array
{
    $body = $response['body'] ?? ''; $code = $response['http_status'] ?? null;
    $visible = html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $error = preg_match('~(?:Fatal error:|Uncaught (?:Error|Exception|mysqli_sql_exception)|Warning:.*?(?:on line|/home/)|Error: Could not (?:connect|load)|SQLSTATE\[)~is', $visible) === 1;
    $challenge = preg_match('~(?:cf-chl-|<title>\s*(?:Just a moment|Checking your browser)|common-maintenance|error/maintenance)~i', $body) === 1;
    $html = stripos($body, '<html') !== false || stripos($body, '<!doctype html') !== false;
    $assetHtml = stripos($response['content_type'] ?? '', 'text/html') !== false || preg_match('~^\s*(?:<!doctype html|<html\b)~i', $body) === 1;
    $ok = ($response['state'] ?? '') === 'received' && in_array($code, $asset ? [200,206] : [200], true) && $body !== '' && !$error && !$challenge && ($asset ? !$assetHtml : $html);
    $out = ['ok' => $ok, 'state' => $response['state'] ?? 'unknown', 'http_status' => $code, 'content_type' => $response['content_type'] ?? null,
            'php_or_database_error_detected' => $error, 'challenge_or_maintenance_detected' => $challenge, 'web_php_version_header' => $response['web_php_version_header'] ?? null];
    if (isset($response['curl_code'])) $out['curl_code'] = $response['curl_code'];
    return $out; // Never print page bodies, redirects, forms or error messages.
}
function live_assets(string $html, string $base): array
{
    $result = [];
    if (preg_match('~<base\b[^>]*\bhref=["\']([^"\']+)["\']~i', $html, $baseMatch)) {
        $resolved = live_absolute($base, $baseMatch[1]);
        if ($resolved === null) return [];
        $base = $resolved;
    }
    foreach (['stylesheet' => '~<link\b[^>]*\bhref=["\']([^"\']+\.css(?:\?[^"\']*)?)["\']~i',
              'script' => '~<script\b[^>]*\bsrc=["\']([^"\']+)["\']~i',
              'image' => '~<img\b[^>]*\bsrc=["\']([^"\']+)["\']~i'] as $kind => $pattern) {
        preg_match_all($pattern, $html, $matches);
        foreach ($matches[1] ?? [] as $reference) {
            $url = live_absolute($base, $reference);
            if ($url === null) continue;
            $path = rawurldecode(parse_url($url, PHP_URL_PATH) ?? '');
            $extensions = ['stylesheet' => 'css', 'script' => 'js', 'image' => 'jpe?g|png|gif|svg|webp|avif|ico'];
            // HTML is untrusted: never follow an asset reference to an action,
            // cron endpoint, admin route or arbitrary same-host PHP script.
            if (!preg_match('~^/(?:catalog|image)/~', $path) || in_array('..', explode('/', $path), true)
                || preg_match('~\.(?:' . $extensions[$kind] . ')$~iD', $path) !== 1) continue;
            $result[$kind] = $url; break;
        }
    }
    return $result;
}
function live_audit(string $root, ?callable $dbChecker = null, ?callable $fetcher = null): array
{
    $out = ['target' => 'lavka-speciy.com', 'shop' => $root . '/www', 'storage' => $root . '/lavka_storage',
            'issues' => [], 'warnings' => [], 'cli_php_version' => PHP_VERSION, 'shop_file_writes' => false,
            'sql_policy' => 'SELECT in READ ONLY transactions; credentials and rows are not printed', 'http_policy' => 'GET only, HTTPS certificate validation enabled',
            'scope' => 'Paths, config/DB identity, baseline, public pages and sample assets. Interactive admin login and order placement are not tested.'];
    try {
        live_require(PHP_VERSION_ID >= 80200 && realpath($root) === $root, 'php82_or_root_invalid');
        $before = live_critical($root); $configs = [];
        foreach (['storefront' => 'www/config.php', 'admin' => 'www/admin/config.php'] as $role => $relative) {
            $c = live_config($root . '/' . $relative); $shop = $root . '/www'; $storage = $root . '/lavka_storage';
            foreach (['DB_HOSTNAME','DB_USERNAME','DB_PASSWORD','DB_DATABASE','DB_PREFIX'] as $key) live_require(isset($c[$key]) && is_string($c[$key]), 'database_config_unresolved');
            if (array_key_exists('DB_PORT', $c)) live_require(is_string($c['DB_PORT']) && ctype_digit($c['DB_PORT']) && (int) $c['DB_PORT'] > 0 && (int) $c['DB_PORT'] <= 65535, 'database_port_unresolved');
            live_require($c['DB_DATABASE'] !== 'lavkasp_test' && preg_match('/^[A-Za-z0-9_]{1,64}$/D', $c['DB_DATABASE']) === 1 && preg_match('/^[A-Za-z0-9_]{1,32}$/D', $c['DB_PREFIX']) === 1, 'test_or_invalid_database_refused');
            $expected = ['DIR_APPLICATION' => $shop . ($role === 'admin' ? '/admin' : '/catalog'), 'DIR_SYSTEM' => $shop . '/system', 'DIR_IMAGE' => $shop . '/image', 'DIR_STORAGE' => $storage];
            if ($role === 'admin') $expected['DIR_CATALOG'] = $shop . '/catalog';
            foreach ($expected as $key => $path) live_require(isset($c[$key]) && rtrim($c[$key], '/') === $path && realpath($path) === $path && is_readable($path), 'shop_path_mismatch_' . $key);
            foreach (['HTTP_SERVER','HTTPS_SERVER'] as $key) {
                $suffix = $role === 'admin' ? 'admin/' : '';
                live_require(isset($c[$key]) && preg_match('~^https?://(?:www\.)?lavka-speciy\.com/' . $suffix . '$~D', $c[$key]) === 1, 'shop_url_mismatch');
            }
            $configs[$role] = $c;
            $out['configs'][$role] = ['state' => 'ok', 'database' => $c['DB_DATABASE'], 'storage' => $storage];
        }
        live_require($configs['storefront']['DB_DATABASE'] === $configs['admin']['DB_DATABASE'] && $configs['storefront']['DB_PREFIX'] === $configs['admin']['DB_PREFIX'], 'admin_store_database_mismatch');
        foreach (['cache','logs','session','upload','download','modification'] as $name) {
            $path = $root . '/lavka_storage/' . $name;
            $ok = is_dir($path) && realpath($path) === $path && is_readable($path) && is_writable($path);
            $out['storage_checks'][$name] = ['readable_and_writable' => $ok];
            if (!$ok) $out['issues'][] = 'storage_access_' . $name;
        }
        $out['baseline'] = live_baseline($root, $before);
        if ($out['baseline']['state'] === 'different') $out['issues'][] = 'protected_files_differ_from_relocation_baseline';
        if ($out['baseline']['state'] === 'unavailable') $out['warnings'][] = 'relocation_baseline_unavailable';
        $dbChecker = $dbChecker ?? 'live_database';
        foreach ($configs as $role => $c) {
            try {
                $out['database'][$role] = $dbChecker($c, $role);
                live_require(($out['database'][$role]['state'] ?? '') === 'ok' && ($out['database'][$role]['read_only_transaction'] ?? false) === true, 'database_probe_failed');
                if ($role === 'admin') live_require(($out['database'][$role]['active_admin_present'] ?? false) === true, 'active_admin_not_found');
            } catch (Throwable $error) {
                $code = $error instanceof RuntimeException && preg_match('/^[a-z_]+$/D', $error->getMessage()) ? $error->getMessage() : 'database_check_failed';
                $out['database'][$role] = ['state' => 'failed', 'code' => $code]; $out['issues'][] = 'database_probe_' . $role;
            }
        }
        $fetcher = $fetcher ?? 'live_fetch'; $origin = 'https://lavka-speciy.com/';
        $urls = ['homepage' => $origin, 'admin_login' => $origin . 'admin/index.php?route=common/login', 'cart' => $origin . 'index.php?route=checkout/cart'];
        $sample = $out['database']['storefront'] ?? [];
        if (!empty($sample['product_id'])) $urls['product'] = $origin . 'index.php?route=product/product&product_id=' . (int) $sample['product_id'];
        if (!empty($sample['category_id'])) $urls['category'] = $origin . 'index.php?route=product/category&path=' . (int) $sample['category_id'];
        $deadline = microtime(true) + 40;
        foreach ($urls as $label => $url) {
            if (microtime(true) >= $deadline) { $out['warnings'][] = 'http_time_budget_reached'; break; }
            try {
                $response = $fetcher($url, false); $out['http'][$label] = live_response($response);
                if ($label === 'admin_login') {
                    $out['http'][$label]['password_form_detected'] = preg_match('~<input\b[^>]*\btype\s*=\s*["\']?password\b~i', $response['body'] ?? '') === 1;
                    if (!$out['http'][$label]['password_form_detected']) $out['http'][$label]['ok'] = false;
                }
                if (!$out['http'][$label]['ok']) $out['issues'][] = 'http_' . $label;
                if ($label === 'homepage' && $out['http'][$label]['ok']) {
                    $assets = live_assets($response['body'] ?? '', $response['url'] ?? $origin);
                    if (!$assets) $out['warnings'][] = 'no_same_host_static_assets_selected';
                    foreach ($assets as $kind => $assetUrl) {
                        if (microtime(true) >= $deadline) { $out['warnings'][] = 'asset_time_budget_reached'; break; }
                        $out['http']['asset_' . $kind] = live_response($fetcher($assetUrl, true), true);
                        if (!$out['http']['asset_' . $kind]['ok']) $out['issues'][] = 'http_asset_' . $kind;
                    }
                }
            } catch (Throwable $error) { $out['http'][$label] = ['ok' => false, 'state' => 'check_failed']; $out['issues'][] = 'http_' . $label; }
        }
        $out['http_complete'] = count(array_intersect(array_keys($urls), array_keys($out['http'] ?? []))) === count($urls);
        if (!$out['http_complete']) $out['issues'][] = 'http_checks_incomplete';
        $out['checked_files_unchanged'] = live_critical($root) === $before;
        if (!$out['checked_files_unchanged']) $out['issues'][] = 'protected_files_changed_during_check';
    } catch (Throwable $error) {
        $out['issues'][] = $error instanceof RuntimeException && preg_match('/^[a-zA-Z0-9_]+$/D', $error->getMessage()) ? $error->getMessage() : 'audit_stopped';
    }
    $out['issues'] = array_values(array_unique($out['issues']));
    $out['ok'] = !$out['issues'];
    return $out;
}
if (!defined('LAVKA_LIVE_CHECK_TEST')) {
    if (PHP_VERSION_ID < 80200 || count($argv) !== 1) { fwrite(STDERR, "Запуск: /usr/local/php82/bin/php ops/check_live_store.php\n"); exit(1); }
    $result = live_audit('/home/lavkasp/lavka-speciy.com');
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
    exit($result['ok'] ? 0 : 1);
}

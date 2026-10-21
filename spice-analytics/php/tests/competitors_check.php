<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/src/Competitors.php';

function cck(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function rejects(callable $call, string $message, string $class = 'InvalidArgumentException'): void {
    try { $call(); } catch (Throwable $e) { cck($e instanceof $class, $message . ': unexpected ' . get_class($e)); return; }
    throw new RuntimeException($message . ': accepted');
}
function product_observation(string $price = '50', string $stock = 'in_stock'): array {
    return ['kind' => 'product', 'title' => 'Синтетический кориандр', 'offers' => [['key' => '100g', 'label' => '100 г', 'price' => $price, 'old_price' => null, 'currency' => 'UAH', 'grams' => 100, 'availability' => $stock, 'min_quantity' => 1, 'unit' => 'pack', 'condition' => '']], 'text' => '', 'links' => [], 'warnings' => [], 'method' => 'fixture'];
}
function text_observation(string $text): array { return ['kind' => 'terms', 'title' => 'Условия', 'offers' => [], 'text' => $text, 'links' => [], 'warnings' => [], 'method' => 'fixture']; }
function catalog_observation(array $links): array { return ['kind' => 'catalog', 'title' => 'Каталог', 'offers' => [], 'text' => '', 'links' => array_map(function ($name) { return ['url' => 'https://deluxe.com.ua/fixture-' . $name . '.html', 'title' => 'Товар ' . $name]; }, $links), 'warnings' => [], 'method' => 'fixture']; }
function change_count(PDO $db): int { return (int) $db->query('SELECT count(*) FROM competitor_changes')->fetchColumn(); }

function competitor_http_test_request(string $base, string $route, string $method = 'GET', ?array $body = null, string $cookie = '', string $origin = ''): array {
    $headers = ['Content-Type: application/json'];
    if ($cookie !== '') $headers[] = 'Cookie: ' . $cookie;
    if ($origin !== '') $headers[] = 'Origin: ' . $origin;
    $options = ['method' => $method, 'header' => implode("\r\n", $headers), 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 3];
    if ($body !== null) $options['content'] = \Lavka\encode($body);
    $response = @file_get_contents($base . '/analytics/index.php?route=' . rawurlencode($route), false, stream_context_create(['http' => $options]));
    $responseHeaders = $http_response_header ?? [];
    preg_match('/^HTTP\/\S+\s+(\d+)/', $responseHeaders[0] ?? '', $match);
    return ['status' => isset($match[1]) ? (int) $match[1] : 0, 'body' => $response === false ? '' : $response, 'headers' => $responseHeaders];
}

function competitor_http_routes_check(string $directory): void {
    require_once dirname(__DIR__) . '/src/Auth.php';
    $httpDirectory = $directory . '/http';
    \Lavka\private_dir($httpDirectory);
    $password = 'Synthetic-only-' . bin2hex(random_bytes(12));
    \Lavka\Auth::setPassword($httpDirectory, $password);
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorText);
    if ($socket === false) throw new RuntimeException('Could not allocate test HTTP port');
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $base = 'http://' . $address;
    $oldDirectory = getenv('ANALYTICS_DATA_DIR');
    $oldBase = getenv('ANALYTICS_BASE_PATH');
    $oldUrl = getenv('ANALYTICS_PUBLIC_URL');
    putenv('ANALYTICS_DATA_DIR=' . $httpDirectory);
    putenv('ANALYTICS_BASE_PATH=/analytics');
    putenv('ANALYTICS_PUBLIC_URL=');
    $process = null;
    try {
        $public = dirname(__DIR__) . '/public';
        $command = 'exec ' . escapeshellarg(PHP_BINARY) . ' -S ' . escapeshellarg($address) . ' -t ' . escapeshellarg($public) . ' ' . escapeshellarg($public . '/index.php');
        $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pipes);
        cck(is_resource($process), 'Synthetic HTTP server starts');
        for ($i = 0; $i < 30; $i++) {
            if (competitor_http_test_request($base, '/health')['status'] === 200) break;
            usleep(100000);
        }
        cck(competitor_http_test_request($base, '/api/competitors')['status'] === 401, 'Competitor API requires existing cabinet login');
        cck(!is_file($httpDirectory . '/competitors.sqlite3'), 'Unauthorized API request does not create module database');
        $login = competitor_http_test_request($base, '/api/login', 'POST', ['password' => $password], '', $base);
        cck($login['status'] === 200, 'Existing authentication accepts synthetic password');
        $cookie = '';
        foreach ($login['headers'] as $header) if (preg_match('/^Set-Cookie:\s*(lavka_analytics_session=[^;]+)/i', $header, $match)) $cookie = $match[1];
        cck($cookie !== '', 'Login supplies existing cabinet session cookie');
        $overview = competitor_http_test_request($base, '/api/competitors', 'GET', null, $cookie);
        cck($overview['status'] === 200 && count(\Lavka\decode($overview['body'])['targets']) === 90, 'Competitor API works independently of orders database');
        cck(!is_file($httpDirectory . '/analytics.sqlite3'), 'Monitoring API does not create orders database');
        cck(competitor_http_test_request($base, '/api/meta', 'GET', null, $cookie)['status'] === 503, 'Orders API still reports missing order data normally');
        $body = ['source_id' => 'deluxe', 'kind' => 'product', 'url' => 'https://deluxe.com.ua/fixture-http.html', 'label' => 'Synthetic HTTP product'];
        cck(competitor_http_test_request($base, '/api/competitors/targets', 'POST', $body, $cookie, 'https://unrelated.invalid')['status'] === 403, 'Cross-origin mutation rejected');
        $create = competitor_http_test_request($base, '/api/competitors/targets', 'POST', $body, $cookie, $base);
        cck($create['status'] === 201, 'Authenticated target addition accepted');
        $id = \Lavka\decode($create['body'])['id'];
        cck(competitor_http_test_request($base, '/api/competitors/targets/' . $id, 'PUT', ['url' => 'https://deluxe.com.ua/changed.html'], $cookie, $base)['status'] === 409, 'HTTP URL edit cannot reattribute history');
        cck(competitor_http_test_request($base, '/api/competitors/targets/' . $id, 'GET', null, $cookie)['status'] === 200, 'Authenticated history API readable');
        cck(competitor_http_test_request($base, '/api/competitors/targets/9999999999', 'GET', null, $cookie)['status'] === 404, 'Missing target is404');
        cck(competitor_http_test_request($base, '/api/competitors/queue', 'POST', ['target_id' => $id], $cookie, $base)['status'] === 200, 'HTTP queue only schedules background work');
        cck(competitor_http_test_request($base, '/api/competitors/queue', 'POST', ['target_id' => (string) $id], $cookie, $base)['status'] === 422, 'HTTP target id requires numeric JSON type');
        cck(competitor_http_test_request($base, '/api/competitors', 'POST', [], $cookie, $base)['status'] === 405, 'Unsupported API methods are rejected');
        $monitor = \Lavka\competitors_db($httpDirectory);
        cck((int) $monitor->query('SELECT count(*) FROM competitor_attempts')->fetchColumn() === 0 && (int) $monitor->query('SELECT count(*) FROM competitor_observations')->fetchColumn() === 0, 'HTTP operations never crawl or invent observations');
        $monitor = null;
    } finally {
        if (is_resource($process)) { proc_terminate($process); proc_close($process); }
        foreach (['ANALYTICS_DATA_DIR' => $oldDirectory, 'ANALYTICS_BASE_PATH' => $oldBase, 'ANALYTICS_PUBLIC_URL' => $oldUrl] as $key => $value) putenv($value === false ? $key : $key . '=' . $value);
        foreach (glob($httpDirectory . '/*') as $file) if (is_file($file)) unlink($file);
        rmdir($httpDirectory);
    }
}

$directory = sys_get_temp_dir() . '/competitors-check-' . bin2hex(random_bytes(5));
\Lavka\private_dir($directory);
$db = null; $orders = null;
try {
    $orders = \Lavka\database($directory . '/analytics.sqlite3'); \Lavka\init_schema($orders);
    $orders->exec("INSERT INTO customer_actions VALUES('synthetic','{\"note\":\"preserve\"}')");
    \Lavka\bump($orders); $ordersRevision = \Lavka\revision($orders);
    $db = \Lavka\competitors_db($directory); $overview = \Lavka\competitors_overview($db);
    cck(count($overview['sources']) === 5 && $overview['settings']['interval_hours'] === 24, 'Fixed sources and daily cadence');
    cck(count(array_filter($overview['targets'], function ($t) { return $t['source_id'] === 'lavka'; })) === 56, 'Priority seed is deduplicated by product id');
    cck(count(array_filter($overview['targets'], function ($t) { return $t['source_id'] !== 'lavka' && $t['kind'] === 'product'; })) === 22, 'Initial competitor candidates are included without claiming equivalence');
    cck(count($overview['targets']) === 90, 'Seed includes all56 own22 competitor and12 commercial/catalog watchers');
    cck(count(array_filter($overview['targets'], function ($t) { return $t['source_id'] === 'spicery' && $t['enabled'] && $t['kind'] === 'terms'; })) === 2, 'Verified Spicery delivery and discount page URLs are enabled');
    cck(!array_filter($overview['targets'], function ($t) { return $t['last_success'] !== null || $t['observation'] !== null || $t['match_confirmed']; }), 'No invented prices, checks or equivalence in seed');
    $count = count($overview['targets']); $db = null; $db = \Lavka\competitors_db($directory);
    cck(count(\Lavka\competitors_overview($db)['targets']) === $count, 'Seed initialization is idempotent');
    cck((fileperms($directory . '/competitors.sqlite3') & 0777) === 0600, 'Monitor SQLite is private');
    foreach (['http://deluxe.com.ua/a', 'https://deluxe.com.ua.evil.invalid/a', 'https://user:pass@deluxe.com.ua/a', 'https://deluxe.com.ua:8443/a', 'https://127.0.0.1/a', 'https://deluxe.com.ua/a#token', 'https://deluxe.com.ua/login', 'https://deluxe.com.ua/login.php', 'https://deluxe.com.ua/%2e%2e/account', 'https://deluxe.com.ua/%252e%252e/account', 'https://deluxe.com.ua/a?access_token=secret', 'https://deluxe.com.ua/index.php?route=account/login', 'https://deluxe.com.ua/a?foo[]=1', 'https://deluxe.com.ua/a%00b', 'https://deluxe.com.ua/a?action=delete', 'https://deluxe.com.ua/a?add_to_cart=1', 'https://deluxe.com.ua/a?api_key=secret', 'https://deluxe.com.ua/basket', 'https://deluxe.com.ua/a?product_id=abc'] as $url) {
        rejects(function () use ($url) { \Lavka\competitors_validate_url($url, 'deluxe'); }, 'Nonpublic/unsafe URL rejected');
    }
    $own = \Lavka\competitors_validate_url('https://lavka-speciy.com/index.php?route=product/product&product_id=990', 'lavka');
    cck(strpos($own, 'product_id=990') !== false && strpos($own, 'route=product%2Fproduct') !== false, 'Public ocStore product query supported');
    rejects(function () { \Lavka\competitors_validate_url('https://lavka-speciy.com/index.php?route=product/product&product_id=abc', 'lavka'); }, 'Own product id must be numeric');
    $body = ['source_id' => 'deluxe', 'kind' => 'product', 'url' => 'https://deluxe.com.ua/fixture-watch.html', 'label' => 'Синтетический товар', 'pack_label' => '100 г / 1 кг'];
    $target = \Lavka\competitors_save_target($db, $body); $id = $target['id'];
    cck($target['queued'] && $target['match_group'] === '' && !$target['match_confirmed'], 'New target is queued with no automatic equivalence');
    rejects(function () use ($db, $body) { \Lavka\competitors_save_target($db, $body); }, 'Duplicate targets rejected', 'Lavka\\CompetitorConflict');
    rejects(function () use ($db, $id) { \Lavka\competitors_save_target($db, ['url' => 'https://deluxe.com.ua/other.html'], $id); }, 'URL edits cannot reattribute old history', 'Lavka\\CompetitorConflict');
    rejects(function () use ($db, $id) { \Lavka\competitors_save_target($db, ['grams_override' => -1], $id); }, 'Negative weight rejected');
    rejects(function () use ($db, $id) { \Lavka\competitors_save_target($db, ['grams_override' => '100'], $id); }, 'Weight uses JSON numeric type');
    rejects(function () use ($db, $id) { \Lavka\competitors_save_target($db, ['match_confirmed' => true], $id); }, 'Confirmation requires group');
    rejects(function () use ($db, $id) { \Lavka\competitors_save_target($db, ['enabled' => 1], $id); }, 'Boolean validation');
    rejects(function () use ($db, $id) { \Lavka\competitors_save_target($db, ['secret' => 'not-a-field'], $id); }, 'Unknown input fields rejected');
    \Lavka\competitors_record_attempt($db, $id, product_observation(), null, '2026-10-01T10:00:00Z', 200);
    cck(change_count($db) === 0, 'First price observation is baseline, not a discount');
    $saved = \Lavka\competitors_target($db, $id);
    cck($saved['observation']['offers'][0]['price'] === '50' && $saved['next_check_at'] === '2026-10-02T10:00:00Z', 'Positive decimal preserved and next success due in24h');
    \Lavka\competitors_record_attempt($db, $id, product_observation('50.0000'), null, '2026-10-02T10:00:00Z', 200);
    cck(change_count($db) === 0, 'Formatting-only price change is ignored');
    \Lavka\competitors_record_attempt($db, $id, null, 'http_404', '2026-10-03T10:00:00Z', 404);
    $error = \Lavka\competitors_target($db, $id);
    cck($error['last_success'] === '2026-10-02T10:00:00Z' && $error['observation'] === $saved['observation'] && $error['last_attempt']['state'] === 'error', 'Load error preserves last successful price and stock');
    cck($error['next_check_at'] === '2026-10-03T11:00:00Z' && change_count($db) === 0, 'Error is not out-of-stock/price change and backs off1h');
    \Lavka\competitors_record_attempt($db, $id, null, 'captcha', '2026-10-03T11:00:00Z', 403);
    cck(\Lavka\competitors_target($db, $id)['next_check_at'] === '2026-10-03T13:00:00Z', 'Repeated errors back off further');
    $attemptCount = count(\Lavka\competitors_history($db, $id)['attempts']);
    rejects(function () use ($db, $id) { \Lavka\competitors_record_attempt($db, $id, product_observation('0'), null, '2026-10-04T10:00:00Z', 200); }, 'Zero cannot masquerade as a successful price');
    rejects(function () use ($db, $id) { \Lavka\competitors_record_attempt($db, $id, product_observation('40'), null, '2026-02-30T10:00:00Z', 200); }, 'Invalid calendar timestamp rejected');
    cck(count(\Lavka\competitors_history($db, $id)['attempts']) === $attemptCount, 'Invalid observations never partially write');
    \Lavka\competitors_record_attempt($db, $id, product_observation('40'), null, '2026-10-04T10:00:00Z', 200);
    cck(change_count($db) === 1 && \Lavka\competitors_overview($db)['changes'][0]['kind'] === 'price', 'Actual price change recorded');
    \Lavka\competitors_record_attempt($db, $id, product_observation('40', 'out_of_stock'), null, '2026-10-05T10:00:00Z', 200);
    cck(change_count($db) === 2 && \Lavka\competitors_overview($db)['changes'][0]['kind'] === 'availability', 'Explicit stock change is distinct');
    $history = \Lavka\competitors_history($db, $id);
    \Lavka\competitors_save_target($db, ['enabled' => false], $id);
    cck(count(\Lavka\competitors_history($db, $id)['observations']) === count($history['observations']), 'Archive preserves observations');
    rejects(function () use ($db, $id) { \Lavka\competitors_queue($db, $id); }, 'Archived target not queued', 'Lavka\\CompetitorConflict');
    \Lavka\competitors_save_target($db, ['enabled' => true, 'match_group' => 'lavka-990', 'match_confirmed' => true], $id);
    cck(\Lavka\competitors_target($db, $id)['match_confirmed'], 'Manual confirmation stored separately from prices');
    $terms = \Lavka\competitors_save_target($db, ['source_id' => 'deluxe', 'kind' => 'terms', 'url' => 'https://deluxe.com.ua/fixture-terms', 'label' => 'Тестовые условия']);
    \Lavka\competitors_record_attempt($db, $terms['id'], text_observation('Минимальный заказ 100 грн.'), null, '2026-10-01T10:00:00Z');
    \Lavka\competitors_record_attempt($db, $terms['id'], text_observation("Минимальный\nзаказ 100 грн."), null, '2026-10-02T10:00:00Z');
    cck(change_count($db) === 2, 'Whitespace-only terms changes ignored');
    \Lavka\competitors_record_attempt($db, $terms['id'], text_observation('Минимальный заказ 200 грн.'), null, '2026-10-03T10:00:00Z');
    cck(\Lavka\competitors_overview($db)['changes'][0]['kind'] === 'terms', 'Changed terms retain before and after evidence');
    $catalog = \Lavka\competitors_save_target($db, ['source_id' => 'deluxe', 'kind' => 'catalog', 'url' => 'https://deluxe.com.ua/fixture-catalog', 'label' => 'Тестовый каталог']);
    \Lavka\competitors_record_attempt($db, $catalog['id'], catalog_observation(['a', 'b']), null, '2026-10-01T10:00:00Z');
    cck(!\Lavka\competitors_overview($db)['discoveries'], 'First listing snapshot is baseline, never all-new');
    \Lavka\competitors_record_attempt($db, $catalog['id'], catalog_observation(['b', 'a', 'c', 'c']), null, '2026-10-02T10:00:00Z');
    $discoveries = \Lavka\competitors_overview($db)['discoveries'];
    cck(count($discoveries) === 1 && !$discoveries[0]['baseline'] && strpos($discoveries[0]['url'], '-c.html') !== false, 'Only new observed URL creates discovery, deduplicated');
    \Lavka\competitors_record_attempt($db, $catalog['id'], catalog_observation(['a']), null, '2026-10-03T10:00:00Z');
    \Lavka\competitors_record_attempt($db, $catalog['id'], catalog_observation(['a', 'c']), null, '2026-10-04T10:00:00Z');
    cck(count(\Lavka\competitors_overview($db)['discoveries']) === 1, 'Reappearance is not a second launch');
    $seedId = $overview['targets'][0]['id']; \Lavka\competitors_save_target($db, ['enabled' => false], $seedId);
    $db = null; $db = \Lavka\competitors_db($directory);
    cck(!\Lavka\competitors_target($db, $seedId)['enabled'], 'Seed initialization preserves user disable');
    cck(\Lavka\competitors_queue($db, $id)['queued'] === 1 && in_array($id, array_column(\Lavka\competitors_due($db, 50), 'id'), true), 'Manual queue is asynchronous and target becomes due');
    for ($i = 0; $i < 55; ++$i) \Lavka\competitors_run_status($db, ['started_at' => '2026-10-01T10:00:00Z', 'processed' => $i]);
    cck((int) $db->query('SELECT count(*) FROM competitor_runs')->fetchColumn() === 50 && count(\Lavka\competitors_overview($db)['runs']) === 10, 'Run summary history bounded');
    cck(\Lavka\revision($orders) === $ordersRevision && $orders->query('SELECT payload FROM customer_actions')->fetchColumn() === '{"note":"preserve"}', 'Monitor never modifies orders CRM or report revision');
    competitor_http_routes_check($directory);
    echo \Lavka\encode(['php' => PHP_VERSION, 'seed_and_validation' => 'passed', 'error_preservation_and_backoff' => 'passed', 'price_stock_and_terms_history' => 'passed', 'catalog_baseline_and_discoveries' => 'passed', 'queue_archive_and_order_isolation' => 'passed', 'http_auth_origin_and_no_crawl' => 'passed']), "\n";
} finally {
    $db = null; $orders = null;
    foreach (glob($directory . '/*') as $file) if (is_file($file)) unlink($file);
    rmdir($directory);
}

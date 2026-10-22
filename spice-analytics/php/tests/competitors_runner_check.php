<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/bin/competitors.php';

function runner_expect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
function runner_remove(string $directory): void
{
    foreach (scandir($directory) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        $path = $directory . '/' . $name;
        if (is_dir($path) && !is_link($path)) runner_remove($path); else unlink($path);
    }
    rmdir($directory);
}
function runner_command(array $arguments): array
{
    $parts = array_merge([PHP_BINARY, dirname(__DIR__) . '/bin/competitors.php'], $arguments);
    $pipes = [];
    $process = proc_open(implode(' ', array_map('escapeshellarg', $parts)), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot start CLI check');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
    return ['exit_code' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
}

$root = sys_get_temp_dir() . '/lavka-runner-' . bin2hex(random_bytes(6));
\Lavka\private_dir($root);
try {
    $cli = runner_command(['--data', $root, '--init-only']);
    if (PHP_VERSION_ID < 80200) {
        runner_expect($cli['exit_code'] === 1 && strpos($cli['stderr'], '8.2 CLI') !== false, 'Real old CLI entry rejects before initialization');
        try { lavka_competitors_run(['data' => $root, 'init-only' => false]); }
        catch (InvalidArgumentException $e) {
            runner_expect(strpos($e->getMessage(), '8.2 CLI') !== false, 'Old CLI receives actionable version message');
            runner_expect(!is_file($root . '/competitors.sqlite3'), 'Old CLI never initializes data');
            echo \Lavka\encode(['runner_old_cli_rejected' => 'passed', 'network_requests' => 0, 'php' => PHP_VERSION]) . "\n";
            exit(0);
        }
        throw new RuntimeException('Old CLI was accepted');
    }
    runner_expect($cli['exit_code'] === 0 && json_decode($cli['stdout'], true)['network_requests'] === 0, 'Real CLI initializes without network');
    $calls = [];
    $now = (float)time();
    $clock = function () use (&$now): float { return $now; };
    $pause = function (float $seconds) use (&$now): void { $now += $seconds; };
    $productHtml = '<h1>Synthetic cinnamon 100 g</h1><script type="application/ld+json">' . json_encode([
        '@type' => 'Product', 'name' => 'Synthetic cinnamon 100 g',
        'offers' => ['@type' => 'Offer', 'price' => '75.00', 'priceCurrency' => 'UAH', 'availability' => 'https://schema.org/InStock']
    ]) . '</script>';
    $transport = function (string $url) use (&$calls, $productHtml): array {
        $calls[] = $url;
        return ['status' => 200, 'headers' => ['content-type' => strpos($url, '/robots.txt') !== false ? 'text/plain' : 'text/html'],
            'body' => strpos($url, '/robots.txt') !== false ? "User-agent: *\nAllow: /\n" : $productHtml];
    };
    $init = lavka_competitors_run(['data' => $root, 'init-only' => false], $transport, $clock, $pause);
    runner_expect($init['state'] === 'initialized' && !$calls, 'Initialization does not request websites');
    runner_expect((fileperms($root . '/competitors.sqlite3') & 0777) === 0600, 'Monitor database stays private');
    $db = \Lavka\competitors_db($root);
    $db->exec('UPDATE competitor_targets SET enabled=0');
    $target = \Lavka\competitors_save_target($db, ['source_id' => 'deluxe', 'kind' => 'product',
        'url' => 'https://deluxe.com.ua/monitor-synthetic-product', 'label' => 'Synthetic cinnamon']);
    $lock = fopen($root . '/.competitors.lock', 'c');
    runner_expect(flock($lock, LOCK_EX | LOCK_NB), 'Test holds run lock');
    $skipped = lavka_competitors_run(['data' => $root], $transport, $clock, $pause);
    runner_expect($skipped['state'] === 'skipped' && !$calls, 'Overlapping run does not request or initialize');
    $lockedCli = runner_command(['--data', $root, '--seconds', '45']);
    runner_expect($lockedCli['exit_code'] === 0 && json_decode($lockedCli['stdout'], true)['reason'] === 'already_running', 'Another CLI process honors the full-run lock');
    flock($lock, LOCK_UN); fclose($lock);

    $result = lavka_competitors_run(['data' => $root, 'limit' => 5, 'seconds' => 45], $transport, $clock, $pause);
    runner_expect($result['checked'] === 1 && $result['succeeded'] === 1 && count($calls) === 2, 'One due product passes robots, parser and storage');
    $stored = \Lavka\competitors_target($db, $target['id']);
    runner_expect(is_string($stored['observation']['offers'][0]['price']) && bccomp($stored['observation']['offers'][0]['price'], '75.00', 2) === 0, 'Actual parsed decimal price is stored');
    runner_expect(strtotime($stored['next_check_at']) - strtotime($stored['last_success']) === 86400, 'Successful target interval is one day');
    runner_expect($stored['last_attempt']['state'] === 'ok', 'Successful attempt is recorded separately');
    $callCount = count($calls);
    $empty = lavka_competitors_run(['data' => $root, 'seconds' => 45], $transport, $clock, $pause);
    runner_expect($empty['checked'] === 0 && count($calls) === $callCount, 'Not-yet-due target is not fetched again');
    $before = $stored['observation'];
    \Lavka\competitors_queue($db, $target['id']);
    $now += 10;
    $failed = lavka_competitors_run(['data' => $root, 'seconds' => 45], function () { return ['status' => 503, 'body' => 'Unavailable']; }, $clock, $pause);
    $stored = \Lavka\competitors_target($db, $target['id']);
    runner_expect($failed['failed'] === 1 && $stored['last_attempt']['error_code'] === 'upstream_error', 'Upstream error remains a failed attempt');
    runner_expect($stored['observation'] === $before, 'Failure preserves the last successful price');
    runner_expect(strtotime($stored['next_check_at']) > strtotime($stored['last_attempt']['checked_at']), 'Failure is retried later');

    // Redirects must not bypass path-specific robots restrictions.
    $redirectDir = $root . '/redirect'; \Lavka\private_dir($redirectDir);
    $redirectDb = \Lavka\competitors_db($redirectDir);
    $redirectDb->exec('UPDATE competitor_targets SET enabled=0');
    $redirectTarget = \Lavka\competitors_save_target($redirectDb, ['source_id' => 'deluxe', 'kind' => 'product',
        'url' => 'https://deluxe.com.ua/public-product', 'label' => 'Redirect test']);
    $redirectCalls = [];
    $redirect = lavka_competitors_run(['data' => $redirectDir, 'seconds' => 45], function ($url) use (&$redirectCalls) {
        $redirectCalls[] = $url;
        if (strpos($url, '/robots.txt') !== false) return ['status' => 200, 'body' => "User-agent: *\nDisallow: /private-product\n"];
        return ['status' => 302, 'body' => '', 'headers' => ['location' => '/private-product']];
    }, $clock, $pause);
    runner_expect($redirect['failed'] === 1 && count($redirectCalls) === 2, 'Redirect robots denial prevents the third HTTP request');
    runner_expect(\Lavka\competitors_target($redirectDb, $redirectTarget['id'])['last_attempt']['error_code'] === 'robots_disallowed', 'Redirect denial is visible in history');

    // A long delay is persisted and deferred instead of sleeping across a run.
    $pacedDir = $root . '/paced'; \Lavka\private_dir($pacedDir);
    $pacedDb = \Lavka\competitors_db($pacedDir);
    $pacedDb->exec('UPDATE competitor_targets SET enabled=0');
    \Lavka\competitors_save_target($pacedDb, ['source_id' => 'banka', 'kind' => 'product',
        'url' => 'https://banka-speciy.in.ua/synthetic-product', 'label' => 'Pacing test']);
    $pacedCalls = [];
    $pacedTransport = function ($url) use (&$pacedCalls, $productHtml) {
        $pacedCalls[] = $url;
        return ['status' => 200, 'body' => strpos($url, '/robots.txt') !== false ? "User-agent: *\nAllow: /\nCrawl-delay: 500\n" : $productHtml];
    };
    $paced = lavka_competitors_run(['data' => $pacedDir, 'seconds' => 45], $pacedTransport, $clock, $pause);
    runner_expect($paced['checked'] === 0 && $paced['deferred'] === 1 && count($pacedCalls) === 1, 'Long crawl delay defers without fetching product');
    $again = lavka_competitors_run(['data' => $pacedDir, 'seconds' => 45], $pacedTransport, $clock, $pause);
    runner_expect($again['deferred'] === 1 && count($pacedCalls) === 1, 'A subsequent cron honors persisted source pacing');
    $now += 901;
    $later = lavka_competitors_run(['data' => $pacedDir, 'seconds' => 45], $pacedTransport, $clock, $pause);
    runner_expect($later['succeeded'] === 1 && count($pacedCalls) === 2, 'Later cron can use cached robots and complete product check');
    $catalogDir = $root . '/catalog'; \Lavka\private_dir($catalogDir);
    $catalogDb = \Lavka\competitors_db($catalogDir);
    $catalogDb->exec('UPDATE competitor_targets SET enabled=0');
    $catalogOwn = \Lavka\competitors_save_target($catalogDb, ['source_id' => 'lavka', 'kind' => 'product', 'url' => 'https://lavka-speciy.com/index.php?route=product/product&product_id=999990', 'label' => 'Own catalogue product']);
    \Lavka\competitors_save_target($catalogDb, ['source_id' => 'deluxe', 'kind' => 'product', 'url' => 'https://deluxe.com.ua/catalog-synthetic-product', 'label' => 'Public competitor']);
    \Lavka\atomic_write($catalogDir . '/own-catalog.json', \Lavka\encode(['state' => 'ok', 'last_success' => \Lavka\utc_now(), 'checked_at' => \Lavka\utc_now(), 'products' => [['target_id' => $catalogOwn['id'], 'product_id' => 999990, 'offers' => []]]]));
    file_put_contents($catalogDir . '/source.json', 'SENTINEL_NOT_VALID_JSON');
    $catalogCalls = [];
    $catalogRun = lavka_competitors_run(['data' => $catalogDir, 'seconds' => 45], function ($url) use (&$catalogCalls, $productHtml) {
        $catalogCalls[] = $url;
        return ['status' => 200, 'body' => strpos($url, '/robots.txt') !== false ? "User-agent: *\nAllow: /\n" : $productHtml];
    }, $clock, $pause);
    runner_expect($catalogRun['own_catalog']['active'] && $catalogRun['checked'] === 1 && count($catalogCalls) === 2, 'Fresh activated snapshot skips own HTTP pages and keeps normal competitor fetching');
    runner_expect(\Lavka\competitors_target($catalogDb, $catalogOwn['id'])['last_attempt'] === null && file_get_contents($catalogDir . '/source.json') === 'SENTINEL_NOT_VALID_JSON', 'Fresh catalogue does not read source settings or mutate own HTTP history');
    $catalogSeo = \Lavka\competitors_save_target($catalogDb, ['source_id' => 'lavka', 'kind' => 'product', 'url' => 'https://lavka-speciy.com/synthetic-custom-seo', 'label' => 'Own SEO link without catalogue ID']);
    runner_expect(in_array($catalogSeo['id'], array_column(\Lavka\competitors_due($catalogDb, 50, [$catalogOwn['id']]), 'id'), true), 'Own SEO pages not mapped to the catalogue keep their HTTP checks');
    $catalogDb = null;
    echo \Lavka\encode(['runner_init_lock_daily_errors' => 'passed', 'redirect_robots_and_pacing' => 'passed', 'own_catalog_http_isolation' => 'passed', 'network_requests' => 0, 'php' => PHP_VERSION]) . "\n";
} finally {
    $db = null; $redirectDb = null; $pacedDb = null;
    runner_remove($root);
}

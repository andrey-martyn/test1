<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/bin/check_competitors.php';

function diagnostic_expect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
function diagnostic_remove(string $directory): void
{
    foreach (scandir($directory) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        $path = $directory . '/' . $name;
        if (is_dir($path) && !is_link($path)) diagnostic_remove($path); else unlink($path);
    }
    rmdir($directory);
}
function diagnostic_command(array $arguments): array
{
    $parts = array_merge([PHP_BINARY, dirname(__DIR__) . '/bin/check_competitors.php'], $arguments);
    $pipes = [];
    $process = proc_open(implode(' ', array_map('escapeshellarg', $parts)), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot start diagnostic CLI');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
    return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
}
function diagnostic_database(string $directory, array $targets): void
{
    \Lavka\private_dir($directory);
    $db = new SQLite3($directory . '/competitors.sqlite3');
    $db->exec('CREATE TABLE competitor_targets(id INTEGER PRIMARY KEY,source_id TEXT,kind TEXT,url TEXT,label TEXT,enabled INTEGER,grams_override REAL,pack_label TEXT,last_attempt TEXT)');
    $stmt = $db->prepare('INSERT INTO competitor_targets VALUES(:id,:source,:kind,:url,:label,:enabled,NULL,\'\',:attempt)');
    foreach ($targets as $index => $target) {
        $stmt->bindValue(':id', $index + 1, SQLITE3_INTEGER);
        $stmt->bindValue(':source', $target[0]); $stmt->bindValue(':kind', $target[1]);
        $stmt->bindValue(':url', $target[2]); $stmt->bindValue(':label', $target[3] ?? 'Synthetic public page');
        $stmt->bindValue(':enabled', $target[4] ?? 1, SQLITE3_INTEGER);
        $stmt->bindValue(':attempt', json_encode(['state' => $target[5] ?? 'error', 'error_code' => 'price_unknown']));
        $stmt->execute()->finalize(); $stmt->reset();
    }
    $stmt->close(); $db->close();
}

$root = sys_get_temp_dir() . '/lavka-diagnostic-' . bin2hex(random_bytes(6));
\Lavka\private_dir($root);
try {
    if (PHP_VERSION_ID < 80200) {
        $cli = diagnostic_command(['--data', $root]);
        diagnostic_expect($cli['exit'] === 1 && strpos($cli['stderr'], '8.2 CLI') !== false, 'PHP 7.3 real CLI rejects before any data work');
        diagnostic_expect(!is_file($root . '/competitors.sqlite3') && !is_file($root . '/.competitors.lock'), 'Old CLI does not create monitor data or locks');
        // All structure helpers still load and execute with the web PHP syntax.
        $sample = lavka_competitor_diagnostic_document('<main><h1>Synthetic pepper</h1><span class="price">50 грн</span></main>', 'https://deluxe.com.ua/product', 'product');
        diagnostic_expect($sample['document']['h1'] === 'Synthetic pepper' && $sample['price_nodes'][0]['class'] === 'price', 'Structural helpers remain PHP 7.3 compatible');
        echo \Lavka\encode(['diagnostics_old_cli_and_helpers' => 'passed', 'network_requests' => 0, 'php' => PHP_VERSION]) . "\n";
        exit(0);
    }
    $missing = diagnostic_command(['--data', $root]);
    diagnostic_expect($missing['exit'] === 1 && !is_file($root . '/competitors.sqlite3'), 'Diagnostics never initialize a missing monitor database');
    foreach (['source.json', 'access-php.json', 'analytics.sqlite3', 'sessions-php.sqlite3'] as $name) file_put_contents($root . '/' . $name, 'PRIVATE_SENTINEL_never_print_123456789');
    $targets = [
        ['lavka', 'product', 'https://lavka-speciy.com/index.php?product_id=1214&route=product%2Fproduct'],
        ['lavka', 'product', 'https://lavka-speciy.com/synthetic-second'],
        ['banka', 'catalog', 'https://banka-speciy.in.ua/ua/novi-nadhodzhennja'],
        ['specii', 'terms', 'https://www.specii.in.ua/ua/about/dostavka-oplata/'],
        ['deluxe', 'catalog', 'https://deluxe.com.ua/disabled', 'Disabled', 0],
        ['spicery', 'product', 'https://spiceryshop.com.ua/successful', 'Successful', 1, 'ok'],
    ];
    diagnostic_database($root, $targets);
    $before = hash_file('sha256', $root . '/competitors.sqlite3');
    $product = '<html><head><title>Synthetic Triphala</title><link rel="canonical" href="https://lavka-speciy.com/triphala">'
        . '<meta property="og:url" content="https://lavka-speciy.com/triphala"><meta itemprop="price" content="0">'
        . '<meta itemprop="priceCurrency" content="UAH"><link itemprop="availability" href="https://schema.org/InStock"></head>'
        . '<body><main id="content"><h1>Synthetic Triphala 600 mg, 100 capsules</h1><ul><li class="zero-price">0 грн</li></ul>'
        . '<div class="stock">В наличии</div><div id="product"><select name="option[34]" id="input-option34">'
        . '<option value="">Choose</option><option value="92" data-price="350.0000" data-price-prefix="+" selected>1 шт. (+350 грн)</option></select>'
        . '<label><input type="radio" name="option[35]" value="93" data-price="400">2 шт. (+400 грн)</label>'
        . '<input type="hidden" name="csrf" value="HIDDEN_SECRET_never_print"><input type="text" value="customer@example.test">'
        . '</div><section class="reviews"><span class="price">REVIEW_PRIVATE_TEXT customer@example.test +380 99 123 45 67</span></section>'
        . '<script>const SECRET_SCRIPT="SCRIPT_PRIVATE_TEXT";</script><footer><span class="price">FOOTER_PRIVATE_TEXT</span></footer></main></body></html>';
    $catalog = '<html><head><title>New arrivals</title></head><body><main class="exotic-list"><h1>New arrivals</h1><nav><a href="/menu">Navigation</a></nav>'
        . '<div class="strange-tiles"><section class="fresh-commodity"><div class="visual"><a href="/ua/pepper" title="Pepper"><img src="x"></a></div>'
        . '<div class="caption"><a class="novel-name" href="/ua/pepper">Synthetic pepper 100 г</a></div><div class="cost-value" data-price="125">125 грн</div></section>'
        . '<section class="fresh-commodity"><a class="novel-name" href="/ua/cinnamon">Synthetic cinnamon 50 г</a><span class="odd-cost">90 грн</span></section></div>'
        . '<section class="comments"><a href="/ua/private-review">REVIEW_PRIVATE_TEXT</a><span>12 грн</span></section></main></body></html>';
    $calls = []; $now = 1800000000.0;
    $clock = function () use (&$now): float { return $now; };
    $pause = function (float $seconds) use (&$now): void { $now += $seconds; };
    $transport = function (string $url, array $options) use (&$calls, $product, $catalog): array {
        $calls[] = $url;
        diagnostic_expect($options['max_bytes'] === 2097152 && $options['timeout_ms'] <= 12000, 'Existing bounded HTTP transport is used');
        if (strpos($url, '/robots.txt') !== false) return ['status' => 200, 'body' => "User-agent: *\nAllow: /\n"];
        if (strpos($url, 'banka-speciy') !== false) return ['status' => 200, 'body' => $catalog];
        if (strpos($url, 'specii.in.ua') !== false) return ['status' => 200, 'body' => '<main><h1>Доставка</h1><p>Мінімальне замовлення 100 грн. Доставка поштою.</p></main>'];
        return ['status' => 200, 'body' => $product];
    };
    $lock = fopen($root . '/.competitors.lock', 'c');
    diagnostic_expect(flock($lock, LOCK_EX | LOCK_NB), 'Test holds cron lock');
    $overlap = lavka_competitors_diagnostics_run(['data' => $root], $transport, $clock, $pause);
    diagnostic_expect($overlap['reason'] === 'already_running' && !$calls, 'Diagnostic honors the existing cron lock');
    $cliOverlap = diagnostic_command(['--data', $root]);
    diagnostic_expect($cliOverlap['exit'] === 0 && json_decode($cliOverlap['stdout'], true)['reason'] === 'already_running', 'Real command honors the shared lock too');
    flock($lock, LOCK_UN); fclose($lock);
    $report = lavka_competitors_diagnostics_run(['data' => $root], $transport, $clock, $pause);
    diagnostic_expect($report['checked'] === 3 && $report['read_only'] && count($calls) === 6, 'Three diverse failed pages pass robots and public fetching');
    diagnostic_expect(array_column($report['targets'], 'kind') === ['product', 'catalog', 'terms'], 'Default diagnostic prioritizes each failed page kind');
    diagnostic_expect(array_column($report['targets'], 'id') === [1, 3, 4], 'Disabled/successful/extra product targets are not fetched');
    diagnostic_expect($before === hash_file('sha256', $root . '/competitors.sqlite3'), 'Module database is byte-for-byte unchanged');
    foreach (['source.json', 'access-php.json', 'analytics.sqlite3', 'sessions-php.sqlite3'] as $name) diagnostic_expect(file_get_contents($root . '/' . $name) === 'PRIVATE_SENTINEL_never_print_123456789', 'Other private data is untouched');
    $first = $report['targets'][0];
    diagnostic_expect($first['http']['status'] === 200 && $first['http']['bytes'] === strlen($product), 'HTTP metadata is present without the HTML body');
    diagnostic_expect($first['document']['canonical'] === 'https://lavka-speciy.com/triphala' && $first['scope']['has_h1'], 'Chosen document and main scope can be diagnosed');
    diagnostic_expect(count($first['purchase_options']) === 2 && $first['purchase_options'][0]['data-price'] === '350.0000', 'Numeric public option pricing and packs are visible');
    diagnostic_expect($first['purchase_options'][0]['selected'] && $first['purchase_options'][0]['control']['id'] === 'input-option34', 'Selected option and control shape are visible');
    diagnostic_expect($first['product_meta'][0] === ['name' => 'price', 'value' => '0'], 'Zero base price is reported as evidence without inventing an offer');
    diagnostic_expect(isset($first['parser']['error_code']) || isset($first['parser']['method']), 'Parser result is restricted to safe method/error metadata');
    $second = $report['targets'][1];
    diagnostic_expect(count($second['catalog_anchors']) === 2 && $second['catalog_anchors'][0]['class'] === 'novel-name', 'Unknown catalog markup still supplies product anchor shapes');
    diagnostic_expect($second['catalog_anchors'][0]['ancestors'][1]['class'] === 'fresh-commodity', 'Useful nearby card ancestry is present');
    $json = lavka_competitors_diagnostics_json($report);
    foreach (['PRIVATE_SENTINEL', 'HIDDEN_SECRET', 'customer@example', 'REVIEW_PRIVATE_TEXT', 'SCRIPT_PRIVATE_TEXT', 'FOOTER_PRIVATE_TEXT', 'csrf', 'body'] as $secret) {
        diagnostic_expect(strpos($json, $secret) === false, 'Diagnostic excludes private/unrelated content: ' . $secret);
    }
    diagnostic_expect(lavka_competitor_diagnostic_text('Email public@example.test phone +380 (99) 123-45-67') === 'Email [email] phone [number]', 'Free text redacts contacts defensively');
    $large = $report;
    $large['targets'] = array_merge($large['targets'], $large['targets']);
    for ($i = 0; $i < 6; $i++) {
        $large['targets'][$i]['price_nodes'] = array_fill(0, 12, ['class' => str_repeat('p', 120), 'text' => str_repeat('г', 120)]);
        $large['targets'][$i]['catalog_anchors'] = array_fill(0, 12, ['title' => str_repeat('ш', 100), 'context' => str_repeat('к', 180)]);
    }
    $bounded = lavka_competitors_diagnostics_json($large);
    diagnostic_expect(strlen($bounded) <= 16000 && json_decode($bounded, true)['truncated'], 'Entire compact JSON remains bounded and valid');

    $deny = $root . '/deny';
    diagnostic_database($deny, [['deluxe', 'product', 'https://deluxe.com.ua/denied']]);
    $denyCalls = [];
    $denied = lavka_competitors_diagnostics_run(['data' => $deny], function (string $url) use (&$denyCalls): array {
        $denyCalls[] = $url;
        return ['status' => 200, 'body' => "User-agent: *\nDisallow: /denied\n"];
    }, $clock, $pause);
    diagnostic_expect(count($denyCalls) === 1 && $denied['targets'][0]['error_code'] === 'robots_disallowed', 'Robots deny blocks the page request');
    $paced = $root . '/paced';
    diagnostic_database($paced, [['banka', 'catalog', 'https://banka-speciy.in.ua/slow']]);
    $paceCalls = [];
    $deferred = lavka_competitors_diagnostics_run(['data' => $paced], function (string $url) use (&$paceCalls): array {
        $paceCalls[] = $url;
        return ['status' => 200, 'body' => "User-agent: *\nAllow: /\nCrawl-delay: 60\n"];
    }, $clock, $pause);
    diagnostic_expect(count($paceCalls) === 1 && $deferred['deferred'] === 1 && $deferred['targets'][0]['error_code'] === 'request_deferred', 'Long crawl delay defers without fetching or sleeping');
    $pacingState = json_decode(file_get_contents($paced . '/competitor-http-cache/pacing-' . hash('sha256', 'banka-speciy.in.ua') . '.json'), true);
    diagnostic_expect($pacingState['crawl_delay'] === 60 && $pacingState['last_request_at'] === (int)$now, 'Deferred policy is remembered without inventing another request');
    $errorDir = $root . '/error';
    diagnostic_database($errorDir, [['deluxe', 'product', 'https://deluxe.com.ua/failure']]);
    $errored = lavka_competitors_diagnostics_run(['data' => $errorDir], function (string $url): array {
        if (strpos($url, '/robots.txt') !== false) return ['status' => 404, 'body' => ''];
        throw new RuntimeException('SECRET_RAW_DRIVER_ERROR');
    }, $clock, $pause);
    diagnostic_expect(strpos(lavka_competitors_diagnostics_json($errored), 'SECRET_RAW') === false, 'Raw driver errors never reach the report');
    $late=$root.'/late';$lateRows=[];
    for($i=1;$i<=400;++$i)$lateRows[]=['lavka','product','https://lavka-speciy.com/index.php?route=product/product&product_id='.$i,'Synthetic own item',1,'ok'];
    $lateRows[]=['deluxe','product','https://deluxe.com.ua/late-item','Synthetic remote item',1,'ok'];
    diagnostic_database($late,$lateRows);
    $selected=lavka_competitors_diagnostics_run(['data'=>$late,'limit'=>1,'targets'=>'401'],$transport,$clock,$pause);
    diagnostic_expect($selected['selected']===1&&$selected['targets'][0]['id']===401,'Explicit page beyond 350 catalogue rows is collected even without a failed attempt');
    $invalidTargets=diagnostic_command(['--data',$root,'--targets','1,2;delete']);
    diagnostic_expect($invalidTargets['exit']===1,'Target IDs accept no arbitrary SQL or paths');
    $save=$root.'/save-report';diagnostic_database($save,[]);
    $saveBefore=hash_file('sha256',$save.'/competitors.sqlite3');
    $savedCli=diagnostic_command(['--data',$save,'--save-report']);
    diagnostic_expect($savedCli['exit']===0&&is_file($save.'/public-page-diagnostics.json'),'Explicit save option writes the fixed support file');
    diagnostic_expect($saveBefore===hash_file('sha256',$save.'/competitors.sqlite3'),'Saving support data does not mutate the monitor DB');
    diagnostic_expect((fileperms($save.'/public-page-diagnostics.json')&0777)===0600,'Saved support file stays private');
    diagnostic_expect(json_decode(file_get_contents($save.'/public-page-diagnostics.json'),true)['schema']==='lavka-public-pages-v1','Support file has a versioned format');
    $invalid = diagnostic_command(['--data', $root, '--limit', '6']);
    diagnostic_expect($invalid['exit'] === 1 && strpos($invalid['stderr'], '1..5') !== false, 'Real CLI rejects excessive request limits');
    echo \Lavka\encode(['diagnostics_read_only_structure_privacy' => 'passed', 'robots_pacing_lock_bounds' => 'passed', 'network_requests' => 0, 'php' => PHP_VERSION]) . "\n";
} finally { diagnostic_remove($root); }

<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/src/CompetitorSandboxJobs.php';

function sj_expect(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function sj_error(string $code, callable $operation): void
{
    try { $operation(); }
    catch (\Lavka\SandboxPricingError $error) { sj_expect($error->errorCode === $code, 'Expected ' . $code . ', got ' . $error->errorCode); return; }
    throw new RuntimeException('Expected ' . $code);
}
function sj_remove(string $directory): void
{
    foreach (scandir($directory) ?: [] as $name) if ($name !== '.' && $name !== '..') {
        $path = $directory . '/' . $name;
        if (is_dir($path) && !is_link($path)) sj_remove($path); else unlink($path);
    }
    rmdir($directory);
}
function sj_fixture(string $base): string
{
    $old = $base . '/analytics-private'; $data = $base . '/analytics-private-test'; $shop = $base . '/test';
    foreach ([$base, $old, $data, $shop . '/admin', $shop . '/storage'] as $path) \Lavka\private_dir($path);
    $config = "<?php\ndefine('DB_DATABASE','lavkasp_test');\ndefine('DB_PREFIX','oc_');\ndefine('DB_HOSTNAME','127.0.0.1');\ndefine('DB_PORT','3306');\ndefine('HTTP_SERVER','https://test.lavka-speciy.com/');\ndefine('HTTPS_SERVER','https://test.lavka-speciy.com/');\n";
    $config .= 'define(\'DIR_STORAGE\',' . var_export($shop . '/storage/', true) . ");\n";
    file_put_contents($shop . '/config.php', $config);
    file_put_contents($shop . '/admin/config.php', $config . "define('HTTP_CATALOG','https://test.lavka-speciy.com/');\ndefine('HTTPS_CATALOG','https://test.lavka-speciy.com/');\n");
    \Lavka\atomic_write($data . '/sandbox.json', \Lavka\encode(['environment' => 'sandbox', 'database' => 'lavkasp_test', 'allowed_shop_database' => 'lavkasp_test',
        'source_user' => 'lavkasp_sandbox', 'writes_enabled' => true, 'test_store_verified' => true, 'data_dir' => $data,
        'previous_data_dir' => $old, 'shop_root' => $shop, 'admin_config' => $shop . '/admin/config.php', 'public_url' => 'https://test.lavka-speciy.com/analytics']));
    \Lavka\atomic_write($data . '/source.json', \Lavka\encode(['host' => '127.0.0.1', 'port' => 3306, 'database' => 'lavkasp_test',
        'user' => 'lavkasp_sandbox', 'password' => 'PRIVATE_NO_MYSQL_ALLOWED', 'prefix' => 'oc_']));
    return $data;
}

$root = sys_get_temp_dir() . '/lavka-sandbox-jobs-' . bin2hex(random_bytes(6)); \Lavka\private_dir($root);
try {
    $data = sj_fixture($root . '/main'); $path = $data . '/sandbox-price-jobs.sqlite3';
    sj_expect(\Lavka\sandbox_price_jobs_recent($data) === [] && !file_exists($path), 'Read-only recent polling never creates a queue');
    sj_error('sandbox_job_not_found', function () use ($data) { \Lavka\sandbox_price_jobs_status($data, str_repeat('a', 32)); });
    sj_expect(!file_exists($path), 'Unknown job polling never creates a queue');
    $request = ['selections' => [['target_id' => 990, 'base_50' => '120.00']]];
    foreach ([['shell', $request, 'sandbox_job_invalid'], ['plan', ['selections' => []], 'sandbox_selection_limit'],
        ['plan', ['selections' => [['target_id' => '990', 'base_50' => '1']]], 'sandbox_target_invalid'],
        ['plan', ['selections' => [['target_id' => 990, 'base_50' => '1.001']]], 'invalid_base_price'],
        ['plan', $request + ['database' => 'lavkasp_lavka'], 'sandbox_job_invalid'],
        ['apply', ['plan_id' => str_repeat('a', 32), 'plan_hash' => str_repeat('b', 64), 'database' => 'lavkasp_lavka'], 'sandbox_plan_invalid'],
        ['rollback', ['plan_id' => '../x', 'plan_hash' => 'bad'], 'sandbox_plan_invalid']] as $invalid) {
        sj_error($invalid[2], function () use ($data, $invalid) { \Lavka\sandbox_price_jobs_enqueue($data, $invalid[0], $invalid[1]); });
    }
    sj_expect(!file_exists($path), 'Invalid operations and injected selectors fail before queue creation');
    $queued = \Lavka\sandbox_price_jobs_enqueue($data, 'plan', $request); $id = $queued['job_id'];
    sj_expect($queued['state'] === 'queued' && preg_match('/^[a-f0-9]{32}$/D', $id) && $queued['database'] === 'lavkasp_test', 'PHP web queues an opaque immutable job');
    $retry = \Lavka\sandbox_price_jobs_enqueue($data, 'plan', $request);
    $queue = \Lavka\sandbox_price_jobs_database($data);
    sj_expect($retry['job_id'] === $id && (int) $queue->query('SELECT COUNT(*) FROM sandbox_price_jobs')->fetchColumn() === 1, 'A lost enqueue response can be retried without a duplicate pending job');
    $queue->prepare("UPDATE sandbox_price_jobs SET state='running' WHERE id=?")->execute([$id]);
    sj_expect(\Lavka\sandbox_price_jobs_enqueue($data, 'plan', $request)['job_id'] === $id, 'An identical retry also joins the running job');
    $queue->prepare("UPDATE sandbox_price_jobs SET state='queued' WHERE id=?")->execute([$id]);
    $request['selections'][0]['base_50'] = '0';
    $status = \Lavka\sandbox_price_jobs_status($data, $id);
    sj_expect($status['state'] === 'queued' && !isset($status['request_payload'], $status['result']), 'Status polling cannot execute or expose request payload');
    $recent = \Lavka\sandbox_price_jobs_recent($data);
    sj_expect(count($recent) === 1 && array_keys($recent[0]) === ['job_id', 'operation', 'state', 'created_at', 'updated_at'], 'Recent requests contain no payload, plan hash, or result');
    sj_expect((fileperms($path) & 0777) === 0600, 'Queue is private');
    if (PHP_VERSION_ID < 80200) {
        $called = false;
        sj_error('requires_php82_cli', function () use ($data, &$called) { \Lavka\sandbox_price_jobs_run($data, 3, 45, function () use (&$called) { $called = true; return []; }); });
        sj_expect(!$called && \Lavka\sandbox_price_jobs_status($data, $id)['state'] === 'queued', 'PHP 7.3 only enqueues and polls; it never executes a price operation');
        echo \Lavka\encode(['sandbox_job_web_queue' => 'passed', 'php73_executor_rejected' => 'passed', 'mysql_connections' => 0, 'php' => PHP_VERSION]) . "\n";
        exit(0);
    }

    $calls = []; $executor = function (string $operation, array $payload, string $directory) use (&$calls, $data): array {
        sj_expect($directory === $data, 'Executor receives the fixed configured sandbox path'); $calls[] = [$operation, $payload];
        return ['state' => $operation === 'plan' ? 'planned' : ($operation === 'apply' ? 'applied' : 'rolled_back'), 'database' => 'lavkasp_test',
            'plan_id' => str_repeat('a', 32), 'plan_hash' => str_repeat('b', 64)];
    };
    $db = \Lavka\sandbox_price_jobs_database($data); $before = $db->query('SELECT request_payload FROM sandbox_price_jobs')->fetchColumn();
    $result = \Lavka\sandbox_price_jobs_run($data, 3, 45, $executor);
    sj_expect($result['succeeded'] === 1 && $calls[0][1]['selections'][0]['base_50'] === '120.00', 'CLI executes the saved reviewed request, unaffected by later client changes');
    sj_expect($db->query('SELECT request_payload FROM sandbox_price_jobs')->fetchColumn() === $before, 'Worker never rewrites the request payload');
    $done = \Lavka\sandbox_price_jobs_status($data, $id);
    sj_expect($done['state'] === 'done' && $done['result']['state'] === 'planned', 'Polling retrieves only the completed public operation result');
    \Lavka\sandbox_price_jobs_run($data, 3, 45, $executor); sj_expect(count($calls) === 1, 'Done jobs never replay automatically');

    $apply = \Lavka\sandbox_price_jobs_enqueue($data, 'apply', ['plan_id' => str_repeat('a', 32), 'plan_hash' => str_repeat('b', 64)]);
    $rollback = \Lavka\sandbox_price_jobs_enqueue($data, 'rollback', ['plan_id' => str_repeat('a', 32), 'plan_hash' => str_repeat('b', 64)]);
    \Lavka\sandbox_price_jobs_run($data, 1, 45, $executor);
    sj_expect(\Lavka\sandbox_price_jobs_status($data, $apply['job_id'])['state'] === 'done' && \Lavka\sandbox_price_jobs_status($data, $rollback['job_id'])['state'] === 'queued', 'Worker respects its packet limit');
    \Lavka\sandbox_price_jobs_run($data, 3, 45, $executor);
    sj_expect(array_column($calls, 0) === ['plan', 'apply', 'rollback'], 'CLI dispatch preserves operation boundaries');

    $expired = \Lavka\sandbox_price_jobs_enqueue($data, 'plan', ['selections' => [['target_id' => 990, 'base_50' => '1']]]);
    $interrupted = \Lavka\sandbox_price_jobs_enqueue($data, 'apply', ['plan_id' => str_repeat('a', 32), 'plan_hash' => str_repeat('b', 64)]);
    $db->prepare("UPDATE sandbox_price_jobs SET expires_at='2000-01-01T00:00:00Z' WHERE id=?")->execute([$expired['job_id']]);
    $db->prepare("UPDATE sandbox_price_jobs SET state='running' WHERE id=?")->execute([$interrupted['job_id']]);
    $callsBefore = count($calls); $run = \Lavka\sandbox_price_jobs_run($data, 3, 45, $executor);
    sj_expect(count($calls) === $callsBefore && $run['interrupted'] === 1, 'Expired jobs and interrupted writes never replay');
    sj_expect(\Lavka\sandbox_price_jobs_status($data, $expired['job_id'])['error_code'] === 'sandbox_job_expired', 'Queued requests expire after ten minutes');
    sj_expect(\Lavka\sandbox_price_jobs_status($data, $interrupted['job_id'])['error_code'] === 'sandbox_job_interrupted', 'Interrupted operations require manual review of the durable pricing journal');

    $bad = \Lavka\sandbox_price_jobs_enqueue($data, 'plan', ['selections' => [['target_id' => 990, 'base_50' => '1']]]);
    \Lavka\sandbox_price_jobs_run($data, 3, 45, function () { throw new PDOException('PRIVATE_MYSQL_PASSWORD_ERROR'); });
    $badStatus = \Lavka\sandbox_price_jobs_status($data, $bad['job_id']);
    sj_expect($badStatus['state'] === 'error' && $badStatus['error_code'] === 'sandbox_job_failed' && strpos(\Lavka\encode($badStatus), 'PRIVATE') === false, 'Driver errors are reduced to a fixed safe code');
    $safe = \Lavka\sandbox_price_jobs_enqueue($data, 'plan', ['selections' => [['target_id' => 990, 'base_50' => '1']]]);
    \Lavka\sandbox_price_jobs_run($data, 3, 45, function () { throw new \Lavka\SandboxPricingError('sandbox_plan_stale'); });
    sj_expect(\Lavka\sandbox_price_jobs_status($data, $safe['job_id'])['error_code'] === 'sandbox_plan_stale', 'Public backend validation codes survive the queue');

    for ($i = 0; $i < 10; $i++) \Lavka\sandbox_price_jobs_enqueue($data, 'plan', ['selections' => [['target_id' => $i + 1, 'base_50' => '1']]]);
    sj_error('sandbox_job_queue_full', function () use ($data) { \Lavka\sandbox_price_jobs_enqueue($data, 'plan', ['selections' => [['target_id' => 11, 'base_50' => '1']]]); });
    sj_expect(count(\Lavka\sandbox_price_jobs_recent($data)) === 5, 'Recent requests are bounded to five metadata entries');
    $lock = fopen($data . '/.sandbox-jobs.lock', 'c'); flock($lock, LOCK_EX | LOCK_NB);
    $skip = \Lavka\sandbox_price_jobs_run($data, 3, 45, $executor);
    sj_expect($skip['state'] === 'skipped' && count($calls) === $callsBefore, 'A second worker cannot overlap the current worker');
    flock($lock, LOCK_UN); fclose($lock);
    sj_error('sandbox_job_worker_limits', function () use ($data) { \Lavka\sandbox_price_jobs_run($data, 4, 45); });
    sj_error('sandbox_job_worker_limits', function () use ($data) { \Lavka\sandbox_price_jobs_run($data, 1, 46); });
    $source = file_get_contents($data . '/source.json'); file_put_contents($data . '/source.json', str_replace('lavkasp_test', 'lavkasp_lavka', $source));
    sj_error('sandbox_source_forbidden', function () use ($data, $id) { \Lavka\sandbox_price_jobs_status($data, $id); });
    sj_error('sandbox_source_forbidden', function () use ($data, $executor) { \Lavka\sandbox_price_jobs_run($data, 3, 45, $executor); });
    sj_expect(count($calls) === $callsBefore, 'A changed source configuration blocks every queued operation before execution');
    echo \Lavka\encode(['sandbox_job_web_queue' => 'passed', 'php82_worker_dispatch' => 'passed', 'expiry_recovery_limits' => 'passed', 'immutable_payload_and_secret_isolation' => 'passed', 'mysql_connections' => 0, 'php' => PHP_VERSION]) . "\n";
} finally { sj_remove($root); }

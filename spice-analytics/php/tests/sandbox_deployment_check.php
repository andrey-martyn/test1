<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/src/SandboxDeployment.php';

function deployment_expect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
function deployment_rejects(callable $operation, string $message): void
{
    try { $operation(); }
    catch (Throwable $error) {
        deployment_expect(strpos($error->getMessage(), 'SYNTHETIC_PASSWORD') === false, 'Passwords never appear in errors');
        return;
    }
    throw new RuntimeException('Expected rejection: ' . $message);
}
function deployment_remove(string $directory): void
{
    foreach (scandir($directory) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        $path = $directory . '/' . $name;
        if (is_dir($path) && !is_link($path)) deployment_remove($path); else unlink($path);
    }
    rmdir($directory);
}
function deployment_store_config(string $shop, bool $admin = false): string
{
    $base = $admin ? 'https://test.lavka-speciy.com/admin/' : 'https://test.lavka-speciy.com/';
    $values = ['DB_DATABASE' => 'lavkasp_test', 'DB_PREFIX' => 'oc_', 'DB_HOSTNAME' => '127.0.0.1', 'DB_PORT' => '3306',
        'DB_PASSWORD' => 'SYNTHETIC_PASSWORD_STORE', 'HTTP_SERVER' => $base, 'HTTPS_SERVER' => $base,
        'DIR_STORAGE' => $shop . '/storage/', 'DIR_APPLICATION' => $shop . ($admin ? '/admin/' : '/catalog/'),
        'DIR_SYSTEM' => $shop . '/system/', 'DIR_IMAGE' => $shop . '/image/'];
    if ($admin) $values += ['HTTP_CATALOG' => 'https://test.lavka-speciy.com/', 'HTTPS_CATALOG' => 'https://test.lavka-speciy.com/'];
    $text = "<?php\n";
    foreach ($values as $name => $value) $text .= 'define(' . var_export($name, true) . ', ' . var_export($value, true) . ");\n";
    return $text . "define('DIR_CACHE', DIR_STORAGE . 'cache/');\ndefine('DIR_LOGS', DIR_STORAGE . 'logs/');\n";
}
function deployment_fixture(string $base): array
{
    $old = $base . '/analytics-private'; $data = $base . '/analytics-private-test'; $shop = $base . '/test';
    $app = $base . '/application'; $webroot = $shop . '/analytics';
    foreach ([$base, $old, $data, $shop, $shop . '/admin', $shop . '/storage', $shop . '/catalog', $shop . '/system', $shop . '/image', $app, $app . '/public', $webroot, $webroot . '/static'] as $directory) {
        if (!is_dir($directory)) mkdir($directory, 0755, true);
        chmod($directory, in_array($directory, [$old, $data], true) ? 0700 : 0755);
    }
    $url = 'https://test.lavka-speciy.com/analytics';
    file_put_contents($shop . '/config.php', deployment_store_config($shop));
    file_put_contents($shop . '/admin/config.php', deployment_store_config($shop, true));
    file_put_contents($shop . '/index.php', '<?php echo "EXISTING_TEST_SHOP";');
    file_put_contents($shop . '/.htaccess', '# Existing shop rules');
    file_put_contents($webroot . '/.htaccess', '# Existing analytics rules');
    file_put_contents($webroot . '/static/example.js', '/* existing public asset */');
    file_put_contents($app . '/public/index.php', '<?php /* synthetic application entry */');
    file_put_contents($old . '/source.json', \Lavka\encode(['database' => 'lavkasp_lavka', 'password' => 'SYNTHETIC_PASSWORD_PRODUCTION']));
    file_put_contents($old . '/access-php.json', '{"preserved":"original password hash"}');
    file_put_contents($old . '/analytics.sqlite3', 'SYNTHETIC_OLD_CRM_AND_ORDERS');
    file_put_contents($data . '/access-php.json', file_get_contents($old . '/access-php.json'));
    $source = ['host' => '127.0.0.1', 'port' => 3306, 'unix_socket' => '', 'database' => 'lavkasp_test', 'prefix' => 'oc_',
        'user' => 'lavkasp_sandbox', 'password' => 'SYNTHETIC_PASSWORD_TEST', 'ssl_ca' => ''];
    \Lavka\atomic_write($data . '/source.json', \Lavka\encode($source));
    $marker = ['environment' => 'sandbox', 'database' => 'lavkasp_test', 'allowed_shop_database' => 'lavkasp_test',
        'source_user' => 'lavkasp_sandbox', 'data_dir' => $data, 'previous_data_dir' => $old, 'shop_root' => $shop,
        'public_url' => $url, 'writes_enabled' => true, 'test_store_verified' => true, 'admin_config' => $shop . '/admin/config.php'];
    \Lavka\atomic_write($data . '/sandbox.json', \Lavka\encode($marker));
    foreach ([$old, $data] as $directory) \Lavka\atomic_write($directory . '/deployment-php.json', \Lavka\encode([
        'application_root' => $app, 'document_root' => $webroot, 'shop_root' => $shop, 'data_dir' => $directory, 'public_url' => $url]));
    $orders = new SQLite3($data . '/analytics.sqlite3');
    $orders->exec('CREATE TABLE sync_status(id INTEGER PRIMARY KEY,payload TEXT NOT NULL)');
    $stmt = $orders->prepare('INSERT INTO sync_status VALUES(1,?)');
    $stmt->bindValue(1, \Lavka\encode(['state' => 'ok', 'source_database' => 'lavkasp_test', 'environment' => 'sandbox', 'last_success' => \Lavka\utc_now()]));
    $stmt->execute()->finalize(); $stmt->close(); $orders->close();
    $catalog = ['state' => 'ok', 'source_database' => 'lavkasp_test', 'environment' => 'sandbox', 'last_success' => \Lavka\utc_now(),
        'checked_at' => \Lavka\utc_now(), 'products' => []];
    \Lavka\atomic_write($data . '/own-catalog.json', \Lavka\encode($catalog));
    $oldLauncher = \Lavka\sandbox_deployment_launcher($app, $old, $url);
    file_put_contents($webroot . '/index.php', $oldLauncher); chmod($webroot . '/index.php', 0644);
    return compact('base', 'old', 'data', 'shop', 'app', 'webroot', 'url', 'source', 'marker', 'catalog', 'oldLauncher');
}
function deployment_preflight(array $fixture, ?callable $checker = null): array
{
    return \Lavka\sandbox_deployment_preflight($fixture['app'], $fixture['webroot'], $fixture['data'], $fixture['url'], $fixture['shop'],
        $checker ?: function ($directory) use ($fixture): array {
            deployment_expect($directory === $fixture['data'], 'Checker receives only the sandbox directory');
            return ['state' => 'ok', 'database' => 'lavkasp_test'];
        });
}
function deployment_protected(array $fixture): array
{
    $paths = [$fixture['old'] . '/source.json', $fixture['old'] . '/access-php.json', $fixture['old'] . '/analytics.sqlite3',
        $fixture['old'] . '/deployment-php.json', $fixture['data'] . '/access-php.json', $fixture['data'] . '/source.json',
        $fixture['shop'] . '/config.php', $fixture['shop'] . '/admin/config.php', $fixture['shop'] . '/index.php',
        $fixture['shop'] . '/.htaccess', $fixture['webroot'] . '/.htaccess', $fixture['webroot'] . '/static/example.js'];
    $result = [];
    foreach ($paths as $path) $result[$path] = hash_file('sha256', $path);
    return $result;
}
function deployment_change_file_rejected(array $fixture, string $path, string $value, string $message): void
{
    $old = file_get_contents($path); $launcher = file_get_contents($fixture['webroot'] . '/index.php');
    try { file_put_contents($path, $value); deployment_rejects(function () use ($fixture) { deployment_preflight($fixture); }, $message); }
    finally { file_put_contents($path, $old); }
    deployment_expect(file_get_contents($fixture['webroot'] . '/index.php') === $launcher, 'Rejected preflight never switches the launcher: ' . $message);
}

$root = sys_get_temp_dir() . '/lavka-sandbox-deploy-' . bin2hex(random_bytes(6));
mkdir($root, 0700);
try {
    $fixture = deployment_fixture($root . '/main');
    $protected = deployment_protected($fixture);
    deployment_expect(\Lavka\analytics_environment_summary($fixture['old'])['mode'] === 'read_only', 'Original cabinet stays read only without reading its source password');
    $summary = \Lavka\analytics_environment_summary($fixture['data']);
    deployment_expect($summary['mode'] === 'sandbox' && $summary['database'] === 'lavkasp_test', 'Sandbox banner uses validated local metadata');
    deployment_expect(strpos(\Lavka\encode($summary), 'SYNTHETIC_PASSWORD') === false, 'Environment summary contains no credentials');
    \Lavka\sandbox_assert_source($fixture['data'], $fixture['source'], $fixture['data'] . '/source.json');
    deployment_rejects(function () use ($fixture) { \Lavka\sandbox_assert_source($fixture['data'], $fixture['source'], $fixture['old'] . '/source.json'); }, 'Different --config cannot redirect sandbox synchronization');
    deployment_rejects(function () use ($fixture) { $source = $fixture['source']; $source['database'] = 'lavkasp_lavka'; \Lavka\sandbox_assert_source($fixture['data'], $source, $fixture['data'] . '/source.json'); }, 'Source values must match the dedicated sandbox file');
    $polluted = $fixture['catalog']; $polluted['source_database'] = 'lavkasp_lavka'; $polluted['products'] = [['product_id' => 99, 'offers' => [['price' => '1']]]];
    \Lavka\atomic_write($fixture['data'] . '/own-catalog.json', \Lavka\encode($polluted));
    $read = \Lavka\competitors_own_catalog_read($fixture['data']);
    deployment_expect($read['state'] === 'error' && $read['products'] === [] && $read['last_success'] === null, 'Sandbox rejects a copied production price snapshot instead of showing retained production prices');
    \Lavka\atomic_write($fixture['data'] . '/own-catalog.json', \Lavka\encode($fixture['catalog']));

    if (PHP_VERSION_ID < 80200) {
        $called = false;
        deployment_rejects(function () use ($fixture, &$called) { deployment_preflight($fixture, function () use (&$called) { $called = true; return ['state' => 'ok', 'database' => 'lavkasp_test']; }); }, 'Old PHP cannot perform the switch');
        deployment_expect(!$called && deployment_protected($fixture) === $protected && file_get_contents($fixture['webroot'] . '/index.php') === $fixture['oldLauncher'], 'PHP 7.3 loads runtime guards but never switches or connects');
        echo \Lavka\encode(['sandbox_runtime_guards' => 'passed', 'old_cli_switch_rejected' => 'passed', 'network_requests' => 0, 'php' => PHP_VERSION]) . "\n";
        exit(0);
    }

    deployment_change_file_rejected($fixture, $fixture['webroot'] . '/index.php', '<?php /* custom launcher */', 'Unknown public launcher');
    deployment_change_file_rejected($fixture, $fixture['shop'] . '/config.php', str_replace("'lavkasp_test'", "'lavkasp_lavka'", deployment_store_config($fixture['shop'])), 'Root store points at production');
    deployment_change_file_rejected($fixture, $fixture['shop'] . '/admin/config.php', str_replace("'lavkasp_test'", "'lavkasp_lavka'", deployment_store_config($fixture['shop'], true)), 'Admin store points at production');
    $invalidSource = $fixture['source']; $invalidSource['database'] = 'lavkasp_lavka';
    deployment_change_file_rejected($fixture, $fixture['data'] . '/source.json', \Lavka\encode($invalidSource), 'Source points at production');
    $invalidMarker = $fixture['marker']; $invalidMarker['test_store_verified'] = false;
    deployment_change_file_rejected($fixture, $fixture['data'] . '/sandbox.json', \Lavka\encode($invalidMarker), 'Unverified marker');
    $invalidMarker = $fixture['marker']; $invalidMarker['previous_data_dir'] = $fixture['shop'];
    deployment_change_file_rejected($fixture, $fixture['data'] . '/sandbox.json', \Lavka\encode($invalidMarker), 'Wrong isolation siblings');
    deployment_change_file_rejected($fixture, $fixture['data'] . '/own-catalog.json', \Lavka\encode($polluted), 'Production catalogue cache');
    deployment_change_file_rejected($fixture, $fixture['data'] . '/deployment-php.json', \Lavka\encode(['data_dir' => $fixture['old']]), 'New deployment metadata mismatch');
    $orders = new SQLite3($fixture['data'] . '/analytics.sqlite3');
    $originalStatus = $orders->querySingle('SELECT payload FROM sync_status WHERE id=1');
    $orders->exec("UPDATE sync_status SET payload='" . SQLite3::escapeString(\Lavka\encode(['state' => 'ok', 'source_database' => 'lavkasp_lavka', 'environment' => 'read_only'])) . "' WHERE id=1");
    deployment_rejects(function () use ($fixture) { deployment_preflight($fixture); }, 'Production sales snapshot');
    $orders->exec("UPDATE sync_status SET payload='" . SQLite3::escapeString($originalStatus) . "' WHERE id=1"); $orders->close();
    foreach (['analytics.sqlite3', 'own-catalog.json'] as $name) {
        rename($fixture['data'] . '/' . $name, $fixture['data'] . '/' . $name . '.held');
        try { deployment_rejects(function () use ($fixture) { deployment_preflight($fixture); }, 'Missing ' . $name); }
        finally { rename($fixture['data'] . '/' . $name . '.held', $fixture['data'] . '/' . $name); }
    }
    deployment_rejects(function () use ($fixture) { deployment_preflight($fixture, function () { return ['state' => 'blocked', 'database' => 'lavkasp_test']; }); }, 'Read-only source check failed');
    deployment_rejects(function () use ($fixture) { deployment_preflight($fixture, function () { return ['state' => 'ok', 'database' => 'lavkasp_lavka']; }); }, 'Source check reports a different database');
    deployment_expect(deployment_protected($fixture) === $protected && file_get_contents($fixture['webroot'] . '/index.php') === $fixture['oldLauncher'], 'All rejected preflights preserve existing app, private data, shop files and password');

    $plan = deployment_preflight($fixture);
    deployment_expect(!$plan['already_switched'] && deployment_protected($fixture) === $protected, 'Successful preflight is read-only');
    $result = \Lavka\sandbox_deployment_commit($plan);
    deployment_expect($result['switched'] && $result['database'] === 'lavkasp_test', 'Commit points the existing cabinet at sandbox only');
    deployment_expect(file_get_contents($fixture['webroot'] . '/index.php') === \Lavka\sandbox_deployment_launcher($fixture['app'], $fixture['data'], $fixture['url']), 'New launcher has exactly the intended data directory');
    deployment_expect(file_get_contents($fixture['data'] . '/before-sandbox-launcher.php') === $fixture['oldLauncher'], 'Previous known launcher is backed up privately');
    clearstatcache();
    deployment_expect((fileperms($fixture['webroot'] . '/index.php') & 0777) === 0644 && (fileperms($fixture['webroot']) & 0777) === 0755
        && (fileperms($fixture['data'] . '/before-sandbox-launcher.php') & 0777) === 0600, 'Public launcher stays traversable and private backup stays protected');
    deployment_expect(deployment_protected($fixture) === $protected, 'Switch changes no store configuration, old source, authentication or CRM bytes');
    $again = deployment_preflight($fixture);
    deployment_expect($again['already_switched'] && \Lavka\sandbox_deployment_commit($again)['switched'], 'Repeated reviewed switch is idempotent');
    deployment_expect(deployment_protected($fixture) === $protected, 'Repeated switch preserves original data');

    $raced = deployment_fixture($root . '/race'); $stale = deployment_preflight($raced);
    $external = '<?php /* changed by administrator after review */';
    file_put_contents($raced['webroot'] . '/index.php', $external);
    deployment_rejects(function () use ($stale) { \Lavka\sandbox_deployment_commit($stale); }, 'Launcher changed after preflight');
    deployment_expect(file_get_contents($raced['webroot'] . '/index.php') === $external && !file_exists($raced['data'] . '/before-sandbox-launcher.php'), 'CAS does not overwrite administrator changes');
    $linked = deployment_fixture($root . '/symlink'); $linkedPlan = deployment_preflight($linked);
    symlink($linked['old'] . '/source.json', $linked['data'] . '/before-sandbox-launcher.php');
    $protectedLink = deployment_protected($linked);
    deployment_rejects(function () use ($linkedPlan) { \Lavka\sandbox_deployment_commit($linkedPlan); }, 'Symlink backup');
    deployment_expect(deployment_protected($linked) === $protectedLink && file_get_contents($linked['webroot'] . '/index.php') === $linked['oldLauncher'], 'Symlink cannot redirect a backup into production credentials');
    deployment_expect(count(glob($fixture['webroot'] . '/.sandbox-launcher-*') ?: []) === 0, 'No temporary public launchers remain');
    echo \Lavka\encode(['sandbox_runtime_guards' => 'passed', 'sandbox_switch_preflight_and_atomic_launcher' => 'passed',
        'production_private_and_shop_preserved' => 'passed', 'cas_symlink_and_idempotency' => 'passed', 'network_requests' => 0, 'php' => PHP_VERSION]) . "\n";
} finally { deployment_remove($root); }

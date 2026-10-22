<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/bin/setup_sandbox.php';
require_once dirname(__DIR__) . '/src/Auth.php';

function setup_expect(bool $value, string $message): void { if (!$value) throw new RuntimeException($message); }
function setup_rejects(string $code, callable $operation): void
{
    try { $operation(); }
    catch (Throwable $error) { setup_expect($error->getMessage() === $code, 'Expected ' . $code . ', got ' . $error->getMessage()); return; }
    throw new RuntimeException('Expected rejection: ' . $code);
}
function setup_remove(string $directory): void
{
    foreach (scandir($directory) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        $path = $directory . '/' . $name;
        if (is_dir($path) && !is_link($path)) setup_remove($path); else unlink($path);
    }
    rmdir($directory);
}
function setup_store_php(bool $admin = false, ?string $storage = null): string
{
    $storage = $storage ?? ($GLOBALS['sandbox_fixture_storage'] ?? '/tmp/sandbox-test/storage/');
    return "<?php\n// define('DB_DATABASE', 'lavkasp_lavka');\n"
        . "define('DB_DATABASE', 'lavkasp_test');\ndefine('DB_PREFIX', 'oc_');\ndefine('DB_HOSTNAME', '127.0.0.1');\ndefine('DB_PORT', '3306');\n"
        . "define('DB_PASSWORD', 'STORE_SECRET_NOT_RETURNED');\n"
        . "define('DIR_STORAGE', '" . $storage . "');\n"
        . "define('DIR_CACHE', DIR_STORAGE . 'cache/');\ndefine('DIR_LOGS', DIR_STORAGE . 'logs/');\n"
        . "define('DIR_SESSION', DIR_STORAGE . 'session/');\ndefine('DIR_MODIFICATION', DIR_STORAGE . 'modification/');\n"
        . "define('HTTP_SERVER', 'http://test.lavka-speciy.com/" . ($admin ? 'secret-admin/' : '') . "');\n"
        . "define('HTTPS_SERVER', 'https://test.lavka-speciy.com/" . ($admin ? 'secret-admin/' : '') . "');\n"
        . ($admin ? "define('HTTP_CATALOG', 'http://test.lavka-speciy.com/');\ndefine('HTTPS_CATALOG', 'https://test.lavka-speciy.com/');\n" : '');
}
function setup_grants(): array
{
    return ["GRANT USAGE ON *.* TO 'lavkasp_sandbox'@'localhost' IDENTIFIED BY PASSWORD 'AUTHENTICATION_MATERIAL_NEVER_PRINT'",
        "GRANT SELECT ON `lavkasp_test`.* TO 'lavkasp_sandbox'@'localhost'",
        "GRANT UPDATE (`price`) ON `lavkasp_test`.`oc_product` TO 'lavkasp_sandbox'@'localhost'",
        "GRANT UPDATE (`price`, `price_prefix`) ON `lavkasp_test`.`oc_product_option_value` TO 'lavkasp_sandbox'@'localhost'"];
}

/** Query-only SQLite executes the real fixed SELECTs; identity statements are explicit fixtures. */
class SandboxSetupSource extends PDO
{
    public $databaseName = 'lavkasp_test';
    public $currentIdentity = 'lavkasp_sandbox@localhost';
    public $grants;
    public $tableType = 'BASE TABLE';
    public $triggerCount = '0';
    public $externalFkCount = '0';
    public $auditUser = 'lavkasp_test@localhost';
    public $queries = [];
    public function __construct()
    {
        parent::__construct('sqlite::memory:');
        $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->grants = setup_grants();
        $schema = \Lavka\SOURCE_FIELDS;
        foreach (\Lavka\COMPETITOR_CATALOG_FIELDS as $table => $fields) $schema['oc_' . ($table === 'setting' ? 'analytics_catalog_settings' : $table)] = $fields;
        foreach ($schema as $table => $fields) parent::exec('CREATE TABLE `' . $table . '` (' . implode(',', array_map(function ($field) { return '`' . $field . '` TEXT'; }, $fields)) . ')');
        foreach (['config_currency' => 'UAH', 'config_language' => 'uk-ua', 'config_customer_group_id' => '1', 'config_tax' => '0'] as $key => $value) {
            parent::exec('INSERT INTO oc_analytics_catalog_settings VALUES(0,' . $this->quote($key) . ',' . $this->quote($value) . ',0)');
        }
        parent::exec('PRAGMA query_only=ON');
    }
    #[\ReturnTypeWillChange]
    public function query($query, ...$arguments)
    {
        $this->queries[] = $query;
        if ($query === 'SELECT DATABASE()') return parent::query('SELECT ' . $this->quote($this->databaseName));
        if ($query === 'SELECT CURRENT_USER()') return parent::query('SELECT ' . $this->quote($this->currentIdentity));
        if (strpos($query, 'FROM information_schema.TABLES') !== false) return parent::query("SELECT 'oc_product' AS TABLE_NAME," . $this->quote($this->tableType) . " AS TABLE_TYPE,'InnoDB' AS ENGINE UNION ALL SELECT 'oc_product_option_value'," . $this->quote($this->tableType) . ",'InnoDB'");
        if (strpos($query, 'FROM oc_analytics_sandbox_guard') !== false) return parent::query("SELECT 'oc_product' AS table_name,'BASE TABLE' AS table_type,'InnoDB' AS engine," . $this->quote($this->triggerCount) . ' AS trigger_count,' . $this->quote($this->externalFkCount) . ' AS external_fk_count,' . $this->quote($this->auditUser) . " AS audit_user UNION ALL SELECT 'oc_product_option_value','BASE TABLE','InnoDB',0,0," . $this->quote($this->auditUser));
        if ($query === 'SHOW GRANTS FOR CURRENT_USER()') return parent::query(implode(' UNION ALL ', array_map(function ($grant) { return 'SELECT ' . $this->quote($grant); }, $this->grants)));
        if (!preg_match('/^SELECT /D', $query)) throw new RuntimeException('Unexpected non-read statement');
        return parent::query($query, ...$arguments);
    }
}

$root = sys_get_temp_dir() . '/lavka-sandbox-setup-' . bin2hex(random_bytes(6));
\Lavka\private_dir($root);
try {
    $old = $root . '/analytics-private'; $data = $root . '/analytics-private-test'; $shop = $root . '/test';
    $GLOBALS['sandbox_fixture_storage'] = $shop . '/storage/';
    foreach ([$old, $shop, $shop . '/storage', $shop . '/secret-admin', $shop . '/analytics', $root . '/www'] as $directory) \Lavka\private_dir($directory);
    file_put_contents($shop . '/config.php', setup_store_php());
    file_put_contents($shop . '/secret-admin/config.php', setup_store_php(true));
    $admin = $shop . '/secret-admin/config.php';
    $stores = lavka_sandbox_store_checks($shop, $admin);
    setup_expect(count($stores) === 2 && $stores[1]['file'] === 'secret-admin/config.php', 'Renamed admin folder is explicitly verified');
    setup_expect(strpos(\Lavka\encode($stores), 'STORE_SECRET') === false, 'Only non-secret store metadata is returned');
    setup_rejects('test_admin_config_invalid', function () use ($shop) { lavka_sandbox_store_checks($shop, $shop . '/config.php'); });
    setup_rejects('test_admin_config_invalid', function () use ($shop) { lavka_sandbox_store_checks($shop); });
    foreach ([
        [str_replace("'lavkasp_test'", "'lavkasp_lavka'", setup_store_php()), 'test_shop_points_elsewhere'],
        [str_replace("'3306'", "'3307'", setup_store_php()), 'test_shop_port_mismatch'],
        [str_replace('https://test.lavka-speciy.com/', 'https://lavka-speciy.com/', setup_store_php()), 'test_shop_url_mismatch'],
        [str_replace('https://test.lavka-speciy.com/', 'https://test.lavka-speciy.com:444/', setup_store_php()), 'test_shop_url_mismatch'],
        [setup_store_php() . "define('DB_DATABASE', 'lavkasp_test');", 'test_shop_config_ambiguous'],
        [str_replace("define('DB_DATABASE', 'lavkasp_test');", "if(false){define('DB_DATABASE', 'lavkasp_test');}", setup_store_php()), 'test_shop_config_executable'],
        ["<?php return;\n" . substr(setup_store_php(), 6), 'test_shop_config_executable'],
        [setup_store_php() . "require 'production-config.php';", 'test_shop_config_executable'],
        [setup_store_php(false, $root . '/www/storage/'), 'test_shop_storage_not_isolated'],
        [str_replace("define('DIR_CACHE', DIR_STORAGE . 'cache/');", "define('DIR_CACHE', '" . $root . "/www/cache/');", setup_store_php()), 'test_shop_storage_not_isolated'],
        [str_replace("'DB_DATABASE', 'lavkasp_test'", "'DB_DATABASE', getenv('DB_DATABASE')", setup_store_php()), 'test_shop_config_not_literal'],
        ["<?php\n// define('DB_DATABASE', 'lavkasp_test');\n" . "define('DB_PREFIX', 'oc_');", 'test_shop_points_elsewhere'],
    ] as $case) {
        file_put_contents($shop . '/config.php', $case[0]);
        setup_rejects($case[1], function () use ($shop, $admin) { lavka_sandbox_store_checks($shop, $admin); });
    }
    file_put_contents($shop . '/config.php', setup_store_php() . "\nfile_put_contents('" . $root . "/EXECUTED_BAD_CONFIG', 'bad');\n");
    setup_rejects('test_shop_config_executable', function () use ($shop, $admin) { lavka_sandbox_store_checks($shop, $admin); });
    setup_expect(!file_exists($root . '/EXECUTED_BAD_CONFIG'), 'Configuration PHP is tokenised, never executed');
    file_put_contents($shop . '/config.php', setup_store_php());
    $configBefore = file_get_contents($shop . '/config.php');
    setup_rejects('sandbox_directories_not_separate', function () use ($old, $root, $shop) { \Lavka\sandbox_validate_layout($old, $root . '/www/analytics-private-test', $shop); });
    symlink($root . '/www', $root . '/public-alias');
    setup_rejects('sandbox_directories_not_separate', function () use ($old, $root, $shop) { \Lavka\sandbox_validate_layout($old, $root . '/public-alias/analytics-private-test', $shop); });
    $grants = setup_grants();
    setup_expect(lavka_sandbox_grants($grants)['price_columns'], 'Column-level UPDATE covers exactly the required price fields');
    foreach ([
        [array_merge($grants, ["GRANT SELECT ON `lavkasp_lavka`.* TO 'lavkasp_sandbox'@'localhost'"]), 'sandbox_other_database_grant'],
        [["GRANT ALL PRIVILEGES ON `lavkasp_test`.* TO 'lavkasp_sandbox'@'localhost'"], 'sandbox_privileges_too_broad'],
        [["GRANT SELECT, UPDATE ON *.* TO 'lavkasp_sandbox'@'localhost'"], 'sandbox_global_privileges'],
        [["GRANT SELECT, UPDATE ON `lavkasp_test`.* TO 'lavkasp_sandbox'@'localhost' WITH GRANT OPTION"], 'sandbox_privileges_not_isolated'],
        [["GRANT SELECT ON `lavkasp_test`.* TO 'lavkasp_sandbox'@'localhost'", "GRANT UPDATE(total) ON `lavkasp_test`.`oc_order` TO 'lavkasp_sandbox'@'localhost'"], 'sandbox_select_update_required'],
        [array_slice($grants, 0, 3), 'sandbox_select_update_required'],
    ] as $case) setup_rejects($case[1], function () use ($case) { lavka_sandbox_grants($case[0]); });

    if (PHP_VERSION_ID < 80200) {
        setup_rejects('requires_php82_cli', function () { lavka_sandbox_setup([]); });
        setup_rejects('requires_php82_cli', function () { lavka_sandbox_check([]); });
        echo \Lavka\encode(['sandbox_shared_literal_guards' => 'passed', 'old_cli_rejected' => 'passed', 'php' => PHP_VERSION, 'network_requests' => 0]) . "\n";
        exit(0);
    }
    \Lavka\Auth::setPassword($old, 'synthetic-password-not-a-live-secret');
    file_put_contents($old . '/source.json', 'ORIGINAL_PRODUCTION_SOURCE_NEVER_COPY');
    file_put_contents($old . '/analytics.sqlite3', 'ORIGINAL_SALES_AND_CRM_PRESERVED');
    file_put_contents($shop . '/analytics/index.php', 'ORIGINAL_PUBLIC_LAUNCHER');
    file_put_contents($shop . '/.htaccess', 'ORIGINAL_STORE_RULES');
    $metadata = ['application_root' => realpath(dirname(__DIR__)), 'shop_root' => $shop,
        'document_root' => $shop . '/analytics', 'data_dir' => $old, 'public_url' => 'https://test.lavka-speciy.com/analytics'];
    \Lavka\atomic_write($old . '/deployment-php.json', \Lavka\encode($metadata));
    $monitor = new SQLite3($old . '/competitors.sqlite3');
    $monitor->exec('PRAGMA journal_mode=WAL; PRAGMA wal_autocheckpoint=0; CREATE TABLE fixture(id INTEGER PRIMARY KEY,label TEXT); INSERT INTO fixture VALUES(1,\'committed\')');
    $monitor->exec('PRAGMA wal_checkpoint(TRUNCATE)');
    $monitor->exec("INSERT INTO fixture VALUES(2,'committed-in-wal')");
    $originals = [];
    foreach ([$old . '/source.json', $old . '/access-php.json', $old . '/analytics.sqlite3', $old . '/deployment-php.json', $shop . '/analytics/index.php', $shop . '/.htaccess', $shop . '/config.php', $admin] as $file) $originals[$file] = file_get_contents($file);
    $args = ['from' => $old, 'data' => $data, 'shop-root' => $shop, 'admin-config' => $admin];
    $source = new SandboxSetupSource();
    $passwordReads = 0;
    $password = function () use (&$passwordReads): string { $passwordReads++; return 'NEW_SANDBOX_PRIVATE_PASSWORD'; };
    $connector = function ($config) use ($source): PDO {
        setup_expect($config['database'] === 'lavkasp_test' && $config['user'] === 'lavkasp_sandbox', 'Connection uses only the new test identity');
        return $source;
    };
    $source->databaseName = 'lavkasp_lavka';
    setup_rejects('sandbox_database_mismatch', function () use ($args, $password, $connector) { lavka_sandbox_setup($args, $password, $connector); });
    setup_expect(!file_exists($data), 'Wrong database blocks before any sandbox files are created');
    $source->databaseName = 'lavkasp_test'; $source->currentIdentity = 'lavkasp_analytics@localhost';
    setup_rejects('sandbox_user_mismatch', function () use ($args, $password, $connector) { lavka_sandbox_setup($args, $password, $connector); });
    $source->currentIdentity = 'lavkasp_sandbox@localhost';
    $source->grants[] = "GRANT SELECT ON `lavkasp_lavka`.* TO 'lavkasp_sandbox'@'localhost'";
    setup_rejects('sandbox_other_database_grant', function () use ($args, $password, $connector) { lavka_sandbox_setup($args, $password, $connector); });
    $source->grants = setup_grants();
    foreach ([['tableType', 'VIEW', 'sandbox_price_tables_not_isolated'], ['triggerCount', '1', 'sandbox_schema_not_isolated'], ['externalFkCount', '1', 'sandbox_schema_not_isolated'], ['auditUser', 'lavkasp_sandbox@localhost', 'sandbox_schema_audit_required']] as $case) {
        $previous = $source->{$case[0]}; $source->{$case[0]} = $case[1];
        setup_rejects($case[2], function () use ($args, $password, $connector) { lavka_sandbox_setup($args, $password, $connector); });
        $source->{$case[0]} = $previous;
        setup_expect(!file_exists($data), 'Schema audit failure blocks before creating private files');
    }
    $prepared = lavka_sandbox_setup($args, $password, $connector);
    setup_expect($prepared['state'] === 'prepared' && !$prepared['public_launcher_changed'] && !$prepared['production_data_changed'], 'Preparation never switches a launcher or production settings');
    foreach ($originals as $file => $contents) setup_expect(file_get_contents($file) === $contents, 'Existing file preserved: ' . basename($file));
    setup_expect(!file_exists($data . '/analytics.sqlite3') && !file_exists($data . '/sessions-php.sqlite3') && !file_exists($data . '/own-catalog.json'), 'Production orders, sessions and own-price cache are not copied');
    setup_expect(file_get_contents($data . '/access-php.json') === file_get_contents($old . '/access-php.json'), 'Cabinet password remains unchanged');
    $copy = new SQLite3($data . '/competitors.sqlite3', SQLITE3_OPEN_READONLY);
    setup_expect($copy->querySingle('SELECT count(*) FROM fixture') === 2, 'Monitoring copy includes committed WAL records');
    $copy->close();
    $savedSource = \Lavka\decode(file_get_contents($data . '/source.json'));
    setup_expect($savedSource['database'] === 'lavkasp_test' && $savedSource['password'] === 'NEW_SANDBOX_PRIVATE_PASSWORD', 'New private connection is separate from the production source');
    $marker = \Lavka\decode(file_get_contents($data . '/sandbox.json'));
    setup_expect($marker['test_store_verified'] && $marker['admin_config'] === $admin && $marker['previous_data_dir'] === $old, 'Marker records both verified configurations and the preserved directory');
    foreach (['source.json', 'access-php.json', 'sandbox.json', 'competitors.sqlite3'] as $name) setup_expect((fileperms($data . '/' . $name) & 0777) === 0600, 'Private file permissions: ' . $name);
    setup_expect((fileperms($data) & 0777) === 0700, 'New private directory permissions');
    $checked = lavka_sandbox_check(['data' => $data], $connector);
    setup_expect($checked['state'] === 'ok' && !$checked['shop_writes'], 'Read-only final check validates the prepared source');
    $output = \Lavka\encode([$prepared, $checked]);
    foreach (['NEW_SANDBOX_PRIVATE_PASSWORD', 'AUTHENTICATION_MATERIAL', 'ORIGINAL_PRODUCTION_SOURCE', 'STORE_SECRET'] as $secret) setup_expect(strpos($output, $secret) === false, 'Reports do not contain private content');
    setup_rejects('sandbox_destination_not_empty', function () use ($args, $password, $connector) { lavka_sandbox_setup($args, $password, $connector); });
    setup_rejects('sandbox_connection_or_schema_failed', function () use ($savedSource) {
        lavka_sandbox_source_check($savedSource, function () { throw new RuntimeException('RAW_DRIVER_PRIVATE_PASSWORD'); });
    });
    $monitor->close(); $monitor = null;
    echo \Lavka\encode(['sandbox_shared_literal_guards' => 'passed', 'new_private_and_wal_snapshot' => 'passed', 'test_identity_grants_schema' => 'passed', 'source_and_shop_preserved' => 'passed', 'php' => PHP_VERSION, 'network_requests' => 0]) . "\n";
} finally {
    if (isset($monitor) && $monitor instanceof SQLite3) $monitor->close();
    setup_remove($root);
}

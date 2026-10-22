<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/bin/prepare_sandbox.php';
require_once dirname(__DIR__) . '/src/Auth.php';

function prep_expect(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function prep_rejects(string $code, callable $operation): void
{
    try { $operation(); }
    catch (Throwable $error) { prep_expect($error->getMessage() === $code, 'Expected ' . $code . ', got ' . $error->getMessage()); return; }
    throw new RuntimeException('Expected rejection: ' . $code);
}
function prep_remove(string $directory): void
{
    foreach (scandir($directory) ?: [] as $name) if ($name !== '.' && $name !== '..') {
        $path = $directory . '/' . $name;
        if (is_dir($path) && !is_link($path)) prep_remove($path); else unlink($path);
    }
    rmdir($directory);
}
function prep_config(string $shop, bool $admin = false, string $user = 'lavkasp_test', ?string $passwordLiteral = null): string
{
    $literal = $passwordLiteral ?? var_export("TEST_ADMIN_PRIVATE_'_\\_\$", true);
    $result = "<?php\n// define('DB_USERNAME','lavkasp_lavka'); define('DB_PASSWORD','COMMENT_NOT_CREDENTIAL');\n";
    foreach (['DB_DATABASE' => 'lavkasp_test', 'DB_PREFIX' => 'oc_', 'DB_HOSTNAME' => '127.0.0.1', 'DB_PORT' => '3306', 'DB_USERNAME' => $user,
        'HTTP_SERVER' => 'https://test.lavka-speciy.com/' . ($admin ? 'secret-admin/' : ''),
        'HTTPS_SERVER' => 'https://test.lavka-speciy.com/' . ($admin ? 'secret-admin/' : ''), 'DIR_STORAGE' => $shop . '/storage/'] as $key => $value) {
        $result .= 'define(' . var_export($key, true) . ',' . var_export($value, true) . ");\n";
    }
    $result .= 'define(\'DB_PASSWORD\',' . $literal . ");\n";
    $result .= "define('DIR_CACHE',DIR_STORAGE.'cache/');\ndefine('DIR_LOGS',DIR_STORAGE.'logs/');\n";
    if ($admin) $result .= "define('HTTP_CATALOG','https://test.lavka-speciy.com/');\ndefine('HTTPS_CATALOG','https://test.lavka-speciy.com/');\n";
    return $result;
}

/** Only two reviewed CREATE VIEW statements are simulated; other mutations fail. */
class PrepareAdminSource extends PDO
{
    public $databaseName = 'lavkasp_test';
    public $identity = 'lavkasp_test@localhost';
    public $grants = ["GRANT USAGE ON *.* TO 'lavkasp_test'@'localhost' IDENTIFIED BY PASSWORD 'PRIVATE_ADMIN_GRANT_MATERIAL'", "GRANT ALL PRIVILEGES ON `lavkasp_test`.* TO 'lavkasp_test'@'localhost'"];
    public $queries = [];
    public $ddl = [];
    public $triggerCount = '0';
    public $externalFkCount = '0';
    public $tableType = 'BASE TABLE';
    public $failViews = false;
    public $failIdentityQuery = false;
    public function __construct()
    {
        parent::__construct('sqlite::memory:');
        $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }
    #[\ReturnTypeWillChange]
    public function query($query, ...$arguments)
    {
        $this->queries[] = $query;
        if ($query === 'SELECT DATABASE()' && $this->failIdentityQuery) throw new PDOException('private_driver_secret');
        if ($query === 'SELECT DATABASE()') return parent::query('SELECT ' . $this->quote($this->databaseName));
        if ($query === 'SELECT CURRENT_USER()') return parent::query('SELECT ' . $this->quote($this->identity));
        if (strpos($query, 'SHOW GRANTS') === 0) return parent::query(implode(' UNION ALL ', array_map(function ($grant) { return 'SELECT ' . $this->quote($grant); }, $this->grants)));
        if (strpos($query, 'FROM information_schema.TABLES') !== false) return parent::query("SELECT 'oc_product' AS TABLE_NAME," . $this->quote($this->tableType) . " AS TABLE_TYPE,'InnoDB' AS ENGINE UNION ALL SELECT 'oc_product_option_value'," . $this->quote($this->tableType) . ",'InnoDB'");
        if (strpos($query, 'FROM oc_analytics_sandbox_guard') !== false) return parent::query("SELECT 'oc_product' AS table_name,'BASE TABLE' AS table_type,'InnoDB' AS engine," . $this->quote($this->triggerCount) . ' AS trigger_count,' . $this->quote($this->externalFkCount) . ' AS external_fk_count,' . $this->quote($this->identity) . " AS audit_user UNION ALL SELECT 'oc_product_option_value','BASE TABLE','InnoDB',0,0," . $this->quote($this->identity));
        throw new RuntimeException('Unexpected administrator query');
    }
    #[\ReturnTypeWillChange]
    public function exec($statement)
    {
        prep_expect((bool) preg_match('/^CREATE OR REPLACE SQL SECURITY DEFINER VIEW lavkasp_test\.oc_analytics_(catalog_settings|sandbox_guard)\s+AS\s+/i', $statement), 'Only the two fixed test views may be created');
        if ($this->failViews) throw new PDOException('PRIVATE_ADMIN_DRIVER_PASSWORD');
        $this->ddl[] = $statement;
        return 0;
    }
}

/** The separate low privilege source executes schema/settings SELECTs on SQLite. */
class PrepareAppSource extends PDO
{
    public $queries = [];
    public function __construct()
    {
        parent::__construct('sqlite::memory:');
        $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $schema = \Lavka\SOURCE_FIELDS;
        foreach (\Lavka\COMPETITOR_CATALOG_FIELDS as $table => $fields) $schema['oc_' . ($table === 'setting' ? 'analytics_catalog_settings' : $table)] = $fields;
        foreach ($schema as $table => $fields) parent::exec('CREATE TABLE `' . $table . '` (' . implode(',', array_map(function ($field) { return '`' . $field . '` TEXT'; }, $fields)) . ')');
        foreach (['config_currency' => 'UAH', 'config_language' => 'uk-ua', 'config_customer_group_id' => '1', 'config_tax' => '0'] as $key => $value) parent::exec('INSERT INTO oc_analytics_catalog_settings VALUES(0,' . $this->quote($key) . ',' . $this->quote($value) . ',0)');
        parent::exec('PRAGMA query_only=ON');
    }
    #[\ReturnTypeWillChange]
    public function query($query, ...$arguments)
    {
        $this->queries[] = $query;
        if ($query === 'SELECT DATABASE()') return parent::query("SELECT 'lavkasp_test'");
        if ($query === 'SELECT CURRENT_USER()') return parent::query("SELECT 'lavkasp_sandbox@localhost'");
        if (strpos($query, 'SHOW GRANTS') === 0) return parent::query("SELECT \"GRANT USAGE ON *.* TO 'lavkasp_sandbox'@'localhost'\" UNION ALL SELECT \"GRANT SELECT, UPDATE ON `lavkasp_test`.* TO 'lavkasp_sandbox'@'localhost'\"");
        if (strpos($query, 'FROM information_schema.TABLES') !== false) return parent::query("SELECT 'oc_product' AS TABLE_NAME,'BASE TABLE' AS TABLE_TYPE,'InnoDB' AS ENGINE UNION ALL SELECT 'oc_product_option_value','BASE TABLE','InnoDB'");
        if (strpos($query, 'FROM oc_analytics_sandbox_guard') !== false) return parent::query("SELECT 'oc_product' AS table_name,'BASE TABLE' AS table_type,'InnoDB' AS engine,0 AS trigger_count,0 AS external_fk_count,'lavkasp_test@localhost' AS audit_user UNION ALL SELECT 'oc_product_option_value','BASE TABLE','InnoDB',0,0,'lavkasp_test@localhost'");
        prep_expect(strpos($query, 'SELECT ') === 0, 'The app source is read-only during preparation');
        return parent::query($query, ...$arguments);
    }
}

$root = sys_get_temp_dir() . '/lavka-sandbox-prepare-' . bin2hex(random_bytes(6));
\Lavka\private_dir($root);
$monitor = null;
try {
    if (PHP_VERSION_ID < 80200) {
        prep_rejects('requires_php82_cli', function () { lavka_sandbox_prepare([]); });
        echo \Lavka\encode(['sandbox_prepare_old_cli_rejected' => 'passed', 'php' => PHP_VERSION, 'network_requests' => 0]) . "\n";
        exit(0);
    }
    $old = $root . '/analytics-private'; $data = $root . '/analytics-private-test'; $shop = $root . '/test'; $adminFile = $shop . '/secret-admin/config.php';
    foreach ([$old, $shop . '/storage', $shop . '/secret-admin', $shop . '/analytics', $root . '/www'] as $directory) \Lavka\private_dir($directory);
    file_put_contents($shop . '/config.php', prep_config($shop));
    file_put_contents($adminFile, prep_config($shop, true));
    $credentials = lavka_sandbox_prepare_credentials($shop . '/config.php');
    prep_expect($credentials === ['user' => 'lavkasp_test', 'password' => "TEST_ADMIN_PRIVATE_'_\\_\$"], 'Quoted values are decoded without executing PHP or accepting commented credentials');
    $doubleLiteral = <<<'PHP_LITERAL'
"DOUBLE_PRIVATE_\n_\t_\\_\"_\$_\x41_\101_\q"
PHP_LITERAL;
    file_put_contents($shop . '/config.php', prep_config($shop, false, 'lavkasp_test', $doubleLiteral));
    prep_expect(lavka_sandbox_prepare_credentials($shop . '/config.php')['password'] === "DOUBLE_PRIVATE_\n_\t_\\_\"_\$_A_A_\\q", 'Double quoted PHP escape semantics are decoded as data');
    file_put_contents($shop . '/config.php', prep_config($shop));
    prep_expect(lavka_sandbox_prepare_admin_config($shop) === $adminFile, 'The unique renamed admin folder is discovered automatically');
    \Lavka\private_dir($shop . '/second-admin'); file_put_contents($shop . '/second-admin/config.php', prep_config($shop, true));
    prep_rejects('test_admin_config_required', function () use ($shop) { lavka_sandbox_prepare_admin_config($shop); });
    unlink($shop . '/second-admin/config.php'); rmdir($shop . '/second-admin');
    rename($adminFile, $adminFile . '.saved');
    prep_rejects('test_admin_config_required', function () use ($shop) { lavka_sandbox_prepare_admin_config($shop); });
    rename($adminFile . '.saved', $adminFile);

    \Lavka\Auth::setPassword($old, 'SYNTHETIC_CABINET_PASSWORD');
    file_put_contents($old . '/source.json', 'ORIGINAL_LIVE_SOURCE_NEVER_READ_OR_COPY');
    file_put_contents($old . '/analytics.sqlite3', 'ORIGINAL_ORDERS_AND_CRM');
    file_put_contents($shop . '/analytics/index.php', 'ORIGINAL_PUBLIC_LAUNCHER');
    file_put_contents($shop . '/.htaccess', 'ORIGINAL_STORE_RULES');
    \Lavka\atomic_write($old . '/deployment-php.json', \Lavka\encode(['application_root' => realpath(dirname(__DIR__)), 'shop_root' => $shop,
        'document_root' => $shop . '/analytics', 'data_dir' => $old, 'public_url' => 'https://test.lavka-speciy.com/analytics']));
    $monitor = new SQLite3($old . '/competitors.sqlite3');
    $monitor->exec('PRAGMA journal_mode=WAL; PRAGMA wal_autocheckpoint=0; CREATE TABLE fixture(id INTEGER PRIMARY KEY,label TEXT); INSERT INTO fixture VALUES(1,\'base\')');
    $monitor->exec('PRAGMA wal_checkpoint(TRUNCATE)'); $monitor->exec("INSERT INTO fixture VALUES(2,'committed-in-wal')");
    $protected = [];
    foreach ([$shop . '/config.php', $adminFile, $old . '/source.json', $old . '/access-php.json', $old . '/analytics.sqlite3', $old . '/deployment-php.json', $shop . '/analytics/index.php', $shop . '/.htaccess'] as $file) $protected[$file] = hash_file('sha256', $file);
    $args = ['from' => $old, 'data' => $data, 'shop-root' => $shop];
    $admin = new PrepareAdminSource(); $app = new PrepareAppSource(); $passwordCalls = 0; $connections = [];
    $password = function () use (&$passwordCalls): string { $passwordCalls++; return 'NEW_APP_PRIVATE_PASSWORD'; };
    $adminConnector = function (array $config) use ($admin, &$connections): PDO {
        prep_expect($config['database'] === 'lavkasp_test' && $config['user'] === 'lavkasp_test' && $config['host'] === '127.0.0.1' && (int) $config['port'] === 3306, 'The admin connection is fixed to the local test source');
        prep_expect($config['password'] === "TEST_ADMIN_PRIVATE_'_\\_\$", 'The test storefront credential is only passed to its fixed connector');
        $connections[] = 'admin'; return $admin;
    };
    $appConnector = function (array $config) use ($app, &$connections): PDO {
        prep_expect($config['database'] === 'lavkasp_test' && $config['user'] === 'lavkasp_sandbox' && $config['password'] === 'NEW_APP_PRIVATE_PASSWORD', 'The persistent source uses only the new limited user');
        $connections[] = 'app'; return $app;
    };
    foreach ([
        [str_replace("'DB_DATABASE','lavkasp_test'", "'DB_DATABASE','lavkasp_lavka'", prep_config($shop)), 'test_shop_points_elsewhere'],
        [prep_config($shop, false, 'lavkasp_lavka'), 'sandbox_test_admin_required'],
        [prep_config($shop) . "\nfile_put_contents('" . $root . "/CONFIG_EXECUTED','bad');", 'test_shop_config_executable'],
        [prep_config($shop, false, 'lavkasp_test', '"INTERPOLATED_$unknown"'), 'test_shop_config_not_literal'],
    ] as $case) {
        file_put_contents($shop . '/config.php', $case[0]);
        prep_rejects($case[1], function () use ($args, $password, $adminConnector, $appConnector) { lavka_sandbox_prepare($args, $password, $adminConnector, $appConnector); });
        prep_expect(!$admin->ddl && $passwordCalls === 0 && !file_exists($data), 'Bad configuration fails before views, password input and new private files');
    }
    prep_expect(!file_exists($root . '/CONFIG_EXECUTED'), 'Storefront PHP is never executed');
    file_put_contents($shop . '/config.php', prep_config($shop));
    $admin->databaseName = 'lavkasp_lavka';
    prep_rejects('sandbox_test_admin_database_mismatch', function () use ($args, $password, $adminConnector, $appConnector) { lavka_sandbox_prepare($args, $password, $adminConnector, $appConnector); });
    $admin->databaseName = 'lavkasp_test'; $admin->identity = 'lavkasp_lavka@localhost';
    prep_rejects('sandbox_test_admin_required', function () use ($args, $password, $adminConnector, $appConnector) { lavka_sandbox_prepare($args, $password, $adminConnector, $appConnector); });
    $admin->identity = 'lavkasp_test@localhost'; $safeGrants = $admin->grants;
    foreach ([
        ["GRANT ALL PRIVILEGES ON *.* TO 'lavkasp_test'@'localhost'"],
        ["GRANT USAGE ON *.* TO 'lavkasp_test'@'localhost' WITH GRANT OPTION", "GRANT ALL PRIVILEGES ON `lavkasp_test`.* TO 'lavkasp_test'@'localhost'"],
        array_merge($safeGrants, ["GRANT SELECT ON `lavkasp_lavka`.* TO 'lavkasp_test'@'localhost'"]),
        ["GRANT SELECT, CREATE VIEW, SHOW VIEW ON `lavkasp_test`.* TO 'lavkasp_test'@'localhost'"],
    ] as $grants) {
        $admin->grants = $grants;
        prep_rejects('sandbox_test_admin_privileges', function () use ($args, $password, $adminConnector, $appConnector) { lavka_sandbox_prepare($args, $password, $adminConnector, $appConnector); });
    }
    $admin->grants = $safeGrants;
    prep_expect(!$admin->ddl && $passwordCalls === 0 && !file_exists($data), 'Identity and scope failures stop before SQL mutations or password input');
    prep_rejects('sandbox_prepare_connection_failed', function () use ($args, $password, $appConnector) { lavka_sandbox_prepare($args, $password, function () { throw new PDOException('PRIVATE_ADMIN_DRIVER_PASSWORD'); }, $appConnector); });
    $admin->failIdentityQuery = true;
    prep_rejects('sandbox_prepare_connection_failed', function () use ($args, $password, $adminConnector, $appConnector) { lavka_sandbox_prepare($args, $password, $adminConnector, $appConnector); });
    $admin->failIdentityQuery = false;
    prep_expect(lavka_sandbox_prepare_error_code(new RuntimeException('private_driver_secret')) === 'sandbox_prepare_failed', 'A lowercase unexpected error cannot be mistaken for a safe public code');
    prep_expect(lavka_sandbox_prepare_error_code(new PDOException('private_driver_secret')) === 'sandbox_prepare_connection_failed', 'CLI formatting never prints driver details');
    $admin->failViews = true;
    prep_rejects('sandbox_prepare_views_failed', function () use ($args, $password, $adminConnector, $appConnector) { lavka_sandbox_prepare($args, $password, $adminConnector, $appConnector); });
    prep_expect(!$admin->ddl && $passwordCalls === 0 && !file_exists($data), 'View errors do not leak driver secrets or prepare a connection');
    $admin->failViews = false;
    $result = lavka_sandbox_prepare($args, $password, $adminConnector, $appConnector);
    prep_expect($result['state'] === 'prepared' && $result['database'] === 'lavkasp_test', 'The helper prepares the existing isolated setup');
    prep_expect(count($admin->ddl) === 2 && strpos($admin->ddl[0], 'oc_analytics_catalog_settings') !== false && strpos($admin->ddl[1], 'oc_analytics_sandbox_guard') !== false, 'Exactly the two fixed reviewed views are created');
    prep_expect(strpos($admin->ddl[0], "'config_currency','config_language','config_customer_group_id','config_tax'") !== false || strpos($admin->ddl[0], "'config_currency', 'config_language', 'config_customer_group_id', 'config_tax'") !== false, 'The settings view exposes only the four public pricing keys');
    prep_expect(strpos($admin->ddl[1], 'CURRENT_USER() AS audit_user') !== false && strpos($admin->ddl[1], 'information_schema.TRIGGERS') !== false && strpos($admin->ddl[1], 'information_schema.KEY_COLUMN_USAGE') !== false, 'The guard view audits under the verified test administrator');
    foreach ($protected as $file => $digest) prep_expect(hash_file('sha256', $file) === $digest, 'Original file preserved: ' . basename($file));
    prep_expect($passwordCalls === 1 && end($connections) === 'app', 'The new user password is requested once after view validation');
    $copy = new SQLite3($data . '/competitors.sqlite3', SQLITE3_OPEN_READONLY);
    prep_expect($copy->querySingle('SELECT count(*) FROM fixture') === 2, 'The monitoring history includes committed WAL pages'); $copy->close();
    prep_expect(file_get_contents($data . '/access-php.json') === file_get_contents($old . '/access-php.json'), 'Existing cabinet password preserved');
    prep_expect(!file_exists($data . '/analytics.sqlite3') && !file_exists($data . '/own-catalog.json'), 'Production orders and own price cache are not copied');
    $saved = \Lavka\decode(file_get_contents($data . '/source.json'));
    prep_expect($saved['user'] === 'lavkasp_sandbox' && $saved['password'] === 'NEW_APP_PRIVATE_PASSWORD', 'The admin credential is never persisted to the new source');
    foreach (['source.json', 'access-php.json', 'sandbox.json', 'competitors.sqlite3'] as $name) prep_expect((fileperms($data . '/' . $name) & 0777) === 0600, 'Private permissions: ' . $name);
    foreach (['TEST_ADMIN_PRIVATE', 'NEW_APP_PRIVATE_PASSWORD', 'PRIVATE_ADMIN_GRANT_MATERIAL', 'ORIGINAL_LIVE_SOURCE'] as $private) prep_expect(strpos(\Lavka\encode($result), $private) === false, 'Output excludes private contents');
    $ddlCount = count($admin->ddl); $connectionCount = count($connections);
    prep_rejects('sandbox_destination_not_empty', function () use ($args, $password, $adminConnector, $appConnector) { lavka_sandbox_prepare($args, $password, $adminConnector, $appConnector); });
    prep_expect(count($admin->ddl) === $ddlCount && count($connections) === $connectionCount, 'Repeat invocation refuses before making changes');
    $monitor->close(); $monitor = null;
    echo \Lavka\encode(['sandbox_prepare_scoped_admin_views' => 'passed', 'literal_credentials_no_execution' => 'passed', 'private_copy_and_wal' => 'passed', 'source_shop_and_price_rows_preserved' => 'passed', 'php' => PHP_VERSION, 'network_requests' => 0]) . "\n";
} finally {
    if ($monitor instanceof SQLite3) $monitor->close();
    prep_remove($root);
}

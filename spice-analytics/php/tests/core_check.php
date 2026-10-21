<?php

declare (strict_types=1);
require_once dirname(__DIR__) . '/src/Analytics.php';
require_once dirname(__DIR__) . '/src/Auth.php';
require_once dirname(__DIR__) . '/src/Sync.php';
function expect(bool $value, string $message): void
{
    if (!$value) {
        throw new RuntimeException($message);
    }
}
function rejects(callable $fn): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        return;
    }
    throw new RuntimeException('Expected rejection');
}
$dir = sys_get_temp_dir() . '/lavka-php-test-' . bin2hex(random_bytes(6));
\Lavka\private_dir($dir);
try {
    $ties=array_map(function($i){return ['index'=>$i,'score'=>1];},range(0,39));
    \Lavka\usort($ties,function($a,$b){return $a['score']<=>$b['score'];});
    expect(array_column($ties,'index')===range(0,39),'Stable ties across PHP versions');
    if(PHP_VERSION_ID<80200){
        try{\Lavka\source_connect([]);throw new RuntimeException('Old MySQL CLI was accepted');}
        catch(\Lavka\SyncError $e){expect(strpos($e->getMessage(),'8.2 CLI')!==false,'Explicit modern sync CLI requirement');}
    }
    expect(\Lavka\public_address('https://shop.example.invalid/analytics/')['base'] === '/analytics', 'Folder URL');
    expect(\Lavka\public_address('https://shop.example.invalid')['base'] === '', 'Subdomain URL');
    foreach (['https://shop.example.invalid/../store', 'https://shop.example.invalid/a%2fb', 'https://shop.example.invalid/?x=1', 'http://shop.example.invalid/analytics', 'https://user:pass@shop.example.invalid'] as $bad) {
        rejects(function () use ($bad) {
            return \Lavka\public_address($bad);
        });
    }
    expect(\Lavka\money(\Lavka\times('100.1234', '1.5')) === 150.19, 'Exact decimal currency conversion');
    expect(\Lavka\money('-0.005') === -0.01, 'Negative half-up');
    expect(\Lavka\package([['name' => 'Фасовка', 'value' => '0,5 кг']])['grams'] === 500.0, 'Weight option');
    expect(\Lavka\package([['name' => 'Фасовка', 'value' => '1 шт.']])['grams'] === null, 'Pieces are not grams');
    expect(\Lavka\phone_key('0961234567') === \Lavka\phone_key('+38 (096) 123-45-67'), 'Phone normalization');
    expect(\Lavka\email_key('nomail@shop.example') === '', 'Placeholder email');
    expect(\Lavka\week('2026-12-28') === 53, 'ISO week 53');
    copy(dirname(__DIR__, 2) . '/tests/fixtures/GeoIP2-City-Test.mmdb', $dir . '/geoip-city.mmdb');
    $geo = new \Lavka\Geo($dir);
    expect($geo->metadata()['available'], 'Bundled official GeoIP reader');
    expect($geo->lookup('81.2.69.160')['country_code'] === 'GB', 'Local MMDB lookup');
    expect($geo->lookup('127.0.0.1') === null, 'Private IP is not geolocated');
    $db = \Lavka\database($dir . '/analytics.sqlite3');
    \Lavka\init_schema($db);
    $daily = "date,channel,sessions,ecommercePurchases,purchaseRevenue,adCost,currencyCode\n20261019,Organic Search,100,1,3510,,UAH\n20261020,Paid Search,10,1,10,2,USD\n";
    \Lavka\ga4_import($db, $daily, 'daily', 'UAH');
    $r = \Lavka\ga4_report($db, 2026, '2026-10-20', 'UAH', []);
    expect($r['totals']['sessions'] === 100.0, 'GA4 currency filter');
    expect($r['totals']['cost'] === null, 'Missing cost remains unknown');
    rejects(function () use ($db) {
        return \Lavka\ga4_import($db, "date,channel,sessions\n20261019,X,NaN\n", 'daily', 'UAH');
    });
    rejects(function () use ($db) {
        return \Lavka\ga4_import($db, "date,channel,sessions\n20261019,X,1.5\n", 'daily', 'UAH');
    });
    rejects(function () use ($db, $daily) {
        return \Lavka\ga4_import($db, $daily . "20261019,Organic Search,10,1,3510,,UAH\n", 'daily', 'UAH');
    });
    expect(\Lavka\ga4_report($db, 2026, '2026-10-20', 'UAH', [])['totals']['sessions'] === 100.0, 'Invalid imports are atomic');
    $password = bin2hex(random_bytes(18));
    \Lavka\Auth::setPassword($dir, $password);
    $auth = new \Lavka\Auth($dir);
    $cookie = $auth->login($password, 'test-client');
    expect((new \Lavka\Auth($dir))->valid($cookie), 'Persistent auth');
    $auth->logout($cookie);
    expect(!$auth->valid($cookie), 'Logout revocation');
    $cookie = $auth->login($password, 'test-client');
    \Lavka\Auth::setPassword($dir, bin2hex(random_bytes(18)));
    expect(!$auth->valid($cookie), 'Password change revokes');
    for ($i = 0; $i < 10; $i++) {
        rejects(function () use ($dir) {
            return (new \Lavka\Auth($dir))->login('incorrect', 'test-client');
        });
    }
    try {
        $auth->login('incorrect', 'test-client');
        throw new RuntimeException('Throttle missing');
    } catch (DomainException $e) {
        expect($e->getMessage() === 'rate_limit', 'Persistent rate limit');
    }
    expect((fileperms($dir . '/access-php.json') & 0777) === 0600, 'Private password file');
    $rows = array_fill_keys(array_keys(\Lavka\TABLES), []);
    $o = array_fill_keys(\Lavka\SOURCE_FIELDS['oc_order'], '');
    $o = array_merge($o, ['order_id' => '1', 'customer_id' => '1', 'firstname' => 'Тест', 'lastname' => 'Покупатель', 'email' => 'test@example.invalid', 'total' => '3510', 'currency_code' => 'UAH', 'currency_value' => '1', 'order_status_id' => '2', 'date_added' => '2026-10-19 12:00:00', 'date_modified' => '2026-10-19 12:00:00']);
    $rows['oc_order']['1'] = $o;
    $rows['oc_order_product']['1'] = ['order_product_id' => '1', 'order_id' => '1', 'product_id' => '1', 'name' => 'Тест', 'model' => 'test', 'quantity' => '6', 'price' => '585', 'total' => '3510'];
    $rows['oc_order_option']['1'] = ['order_option_id' => '1', 'order_id' => '1', 'order_product_id' => '1', 'name' => 'Фасовка', 'value' => '500 г'];
    $rows['oc_order_status']['2:1'] = ['order_status_id' => '2', 'language_id' => '1', 'name' => 'В обработке'];
    \Lavka\apply_snapshot($db, $rows, 'verified_two_reads');
    $store = new \Lavka\Analytics($db, $dir);
    $report = $store->report(2026, '2026-10-20', 'UAH');
    expect($report['metrics']['amount'] === 3510.0, 'Snapshot amount');
    expect($report['metrics']['kilograms'] === 3.0, 'Six half-kilo packs');
    $meta = \Lavka\fast_metadata($db, $dir);
    expect($meta === $store->metadata(), 'Fast metadata preserves contract');
    $db->exec("INSERT INTO customer_actions VALUES('1','{\"stage\":\"do_not_contact\"}');INSERT INTO manual_links VALUES('1','2','test');INSERT INTO settings VALUES('keep','yes');");
    $rows['oc_order']['1']['total'] = '3600';
    \Lavka\apply_snapshot($db, $rows, 'verified_two_reads');
    expect((int) $db->query('SELECT count(*) FROM customer_actions')->fetchColumn() === 1, 'CRM survives sync');
    expect((int) $db->query('SELECT count(*) FROM manual_links')->fetchColumn() === 1, 'Identity links survive sync');
    expect($db->query("SELECT value FROM settings WHERE key='keep'")->fetchColumn() === 'yes', 'Settings survive sync');
    expect(!\Lavka\apply_snapshot($db, $rows, 'verified_two_reads')['changed'], 'Unchanged source does not invalidate reports');
    $bad = $rows;
    $bad['oc_order_option']['1']['order_product_id'] = '999';
    rejects(function () use ($db, $bad) {
        return \Lavka\apply_snapshot($db, $bad, 'verified_two_reads');
    });
    expect((int) $db->query('SELECT count(*) FROM imports')->fetchColumn() === 2, 'Invalid snapshot cannot replace data');
    echo \Lavka\encode(['php_business_rules' => 'passed', 'ga4_atomic_validation' => 'passed', 'persistent_auth_and_throttle' => 'passed']) . "\n";
} finally {
    $db = null;
    foreach (glob($dir . '/*') as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    rmdir($dir);
}

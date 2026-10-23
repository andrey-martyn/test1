<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/src/CompetitorPackaging.php';

function packaging_expect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function packaging_rejects(callable $operation, string $message): void
{
    try { $operation(); }
    catch (InvalidArgumentException $error) { return; }
    throw new RuntimeException($message);
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
// Pre-upgrade tables include the identity, match and history fields whose data
// must survive the additive migration.
$db->exec("CREATE TABLE competitor_targets(id INTEGER PRIMARY KEY,source_id TEXT,kind TEXT,url TEXT,match_group TEXT,match_confirmed INTEGER,observation TEXT);
    CREATE TABLE competitor_observations(id INTEGER PRIMARY KEY,target_id INTEGER,payload TEXT);
    PRAGMA user_version=1;
    INSERT INTO competitor_targets VALUES(1,'spicery','product','https://spiceryshop.com.ua/ru/content/products/test','clove',1,'saved-price');
    INSERT INTO competitor_targets VALUES(2,'specii','product','https://specii.in.ua/test/','clove',0,NULL);
    INSERT INTO competitor_targets VALUES(3,'banka','terms','https://banka-speciy.in.ua/ua/faq','',0,'saved-terms');
    INSERT INTO competitor_observations VALUES(1,1,'historical-price');");
$before = $db->query('SELECT * FROM competitor_targets ORDER BY id')->fetchAll();
$db->beginTransaction();
\Lavka\competitors_packaging_migrate($db);
$db->commit();
\Lavka\competitors_packaging_migrate($db);
$after = $db->query('SELECT * FROM competitor_targets ORDER BY id')->fetchAll();
foreach ($after as $index => $row) {
    packaging_expect($row['packaging'] === 'auto', 'Existing target gets neutral automatic setting');
    unset($row['packaging']);
    packaging_expect($row === $before[$index], 'Identity, observation and match confirmation survive unchanged');
}
packaging_expect((int) $db->query('SELECT count(*) FROM competitor_observations')->fetchColumn() === 1
    && $db->query('SELECT payload FROM competitor_observations')->fetchColumn() === 'historical-price', 'Observation history preserved');
packaging_expect((int) $db->query('PRAGMA user_version')->fetchColumn() === 1, 'Shared database version remains compatible');
$spicery = \Lavka\competitors_packaging_fields($after[0]);
$specii = \Lavka\competitors_packaging_fields($after[1]);
packaging_expect($spicery === ['packaging' => 'auto', 'packaging_effective' => 'doypack'], 'Spicery defaults to actual Doy-pack selection');
packaging_expect($specii['packaging_effective'] === 'kraft', 'Specii defaults to Kraft-pack selection');
packaging_expect(\Lavka\competitors_packaging_fields($after[2])['packaging_effective'] === null, 'Terms have no effective packaging');
packaging_expect(\Lavka\competitors_packaging_effective('deluxe', 'product') === null, 'Other stores do not invent a packaging identity');

$choice = \Lavka\competitors_packaging_request(['packaging' => 'zip'], $spicery, 'product');
$db->beginTransaction();
\Lavka\competitors_packaging_store($db, 1, $choice);
$db->commit();
$saved = $db->query('SELECT * FROM competitor_targets WHERE id=1')->fetch();
packaging_expect(\Lavka\competitors_packaging_fields($saved)['packaging_effective'] === 'zip', 'Explicit preference overrides source default');
packaging_expect(\Lavka\competitors_packaging_request(['label' => 'Old client edit'], $saved, 'product') === 'zip', 'Old client PUT omission preserves preference');
packaging_expect(\Lavka\competitors_packaging_request([], null, 'product') === 'auto', 'New target defaults automatically');

foreach ([null, '', 'any', 'unknown', 'DOYPACK', 'doypack ', [], 1, true, "zip' OR 1=1--"] as $bad) {
    packaging_rejects(function () use ($bad) { \Lavka\competitors_packaging_request(['packaging' => $bad], null, 'product'); }, 'Invalid preference must be rejected');
}
packaging_rejects(function () { \Lavka\competitors_packaging_request(['packaging' => 'jar'], null, 'terms'); }, 'Terms cannot receive a product preference');
packaging_rejects(function () { \Lavka\competitors_packaging_request(['packaging' => 'kraft'], null, 'catalog'); }, 'Catalog cannot receive a product preference');
packaging_expect(\Lavka\competitors_packaging_request([], null, 'terms') === 'auto', 'Neutral setting allows old terms forms');

$db->beginTransaction();
\Lavka\competitors_packaging_store($db, 1, 'jar');
$db->rollBack();
packaging_expect($db->query('SELECT packaging FROM competitor_targets WHERE id=1')->fetchColumn() === 'zip', 'Preference participates in surrounding save transaction');
packaging_expect($db->query('SELECT match_group FROM competitor_targets WHERE id=1')->fetchColumn() === 'clove'
    && (int) $db->query('SELECT match_confirmed FROM competitor_targets WHERE id=1')->fetchColumn() === 1, 'Changing packaging never changes product matching');
echo json_encode(['migration' => 'passed', 'source_preferences' => 'passed', 'old_client_preservation' => 'passed', 'validation' => 'passed', 'shop_writes' => false]) . "\n";

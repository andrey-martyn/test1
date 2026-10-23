<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/src/CompetitorParser.php';

function variant_expect(bool $value, string $message): void
{
    if (!$value) throw new RuntimeException($message);
}
function variant_rejects(callable $operation): void
{
    try { $operation(); }
    catch (\Lavka\CompetitorParseError $error) { return; }
    throw new RuntimeException('An unresolved native variant must not acquire a page-level price');
}
function variant_page(string $body, string $head = ''): string
{
    return '<!doctype html><html><head><meta charset="utf-8">'.$head.'</head><body><main>'.$body.'</main></body></html>';
}
function variant_by_id(array $parsed): array
{
    $found = [];
    foreach ($parsed['offers'] as $offer) $found[$offer['source_variant_id']] = $offer;
    return $found;
}

// Small synthetic structures model the supplied DOM archives. No captured page,
// scripts, cookies, customer data, or snapshot prices are needed by production.
$target = ['kind' => 'product'];
$spiceryUrl = 'https://spiceryshop.com.ua/ru/content/products/example';
$spiceryBody = '<div class="product-details"><h1>Тестовая пряность</h1>'.
    '<table class="product-info"><tr><td>Наличие:</td><td>Есть в наличии</td></tr></table>'.
    '<div id="product"><select data-product="321" name="option[77]">'.
    '<option value="41" data-price="91.50 грн.">Пакет-струна РЕ 50 г</option>'.
    '<option value="42" data-price="96.50 грн." data-preorder-quantity="12">Дой-пак 50 г</option>'.
    '<option value="43" data-price="610.00 грн.">Дой-пак 500 г</option>'.
    '<option value="44" data-price="101.00 грн.">Баночка ПЭТ 100 мл</option>'.
    '<option value="45" data-price="33.00 грн." data-price-prefix="+">Дой-пак 100 г</option>'.
    '<option value="46" data-price="70.00 USD">Дой-пак 200 г</option>'.
    '<option value="47" data-price="99.00 грн." data-preorder-quantity="0">Дой-пак 20 г</option>'.
    '</select></div></div>'.
    '<aside><select data-product="999" name="option[9]"><option value="1" data-price="1.00 грн.">Дой-пак 500 г</option></select></aside>';
$spicery = variant_page($spiceryBody);
$result = \Lavka\competitors_parse($target, $spicery, $spiceryUrl);
$byId = variant_by_id($result);
variant_expect($result['method'] === 'spicery_option_prices' && count($byId) === 5, 'Native options scoped to the current product; currency and delta guards');
variant_expect($byId['41']['packaging'] === 'zip' && $byId['42']['packaging'] === 'doypack', 'Equal mass cannot merge zip and doypack');
variant_expect($byId['42']['price'] === '96.50' && $byId['43']['grams'] === 500.0, 'Full option price is paired with its exact mass');
variant_expect($byId['42']['variant_id'] === 'spicery:321:77:42', 'Native identity includes product, group, and value');
variant_expect($byId['44']['grams'] === null && $byId['44']['packaging'] === 'jar', 'Jar volume never becomes gram mass');
variant_expect($byId['42']['availability'] === 'in_stock' && $byId['47']['availability'] === 'unknown', 'Ambiguous zero preorder quantity never inherits in-stock state');
$withJson = variant_page($spiceryBody, '<script type="application/ld+json">'.\Lavka\encode([
    '@type' => 'Product', 'name' => 'Тестовая пряность', 'size' => '50 г',
    'offers' => ['@type' => 'Offer', 'price' => '2', 'priceCurrency' => 'UAH'],
]).'</script>');
$result = \Lavka\competitors_parse($target, $withJson, $spiceryUrl);
variant_expect(variant_by_id($result)['42']['price'] === '96.50', 'Exact native option prices take precedence over a page default JSON-LD price');
$updated = \Lavka\competitors_parse($target, str_replace('96.50', '97.25', $spicery), $spiceryUrl);
variant_expect(variant_by_id($updated)['42']['variant_id'] === $byId['42']['variant_id'], 'Price changes preserve native variant identity');
variant_rejects(function () use ($target, $spicery, $spiceryUrl) {
    \Lavka\competitors_parse($target, str_replace('</select>', '<option value="42" data-price="500.00 грн.">Дой-пак 50 г</option></select>', $spicery), $spiceryUrl);
});
variant_rejects(function () use ($target, $spicery, $spiceryUrl) {
    $extra = '<select data-product="321" name="option[78]"><option value="100" data-price="5.00 грн.">Мелкий помол</option></select>';
    \Lavka\competitors_parse($target, str_replace('</select></div>', '</select>'.$extra.'</div>', $spicery), $spiceryUrl);
});

$deluxeUrl = 'https://deluxe.com.ua/catalog/spetsii/example.html';
$deluxe = variant_page('<div class="priceblock"><h1>Тестовая пряность</h1><span class="available-pr">В наличии</span>'.
    '<ul class="itemPriceList"><li data-price-id="71" data-price="155" data-price-new="0"><div class="weight-amount">100 г</div><div class="weight-price">155 грн</div></li>'.
    '<li data-price-id="72" data-price="635" data-price-new="0"><div class="weight-amount">500 г</div><div class="weight-price">635 грн</div></li>'.
    '<li data-price-id="73" data-price="85" data-price-new="0"><div class="weight-amount">50 г</div><div class="weight-price">95 грн</div></li></ul></div>'.
    '<div class="related"><ul class="itemPriceList"><li data-price-id="80" data-price="1"><div class="weight-amount">500 г</div><div class="weight-price">1 грн</div></li></ul></div>');
$result = \Lavka\competitors_parse($target, $deluxe, $deluxeUrl); $byId = variant_by_id($result);
variant_expect($result['method'] === 'deluxe_variant_prices' && count($byId) === 2, 'Deluxe rejects unrelated and inconsistent visible prices');
variant_expect($byId['72']['grams'] === 500.0 && $byId['72']['price'] === '635.00' && $byId['72']['availability'] === 'in_stock', 'Deluxe exact weight-price and explicit availability');
variant_expect($byId['72']['packaging'] === 'unknown', 'Product photographs do not prove the packaging of each weight');
$sale = str_replace('data-price-new="0"', 'data-price-new="100"', $deluxe);
variant_rejects(function () use ($target, $sale, $deluxeUrl) { \Lavka\competitors_parse($target, $sale, $deluxeUrl); });

$bankaUrl = 'https://banka-speciy.in.ua/ua/example';
$banka = variant_page('<section class="product" data-product-id="17"><h1>Тестовая пряность</h1>'.
    '<input class="product-option-value" type="radio" id="product-size-17-201" name="option[9]" value="201" data-price="130.50 грн" data-description="100 г" data-name="100 г" data-min-quantity="1" data-special="">'.
    '<input class="product-option-value" type="radio" id="product-size-17-202" name="option[9]" value="202" data-price="652.50 грн" data-description="500 г" data-name="500 г" data-min-quantity="1" data-special="">'.
    '<input class="product-option-value" type="radio" id="product-size-17-203" name="option[9]" value="203" data-price="1200.00 грн" data-description="1 кг" data-name="Опт від 2 до 5 кг" data-min-quantity="2" data-special="">'.
    '<input class="product-option-value" type="radio" id="product-size-88-204" name="option[9]" value="204" data-price="1.00 грн" data-description="500 г" data-min-quantity="1">'.
    '<button>Купити</button></section>'.
    '<section class="product" data-product-id="88"><h2>Другой товар</h2><input class="product-option-value" type="radio" name="option[1]" value="9" data-price="1.00 грн" data-description="500 г" data-min-quantity="1"></section>');
$result = \Lavka\competitors_parse($target, $banka, $bankaUrl); $byId = variant_by_id($result);
variant_expect($result['method'] === 'banka_option_prices' && count($byId) === 3, 'Banka primary product excludes related variant controls');
variant_expect($byId['202']['grams'] === 500.0 && $byId['202']['price'] === '652.50', 'Banka data-price is the whole selected package price');
variant_expect($byId['203']['grams'] === 1000.0 && $byId['203']['min_quantity'] === 2.0 && $byId['203']['condition'] !== '', 'Wholesale description weight and minimum stay distinct');
variant_expect($byId['201']['availability'] === 'unknown', 'An enabled buy button is not proof of availability');

$speciiUrl = 'https://www.specii.in.ua/ua/catalog/whole/example/';
$specii = variant_page('<div itemscope itemtype="http://schema.org/Product"><h1>Тестовая пряность</h1>'.
    '<div><span class="here"><span class="cnt">Пакет-струна 20 г</span></span><ul id="bx_123_44_prop_31_list">'.
    '<li data-treevalue="31_10" class="bx_active move"><span class="cnt">Пакет-струна 20 г</span></li>'.
    '<li data-treevalue="31_105"><span class="cnt">Крафт-пакет 500 г</span></li></ul></div>'.
    '<div itemprop="offers"><div id="bx_123_44_price" itemprop="price" content="62.00">62 грн.</div>'.
    '<span itemprop="priceCurrency" content="UAH">грн.</span><link itemprop="availability" href="http://schema.org/InStock">'.
    '<a itemprop="url" href="'.$speciiUrl.'">Тестовая пряность</a></div></div>');
$result = \Lavka\competitors_parse(['kind' => 'product', 'grams_override' => 500], $specii, $speciiUrl);
variant_expect($result['method'] === 'specii_selected_variant' && count($result['offers']) === 1, 'Specii only captures the selected variant with a proven price link');
$selected = $result['offers'][0];
variant_expect($selected['grams'] === 20.0 && $selected['price'] === '62.00' && $selected['packaging'] === 'zip', 'Current 20g price never becomes an unpriced 500g kraft offer or a manual override');
variant_expect($selected['variant_id'] === 'specii:44:31_10' && in_array('variant_prices_unavailable', $result['warnings'], true), 'Missing prices for other variants remain explicit');
variant_rejects(function () use ($target, $specii, $speciiUrl) { \Lavka\competitors_parse($target, str_replace('bx_active move', '', $specii), $speciiUrl); });

foreach (['Дой-пак 500 г' => 'doypack', 'DoyPak 50 g' => 'doypack', 'Крафт-пакет 500 г' => 'kraft',
    'Пакет-струна РЕ 500 г' => 'zip', 'Баночка 100 мл / 30 г' => 'jar', '500 г' => 'unknown',
    'Дой-пак или крафт-пакет' => 'unknown'] as $label => $expected) {
    variant_expect(\Lavka\competitor_packaging($label) === $expected, 'Unambiguous packaging: '.$label);
}
$terms = \Lavka\competitor_commercial_text("Оплата карткою.\nПісляплата.\nВозврат в течение 14 дней.\nВідправляємо у робочі дні.\nБанковская карта 0000 0000 0000 0000\nОплата на UA00 0000 0000 0000 0000 0000 0000 0\nДоставка безкоштовна від 2000 грн.", 'Условия');
variant_expect(strpos($terms, 'карткою') !== false && strpos($terms, 'Післяплата') !== false && strpos($terms, 'Возврат') !== false, 'Commercial extraction retains payment and return facts');
variant_expect(strpos($terms, '0000') === false && strpos($terms, 'UA00') === false && strpos($terms, '2000 грн') !== false, 'Payment methods are retained while card and account requisites are omitted');

echo \Lavka\encode(['source_variants' => 'passed', 'packaging_identity' => 'passed',
    'relative_prices_and_wholesale' => 'passed', 'unresolved_prices_and_stock' => 'passed', 'real_network_requests' => 0])."\n";

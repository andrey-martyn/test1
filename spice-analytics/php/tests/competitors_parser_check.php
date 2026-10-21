<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/src/CompetitorParser.php';

function parser_expect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
function parser_rejects(string $code, callable $operation): void
{
    try { $operation(); }
    catch (\Lavka\CompetitorParseError $error) {
        parser_expect($error->errorCode === $code, 'Expected ' . $code . ', got ' . $error->errorCode);
        return;
    }
    throw new RuntimeException('Expected rejection: ' . $code);
}
function product_html(array $product, string $h1 = 'Синтетическая паприка'): string
{
    return '<!doctype html><html><head><meta charset="utf-8"><script type="application/ld+json">' .
        \Lavka\encode($product) . '</script></head><body><main><h1>' .
        htmlspecialchars($h1, ENT_QUOTES, 'UTF-8') . '</h1></main></body></html>';
}

$target = ['kind' => 'product', 'pack_label' => '', 'grams_override' => null];
$url = 'https://deluxe.com.ua/catalog/spetsii/test.html';
$product = ['@context' => 'https://schema.org', '@type' => 'Product', 'name' => 'Синтетическая паприка',
    'size' => '100 г', 'offers' => ['@type' => 'Offer', 'price' => '55.50', 'priceCurrency' => 'UAH', 'availability' => 'https://schema.org/InStock']];
$result = \Lavka\competitors_parse($target, product_html($product), $url);
parser_expect(count($result['offers']) === 1 && $result['offers'][0]['price'] === '55.50', 'Exact decimal price');
parser_expect($result['offers'][0]['grams'] === 100.0 && $result['offers'][0]['availability'] === 'in_stock', 'Explicit pack and stock');
parser_expect($result['method'] === 'json_ld', 'Structured data provenance');
parser_expect(\Lavka\competitor_currency('грн') === 'UAH', 'Lowercase Ukrainian currency');
$ambiguousJson = str_replace('</main>', '<select><option>100 г</option><option>500 г</option></select></main>', product_html($product));
$result = \Lavka\competitors_parse($target, $ambiguousJson, $url);
parser_expect($result['offers'][0]['grams'] === null, 'Single JSON-LD page price does not select a variant');
$perKiloSpec = $product;
$perKiloSpec['offers']['priceSpecification'] = ['@type' => 'UnitPriceSpecification', 'price' => '555', 'priceCurrency' => 'UAH',
    'referenceQuantity' => ['value' => 1, 'unitCode' => 'KGM']];
$result = \Lavka\competitors_parse($target, product_html($perKiloSpec), $url);
parser_expect($result['offers'][0]['unit'] === 'pack' && $result['offers'][0]['price'] === '55.50', 'Pack price keeps pack basis despite separate per-kg specification');
$restricted = $product;
$restricted['offers']['eligibleTransactionVolume'] = ['minValue' => 2000, 'currency' => 'UAH'];
$restricted['offers']['validForMemberTier'] = 'https://example.invalid/members';
$result = \Lavka\competitors_parse($target, product_html($restricted), $url);
parser_expect(strpos($result['offers'][0]['condition'], '2000') !== false && $result['offers'][0]['condition'] !== '', 'Order threshold and membership never become ordinary prices');
$restricted = $product;
$restricted['offers']['priceSpecification'] = ['eligibleQuantity' => ['minValue' => 0.5, 'maxValue' => 5, 'unitCode' => 'KGM']];
$result = \Lavka\competitors_parse($target, product_html($restricted), $url);
parser_expect($result['offers'][0]['condition'] !== '', 'Fractional kilogram eligibility remains conditional');
$saleJson = $product;
$saleJson['offers']['priceSpecification'] = ['@type' => 'UnitPriceSpecification', 'priceType' => 'https://schema.org/StrikethroughPrice', 'price' => '70', 'priceCurrency' => 'UAH'];
$result = \Lavka\competitors_parse($target, product_html($saleJson), $url);
parser_expect($result['offers'][0]['price'] === '55.50' && $result['offers'][0]['old_price'] === '70.00', 'Explicit struck-through JSON-LD price is historical, not current');
unset($saleJson['offers']['price']);
parser_rejects('price_unknown', function () use ($target, $url, $saleJson) { \Lavka\competitors_parse($target, product_html($saleJson), $url); });

$group = ['@context' => 'https://schema.org', '@type' => 'ProductGroup', 'name' => 'Синтетическая паприка', 'hasVariant' => [
    ['@type' => 'Product', 'name' => 'Синтетическая паприка', 'size' => '100 г', 'offers' => ['@type' => 'Offer', 'price' => '55.5', 'priceCurrency' => 'UAH']],
    ['@type' => 'Product', 'name' => 'Синтетическая паприка', 'size' => '500 г', 'offers' => ['@type' => 'Offer', 'price' => '210', 'priceCurrency' => 'UAH', 'eligibleQuantity' => ['minValue' => 5]]],
]];
$result = \Lavka\competitors_parse($target, product_html($group), $url);
parser_expect(count($result['offers']) === 2, 'Variants stay distinct');
$byWeight = []; foreach ($result['offers'] as $offer) $byWeight[(string) $offer['grams']] = $offer;
parser_expect($byWeight['100']['price'] === '55.50' && $byWeight['500']['price'] === '210.00', 'Weight-price pairing');
parser_expect($byWeight['500']['min_quantity'] === 5.0 && $byWeight['500']['condition'] !== '', 'Volume condition retained');

$aggregate = $product;
$aggregate['offers'] = ['@type' => 'AggregateOffer', 'lowPrice' => '10', 'highPrice' => '100', 'priceCurrency' => 'UAH'];
parser_rejects('variant_prices_unavailable', function () use ($target, $url, $aggregate) {
    \Lavka\competitors_parse($target, product_html($aggregate), $url);
});
$bad = $product;
$bad['offers']['price'] = '0';
parser_rejects('price_unknown', function () use ($target, $url, $bad) { \Lavka\competitors_parse($target, product_html($bad), $url); });
$bad['offers']['price'] = '-5';
parser_rejects('price_unknown', function () use ($target, $url, $bad) { \Lavka\competitors_parse($target, product_html($bad), $url); });
$bad['offers']['price'] = '50';
unset($bad['offers']['priceCurrency']);
parser_rejects('price_unknown', function () use ($target, $url, $bad) { \Lavka\competitors_parse($target, product_html($bad), $url); });

$related = $product;
$related['name'] = 'Другой товар';
$related['url'] = 'https://deluxe.com.ua/catalog/spetsii/other.html';
parser_rejects('price_unknown', function () use ($target, $url, $related) {
    \Lavka\competitors_parse($target, product_html($related, 'Нужный товар'), $url);
});

parser_expect(\Lavka\competitor_weight('Куркума 550 мг, 100 капсул') === null, 'Capsule strength is not pack weight');
parser_expect(\Lavka\competitor_weight('Гхі, 400 мл') === null, 'Volume is not weight');
parser_expect(\Lavka\competitor_weight('50 г / 200 г') === null, 'Multiple packs are ambiguous');
parser_expect(\Lavka\competitor_weight('1,5 кг') === 1500.0, 'Decimal kilogram weight');

$micro = '<html><head><meta charset="utf-8"></head><body><main itemscope itemtype="https://schema.org/Product"><h1>Корица 50 г</h1>' .
    '<div itemprop="offers" itemscope itemtype="https://schema.org/Offer"><meta itemprop="priceCurrency" content="UAH"><meta itemprop="price" content="78.90">' .
    '<link itemprop="availability" href="https://schema.org/OutOfStock"></div></main></body></html>';
$result = \Lavka\competitors_parse($target, $micro, $url);
parser_expect($result['method'] === 'microdata' && $result['offers'][0]['availability'] === 'out_of_stock', 'Out of stock distinct from request error');
parser_expect($result['offers'][0]['grams'] === 50.0, 'Microdata title gives explicit single pack');
$result = \Lavka\competitors_parse($target, str_replace('</main>', '<select><option>50 г</option><option>200 г</option></select></main>', $micro), $url);
parser_expect($result['offers'][0]['grams'] === null, 'Microdata page price also obeys ambiguous variant guard');
$relatedMicro = '<html><head><meta charset="utf-8"></head><body><main><h1>Нужный товар 50 г</h1><div class="product-thumb">' .
    '<div itemscope itemtype="https://schema.org/Product"><span itemprop="name">Другой товар</span><div itemprop="offers">' .
    '<meta itemprop="priceCurrency" content="UAH"><meta itemprop="price" content="123"></div></div></div></main></body></html>';
parser_rejects('price_unknown', function () use ($target, $url, $relatedMicro) { \Lavka\competitors_parse($target, $relatedMicro, $url); });
$relatedData = '<html><head><meta itemprop="priceCurrency" content="UAH"></head><body><main><h1>Requested</h1>' .
    '<div class="related-products"><span data-price="99" data-grams="100">Recommendation</span></div></main></body></html>';
parser_rejects('price_unknown', function () use ($target, $url, $relatedData) { \Lavka\competitors_parse($target, $relatedData, $url); });

$explicit = '<html><head><meta charset="utf-8"><meta itemprop="priceCurrency" content="UAH"></head><body><main><h1>Корица</h1>' .
    '<option data-price="25" data-grams="50" data-pack="50 г"></option><option data-price="85" data-grams="200" data-pack="200 г"></option>' .
    '<option data-price="10" data-grams="1000" data-pack="1 кг" data-price-prefix="+"></option></main></body></html>';
$result = \Lavka\competitors_parse($target, $explicit, $url);
parser_expect(count($result['offers']) === 2, 'Absolute prices accepted; relative surcharge excluded');
$result = \Lavka\competitors_parse(array_merge($target, ['grams_override' => 999]), $explicit, $url);
foreach ($result['offers'] as $offer) parser_expect($offer['grams'] !== 999.0, 'Manual weight never overwrites variant weights');

$metadata = '<html><head><meta charset="utf-8"><meta property="product:price:amount" content="50"><meta property="product:price:currency" content="UAH"></head>' .
    '<body><main><h1>Корица 50 г</h1><select><option>50 г</option><option>200 г</option></select></main></body></html>';
$result = \Lavka\competitors_parse($target, $metadata, $url);
parser_expect($result['offers'][0]['grams'] === null && in_array('variant_prices_unavailable', $result['warnings'], true), 'Unknown selected variant stays unweighted');
$result = \Lavka\competitors_parse(['kind' => 'product', 'pack_label' => '50 г подтверждено вручную', 'grams_override' => 50], $metadata, $url);
parser_expect($result['offers'][0]['grams'] === 50.0 && in_array('weight_set_manually', $result['warnings'], true), 'Explicit manual weight documented');
$saleMetadata = str_replace('</head>', '<meta property="product:sale_price:amount" content="40"><meta property="product:sale_price:currency" content="UAH"></head>', $metadata);
$result = \Lavka\competitors_parse($target, $saleMetadata, $url);
parser_expect($result['offers'][0]['price'] === '40.00' && $result['offers'][0]['old_price'] === '50.00', 'Explicit sale and original metadata retained separately');

$terms = '<html><head><meta charset="utf-8"></head><body><nav>Меню: каждый раз новое</nav><main><h1>Доставка и скидки</h1>' .
    '<p>Минимальный заказ — 200 грн.</p><p>От 2000 грн скидка 5%; скидки не суммируются.</p>' .
    '<p>Банковская карта 0000 0000 0000 0000</p><script>random()</script></main><footer>Динамический счетчик 99</footer></body></html>';
$result = \Lavka\competitors_parse(['kind' => 'terms'], $terms, $url);
parser_expect(strpos($result['text'], 'Минимальный заказ') !== false && strpos($result['text'], 'не суммируются') !== false, 'Conditions and exclusions retained');
parser_expect(strpos($result['text'], '0000') === false && strpos($result['text'], 'Меню') === false && strpos($result['text'], 'счетчик') === false, 'Unrelated boilerplate and payment details omitted');
parser_rejects('commercial_terms_unrecognized', function () use ($url) {
    \Lavka\competitors_parse(['kind' => 'terms'], '<html><main><h1>Добро пожаловать</h1><p>Здесь фотографии нашего здания, история основания и сведения о заведении.</p></main></html>', $url);
});

$listing = '<html><head><meta charset="utf-8"></head><body><main><h1>Новинки</h1><div class="product-thumb"><h4><a href="/ua/paprika">Паприка</a></h4></div>' .
    '<div class="product-thumb"><h4><a href="https://evil.invalid/steal">Посторонний</a></h4></div><nav><a href="/ua/spices">Категория</a></nav>' .
    '</main></body></html>';
$result = \Lavka\competitors_parse(['kind' => 'catalog'], $listing, 'https://banka-speciy.in.ua/ua/novi-nadhodzhennja');
parser_expect(count($result['links']) === 1 && $result['links'][0]['url'] === 'https://banka-speciy.in.ua/ua/paprika', 'Only local product-card links');
parser_expect(in_array('watched_page_only_not_full_catalog', $result['warnings'], true), 'Coverage explicit');

parser_rejects('captcha', function () use ($target, $url) { \Lavka\competitors_parse($target, '<html><title>Just a moment...</title></html>', $url); });
foreach (['product', 'terms', 'catalog'] as $kind) {
    parser_rejects('upstream_error', function () use ($kind, $url) { \Lavka\competitors_parse(['kind' => $kind], '<html><h1>Bitrix</h1>Mysql connect error (2002)</html>', $url); });
}
parser_rejects('invalid_content', function () use ($target, $url) { \Lavka\competitors_parse($target, str_repeat('x', 2097153), $url); });
echo \Lavka\encode(['structured_offers' => 'passed', 'weight_and_tiers' => 'passed', 'terms_and_catalog' => 'passed',
    'error_classification' => 'passed', 'real_network_requests' => 0]) . "\n";

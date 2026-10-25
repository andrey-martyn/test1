<?php

declare(strict_types=1);
namespace Lavka;

require_once __DIR__ . '/common.php';

final class CompetitorParseError extends \RuntimeException
{
    public $errorCode;
    public function __construct(string $code)
    {
        $this->errorCode = $code;
        parent::__construct($code);
    }
}

function competitor_text(string $text): string
{
    return trim(preg_replace('/[\s\x{00a0}\x{202f}]+/u', ' ', $text));
}

function competitor_dom(string $html): array
{
    if (strlen($html) > 2097152 || trim($html) === '') throw new CompetitorParseError('invalid_content');
    $previous = libxml_use_internal_errors(true);
    try {
        $doc = new \DOMDocument();
        if (!$doc->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            throw new CompetitorParseError('invalid_content');
        }
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
    $xpath = new \DOMXPath($doc);
    $title = competitor_first_text($xpath, ['//h1', '//title']);
    if (preg_match('/(?:mysql(?:i)?[ _-]?(?:connect|query)?[ _-]?error|mysql connect error|bitrix.*(?:exception|connect error)|(?:fatal error|uncaught (?:error|exception)))/iu', competitor_text($doc->textContent))) {
        throw new CompetitorParseError('upstream_error');
    }
    if (preg_match('/(?:just a moment|attention required|access denied|verify (?:you are|that you)|checking your browser|подтвердите.*человек|перевірте.*людина|captcha)/iu', $title)
        || $xpath->query('//*[@id="challenge-form" or @id="cf-challenge-running" or @id="cf-wrapper"]')->length) {
        throw new CompetitorParseError('captcha');
    }
    return [$doc, $xpath];
}

function competitor_first_node(\DOMXPath $xpath, array $paths, ?\DOMNode $context = null): ?\DOMNode
{
    foreach ($paths as $path) {
        $nodes = $xpath->query($path, $context);
        if ($nodes && $nodes->length) return $nodes->item(0);
    }
    return null;
}

function competitor_node_value(?\DOMNode $node): string
{
    if (!$node) return '';
    if ($node instanceof \DOMElement) {
        foreach (['content', 'value', 'href'] as $attribute) {
            if ($node->hasAttribute($attribute) && $node->getAttribute($attribute) !== '') return competitor_text($node->getAttribute($attribute));
        }
    }
    return competitor_text($node->textContent);
}

function competitor_first_text(\DOMXPath $xpath, array $paths, ?\DOMNode $context = null): string
{
    return competitor_node_value(competitor_first_node($xpath, $paths, $context));
}

function competitor_decimal($value): ?string
{
    if (!is_scalar($value) || is_bool($value)) return null;
    $value = str_replace(["\xc2\xa0", "\xe2\x80\xaf", ' '], '', trim((string) $value));
    if (strpos($value, ',') !== false && strpos($value, '.') === false) $value = str_replace(',', '.', $value);
    if (!preg_match('/^\d{1,9}(?:\.\d{1,6})?$/D', $value) || bccomp($value, '0', 6) <= 0) return null;
    $rounded = bcadd($value, '0', 2);
    return bccomp($rounded, '0', 2) > 0 ? $rounded : bcadd($value, '0', 6);
}

function competitor_currency($value): string
{
    $value = mb_strtoupper(trim(is_scalar($value) ? (string) $value : ''), 'UTF-8');
    if (in_array($value, ['ГРН', 'ГРН.', '₴', 'UAH'], true)) return 'UAH';
    return preg_match('/^[A-Z]{3}$/D', $value) ? $value : '';
}

function competitor_weight(string $label): ?float
{
    // Milligrams in capsule strength and millilitres are deliberately excluded.
    preg_match_all('/(?<![\p{L}\d])(\d{1,6}(?:[.,]\d{1,3})?)\s*(кг|kg|гр\.?|г|g)(?![\p{L}])/iu', $label, $matches, PREG_SET_ORDER);
    $weights = [];
    foreach ($matches as $match) {
        $weight = (float) str_replace(',', '.', $match[1]);
        if (in_array(mb_strtolower($match[2]), ['кг', 'kg'], true)) $weight *= 1000;
        if ($weight > 0 && $weight <= 1000000) $weights[(string) $weight] = $weight;
    }
    return count($weights) === 1 ? array_values($weights)[0] : null;
}

/** Packaging is independent of mass; a photograph is not variant evidence. */
function competitor_packaging(string $label): string
{
    $label = mb_strtolower(competitor_text($label));
    $found = [];
    foreach ([
        'doypack' => '/(?:дой[\s\-‐‑–—]*пак|doy[\s-]*(?:pack|pak))/u',
        'kraft' => '/(?:крафт|kraft)/u',
        'zip' => '/(?:пакет[\s\-‐‑–—]*струна|zip[\s-]*(?:lock|bag)?|зіп[\s-]*пак|зип[\s-]*пак)/u',
        'jar' => '/(?:баночк|банк[аиуеі]|скло|склян|стекл|(?<!\p{L})(?:jar|glass|пэт|pet)(?!\p{L}))/u',
    ] as $type => $pattern) if (preg_match($pattern, $label)) $found[] = $type;
    return count($found) === 1 ? $found[0] : 'unknown';
}

function competitor_availability($value): string
{
    $value = mb_strtolower(competitor_text(is_scalar($value) ? (string) $value : ''));
    if (preg_match('~(?:^|[/#])(?:outofstock|soldout|discontinued)$~', $value)
        || preg_match('/(?:нет в наличии|немає в наявності|нема[єа]? в наявност|відсутн|закінчився|закончился)/u', $value)) return 'out_of_stock';
    if (preg_match('~(?:^|[/#])(?:preorder|presale|backorder)$~', $value)
        || preg_match('/(?:под заказ|під замовлення|передзамовлення|предзаказ)/u', $value)) return 'preorder';
    if (preg_match('~(?:^|[/#])(?:instock|limitedavailability|onlineonly)$~', $value)
        || preg_match('/(?:есть в наличии|в наличии|є в наявності|в наявності|у наявності)/u', $value)) return 'in_stock';
    return 'unknown';
}

function competitor_types(array $node): array
{
    $types = $node['@type'] ?? [];
    return array_map(function ($type) {
        return preg_replace('~^.*[/#]~', '', is_string($type) ? $type : '');
    }, is_array($types) ? $types : [$types]);
}

function competitor_json_nodes(\DOMXPath $xpath): array
{
    $found = [];
    $remaining = 6000;
    $walk = function ($value, int $depth) use (&$walk, &$found, &$remaining) {
        if ($depth > 16 || --$remaining < 0 || !is_array($value)) return;
        if (isset($value['@type'])) $found[] = $value;
        foreach ($value as $child) if (is_array($child)) $walk($child, $depth + 1);
    };
    foreach ($xpath->query('//script[contains(translate(@type,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"ld+json")]') as $script) {
        if (strlen($script->textContent) > 524288) continue;
        $value = json_decode($script->textContent, true, 32);
        if (is_array($value)) $walk($value, 0);
    }
    return $found;
}

function competitor_absolute_url(string $link, string $base): ?string
{
    $link = html_entity_decode(trim($link), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if ($link === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $link) || strlen($link) > 2048) return null;
    $origin = parse_url($base);
    if (!$origin || empty($origin['host'])) return null;
    if (substr($link, 0, 2) === '//') $link = 'https:' . $link;
    elseif (!preg_match('~^[a-z][a-z0-9+.-]*:~i', $link)) {
        if ($link[0] === '#') return null;
        $path = $origin['path'] ?? '/';
        $directory = substr($path, -1) === '/' ? $path : rtrim(dirname($path), '/\\') . '/';
        $link = 'https://' . $origin['host'] . ($link[0] === '/' ? '' : $directory) . $link;
    }
    $url = parse_url($link);
    if (!$url || ($url['scheme'] ?? '') !== 'https' || isset($url['user']) || isset($url['pass']) || isset($url['port']) && $url['port'] !== 443) return null;
    if (strtolower($url['host'] ?? '') !== strtolower($origin['host'])) {
        if (preg_replace('/^www\./i', '', $url['host'] ?? '') !== preg_replace('/^www\./i', '', $origin['host'])) return null;
    }
    $parts = [];
    foreach (explode('/', $url['path'] ?? '/') as $part) {
        if ($part === '..') array_pop($parts);
        elseif ($part !== '.' && $part !== '') $parts[] = $part;
    }
    $path = '/' . implode('/', $parts);
    if (substr($url['path'] ?? '', -1) === '/' && $path !== '/') $path .= '/';
    return 'https://' . strtolower($url['host']) . $path . (isset($url['query']) ? '?' . $url['query'] : '');
}

function competitor_offer(array $node, array $product, string $currency, string $availability): ?array
{
    if (in_array('AggregateOffer', competitor_types($node), true)) return null; // "from" is not an actual variant.
    $spec = isset($node['priceSpecification']) && is_array($node['priceSpecification']) ? $node['priceSpecification'] : [];
    $packPrice = competitor_decimal($node['price'] ?? null);
    $specType = preg_replace('~^.*[/#]~', '', is_string($spec['priceType'] ?? null) ? $spec['priceType'] : '');
    if ($packPrice === null && in_array($specType, ['ListPrice', 'StrikethroughPrice'], true)) return null;
    $price = $packPrice ?? competitor_decimal($spec['price'] ?? null);
    if ($price === null) return null;
    $currency = competitor_currency($node['priceCurrency'] ?? $spec['priceCurrency'] ?? $currency);
    if ($currency === '') return null;
    $label = competitor_text((string) ($node['name'] ?? $node['size'] ?? $product['size'] ?? $product['name'] ?? 'Фасовка не указана'));
    $grams = null;
    $weight = $node['weight'] ?? $product['weight'] ?? null;
    if (is_array($weight) && is_scalar($weight['value'] ?? null)) {
        $unit = strtoupper((string) ($weight['unitCode'] ?? $weight['unitText'] ?? ''));
        $value = competitor_decimal($weight['value']);
        if ($value !== null && in_array($unit, ['GRM', 'G', 'Г', 'KGM', 'KG', 'КГ'], true)) $grams = (float) $value * (in_array($unit, ['KGM', 'KG', 'КГ'], true) ? 1000 : 1);
    } elseif (is_string($weight)) $grams = competitor_weight($weight);
    if ($grams === null) $grams = competitor_weight($label);
    $quantity = $node['eligibleQuantity'] ?? $spec['eligibleQuantity'] ?? [];
    $min = is_array($quantity) ? ($quantity['minValue'] ?? 1) : 1;
    $min = is_numeric($min) && (float) $min > 0 ? (float) $min : 1;
    $conditions = [];
    if ($quantity) {
        $quantityUnit = is_array($quantity) ? (string) ($quantity['unitText'] ?? $quantity['unitCode'] ?? 'единиц') : 'единиц';
        $maximum = is_array($quantity) && is_numeric($quantity['maxValue'] ?? null) ? (string) $quantity['maxValue'] : '';
        if ($min !== 1.0 && $min !== 1 || $maximum !== '' || !in_array(mb_strtoupper($quantityUnit), ['ЕДИНИЦ', 'C62', 'H87', 'PCE'], true)) {
            $conditions[] = 'Количество: от ' . $min . ($maximum !== '' ? ' до ' . $maximum : '') . ' ' . $quantityUnit;
        }
    }
    foreach ([$node, $spec] as $restrictions) {
        if (isset($restrictions['eligibleTransactionVolume'])) {
            $volume = $restrictions['eligibleTransactionVolume'];
            $amount = is_array($volume) && is_scalar($volume['minValue'] ?? null) ? (string) $volume['minValue'] : '';
            $conditions[] = 'Условие по сумме заказа' . ($amount !== '' ? ': от ' . $amount . ' ' . (is_scalar($volume['currency'] ?? null) ? (string) $volume['currency'] : $currency) : '; подробности у источника');
        }
        foreach (['eligibleCustomerType', 'validForMemberTier', 'eligibleRegion', 'eligibleDuration', 'billingDuration'] as $restriction) {
            if (isset($restrictions[$restriction])) $conditions[] = 'Цена с дополнительными условиями для покупателя; проверьте страницу источника';
        }
        if (isset($restrictions['validFrom']) && is_string($restrictions['validFrom']) && strtotime($restrictions['validFrom']) > time()) $conditions[] = 'Предложение ещё не вступило в силу';
        foreach (['validThrough', 'priceValidUntil'] as $expiry) {
            if (isset($restrictions[$expiry]) && is_string($restrictions[$expiry]) && strtotime($restrictions[$expiry]) !== false && strtotime($restrictions[$expiry]) < time()) $conditions[] = 'Указанный срок предложения истёк';
        }
    }
    $condition = mb_substr(implode('; ', array_unique($conditions)), 0, 1000);
    $reference = $spec['referenceQuantity'] ?? [];
    $unit = 'pack';
    if ($packPrice === null && is_array($reference) && strtoupper((string) ($reference['unitCode'] ?? '')) === 'KGM' && (float) ($reference['value'] ?? 1) === 1.0) {
        $unit = 'kg';
        $grams = 1000.0;
    }
    $oldPrice = null;
    $specifications = array_is_list($spec) ? $spec : [$spec];
    foreach ($specifications as $specification) if (is_array($specification)) {
        $priceType = preg_replace('~^.*[/#]~', '', is_string($specification['priceType'] ?? null) ? $specification['priceType'] : '');
        $original = competitor_decimal($specification['price'] ?? null);
        if (in_array($priceType, ['ListPrice', 'StrikethroughPrice'], true) && $original !== null
            && competitor_currency($specification['priceCurrency'] ?? $currency) === $currency && bccomp($original, $price, 6) > 0) $oldPrice = $original;
    }
    return ['key' => $label, 'label' => $label, 'price' => $price, 'old_price' => $oldPrice,
        'currency' => $currency, 'grams' => $grams, 'availability' => competitor_availability($node['availability'] ?? $availability),
        'min_quantity' => $min, 'unit' => $unit, 'condition' => $condition,
        '_paired_variant' => !empty($product['_is_variant']) || isset($node['weight']) || competitor_weight((string) ($node['name'] ?? $node['size'] ?? '')) !== null];
}

function competitor_product_json(array $nodes, string $title, string $url): array
{
    $products = array_values(array_filter($nodes, function ($node) {
        return (bool) array_intersect(['Product', 'ProductGroup'], competitor_types($node));
    }));
    if (!$products) return [[], '', []];
    $selected = [];
    foreach ($products as $product) {
        $candidate = is_string($product['url'] ?? null) ? competitor_absolute_url($product['url'], $url) : null;
        $name = is_scalar($product['name'] ?? null) ? normalize((string) $product['name']) : '';
        if (($candidate !== null && rtrim($candidate, '/') === rtrim($url, '/')) || ($name !== '' && $name === normalize($title))) $selected[] = $product;
    }
    if (!$selected && count($products) === 1) {
        $candidate = $products[0];
        $name = is_scalar($candidate['name'] ?? null) ? normalize((string) $candidate['name']) : '';
        // A related-product block must not masquerade as the requested product.
        $declaredUrl = is_string($candidate['url'] ?? null) ? competitor_absolute_url($candidate['url'], $url) : null;
        if (($declaredUrl === null || rtrim($declaredUrl, '/') === rtrim($url, '/'))
            && ($title === '' || ($name !== '' && (str_contains(normalize($title), $name) || str_contains($name, normalize($title)))))) {
            $selected = $products;
        }
    }
    if (!$selected) return [[], '', ['multiple_products_unresolved']];
    $offers = [];
    $hasAggregate = false;
    $chosenTitle = '';
    $extract = function (array $product) use (&$offers, &$hasAggregate, &$chosenTitle) {
        if (!$chosenTitle && is_string($product['name'] ?? null)) $chosenTitle = competitor_text($product['name']);
        $items = $product['offers'] ?? [];
        if (!is_array($items)) return;
        if (!array_is_list($items)) $items = [$items];
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            if (in_array('AggregateOffer', competitor_types($item), true)) {
                $hasAggregate = true;
                $nested = $item['offers'] ?? [];
                if (is_array($nested)) {
                    if (!array_is_list($nested)) $nested = [$nested];
                    foreach ($nested as $entry) if (is_array($entry)) {
                        $offer = competitor_offer($entry, $product, (string) ($item['priceCurrency'] ?? ''), (string) ($item['availability'] ?? ''));
                        if ($offer) $offers[] = $offer;
                    }
                }
            } else {
                $offer = competitor_offer($item, $product, (string) ($product['priceCurrency'] ?? ''), '');
                if ($offer) $offers[] = $offer;
            }
        }
    };
    foreach ($selected as $product) {
        $variants = $product['hasVariant'] ?? [];
        if (is_array($variants) && !array_is_list($variants)) $variants = [$variants];
        if ($variants) {
            foreach ($variants as $variant) if (is_array($variant)) { $variant['_is_variant'] = true; $extract($variant); }
        } else $extract($product);
    }
    return [$offers, $chosenTitle, $hasAggregate ? ['aggregate_from_price_ignored'] : []];
}

function competitor_main_node(\DOMXPath $xpath): \DOMNode
{
    $heading = null;
    $root = $xpath->query('/*')->item(0);
    foreach ($xpath->query('//h1') as $candidate) {
        if (!competitor_catalog_navigation($candidate, $root)) { $heading = $candidate; break; }
    }
    foreach ([
        '//main', '//*[@id="content"]', '//article',
        '//*[contains(concat(" ",normalize-space(@class)," ")," page-content ")]',
        '//*[contains(concat(" ",normalize-space(@class)," ")," main-content ")]'
    ] as $path) {
        foreach ($xpath->query($path) as $candidate) {
            if (!$heading && strtolower($candidate->nodeName) !== 'article') return $candidate;
            // A review/article above the real content is not the page's main scope.
            for ($node = $heading; $node; $node = $node->parentNode) {
                if ($node->isSameNode($candidate)) return $candidate;
            }
        }
    }
    return competitor_first_node($xpath, ['//body']) ?: $xpath->query('/*')->item(0);
}

function competitor_clean_scope(\DOMXPath $xpath, \DOMNode $scope): \DOMNode
{
    $clone = $scope->cloneNode(true);
    $doc = new \DOMDocument('1.0', 'UTF-8');
    $doc->appendChild($doc->importNode($clone, true));
    $clean = new \DOMXPath($doc);
    $remove = $clean->query('//script|//style|//noscript|//nav|//header|//footer|//form|//iframe|//button|//*[contains(concat(" ",normalize-space(@class)," ")," breadcrumb ") or contains(concat(" ",normalize-space(@class)," ")," sidebar ") or contains(concat(" ",normalize-space(@class)," ")," menu ") or contains(concat(" ",normalize-space(@class)," ")," cookie-banner ")]');
    $items = [];
    foreach ($remove as $node) $items[] = $node;
    foreach ($items as $node) if ($node->parentNode) $node->parentNode->removeChild($node);
    return $doc->documentElement;
}

function competitor_scoped_text(\DOMXPath $xpath, \DOMNode $scope): string
{
    $clean = competitor_clean_scope($xpath, $scope);
    $parts = [];
    $walk = function (\DOMNode $node) use (&$walk, &$parts) {
        if ($node instanceof \DOMText) {
            $parts[] = $node->textContent;
            return;
        }
        foreach ($node->childNodes as $child) $walk($child);
        if (in_array(strtolower($node->nodeName), ['p', 'div', 'li', 'tr', 'h1', 'h2', 'h3', 'br', 'section', 'article'], true)) $parts[] = "\n";
    };
    $walk($clean);
    $lines = preg_split('/\n+/', implode(' ', $parts));
    $lines = array_values(array_filter(array_map(__NAMESPACE__ . '\\competitor_text', $lines), function ($line) { return $line !== ''; }));
    return mb_substr(implode("\n", $lines), 0, 12000);
}

function competitor_commercial_text(string $text, string $title): string
{
    $lines = [];
    foreach (explode("\n", $text) as $line) {
        // Keep commercial conditions and their exceptions; omit menus and payment requisites.
        if (preg_match('/(?:реквизит|реквізит|IBAN|МФО|ЄДРПОУ|ЕДРПОУ|(?<![\p{L}\d])[A-Z]{2}\s*\d{2}(?:[\s-]*[A-Z\d]){11,30}(?![\p{L}\d])|(?<!\d)(?:\d[\s-]*){13,19}(?!\d))/iu', $line)) continue;
        if (preg_match('/(?:скид|зниж|накоп|перв|перш|замов|заказ|услов|умов|исключ|винят|роздр|опт|достав|відправ|отправ|самовывоз|самовив|безкош|бесплат|мінім|миним|порог|нов[ао]я?\s+пошт|укрпоч|укрпошт|Meest|упаков|фасов|оплат|плат[еі]ж|карт|Visa|Mastercard|Apple\s*Pay|Google\s*Pay|LiqPay|PayPal|WayForPay|готів|наличн|наложен|післяплат|предоплат|повернен|возврат|обмін|обмен|робоч|рабоч|термін|срок|%|грн|UAH|HoReCa|перевіз|перевоз|промокод|купон)/iu', $line)) {
            $lines[] = $line;
        }
    }
    if (!$lines) throw new CompetitorParseError('commercial_terms_unrecognized');
    return mb_substr(($title !== '' ? $title . "\n" : '') . implode("\n", $lines), 0, 12000);
}

function competitor_meta_currency(\DOMXPath $xpath): string
{
    return competitor_currency(competitor_first_text($xpath, [
        '//meta[@property="product:price:currency"]', '//head/meta[@itemprop="priceCurrency"]'
    ]));
}

function competitor_related_node(\DOMNode $node, ?\DOMNode $currentProduct, \DOMNode $scope): bool
{
    for ($parent = $node; $parent instanceof \DOMElement; $parent = $parent->parentNode) {
        if ($currentProduct && $parent->isSameNode($currentProduct)) return false;
        if (preg_match('/(?:^|\s)(?:product-thumb|product-layout|product-card|product-item|related|related-products|recommendations|recommended|also-buy)(?:\s|$)/', $parent->getAttribute('class'))
            || strtolower($parent->nodeName) === 'aside'
            || preg_match('~/Product$~', $parent->getAttribute('itemtype'))) return true;
        if ($parent->isSameNode($scope)) break;
    }
    return false;
}

/** Currency-bearing absolute text, never an option surcharge or a "from" price. */
function competitor_variant_money(string $text): ?array
{
    $text = competitor_text($text);
    if (!preg_match('/^(\d[\d\s\x{00a0}\x{202f}]*(?:[.,]\d{1,6})?)\s*(грн\.?|UAH|₴)$/iuD', $text, $match)) return null;
    $price = competitor_decimal($match[1]);
    $currency = competitor_currency($match[2]);
    return $price !== null && $currency !== '' ? ['price' => $price, 'currency' => $currency] : null;
}

function competitor_variant_offer(string $label, array $money, string $availability, string $variantId, string $nativeId, ?float $grams = null, float $minimum = 1, string $condition = ''): array
{
    return ['key' => $variantId, 'label' => $label, 'price' => $money['price'], 'old_price' => null,
        'currency' => $money['currency'], 'grams' => $grams ?? competitor_weight($label),
        'packaging' => competitor_packaging($label), 'variant_id' => $variantId, 'source_variant_id' => $nativeId,
        'availability' => $availability, 'min_quantity' => $minimum, 'unit' => 'pack',
        'condition' => $condition, '_paired_variant' => true];
}

/** Known public storefront structures, restricted to their own current product. */
function competitor_source_variants(\DOMXPath $xpath, \DOMNode $scope, string $title, string $url): array
{
    $host = preg_replace('/^www\./i', '', strtolower(parse_url($url, PHP_URL_HOST) ?: ''));
    $offers = []; $warnings = [];
    if ($host === 'spiceryshop.com.ua') {
        $selects = []; $priceGroups = 0;
        foreach ($xpath->query('//*[@id="product"]//select[@data-product and starts-with(@name,"option[")]') as $select) {
            if ($xpath->query('./option[@data-price]', $select)->length) $priceGroups++;
            foreach ($xpath->query('./option[@data-price]', $select) as $option) if (competitor_weight($option->textContent) !== null) { $selects[] = $select; break; }
        }
        if ($priceGroups > 1) return [[], 'spicery_option_prices', ['option_combination_unsupported']];
        if (count($selects) !== 1) return [[], '', []];
        $select = $selects[0]; $productId = $select->getAttribute('data-product');
        if (!preg_match('/^[1-9][0-9]{0,11}$/D', $productId) || !preg_match('/^option\[([1-9][0-9]{0,11})\]$/D', $select->getAttribute('name'), $group)) return [[], '', []];
        $details = null;
        for ($parent = $select->parentNode, $depth = 0; $parent instanceof \DOMElement && $depth++ < 5; $parent = $parent->parentNode) {
            if (normalize(competitor_first_text($xpath, ['.//h1'], $parent)) === normalize($title) && $title !== '') { $details = $parent; break; }
        }
        if (!$details) return [[], '', []];
        $availability = competitor_availability(competitor_first_text($xpath, ['.//table[contains(concat(" ",normalize-space(@class)," ")," product-info ")]//tr[td[contains(.,"Наличие") or contains(.,"Наявність")]]/td[last()]', './/*[@itemprop="availability"]'], $details));
        foreach ($xpath->query('./option[@data-price and @value]', $select) as $option) {
            if (!preg_match('/^[1-9][0-9]{0,11}$/D', $option->getAttribute('value'))) continue;
            if ($option->hasAttribute('data-price-prefix') && $option->getAttribute('data-price-prefix') !== '=') continue;
            $money = competitor_variant_money($option->getAttribute('data-price'));
            if (!$money) { $warnings[] = 'variant_prices_unavailable'; continue; }
            $label = competitor_text($option->textContent); $nativeId = $option->getAttribute('value');
            $stock = $option->hasAttribute('disabled') || $option->getAttribute('data-preorder-quantity') === '0' ? 'unknown' : $availability;
            $offers[] = competitor_variant_offer($label, $money, $stock, 'spicery:'.$productId.':'.$group[1].':'.$nativeId, $nativeId);
        }
        return [$offers, 'spicery_option_prices', $warnings];
    }
    if ($host === 'deluxe.com.ua') {
        foreach ($xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," priceblock ")]') as $block) {
            if ($title === '' || normalize(competitor_first_text($xpath, ['.//h1'], $block)) !== normalize($title)) continue;
            $availability = competitor_availability(competitor_first_text($xpath, ['.//*[contains(concat(" ",normalize-space(@class)," ")," available-pr ")]', './/*[@itemprop="availability"]'], $block));
            foreach ($xpath->query('.//ul[contains(concat(" ",normalize-space(@class)," ")," itemPriceList ")]/li[@data-price-id and @data-price]', $block) as $node) {
                $nativeId = $node->getAttribute('data-price-id');
                if (!preg_match('/^[1-9][0-9]{0,11}$/D', $nativeId)) continue;
                $label = competitor_first_text($xpath, ['.//*[contains(concat(" ",normalize-space(@class)," ")," weight-amount ")]'], $node);
                $price = competitor_decimal($node->getAttribute('data-price'));
                $visible = competitor_variant_money(competitor_first_text($xpath, ['.//*[contains(concat(" ",normalize-space(@class)," ")," weight-price ")]'], $node));
                if ($price === null || !$visible || $price !== $visible['price'] || competitor_weight($label) === null) continue;
                // Nonzero data-price-new has not yet been verified against a sale page.
                $newPrice = trim($node->getAttribute('data-price-new'));
                if ($newPrice !== '' && !preg_match('/^0(?:[.,]0+)?$/D', $newPrice)) { $warnings[] = 'variant_sale_unresolved'; continue; }
                $offers[] = competitor_variant_offer($label, $visible, $availability, 'deluxe:'.substr(hash('sha256', $url), 0, 16).':'.$nativeId, $nativeId);
            }
            return [$offers, 'deluxe_variant_prices', $warnings];
        }
        return [[], '', $warnings];
    }
    if ($host === 'banka-speciy.in.ua') {
        foreach ($xpath->query('//section[contains(concat(" ",normalize-space(@class)," ")," product ") and @data-product-id]') as $block) {
            $productId = $block->getAttribute('data-product-id');
            if (!preg_match('/^[1-9][0-9]{0,11}$/D', $productId) || $title === '' || normalize(competitor_first_text($xpath, ['.//h1'], $block)) !== normalize($title)) continue;
            $availability = competitor_availability(competitor_first_text($xpath, ['.//*[@itemprop="availability"]', './/*[contains(concat(" ",normalize-space(@class)," ")," product-availability ")]'], $block));
            $pageCurrency=competitor_meta_currency($xpath)?:competitor_currency(competitor_first_text($xpath,['.//*[@itemprop="priceCurrency"]'],$block));
            foreach ($xpath->query('.//input[@type="radio" and @data-price and @data-description and contains(concat(" ",normalize-space(@class)," ")," product-option-value ")] | .//select[starts-with(@name,"option[")]/option[@value and @data-price and @data-description]', $block) as $node) {
                if(competitor_related_node($node,$block,$block))continue;
                if($node->hasAttribute('data-price-prefix')&&$node->getAttribute('data-price-prefix')!=='=')continue;
                $nativeId = $node->getAttribute('value');
                $optionName=$node->nodeName==='option'?$node->parentNode->getAttribute('name'):$node->getAttribute('name');
                if (!preg_match('/^[1-9][0-9]{0,11}$/D', $nativeId) || !preg_match('/^option\[([1-9][0-9]{0,11})\]$/D', $optionName, $group)) continue;
                if ($node->hasAttribute('id') && strpos($node->getAttribute('id'), 'product-size-'.$productId.'-') !== 0) continue;
                $money = competitor_variant_money($node->getAttribute('data-price'));
                if(!$money&&$pageCurrency==='UAH'&&preg_match('/^\d+(?:[.,]\d{1,6})?$/D',trim($node->getAttribute('data-price'))))$money=['price'=>competitor_decimal($node->getAttribute('data-price')),'currency'=>$pageCurrency];
                $grams = competitor_weight($node->getAttribute('data-description'));
                $min = $node->getAttribute('data-min-quantity');
                if (!$money || $grams === null || !preg_match('/^[1-9][0-9]{0,5}$/D', $min)) continue;
                $special=trim($node->getAttribute('data-special'));
                if ($special !== '' && !preg_match('/^0+(?:[.,]0+)?$/D',$special)) { $warnings[] = 'variant_sale_unresolved'; continue; }
                $name = competitor_text($node->getAttribute('data-name'));
                $label = $name !== '' ? $name : competitor_text($node->getAttribute('data-description'));
                $condition = (int) $min > 1 ? 'От '.$min.' упаковок' : '';
                if (preg_match('/(?:опт|wholesale)/iu', $label)) $condition = $condition !== '' ? $condition.'; оптовое предложение' : 'Оптовое предложение';
                $stock = $node->hasAttribute('disabled') ? 'unknown' : $availability;
                $offers[] = competitor_variant_offer($label, $money, $stock, 'banka:'.$productId.':'.$group[1].':'.$nativeId, $nativeId, $grams, (float) $min, $condition);
            }
            return [$offers, 'banka_option_prices', $warnings];
        }
        return [[], '', $warnings];
    }
    if ($host === 'specii.in.ua') {
        foreach ($xpath->query('//*[@itemtype="http://schema.org/Product" or @itemtype="https://schema.org/Product"]') as $block) {
            $productUrl = competitor_first_text($xpath, ['.//*[@itemprop="url"]'], $block);
            if ($title === '' || normalize(competitor_first_text($xpath, ['.//h1', './/*[@itemprop="name"]'], $block)) !== normalize($title)
                && competitor_absolute_url($productUrl, $url) !== $url) continue;
            $active = $xpath->query('.//li[@data-treevalue and contains(concat(" ",normalize-space(@class)," ")," bx_active ") and not(contains(concat(" ",normalize-space(@class)," ")," bx_missing "))]', $block);
            if ($active->length !== 1) {
                if ($xpath->query('.//li[@data-treevalue]', $block)->length) return [[], 'specii_selected_variant', ['variant_prices_unavailable']];
                continue;
            }
            $node = $active->item(0); $list = $node->parentNode;
            if (!$list instanceof \DOMElement || !preg_match('/^(bx_[0-9]+_([0-9]+))_prop_[0-9]+_list$/D', $list->getAttribute('id'), $prefix)
                || !preg_match('/^[0-9]+_[0-9]+$/D', $node->getAttribute('data-treevalue'))) continue;
            $label = competitor_first_text($xpath, ['.//*[contains(concat(" ",normalize-space(@class)," ")," cnt ")]'], $node);
            $here = competitor_first_text($xpath, ['.//*[contains(concat(" ",normalize-space(@class)," ")," here ")]'], $list->parentNode);
            if ($label === '' || normalize($here) !== normalize($label) || competitor_weight($label) === null) continue;
            $priceNode = competitor_first_node($xpath, ['.//*[@id="'.$prefix[1].'_price" and @itemprop="price"]'], $block);
            if (!$priceNode) continue;
            $price = competitor_decimal(competitor_node_value($priceNode));
            $currency = competitor_currency(competitor_first_text($xpath, ['.//*[@itemprop="priceCurrency"]'], $priceNode->parentNode));
            if ($price === null || $currency === '') continue;
            $stock = competitor_availability(competitor_first_text($xpath, ['.//*[@itemprop="availability"]'], $priceNode->parentNode));
            $nativeId = $node->getAttribute('data-treevalue');
            $offers[] = competitor_variant_offer($label, ['price' => $price, 'currency' => $currency], $stock, 'specii:'.$prefix[2].':'.$nativeId, $nativeId);
            return [$offers, 'specii_selected_variant', ['variant_prices_unavailable']];
        }
    }
    return [[], '', []];
}

function competitor_dom_offers(\DOMXPath $xpath, \DOMNode $scope, string $title, string $url): array
{
    $offers = [];
    $currency = competitor_meta_currency($xpath);
    $product = null;
    foreach ($xpath->query('//*[@itemtype="https://schema.org/Product" or @itemtype="http://schema.org/Product"]') as $candidate) {
        $candidateTitle = competitor_first_text($xpath, ['.//h1', './/*[@itemprop="name"]'], $candidate);
        $candidateUrl = competitor_first_text($xpath, ['.//*[@itemprop="url"]'], $candidate);
        $candidateUrl = $candidateUrl !== '' ? competitor_absolute_url($candidateUrl, $url) : null;
        if ((normalize($candidateTitle) === normalize($title) && $title !== '') || $candidateUrl === $url) {
            if (!competitor_related_node($candidate->parentNode, null, $scope) || $xpath->query('.//h1', $candidate)->length) { $product = $candidate; break; }
        }
    }
    $context = $product ?: $scope;
    if ($currency === '' && $product) {
        foreach ($xpath->query('.//*[@itemprop="priceCurrency"]', $product) as $currencyNode) {
            if (!competitor_related_node($currencyNode, $product, $context)) { $currency = competitor_currency(competitor_node_value($currencyNode)); break; }
        }
    }
    $availability = competitor_first_text($xpath, ['.//*[@itemprop="availability"]'], $context);
    $micro = $xpath->query('.//*[@itemprop="offers"]', $context);
    foreach ($micro as $node) {
        if (competitor_related_node($node, $product, $context)) continue;
        $price = competitor_decimal(competitor_first_text($xpath, ['.//*[@itemprop="price"]'], $node));
        $unitCurrency = competitor_currency(competitor_first_text($xpath, ['.//*[@itemprop="priceCurrency"]'], $node)) ?: $currency;
        if ($price === null || $unitCurrency === '') continue;
        $label = competitor_first_text($xpath, ['.//*[@itemprop="name"]', './/*[@itemprop="size"]'], $node);
        if ($label === '') $label = $title;
        $offers[] = ['key' => $label, 'label' => $label, 'price' => $price, 'old_price' => null, 'currency' => $unitCurrency,
            'grams' => competitor_weight($label), 'availability' => competitor_availability(competitor_first_text($xpath, ['.//*[@itemprop="availability"]'], $node) ?: $availability),
            'min_quantity' => 1, 'unit' => 'pack', 'condition' => '',
            '_paired_variant' => competitor_weight(competitor_first_text($xpath, ['.//*[@itemprop="name"]', './/*[@itemprop="size"]'], $node)) !== null];
    }
    if ($offers) return [$offers, 'microdata', []];

    // Explicit absolute variant data only. OpenCart "+/- option surcharge" is not an absolute price.
    $variants = $xpath->query('.//*[@data-price and (@data-grams or @data-weight or @data-pack)]', $context);
    foreach ($variants as $node) {
        if (competitor_related_node($node, $product, $context)) continue;
        if ($node->hasAttribute('data-price-prefix') && $node->getAttribute('data-price-prefix') !== '=') continue;
        $price = competitor_decimal($node->getAttribute('data-price'));
        $unitCurrency = competitor_currency($node->getAttribute('data-currency')) ?: $currency;
        if ($price === null || $unitCurrency === '') continue;
        $label = competitor_text($node->getAttribute('data-pack') ?: $node->getAttribute('data-weight') ?: $node->textContent);
        $grams = competitor_weight($label);
        if ($node->hasAttribute('data-grams') && competitor_decimal($node->getAttribute('data-grams')) !== null) $grams = (float) $node->getAttribute('data-grams');
        $min = $node->getAttribute('data-min-quantity');
        $min = is_numeric($min) && (float) $min >= 1 ? (float) $min : 1;
        $offers[] = ['key' => $label, 'label' => $label, 'price' => $price, 'old_price' => competitor_decimal($node->getAttribute('data-old-price')),
            'currency' => $unitCurrency, 'grams' => $grams, 'availability' => competitor_availability($node->getAttribute('data-availability') ?: $availability),
            'min_quantity' => $min, 'unit' => 'pack', 'condition' => $min > 1 ? 'От ' . $min . ' упаковок' : '', '_paired_variant' => true];
    }
    if ($offers) return [$offers, 'explicit_variant_data', []];

    $price = competitor_decimal(competitor_first_text($xpath, ['//meta[@property="product:price:amount"]']));
    if ($price === null && $product) {
        foreach ($xpath->query('.//*[@itemprop="price"]', $product) as $priceNode) {
            if (!competitor_related_node($priceNode, $product, $context)) { $price = competitor_decimal(competitor_node_value($priceNode)); break; }
        }
    }
    if ($price === null || $currency === '') return [[], '', []];
    $oldPrice = null;
    $salePrice = competitor_decimal(competitor_first_text($xpath, ['//meta[@property="product:sale_price:amount"]']));
    $saleCurrency = competitor_currency(competitor_first_text($xpath, ['//meta[@property="product:sale_price:currency"]']));
    if ($salePrice !== null && $saleCurrency === $currency && bccomp($salePrice, $price, 6) < 0) {
        $oldPrice = $price;
        $price = $salePrice;
    }
    $weights = [];
    foreach ($xpath->query('.//select//option|.//label', $context) as $node) {
        $weight = competitor_weight($node->textContent);
        if ($weight !== null) $weights[(string) $weight] = true;
    }
    $ambiguous = count($weights) > 1;
    $label = $ambiguous ? 'Цена на странице; фасовка не определена' : $title;
    return [[['key' => 'page-price', 'label' => $label, 'price' => $price, 'old_price' => $oldPrice, 'currency' => $currency,
        'grams' => $ambiguous ? null : competitor_weight($title), 'availability' => competitor_availability($availability),
        'min_quantity' => 1, 'unit' => 'pack', 'condition' => '']], 'product_metadata',
        $ambiguous ? ['variant_prices_unavailable'] : []];
}

function competitor_catalog_navigation(\DOMNode $node, \DOMNode $scope): bool
{
    for ($parent = $node; $parent instanceof \DOMElement; $parent = $parent->parentNode) {
        if (in_array(strtolower($parent->nodeName), ['nav', 'header', 'footer', 'aside'], true)
            || preg_match('/(?:^|[\s_-])(?:menu|navigation|sidebar|breadcrumb|reviews?|comments?|filters?|category[-_](?:card|item|name|title))(?:[\s_-]|$)/i', $parent->getAttribute('class') . ' ' . $parent->getAttribute('id'))) return true;
        if ($parent->isSameNode($scope)) break;
    }
    return false;
}

/** Bounded semantic card evidence for Banka's flat product/category URL space. */
function competitor_banka_card(\DOMXPath $xpath, \DOMElement $anchor, \DOMNode $scope, string $url): bool
{
    if (preg_replace('/^www\./i', '', parse_url($url, PHP_URL_HOST) ?: '') !== 'banka-speciy.in.ua') return false;
    $wanted = competitor_absolute_url($anchor->getAttribute('href'), $url);
    $depth = 0;
    for ($card = $anchor->parentNode; $card instanceof \DOMElement && !$card->isSameNode($scope) && $depth++ < 5; $card = $card->parentNode) {
        if (in_array(strtolower($card->nodeName), ['main', 'body'], true)) break;
        $text = competitor_text($card->textContent);
        if (mb_strlen($text) > 2000) continue;
        $urls = [];
        foreach ($xpath->query('.//a[@href]', $card) as $link) {
            $href = competitor_absolute_url($link->getAttribute('href'), $url);
            if ($href) $urls[$href] = true;
            if (count($urls) > 2) break;
        }
        if (count($urls) !== 1 || !isset($urls[$wanted])) continue;
        $productId = $xpath->query('.//input[@name="product_id"]|.//*[@data-product-id]', $card)->length > 0;
        $heading = $xpath->query('.//h2|.//h3|.//h4', $card)->length > 0;
        $price = preg_match('/\d[\d\s.,]*\s*(?:грн\.?|UAH|₴)(?![\p{L}])/iu', $text);
        $orderControl = false;
        foreach ($xpath->query('.//select/option|.//button', $card) as $control) {
            $label = competitor_text($control->textContent);
            if (competitor_weight($label) !== null
                || preg_match('/(?:\d\s*шт\.?|купити|купить|у кошик|в кошик|в корзину)/iu', $label)) {
                $orderControl = true;
                break;
            }
        }
        if ($productId || ($heading && $price && $orderControl)) return true;
    }
    return false;
}

function competitor_catalog_links(\DOMXPath $xpath, \DOMNode $scope, array $jsonNodes, string $url): array
{
    $links = [];
    $add = function ($href, $title) use (&$links, $url) {
        if (!is_string($href) || !is_scalar($title)) return;
        $href = competitor_absolute_url($href, $url);
        $title = mb_substr(competitor_text((string) $title), 0, 300);
        if (!$href || $title === '' || $href === $url || count($links) >= 250) return;
        if (preg_match('/^(?:купить|купити|в корзину|в кошик|детальніше|подробнее|повідомити.*|сообщить.*)$/iu', $title)) return;
        $path = parse_url($href, PHP_URL_PATH) ?: '/';
        if (preg_match('~/(?:account|login|cart|checkout|search|contact|about|news|faq|cooperation)(?:/|$)~i', $path)) return;
        if (!isset($links[$href])) $links[$href] = ['url' => $href, 'title' => $title];
    };
    foreach ($jsonNodes as $node) {
        if (in_array('Product', competitor_types($node), true)) $add($node['url'] ?? '', $node['name'] ?? '');
        if (in_array('ItemList', competitor_types($node), true)) {
            foreach (($node['itemListElement'] ?? []) as $item) if (is_array($item)) {
                $entry = $item['item'] ?? $item;
                if (is_array($entry)) $add($entry['url'] ?? $entry['@id'] ?? '', $entry['name'] ?? $item['name'] ?? '');
            }
        }
    }
    $anchors = $xpath->query('.//a[@href]', $scope);
    $remaining = 3000;
    foreach ($anchors as $anchor) {
        if (--$remaining < 0 || count($links) >= 250) break;
        if (competitor_catalog_navigation($anchor, $scope)) continue;
        $href = $anchor->getAttribute('href');
        $title = competitor_text($anchor->textContent);
        if ($title === '') $title = $anchor->getAttribute('title');
        $absolute = competitor_absolute_url($href, $url);
        if (!$absolute) continue;
        $path = parse_url($absolute, PHP_URL_PATH) ?: '';
        $knownProductPath = preg_match('~/(?:catalog/[^?#]+\.html|content/products/[^/?#]+)~', $path);
        $category = parse_url($url, PHP_URL_PATH) ?: '/';
        if (preg_replace('/^www\./i', '', parse_url($url, PHP_URL_HOST) ?: '') === 'specii.in.ua'
            && preg_match('~^/ua/catalog/(?:tselye-pryanosti|natural-spices-in-glass)/$~D', $category)
            && preg_match('~^' . preg_quote($category, '~') . '[^/]+/$~D', $path) && parse_url($absolute, PHP_URL_QUERY) === null) {
            $knownProductPath = true;
        }
        $inCard = false;
        for ($node = $anchor; $node instanceof \DOMElement && $node !== $scope; $node = $node->parentNode) {
            if (preg_match('/(?:^|\s)(?:product-thumb|product-layout|product-card|product-item|product-name|product-title|product__name|product__title|catalog-item|item-title)(?:\s|$)/', $node->getAttribute('class'))
                || preg_match('~/Product$~', $node->getAttribute('itemtype'))) { $inCard = true; break; }
        }
        if ($knownProductPath || $inCard || competitor_banka_card($xpath, $anchor, $scope, $url)) $add($absolute, $title);
    }
    ksort($links, SORT_STRING);
    return array_values($links);
}

function competitors_parse_document(array $target, string $html, string $finalUrl): array
{
    list($doc, $xpath) = competitor_dom($html);
    $kind = $target['kind'] ?? 'product';
    $scope = competitor_main_node($xpath);
    $title = '';
    foreach ($xpath->query('.//h1', $scope) as $heading) {
        if (!competitor_catalog_navigation($heading, $scope)) { $title = competitor_text($heading->textContent); break; }
    }
    if ($title === '') $title = competitor_first_text($xpath, ['//meta[@property="og:title"]', '//title']);
    $title = mb_substr($title, 0, 300);
    $json = competitor_json_nodes($xpath);
    $base = ['kind' => $kind, 'title' => $title, 'offers' => [], 'text' => '', 'links' => [], 'warnings' => [], 'method' => ''];
    if ($kind === 'terms') {
        $text = competitor_scoped_text($xpath, $scope);
        if (mb_strlen($text) < 30) throw new CompetitorParseError('parse_error');
        $base['text'] = competitor_commercial_text($text, $title);
        $base['method'] = 'commercial_page_text';
        return $base;
    }
    if ($kind === 'catalog') {
        $base['links'] = competitor_catalog_links($xpath, $scope, $json, $finalUrl);
        if (!$base['links']) throw new CompetitorParseError('catalog_unrecognized');
        $base['method'] = 'watched_page_links';
        $base['warnings'] = ['watched_page_only_not_full_catalog'];
        return $base;
    }
    if ($kind !== 'product') throw new CompetitorParseError('parse_error');
    list($offers, $method, $warnings) = competitor_source_variants($xpath, $scope, $title, $finalUrl);
    $jsonTitle = '';
    if (!$offers && $method !== '') throw new CompetitorParseError('variant_prices_unavailable');
    if (!$offers) {
        list($offers, $jsonTitle, $jsonWarnings) = competitor_product_json($json, $title, $finalUrl);
        $warnings = array_merge($warnings, $jsonWarnings);
        $method = $offers ? 'json_ld' : '';
    }
    if (!$offers) {
        list($offers, $method, $domWarnings) = competitor_dom_offers($xpath, $scope, $title, $finalUrl);
        $warnings = array_merge($warnings, $domWarnings);
    }
    if (!$offers) throw new CompetitorParseError(in_array('aggregate_from_price_ignored', $warnings, true) ? 'variant_prices_unavailable' : 'price_unknown');
    $base['title'] = mb_substr($jsonTitle ?: $title, 0, 300);
    $pageWeights = [];
    foreach ($xpath->query('.//select//option|.//label', $scope) as $node) {
        $weight = competitor_weight($node->textContent);
        if ($weight !== null) $pageWeights[(string) $weight] = true;
    }
    if (count($pageWeights) > 1) {
        foreach ($offers as &$offer) if (empty($offer['_paired_variant']) && $offer['unit'] !== 'kg') {
            $offer['grams'] = null;
            $offer['label'] = 'Цена на странице; фасовка не определена';
            $warnings[] = 'variant_prices_unavailable';
        }
        unset($offer);
    }
    $unique = [];
    foreach ($offers as $offer) {
        if (count($offers) === 1 && $offer['grams'] === null && isset($target['grams_override']) && is_numeric($target['grams_override']) && (float) $target['grams_override'] > 0) {
            $offer['grams'] = (float) $target['grams_override'];
            $offer['label'] = ($target['pack_label'] ?? '') ?: $offer['grams'] . ' г (задано вручную)';
            $warnings[] = 'weight_set_manually';
        }
        if ($offer['grams'] === null) $warnings[] = 'weight_unknown';
        if ($offer['availability'] === 'unknown') $warnings[] = 'availability_unknown';
        if ($offer['old_price'] !== null && bccomp($offer['old_price'], $offer['price'], 2) <= 0) $offer['old_price'] = null;
        $offer['label'] = mb_substr($offer['label'], 0, 200);
        $offer['packaging'] = $offer['packaging'] ?? competitor_packaging($offer['label']);
        if($offer['packaging']==='unknown')$offer['packaging']=competitor_packaging($base['title']);
        unset($offer['_paired_variant']);
        $identity = [$offer['label'], $offer['grams'], $offer['currency'], $offer['min_quantity'], $offer['condition']];
        if (isset($offer['variant_id'])) $identity[] = $offer['variant_id'];
        $key = hash('sha256', encode($identity));
        if (isset($unique[$key]) && ($unique[$key]['price'] !== $offer['price'] || $unique[$key]['availability'] !== $offer['availability'])) {
            throw new CompetitorParseError('variant_prices_unavailable');
        }
        $offer['key'] = substr($key, 0, 24);
        $unique[$key] = $offer;
    }
    if (count($unique) > 100) throw new CompetitorParseError('too_many_variants');
    ksort($unique, SORT_STRING);
    $base['offers'] = array_values($unique);
    $base['warnings'] = array_values(array_unique($warnings));
    $base['method'] = $method;
    return $base;
}

/** Malformed remote markup is an error of this target, never a fatal whole-run failure. */
function competitors_parse(array $target, string $html, string $finalUrl): array
{
    try { return competitors_parse_document($target, $html, $finalUrl); }
    catch (CompetitorParseError $error) { throw $error; }
    catch (\Throwable $error) { throw new CompetitorParseError('parse_error'); }
}

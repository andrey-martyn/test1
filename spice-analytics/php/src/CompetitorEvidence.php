<?php

declare(strict_types=1);
require_once __DIR__ . '/CompetitorHttp.php';
require_once __DIR__ . '/CompetitorParser.php';

/** Only public page structure; never print arbitrary attributes, scripts or form values. */
function lavka_competitor_diagnostic_text(string $value, int $length = 120): string
{
    $value = \Lavka\competitor_text($value);
    $value = preg_replace('/[\p{L}\d._%+\-]+@[\p{L}\d.\-]+\.[\p{L}]{2,}/u', '[email]', $value);
    $value = preg_replace('/(?<!\d)\+?\d[\d() .\-]{7,}\d(?!\d)/u', '[number]', $value);
    $value = preg_replace('/\b(?=[A-Za-z0-9_-]{28,}\b)(?=[A-Za-z0-9_-]*[A-Za-z])(?=[A-Za-z0-9_-]*\d)[A-Za-z0-9_-]+\b/', '[opaque]', $value);
    return mb_substr($value, 0, $length, 'UTF-8');
}

function lavka_competitor_diagnostic_excluded(DOMNode $node): bool
{
    for ($ancestor = $node; $ancestor instanceof DOMElement; $ancestor = $ancestor->parentNode) {
        if (in_array(strtolower($ancestor->tagName), ['script', 'style', 'noscript', 'template', 'header', 'footer', 'nav', 'address', 'textarea'], true)) return true;
        $identity = $ancestor->getAttribute('id') . ' ' . $ancestor->getAttribute('class') . ' ' . $ancestor->getAttribute('itemprop');
        if (preg_match('/(?:review|comment|feedback|testimonial|customer|account|contact|login|register|csrf|secret|token|otzyv|otziv|vidguk|author)/i', $identity)
            || preg_match('~schema\.org/(?:Review|Person)~i', $ancestor->getAttribute('itemtype'))) return true;
    }
    return false;
}

/** Public component IDs with a known product/SKU grammar, never arbitrary tokens. */
function lavka_competitor_diagnostic_id(string $value): ?string
{
    return strlen($value)<=120 && preg_match('/^(?:bx_[0-9]{1,20}_[0-9]{1,12}(?:_[a-z0-9_]{1,50})?|product-size-[0-9]{1,12}-[0-9]{1,12}|input-option[0-9]{1,12})$/D',$value)?$value:null;
}

function lavka_competitor_diagnostic_shape(DOMElement $node): array
{
    $result = ['tag' => strtolower($node->tagName)];
    foreach (['id', 'class', 'itemprop'] as $attribute) {
        $value = $node->getAttribute($attribute);
        if ($value !== '') $result[$attribute] = ($attribute==='id'?lavka_competitor_diagnostic_id($value):null)??lavka_competitor_diagnostic_text($value, 120);
    }
    return $result;
}

/** Remove non-product/private subtrees before extracting a short visible snippet. */
function lavka_competitor_diagnostic_node_text(DOMNode $node, int $length = 120): string
{
    $parts = []; $remaining = 500;
    $walk = function (DOMNode $current) use (&$walk, &$parts, &$remaining): void {
        if (--$remaining < 0) return;
        if ($current instanceof DOMElement) {
            if (lavka_competitor_diagnostic_excluded($current)
                || in_array(strtolower($current->tagName), ['form', 'input', 'select', 'button'], true)) return;
        }
        if ($current instanceof DOMText) $parts[] = $current->nodeValue;
        foreach ($current->childNodes as $child) $walk($child);
    };
    $walk($node);
    return lavka_competitor_diagnostic_text(implode(' ', $parts), $length);
}

function lavka_competitor_diagnostic_url(string $value, string $base): ?string
{
    $url = \Lavka\competitor_absolute_url($value, $base);
    if ($url === null || strlen($url) > 700) return null;
    try { return \Lavka\competitors_http_url($url)['url']; }
    catch (\Lavka\CompetitorHttpError $ignored) { return null; }
}

function lavka_competitor_diagnostic_numeric_attributes(DOMElement $node): array
{
    $attributes = [];
    foreach (['content', 'data-price-id', 'data-price-new', 'data-price', 'data-price-value', 'data-base-price', 'data-weight', 'data-grams', 'data-option-value-id', 'data-product-option-value-id'] as $key) {
        $value = trim($node->getAttribute($key));
        if (preg_match('/^[+\-]?\d{1,10}(?:[.,]\d{1,6})?$/D', $value)) $attributes[$key] = $value;
    }
    foreach (['data-price-prefix', 'data-prefix', 'price-prefix'] as $key) {
        $value = $node->getAttribute($key);
        if ($value === '+' || $value === '-') $attributes[$key] = $value;
    }
    $tree=$node->getAttribute('data-treevalue');
    if(preg_match('/^[0-9]{1,12}_[0-9]{1,12}$/D',$tree))$attributes['data-treevalue']=$tree;
    return $attributes;
}

function lavka_competitor_diagnostic_document(string $html, string $url, string $kind): array
{
    list($document, $xpath) = \Lavka\competitor_dom($html);
    $main = \Lavka\competitor_main_node($xpath);
    $result = ['document' => ['title' => '', 'h1' => '', 'canonical' => null, 'og_url' => null],
        'scope' => $main instanceof DOMElement ? lavka_competitor_diagnostic_shape($main) : ['tag' => $main->nodeName],
        'product_meta' => [], 'price_nodes' => [], 'purchase_options' => [], 'catalog_anchors' => []];
    foreach (['title' => '//title', 'h1' => '//h1'] as $key => $query) {
        $node = $xpath->query($query)->item(0);
        if ($node && !lavka_competitor_diagnostic_excluded($node)) $result['document'][$key] = lavka_competitor_diagnostic_node_text($node, 180);
    }
    $result['scope']['has_h1'] = (bool)$xpath->query('.//h1', $main)->length || $main->nodeName === 'h1';
    foreach (['canonical' => '//link[contains(concat(" ",normalize-space(@rel)," ")," canonical ")]/@href',
        'og_url' => '//meta[@property="og:url"]/@content'] as $key => $query) {
        $node = $xpath->query($query)->item(0);
        if ($node) $result['document'][$key] = lavka_competitor_diagnostic_url($node->nodeValue, $url);
    }
    foreach ($xpath->query('//meta[@itemprop or @property or @name] | //link[@itemprop]') as $node) {
        if (lavka_competitor_diagnostic_excluded($node)) continue;
        $name = strtolower($node->getAttribute('itemprop') ?: ($node->getAttribute('property') ?: $node->getAttribute('name')));
        if (!in_array($name, ['price', 'pricecurrency', 'availability', 'product:price:amount', 'product:price:currency', 'product:availability', 'og:price:amount', 'og:price:currency'], true)) continue;
        $value = $node->hasAttribute('content') ? $node->getAttribute('content') : $node->getAttribute('href');
        if (strpos($name, 'availability') !== false) $value = \Lavka\competitor_availability($value);
        elseif (strpos($name, 'currency') !== false) $value = \Lavka\competitor_currency($value);
        elseif (!preg_match('/^\d{1,10}(?:[.,]\d{1,6})?$/D', trim($value))) continue;
        if ($value !== '' && count($result['product_meta']) < 12) $result['product_meta'][] = ['name' => $name, 'value' => $value];
    }
    $priceQuery = './/*[@itemprop="price" or @data-price or contains(translate(@class,"PRICE","price"),"price") or contains(translate(@id,"PRICE","price"),"price")]';
    foreach ($xpath->query($priceQuery, $main) as $node) {
        if (count($result['price_nodes']) >= 12) break;
        if (!$node instanceof DOMElement || lavka_competitor_diagnostic_excluded($node)
            || in_array(strtolower($node->tagName), ['input', 'select', 'option', 'form'], true)) continue;
        $text = lavka_competitor_diagnostic_node_text($node);
        $attributes = lavka_competitor_diagnostic_numeric_attributes($node);
        if ($text === '' && !$attributes) continue;
        $result['price_nodes'][] = array_merge(lavka_competitor_diagnostic_shape($node), $attributes, ['text' => $text]);
    }
    $result['availability_nodes']=[];
    foreach($xpath->query('.//*[@itemprop="availability" or contains(translate(@class,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"stock") or contains(translate(@class,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"avail")]', $main) as $node){
        if(count($result['availability_nodes'])>=8)break;
        if(!$node instanceof DOMElement||lavka_competitor_diagnostic_excluded($node))continue;
        $text=lavka_competitor_diagnostic_node_text($node,140);$raw=$node->getAttribute('href')?:$node->getAttribute('content');
        $stock=\Lavka\competitor_availability($raw?:$text);
        $result['availability_nodes'][]=lavka_competitor_diagnostic_shape($node)+['text'=>$text,'recognized'=>$stock];
    }
    if ($kind === 'product') {
        // Reuse the parser's primary-product matching; related JSON-LD offers
        // cannot become evidence for the requested product.
        list($jsonOffers,$jsonTitle,$jsonWarnings)=\Lavka\competitor_product_json(\Lavka\competitor_json_nodes($xpath),$result['document']['h1'],$url);
        $result['structured_offers']=lavka_competitor_diagnostic_offers($jsonOffers);
        $result['sku_options']=[];
        foreach($xpath->query('.//li[@data-treevalue]',$main) as $node){
            if(count($result['sku_options'])>=12)break;
            if(lavka_competitor_diagnostic_excluded($node))continue;
            $label=lavka_competitor_diagnostic_node_text($node,120);
            if(\Lavka\competitor_weight($label)===null)continue;
            $shape=lavka_competitor_diagnostic_shape($node)+lavka_competitor_diagnostic_numeric_attributes($node)+['label'=>$label];
            if($node->parentNode instanceof DOMElement){
                $shape['control']=lavka_competitor_diagnostic_shape($node->parentNode);
                $current=$xpath->query('.//*[contains(concat(" ",normalize-space(@class)," ")," here ")]',$node->parentNode->parentNode)->item(0);
                if($current)$shape['current_label']=lavka_competitor_diagnostic_node_text($current,120);
            }
            $result['sku_options'][]=$shape;
        }

        foreach ($xpath->query('.//select/option | .//input[@type="radio"]', $main) as $node) {
            if (count($result['purchase_options']) >= 12) break;
            if (lavka_competitor_diagnostic_excluded($node)) continue;
            $value = trim($node->getAttribute('value'));
            if (!preg_match('/^\d{1,10}$/D', $value)) continue;
            $control = $node->tagName === 'option' ? $node->parentNode : $node;
            $identity = $control instanceof DOMElement ? $control->getAttribute('name') . ' ' . $control->getAttribute('id') . ' ' . $control->getAttribute('class') : '';
            if (!preg_match('/(?:option|pack|weight|size|gram|fasov)/i', $identity)) continue;
            $label = $node->tagName === 'option' ? lavka_competitor_diagnostic_text($node->textContent) : '';
            if ($node->tagName === 'input') {
                $parent = $node->parentNode;
                if ($parent instanceof DOMElement && strtolower($parent->tagName) === 'label') $label = lavka_competitor_diagnostic_node_text($parent);
                elseif ($node->getAttribute('id') !== '') {
                    foreach ($xpath->query('//label[@for]') as $candidate) {
                        if ($candidate->getAttribute('for') === $node->getAttribute('id') && !lavka_competitor_diagnostic_excluded($candidate)) { $label = lavka_competitor_diagnostic_node_text($candidate); break; }
                    }
                }
            }
            if ($label === '' || !preg_match('/(?:\d\s*(?:кг|kg|г|гр|g|шт|pcs|капс|ml|мл)|грн|UAH|₴)/iu', $label)) continue;
            $shape = array_merge(lavka_competitor_diagnostic_shape($node), lavka_competitor_diagnostic_numeric_attributes($node), ['value' => $value, 'label' => $label]);
            if ($control instanceof DOMElement) $shape['control'] = lavka_competitor_diagnostic_shape($control);
            if ($node->hasAttribute('selected') || $node->hasAttribute('checked')) $shape['selected'] = true;
            $result['purchase_options'][] = $shape;
        }
    }
    if ($kind === 'catalog') {
        $seen = [];
        foreach ($xpath->query('.//a[@href]', $main) as $anchor) {
            if (count($result['catalog_anchors']) >= 12) break;
            if (lavka_competitor_diagnostic_excluded($anchor)) continue;
            $href = lavka_competitor_diagnostic_url($anchor->getAttribute('href'), $url);
            if ($href === null || isset($seen[$href]) || rtrim($href, '/') === rtrim($url, '/')) continue;
            $label = lavka_competitor_diagnostic_node_text($anchor, 100);
            if (mb_strlen($label, 'UTF-8') < 3) continue;
            $ancestors = []; $context = ''; $hasPrice = false;
            for ($ancestor = $anchor->parentNode, $depth = 1; $ancestor instanceof DOMElement && $ancestor !== $main && $depth <= 4; $ancestor = $ancestor->parentNode, $depth++) {
                if (lavka_competitor_diagnostic_excluded($ancestor)) break;
                $ancestors[] = array_merge(lavka_competitor_diagnostic_shape($ancestor), ['depth' => $depth]);
                $text = lavka_competitor_diagnostic_node_text($ancestor, 400);
                if (preg_match('/\d[\d\s.,]*\s*(?:грн|UAH|₴)/iu', $text) || $xpath->query($priceQuery, $ancestor)->length) {
                    $hasPrice = true; $context = lavka_competitor_diagnostic_text($text, 180); break;
                }
            }
            if (!$hasPrice) continue;
            $seen[$href] = true;
            $result['catalog_anchors'][] = array_merge(lavka_competitor_diagnostic_shape($anchor), ['href' => $href,
                'title' => $label, 'ancestors' => $ancestors, 'context' => $context]);
        }
    }
    return $result;
}

/** Public normalized offers, useful when prices parse but stock/packaging do not. */
function lavka_competitor_diagnostic_offers(array $offers): array
{
    $result=[];
    foreach(array_slice($offers,0,6) as $offer){
        $row=[];foreach(['price','old_price','grams','min_quantity'] as $key)if(isset($offer[$key])&&is_numeric($offer[$key]))$row[$key]=0+$offer[$key];
        foreach(['availability','availability_basis','packaging','currency','unit'] as $key)if(isset($offer[$key])&&preg_match('/^[a-zA-Z_]{1,30}$/D',(string)$offer[$key]))$row[$key]=$offer[$key];
        foreach(['label','condition'] as $key)if(is_string($offer[$key]??null))$row[$key]=lavka_competitor_diagnostic_text($offer[$key],120);
        $result[]=$row;
    }
    return $result;
}

/** Bounded evidence from a response already obtained by the ordinary worker. */
function lavka_competitor_evidence_capture(PDO $db, array $target, array $response, ?string $error, string $at): array
{
    if (!in_array($target['kind'] ?? '', ['product','catalog'], true) || empty($response['body'])) return ['state'=>'skipped'];
    try {
        $evidence=lavka_competitor_diagnostic_document($response['body'],$response['url'],$target['kind']);
        foreach (['price_nodes','purchase_options','catalog_anchors','availability_nodes','sku_options','structured_offers'] as $key) $evidence[$key]=array_slice($evidence[$key]??[],0,8);
        $payload=\Lavka\encode($evidence);
        if(strlen($payload)>16000){foreach(['price_nodes','purchase_options','catalog_anchors','availability_nodes','sku_options','structured_offers'] as $key)$evidence[$key]=array_slice($evidence[$key],0,3);$payload=\Lavka\encode($evidence);}
        if(strlen($payload)>16000)return ['state'=>'skipped','error_code'=>'evidence_size_limit'];
        $db->prepare('INSERT OR REPLACE INTO competitor_page_evidence(source_id,url,captured_at,error_code,payload) VALUES(?,?,?,?,?)')->execute([$target['source_id'],$target['url'],$at,$error,$payload]);
        $db->exec('DELETE FROM competitor_page_evidence WHERE rowid NOT IN (SELECT rowid FROM competitor_page_evidence ORDER BY (error_code IS NOT NULL) DESC,captured_at DESC LIMIT 300)');
        return ['state'=>'saved'];
    } catch(Throwable $failure) {
        // Do not interrupt price checks or expose driver errors; do expose that capture failed.
        return ['state'=>'failed','error_code'=>$failure instanceof PDOException?'evidence_storage_failed':'evidence_structure_failed'];
    }
}

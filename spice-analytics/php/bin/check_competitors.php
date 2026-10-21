<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/src/Competitors.php';
require_once dirname(__DIR__) . '/src/CompetitorHttp.php';
require_once dirname(__DIR__) . '/src/CompetitorParser.php';

final class LavkaCompetitorDiagnosticDeferred extends RuntimeException {}

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

function lavka_competitor_diagnostic_shape(DOMElement $node): array
{
    $result = ['tag' => strtolower($node->tagName)];
    foreach (['id', 'class', 'itemprop'] as $attribute) {
        $value = $node->getAttribute($attribute);
        if ($value !== '') $result[$attribute] = lavka_competitor_diagnostic_text($value, 120);
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
    foreach (['content', 'data-price', 'data-price-value', 'data-base-price', 'data-weight', 'data-grams', 'data-option-value-id', 'data-product-option-value-id'] as $key) {
        $value = trim($node->getAttribute($key));
        if (preg_match('/^[+\-]?\d{1,10}(?:[.,]\d{1,6})?$/D', $value)) $attributes[$key] = $value;
    }
    foreach (['data-price-prefix', 'data-prefix', 'price-prefix'] as $key) {
        $value = $node->getAttribute($key);
        if ($value === '+' || $value === '-') $attributes[$key] = $value;
    }
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
    if ($kind === 'product') {
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

/** Keep the entire report small enough to paste into a support conversation. */
function lavka_competitors_diagnostics_json(array $report): string
{
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;
    while (strlen($json = json_encode($report, $flags)) > 16000) {
        $largest = 0; $position = null; $section = null;
        foreach ($report['targets'] ?? [] as $index => $target) {
            foreach (['catalog_anchors', 'price_nodes', 'purchase_options', 'product_meta'] as $key) {
                $size = strlen(json_encode($target[$key] ?? [], $flags));
                if (!empty($target[$key]) && $size > $largest) { $largest = $size; $position = $index; $section = $key; }
            }
        }
        $report['truncated'] = true;
        if ($position !== null) array_pop($report['targets'][$position][$section]);
        elseif (!empty($report['targets'])) array_pop($report['targets']);
        else return '{"state":"error","error_code":"report_too_large"}';
    }
    return $json;
}

/** Read-only module DB; injected transport/clock are callable test seams only. */
function lavka_competitors_diagnostics_run(array $args, ?callable $transport = null, ?callable $clock = null, ?callable $pause = null): array
{
    if (PHP_VERSION_ID < 80200) throw new InvalidArgumentException('Нужен PHP 8.2 CLI; веб-PHP магазина менять не требуется.');
    $limit = filter_var($args['limit'] ?? 3, FILTER_VALIDATE_INT);
    if ($limit === false || $limit < 1 || $limit > 5) throw new InvalidArgumentException('Допустимо --limit 1..5.');
    $data = $args['data'] ?? '';
    if (!is_string($data) || $data === '' || $data[0] !== '/' || !is_dir($data) || !($directory = realpath($data))) throw new InvalidArgumentException('Укажите --data с абсолютным путём существующего закрытого каталога.');
    $manifestPath = $directory . '/deployment-php.json';
    $manifest = is_file($manifestPath) && !is_link($manifestPath) && filesize($manifestPath) <= 65536 ? json_decode(file_get_contents($manifestPath), true) : [];
    foreach (['document_root', 'shop_root'] as $key) {
        if (is_string($manifest[$key] ?? null) && $manifest[$key] !== '') {
            $public = \Lavka\canonical_path($manifest[$key]);
            if ($directory === $public || \Lavka\str_starts_with($directory, $public . '/')) throw new InvalidArgumentException('Данные должны находиться вне публичных каталогов.');
        }
    }
    $path = $directory . '/competitors.sqlite3';
    if (!is_file($path) || is_link($path)) throw new InvalidArgumentException('База мониторинга не найдена; диагностика не создаёт и не изменяет её.');
    $clock = $clock ?: function (): float { return microtime(true); };
    $pause = $pause ?: function (float $seconds): void { if ($seconds > 0) usleep((int)ceil($seconds * 1000000)); };
    $started = $clock(); $deadline = $started + 90.0;
    umask(0077);
    $lock = fopen($directory . '/.competitors.lock', 'c');
    if ($lock === false) throw new RuntimeException('lock_failed');
    chmod($directory . '/.competitors.lock', 0600);
    $db = null;
    try {
        if (!flock($lock, LOCK_EX | LOCK_NB)) return ['state' => 'skipped', 'reason' => 'already_running', 'targets' => []];
        $db = new SQLite3($path, SQLITE3_OPEN_READONLY);
        $db->enableExceptions(true);
        $rows = $db->query('SELECT id,source_id,kind,url,label,grams_override,pack_label,last_attempt FROM competitor_targets WHERE enabled=1 ORDER BY id LIMIT 350');
        $groups = ['product' => [], 'catalog' => [], 'terms' => []];
        while ($row = $rows->fetchArray(SQLITE3_ASSOC)) {
            $attempt = json_decode($row['last_attempt'] ?? '', true);
            if (($attempt['state'] ?? '') !== 'error' || !isset($groups[$row['kind']])) continue;
            try { $row['url'] = \Lavka\competitors_validate_url($row['url'], $row['source_id']); }
            catch (Throwable $ignored) { continue; }
            $groups[$row['kind']][] = $row;
        }
        $targets = [];
        while (count($targets) < $limit) {
            $added = false;
            foreach ($groups as &$group) {
                if ($group && count($targets) < $limit) { $targets[] = array_shift($group); $added = true; }
            }
            unset($group);
            if (!$added) break;
        }
        $rows->finalize(); $db->close(); $db = null;
        $report = ['state' => 'ok', 'read_only' => true, 'selected' => count($targets), 'checked' => 0, 'deferred' => 0, 'targets' => []];
        if (!$targets) return $report;
        $cache = $directory . '/competitor-http-cache';
        \Lavka\private_dir($cache);
        $pace = function (string $url, ?float $delay = null) use ($cache, $clock, $pause, $deadline): void {
            $host = preg_replace('/^www\./', '', \Lavka\competitors_http_url($url)['host']);
            $file = $cache . '/pacing-' . hash('sha256', $host) . '.json';
            $previous = is_file($file) && !is_link($file) && filesize($file) <= 1000 ? json_decode(file_get_contents($file), true) : [];
            $delay = max(2.0, min(86400.0, $delay ?? (float)($previous['crawl_delay'] ?? 0)));
            $last = max(0.0, min($clock(), (float)($previous['last_request_at'] ?? 0)));
            $wait = max(0.0, $last + $delay - $clock());
            if ($wait > 5.0 || $deadline - $clock() < $wait + 13.0) {
                // Remember a newly learned policy without pretending a page was
                // requested. Later cron/robots requests must honor that delay.
                \Lavka\atomic_write($file, \Lavka\encode(['last_request_at' => $last, 'crawl_delay' => $delay]));
                throw new LavkaCompetitorDiagnosticDeferred('request_deferred');
            }
            if ($wait > 0) $pause($wait);
            \Lavka\atomic_write($file, \Lavka\encode(['last_request_at' => $clock(), 'crawl_delay' => $delay]));
        };
        $robotsPace = function (string $url) use ($pace): void { $pace($url); };
        $guard = function (string $url) use ($directory, $transport, $clock, $pace, $robotsPace): void {
            $policy = \Lavka\competitors_robots_policy($url, $directory, $transport, (int)$clock(), $robotsPace);
            if (!$policy['allowed']) throw new \Lavka\CompetitorHttpError('robots_disallowed');
            $pace($url, (float)$policy['crawl_delay']);
        };
        foreach ($targets as $position => $target) {
            if ($deadline - $clock() < 27.0) { $report['deferred'] += count($targets) - $position; break; }
            $item = ['id' => (int)$target['id'], 'source' => $target['source_id'], 'kind' => $target['kind'],
                'label' => lavka_competitor_diagnostic_text($target['label'], 180), 'url' => $target['url']];
            try {
                $response = \Lavka\competitors_http_fetch($target['url'], $transport, $guard);
                $item['http'] = ['status' => $response['status'], 'final_url' => $response['url'], 'bytes' => strlen($response['body'])];
                try {
                    $parsed = \Lavka\competitors_parse($target, $response['body'], $response['url']);
                    $item['parser'] = ['method' => $parsed['method'] ?? 'parsed', 'offers_count' => count($parsed['offers'] ?? []), 'links_count' => count($parsed['links'] ?? [])];
                } catch (\Lavka\CompetitorParseError $error) { $item['parser'] = ['error_code' => $error->errorCode]; }
                try { $item = array_merge($item, lavka_competitor_diagnostic_document($response['body'], $response['url'], $target['kind'])); }
                catch (\Lavka\CompetitorParseError $error) { $item['document_error'] = $error->errorCode; }
                $report['checked']++;
            } catch (LavkaCompetitorDiagnosticDeferred $error) {
                $item['error_code'] = 'request_deferred'; $report['deferred']++;
            } catch (\Lavka\CompetitorHttpError $error) {
                $item['error_code'] = $error->errorCode; $item['http'] = ['status' => $error->httpStatus]; $report['checked']++;
            } catch (Throwable $ignored) { $item['error_code'] = 'diagnostic_error'; $report['checked']++; }
            $report['targets'][] = $item;
        }
        $report['seconds'] = round($clock() - $started, 3);
        return $report;
    } finally {
        if ($db instanceof SQLite3) $db->close();
        flock($lock, LOCK_UN); fclose($lock);
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) return;
try {
    echo lavka_competitors_diagnostics_json(lavka_competitors_diagnostics_run(getopt('', ['data:', 'limit:']))) . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, ($error instanceof InvalidArgumentException ? $error->getMessage() : 'Диагностика не выполнена; данные мониторинга не изменены.') . "\n");
    exit(1);
}

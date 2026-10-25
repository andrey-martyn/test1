<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/src/Competitors.php';
require_once dirname(__DIR__) . '/src/CompetitorHttp.php';
require_once dirname(__DIR__) . '/src/CompetitorParser.php';

final class LavkaCompetitorDiagnosticDeferred extends RuntimeException {}

require_once dirname(__DIR__) . '/src/CompetitorEvidence.php';

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

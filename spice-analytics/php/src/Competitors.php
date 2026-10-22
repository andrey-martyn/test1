<?php

declare(strict_types=1);
namespace Lavka;

require_once __DIR__ . '/common.php';

final class CompetitorNotFound extends \InvalidArgumentException {}
final class CompetitorConflict extends \InvalidArgumentException {}

/** Public sources only. Shop credentials and the orders database are never used. */
function competitors_sources(): array
{
    return [
        ['id' => 'lavka', 'name' => 'Лавка специй', 'base_url' => 'https://lavka-speciy.com', 'role' => 'own', 'notes' => '', 'notes_status' => null],
        ['id' => 'deluxe', 'name' => 'Deluxe', 'base_url' => 'https://deluxe.com.ua', 'role' => 'competitor', 'notes' => 'По сведениям пользователя: распродажи и несколько фасовок. Условия требуют проверки по источнику.', 'notes_status' => 'user_provided_unverified'],
        ['id' => 'spicery', 'name' => 'Spicery Shop', 'base_url' => 'https://spiceryshop.com.ua', 'role' => 'competitor', 'notes' => 'По сведениям пользователя: скидки 5–10% при выполнении условий по сумме заказов. Условия требуют проверки по источнику.', 'notes_status' => 'user_provided_unverified'],
        ['id' => 'banka', 'name' => 'Банка Спецій', 'base_url' => 'https://banka-speciy.in.ua', 'role' => 'competitor', 'notes' => 'По сведениям пользователя: скидка 10% на первый заказ за подписку. Условия требуют проверки по источнику.', 'notes_status' => 'user_provided_unverified'],
        ['id' => 'specii', 'name' => 'Лавка прянощів (specii.in.ua)', 'base_url' => 'https://www.specii.in.ua', 'role' => 'competitor', 'notes' => 'По сведениям пользователя: скидки 3%, 5%, 10% при суммах 1000, 2000, 3000 грн. Условия требуют проверки по источнику.', 'notes_status' => 'user_provided_unverified'],
    ];
}

function competitors_source(string $id): array
{
    foreach (competitors_sources() as $source) if ($source['id'] === $id) return $source;
    throw new \InvalidArgumentException('Выберите магазин из списка');
}

/** Canonical public HTTPS URL; the CLI transport independently enforces the same restrictions. */
function competitors_validate_url(string $url, string $sourceId, bool $allowTestOwn = false): string
{
    $source = competitors_source($sourceId);
    if (strlen($url) > 2000 || $url === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $url) || preg_match('/%(?:0[0-9a-f]|1[0-9a-f]|7f)/i', $url)) {
        throw new \InvalidArgumentException('Нужна публичная HTTPS-ссылка без пробелов и служебных символов');
    }
    $parts = parse_url($url);
    $baseHost = preg_replace('/^www\./', '', parse_url($source['base_url'], PHP_URL_HOST));
    $host = strtolower($parts['host'] ?? '');
    $allowedHosts = [$baseHost, 'www.' . $baseHost];
    if ($allowTestOwn && $sourceId === 'lavka') $allowedHosts[] = 'test.lavka-speciy.com';
    if (!$parts || strtolower($parts['scheme'] ?? '') !== 'https' || !in_array($host, $allowedHosts, true) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || isset($parts['port']) && $parts['port'] !== 443) {
        throw new \InvalidArgumentException('Ссылка должна вести на публичную страницу выбранного магазина по HTTPS');
    }
    $path = $parts['path'] ?? '/';
    if ($path === '') $path = '/';
    $decodedPath = rawurldecode(rawurldecode($path));
    if ($path[0] !== '/' || preg_match('~(?:^|/)(?:\.{1,2}|admin|administrator|login|logout|signin|auth|account|customer|cart|checkout|basket|order|analytics|api)(?:/|\.|$)~i', $decodedPath) || preg_match('/[\x00-\x20\x7f\\\\]/', $decodedPath)) {
        throw new \InvalidArgumentException('Нужна публичная страница товара, условий или каталога');
    }
    $query = [];
    if (isset($parts['query'])) parse_str($parts['query'], $query);
    foreach ($query as $key => $value) {
        if (!is_string($value) || preg_match('/(?:password|passwd|token|secret|session|auth|email|customer|redirect|return|callback|api.?key)/i', (string) $key) || preg_match('/^(?:add(?:[-_]?to[-_]?cart)?|action|url|coupon|code|quantity|remove|cmd|do)$/i', (string) $key)) {
            throw new \InvalidArgumentException('Ссылка содержит непубличные или неподдерживаемые параметры');
        }
        if (strtolower((string) $key) === 'product_id' && !preg_match('/^[1-9][0-9]{0,9}$/D', $value)) throw new \InvalidArgumentException('В ссылке товара нужен числовой product_id');
    }
    if (isset($query['route'])) {
        $route = $query['route'];
        if (!in_array($route, ['product/product', 'product/category', 'product/latest', 'product/special', 'information/information', 'feed/google_sitemap', 'extension/feed/google_sitemap'], true)) {
            throw new \InvalidArgumentException('Нужна публичная страница товара, условий или каталога');
        }
        if ($route === 'product/product' && (!isset($query['product_id']) || !preg_match('/^[1-9][0-9]{0,9}$/D', $query['product_id']))) {
            throw new \InvalidArgumentException('В ссылке товара нужен числовой product_id');
        }
    }
    ksort($query);
    return 'https://' . $host . $path . ($query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
}

function competitors_text($value, int $max, string $name, bool $allowEmpty = true): string
{
    if (!is_string($value) || mb_strlen($value) > $max || !$allowEmpty && trim($value) === '' || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) {
        throw new \InvalidArgumentException('Неверное значение: ' . $name);
    }
    return trim($value);
}

function competitors_default_targets(): array
{
    return [
        ['seed_key' => 'deluxe-delivery', 'source_id' => 'deluxe', 'kind' => 'terms', 'url' => 'https://deluxe.com.ua/dostavka', 'label' => 'Доставка и минимальный заказ'],
        ['seed_key' => 'deluxe-sale', 'source_id' => 'deluxe', 'kind' => 'terms', 'url' => 'https://deluxe.com.ua/actions', 'label' => 'Акции и скидки'],
        ['seed_key' => 'deluxe-catalog', 'source_id' => 'deluxe', 'kind' => 'catalog', 'url' => 'https://deluxe.com.ua/', 'label' => 'Позиции на главной странице'],
        ['seed_key' => 'spicery-terms', 'source_id' => 'spicery', 'kind' => 'terms', 'url' => 'https://spiceryshop.com.ua/sistema-skidok-na-spetsii', 'label' => 'Скидки и условия применения'],
        ['seed_key' => 'spicery-delivery', 'source_id' => 'spicery', 'kind' => 'terms', 'url' => 'https://spiceryshop.com.ua/ru/oplata-i-dostavka', 'label' => 'Доставка и минимальный заказ'],
        ['seed_key' => 'spicery-catalog', 'source_id' => 'spicery', 'kind' => 'catalog', 'url' => 'https://spiceryshop.com.ua/ru/catalog/novinki-2025-goda', 'label' => 'Позиции в разделе новинок сайта'],
        ['seed_key' => 'banka-delivery', 'source_id' => 'banka', 'kind' => 'terms', 'url' => 'https://banka-speciy.in.ua/ua/faq', 'label' => 'Оплата, доставка и минимальный заказ'],
        ['seed_key' => 'banka-terms', 'source_id' => 'banka', 'kind' => 'terms', 'url' => 'https://banka-speciy.in.ua/ua/cooperation', 'label' => 'Условия сотрудничества'],
        ['seed_key' => 'banka-catalog', 'source_id' => 'banka', 'kind' => 'catalog', 'url' => 'https://banka-speciy.in.ua/ua/novi-nadhodzhennja', 'label' => 'Новые поступления'],
        ['seed_key' => 'specii-discounts', 'source_id' => 'specii', 'kind' => 'terms', 'url' => 'https://www.specii.in.ua/ua/about/skidki-i-aktsii/', 'label' => 'Скидки и условия применения'],
        ['seed_key' => 'specii-delivery', 'source_id' => 'specii', 'kind' => 'terms', 'url' => 'https://www.specii.in.ua/ua/about/dostavka-oplata/', 'label' => 'Доставка и минимальный заказ'],
        ['seed_key' => 'specii-whole-spices-catalog', 'source_id' => 'specii', 'kind' => 'catalog', 'url' => 'https://www.specii.in.ua/ua/catalog/tselye-pryanosti/', 'label' => 'Цілі прянощі — наблюдаемый раздел'],
    ];
}

function competitors_db(string $directory): \PDO
{
    $path = $directory . '/competitors.sqlite3';
    if (is_link($path)) throw new \RuntimeException('Недопустимый путь базы мониторинга');
    $db = database($path);
    $db->exec('PRAGMA foreign_keys=ON');
    if ((int) $db->query('PRAGMA user_version')->fetchColumn() > 1) throw new \RuntimeException('Для базы мониторинга нужна новая версия приложения');
    $db->exec('PRAGMA journal_mode=WAL');
    $db->beginTransaction();
    try {
        $db->exec('CREATE TABLE IF NOT EXISTS competitor_targets(
            id INTEGER PRIMARY KEY,seed_key TEXT UNIQUE,source_id TEXT NOT NULL,kind TEXT NOT NULL,url TEXT NOT NULL,label TEXT NOT NULL,
            enabled INTEGER NOT NULL DEFAULT 1,grams_override REAL,pack_label TEXT NOT NULL DEFAULT \'\',match_group TEXT NOT NULL DEFAULT \'\',match_confirmed INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL,updated_at TEXT NOT NULL,next_check_at TEXT NOT NULL,queued INTEGER NOT NULL DEFAULT 0,
            last_success TEXT,observation TEXT,fingerprint TEXT,last_attempt TEXT,error_count INTEGER NOT NULL DEFAULT 0,
            UNIQUE(source_id,kind,url));
            CREATE INDEX IF NOT EXISTS competitor_targets_due ON competitor_targets(enabled,next_check_at,id);
            CREATE TABLE IF NOT EXISTS competitor_observations(id INTEGER PRIMARY KEY,target_id INTEGER NOT NULL REFERENCES competitor_targets(id),checked_at TEXT NOT NULL,payload TEXT NOT NULL,fingerprint TEXT NOT NULL);
            CREATE INDEX IF NOT EXISTS competitor_observations_target ON competitor_observations(target_id,id);
            CREATE TABLE IF NOT EXISTS competitor_attempts(id INTEGER PRIMARY KEY,target_id INTEGER NOT NULL REFERENCES competitor_targets(id),checked_at TEXT NOT NULL,state TEXT NOT NULL,error_code TEXT,http_status INTEGER);
            CREATE INDEX IF NOT EXISTS competitor_attempts_target ON competitor_attempts(target_id,id);
            CREATE TABLE IF NOT EXISTS competitor_changes(id INTEGER PRIMARY KEY,target_id INTEGER NOT NULL REFERENCES competitor_targets(id),source_id TEXT NOT NULL,kind TEXT NOT NULL,message TEXT NOT NULL,occurred_at TEXT NOT NULL,before_payload TEXT,after_payload TEXT);
            CREATE TABLE IF NOT EXISTS competitor_discoveries(target_id INTEGER NOT NULL REFERENCES competitor_targets(id),source_id TEXT NOT NULL,url TEXT NOT NULL,title TEXT NOT NULL,first_seen TEXT NOT NULL,baseline INTEGER NOT NULL,PRIMARY KEY(target_id,url));
            CREATE TABLE IF NOT EXISTS competitor_runs(id INTEGER PRIMARY KEY,started_at TEXT NOT NULL,payload TEXT NOT NULL);
            CREATE TABLE IF NOT EXISTS competitor_migrations(name TEXT PRIMARY KEY,applied_at TEXT NOT NULL);
            PRAGMA user_version=1;');
        $seeds = competitors_default_targets();
        if (is_file(__DIR__ . '/CompetitorSeed.php')) {
            require_once __DIR__ . '/CompetitorSeed.php';
            if (function_exists(__NAMESPACE__ . '\\competitors_priority_targets')) $seeds = array_merge($seeds, competitors_priority_targets());
            if (function_exists(__NAMESPACE__ . '\\competitors_candidate_targets')) $seeds = array_merge($seeds, competitors_candidate_targets());
        }
        $stmt = $db->prepare('INSERT OR IGNORE INTO competitor_targets(seed_key,source_id,kind,url,label,enabled,grams_override,pack_label,match_group,match_confirmed,created_at,updated_at,next_check_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $now = utc_now();
        foreach ($seeds as $seed) {
            $stmt->execute([$seed['seed_key'], $seed['source_id'], $seed['kind'], competitors_validate_url($seed['url'], $seed['source_id']), $seed['label'], (int) ($seed['enabled'] ?? true), $seed['grams_override'] ?? null, $seed['pack_label'] ?? '', $seed['match_group'] ?? '', (int) ($seed['match_confirmed'] ?? false), $now, $now, $now]);
        }
        // The homepage has no product cards. Keep its identity and history, and
        // retire only the untouched factory target once; later user edits win.
        $migration = 'specii-catalog-source-v1';
        $applied = $db->prepare('SELECT 1 FROM competitor_migrations WHERE name=?');
        $applied->execute([$migration]);
        if (!$applied->fetchColumn()) {
            $archive = $db->prepare("UPDATE competitor_targets SET enabled=0,queued=0,updated_at=?
                WHERE seed_key='specii-catalog' AND source_id='specii' AND kind='catalog'
                AND url='https://www.specii.in.ua/ua/' AND label='Позиции на главной странице'
                AND enabled=1 AND grams_override IS NULL AND pack_label='' AND match_group='' AND match_confirmed=0
                AND created_at=updated_at");
            $archive->execute([$now]);
            $db->prepare('INSERT INTO competitor_migrations(name,applied_at) VALUES(?,?)')->execute([$migration, $now]);
        }
        $db->commit();
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    return $db;
}

function competitors_target_row(array $row): array
{
    return ['id' => (int) $row['id'], 'source_id' => $row['source_id'], 'kind' => $row['kind'], 'url' => $row['url'], 'label' => $row['label'],
        'enabled' => (bool) $row['enabled'], 'grams_override' => $row['grams_override'] === null ? null : (float) $row['grams_override'], 'pack_label' => $row['pack_label'],
        'match_group' => $row['match_group'], 'match_confirmed' => (bool) $row['match_confirmed'], 'next_check_at' => $row['next_check_at'], 'queued' => (bool) $row['queued'],
        'last_success' => $row['last_success'], 'observation' => $row['observation'] === null ? null : decode($row['observation']), 'last_attempt' => $row['last_attempt'] === null ? null : decode($row['last_attempt'])];
}

function competitors_target(\PDO $db, int $id): array
{
    $stmt = $db->prepare('SELECT * FROM competitor_targets WHERE id=?');
    $stmt->execute([$id]); $row = $stmt->fetch();
    if (!$row) throw new CompetitorNotFound('Страница наблюдения не найдена');
    return competitors_target_row($row);
}

function competitors_save_target(\PDO $db, array $body, ?int $id = null, bool $sandbox = false): array
{
    $allowed = ['source_id', 'kind', 'url', 'label', 'enabled', 'grams_override', 'pack_label', 'match_group', 'match_confirmed'];
    if (array_diff(array_keys($body), $allowed)) throw new \InvalidArgumentException('Неизвестные поля страницы наблюдения');
    $old = $id === null ? null : competitors_target($db, $id);
    $v = array_merge(['label' => '', 'enabled' => true, 'grams_override' => null, 'pack_label' => '', 'match_group' => '', 'match_confirmed' => false], $old ?? [], $body);
    $sourceId = competitors_text($v['source_id'] ?? null, 20, 'магазин', false);
    competitors_source($sourceId);
    $kind = $v['kind'] ?? null;
    if (!in_array($kind, ['product', 'terms', 'catalog'], true)) throw new \InvalidArgumentException('Выберите тип страницы наблюдения');
    $url = competitors_validate_url(competitors_text($v['url'] ?? null, 2000, 'ссылка', false), $sourceId, $sandbox && $kind === 'product');
    if ($old && ($sourceId !== $old['source_id'] || $kind !== $old['kind'] || $url !== $old['url'])) throw new CompetitorConflict('Для другой ссылки создайте новую страницу; прежнюю можно отключить с сохранением истории');
    $label = competitors_text($v['label'], 160, 'название');
    if ($label === '') $label = competitors_source($sourceId)['name'] . ' · ' . ['product' => 'товар', 'terms' => 'условия', 'catalog' => 'каталог'][$kind];
    $pack = competitors_text($v['pack_label'], 200, 'фасовки');
    $group = competitors_text($v['match_group'], 120, 'группа сравнения');
    if (!is_bool($v['enabled']) || !is_bool($v['match_confirmed'])) throw new \InvalidArgumentException('Неверное логическое значение');
    $grams = $v['grams_override'];
    if ($grams !== null && (!(is_int($grams) || is_float($grams)) || !is_finite((float) $grams) || $grams <= 0 || $grams > 10000000)) throw new \InvalidArgumentException('Вес упаковки должен быть положительным числом граммов');
    if ($kind !== 'product' && ($grams !== null || $group !== '' || $v['match_confirmed'])) throw new \InvalidArgumentException('Вес и группа сравнения доступны только для товаров');
    if ($v['match_confirmed'] && $group === '') throw new \InvalidArgumentException('Для подтверждения соответствия укажите группу сравнения');
    $now = utc_now();
    $db->beginTransaction();
    try {
        if ($v['enabled']) {
            $stmt = $db->prepare('SELECT count(*) FROM competitor_targets WHERE enabled=1 AND id<>?'); $stmt->execute([$id ?? 0]);
            if ((int) $stmt->fetchColumn() >= 350) throw new CompetitorConflict('Лимит — 350 активных страниц наблюдения');
            if ($kind === 'product') {
                $stmt = $db->prepare("SELECT count(*) FROM competitor_targets WHERE enabled=1 AND kind='product' AND id<>?"); $stmt->execute([$id ?? 0]);
                if ((int) $stmt->fetchColumn() >= 300) throw new CompetitorConflict('Лимит — 300 активных товарных страниц');
            }
        }
        $stmt = $db->prepare('SELECT id FROM competitor_targets WHERE source_id=? AND kind=? AND url=? AND id<>?'); $stmt->execute([$sourceId, $kind, $url, $id ?? 0]);
        if ($stmt->fetchColumn()) throw new CompetitorConflict('Эта страница уже есть в наблюдении, включая отключённые страницы');
        if ($old) {
            $recheck = $old['grams_override'] !== ($grams === null ? null : (float) $grams) || !$old['enabled'] && $v['enabled'];
            $stmt = $db->prepare('UPDATE competitor_targets SET label=?,enabled=?,grams_override=?,pack_label=?,match_group=?,match_confirmed=?,updated_at=?,next_check_at=?,queued=? WHERE id=?');
            $stmt->execute([$label, (int) $v['enabled'], $grams, $pack, $group, (int) $v['match_confirmed'], $now, $recheck ? $now : $old['next_check_at'], (int) ($v['enabled'] && ($recheck || $old['queued'])), $id]);
        } else {
            $stmt = $db->prepare('INSERT INTO competitor_targets(source_id,kind,url,label,enabled,grams_override,pack_label,match_group,match_confirmed,created_at,updated_at,next_check_at,queued) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$sourceId, $kind, $url, $label, (int) $v['enabled'], $grams, $pack, $group, (int) $v['match_confirmed'], $now, $now, $now, (int) $v['enabled']]);
            $id = (int) $db->lastInsertId();
        }
        $db->commit();
    } catch (\Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    return competitors_target($db, $id);
}

function competitors_overview(\PDO $db, ?string $directory = null): array
{
    $targets = array_map(__NAMESPACE__ . '\\competitors_target_row', $db->query('SELECT * FROM competitor_targets ORDER BY CASE source_id WHEN \'lavka\' THEN 0 ELSE 1 END,source_id,kind,id')->fetchAll());
    $changes = [];
    foreach ($db->query('SELECT * FROM competitor_changes ORDER BY id DESC LIMIT 100') as $r) $changes[] = ['id' => (int) $r['id'], 'target_id' => (int) $r['target_id'], 'source_id' => $r['source_id'], 'kind' => $r['kind'], 'message' => $r['message'], 'occurred_at' => $r['occurred_at'], 'before' => $r['before_payload'] === null ? null : decode($r['before_payload']), 'after' => $r['after_payload'] === null ? null : decode($r['after_payload'])];
    $discoveries = [];
    foreach ($db->query('SELECT * FROM competitor_discoveries WHERE baseline=0 ORDER BY first_seen DESC,target_id,url LIMIT 100') as $r) { $r['target_id'] = (int) $r['target_id']; $r['baseline'] = (bool) $r['baseline']; $discoveries[] = $r; }
    $runs = []; foreach ($db->query('SELECT payload FROM competitor_runs ORDER BY id DESC LIMIT 10') as $r) $runs[] = decode($r['payload']);
    $result = ['sources' => competitors_sources(), 'targets' => $targets, 'changes' => $changes, 'discoveries' => $discoveries, 'runs' => $runs, 'settings' => ['interval_hours' => 24, 'max_product_targets' => 300, 'max_targets' => 350, 'catalog_coverage' => 'watched_pages', 'comparison_basis' => 'exact_pack', 'initial_grams' => 50, 'shop_price_writes' => false], 'server_now' => utc_now()];
    if ($directory !== null) {
        require_once __DIR__ . '/CompetitorOwnCatalog.php';
        $result['own_catalog'] = competitors_own_catalog_read($directory);
    }
    return $result;
}

function competitors_history(\PDO $db, int $id): array
{
    $target = competitors_target($db, $id);
    $stmt = $db->prepare('SELECT checked_at,payload FROM competitor_observations WHERE target_id=? ORDER BY id DESC LIMIT 180'); $stmt->execute([$id]);
    $observations = []; foreach ($stmt as $r) $observations[] = ['checked_at' => $r['checked_at'], 'observation' => decode($r['payload'])];
    $stmt = $db->prepare('SELECT checked_at,state,error_code,http_status FROM competitor_attempts WHERE target_id=? ORDER BY id DESC LIMIT 100'); $stmt->execute([$id]);
    $attempts = []; foreach ($stmt as $r) { $r['http_status'] = $r['http_status'] === null ? null : (int) $r['http_status']; $attempts[] = $r; }
    return ['target' => $target, 'observations' => $observations, 'attempts' => $attempts];
}

function competitors_catalog_target_ids(array $catalog): array
{
    if (empty($catalog['last_success'])) return [];
    $ids = [];
    foreach ($catalog['products'] ?? [] as $product) {
        if (is_array($product) && is_int($product['target_id'] ?? null) && $product['target_id'] > 0) $ids[] = $product['target_id'];
        if (count($ids) >= 350) break;
    }
    return array_values(array_unique($ids));
}

function competitors_queue(\PDO $db, ?int $id = null, array $ownCatalogTargetIds = []): array
{
    if ($id !== null && !competitors_target($db, $id)['enabled']) throw new CompetitorConflict('Сначала включите наблюдение за страницей');
    $ownCatalogTargetIds = array_values(array_unique(array_filter(array_slice($ownCatalogTargetIds, 0, 350), function ($value) { return is_int($value) && $value > 0; })));
    if ($id !== null && in_array($id, $ownCatalogTargetIds, true)) throw new CompetitorConflict('Цена этого товара обновляется из каталога БД раз в сутки; проверка его веб-страницы не требуется');
    $exclude = $ownCatalogTargetIds ? ' AND id NOT IN (' . implode(',', array_fill(0, count($ownCatalogTargetIds), '?')) . ')' : '';
    $stmt = $db->prepare('UPDATE competitor_targets SET next_check_at=?,queued=1 WHERE enabled=1' . ($id === null ? '' : ' AND id=?') . $exclude);
    $stmt->execute(array_merge($id === null ? [utc_now()] : [utc_now(), $id], $ownCatalogTargetIds));
    return ['ok' => true, 'queued' => $stmt->rowCount(), 'message' => 'Проверка поставлена в очередь фоновой задачи'];
}

function competitors_due(\PDO $db, int $limit, array $ownCatalogTargetIds = []): array
{
    $limit = max(1, min(50, $limit));
    // A manual recheck is served before the untouched initial backlog, still only
    // by the next bounded CLI packet. HTTP never performs the request itself.
    $ownCatalogTargetIds = array_values(array_unique(array_filter(array_slice($ownCatalogTargetIds, 0, 350), function ($id) { return is_int($id) && $id > 0; })));
    $exclude = $ownCatalogTargetIds ? " AND NOT (source_id='lavka' AND kind='product' AND id IN (" . implode(',', array_fill(0, count($ownCatalogTargetIds), '?')) . '))' : '';
    $stmt = $db->prepare('SELECT * FROM competitor_targets WHERE enabled=1 AND next_check_at<=?' . $exclude . ' ORDER BY queued DESC,next_check_at,id LIMIT ' . $limit);
    $stmt->execute(array_merge([utc_now()], $ownCatalogTargetIds));
    return array_map(__NAMESPACE__ . '\\competitors_target_row', $stmt->fetchAll());
}

function competitors_observation(array $v, array $target): array
{
    if (($v['kind'] ?? null) !== $target['kind']) throw new \InvalidArgumentException('Неверный тип результата проверки');
    $result = ['kind' => $target['kind'], 'title' => competitors_text($v['title'] ?? '', 300, 'название результата'), 'offers' => [], 'text' => competitors_text($v['text'] ?? '', 12000, 'текст условий'), 'links' => [], 'warnings' => [], 'method' => competitors_text($v['method'] ?? '', 80, 'способ чтения')];
    foreach (['offers' => 100, 'links' => 500, 'warnings' => 20] as $key => $limit) if (!isset($v[$key]) || !is_array($v[$key]) || count($v[$key]) > $limit) throw new \InvalidArgumentException('Слишком большой или неверный результат проверки');
    foreach ($v['offers'] as $offer) {
        if (!is_array($offer)) throw new \InvalidArgumentException('Неверное предложение товара');
        $o = ['key' => competitors_text($offer['key'] ?? '', 180, 'ключ фасовки'), 'label' => competitors_text($offer['label'] ?? '', 200, 'название фасовки'), 'price' => null, 'old_price' => null, 'currency' => $offer['currency'] ?? '', 'grams' => $offer['grams'] ?? null, 'availability' => $offer['availability'] ?? 'unknown', 'min_quantity' => $offer['min_quantity'] ?? null, 'unit' => $offer['unit'] ?? 'unknown', 'condition' => competitors_text($offer['condition'] ?? '', 1000, 'условие цены')];
        if (!is_string($o['currency']) || !preg_match('/^(?:[A-Z]{3})?$/D', $o['currency']) || !in_array($o['availability'], ['in_stock', 'out_of_stock', 'preorder', 'unknown'], true) || !in_array($o['unit'], ['pack', 'kg', 'piece', 'unknown'], true)) throw new \InvalidArgumentException('Неверные единицы предложения');
        foreach (['price', 'old_price'] as $key) {
            $price = $offer[$key] ?? null;
            if ($price !== null) {
                if (!is_string($price) || strlen($price) > 35 || !preg_match('/^\d+(?:\.\d{1,12})?$/D', $price) || bccomp($price, '0', 12) <= 0 || bccomp($price, '1000000000', 12) > 0) throw new \InvalidArgumentException('Неизвестную цену нельзя сохранять как ноль');
                $o[$key] = rtrim(rtrim(bcadd($price, '0', 12), '0'), '.');
            }
        }
        foreach (['grams', 'min_quantity'] as $key) if ($o[$key] !== null && (!(is_int($o[$key]) || is_float($o[$key])) || !is_finite((float) $o[$key]) || $o[$key] <= 0 || $o[$key] > 10000000)) throw new \InvalidArgumentException('Неверное количество или масса предложения');
        $result['offers'][] = $o;
    }
    usort($result['offers'], function ($a, $b) { return strcmp($a['key'] . ':' . $a['label'], $b['key'] . ':' . $b['label']); });
    foreach ($v['warnings'] as $warning) $result['warnings'][] = competitors_text($warning, 500, 'пояснение проверки');
    $links = [];
    foreach ($v['links'] as $link) {
        if (!is_array($link) || !is_string($link['url'] ?? null)) continue;
        try { $url = competitors_validate_url($link['url'], $target['source_id']); }
        catch (\InvalidArgumentException $e) { continue; }
        $links[$url] = ['url' => $url, 'title' => competitors_text($link['title'] ?? '', 300, 'название ссылки')];
    }
    ksort($links); $result['links'] = array_values($links);
    if ($target['kind'] === 'product' && !$result['offers']) throw new \InvalidArgumentException('Не удалось подтвердить предложение товара');
    if ($target['kind'] === 'terms' && $result['text'] === '') throw new \InvalidArgumentException('Не удалось подтвердить текст условий');
    if ($target['kind'] === 'catalog' && !$result['links']) throw new \InvalidArgumentException('Не удалось подтвердить позиции каталога');
    return $result;
}

function competitors_projection(array $v): array
{
    if ($v['kind'] === 'product') return ['offers' => $v['offers']];
    if ($v['kind'] === 'catalog') return ['links' => $v['links']];
    return ['text' => preg_replace('/\s+/u', ' ', $v['text'])];
}

function competitors_add_change(\PDO $db, array $target, string $kind, string $message, string $at, $before, $after): void
{
    $stmt = $db->prepare('INSERT INTO competitor_changes(target_id,source_id,kind,message,occurred_at,before_payload,after_payload) VALUES(?,?,?,?,?,?,?)');
    $stmt->execute([$target['id'], $target['source_id'], $kind, $message, $at, $before === null ? null : encode($before), $after === null ? null : encode($after)]);
}

function competitors_record_attempt(\PDO $db, int $id, ?array $observation, ?string $errorCode, string $checkedAt, ?int $httpStatus = null): void
{
    if (!preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/D', $checkedAt) || strtotime($checkedAt) === false || gmdate('Y-m-d\TH:i:s\Z', strtotime($checkedAt)) !== $checkedAt || $httpStatus !== null && ($httpStatus < 100 || $httpStatus > 599)) throw new \InvalidArgumentException('Неверные метаданные проверки');
    if (($observation === null) === ($errorCode === null)) throw new \InvalidArgumentException('Нужен результат или код ошибки проверки');
    if ($errorCode !== null && !preg_match('/^[a-z][a-z0-9_]{0,79}$/D', $errorCode)) throw new \InvalidArgumentException('Неверный код ошибки проверки');
    $target = competitors_target($db, $id);
    $clean = $observation === null ? null : competitors_observation($observation, $target);
    $attempt = ['checked_at' => $checkedAt, 'state' => $errorCode === null ? 'ok' : 'error', 'error_code' => $errorCode, 'http_status' => $httpStatus];
    $db->beginTransaction();
    try {
        // Re-read within the transaction: a configuration edit must not be lost.
        $current = competitors_target($db, $id);
        $stmt = $db->prepare('INSERT INTO competitor_attempts(target_id,checked_at,state,error_code,http_status) VALUES(?,?,?,?,?)');
        $stmt->execute([$id, $checkedAt, $attempt['state'], $errorCode, $httpStatus]);
        if ($clean === null) {
            $stmt = $db->prepare('SELECT error_count FROM competitor_targets WHERE id=?'); $stmt->execute([$id]); $errors = (int) $stmt->fetchColumn() + 1;
            $delay = min(24, 1 << min(5, $errors - 1));
            $next = gmdate('Y-m-d\TH:i:s\Z', strtotime($checkedAt) + $delay * 3600);
            $stmt = $db->prepare('UPDATE competitor_targets SET last_attempt=?,next_check_at=?,queued=0,error_count=? WHERE id=?');
            $stmt->execute([encode($attempt), $next, $errors, $id]);
        } else {
            $projection = competitors_projection($clean); $fingerprint = hash('sha256', encode($projection));
            $old = $current['observation'];
            if ($clean['kind'] === 'catalog') {
                $newLinks = []; $lookup = $db->prepare('SELECT 1 FROM competitor_discoveries WHERE target_id=? AND url=?');
                $insert = $db->prepare('INSERT OR IGNORE INTO competitor_discoveries(target_id,source_id,url,title,first_seen,baseline) VALUES(?,?,?,?,?,?)');
                foreach ($clean['links'] as $link) {
                    $lookup->execute([$id, $link['url']]); $known = (bool) $lookup->fetchColumn();
                    if (!$known) { $insert->execute([$id, $target['source_id'], $link['url'], $link['title'], $checkedAt, (int) ($old === null)]); if ($old !== null) $newLinks[] = $link; }
                }
                if ($newLinks) competitors_add_change($db, $target, 'catalog', 'На наблюдаемой странице впервые замечено позиций: ' . count($newLinks), $checkedAt, null, ['links' => $newLinks]);
            } elseif ($old !== null && encode(competitors_projection($old)) !== encode($projection)) {
                if ($clean['kind'] === 'terms') {
                    competitors_add_change($db, $target, 'terms', 'Изменился текст условий на наблюдаемой странице', $checkedAt, competitors_projection($old), $projection);
                } else {
                    $oldOffers = $old['offers']; $newOffers = $clean['offers'];
                    $prices = function ($offers) { return array_map(function ($o) { return [$o['key'], $o['label'], $o['price'], $o['old_price'], $o['currency'], $o['grams'], $o['min_quantity'], $o['unit'], $o['condition']]; }, $offers); };
                    $stock = function ($offers) { return array_map(function ($o) { return [$o['key'], $o['label'], $o['availability']]; }, $offers); };
                    if ($prices($oldOffers) !== $prices($newOffers)) competitors_add_change($db, $target, 'price', 'Изменилась цена, фасовка или условие предложения', $checkedAt, ['offers' => $oldOffers], ['offers' => $newOffers]);
                    if ($stock($oldOffers) !== $stock($newOffers)) competitors_add_change($db, $target, 'availability', 'Изменились сведения о наличии товара', $checkedAt, ['offers' => $oldOffers], ['offers' => $newOffers]);
                }
            }
            $stmt = $db->prepare('INSERT INTO competitor_observations(target_id,checked_at,payload,fingerprint) VALUES(?,?,?,?)'); $stmt->execute([$id, $checkedAt, encode($clean), $fingerprint]);
            $next = gmdate('Y-m-d\TH:i:s\Z', strtotime($checkedAt) + 86400);
            $stmt = $db->prepare('UPDATE competitor_targets SET observation=?,fingerprint=?,last_success=?,last_attempt=?,next_check_at=?,queued=0,error_count=0 WHERE id=?');
            $stmt->execute([encode($clean), $fingerprint, $checkedAt, encode($attempt), $next, $id]);
        }
        $db->commit();
    } catch (\Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
}

function competitors_run_status(\PDO $db, array $status): void
{
    $at = $status['started_at'] ?? utc_now();
    if (!is_string($at) || strlen($at) > 40 || strlen(encode($status)) > 10000) throw new \InvalidArgumentException('Неверный итог фоновой проверки');
    $db->beginTransaction();
    try {
        $stmt = $db->prepare('INSERT INTO competitor_runs(started_at,payload) VALUES(?,?)'); $stmt->execute([$at, encode($status)]);
        $db->exec('DELETE FROM competitor_runs WHERE id NOT IN (SELECT id FROM competitor_runs ORDER BY id DESC LIMIT 50)');
        $db->commit();
    } catch (\Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
}

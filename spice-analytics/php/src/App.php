<?php

declare (strict_types=1);
namespace Lavka;

require_once __DIR__ . '/Analytics.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Assets.php';
require_once __DIR__ . '/Identity.php';
require_once __DIR__ . '/Competitors.php';
require_once __DIR__ . '/SandboxRuntime.php';
final class HttpError extends \RuntimeException
{
    public $status;
    public function __construct(int $status, string $message)
    {
        $this->status = $status;
        parent::__construct($message);
    }
}
function fail(int $status, string $message)
{
    throw new HttpError($status, $message);
}
function respond($data, int $status = 200)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo encode($data);
    exit;
}
function valid_string($s, int $max = 1000): string
{
    if (!is_string($s) || mb_strlen($s) > $max) {
        fail(422, 'Неверное строковое значение');
    }
    return $s;
}
function request_json(): array
{
    $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($length > 4 * 1024 * 1024) {
        fail(413, 'Слишком большой запрос');
    }
    $body = file_get_contents('php://input', false, null, 0, 4 * 1024 * 1024 + 1);
    if (strlen($body) > 4 * 1024 * 1024) {
        fail(413, 'Слишком большой запрос');
    }
    try {
        $data = decode($body);
    } catch (\Throwable $e) {
        fail(422, 'Нужен JSON-объект');
    }
    if (!is_array($data) || array_is_list($data) && $data !== []) {
        fail(422, 'Нужен JSON-объект');
    }
    return $data;
}
function report_params(): array
{
    $year = filter_var($_GET['year'] ?? null, FILTER_VALIDATE_INT);
    $asof = $_GET['as_of'] ?? '';
    $currency = $_GET['currency'] ?? 'UAH';
    if ($year === false || ($year !== 0 && ($year < 2000 || $year > 2100)) || !is_string($asof) || !valid_date($asof) || !is_string($currency) || !preg_match('/^[A-Z]{3}$/', $currency)) {
        fail(422, 'Неверные параметры отчёта');
    }
    return [$year, $asof, $currency];
}
function report_gender(): string
{
    $gender = $_GET['gender'] ?? 'all';
    if (!is_string($gender) || !in_array($gender, ['all', 'male', 'female', 'unknown'], true)) fail(422, 'Неверная группа по имени');
    return $gender;
}
function cached_report(\PDO $db, string $directory, int $year, string $asof, string $currency, string $gender = 'all'): array
{
    $cache = $directory . '/cache';
    private_dir($cache);
    $key = hash('sha256', revision($db) . ':php-v4-name-gender:' . $year . ':' . $asof . ':' . $currency . ':' . $gender);
    $file = $cache . '/report-' . $key . '.json';
    if (is_file($file)) {
        return decode(file_get_contents($file));
    }
    $lock = fopen($cache . '/.report.lock', 'c');
    if (!flock($lock, LOCK_EX)) {
        throw new \RuntimeException('Не удалось подготовить отчёт');
    }
    try {
        if (is_file($file)) {
            return decode(file_get_contents($file));
        }
        $db->beginTransaction();
        try {
            $s = new Analytics($db, $directory);
            if (!in_array($currency, $s->metadata()['currencies'], true)) {
                fail(422, 'Неизвестная валюта');
            }
            $r = $s->report($year, $asof, $currency, $gender);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
        atomic_write($file, encode($r));
        $files = glob($cache . '/report-*.json');
        if (count($files) > 32) {
            usort($files, function ($a, $b) {
                return filemtime($a) <=> filemtime($b);
            });
            foreach (array_slice($files, 0, count($files) - 32) as $old) {
                if ($old !== $file) {
                    unlink($old);
                }
            }
        }
        return $r;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
function sync_state(\PDO $db): array
{
    $payload = $db->query('SELECT payload FROM sync_status WHERE id=1')->fetchColumn();
    if (!$payload) {
        return ['configured' => false];
    }
    $r = decode($payload);
    return array_merge(['configured' => true], $r, ['stale' => empty($r['last_success']) || time() - strtotime($r['last_success']) > 900]);
}
function run_app(string $directory, string $publicUrl, string $assets, string $localBase = '')
{
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
    $target = $_SERVER['REQUEST_URI'] ?? '/';
    $requestPath = rawurldecode(parse_url($target, PHP_URL_PATH) ?: '/');
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['SERVER_PORT'] ?? '') === '443';
    try {
        try {
            $address = $publicUrl ? public_address($publicUrl) : null;
            $base = $address['base'] ?? mount_path($localBase);
        } catch (\InvalidArgumentException $e) {
            fail(503, 'Неверная настройка адреса кабинета');
        }
        if ($address && strcasecmp($host, $address['host']) !== 0) {
            fail(400, 'Недопустимый адрес кабинета');
        }
        if ($base !== '' && $requestPath !== $base && !str_starts_with($requestPath, $base . '/')) {
            fail(404, 'Страница не найдена');
        }
        if ($address && !$https) {
            header('Location: ' . $address['origin'] . $target, true, 307);
            exit;
        }
        if ($address) {
            header('Strict-Transport-Security: max-age=31536000');
        }
        if ($base !== '' && $requestPath === $base) {
            $query = parse_url($target, PHP_URL_QUERY);
            header('Location: ' . $base . '/' . ($query !== null ? '?' . $query : ''), true, 308);
            exit;
        }
        $path = substr($requestPath, strlen($base));
        if ($path === '/index.php') {
            $path = $_GET['route'] ?? '/';
            if (!is_string($path) || (!preg_match('~^/(?:[A-Za-z0-9_-]+/?)*$~D', $path) && !(str_starts_with($path, '/static/') && valid_static_asset_name(substr($path, 8))))) {
                fail(400, 'Неверный маршрут');
            }
        }
        if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            $origin = rtrim($_SERVER['HTTP_ORIGIN'] ?? '', '/');
            $expected = $address['origin'] ?? ($https ? 'https' : 'http') . '://' . $host;
            if (($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site' || $origin && $origin !== $expected) {
                fail(403, 'Недопустимый источник запроса');
            }
        }
        if (str_starts_with($path, '/static/')) {
            if (!in_array($method, ['GET', 'HEAD'], true)) {
                fail(405, 'Метод не поддерживается');
            }
            $name = substr($path, 8);
            if (!valid_static_asset_name($name) || !is_file($assets . '/' . $name) || is_link($assets . '/' . $name)) {
                fail(404, 'Файл не найден');
            }
            $type = ['js' => 'application/javascript', 'css' => 'text/css', 'geojson' => 'application/geo+json', 'png' => 'image/png'][pathinfo($name, PATHINFO_EXTENSION)];
            header('Content-Type: ' . $type . ($type === 'image/png' ? '' : '; charset=utf-8'));
            header('Cache-Control: private, max-age=60');
            if ($method !== 'HEAD') readfile($assets . '/' . $name);
            exit;
        }
        $auth = new Auth($directory);
        $public = in_array($path, ['/login', '/api/login', '/health'], true);
        $cookieName = 'lavka_analytics_session';
        $cookieOptions = ['expires' => time() + 43200, 'path' => $base . '/', 'secure' => (bool) $publicUrl || $https, 'httponly' => true, 'samesite' => 'Strict'];
        if (!$public && !$auth->valid($_COOKIE[$cookieName] ?? null)) {
            if (str_starts_with($path, '/api/')) {
                fail(401, 'Войдите в кабинет');
            }
            header('Location: ' . route_address($base, '/login'), true, 303);
            exit;
        }
        if ($path === '/health') {
            respond(['status' => 'ok']);
        }
        if ($path === '/login' || $path === '/') {
            if (!in_array($method, ['GET', 'HEAD'], true)) {
                fail(405, 'Метод не поддерживается');
            }
            header('Content-Type: text/html; charset=utf-8');
            $html = asset_page_html($assets . ($path === '/login' ? '/login.html' : '/index.html'), $base, $assets);
            if ($path === '/') {
                $environment = analytics_environment_summary($directory);
                $html = str_replace(['__ENV_MODE__', '__ENV_LABEL__', '__ENV_MESSAGE__'], array_map(function ($value) { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }, [$environment['mode'], $environment['label'], $environment['message']]), $html);
            }
            echo $html;
            exit;
        }
        if ($path === '/api/login' && $method === 'POST') {
            $b = request_json();
            $password = valid_string($b['password'] ?? null, 512);
            if (!$auth->configured()) {
                fail(503, 'Сначала настройте пароль кабинета');
            }
            try {
                $token = $auth->login($password, $_SERVER['REMOTE_ADDR'] ?? 'unknown');
            } catch (\DomainException $e) {
                fail($e->getMessage() === 'rate_limit' ? 429 : 401, $e->getMessage() === 'rate_limit' ? 'Слишком много попыток. Подождите пять минут.' : 'Неверный пароль');
            }
            setcookie($cookieName, $token, $cookieOptions);
            respond(['ok' => true]);
        }
        if ($path === '/api/logout' && $method === 'POST') {
            $auth->logout($_COOKIE[$cookieName] ?? '');
            setcookie($cookieName, '', array_replace($cookieOptions, ['expires' => 1]));
            respond(['ok' => true]);
        }
        if ($path === '/api/competitors' || str_starts_with($path, '/api/competitors/')) {
            // HTTP reads saved observations or queues CLI work; it never fetches sites.
            $competitors = competitors_db($directory);
            try {
                if ($path === '/api/competitors') {
                    if ($method !== 'GET') fail(405, 'Метод не поддерживается');
                    require_once __DIR__ . '/CompetitorSandboxJobs.php';
                    $overview = competitors_overview($competitors, $directory);
                    $overview['sandbox_pricing'] = sandbox_pricing_status($directory);
                    $overview['sandbox_pricing']['recent_jobs'] = $overview['sandbox_pricing']['enabled'] ? sandbox_price_jobs_recent($directory) : [];
                    $overview['environment'] = analytics_environment_summary($directory);
                    if ($overview['environment']['mode'] !== 'read_only') foreach ($overview['sources'] as &$source) {
                        if ($source['id'] === 'lavka') $source['base_url'] = 'https://test.lavka-speciy.com';
                    }
                    unset($source);
                    respond($overview);
                }
                if (str_starts_with($path, '/api/competitors/sandbox/')) {
                    require_once __DIR__ . '/CompetitorSandboxJobs.php';
                    try {
                        if ($path === '/api/competitors/sandbox/status') {
                            if ($method !== 'GET') fail(405, 'Метод не поддерживается');
                            $status = sandbox_pricing_status($directory);
                            $status['recent_jobs'] = $status['enabled'] ? sandbox_price_jobs_recent($directory) : [];
                            respond($status);
                        }
                        if (preg_match('~^/api/competitors/sandbox/jobs/([a-f0-9]{32})$~D', $path, $job)) {
                            if ($method !== 'GET') fail(405, 'Метод не поддерживается');
                            respond(sandbox_price_jobs_status($directory, $job[1]));
                        }
                        if (!in_array($path, ['/api/competitors/sandbox/plan', '/api/competitors/sandbox/apply', '/api/competitors/sandbox/rollback'], true)) fail(404, 'Страница не найдена');
                        if ($method !== 'POST') fail(405, 'Метод не поддерживается');
                        $body = request_json();
                        if ($path === '/api/competitors/sandbox/plan') {
                            if (array_diff(array_keys($body), ['selections']) || !is_array($body['selections'] ?? null)) fail(422, 'sandbox_selection_invalid');
                            respond(sandbox_price_jobs_enqueue($directory, 'plan', $body), 202);
                        }
                        if (array_diff(array_keys($body), ['plan_id', 'plan_hash']) || !is_string($body['plan_id'] ?? null) || !is_string($body['plan_hash'] ?? null)) fail(422, 'sandbox_plan_invalid');
                        respond(sandbox_price_jobs_enqueue($directory,
                            $path === '/api/competitors/sandbox/apply' ? 'apply' : 'rollback', $body), 202);
                    } catch (SandboxPricingError $error) {
                        $code = preg_match('/^[a-z][a-z0-9_]{1,70}$/D', $error->errorCode) ? $error->errorCode : 'sandbox_operation_failed';
                        $status = preg_match('/(?:conflict|expired|already_running|in_progress|locked)/', $code) ? 409 : (preg_match('/(?:invalid|selection|base_price|unsupported|missing)/', $code) ? 422 : 403);
                        respond(['detail' => $code, 'error_code' => $code], $status);
                    } catch (HttpError $error) { throw $error; }
                    catch (\Throwable $ignored) { respond(['detail' => 'sandbox_operation_failed', 'error_code' => 'sandbox_operation_failed'], 503); }
                }
                if ($path === '/api/competitors/discovery') {
                    if ($method !== 'GET') fail(405, 'Метод не поддерживается');
                    respond(competitors_discovery_results($competitors, (string)($_GET['search']??''), (string)($_GET['source']??'all'), (int)($_GET['page']??1), (int)($_GET['own_id']??0)));
                }
                if ($path === '/api/competitors/discovery/start') {
                    if ($method !== 'POST') fail(405, 'Метод не поддерживается');
                    $body=request_json();if($body)fail(422,'Лишние параметры');respond(competitors_discovery_start($competitors));
                }
                if ($path === '/api/competitors/discovery/review') {
                    if ($method !== 'POST') fail(405, 'Метод не поддерживается');
                    respond(competitors_discovery_review($competitors,request_json()));
                }
                if ($path === '/api/competitors/targets') {
                    if ($method !== 'POST') fail(405, 'Метод не поддерживается');
                    respond(competitors_save_target($competitors, request_json(), null, analytics_environment_summary($directory)['mode'] === 'sandbox'), 201);
                }
                if (preg_match('~^/api/competitors/targets/([1-9][0-9]{0,9})$~D', $path, $m)) {
                    if ($method === 'GET') respond(competitors_history($competitors, (int) $m[1]));
                    if ($method === 'PUT') respond(competitors_save_target($competitors, request_json(), (int) $m[1], analytics_environment_summary($directory)['mode'] === 'sandbox'));
                    fail(405, 'Метод не поддерживается');
                }
                if ($path === '/api/competitors/queue') {
                    if ($method !== 'POST') fail(405, 'Метод не поддерживается');
                    $body = request_json();
                    if (array_diff(array_keys($body), ['target_id']) || array_key_exists('target_id', $body) && (!is_int($body['target_id']) || $body['target_id'] <= 0)) fail(422, 'Неверный номер страницы наблюдения');
                    require_once __DIR__ . '/CompetitorOwnCatalog.php';
                    respond(competitors_queue($competitors, $body['target_id'] ?? null, competitors_catalog_target_ids(competitors_own_catalog_read($directory))));
                }
                fail(404, 'Страница не найдена');
            } catch (CompetitorNotFound $e) {
                fail(404, $e->getMessage());
            } catch (CompetitorConflict $e) {
                fail(409, $e->getMessage());
            }
        }
        if (!is_file($directory . '/analytics.sqlite3')) {
            fail(503, 'Сначала загрузите данные магазина');
        }
        $db = database($directory . '/analytics.sqlite3');
        init_schema($db);
        if ($path === '/api/meta' && $method === 'GET') {
            respond(array_merge(fast_metadata($db, $directory), ['today' => date('Y-m-d'), 'production' => (bool) $publicUrl, 'names_visible' => (bool) $publicUrl, 'sync' => sync_state($db), 'runtime' => 'PHP ' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION]));
        }
        if ($path === '/api/report' && $method === 'GET') {
            [$year, $asof, $currency] = report_params();
            respond(cached_report($db, $directory, $year, $asof, $currency, report_gender()));
        }
        if ($path === '/api/dashboard') {
            if ($method !== 'GET') fail(405, 'Метод не поддерживается');
            require_once __DIR__ . '/Dashboard.php';
            $currency = $_GET['currency'] ?? 'UAH';
            if (!is_string($currency) || !preg_match('/^[A-Z]{3}$/D', $currency)) fail(422, 'Неверная валюта');
            $result = cached_dashboard($db, $directory, date('Y-m-d'), $currency);
            $result['sync'] = sync_state($db);
            $result['server_now'] = utc_now();
            respond($result);
        }
        if (preg_match('~^/api/crm/customers/(\d+)$~', $path, $m) && $method === 'GET') {
            [, $asof, $currency] = report_params();
            [$s, $report, $customer, $events, $orders] = crm_context($db, $directory, $m[1], $asof, $currency);
            $summaries = (array) $report['crm']['customers'];
            $summary = $summaries[$customer['id']];
            respond(['customer' => $customer, 'summary' => $summary, 'events' => array_reverse($events), 'attempts' => crm_attempts($events, $orders, $asof), 'plan' => crm_plan($s, $customer, $orders, $events, $summary, $asof), 'server_now' => date('Y-m-d\TH:i:s'), 'as_of' => $asof, 'currency' => $currency, 'reasons' => CRM_REASONS, 'outcomes' => CRM_OUTCOMES, 'kinds' => CRM_KINDS]);
        }
        if (preg_match('~^/api/crm/customers/(\d+)/events$~', $path, $m) && $method === 'POST') {
            $b = request_json();
            respond(crm_save_event($db, $directory, $m[1], valid_string($b['as_of'] ?? null, 10), valid_string($b['currency'] ?? null, 3), $b));
        }
        if (preg_match('~^/api/crm/events/([a-f0-9]{32})/void$~', $path, $m) && $method === 'POST') {
            $b = request_json(); $reason = trim(valid_string($b['reason'] ?? '', 500));
            if ($reason === '') fail(422, 'Укажите причину отмены записи');
            $db->beginTransaction();
            try {
                $stmt = $db->prepare('SELECT id FROM crm_events WHERE id=?'); $stmt->execute([$m[1]]);
                if (!$stmt->fetchColumn()) fail(404, 'Запись не найдена');
                $stmt = $db->prepare('UPDATE crm_events SET voided_at=?,void_reason=? WHERE id=? AND voided_at IS NULL');
                $stmt->execute([date('Y-m-d\TH:i:s'), $reason, $m[1]]); bump($db); $db->commit();
            } catch (\Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
            respond(['ok' => true]);
        }
        if (preg_match('~^/api/orders/(\d+)$~', $path, $m) && $method === 'GET') {
            $s = new Analytics($db, $directory);
            if (!isset($s->orders[$m[1]])) {
                fail(404, 'Заказ не найден');
            }
            respond($s->order($m[1]));
        }
        if (preg_match('~^/api/customers/(\d+)/orders$~', $path, $m) && $method === 'GET') {
            [$year, $asof, $currency] = report_params();
            $r = cached_report($db, $directory, $year, $asof, $currency);
            $customer = null;
            foreach ($r['customers'] as $c) {
                if ($c['id'] === $m[1]) {
                    $customer = $c;
                    break;
                }
            }
            if (!$customer) {
                fail(404, 'Покупатель не найден');
            }
            $s = new Analytics($db, $directory);
            $orders = [];
            foreach (array_reverse($customer['order_ids']) as $id) {
                $orders[] = $s->order($id);
            }
            respond(['customer' => $customer, 'orders' => $orders]);
        }
        if ($path === '/api/settings' && $method === 'PUT') {
            $b = request_json();
            $s = new Analytics($db, $directory);
            foreach (['interval_days' => [1, 730], 'grace_days' => [0, 365], 'minimum_orders' => [3, 30], 'followup_min_orders' => [1, 1000], 'contact_cooldown_days' => [0, 365]] as $key => [$min, $max]) {
                if (!isset($b[$key])) {
                    $b[$key] = $s->settings[$key];
                }
                if (!is_int($b[$key]) || $b[$key] < $min || $b[$key] > $max) {
                    fail(422, 'Неверные правила расчёта');
                }
            }
            if (empty($b['status_ids']) || !is_array($b['status_ids'])) {
                fail(422, 'Выберите статусы');
            }
            foreach ($b['status_ids'] as $id) {
                if (!is_string($id) || !isset($s->statuses[$id])) {
                    fail(422, 'Неизвестный статус');
                }
            }
            $b = array_intersect_key($b, $s->settings);
            $db->beginTransaction();
            $stmt = $db->prepare("INSERT OR REPLACE INTO settings VALUES('report',?)");
            $stmt->execute([encode($b)]);
            bump($db);
            $db->commit();
            respond(['ok' => true]);
        }
        if (preg_match('~^/api/customers/(\d+)/action$~', $path, $m) && $method === 'PUT') {
            $b = request_json();
            $s = new Analytics($db, $directory);
            if (!isset($s->orders[$m[1]])) {
                fail(404, 'Покупатель не найден');
            }
            $stage = $b['stage'] ?? '';
            if (!in_array($stage, ['planned', 'contacted', 'replied', 'declined', 'do_not_contact', 'new'], true)) {
                fail(422, 'Неизвестная отметка');
            }
            $note = valid_string($b['note'] ?? '', 3000);
            foreach (['contact_date', 'next_contact'] as $key) {
                if (isset($b[$key]) && (!is_string($b[$key]) || !valid_date($b[$key]))) {
                    fail(422, 'Неверная дата контакта');
                }
            }
            $payload = ['stage' => $stage, 'contact_date' => $b['contact_date'] ?? (in_array($stage, ['contacted', 'replied'], true) ? date('Y-m-d') : null), 'next_contact' => $b['next_contact'] ?? null, 'note' => $note, 'updated_at' => date(DATE_ATOM)];
            $db->beginTransaction();
            $stmt = $db->prepare('INSERT OR REPLACE INTO customer_actions VALUES(?,?)');
            $stmt->execute([$m[1], encode($payload)]);
            bump($db);
            $db->commit();
            respond(['ok' => true]);
        }
        if ($path === '/api/ga4/import' && $method === 'POST') {
            $b = request_json();
            $content = valid_string($b['content'] ?? null, 2000000);
            respond(ga4_import($db, $content, valid_string($b['kind'] ?? null, 20), valid_string($b['currency'] ?? null, 3)));
        }
        if ($path === '/api/identity/merge-pairs' && $method === 'POST') {
            $b = request_json();
            if (!isset($b['pairs']) || !is_array($b['pairs'])) fail(422, 'Нужен список пар контактов');
            respond(merge_identity_pairs($db, $directory, $b['pairs'], valid_string($b['as_of'] ?? null, 10), valid_string($b['currency'] ?? null, 3)));
        }
        if ($path === '/api/identity/merge' && $method === 'POST') {
            $b = request_json();
            $ids = $b['ids'] ?? [];
            $asof = $b['as_of'] ?? '';
            if (!is_array($ids) || count($ids) < 2 || count($ids) > 20 || count(array_unique($ids)) < 2 || !is_string($asof) || !valid_date($asof)) {
                fail(422, 'Неверные идентификаторы');
            }
            $s = new Analytics($db, $directory);
            foreach ($ids as $id) {
                if (!is_string($id) || !isset($s->orders[$id]) || substr($s->orders[$id]['date_added'], 0, 10) > $asof) {
                    fail(422, 'Неверные идентификаторы');
                }
            }
            $db->beginTransaction();
            $stmt = $db->prepare('INSERT OR IGNORE INTO manual_links VALUES(?,?,?)');
            foreach (array_slice($ids, 1) as $other) {
                $pair = [$ids[0], $other];
                sort($pair, SORT_NUMERIC);
                $stmt->execute([$pair[0], $pair[1], utc_now()]);
            }
            bump($db);
            $db->commit();
            respond(['ok' => true]);
        }
        if ($path === '/api/identity/unmerge' && $method === 'POST') {
            $b = request_json();
            $id = valid_string($b['id'] ?? null, 30);
            $asof = $b['as_of'] ?? '';
            if (!is_string($asof) || !valid_date($asof)) {
                fail(422, 'Неверная дата');
            }
            $s = new Analytics($db, $directory);
            $dsu = $s->identity(array_values(array_filter($s->orders, function ($o) use ($asof) {
                return substr($o['date_added'], 0, 10) <= $asof;
            })));
            if (!isset($dsu->parents[$id])) {
                fail(404, 'Покупатель не найден');
            }
            $root = $dsu->find($id);
            $linked = [];
            foreach ($dsu->parents as $oid => $_) {
                if ($dsu->find($oid) === $root) {
                    $linked[$oid] = true;
                }
            }
            $db->beginTransaction();
            $stmt = $db->prepare('DELETE FROM manual_links WHERE a=? AND b=?');
            foreach ($s->links as $link) {
                if (isset($linked[$link['a']]) || isset($linked[$link['b']])) {
                    $stmt->execute([$link['a'], $link['b']]);
                }
            }
            bump($db);
            $db->commit();
            respond(['ok' => true]);
        }
        fail(404, 'Страница не найдена');
    } catch (HttpError $e) {
        respond(['detail' => $e->getMessage()], $e->status);
    } catch (\InvalidArgumentException $e) {
        respond(['detail' => $e->getMessage()], 422);
    } catch (\Throwable $e) {
        respond(['detail' => 'Кабинет временно недоступен. Проверьте закрытые настройки и последнюю синхронизацию.'], 503);
    }
}

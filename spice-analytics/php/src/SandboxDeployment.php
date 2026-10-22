<?php

declare(strict_types=1);
namespace Lavka;
require_once __DIR__ . '/SandboxRuntime.php';
require_once __DIR__ . '/CompetitorOwnCatalog.php';

/** Keep generated launchers byte-for-byte compatible with existing installations. */
function sandbox_deployment_launcher(string $root, string $data, string $url): string
{
    return "<?php\ndeclare(strict_types=1);\nif (PHP_VERSION_ID < 70300) { http_response_code(503); header('Content-Type: text/plain; charset=utf-8'); header('Cache-Control: no-store'); exit('Для кабинета нужен PHP 7.3+ и проверка расширений. Версию PHP магазина менять не требуется.'); }\nputenv('ANALYTICS_DATA_DIR='." . var_export($data, true) . ");\nputenv('ANALYTICS_PUBLIC_URL='." . var_export($url, true) . ");\nrequire " . var_export($root . '/public/index.php', true) . ";\n";
}

/** Read-only preflight; an injectable checker is a callable test seam, never CLI input. */
function sandbox_deployment_preflight(string $root, string $webroot, string $data, string $url, string $shop, ?callable $checker = null): array
{
    if (PHP_VERSION_ID < 80200) throw new \RuntimeException('Переключение выполняйте существующим PHP 8.2 CLI');
    $configuration = sandbox_runtime_configuration($data);
    $old = $configuration['marker']['previous_data_dir'];
    if ($url !== 'https://test.lavka-speciy.com/analytics' || $webroot !== $shop . '/analytics'
        || $configuration['marker']['shop_root'] !== $shop) throw new \RuntimeException('Параметры песочницы не совпадают с установкой');
    foreach ([$old, $data] as $directory) {
        $path = $directory . '/deployment-php.json';
        if (!is_file($path) || is_link($path) || filesize($path) > 65536) throw new \RuntimeException('Не найден манифест существующей установки');
        $metadata = decode(file_get_contents($path));
        foreach (['application_root' => $root, 'document_root' => $webroot, 'data_dir' => $directory, 'public_url' => $url, 'shop_root' => $shop] as $key => $value) {
            if (($metadata[$key] ?? null) !== $value) throw new \RuntimeException('Манифест установки не совпадает с путями песочницы');
        }
    }
    $launcherFile = $webroot . '/index.php';
    if (!is_file($launcherFile) || is_link($launcherFile)) throw new \RuntimeException('Не найден launcher кабинета');
    $before = file_get_contents($launcherFile);
    $oldLauncher = sandbox_deployment_launcher($root, $old, $url);
    $newLauncher = sandbox_deployment_launcher($root, $data, $url);
    if ($before !== $oldLauncher && $before !== $newLauncher) throw new \RuntimeException('Launcher изменён вручную; переключение остановлено');
    $ordersFile = $data . '/analytics.sqlite3';
    if (!is_file($ordersFile) || is_link($ordersFile)) throw new \RuntimeException('Сначала синхронизируйте заказы из тестовой БД');
    $orders = new \SQLite3($ordersFile, SQLITE3_OPEN_READONLY);
    try {
        $orders->enableExceptions(true);
        $payload = $orders->querySingle('SELECT payload FROM sync_status WHERE id=1');
        $state = is_string($payload) ? decode($payload) : [];
        if (($state['state'] ?? null) !== 'ok' || ($state['source_database'] ?? null) !== 'lavkasp_test'
            || ($state['environment'] ?? null) !== 'sandbox') throw new \RuntimeException('Нет успешного снимка заказов из lavkasp_test');
    } finally { $orders->close(); }
    $catalog = competitors_own_catalog_read($data);
    if ($catalog['state'] !== 'ok' || ($catalog['source_database'] ?? null) !== 'lavkasp_test'
        || ($catalog['environment'] ?? null) !== 'sandbox') throw new \RuntimeException('Сначала успешно загрузите каталог тестовой БД');
    if (!$checker) {
        require_once dirname(__DIR__) . '/bin/check_sandbox.php';
        $checker = function ($directory) { return \lavka_sandbox_check(['data' => $directory]); };
    }
    $checked = $checker($data);
    if (!is_array($checked) || ($checked['state'] ?? null) !== 'ok' || ($checked['database'] ?? null) !== 'lavkasp_test') throw new \RuntimeException('Проверка подключения песочницы не пройдена');
    return ['webroot' => $webroot, 'data' => $data, 'before' => $before, 'old_launcher' => $oldLauncher,
        'new_launcher' => $newLauncher, 'already_switched' => $before === $newLauncher];
}

/** Only the known public analytics launcher changes. Storefront files remain untouched. */
function sandbox_deployment_commit(array $preflight): array
{
    $data = $preflight['data']; $webroot = $preflight['webroot'];
    $lock = fopen($data . '/.sandbox-deploy.lock', 'c');
    if (!$lock) throw new \RuntimeException('Не удалось заблокировать переключение');
    $temporary = null;
    try {
        if (!flock($lock, LOCK_EX | LOCK_NB)) throw new \RuntimeException('Переключение уже выполняется');
        if (is_link($webroot . '/index.php') || file_get_contents($webroot . '/index.php') !== $preflight['before']) throw new \RuntimeException('Launcher изменился после проверки');
        $backup = $data . '/before-sandbox-launcher.php';
        if (is_link($backup) || is_file($backup) && file_get_contents($backup) !== $preflight['old_launcher']) throw new \RuntimeException('Прежняя копия launcher не совпадает');
        if (!file_exists($backup)) atomic_write($backup, $preflight['old_launcher']);
        if (!$preflight['already_switched']) {
            $temporary = tempnam($webroot, '.sandbox-launcher-');
            if ($temporary === false || file_put_contents($temporary, $preflight['new_launcher'], LOCK_EX) === false
                || !chmod($temporary, 0644) || !rename($temporary, $webroot . '/index.php')) throw new \RuntimeException('Не удалось заменить launcher');
            $temporary = null;
        }
        return ['switched' => true, 'environment' => 'sandbox', 'database' => 'lavkasp_test', 'production_data' => 'preserved', 'shop_files' => 'preserved'];
    } finally {
        if (is_string($temporary) && is_file($temporary)) unlink($temporary);
        flock($lock, LOCK_UN); fclose($lock);
    }
}

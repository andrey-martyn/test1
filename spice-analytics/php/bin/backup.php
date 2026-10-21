<?php

declare (strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/src/common.php';
$args = getopt('', ['data:', 'destination:']);
try {
    $data = realpath($args['data'] ?? '');
    $destination = $args['destination'] ?? '';
    if (!$data || !$destination || !\Lavka\str_starts_with($destination, '/')) {
        throw new InvalidArgumentException('Нужны --data и абсолютный --destination');
    }
    $destination = \Lavka\canonical_path($destination);
    if ($destination === $data || \Lavka\str_starts_with($destination, $data . '/')) {
        throw new InvalidArgumentException('Копии должны храниться отдельно');
    }
    $manifest = is_file($data . '/deployment-php.json') ? \Lavka\decode(file_get_contents($data . '/deployment-php.json')) : [];
    foreach (['document_root', 'shop_root'] as $key) {
        $public = $manifest[$key] ?? null;
        if ($public && ($destination === $public || \Lavka\str_starts_with($destination, $public . '/'))) {
            throw new InvalidArgumentException('Нельзя сохранять копии в публичной папке');
        }
    }
    \Lavka\private_dir($destination);
    if (!is_file($data . '/analytics.sqlite3')) {
        throw new InvalidArgumentException('Нет базы для копирования');
    }
    $copyDir = $destination . '/.pending-' . bin2hex(random_bytes(5));
    \Lavka\private_dir($copyDir);
    $original = new SQLite3($data . '/analytics.sqlite3', SQLITE3_OPEN_READONLY);
    $original->busyTimeout(15000);
    $copyPath = $copyDir . '/analytics.sqlite3';
    if (method_exists($original, 'backup')) {
        $copy = new SQLite3($copyPath);
        $ok = $original->backup($copy);
    } else {
        // PHP 7.3 has no SQLite3::backup. VACUUM INTO creates a consistent
        // SQLite snapshot (including committed WAL pages), never a raw file copy.
        if (SQLite3::version()['versionNumber'] < 3027000) throw new RuntimeException('Для копии нужен SQLite 3.27+');
        $ok = $original->exec("VACUUM INTO '" . SQLite3::escapeString($copyPath) . "'");
        $copy = new SQLite3($copyPath, SQLITE3_OPEN_READONLY);
    }
    if (!$ok || $copy->querySingle('PRAGMA quick_check') !== 'ok') {
        throw new RuntimeException('Резервная копия не прошла проверку');
    }
    $copy->close();
    $original->close();
    chmod($copyDir . '/analytics.sqlite3', 0600);
    foreach (['access-php.json', 'source.json', 'deployment-php.json'] as $name) {
        if (is_file($data . '/' . $name)) {
            copy($data . '/' . $name, $copyDir . '/' . $name);
            chmod($copyDir . '/' . $name, 0600);
        }
    }
    $completed = $destination . '/' . gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(3));
    if (!rename($copyDir, $completed)) {
        throw new RuntimeException('Не удалось сохранить копию');
    }
    echo "Закрытая резервная копия создана и проверена. Активные сессии не включены.\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

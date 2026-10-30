<?php
declare(strict_types=1);
// CLI helper only: existing module SQLite; no application bootstrap or MySQL.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', '0');
function relocation_require(bool $value, string $code): void
{
    if (!$value) throw new RuntimeException($code);
}
function relocation_digest(SQLite3 $db): array
{
    $result = [];
    $tables = $db->query("SELECT name,sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
    while ($table = $tables->fetchArray(SQLITE3_ASSOC)) {
        $name = $table['name'];
        $query = $db->query('SELECT * FROM "' . str_replace('"', '""', $name) . '"');
        $rows = [];
        while (($row = $query->fetchArray(SQLITE3_NUM)) !== false) $rows[] = hash('sha256', serialize($row));
        $query->finalize();
        sort($rows, SORT_STRING);
        $result[$name] = ['count' => count($rows), 'digest' => hash('sha256', implode("\n", $rows)), 'schema' => hash('sha256', $table['sql'] ?? '')];
    }
    $tables->finalize();
    return $result;
}
try {
    relocation_require(PHP_VERSION_ID >= 80200 && class_exists('SQLite3'), 'php82_sqlite_required');
    $input = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
    $root = $input['root'] ?? '';
    relocation_require(is_string($root) && realpath($root) === $root && is_dir($root), 'root_invalid');
    $files = $input['files'] ?? null;
    relocation_require(is_array($files) && count($files) > 0 && count($files) <= 50, 'file_list_invalid');
    $fingerprints = [];
    foreach ($files as $path) {
        relocation_require(is_string($path) && is_file($path) && realpath($path) === $path && !is_link($path), 'existing_database_required');
        $relative = substr($path, strlen($root) + 1);
        relocation_require(str_starts_with($path, $root . '/') &&
            preg_match('~^(?:analytics-private|analytics-private-test|review-factory-private-test|private/analytics/(?:previous|test)|private/review-factory/test)/[a-zA-Z0-9._-]+\.sqlite3$~D', $relative) === 1, 'database_not_allowed');
        $db = new SQLite3($path, SQLITE3_OPEN_READWRITE); // Never CREATE a missing database.
        try {
            $db->enableExceptions(true);
            $db->busyTimeout(2000);
            relocation_require($db->querySingle('PRAGMA quick_check') === 'ok', 'sqlite_integrity_failed');
            $db->exec('BEGIN EXCLUSIVE');
            try { $before = relocation_digest($db); } finally { $db->exec('ROLLBACK'); }
            $checkpoint = $db->querySingle('PRAGMA wal_checkpoint(TRUNCATE)', true);
            relocation_require(is_array($checkpoint) && (int) reset($checkpoint) === 0, 'sqlite_reader_or_writer_active');
            relocation_require(relocation_digest($db) === $before, 'logical_data_changed');
            $fingerprints[$relative] = $before;
        } finally { $db->close(); }
    }
    echo json_encode(['state' => 'verified', 'fingerprints' => $fingerprints], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), "\n";
} catch (Throwable $error) {
    // Fixed output only; raw SQL errors or values must not escape the helper.
    echo "{\"state\":\"stopped\",\"code\":\"sqlite_check_failed\"}\n";
    exit(1);
}

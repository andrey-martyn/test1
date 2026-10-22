<?php

declare(strict_types=1);
namespace Lavka;

require_once __DIR__ . '/CompetitorSandboxPricing.php';

/** The web process only writes a private SQLite request; MySQL stays in PHP 8.2 CLI. */
function sandbox_price_jobs_payload(string $operation, array $payload): array
{
    if ($operation === 'plan') {
        if (array_keys($payload) !== ['selections'] || !is_array($payload['selections'])) throw new SandboxPricingError('sandbox_job_invalid');
        $selections = $payload['selections']; $seen = [];
        if (!$selections || count($selections) > 20 || array_values($selections) !== $selections) throw new SandboxPricingError('sandbox_selection_limit');
        foreach ($selections as $selection) {
            if (!is_array($selection) || count($selection) !== 2 || array_diff(array_keys($selection), ['target_id', 'base_50'])
                || !is_int($selection['target_id'] ?? null) || $selection['target_id'] < 1 || isset($seen[$selection['target_id']])) throw new SandboxPricingError('sandbox_target_invalid');
            sandbox_pricing_amount($selection['base_50'] ?? null);
            $seen[$selection['target_id']] = true;
        }
    } elseif (in_array($operation, ['apply', 'rollback'], true)) {
        if (count($payload) !== 2 || array_diff(array_keys($payload), ['plan_id', 'plan_hash'])
            || !is_string($payload['plan_id'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D', $payload['plan_id'])
            || !is_string($payload['plan_hash'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $payload['plan_hash'])) throw new SandboxPricingError('sandbox_plan_invalid');
    } else throw new SandboxPricingError('sandbox_job_invalid');
    return $payload;
}

function sandbox_price_jobs_database(string $directory): \PDO
{
    $path = $directory . '/sandbox-price-jobs.sqlite3';
    if (is_link($path)) throw new SandboxPricingError('sandbox_job_store_invalid');
    $db = database($path); $db->exec('PRAGMA busy_timeout=2000');
    $db->exec("CREATE TABLE IF NOT EXISTS sandbox_price_jobs(
        id TEXT PRIMARY KEY,operation TEXT NOT NULL,request_payload TEXT NOT NULL,state TEXT NOT NULL,
        result_payload TEXT,error_code TEXT,created_at TEXT NOT NULL,updated_at TEXT NOT NULL,expires_at TEXT NOT NULL);
        CREATE INDEX IF NOT EXISTS sandbox_price_jobs_pending ON sandbox_price_jobs(state,created_at)");
    return $db;
}

function sandbox_price_jobs_enqueue(string $directory, string $operation, array $payload): array
{
    sandbox_pricing_configuration($directory);
    $payload = sandbox_price_jobs_payload($operation, $payload);
    $db = sandbox_price_jobs_database($directory); $id = bin2hex(random_bytes(16)); $now = utc_now(); $encoded = encode($payload);
    $db->exec('BEGIN IMMEDIATE');
    try {
        $expired = $db->prepare("UPDATE sandbox_price_jobs SET state='error',error_code='sandbox_job_expired',updated_at=? WHERE state='queued' AND expires_at<?");
        $expired->execute([$now, $now]);
        $existing = $db->prepare("SELECT id FROM sandbox_price_jobs WHERE operation=? AND request_payload=? AND state IN ('queued','running') ORDER BY created_at,rowid LIMIT 1");
        $existing->execute([$operation, $encoded]); $existingId = $existing->fetchColumn(); $existing->closeCursor();
        if ($existingId) {
            $db->exec('COMMIT');
            return ['state' => 'queued', 'job_id' => $existingId, 'operation' => $operation, 'database' => 'lavkasp_test'];
        }
        if ((int) $db->query("SELECT COUNT(*) FROM sandbox_price_jobs WHERE state IN ('queued','running')")->fetchColumn() >= 10) throw new SandboxPricingError('sandbox_job_queue_full');
        $db->prepare('INSERT INTO sandbox_price_jobs VALUES(?,?,?,\'queued\',NULL,NULL,?,?,?)')->execute([
            $id, $operation, $encoded, $now, $now, gmdate('Y-m-d\TH:i:s\Z', time() + 600)]);
        $db->exec('COMMIT');
    } catch (\Throwable $error) {
        $db->exec('ROLLBACK');
        if ($error instanceof SandboxPricingError) throw $error;
        throw new SandboxPricingError('sandbox_job_store_failed');
    }
    return ['state' => 'queued', 'job_id' => $id, 'operation' => $operation, 'database' => 'lavkasp_test'];
}

/** Read-only open: polling never creates a queue or starts an executor. */
function sandbox_price_jobs_read(string $directory, callable $read): array
{
    sandbox_pricing_configuration($directory);
    $path = $directory . '/sandbox-price-jobs.sqlite3';
    if (!is_file($path)) return [];
    if (is_link($path)) throw new SandboxPricingError('sandbox_job_store_invalid');
    try {
        $db = new \SQLite3($path, SQLITE3_OPEN_READONLY); $db->enableExceptions(true); $db->busyTimeout(2000);
        try { return $read($db); } finally { $db->close(); }
    } catch (SandboxPricingError $error) { throw $error; }
    catch (\Throwable $ignored) { throw new SandboxPricingError('sandbox_job_store_failed'); }
}

function sandbox_price_jobs_status(string $directory, string $jobId): array
{
    if (!preg_match('/^[a-f0-9]{32}$/D', $jobId)) throw new SandboxPricingError('sandbox_job_invalid');
    $row = sandbox_price_jobs_read($directory, function (\SQLite3 $db) use ($jobId): array {
        $stmt = $db->prepare('SELECT id,operation,state,result_payload,error_code,created_at,updated_at FROM sandbox_price_jobs WHERE id=?');
        $stmt->bindValue(1, $jobId, SQLITE3_TEXT); $result = $stmt->execute(); $row = $result->fetchArray(SQLITE3_ASSOC);
        $result->finalize(); $stmt->close(); return $row ?: [];
    });
    if (!$row) throw new SandboxPricingError('sandbox_job_not_found');
    $result = ['state' => $row['state'], 'job_id' => $row['id'], 'operation' => $row['operation'], 'database' => 'lavkasp_test',
        'created_at' => $row['created_at'], 'updated_at' => $row['updated_at']];
    if ($row['state'] === 'done') $result['result'] = decode($row['result_payload']);
    elseif ($row['state'] === 'error') $result['error_code'] = $row['error_code'];
    return $result;
}

function sandbox_price_jobs_recent(string $directory): array
{
    return sandbox_price_jobs_read($directory, function (\SQLite3 $db): array {
        $query = $db->query('SELECT id AS job_id,operation,state,created_at,updated_at FROM sandbox_price_jobs ORDER BY created_at DESC,rowid DESC LIMIT 5');
        $rows = []; while ($row = $query->fetchArray(SQLITE3_ASSOC)) $rows[] = $row; $query->finalize(); return $rows;
    });
}

/** Optional executor is a local test seam, never an HTTP or CLI selector. */
function sandbox_price_jobs_run(string $directory, int $limit = 3, int $seconds = 45, ?callable $executor = null): array
{
    if (PHP_SAPI !== 'cli' || PHP_VERSION_ID < 80200) throw new SandboxPricingError('requires_php82_cli');
    if ($limit < 1 || $limit > 3 || $seconds < 1 || $seconds > 45) throw new SandboxPricingError('sandbox_job_worker_limits');
    sandbox_pricing_configuration($directory); $started = microtime(true);
    $summary = ['state' => 'idle', 'database' => 'lavkasp_test', 'processed' => 0, 'succeeded' => 0, 'failed' => 0, 'interrupted' => 0];
    $lockPath = $directory . '/.sandbox-jobs.lock';
    if (is_link($lockPath)) throw new SandboxPricingError('sandbox_lock_invalid');
    $lock = fopen($lockPath, 'c');
    if (!$lock) throw new SandboxPricingError('sandbox_job_store_failed');
    chmod($lockPath, 0600);
    try {
        if (!flock($lock, LOCK_EX | LOCK_NB)) return array_merge($summary, ['state' => 'skipped', 'reason' => 'already_running']);
        if (!is_file($directory . '/sandbox-price-jobs.sqlite3')) return $summary;
        $db = sandbox_price_jobs_database($directory); $now = utc_now();
        // A stopped worker may already have changed a MyISAM row. Never replay it automatically.
        $interrupted = $db->prepare("UPDATE sandbox_price_jobs SET state='error',error_code='sandbox_job_interrupted',updated_at=? WHERE state='running'");
        $interrupted->execute([$now]); $summary['interrupted'] = $interrupted->rowCount();
        $expired = $db->prepare("UPDATE sandbox_price_jobs SET state='error',error_code='sandbox_job_expired',updated_at=? WHERE state='queued' AND expires_at<?");
        $expired->execute([$now, $now]);
        $executor = $executor ?: function (string $operation, array $payload, string $data): array {
            if ($operation === 'plan') return sandbox_pricing_plan($data, $payload['selections']);
            if ($operation === 'apply') return sandbox_pricing_apply($data, $payload['plan_id'], $payload['plan_hash']);
            if ($operation === 'rollback') return sandbox_pricing_rollback($data, $payload['plan_id'], $payload['plan_hash']);
            throw new SandboxPricingError('sandbox_job_invalid');
        };
        while ($summary['processed'] < $limit && microtime(true) - $started < $seconds) {
            $job = $db->query("SELECT * FROM sandbox_price_jobs WHERE state='queued' ORDER BY created_at,rowid LIMIT 1")->fetch();
            if (!$job) break;
            $claim = $db->prepare("UPDATE sandbox_price_jobs SET state='running',updated_at=? WHERE id=? AND state='queued'");
            $claim->execute([utc_now(), $job['id']]); if ($claim->rowCount() !== 1) continue;
            $summary['processed']++;
            try {
                sandbox_pricing_configuration($directory);
                if (strtotime($job['expires_at']) < time()) throw new SandboxPricingError('sandbox_job_expired');
                $request = sandbox_price_jobs_payload($job['operation'], decode($job['request_payload']));
                $result = $executor($job['operation'], $request, $directory);
                if (!is_array($result)) throw new SandboxPricingError('sandbox_job_failed');
                $db->prepare("UPDATE sandbox_price_jobs SET state='done',result_payload=?,error_code=NULL,updated_at=? WHERE id=?")->execute([encode($result), utc_now(), $job['id']]);
                $summary['succeeded']++;
            } catch (\Throwable $error) {
                $code = $error instanceof SandboxPricingError && preg_match('/^[a-z][a-z0-9_]{1,79}$/D', $error->errorCode) ? $error->errorCode : 'sandbox_job_failed';
                $db->prepare("UPDATE sandbox_price_jobs SET state='error',result_payload=NULL,error_code=?,updated_at=? WHERE id=?")->execute([$code, utc_now(), $job['id']]);
                $summary['failed']++;
            }
        }
        $summary['state'] = $summary['failed'] || $summary['interrupted'] ? 'partial' : ($summary['processed'] ? 'ok' : 'idle');
        $summary['seconds'] = round(microtime(true) - $started, 3); return $summary;
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}

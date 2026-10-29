<?php
declare(strict_types=1);
namespace ReviewFactory;
require_once __DIR__ . "/Core.php";

function catalogDigest(array $products): string
{
    $items = [];
    foreach ($products as $p) {
        $items[] = [(int) $p["id"], (string) $p["model"], (string) $p["sku"], (string) $p["ean"]];
    }
    usort($items, function ($a, $b) {
        return $a[0] <=> $b[0];
    });
    return hash("sha256", json($items));
}
/** Stable unique identifiers outrank coincidentally identical database IDs. */
function migrationMapping(array $source, array $target, array $overrides = []): array
{
    $indexes = [];
    foreach (["ean", "sku", "model"] as $key) {
        foreach ($target as $p) {
            $value = trim((string) ($p[$key] ?? ""));
            if ($value !== "") {
                $indexes[$key][$value][] = (int) $p["id"];
            }
        }
    }
    $targetIds = array_map("intval", array_column($target, "id"));
    $manual = [];
    foreach ($overrides as $item) {
        $old = integer($item["source_id"] ?? null, 1, 2147483647);
        $new = integer($item["target_id"] ?? null, 1, 2147483647);
        text($item["reason"] ?? "", 300, true);
        if (isset($manual[$old]) || !in_array($new, $targetIds, true)) {
            throw new Problem("migration_manual_mapping_invalid");
        }
        $manual[$old] = $new;
    }
    $map = [];
    $problems = [];
    $used = [];
    foreach ($source as $p) {
        $candidate = null;
        $reason = "";
        if (isset($manual[(int) $p["id"]])) {
            $candidate = $manual[(int) $p["id"]];
        }
        foreach (isset($manual[(int) $p["id"]]) ? [] : ["ean", "sku", "model"] as $key) {
            $value = trim((string) ($p[$key] ?? ""));
            if ($value === "") {
                continue;
            }
            $matches = $indexes[$key][$value] ?? [];
            if (count($matches) === 1) {
                if ($candidate !== null && $candidate !== $matches[0]) {
                    $candidate = null;
                    $reason = "conflicting_identifiers";
                    break;
                }
                $candidate = $matches[0];
            } elseif (count($matches) > 1) {
                $reason = "ambiguous_" . $key;
            }
        }
        if ($candidate === null) {
            $problems[] = ["source_id" => (int) $p["id"], "reason" => $reason ?: "no_unique_identifier"];
            continue;
        }
        if (isset($used[$candidate])) {
            $problems[] = ["source_id" => (int) $p["id"], "reason" => "duplicate_destination"];
            continue;
        }
        $used[$candidate] = true;
        $map[(int) $p["id"]] = $candidate;
    }
    return [
        "mapping" => $map,
        "problems" => $problems,
        "source_digest" => catalogDigest($source),
        "target_digest" => catalogDigest($target),
    ];
}
/** Writes only a new private SQLite copy; never writes to either shop. */
function migrateCopy(\PDO $source, string $destination, array $mapping): array
{
    if (file_exists($destination)) {
        throw new Problem("Назначение уже существует");
    }
    $parent = dirname($destination);
    if (!is_dir($parent)) {
        throw new Problem("Родительский каталог не существует");
    }
    $products = rows($source, "SELECT id FROM products");
    foreach ($products as $p) {
        if (!isset($mapping[$p["id"]]) || !is_int($mapping[$p["id"]]) || $mapping[$p["id"]] < 1) {
            throw new Problem("migration_mapping_incomplete");
        }
    }
    if (count(array_unique(array_values($mapping))) !== count($mapping)) {
        throw new Problem("migration_mapping_not_unique");
    }
    $tmp = $destination . "." . bin2hex(random_bytes(6)) . ".tmp";
    mkdir($tmp, 0700);
    try {
        $source->exec("VACUUM INTO " . $source->quote($tmp . "/factory.sqlite3"));
        $copy = database($tmp);
        $copy->exec("PRAGMA foreign_keys=OFF");
        transaction(
            $copy,
            function () use ($copy, $mapping) {
                $copy->exec(
                    "CREATE TABLE IF NOT EXISTS migration_archive(id INTEGER PRIMARY KEY,entity TEXT NOT NULL,payload TEXT NOT NULL,archived_at TEXT NOT NULL)",
                );
                foreach (rows($copy, "SELECT * FROM drafts WHERE state<>'draft'") as $d) {
                    execute(
                        $copy,
                        "INSERT INTO migration_archive(entity,payload,archived_at) VALUES(?,?,?)",
                        [$d["id"], json($d), now()],
                    );
                }
                foreach (rows($copy, "SELECT * FROM reply_drafts") as $d) {
                    execute(
                        $copy,
                        "INSERT INTO migration_archive(entity,payload,archived_at) VALUES(?,?,?)",
                        ["reply:" . $d["id"], json($d), now()],
                    );
                }
                foreach (rows($copy, "SELECT * FROM reply_jobs") as $j) {
                    execute(
                        $copy,
                        "INSERT INTO migration_archive(entity,payload,archived_at) VALUES(?,?,?)",
                        ["reply-job:" . $j["draft_id"], json($j), now()],
                    );
                }
                // Native review IDs belong to the source shop. Do not replay TEST replies.
                $copy->exec(
                    'DELETE FROM reply_jobs;DELETE FROM reply_drafts;DELETE FROM reply_reviews;DELETE FROM settings WHERE key IN (\'replies_updated\',\'replies_last_run\',\'replies_error\')',
                );
                $copy->exec(
                    "DELETE FROM sessions; DELETE FROM attempts; DELETE FROM outbox; DELETE FROM shop_reviews; DELETE FROM queue; DELETE FROM settings WHERE key IN ('shop_updated','queue_updated','matches_updated','competitors_last_run','sync_error','maintenance'); UPDATE drafts SET state='historical',review_id=NULL,revision=revision+1 WHERE state<>'draft'; UPDATE products SET active=0,stock=NULL,customer_count=NULL,customer_rating=NULL,sales90=NULL,views30=NULL,cooldown_until=NULL; UPDATE settings SET value='0' WHERE key='bridge_ready';UPDATE settings SET value='production' WHERE key='environment'",
                );
                execute($copy, "DELETE FROM monitoring_requests");
                foreach ($mapping as $old => $new) {
                    execute($copy, "UPDATE products SET id=? WHERE id=?", [-(int) $old, (int) $old]);
                    foreach (["drafts", "assignments", "competitors", "competitor_history"] as $table) {
                        execute($copy, "UPDATE " . $table . " SET product_id=? WHERE product_id=?", [
                            -(int) $old,
                            (int) $old,
                        ]);
                    }
                }
                foreach ($mapping as $old => $new) {
                    execute(
                        $copy,
                        "UPDATE products SET id=?,url=REPLACE(url,?,?),image=REPLACE(image,?,?) WHERE id=?",
                        [
                            $new,
                            "https://test.lavka-speciy.com",
                            "https://lavka-speciy.com",
                            "https://test.lavka-speciy.com",
                            "https://lavka-speciy.com",
                            -(int) $old,
                        ],
                    );
                    foreach (["drafts", "assignments", "competitors", "competitor_history"] as $table) {
                        execute($copy, "UPDATE " . $table . " SET product_id=? WHERE product_id=?", [
                            $new,
                            -(int) $old,
                        ]);
                    }
                }
                audit($copy, null, "environment.migrated", "production", [
                    "mapping" => $mapping,
                    "shop_writes" => false,
                ]);
            },
            true,
        );
        $copy->exec("PRAGMA foreign_keys=ON");
        if (
            rows($copy, "PRAGMA foreign_key_check") ||
            $copy->query("PRAGMA quick_check")->fetchColumn() !== "ok"
        ) {
            throw new Problem("migration_integrity_failed");
        }
        $counts = [
            "users" => (int) one($copy, "SELECT count(*) n FROM users")["n"],
            "drafts" => (int) one($copy, "SELECT count(*) n FROM drafts WHERE state='draft'")["n"],
            "test_archive" => (int) one($copy, "SELECT count(*) n FROM drafts WHERE state='historical'")[
                "n"
            ],
        ];
        $copy->exec("PRAGMA wal_checkpoint(TRUNCATE)");
        $copy = null;
        if (!rename($tmp, $destination)) {
            throw new Problem("migration_rename_failed");
        }
        return $counts;
    } catch (\Throwable $e) {
        $copy = null;
        foreach (glob($tmp . "/*") as $file) {
            unlink($file);
        }
        rmdir($tmp);
        throw $e;
    }
}

<?php
declare(strict_types=1);
namespace ReviewFactory;
require_once __DIR__ . "/Simulation.php";

const DEMO_LABEL = "ДЕМО · синтетическая запись, не отзыв покупателя";

/** All storefront reads use read-only SQLite handles; no shop connection exists here. */
function demoContext(string $directory): array
{
    $directory = rtrim($directory, "/");
    if (!is_dir($directory) || is_link($directory) || realpath($directory) !== $directory) {
        throw new Problem("demo_private_path", 503);
    }
    foreach (["instance.json", "factory.sqlite3", "synthetic-reviews.sqlite3"] as $file) {
        if (!is_file($directory . "/" . $file) || is_link($directory . "/" . $file)) {
            throw new Problem("demo_not_installed", 503);
        }
    }
    $cfg = json_decode(file_get_contents($directory . "/instance.json"), true, 16, JSON_THROW_ON_ERROR);
    if (
        ($cfg["environment"] ?? "") !== "test" ||
        rtrim($cfg["shop_url"] ?? "", "/") !== "https://test.lavka-speciy.com" ||
        parse_url($cfg["public_url"] ?? "", PHP_URL_HOST) !== "test.lavka-speciy.com"
    ) {
        throw new Problem("demo_test_only", 403);
    }
    $core = new \SQLite3($directory . "/factory.sqlite3", SQLITE3_OPEN_READONLY);
    $core->enableExceptions(true);
    $core->busyTimeout(1000);
    $core->exec("PRAGMA query_only=ON");
    if ($core->querySingle("SELECT value FROM settings WHERE key='environment'") !== "test") {
        $core->close();
        throw new Problem("demo_test_only", 403);
    }
    if ($core->querySingle("SELECT value FROM settings WHERE key='maintenance'") !== null) {
        $core->close();
        throw new Problem("demo_maintenance", 503);
    }
    $db = new \SQLite3($directory . "/synthetic-reviews.sqlite3", SQLITE3_OPEN_READONLY);
    $db->enableExceptions(true);
    $db->busyTimeout(1000);
    $db->exec("PRAGMA query_only=ON");
    return [$directory, $core, $db];
}
function demoInitialize(\PDO $core): void
{
    $db = simulationDb($core);
    $db->exec("CREATE TABLE IF NOT EXISTS demo_decisions(entry_id TEXT PRIMARY KEY REFERENCES entries(id),decision TEXT NOT NULL CHECK(decision IN ('approved','hidden')),entry_revision INTEGER NOT NULL,actor TEXT NOT NULL,decided_at TEXT NOT NULL);
      CREATE TABLE IF NOT EXISTS demo_events(id INTEGER PRIMARY KEY,entry_id TEXT NOT NULL,decision TEXT NOT NULL,entry_revision INTEGER NOT NULL,actor TEXT NOT NULL,created_at TEXT NOT NULL);");
}
function demoRows(\SQLite3 $db, string $sql, array $args = []): array
{
    $q = $db->prepare($sql);
    foreach (array_values($args) as $i => $v) {
        $q->bindValue($i + 1, $v, is_int($v) ? SQLITE3_INTEGER : SQLITE3_TEXT);
    }
    $result = $q->execute();
    $rows = [];
    while ($r = $result->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = $r;
    }
    $result->finalize();
    $q->close();
    return $rows;
}
function demoList(string $directory, string $filter = "pending", int $page = 1): array
{
    enum($filter, ["pending", "approved", "hidden", "all"]);
    integer($page, 1, 10000);
    [$dir, $core, $db] = demoContext($directory);
    $reading = false;
    try {
        $db->exec("BEGIN");
        $reading = true;
        $state = "CASE WHEN d.entry_revision=e.revision THEN d.decision ELSE 'pending' END";
        $from =
            " FROM entries e LEFT JOIN demo_decisions d ON d.entry_id=e.id WHERE e.state='saved' AND e.origin='synthetic'";
        $args = [];
        if ($filter !== "all") {
            $from .= " AND " . $state . "=?";
            $args[] = $filter;
        }
        $total = (int) demoRows($db, "SELECT count(*) n" . $from, $args)[0]["n"];
        $rows = demoRows(
            $db,
            "SELECT e.id,e.product_id,e.product_name,e.model_author,e.model_city,e.model_date,e.rating,e.body,e.revision,e.saved_at," .
                $state .
                " demo_state" .
                $from .
                " ORDER BY e.saved_at DESC,e.seq DESC LIMIT 20 OFFSET " .
                ($page - 1) * 20,
            $args,
        );
        return ["total" => $total, "items" => $rows, "page" => $page, "label" => DEMO_LABEL];
    } finally {
        if ($reading) {
            $db->exec("ROLLBACK");
        }
        $db->close();
        $core->close();
    }
}
function demoModerate(
    string $directory,
    string $id,
    string $decision,
    string $actor,
    int $revision
): void {
    if (!preg_match('/^[a-f0-9]{32}$/D', $id) || !preg_match('/^ocstore:[1-9][0-9]{0,9}$/D', $actor)) {
        throw new Problem("demo_invalid_request");
    }
    enum($decision, ["approved", "hidden"]);
    integer($revision, 1, 2147483647);
    [$dir, $core, $read] = demoContext($directory);
    $read->close();
    $db = new \PDO("sqlite:" . $dir . "/synthetic-reviews.sqlite3", null, null, [
        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
    ]);
    $db->exec("PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000; BEGIN IMMEDIATE");
    try {
        if ($core->querySingle("SELECT value FROM settings WHERE key='maintenance'") !== null) {
            throw new Problem("demo_maintenance", 503);
        }
        $e = one(
            $db,
            "SELECT revision FROM entries WHERE id=? AND state='saved' AND origin='synthetic'",
            [$id],
        );
        if (!$e) {
            throw new Problem("demo_entry_missing", 404);
        }
        if ((int) $e["revision"] !== $revision) {
            throw new Problem("demo_revision_conflict", 409);
        }
        execute(
            $db,
            "INSERT INTO demo_decisions(entry_id,decision,entry_revision,actor,decided_at) VALUES(?,?,?,?,?) ON CONFLICT(entry_id) DO UPDATE SET decision=excluded.decision,entry_revision=excluded.entry_revision,actor=excluded.actor,decided_at=excluded.decided_at",
            [$id, $decision, $revision, $actor, now()],
        );
        execute(
            $db,
            "INSERT INTO demo_events(entry_id,decision,entry_revision,actor,created_at) VALUES(?,?,?,?,?)",
            [$id, $decision, $revision, $actor, now()],
        );
        $db->exec("COMMIT");
    } catch (\Throwable $e) {
        $db->exec("ROLLBACK");
        throw $e;
    } finally {
        $core->close();
    }
}
function demoRead(string $directory, array $products, ?int $detail = null): array
{
    if (count($products) > 40) {
        throw new Problem("demo_id_limit");
    }
    $products = array_values(
        array_unique(
            array_map(function ($id) {
                return integer($id, 1, 2147483647);
            }, $products),
        ),
    );
    if ($detail !== null && !in_array($detail, $products, true)) {
        throw new Problem("demo_detail_id");
    }
    [$dir, $core, $db] = demoContext($directory);
    $reading = false;
    try {
        $db->exec("BEGIN");
        $reading = true;
        $output = [];
        if (!$products) {
            return ["environment" => "test", "label" => DEMO_LABEL, "products" => (object) []];
        }
        $from =
            " FROM entries e JOIN demo_decisions d ON d.entry_id=e.id AND d.entry_revision=e.revision WHERE e.state='saved' AND e.origin='synthetic' AND d.decision='approved'";
        foreach (
            demoRows(
                $db,
                "SELECT e.product_id,count(*) demo_count,avg(e.rating) demo_average" .
                    $from .
                    " AND e.product_id IN (" .
                    implode(",", array_fill(0, count($products), "?")) .
                    ") GROUP BY e.product_id",
                $products,
            )
            as $row
        ) {
            $output[(int) $row["product_id"]] = [
                "demo_count" => (int) $row["demo_count"],
                "demo_average" => (float) $row["demo_average"],
                "entries" => [],
            ];
        }
        if ($detail !== null && isset($output[$detail])) {
            foreach (
                demoRows(
                    $db,
                    "SELECT e.model_author,e.model_city,e.model_date,e.rating,e.body" .
                        $from .
                        " AND e.product_id=? ORDER BY e.model_date DESC,e.seq DESC LIMIT 20",
                    [$detail],
                )
                as $r
            ) {
                $output[$detail]["entries"][] = [
                    "label" => DEMO_LABEL,
                    "author" => $r["model_author"],
                    "city" => $r["model_city"],
                    "model_date" => $r["model_date"],
                    "demo_score" => (int) $r["rating"],
                    "body" => mb_substr($r["body"], 0, 3000),
                    "excerpt" => mb_strlen($r["body"]) > 3000,
                ];
            }
        }
        return ["environment" => "test", "label" => DEMO_LABEL, "products" => (object) $output];
    } finally {
        if ($reading) {
            $db->exec("ROLLBACK");
        }
        $db->close();
        $core->close();
    }
}

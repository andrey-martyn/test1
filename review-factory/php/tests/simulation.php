<?php
declare(strict_types=1);
require_once dirname(__DIR__) . "/src/App.php";
require_once dirname(__DIR__) . "/src/Migration.php";
use function ReviewFactory\{
    database,
    execute,
    one,
    rows,
    now,
    json,
    simulationClaim,
    simulationEntry,
    simulationSave,
    simulationDb,
};
$checks = 0;
function sc(bool $ok, string $message): void
{
    global $checks;
    ++$checks;
    if (!$ok) {
        throw new RuntimeException($message);
    }
}
function sd(callable $fn, int $status): void
{
    try {
        $fn();
    } catch (ReviewFactory\Problem $e) {
        sc($e->status === $status, "Expected rejection " . $status);
        return;
    }
    throw new RuntimeException("Unexpected acceptance");
}
$dir = sys_get_temp_dir() . "/rf-simulation-" . bin2hex(random_bytes(6));
mkdir($dir, 0700);
$db = database($dir);
$manifest = [
    "environment" => "test",
    "shop_url" => "https://test.lavka-speciy.com",
    "public_url" => "https://test.lavka-speciy.com/review-workshop/",
];
file_put_contents($dir . "/instance.json", json($manifest));
try {
    $u = ReviewFactory\createUser($db, [
        "login" => "admin-review",
        "name" => "Оператор",
        "staff_role" => "сотрудник",
        "role" => "admin",
        "password" => "Synthetic-test-password",
    ]);
    $b = ReviewFactory\createUser($db, [
        "login" => "other-user",
        "name" => "Коллега",
        "staff_role" => "сотрудник",
        "role" => "author",
        "password" => "Synthetic-test-password",
    ]);
    sc(
        $u["work_mode"] === "testing" && $b["work_mode"] === "testing",
        "TEST defaults to QA for all roles",
    );
    for ($i = 1; $i <= 12; $i++) {
        execute(
            $db,
            "INSERT INTO products(id,model,name,url,stock,customer_count,customer_rating,sales90,updated_at) VALUES(?,?,?,?,?,?,?,?,?)",
            [
                $i,
                "SYN-" . $i,
                "Тестовая специя " . $i,
                "https://test.lavka-speciy.com/",
                10,
                9,
                3.5,
                20 - $i,
                now(),
            ],
        );
    }
    ReviewFactory\rebuildQueue($db);
    $a = simulationClaim($db, $u, 5)["ids"];
    $other = simulationClaim($db, $b, 5)["ids"];
    $productsA = [];
    foreach ($a as $id) {
        $productsA[] = simulationEntry($db, $u, $id)["entry"]["product_id"];
    }
    $productsB = [];
    foreach ($other as $id) {
        $productsB[] = simulationEntry($db, $b, $id)["entry"]["product_id"];
    }
    sc(
        count($a) === 5 && !array_intersect($productsA, $productsB),
        "Packets reserve distinct products across operators",
    );
    sc(simulationClaim($db, $u, 1)["ids"] === $a, "Reload retains packet");
    $first = simulationEntry($db, $u, $a[0]);
    $entry = $first["entry"];
    sc(strpos($entry["body"], "[ТЕСТОВЫЙ ОТЗЫВ") === 0, "Text is prefilled and labelled");
    sd(function () use ($db, $b, $a) {
        simulationEntry($db, $b, $a[0]);
    }, 404);
    sd(function () use ($db, $u) {
        ReviewFactory\claim($db, $u);
    }, 403);
    sd(function () use ($db, $u) {
        ReviewFactory\saveReply($db, $u, []);
    }, 403);
    $saved = simulationSave($db, $u, $a[0], [
        "body" => "Проверка <script>текст, не HTML</script>",
        "revision" => 1,
        "rating" => 1,
        "model_author" => "Подмена",
        "model_date" => "2000-01-01",
    ]);
    foreach (["model_author", "model_city", "model_date", "rating", "mood", "registration"] as $key) {
        sc($saved[$key] === $entry[$key], "Autofield frozen: " . $key);
    }
    sd(function () use ($db, $u, $a) {
        simulationSave($db, $u, $a[0], ["body" => "Stale", "revision" => 1]);
    }, 409);
    $finished = simulationSave($db, $u, $a[0], ["body" => $saved["body"], "revision" => 2], true);
    sc($finished["state"] === "saved", "Private entry saved");
    sc(
        simulationSave($db, $u, $a[0], ["body" => $saved["body"], "revision" => 2], true)["id"] ===
            $a[0],
        "Ambiguous retry idempotent",
    );
    sc(
        count(ReviewFactory\simulationPreview($db, $u, (int) $entry["product_id"])["entries"]) === 1,
        "Preview shows saved synthetic entry",
    );
    $sim = simulationDb($db);
    foreach (rows($sim, "SELECT id,operator_id,body,revision FROM entries WHERE state='draft'") as $e) {
        simulationSave(
            $db,
            (int) $e["operator_id"] === (int) $u["id"] ? $u : $b,
            $e["id"],
            ["body" => $e["body"], "revision" => (int) $e["revision"]],
            true,
        );
    }
    // Issue/save another 90 records through the real API, not direct fixture inserts.
    for ($i = 0; $i < 90; $i++) {
        $id = simulationClaim($db, $u, 1)["ids"][0];
        $e = simulationEntry($db, $u, $id)["entry"];
        simulationSave($db, $u, $id, ["body" => $e["body"], "revision" => (int) $e["revision"]], true);
    }
    $stats = ReviewFactory\simulationSummary($db, $u)["stats"];
    sc(
        (int) $stats["total"] === 100 && (int) $stats["guests"] === 15 && (int) $stats["no_city"] === 20,
        "100 issues produce exactly 15 model guests and 20 blank cities",
    );
    foreach (
        rows(
            $sim,
            "SELECT product_id,count(*) n,avg(rating) average FROM entries WHERE state='saved' GROUP BY product_id",
        )
        as $r
    ) {
        sc(
            (int) $r["n"] < 2 || ((float) $r["average"] >= 4.4 && (float) $r["average"] <= 4.8),
            "Per-product synthetic mean stays in band after first entry",
        );
    }
    sc(
        !one($sim, "SELECT id FROM entries WHERE model_date<'2022-01-01' OR model_date>?", [
            min(gmdate("Y-m-d"), "2026-12-31"),
        ]),
        "Dates stay in allowed past range",
    );
    sc(
        (int) one($sim, "SELECT count(DISTINCT substr(model_date,1,4)) n FROM entries")["n"] === 5,
        "Model dates spread over all five years",
    );
    sc(
        (int) one($db, "SELECT count(*) n FROM outbox")["n"] === 0 &&
            (int) one($db, "SELECT count(*) n FROM reply_jobs")["n"] === 0,
        "QA saves create no native publication or reply jobs",
    );
    sc(
        (int) one($db, "SELECT customer_count FROM products WHERE id=1")["customer_count"] === 9 &&
            (float) one($db, "SELECT customer_rating FROM products WHERE id=1")["customer_rating"] ===
                3.5,
        "Customer counts and ratings untouched",
    );
    execute($db, "INSERT INTO settings VALUES('maintenance','test')");
    sd(function () use ($db, $u) {
        simulationClaim($db, $u, 1);
    }, 503);
    execute($db, "DELETE FROM settings WHERE key='maintenance'");
    foreach (["environment", "public_url", "shop_url"] as $key) {
        $bad = $manifest;
        $bad[$key] = $key === "environment" ? "production" : "https://lavka-speciy.com/";
        file_put_contents($dir . "/instance.json", json($bad));
        sd(function () use ($db) {
            simulationDb($db);
        }, 403);
    }
    file_put_contents($dir . "/instance.json", json($manifest));
    execute($db, "UPDATE settings SET value='production' WHERE key='environment'");
    sd(function () use ($db) {
        simulationDb($db);
    }, 403);
    execute($db, "UPDATE settings SET value='test' WHERE key='environment'");
    $map = [];
    for ($i = 1; $i <= 12; $i++) {
        $map[$i] = $i + 100;
    }
    ReviewFactory\migrateCopy($db, $dir . "-prod", $map);
    sc(
        !file_exists($dir . "-prod/synthetic-reviews.sqlite3"),
        "Production migration does not copy synthetic database",
    );
    // Simulate upgrading existing accounts with an active session and saved native draft.
    $hash = one($db, "SELECT hash FROM users WHERE id=?", [$u["id"]])["hash"];
    $session = ReviewFactory\login($db, "admin-review", "Synthetic-test-password", "127.0.0.1");
    execute(
        $db,
        "INSERT INTO drafts(id,product_id,author_id,body,state,created_at,updated_at) VALUES('preserved',1,?,'Сохранённый материал','submitted',?,?)",
        [$u["id"], now(), now()],
    );
    execute($db, "INSERT INTO outbox(draft_id,state,updated_at) VALUES('preserved','pending',?)", [
        now(),
    ]);
    execute(
        $db,
        "INSERT INTO reply_drafts(id,review_id,user_id,body,base_reply,context_hash,source_database,state,created_at,updated_at) VALUES('old-reply',1,?,'Сохранённый ответ','','context','lavkasp_test','queued',?,?)",
        [$u["id"], now(), now()],
    );
    execute($db, "INSERT INTO reply_jobs(draft_id,payload,updated_at) VALUES('old-reply','{}',?)", [
        now(),
    ]);
    execute($db, "UPDATE users SET work_mode='replies'");
    execute($db, "DELETE FROM settings WHERE key='simulation_workspace_v1'");
    $db = database($dir);
    sc(
        one($db, "SELECT hash,work_mode FROM users WHERE id=?", [$u["id"]]) === [
            "hash" => $hash,
            "work_mode" => "testing",
        ],
        "Upgrade preserves credentials and selects QA",
    );
    sc(
        ReviewFactory\session($db, $session["token"])["work_mode"] === "testing",
        "Existing login session sees updated work mode",
    );
    sc(
        one($db, "SELECT state FROM outbox WHERE draft_id='preserved'")["state"] === "paused" &&
            one($db, "SELECT body FROM drafts WHERE id='preserved'")["body"] === "Сохранённый материал",
        "Upgrade pauses native job and preserves saved text",
    );
    sc(
        one($db, "SELECT state FROM reply_jobs WHERE draft_id='old-reply'")["state"] === "paused" &&
            one($db, "SELECT state FROM reply_drafts WHERE id='old-reply'")["state"] === "paused",
        "Upgrade pauses old reply draft and job",
    );
    echo json(["state" => "ok", "checks" => $checks, "php" => PHP_VERSION]) . "\n";
} finally {
    $db = null;
    $sim = null;
    foreach ([$dir, $dir . "-prod"] as $folder) {
        if (is_dir($folder)) {
            foreach (glob($folder . "/*") as $file) {
                unlink($file);
            }
            rmdir($folder);
        }
    }
}

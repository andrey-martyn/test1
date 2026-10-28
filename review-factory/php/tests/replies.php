<?php
declare(strict_types=1);
require_once dirname(__DIR__) . "/src/App.php";
require_once dirname(__DIR__) . "/src/Migration.php";
use function ReviewFactory\{database, execute, one, rows, now, json};
$checks = 0;
function rc(bool $ok, string $message): void
{
    global $checks;
    ++$checks;
    if (!$ok) {
        throw new RuntimeException($message);
    }
}
function deniedReply(callable $fn, int $status): void
{
    try {
        $fn();
    } catch (ReviewFactory\Problem $e) {
        rc($e->status === $status, "Expected status " . $status);
        return;
    }
    throw new RuntimeException("Expected rejection");
}
$dir = sys_get_temp_dir() . "/rf-replies-" . bin2hex(random_bytes(6));
mkdir($dir, 0700);
$db = database($dir);
try {
    $manager = ReviewFactory\createUser($db, [
        "login" => "admin-review",
        "name" => "Таня",
        "staff_role" => "хозяйка",
        "role" => "admin",
        "password" => "Synthetic-test-password",
    ]);
    $author = ReviewFactory\createUser($db, [
        "login" => "writer",
        "name" => "Автор",
        "staff_role" => "сотрудник",
        "role" => "author",
        "password" => "Synthetic-test-password",
    ]);
    $other = ReviewFactory\createUser($db, [
        "login" => "editor",
        "name" => "Редактор",
        "staff_role" => "сотрудник",
        "role" => "moderator",
        "password" => "Synthetic-test-password",
    ]);
    rc($manager["work_mode"] === "replies", "Reply account has a separate workspace");
    deniedReply(function () use ($db, $manager) {
        ReviewFactory\claim($db, $manager);
    }, 403);
    deniedReply(function () use ($db, $author) {
        ReviewFactory\replyList($db, $author);
    }, 403);
    execute($db, "INSERT INTO products(id,model,name,url,updated_at) VALUES(1,?,?,?,?)", [
        "MODEL-1",
        "Тестовая специя",
        "https://test.lavka-speciy.com/index.php?route=product/product&product_id=1",
        now(),
    ]);
    $r = [
        "id" => 901,
        "product_id" => 1,
        "product_name" => "Тестовая специя",
        "author" => "Покупатель теста",
        "body" => "Подскажите, как хранить специю после открытия?",
        "rating" => 3,
        "status" => 1,
        "published_at" => "2026-10-20 12:00:00",
    ];
    execute(
        $db,
        "INSERT INTO reply_reviews(id,product_id,product_name,author,body,rating,status,published_at,shop_reply,context_hash,source_database,search_text,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)",
        [
            $r["id"],
            $r["product_id"],
            $r["product_name"],
            $r["author"],
            $r["body"],
            $r["rating"],
            $r["status"],
            $r["published_at"],
            "",
            ReviewFactory\replyContext($r),
            "lavkasp_test",
            mb_strtolower($r["product_name"] . " " . $r["author"] . " " . $r["body"]),
            now(),
        ],
    );
    rc(ReviewFactory\replyList($db, $manager)["total"] === 1, "Existing review visible");
    rc(
        ReviewFactory\replyList($db, $manager, [
            "search" => "ПОКУПАТЕЛЬ",
            "page" => 1,
            "filter" => "all",
        ])["total"] === 1,
        "Search supports Cyrillic case",
    );
    rc(
        ReviewFactory\replyList($db, $manager, ["search" => "%", "page" => 1, "filter" => "all"])[
            "total"
        ] === 0,
        "Search wildcard escaped",
    );
    deniedReply(function () use ($db, $manager) {
        ReviewFactory\saveReply($db, $manager, [
            "review_id" => 901,
            "body" => "<script>alert(1)</script>",
        ]);
    }, 422);
    deniedReply(function () use ($db, $manager) {
        ReviewFactory\saveReply($db, $manager, [
            "review_id" => 901,
            "body" => "&amp;lt;img src=x&amp;gt;",
        ]);
    }, 422);
    $d = ReviewFactory\saveReply($db, $manager, [
        "review_id" => 901,
        "body" => "Спасибо за вопрос! Храните специю в закрытой упаковке.",
        "rating" => 5,
        "author" => "Чужое имя",
    ]);
    deniedReply(function () use ($db, $other) {
        ReviewFactory\saveReply($db, $other, ["review_id" => 901, "body" => "Другой ответ."]);
    }, 409);
    deniedReply(function () use ($db, $other, $d) {
        ReviewFactory\queueReply($db, $other, $d["id"], 1);
    }, 404);
    $queued = ReviewFactory\queueReply($db, $manager, $d["id"], (int) $d["revision"]);
    rc($queued["state"] === "queued", "Reply queued without web MySQL");
    ReviewFactory\queueReply($db, $manager, $d["id"], 1);
    rc(
        (int) one($db, "SELECT count(*) n FROM reply_jobs")["n"] === 1,
        "Queue retry creates no duplicate",
    );
    $payload = json_decode(one($db, "SELECT payload FROM reply_jobs")["payload"], true);
    rc(
        $payload["author"] === $r["author"] &&
            $payload["rating"] === 3 &&
            $payload["body"] === $r["body"],
        "Original review comes from stored snapshot, not request",
    );
    rc(
        ReviewFactory\replyList($db, $manager)["total"] === 0,
        "Queued replies leave unanswered work queue",
    );
    rc(
        one($db, "SELECT shop_reply FROM reply_reviews WHERE id=901")["shop_reply"] === "",
        "Web queue never pretends shop is updated",
    );
    deniedReply(function () use ($db, $manager, $d) {
        ReviewFactory\saveReply($db, $manager, [
            "review_id" => 901,
            "draft_id" => $d["id"],
            "revision" => 2,
            "body" => "Новый текст.",
        ]);
    }, 409);
    $copyDir = $dir . "-prod";
    ReviewFactory\migrateCopy($db, $copyDir, [1 => 101]);
    $copy = database($copyDir);
    rc(
        (int) one($copy, "SELECT count(*) n FROM reply_jobs")["n"] === 0,
        "TEST reply jobs never move to production",
    );
    rc(
        (int) one($copy, "SELECT count(*) n FROM reply_reviews")["n"] === 0,
        "TEST review IDs never become production sources",
    );
    rc(
        (int) one($copy, "SELECT count(*) n FROM migration_archive WHERE entity LIKE 'reply:%'")["n"] ===
            1,
        "Reply text preserved in private migration archive",
    );
    $copy = null;
    // Upgrade a legacy account without changing its credentials.
    $legacyDir = $dir . "-legacy";
    mkdir($legacyDir, 0700);
    $legacy = new PDO("sqlite:" . $legacyDir . "/factory.sqlite3");
    $legacy->exec(
        "CREATE TABLE users(id INTEGER PRIMARY KEY,login TEXT UNIQUE,name TEXT,staff_role TEXT,role TEXT,hash TEXT,active INTEGER DEFAULT 1,revision INTEGER DEFAULT 1)",
    );
    $hash = password_hash("Legacy-synthetic-password", PASSWORD_BCRYPT);
    $q = $legacy->prepare("INSERT INTO users(id,login,name,staff_role,role,hash) VALUES(7,?,?,?,?,?)");
    $q->execute(["admin-review", "Таня", "хозяйка", "admin", $hash]);
    $q = null;
    $legacy = null;
    $legacy = database($legacyDir);
    $upgraded = one($legacy, "SELECT * FROM users WHERE id=7");
    rc(
        $upgraded["work_mode"] === "replies" && $upgraded["hash"] === $hash,
        "Legacy account upgraded without password reset",
    );
    $legacy = null;
    echo json(["state" => "ok", "checks" => $checks, "php" => PHP_VERSION]) . "\n";
} finally {
    $db = null;
    foreach ([$dir, $dir . "-prod", $dir . "-legacy"] as $folder) {
        if (is_dir($folder)) {
            foreach (glob($folder . "/*") as $file) {
                unlink($file);
            }
            rmdir($folder);
        }
    }
}

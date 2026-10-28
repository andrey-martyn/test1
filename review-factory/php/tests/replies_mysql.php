<?php
declare(strict_types=1);
require_once dirname(__DIR__) . "/src/Shop.php";
require_once dirname(__DIR__) . "/src/Replies.php";
use function ReviewFactory\{one, rows, execute, database, json};
$env = [];
foreach (file("/test-secrets/mysql.env", FILE_IGNORE_NEW_LINES) as $line) {
    $parts = explode("=", $line, 2);
    $env[$parts[0]] = $parts[1] ?? "";
}
$shop = ReviewFactory\shopConnect([
    "DB_HOSTNAME" => "rf-mysql",
    "DB_PORT" => "3306",
    "DB_DATABASE" => "lavkasp_test",
    "DB_USERNAME" => "root",
    "DB_PASSWORD" => $env["MYSQL_ROOT_PASSWORD"],
]);
$checks = 0;
function mc(bool $ok, string $m): void
{
    global $checks;
    ++$checks;
    if (!$ok) {
        throw new RuntimeException($m);
    }
}
$prefix = "rf_reply_";
foreach (["review", "product_description", "product_to_store"] as $table) {
    $shop->exec("DROP TABLE IF EXISTS " . $prefix . $table);
}
$shop->exec(
    "CREATE TABLE rf_reply_review(review_id INT PRIMARY KEY,product_id INT,customer_id INT NOT NULL,author VARCHAR(64),text TEXT,admin_reply TEXT NOT NULL,plus TEXT NOT NULL,minus TEXT NOT NULL,rating INT,status INT,date_added DATETIME,date_modified DATETIME) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;INSERT INTO rf_reply_review VALUES(17,1,3,'Клиент теста','Как хранить специю?','','Упаковка','Нет инструкции',3,1,'2026-10-20 12:00:00','2026-10-20 12:00:00');CREATE TABLE rf_reply_product_description(product_id INT,language_id INT,name VARCHAR(255));INSERT INTO rf_reply_product_description VALUES(1,1,'Специя');CREATE TABLE rf_reply_product_to_store(product_id INT,store_id INT);INSERT INTO rf_reply_product_to_store VALUES(1,0)",
);
$dir = sys_get_temp_dir() . "/rf-reply-mysql-" . bin2hex(random_bytes(6));
mkdir($dir, 0700);
$db = database($dir);
try {
    $user = ReviewFactory\createUser($db, [
        "login" => "admin-review",
        "name" => "Таня",
        "staff_role" => "хозяйка",
        "role" => "admin",
        "work_mode" => "replies",
        "password" => "Synthetic-test-password",
    ]);
    $original = one($shop, "SELECT * FROM rf_reply_review WHERE review_id=17");
    ReviewFactory\importReplies($db, $shop, $prefix, 1);
    mc((int) one($db, "SELECT count(*) n FROM reply_reviews")["n"] === 1, "Reply snapshot loaded");
    $d = ReviewFactory\saveReply($db, $user, [
        "review_id" => 17,
        "body" => "Спасибо за вопрос! Храните в закрытой упаковке.",
    ]);
    ReviewFactory\queueReply($db, $user, $d["id"], 1);
    $job = json_decode(one($db, "SELECT payload FROM reply_jobs")["payload"], true);
    mc(ReviewFactory\applyReply($shop, $prefix, $job) === "applied", "Native reply updated");
    mc(
        ReviewFactory\applyReply($shop, $prefix, $job) === "already_present",
        "Ambiguous retry does not rewrite reply",
    );
    $after = one($shop, "SELECT * FROM rf_reply_review WHERE review_id=17");
    $expected = $original;
    $expected["admin_reply"] = $job["reply_body"];
    mc($after === $expected, "Every review field except admin_reply is unchanged, including dates");
    $result = ReviewFactory\processReplies($db, $shop, $prefix);
    mc($result["applied"] === 1, "Worker recovers after native update before local commit");
    mc(
        one($db, "SELECT state FROM reply_drafts WHERE id=?", [$d["id"]])["state"] === "applied",
        "Local status records actual success",
    );
    mc(
        ReviewFactory\processReplies($db, $shop, $prefix)["applied"] === 0,
        "Finished job is not applied twice",
    );
    execute($db, "DELETE FROM settings WHERE key='replies_updated'");
    mc(
        ReviewFactory\replyTick($db, $shop, $prefix, 1)["synced"] === 1,
        "Scheduled worker refreshes list when due",
    );
    mc(
        !isset(ReviewFactory\replyTick($db, $shop, $prefix, 1)["synced"]),
        "Fresh list is not re-read every minute",
    );
    $next = ReviewFactory\saveReply($db, $user, [
        "review_id" => 17,
        "body" => "Уточнённый ответ по хранению.",
    ]);
    ReviewFactory\queueReply($db, $user, $next["id"], 1);
    execute($shop, "UPDATE rf_reply_review SET admin_reply=? WHERE review_id=17", [
        "Ответ другого сотрудника.",
    ]);
    $result = ReviewFactory\processReplies($db, $shop, $prefix);
    mc($result["conflicts"] === 1, "Concurrent native edit conflicts");
    mc(
        one($shop, "SELECT admin_reply FROM rf_reply_review WHERE review_id=17")["admin_reply"] ===
            "Ответ другого сотрудника.",
        "Another employee reply preserved",
    );
    mc(
        one($db, "SELECT shop_reply FROM reply_reviews WHERE id=17")["shop_reply"] ===
            "Ответ другого сотрудника.",
        "Actual conflicting response loaded into local snapshot",
    );
    $next = ReviewFactory\saveReply($db, $user, [
        "review_id" => 17,
        "body" => "Новый проверенный ответ магазина.",
    ]);
    ReviewFactory\queueReply($db, $user, $next["id"], 1);
    execute($shop, "UPDATE rf_reply_review SET rating=4 WHERE review_id=17");
    mc(
        ReviewFactory\processReplies($db, $shop, $prefix)["conflicts"] === 1,
        "Changed original rating conflicts",
    );
    $next = ReviewFactory\saveReply($db, $user, [
        "review_id" => 17,
        "body" => "Ответ для проверки отключения.",
    ]);
    ReviewFactory\queueReply($db, $user, $next["id"], 1);
    execute($db, "UPDATE users SET active=0 WHERE id=?", [$user["id"]]);
    mc(
        ReviewFactory\processReplies($db, $shop, $prefix)["conflicts"] === 1,
        "Disabled reply author cannot write shop",
    );
    execute($db, "UPDATE users SET active=1 WHERE id=?", [$user["id"]]);
    $next = ReviewFactory\saveReply($db, $user, [
        "review_id" => 17,
        "body" => "Ответ для проверки удаления.",
    ]);
    ReviewFactory\queueReply($db, $user, $next["id"], 1);
    execute($shop, "DELETE FROM rf_reply_review WHERE review_id=17");
    mc(
        ReviewFactory\processReplies($db, $shop, $prefix)["conflicts"] === 1,
        "Deleted original review is not recreated",
    );
    mc(
        (int) one($shop, "SELECT count(*) n FROM rf_reply_review")["n"] === 0,
        "Reply worker never inserts review rows",
    );
    $job["source_database"] = "lavkasp_lavka";
    try {
        ReviewFactory\applyReply($shop, $prefix, $job);
        throw new RuntimeException("Expected database refusal");
    } catch (ReviewFactory\Problem $e) {
        mc($e->getMessage() === "reply_database_mismatch", "TEST IDs cannot be applied in another shop");
    }
    echo json(["state" => "ok", "checks" => $checks, "php" => PHP_VERSION, "engine" => "MyISAM"]) . "\n";
} finally {
    $db = null;
    foreach (glob($dir . "/*") as $file) {
        unlink($file);
    }
    rmdir($dir);
}

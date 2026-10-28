<?php
declare(strict_types=1);
require_once dirname(__DIR__) . "/src/Shop.php";
use function ReviewFactory\one;
use function ReviewFactory\execute;
use function ReviewFactory\rows;
$env = [];
foreach (file("/test-secrets/mysql.env", FILE_IGNORE_NEW_LINES) as $line) {
    $pair = explode("=", $line, 2);
    $env[$pair[0]] = $pair[1] ?? "";
}
$shop = ReviewFactory\shopConnect([
    "DB_HOSTNAME" => "rf-mysql",
    "DB_PORT" => "3306",
    "DB_DATABASE" => "lavkasp_test",
    "DB_USERNAME" => "root",
    "DB_PASSWORD" => $env["MYSQL_ROOT_PASSWORD"],
]);
$checks = 0;
function ck(bool $ok, string $message): void
{
    global $checks;
    ++$checks;
    if (!$ok) {
        throw new RuntimeException($message);
    }
}
foreach (["review", "review_factory_meta", "review_factory_config", "product"] as $table) {
    $shop->exec("DROP TABLE IF EXISTS oc_" . $table);
}
$shop->exec(
    "CREATE TABLE oc_product(product_id INT PRIMARY KEY,status INT,model VARCHAR(64) DEFAULT 'MODEL1',sku VARCHAR(64) DEFAULT 'SKU1',ean VARCHAR(64) DEFAULT '',date_available DATE DEFAULT '2024-01-01',quantity INT DEFAULT 10,subtract INT DEFAULT 1,image VARCHAR(255) DEFAULT '') ENGINE=MyISAM",
);
$shop->exec(
    "CREATE TABLE oc_review(review_id INT PRIMARY KEY AUTO_INCREMENT,product_id INT NOT NULL,customer_id INT NOT NULL DEFAULT 0,author VARCHAR(64) NOT NULL,text TEXT NOT NULL,rating INT NOT NULL,status INT NOT NULL,date_added DATETIME NOT NULL,date_modified DATETIME NOT NULL) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4",
);
$shop->exec(
    "CREATE TABLE oc_review_factory_meta(review_id INT UNSIGNED PRIMARY KEY,request_id CHAR(32) UNIQUE,payload_hash CHAR(64),origin VARCHAR(16),author_user_id INT,author_role VARCHAR(64),experience_date VARCHAR(10),city VARCHAR(80),sentiment VARCHAR(16),depth VARCHAR(16),dispatch_state VARCHAR(16),created_at DATETIME) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
);
$shop->exec(
    "CREATE TABLE oc_review_factory_config(`key` VARCHAR(64) PRIMARY KEY,value VARCHAR(255));INSERT INTO oc_review_factory_config VALUES('rating_filter_verified','1');INSERT INTO oc_product(product_id,status) VALUES(1,1)",
);
$d = [
    "id" => str_repeat("a", 32),
    "product_id" => 1,
    "author_id" => 2,
    "author_label" => "Автор · сотрудник Лавки",
    "staff_role" => "сотрудник",
    "body" => "Это мой опыт использования настоящей корицы в выпечке.",
    "rating" => 5,
    "sentiment" => "positive",
    "experience_date" => "2024-01-01",
    "city" => "Одесса",
];
try {
    ReviewFactory\sendToShop($shop, "oc_", $d, function () {
        throw new RuntimeException("simulated_crash");
    });
    throw new RuntimeException("Expected crash");
} catch (RuntimeException $e) {
    if ($e->getMessage() !== "simulated_crash") {
        throw $e;
    }
}
ck((int) one($shop, "SELECT count(*) n FROM oc_review")["n"] === 0, "No review inserted before crash");
ck(
    one($shop, "SELECT dispatch_state FROM oc_review_factory_meta")["dispatch_state"] === "prepared",
    "Durable reservation survived crash",
);
$id = ReviewFactory\sendToShop($shop, "oc_", $d);
ck($id === 1, "Reserved ID reused");
ck(ReviewFactory\sendToShop($shop, "oc_", $d) === $id, "Retry returns same review");
ck((int) one($shop, "SELECT count(*) n FROM oc_review")["n"] === 1, "No duplicate");
$review = one($shop, "SELECT * FROM oc_review WHERE review_id=1");
ck((int) $review["status"] === 0, "Never auto-activates");
ck($review["author"] === $d["author_label"], "Real staff identity");
ck(strpos($review["text"], "2024-01-01") !== false, "Experience date separate");
ck(substr($review["date_added"], 0, 10) === gmdate("Y-m-d"), "No backdating publication");
$changed = $d;
$changed["rating"] = 1;
try {
    ReviewFactory\sendToShop($shop, "oc_", $changed);
    throw new RuntimeException("Expected conflict");
} catch (ReviewFactory\Problem $e) {
    ck($e->getMessage() === "dispatch_conflict", "Payload changes rejected");
}
execute(
    $shop,
    "INSERT INTO oc_review(product_id,author,text,rating,status,date_added,date_modified) VALUES(1,'Покупатель','Отзыв покупателя',3,1,NOW(),NOW())",
);
execute($shop, "UPDATE oc_review SET status=1 WHERE review_id=1");
$customer = one(
    $shop,
    "SELECT AVG(rating) rating,COUNT(*) n FROM oc_review r1 WHERE r1.product_id=1 AND r1.status=1 AND NOT EXISTS(SELECT 1 FROM oc_review_factory_meta rf WHERE rf.review_id=r1.review_id AND rf.origin='staff')",
);
ck(
    (float) $customer["rating"] === 3.0 && (int) $customer["n"] === 1,
    "Published staff stars excluded from customer average and count",
);
$all = one($shop, "SELECT count(*) n FROM oc_review WHERE status=1");
ck((int) $all["n"] === 2, "Both remain in native review list");
$shop->exec("ALTER TABLE oc_review ADD COLUMN admin_reply TEXT NULL");
$shop->exec(
    "DROP TABLE IF EXISTS oc_uni_review_reply;CREATE TABLE oc_uni_review_reply(review_id INT,reply_text TEXT)",
);
$beforeSchema = rows(
    $shop,
    "SELECT column_name FROM information_schema.COLUMNS WHERE table_schema=DATABASE() AND table_name='oc_review' ORDER BY ordinal_position",
);
$schema = ReviewFactory\reviewReplySchema($shop, "oc_");
ck(
    in_array("admin_reply", array_column($schema["columns"], "column_name"), true),
    "Native reply column discovered",
);
ck(
    in_array("oc_uni_review_reply", array_column($schema["columns"], "table_name"), true),
    "Separate reply table discovered",
);
ck(
    !in_array("oc_review_factory_meta", array_column($schema["columns"], "table_name"), true),
    "Own metadata omitted from reply diagnostics",
);
ck(
    strpos(ReviewFactory\json($schema), $d["body"]) === false,
    "Reply diagnostics contain no review text",
);
ck(
    (int) one($shop, "SELECT count(*) n FROM oc_review")["n"] === 2,
    "Reply diagnostics preserve reviews",
);
ck(
    $beforeSchema ===
        rows(
            $shop,
            "SELECT column_name FROM information_schema.COLUMNS WHERE table_schema=DATABASE() AND table_name='oc_review' ORDER BY ordinal_position",
        ),
    "Reply diagnostics do not alter schema",
);
foreach (
    [
        "product_description",
        "product_to_store",
        "category_description",
        "product_to_category",
        "order",
        "order_product",
    ]
    as $table
) {
    $shop->exec("DROP TABLE IF EXISTS `oc_" . $table . "`");
}
$shop->exec(
    "CREATE TABLE oc_product_description(product_id INT,language_id INT,name VARCHAR(255),description TEXT);INSERT INTO oc_product_description VALUES(1,1,'Специя','Описание');CREATE TABLE oc_product_to_store(product_id INT,store_id INT);INSERT INTO oc_product_to_store VALUES(1,0);CREATE TABLE oc_category_description(category_id INT,language_id INT,name VARCHAR(255));CREATE TABLE oc_product_to_category(product_id INT,category_id INT);CREATE TABLE `oc_order`(order_id INT,store_id INT,order_status_id INT,date_added DATETIME);INSERT INTO `oc_order` VALUES(1,0,5,NOW()),(2,0,7,NOW());CREATE TABLE oc_order_product(order_id INT,product_id INT,quantity INT);INSERT INTO oc_order_product VALUES(1,1,3),(2,1,999)",
);
$directory = sys_get_temp_dir() . "/rf-shop-" . bin2hex(random_bytes(5));
mkdir($directory, 0700);
$local = ReviewFactory\database($directory);
try {
    ReviewFactory\importShop($local, $shop, "oc_", 1, [5]);
    $imported = one($local, "SELECT * FROM products WHERE id=1");
    ck(
        (int) $imported["customer_count"] === 1 && (float) $imported["customer_rating"] === 3.0,
        "Imported counts exclude staff",
    );
    ck((float) $imported["sales90"] === 3.0, "Sales exclude unselected cancelled status");
    ck($imported["views30"] === null, "No fabricated monthly page views");
    ck(
        (int) one($shop, "SELECT count(*) n FROM oc_review")["n"] === 2,
        "Import never changes shop reviews",
    );
} finally {
    $local = null;
    foreach (glob($directory . "/*") as $file) {
        unlink($file);
    }
    rmdir($directory);
}
execute($shop, "DELETE FROM oc_review WHERE review_id=1");
try {
    ReviewFactory\sendToShop($shop, "oc_", $d);
    throw new RuntimeException("Expected removed");
} catch (ReviewFactory\Problem $e) {
    ck($e->getMessage() === "review_removed", "Deleted review never recreated automatically");
}
$d["id"] = str_repeat("b", 32);
try {
    ReviewFactory\sendToShop($shop, "oc_", $d, function () {
        throw new RuntimeException("simulated_crash");
    });
} catch (RuntimeException $e) {
}
$reserved = (int) one($shop, "SELECT review_id FROM oc_review_factory_meta WHERE request_id=?", [
    $d["id"],
])["review_id"];
execute(
    $shop,
    "INSERT INTO oc_review(review_id,product_id,author,text,rating,status,date_added,date_modified) VALUES(?,1,'Другой','Не наша запись',2,0,NOW(),NOW())",
    [$reserved],
);
try {
    ReviewFactory\sendToShop($shop, "oc_", $d);
    throw new RuntimeException("Expected collision");
} catch (ReviewFactory\Problem $e) {
    ck(
        $e->getMessage() === "dispatch_conflict",
        "Reservation collision never overwrites customer review",
    );
}
echo ReviewFactory\json([
    "state" => "ok",
    "checks" => $checks,
    "engine" => "native MyISAM + metadata InnoDB",
]) . "\n";

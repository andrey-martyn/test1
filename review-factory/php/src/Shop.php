<?php
declare(strict_types=1);
namespace ReviewFactory;
require_once __DIR__ . "/Core.php";

/** Parse literals only: never execute a store's config.php or print its contents. */
function shopConfig(string $root, string $environment = "test"): array
{
    $root = realpath($root) ?: "";
    $file = $root . "/config.php";
    if (!$root || !is_file($file) || is_link($file)) {
        throw new Problem("shop_config_missing");
    }
    $source = file_get_contents($file);
    $c = [];
    foreach (
        ["DB_HOSTNAME", "DB_USERNAME", "DB_PASSWORD", "DB_DATABASE", "DB_PORT", "DB_PREFIX"]
        as $key
    ) {
        $pattern =
            "~define\s*\(\s*['\"]" .
            $key .
            "['\"]\s*,\s*('(?:\\\\.|[^'\\\\])*'|\"(?:\\\\.|[^\"\\\\])*\"|[0-9]+)\s*\)\s*;~";
        if (!preg_match($pattern, $source, $m)) {
            throw new Problem("shop_config_unsupported");
        }
        $v = $m[1];
        if ($v[0] === "'") {
            $v = str_replace(["\\'", "\\\\"], ["'", "\\"], substr($v, 1, -1));
        } elseif ($v[0] === '"') {
            if (strpos($v, '$') !== false) {
                throw new Problem("shop_config_unsupported");
            }
            $v = stripcslashes(substr($v, 1, -1));
        }
        $c[$key] = $v;
    }
    // Existing account's verified local connection, without changing config.php.
    if ($c["DB_HOSTNAME"] === "lavkasp.mysql.tools") {
        $c["DB_HOSTNAME"] = "127.0.0.1";
    }
    $expected = ["test" => "lavkasp_test", "production" => "lavkasp_lavka"];
    if (
        !isset($expected[$environment]) ||
        $c["DB_DATABASE"] !== $expected[$environment] ||
        !in_array($c["DB_HOSTNAME"], ["localhost", "127.0.0.1"], true) ||
        !preg_match('/^[a-zA-Z0-9_]{1,24}$/D', $c["DB_PREFIX"]) ||
        !ctype_digit($c["DB_PORT"])
    ) {
        throw new Problem("environment_mismatch");
    }
    return $c;
}
function shopConnect(array $c): \PDO
{
    if (PHP_VERSION_ID < 80200) {
        throw new Problem("Нужен PHP 8.2 CLI");
    }
    ini_set("mysqlnd.net_read_timeout", "30");
    $db = new \PDO(
        "mysql:host=" .
            $c["DB_HOSTNAME"] .
            ";port=" .
            $c["DB_PORT"] .
            ";dbname=" .
            $c["DB_DATABASE"] .
            ";charset=utf8mb4",
        $c["DB_USERNAME"],
        $c["DB_PASSWORD"],
        [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
            \PDO::ATTR_TIMEOUT => 10,
        ],
    );
    $db->exec("SET SESSION lock_wait_timeout=5;SET SESSION innodb_lock_wait_timeout=5");
    return $db;
}
function shopLanguage(\PDO $shop, string $prefix): int
{
    $setting = one(
        $shop,
        "SELECT value FROM `{$prefix}setting` WHERE store_id=0 AND `key`='config_language'",
    );
    $language = $setting
        ? one($shop, "SELECT language_id FROM `{$prefix}language` WHERE code=? AND status=1", [
            $setting["value"],
        ])
        : null;
    if (!$language) {
        throw new Problem("shop_language_unknown");
    }
    return (int) $language["language_id"];
}
function salesStatuses(\PDO $shop, string $prefix, int $language, string $analyticsDirectory): array
{
    $available = rows(
        $shop,
        "SELECT order_status_id,name FROM `{$prefix}order_status` WHERE language_id=?",
        [$language],
    );
    $ids = [];
    $names = [];
    foreach ($available as $status) {
        $names[(int) $status["order_status_id"]] = $status["name"];
    }
    $file = rtrim($analyticsDirectory, "/") . "/analytics.sqlite3";
    if (is_file($file) && !is_link($file)) {
        $source = new \SQLite3($file, SQLITE3_OPEN_READONLY);
        $source->enableExceptions(true);
        try {
            $raw = $source->querySingle("SELECT value FROM settings WHERE key='report'");
            $report = $raw ? json_decode($raw, true) : [];
            foreach ($report["status_ids"] ?? [] as $id) {
                $ids[] = (int) $id;
            }
        } finally {
            $source->close();
        }
    }
    if (!$ids) {
        foreach ($names as $id => $name) {
            if (
                $id !== 0 &&
                !preg_match(
                    "/отмен|скасов|анулир|аннул|возврат|повернен|возмещ|відшкод|неуда|невдал|истек|cancel|refund|fail|revers|void|denied/iu",
                    $name,
                )
            ) {
                $ids[] = $id;
            }
        };
    }
    foreach ($ids as $id) {
        if (!isset($names[$id])) {
            throw new Problem("sales_status_mapping_required");
        }
    }
    if (!$ids) {
        throw new Problem("sales_statuses_empty");
    }
    return array_values(array_unique($ids));
}
function bridgeReady(\PDO $shop, string $prefix): bool
{
    try {
        $r = one(
            $shop,
            "SELECT value FROM `{$prefix}review_factory_config` WHERE `key`='rating_filter_verified'",
        );
        if ($r && $r["value"] === "1") {
            return true;
        }
        $test = one(
            $shop,
            "SELECT value FROM `{$prefix}review_factory_config` WHERE `key`='test_dispatch_ready'",
        );
        return $test &&
            $test["value"] === "1" &&
            $shop->query("SELECT DATABASE()")->fetchColumn() === "lavkasp_test";
    } catch (\PDOException $e) {
        return false;
    }
}
function shopRatingFilter(string $root): bool
{
    $config = file_get_contents($root . "/config.php");
    if (
        !preg_match(
            "~define\\s*\\(\\s*['\"]DIR_STORAGE['\"]\\s*,\\s*'((?:\\\\.|[^'\\\\])*)'\\s*\\)~",
            $config,
            $m,
        )
    ) {
        return false;
    }
    $storage = str_replace(["\\'", "\\\\"], ["'", "\\"], $m[1]);
    $file = rtrim($storage, "/") . "/modification/catalog/model/catalog/product.php";
    if (!is_file($file)) {
        return false;
    }
    $source = file_get_contents($file);
    foreach (["r1", "r2"] as $alias) {
        $needle = $alias . ".product_id = p.product_id AND " . $alias . ".status = '1'";
        if (
            substr_count($source, $needle) === 0 ||
            substr_count($source, $needle) !== substr_count($source, $needle . " AND NOT EXISTS")
        ) {
            return false;
        }
    }
    return true;
}
/** The extension creates metadata; this command performs no schema changes. */
function importShop(
    \PDO $db,
    \PDO $shop,
    string $prefix,
    int $language,
    array $statuses,
    string $shopUrl = "https://test.lavka-speciy.com"
): array {
    if (!$statuses) {
        throw new Problem("Выберите учитываемые статусы заказов");
    }
    foreach ($statuses as $status) {
        integer($status, 1, 9999);
    }
    integer($language, 1, 9999);
    $ids = implode(",", array_map("intval", $statuses));
    $meta = false;
    try {
        $shop->query("SELECT review_id FROM `{$prefix}review_factory_meta` LIMIT 0");
        $meta = true;
    } catch (\PDOException $e) {
    }
    $products = rows(
        $shop,
        "SELECT p.product_id id,p.model,p.sku,p.ean,CASE WHEN p.status=1 AND p.date_available<=CURRENT_DATE THEN 1 ELSE 0 END active,CASE WHEN p.subtract=0 THEN NULL ELSE p.quantity END stock,p.image,pd.name,pd.description,COALESCE((SELECT cd.name FROM `{$prefix}category_description` cd JOIN `{$prefix}product_to_category` pc ON pc.category_id=cd.category_id WHERE pc.product_id=p.product_id AND cd.language_id=" .
            $language .
            " ORDER BY pc.category_id LIMIT 1),'') category FROM `{$prefix}product` p JOIN `{$prefix}product_description` pd ON pd.product_id=p.product_id AND pd.language_id=" .
            $language .
            " JOIN `{$prefix}product_to_store` ps ON ps.product_id=p.product_id AND ps.store_id=0 ORDER BY p.product_id LIMIT 10001",
    );
    if (count($products) > 10000) {
        throw new Problem("catalog_limit");
    }
    $join = $meta ? " LEFT JOIN `{$prefix}review_factory_meta` m ON m.review_id=r.review_id " : "";
    $origin = $meta ? "COALESCE(m.origin,'customer')" : "'customer'";
    $reviews = rows(
        $shop,
        "SELECT r.review_id id,r.product_id,r.author,r.text,r.rating,r.date_added published_at,{$origin} origin FROM `{$prefix}review` r{$join} WHERE r.status=1 AND r.product_id IN (SELECT product_id FROM `{$prefix}product_to_store` WHERE store_id=0) ORDER BY r.review_id LIMIT 100001",
    );
    if (count($reviews) > 100000) {
        throw new Problem("reviews_limit");
    }
    // Only aggregate sales are read; customer records and contacts are not requested.
    $sales = rows(
        $shop,
        "SELECT op.product_id,SUM(op.quantity) units FROM `{$prefix}order_product` op JOIN `{$prefix}order` o ON o.order_id=op.order_id WHERE o.store_id=0 AND o.order_status_id IN ({$ids}) AND o.date_added>=DATE_SUB(NOW(),INTERVAL 90 DAY) GROUP BY op.product_id",
    );
    $bySales = [];
    foreach ($sales as $s) {
        $bySales[$s["product_id"]] = (float) $s["units"];
    }
    $customer = [];
    foreach ($reviews as $r) {
        if ($r["origin"] === "customer") {
            $id = $r["product_id"];
            $customer[$id]["n"] = ($customer[$id]["n"] ?? 0) + 1;
            $customer[$id]["sum"] = ($customer[$id]["sum"] ?? 0) + (int) $r["rating"];
        }
    }
    $ready = bridgeReady($shop, $prefix);
    transaction($db, function () use ($db, $products, $reviews, $bySales, $customer, $shopUrl, $ready) {
        execute($db, "UPDATE products SET active=0");
        foreach ($products as $p) {
            $id = (int) $p["id"];
            $n = $customer[$id]["n"] ?? 0;
            $image = ltrim((string) $p["image"], "/");
            if (strpos($image, "..") !== false || preg_match('/[\x00-\x20?#]/', $image)) {
                $image = "";
            }
            execute(
                $db,
                "INSERT INTO products(id,model,sku,ean,name,description,category,image,url,active,stock,customer_count,customer_rating,sales90,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON CONFLICT(id) DO UPDATE SET model=excluded.model,sku=excluded.sku,ean=excluded.ean,name=excluded.name,description=excluded.description,category=excluded.category,image=excluded.image,url=excluded.url,active=excluded.active,stock=excluded.stock,customer_count=excluded.customer_count,customer_rating=excluded.customer_rating,sales90=excluded.sales90,updated_at=excluded.updated_at",
                [
                    $id,
                    $p["model"],
                    $p["sku"],
                    $p["ean"],
                    mb_substr(html_entity_decode($p["name"], ENT_QUOTES | ENT_HTML5, "UTF-8"), 0, 300),
                    mb_substr(
                        trim(
                            strip_tags(
                                html_entity_decode($p["description"], ENT_QUOTES | ENT_HTML5, "UTF-8"),
                            ),
                        ),
                        0,
                        12000,
                    ),
                    $p["category"],
                    $image ? $shopUrl . "/image/" . $image : "",
                    $shopUrl . "/index.php?route=product/product&product_id=" . $id,
                    (int) $p["active"],
                    $p["stock"] === null ? null : (int) $p["stock"],
                    $n,
                    $n ? $customer[$id]["sum"] / $n : null,
                    $bySales[$id] ?? 0,
                    now(),
                ],
            );
        }
        $db->exec("DELETE FROM shop_reviews");
        foreach ($reviews as $r) {
            if (one($db, "SELECT id FROM products WHERE id=?", [$r["product_id"]])) {
                execute($db, "INSERT INTO shop_reviews VALUES(?,?,?,?,?,?,?)", [
                    $r["id"],
                    $r["product_id"],
                    $r["origin"],
                    mb_substr($r["author"], 0, 100),
                    mb_substr($r["text"], 0, 10000),
                    $r["rating"],
                    $r["published_at"],
                ]);
            }
        }
        execute($db, "INSERT OR REPLACE INTO settings VALUES('shop_updated',?)", [now()]);
        execute($db, "INSERT OR REPLACE INTO settings VALUES('bridge_ready',?)", [$ready ? "1" : "0"]);
    });
    return ["products" => count($products), "reviews" => count($reviews)];
}
function outgoingPayload(array $d): array
{
    $text = $d["body"];
    if ($d["city"] !== "") {
        $text .= "\n\nГород автора: " . $d["city"];
    }
    if ($d["experience_date"] !== "") {
        $text .= "\nОпыт использования: " . $d["experience_date"];
    }
    return [
        "product_id" => (int) $d["product_id"],
        "author" => $d["author_label"],
        "text" => $text,
        "rating" => (int) $d["rating"],
    ];
}
/** Explicit reservation makes retries safe even when native oc_review is MyISAM. */
function sendToShop(\PDO $shop, string $prefix, array $d, ?callable $afterReserve = null): int
{
    if (!bridgeReady($shop, $prefix)) {
        throw new Problem("extension_not_verified");
    }
    $payload = outgoingPayload($d);
    $hash = hash("sha256", json($payload));
    $review = "`{$prefix}review`";
    $meta = "`{$prefix}review_factory_meta`";
    $product = "`{$prefix}product`";
    $shop->exec("LOCK TABLES {$review} WRITE, {$meta} WRITE, {$product} READ");
    try {
        if (
            !one($shop, "SELECT product_id FROM {$product} WHERE product_id=? AND status=1", [
                $payload["product_id"],
            ])
        ) {
            throw new Problem("product_disabled");
        }
        $m = one($shop, "SELECT * FROM {$meta} WHERE request_id=?", [$d["id"]]);
        if ($m) {
            if (!hash_equals($m["payload_hash"], $hash)) {
                throw new Problem("dispatch_conflict");
            }
            $id = (int) $m["review_id"];
        } else {
            $r = one($shop, "SELECT COALESCE(MAX(review_id),0) n FROM {$review}");
            $mMax = one($shop, "SELECT COALESCE(MAX(review_id),0) n FROM {$meta}");
            $id = max((int) $r["n"], (int) $mMax["n"]) + 1;
            execute(
                $shop,
                "INSERT INTO {$meta}(review_id,request_id,payload_hash,origin,author_user_id,author_role,experience_date,city,sentiment,depth,dispatch_state,created_at) VALUES(?,?,?,'staff',?,?,?,?,?,?,'prepared',NOW())",
                [
                    $id,
                    $d["id"],
                    $hash,
                    $d["author_id"],
                    $d["staff_role"],
                    $d["experience_date"],
                    $d["city"],
                    $d["sentiment"],
                    depth($d["body"]),
                ],
            );
            if ($afterReserve) {
                $afterReserve();
            }
            $m = ["dispatch_state" => "prepared"];
        }
        $existing = one($shop, "SELECT product_id,author,text,rating FROM {$review} WHERE review_id=?", [
            $id,
        ]);
        if ($existing) {
            $actual = [
                "product_id" => (int) $existing["product_id"],
                "author" => $existing["author"],
                "text" => $existing["text"],
                "rating" => (int) $existing["rating"],
            ];
            if (!hash_equals($hash, hash("sha256", json($actual)))) {
                throw new Problem("dispatch_conflict");
            }
        } elseif ($m["dispatch_state"] === "written") {
            throw new Problem("review_removed");
        } else {
            execute(
                $shop,
                "INSERT INTO {$review}(review_id,product_id,customer_id,author,text,rating,status,date_added,date_modified) VALUES(?,?,0,?,?,?,0,NOW(),NOW())",
                [$id, $payload["product_id"], $payload["author"], $payload["text"], $payload["rating"]],
            );
        }
        execute($shop, "UPDATE {$meta} SET dispatch_state='written' WHERE review_id=?", [$id]);
        return $id;
    } finally {
        $shop->exec("UNLOCK TABLES");
    }
}
function dispatch(\PDO $db, \PDO $shop, string $prefix, int $limit = 5, ?string $shopRoot = null): array
{
    if (!$shopRoot || !shopRatingFilter($shopRoot)) {
        throw new Problem("rating_filter_missing");
    }
    $summary = ["sent" => 0, "failed" => 0];
    $jobs = rows(
        $db,
        "SELECT o.id job_id,d.* FROM outbox o JOIN drafts d ON d.id=o.draft_id WHERE o.state='pending' ORDER BY o.id LIMIT " .
            max(1, min(20, $limit)),
    );
    foreach ($jobs as $d) {
        try {
            $id = sendToShop($shop, $prefix, $d);
            transaction($db, function () use ($db, $d, $id) {
                execute($db, "UPDATE outbox SET state='done',last_error=NULL,updated_at=? WHERE id=?", [
                    now(),
                    $d["job_id"],
                ]);
                execute(
                    $db,
                    "UPDATE drafts SET state='moderation',review_id=?,updated_at=? WHERE id=?",
                    [$id, now(), $d["id"]],
                );
                audit($db, null, "review.delivered", $d["id"], ["review_id" => $id]);
            });
            ++$summary["sent"];
        } catch (\Throwable $e) {
            $code = $e instanceof Problem ? $e->getMessage() : "shop_unavailable";
            execute($db, "UPDATE outbox SET attempts=attempts+1,last_error=?,updated_at=? WHERE id=?", [
                $code,
                now(),
                $d["job_id"],
            ]);
            ++$summary["failed"];
        }
    }
    execute($db, "INSERT OR REPLACE INTO settings VALUES('bridge_ready',?)", [
        bridgeReady($shop, $prefix) ? "1" : "0",
    ]);
    foreach (
        rows(
            $db,
            "SELECT id,review_id,state FROM drafts WHERE state IN ('moderation','published') AND review_id IS NOT NULL",
        )
        as $d
    ) {
        $r = one($shop, "SELECT status FROM `{$prefix}review` WHERE review_id=?", [$d["review_id"]]);
        $new = $r ? ((int) $r["status"] === 1 ? "published" : "moderation") : "rejected";
        if ($new !== $d["state"]) {
            execute($db, "UPDATE drafts SET state=?,updated_at=? WHERE id=?", [$new, now(), $d["id"]]);
            audit($db, null, "review.status_synced", $d["id"], [
                "state" => $new,
                "review_id" => (int) $d["review_id"],
            ]);
        }
    }
    return $summary;
}

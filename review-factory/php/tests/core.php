<?php
declare(strict_types=1);
require_once dirname(__DIR__) . "/src/Core.php";
require_once dirname(__DIR__) . "/src/Competitors.php";
require_once dirname(__DIR__) . "/src/Migration.php";
require_once dirname(__DIR__) . "/src/App.php";
use function ReviewFactory\database;
use function ReviewFactory\execute;
use function ReviewFactory\one;
use function ReviewFactory\rows;
use function ReviewFactory\now;
$checks = 0;
function check($condition, string $message): void
{
    global $checks;
    ++$checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function refuses(callable $fn, int $status = 422): void
{
    try {
        $fn();
    } catch (ReviewFactory\Problem $e) {
        check($e->status === $status, "Expected HTTP " . $status . ", got " . $e->status);
        return;
    }
    throw new RuntimeException("Unexpectedly accepted operation");
}
$dir = sys_get_temp_dir() . "/rf-" . bin2hex(random_bytes(8));
mkdir($dir, 0700);
$db = database($dir);
try {
    $admin = ReviewFactory\createUser($db, [
        "login" => "owner",
        "name" => "Владелец",
        "staff_role" => "хозяин",
        "role" => "admin",
        "password" => "Synthetic-only-password",
    ]);
    $a = ReviewFactory\createUser($db, [
        "login" => "author-a",
        "name" => "Автор А",
        "staff_role" => "сотрудник",
        "role" => "author",
        "password" => "Synthetic-only-password",
    ]);
    $b = ReviewFactory\createUser($db, [
        "login" => "author-b",
        "name" => "Автор Б",
        "staff_role" => "сотрудник",
        "role" => "author",
        "password" => "Synthetic-only-password",
    ]);
    check(!isset($a["hash"]), "Password hash does not leave user response");
    refuses(function () use ($db) {
        ReviewFactory\createUser($db, [
            "login" => "author-a",
            "name" => "А",
            "staff_role" => "сотрудник",
            "role" => "admin",
            "password" => "Synthetic-only-password",
        ]);
    }, 409);
    $auth = ReviewFactory\login($db, "author-a", "Synthetic-only-password", "127.0.0.1");
    $session = ReviewFactory\session($db, $auth["token"]);
    check($session["id"] === $a["id"], "Login identifies person");
    check(strlen($session["csrf"]) === 64, "Separate CSRF token");
    check(one($db, "SELECT token FROM sessions")["token"] !== $auth["token"], "Session stored hashed");
    execute($db, "UPDATE users SET revision=revision+1 WHERE id=?", [$a["id"]]);
    check(
        ReviewFactory\session($db, $auth["token"]) === null,
        "Password/access revision revokes sessions",
    );
    for ($i = 1; $i <= 12; $i++) {
        execute(
            $db,
            "INSERT INTO products(id,model,name,url,active,stock,customer_count,customer_rating,sales90,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?)",
            [
                $i,
                "MODEL-" . $i,
                "Тестовая специя " . $i,
                "https://test.lavka-speciy.com/index.php?route=product/product&product_id=" . $i,
                1,
                10,
                0,
                null,
                13 - $i,
                now(),
            ],
        );
    }
    execute(
        $db,
        "INSERT INTO competitors(product_id,source_id,url,confirmed,review_count,last_success,next_check) VALUES(?,?,?,?,?,?,?)",
        [1, "deluxe", "https://deluxe.com.ua/fixture.html", 1, 72, now(), now()],
    );
    execute(
        $db,
        "INSERT INTO competitors(product_id,source_id,url,confirmed,review_count,last_success,next_check) VALUES(?,?,?,?,?,?,?)",
        [1, "banka", "https://banka-speciy.in.ua/fixture", 1, 48, now(), now()],
    );
    ReviewFactory\rebuildQueue($db);
    $q = one($db, "SELECT * FROM queue WHERE product_id=1");
    check((int) $q["target"] === 8, "Target ceil sqrt of average top two");
    check(ReviewFactory\competitorReference([72, 48, 19, 8]) === 60.0, "Reference top two");
    check(ReviewFactory\competitorReference([null, null]) === null, "Missing not zero");
    check(ReviewFactory\competitorReference([0, null]) === 0.0, "Confirmed zero retained");
    check(ReviewFactory\normalize([0.0, 1.0], null) === null, "Unknown factor omitted");
    $idsA = ReviewFactory\claim($db, $a, 5);
    $idsB = ReviewFactory\claim($db, $b, 5);
    check(count($idsA) === 5 && count($idsB) === 5, "Batch claim five");
    check(!array_intersect($idsA, $idsB), "Assignments exclusive");
    check(ReviewFactory\claim($db, $a, 1) === $idsA, "Existing assignments retained");
    $id = $idsA[0];
    refuses(function () use ($db, $b, $id) {
        ReviewFactory\product($db, $id, $b);
    }, 403);
    refuses(function () use ($db, $a) {
        ReviewFactory\api($db, "users", "GET", [], $a);
    }, 403);
    $body = [
        "product_id" => $id,
        "rating" => 4,
        "body" => "Я добавляю эту специю в выпечку и замечаю насыщенный аромат.",
        "sentiment" => "positive",
        "city" => "Одесса",
        "experience_date" => "2024-03-01",
    ];
    $draft = ReviewFactory\saveDraft($db, $a, $body);
    check($draft["state"] === "draft", "Draft saved");
    refuses(function () use ($db, $a, $body) {
        ReviewFactory\saveDraft($db, $a, $body + ["revision" => 0]);
    }, 409);
    refuses(function () use ($db, $b, $body) {
        ReviewFactory\saveDraft($db, $b, $body);
    }, 403);
    refuses(function () use ($db, $a, $draft) {
        ReviewFactory\submit($db, $a, $draft["id"], 999);
    }, 409);
    $submitted = ReviewFactory\submit($db, $a, $draft["id"], (int) $draft["revision"]);
    check($submitted["state"] === "submitted", "Submission queued");
    check($submitted["author_label"] === "Автор А · сотрудник Лавки", "Real author frozen");
    check(
        ReviewFactory\submit($db, $a, $draft["id"], (int) $draft["revision"])["id"] === $draft["id"],
        "Retry submit idempotent",
    );
    check((int) one($db, "SELECT count(*) n FROM outbox")["n"] === 1, "One outbox item");
    check(
        !one($db, "SELECT product_id FROM assignments WHERE product_id=?", [$id]),
        "Next product available after submit",
    );
    refuses(function () use ($db, $b, $draft) {
        ReviewFactory\submit($db, $b, $draft["id"], (int) $draft["revision"]);
    }, 404);
    $draftB = ReviewFactory\saveDraft($db, $b, [
        "product_id" => $idsB[0],
        "body" => "Это другой настоящий опыт применения специи.",
        "rating" => 5,
    ]);
    check(ReviewFactory\wordCount("Я пью иван-чай каждый день.") === 5, "Unicode and hyphen word count");
    $fixture = function (array $data): string {
        return '<!doctype html><html><body><main><h1>Специя</h1><script type="application/ld+json">' .
            ReviewFactory\json($data) .
            "</script></main></body></html>";
    };
    $url = "https://deluxe.com.ua/fixture.html";
    $parsed = ReviewFactory\parseReviews(
        $fixture([
            "@type" => "Product",
            "url" => $url,
            "aggregateRating" => ["@type" => "AggregateRating", "reviewCount" => 0],
        ]),
        $url,
    );
    check(
        $parsed["review_count"] === 0 && $parsed["average_rating"] === null,
        "Explicit zero without invented rating",
    );
    $parsed = ReviewFactory\parseReviews(
        $fixture([
            "@type" => "Product",
            "url" => $url,
            "aggregateRating" => ["reviewCount" => 10, "ratingValue" => 4.5],
            "review" => [["reviewBody" => "Приятный аромат и хороший вкус."]],
        ]),
        $url,
    );
    check(
        $parsed["review_count"] === 10 &&
            $parsed["average_rating"] === 4.5 &&
            count($parsed["samples"]) === 1,
        "Structured values and anonymous sample",
    );
    $parsed = ReviewFactory\parseReviews(
        $fixture([
            "@type" => "Product",
            "url" => $url,
            "aggregateRating" => ["ratingCount" => 20, "ratingValue" => 4.5],
        ]),
        $url,
    );
    check($parsed["review_count"] === null, "ratingCount is not reviewCount");
    refuses(function () use ($fixture, $url) {
        ReviewFactory\parseReviews(
            $fixture([
                "@graph" => [
                    [
                        "@type" => "Product",
                        "url" => "https://deluxe.com.ua/other",
                        "aggregateRating" => ["reviewCount" => 99],
                    ],
                    ["@type" => "Product", "url" => $url],
                ],
            ]),
            $url,
        );
    });
    $source = rows($db, "SELECT id,model,sku,ean FROM products");
    $target = $source;
    foreach ($target as &$p) {
        $p["id"] += 100;
    }
    unset($p);
    $plan = ReviewFactory\migrationMapping($source, $target);
    check(
        !$plan["problems"] && $plan["mapping"][1] === 101,
        "Migration maps identity across changed IDs",
    );
    $bad = $target;
    $bad[0]["model"] = $bad[1]["model"];
    check(
        count(ReviewFactory\migrationMapping($source, $bad)["problems"]) > 0,
        "Ambiguous identities block migration",
    );
    $copyDir = $dir . "-production";
    $result = ReviewFactory\migrateCopy($db, $copyDir, $plan["mapping"]);
    $copy = database($copyDir);
    check((int) one($copy, "SELECT count(*) n FROM users")["n"] === 3, "Accounts migrate");
    check((int) one($copy, "SELECT count(*) n FROM sessions")["n"] === 0, "No migrated sessions");
    check((int) one($copy, "SELECT count(*) n FROM outbox")["n"] === 0, "No test auto-publication jobs");
    $migrated = one($copy, "SELECT state,product_id FROM drafts WHERE id=?", [$draft["id"]]);
    check(
        $migrated["state"] === "historical" && (int) $migrated["product_id"] === $id + 100,
        "Submitted TEST text archived with remapped product",
    );
    check(
        one($copy, "SELECT state FROM drafts WHERE id=?", [$draftB["id"]])["state"] === "draft",
        "Unsent draft stays editable",
    );
    check((int) one($copy, "SELECT count(*) n FROM assignments")["n"] === 9, "Assignments preserved");
    check((int) one($db, "SELECT count(*) n FROM outbox")["n"] === 1, "TEST remains unchanged");
    check(!rows($copy, "PRAGMA foreign_key_check"), "Migrated references valid");
    $copy = null;
    echo ReviewFactory\json(["checks" => $checks, "php" => PHP_VERSION, "state" => "ok"]) . "\n";
} finally {
    $db = null;
    foreach ([$dir, $dir . "-production"] as $folder) {
        if (is_dir($folder)) {
            foreach (glob($folder . "/*") as $file) {
                unlink($file);
            }
            rmdir($folder);
        }
    }
}

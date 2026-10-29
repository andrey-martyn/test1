<?php
declare(strict_types=1);
require_once dirname(__DIR__) . "/src/Competitors.php";
require_once dirname(__DIR__) . "/src/Simulation.php";
use function ReviewFactory\{
    database,
    execute,
    one,
    rows,
    now,
    json,
    importMatches,
    simulationSources,
    collectReviews,
};
$checks = 0;
function mc(bool $ok, string $message): void
{
    global $checks;
    ++$checks;
    if (!$ok) {
        throw new RuntimeException($message);
    }
}
$dir = sys_get_temp_dir() . "/rf-monitoring-" . bin2hex(random_bytes(6));
mkdir($dir, 0700);
mkdir($dir . "/analytics", 0700);
mkdir($dir . "/factory", 0700);
$db = database($dir . "/factory");
$source = new SQLite3($dir . "/analytics/competitors.sqlite3");
$source->exec(
    "CREATE TABLE competitor_targets(id INTEGER PRIMARY KEY,source_id TEXT,url TEXT,match_group TEXT,match_confirmed INTEGER,match_method TEXT,enabled INTEGER DEFAULT 1,kind TEXT DEFAULT 'product')",
);
$put = function (
    int $id,
    string $shop,
    string $group,
    int $confirmed = 1,
    int $enabled = 1,
    string $kind = "product"
) use ($source) {
    $q = $source->prepare("INSERT INTO competitor_targets VALUES(?,?,?,?,?,?,?,?)");
    foreach (
        [
            $id,
            $shop,
            "https://" .
            [
                "deluxe" => "deluxe.com.ua",
                "spicery" => "spiceryshop.com.ua",
                "banka" => "banka-speciy.in.ua",
                "lavka" => "test.lavka-speciy.com",
            ][$shop] .
            "/qa-" .
            $id,
            $group,
            $confirmed,
            "manual",
            $enabled,
            $kind,
        ]
        as $i => $value
    ) {
        $q->bindValue($i + 1, $value, is_int($value) ? SQLITE3_INTEGER : SQLITE3_TEXT);
    }
    $q->execute();
};
try {
    foreach ([11, 12, 13] as $id) {
        execute($db, "INSERT INTO products(id,name,url,updated_at) VALUES(?,?,?,?)", [
            $id,
            "Тестовая специя " . $id,
            "https://test.lavka-speciy.com/",
            now(),
        ]);
    }
    $put(101, "deluxe", "lavka-11");
    $put(102, "deluxe", "lavka-11");
    $put(103, "spicery", "lavka-12");
    $put(104, "banka", "lavka-13", 0);
    $put(105, "banka", "lavka-13", 1, 0);
    $put(106, "banka", "lavka-13", 1, 1, "category");
    $put(107, "lavka", "lavka-11");
    $put(108, "banka", "");
    $put(109, "banka", "unknown-key");
    $put(110, "banka", "lavka-999");
    $before = hash_file("sha256", $dir . "/analytics/competitors.sqlite3");
    $summary = importMatches($db, $dir . "/analytics");
    mc(
        $summary === [
            "confirmed_pages" => 5,
            "mappings" => 2,
            "products_with_sources" => 2,
            "unknown_product_pages" => 1,
            "invalid_group_pages" => 1,
            "duplicate_pages" => 1,
        ],
        "Full matching report distinguishes missing products and duplicate pages",
    );
    mc(
        hash_file("sha256", $dir . "/analytics/competitors.sqlite3") === $before,
        "Source SQLite bytes unchanged by read-only import",
    );
    mc(
        strpos(one($db, "SELECT url FROM competitors WHERE product_id=11")["url"], "qa-101") !== false,
        "match_group links product, never target ID",
    );
    mc(
        !one($db, "SELECT * FROM competitors WHERE product_id=13"),
        "Unconfirmed, archived and category targets excluded",
    );
    $calls = [];
    $transport = function (string $url, array $options) use (&$calls) {
        $calls[] = $url;
        return [
            "status" => 200,
            "headers" => ["content-type" => "text/html; charset=utf-8"],
            "body" =>
                substr($url, -11) === "/robots.txt"
                    ? "User-agent: *\nAllow: /\n"
                    : '<html><main><script type="application/ld+json">' .
                        json([
                            "@type" => "Product",
                            "url" => $url,
                            "aggregateRating" => ["reviewCount" => 12, "ratingValue" => 4.5],
                            "review" => [["reviewBody" => "Справочный текст из публичного источника."]],
                        ]) .
                        "</script></main></html>",
        ];
    };
    $collected = collectReviews($db, $dir . "/factory", 1, 30, $transport);
    mc(
        $collected["succeeded"] === 1 && $collected["checked"] === 1,
        "A confirmed page fills cached count, rating and sample",
    );
    $loaded = simulationSources($db, 11)[0];
    mc(
        (int) $loaded["review_count"] === 12 &&
            (float) $loaded["average_rating"] === 4.5 &&
            count($loaded["samples"]) === 1,
        "API exposes reference sample and source rating without mixing model average",
    );
    mc(count($calls) === 2, "Only robots and product are requested through the mocked transport");
    $collected = collectReviews($db, $dir . "/factory", 1, 30, function ($url) {
        return ["status" => 403, "headers" => [], "body" => "Forbidden"];
    });
    mc($collected["failed"] === 1, "Blocked source reports a failure");
    $source->exec("UPDATE competitor_targets SET enabled=0 WHERE id IN (101,102)");
    importMatches($db, $dir . "/analytics");
    mc(simulationSources($db, 11) === [], "Full reimport removes withdrawn source from current UI");
    mc(
        (int) one($db, "SELECT review_count FROM competitors WHERE product_id=11")["review_count"] ===
            12,
        "Withdrawal preserves old private observations",
    );
    $source->exec(
        "UPDATE competitor_targets SET url='https://spiceryshop.com.ua/qa-changed' WHERE id=103",
    );
    execute($db, "UPDATE competitors SET review_count=99,average_rating=4.8 WHERE product_id=12");
    importMatches($db, $dir . "/analytics");
    mc(
        one($db, "SELECT review_count FROM competitors WHERE product_id=12")["review_count"] === null,
        "Changed URL never inherits another page review count",
    );
    mc(
        (int) one($db, "SELECT count(*) n FROM competitor_history WHERE product_id=12")["n"] === 1,
        "Changed URL archives the previous observation",
    );
    mc(
        one($db, "SELECT value FROM settings WHERE key='matches_report'") !== null,
        "Latest import report is retained for diagnosis",
    );
    echo json(["state" => "ok", "checks" => $checks, "php" => PHP_VERSION]) . "\n";
} finally {
    $db = null;
    $source->close();
    $remove = function ($folder) use (&$remove) {
        foreach (scandir($folder) as $name) {
            if ($name === "." || $name === "..") {
                continue;
            }
            $path = $folder . "/" . $name;
            if (is_dir($path)) {
                $remove($path);
            } else {
                unlink($path);
            }
        }
        rmdir($folder);
    };
    $remove($dir);
}

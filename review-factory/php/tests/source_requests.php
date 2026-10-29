<?php
declare(strict_types=1);
require_once dirname(__DIR__) . "/src/App.php";
require_once dirname(__DIR__) . "/src/Competitors.php";
require_once dirname(__DIR__) . "/src/Migration.php";
use function ReviewFactory\{
    database,
    execute,
    one,
    rows,
    now,
    json,
    simulationEntry,
    simulationRequestSource,
    collectReviews,
};
$checks = 0;
function qc(bool $ok, string $message): void
{
    global $checks;
    ++$checks;
    if (!$ok) {
        throw new RuntimeException($message);
    }
}
function qd(callable $fn, int $status): void
{
    try {
        $fn();
    } catch (ReviewFactory\Problem $e) {
        qc($e->status === $status, "Expected rejection " . $status);
        return;
    }
    throw new RuntimeException("Unexpected acceptance");
}
$dir = sys_get_temp_dir() . "/rf-source-request-" . bin2hex(random_bytes(6));
mkdir($dir, 0700);
$db = database($dir);
file_put_contents(
    $dir . "/instance.json",
    json([
        "environment" => "test",
        "shop_url" => "https://test.lavka-speciy.com",
        "public_url" => "https://test.lavka-speciy.com/review-workshop/",
    ]),
);
try {
    $u = ReviewFactory\createUser($db, [
        "login" => "qa-owner",
        "name" => "Оператор",
        "staff_role" => "сотрудник",
        "role" => "admin",
        "password" => "Synthetic-test-password",
    ]);
    $other = ReviewFactory\createUser($db, [
        "login" => "qa-other",
        "name" => "Коллега",
        "staff_role" => "сотрудник",
        "role" => "author",
        "password" => "Synthetic-test-password",
    ]);
    foreach ([1, 2, 3] as $id) {
        execute($db, "INSERT INTO products(id,name,url,stock,updated_at) VALUES(?,?,?,?,?)", [
            $id,
            "QA " . $id,
            "https://test.lavka-speciy.com/",
            10,
            now(),
        ]);
    }
    ReviewFactory\rebuildQueue($db);
    $a = ReviewFactory\simulationClaim($db, $u, 1)["ids"][0];
    $b = ReviewFactory\simulationClaim($db, $other, 1)["ids"][0];
    qc(
        ReviewFactory\simulationShopStats($db, 1)["count"] === null,
        "An uninitialized shop snapshot is unknown, not zero",
    );
    execute($db, "INSERT INTO shop_reviews VALUES(1,1,'customer','QA author','QA body',3,?)", [now()]);
    execute($db, "INSERT INTO settings VALUES('shop_updated',?)", [now()]);
    $card = simulationEntry($db, $u, $a);
    qc(
        $card["shop_stats"]["count"] === 1 && $card["shop_stats"]["average"] === 3.0,
        "Native published shop review and average are visible",
    );
    qc(
        $card["combined_stats"]["count"] === 1 &&
            $card["combined_stats"]["average"] === 3.0 &&
            $card["combined_stats"]["after_current"] === 4.0,
        "Experiment starts with native ratings and projects the synthetic draft once",
    );
    qc(
        $card["model_stats"]["count"] === 0 && $card["model_stats"]["average"] === null,
        "Native snapshot does not become synthetic saved records",
    );
    $target1 = "https://deluxe.com.ua/qa-review-1";
    $target2 = "https://banka-speciy.in.ua/qa-review-2";
    execute(
        $db,
        "INSERT INTO competitors(product_id,source_id,url,confirmed,next_check) VALUES(1,?,?,1,?)",
        ["deluxe", $target1, "2020-01-01T00:00:00Z"],
    );
    execute(
        $db,
        "INSERT INTO competitors(product_id,source_id,url,confirmed,next_check) VALUES(2,?,?,1,?)",
        ["banka", $target2, now()],
    );
    qd(function () use ($db, $other, $a) {
        simulationRequestSource($db, $other, $a, "deluxe");
    }, 404);
    qd(function () use ($db, $u, $a) {
        simulationRequestSource($db, $u, $a, "banka");
    }, 404);
    simulationRequestSource($db, $other, $b, "banka");
    simulationRequestSource($db, $other, $b, "banka");
    qc(
        (int) one($db, "SELECT count(*) n FROM monitoring_requests")["n"] === 1,
        "Duplicate clicks create one request",
    );
    qc(
        simulationEntry($db, $other, $b)["sources"][0]["requested_at"] !== null,
        "Pending request is exposed for the owner UI",
    );
    $transport = function ($url) {
        return [
            "status" => 200,
            "headers" => ["content-type" => "text/html"],
            "body" =>
                substr($url, -11) === "/robots.txt"
                    ? "User-agent: *\nAllow: /\n"
                    : '<html><main><script type="application/ld+json">' .
                        json([
                            "@type" => "Product",
                            "url" => $url,
                            "aggregateRating" => ["reviewCount" => 11, "ratingValue" => 4.5],
                            "review" => [["reviewBody" => "Synthetic reference sample."]],
                        ]) .
                        "</script></main></html>",
        ];
    };
    $result = collectReviews($db, $dir, 1, 30, $transport);
    qc(
        $result["succeeded"] === 1 && $result["pages"][0]["product_id"] === 2,
        "Explicit user request takes priority over earlier regular targets",
    );
    qc(
        (int) one($db, "SELECT count(*) n FROM monitoring_requests")["n"] === 0,
        "Finished request leaves the pending queue",
    );
    qc(
        (int) simulationEntry($db, $other, $b)["sources"][0]["review_count"] === 11,
        "The requested page fills the saved reference data",
    );
    qc(
        simulationRequestSource($db, $other, $b, "banka")["state"] === "not_due",
        "Fresh results do not bypass the daily check or retry delay",
    );
    qd(function () use ($db, $dir, $transport) {
        collectReviews($db, $dir, 1, 30, $transport, "https://other.invalid/arbitrary");
    }, 422);
    $result = collectReviews($db, $dir, 1, 30, $transport, $target1, true);
    qc(
        $result["pages"][0]["product_id"] === 1 && $result["succeeded"] === 1,
        "Targeted CLI collects the confirmed URL only",
    );
    $capture = $result["pages"][0]["capture_file"];
    qc(
        is_file($capture) && (fileperms($capture) & 0777) === 0600,
        "Optional capture is a private non-executable HTML file",
    );
    $again = collectReviews($db, $dir, 1, 30, $transport, $target1);
    qc(
        $again["checked"] === 0 && isset($again["target_next_check"]),
        "Targeted request respects fresh cache and reports the next check",
    );
    execute($db, "INSERT INTO monitoring_requests VALUES(1,?,?,?,?)", [
        "deluxe",
        $target1,
        $u["id"],
        now(),
    ]);
    execute($db, "UPDATE competitors SET confirmed=0 WHERE product_id=1");
    collectReviews($db, $dir, 1, 30, $transport);
    qc(
        (int) one($db, "SELECT count(*) n FROM monitoring_requests")["n"] === 0,
        "Withdrawn confirmation drops the pending request without fetching",
    );
    execute($db, "INSERT INTO monitoring_requests VALUES(2,?,?,?,?)", [
        "banka",
        $target2,
        $u["id"],
        now(),
    ]);
    ReviewFactory\migrateCopy($db, $dir . "-prod", [1 => 101, 2 => 102, 3 => 103]);
    $copy = database($dir . "-prod");
    qc(
        (int) one($copy, "SELECT count(*) n FROM monitoring_requests")["n"] === 0,
        "TEST monitoring jobs never replay against production product IDs",
    );
    $copy = null;
    qc(
        (int) one($db, "SELECT count(*) n FROM monitoring_requests")["n"] === 1,
        "Migration leaves TEST request intact",
    );
    $entry = simulationEntry($db, $u, $a)["entry"];
    ReviewFactory\simulationSave(
        $db,
        $u,
        $a,
        ["body" => $entry["body"], "revision" => (int) $entry["revision"]],
        true,
    );
    qc(
        ReviewFactory\simulationShopStats($db, 1)["average"] === 3.0,
        "Saving a synthetic record does not change native shop average",
    );
    $weighted = ReviewFactory\simulationCombinedStats(
        ["count" => 20, "rating_sum" => 90, "average" => 4.5],
        ["count" => 5, "rating_sum" => 25, "average" => 5.0],
        4,
    );
    qc(
        $weighted["count"] === 25 &&
            $weighted["rating_sum"] === 115 &&
            abs($weighted["average"] - 4.6) < 1e-12,
        "Unequal source sizes use exact sums and counts, not the average of averages",
    );
    qc(
        abs($weighted["after_current"] - 119 / 26) < 1e-12,
        "Projection adds the current draft rating and one record",
    );
    $unknown = ReviewFactory\simulationCombinedStats(
        ["count" => null, "rating_sum" => null],
        ["count" => 5, "rating_sum" => 25],
        5,
    );
    qc(
        $unknown["count"] === null && $unknown["average"] === null && $unknown["after_current"] === null,
        "Missing native snapshot is not treated as an empty store",
    );
    $empty = ReviewFactory\simulationCombinedStats(
        ["count" => 0, "rating_sum" => 0],
        ["count" => 0, "rating_sum" => 0],
        5,
    );
    qc(
        $empty["count"] === 0 && $empty["average"] === null && $empty["after_current"] === 5.0,
        "Empty known sources avoid division by zero and still permit a projection",
    );
    $finishedCard = simulationEntry($db, $u, $a);
    qc(
        $finishedCard["combined_stats"]["count"] === 2 &&
            $finishedCard["combined_stats"]["average"] === 4.0 &&
            $finishedCard["combined_stats"]["after_current"] === 4.0,
        "Saved record appears once in combined mean and is not added again as a draft",
    );
    $preview = ReviewFactory\simulationPreview($db, $u, 1);
    qc(
        $preview["combined_stats"]["average"] === 4.0 && $preview["stats"]["count"] === 1,
        "Private preview shows combined mean but still lists synthetic records only",
    );
    $summary = ReviewFactory\simulationSummary($db, $u);
    qc(
        $summary["per_product"][0]["combined_stats"]["average"] === 4.0,
        "History exposes the same combined product mean",
    );
    $header =
        '<html><section class="product" data-product-id="154"><h1>QA herb</h1><a><img src="icon.svg">11 відгуків</a><div class="related-products"><div>999 відгуків</div></div><div>5 із 5</div></section></html>';
    $parsed = ReviewFactory\parseReviews($header, "https://banka-speciy.in.ua/ua/qa-herb");
    qc(
        $parsed["review_count"] === 11 &&
            $parsed["average_rating"] === null &&
            $parsed["samples"] === [],
        "Banka header gives a count without inventing average or text",
    );
    qd(function () use ($header) {
        ReviewFactory\parseReviews(
            str_replace("11 відгуків", "Unrecognised", $header),
            "https://banka-speciy.in.ua/ua/qa-herb",
        );
    }, 422);
    $fragment = collectReviews($db, $dir, 1, 30, $transport, $target2);
    qc($fragment["checked"] === 0, "Fresh data remains cached after parser additions");
    echo json(["state" => "ok", "checks" => $checks, "php" => PHP_VERSION]) . "\n";
} finally {
    $db = null;
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
    if (is_dir($dir . "-prod")) {
        $remove($dir . "-prod");
    }
}

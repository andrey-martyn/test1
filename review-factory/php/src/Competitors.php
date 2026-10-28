<?php
declare(strict_types=1);
namespace ReviewFactory;
require_once __DIR__ . "/Core.php";

const SOURCES = [
    "deluxe" => "Deluxe",
    "spicery" => "Spicery Shop",
    "banka" => "Банка Спецій",
    "specii" => "Лавка прянощів",
];
function analyticsLibrary(): string
{
    return dirname(__DIR__, 3) . "/spice-analytics/php/src";
}
function loadParser(): void
{
    require_once analyticsLibrary() . "/CompetitorParser.php";
}
function publicProductUrl(string $url): string
{
    $p = parse_url($url);
    return strtolower($p["host"] ?? "") .
        rtrim($p["path"] ?? "/", "/") .
        (!empty($p["query"]) ? "?" . $p["query"] : "");
}
/** Structured review count, not a guess from text or ratingCount. No scripts execute. */
function parseReviews(string $html, string $url): array
{
    loadParser();
    [$document, $xpath] = \Lavka\competitor_dom($html);
    $nodes = \Lavka\competitor_json_nodes($xpath);
    $products = [];
    foreach ($nodes as $node) {
        if (in_array("Product", \Lavka\competitor_types($node), true)) {
            $products[] = $node;
        }
    }
    $matched = [];
    foreach ($products as $p) {
        $address = $p["url"] ?? ($p["@id"] ?? "");
        if (is_string($address) && publicProductUrl($address) === publicProductUrl($url)) {
            $matched[] = $p;
        }
    }
    $product = count($matched) === 1 ? $matched[0] : (count($products) === 1 ? $products[0] : null);
    $result = ["review_count" => null, "average_rating" => null, "samples" => [], "method" => "unknown"];
    $count = function ($v): ?int {
        return (is_int($v) || is_string($v)) && preg_match('/^\d{1,7}$/D', (string) $v)
            ? (int) $v
            : null;
    };
    $rating = function ($value, $best = 5, $worst = 1): ?float {
        if (
            !is_numeric($value) ||
            !is_numeric($best) ||
            !is_numeric($worst) ||
            (float) $best !== 5.0 ||
            (float) $worst !== 1.0
        ) {
            return null;
        }
        $v = (float) $value;
        return $v >= 1 && $v <= 5 ? $v : null;
    };
    if ($product) {
        $aggregate = $product["aggregateRating"] ?? null;
        if (is_array($aggregate)) {
            $result["review_count"] = $count($aggregate["reviewCount"] ?? null);
            $result["average_rating"] = $rating(
                $aggregate["ratingValue"] ?? null,
                $aggregate["bestRating"] ?? 5,
                $aggregate["worstRating"] ?? 1,
            );
            $result["method"] = "jsonld";
        }
        $reviews = $product["review"] ?? [];
        if (is_array($reviews) && isset($reviews["@type"])) {
            $reviews = [$reviews];
        }
        if (is_array($reviews)) {
            foreach ($reviews as $r) {
                if (!is_array($r) || !is_string($r["reviewBody"] ?? null)) {
                    continue;
                }
                $body = trim(
                    preg_replace(
                        "/\s+/u",
                        " ",
                        html_entity_decode(
                            strip_tags($r["reviewBody"]),
                            ENT_QUOTES | ENT_HTML5,
                            "UTF-8",
                        ),
                    ),
                );
                if ($body !== "") {
                    $result["samples"][] = mb_substr($body, 0, 700);
                }
                if (count($result["samples"]) >= 3) {
                    break;
                }
            };
        }
    }
    // Only a single primary Product scope is accepted. Related products never supply a count.
    $scope = \Lavka\competitor_main_node($xpath);
    $scopes = $xpath->query('.//*[@itemscope and contains(@itemtype,"schema.org/Product")]', $scope);
    if (
        $scope instanceof \DOMElement &&
        $scope->hasAttribute("itemscope") &&
        strpos($scope->getAttribute("itemtype"), "schema.org/Product") !== false
    ) {
        $primary = $scope;
    } elseif ($scopes->length === 1) {
        $primary = $scopes->item(0);
    } else {
        $primary = null;
    }
    if ($primary) {
        if ($result["review_count"] === null) {
            $v = $xpath->query(
                './/*[@itemprop="aggregateRating"]//*[@itemprop="reviewCount"]',
                $primary,
            );
            if ($v->length === 1) {
                $n = $v->item(0);
                $result["review_count"] = $count(trim($n->getAttribute("content") ?: $n->textContent));
                if ($result["review_count"] !== null) {
                    $result["method"] = "microdata";
                }
            }
        }
        if ($result["average_rating"] === null) {
            $v = $xpath->query(
                './/*[@itemprop="aggregateRating"]//*[@itemprop="ratingValue"]',
                $primary,
            );
            if ($v->length === 1) {
                $n = $v->item(0);
                $best = $xpath->query(
                    './/*[@itemprop="aggregateRating"]//*[@itemprop="bestRating"]',
                    $primary,
                );
                $max = $best->length
                    ? ($best->item(0)->getAttribute("content") ?:
                    $best->item(0)->textContent)
                    : 5;
                $result["average_rating"] = $rating(
                    trim($n->getAttribute("content") ?: $n->textContent),
                    $max,
                );
            }
        }
        if (!$result["samples"]) {
            foreach (
                $xpath->query('.//*[@itemprop="review"]//*[@itemprop="reviewBody"]', $primary)
                as $n
            ) {
                $body = trim(preg_replace("/\s+/u", " ", strip_tags($n->textContent)));
                if ($body !== "") {
                    $result["samples"][] = mb_substr($body, 0, 700);
                }
                if (count($result["samples"]) >= 3) {
                    break;
                }
            };
        }
    }
    $result["samples"] = array_values(array_unique($result["samples"]));
    if ($result["review_count"] === null && $result["average_rating"] === null && !$result["samples"]) {
        throw new Problem("reviews_unrecognized");
    }
    return $result;
}
/** Read the existing confirmed mapping through SQLite's read-only handle. */
function importMatches(\PDO $db, string $analyticsDirectory): array
{
    $path = rtrim($analyticsDirectory, "/") . "/competitors.sqlite3";
    if (!is_file($path) || is_link($path)) {
        throw new Problem("monitoring_missing");
    }
    $source = new \SQLite3($path, SQLITE3_OPEN_READONLY);
    $source->enableExceptions(true);
    $mappings = [];
    try {
        $result = $source->query(
            "SELECT id,source_id,url,match_group,match_confirmed,match_method FROM competitor_targets WHERE enabled=1 AND kind='product' AND source_id IN ('deluxe','spicery','banka','specii') ORDER BY match_confirmed DESC,id",
        );
        while ($r = $result->fetchArray(SQLITE3_ASSOC)) {
            if (!preg_match('/^lavka-([1-9][0-9]{0,9})$/D', $r["match_group"], $m)) {
                continue;
            }
            $id = (int) $m[1];
            if (!one($db, "SELECT id FROM products WHERE id=?", [$id])) {
                continue;
            }
            $key = $id . ":" . $r["source_id"];
            if (isset($mappings[$key])) {
                continue;
            }
            $mappings[$key] = [
                $id,
                $r["source_id"],
                $r["url"],
                (int) $r["match_confirmed"],
                $r["match_method"],
            ];
        }
    } finally {
        $source->close();
    }
    transaction($db, function () use ($db, $mappings) {
        execute($db, "UPDATE competitors SET confirmed=0");
        foreach ($mappings as $m) {
            $old = one($db, "SELECT url FROM competitors WHERE product_id=? AND source_id=?", [
                $m[0],
                $m[1],
            ]);
            if ($old && $old["url"] !== $m[2]) {
                $previous = one($db, "SELECT * FROM competitors WHERE product_id=? AND source_id=?", [
                    $m[0],
                    $m[1],
                ]);
                execute(
                    $db,
                    "INSERT INTO competitor_history(product_id,source_id,url,payload,recorded_at) VALUES(?,?,?,?,?)",
                    [$m[0], $m[1], $previous["url"], json($previous), now()],
                );
                execute($db, "DELETE FROM competitors WHERE product_id=? AND source_id=?", [
                    $m[0],
                    $m[1],
                ]);
            }
            execute(
                $db,
                "INSERT INTO competitors(product_id,source_id,url,confirmed,match_method,next_check) VALUES(?,?,?,?,?,?) ON CONFLICT(product_id,source_id) DO UPDATE SET confirmed=excluded.confirmed,match_method=excluded.match_method",
                array_merge($m, [now()]),
            );
        }
        execute($db, "INSERT OR REPLACE INTO settings VALUES('matches_updated',?)", [now()]);
    });
    return ["mappings" => count($mappings)];
}
function collectReviews(
    \PDO $db,
    string $directory,
    int $limit = 5,
    int $seconds = 55,
    ?callable $transport = null
): array {
    if ($limit < 1 || $limit > 30 || $seconds < 30 || $seconds > 55) {
        throw new Problem("Допустимо limit 1–30, seconds 30–55");
    }
    require_once analyticsLibrary() . "/CompetitorHttp.php";
    $started = microtime(true);
    $deadline = $started + $seconds;
    $summary = ["checked" => 0, "succeeded" => 0, "failed" => 0, "deferred" => 0];
    $guard = function (string $url) use ($directory, $transport, $deadline): void {
        $policy = \Lavka\competitors_robots_policy($url, $directory, $transport);
        if (!$policy["allowed"]) {
            throw new \Lavka\CompetitorHttpError("robots_disallowed");
        }
        $host = parse_url($url, PHP_URL_HOST);
        $file = $directory . "/pace-" . hash("sha256", $host) . ".json";
        $old = is_file($file) ? json_decode(file_get_contents($file), true) : [];
        $wait = max(0, ($old["last"] ?? 0) + max(2, (float) $policy["crawl_delay"]) - microtime(true));
        if ($wait > 5 || microtime(true) + $wait + 13 > $deadline) {
            throw new Problem("request_deferred");
        }
        if ($wait > 0) {
            usleep((int) ceil($wait * 1000000));
        }
        file_put_contents($file, json(["last" => microtime(true)]), LOCK_EX);
        chmod($file, 0600);
    };
    $targets = rows(
        $db,
        "SELECT c.* FROM competitors c JOIN products p ON p.id=c.product_id WHERE c.confirmed=1 AND p.active=1 AND c.next_check<=? ORDER BY c.next_check,c.product_id,c.source_id LIMIT " .
            max(1, min(30, $limit)),
        [now()],
    );
    foreach ($targets as $target) {
        if (microtime(true) + 27 > $deadline) {
            ++$summary["deferred"];
            break;
        }
        try {
            $response = \Lavka\competitors_http_fetch($target["url"], $transport, $guard);
            $parsed = parseReviews($response["body"], $response["url"]);
            execute(
                $db,
                "INSERT INTO competitor_history(product_id,source_id,url,payload,recorded_at) VALUES(?,?,?,?,?)",
                [$target["product_id"], $target["source_id"], $response["url"], json($parsed), now()],
            );
            execute(
                $db,
                "UPDATE competitors SET review_count=?,average_rating=?,samples=?,last_success=?,last_attempt=?,error=NULL,next_check=? WHERE product_id=? AND source_id=?",
                [
                    $parsed["review_count"],
                    $parsed["average_rating"],
                    json($parsed["samples"]),
                    now(),
                    now(),
                    gmdate("Y-m-d\TH:i:s\Z", time() + 86400),
                    $target["product_id"],
                    $target["source_id"],
                ],
            );
            ++$summary["succeeded"];
        } catch (\Throwable $e) {
            $code =
                $e instanceof \Lavka\CompetitorHttpError
                    ? $e->errorCode
                    : ($e instanceof Problem
                        ? $e->getMessage()
                        : "parse_error");
            if ($code === "request_deferred") {
                ++$summary["deferred"];
                continue;
            }
            if (!preg_match('/^[a-z_]{1,50}$/D', $code)) {
                $code = "parse_error";
            }
            execute(
                $db,
                "UPDATE competitors SET error=?,last_attempt=?,next_check=? WHERE product_id=? AND source_id=?",
                [
                    $code,
                    now(),
                    gmdate("Y-m-d\TH:i:s\Z", time() + 21600),
                    $target["product_id"],
                    $target["source_id"],
                ],
            );
            ++$summary["failed"];
        }
        ++$summary["checked"];
    }
    execute($db, "INSERT OR REPLACE INTO settings VALUES('competitors_last_run',?)", [
        json($summary + ["at" => now()]),
    ]);
    return $summary;
}

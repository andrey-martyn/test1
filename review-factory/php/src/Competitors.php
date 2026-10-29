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
/** Banka's existing primary product wrapper is also used by its catalog parser. */
function bankaReviewHeader(\DOMXPath $xpath, string $url): ?int
{
    if (preg_replace("/^www\./i", "", parse_url($url, PHP_URL_HOST) ?: "") !== "banka-speciy.in.ua") {
        return null;
    }
    $blocks = $xpath->query(
        '//section[contains(concat(" ",normalize-space(@class)," ")," product ") and @data-product-id and .//h1]',
    );
    if ($blocks->length !== 1) {
        return null;
    }
    $block = $blocks->item(0);
    if (
        !preg_match('/^[1-9][0-9]{0,11}$/D', $block->getAttribute("data-product-id")) ||
        $xpath->query(".//h1", $block)->length !== 1
    ) {
        return null;
    }
    $counts = [];
    foreach (
        $xpath->query(".//*[self::a or self::span or self::button or self::div]", $block)
        as $node
    ) {
        if (\Lavka\competitor_related_node($node, $block, $block)) {
            continue;
        }
        $label = \Lavka\competitor_text($node->textContent);
        if (mb_strlen($label) > 80) {
            continue;
        }
        if (preg_match('/^([0-9]{1,7})\s*(?:відгук(?:ів|и)?|отзыв(?:ов|а)?|reviews?)$/iu', $label, $m)) {
            $counts[(int) $m[1]] = true;
        }
    }
    return count($counts) === 1 ? (int) array_key_first($counts) : null;
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
            }
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
            }
        }
    }
    if ($result["review_count"] === null) {
        $header = bankaReviewHeader($xpath, $url);
        if ($header !== null) {
            $result["review_count"] = $header;
            $result["method"] = "banka_header";
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
    $source->busyTimeout(5000);
    $source->exec("PRAGMA query_only=ON");
    $mappings = [];
    $summary = [
        "confirmed_pages" => 0,
        "mappings" => 0,
        "products_with_sources" => 0,
        "unknown_product_pages" => 0,
        "invalid_group_pages" => 0,
        "duplicate_pages" => 0,
    ];
    try {
        $result = $source->query(
            "SELECT id,source_id,url,match_group,match_confirmed,match_method FROM competitor_targets WHERE enabled=1 AND kind='product' AND match_confirmed=1 AND TRIM(match_group)<>'' AND source_id IN ('deluxe','spicery','banka','specii') ORDER BY match_confirmed DESC,id",
        );
        while ($r = $result->fetchArray(SQLITE3_ASSOC)) {
            ++$summary["confirmed_pages"];
            if (!preg_match('/^lavka-([1-9][0-9]{0,9})$/D', trim($r["match_group"]), $m)) {
                ++$summary["invalid_group_pages"];
                continue;
            }
            $id = (int) $m[1];
            if (!one($db, "SELECT id FROM products WHERE id=?", [$id])) {
                ++$summary["unknown_product_pages"];
                continue;
            }
            $key = $id . ":" . $r["source_id"];
            if (isset($mappings[$key])) {
                ++$summary["duplicate_pages"];
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
    $summary["mappings"] = count($mappings);
    $summary["products_with_sources"] = count(array_unique(array_column($mappings, 0)));
    transaction($db, function () use ($db, $mappings, $summary) {
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
        execute($db, "INSERT OR REPLACE INTO settings VALUES('matches_report',?)", [json($summary)]);
        execute($db, "DELETE FROM settings WHERE key='mapping_error'");
    });
    return $summary;
}
/** Optional diagnostic capture of a public page; kept outside the web root. */
function captureCompetitorPage(string $directory, array $target, string $html): string
{
    $folder = $directory . "/competitor-pages";
    if (is_link($folder) || (!is_dir($folder) && !mkdir($folder, 0700))) {
        throw new Problem("capture_directory_failed");
    }
    chmod($folder, 0700);
    $path =
        $folder .
        "/" .
        preg_replace("/[^a-z]/", "", $target["source_id"]) .
        "-" .
        hash("sha256", $target["url"]) .
        ".html";
    if (is_link($path)) {
        throw new Problem("capture_path_rejected");
    }
    $tmp = tempnam($folder, ".page-");
    if ($tmp === false) {
        throw new Problem("capture_failed");
    }
    try {
        if (
            file_put_contents($tmp, $html, LOCK_EX) === false ||
            !chmod($tmp, 0600) ||
            !rename($tmp, $path)
        ) {
            throw new Problem("capture_failed");
        }
    } finally {
        if (is_file($tmp)) {
            unlink($tmp);
        }
    }
    return $path;
}
function collectReviews(
    \PDO $db,
    string $directory,
    int $limit = 5,
    int $seconds = 55,
    ?callable $transport = null,
    ?string $targetUrl = null,
    bool $capture = false
): array {
    if ($limit < 1 || $limit > 30 || $seconds < 30 || $seconds > 55) {
        throw new Problem("Допустимо limit 1–30, seconds 30–55");
    }
    if ($capture && $targetUrl === null) {
        throw new Problem("Для сохранения диагностической страницы укажите --url");
    }
    require_once analyticsLibrary() . "/CompetitorHttp.php";
    $started = microtime(true);
    $deadline = $started + $seconds;
    $summary = ["checked" => 0, "succeeded" => 0, "failed" => 0, "deferred" => 0, "pages" => []];
    execute(
        $db,
        "DELETE FROM monitoring_requests WHERE NOT EXISTS(SELECT 1 FROM competitors c JOIN products p ON p.id=c.product_id WHERE c.product_id=monitoring_requests.product_id AND c.source_id=monitoring_requests.source_id AND c.url=monitoring_requests.url AND c.confirmed=1 AND p.active=1)",
    );
    if ($targetUrl !== null) {
        $target = one(
            $db,
            "SELECT c.next_check FROM competitors c JOIN products p ON p.id=c.product_id WHERE c.url=? AND c.confirmed=1 AND p.active=1",
            [$targetUrl],
        );
        if (!$target) {
            throw new Problem("Для этого адреса нет активного подтверждённого соответствия");
        }
        $summary["target_next_check"] = $target["next_check"];
    }
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
        "SELECT c.* FROM competitors c JOIN products p ON p.id=c.product_id LEFT JOIN monitoring_requests r ON r.product_id=c.product_id AND r.source_id=c.source_id AND r.url=c.url WHERE c.confirmed=1 AND p.active=1 AND c.next_check<=?" .
            ($targetUrl === null ? "" : " AND c.url=?") .
            " ORDER BY CASE WHEN r.requested_at IS NULL THEN 1 ELSE 0 END,r.requested_at,c.next_check,c.product_id,c.source_id LIMIT " .
            max(1, min(30, $limit)),
        $targetUrl === null ? [now()] : [now(), $targetUrl],
    );
    foreach ($targets as $target) {
        if (microtime(true) + 27 > $deadline) {
            ++$summary["deferred"];
            break;
        }
        $captured = null;
        try {
            $response = \Lavka\competitors_http_fetch($target["url"], $transport, $guard);
            if ($capture) {
                $captured = captureCompetitorPage($directory, $target, $response["body"]);
            }
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
            $summary["pages"][] = [
                "product_id" => (int) $target["product_id"],
                "source_id" => $target["source_id"],
                "state" => "succeeded",
                "review_count" => $parsed["review_count"],
                "average_rating" => $parsed["average_rating"],
                "samples" => count($parsed["samples"]),
                "method" => $parsed["method"],
            ];
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
            $summary["pages"][] = [
                "product_id" => (int) $target["product_id"],
                "source_id" => $target["source_id"],
                "state" => "failed",
                "error" => $code,
            ];
            ++$summary["failed"];
        }
        if ($captured !== null) {
            $last = count($summary["pages"]) - 1;
            if ($last >= 0) {
                $summary["pages"][$last]["capture_file"] = $captured;
            }
        }
        execute($db, "DELETE FROM monitoring_requests WHERE product_id=? AND source_id=? AND url=?", [
            $target["product_id"],
            $target["source_id"],
            $target["url"],
        ]);
        ++$summary["checked"];
    }
    execute($db, "INSERT OR REPLACE INTO settings VALUES('competitors_last_run',?)", [
        json($summary + ["at" => now()]),
    ]);
    return $summary;
}

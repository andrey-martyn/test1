<?php
declare(strict_types=1);
require_once __DIR__ . "/demo_fixture.php";
use function ReviewFactory\{demoRead, demoList, demoModerate, one, execute, json};
$checks = 0;
function dc(bool $ok, string $message): void
{
    global $checks;
    ++$checks;
    if (!$ok) {
        throw new RuntimeException($message);
    }
}
function dd(callable $fn, int $status = 422): void
{
    try {
        $fn();
    } catch (ReviewFactory\Problem $e) {
        dc($e->status === $status, "Expected status " . $status);
        return;
    }
    throw new RuntimeException("Expected rejection");
}
$base = sys_get_temp_dir() . "/rf-demo-" . bin2hex(random_bytes(6));
try {
    $f = demoFixture($base);
    $private = $f["private"];
    $id = $f["entry"]["id"];
    $revision = (int) $f["entry"]["revision"];
    dc(
        file_get_contents($f["root"] . "/index.php") === "original storefront",
        "Installer preserves native root file",
    );
    $config = file_get_contents($f["root"] . "/config.php");
    $rootHash = hash("sha256", $config);
    dc(
        is_file($f["root"] . "/catalog/controller/extension/module/review_factory_demo.php"),
        "Native module files are installed without OCMOD",
    );
    dc((array) demoRead($private, [1], 1)["products"] === [], "Saved records start unpublished");
    dc(demoList($private)["total"] === 1, "Saved records await a moderator");
    $core = ReviewFactory\database($private);
    dc(
        ReviewFactory\simulationSummary($core, $f["user"])["items"][0]["demo_state"] === "pending",
        "Author history shows the pending demo state",
    );
    dd(function () use ($private, $id, $revision) {
        demoModerate($private, $id, "approved", "customer:7", $revision);
    });
    dd(function () use ($private, $id, $revision) {
        demoModerate($private, $id, "approved", "ocstore:7", $revision + 1);
    }, 409);
    demoModerate($private, $id, "approved", "ocstore:7", $revision);
    $published = (array) demoRead($private, [1, 2], 1)["products"];
    dc(
        ReviewFactory\simulationSummary($core, $f["user"])["items"][0]["demo_state"] === "approved",
        "Author history reflects explicit moderation",
    );
    dc(
        count($published) === 1 && $published[1]["demo_count"] === 1,
        "Only approved product appears in public data",
    );
    dc(
        strpos($published[1]["entries"][0]["label"], "ДЕМО") === 0 &&
            $published[1]["entries"][0]["body"] === $f["entry"]["body"],
        "Public entry carries an explicit demo label and literal text",
    );
    dc(
        !isset($published[1]["entries"][0]["registration"]) &&
            !isset($published[1]["entries"][0]["customer_id"]),
        "Public payload has no buyer or verified-review identity",
    );
    $summary = (array) demoRead($private, [1])["products"];
    dc(
        $summary[1]["entries"] === [] && $summary[1]["demo_count"] === 1,
        "Catalog receives summaries without unnecessary text",
    );
    dd(function () use ($private) {
        demoRead($private, [1], 2);
    });
    demoModerate($private, $id, "hidden", "ocstore:7", $revision);
    dc(
        (array) demoRead($private, [1], 1)["products"] === [],
        "Withdrawal is effective on the next request",
    );
    demoModerate($private, $id, "approved", "ocstore:7", $revision);
    $db = new PDO("sqlite:" . $private . "/synthetic-reviews.sqlite3");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    execute($db, "UPDATE entries SET revision=revision+1 WHERE id=?", [$id]);
    dc(
        (array) demoRead($private, [1], 1)["products"] === [] && demoList($private)["total"] === 1,
        "Changed content loses approval and returns to moderation",
    );
    demoModerate($private, $id, "approved", "ocstore:7", $revision + 1);
    dc(
        (int) one($db, "SELECT count(*) n FROM demo_events")["n"] === 4,
        "Moderator decisions have a separate audit trail",
    );
    $db = null;
    $core = ReviewFactory\database($private);
    execute($core, "INSERT INTO settings VALUES('maintenance','test')");
    dd(function () use ($private) {
        demoRead($private, [1]);
    }, 503);
    execute($core, "DELETE FROM settings WHERE key='maintenance'");
    execute($core, "UPDATE settings SET value='production' WHERE key='environment'");
    dd(function () use ($private) {
        demoRead($private, [1]);
    }, 403);
    execute($core, "UPDATE settings SET value='test' WHERE key='environment'");
    $manifest = json_decode(file_get_contents($private . "/instance.json"), true);
    $bad = $manifest;
    $bad["environment"] = "production";
    file_put_contents($private . "/instance.json", json($bad));
    dd(function () use ($private) {
        demoRead($private, [1]);
    }, 403);
    file_put_contents($private . "/instance.json", json($manifest));
    dc(
        (int) one($core, "SELECT count(*) n FROM outbox")["n"] === 0 &&
            (int) one($core, "SELECT count(*) n FROM shop_reviews")["n"] === 0,
        "Demo moderation never creates native review or publication jobs",
    );
    dc(
        hash_file("sha256", $f["root"] . "/config.php") === $rootHash,
        "Native shop configuration is unchanged",
    );
    $before = one($core, "SELECT hash FROM users WHERE id=?", [$f["user"]["id"]])["hash"];
    rf_demoInstall($core, $private, rf_instance($private));
    dc(
        one($core, "SELECT hash FROM users WHERE id=?", [$f["user"]["id"]])["hash"] === $before &&
            demoList($private, "approved")["total"] === 1,
        "Reinstall preserves accounts and moderation decisions",
    );
    file_put_contents(
        $f["root"] . "/catalog/view/javascript/review_factory_demo.js",
        "unrelated custom file",
    );
    dd(function () use ($private, $core) {
        rf_demoInstall($core, $private, rf_instance($private));
    });
    $core = null;
    echo json(["state" => "ok", "checks" => $checks, "php" => PHP_VERSION]) . "\n";
} finally {
    demoRemove($base);
}

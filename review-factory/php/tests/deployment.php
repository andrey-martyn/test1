<?php
declare(strict_types=1);
require_once dirname(__DIR__) . "/bin/console.php";
$base = sys_get_temp_dir() . "/rf-deploy-" . bin2hex(random_bytes(8));
$root = $base . "/domain/test";
mkdir($root, 0700, true);
file_put_contents($root . "/index.php", "original-store");
$checks = 0;
function dc(bool $ok, string $message): void
{
    global $checks;
    ++$checks;
    if (!$ok) {
        throw new RuntimeException($message);
    }
}
function denied(callable $call): void
{
    try {
        $call();
    } catch (\ReviewFactory\Problem $e) {
        dc(true, "Rejected expected operation");
        return;
    }
    throw new RuntimeException("Unexpected acceptance");
}
try {
    $a = [
        "data" => $base . "/domain/private",
        "shop-root" => $root,
        "webroot" => $root . "/review-workshop",
        "url" => "https://test.lavka-speciy.com/review-workshop",
        "analytics-data" => $base . "/analytics",
        "statuses" => "2,3,5",
        "language" => "1",
    ];
    denied(function () use ($a, $root) {
        rf_setup(array_merge($a, ["data" => $root . "/private"]));
    });
    dc(!is_dir($root . "/private"), "Invalid private path rejected before mkdir");
    $r = rf_setup($a);
    dc($r["environment"] === "test", "Initial deployment TEST only");
    dc(file_get_contents($root . "/index.php") === "original-store", "Store root untouched");
    dc(is_file($a["webroot"] . "/index.php"), "Separate launcher created");
    dc((fileperms($a["data"]) & 0777) === 0700, "Private permissions");
    $instance = rf_instance($a["data"]);
    dc($instance["statuses"] === [2, 3, 5], "Explicit statuses preserved");
    denied(function () use ($a) {
        rf_setup($a);
    });
    $db = \ReviewFactory\database($a["data"]);
    \ReviewFactory\createUser($db, [
        "login" => "tester",
        "name" => "Тест",
        "staff_role" => "сотрудник",
        "role" => "admin",
        "password" => "Synthetic-password-only",
    ]);
    $s = \ReviewFactory\login($db, "tester", "Synthetic-password-only", "127.0.0.1");
    dc(
        rf_run("simulation-status", ["data" => $a["data"]])["shop_publication"] === false,
        "QA status never enables native publishing",
    );
    dc(
        rf_run("reply-worker", ["data" => $a["data"]])["state"] === "reply_workspace_disabled",
        "Old reply cron stops before reading shop credentials",
    );
    dc(
        rf_run("dispatch", ["data" => $a["data"]])["state"] === "native_authoring_disabled",
        "Old publication cron stops for QA users",
    );
    $sim = \ReviewFactory\simulationDb($db);
    \ReviewFactory\execute(
        $sim,
        "INSERT INTO events(operator_id,entry_id,action,created_at) VALUES(1,'fixture','backup_test',?)",
        [\ReviewFactory\now()],
    );
    $backup = $base . "/backup";
    rf_run("backup", ["data" => $a["data"], "destination" => $backup]);
    $copy = \ReviewFactory\database($backup);
    dc(
        (int) \ReviewFactory\one($copy, "SELECT count(*) n FROM sessions")["n"] === 0,
        "Backup excludes active sessions",
    );
    dc(
        (int) \ReviewFactory\one($copy, "SELECT count(*) n FROM users")["n"] === 1,
        "Backup retains accounts",
    );
    $simCopy = \ReviewFactory\simulationDb($copy);
    dc(
        (int) \ReviewFactory\one($simCopy, "SELECT count(*) n FROM events")["n"] === 1,
        "Backup includes a consistent synthetic SQLite snapshot",
    );
    $simCopy = null;
    $sim = null;
    $copy = null;
    $config =
        "<?php\ndefine('DB_HOSTNAME','lavkasp.mysql.tools');define('DB_USERNAME','synthetic');define('DB_PASSWORD','synthetic-secret');define('DB_DATABASE','lavkasp_test');define('DB_PORT','3306');define('DB_PREFIX','oc_');file_put_contents(" .
        var_export($base . "/executed", true) .
        ",'bad');";
    file_put_contents($root . "/config.php", $config);
    $c = \ReviewFactory\shopConfig($root);
    dc($c["DB_HOSTNAME"] === "127.0.0.1", "Known hosting account uses verified local connection");
    dc(!file_exists($base . "/executed"), "PHP store config never executed");
    $storage = $base . "/storage";
    mkdir($storage . "/modification/catalog/model/catalog", 0700, true);
    file_put_contents(
        $root . "/config.php",
        $config . "define('DIR_STORAGE'," . var_export($storage . "/", true) . ");",
    );
    $filter =
        "r1.product_id = p.product_id AND r1.status = '1' AND NOT EXISTS (marker)\nr2.product_id = p.product_id AND r2.status = '1' AND NOT EXISTS (marker)";
    file_put_contents($storage . "/modification/catalog/model/catalog/product.php", $filter);
    dc(ReviewFactory\shopRatingFilter($root), "Dispatch validates both generated filter queries");
    file_put_contents(
        $storage . "/modification/catalog/model/catalog/product.php",
        str_replace(
            'r1.product_id = p.product_id AND r1.status = \'1\' AND NOT EXISTS',
            'r1.product_id = p.product_id AND r1.status = \'1\' AND EXISTS',
            $filter,
        ),
    );
    dc(!ReviewFactory\shopRatingFilter($root), "Removed average filter stops dispatch");
    denied(function () use ($root) {
        \ReviewFactory\shopConfig($root, "production");
    });
    \ReviewFactory\execute($db, "INSERT INTO settings VALUES('maintenance','migration')");
    denied(function () use ($db) {
        \ReviewFactory\transaction($db, function () {});
    });
    dc(
        rf_run("queue", ["data" => $a["data"]])["state"] === "paused_for_migration",
        "Background changes pause for migration",
    );
    dc(
        rf_run("resume", ["data" => $a["data"]])["state"] === "writes_resumed",
        "Explicit recovery supported",
    );
    echo \ReviewFactory\json(["checks" => $checks, "state" => "ok", "php" => PHP_VERSION]) . "\n";
} finally {
    $db = null;
    $delete = function ($dir) use (&$delete) {
        foreach (scandir($dir) as $name) {
            if ($name !== "." && $name !== "..") {
                $p = $dir . "/" . $name;
                if (is_dir($p) && !is_link($p)) {
                    $delete($p);
                } else {
                    unlink($p);
                }
            }
        }
        rmdir($dir);
    };
    $delete($base);
}

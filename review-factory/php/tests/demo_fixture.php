<?php
declare(strict_types=1);
require_once dirname(__DIR__) . "/bin/console.php";

function demoFixture(string $base): array
{
    $root = $base . "/domain/test";
    mkdir($root, 0700, true);
    file_put_contents($root . "/index.php", "original storefront");
    $a = [
        "data" => $base . "/domain/private",
        "shop-root" => $root,
        "webroot" => $root . "/review-workshop",
        "url" => "https://test.lavka-speciy.com/review-workshop",
        "analytics-data" => $base . "/analytics",
        "statuses" => "2",
        "language" => "1",
    ];
    rf_setup($a);
    $config =
        "<?php\ndefine('DB_HOSTNAME','127.0.0.1');define('DB_USERNAME','synthetic');define('DB_PASSWORD','synthetic');define('DB_DATABASE','lavkasp_test');define('DB_PORT','3306');define('DB_PREFIX','oc_');";
    file_put_contents($root . "/config.php", $config);
    $db = \ReviewFactory\database($a["data"]);
    $u = \ReviewFactory\createUser($db, [
        "login" => "qa-demo",
        "name" => "QA operator",
        "staff_role" => "сотрудник",
        "role" => "admin",
        "password" => "Synthetic-test-password",
    ]);
    foreach ([1, 2] as $id) {
        \ReviewFactory\execute(
            $db,
            "INSERT INTO products(id,name,url,stock,updated_at) VALUES(?,?,?,?,?)",
            [
                $id,
                "QA product " . $id,
                "https://test.lavka-speciy.com/index.php?route=product/product&product_id=" . $id,
                10,
                \ReviewFactory\now(),
            ],
        );
    }
    \ReviewFactory\rebuildQueue($db);
    $id = \ReviewFactory\simulationClaim($db, $u, 1)["ids"][0];
    $entry = \ReviewFactory\simulationEntry($db, $u, $id)["entry"];
    $saved = \ReviewFactory\simulationSave(
        $db,
        $u,
        $id,
        [
            "body" => "[ТЕСТ] <img src=x onerror=alert(1)> Synthetic example.",
            "revision" => (int) $entry["revision"],
        ],
        true,
    );
    rf_demoInstall($db, $a["data"], rf_instance($a["data"]));
    return ["base" => $base, "root" => $root, "private" => $a["data"], "entry" => $saved, "user" => $u];
}
function demoRemove(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) as $name) {
        if ($name === "." || $name === "..") {
            continue;
        }
        $path = $dir . "/" . $name;
        if (is_dir($path) && !is_link($path)) {
            demoRemove($path);
        } else {
            unlink($path);
        }
    }
    rmdir($dir);
}

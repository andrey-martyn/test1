<?php
declare(strict_types=1);
if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit();
}
require_once __DIR__ . "/console.php";
require_once dirname(__DIR__) . "/src/Migration.php";
use ReviewFactory\Problem;
use function ReviewFactory\database;
use function ReviewFactory\json;
try {
    if (PHP_VERSION_ID < 80200) {
        throw new Problem("Нужен PHP 8.2 CLI");
    }
    umask(0077);
    $a = rf_args($argv);
    $command = $argv[1] ?? "";
    if (!isset($a["data"], $a["shop-root"], $a["plan"])) {
        throw new Problem("Нужны --data, --shop-root и --plan");
    }
    $sourceDir = rf_canonical($a["data"]);
    $instance = rf_instance($sourceDir);
    if ($instance["environment"] !== "test") {
        throw new Problem("Источником переноса должна быть TEST-установка");
    }
    $root = rf_canonical($a["shop-root"]);
    if (basename($root) !== "www") {
        throw new Problem("Рабочий корень должен быть существующей папкой www");
    }
    $planFile = rf_private($a["plan"], $instance["public_roots"]);
    $cfg = \ReviewFactory\shopConfig($root, "production");
    $shop = \ReviewFactory\shopConnect($cfg);
    $prefix = $cfg["DB_PREFIX"];
    // SELECT only, no DDL and no invocation of dispatch.
    $target = \ReviewFactory\rows(
        $shop,
        "SELECT product_id id,model,sku,ean FROM `{$prefix}product` ORDER BY product_id",
    );
    $lock = fopen($sourceDir . "/.worker.lock", "c");
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        throw new Problem("Дождитесь завершения фонового обработчика");
    }
    try {
        $db = database($sourceDir);
        $source = \ReviewFactory\rows($db, "SELECT id,model,sku,ean FROM products ORDER BY id");
        $overrides = [];
        if (isset($a["map-file"])) {
            $mapPath = rf_private($a["map-file"], $instance["public_roots"]);
            $overrides = json_decode(file_get_contents($mapPath), true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($overrides)) {
                throw new Problem("migration_manual_mapping_invalid");
            }
        }
        $mapping = \ReviewFactory\migrationMapping($source, $target, $overrides);
        if ($command === "plan") {
            if (file_exists($planFile)) {
                throw new Problem("Файл плана уже существует");
            }
            rf_write(
                $planFile,
                json(
                    $mapping + [
                        "manual_overrides" => $overrides,
                        "created_at" => \ReviewFactory\now(),
                        "shop_root" => $root,
                        "source_directory" => $sourceDir,
                    ],
                ),
            );
            echo json([
                "state" => "plan_created",
                "mapped" => count($mapping["mapping"]),
                "unresolved" => count($mapping["problems"]),
                "shop_writes" => false,
            ]) . "\n";
        } elseif ($command === "apply") {
            if (!isset($a["destination"], $a["url"])) {
                throw new Problem("Нужны --destination и --url");
            }
            $plan = json_decode(file_get_contents($planFile), true, 32, JSON_THROW_ON_ERROR);
            if (
                ($plan["source_directory"] ?? "") !== $sourceDir ||
                ($plan["shop_root"] ?? "") !== $root ||
                $plan["source_digest"] !== $mapping["source_digest"] ||
                $plan["target_digest"] !== $mapping["target_digest"] ||
                $plan["mapping"] !== $mapping["mapping"] ||
                $plan["problems"]
            ) {
                throw new Problem("План неполный или устарел. Требуется новая сверка товаров.");
            }
            $url = parse_url($a["url"]);
            if (
                ($url["scheme"] ?? "") !== "https" ||
                ($url["host"] ?? "") !== "lavka-speciy.com" ||
                !preg_match('~^/[a-zA-Z0-9_-]+/?$~D', $url["path"] ?? "") ||
                isset($url["query"]) ||
                isset($url["fragment"]) ||
                isset($url["user"]) ||
                isset($url["port"])
            ) {
                throw new Problem("Нужна отдельная папка https://lavka-speciy.com/");
            }
            $dest = rf_private($a["destination"], $instance["public_roots"]);
            \ReviewFactory\execute(
                $db,
                "INSERT OR REPLACE INTO settings VALUES('maintenance','migration')",
            );
            try {
                $result = \ReviewFactory\migrateCopy($db, $dest, array_map("intval", $plan["mapping"]));
            } catch (\Throwable $e) {
                \ReviewFactory\execute($db, "DELETE FROM settings WHERE key='maintenance'");
                throw $e;
            }
            $instance["environment"] = "production";
            $instance["shop_root"] = $root;
            $instance["shop_url"] = "https://lavka-speciy.com";
            $instance["public_url"] = rtrim($a["url"], "/");
            $instance["webroot"] = $root . rtrim($url["path"], "/");
            $instance["analytics_data"] = "";
            rf_write($dest . "/instance.json", json($instance));
            echo json(
                ["state" => "private_copy_created", "sessions_copied" => false, "shop_writes" => false] +
                    $result,
            ) . "\n";
        } else {
            throw new Problem("Команды: plan, apply");
        }
    } finally {
        if (is_resource($lock)) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
} catch (\Throwable $e) {
    fwrite(
        STDERR,
        ($e instanceof Problem ? $e->getMessage() : "Перенос остановлен. Рабочий магазин не изменён.") .
            "\n",
    );
    exit(1);
}

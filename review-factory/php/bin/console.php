<?php
declare(strict_types=1);
if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit();
}
require_once dirname(__DIR__) . "/src/Core.php";
require_once dirname(__DIR__) . "/src/Shop.php";
require_once dirname(__DIR__) . "/src/Competitors.php";
require_once dirname(__DIR__) . "/src/Replies.php";
require_once dirname(__DIR__) . "/src/Simulation.php";
use function ReviewFactory\json;
use function ReviewFactory\database;
use ReviewFactory\Problem;
function rf_args(array $argv): array
{
    $out = [];
    for ($i = 2; $i < count($argv); $i++) {
        if (!preg_match('/^--([a-z-]+)$/D', $argv[$i], $m)) {
            throw new Problem("Неверный аргумент");
        }
        $name = $m[1];
        if (isset($out[$name])) {
            throw new Problem("Повтор аргумента");
        }
        if (!isset($argv[$i + 1]) || strpos($argv[$i + 1], "--") === 0) {
            $out[$name] = true;
        } else {
            $out[$name] = $argv[++$i];
        }
    }
    return $out;
}
function rf_canonical(string $path): string
{
    if ($path === "" || $path[0] !== "/" || strpos($path, "\0") !== false) {
        throw new Problem("Нужен абсолютный путь");
    }
    $parts = explode("/", $path);
    if (in_array("..", $parts, true) || in_array(".", $parts, true)) {
        throw new Problem("Недопустимый путь");
    }
    $tail = [];
    $parent = rtrim($path, "/");
    while (!file_exists($parent)) {
        array_unshift($tail, basename($parent));
        $parent = dirname($parent);
    }
    if (is_link($parent)) {
        throw new Problem("Символические ссылки не поддерживаются");
    }
    $real = realpath($parent);
    if (!$real) {
        throw new Problem("Каталог недоступен");
    }
    return rtrim($real, "/") . ($tail ? "/" . implode("/", $tail) : "");
}
function rf_private(string $directory, array $publicRoots): string
{
    $dir = rf_canonical($directory);
    foreach ($publicRoots as $public) {
        $root = rf_canonical($public);
        if ($dir === $root || strpos($dir, $root . "/") === 0) {
            throw new Problem("Закрытые данные должны находиться вне публичных корней");
        }
    }
    return $dir;
}
function rf_write(string $path, string $content): void
{
    if (is_link($path)) {
        throw new Problem("Недопустимая символическая ссылка");
    }
    $tmp = $path . "." . bin2hex(random_bytes(6)) . ".tmp";
    if (file_put_contents($tmp, $content, LOCK_EX) === false) {
        throw new Problem("Не удалось записать файл");
    }
    chmod($tmp, 0600);
    if (!rename($tmp, $path)) {
        unlink($tmp);
        throw new Problem("Не удалось заменить файл");
    }
}
function rf_instance(string $directory): array
{
    $file = $directory . "/instance.json";
    if (!is_file($file) || is_link($file)) {
        throw new Problem("Установка не настроена");
    }
    $c = json_decode(file_get_contents($file), true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($c) || !in_array($c["environment"] ?? "", ["test", "production"], true)) {
        throw new Problem("Неверная среда установки");
    }
    rf_private($directory, $c["public_roots"]);
    return $c;
}
function rf_password(): string
{
    if (!function_exists("posix_isatty") || !posix_isatty(STDIN)) {
        throw new Problem("Создайте пользователя в интерактивном WebSSH");
    }
    $old = shell_exec("stty -g");
    if (!$old) {
        throw new Problem("Скрытый ввод недоступен");
    }
    try {
        system("stty -echo");
        fwrite(STDOUT, "Пароль: ");
        $first = rtrim((string) fgets(STDIN), "\r\n");
        fwrite(STDOUT, "\nПовторите: ");
        $second = rtrim((string) fgets(STDIN), "\r\n");
        fwrite(STDOUT, "\n");
    } finally {
        system("stty " . escapeshellarg(trim($old)));
    }
    if (!hash_equals($first, $second)) {
        throw new Problem("Пароли не совпадают");
    }
    return $first;
}
function rf_setup(array $a): array
{
    foreach (["data", "shop-root", "webroot", "url", "analytics-data", "statuses", "language"] as $key) {
        if (!is_string($a[$key] ?? null)) {
            throw new Problem("Нужен --" . $key);
        }
    }
    $root = rf_canonical($a["shop-root"]);
    $public = rf_canonical($a["webroot"]);
    $code = realpath(dirname(__DIR__));
    $url = parse_url($a["url"]);
    if (
        !$url ||
        ($url["scheme"] ?? "") !== "https" ||
        ($url["host"] ?? "") !== "test.lavka-speciy.com" ||
        !preg_match('~^/[a-zA-Z0-9_-]+/?$~D', $url["path"] ?? "") ||
        isset($url["query"]) ||
        isset($url["fragment"]) ||
        isset($url["port"]) ||
        isset($url["user"])
    ) {
        throw new Problem("Первую установку выполняем в отдельной папке https://test.lavka-speciy.com/");
    }
    if ($public !== $root . rtrim($url["path"], "/")) {
        throw new Problem("URL и публичный путь не совпадают");
    }
    $roots = array_unique([
        $root,
        dirname($root) . "/www",
        dirname($root) . "/test",
        dirname($root) . "/analytics",
    ]);
    $dir = rf_private($a["data"], $roots);
    rf_private($code, $roots);
    if (is_dir($public) && count(scandir($public)) > 2) {
        throw new Problem("Публичная папка уже занята");
    }
    if (is_dir($dir) && count(scandir($dir)) > 2) {
        throw new Problem("Закрытая папка уже занята");
    }
    $language = $a["language"];
    $statuses = $a["statuses"];
    if ($language === "auto" || $statuses === "analytics") {
        $cfg = \ReviewFactory\shopConfig($root);
        $shop = \ReviewFactory\shopConnect($cfg);
        if ($language === "auto") {
            $language = (string) \ReviewFactory\shopLanguage($shop, $cfg["DB_PREFIX"]);
        }
        if ($statuses === "analytics") {
            $statuses = implode(
                ",",
                \ReviewFactory\salesStatuses(
                    $shop,
                    $cfg["DB_PREFIX"],
                    (int) $language,
                    $a["analytics-data"],
                ),
            );
        }
    }
    if (!preg_match('/^[1-9][0-9]*(?:,[1-9][0-9]*)*$/D', $statuses) || !ctype_digit($language)) {
        throw new Problem("Укажите ID языка и статусов либо --language auto --statuses analytics");
    }
    if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
        throw new Problem("Не удалось создать закрытый каталог");
    }
    chmod($dir, 0700);
    $config = [
        "environment" => "test",
        "shop_root" => $root,
        "shop_url" => "https://test.lavka-speciy.com",
        "public_url" => rtrim($a["url"], "/"),
        "webroot" => $public,
        "public_roots" => array_values($roots),
        "analytics_data" => rf_canonical($a["analytics-data"]),
        "language" => (int) $language,
        "statuses" => array_map("intval", explode(",", $statuses)),
    ];
    rf_write($dir . "/instance.json", json($config));
    database($dir);
    if (!is_dir($public) && !mkdir($public, 0755, true)) {
        throw new Problem("Не удалось создать публичную папку");
    }
    chmod($public, 0755);
    $launcher =
        "<?php\ndeclare(strict_types=1);\nrequire_once " .
        var_export($code . "/src/App.php", true) .
        ";\n\\ReviewFactory\\run(" .
        var_export($dir, true) .
        "," .
        var_export($config["public_url"], true) .
        ");\n";
    file_put_contents($public . "/index.php", $launcher);
    chmod($public . "/index.php", 0644);
    file_put_contents($public . "/.htaccess", "Options -Indexes\nDirectoryIndex index.php\n");
    chmod($public . "/.htaccess", 0644);
    return [
        "state" => "installed",
        "environment" => "test",
        "next" => "Создайте личную учётную запись командой user.",
    ];
}
function rf_run(string $command, array $a): array
{
    if (PHP_VERSION_ID < 80200) {
        throw new Problem("Команды запускаются PHP 8.2 CLI; веб-PHP менять не нужно");
    }
    umask(0077);
    if ($command === "setup") {
        return rf_setup($a);
    }
    if (!is_string($a["data"] ?? null)) {
        throw new Problem("Укажите --data");
    }
    $dir = rf_canonical($a["data"]);
    $c = rf_instance($dir);
    $lock = fopen($dir . "/.worker.lock", "c");
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        return ["state" => "already_running"];
    }
    try {
        $db = database($dir);
        if ($command === "resume") {
            \ReviewFactory\execute($db, "DELETE FROM settings WHERE key='maintenance'");
            \ReviewFactory\audit($db, null, "environment.resumed", $c["environment"]);
            return ["state" => "writes_resumed"];
        }
        if (
            \ReviewFactory\one($db, "SELECT value FROM settings WHERE key='maintenance'") &&
            !in_array($command, ["backup", "status"], true)
        ) {
            return ["state" => "paused_for_migration"];
        }
        if ($command === "user") {
            $user = \ReviewFactory\createUser($db, [
                "login" => $a["login"] ?? "",
                "name" => $a["name"] ?? "",
                "staff_role" => $a["staff-role"] ?? "",
                "role" => $a["role"] ?? "author",
                "password" => rf_password(),
            ]);
            return ["state" => "user_created", "user_id" => (int) $user["id"]];
        }
        if ($command === "reset-password") {
            $user = \ReviewFactory\one($db, "SELECT id FROM users WHERE login=?", [$a["login"] ?? ""]);
            if (!$user) {
                throw new Problem("Пользователь не найден");
            }
            $password = rf_password();
            if (mb_strlen($password) < 12) {
                throw new Problem("Минимум 12 символов");
            }
            \ReviewFactory\execute($db, "UPDATE users SET hash=?,revision=revision+1 WHERE id=?", [
                password_hash($password, PASSWORD_ARGON2ID, [
                    "memory_cost" => 32768,
                    "time_cost" => 3,
                    "threads" => 1,
                ]),
                $user["id"],
            ]);
            \ReviewFactory\execute($db, "DELETE FROM sessions WHERE user_id=?", [$user["id"]]);
            \ReviewFactory\audit($db, null, "password.reset", (string) $user["id"]);
            return ["state" => "password_reset"];
        }
        if ($command === "matches-sync") {
            $source = $a["analytics-data"] ?? ($c["analytics_data"] ?? "");
            if (!is_string($source) || $source === "") {
                throw new Problem("Укажите --analytics-data с закрытым каталогом SQLite аналитики");
            }
            try {
                $source = rf_canonical($source);
                $summary = \ReviewFactory\importMatches($db, $source);
            } catch (\Throwable $error) {
                \ReviewFactory\execute(
                    $db,
                    "INSERT OR REPLACE INTO settings VALUES('mapping_error',?)",
                    [\ReviewFactory\now()],
                );
                throw $error;
            }
            if (isset($a["analytics-data"])) {
                $c["analytics_data"] = $source;
                rf_write($dir . "/instance.json", json($c));
            }
            \ReviewFactory\rebuildQueue($db);
            return ["state" => "matches_synced"] + $summary;
        }
        if ($command === "simulation-status") {
            $sim = \ReviewFactory\simulationDb($db);
            return [
                "state" => "private_simulation_ready",
                "environment" => $c["environment"],
                "shop_publication" => false,
                "products" => (int) \ReviewFactory\one(
                    $db,
                    "SELECT count(*) n FROM products WHERE active=1",
                )["n"],
                "entries" => (int) \ReviewFactory\one($sim, "SELECT count(*) n FROM entries")["n"],
                "saved_stats" => \ReviewFactory\simulationRatingStats($sim),
                "per_product" => \ReviewFactory\simulationProductStats($sim, 20),
                "confirmed_sources" => (int) \ReviewFactory\one(
                    $db,
                    "SELECT count(*) n FROM competitors WHERE confirmed=1",
                )["n"],
                "products_with_sources" => (int) \ReviewFactory\one(
                    $db,
                    "SELECT count(DISTINCT product_id) n FROM competitors WHERE confirmed=1",
                )["n"],
            ];
        }
        if ($command === "status") {
            return [
                "state" => "ok",
                "environment" => $c["environment"],
                "products" => (int) \ReviewFactory\one(
                    $db,
                    "SELECT count(*) n FROM products WHERE active=1",
                )["n"],
                "pending" => (int) \ReviewFactory\one(
                    $db,
                    "SELECT count(*) n FROM outbox WHERE state='pending'",
                )["n"],
                "health" => \ReviewFactory\rows(
                    $db,
                    "SELECT key,value FROM settings WHERE key NOT IN ('weights')",
                ),
            ];
        }
        if ($command === "deploy") {
            $public = rf_canonical($c["webroot"]);
            $root = rf_canonical($c["shop_root"]);
            $url = parse_url($c["public_url"]);
            $code = realpath(dirname(__DIR__));
            $expected = $c["environment"] === "test" ? "test.lavka-speciy.com" : "lavka-speciy.com";
            if (
                ($url["scheme"] ?? "") !== "https" ||
                ($url["host"] ?? "") !== $expected ||
                $public !== $root . rtrim($url["path"] ?? "", "/")
            ) {
                throw new Problem("Пути установки не совпадают");
            }
            rf_private($code, $c["public_roots"]);
            if (is_dir($public) && count(scandir($public)) > 2) {
                throw new Problem(
                    "Публичная папка занята. Код установленного модуля обновляется из клона, повторный deploy не нужен.",
                );
            }
            if (!is_dir($public) && !mkdir($public, 0755, true)) {
                throw new Problem("Не удалось создать публичную папку");
            }
            chmod($public, 0755);
            $launcher =
                "<?php\ndeclare(strict_types=1);\nrequire_once " .
                var_export($code . "/src/App.php", true) .
                ";\n\\ReviewFactory\\run(" .
                var_export($dir, true) .
                "," .
                var_export($c["public_url"], true) .
                ");\n";
            file_put_contents($public . "/index.php", $launcher);
            chmod($public . "/index.php", 0644);
            file_put_contents($public . "/.htaccess", "Options -Indexes\nDirectoryIndex index.php\n");
            chmod($public . "/.htaccess", 0644);
            return ["state" => "launcher_created"];
        }
        if ($command === "queue") {
            \ReviewFactory\rebuildQueue($db);
            return ["state" => "queue_updated"];
        }
        if ($command === "collect") {
            $summary = \ReviewFactory\collectReviews(
                $db,
                $dir,
                (int) ($a["limit"] ?? 5),
                (int) ($a["seconds"] ?? 55),
            );
            \ReviewFactory\rebuildQueue($db);
            return ["state" => "checked"] + $summary;
        }
        if (
            in_array($command, ["sync", "dispatch", "review-schema", "reply-sync", "reply-worker"], true)
        ) {
            if ($command === "review-schema" && $c["environment"] !== "test") {
                throw new Problem("reply_diagnostics_test_only");
            }
            if (
                $command === "reply-worker" &&
                !(int) \ReviewFactory\one(
                    $db,
                    "SELECT count(*) n FROM users WHERE active=1 AND role IN ('admin','moderator') AND work_mode<>'testing'",
                )["n"]
            ) {
                return ["state" => "reply_workspace_disabled"];
            }
            if (
                $command === "dispatch" &&
                !(int) \ReviewFactory\one(
                    $db,
                    "SELECT count(*) n FROM users WHERE active=1 AND work_mode='author'",
                )["n"]
            ) {
                return ["state" => "native_authoring_disabled"];
            }
            $cfg = \ReviewFactory\shopConfig($c["shop_root"], $c["environment"]);
            $shop = \ReviewFactory\shopConnect($cfg);
            $prefix = $cfg["DB_PREFIX"];
            if ($command === "review-schema") {
                return \ReviewFactory\reviewReplySchema($shop, $prefix);
            }
            if ($command === "reply-sync") {
                $result = \ReviewFactory\importReplies($db, $shop, $prefix, $c["language"]);
                \ReviewFactory\execute($db, "DELETE FROM settings WHERE key='replies_error'");
                return ["state" => "replies_synced"] + $result;
            }
            if ($command === "reply-worker") {
                return ["state" => "replies_processed"] +
                    \ReviewFactory\replyTick(
                        $db,
                        $shop,
                        $prefix,
                        $c["language"],
                        (int) ($a["limit"] ?? 3),
                    );
            }
            if ($command === "dispatch") {
                return ["state" => "processed"] +
                    \ReviewFactory\dispatch(
                        $db,
                        $shop,
                        $prefix,
                        (int) ($a["limit"] ?? 5),
                        $c["shop_root"],
                    );
            }
            $summary = \ReviewFactory\importShop(
                $db,
                $shop,
                $prefix,
                $c["language"],
                $c["statuses"],
                $c["shop_url"],
            );
            try {
                $summary["reply_reviews"] = \ReviewFactory\importReplies(
                    $db,
                    $shop,
                    $prefix,
                    $c["language"],
                )["reviews"];
                \ReviewFactory\execute($db, "DELETE FROM settings WHERE key='replies_error'");
            } catch (\Throwable $e) {
                $summary["reply_reviews"] = "unavailable";
                \ReviewFactory\execute(
                    $db,
                    "INSERT OR REPLACE INTO settings VALUES('replies_error',?)",
                    [\ReviewFactory\now()],
                );
            }
            if (!empty($c["analytics_data"])) {
                try {
                    $summary += \ReviewFactory\importMatches($db, $c["analytics_data"]);
                    \ReviewFactory\execute($db, "DELETE FROM settings WHERE key='mapping_error'");
                } catch (\Throwable $error) {
                    $summary["mappings"] = "unavailable";
                    \ReviewFactory\execute(
                        $db,
                        "INSERT OR REPLACE INTO settings VALUES('mapping_error',?)",
                        [\ReviewFactory\now()],
                    );
                }
            }
            \ReviewFactory\rebuildQueue($db);
            \ReviewFactory\execute($db, "DELETE FROM settings WHERE key='sync_error'");
            return ["state" => "synced"] + $summary;
        }
        if ($command === "backup") {
            if (!is_string($a["destination"] ?? null)) {
                throw new Problem("Укажите --destination");
            }
            $dest = rf_private($a["destination"], $c["public_roots"]);
            if (file_exists($dest)) {
                throw new Problem("Назначение уже существует");
            }
            if (!is_dir(dirname($dest))) {
                throw new Problem("Родительский каталог не существует");
            }
            $tmp = $dest . "." . bin2hex(random_bytes(5)) . ".tmp";
            mkdir($tmp, 0700);
            try {
                $db->exec("VACUUM INTO " . $db->quote($tmp . "/factory.sqlite3"));
                $copy = database($tmp);
                if ($copy->query("PRAGMA quick_check")->fetchColumn() !== "ok") {
                    throw new Problem("backup_failed");
                }
                $copy->exec("DELETE FROM sessions;DELETE FROM attempts;PRAGMA wal_checkpoint(TRUNCATE)");
                $copy = null;
                if (is_file($dir . "/synthetic-reviews.sqlite3")) {
                    $sim = \ReviewFactory\simulationDb($db);
                    $sim->exec("VACUUM INTO " . $sim->quote($tmp . "/synthetic-reviews.sqlite3"));
                    chmod($tmp . "/synthetic-reviews.sqlite3", 0600);
                    $sim = null;
                }
                copy($dir . "/instance.json", $tmp . "/instance.json");
                chmod($tmp . "/instance.json", 0600);
                if (!rename($tmp, $dest)) {
                    throw new Problem("backup_failed");
                }
            } catch (\Throwable $e) {
                foreach (glob($tmp . "/*") as $file) {
                    unlink($file);
                }
                rmdir($tmp);
                throw $e;
            }
            return ["state" => "backup_created"];
        }
        throw new Problem(
            "Команды: setup, user, reset-password, sync, matches-sync, collect, queue, dispatch, status, simulation-status, backup, deploy, resume, review-schema, reply-sync, reply-worker",
        );
    } catch (\Throwable $e) {
        if (
            isset($db) &&
            in_array($command, ["sync", "collect", "dispatch", "reply-sync", "reply-worker"], true)
        ) {
            $code =
                $e instanceof Problem && preg_match('/^[a-z_]{1,60}$/D', $e->getMessage())
                    ? $e->getMessage()
                    : "worker_error";
            try {
                $key = in_array($command, ["reply-sync", "reply-worker"], true)
                    ? "replies_error"
                    : "sync_error";
                \ReviewFactory\execute($db, "INSERT OR REPLACE INTO settings VALUES(?,?)", [
                    $key,
                    json(["command" => $command, "code" => $code, "at" => \ReviewFactory\now()]),
                ]);
                if ($command === "dispatch") {
                    \ReviewFactory\execute(
                        $db,
                        "UPDATE settings SET value='0' WHERE key='bridge_ready'",
                    );
                    \ReviewFactory\execute(
                        $db,
                        "UPDATE outbox SET last_error=?,updated_at=? WHERE state='pending'",
                        [$code, \ReviewFactory\now()],
                    );
                }
            } catch (\Throwable $ignored) {
            }
        }
        throw $e;
    } finally {
        if (is_resource($lock)) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
if (realpath($_SERVER["SCRIPT_FILENAME"] ?? "") === __FILE__) {
    try {
        echo json(rf_run($argv[1] ?? "help", rf_args($argv))) . "\n";
    } catch (\Throwable $e) {
        $message =
            $e instanceof Problem
                ? $e->getMessage()
                : "Ошибка команды; предыдущие данные сохранены. Подробности подключения не выводятся.";
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

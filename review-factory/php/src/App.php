<?php
declare(strict_types=1);
namespace ReviewFactory;
require_once __DIR__ . "/Core.php";
function response($data, int $status = 200): void
{
    http_response_code($status);
    header("Content-Type: application/json; charset=utf-8");
    echo json($data);
}
function requestBody(): array
{
    if ((int) ($_SERVER["CONTENT_LENGTH"] ?? 0) > 65536) {
        throw new Problem("Запрос слишком большой", 413);
    }
    $raw = file_get_contents("php://input", false, null, 0, 65537);
    if (strlen($raw) > 65536) {
        throw new Problem("Запрос слишком большой", 413);
    }
    try {
        $value = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    } catch (\Throwable $e) {
        throw new Problem("Неверный JSON", 400);
    }
    if (!is_array($value)) {
        throw new Problem("Нужен объект JSON", 400);
    }
    return $value;
}
function api(\PDO $db, string $path, string $method, array $body, ?array $user): array
{
    if ($path === "me") {
        return [
            "user" => safeUser($user),
            "csrf" => $user["csrf"],
            "environment" => one($db, "SELECT value FROM settings WHERE key='environment'")["value"],
            "health" => rows(
                $db,
                "SELECT key,value FROM settings WHERE key IN ('shop_updated','queue_updated','matches_updated','bridge_ready','competitors_last_run','sync_error')",
            ),
        ];
    }
    if ($path === "claim" && $method === "POST") {
        return ["ids" => claim($db, $user, integer($body["count"] ?? 1, 1, 10))];
    }
    if ($path === "assignments") {
        return [
            "items" => rows(
                $db,
                "SELECT p.id,p.name,q.priority FROM assignments a JOIN products p ON p.id=a.product_id LEFT JOIN queue q ON q.product_id=p.id WHERE a.user_id=? ORDER BY a.assigned_at,p.id",
                [$user["id"]],
            ),
        ];
    }
    if (preg_match('~^products/([1-9][0-9]{0,9})$~D', $path, $m) && $method === "GET") {
        return product($db, (int) $m[1], $user);
    }
    if ($path === "drafts" && $method === "POST") {
        return saveDraft($db, $user, $body);
    }
    if (preg_match('~^drafts/([a-f0-9]{32})/submit$~D', $path, $m) && $method === "POST") {
        return submit($db, $user, $m[1], integer($body["revision"] ?? null, 1, 2147483647));
    }
    if (preg_match('~^drafts/([a-f0-9]{32})/copy$~D', $path, $m) && $method === "POST") {
        $d = one($db, "SELECT * FROM drafts WHERE id=? AND author_id=? AND state='historical'", [
            $m[1],
            $user["id"],
        ]);
        if (!$d) {
            throw new Problem("Материал TEST не найден", 404);
        }
        transaction($db, function () use ($db, $d, $user) {
            $assigned = one($db, "SELECT user_id FROM assignments WHERE product_id=?", [
                $d["product_id"],
            ]);
            if ($assigned && (int) $assigned["user_id"] !== (int) $user["id"]) {
                throw new Problem("Товар уже в работе у другого автора", 409);
            }
            if (!$assigned) {
                execute($db, "INSERT INTO assignments VALUES(?,?,?)", [
                    $d["product_id"],
                    $user["id"],
                    now(),
                ]);
            }
        });
        $existing = one(
            $db,
            "SELECT id FROM drafts WHERE product_id=? AND author_id=? AND state IN ('draft','submitted','moderation')",
            [$d["product_id"], $user["id"]],
        );
        if ($existing) {
            throw new Problem("По этому товару уже есть незавершённый материал", 409);
        }
        return saveDraft($db, $user, [
            "product_id" => (int) $d["product_id"],
            "rating" => $d["rating"] === null ? null : (int) $d["rating"],
            "body" => $d["body"],
            "sentiment" => $d["sentiment"],
            "city" => $d["city"],
            "experience_date" => $d["experience_date"],
        ]);
    }
    if ($path === "drafts" && $method === "GET") {
        $sql =
            "SELECT d.*,p.name product_name,u.name author_name,o.last_error FROM drafts d JOIN products p ON p.id=d.product_id JOIN users u ON u.id=d.author_id LEFT JOIN outbox o ON o.draft_id=d.id";
        return [
            "items" => rows(
                $db,
                $sql .
                    ($user["role"] === "author" ? " WHERE d.author_id=" . (int) $user["id"] : "") .
                    " ORDER BY d.updated_at DESC LIMIT 200",
            ),
        ];
    }
    if ($path === "skip" && $method === "POST") {
        $id = integer($body["product_id"] ?? null, 1, 2147483647);
        transaction($db, function () use ($db, $user, $id) {
            if (
                one(
                    $db,
                    "SELECT id FROM drafts WHERE product_id=? AND author_id=? AND state='draft' AND body<>''",
                    [$id, $user["id"]],
                )
            ) {
                throw new Problem(
                    "У товара есть черновик. Сохраните его и выберите другой товар из пакета.",
                    409,
                );
            }
            execute($db, "DELETE FROM assignments WHERE product_id=? AND user_id=?", [$id, $user["id"]]);
            // A short global pause prevents immediately returning the same first item.
            execute($db, "UPDATE products SET cooldown_until=? WHERE id=?", [
                gmdate("Y-m-d\TH:i:s\Z", time() + 3600),
                $id,
            ]);
            audit($db, (int) $user["id"], "product.skipped", (string) $id);
        });
        return ["ok" => true];
    }
    if ($path === "stats") {
        return stats($db, $user);
    }
    requireRole($user, ["admin"]);
    if ($path === "users" && $method === "GET") {
        return [
            "items" => array_map(
                __NAMESPACE__ . "\\safeUser",
                rows($db, "SELECT * FROM users ORDER BY id"),
            ),
        ];
    }
    if ($path === "users" && $method === "POST") {
        return createUser($db, $body, (int) $user["id"]);
    }
    if (preg_match('~^users/([1-9][0-9]*)$~D', $path, $m) && $method === "POST") {
        $id = (int) $m[1];
        $active = $body["active"] ?? null;
        if (!is_bool($active)) {
            throw new Problem("Укажите состояние учётной записи");
        }
        if ($id === (int) $user["id"] && !$active) {
            throw new Problem("Нельзя отключить собственную учётную запись");
        }
        $target = one($db, "SELECT * FROM users WHERE id=?", [$id]);
        if (!$target) {
            throw new Problem("Пользователь не найден", 404);
        }
        if (
            $target["role"] === "admin" &&
            !$active &&
            (int) one($db, "SELECT count(*) n FROM users WHERE role='admin' AND active=1")["n"] <= 1
        ) {
            throw new Problem("Нужен хотя бы один администратор");
        }
        transaction($db, function () use ($db, $active, $id, $user) {
            execute($db, "UPDATE users SET active=?,revision=revision+1 WHERE id=?", [
                $active ? 1 : 0,
                $id,
            ]);
            execute($db, "DELETE FROM sessions WHERE user_id=?", [$id]);
            audit($db, (int) $user["id"], "user.access_changed", (string) $id);
        });
        return ["ok" => true];
    }
    if ($path === "settings" && $method === "GET") {
        return [
            "weights" => json_decode(
                one($db, "SELECT value FROM settings WHERE key='weights'")["value"],
                true,
            ),
            "cooldown_days" => (int) one($db, "SELECT value FROM settings WHERE key='cooldown_days'")[
                "value"
            ],
        ];
    }
    if ($path === "settings" && $method === "POST") {
        $weights = [];
        foreach (["competitor", "gap", "sales", "views"] as $key) {
            $weights[$key] = integer($body["weights"][$key] ?? null, 0, 100);
        }
        if (array_sum($weights) === 0) {
            throw new Problem("Хотя бы один вес должен быть больше нуля");
        }
        $days = integer($body["cooldown_days"] ?? 14, 1, 365);
        transaction($db, function () use ($db, $weights, $days, $user) {
            execute($db, "UPDATE settings SET value=? WHERE key='weights'", [json($weights)]);
            execute($db, "UPDATE settings SET value=? WHERE key='cooldown_days'", [(string) $days]);
            audit($db, (int) $user["id"], "settings.updated", "queue");
        });
        rebuildQueue($db);
        return ["ok" => true];
    }
    if ($path === "queue" && $method === "GET") {
        return [
            "items" => rows(
                $db,
                "SELECT p.id,p.name,p.boost,p.boost_reason,q.priority,a.user_id,u.name assigned_to FROM products p LEFT JOIN queue q ON q.product_id=p.id LEFT JOIN assignments a ON a.product_id=p.id LEFT JOIN users u ON u.id=a.user_id WHERE p.active=1 ORDER BY q.priority DESC,p.id LIMIT 1000",
            ),
        ];
    }
    if ($path === "queue" && $method === "POST") {
        $id = integer($body["product_id"] ?? null, 1, 2147483647);
        $boost = integer($body["boost"] ?? 0, -100, 100);
        $reason = text($body["reason"] ?? "", 300, true);
        if (!one($db, "SELECT id FROM products WHERE id=?", [$id])) {
            throw new Problem("Товар не найден", 404);
        }
        transaction($db, function () use ($db, $id, $boost, $reason, $body, $user) {
            execute($db, "UPDATE products SET boost=?,boost_reason=? WHERE id=?", [
                $boost,
                $reason,
                $id,
            ]);
            if (!empty($body["release"])) {
                execute($db, "DELETE FROM assignments WHERE product_id=?", [$id]);
            }
            audit($db, (int) $user["id"], "queue.adjusted", (string) $id, [
                "boost" => $boost,
                "reason" => $reason,
                "released" => !empty($body["release"]),
            ]);
        });
        rebuildQueue($db);
        return ["ok" => true];
    }
    if ($path === "audit") {
        return [
            "items" => rows(
                $db,
                "SELECT a.*,u.name FROM audit a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.id DESC LIMIT 200",
            ),
        ];
    }
    throw new Problem("Маршрут не найден", 404);
}
function run(string $directory, string $publicUrl, bool $development = false): void
{
    header("Cache-Control: no-store");
    header("X-Content-Type-Options: nosniff");
    header("X-Frame-Options: DENY");
    header("Referrer-Policy: no-referrer");
    header("X-Robots-Tag: noindex, nofollow, noarchive");
    header(
        "Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' https://test.lavka-speciy.com https://lavka-speciy.com data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'",
    );
    try {
        $url = parse_url($publicUrl);
        $base = rtrim($url["path"] ?? "", "/");
        $expected =
            ($url["scheme"] ?? "https") .
            "://" .
            ($url["host"] ?? "") .
            (isset($url["port"]) ? ":" . $url["port"] : "");
        $host = $_SERVER["HTTP_HOST"] ?? "";
        $secure =
            (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") ||
            ($_SERVER["SERVER_PORT"] ?? "") === "443";
        if (
            !$development &&
            (!$url || ($url["scheme"] ?? "") !== "https" || strcasecmp($host, $url["host"] ?? "") !== 0)
        ) {
            throw new Problem("Неверный адрес приложения", 400);
        }
        if (!$development && !$secure) {
            header("Location: " . $publicUrl, true, 307);
            return;
        }
        if (!$development) {
            header("Strict-Transport-Security: max-age=31536000");
        }
        $method = $_SERVER["REQUEST_METHOD"] ?? "GET";
        $route = $_GET["route"] ?? "";
        if (!is_string($route) || !preg_match('~^[a-zA-Z0-9_/.\-]*$~D', $route)) {
            throw new Problem("Неверный маршрут", 400);
        }
        if (strpos($route, "assets/") === 0) {
            if (!in_array($method, ["GET", "HEAD"], true)) {
                throw new Problem("Метод не поддерживается", 405);
            }
            $name = substr($route, 7);
            $files = ["app.js" => "application/javascript", "style.css" => "text/css"];
            if (!isset($files[$name])) {
                throw new Problem("Файл не найден", 404);
            }
            header("Content-Type: " . $files[$name] . "; charset=utf-8");
            if ($method !== "HEAD") {
                readfile(dirname(__DIR__) . "/static/" . $name);
            }
            return;
        }
        if ($route === "") {
            if ($method !== "GET") {
                throw new Problem("Метод не поддерживается", 405);
            }
            header("Content-Type: text/html; charset=utf-8");
            $html = file_get_contents(dirname(__DIR__) . "/static/index.html");
            $html = str_replace(
                "__ASSET_VERSION__",
                substr(
                    hash(
                        "sha256",
                        hash_file("sha256", dirname(__DIR__) . "/static/app.js") .
                            hash_file("sha256", dirname(__DIR__) . "/static/style.css"),
                    ),
                    0,
                    24,
                ),
                $html,
            );
            echo $html;
            return;
        }
        if (!in_array($method, ["GET", "POST"], true)) {
            throw new Problem("Метод не поддерживается", 405);
        }
        if ($method === "POST") {
            $origin = $_SERVER["HTTP_ORIGIN"] ?? "";
            if ($origin !== $expected || ($_SERVER["HTTP_SEC_FETCH_SITE"] ?? "") === "cross-site") {
                throw new Problem("Недопустимый источник запроса", 403);
            }
        }
        $db = database($directory);
        $token = $_COOKIE["lavka_review_factory"] ?? "";
        $user = session($db, is_string($token) ? $token : "");
        $body = $method === "POST" ? requestBody() : [];
        $cookie = [
            "expires" => time() + 43200,
            "path" => $base . "/",
            "secure" => !$development || $secure,
            "httponly" => true,
            "samesite" => "Strict",
        ];
        if ($route === "login" && $method === "POST") {
            $result = login(
                $db,
                text($body["login"] ?? "", 64),
                text($body["password"] ?? "", 512),
                $_SERVER["REMOTE_ADDR"] ?? "",
            );
            setcookie("lavka_review_factory", $result["token"], $cookie);
            unset($result["token"]);
            response($result);
            return;
        }
        if (!$user) {
            throw new Problem("Войдите в комбайн", 401);
        }
        if (
            $method === "POST" &&
            (!is_string($_SERVER["HTTP_X_CSRF_TOKEN"] ?? null) ||
                !hash_equals($user["csrf"], $_SERVER["HTTP_X_CSRF_TOKEN"]))
        ) {
            throw new Problem("Обновите страницу перед отправкой", 403);
        }
        if (
            $method === "POST" &&
            $route !== "logout" &&
            one($db, "SELECT value FROM settings WHERE key='maintenance'")
        ) {
            throw new Problem("Идёт перенос данных. Запись временно приостановлена.", 503);
        }
        if ($route === "logout" && $method === "POST") {
            execute($db, "DELETE FROM sessions WHERE token=?", [hash("sha256", $token)]);
            $cookie["expires"] = 1;
            setcookie("lavka_review_factory", "", $cookie);
            response(["ok" => true]);
            return;
        }
        if ($route === "login") {
            throw new Problem("Метод не поддерживается", 405);
        }
        response(api($db, $route, $method, $body, $user));
    } catch (Problem $e) {
        response(["error" => $e->getMessage()], $e->status);
    } catch (\Throwable $e) {
        error_log("review-factory: " . get_class($e));
        response(
            ["error" => "Не удалось выполнить действие. Сохранённые материалы остаются в системе."],
            500,
        );
    }
}

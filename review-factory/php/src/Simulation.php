<?php
declare(strict_types=1);
namespace ReviewFactory;
require_once __DIR__ . "/Core.php";

const SIMULATION_LABEL = "ТЕСТОВЫЕ ДАННЫЕ · не отзыв покупателя";

/** Authenticated QA only. Never opens a shop connection or a customer database. */
function simulationDirectory(\PDO $core): string
{
    $files = rows($core, "PRAGMA database_list");
    $file = "";
    foreach ($files as $r) {
        if ($r["name"] === "main") {
            $file = $r["file"];
        }
    }
    if (!$file || basename($file) !== "factory.sqlite3") {
        throw new Problem("Тестовый режим не настроен", 503);
    }
    $directory = dirname($file);
    $manifest = $directory . "/instance.json";
    if (!is_file($manifest) || is_link($manifest)) {
        throw new Problem("Для тестового режима нужна действующая TEST-установка", 403);
    }
    $cfg = json_decode(file_get_contents($manifest), true, 16, JSON_THROW_ON_ERROR);
    $environment = one($core, "SELECT value FROM settings WHERE key='environment'")["value"] ?? "";
    if (
        $environment !== "test" ||
        ($cfg["environment"] ?? "") !== "test" ||
        rtrim($cfg["shop_url"] ?? "", "/") !== "https://test.lavka-speciy.com" ||
        parse_url($cfg["public_url"] ?? "", PHP_URL_HOST) !== "test.lavka-speciy.com"
    ) {
        throw new Problem("Генератор тестовых данных отключён на рабочем сайте", 403);
    }
    return $directory;
}
function simulationDb(\PDO $core): \PDO
{
    $path = simulationDirectory($core) . "/synthetic-reviews.sqlite3";
    if (is_link($path)) {
        throw new Problem("Недопустимый путь тестовой базы", 503);
    }
    $db = new \PDO("sqlite:" . $path, null, null, [
        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
    ]);
    chmod($path, 0600);
    $db->exec("PRAGMA journal_mode=WAL;PRAGMA busy_timeout=5000");
    $db->exec("CREATE TABLE IF NOT EXISTS entries(seq INTEGER PRIMARY KEY AUTOINCREMENT,id TEXT NOT NULL UNIQUE,product_id INTEGER NOT NULL,product_name TEXT NOT NULL,operator_id INTEGER NOT NULL,origin TEXT NOT NULL DEFAULT 'synthetic' CHECK(origin='synthetic'),model_author TEXT NOT NULL,model_city TEXT NOT NULL,model_date TEXT NOT NULL,rating INTEGER NOT NULL CHECK(rating BETWEEN 3 AND 5),mood TEXT NOT NULL,registration TEXT NOT NULL CHECK(registration IN ('model_verified','model_guest')),body TEXT NOT NULL DEFAULT '',state TEXT NOT NULL DEFAULT 'draft' CHECK(state IN ('draft','saved','skipped')),revision INTEGER NOT NULL DEFAULT 1,created_at TEXT NOT NULL,saved_at TEXT);
      CREATE UNIQUE INDEX IF NOT EXISTS one_model_draft ON entries(product_id) WHERE state='draft';CREATE INDEX IF NOT EXISTS model_operator ON entries(operator_id,state);CREATE INDEX IF NOT EXISTS model_products ON entries(product_id,state);
      CREATE TABLE IF NOT EXISTS events(id INTEGER PRIMARY KEY,operator_id INTEGER NOT NULL,entry_id TEXT NOT NULL,action TEXT NOT NULL,created_at TEXT NOT NULL);");
    return $db;
}
function simulationTransaction(\PDO $core, \PDO $db, callable $fn)
{
    $db->exec("BEGIN IMMEDIATE");
    try {
        if (one($core, "SELECT value FROM settings WHERE key='maintenance'")) {
            throw new Problem("Запись временно приостановлена на время переноса", 503);
        }
        $r = $fn();
        $db->exec("COMMIT");
        return $r;
    } catch (\Throwable $e) {
        $db->exec("ROLLBACK");
        throw $e;
    }
}
function requireSimulationUser(array $user): void
{
    if (($user["work_mode"] ?? "") !== "testing") {
        throw new Problem("Эта учётная запись не включена в тестовый режим", 403);
    }
}
function simulationEvent(\PDO $db, array $user, string $id, string $action): void
{
    execute($db, "INSERT INTO events(operator_id,entry_id,action,created_at) VALUES(?,?,?,?)", [
        $user["id"],
        $id,
        $action,
        now(),
    ]);
}

function simulationDate(\PDO $db, int $product): string
{
    $end = min(gmdate("Y-m-d"), "2026-12-31");
    if ($end < "2022-01-01") {
        throw new Problem("Диапазон модельных дат недоступен");
    }
    $years = array_fill_keys(range(2022, (int) substr($end, 0, 4)), 0);
    $months = [];
    $dates = rows(
        $db,
        "SELECT model_date FROM entries WHERE product_id=? AND state IN ('saved','draft')",
        [$product],
    );
    foreach ($dates as $r) {
        $y = (int) substr($r["model_date"], 0, 4);
        if (isset($years[$y])) {
            ++$years[$y];
        }
        $key = substr($r["model_date"], 0, 7);
        $months[$key] = ($months[$key] ?? 0) + 1;
    }
    $least = min($years);
    $choices = array_keys(
        array_filter($years, function ($n) use ($least) {
            return $n === $least;
        }),
    );
    $year = (int) $choices[random_int(0, count($choices) - 1)];
    $maxMonth = $year === (int) substr($end, 0, 4) ? (int) substr($end, 5, 2) : 12;
    $buckets = [];
    for ($m = 1; $m <= $maxMonth; $m++) {
        $buckets[$m] = $months[sprintf("%04d-%02d", $year, $m)] ?? 0;
    }
    $least = min($buckets);
    $choices = array_keys(
        array_filter($buckets, function ($n) use ($least) {
            return $n === $least;
        }),
    );
    $month = (int) $choices[random_int(0, count($choices) - 1)];
    $prefix = sprintf("%04d-%02d", $year, $month);
    $days = (int) (new \DateTimeImmutable($prefix . "-01", new \DateTimeZone("UTC")))->format("t");
    if ($prefix === substr($end, 0, 7)) {
        $days = (int) substr($end, 8, 2);
    }
    $occupied = [0, $days + 1];
    foreach ($dates as $r) {
        if (substr($r["model_date"], 0, 7) === $prefix) {
            $occupied[] = (int) substr($r["model_date"], 8, 2);
        }
    }
    $occupied = array_values(array_unique($occupied));
    sort($occupied);
    $gaps = [];
    $largest = 0;
    for ($i = 1; $i < count($occupied); $i++) {
        $length = $occupied[$i] - $occupied[$i - 1] - 1;
        if ($length > $largest) {
            $gaps = [];
            $largest = $length;
        }
        if ($length > 0 && $length === $largest) {
            $gaps[] = [$occupied[$i - 1] + 1, $occupied[$i] - 1];
        }
    }
    if (!$gaps) {
        return $prefix . "-" . sprintf("%02d", random_int(1, $days));
    }
    $gap = $gaps[random_int(0, count($gaps) - 1)];
    $margin = (int) floor(($gap[1] - $gap[0]) / 4);
    return $prefix . "-" . sprintf("%02d", random_int($gap[0] + $margin, $gap[1] - $margin));
}
function simulationFields(\PDO $db, int $product, int $ordinal): array
{
    // Deliberately fictional seed lists, never derived from customer identities.
    $names = [
        "Анна",
        "Ирина",
        "Ольга",
        "Марина",
        "Елена",
        "Наталья",
        "Андрей",
        "Сергей",
        "Алексей",
        "Иван",
        "Дмитрий",
        "Максим",
    ];
    $surnames = [
        "Коваленко",
        "Мельник",
        "Шевченко",
        "Бондаренко",
        "Ткаченко",
        "Петренко",
        "Кравченко",
        "Полищук",
    ];
    $name = $names[random_int(0, count($names) - 1)];
    if (random_int(0, 1)) {
        $name .= " " . $surnames[random_int(0, count($surnames) - 1)];
    }
    $cities = [
        "Одесса",
        "Киев",
        "Львов",
        "Днепр",
        "Харьков",
        "Винница",
        "Полтава",
        "Черкассы",
        "Черновцы",
        "Житомир",
    ];
    $omitCity = round($ordinal * 0.2) > round(($ordinal - 1) * 0.2);
    $guest = round($ordinal * 0.15) > round(($ordinal - 1) * 0.15);
    $stats = one(
        $db,
        "SELECT count(*) n,coalesce(sum(rating),0) total FROM entries WHERE product_id=? AND state='saved'",
        [$product],
    );
    $n = (int) $stats["n"];
    $sum = (int) $stats["total"];
    $allowed = [];
    foreach ([3, 4, 5] as $rating) {
        $average = ($sum + $rating) / ($n + 1);
        if ($average >= 4.4 && $average <= 4.8) {
            $allowed[] = $rating;
        }
    }
    if (!$allowed) {
        $allowed = [5];
        $best = INF;
        foreach ([3, 4, 5] as $candidate) {
            $distance = abs(($sum + $candidate) / ($n + 1) - 4.6);
            if ($distance < $best) {
                $allowed = [$candidate];
                $best = $distance;
            }
        }
    }
    $rating = $allowed[random_int(0, count($allowed) - 1)];
    return [
        "model_author" => $name,
        "model_city" => $omitCity ? "" : $cities[random_int(0, count($cities) - 1)],
        "model_date" => simulationDate($db, $product),
        "rating" => $rating,
        "mood" => [3 => "Нейтральное", 4 => "Хорошее", 5 => "Отличное"][$rating],
        "registration" => $guest ? "model_guest" : "model_verified",
    ];
}
function simulationBody(array $product, array $fields): string
{
    $scenarios = [
        "Проверка короткой карточки: название товара, текст, звёзды и дата должны отображаться без ошибок.",
        "Проверка переноса строк и отображения автора на мобильном экране. Это искусственный пример для проверки формы.",
        "Проверка карточки с описанием упаковки и способа применения. Реальный покупатель этот текст не писал.",
        "Проверка длинного текста: карточка должна сохраняться после обновления страницы, а следующий товар открываться без ручного поиска. Данные созданы автоматически для закрытого стенда.",
    ];
    return "[ТЕСТОВЫЙ ОТЗЫВ · СИНТЕТИЧЕСКИЕ ДАННЫЕ] Товар: «" .
        $product["name"] .
        "». " .
        $scenarios[random_int(0, count($scenarios) - 1)] .
        " Модельное настроение: " .
        $fields["mood"] .
        ".";
}
function simulationPool(\PDO $core, \PDO $db): array
{
    $saved = [];
    foreach (
        rows(
            $db,
            "SELECT product_id,count(*) n,sum(rating) total,max(saved_at) last_saved FROM entries WHERE state='saved' GROUP BY product_id",
        )
        as $r
    ) {
        $saved[$r["product_id"]] = $r;
    }
    $busy = [];
    foreach (rows($db, "SELECT product_id FROM entries WHERE state='draft'") as $r) {
        $busy[$r["product_id"]] = true;
    }
    $weights = json_decode(one($core, "SELECT value FROM settings WHERE key='weights'")["value"], true);
    $pool = [];
    foreach (
        rows(
            $core,
            "SELECT p.id,p.name,p.description,p.image,p.url,p.category,p.boost,q.target,q.depth,q.components FROM products p LEFT JOIN queue q ON q.product_id=p.id WHERE p.active=1 AND (p.stock IS NULL OR p.stock>0)",
        )
        as $p
    ) {
        if (isset($busy[$p["id"]])) {
            continue;
        }
        $s = $saved[$p["id"]] ?? ["n" => 0, "total" => 0, "last_saved" => null];
        $target = max(3, (int) ($p["target"] ?? 3));
        $parts = json_decode($p["components"] ?? "{}", true);
        $parts["gap"] = 100 * max(0, 1 - (int) $s["n"] / $target);
        $score = 0;
        $weight = 0;
        foreach ($weights as $key => $w) {
            if (isset($parts[$key])) {
                $score += (float) $parts[$key] * $w;
                $weight += $w;
            }
        }
        $p["priority"] = round(($weight ? $score / $weight : 0) + (float) $p["boost"], 2);
        $p["model_count"] = (int) $s["n"];
        $p["model_average"] = $s["n"] ? (float) $s["total"] / (int) $s["n"] : null;
        $p["last_saved"] = $s["last_saved"];
        $p["target"] = $target;
        $pool[] = $p;
    }
    $fresh = array_filter($pool, function ($p) {
        return !$p["last_saved"] || strtotime($p["last_saved"]) < time() - 3600;
    });
    if ($fresh) {
        $pool = array_values($fresh);
    }
    usort($pool, function ($a, $b) {
        if (
            $a["last_saved"] &&
            $b["last_saved"] &&
            strtotime($a["last_saved"]) >= time() - 3600 &&
            strtotime($b["last_saved"]) >= time() - 3600
        ) {
            return strcmp($a["last_saved"], $b["last_saved"]) ?: (int) $a["id"] <=> (int) $b["id"];
        }
        return $b["priority"] <=> $a["priority"] ?: (int) $a["id"] <=> (int) $b["id"];
    });
    return $pool;
}
function simulationClaim(\PDO $core, array $user, int $count = 1): array
{
    requireSimulationUser($user);
    integer($count, 1, 10);
    if (!in_array($count, [1, 5, 10], true)) {
        throw new Problem("Размер пакета: 1, 5 или 10");
    }
    $db = simulationDb($core);
    return simulationTransaction($core, $db, function () use ($core, $db, $user, $count) {
        $drafts = rows(
            $db,
            "SELECT id FROM entries WHERE operator_id=? AND state='draft' ORDER BY seq",
            [$user["id"]],
        );
        $need = max(0, $count - count($drafts));
        foreach (array_slice(simulationPool($core, $db), 0, $need) as $p) {
            $ordinal = (int) one($db, "SELECT coalesce(max(seq),0)+1 n FROM entries")["n"];
            $f = simulationFields($db, (int) $p["id"], $ordinal);
            $id = bin2hex(random_bytes(16));
            execute(
                $db,
                "INSERT INTO entries(id,product_id,product_name,operator_id,model_author,model_city,model_date,rating,mood,registration,body,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)",
                [
                    $id,
                    $p["id"],
                    $p["name"],
                    $user["id"],
                    $f["model_author"],
                    $f["model_city"],
                    $f["model_date"],
                    $f["rating"],
                    $f["mood"],
                    $f["registration"],
                    simulationBody($p, $f),
                    now(),
                ],
            );
            simulationEvent($db, $user, $id, "issued");
            $drafts[] = ["id" => $id];
        }
        return ["ids" => array_column($drafts, "id"), "label" => SIMULATION_LABEL];
    });
}
function simulationEntry(\PDO $core, array $user, string $id): array
{
    requireSimulationUser($user);
    $db = simulationDb($core);
    $e = one($db, "SELECT * FROM entries WHERE id=? AND operator_id=?", [$id, $user["id"]]);
    if (!$e) {
        throw new Problem("Тестовая карточка не найдена", 404);
    }
    $p = one($core, "SELECT id,name,description,category,image,url FROM products WHERE id=?", [
        $e["product_id"],
    ]);
    if (!$p) {
        $p = [
            "id" => (int) $e["product_id"],
            "name" => $e["product_name"],
            "description" => "Товар больше не входит в текущий каталог.",
            "category" => "",
            "image" => "",
            "url" => "",
        ];
    }
    $stats = one(
        $db,
        "SELECT count(*) count,avg(rating) average FROM entries WHERE product_id=? AND state='saved'",
        [$e["product_id"]],
    );
    $q = one($core, "SELECT target,depth,priority FROM queue WHERE product_id=?", [$e["product_id"]]);
    return [
        "entry" => $e,
        "product" => $p,
        "model_stats" => $stats,
        "guidance" => $q ?? ["target" => 3, "depth" => "normal", "priority" => null],
        "sources" => rows(
            $core,
            "SELECT source_id,url,review_count,average_rating,last_success,error,confirmed FROM competitors WHERE product_id=? ORDER BY source_id",
            [$e["product_id"]],
        ),
        "label" => SIMULATION_LABEL,
    ];
}
function simulationSave(\PDO $core, array $user, string $id, array $body, bool $finish = false): array
{
    requireSimulationUser($user);
    $db = simulationDb($core);
    $text = text($body["body"] ?? "", 10000, $finish);
    $revision = integer($body["revision"] ?? null, 1, 2147483647);
    return simulationTransaction($core, $db, function () use (
        $db,
        $user,
        $id,
        $text,
        $revision,
        $finish
    ) {
        $e = one($db, "SELECT * FROM entries WHERE id=? AND operator_id=?", [$id, $user["id"]]);
        if (!$e) {
            throw new Problem("Тестовая карточка не найдена", 404);
        }
        if ($finish && $e["state"] === "saved" && $e["body"] === $text) {
            return $e;
        }
        if ($e["state"] !== "draft" || (int) $e["revision"] !== $revision) {
            throw new Problem("Тестовый черновик изменился. Обновите страницу.", 409);
        }
        execute($db, "UPDATE entries SET body=?,state=?,revision=revision+1,saved_at=? WHERE id=?", [
            $text,
            $finish ? "saved" : "draft",
            $finish ? now() : null,
            $id,
        ]);
        simulationEvent($db, $user, $id, $finish ? "saved" : "draft_saved");
        return one($db, "SELECT * FROM entries WHERE id=?", [$id]);
    });
}
function simulationSkip(\PDO $core, array $user, string $id): array
{
    requireSimulationUser($user);
    $db = simulationDb($core);
    return simulationTransaction($core, $db, function () use ($db, $user, $id) {
        $e = one($db, "SELECT * FROM entries WHERE id=? AND operator_id=?", [$id, $user["id"]]);
        if (!$e || $e["state"] !== "draft") {
            throw new Problem("Тестовая карточка недоступна", 409);
        }
        execute($db, "UPDATE entries SET state='skipped',revision=revision+1 WHERE id=?", [$id]);
        simulationEvent($db, $user, $id, "skipped");
        return ["ok" => true];
    });
}
function simulationPreview(\PDO $core, array $user, int $product): array
{
    requireSimulationUser($user);
    $db = simulationDb($core);
    $p = one($core, "SELECT id,name,image FROM products WHERE id=?", [$product]);
    $entries = rows(
        $db,
        "SELECT model_author,model_city,model_date,rating,mood,registration,body,origin FROM entries WHERE product_id=? AND state='saved' ORDER BY model_date DESC,seq DESC LIMIT 200",
        [$product],
    );
    return ["product" => $p, "entries" => $entries, "label" => SIMULATION_LABEL];
}
function simulationSummary(\PDO $core, array $user): array
{
    requireSimulationUser($user);
    $db = simulationDb($core);
    $stats = one(
        $db,
        "SELECT count(*) total,avg(rating) average,sum(CASE WHEN registration='model_guest' THEN 1 ELSE 0 END) guests,sum(CASE WHEN model_city='' THEN 1 ELSE 0 END) no_city FROM entries WHERE state='saved'",
    );
    return [
        "stats" => $stats,
        "items" => rows(
            $db,
            "SELECT id,product_id,product_name,model_author,model_city,model_date,rating,registration,state,substr(body,1,300) excerpt FROM entries WHERE operator_id=? ORDER BY seq DESC LIMIT 100",
            [$user["id"]],
        ),
        "label" => SIMULATION_LABEL,
    ];
}

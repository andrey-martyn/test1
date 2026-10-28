<?php
declare(strict_types=1);
namespace ReviewFactory;

final class Problem extends \RuntimeException
{
    public $status;
    public function __construct(string $message, int $status = 422)
    {
        parent::__construct($message);
        $this->status = $status;
    }
}
function now(): string
{
    return gmdate("Y-m-d\TH:i:s\Z");
}
function json($value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}
function one(\PDO $db, string $sql, array $args = []): ?array
{
    $q = $db->prepare($sql);
    $q->execute($args);
    return $q->fetch(\PDO::FETCH_ASSOC) ?: null;
}
function rows(\PDO $db, string $sql, array $args = []): array
{
    $q = $db->prepare($sql);
    $q->execute($args);
    return $q->fetchAll(\PDO::FETCH_ASSOC);
}
function execute(\PDO $db, string $sql, array $args = []): void
{
    $q = $db->prepare($sql);
    $q->execute($args);
}
function transaction(\PDO $db, callable $fn, bool $allowFrozen = false)
{
    $db->exec("BEGIN IMMEDIATE");
    try {
        if (!$allowFrozen && one($db, "SELECT value FROM settings WHERE key='maintenance'")) {
            throw new Problem("Идёт перенос данных. Запись временно приостановлена.", 503);
        }
        $value = $fn();
        $db->exec("COMMIT");
        return $value;
    } catch (\Throwable $e) {
        $db->exec("ROLLBACK");
        throw $e;
    }
}
function text($value, int $max, bool $required = false): string
{
    if (
        !is_string($value) ||
        !mb_check_encoding($value, "UTF-8") ||
        mb_strlen($value) > $max ||
        preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)
    ) {
        throw new Problem("Некорректное текстовое поле");
    }
    $value = trim($value);
    if ($required && $value === "") {
        throw new Problem("Заполните обязательное поле");
    }
    return $value;
}
function enum($value, array $allowed): string
{
    if (!is_string($value) || !in_array($value, $allowed, true)) {
        throw new Problem("Недопустимое значение");
    }
    return $value;
}
function integer($value, int $min, int $max): int
{
    if (!is_int($value) || $value < $min || $value > $max) {
        throw new Problem("Некорректное число");
    }
    return $value;
}
function audit(\PDO $db, ?int $userId, string $action, string $entity, array $detail = []): void
{
    execute($db, "INSERT INTO audit(user_id,action,entity,detail,created_at) VALUES(?,?,?,?,?)", [
        $userId,
        $action,
        $entity,
        json($detail),
        now(),
    ]);
}
function database(string $directory): \PDO
{
    if (!is_dir($directory) || is_link($directory)) {
        throw new Problem("Закрытый каталог не настроен", 503);
    }
    $path = $directory . "/factory.sqlite3";
    if (is_link($path)) {
        throw new Problem("Недопустимый путь базы", 503);
    }
    $db = new \PDO("sqlite:" . $path, null, null, [
        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
    ]);
    chmod($path, 0600);
    $db->exec("PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000; PRAGMA journal_mode=WAL;");
    $db->exec("CREATE TABLE IF NOT EXISTS users(id INTEGER PRIMARY KEY,login TEXT NOT NULL UNIQUE COLLATE NOCASE,name TEXT NOT NULL,staff_role TEXT NOT NULL,role TEXT NOT NULL CHECK(role IN ('admin','author','moderator')),hash TEXT NOT NULL,active INTEGER NOT NULL DEFAULT 1,revision INTEGER NOT NULL DEFAULT 1);
      CREATE TABLE IF NOT EXISTS sessions(token TEXT PRIMARY KEY,user_id INTEGER NOT NULL REFERENCES users(id),csrf TEXT NOT NULL,revision INTEGER NOT NULL,expires INTEGER NOT NULL);
      CREATE TABLE IF NOT EXISTS attempts(client TEXT NOT NULL,moment INTEGER NOT NULL);
      CREATE INDEX IF NOT EXISTS attempts_moment ON attempts(moment);
      CREATE TABLE IF NOT EXISTS products(id INTEGER PRIMARY KEY,model TEXT NOT NULL DEFAULT '',sku TEXT NOT NULL DEFAULT '',ean TEXT NOT NULL DEFAULT '',name TEXT NOT NULL,description TEXT NOT NULL DEFAULT '',category TEXT NOT NULL DEFAULT '',image TEXT NOT NULL DEFAULT '',url TEXT NOT NULL,active INTEGER NOT NULL DEFAULT 1,stock INTEGER,customer_count INTEGER,customer_rating REAL,sales90 REAL,views30 REAL,updated_at TEXT NOT NULL,cooldown_until TEXT,boost REAL NOT NULL DEFAULT 0,boost_reason TEXT NOT NULL DEFAULT '');
      CREATE TABLE IF NOT EXISTS shop_reviews(id INTEGER PRIMARY KEY,product_id INTEGER NOT NULL REFERENCES products(id),origin TEXT NOT NULL,author TEXT NOT NULL,text TEXT NOT NULL,rating INTEGER NOT NULL,published_at TEXT NOT NULL);
      CREATE TABLE IF NOT EXISTS competitors(product_id INTEGER NOT NULL REFERENCES products(id),source_id TEXT NOT NULL,url TEXT NOT NULL,confirmed INTEGER NOT NULL DEFAULT 0,match_method TEXT,review_count INTEGER,average_rating REAL,samples TEXT NOT NULL DEFAULT '[]',last_success TEXT,last_attempt TEXT,error TEXT,next_check TEXT NOT NULL,PRIMARY KEY(product_id,source_id));
      CREATE TABLE IF NOT EXISTS competitor_history(id INTEGER PRIMARY KEY,product_id INTEGER NOT NULL REFERENCES products(id),source_id TEXT NOT NULL,url TEXT NOT NULL,payload TEXT NOT NULL,recorded_at TEXT NOT NULL);
      CREATE TABLE IF NOT EXISTS drafts(id TEXT PRIMARY KEY,product_id INTEGER NOT NULL REFERENCES products(id),author_id INTEGER NOT NULL REFERENCES users(id),author_label TEXT NOT NULL DEFAULT '',staff_role TEXT NOT NULL DEFAULT '',rating INTEGER,sentiment TEXT NOT NULL DEFAULT 'positive',body TEXT NOT NULL DEFAULT '',experience_date TEXT NOT NULL DEFAULT '',city TEXT NOT NULL DEFAULT '',state TEXT NOT NULL DEFAULT 'draft' CHECK(state IN ('draft','submitted','moderation','published','rejected','historical')),revision INTEGER NOT NULL DEFAULT 1,review_id INTEGER,created_at TEXT NOT NULL,updated_at TEXT NOT NULL,rejection TEXT NOT NULL DEFAULT '');
      CREATE UNIQUE INDEX IF NOT EXISTS one_open_draft ON drafts(product_id,author_id) WHERE state IN ('draft','submitted','moderation');
      CREATE TABLE IF NOT EXISTS assignments(product_id INTEGER PRIMARY KEY REFERENCES products(id),user_id INTEGER NOT NULL REFERENCES users(id),assigned_at TEXT NOT NULL);
      CREATE TABLE IF NOT EXISTS queue(product_id INTEGER PRIMARY KEY REFERENCES products(id),priority REAL NOT NULL,components TEXT NOT NULL,target INTEGER NOT NULL,depth TEXT NOT NULL,calculated_at TEXT NOT NULL);
      CREATE TABLE IF NOT EXISTS outbox(id INTEGER PRIMARY KEY,draft_id TEXT NOT NULL UNIQUE REFERENCES drafts(id),state TEXT NOT NULL DEFAULT 'pending',attempts INTEGER NOT NULL DEFAULT 0,last_error TEXT,updated_at TEXT NOT NULL);
      CREATE TABLE IF NOT EXISTS settings(key TEXT PRIMARY KEY,value TEXT NOT NULL);
      CREATE TABLE IF NOT EXISTS audit(id INTEGER PRIMARY KEY,user_id INTEGER,action TEXT NOT NULL,entity TEXT NOT NULL,detail TEXT NOT NULL,created_at TEXT NOT NULL);
      CREATE INDEX IF NOT EXISTS drafts_author ON drafts(author_id,state);
      CREATE INDEX IF NOT EXISTS reviews_product ON shop_reviews(product_id);
      CREATE TABLE IF NOT EXISTS reply_reviews(id INTEGER PRIMARY KEY,product_id INTEGER NOT NULL,product_name TEXT NOT NULL,author TEXT NOT NULL,body TEXT NOT NULL,plus_text TEXT NOT NULL DEFAULT '',minus_text TEXT NOT NULL DEFAULT '',rating INTEGER NOT NULL,status INTEGER NOT NULL,published_at TEXT NOT NULL,shop_reply TEXT NOT NULL,context_hash TEXT NOT NULL,source_database TEXT NOT NULL,available INTEGER NOT NULL DEFAULT 1,search_text TEXT NOT NULL,updated_at TEXT NOT NULL);
      CREATE TABLE IF NOT EXISTS reply_drafts(id TEXT PRIMARY KEY,review_id INTEGER NOT NULL,user_id INTEGER NOT NULL REFERENCES users(id),body TEXT NOT NULL,base_reply TEXT NOT NULL,context_hash TEXT NOT NULL,source_database TEXT NOT NULL,state TEXT NOT NULL DEFAULT 'draft',revision INTEGER NOT NULL DEFAULT 1,created_at TEXT NOT NULL,updated_at TEXT NOT NULL);
      CREATE UNIQUE INDEX IF NOT EXISTS reply_one_open ON reply_drafts(review_id) WHERE state IN ('draft','queued');
      CREATE TABLE IF NOT EXISTS reply_jobs(id INTEGER PRIMARY KEY,draft_id TEXT NOT NULL UNIQUE REFERENCES reply_drafts(id),payload TEXT NOT NULL,state TEXT NOT NULL DEFAULT 'pending',error TEXT,attempts INTEGER NOT NULL DEFAULT 0,updated_at TEXT NOT NULL);");
    // Additive migration; existing accounts, credentials and drafts stay intact.
    $db->exec("BEGIN IMMEDIATE");
    try {
        if (!in_array("work_mode", array_column(rows($db, "PRAGMA table_info(users)"), "name"), true)) {
            $db->exec("ALTER TABLE users ADD COLUMN work_mode TEXT NOT NULL DEFAULT 'author'");
        }
        if (!one($db, "SELECT value FROM settings WHERE key='reply_workspace_v1'")) {
            execute(
                $db,
                "UPDATE users SET work_mode='replies' WHERE role='moderator' OR (role='admin' AND lower(login) IN ('admin-review','tanya'))",
            );
            execute(
                $db,
                "DELETE FROM assignments WHERE user_id IN (SELECT id FROM users WHERE work_mode='replies')",
            );
            execute($db, "INSERT INTO settings VALUES('reply_workspace_v1','1')");
        }
        $db->exec("COMMIT");
    } catch (\Throwable $e) {
        $db->exec("ROLLBACK");
        throw $e;
    }
    execute($db, "INSERT OR IGNORE INTO settings VALUES('weights',?)", [
        json(["competitor" => 55, "gap" => 20, "sales" => 15, "views" => 10]),
    ]);
    execute($db, "INSERT OR IGNORE INTO settings VALUES('cooldown_days','14')");
    execute($db, "INSERT OR IGNORE INTO settings VALUES('environment','test')");
    return $db;
}
function safeUser(array $user): array
{
    return array_intersect_key(
        $user,
        array_flip(["id", "login", "name", "staff_role", "role", "active", "work_mode"]),
    );
}
function requireRole(array $user, array $roles): void
{
    if (!in_array($user["role"], $roles, true)) {
        throw new Problem("Недостаточно прав", 403);
    }
}
function requireAuthoring(array $user): void
{
    if (($user["work_mode"] ?? "author") === "replies") {
        throw new Problem("Эта учётная запись предназначена для ответов магазина", 403);
    }
}
function createUser(\PDO $db, array $body, ?int $actor = null): array
{
    $login = text($body["login"] ?? "", 64, true);
    if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{2,63}$/D', $login)) {
        throw new Problem("Логин: 3–64 латинских символа, цифры, точка, дефис");
    }
    $name = text($body["name"] ?? "", 40, true);
    $staffRole = text($body["staff_role"] ?? "", 30, true);
    // Native OpenCart review.author is VARCHAR(64).
    if (mb_strlen($name . " · " . $staffRole . " Лавки") > 64) {
        throw new Problem("Сократите имя или должность для подписи");
    }
    $role = enum($body["role"] ?? "", ["admin", "author", "moderator"]);
    $mode = enum($body["work_mode"] ?? "auto", ["auto", "author", "replies"]);
    if ($mode === "auto") {
        $mode = $role === "author" ? "author" : "replies";
    }
    if ($role === "author" && $mode === "replies") {
        throw new Problem("Для ответов нужна роль администратора или редактора");
    }
    $password = text($body["password"] ?? "", 512, true);
    if (mb_strlen($password) < 12) {
        throw new Problem("Пароль должен содержать минимум 12 символов");
    }
    if (one($db, "SELECT id FROM users WHERE login=?", [$login])) {
        throw new Problem("Логин уже занят", 409);
    }
    $hash = password_hash($password, PASSWORD_ARGON2ID, [
        "memory_cost" => 32768,
        "time_cost" => 3,
        "threads" => 1,
    ]);
    return transaction($db, function () use (
        $db,
        $login,
        $name,
        $staffRole,
        $role,
        $hash,
        $actor,
        $mode
    ) {
        if (one($db, "SELECT id FROM users WHERE login=?", [$login])) {
            throw new Problem("Логин уже занят", 409);
        }
        execute(
            $db,
            "INSERT INTO users(login,name,staff_role,role,hash,work_mode) VALUES(?,?,?,?,?,?)",
            [$login, $name, $staffRole, $role, $hash, $mode],
        );
        $id = (int) $db->lastInsertId();
        audit($db, $actor, "user.created", (string) $id, ["role" => $role]);
        return safeUser(one($db, "SELECT * FROM users WHERE id=?", [$id]));
    });
}
function login(\PDO $db, string $login, string $password, string $ip): array
{
    $client = hash("sha256", $ip);
    $account = hash("sha256", "login:" . mb_strtolower($login));
    $stamp = time();
    execute($db, "DELETE FROM attempts WHERE moment<?", [$stamp - 300]);
    execute($db, "DELETE FROM sessions WHERE expires<?", [$stamp]);
    $blocked = one($db, "SELECT count(*) n FROM attempts WHERE client IN (?,?)", [$client, $account]);
    if ((int) $blocked["n"] >= 10 || (int) one($db, "SELECT count(*) n FROM attempts")["n"] >= 300) {
        throw new Problem("Слишком много попыток. Повторите через пять минут.", 429);
    }
    $user = one($db, "SELECT * FROM users WHERE login=?", [$login]);
    $dummy = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi';
    if (!password_verify($password, $user["hash"] ?? $dummy) || !$user || !$user["active"]) {
        execute($db, "INSERT INTO attempts VALUES(?,?),(?,?)", [$client, $stamp, $account, $stamp]);
        throw new Problem("Неверный логин или пароль", 401);
    }
    $token = bin2hex(random_bytes(32));
    $csrf = bin2hex(random_bytes(32));
    execute($db, "INSERT INTO sessions VALUES(?,?,?,?,?)", [
        hash("sha256", $token),
        $user["id"],
        $csrf,
        $user["revision"],
        $stamp + 43200,
    ]);
    execute($db, "DELETE FROM attempts WHERE client IN (?,?)", [$client, $account]);
    audit($db, (int) $user["id"], "session.login", (string) $user["id"]);
    return ["token" => $token, "csrf" => $csrf, "user" => safeUser($user)];
}
function session(\PDO $db, string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/D', $token)) {
        return null;
    }
    return one(
        $db,
        "SELECT u.*,s.csrf FROM users u JOIN sessions s ON s.user_id=u.id AND s.revision=u.revision WHERE s.token=? AND s.expires>? AND u.active=1",
        [hash("sha256", $token), time()],
    );
}
function wordCount(string $body): int
{
    preg_match_all("/[\pL\pN]+(?:[’\x{0027}-][\pL\pN]+)*/u", $body, $m);
    return count($m[0]);
}
function depth(string $body): string
{
    $n = wordCount($body);
    return $n <= 30 ? "short" : ($n <= 100 ? "normal" : "deep");
}
function normalize(array $values, ?float $value, bool $log = false): ?float
{
    if ($value === null || !$values) {
        return null;
    }
    sort($values, SORT_NUMERIC);
    $cap = (float) $values[(int) ceil(0.95 * count($values)) - 1];
    if ($cap <= 0) {
        return 0.0;
    }
    return min(100.0, 100 * ($log ? log1p(max(0, $value)) / log1p($cap) : max(0, $value) / $cap));
}
function competitorReference(array $counts): ?float
{
    $counts = array_values(
        array_filter($counts, function ($x) {
            return $x !== null;
        }),
    );
    if (!$counts) {
        return null;
    }
    rsort($counts, SORT_NUMERIC);
    return array_sum(array_slice($counts, 0, 2)) / min(2, count($counts));
}
function rebuildQueue(\PDO $db): void
{
    $products = rows($db, "SELECT * FROM products WHERE active=1");
    $all = [];
    $pop = ["competitor" => [], "sales" => [], "views" => []];
    $weights = json_decode(one($db, "SELECT value FROM settings WHERE key='weights'")["value"], true);
    foreach ($products as $p) {
        $cs = rows(
            $db,
            "SELECT review_count FROM competitors WHERE product_id=? AND confirmed=1 AND last_success>=? AND error IS NULL",
            [$p["id"], gmdate("Y-m-d\TH:i:s\Z", time() - 172800)],
        );
        $ref = competitorReference(array_column($cs, "review_count"));
        $materials = rows(
            $db,
            "SELECT body FROM drafts WHERE product_id=? AND state IN ('submitted','moderation','published')",
            [$p["id"]],
        );
        $historical = rows(
            $db,
            "SELECT text AS body FROM shop_reviews WHERE product_id=? AND origin='staff' AND id NOT IN (SELECT review_id FROM drafts WHERE review_id IS NOT NULL)",
            [$p["id"]],
        );
        $materials = array_merge($materials, $historical);
        $target = $ref === null ? 3 : max(3, min(10, (int) ceil(sqrt($ref))));
        $short = 0;
        $normal = 0;
        $deep = 0;
        foreach ($materials as $m) {
            $d = depth($m["body"]);
            if ($d === "short") {
                ++$short;
            } elseif ($d === "normal") {
                ++$normal;
            } else {
                ++$deep;
            }
        }
        $counts = ["short" => $short, "normal" => $normal, "deep" => $deep];
        $desired = ["short" => 0.4, "normal" => 0.4, "deep" => 0.2];
        $recommended = "short";
        $largest = -INF;
        foreach ($desired as $d => $ratio) {
            $deficit = $ratio * (count($materials) + 1) - $counts[$d];
            if ($deficit > $largest) {
                $largest = $deficit;
                $recommended = $d;
            }
        }
        $all[$p["id"]] = [
            "p" => $p,
            "ref" => $ref,
            "target" => $target,
            "count" => count($materials),
            "depth" => $recommended,
        ];
        foreach (
            ["competitor" => $ref, "sales" => $p["sales90"], "views" => $p["views30"]]
            as $k => $v
        ) {
            if ($v !== null) {
                $pop[$k][] = (float) $v;
            }
        }
    }
    transaction($db, function () use ($db, $all, $pop, $weights) {
        $db->exec("DELETE FROM queue");
        foreach ($all as $id => $r) {
            $p = $r["p"];
            $components = [
                "competitor" => normalize($pop["competitor"], $r["ref"], true),
                "gap" => 100 * max(0, 1 - $r["count"] / $r["target"]),
                "sales" => normalize(
                    $pop["sales"],
                    $p["sales90"] === null ? null : (float) $p["sales90"],
                ),
                "views" => normalize(
                    $pop["views"],
                    $p["views30"] === null ? null : (float) $p["views30"],
                ),
            ];
            $total = 0;
            $weight = 0;
            foreach ($components as $k => $v) {
                if ($v !== null) {
                    $total += $v * $weights[$k];
                    $weight += $weights[$k];
                }
            }
            $score = ($weight ? $total / $weight : 0) + (float) $p["boost"];
            execute($db, "INSERT INTO queue VALUES(?,?,?,?,?,?)", [
                $id,
                round($score, 3),
                json($components),
                $r["target"],
                $r["depth"],
                now(),
            ]);
        }
        execute($db, "INSERT OR REPLACE INTO settings VALUES('queue_updated',?)", [now()]);
    });
}
function claim(\PDO $db, array $user, int $count = 1): array
{
    requireAuthoring($user);
    integer($count, 1, 10);
    if (!in_array($count, [1, 5, 10], true)) {
        throw new Problem("Размер пакета: 1, 5 или 10");
    }
    return transaction($db, function () use ($db, $user, $count) {
        $assigned = rows(
            $db,
            "SELECT product_id FROM assignments WHERE user_id=? ORDER BY assigned_at,product_id",
            [$user["id"]],
        );
        $need = max(0, $count - count($assigned));
        if ($need) {
            $candidates = rows(
                $db,
                "SELECT p.id FROM products p JOIN queue q ON q.product_id=p.id LEFT JOIN assignments a ON a.product_id=p.id WHERE p.active=1 AND (p.stock IS NULL OR p.stock>0) AND (p.cooldown_until IS NULL OR p.cooldown_until<=?) AND a.product_id IS NULL AND NOT EXISTS(SELECT 1 FROM drafts d WHERE d.product_id=p.id AND d.author_id=? AND d.state IN ('submitted','moderation','published')) ORDER BY q.priority DESC,p.id LIMIT " .
                    $need,
                [now(), $user["id"]],
            );
            foreach ($candidates as $p) {
                execute($db, "INSERT INTO assignments VALUES(?,?,?)", [$p["id"], $user["id"], now()]);
                $assigned[] = ["product_id" => $p["id"]];
                audit($db, (int) $user["id"], "product.claimed", (string) $p["id"]);
            }
        }
        return array_map(function ($a) {
            return (int) $a["product_id"];
        }, $assigned);
    });
}
function product(\PDO $db, int $id, array $user): array
{
    if (
        !one($db, "SELECT product_id FROM assignments WHERE product_id=? AND user_id=?", [
            $id,
            $user["id"],
        ]) &&
        $user["role"] === "author"
    ) {
        throw new Problem("Товар назначен другому автору", 403);
    }
    $p = one(
        $db,
        "SELECT p.*,q.priority,q.target,q.depth,q.components FROM products p LEFT JOIN queue q ON q.product_id=p.id WHERE p.id=?",
        [$id],
    );
    if (!$p) {
        throw new Problem("Товар не найден", 404);
    }
    $p["components"] = json_decode($p["components"] ?? "{}", true);
    $p["competitors"] = rows(
        $db,
        "SELECT source_id,url,confirmed,match_method,review_count,average_rating,last_success,last_attempt,error,samples FROM competitors WHERE product_id=? ORDER BY source_id",
        [$id],
    );
    foreach ($p["competitors"] as &$c) {
        $c["samples"] = json_decode($c["samples"], true);
        $c["stale"] = !$c["last_success"] || strtotime($c["last_success"]) < time() - 172800;
    }
    unset($c);
    $p["reviews"] = rows(
        $db,
        "SELECT origin,author,text,rating,published_at FROM shop_reviews WHERE product_id=? ORDER BY published_at DESC LIMIT 20",
        [$id],
    );
    $p["draft"] = one($db, "SELECT * FROM drafts WHERE product_id=? AND author_id=? AND state='draft'", [
        $id,
        $user["id"],
    ]);
    $p["guidance"] = guidance($p);
    return $p;
}
function guidance(array $p): array
{
    $themes = [
        "aroma" => "аромат",
        "taste" => "вкус",
        "use" => "способ применения",
        "pack" => "упаковка",
        "storage" => "хранение",
    ];
    $patterns = [
        "aroma" => "аромат|запах|пахн",
        "taste" => "вкус|смак|гореч|гірк",
        "use" => "готов|дода|добав|рецепт|завар",
        "pack" => "упаков|пакет|фасов",
        "storage" => "хран|зберіг",
    ];
    $covered = [];
    foreach ($p["reviews"] as $r) {
        foreach ($patterns as $k => $regex) {
            if (preg_match("/" . $regex . "/iu", $r["text"])) {
                $covered[$k] = true;
            }
        }
    }
    $missing = [];
    foreach ($themes as $k => $label) {
        if (!isset($covered[$k])) {
            $missing[] = $label;
        }
    }
    $signals = ["positive" => [], "critical" => []];
    foreach ($p["competitors"] as $c) {
        if ($c["confirmed"] && !$c["stale"] && !$c["error"]) {
            foreach ($c["samples"] as $sample) {
                $sentences = preg_split('/[.!?\n]+/u', $sample);
                foreach ($sentences as $sentence) {
                    $kind = preg_match(
                        "/не\s+понрав|невкус|слаб|горчит|гірчи|затхл|несвеж|не\s+свеж|плох|поган/iu",
                        $sentence,
                    )
                        ? "critical"
                        : (preg_match(
                            "/понрав|подоба|приятн|приємн|вкусн|смачн|отличн|чудов|свеж|свіж/iu",
                            $sentence,
                        )
                            ? "positive"
                            : null);
                    if (!$kind) {
                        continue;
                    }
                    foreach ($patterns as $key => $regex) {
                        if (preg_match("/" . $regex . "/iu", $sentence)) {
                            $signals[$kind][$key] = $themes[$key];
                        }
                    }
                }
            }
        }
    }
    return [
        "missing" => $missing,
        "signals" => array_map("array_values", $signals),
        "method" =>
            "Поиск ключевых слов в последних 20 опубликованных материалах. Это подсказка, а не полный анализ смысла.",
        "depth" => $p["depth"] ?? "normal",
    ];
}
function saveDraft(\PDO $db, array $user, array $body): array
{
    requireAuthoring($user);
    $productId = integer($body["product_id"] ?? null, 1, 2147483647);
    $p = one($db, "SELECT * FROM products WHERE id=?", [$productId]);
    if (!$p || !$p["active"]) {
        throw new Problem("Товар недоступен", 409);
    }
    if (
        !one($db, "SELECT * FROM assignments WHERE product_id=? AND user_id=?", [
            $productId,
            $user["id"],
        ])
    ) {
        throw new Problem("Сначала возьмите товар в работу", 403);
    }
    $rating = $body["rating"] ?? null;
    if ($rating !== null) {
        integer($rating, 1, 5);
    }
    integer($body["revision"] ?? 0, 0, 2147483647);
    if (
        isset($body["draft_id"]) &&
        (!is_string($body["draft_id"]) || !preg_match('/^[a-f0-9]{32}$/D', $body["draft_id"]))
    ) {
        throw new Problem("Некорректный идентификатор черновика");
    }
    $content = text($body["body"] ?? "", 10000);
    $sentiment = enum($body["sentiment"] ?? "positive", ["positive", "neutral", "mixed", "critical"]);
    $city = text($body["city"] ?? "", 80);
    $experience = text($body["experience_date"] ?? "", 10);
    if (
        $experience !== "" &&
        (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $experience) ||
            !($d = \DateTimeImmutable::createFromFormat(
                "!Y-m-d",
                $experience,
                new \DateTimeZone("UTC"),
            )) ||
            $d->format("Y-m-d") !== $experience ||
            $experience > gmdate("Y-m-d"))
    ) {
        throw new Problem("Укажите фактическую дату опыта не позднее сегодня");
    }
    return transaction($db, function () use (
        $db,
        $user,
        $body,
        $productId,
        $rating,
        $content,
        $sentiment,
        $city,
        $experience
    ) {
        if (!empty($body["draft_id"])) {
            $known = one($db, "SELECT id,state,product_id FROM drafts WHERE id=? AND author_id=?", [
                $body["draft_id"],
                $user["id"],
            ]);
            if (!$known || (int) $known["product_id"] !== $productId) {
                throw new Problem("Черновик не найден", 404);
            }
            if ($known["state"] !== "draft") {
                throw new Problem(
                    "Материал уже отправлен. Его состояние видно в списке материалов.",
                    409,
                );
            }
        }
        $old = one($db, "SELECT * FROM drafts WHERE product_id=? AND author_id=? AND state='draft'", [
            $productId,
            $user["id"],
        ]);
        if ($old) {
            if ((int) ($body["revision"] ?? 0) !== (int) $old["revision"]) {
                throw new Problem("Черновик изменён в другой вкладке. Обновите страницу.", 409);
            }
            execute(
                $db,
                "UPDATE drafts SET rating=?,body=?,sentiment=?,city=?,experience_date=?,revision=revision+1,updated_at=? WHERE id=?",
                [$rating, $content, $sentiment, $city, $experience, now(), $old["id"]],
            );
            $id = $old["id"];
        } else {
            $id = bin2hex(random_bytes(16));
            execute(
                $db,
                "INSERT INTO drafts(id,product_id,author_id,rating,sentiment,body,city,experience_date,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?)",
                [
                    $id,
                    $productId,
                    $user["id"],
                    $rating,
                    $sentiment,
                    $content,
                    $city,
                    $experience,
                    now(),
                    now(),
                ],
            );
        }
        audit($db, (int) $user["id"], "draft.saved", $id);
        return one($db, "SELECT * FROM drafts WHERE id=?", [$id]);
    });
}
function submit(\PDO $db, array $user, string $id, int $revision): array
{
    requireAuthoring($user);
    return transaction($db, function () use ($db, $user, $id, $revision) {
        $d = one($db, "SELECT * FROM drafts WHERE id=? AND author_id=?", [$id, $user["id"]]);
        if (!$d) {
            throw new Problem("Черновик не найден", 404);
        }
        if (in_array($d["state"], ["submitted", "moderation", "published"], true)) {
            return $d;
        }
        if ($d["state"] !== "draft" || (int) $d["revision"] !== $revision) {
            throw new Problem("Черновик изменён. Перечитайте его перед отправкой.", 409);
        }
        if (
            !one($db, "SELECT * FROM assignments WHERE product_id=? AND user_id=?", [
                $d["product_id"],
                $user["id"],
            ])
        ) {
            throw new Problem("Назначение товара изменилось", 409);
        }
        if (!$d["rating"] || wordCount($d["body"]) < 5) {
            throw new Problem("Выберите звёзды и напишите минимум пять слов о своём опыте");
        }
        $p = one($db, "SELECT active FROM products WHERE id=?", [$d["product_id"]]);
        if (!$p || !$p["active"]) {
            throw new Problem("Товар отключён", 409);
        }
        execute(
            $db,
            "UPDATE drafts SET state='submitted',author_label=?,staff_role=?,revision=revision+1,updated_at=? WHERE id=?",
            [$user["name"] . " · " . $user["staff_role"] . " Лавки", $user["staff_role"], now(), $id],
        );
        execute($db, "INSERT INTO outbox(draft_id,updated_at) VALUES(?,?)", [$id, now()]);
        $days = (int) one($db, "SELECT value FROM settings WHERE key='cooldown_days'")["value"];
        execute($db, "UPDATE products SET cooldown_until=? WHERE id=?", [
            gmdate("Y-m-d\TH:i:s\Z", time() + $days * 86400),
            $d["product_id"],
        ]);
        execute($db, "DELETE FROM assignments WHERE product_id=? AND user_id=?", [
            $d["product_id"],
            $user["id"],
        ]);
        audit($db, (int) $user["id"], "draft.submitted", $id);
        return one($db, "SELECT * FROM drafts WHERE id=?", [$id]);
    });
}
function stats(\PDO $db, array $user): array
{
    $where = $user["role"] === "author" ? " WHERE author_id=" . (int) $user["id"] : "";
    $counts = rows($db, "SELECT state,count(*) n FROM drafts" . $where . " GROUP BY state");
    $sentiments = rows($db, "SELECT sentiment,count(*) n FROM drafts" . $where . " GROUP BY sentiment");
    $bodies = rows($db, "SELECT body FROM drafts" . $where);
    $depths = ["short" => 0, "normal" => 0, "deep" => 0];
    $words = 0;
    foreach ($bodies as $d) {
        ++$depths[depth($d["body"])];
        $words += wordCount($d["body"]);
    }
    $summary = [
        "states" => $counts,
        "sentiments" => $sentiments,
        "depths" => $depths,
        "average_words" => count($bodies) ? round($words / count($bodies), 1) : null,
    ];
    if ($user["role"] !== "author") {
        $summary["catalog"] = one(
            $db,
            "SELECT count(*) total,sum(CASE WHEN customer_count=0 THEN 1 ELSE 0 END) without_customer_reviews,sum(CASE WHEN customer_count IS NULL THEN 1 ELSE 0 END) unknown_customer_reviews,sum(CASE WHEN NOT EXISTS(SELECT 1 FROM drafts d WHERE d.product_id=p.id AND d.state IN ('submitted','moderation','published')) AND NOT EXISTS(SELECT 1 FROM shop_reviews s WHERE s.product_id=p.id AND s.origin='staff') THEN 1 ELSE 0 END) without_staff FROM products p WHERE active=1",
        );
        $summary["authors"] = rows(
            $db,
            'SELECT u.name,count(d.id) total,sum(CASE WHEN d.state=\'published\' THEN 1 ELSE 0 END) published FROM users u LEFT JOIN drafts d ON d.author_id=u.id GROUP BY u.id ORDER BY total DESC',
        );
        $summary["top"] = rows(
            $db,
            "SELECT p.id,p.name,q.priority,q.components FROM queue q JOIN products p ON p.id=q.product_id WHERE p.active=1 AND NOT EXISTS(SELECT 1 FROM drafts d WHERE d.product_id=p.id AND d.state IN ('submitted','moderation','published')) ORDER BY q.priority DESC LIMIT 50",
        );
        $all = rows(
            $db,
            "SELECT p.id,p.name,q.components FROM queue q JOIN products p ON p.id=q.product_id WHERE p.active=1",
        );
        foreach ($all as &$p) {
            $c = json_decode($p["components"], true);
            $p["competitor_score"] = $c["competitor"];
        }
        unset($p);
        $all = array_values(
            array_filter($all, function ($p) {
                return $p["competitor_score"] !== null;
            }),
        );
        usort($all, function ($a, $b) {
            return $b["competitor_score"] <=> $a["competitor_score"];
        });
        $summary["top_competitors"] = array_slice($all, 0, 50);
    }
    return $summary;
}

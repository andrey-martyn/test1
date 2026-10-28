<?php
declare(strict_types=1);
namespace ReviewFactory;
require_once __DIR__ . "/Core.php";

function replyContext(array $r): string
{
    return hash(
        "sha256",
        json([
            (int) $r["id"],
            (int) $r["product_id"],
            (string) $r["author"],
            (string) $r["body"],
            (string) ($r["plus_text"] ?? ""),
            (string) ($r["minus_text"] ?? ""),
            (int) $r["rating"],
            (int) $r["status"],
            (string) $r["published_at"],
        ]),
    );
}
function replyPlain(string $value): string
{
    return trim(strip_tags(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, "UTF-8")));
}
function replyBody($value): string
{
    $body = text($value, 8000, true);
    $decoded = $body;
    for ($i = 0; $i < 8; $i++) {
        $next = html_entity_decode($decoded, ENT_QUOTES | ENT_HTML5, "UTF-8");
        if ($next === $decoded) {
            break;
        }
        $decoded = $next;
    }
    if (
        strpos($decoded, "<") !== false ||
        strpos($decoded, ">") !== false ||
        preg_match("/&(?:amp;)*(?:lt|gt|#0*60|#x0*3c);?/i", $decoded)
    ) {
        throw new Problem("Введите ответ обычным текстом, без HTML");
    }
    return $body;
}
function replySource(\PDO $db, int $id): array
{
    $r = one($db, "SELECT * FROM reply_reviews WHERE id=? AND available=1", [$id]);
    if (!$r) {
        throw new Problem("Отзыв не найден в последней загрузке магазина", 404);
    }
    return $r;
}
function replyList(\PDO $db, array $user, array $body = []): array
{
    requireRole($user, ["admin", "moderator"]);
    $filter = enum($body["filter"] ?? "unanswered", ["unanswered", "answered", "all"]);
    $page = integer($body["page"] ?? 1, 1, 100000);
    $search = mb_strtolower(text($body["search"] ?? "", 200));
    $where = "r.available=1";
    $args = [];
    if ($filter === "unanswered") {
        $where .=
            " AND trim(r.shop_reply)='' AND NOT EXISTS(SELECT 1 FROM reply_drafts pending WHERE pending.review_id=r.id AND pending.state='queued')";
    }
    if ($filter === "answered") {
        $where .= " AND trim(r.shop_reply)<>''";
    }
    if ($search !== "") {
        $where .= " AND r.search_text LIKE ? ESCAPE '\\'";
        $args[] = "%" . str_replace(["\\", "%", "_"], ["\\\\", "\\%", "\\_"], $search) . "%";
    }
    $total = (int) one($db, "SELECT count(*) n FROM reply_reviews r WHERE " . $where, $args)["n"];
    $page = min($page, max(1, (int) ceil($total / 50)));
    $items = rows(
        $db,
        "SELECT r.id,r.product_name,r.author,r.rating,r.status,r.published_at,r.shop_reply,substr(r.body,1,350) excerpt,d.user_id draft_user,d.state draft_state,u.name draft_owner FROM reply_reviews r LEFT JOIN reply_drafts d ON d.review_id=r.id AND d.state IN ('draft','queued') LEFT JOIN users u ON u.id=d.user_id WHERE " .
            $where .
            " ORDER BY r.published_at DESC,r.id DESC LIMIT 50 OFFSET " .
            ($page - 1) * 50,
        $args,
    );
    return [
        "items" => $items,
        "total" => $total,
        "page" => $page,
        "pages" => (int) ceil($total / 50),
        "filter" => $filter,
        "updated_at" =>
            one($db, "SELECT value FROM settings WHERE key='replies_updated'")["value"] ?? null,
    ];
}
function replyDetail(\PDO $db, array $user, int $id): array
{
    requireRole($user, ["admin", "moderator"]);
    $r = replySource($db, $id);
    $r["reply_plain"] = replyPlain($r["shop_reply"]);
    $r["draft"] = one(
        $db,
        "SELECT d.*,u.name owner FROM reply_drafts d JOIN users u ON u.id=d.user_id WHERE d.review_id=? AND d.state IN ('draft','queued') ORDER BY d.created_at DESC LIMIT 1",
        [$id],
    );
    if ($r["draft"] && (int) $r["draft"]["user_id"] !== (int) $user["id"]) {
        $r["draft"] = array_intersect_key($r["draft"], array_flip(["user_id", "owner", "state"]));
    }
    $r["recent"] = rows(
        $db,
        "SELECT d.id,d.state,d.body,d.updated_at,j.error FROM reply_drafts d LEFT JOIN reply_jobs j ON j.draft_id=d.id WHERE d.review_id=? AND d.user_id=? ORDER BY d.updated_at DESC LIMIT 5",
        [$id, $user["id"]],
    );
    return $r;
}
function saveReply(\PDO $db, array $user, array $body): array
{
    requireRole($user, ["admin", "moderator"]);
    $id = integer($body["review_id"] ?? null, 1, 2147483647);
    $text = replyBody($body["body"] ?? "");
    $revision = integer($body["revision"] ?? 0, 0, 2147483647);
    $requestId = $body["draft_id"] ?? null;
    if (
        $requestId !== null &&
        (!is_string($requestId) || !preg_match('/^[a-f0-9]{32}$/D', $requestId))
    ) {
        throw new Problem("Неверный черновик ответа");
    }
    return transaction($db, function () use ($db, $user, $id, $text, $revision, $requestId) {
        $source = replySource($db, $id);
        if ($requestId) {
            $known = one($db, "SELECT * FROM reply_drafts WHERE id=? AND user_id=? AND review_id=?", [
                $requestId,
                $user["id"],
                $id,
            ]);
            if (!$known) {
                throw new Problem("Черновик ответа не найден", 404);
            }
            if ($known["state"] !== "draft") {
                throw new Problem("Ответ уже отправлен. Обновите его состояние.", 409);
            }
        }
        $old = one($db, "SELECT * FROM reply_drafts WHERE review_id=? AND state IN ('draft','queued')", [
            $id,
        ]);
        if ($old) {
            if ((int) $old["user_id"] !== (int) $user["id"]) {
                throw new Problem("Ответ уже готовит другой сотрудник", 409);
            }
            if ($old["state"] !== "draft" || (int) $old["revision"] !== $revision) {
                throw new Problem("Черновик изменён или уже отправлен. Обновите страницу.", 409);
            }
            execute($db, "UPDATE reply_drafts SET body=?,revision=revision+1,updated_at=? WHERE id=?", [
                $text,
                now(),
                $old["id"],
            ]);
            $draftId = $old["id"];
        } else {
            $draftId = bin2hex(random_bytes(16));
            execute(
                $db,
                "INSERT INTO reply_drafts(id,review_id,user_id,body,base_reply,context_hash,source_database,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?)",
                [
                    $draftId,
                    $id,
                    $user["id"],
                    $text,
                    $source["shop_reply"],
                    $source["context_hash"],
                    $source["source_database"],
                    now(),
                    now(),
                ],
            );
        }
        audit($db, (int) $user["id"], "reply.draft_saved", $draftId, ["review_id" => $id]);
        return one($db, "SELECT * FROM reply_drafts WHERE id=?", [$draftId]);
    });
}
function queueReply(\PDO $db, array $user, string $id, int $revision): array
{
    requireRole($user, ["admin", "moderator"]);
    return transaction($db, function () use ($db, $user, $id, $revision) {
        $d = one($db, "SELECT * FROM reply_drafts WHERE id=? AND user_id=?", [$id, $user["id"]]);
        if (!$d) {
            throw new Problem("Ответ не найден", 404);
        }
        if (in_array($d["state"], ["queued", "applied"], true)) {
            return $d;
        }
        if ($d["state"] !== "draft" || (int) $d["revision"] !== $revision) {
            throw new Problem("Обновите черновик перед отправкой", 409);
        }
        $s = replySource($db, (int) $d["review_id"]);
        if ($s["context_hash"] !== $d["context_hash"] || $s["shop_reply"] !== $d["base_reply"]) {
            throw new Problem("Отзыв или ответ уже изменился. Откройте актуальные данные.", 409);
        }
        if ($d["body"] === $s["shop_reply"] || $d["body"] === replyPlain($s["shop_reply"])) {
            execute(
                $db,
                "UPDATE reply_drafts SET state='applied',revision=revision+1,updated_at=? WHERE id=?",
                [now(), $id],
            );
            audit($db, (int) $user["id"], "reply.unchanged", $id);
            return one($db, "SELECT * FROM reply_drafts WHERE id=?", [$id]);
        }
        $payload = [
            "review_id" => (int) $s["id"],
            "product_id" => (int) $s["product_id"],
            "author" => $s["author"],
            "body" => $s["body"],
            "plus_text" => $s["plus_text"],
            "minus_text" => $s["minus_text"],
            "rating" => (int) $s["rating"],
            "status" => (int) $s["status"],
            "published_at" => $s["published_at"],
            "base_reply" => $d["base_reply"],
            "reply_body" => $d["body"],
            "source_database" => $d["source_database"],
        ];
        execute($db, "INSERT INTO reply_jobs(draft_id,payload,updated_at) VALUES(?,?,?)", [
            $id,
            json($payload),
            now(),
        ]);
        execute(
            $db,
            "UPDATE reply_drafts SET state='queued',revision=revision+1,updated_at=? WHERE id=?",
            [now(), $id],
        );
        audit($db, (int) $user["id"], "reply.queued", $id, ["review_id" => (int) $s["id"]]);
        return one($db, "SELECT * FROM reply_drafts WHERE id=?", [$id]);
    });
}
function importReplies(\PDO $db, \PDO $shop, string $prefix, int $language): array
{
    integer($language, 1, 9999);
    $sourceDatabase = $shop->query("SELECT DATABASE()")->fetchColumn();
    if (!in_array($sourceDatabase, ["lavkasp_test", "lavkasp_lavka"], true)) {
        throw new Problem("reply_database_mismatch");
    }
    $start = microtime(true);
    $shop->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
    $query = null;
    try {
        $query = $shop->prepare(
            "SELECT r.review_id id,r.product_id,pd.name product_name,r.author,r.text body,r.`plus` plus_text,r.`minus` minus_text,r.rating,r.status,r.date_added published_at,r.admin_reply shop_reply FROM `{$prefix}review` r JOIN `{$prefix}product_to_store` ps ON ps.product_id=r.product_id AND ps.store_id=0 JOIN `{$prefix}product_description` pd ON pd.product_id=r.product_id AND pd.language_id=? ORDER BY r.review_id LIMIT 100001",
        );
        $query->execute([$language]);
        $count = transaction($db, function () use ($db, $query, $sourceDatabase, $start) {
            $count = 0;
            execute($db, "UPDATE reply_reviews SET available=0");
            while ($r = $query->fetch(\PDO::FETCH_ASSOC)) {
                if (++$count > 100000 || microtime(true) - $start > 45) {
                    throw new Problem("reply_import_limit");
                }
                $r["shop_reply"] = (string) $r["shop_reply"];
                execute(
                    $db,
                    "INSERT INTO reply_reviews(id,product_id,product_name,author,body,plus_text,minus_text,rating,status,published_at,shop_reply,context_hash,source_database,available,search_text,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,1,?,?) ON CONFLICT(id) DO UPDATE SET product_id=excluded.product_id,product_name=excluded.product_name,author=excluded.author,body=excluded.body,plus_text=excluded.plus_text,minus_text=excluded.minus_text,rating=excluded.rating,status=excluded.status,published_at=excluded.published_at,shop_reply=excluded.shop_reply,context_hash=excluded.context_hash,source_database=excluded.source_database,available=1,search_text=excluded.search_text,updated_at=excluded.updated_at",
                    [
                        $r["id"],
                        $r["product_id"],
                        $r["product_name"],
                        $r["author"],
                        $r["body"],
                        $r["plus_text"],
                        $r["minus_text"],
                        $r["rating"],
                        $r["status"],
                        $r["published_at"],
                        $r["shop_reply"],
                        replyContext($r),
                        $sourceDatabase,
                        mb_strtolower(
                            $r["product_name"] .
                                " " .
                                $r["author"] .
                                " " .
                                mb_substr(
                                    $r["body"] . " " . $r["plus_text"] . " " . $r["minus_text"],
                                    0,
                                    5000,
                                ),
                        ),
                        now(),
                    ],
                );
            }
            execute($db, "INSERT OR REPLACE INTO settings VALUES('replies_updated',?)", [now()]);
            return $count;
        });
        return ["reviews" => $count];
    } finally {
        if ($query) {
            $query->closeCursor();
        }
        $shop->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
    }
}
/** One compare-and-set UPDATE; compatible with MyISAM and InnoDB. */
function applyReply(\PDO $shop, string $prefix, array $job): string
{
    if (
        !in_array($job["source_database"], ["lavkasp_test", "lavkasp_lavka"], true) ||
        $shop->query("SELECT DATABASE()")->fetchColumn() !== $job["source_database"]
    ) {
        throw new Problem("reply_database_mismatch");
    }
    $reply = replyBody($job["reply_body"]);
    $current = one(
        $shop,
        "SELECT review_id id,product_id,author,text body,`plus` plus_text,`minus` minus_text,rating,status,date_added published_at,admin_reply shop_reply FROM `{$prefix}review` WHERE review_id=?",
        [$job["review_id"]],
    );
    if (!$current) {
        throw new Problem("reply_review_removed");
    }
    if ($current["shop_reply"] === $reply) {
        return "already_present";
    }
    $expected = ["id" => $job["review_id"]] + $job;
    if (
        replyContext($current) !== replyContext($expected) ||
        $current["shop_reply"] !== $job["base_reply"]
    ) {
        throw new Problem("reply_conflict");
    }
    // No changes to text, author, rating, status, dates, or product association.
    $q = $shop->prepare(
        "UPDATE `{$prefix}review` SET admin_reply=? WHERE review_id=? AND product_id=? AND BINARY author=BINARY ? AND BINARY text=BINARY ? AND BINARY `plus`=BINARY ? AND BINARY `minus`=BINARY ? AND rating=? AND status=? AND date_added=? AND BINARY admin_reply=BINARY ?",
    );
    $q->execute([
        $reply,
        $job["review_id"],
        $job["product_id"],
        $job["author"],
        $job["body"],
        $job["plus_text"],
        $job["minus_text"],
        $job["rating"],
        $job["status"],
        $job["published_at"],
        $job["base_reply"],
    ]);
    if ($q->rowCount() === 1) {
        return "applied";
    }
    $after = one($shop, "SELECT admin_reply FROM `{$prefix}review` WHERE review_id=?", [
        $job["review_id"],
    ]);
    if ($after && $after["admin_reply"] === $reply) {
        return "already_present";
    }
    throw new Problem("reply_conflict");
}
function processReplies(\PDO $db, \PDO $shop, string $prefix, int $limit = 3): array
{
    integer($limit, 1, 10);
    $summary = ["applied" => 0, "failed" => 0, "conflicts" => 0];
    foreach (
        rows(
            $db,
            "SELECT j.*,d.user_id FROM reply_jobs j JOIN reply_drafts d ON d.id=j.draft_id WHERE j.state='pending' ORDER BY j.id LIMIT " .
                $limit,
        )
        as $j
    ) {
        $payload = json_decode($j["payload"], true, 32, JSON_THROW_ON_ERROR);
        try {
            $user = one($db, "SELECT * FROM users WHERE id=? AND active=1", [$j["user_id"]]);
            if (!$user || !in_array($user["role"], ["admin", "moderator"], true)) {
                throw new Problem("reply_author_disabled");
            }
            $result = applyReply($shop, $prefix, $payload);
            $snapshot = one(
                $shop,
                "SELECT review_id id,product_id,author,text body,`plus` plus_text,`minus` minus_text,rating,status,date_added published_at,admin_reply shop_reply FROM `{$prefix}review` WHERE review_id=?",
                [$payload["review_id"]],
            );
            transaction($db, function () use ($db, $j, $payload, $result, $snapshot) {
                execute(
                    $db,
                    "UPDATE reply_jobs SET state='done',error=NULL,attempts=attempts+1,updated_at=? WHERE id=?",
                    [now(), $j["id"]],
                );
                execute($db, "UPDATE reply_drafts SET state='applied',updated_at=? WHERE id=?", [
                    now(),
                    $j["draft_id"],
                ]);
                cacheReplySnapshot($db, $payload, $snapshot);
                audit($db, (int) $j["user_id"], "reply.applied", $j["draft_id"], [
                    "review_id" => $payload["review_id"],
                    "result" => $result,
                ]);
            });
            ++$summary["applied"];
        } catch (\Throwable $e) {
            $code = $e instanceof Problem ? $e->getMessage() : "reply_shop_unavailable";
            if (
                $e instanceof \PDOException &&
                in_array((int) ($e->errorInfo[1] ?? 0), [1366, 1406], true)
            ) {
                $code = "reply_text_invalid";
            }
            if (!preg_match('/^[a-z_]{1,60}$/D', $code)) {
                $code = "reply_shop_unavailable";
            }
            $terminal = in_array(
                $code,
                [
                    "reply_conflict",
                    "reply_review_removed",
                    "reply_database_mismatch",
                    "reply_author_disabled",
                    "reply_text_invalid",
                ],
                true,
            );
            $state = $terminal ? "conflict" : "pending";
            $snapshot = null;
            $refresh = in_array($code, ["reply_conflict", "reply_review_removed"], true);
            if ($refresh) {
                try {
                    $snapshot = one(
                        $shop,
                        "SELECT review_id id,product_id,author,text body,`plus` plus_text,`minus` minus_text,rating,status,date_added published_at,admin_reply shop_reply FROM `{$prefix}review` WHERE review_id=?",
                        [$payload["review_id"]],
                    );
                } catch (\Throwable $ignored) {
                    $refresh = false;
                }
            }
            transaction($db, function () use (
                $db,
                $j,
                $code,
                $state,
                $terminal,
                $refresh,
                $snapshot,
                $payload
            ) {
                execute(
                    $db,
                    "UPDATE reply_jobs SET state=?,error=?,attempts=attempts+1,updated_at=? WHERE id=?",
                    [$state, $code, now(), $j["id"]],
                );
                if ($terminal) {
                    execute($db, "UPDATE reply_drafts SET state='conflict',updated_at=? WHERE id=?", [
                        now(),
                        $j["draft_id"],
                    ]);
                }
                if ($refresh) {
                    cacheReplySnapshot($db, $payload, $snapshot);
                }
                audit($db, (int) $j["user_id"], "reply.failed", $j["draft_id"], ["reason" => $code]);
            });
            ++$summary[$terminal ? "conflicts" : "failed"];
        }
    }
    execute($db, "INSERT OR REPLACE INTO settings VALUES('replies_last_run',?)", [
        json($summary + ["at" => now()]),
    ]);
    return $summary;
}
function cacheReplySnapshot(\PDO $db, array $job, ?array $snapshot): void
{
    if (!$snapshot || (int) $snapshot["product_id"] !== (int) $job["product_id"]) {
        execute($db, "UPDATE reply_reviews SET available=0,updated_at=? WHERE id=?", [
            now(),
            $job["review_id"],
        ]);
        return;
    }
    $old = one($db, "SELECT product_name FROM reply_reviews WHERE id=?", [$job["review_id"]]);
    execute(
        $db,
        "UPDATE reply_reviews SET author=?,body=?,plus_text=?,minus_text=?,rating=?,status=?,published_at=?,shop_reply=?,context_hash=?,search_text=?,updated_at=? WHERE id=?",
        [
            $snapshot["author"],
            $snapshot["body"],
            $snapshot["plus_text"],
            $snapshot["minus_text"],
            $snapshot["rating"],
            $snapshot["status"],
            $snapshot["published_at"],
            $snapshot["shop_reply"],
            replyContext($snapshot),
            mb_strtolower(
                ($old["product_name"] ?? "") .
                    " " .
                    $snapshot["author"] .
                    " " .
                    mb_substr(
                        $snapshot["body"] . " " . $snapshot["plus_text"] . " " . $snapshot["minus_text"],
                        0,
                        5000,
                    ),
            ),
            now(),
            $job["review_id"],
        ],
    );
}
/** One scheduled entrypoint: write requested replies, refresh the list every 15 minutes. */
function replyTick(\PDO $db, \PDO $shop, string $prefix, int $language, int $limit = 3): array
{
    $result = processReplies($db, $shop, $prefix, $limit);
    $last = one($db, "SELECT value FROM settings WHERE key='replies_updated'")["value"] ?? null;
    if (!$last || strtotime($last) < time() - 900) {
        try {
            $result["synced"] = importReplies($db, $shop, $prefix, $language)["reviews"];
            execute($db, "DELETE FROM settings WHERE key='replies_error'");
        } catch (\Throwable $e) {
            $result["sync"] = "failed";
            execute($db, "INSERT OR REPLACE INTO settings VALUES('replies_error',?)", [
                json(["at" => now(), "code" => "reply_sync_failed"]),
            ]);
        }
    }
    return $result;
}

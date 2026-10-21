<?php

declare(strict_types=1);
namespace Lavka;

const CRM_REASONS = ['unknown' => 'Причина пока неизвестна', 'stock' => 'Остались запасы', 'price' => 'Цена', 'quality' => 'Качество', 'delivery' => 'Доставка / сервис', 'competitor' => 'Другой поставщик', 'need' => 'Потребность изменилась', 'contact' => 'Неудобный канал связи', 'other' => 'Другое'];
const CRM_OUTCOMES = ['unknown' => 'Ещё не выяснили', 'no_answer' => 'Нет ответа', 'replied' => 'Ответ получен', 'interested' => 'Есть интерес', 'later' => 'Вернуться позже', 'declined' => 'Отказ', 'do_not_contact' => 'Не контактировать'];
const CRM_KINDS = ['start' => 'Начать попытку и записать контакт', 'contact' => 'Повторный контакт', 'reply' => 'Ответ покупателя', 'offer' => 'Предложение / купон', 'note' => 'Заметка', 'analysis' => 'Ответ ChatGPT', 'close' => 'Завершить попытку'];

/** Includes large shared-contact groups; a name match is a weaker warning. */
function crm_duplicate_index(array $customers): array
{
    $index = []; $result = [];
    foreach ($customers as $c) {
        foreach ($c['emails'] as $v) if ($k = email_key($v)) $index['email'][$k][$c['id']] = true;
        foreach ($c['phones'] as $v) if ($k = phone_key($v)) $index['phone'][$k][$c['id']] = true;
        $k = normalize($c['name']);
        if (count(explode(' ', $k)) >= 2) $index['name'][$k][$c['id']] = true;
        $result[$c['id']] = ['count' => 0, 'kinds' => [], 'ids' => [], 'strong' => false, 'at_least' => false, '_ids' => []];
    }
    foreach ($index as $kind => $groups) foreach ($groups as $ids) {
        if (count($ids) < 2) continue;
        $sample = count($ids) > 50 ? array_slice($ids, 0, 21, true) : $ids;
        foreach ($ids as $id => $_) {
            $result[$id]['kinds'][$kind] = true;
            if ($kind !== 'name') $result[$id]['strong'] = true;
            if (count($ids) > 50) { $result[$id]['at_least'] = true; $result[$id]['count'] = max($result[$id]['count'], count($ids) - 1); }
            foreach ($sample as $other => $_) if ($other !== $id) $result[$id]['_ids'][(string) $other] = true;
        }
    }
    foreach ($result as &$r) {
        $r['kinds'] = array_keys($r['kinds']);
        $r['count'] = max($r['count'], count($r['_ids']));
        $r['ids'] = array_map('strval', array_slice(array_keys($r['_ids']), 0, 20));
        unset($r['_ids']);
    }
    unset($r);
    return $result;
}
function crm_events(Analytics $s, DSU $dsu, string $asof, string $currency, array $customers): array
{
    $known = array_fill_keys(array_column($customers, 'id'), true); $events = [];
    $stmt = $s->db->prepare('SELECT * FROM crm_events WHERE currency=? AND occurred_at<=? ORDER BY occurred_at,id');
    $stmt->execute([$currency, $asof . 'T23:59:59']);
    foreach ($stmt as $r) {
        $p = decode($r['payload']); $cid = null;
        foreach (array_unique(array_merge([$r['customer_id']], $p['member_order_ids'] ?? [])) as $anchor) {
            if (isset($dsu->parents[$anchor]) && isset($known[$dsu->find($anchor)])) { $cid = $dsu->find($anchor); break; }
        }
        if ($cid !== null) $events[$cid][] = array_merge($p, ['id' => $r['id'], 'occurred_at' => $r['occurred_at'], 'created_at' => $r['created_at'], 'voided_at' => $r['voided_at'], 'void_reason' => $r['void_reason']]);
    }
    return $events;
}
function crm_attempts(array $events, array $orders, string $asof): array
{
    $starts = array_values(array_filter($events, function ($e) { return !$e['voided_at'] && $e['kind'] === 'start'; }));
    $result = []; $asofEnd = $asof . 'T23:59:59';
    foreach ($starts as $i => $start) {
        $end = (new \DateTimeImmutable($start['occurred_at']))->modify('+' . $start['window_days'] . ' days')->format('Y-m-d\TH:i:s');
        $closed = false;
        foreach ($events as $e) if (!$e['voided_at'] && $e['kind'] === 'close' && ($e['attempt_id'] ?? '') === $start['id'] && $e['occurred_at'] >= $start['occurred_at'] && $e['occurred_at'] < $end) { $end = $e['occurred_at']; $closed = true; }
        // Merged profiles can bring overlapping attempts. The next attempt
        // ends the previous window; an order can belong to only one attempt.
        $superseded = isset($starts[$i + 1]) && $starts[$i + 1]['occurred_at'] <= $end;
        if ($superseded) $end = $starts[$i + 1]['occurred_at'];
        $after = []; $baseline = false;
        foreach ($orders as $o) {
            $date = str_replace(' ', 'T', $o['date_added']);
            if ($date <= $start['occurred_at']) $baseline = true;
            if ($date > $start['occurred_at'] && $date <= min($end, $asofEnd) && (!$superseded || $date < $end)) $after[] = $o;
        }
        $first = $baseline && $after ? $after[0] : null;
        $result[] = ['id' => $start['id'], 'started_at' => $start['occurred_at'], 'end_at' => $end, 'window_days' => $start['window_days'], 'owner' => $start['owner'], 'baseline' => $baseline, 'superseded' => $superseded, 'closed' => $closed, 'matured' => $end <= $asofEnd && !$superseded, 'state' => !$baseline ? 'no_baseline' : ($first ? 'returned' : ($end <= $asofEnd ? 'no_order' : 'waiting')), 'first_order_id' => $first['order_id'] ?? null, 'first_order_date' => $first ? substr($first['date_added'], 0, 10) : null, 'first_order_amount' => $first ? money(order_value($first)) : 0, 'orders_count' => $baseline ? count($after) : 0, 'amount' => $baseline ? money(sum_orders($after)) : 0, 'retrospective' => substr($start['created_at'], 0, 10) > substr($start['occurred_at'], 0, 10)];
    }
    return $result;
}
function crm_build(Analytics $s, array $customers, array $groups, DSU $dsu, string $asof, string $currency): array
{
    $duplicates = crm_duplicate_index($customers); $events = crm_events($s, $dsu, $asof, $currency, $customers);
    $spends = array_column($customers, 'lifetime_amount'); sort($spends, SORT_NUMERIC);
    $p50 = $spends ? $spends[min(count($spends) - 1, (int) floor(count($spends) * .5))] : 0;
    $p80 = $spends ? $spends[min(count($spends) - 1, (int) floor(count($spends) * .8))] : 0;
    $stats = ['attempts' => 0, 'contacted_customers' => 0, 'returned_customers' => 0, 'returned_attempts' => 0, 'matured_attempts' => 0, 'matured_returned' => 0, 'waiting' => 0, 'open_windows' => 0, 'replied_customers' => 0, 'offered_customers' => 0, 'first_order_amount' => 0, 'duplicate_candidates' => 0];
    $rows = [];
    foreach ($customers as $c) {
        $cid = $c['id']; $history = $events[$cid] ?? []; $live = array_values(array_filter($history, function ($e) { return !$e['voided_at']; }));
        $attempts = crm_attempts($history, $groups[$cid], $asof);
        $last = $live ? $live[count($live) - 1] : null; $lastContact = null; $lastAttempt = $attempts ? $attempts[count($attempts) - 1] : null;
        foreach ($live as $e) if (in_array($e['kind'], ['start', 'contact', 'reply', 'offer'], true)) $lastContact = $e;
        $tier = $c['lifetime_count'] >= 2 && $c['lifetime_amount'] >= $p80 ? 'gold' : ($c['lifetime_count'] >= 2 && $c['lifetime_amount'] >= $p50 ? 'silver' : 'bronze');
        $candidate = $c['followup_eligible'] && $c['reminder'] !== 'ok';
        $returned = false; $replied = false; $offered = false;
        $attemptIds = array_fill_keys(array_column($attempts, 'id'), true);
        foreach ($live as $e) {
            if (!isset($attemptIds[$e['attempt_id'] ?? ''])) continue;
            if (in_array($e['outcome'], ['replied', 'interested', 'later', 'declined', 'do_not_contact'], true) || $e['kind'] === 'reply') $replied = true;
            if ($e['kind'] === 'offer' || $e['offer'] !== 'none') $offered = true;
        }
        foreach ($attempts as $a) {
            ++$stats['attempts'];
            if ($a['state'] === 'returned') { ++$stats['returned_attempts']; $stats['first_order_amount'] += $a['first_order_amount']; $returned = true; }
            if ($a['matured'] && $a['baseline']) { ++$stats['matured_attempts']; if ($a['state'] === 'returned') ++$stats['matured_returned']; }
            if ($a['state'] === 'waiting') ++$stats['waiting'];
            if (!$a['superseded'] && $a['end_at'] > $asof . 'T23:59:59') ++$stats['open_windows'];
        }
        if ($attempts) ++$stats['contacted_customers'];
        if ($returned) ++$stats['returned_customers'];
        if ($replied && $attempts) ++$stats['replied_customers'];
        if ($offered && $attempts) ++$stats['offered_customers'];
        if ($candidate && $duplicates[$cid]['count']) ++$stats['duplicate_candidates'];
        $rows[$cid] = ['tier' => $tier, 'tier_rank' => ['gold' => 3, 'silver' => 2, 'bronze' => 1][$tier], 'candidate' => $candidate, 'duplicates' => $duplicates[$cid], 'events_count' => count($live), 'attempts_count' => count($attempts), 'state' => $lastAttempt['state'] ?? 'not_started', 'latest_attempt' => $lastAttempt, 'returned_ever' => $returned, 'owner' => $last['owner'] ?? ($tier === 'gold' ? 'Таня' : 'Команда'), 'last_event' => $lastContact ? ['kind' => $lastContact['kind'], 'outcome' => $lastContact['outcome'], 'occurred_at' => $lastContact['occurred_at']] : null, 'next_contact' => ((array) $c['action'])['next_contact'] ?? null];
    }
    $stats['first_order_amount'] = even_round($stats['first_order_amount'], 2);
    $stats['matured_rate'] = $stats['matured_attempts'] ? pct($stats['matured_returned'], $stats['matured_attempts']) : null;
    return ['customers' => (object) $rows, 'stats' => $stats, 'thresholds' => ['p50' => $p50, 'p80' => $p80], 'as_of' => $asof, 'currency' => $currency];
}

function crm_context(\PDO $db, string $directory, string $cid, string $asof, string $currency): array
{
    $s = new Analytics($db, $directory);
    if (!valid_date($asof) || !in_array($currency, $s->metadata()['currencies'], true)) fail(422, 'Неверная дата или валюта');
    $r = $s->report(0, $asof, $currency); $customer = null;
    foreach ($r['customers'] as $c) if ($c['id'] === $cid) { $customer = $c; break; }
    if (!$customer) fail(404, 'Карточка изменилась. Обновите отчёт.');
    $before = array_values(array_filter($s->orders, function ($o) use ($asof) { return substr($o['date_added'], 0, 10) <= $asof; }));
    $dsu = $s->identity($before); $history = crm_events($s, $dsu, $asof, $currency, $r['customers']);
    $orders = array_map(function ($id) use ($s) { return $s->orders[$id]; }, $customer['order_ids']);
    return [$s, $r, $customer, $history[$cid] ?? [], $orders];
}
function crm_plan(Analytics $s, array $c, array $orders, array $events, array $summary, string $asof): array
{
    $products = []; $combos = [];
    foreach ($orders as $o) {
        $basket = [];
        foreach ($s->lines[$o['order_id']] ?? [] as $line) {
            $key = encode([$line['product_id'], $line['pack']['label']]);
            if (!isset($products[$key])) $products[$key] = ['name' => $line['name'], 'pack' => $line['pack']['label'], 'quantity' => 0, 'orders' => [], 'last_date' => ''];
            $products[$key]['quantity'] += (int) $line['quantity'];
            $products[$key]['orders'][$o['order_id']] = true;
            $products[$key]['last_date'] = max($products[$key]['last_date'], substr($o['date_added'], 0, 10));
            $basket[$line['product_id']] = $line['name'];
        }
        $names = array_values($basket); sort($names, SORT_STRING);
        for ($i = 0; $i < min(count($names), 15); ++$i) for ($j = $i + 1; $j < min(count($names), 15); ++$j) { $key = encode([$names[$i], $names[$j]]); $combos[$key] = ($combos[$key] ?? 0) + 1; }
    }
    foreach ($products as &$p) $p['orders'] = count($p['orders']); unset($p);
    usort($products, function ($a, $b) { return $b['orders'] <=> $a['orders'] ?: strcmp($b['last_date'], $a['last_date']); });
    arsort($combos, SORT_NUMERIC); $pairs = [];
    foreach (array_slice($combos, 0, 3, true) as $key => $n) $pairs[] = ['products' => decode($key), 'orders' => $n];
    $products = array_slice($products, 0, 6); $last = null; $touches = 0;
    foreach ($events as $e) if (!$e['voided_at']) { if ($e['kind'] !== 'analysis' && $e['kind'] !== 'note') $last = $e; if (in_array($e['kind'], ['start', 'contact', 'offer'], true) && days(substr($e['occurred_at'], 0, 10), $asof) <= 30) ++$touches; }
    $phase = $c['days_since'] >= 365 ? 'Заново установить контакт' : ($c['days_since'] > max(180, 3 * $c['interval_days']) ? 'Узнать причину паузы' : 'Помочь пополнить запас');
    $next = 'Спросить, актуальна ли потребность, и выяснить причину паузы одним открытым вопросом.';
    $reasons = $last['reasons'] ?? [];
    if (in_array('stock', $reasons, true)) $next = 'Уточнить, когда закончатся запасы; согласовать дату напоминания и более подходящую фасовку.';
    if (in_array('price', $reasons, true)) $next = 'Уточнить бюджет и нужный объём. Сравнить фасовки; скидку предлагать после проверки условий с Таней.';
    if (in_array('competitor', $reasons, true)) $next = 'Узнать, что удобнее у другого поставщика, и предложить один конкретный способ улучшить опыт.';
    if (in_array('quality', $reasons, true) || in_array('delivery', $reasons, true)) $next = 'Передать Тане конкретную проблему, согласовать решение и только затем обсуждать повторный заказ.';
    if (in_array('need', $reasons, true)) $next = 'Уточнить новую задачу; не повторять прежнее предложение, если потребность исчезла.';
    if (($last['outcome'] ?? '') === 'interested') $next = 'Уточнить состав и фасовку повторного заказа, сроки и удобный способ оформления.';
    $stopped = in_array(((array) $c['action'])['stage'] ?? '', ['declined', 'do_not_contact'], true);
    if ($touches >= 2 && ($last['outcome'] ?? '') === 'no_answer') $next = 'За 30 дней уже было не менее двух обращений без ответа. Сделать паузу; не усиливать частоту.';
    if ($stopped) $next = 'Активное обращение остановлено по CRM-отметке. Сохранить причину; возобновлять только после явного изменения договорённости.';
    $basket = implode(' и ', array_map(function ($p) { return $p['name'] . ' (' . $p['pack'] . ')'; }, array_slice($products, 0, 2)));
    $intro = $c['days_since'] >= 365 ? 'Давно не общались — подскажите, специи сейчас ещё актуальны для вас?' : ($c['days_since'] > max(180, 3 * $c['interval_days']) ? 'Заметили паузу в заказах. Что изменилось и есть ли что-то, что нам стоит улучшить?' : 'Как ваши запасы специй — уже нужно что-нибудь пополнить?');
    $template = 'Здравствуйте! Это Таня / команда «Лавки специй». ' . $intro . ($basket ? ' Раньше вы выбирали ' . $basket . '. Если актуально, поможем подобрать нужный объём или повторить знакомый состав.' : '') . ' Если сейчас не время, подскажите, когда удобнее вернуться к вопросу.';
    return ['phase' => $phase, 'next_step' => $next, 'template' => $template, 'products' => $products, 'combinations' => $pairs, 'touches_30d' => $touches, 'stopped' => $stopped, 'steps' => ['Проверить совпадения контактов и CRM-ограничения.', 'Восстановить контакт, уточнить актуальность и причину паузы.', 'Ответить на конкретную причину; предложить знакомые товары и подходящий объём.', 'Согласовать следующий шаг и дату; зафиксировать ответ.', 'Наблюдать новый заказ в окне попытки; проверить его статус.'], 'basis' => 'Сценарий по правилам и фактам заказов, без генеративной модели. Вес упаковки не доказывает скорость расхода и назначение специй.'] ;
}

function crm_validate_event(array $b): array
{
    $id = valid_string($b['id'] ?? null, 32);
    if (!preg_match('/^[a-f0-9]{32}$/D', $id)) fail(422, 'Неверный номер записи');
    $kind = valid_string($b['kind'] ?? null, 20); $outcome = valid_string($b['outcome'] ?? 'unknown', 30);
    $at = valid_string($b['occurred_at'] ?? null, 19);
    if (!isset(CRM_KINDS[$kind]) || !isset(CRM_OUTCOMES[$outcome]) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/D', $at) || !valid_date(substr($at, 0, 10)) || substr($at, 11, 2) > '23' || substr($at, 14, 2) > '59' || substr($at, 17, 2) > '59' || $at > date('Y-m-d\TH:i:s', time() + 300)) fail(422, 'Проверьте тип записи и время контакта (Киев)');
    $channel = valid_string($b['channel'] ?? 'note', 20); $offer = valid_string($b['offer'] ?? 'none', 20); $permission = valid_string($b['permission'] ?? 'unknown', 10);
    if (!in_array($channel, ['call', 'messenger', 'email', 'sms', 'other', 'note'], true) || !in_array($offer, ['none', 'repeat', 'pack', 'coupon', 'shipping', 'other'], true) || !in_array($permission, ['unknown', 'yes', 'no'], true)) fail(422, 'Неверное значение чеклиста');
    $reasons = $b['reasons'] ?? [];
    if (!is_array($reasons) || !array_is_list($reasons) || count($reasons) > 9) fail(422, 'Неверные причины паузы');
    foreach ($reasons as $r) if (!is_string($r) || !isset(CRM_REASONS[$r])) fail(422, 'Неверная причина паузы');
    $next = $b['next_contact'] ?? null;
    if ($next !== null && (!is_string($next) || !valid_date($next) || $next < substr($at, 0, 10))) fail(422, 'Дата следующего контакта должна быть не раньше текущего');
    $window = filter_var($b['window_days'] ?? 90, FILTER_VALIDATE_INT);
    if (!in_array($window, [30, 60, 90, 180], true)) fail(422, 'Окно возврата: 30, 60, 90 или 180 дней');
    $owner = trim(valid_string($b['owner'] ?? '', 80));
    if ($owner === '') fail(422, 'Укажите ответственного');
    $note = valid_string($b['note'] ?? '', 3000); $ai = valid_string($b['ai_text'] ?? '', 12000);
    if ($kind === 'analysis' && trim($ai) === '') fail(422, 'Вставьте ответ ChatGPT');
    if ($kind === 'start' && $channel === 'note') fail(422, 'Для начала попытки укажите канал фактического контакта');
    return ['id' => $id, 'kind' => $kind, 'occurred_at' => $at, 'outcome' => $outcome, 'channel' => $channel, 'offer' => $offer, 'permission' => $permission, 'reasons' => array_values(array_unique($reasons)), 'next_contact' => $next, 'window_days' => $window, 'owner' => $owner, 'coupon' => valid_string($b['coupon'] ?? '', 80), 'note' => $note, 'ai_text' => $ai];
}
function crm_save_event(\PDO $db, string $directory, string $cid, string $asof, string $currency, array $body): array
{
    $p = crm_validate_event($body); $digest = hash('sha256', encode([$cid, $currency, $p]));
    $db->exec('BEGIN IMMEDIATE');
    try {
        $old = $db->prepare('SELECT payload FROM crm_events WHERE id=?'); $old->execute([$p['id']]); $old = $old->fetchColumn();
        if ($old) {
            if ((decode($old)['request_digest'] ?? '') !== $digest) fail(409, 'Номер записи уже использован');
            $db->exec('COMMIT'); return ['ok' => true, 'id' => $p['id'], 'repeated' => true];
        }
        [$s, $r, $c, $events, $orders] = crm_context($db, $directory, $cid, $asof, $currency);
        if (substr($p['occurred_at'], 0, 10) > $asof) fail(422, 'Контакт позже даты проверки отчёта');
        $attempts = crm_attempts($events, $orders, $asof); $active = null;
        foreach ($attempts as $a) if ($a['started_at'] <= $p['occurred_at'] && ($p['occurred_at'] < $a['end_at'] || $p['occurred_at'] === $a['end_at'] && !$a['closed'])) $active = $a;
        if ($p['kind'] === 'start') {
            if ($active) fail(409, 'Уже есть попытка в этом окне. Добавьте контакт к ней или завершите её.');
            foreach ($attempts as $a) if ($a['started_at'] > $p['occurred_at'] && $a['started_at'] <= (new \DateTimeImmutable($p['occurred_at']))->modify('+' . $p['window_days'] . ' days')->format('Y-m-d\TH:i:s')) fail(409, 'Окно пересекается с записанной попыткой');
            $baseline = false;
            foreach ($orders as $o) if (str_replace(' ', 'T', $o['date_added']) <= $p['occurred_at']) $baseline = true;
            if (!$baseline) fail(422, 'До контакта должен быть хотя бы один учитываемый заказ');
            $p['attempt_id'] = $p['id'];
        } else {
            $p['attempt_id'] = $active['id'] ?? null;
            if ($p['kind'] === 'close' && !$active) fail(409, 'Нет открытой попытки на эту дату');
        }
        $outbound = in_array($p['kind'], ['start', 'contact', 'offer'], true);
        if ($outbound && in_array(((array) $c['action'])['stage'] ?? '', ['do_not_contact', 'declined'], true)) fail(409, 'В карточке стоит отказ или запрет контакта. Проверьте договорённость.');
        $p['member_order_ids'] = $c['order_ids']; $p['request_digest'] = $digest;
        $stmt = $db->prepare('INSERT INTO crm_events(id,customer_id,currency,occurred_at,created_at,payload) VALUES(?,?,?,?,?,?)');
        $stmt->execute([$p['id'], $cid, $currency, $p['occurred_at'], date('Y-m-d\TH:i:s'), encode($p)]);
        $latestContact = '';
        foreach ($events as $e) if (!$e['voided_at'] && !in_array($e['kind'], ['note', 'analysis', 'close'], true)) $latestContact = max($latestContact, $e['occurred_at']);
        $stop = $p['permission'] === 'no' || in_array($p['outcome'], ['declined', 'do_not_contact'], true);
        $interaction = !in_array($p['kind'], ['note', 'analysis', 'close'], true);
        if (($interaction || $stop || $p['next_contact'] !== null) && $p['occurred_at'] >= $latestContact && substr($p['occurred_at'], 0, 10) >= (((array) $c['action'])['contact_date'] ?? '')) {
            $action = (array) $c['action'];
            $stage = $p['permission'] === 'no' || $p['outcome'] === 'do_not_contact' ? 'do_not_contact' : ($p['outcome'] === 'declined' ? 'declined' : (in_array($p['outcome'], ['replied', 'interested', 'later'], true) || $p['kind'] === 'reply' ? 'replied' : 'contacted'));
            if ($stop || $interaction && !in_array($action['stage'] ?? '', ['declined', 'do_not_contact'], true)) $action['stage'] = $stage;
            if ($interaction) $action['contact_date'] = max($action['contact_date'] ?? '', substr($p['occurred_at'], 0, 10));
            if ($interaction || $p['next_contact'] !== null) $action['next_contact'] = $p['next_contact'];
            $action['updated_at'] = date(DATE_ATOM);
            $stmt = $db->prepare('INSERT OR REPLACE INTO customer_actions VALUES(?,?)'); $stmt->execute([$cid, encode($action)]);
        }
        bump($db); $db->exec('COMMIT');
        return ['ok' => true, 'id' => $p['id']];
    } catch (\Throwable $e) { $db->exec('ROLLBACK'); throw $e; }
}

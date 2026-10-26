<?php

declare(strict_types=1);
namespace Lavka;

require_once __DIR__ . '/common.php';

/** Small projection of the existing report. No shop connection or network access. */
function dashboard_projection(array $report, iterable $orders, string $today): array
{
    $start = substr($today, 0, 7) . '-01';
    $month = new \DateTimeImmutable($start, report_timezone());
    $previous = $month->modify('-1 year');
    $previousStart = $previous->format('Y-m-d');
    $previousMonthEnd = $previous->format('Y-m-t');
    $closedDays = min((int) substr($today, 8, 2) - 1, (int) $previous->format('t'));
    $currentEnd = $month->modify('+' . max(0, $closedDays - 1) . ' days')->format('Y-m-d');
    $previousEnd = $previous->modify('+' . max(0, $closedDays - 1) . ' days')->format('Y-m-d');
    $owners = []; $first = []; $actions = [];
    $crm = (array) ($report['crm']['customers'] ?? []);
    foreach ($report['customers'] as $customer) {
        $id = $customer['id']; $first[$id] = $customer['first_order'];
        foreach ($customer['order_ids'] as $orderId) $owners[$orderId] = $id;
        $summary = $crm[$id] ?? [];
        // The existing CRM owns contact permissions, cooling periods and deferrals.
        if (!$customer['followup_eligible'] || $customer['reminder'] === 'ok' || !empty($customer['suppression'])) continue;
        if (($summary['state'] ?? '') === 'waiting') continue;
        $risk = in_array($customer['segment'], ['vip_risk', 'sleeping'], true);
        $actions[] = ['id' => 'customer-' . $id, 'customer_id' => $id, 'name' => $customer['name'],
            'type' => $risk ? 'risk' : 'opportunity', 'priority' => $customer['priority'],
            'segment' => $customer['segment'], 'segment_name' => $customer['segment_name'],
            'days_since' => $customer['days_since'], 'interval_days' => $customer['interval_days'],
            'lifetime_count' => $customer['lifetime_count'], 'lifetime_amount' => $customer['lifetime_amount']];
    }
    usort($actions, function ($a, $b) { return $b['priority'] <=> $a['priority'] ?: $b['lifetime_amount'] <=> $a['lifetime_amount'] ?: strcmp($a['id'], $b['id']); });
    $days = []; $previousDays = [];
    for ($i = 1; $i <= (int) $month->format('t'); ++$i) {
        $date = $month->format('Y-m-') . sprintf('%02d', $i);
        $days[$date] = ['date' => $date, 'amount' => '0', 'count' => 0, 'order_ids' => [], 'state' => $date < $today ? 'closed' : ($date === $today ? 'today' : 'future')];
    }
    for ($i = 1; $i <= (int) $previous->format('t'); ++$i) $previousDays[$i] = ['amount' => '0', 'count' => 0];
    $totals = ['amount' => '0', 'count' => 0, 'customers' => []];
    $closed = $totals; $prior = $totals;
    foreach ($orders as $order) {
        $id = $order['order_id']; $date = substr($order['date_added'], 0, 10);
        if (!isset($owners[$id]) || $date > $today || !isset($days[$date]) && ($date < $previousStart || $date > $previousMonthEnd)) continue;
        $value = order_value($order); $cid = $owners[$id];
        if (isset($days[$date])) {
            $days[$date]['amount'] = plus($days[$date]['amount'], $value);
            ++$days[$date]['count']; $days[$date]['order_ids'][] = (string) $id;
            $totals['amount'] = plus($totals['amount'], $value); ++$totals['count']; $totals['customers'][$cid] = $first[$cid] < $start;
            if ($closedDays && $date <= $currentEnd) {
                $closed['amount'] = plus($closed['amount'], $value); ++$closed['count']; $closed['customers'][$cid] = $first[$cid] < $start;
            }
        } elseif ($date >= $previousStart && $date <= $previousMonthEnd) {
            $day = (int) substr($date, 8, 2);
            $previousDays[$day]['amount'] = plus($previousDays[$day]['amount'], $value); ++$previousDays[$day]['count'];
            if ($closedDays && $date <= $previousEnd) {
                $prior['amount'] = plus($prior['amount'], $value); ++$prior['count']; $prior['customers'][$cid] = $first[$cid] < $previousStart;
            }
        }
    }
    $metrics = function (array $bucket): array {
        $count = count($bucket['customers']); $repeat = count(array_filter($bucket['customers']));
        return ['amount' => money($bucket['amount']), 'count' => $bucket['count'], 'average' => $bucket['count'] ? money(bcdiv($bucket['amount'], (string) $bucket['count'], 12)) : null,
            'customers' => $count, 'repeat_customers' => $repeat, 'repeat_share' => $count ? even_round(100 * $repeat / $count, 1) : null];
    };
    foreach ($days as &$day) $day['amount'] = money($day['amount']); unset($day);
    foreach ($previousDays as &$day) $day['amount'] = money($day['amount']); unset($day);
    return ['today' => $today, 'month_start' => $start, 'currency' => $report['currency'], 'metrics' => $metrics($totals),
        'comparison' => ['basis' => 'same_month_last_year', 'days' => $closedDays, 'current_start' => $start, 'current_end' => $closedDays ? $currentEnd : null,
            'previous_start' => $previousStart, 'previous_end' => $closedDays ? $previousEnd : null, 'current' => $metrics($closed), 'previous' => $metrics($prior)],
        'days' => array_values($days), 'previous_days' => array_values($previousDays),
        'actions' => $actions, 'action_count' => count($actions), 'sources' => ['inventory' => 'not_connected', 'inbox' => 'not_connected', 'costs' => 'not_connected']];
}

function cached_dashboard(\PDO $db, string $directory, string $today, string $currency): array
{
    $cache = $directory . '/cache'; private_dir($cache);
    $sourceRevision = revision($db);
    $key = hash('sha256', $sourceRevision . ':dashboard-v2-seasonal:' . $today . ':' . $currency);
    $path = $cache . '/dashboard-' . $key . '.json';
    if (is_file($path)) return decode(file_get_contents($path));
    $report = cached_report($db, $directory, 0, $today, $currency);
    $db->beginTransaction();
    try {
        if (revision($db) !== $sourceRevision) throw new \RuntimeException('Dashboard source changed; retry');
        $query = $db->query("SELECT payload FROM raw_records WHERE table_name='oc_order'");
        $orders = function () use ($query) { while ($row = $query->fetch()) yield decode($row['payload']); };
        $result = dashboard_projection($report, $orders(), $today);
        $result['imported_at'] = $db->query('SELECT imported_at FROM imports ORDER BY id DESC LIMIT 1')->fetchColumn() ?: null;
        $db->commit();
    } catch (\Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
    // A concurrent import/CRM edit must never be labelled with a newer revision.
    atomic_write($path, encode($result));
    $files = glob($cache . '/dashboard-*.json') ?: [];
    usort($files, function ($a, $b) { return filemtime($b) <=> filemtime($a); });
    foreach (array_slice($files, 8) as $old) if ($old !== $path) @unlink($old);
    return $result;
}

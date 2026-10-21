<?php

declare (strict_types=1);
namespace Lavka;

const SEGMENTS = ['new' => ['Новые покупатели', 'Помочь сделать второй заказ, предложить знакомые фасовки.'], 'second' => ['Ждём вторую покупку', 'Уточнить впечатления от первого заказа; предложить пополнение.'], 'loyal' => ['Лояльные и активные', 'Личное предложение, новинки, доступ к большим фасовкам.'], 'growing' => ['Развивающиеся', 'Закрепить привычку: повтор любимых товаров и полезное дополнение.'], 'vip_risk' => ['Ценные под риском', 'Приоритетный личный контакт: выяснить причину паузы.'], 'return' => ['Пора возвращать', 'Напомнить о знакомых товарах и удобном повторном заказе.'], 'sleeping' => ['Уснувшие постоянные', 'Отдельная реактивация с понятным поводом вернуться.'], 'oneoff' => ['Разовые и давно ушедшие', 'Редкая реактивация; отделить от постоянной очереди контактов.']];
function marketing(Analytics $s, array &$customers, array $groups, array $period, string $asof): array
{
    $segments = [];
    foreach (SEGMENTS as $key => [$name, $suggestion]) {
        $segments[$key] = ['id' => $key, 'name' => $name, 'suggestion' => $suggestion, 'count' => 0, 'amount' => 0, 'customer_ids' => []];
    }
    $spends = array_column($customers, 'lifetime_amount');
    sort($spends, SORT_NUMERIC);
    $cuts = [];
    foreach ([0.2, 0.4, 0.6, 0.8] as $q) {
        $cuts[] = $spends ? $spends[min(count($spends) - 1, (int) (count($spends) * $q))] : 0;
    }
    $cohorts = [];
    $activity = [];
    $frequency = [];
    $recency = [];
    $matrix = [];
    $timeline = [];
    $repeatIntervals = [];
    $conversion = [];
    $firstProducts = [];
    $ownership = [];
    $purchases = [];
    $queue = [];
    $first = [];
    $byid = [];
    foreach ([30, 60, 90] as $h) {
        $conversion[$h] = ['window' => $h, 'eligible' => 0, 'returned' => 0];
    }
    foreach ($customers as &$c) {
        $cid = $c['id'];
        $orders = $groups[$cid];
        $dates = array_map(function ($o) {
            return substr($o['date_added'], 0, 10);
        }, $orders);
        $first[$cid] = $orders[0]['order_id'];
        $second = count($dates) > 1 ? days($dates[0], $dates[1]) : null;
        if ($second !== null) {
            $repeatIntervals[] = $second;
        }
        $start = month_index($dates[0]);
        $cohorts[$start][$cid] = true;
        foreach ($dates as $d) {
            $activity[$start][month_index($d) - $start][$cid] = true;
        }
        foreach ($conversion as $h => &$item) {
            if (days($dates[0], $asof) >= $h) {
                $item['eligible']++;
                if ($second !== null && $second <= $h) {
                    $item['returned']++;
                }
            }
        }
        unset($item);
        $n = $c['lifetime_count'];
        $r = $c['days_since'];
        $ratio = $r / max(1, $c['interval_days']);
        if ($n === 1) {
            $segment = $r <= 30 ? 'new' : ($r <= 90 ? 'second' : 'oneoff');
        } elseif ($r > max(180, $c['interval_days'] * 3)) {
            $segment = 'sleeping';
        } elseif ($c['reminder'] !== 'ok') {
            $segment = $n >= 5 ? 'vip_risk' : 'return';
        } else {
            $segment = $n >= 5 ? 'loyal' : 'growing';
        }
        $rScore = $r <= 30 ? 5 : ($r <= 60 ? 4 : ($r <= 90 ? 3 : ($r <= 180 ? 2 : 1)));
        $fScore = $n === 1 ? 1 : ($n === 2 ? 2 : ($n < 5 ? 3 : ($n < 10 ? 4 : 5)));
        $mScore = 1;
        foreach ($cuts as $cut) {
            if ($c['lifetime_amount'] > $cut) {
                $mScore++;
            }
        }
        $vip = $c['lifetime_amount'] >= $cuts[3] && $n >= 2;
        $priority = (int) even_round(min(100, min($n, 10) * 3 + min(30, log1p(max(0, $c['lifetime_amount'])) / log1p(max(1, $spends ? max($spends) : 1)) * 30) + min(35, max(0, $ratio - 1) * 18) + ($vip ? 5 : 0)));
        $c = array_merge($c, ['segment' => $segment, 'segment_name' => SEGMENTS[$segment][0], 'rfm' => (string) $rScore . $fScore . $mScore, 'recency_score' => $rScore, 'frequency_score' => $fScore, 'monetary_score' => $mScore, 'priority' => $priority, 'vip' => $vip, 'first_order' => $dates[0], 'second_order_days' => $second, 'tenure_days' => days($dates[0], $asof), 'delay_ratio' => even_round($ratio, 2)]);
        $sg =& $segments[$segment];
        $sg['count']++;
        $sg['amount'] += $c['lifetime_amount'];
        $sg['customer_ids'][] = $cid;
        unset($sg);
        $fb = $n === 1 ? '1' : ($n < 5 ? '2–4' : ($n < 10 ? '5–9' : '10+'));
        $frequency[$fb] = ($frequency[$fb] ?? 0) + 1;
        $rb = $r <= 30 ? '0–30' : ($r <= 60 ? '31–60' : ($r <= 90 ? '61–90' : ($r <= 180 ? '91–180' : '180+')));
        $recency[$rb][$fb] = ($recency[$rb][$fb] ?? 0) + 1;
        $mk = ($r <= 60 ? 'recent' : ($r <= 180 ? 'warm' : 'old')) . ':' . ($n === 1 ? 'one' : ($n < 5 ? 'repeat' : 'loyal'));
        $matrix[$mk][] = $cid;
        if ($c['followup_eligible'] && $c['reminder'] !== 'ok') {
            $queue[] = ['customer_id' => $cid, 'priority' => $priority, 'segment' => $segment, 'suggestion' => SEGMENTS[$segment][1]];
        }
        $owned = [];
        foreach ($orders as $o) {
            $orderProducts = [];
            foreach ($s->lines[$o['order_id']] ?? [] as $line) {
                $key = encode([$line['product_id'], $line['pack']['label']]);
                $owned[$line['product_id']] = true;
                $orderProducts[$line['product_id']] = true;
                $purchases[$cid][$key] = $purchases[$cid][$key] ?? ['product_id' => $line['product_id'], 'name' => $line['name'], 'pack' => $line['pack']['label'], 'dates' => [], 'quantity' => 0];
                $purchases[$cid][$key]['dates'][substr($o['date_added'], 0, 10)] = true;
                $purchases[$cid][$key]['quantity'] += (int) $line['quantity'];
            }
            if ($o['order_id'] === $first[$cid] && days($dates[0], $asof) >= 60) {
                foreach ($orderProducts as $pid => $_) {
                    $firstProducts[$pid]['eligible'][$cid] = true;
                    if ($second !== null && $second <= 60) {
                        $firstProducts[$pid]['returned'][$cid] = true;
                    }
                }
            }
        }
        $ownership[$cid] = $owned;
        $byid[$cid] = $c;
    }
    unset($c);
    foreach ($segments as &$sg) {
        $sg['amount'] = even_round($sg['amount'], 2);
    }
    unset($sg);
    usort($queue, function ($a, $b) {
        return $b['priority'] <=> $a['priority'];
    });
    foreach ($conversion as &$v) {
        $v['rate'] = pct($v['returned'], $v['eligible']);
    }
    unset($v);
    $current = month_index($asof);
    krsort($cohorts, SORT_NUMERIC);
    $cohortRows = [];
    foreach ($cohorts as $month => $ids) {
        $cells = [];
        for ($offset = 0; $offset <= 12; $offset++) {
            $target = $month + $offset;
            $count = $target <= $current ? count($activity[$month][$offset] ?? []) : null;
            $cells[] = ['month' => $offset, 'count' => $count, 'rate' => $count !== null ? pct($count, count($ids)) : null, 'partial' => $target === $current, 'future' => $target > $current];
        }
        $cohortRows[] = ['month' => month_label($month), 'size' => count($ids), 'cells' => $cells];
    }
    $names = [];
    foreach ($s->lines as $lines) {
        foreach ($lines as $line) {
            $names[$line['product_id']] = $line['name'];
        }
    }
    $productCounts = [];
    $pairs = [];
    $amounts = [];
    $season = [];
    $hours = array_fill(0, 7, array_fill(0, 24, 0));
    $months = [];
    for ($m = 1; $m <= 12; $m++) {
        $months[] = ['month' => $m, 'count' => 0, 'amount' => '0'];
    }
    $basketCount = 0;
    $excluded = 0;
    $firstIds = array_fill_keys(array_values($first), true);
    foreach ($period as $o) {
        $d = new \DateTimeImmutable($o['date_added']);
        $m = (int) $d->format('n');
        $months[$m - 1]['count']++;
        $months[$m - 1]['amount'] = plus($months[$m - 1]['amount'], order_value($o));
        $hours[(int) $d->format('N') - 1][(int) $d->format('G')]++;
        $week = week($o['date_added']);
        $kind = isset($firstIds[$o['order_id']]) ? 'first' : 'repeat';
        $timeline[$week] = $timeline[$week] ?? ['first_count' => 0, 'repeat_count' => 0, 'first_amount' => '0', 'repeat_amount' => '0'];
        $timeline[$week][$kind . '_count']++;
        $timeline[$week][$kind . '_amount'] = plus($timeline[$week][$kind . '_amount'], order_value($o));
        $ids = [];
        foreach ($s->lines[$o['order_id']] ?? [] as $line) {
            $ids[$line['product_id']] = true;
        }
        $ids = array_map('strval', array_keys($ids));
        sort($ids, SORT_STRING);
        if (count($ids) <= 100) {
            foreach ($ids as $pid) {
                $productCounts[$pid] = ($productCounts[$pid] ?? 0) + 1;
            }
            for ($a = 0; $a < count($ids); $a++) {
                for ($b = $a + 1; $b < count($ids); $b++) {
                    $key = encode([$ids[$a], $ids[$b]]);
                    $pairs[$key] = ($pairs[$key] ?? 0) + 1;
                }
            }
            $basketCount++;
        } else {
            $excluded++;
        }
        foreach ($s->lines[$o['order_id']] ?? [] as $line) {
            $pid = $line['product_id'];
            $amounts[$pid] = plus($amounts[$pid] ?? '0', times($line['total'], $o['currency_value']));
            $season[$pid][$m] = ($season[$pid][$m] ?? 0) + (int) $line['quantity'];
        }
    }
    $affinities = [];
    foreach ($pairs as $key => $count) {
        if ($count < 3) {
            continue;
        }
        [$a, $b] = decode($key);
        $lift = $count * $basketCount / ($productCounts[$a] * $productCounts[$b]);
        $affinities[] = ['a' => $a, 'b' => $b, 'name_a' => $names[$a], 'name_b' => $names[$b], 'orders' => $count, 'confidence_a' => pct($count, $productCounts[$a]), 'confidence_b' => pct($count, $productCounts[$b]), 'lift' => even_round($lift, 2)];
    }
    usort($affinities, function ($a, $b) {
        return $b['orders'] <=> $a['orders'] ?: $b['lift'] <=> $a['lift'];
    });
    $productGaps = [];
    foreach ($purchases as $items) {
        foreach ($items as $key => $item) {
            $dates = array_keys($item['dates']);
            sort($dates);
            for ($i = 1; $i < count($dates); $i++) {
                $productGaps[$key][] = days($dates[$i - 1], $dates[$i]);
            }
        }
    }
    $replenishment = [];
    foreach ($purchases as $cid => $items) {
        $c = $byid[$cid];
        if (!$c['followup_eligible']) {
            continue;
        }
        foreach ($items as $key => $item) {
            $dates = array_keys($item['dates']);
            sort($dates);
            $gaps = [];
            for ($i = 1; $i < count($dates); $i++) {
                $gaps[] = days($dates[$i - 1], $dates[$i]);
            }
            if (count($gaps) >= 2) {
                $interval = (int) even_round(median(array_slice($gaps, -6)));
                $basis = 'Личная история товара';
            } elseif (count($productGaps[$key] ?? []) >= 5) {
                $interval = (int) even_round(median($productGaps[$key]));
                $basis = 'Интервалы покупателей этой фасовки';
            } else {
                continue;
            }
            $due = add_days(end($dates), max(1, $interval));
            $late = days($due, $asof);
            if ($late < 0 || $late > max(180, $interval * 3)) {
                continue;
            }
            $replenishment[] = ['customer_id' => (string) $cid, 'product_id' => $item['product_id'], 'product' => $item['name'], 'pack' => $item['pack'], 'last_date' => end($dates), 'due_on' => $due, 'late_days' => $late, 'interval' => $interval, 'basis' => $basis, 'priority' => $c['priority']];
        }
    }
    usort($replenishment, function ($a, $b) {
        return $b['priority'] <=> $a['priority'] ?: $b['late_days'] <=> $a['late_days'];
    });
    $offers = [];
    foreach ($customers as $c) {
        if (!$c['followup_eligible']) {
            continue;
        }
        $owned = $ownership[$c['id']];
        $best = null;
        foreach ($affinities as $p) {
            if ($p['lift'] < 1) {
                continue;
            }
            $pid = null;
            $confidence = 0;
            if (isset($owned[$p['a']]) && !isset($owned[$p['b']])) {
                $pid = $p['b'];
                $confidence = $p['confidence_a'];
            } elseif (isset($owned[$p['b']]) && !isset($owned[$p['a']])) {
                $pid = $p['a'];
                $confidence = $p['confidence_b'];
            }
            if ($pid !== null && ($best === null || $confidence > $best['confidence'] || $confidence === $best['confidence'] && $p['orders'] > $best['paired_orders'])) {
                $best = ['customer_id' => $c['id'], 'product' => $names[$pid], 'product_id' => $pid, 'paired_orders' => $p['orders'], 'confidence' => $confidence, 'lift' => $p['lift'], 'priority' => $c['priority']];
            }
        }
        if ($best) {
            $offers[] = $best;
        }
    }
    usort($offers, function ($a, $b) {
        return $b['priority'] <=> $a['priority'];
    });
    uasort($amounts, function ($a, $b) {
        return -bccomp($a, $b, 12);
    });
    $total = '0';
    foreach ($amounts as $v) {
        $total = plus($total, $v);
    }
    $cumulative = '0';
    $abc = [];
    foreach ($amounts as $pid => $v) {
        $share = bccomp($total, '0', 12) !== 0 ? (float) bcdiv($cumulative, $total, 12) : 0;
        $abc[] = ['product_id' => (string) $pid, 'name' => $names[$pid], 'amount' => money($v), 'class' => $share < 0.8 ? 'A' : ($share < 0.95 ? 'B' : 'C'), 'share' => bccomp($total, '0', 12) !== 0 ? even_round((float) bcdiv($v, $total, 12) * 100, 2) : 0];
        $cumulative = plus($cumulative, $v);
    }
    $firstRows = [];
    foreach ($firstProducts as $pid => $x) {
        if (count($x['eligible']) >= 5) {
            $firstRows[] = ['product_id' => (string) $pid, 'name' => $names[$pid], 'eligible' => count($x['eligible']), 'returned' => count($x['returned'] ?? []), 'rate' => pct(count($x['returned'] ?? []), count($x['eligible']))];
        }
    }
    usort($firstRows, function ($a, $b) {
        return $b['rate'] <=> $a['rate'] ?: $b['eligible'] <=> $a['eligible'];
    });
    $ranked = array_values(array_filter($customers, function ($c) {
        return $c['order_count'] > 0;
    }));
    usort($ranked, function ($a, $b) {
        return $b['amount'] <=> $a['amount'];
    });
    $periodTotal = array_sum(array_column($ranked, 'amount'));
    $topN = max(1, (int) even_round(count($ranked) * 0.2));
    $totalFirst = '0';
    $totalRepeat = '0';
    foreach ($timeline as &$row) {
        $totalFirst = plus($totalFirst, $row['first_amount']);
        $totalRepeat = plus($totalRepeat, $row['repeat_amount']);
        $row['first_amount'] = money($row['first_amount']);
        $row['repeat_amount'] = money($row['repeat_amount']);
    }
    unset($row);
    $freq = [];
    foreach (['1', '2–4', '5–9', '10+'] as $k) {
        $freq[] = ['label' => $k, 'count' => $frequency[$k] ?? 0];
    }
    $recent = [];
    foreach (['0–30', '31–60', '61–90', '91–180', '180+'] as $k) {
        $recent[] = ['label' => $k, 'groups' => (object) ($recency[$k] ?? [])];
    }
    $matrixRows = [];
    foreach ($matrix as $key => $ids) {
        [$r, $f] = explode(':', $key);
        $matrixRows[] = ['recency' => $r, 'frequency' => $f, 'count' => count($ids), 'customer_ids' => $ids];
    }
    foreach ($months as &$m) {
        $m['amount'] = money($m['amount']);
    }
    unset($m);
    $productMonths = [];
    foreach (array_slice(array_keys($amounts), 0, 20) as $pid) {
        $mm = [];
        for ($m = 1; $m <= 12; $m++) {
            $mm[] = $season[$pid][$m] ?? 0;
        }
        $productMonths[] = ['id' => (string) $pid, 'name' => $names[$pid], 'months' => $mm];
    }
    return ['segments' => array_values($segments), 'queue' => $queue, 'cohorts' => $cohortRows, 'conversion' => array_values($conversion), 'median_second_days' => median($repeatIntervals), 'frequency' => $freq, 'recency' => $recent, 'rfm_matrix' => $matrixRows, 'timeline' => (object) $timeline, 'first_amount' => money($totalFirst), 'repeat_amount' => money($totalRepeat), 'repeat_amount_share' => pct((float) $totalRepeat, (float) plus($totalFirst, $totalRepeat)), 'top20_customer_share' => pct(array_sum(array_column(array_slice($ranked, 0, $topN), 'amount')), $periodTotal), 'replenishment' => array_slice($replenishment, 0, 300), 'offers' => array_slice($offers, 0, 300), 'affinities' => array_slice($affinities, 0, 80), 'affinity_excluded_orders' => $excluded, 'first_products' => array_slice($firstRows, 0, 50), 'abc' => $abc, 'hours' => $hours, 'months' => $months, 'product_months' => $productMonths];
}

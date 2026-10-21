<?php

declare (strict_types=1);
namespace Lavka;

const GA_ALIASES = ['date' => ['date', 'дата'], 'channel' => ['channel', 'sessiondefaultchannelgroup', 'sessionprimarychannelgroup', 'группа каналов по умолчанию для сеанса'], 'sessions' => ['sessions', 'сеансы'], 'users' => ['activeusers', 'users', 'активные пользователи'], 'carts' => ['addtocarts', 'добавления в корзину'], 'checkouts' => ['checkouts', 'begincheckouts', 'оформления заказов'], 'purchases' => ['ecommercepurchases', 'purchases', 'покупки'], 'revenue' => ['purchaserevenue', 'revenue', 'доход от покупок'], 'cost' => ['advertisercost', 'adcost', 'cost', 'расходы'], 'currency' => ['currencycode', 'currency', 'валюта'], 'transaction_id' => ['transactionid', 'transaction_id', 'идентификатор транзакции'], 'source_medium' => ['sessionsourcemedium', 'source_medium', 'источник/канал сеанса'], 'campaign' => ['sessioncampaignname', 'campaign', 'кампания сеанса']];
function ga_header(string $s): string
{
    return preg_replace('/[\s_\-\/()]+/u', '', mb_convert_case(ltrim($s, "﻿"), MB_CASE_FOLD, 'UTF-8'));
}
function ga_number(string $s): string
{
    $s = str_replace([" ", ' '], '', trim($s ?: '0'));
    if (str_contains($s, ',') && str_contains($s, '.')) {
        $s = strrpos($s, '.') > strrpos($s, ',') ? str_replace(',', '', $s) : str_replace(',', '.', str_replace('.', '', $s));
    } else {
        $s = str_replace(',', '.', $s);
    }
    if (!preg_match('/^[+]?((?:\d+(?:\.\d*)?|\.\d+))(?:[eE]([+-]?\d+))?$/', $s, $m)) {
        throw new \InvalidArgumentException('Нужны конечные неотрицательные числа');
    }
    $number = $m[1];
    $exponent = (int) ($m[2] ?? 0);
    if (abs($exponent) > 30) {
        throw new \InvalidArgumentException('Слишком большое число');
    }
    if ($exponent > 0) {
        $number = bcmul($number, '1' . str_repeat('0', $exponent), 12);
    } elseif ($exponent < 0) {
        $number = bcdiv($number, '1' . str_repeat('0', -$exponent), 12);
    }
    return $number;
}
function valid_date(string $s): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
        return false;
    }
    [$y, $m, $d] = array_map('intval', explode('-', $s));
    return checkdate($m, $d, $y);
}
function ga_date(string $s): string
{
    $s = trim($s);
    if (preg_match('/^\d{8}$/', $s)) {
        $s = substr($s, 0, 4) . '-' . substr($s, 4, 2) . '-' . substr($s, 6, 2);
    } elseif (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $s, $m)) {
        $s = $m[3] . '-' . $m[2] . '-' . $m[1];
    }
    if (!valid_date($s)) {
        throw new \InvalidArgumentException('Дата должна быть YYYYMMDD, YYYY-MM-DD или DD.MM.YYYY');
    }
    return $s;
}
function transaction_key(string $s): string
{
    $s = trim($s);
    return preg_match('/^(?:#|order[-_ ]?)?(\d+)$/i', $s, $m) ? (string) (int) $m[1] : $s;
}
function ga4_import(\PDO $db, string $text, string $kind, string $currency): array
{
    if (strpos($text, "\0") !== false) throw new \InvalidArgumentException('CSV содержит недопустимый нулевой байт');
    if (!in_array($kind, ['daily', 'transactions'], true)) {
        throw new \InvalidArgumentException('Неизвестный тип отчёта');
    }
    if (!preg_match('/^[A-Z]{3}$/', $currency)) {
        throw new \InvalidArgumentException('Валюта — код из трёх букв');
    }
    $lines = array_values(array_filter(preg_split('/\r?\n/', ltrim($text, "﻿")), function ($s) {
        return trim($s) !== '' && !str_starts_with(ltrim($s), '#');
    }));
    if (!$lines) {
        throw new \InvalidArgumentException('Пустой CSV');
    }
    $delimiter = substr_count($lines[0], ';') > substr_count($lines[0], ',') ? ';' : ',';
    $stream = fopen('php://temp', 'w+');
    fwrite($stream, implode("\n", $lines));
    rewind($stream);
    $headers = fgetcsv($stream, 0, $delimiter, '"', "\0");
    $mapped = [];
    foreach (GA_ALIASES as $key => $aliases) {
        foreach ($headers as $i => $column) {
            if (in_array(ga_header($column), array_map(__NAMESPACE__ . '\ga_header', $aliases), true)) {
                $mapped[$key] = $i;
                break;
            }
        }
    }
    $required = $kind === 'daily' ? ['date', 'channel', 'sessions'] : ['date', 'transaction_id', 'revenue'];
    $missing = array_diff($required, array_keys($mapped));
    if ($missing) {
        throw new \InvalidArgumentException('Нет колонок: ' . implode(', ', $missing));
    }
    $records = [];
    $skipped = 0;
    $lineNo = 1;
    while (($row = fgetcsv($stream, 0, $delimiter, '"', "\0")) !== false) {
        $lineNo++;
        if ($lineNo > 50002) {
            throw new \InvalidArgumentException('Лимит — 50 000 строк за импорт');
        }
        $field = function ($key) use ($row, $mapped) {
            return (string) ($row[$mapped[$key] ?? -1] ?? '');
        };
        if (in_array(normalize($field('date')), ['total', 'totals', 'grand total', 'итого', 'общий итог', 'всього'], true)) {
            $skipped++;
            continue;
        }
        try {
            $date = ga_date($field('date'));
            $ccy = strtoupper(trim($field('currency') ?: $currency));
            if (!preg_match('/^[A-Z]{3}$/', $ccy)) {
                throw new \InvalidArgumentException('Некорректная валюта');
            }
            if ($kind === 'daily') {
                if (trim($field('sessions')) === '') {
                    throw new \InvalidArgumentException('Пустое значение sessions');
                }
                $record = ['date' => $date, 'channel' => trim($field('channel')) ?: '(not set)', 'currency' => $ccy];
                foreach (['sessions', 'users', 'carts', 'checkouts', 'purchases'] as $key) {
                    $n = isset($mapped[$key]) && trim($field($key)) !== '' ? ga_number($field($key)) : null;
                    if ($n !== null && (bccomp($n, bcadd($n, '0', 0), 12) !== 0 || bccomp($n, (string) PHP_INT_MAX, 0) > 0)) {
                        throw new \InvalidArgumentException('Счётчики должны быть целыми');
                    }
                    $record[$key] = $n !== null ? (int) $n : null;
                }
                $record['revenue'] = trim($field('revenue')) !== '' ? ga_number($field('revenue')) : null;
                $record['cost'] = trim($field('cost')) !== '' ? ga_number($field('cost')) : null;
                $key = encode([$date, $record['channel'], $ccy]);
            } else {
                if (trim($field('revenue')) === '') {
                    throw new \InvalidArgumentException('Пустое значение purchaseRevenue');
                }
                $tx = transaction_key($field('transaction_id'));
                if (!$tx || in_array(normalize($tx), ['(not set)', '(not provided)', 'не задано'], true)) {
                    $skipped++;
                    continue;
                }
                $record = ['date' => $date, 'transaction_id' => $tx, 'revenue' => ga_number($field('revenue')), 'currency' => $ccy, 'source_medium' => trim($field('source_medium')), 'campaign' => trim($field('campaign'))];
                $key = encode([$tx, $ccy]);
            }
            if (isset($records[$key])) {
                throw new \InvalidArgumentException('Повтор ключа: нужен один итог на день/канал или transaction_id');
            }
            $records[$key] = $record;
        } catch (\InvalidArgumentException $e) {
            throw new \InvalidArgumentException('Строка ' . $lineNo . ': ' . $e->getMessage());
        }
    }
    fclose($stream);
    if (!$records) {
        throw new \InvalidArgumentException('Нет строк данных');
    }
    $db->beginTransaction();
    try {
        $stmt = $db->prepare('DELETE FROM ga4_records WHERE kind=?');
        $stmt->execute([$kind]);
        $stmt = $db->prepare('INSERT INTO ga4_records VALUES(?,?,?)');
        foreach ($records as $key => $r) {
            $stmt->execute([$kind, $key, encode($r)]);
        }
        bump($db);
        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    return ['kind' => $kind, 'rows' => count($records), 'skipped_totals_or_missing_ids' => $skipped];
}
function ga4_report(\PDO $db, int $year, string $asof, string $currency, array $orders): array
{
    $raw = $db->query('SELECT kind,payload FROM ga4_records')->fetchAll();
    $data = ['daily' => [], 'transactions' => []];
    foreach ($raw as $r) {
        $v = decode($r['payload']);
        if ($v['currency'] === $currency && $v['date'] <= $asof && iso_year($v['date']) === $year) {
            $data[$r['kind']][] = $v;
        }
    }
    $daily = $data['daily'];
    $transactions = $data['transactions'];
    $channels = [];
    $fields = ['sessions', 'users', 'carts', 'checkouts', 'purchases', 'revenue', 'cost'];
    $totals = array_fill_keys($fields, null);
    foreach ($daily as $row) {
        $channels[$row['channel']] = $channels[$row['channel']] ?? array_merge(['channel' => $row['channel']], array_fill_keys($fields, null));
        foreach ($fields as $f) {
            if ($row[$f] !== null) {
                $v = $row[$f];
                $totals[$f] = plus($totals[$f] ?? '0', $v);
                $channels[$row['channel']][$f] = plus($channels[$row['channel']][$f] ?? '0', $v);
            }
        }
    }
    $convert = function ($row) {
        foreach ($row as $k => $v) {
            if ($k !== 'channel' && $v !== null) {
                $row[$k] = (float) $v;
            }
        }
        return $row;
    };
    $channelRows = [];
    foreach ($channels as $row) {
        $row = $convert($row);
        $row['purchase_session_ratio'] = $row['sessions'] && $row['purchases'] !== null ? even_round($row['purchases'] / $row['sessions'] * 100, 2) : null;
        $row['roas'] = $row['cost'] && $row['revenue'] !== null ? even_round($row['revenue'] / $row['cost'], 2) : null;
        $channelRows[] = $row;
    }
    usort($channelRows, function ($a, $b) {
        return ($b['sessions'] ?? 0) <=> ($a['sessions'] ?? 0);
    });
    $oc = [];
    foreach ($orders as $o) {
        $oc[$o['order_id']] = $o;
    }
    $matched = [];
    $gaOnly = [];
    $matchedIds = [];
    $trend = [];
    foreach ($transactions as $row) {
        $id = $row['transaction_id'];
        if (isset($oc[$id])) {
            $o = $oc[$id];
            $matched[] = ['order_id' => $o['order_id'], 'ga_amount' => (float) $row['revenue'], 'order_amount' => (float) order_value($o), 'difference' => money(bcsub($row['revenue'], order_value($o), 12)), 'source_medium' => $row['source_medium'], 'campaign' => $row['campaign']];
            $matchedIds[$id] = true;
        } else {
            $gaOnly[] = $id;
        }
    }
    foreach ($daily as $row) {
        $d = $row['date'];
        $trend[$d] = $trend[$d] ?? ['date' => $d, 'sessions' => 0, 'ga_purchases' => 0, 'order_count' => 0, 'order_amount' => 0];
        $trend[$d]['sessions'] += $row['sessions'] ?? 0;
        $trend[$d]['ga_purchases'] += $row['purchases'] ?? 0;
    }
    foreach ($orders as $o) {
        $d = substr($o['date_added'], 0, 10);
        $trend[$d] = $trend[$d] ?? ['date' => $d, 'sessions' => 0, 'ga_purchases' => 0, 'order_count' => 0, 'order_amount' => 0];
        $trend[$d]['order_count']++;
        $trend[$d]['order_amount'] += (float) order_value($o);
    }
    ksort($trend);
    $ocOnly = $transactions ? array_map('strval', array_keys(array_diff_key($oc, $matchedIds))) : [];
    sort($ocOnly, SORT_NUMERIC);
    return ['available' => (bool) $raw, 'daily_available' => (bool) $daily, 'transactions_available' => (bool) $transactions, 'daily_rows' => count($daily), 'transaction_rows' => count($transactions), 'totals' => $convert($totals), 'channels' => $channelRows, 'matched' => $matched, 'ga_only' => $gaOnly, 'oc_only' => $ocOnly, 'trend' => $daily ? array_values($trend) : []];
}

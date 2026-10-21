<?php

declare(strict_types=1);
namespace Lavka;

/** Validate the entire selection before writing one edge for each selected row. */
function merge_identity_pairs(\PDO $db, string $directory, array $pairs, string $asof, string $currency): array
{
    if (!array_is_list($pairs) || count($pairs) < 1 || count($pairs) > 100 || !valid_date($asof) || !preg_match('/^[A-Z]{3}$/D', $currency)) fail(422, 'Выберите от 1 до 100 пар и корректную дату');
    $normalized = [];
    foreach ($pairs as $pair) {
        if (!is_array($pair) || !array_is_list($pair) || count($pair) !== 2) fail(422, 'Каждая строка должна содержать ровно два контакта');
        foreach ($pair as $id) if (!is_string($id) || !preg_match('/^[0-9]{1,30}$/D', $id)) fail(422, 'Неверный идентификатор контакта');
        if ($pair[0] === $pair[1]) fail(422, 'Выберите разные контакты');
        sort($pair, SORT_NUMERIC);
        $key = implode(':', $pair);
        if (isset($normalized[$key])) fail(422, 'Одна пара выбрана несколько раз');
        $normalized[$key] = $pair;
    }
    $db->exec('BEGIN IMMEDIATE');
    try {
        $store = new Analytics($db, $directory);
        if (!in_array($currency, $store->metadata()['currencies'], true)) fail(422, 'Неизвестная валюта');
        $report = $store->report(0, $asof, $currency);
        $available = array_fill_keys(array_column($report['duplicate_pairs'], 'id'), true);
        foreach ($normalized as $key => $_) if (!isset($available[$key])) fail(409, 'Совпадения изменились. Обновите таблицу и выберите пары заново.');
        $nodes = [];
        foreach ($normalized as $pair) foreach ($pair as $id) $nodes[$id] = true;
        $dsu = new DSU(array_map('strval', array_keys($nodes)));
        $stmt = $db->prepare('INSERT OR IGNORE INTO manual_links VALUES(?,?,?)');
        foreach ($normalized as $pair) {
            $stmt->execute([$pair[0], $pair[1], utc_now()]);
            $dsu->union($pair[0], $pair[1]);
        }
        $components = [];
        foreach ($nodes as $id => $_) $components[$dsu->find((string) $id)] = true;
        bump($db);
        $db->exec('COMMIT');
        return ['ok' => true, 'pairs_merged' => count($normalized), 'contacts' => count($nodes), 'groups' => count($components)];
    } catch (\Throwable $error) {
        $db->exec('ROLLBACK');
        throw $error;
    }
}

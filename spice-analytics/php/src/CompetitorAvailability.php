<?php

declare(strict_types=1);
namespace Lavka;

/** A comparison convention, never a claim about actual warehouse stock. */
function competitors_availability_policy(string $sourceId): string
{
    return $sourceId === 'banka' ? 'listed_offer' : 'explicit_only';
}

function competitors_stock_usable(string $sourceId, array $offer): bool
{
    return ($offer['availability'] ?? 'unknown') === 'in_stock'
        || competitors_availability_policy($sourceId) === 'listed_offer'
        && ($offer['availability'] ?? 'unknown') === 'unknown'
        && ($offer['availability_basis'] ?? 'unknown') === 'not_listed';
}

/** Re-read legacy unknowns once; never relabel cached prices as available. */
function competitors_availability_migrate(\PDO $db, string $now): void
{
    $name = 'availability-evidence-v1';
    $applied = $db->prepare('SELECT 1 FROM competitor_migrations WHERE name=?');
    $applied->execute([$name]);
    if ($applied->fetchColumn()) return;
    $queue = $db->prepare('UPDATE competitor_targets SET queued=1,next_check_at=? WHERE id=?');
    foreach ($db->query("SELECT id,observation FROM competitor_targets WHERE enabled=1 AND kind='product' AND source_id='banka' AND observation IS NOT NULL") as $row) {
        $observation = decode($row['observation']);
        foreach ($observation['offers'] ?? [] as $offer) {
            if (($offer['availability'] ?? 'unknown') === 'unknown' && !in_array($offer['availability_basis'] ?? 'unknown', ['not_listed', 'blocked'], true)) {
                $queue->execute([$now, $row['id']]);
                break;
            }
        }
    }
    $db->prepare('INSERT INTO competitor_migrations(name,applied_at) VALUES(?,?)')->execute([$name, $now]);
}

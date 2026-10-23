<?php

declare(strict_types=1);
namespace Lavka;

/**
 * This is a comparison preference, not evidence about the downloaded variant.
 * A selected preference must still match an actual offer with a known weight.
 */
const COMPETITOR_PACKAGING_CHOICES = ['auto', 'doypack', 'zip', 'kraft', 'jar'];

/** Add only the preference column; preserve existing targets and observations. */
function competitors_packaging_migrate(\PDO $db): void
{
    $columns = [];
    foreach ($db->query('PRAGMA table_info(competitor_targets)') as $column) {
        $columns[] = $column['name'];
    }
    if (!$columns) throw new \RuntimeException('Таблица наблюдений не найдена');
    if (!in_array('packaging', $columns, true)) {
        $db->exec("ALTER TABLE competitor_targets ADD COLUMN packaging TEXT NOT NULL DEFAULT 'auto'
            CHECK(packaging IN ('auto','doypack','zip','kraft','jar'))");
    }
}

/**
 * Omission preserves an existing explicit choice, including old clients' PUTs.
 * For non-product pages only the neutral default is meaningful.
 */
function competitors_packaging_request(array $body, ?array $old, string $kind): string
{
    $value = array_key_exists('packaging', $body) ? $body['packaging'] : ($old['packaging'] ?? 'auto');
    if (!is_string($value) || !in_array($value, COMPETITOR_PACKAGING_CHOICES, true)) {
        throw new \InvalidArgumentException('Выберите тип упаковки из списка');
    }
    if ($kind !== 'product' && $value !== 'auto') {
        throw new \InvalidArgumentException('Тип упаковки доступен только для товаров');
    }
    return $value;
}

/**
 * null means no source preference, not permission to compare arbitrary packs.
 * The report must reject an ambiguous set of variants rather than pick its
 * cheapest member. Unknown observed packaging cannot satisfy an explicit choice.
 */
function competitors_packaging_effective(string $sourceId, string $kind, string $preference = 'auto'): ?string
{
    if (!in_array($preference, COMPETITOR_PACKAGING_CHOICES, true)) {
        throw new \InvalidArgumentException('Выберите тип упаковки из списка');
    }
    if ($kind !== 'product') return null;
    if ($preference !== 'auto') return $preference;
    if ($sourceId === 'spicery') return 'doypack';
    if ($sourceId === 'specii') return 'kraft';
    return null;
}

function competitors_packaging_fields(array $row): array
{
    $value = $row['packaging'] ?? 'auto';
    if (!is_string($value) || !in_array($value, COMPETITOR_PACKAGING_CHOICES, true)) {
        throw new \RuntimeException('Настройка упаковки повреждена');
    }
    return ['packaging' => $value,
        'packaging_effective' => competitors_packaging_effective((string) $row['source_id'], (string) $row['kind'], $value)];
}

/** Call inside the same transaction as the target insert/update. */
function competitors_packaging_store(\PDO $db, int $targetId, string $value): void
{
    if ($targetId < 1 || !in_array($value, COMPETITOR_PACKAGING_CHOICES, true)) {
        throw new \InvalidArgumentException('Неверная настройка упаковки');
    }
    $statement = $db->prepare('UPDATE competitor_targets SET packaging=? WHERE id=?');
    $statement->execute([$value, $targetId]);
    if ($statement->rowCount() !== 1) throw new \RuntimeException('Страница наблюдения не найдена');
}

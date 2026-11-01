<?php
declare(strict_types=1);

function homes_rcn_benchmark(PDO $db, array $home): array {
    if (!$home['area'] || (float)$home['area'] < 15 || ($home['source'] === 'Adradar' && mb_stripos($home['title'], 'Kwidzyn') === false)) return [null, 0, null, null];
    $exists = $db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='rcn_transactions'")->fetchColumn();
    if (!$exists) return [null, 0, null, null];
    $area = (float)$home['area'];
    $since = gmdate('Y-m-d', strtotime('-24 months'));
    $query = $db->prepare("SELECT price_m2,sold_at FROM rcn_transactions WHERE market='rynekWtorny' AND sold_at>=? AND area BETWEEN ? AND ? ORDER BY price_m2");
    $query->execute([$since, $area * 0.8, $area * 1.2]);
    $rows = $query->fetchAll(PDO::FETCH_ASSOC);
    $count = count($rows);
    if ($count < 5) return [null, $count, null, null];
    $median = $count % 2 ? (float)$rows[intdiv($count, 2)]['price_m2'] : ((float)$rows[$count / 2 - 1]['price_m2'] + (float)$rows[$count / 2]['price_m2']) / 2;
    $dates = array_column($rows, 'sold_at');
    return [(int)(round($median * $area / 1000) * 1000), $count, (int)round($median), [min($dates), max($dates)]];
}

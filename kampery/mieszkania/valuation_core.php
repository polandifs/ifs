<?php
declare(strict_types=1);

function valuation_street(string $value): string {
    if (preg_match('/(?:^|;)UL:([^;]+)/u', $value, $m)) $value = $m[1];
    $value = mb_strtolower(trim($value));
    $value = strtr($value, ['ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ó'=>'o','ś'=>'s','ź'=>'z','ż'=>'z']);
    $value = preg_replace('/^\s*(ulica|ul)\s+/u', '', $value);
    $value = preg_replace('/[^a-z0-9]+/u', ' ', $value);
    return trim((string)$value);
}

function valuation_percentile(array $sorted, float $position): float {
    $last = count($sorted) - 1;
    $index = $last * $position;
    $low = (int)floor($index);
    $high = (int)ceil($index);
    return (float)$sorted[$low] + ((float)$sorted[$high] - (float)$sorted[$low]) * ($index - $low);
}

function valuation_summary(PDO $db, float $area, string $street = ''): array {
    $empty = ['available'=>false,'count'=>0,'scope'=>'Kwidzyn','area'=>$area,'street'=>'','from'=>null,'to'=>null,'low'=>null,'median'=>null,'high'=>null,'lower_quartile'=>null,'upper_quartile'=>null,'price_m2'=>null,'synced_at'=>null];
    if ($area < 15 || $area > 250) return $empty;
    if (!$db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='rcn_transactions'")->fetchColumn()) return $empty;
    $since = gmdate('Y-m-d', strtotime('-24 months'));
    $query = $db->prepare("SELECT price_m2,sold_at,address FROM rcn_transactions WHERE market='rynekWtorny' AND sold_at>=? AND area BETWEEN ? AND ? AND price_m2 BETWEEN 1000 AND 50000");
    $query->execute([$since, $area * 0.8, $area * 1.2]);
    $rows = $query->fetchAll(PDO::FETCH_ASSOC);
    $requestedStreet = valuation_street($street);
    $streetRows = [];
    if ($requestedStreet !== '') foreach ($rows as $row) {
        if (valuation_street((string)$row['address']) === $requestedStreet) $streetRows[] = $row;
    }
    $useStreet = count($streetRows) >= 5;
    $selected = $useStreet ? $streetRows : $rows;
    $empty['count'] = count($selected);
    $empty['street'] = $useStreet ? $street : '';
    $empty['scope'] = $useStreet ? 'ul. ' . trim($street) : 'Kwidzyn (całe miasto)';
    if ($db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='rcn_meta'")->fetchColumn()) {
        $empty['synced_at'] = $db->query("SELECT value FROM rcn_meta WHERE key='synced_at'")->fetchColumn() ?: null;
    }
    if (count($selected) < 5) return $empty;
    $values = array_map(static fn(array $row): float => (float)$row['price_m2'], $selected);
    sort($values, SORT_NUMERIC);
    $dates = array_column($selected, 'sold_at');
    $money = static fn(float $priceM2): int => (int)(round($priceM2 * $area / 1000) * 1000);
    return array_merge($empty, [
        'available'=>true,
        'from'=>min($dates), 'to'=>max($dates),
        'low'=>$money(valuation_percentile($values, 0.2)),
        'median'=>$money(valuation_percentile($values, 0.5)),
        'high'=>$money(valuation_percentile($values, 0.8)),
        'lower_quartile'=>$money(valuation_percentile($values, 0.25)),
        'upper_quartile'=>$money(valuation_percentile($values, 0.75)),
        'price_m2'=>(int)round(valuation_percentile($values, 0.5)),
    ]);
}

function valuation_scenario(array $summary, string $condition): ?int {
    if (!$summary['available']) return null;
    if ($condition === 'remont') return $summary['lower_quartile'];
    if ($condition === 'bardzo_dobry') return $summary['upper_quartile'];
    return $summary['median'];
}

function valuation_money(?int $value): string {
    return $value === null ? '—' : number_format($value, 0, ',', ' ') . ' zł';
}

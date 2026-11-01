<?php
declare(strict_types=1);
require_once __DIR__ . '/valuation_core.php';

function market_listing_summary(PDO $db, float $area, ?int $rooms = null, string $excludeUrl = ''): array {
    $empty = ['available'=>false, 'count'=>0, 'median'=>null, 'low'=>null, 'high'=>null, 'examples'=>[],
        'price_m2'=>null, 'scope'=>'Oferty z Kwidzyna · metraż ±20%' . ($rooms ? ' · ' . $rooms . ' pok.' : ''),
        'reason'=>'too_few'];
    if ($area < 15 || $area > 250) return $empty;
    $since = gmdate('Y-m-d H:i:s', strtotime('-30 days'));
    $sql = "SELECT url,source,title,price,area,rooms,street,image_url,last_seen FROM homes WHERE source NOT IN ('Adradar','Facebook') AND price BETWEEN 50000 AND 2000000 AND area BETWEEN ? AND ? AND last_seen>=?";
    $params = [$area * 0.8, $area * 1.2, $since];
    if ($rooms !== null && $rooms > 0) { $sql .= ' AND rooms=?'; $params[] = $rooms; }
    $query = $db->prepare($sql);
    $query->execute($params);
    $values = []; $seen = []; $examples = [];
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ($row['url'] === $excludeUrl) continue;
        $signature = (int)$row['price'] . '|' . round((float)$row['area'], 1) . '|' . (int)$row['rooms'] . '|' . valuation_street((string)$row['street']);
        if (isset($seen[$signature])) continue;
        $seen[$signature] = true;
        $values[] = (float)$row['price'] / (float)$row['area'];
        $examples[] = $row;
    }
    sort($values, SORT_NUMERIC);
    $empty['count'] = count($values);
    usort($examples, static fn(array $a, array $b): int =>
        abs((float)$a['area'] - $area) <=> abs((float)$b['area'] - $area)
        ?: strcmp((string)$b['last_seen'], (string)$a['last_seen']));
    $empty['examples'] = array_slice($examples, 0, 8);
    if (count($values) < 5) return $empty;
    $money = static fn(float $p): int => (int)(round($p * $area / 1000) * 1000);
    return array_merge($empty, ['available'=>true, 'reason'=>'',
        'low'=>$money(valuation_percentile($values, .2)),
        'median'=>$money(valuation_percentile($values, .5)),
        'high'=>$money(valuation_percentile($values, .8)),
        'price_m2'=>(int)round(valuation_percentile($values, .5))]);
}

function market_valuation(PDO $db, float $area, string $street, ?int $rooms = null, string $condition = 'dobry', string $excludeUrl = ''): array {
    $rcn = valuation_summary($db, $area, $street);
    $listings = market_listing_summary($db, $area, $rooms, $excludeUrl);
    // The source data do not identify condition consistently. Never infer a condition discount.
    $rcnValue = $rcn['available'] ? $rcn['median'] : null;
    $listingValue = $listings['available'] ? $listings['median'] : null;
    $estimate = null; $basis = 'none';
    $jointLow = null; $jointHigh = null;
    if ($rcnValue !== null && $listingValue !== null) {
        $jointLow = max($rcn['low'], $listings['low']);
        $jointHigh = min($rcn['high'], $listings['high']);
        // A narrow overlap with far-apart medians is weak corroboration.
        $narrowOverlap = $jointLow <= $jointHigh
            && ($jointHigh - $jointLow) < .1 * min($rcnValue, $listingValue)
            && abs($rcnValue - $listingValue) > .15 * max($rcnValue, $listingValue);
        $basis = $jointLow > $jointHigh ? 'conflict' : ($narrowOverlap ? 'divergent' : 'both');
        $estimate = $basis === 'both' ? $rcnValue : null;
    } elseif ($rcnValue !== null) { $basis = 'rcn'; }
    elseif ($listingValue !== null) { $basis = 'listings'; }
    return ['rcn'=>$rcn, 'listings'=>$listings, 'rcn_value'=>$rcnValue,
        'listing_value'=>$listingValue, 'estimate'=>$estimate, 'basis'=>$basis,
        'joint_low'=>$jointLow !== null && $jointLow <= $jointHigh?$jointLow:null,
        'joint_high'=>$jointHigh !== null && $jointLow <= $jointHigh?$jointHigh:null,
        'ranges_overlap'=>$jointLow !== null && $jointLow <= $jointHigh];
}

function market_negotiation(array $market, ?int $asking, ?int $renovation = null, int $otherCosts = 0): ?array {
    $estimate = $market['estimate'];
    if ($asking === null || $asking <= 0 || $estimate === null) return null;
    $gap = $asking - $estimate;
    $high = $market['basis'] === 'both' && $asking > max($market['rcn']['high'], $market['listings']['high']);
    $target = $high ? min($asking, (int)(floor($estimate / 1000) * 1000)) : null;
    $reasons = [];
    if ($market['rcn']['available']) $reasons[] = 'Mediana ' . (int)$market['rcn']['count'] . ' podobnych transakcji RCN w promieniu 500 m: ' . valuation_money($market['rcn']['median']) . '.';
    if ($market['listings']['available']) $reasons[] = 'Mediana ' . (int)$market['listings']['count'] . ' podobnych ofert widzianych w ostatnich 30 dniach: ' . valuation_money($market['listings']['median']) . '.';
    if ($high) $reasons[] = 'Cena oferty przekracza 80. percentyl cen z obu źródeł; względem wartości referencyjnej różnica wynosi ' . valuation_money($gap) . '.';
    if ($renovation !== null && $renovation > 0) $reasons[] = 'Do budżetu zakupu trzeba doliczyć szacowany remont: ' . valuation_money($renovation) . '.';
    if ($otherCosts > 0) $reasons[] = 'Pozostałe wpisane koszty: ' . valuation_money($otherCosts) . '.';
    $draft = null;
    if ($high && $target !== null) {
        $parts = ['Dzień dobry, jestem zainteresowany/a mieszkaniem, jednak cena ' . valuation_money($asking) . ' jest powyżej poziomu, który mogę uzasadnić dostępnymi danymi.'];
        if ($market['rcn']['available']) $parts[] = 'W rejestrze cen odnotowano ' . (int)$market['rcn']['count'] . ' transakcji mieszkań o podobnym metrażu do 500 m od wskazanej ulicy; ich mediana wynosi ' . valuation_money($market['rcn']['median']) . '.';
        if ($market['listings']['available']) $parts[] = 'Mediana ' . (int)$market['listings']['count'] . ' podobnych ofert widzianych w ostatnich 30 dniach wynosi ' . valuation_money($market['listings']['median']) . '.';
        if ($renovation !== null && $renovation > 0) $parts[] = 'Uwzględniam też orientacyjny koszt remontu ' . valuation_money($renovation) . '.';
        $parts[] = 'Rozumiem, że standard, piętro i wyposażenie mogą uzasadniać różnicę. Czy rozważą Państwo cenę ' . valuation_money($target) . '? Chętnie obejrzę mieszkanie i omówię szczegóły.';
        $draft = implode("\n\n", $parts);
    }
    return ['high'=>$high, 'gap'=>$gap, 'target'=>$target, 'reasons'=>$reasons, 'draft'=>$draft];
}

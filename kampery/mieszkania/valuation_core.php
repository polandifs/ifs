<?php
declare(strict_types=1);

function valuation_street(string $value): string {
    if (preg_match('/(?:^|;)UL:([^;]+)/u', $value, $m)) $value = $m[1];
    $value = mb_strtolower(trim($value));
    $value = strtr($value, ['ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ó'=>'o','ś'=>'s','ź'=>'z','ż'=>'z']);
    $value = preg_replace('/^\s*(ulica|ul)\s+/u', '', $value);
    $value = preg_replace('/[^a-z0-9]+/u', ' ', $value);
    // A user can enter a building number; RCN stores it separately from UL.
    $value = preg_replace('/\s+\d+[a-z]?(?:\s+\d+)?$/u', '', $value);
    return trim((string)$value);
}

function valuation_percentile(array $sorted, float $position): float {
    $last = count($sorted) - 1;
    $index = $last * $position;
    $low = (int)floor($index);
    $high = (int)ceil($index);
    return (float)$sorted[$low] + ((float)$sorted[$high] - (float)$sorted[$low]) * ($index - $low);
}

function valuation_trim_outliers(array $rows): array {
    $count = count($rows);
    if ($count < 10) return [$rows, 0];
    $values = array_map(static fn(array $row): float => (float)$row['price_m2'], $rows);
    sort($values, SORT_NUMERIC);
    $median = valuation_percentile($values, .5);
    $q1 = valuation_percentile($values, .25);
    $q3 = valuation_percentile($values, .75);
    $iqr = $q3 - $q1;
    // A transaction must be far outside the middle half and at least 25% from the median.
    $lower = min($q1 - 1.5 * $iqr, $median * .75);
    $upper = max($q3 + 1.5 * $iqr, $median * 1.25);
    $kept = array_values(array_filter($rows, static fn(array $row): bool =>
        (float)$row['price_m2'] >= $lower && (float)$row['price_m2'] <= $upper));
    return [$kept, $count - count($kept)];
}

function valuation_geometry(PDO $db, string $street): array {
    static $memo = [];
    $name = valuation_street($street);
    if ($name === '') return [];
    if (array_key_exists($name, $memo)) return $memo[$name];
    $db->exec('CREATE TABLE IF NOT EXISTS rcn_geocodes (street TEXT PRIMARY KEY, geometry_json TEXT NOT NULL, checked_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
    $cached = $db->prepare('SELECT geometry_json,checked_at FROM rcn_geocodes WHERE street=?');
    $cached->execute([$name]);
    $entry = $cached->fetch(PDO::FETCH_ASSOC);
    if ($entry && strtotime($entry['checked_at']) > time() - ($entry['geometry_json'] === '[]' ? 86400 : 2592000)) {
        return $memo[$name] = json_decode($entry['geometry_json'], true) ?: [];
    }
    $queries = [trim(preg_replace('/^\s*(?:Kwidzyn\s*,\s*)?(?:ul\.?\s*)?/iu', '', $street))];
    if (count(explode(' ', $name)) > 1 && !preg_match('/\d/', $street)) {
        $parts = explode(' ', $queries[0]);
        $queries[] = end($parts);
    }
    $geometry = [];
    $serviceResponded = false;
    foreach (array_unique($queries) as $query) {
        $url = 'https://services.gugik.gov.pl/uug/?request=GetAddress&address=' . rawurlencode('Kwidzyn, ' . $query);
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>false, CURLOPT_CONNECTTIMEOUT=>2,
            CURLOPT_TIMEOUT=>4, CURLOPT_MAXFILESIZE=>100000, CURLOPT_USERAGENT=>'IFS-Kwidzyn-RCN/1.0']);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($status !== 200 || !is_string($body)) continue;
        $serviceResponded = true;
        $data = json_decode($body, true);
        $candidate = $data['results']['1'] ?? null;
        if (!is_array($candidate) || ($candidate['teryt'] ?? '') !== '220701' || ($candidate['city'] ?? '') !== 'Kwidzyn') continue;
        if (valuation_street((string)($candidate['street'] ?? '')) !== valuation_street($query)) continue;
        $wkt = (string)($candidate['geometry_wkt'] ?? '');
        if (!preg_match('/^(POINT|LINESTRING)\(([-0-9., ]+)\)$/', $wkt, $match)) continue;
        $points = [];
        foreach (explode(',', $match[2]) as $pair) {
            $xy = preg_split('/\s+/', trim($pair));
            // UUG gives EPSG:2180 as northing (x), easting (y).
            if (count($xy) !== 2 || !is_numeric($xy[0]) || !is_numeric($xy[1])) { $points = []; break; }
            $north = (float)$xy[0]; $east = (float)$xy[1];
            if ($east < 600000 || $east > 700000 || $north < 450000 || $north > 550000) { $points = []; break; }
            $points[] = [$east, $north];
        }
        if ($points && count($points) <= 1000) { $geometry = $points; break; }
    }
    if ($geometry || $serviceResponded) {
        $db->prepare('INSERT OR REPLACE INTO rcn_geocodes(street,geometry_json,checked_at) VALUES(?,?,CURRENT_TIMESTAMP)')
            ->execute([$name, json_encode($geometry)]);
    }
    return $memo[$name] = $geometry;
}

function valuation_distance(float $east, float $north, array $line): float {
    $distance = INF;
    foreach ($line as $index=>$point) {
        if ($index === 0) continue;
        $previous = $line[$index - 1];
        $dx = $point[0] - $previous[0]; $dy = $point[1] - $previous[1];
        $length2 = $dx*$dx + $dy*$dy;
        $t = $length2 > 0 ? max(0, min(1, (($east-$previous[0])*$dx + ($north-$previous[1])*$dy)/$length2)) : 0;
        $distance = min($distance, hypot($east-$previous[0]-$t*$dx, $north-$previous[1]-$t*$dy));
    }
    if (count($line) === 1) $distance = hypot($east-$line[0][0], $north-$line[0][1]);
    return $distance;
}

function valuation_comparables(PDO $db, float $area, string $street): array {
    $name = valuation_street($street);
    if ($area < 15 || $area > 250 || $name === '') return ['rows'=>[], 'scope'=>'Podaj ulicę', 'reason'=>'missing_street'];
    if (!$db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='rcn_transactions'")->fetchColumn()) return ['rows'=>[], 'scope'=>'Brak danych RCN', 'reason'=>'missing_data'];
    $columns = array_column($db->query('PRAGMA table_info(rcn_transactions)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('easting', $columns, true) || !in_array('northing', $columns, true)) return ['rows'=>[], 'scope'=>'Dane przestrzenne RCN jeszcze niepobrane', 'reason'=>'missing_coordinates'];
    $line = valuation_geometry($db, $street);
    if (!$line) return ['rows'=>[], 'scope'=>'Nie udało się zlokalizować ulicy', 'reason'=>'unknown_street'];
    $since = gmdate('Y-m-d', strtotime('-24 months'));
    $query = $db->prepare("SELECT sold_at,price,area,price_m2,address,easting,northing FROM rcn_transactions WHERE market='rynekWtorny' AND sold_at>=? AND area BETWEEN ? AND ? AND price_m2 BETWEEN 1000 AND 50000 AND easting IS NOT NULL AND northing IS NOT NULL ORDER BY sold_at DESC");
    $query->execute([$since, $area * 0.8, $area * 1.2]);
    $rows = [];
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $distance = valuation_distance((float)$row['easting'], (float)$row['northing'], $line);
        if ($distance > 500) continue;
        $row['distance_m'] = (int)round($distance);
        $rows[] = $row;
    }
    return ['rows'=>$rows, 'scope'=>'do 500 m od ul. ' . trim($street), 'reason'=>count($rows) < 5 ? 'too_few' : ''];
}

function valuation_summary(PDO $db, float $area, string $street = ''): array {
    $empty = ['available'=>false,'count'=>0,'raw_count'=>0,'excluded_count'=>0,'scope'=>'Podaj ulicę','reason'=>'missing_street','area'=>$area,'street'=>$street,'from'=>null,'to'=>null,'low'=>null,'median'=>null,'high'=>null,'lower_quartile'=>null,'upper_quartile'=>null,'price_m2'=>null,'synced_at'=>null];
    if ($area < 15 || $area > 250) return $empty;
    $comparison = valuation_comparables($db, $area, $street);
    $empty['raw_count'] = count($comparison['rows']);
    [$selected, $empty['excluded_count']] = valuation_trim_outliers($comparison['rows']);
    $empty['count'] = count($selected);
    $empty['scope'] = $comparison['scope'];
    $empty['reason'] = $comparison['reason'];
    if ($db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='rcn_meta'")->fetchColumn()) {
        $empty['synced_at'] = $db->query("SELECT value FROM rcn_meta WHERE key='synced_at'")->fetchColumn() ?: null;
    }
    if (count($selected) < 5) { $empty['reason'] = 'too_few'; return $empty; }
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

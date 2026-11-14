<?php
declare(strict_types=1);

// Display only: uses exactly the sample and outlier policy of the existing valuation.
function rcn_chart_render(array $local, array $city, float $area, ?int $asking): string {
    $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    if (!is_finite($area) || $area < 15 || $area > 250) return '';
    $citywide = empty($local['available']);
    $sample = $citywide ? $city : $local;
    $rows = array_values(array_filter($sample['chart_rows'] ?? [], static function (array $r): bool {
        $date = (string)($r['sold_at'] ?? '');
        $price = (float)($r['price_m2'] ?? 0);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) === 1
            && strtotime($date . ' UTC') !== false && $date <= gmdate('Y-m-d')
            && is_finite($price) && $price > 0;
    }));
    if (empty($sample['available']) || count($rows) < 5) {
        return '<div class="rcn-chart rcn-chart-empty"><h3>Cena oferty na tle RCN</h3><p>Za mało transakcji do wykresu. Potrzeba co najmniej 5 porównywalnych sprzedaży. Uzupełnij ulicę i metraż lub sprawdź zakres danych RCN.</p></div>';
    }
    $values = array_column($rows, 'price_m2');
    sort($values, SORT_NUMERIC);
    $median = valuation_percentile($values, .5);
    $q1 = valuation_percentile($values, .25);
    $q3 = valuation_percentile($values, .75);
    $askingM2 = $asking !== null && $asking > 0 ? $asking / $area : null;
    $extent = $values;
    if ($askingM2 !== null) $extent[] = $askingM2;
    $min = max(0, min($extent) - (max($extent) - min($extent)) * .15 - 100);
    $max = max($extent) + (max($extent) - min($extent)) * .15 + 100;
    $dates = array_column($rows, 'sold_at');
    $first = min($dates); $last = max($dates);
    $start = strtotime($first . ' UTC'); $finish = strtotime($last . ' UTC');
    $y = static fn(float $v): string => number_format(256 - ($v - $min) / ($max - $min) * 220, 2, '.', '');
    $money = static fn(float $v): string => number_format($v, 0, ',', ' ');
    $scope = $citywide ? 'Cały Kwidzyn · porównanie orientacyjne' : (string)($local['scope'] ?? 'Do 500 m od wskazanej ulicy');
    $description = count($rows) . ' transakcji, ' . $first . ' – ' . $last . '. Mediana ' . $money($median) . ' zł/m².';
    if ($askingM2 !== null) $description .= ' Cena oferty ' . $money($askingM2) . ' zł/m².';
    ob_start();
    ?>
<figure class="rcn-chart">
<figcaption><h3>Cena oferty na tle RCN</h3><p><?= $e($scope) ?> · metraż ±20% · ostatnie 24 miesiące</p></figcaption>
<?php if ($citywide): ?><p class="rcn-chart-notice">Brak wystarczającej próby w okolicy ulicy. Wykres obejmuje całe miasto i nie uwzględnia różnic między dzielnicami.</p><?php endif ?>
<div class="rcn-chart-stats"><div><span>Cena oferty</span><strong><?= $askingM2 !== null ? $money($askingM2) . ' zł/m²' : 'Brak ceny' ?></strong></div><div><span>Mediana transakcji</span><strong><?= $money($median) ?> zł/m²</strong></div><div><span>Liczba transakcji</span><strong><?= count($rows) ?></strong></div></div>
<?php if ($askingM2 !== null): $difference = ($askingM2 / $median - 1) * 100; ?>
<p class="rcn-chart-difference">Cena ofertowa <?= abs($difference) < .05 ? 'równa medianie' : 'o ' . number_format(abs($difference), 1, ',', ' ') . '% ' . ($difference > 0 ? 'powyżej' : 'poniżej') . ' mediany' ?><?= $citywide ? ' dla całego miasta' : ' porównywanych transakcji' ?>. To różnica cen, nie ocena okazji.</p>
<?php endif ?>
<svg viewBox="0 0 800 310" role="img" aria-label="<?= $e($description) ?>" class="rcn-chart-plot" xmlns="http://www.w3.org/2000/svg">
<title><?= $e($description) ?></title>
<text x="74" y="18" class="rcn-axis">Cena brutto za m² (zł)</text>
<?php for ($i = 0; $i <= 4; ++$i): $tick = $min + ($max - $min) * $i / 4; ?>
<line x1="74" y1="<?= $y($tick) ?>" x2="776" y2="<?= $y($tick) ?>" stroke="#dce7e2"/>
<text x="64" y="<?= (float)$y($tick) + 5 ?>" text-anchor="end" class="rcn-axis"><?= $money($tick) ?></text>
<?php endfor ?>
<rect x="74" y="<?= $y($q3) ?>" width="702" height="<?= max(1, (float)$y($q1) - (float)$y($q3)) ?>" fill="#dcefe4"/>
<line x1="74" y1="<?= $y($median) ?>" x2="776" y2="<?= $y($median) ?>" stroke="#17694f" stroke-width="2"/>
<?php foreach ($rows as $row): $stamp = strtotime($row['sold_at'] . ' UTC'); $x = $finish > $start ? 80 + ($stamp - $start) / ($finish - $start) * 690 : 425; ?>
<circle cx="<?= number_format($x, 2, '.', '') ?>" cy="<?= $y($row['price_m2']) ?>" r="4" fill="#17694f" fill-opacity=".65"><title><?= $e($row['sold_at']) ?> · <?= $money($row['price_m2']) ?> zł/m²</title></circle>
<?php endforeach ?>
<?php if ($askingM2 !== null): ?><line x1="74" y1="<?= $y($askingM2) ?>" x2="776" y2="<?= $y($askingM2) ?>" stroke="#b65c22" stroke-width="3" stroke-dasharray="9 5"/><?php endif ?>
<text x="74" y="282" class="rcn-axis"><?= $e($first) ?></text><text x="776" y="282" text-anchor="end" class="rcn-axis"><?= $e($last) ?></text>
<text x="425" y="304" text-anchor="middle" class="rcn-axis">Data sprzedaży</text>
</svg>
<div class="rcn-chart-legend"><span>● Transakcje RCN</span><span><i class="rcn-line"></i> Mediana</span><span><i class="rcn-band"></i> Środkowe 50% cen</span><?php if ($askingM2 !== null): ?><span><i class="rcn-asking"></i> Cena oferty</span><?php endif ?></div>
<p class="rcn-chart-footnote">Sprzedaże: <?= $e($first) ?> – <?= $e($last) ?> · wyłączone ceny skrajne: <?= (int)($sample['excluded_count'] ?? 0) ?>. Źródło: GUGiK / RCN.<?php if (!empty($local['synced_at'])): ?> Dane pobrano <?= $e((string)$local['synced_at']) ?> UTC.<?php endif ?> Punkty nie są cenami ogłoszeń. Stan lokalu, piętro i budynek mogą się różnić; wykres nie jest wyceną ani prognozą ceny sprzedaży.</p>
</figure>
<?php
    return (string)ob_get_clean();
}

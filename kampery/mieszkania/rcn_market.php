<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app.php';
require __DIR__ . '/core.php';
require __DIR__ . '/rcn.php';
require __DIR__ . '/valuation_core.php';
session_name('kampery_session');
session_set_cookie_params(['httponly' => true, 'secure' => true, 'samesite' => 'Lax']);
session_start();
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
if (empty($_SESSION['auth'])) { header('Location: ../index.php'); exit; }
$db = homes_db();
$area = filter_var($_GET['area'] ?? '45', FILTER_VALIDATE_FLOAT);
if ($area === false || $area < 15 || $area > 250) $area = 45.0;
$street = isset($_GET['street']) && is_string($_GET['street']) ? mb_substr(trim($_GET['street']), 0, 100) : '';
$exists = $db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='rcn_transactions'")->fetchColumn();
$rows = [];
$synced = null;
$scope = 'Podaj ulicę, aby wyznaczyć promień 500 m.';
if ($exists) {
    $comparison = valuation_comparables($db, (float)$area, $street);
    $rows = $comparison['rows'];
    $scope = $comparison['scope'];
    $synced = $db->query("SELECT value FROM rcn_meta WHERE key='synced_at'")->fetchColumn();
}
function rcn_money(int $value): string { return number_format($value, 0, ',', ' ') . ' zł'; }
?>
<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Transakcje RCN · Kwidzyn · IFS</title>
<style>:root{color-scheme:light}*{box-sizing:border-box}body{margin:0;background:#f4f7f6;color:#15312e;font:16px/1.5 system-ui,-apple-system,sans-serif}header{background:#123c38;color:#fff;padding:22px}header div,main{max-width:1100px;margin:auto}header a{color:#d7eee9}h1{margin:0;font-size:28px}main{padding:24px}.card{background:#fff;border:1px solid #dae5e1;border-radius:14px;padding:20px;margin:16px 0}a{color:#0b685a}label{display:inline-block;margin-right:12px}input{font:inherit;padding:8px;border:1px solid #b8cbc5;border-radius:8px;width:100px}button{font:inherit;background:#0c6958;color:#fff;border:0;border-radius:8px;padding:9px 14px;cursor:pointer}table{border-collapse:collapse;width:100%;font-size:14px}th,td{padding:9px;border-bottom:1px solid #e3ece8;text-align:left}th{background:#edf4f1}.muted{color:#637a75;font-size:14px}.table-wrap{overflow-x:auto}</style></head><body>
<header><div><h1>Transakcje mieszkań · Kwidzyn</h1><a href="watch.php">← Obserwowane oferty</a></div></header><main>
<div class="card"><form method="get"><label>Metraż analizowanego mieszkania (m²) <input name="area" type="number" min="15" max="250" step="0.1" value="<?= homes_e((string)$area) ?>"></label><label>Ulica (opcjonalnie) <input name="street" type="text" maxlength="100" style="width:180px" value="<?= homes_e($street) ?>"></label><button type="submit">Pokaż transakcje</button></form>
<p>Rynek wtórny · lokal mieszkalny · <?= homes_e($scope) ?> · ostatnie 24 miesiące · metraż od <?= number_format($area * 0.8, 1, ',', ' ') ?> do <?= number_format($area * 1.2, 1, ',', ' ') ?> m².</p>
<p class="muted">Źródło: <a href="https://www.geoportal.gov.pl/pl/dane/rejestr-cen-nieruchomosci-rcn/" target="_blank" rel="noopener noreferrer">GUGiK, Rejestr Cen Nieruchomości ↗</a><?= $synced ? ' · pobrano ' . homes_e($synced) . ' UTC' : ' · dane jeszcze niepobrane' ?>. Ceny mogą obejmować prawa związane z lokalem. Stan, wyposażenie, piętro i budynek mogą się różnić; część transakcji trafia do rejestru z opóźnieniem.</p></div>
<div class="card"><h2>Transakcje (<?= count($rows) ?>)</h2><?php if ($rows): ?><div class="table-wrap"><table><thead><tr><th>Data sprzedaży</th><th>Adres w RCN</th><th>Odległość od ulicy</th><th>Powierzchnia</th><th>Cena</th><th>Cena za m²</th></tr></thead><tbody><?php foreach ($rows as $row): ?><tr><td><?= homes_e($row['sold_at']) ?></td><td><?= homes_e(str_replace(['MSC:',';UL:',';NR_PORZ:'], ['', ', ul. ', ' '], $row['address'])) ?></td><td><?= (int)$row['distance_m'] ?> m</td><td><?= number_format((float)$row['area'], 1, ',', ' ') ?> m²</td><td><?= rcn_money((int)$row['price']) ?></td><td><?= rcn_money((int)round($row['price_m2'])) ?></td></tr><?php endforeach ?></tbody></table></div><?php else: ?><p>Brak transakcji w wybranym zakresie. Sprawdź nazwę ulicy.</p><?php endif ?></div>
</main></body></html>

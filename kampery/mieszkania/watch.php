<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app.php';
require __DIR__ . '/core.php';
require __DIR__ . '/rcn.php';
$config = config();
session_name('kampery_session');
session_set_cookie_params(['httponly' => true, 'secure' => true, 'samesite' => 'Lax']);
session_start();
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
if (empty($_SESSION['auth'])) { header('Location: ../index.php'); exit; }
$db = homes_db();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf($config);
    $url = (string)($_POST['url'] ?? '');
    $action = (string)($_POST['action'] ?? '');
    $known = $db->prepare('SELECT 1 FROM homes WHERE url=?');
    $known->execute([$url]);
    if (!$known->fetchColumn()) { http_response_code(400); exit('Nieznana oferta.'); }
    if ($action === 'add') {
        $db->prepare('INSERT OR IGNORE INTO watched(url) VALUES (?)')->execute([$url]);
    } elseif ($action === 'remove') {
        $db->prepare('DELETE FROM watched WHERE url=?')->execute([$url]);
    } elseif ($action === 'save') {
        $values = [];
        foreach (['purchase_cost', 'renovation', 'reserve_cost', 'resale_target'] as $field) {
            $raw = trim((string)($_POST[$field] ?? '0'));
            if ($raw === '') $raw = '0';
            if (!ctype_digit($raw) || (float)$raw > 100000000) { http_response_code(400); exit('Podaj poprawne kwoty.'); }
            $values[] = (int)$raw;
        }
        $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 1000);
        $db->prepare('UPDATE watched SET purchase_cost=?,renovation=?,reserve_cost=?,resale_target=?,note=? WHERE url=?')
            ->execute(array_merge($values, [$note, $url]));
    } else { http_response_code(400); exit('Nieznana operacja.'); }
    header('Location: watch.php'); exit;
}
$watched = $db->query("SELECT h.*,w.saved_at,w.purchase_cost,w.renovation,w.reserve_cost,w.resale_target,w.note FROM watched w JOIN homes h ON h.url=w.url ORDER BY CASE WHEN h.title LIKE 'Kwidzyn%' THEN 0 ELSE 1 END,w.saved_at DESC,h.title ASC")->fetchAll(PDO::FETCH_ASSOC);
$candidates = $db->query("SELECT url,price,area,rooms,street FROM homes WHERE source<>'Adradar' AND price BETWEEN 50000 AND 2000000 AND area BETWEEN 15 AND 250 AND rooms BETWEEN 1 AND 8")->fetchAll(PDO::FETCH_ASSOC);
$histQuery = $db->prepare('SELECT old_price,new_price,changed_at FROM price_history WHERE url=? ORDER BY id DESC LIMIT 8');
$rcnSynced = $db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='rcn_meta'")->fetchColumn()
    ? $db->query("SELECT value FROM rcn_meta WHERE key='synced_at'")->fetchColumn() : false;
function homes_money(int $value): string { return number_format($value, 0, ',', ' ') . ' zł'; }
function homes_benchmark(array $home, array $candidates): array {
    if ($home['source'] === 'Adradar' && mb_stripos($home['title'], 'Kwidzyn') === false) return [null, 0];
    if (!$home['area'] || !$home['rooms']) return [null, 0];
    $values = []; $seen = [];
    foreach ($candidates as $item) {
        if ($item['url'] === $home['url'] || (int)$item['rooms'] !== (int)$home['rooms']) continue;
        $area = (float)$item['area'];
        if ($area < (float)$home['area'] * 0.8 || $area > (float)$home['area'] * 1.2) continue;
        $signature = (int)$item['price'] . '|' . round($area, 1) . '|' . (int)$item['rooms'] . '|' . mb_strtolower(trim((string)$item['street']));
        if (isset($seen[$signature])) continue;
        $seen[$signature] = true;
        $values[] = (int)$item['price'] / $area;
    }
    sort($values, SORT_NUMERIC);
    $n = count($values);
    if ($n < 5) return [null, $n];
    $median = $n % 2 ? $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2;
    return [(int)round($median * (float)$home['area']), $n];
}
?>
<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Obserwowane mieszkania · IFS</title>
<style>:root{color-scheme:light}*{box-sizing:border-box}body{margin:0;background:#f4f7f6;color:#15312e;font:16px/1.5 system-ui,-apple-system,sans-serif}header{background:#123c38;color:white;padding:22px}header div,main{max-width:1100px;margin:auto}header a{color:#d7eee9}h1{margin:0;font-size:28px}h2{font-size:21px}main{padding:24px}.card{background:#fff;border:1px solid #dae5e1;border-radius:14px;padding:20px;margin:16px 0;box-shadow:0 2px 8px #1a44330b}.offer{display:grid;grid-template-columns:230px 1fr;gap:20px}.photo{width:100%;height:180px;object-fit:cover;border-radius:9px;background:#e7efec}.price{font-size:24px;font-weight:750;color:#0e604d}.muted{color:#637a75;font-size:14px}.bad{color:#a32626}.good{color:#16744c}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px}label{display:block;font-size:13px;color:#52716d}input,textarea{width:100%;border:1px solid #b8cbc5;border-radius:8px;padding:9px;font:inherit;color:#15312e}textarea{min-height:75px}button{background:#0c6958;color:#fff;border:0;border-radius:9px;padding:10px 16px;font:inherit;cursor:pointer}.secondary{background:#e8f2ee;color:#165546}a{color:#147668}form{margin-top:15px}.result{padding:12px;background:#eaf4ef;border-radius:9px;margin:12px 0}@media(max-width:650px){.offer{grid-template-columns:1fr}.photo{height:220px}}</style></head><body>
<header><div><h1>Obserwowane mieszkania</h1><a href="./">← Wszystkie oferty</a></div></header><main>
<p>Historia monitora IFS i zaimportowana historia Adradar są pokazane oddzielnie. Porównanie cen ofertowych korzysta z mieszkań o tej samej liczbie pokoi i metrażu ±20%; import Adradar nie jest dodatkową próbką. Porównanie RCN korzysta z cen transakcyjnych lokali mieszkalnych na rynku wtórnym w Kwidzynie z ostatnich 24 miesięcy i metrażu ±20%. To statystyka dla miasta, nie wycena konkretnego mieszkania.</p>
<p class="muted">Źródło cen transakcyjnych: <a href="https://www.geoportal.gov.pl/pl/dane/rejestr-cen-nieruchomosci-rcn/" target="_blank" rel="noopener noreferrer">GUGiK, Rejestr Cen Nieruchomości ↗</a><?= $rcnSynced ? ' · pobrano ' . homes_e($rcnSynced) . ' UTC' : ' · dane jeszcze niepobrane' ?>.</p>
<p><a href="import_adradar.php">Importuj historię Adradar →</a></p>
<p><a href="rcn_market.php">Przeglądaj transakcje RCN w Kwidzynie →</a></p>
<p><strong><?= count($watched) ?></strong> obserwowanych ofert</p>
<?php foreach ($watched as $home): [$benchmark, $sample] = homes_benchmark($home, $candidates); [$rcnBenchmark, $rcnSample, $rcnPriceM2, $rcnDates] = homes_rcn_benchmark($db, $home); $histQuery->execute([$home['url']]); $history = $histQuery->fetchAll(PDO::FETCH_ASSOC); $resale = (int)$home['resale_target'] > 0 ? (int)$home['resale_target'] : ($rcnBenchmark ?? $benchmark); $investment = (int)$home['price'] + (int)$home['purchase_cost'] + (int)$home['renovation'] + (int)$home['reserve_cost']; $profit = $resale !== null && $home['price'] ? $resale - $investment : null; ?>
<article class="card offer"><div><?php if ($home['image_url']): ?><img class="photo" src="<?= homes_e($home['image_url']) ?>" alt="" loading="lazy" referrerpolicy="no-referrer"><?php endif ?></div><div><h2><?= homes_e($home['title']) ?></h2><p class="muted"><?= homes_e($home['source']) ?> · obserwowane od <?= homes_e($home['saved_at']) ?> UTC</p><div class="price"><?= $home['price'] ? homes_money((int)$home['price']) : 'Cena do sprawdzenia' ?></div><p><?= $home['area'] ? number_format((float)$home['area'], 1, ',', ' ') . ' m²' : 'Brak metrażu' ?><?= $home['rooms'] ? ' · ' . (int)$home['rooms'] . ' pok.' : '' ?><?= $home['street'] ? ' · ' . homes_e($home['street']) : '' ?></p><p><a href="<?= homes_e($home['url']) ?>" target="_blank" rel="noopener noreferrer">Otwórz ofertę w portalu ↗</a> · <a href="history.php?url=<?= rawurlencode($home['url']) ?>">Historia ogłoszenia</a></p>
<div class="result"><b>RCN · mediana cen transakcyjnych:</b> <?= $rcnBenchmark !== null ? homes_money($rcnBenchmark) . ' · ' . homes_money($rcnPriceM2) . '/m² · ' . $rcnSample . ' transakcji (' . homes_e($rcnDates[0]) . '–' . homes_e($rcnDates[1]) . ')' : 'Za mało porównywalnych transakcji (' . $rcnSample . ').' ?><br><b>Porównanie cen ofertowych:</b> <?= $benchmark !== null ? homes_money($benchmark) . ' · ' . $sample . ' podobnych ofert' : 'Za mało porównywalnych ofert (' . $sample . ').' ?><br><b>Wynik symulacji:</b> <?php if ($profit !== null): ?><strong class="<?= $profit >= 0 ? 'good' : 'bad' ?>"><?= homes_money($profit) ?></strong> · <?= $investment > 0 ? number_format(100 * $profit / $investment, 1, ',', ' ') . '% kosztu całkowitego' : '' ?><?= (int)$home['resale_target'] ? ' · według Twojej ceny sprzedaży' : ($rcnBenchmark !== null ? ' · według mediany transakcji RCN' : ' · według porównania ofert') ?><?php else: ?>uzupełnij cenę sprzedaży i cenę oferty<?php endif ?><br><span class="muted">Sprzedaż minus cena zakupu, koszty transakcji, remont i rezerwa. Podatki, prowizje sprzedaży i finansowanie uwzględnij w kosztach, jeśli wystąpią. RCN nie uwzględnia stanu i wyposażenia oglądanego mieszkania.</span></div>
<?php if ($rcnBenchmark !== null): ?><p><a href="rcn_market.php?area=<?= rawurlencode((string)$home['area']) ?>">Zobacz transakcje RCN użyte w analizie →</a></p><?php endif ?>
<form method="post" class="grid"><input type="hidden" name="csrf" value="<?= homes_e(csrf_token($config)) ?>"><input type="hidden" name="url" value="<?= homes_e($home['url']) ?>"><div><label>Koszty transakcji (zł)<input type="number" name="purchase_cost" min="0" max="100000000" value="<?= (int)$home['purchase_cost'] ?>"></label></div><div><label>Remont (zł)<input type="number" name="renovation" min="0" max="100000000" value="<?= (int)$home['renovation'] ?>"></label></div><div><label>Rezerwa (zł)<input type="number" name="reserve_cost" min="0" max="100000000" value="<?= (int)$home['reserve_cost'] ?>"></label></div><div><label>Moja cena sprzedaży (zł)<input type="number" name="resale_target" min="0" max="100000000" value="<?= (int)$home['resale_target'] ?>"></label></div><div style="grid-column:1/-1"><label>Notatka<textarea name="note" maxlength="1000"><?= homes_e($home['note']) ?></textarea></label></div><div><button name="action" value="save" type="submit">Zapisz analizę</button></div></form>
<p><b>Historia ceny od monitorowania:</b> <?php if (!$history): ?>bez wykrytych zmian<?php else: ?><?php foreach ($history as $change): ?><?= homes_money((int)$change['old_price']) ?> → <?= homes_money((int)$change['new_price']) ?> (<?= homes_e($change['changed_at']) ?> UTC); <?php endforeach ?><?php endif ?></p>
<form method="post"><input type="hidden" name="csrf" value="<?= homes_e(csrf_token($config)) ?>"><input type="hidden" name="url" value="<?= homes_e($home['url']) ?>"><button class="secondary" name="action" value="remove" type="submit">Usuń z obserwowanych</button></form>
</div></article>
<?php endforeach ?><?php if (!$watched): ?><div class="card">Nie obserwujesz jeszcze ofert. Otwórz <a href="./">listę mieszkań</a> i wybierz „Obserwuj i wyceń”.</div><?php endif ?>
</main></body></html>

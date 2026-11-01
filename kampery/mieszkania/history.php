<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app.php';
require __DIR__ . '/core.php';
session_name('kampery_session');
session_set_cookie_params(['httponly' => true, 'secure' => true, 'samesite' => 'Lax']);
session_start();
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
if (empty($_SESSION['auth'])) { header('Location: ../index.php'); exit; }
$db = homes_db();
$url = (string)($_GET['url'] ?? '');
if (strlen($url) > 2048) { http_response_code(400); exit('Niepoprawny adres oferty.'); }
$find = $db->prepare('SELECT * FROM homes WHERE url=?');
$find->execute([$url]);
$home = $find->fetch(PDO::FETCH_ASSOC);
if (!$home) { http_response_code(404); exit('Nie znaleziono oferty.'); }
$adradar = null;
$adradarLinks = [];
if ($home['source'] === 'Adradar' && $db->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='adradar_offers'")->fetchColumn()) {
    $imported = $db->prepare('SELECT * FROM adradar_offers WHERE group_url=?');
    $imported->execute([$url]);
    $adradar = $imported->fetch(PDO::FETCH_ASSOC) ?: null;
    $importedLinks = $db->prepare('SELECT * FROM adradar_history WHERE group_url=? ORDER BY row_number ASC LIMIT 1000');
    $importedLinks->execute([$url]);
    $adradarLinks = $importedLinks->fetchAll(PDO::FETCH_ASSOC);
}
$prices = $db->prepare('SELECT old_price,new_price,changed_at,id FROM price_history WHERE url=? ORDER BY id DESC LIMIT 100');
$prices->execute([$url]);
$priceChanges = $prices->fetchAll(PDO::FETCH_ASSOC);
$events = $db->prepare('SELECT kind,old_value,new_value,occurred_at,id FROM offer_events WHERE url=? ORDER BY id DESC LIMIT 100');
$events->execute([$url]);
$fieldChanges = $events->fetchAll(PDO::FETCH_ASSOC);
$timeline = [];
foreach ($priceChanges as $change) $timeline[] = [
    'time' => $change['changed_at'], 'kind' => 'price', 'old' => $change['old_price'], 'new' => $change['new_price'], 'id' => $change['id']
];
foreach ($fieldChanges as $change) $timeline[] = [
    'time' => $change['occurred_at'], 'kind' => $change['kind'], 'old' => $change['old_value'], 'new' => $change['new_value'], 'id' => $change['id']
];
usort($timeline, static fn(array $a, array $b): int => strcmp($b['time'], $a['time']) ?: ($b['id'] <=> $a['id']));
$initialPrice = $db->prepare('SELECT old_price FROM price_history WHERE url=? ORDER BY id ASC LIMIT 1');
$initialPrice->execute([$url]);
$savedInitialPrice = $initialPrice->fetchColumn();
$hadPriceAdded = false;
foreach ($fieldChanges as $change) if ($change['kind'] === 'price_added') $hadPriceAdded = true;
$firstPrice = $savedInitialPrice !== false ? (int)$savedInitialPrice : ($hadPriceAdded ? null : ($home['price'] !== null ? (int)$home['price'] : null));
function history_money(int $value): string { return number_format($value, 0, ',', ' ') . ' zł'; }
$labels = ['title' => 'Zmiana tytułu', 'area' => 'Zmiana metrażu', 'rooms' => 'Zmiana liczby pokoi', 'street' => 'Zmiana ulicy', 'price_added' => 'Uzupełniono cenę', 'returned' => 'Ponownie wykryto po przerwie'];
?>
<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Historia ogłoszenia · IFS</title>
<style>:root{color-scheme:light}*{box-sizing:border-box}body{margin:0;background:#f4f7f6;color:#17342e;font:16px/1.5 system-ui,-apple-system,sans-serif}header{background:#123c38;color:white;padding:22px}header div,main{max-width:1000px;margin:auto}header a{color:#d7eee9}h1{margin:0;font-size:28px}main{padding:24px}.card{background:#fff;border:1px solid #dae5e1;border-radius:14px;padding:20px;margin:16px 0}.summary{display:grid;grid-template-columns:180px 1fr;gap:20px}.photo{width:180px;height:150px;object-fit:cover;border-radius:9px;background:#e7efec}.price{font-size:25px;font-weight:750;color:#0e604d}.muted{color:#637a75;font-size:14px}.facts{display:flex;gap:22px;flex-wrap:wrap}.facts div{min-width:150px}.facts b{display:block}.timeline{list-style:none;padding:0;margin:0}.timeline li{border-left:3px solid #bddbd1;padding:0 0 20px 18px;margin-left:5px;position:relative}.timeline li:before{content:'';position:absolute;left:-7px;top:4px;width:11px;height:11px;border-radius:50%;background:#0d755f}.timeline time{font-size:13px;color:#637a75}.timeline b{display:block}.change{overflow-wrap:anywhere}a{color:#0b685a}@media(max-width:650px){.summary{grid-template-columns:1fr}.photo{width:100%;height:220px}}</style></head><body>
<header><div><h1>Historia ogłoszenia</h1><a href="./">← Oferty mieszkań</a> · <a href="watch.php">Obserwowane</a></div></header><main>
<article class="card summary"><div><?php if ($home['image_url']): ?><img class="photo" src="<?= homes_e($home['image_url']) ?>" alt="" referrerpolicy="no-referrer"><?php endif ?></div><div><div class="muted"><?= homes_e($home['source']) ?></div><h2><?= homes_e($home['title']) ?></h2><div class="price"><?= $home['price'] !== null ? history_money((int)$home['price']) : 'Cena do sprawdzenia' ?></div><p><?= $home['area'] !== null ? number_format((float)$home['area'],1,',',' ') . ' m²' : '' ?><?= $home['rooms'] !== null ? ' · ' . (int)$home['rooms'] . ' pok.' : '' ?><?= $home['street'] !== '' ? ' · ' . homes_e($home['street']) : '' ?></p><a href="<?= homes_e($home['url']) ?>" target="_blank" rel="noopener noreferrer">Otwórz ogłoszenie w portalu ↗</a></div></article>
<section class="card"><h2>Monitoring IFS</h2><div class="facts"><div><span class="muted">Pierwszy zapis</span><b><?= homes_e($home['first_seen']) ?> UTC</b></div><div><span class="muted">Ostatnio wykryto</span><b><?= homes_e($home['last_seen']) ?> UTC</b></div><div><span class="muted">Wykryte zmiany ceny</span><b><?= count($priceChanges) ?></b></div></div><p class="muted">Historia dotyczy tego adresu ogłoszenia i danych dostępnych od pierwszego zapisu w IFS. „Ostatnio wykryto” oznacza obecność w wynikach skanowania, a nie potwierdzenie aktualności oferty. Ogłoszenia z różnych portali mogą dotyczyć tego samego mieszkania.</p></section>
<?php if ($adradar): $adradarSummary = json_decode($adradar['summary_json'], true) ?: []; ?><section class="card"><h2>Historia z Adradar</h2><p class="muted">Import z <?= homes_e($adradar['imported_at']) ?> UTC. Dane archiwalne pochodzą z Adradar; dostępność i ceny sprawdź w portalu. Wiersze mogą obejmować kolejne aktualizacje tego samego ogłoszenia.</p><?php if ($adradar['status']): ?><p><b>Status przy imporcie:</b> <?= homes_e($adradar['status']) ?></p><?php endif ?><?php if ($adradar['description']): ?><p><?= nl2br(homes_e($adradar['description'])) ?></p><?php endif ?><?php if ($adradarSummary): ?><h3>Podsumowanie źródła</h3><ul><?php foreach ($adradarSummary as $point): ?><li><?= homes_e((string)$point) ?></li><?php endforeach ?></ul><?php endif ?><h3>Ogłoszenia i aktualizacje (<?= count($adradarLinks) ?>)</h3><div style="overflow-x:auto"><table style="border-collapse:collapse;width:100%;font-size:14px"><thead><tr><th>Cena</th><th>Oferent</th><th>Portal</th><th>Miasto</th><th>Ulica</th><th>Dodano</th><th>Zaktualizowano</th></tr></thead><tbody><?php foreach ($adradarLinks as $link): ?><tr><td><?= homes_e($link['price']) ?></td><td><?= homes_e($link['seller_type']) ?></td><td><?php if ($link['portal_url']): ?><a href="<?= homes_e($link['portal_url']) ?>" target="_blank" rel="noopener noreferrer"><?= homes_e((string)parse_url($link['portal_url'], PHP_URL_HOST)) ?></a><?php endif ?></td><td><?= homes_e($link['city']) ?></td><td><?= homes_e($link['street']) ?></td><td><?= homes_e($link['added']) ?></td><td><?= homes_e($link['updated']) ?></td></tr><?php endforeach ?></tbody></table></div></section><?php endif ?>
<section class="card"><h2>Oś historii</h2><ol class="timeline">
<?php foreach ($timeline as $entry): ?><li><time><?= homes_e($entry['time']) ?> UTC</time><b><?= $entry['kind'] === 'price' ? 'Zmiana ceny' : homes_e($labels[$entry['kind']] ?? 'Zmiana danych') ?></b><?php if ($entry['kind'] === 'price'): ?><div class="change"><?= history_money((int)$entry['old']) ?> → <?= history_money((int)$entry['new']) ?></div><?php elseif ($entry['kind'] === 'price_added'): ?><div class="change"><?= history_money((int)$entry['new']) ?></div><?php elseif ($entry['kind'] !== 'returned'): ?><div class="change"><?= $entry['old'] !== '' ? homes_e((string)$entry['old']) . ' → ' : '' ?><?= homes_e((string)$entry['new']) ?></div><?php endif ?></li><?php endforeach ?>
<li><time><?= homes_e($home['first_seen']) ?> UTC</time><b>Pierwszy zapis w IFS</b><?php if ($firstPrice !== null): ?><div><?= history_money($firstPrice) ?></div><?php endif ?></li>
</ol></section></main></body></html>

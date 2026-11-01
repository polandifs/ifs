<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app.php';
require __DIR__ . '/core.php';
require __DIR__ . '/push.php';
require __DIR__ . '/market_analysis.php';
$config = config();
session_name('kampery_session');
session_set_cookie_params(['httponly' => true, 'secure' => true, 'samesite' => 'Lax']);
session_start();
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
if (empty($_SESSION['auth'])) { header('Location: ../index.php'); exit; }
$db = homes_db();
$uniqueWhere = "source <> 'Adradar' AND (source <> 'Morizon' OR NOT EXISTS (SELECT 1 FROM homes g WHERE g.source='Gratka' AND g.title=homes.title AND g.price=homes.price AND g.area=homes.area)) AND (source <> 'Domiporta' OR NOT EXISTS (SELECT 1 FROM homes g WHERE g.source<> 'Domiporta' AND g.price=homes.price AND g.area IS NOT NULL AND ABS(g.area-homes.area)<0.15 AND g.rooms=homes.rooms AND g.street=homes.street AND g.street<>''))";
$allHomes = $db->query('SELECT *, (SELECT old_price FROM price_history p WHERE p.url=homes.url ORDER BY id DESC LIMIT 1) previous_price FROM homes WHERE ' . $uniqueWhere . ' ORDER BY first_seen DESC LIMIT 500')->fetchAll(PDO::FETCH_ASSOC);
$filters = [
    'q' => trim((string)($_GET['q'] ?? '')), 'source' => (string)($_GET['source'] ?? ''),
    'price_min' => (string)($_GET['price_min'] ?? ''), 'price_max' => (string)($_GET['price_max'] ?? ''),
    'area_min' => (string)($_GET['area_min'] ?? ''), 'area_max' => (string)($_GET['area_max'] ?? ''),
    'ppm_max' => (string)($_GET['ppm_max'] ?? ''), 'rooms' => (string)($_GET['rooms'] ?? ''),
    'sort' => (string)($_GET['sort'] ?? 'newest'),
];
foreach (['price_min','price_max','area_min','area_max','ppm_max','rooms'] as $key) {
    if ($filters[$key] !== '' && (!is_numeric($filters[$key]) || (float)$filters[$key] < 0)) $filters[$key] = '';
}
if (!in_array($filters['sort'], ['newest','price_asc','price_desc','ppm_asc','area_desc'], true)) $filters['sort'] = 'newest';
if ($filters['source'] !== '' && !array_key_exists($filters['source'], homes_sources()) && $filters['source'] !== 'Facebook') $filters['source'] = '';
$homes = array_values(array_filter($allHomes, static function (array $home) use ($filters): bool {
    if ($filters['q'] !== '' && mb_stripos($home['title'] . ' ' . $home['street'], $filters['q']) === false) return false;
    if ($filters['source'] !== '' && $home['source'] !== $filters['source']) return false;
    foreach (['price_min'=>'price','area_min'=>'area'] as $input => $field) if ($filters[$input] !== '' && ($home[$field] === null || (float)$home[$field] < (float)$filters[$input])) return false;
    foreach (['price_max'=>'price','area_max'=>'area'] as $input => $field) if ($filters[$input] !== '' && ($home[$field] === null || (float)$home[$field] > (float)$filters[$input])) return false;
    if ($filters['rooms'] !== '' && (int)$home['rooms'] !== (int)$filters['rooms']) return false;
    if ($filters['ppm_max'] !== '' && (!$home['price'] || !$home['area'] || $home['price'] / $home['area'] > (float)$filters['ppm_max'])) return false;
    return true;
}));
if ($filters['sort'] !== 'newest') usort($homes, static function (array $a, array $b) use ($filters): int {
    if ($filters['sort'] === 'area_desc') return (float)$b['area'] <=> (float)$a['area'];
    $field = $filters['sort'] === 'ppm_asc' ? 'ppm' : 'price';
    $x = $field === 'ppm' ? ($a['price'] && $a['area'] ? $a['price']/$a['area'] : PHP_INT_MAX) : ($a['price'] ?: PHP_INT_MAX);
    $y = $field === 'ppm' ? ($b['price'] && $b['area'] ? $b['price']/$b['area'] : PHP_INT_MAX) : ($b['price'] ?: PHP_INT_MAX);
    return $filters['sort'] === 'price_desc' ? $y <=> $x : $x <=> $y;
});
$scans = $db->query('SELECT s.* FROM scans s JOIN (SELECT source,MAX(id) id FROM scans GROUP BY source) m ON m.id=s.id ORDER BY s.source')->fetchAll(PDO::FETCH_ASSOC);
$count = (int)$db->query('SELECT COUNT(*) FROM homes WHERE ' . $uniqueWhere)->fetchColumn();
$watchedCount = (int)$db->query('SELECT COUNT(*) FROM watched')->fetchColumn();
$candidateCount = (int)$db->query('SELECT COUNT(*) FROM watched WHERE purchase_candidate=1')->fetchColumn();
$subs = (int)$db->query('SELECT COUNT(*) FROM subscriptions')->fetchColumn();
$cfg = homes_push_config();
$latest = (int)$db->query('SELECT COALESCE(MAX(id),0) FROM alerts')->fetchColumn();
$featured = null;
foreach ($homes as $candidate) {
    if (!empty($candidate['image_url']) && !empty($candidate['price'])) { $featured = $candidate; break; }
}
$opportunities = [];
$geoExists = (bool)$db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='rcn_geocodes'")->fetchColumn();
if ($geoExists) {
    $geoCheck = $db->prepare('SELECT 1 FROM rcn_geocodes WHERE street=? AND geometry_json<>\'[]\'');
    $checked = 0;
    foreach ($allHomes as $home) {
        if ($checked >= 24) break;
        if (empty($home['price']) || empty($home['area']) || empty($home['street']) || empty($home['image_url'])
            || (int)$home['price'] < 50000 || (int)$home['price'] > 2000000 || (float)$home['area'] < 15 || (float)$home['area'] > 250
            || $home['source'] === 'Facebook' || (string)$home['last_seen'] < gmdate('Y-m-d H:i:s', strtotime('-48 hours'))) continue;
        $geoCheck->execute([valuation_street((string)$home['street'])]);
        if (!$geoCheck->fetchColumn()) continue;
        $checked++;
        $market = market_valuation($db, (float)$home['area'], (string)$home['street'], $home['rooms'] !== null ? (int)$home['rooms'] : null, 'dobry', (string)$home['url']);
        if ($market['basis'] !== 'both') continue;
        $spread = (int)$market['estimate'] - (int)$home['price'];
        if ($spread < max(20000, (int)round((int)$home['price'] * .08))) continue;
        $home['price_gap'] = $spread;
        $home['rcn_count'] = (int)$market['rcn']['count'];
        $home['offers_count'] = (int)$market['listings']['count'];
        $opportunities[] = $home;
    }
    usort($opportunities, static fn(array $a, array $b): int => $b['price_gap'] <=> $a['price_gap']);
    $opportunities = array_slice($opportunities, 0, 3);
}
?>
<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Mieszkania Kwidzyn · IFS</title>
<style>
.offer-photo{display:block;width:100%;height:190px;object-fit:cover;border-radius:9px;margin-bottom:12px;background:#e7efec}
.filters{display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:12px}.filters label{display:block;font-size:13px;color:#52716d}.filters input,.filters select{width:100%;border:1px solid #b8cbc5;border-radius:8px;padding:10px;font:inherit;background:white;color:#15312e}.filter-actions{display:flex;gap:12px;align-items:end}.offer .prior{font-size:13px;color:#a32626}.offer .prior del{color:#71847f}.save-actions{display:flex;gap:8px;flex-wrap:wrap}.save-actions .candidate-action{background:#fff2ce;color:#654700;border:1px solid #e9d392}
:root{color-scheme:light}*{box-sizing:border-box}body{margin:0;background:#f4f7f6;color:#15312e;font:16px/1.5 system-ui,-apple-system,sans-serif}main{max-width:1100px;margin:auto;padding:24px}header{background:#123c38;color:white;padding:22px}header .wrap{max-width:1100px;margin:auto;display:flex;justify-content:space-between;align-items:center;gap:15px}a{color:#147668}header a{color:#d7eee9}h1{margin:0;font-size:28px}h2{font-size:21px}p{margin:7px 0}.lead{color:#52716d}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px}.card,.offer{background:white;border:1px solid #dae5e1;border-radius:14px;padding:18px;box-shadow:0 2px 8px #1a44330b}.card strong{font-size:24px}.ok{color:#16744c}.bad{color:#a32626}.muted{color:#637a75;font-size:14px}.offers{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:12px}.offer h3{margin:0 0 9px;font-size:17px}.offer .price{font-size:22px;font-weight:750;color:#0e604d}.offer a{font-weight:650}button{background:#0c6958;color:white;border:0;border-radius:9px;padding:11px 16px;font:inherit;cursor:pointer}button:disabled{background:#8ba49e;cursor:default}.tag{display:inline-block;background:#e3f2ec;color:#195d4b;padding:3px 9px;border-radius:99px;font-size:13px;margin-right:6px}section{margin:28px 0}
.workflow{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin:20px 0}.workflow a{display:block;text-decoration:none;color:#15312e;background:#fff;border:1px solid #dae5e1;border-radius:14px;padding:18px;box-shadow:0 2px 8px #1a44330b}.workflow strong{display:block;font-size:19px}.workflow span{display:block;color:#52716d;font-size:14px;margin-top:5px}.workflow a:hover{border-color:#0c6958}.utility{margin:18px 0}.utility>summary{font-weight:800;cursor:pointer;padding:14px 18px;background:#e7f0ec;border-radius:10px}.utility .card{margin:12px 0}</style><link rel="stylesheet" href="ui.css?v=2"></head><body class="page-list"><header><div class="wrap"><div><div class="eyebrow" style="color:#d8ed69">IFS · NIERUCHOMOŚCI</div><h1>Mieszkania w Kwidzynie</h1><div>Oferty sprzedaży · monitoring IFS</div></div><div><a href="../panel.php">Panel główny IFS</a> · <a href="../index.php">Panel kamperów</a></div></div></header><main>
<section class="dashboard-hero" aria-label="Przegląd ofert"><div class="hero-copy"><span class="eyebrow">MONITOR RYNKU · KWIDZYN</span><h2>Znajdź mieszkanie, które warto sprawdzić.</h2><p>Zdjęcia, ceny i historia w jednym miejscu. Nowe ogłoszenia są sprawdzane co 5 minut; dostępność i stan lokalu potwierdź w portalu.</p><a href="#oferty">Zobacz ogłoszenia ↓</a></div><?php if ($featured): ?><article class="hero-feature"><img src="<?= homes_e($featured['image_url']) ?>" alt="" referrerpolicy="no-referrer"><div class="hero-feature-content"><span class="eyebrow">OSTATNIO WYKRYTA OFERTA ZE ZDJĘCIEM</span><h3><a href="history.php?url=<?= rawurlencode($featured['url']) ?>#analiza"><?= homes_e($featured['title']) ?></a></h3><p><?= number_format((int)$featured['price'], 0, ',', ' ') ?> zł</p></div></article><?php endif ?></section>
<nav class="workflow" aria-label="Praca z mieszkaniami"><a href="#oferty"><strong>1 · Przeglądaj oferty</strong><span>Nowe mieszkania i filtry wyszukiwania</span></a><a href="watch.php"><strong>2 · Obserwowane</strong><span>Zapisane oferty, koszty i historia</span></a><a href="watch.php?view=candidates#zapisane"><strong>3 · Ulubione oferty</strong><span>Twoja wybrana lista · <?= $candidateCount ?></span></a><a href="wycena.php"><strong>4 · Wyceń i negocjuj</strong><span>Oferty + transakcje RCN + szkic wiadomości</span></a><a href="facebook.php"><strong>5 · Grupa Facebook</strong><span>Ogłoszenia nieruchomości · zapisz post</span></a></nav>
<section class="grid stats" aria-label="Stan monitora"><div class="card"><div class="muted">Oferty w bazie</div><strong><?= $count ?></strong></div><div class="card"><div class="muted">Podłączone źródła</div><strong><?= count(homes_sources()) ?> / <?= count(homes_requested_sources()) ?></strong></div><div class="card"><div class="muted">Obserwowane oferty</div><strong><?= $watchedCount ?></strong><p><a href="watch.php">Zobacz obserwowane →</a></p></div><div class="card stat-highlight"><div class="muted">Moje ulubione oferty</div><strong><?= $candidateCount ?></strong><p><a href="watch.php?view=candidates#zapisane">Otwórz ulubione →</a></p></div></section>
<section id="okazje" class="opportunity-section"><div class="section-heading"><div><span class="eyebrow">SYGNAŁY CENOWE</span><h2>Oferty do sprawdzenia</h2></div><p>Ostatnio widziane oferty, których cena jest niższa od mediany RCN przy zgodnym zakresie cen podobnych ogłoszeń. Różnica jest przed kosztami i nie uwzględnia stanu mieszkania.</p></div>
<?php if ($opportunities): ?><div class="opportunity-grid"><?php foreach ($opportunities as $home): ?><article class="opportunity"><a class="opportunity-image" href="history.php?url=<?= rawurlencode($home['url']) ?>#analiza"><img src="<?= homes_e($home['image_url']) ?>" alt="" loading="lazy" referrerpolicy="no-referrer"><span>Różnica +<?= number_format((int)$home['price_gap'], 0, ',', ' ') ?> zł</span></a><div class="opportunity-body"><div class="eyebrow"><?= homes_e($home['source']) ?> · <?= homes_e($home['street']) ?></div><h3><a href="history.php?url=<?= rawurlencode($home['url']) ?>#analiza"><?= homes_e($home['title']) ?></a></h3><strong><?= number_format((int)$home['price'], 0, ',', ' ') ?> zł</strong><p class="muted"><?= number_format((float)$home['area'], 1, ',', ' ') ?> m² · <?= (int)$home['rcn_count'] ?> transakcji RCN · <?= (int)$home['offers_count'] ?> podobnych ofert</p></div></article><?php endforeach ?></div><?php else: ?><div class="opportunity-empty">Wśród ostatnio widzianych ofert nie ma dziś wyraźnego sygnału cenowego z obu źródeł. <a href="watch.php">Zobacz analizę obserwowanych →</a></div><?php endif ?></section>
<details class="utility"><summary>Powiadomienia i ustawienia</summary><section class="card"><h2>Powiadomienia e-mail</h2><p>Nowe oferty i obniżki cen są wysyłane na <strong>mieszkania@ifs-poland.com</strong> po wykryciu zmiany w skanowaniu. Pierwszy odczyt nowego źródła tworzy listę początkową bez wysyłania wiadomości.</p></section>
<section class="card"><h2>Powiadomienia przeglądarki</h2><p>Włącz na urządzeniu, na którym chcesz odbierać nowe oferty. Przeglądarka poprosi o zgodę. Powiadomienia działają również po zamknięciu tej karty, jeśli przeglądarka i system zezwalają na działanie w tle.</p>
<button id="enable" <?= empty($cfg['public_key']) ? 'disabled' : '' ?>>Włącz powiadomienia</button> <button id="test" type="button">Pokaż próbne powiadomienie</button><p id="push-status" class="muted"><?= empty($cfg['public_key']) ? 'Konfiguracja powiadomień trwa.' : 'Powiadomienia nie są jeszcze włączone na tym urządzeniu.' ?></p></section>
</details>
<section class="card" id="filtry"><h2>Wyszukaj oferty</h2><form method="get" class="filters">
<div><label for="q">Ulica lub słowo</label><input id="q" name="q" value="<?= homes_e($filters['q']) ?>" placeholder="np. Polna"></div>
<div><label for="source">Portal / kanał</label><select id="source" name="source"><option value="">Wszystkie</option><?php foreach (homes_sources() as $name => $url): ?><option value="<?= homes_e($name) ?>" <?= $filters['source']===$name?'selected':'' ?>><?= homes_e($name) ?></option><?php endforeach ?><option value="Facebook" <?= $filters['source']==='Facebook'?'selected':'' ?>>Facebook · zapisane ręcznie</option></select></div>
<div><label for="price_min">Cena od (zł)</label><input id="price_min" name="price_min" type="number" min="0" value="<?= homes_e($filters['price_min']) ?>"></div>
<div><label for="price_max">Cena do (zł)</label><input id="price_max" name="price_max" type="number" min="0" value="<?= homes_e($filters['price_max']) ?>"></div>
<div><label for="area_min">Metraż od (m²)</label><input id="area_min" name="area_min" type="number" min="0" step="0.1" value="<?= homes_e($filters['area_min']) ?>"></div>
<div><label for="area_max">Metraż do (m²)</label><input id="area_max" name="area_max" type="number" min="0" step="0.1" value="<?= homes_e($filters['area_max']) ?>"></div>
<div><label for="ppm_max">Cena/m² do (zł)</label><input id="ppm_max" name="ppm_max" type="number" min="0" value="<?= homes_e($filters['ppm_max']) ?>"></div>
<div><label for="rooms">Pokoje</label><select id="rooms" name="rooms"><option value="">Dowolnie</option><?php for ($r=1;$r<=8;$r++): ?><option value="<?= $r ?>" <?= $filters['rooms']===(string)$r?'selected':'' ?>><?= $r ?></option><?php endfor ?></select></div>
<div><label for="sort">Sortowanie</label><select id="sort" name="sort"><option value="newest">Ostatnio wykryte</option><option value="price_asc" <?= $filters['sort']==='price_asc'?'selected':'' ?>>Najtańsze</option><option value="price_desc" <?= $filters['sort']==='price_desc'?'selected':'' ?>>Najdroższe</option><option value="ppm_asc" <?= $filters['sort']==='ppm_asc'?'selected':'' ?>>Najtańsze za m²</option><option value="area_desc" <?= $filters['sort']==='area_desc'?'selected':'' ?>>Największe</option></select></div>
<div class="filter-actions"><button type="submit">Filtruj</button><a href="./">Wyczyść</a></div>
</form></section>
<details class="utility"><summary>Status źródeł ofert</summary><section><h2>Źródła ofert</h2><p class="muted">Panel pokazuje osobno działające skanowanie i portale, których strony odrzucają połączenie z serwera. Brak skanowania nie oznacza, że na portalu nie ma ofert.</p><div class="grid"><?php foreach (homes_requested_sources() as $source => $url): $scan = null; foreach ($scans as $s) if ($s['source'] === $source) $scan = $s; $connected = array_key_exists($source, homes_sources()); ?><div class="card"><b><?= homes_e($source) ?></b><p class="<?= $connected && $scan && !$scan['error'] ? 'ok' : 'bad' ?>"><?= !$connected ? 'Serwer odrzucony przez portal (HTTP 403)' : (!$scan ? 'Jeszcze nie sprawdzono' : ($scan['error'] ? homes_e($scan['error']) : 'Działa · ' . (int)$scan['found'] . ' ofert')) ?></p><div class="muted"><?= $scan ? homes_e($scan['checked_at']) . ' UTC' : '' ?></div><a href="<?= homes_e($url) ?>" target="_blank" rel="noopener noreferrer">Otwórz źródło ↗</a></div><?php endforeach ?><div class="card"><b>Facebook · KWIDZYN Sprzedam Wynajme mieszkanie Okolice</b><p class="muted">Kanał ręczny · Facebook nie udostępnia postów do automatycznego odczytu.</p><a href="facebook.php">Otwórz kanał i zapisz post →</a></div></div></section></details>
<section id="oferty"><div class="section-heading"><div><span class="eyebrow">OGŁOSZENIA</span><h2>Oferty sprzedaży · <?= count($homes) ?></h2></div><p>Zdjęcia i ceny pochodzą z ogłoszeń. Daty oznaczają wykrycie przez IFS; dostępność potwierdź w portalu.</p></div><div class="offers">
<?php foreach ($homes as $home): $dropped = $home['price'] && $home['previous_price'] && (int)$home['previous_price'] <= 100000000 && (int)$home['previous_price'] > (int)$home['price']; ?>
<article class="offer"><a class="offer-media" href="history.php?url=<?= rawurlencode($home['url']) ?>#analiza" aria-label="Analiza: <?= homes_e($home['title']) ?>"><?php if ($home['image_url']): ?><img class="offer-photo" src="<?= homes_e($home['image_url']) ?>" alt="" loading="lazy" referrerpolicy="no-referrer"><?php else: ?><span class="photo-placeholder">Zdjęcie niedostępne</span><?php endif ?><span class="media-badge"><?= homes_e($home['source']) ?></span><?php if ($dropped): ?><span class="drop-badge">Obniżka ceny</span><?php endif ?></a>
<div class="offer-body"><div class="source-line"><span class="muted"><?= homes_e($home['street'] ?: 'Kwidzyn') ?></span><span class="muted"><?= homes_e(substr((string)$home['first_seen'], 0, 16)) ?> UTC</span></div><h3><?= homes_e($home['title']) ?></h3><div class="price"><?= $home['price'] ? number_format((int)$home['price'], 0, ',', ' ') . ' zł' : 'Cena do sprawdzenia' ?></div><?php if ($dropped): ?><div class="prior">Poprzednio <del><?= number_format((int)$home['previous_price'], 0, ',', ' ') ?> zł</del></div><?php endif ?>
<div class="chip-row"><?php if ($home['area']): ?><span class="fact-chip"><?= number_format((float)$home['area'], 1, ',', ' ') ?> m²</span><?php endif ?><?php if ($home['rooms']): ?><span class="fact-chip"><?= (int)$home['rooms'] ?> pok.</span><?php endif ?><?php if ($home['price'] && $home['area']): ?><span class="fact-chip"><?= number_format((int)round($home['price'] / $home['area']), 0, ',', ' ') ?> zł/m²</span><?php endif ?></div>
<p class="offer-links"><a href="history.php?url=<?= rawurlencode($home['url']) ?>#analiza">Analiza ceny →</a><a href="<?= homes_e($home['url']) ?>" target="_blank" rel="noopener noreferrer">Ogłoszenie ↗</a></p>
<form action="watch.php" method="post"><input type="hidden" name="csrf" value="<?= homes_e(csrf_token($config)) ?>"><input type="hidden" name="url" value="<?= homes_e($home['url']) ?>"><div class="save-actions"><button name="action" value="add" type="submit">Obserwuj</button><button class="candidate-action" name="action" value="add_candidate" type="submit">★ Do ulubionych</button></div></form></div></article>
<?php endforeach ?></div><?php if (!$homes): ?><p>Brak ofert spełniających filtry.</p><?php endif ?></section>

</main><script>
const key = <?= json_encode($cfg['public_key'] ?? '') ?>;
const csrf = <?= json_encode(csrf_token($config)) ?>;
const latest = <?= $latest ?>;
const status = document.getElementById('push-status');
function bytes(s){const pad='='.repeat((4-s.length%4)%4);const raw=atob((s+pad).replace(/-/g,'+').replace(/_/g,'/'));return Uint8Array.from(raw,c=>c.charCodeAt(0));}
document.getElementById('enable').onclick = async () => {
  try {
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) throw new Error('Ta przeglądarka nie obsługuje powiadomień push.');
    const permission = await Notification.requestPermission();
    if (permission !== 'granted') throw new Error('Zezwól na powiadomienia w ustawieniach przeglądarki.');
    const reg = await navigator.serviceWorker.register('./sw.js', {scope:'./'});
    const sub = await reg.pushManager.subscribe({userVisibleOnly:true,applicationServerKey:bytes(key)});
    const response = await fetch('./alerts.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify(sub)});
    if (!response.ok) throw new Error('Nie udało się zapisać urządzenia (HTTP '+response.status+').');
    status.textContent='Powiadomienia włączone na tym urządzeniu.';
  } catch(e) {status.textContent=e.message;}
};
document.getElementById('test').onclick = async () => {
  try {if(Notification.permission!=='granted') throw new Error('Najpierw włącz powiadomienia.');
    const reg=await navigator.serviceWorker.ready;
    await reg.showNotification('Próba: mieszkania Kwidzyn',{body:'Tak będą wyglądały powiadomienia o nowych ofertach.',data:{url:'./'}});
    status.textContent='Próbne powiadomienie wyświetlone.';
  } catch(e){status.textContent=e.message;}
};
</script></body></html>

<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app.php';
require __DIR__ . '/core.php';
require __DIR__ . '/push.php';
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
if ($filters['source'] !== '' && !array_key_exists($filters['source'], homes_sources())) $filters['source'] = '';
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
$subs = (int)$db->query('SELECT COUNT(*) FROM subscriptions')->fetchColumn();
$cfg = homes_push_config();
$latest = (int)$db->query('SELECT COALESCE(MAX(id),0) FROM alerts')->fetchColumn();
?>
<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Mieszkania Kwidzyn · IFS</title>
<style>
.offer-photo{display:block;width:100%;height:190px;object-fit:cover;border-radius:9px;margin-bottom:12px;background:#e7efec}
.filters{display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:12px}.filters label{display:block;font-size:13px;color:#52716d}.filters input,.filters select{width:100%;border:1px solid #b8cbc5;border-radius:8px;padding:10px;font:inherit;background:white;color:#15312e}.filter-actions{display:flex;gap:12px;align-items:end}.offer .prior{font-size:13px;color:#a32626}.offer .prior del{color:#71847f}
:root{color-scheme:light}*{box-sizing:border-box}body{margin:0;background:#f4f7f6;color:#15312e;font:16px/1.5 system-ui,-apple-system,sans-serif}main{max-width:1100px;margin:auto;padding:24px}header{background:#123c38;color:white;padding:22px}header .wrap{max-width:1100px;margin:auto;display:flex;justify-content:space-between;align-items:center;gap:15px}a{color:#147668}header a{color:#d7eee9}h1{margin:0;font-size:28px}h2{font-size:21px}p{margin:7px 0}.lead{color:#52716d}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px}.card,.offer{background:white;border:1px solid #dae5e1;border-radius:14px;padding:18px;box-shadow:0 2px 8px #1a44330b}.card strong{font-size:24px}.ok{color:#16744c}.bad{color:#a32626}.muted{color:#637a75;font-size:14px}.offers{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:12px}.offer h3{margin:0 0 9px;font-size:17px}.offer .price{font-size:22px;font-weight:750;color:#0e604d}.offer a{font-weight:650}button{background:#0c6958;color:white;border:0;border-radius:9px;padding:11px 16px;font:inherit;cursor:pointer}button:disabled{background:#8ba49e;cursor:default}.tag{display:inline-block;background:#e3f2ec;color:#195d4b;padding:3px 9px;border-radius:99px;font-size:13px;margin-right:6px}section{margin:28px 0}
</style></head><body><header><div class="wrap"><div><h1>Mieszkania w Kwidzynie</h1><div>Oferty sprzedaży · monitoring IFS</div></div><div><a href="../panel.php">Panel główny IFS</a> · <a href="../index.php">Panel kamperów</a></div></div></header><main>
<p class="lead">Nowe ogłoszenia z publicznych wyników wyszukiwania. Każde źródło jest sprawdzane przez serwer co 5 minut; publikacja i dostępność oferty wymagają potwierdzenia w portalu.</p>
<section class="card"><h2>Wycena mieszkania</h2><p>Wpisz ulicę, metraż, stan i koszty. Zobacz orientacyjny zakres cen na podstawie transakcji RCN w Kwidzynie.</p><p><a href="wycena.php">Otwórz moduł wyceny →</a></p></section>
<section class="grid"><div class="card"><div class="muted">Wykryte ogłoszenia</div><strong><?= $count ?></strong></div><div class="card"><div class="muted">Podłączone źródła</div><strong><?= count(homes_sources()) ?> / <?= count(homes_requested_sources()) ?></strong></div><div class="card"><div class="muted">Obserwowane oferty</div><strong><?= $watchedCount ?></strong><p><a href="watch.php">Otwórz listę i wyceny →</a></p></div><div class="card"><div class="muted">Urządzenia z powiadomieniami</div><strong><?= $subs ?></strong></div></section>
<section class="card"><h2>Powiadomienia e-mail</h2><p>Nowe oferty i obniżki cen są wysyłane na <strong>mieszkania@ifs-poland.com</strong> po wykryciu zmiany w skanowaniu. Pierwszy odczyt nowego źródła tworzy listę początkową bez wysyłania wiadomości.</p></section>
<section class="card"><h2>Powiadomienia przeglądarki</h2><p>Włącz na urządzeniu, na którym chcesz odbierać nowe oferty. Przeglądarka poprosi o zgodę. Powiadomienia działają również po zamknięciu tej karty, jeśli przeglądarka i system zezwalają na działanie w tle.</p>
<button id="enable" <?= empty($cfg['public_key']) ? 'disabled' : '' ?>>Włącz powiadomienia</button> <button id="test" type="button">Pokaż próbne powiadomienie</button><p id="push-status" class="muted"><?= empty($cfg['public_key']) ? 'Konfiguracja powiadomień trwa.' : 'Powiadomienia nie są jeszcze włączone na tym urządzeniu.' ?></p></section>
<section class="card"><h2>Wyszukaj oferty</h2><form method="get" class="filters">
<div><label for="q">Ulica lub słowo</label><input id="q" name="q" value="<?= homes_e($filters['q']) ?>" placeholder="np. Polna"></div>
<div><label for="source">Portal</label><select id="source" name="source"><option value="">Wszystkie</option><?php foreach (homes_sources() as $name => $url): ?><option value="<?= homes_e($name) ?>" <?= $filters['source']===$name?'selected':'' ?>><?= homes_e($name) ?></option><?php endforeach ?></select></div>
<div><label for="price_min">Cena od (zł)</label><input id="price_min" name="price_min" type="number" min="0" value="<?= homes_e($filters['price_min']) ?>"></div>
<div><label for="price_max">Cena do (zł)</label><input id="price_max" name="price_max" type="number" min="0" value="<?= homes_e($filters['price_max']) ?>"></div>
<div><label for="area_min">Metraż od (m²)</label><input id="area_min" name="area_min" type="number" min="0" step="0.1" value="<?= homes_e($filters['area_min']) ?>"></div>
<div><label for="area_max">Metraż do (m²)</label><input id="area_max" name="area_max" type="number" min="0" step="0.1" value="<?= homes_e($filters['area_max']) ?>"></div>
<div><label for="ppm_max">Cena/m² do (zł)</label><input id="ppm_max" name="ppm_max" type="number" min="0" value="<?= homes_e($filters['ppm_max']) ?>"></div>
<div><label for="rooms">Pokoje</label><select id="rooms" name="rooms"><option value="">Dowolnie</option><?php for ($r=1;$r<=8;$r++): ?><option value="<?= $r ?>" <?= $filters['rooms']===(string)$r?'selected':'' ?>><?= $r ?></option><?php endfor ?></select></div>
<div><label for="sort">Sortowanie</label><select id="sort" name="sort"><option value="newest">Ostatnio wykryte</option><option value="price_asc" <?= $filters['sort']==='price_asc'?'selected':'' ?>>Najtańsze</option><option value="price_desc" <?= $filters['sort']==='price_desc'?'selected':'' ?>>Najdroższe</option><option value="ppm_asc" <?= $filters['sort']==='ppm_asc'?'selected':'' ?>>Najtańsze za m²</option><option value="area_desc" <?= $filters['sort']==='area_desc'?'selected':'' ?>>Największe</option></select></div>
<div class="filter-actions"><button type="submit">Filtruj</button><a href="./">Wyczyść</a></div>
</form></section>
<section><h2>Źródła ofert</h2><p class="muted">Panel pokazuje osobno działające skanowanie i portale, których strony odrzucają połączenie z serwera. Brak skanowania nie oznacza, że na portalu nie ma ofert.</p><div class="grid"><?php foreach (homes_requested_sources() as $source => $url): $scan = null; foreach ($scans as $s) if ($s['source'] === $source) $scan = $s; $connected = array_key_exists($source, homes_sources()); ?><div class="card"><b><?= homes_e($source) ?></b><p class="<?= $connected && $scan && !$scan['error'] ? 'ok' : 'bad' ?>"><?= !$connected ? 'Serwer odrzucony przez portal (HTTP 403)' : (!$scan ? 'Jeszcze nie sprawdzono' : ($scan['error'] ? homes_e($scan['error']) : 'Działa · ' . (int)$scan['found'] . ' ofert')) ?></p><div class="muted"><?= $scan ? homes_e($scan['checked_at']) . ' UTC' : '' ?></div><a href="<?= homes_e($url) ?>" target="_blank" rel="noopener noreferrer">Otwórz źródło ↗</a></div><?php endforeach ?></div></section>
<section><h2>Oferty sprzedaży (<?= count($homes) ?> wyników)</h2><p class="muted">Czas „wykrycia” oznacza zapis w tym monitorze. Historia cen jest liczona od uruchomienia monitora. Możliwe duplikaty między portalami.</p><div class="offers"><?php foreach ($homes as $home): ?><article class="offer"><?php if ($home["image_url"]): ?><a href="<?= homes_e($home["url"]) ?>" target="_blank" rel="noopener noreferrer"><img class="offer-photo" src="<?= homes_e($home["image_url"]) ?>" alt="" loading="lazy" referrerpolicy="no-referrer"></a><?php endif; ?><div><span class="tag"><?= homes_e($home['source']) ?></span><span class="muted"><?= homes_e($home['first_seen']) ?> UTC</span></div><h3><?= homes_e($home['title']) ?></h3><div class="price"><?= $home['price'] ? number_format((int)$home['price'], 0, ',', ' ') . ' zł' : 'Cena do sprawdzenia' ?></div><?php if ($home['previous_price'] && (int)$home['previous_price'] <= 100000000 && (int)$home['previous_price'] > (int)$home['price']): ?><div class="prior">Obniżka z <del><?= number_format((int)$home['previous_price'],0,',',' ') ?> zł</del></div><?php endif ?><p class="muted"><?= $home['area'] ? number_format((float)$home['area'], 1, ',', ' ') . ' m²' : '' ?><?= $home['rooms'] ? ' · ' . (int)$home['rooms'] . ' pok.' : '' ?><?= $home['street'] ? ' · ' . homes_e($home['street']) : '' ?><?= $home['price'] && $home['area'] ? ' · ' . number_format((int)round($home['price']/$home['area']),0,',',' ') . ' zł/m²' : '' ?></p><p><a href="<?= homes_e($home['url']) ?>" target="_blank" rel="noopener noreferrer">Zobacz ogłoszenie ↗</a> · <a href="history.php?url=<?= rawurlencode($home['url']) ?>#analiza">Analiza ceny i historia</a></p><form action="watch.php" method="post"><input type="hidden" name="csrf" value="<?= homes_e(csrf_token($config)) ?>"><input type="hidden" name="url" value="<?= homes_e($home['url']) ?>"><button name="action" value="add" type="submit">Obserwuj i wyceń</button></form></article><?php endforeach ?></div><?php if (!$homes): ?><p>Brak ofert spełniających filtry.</p><?php endif ?></section>
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

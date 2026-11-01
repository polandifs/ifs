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
$homes = $db->query('SELECT * FROM homes ORDER BY first_seen DESC LIMIT 150')->fetchAll(PDO::FETCH_ASSOC);
$scans = $db->query('SELECT s.* FROM scans s JOIN (SELECT source,MAX(id) id FROM scans GROUP BY source) m ON m.id=s.id ORDER BY s.source')->fetchAll(PDO::FETCH_ASSOC);
$count = (int)$db->query('SELECT COUNT(*) FROM homes')->fetchColumn();
$subs = (int)$db->query('SELECT COUNT(*) FROM subscriptions')->fetchColumn();
$cfg = homes_push_config();
$latest = (int)$db->query('SELECT COALESCE(MAX(id),0) FROM alerts')->fetchColumn();
?>
<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Mieszkania Kwidzyn · IFS</title>
<style>
:root{color-scheme:light}*{box-sizing:border-box}body{margin:0;background:#f4f7f6;color:#15312e;font:16px/1.5 system-ui,-apple-system,sans-serif}main{max-width:1100px;margin:auto;padding:24px}header{background:#123c38;color:white;padding:22px}header .wrap{max-width:1100px;margin:auto;display:flex;justify-content:space-between;align-items:center;gap:15px}a{color:#147668}header a{color:#d7eee9}h1{margin:0;font-size:28px}h2{font-size:21px}p{margin:7px 0}.lead{color:#52716d}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px}.card,.offer{background:white;border:1px solid #dae5e1;border-radius:14px;padding:18px;box-shadow:0 2px 8px #1a44330b}.card strong{font-size:24px}.ok{color:#16744c}.bad{color:#a32626}.muted{color:#637a75;font-size:14px}.offers{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:12px}.offer h3{margin:0 0 9px;font-size:17px}.offer .price{font-size:22px;font-weight:750;color:#0e604d}.offer a{font-weight:650}button{background:#0c6958;color:white;border:0;border-radius:9px;padding:11px 16px;font:inherit;cursor:pointer}button:disabled{background:#8ba49e;cursor:default}.tag{display:inline-block;background:#e3f2ec;color:#195d4b;padding:3px 9px;border-radius:99px;font-size:13px;margin-right:6px}section{margin:28px 0}
</style></head><body><header><div class="wrap"><div><h1>Mieszkania w Kwidzynie</h1><div>Oferty sprzedaży · monitoring IFS</div></div><a href="../index.php">Panel kamperów</a></div></header><main>
<p class="lead">Nowe ogłoszenia z publicznych wyników wyszukiwania. Każde źródło jest sprawdzane przez serwer co 5 minut; publikacja i dostępność oferty wymagają potwierdzenia w portalu.</p>
<section class="grid"><div class="card"><div class="muted">Zapisane ogłoszenia</div><strong><?= $count ?></strong></div><div class="card"><div class="muted">Źródła</div><strong><?= count(homes_sources()) ?></strong></div><div class="card"><div class="muted">Urządzenia z powiadomieniami</div><strong><?= $subs ?></strong></div></section>
<section class="card"><h2>Powiadomienia przeglądarki</h2><p>Włącz na urządzeniu, na którym chcesz odbierać nowe oferty. Przeglądarka poprosi o zgodę. Powiadomienia działają również po zamknięciu tej karty, jeśli przeglądarka i system zezwalają na działanie w tle.</p>
<button id="enable" <?= empty($cfg['public_key']) ? 'disabled' : '' ?>>Włącz powiadomienia</button> <button id="test" type="button">Pokaż próbne powiadomienie</button><p id="push-status" class="muted"><?= empty($cfg['public_key']) ? 'Konfiguracja powiadomień trwa.' : 'Powiadomienia nie są jeszcze włączone na tym urządzeniu.' ?></p></section>
<section><h2>Ostatnie skanowania</h2><div class="grid"><?php foreach (homes_sources() as $source => $url): $scan = null; foreach ($scans as $s) if ($s['source'] === $source) $scan = $s; ?><div class="card"><b><?= homes_e($source) ?></b><p class="<?= $scan && !$scan['error'] ? 'ok' : 'bad' ?>"><?= !$scan ? 'Jeszcze nie sprawdzono' : ($scan['error'] ? homes_e($scan['error']) : 'Działa · ' . (int)$scan['found'] . ' ofert') ?></p><div class="muted"><?= $scan ? homes_e($scan['checked_at']) . ' UTC' : '' ?></div><a href="<?= homes_e($url) ?>" target="_blank" rel="noopener noreferrer">Otwórz źródło ↗</a></div><?php endforeach ?></div></section>
<section><h2>Ostatnio wykryte oferty</h2><div class="offers"><?php foreach ($homes as $home): ?><article class="offer"><div><span class="tag"><?= homes_e($home['source']) ?></span><span class="muted"><?= homes_e($home['first_seen']) ?> UTC</span></div><h3><?= homes_e($home['title']) ?></h3><div class="price"><?= $home['price'] ? number_format((int)$home['price'], 0, ',', ' ') . ' zł' : 'Cena do sprawdzenia' ?></div><p class="muted"><?= $home['area'] ? number_format((float)$home['area'], 1, ',', ' ') . ' m²' : '' ?><?= $home['rooms'] ? ' · ' . (int)$home['rooms'] . ' pok.' : '' ?><?= $home['street'] ? ' · ' . homes_e($home['street']) : '' ?></p><a href="<?= homes_e($home['url']) ?>" target="_blank" rel="noopener noreferrer">Zobacz ogłoszenie ↗</a></article><?php endforeach ?></div><?php if (!$homes): ?><p>Oferty pojawią się po pierwszym poprawnym skanowaniu.</p><?php endif ?></section>
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

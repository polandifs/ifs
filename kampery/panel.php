<?php
declare(strict_types=1);

require __DIR__ . '/app.php';
require __DIR__ . '/mieszkania/core.php';
require __DIR__ . '/mieszkania/market_analysis.php';
$config = config();
session_name('kampery_session');
session_set_cookie_params(['httponly' => true, 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'samesite' => 'Lax']);
session_start();
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: no-store');
if (empty($_SESSION['auth'])) {
    header('Location: index.php');
    exit;
}

$view = (string)($_GET['view'] ?? 'all');
if (!in_array($view, ['all', 'campers', 'homes'], true)) $view = 'all';
$perModuleLimit = $view === 'all' ? 6 : 12;
$offers = [];
$errors = [];
$camperCount = 0;
$homeCount = 0;
$camperScan = '';
$homeScan = '';
$camperOpportunities = [];
$homeOpportunities = [];
try {
    $camperDb = db();
    $camperCount = (int)$camperDb->query('SELECT COUNT(*) FROM discoveries')->fetchColumn();
    $camperScan = (string)$camperDb->query('SELECT MAX(scanned_at) FROM scan_runs')->fetchColumn();
    if ($view !== 'homes') {
        $rows = $camperDb->query('SELECT url, title, source, price_text, image_url, first_seen FROM discoveries ORDER BY first_seen DESC LIMIT ' . $perModuleLimit)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $offers[] = [
                'kind' => 'camper', 'url' => (string)$row['url'], 'title' => (string)$row['title'],
                'source' => (string)$row['source'], 'price' => (string)$row['price_text'],
                'image' => (string)$row['image_url'], 'seen' => (string)$row['first_seen'],
            ];
        }
        $candidates = $camperDb->query("SELECT d.url,d.title,d.source,d.signal,d.last_seen,c.price_pln,c.image_url AS catalog_image,d.image_url AS discovery_image,c.type,c.year,c.mileage_km
            FROM discoveries d JOIN catalog_offers c ON c.url=d.url
            WHERE d.source='WybierzKampera' AND d.last_seen>=datetime('now','-7 days')
              AND c.price_certainty='Potwierdzona' AND c.availability='od ręki' AND c.price_pln BETWEEN 30000 AND 300000
            ORDER BY CAST(d.signal AS INTEGER) DESC LIMIT 12")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($candidates as $row) {
            if (!preg_match('/^(\d+)% poniżej mediany typu, rocznika i przebiegu$/u', (string)$row['signal'], $match)) continue;
            $camperOpportunities[] = [
                'url'=>(string)$row['url'], 'title'=>(string)$row['title'], 'price'=>(int)$row['price_pln'],
                'image'=>(string)($row['discovery_image'] ?: $row['catalog_image']), 'discount'=>(int)$match[1],
                'type'=>(string)$row['type'], 'year'=>(int)$row['year'], 'seen'=>(string)$row['last_seen'],
            ];
        }
        $camperOpportunities = array_slice($camperOpportunities, 0, 4);
    }
} catch (Throwable $e) {
    error_log('IFS panel camper feed: ' . $e->getMessage());
    $errors[] = 'Nie można teraz odczytać ofert kamperów.';
}
try {
    $homeDb = homes_db();
    $homeCount = (int)$homeDb->query("SELECT COUNT(*) FROM homes WHERE source<>'Adradar'")->fetchColumn();
    $homeScan = (string)$homeDb->query('SELECT MAX(checked_at) FROM scans')->fetchColumn();
    if ($view !== 'campers') {
        $rows = $homeDb->query("SELECT url, title, source, price, image_url, first_seen FROM homes WHERE source<>'Adradar' ORDER BY first_seen DESC LIMIT " . $perModuleLimit)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $offers[] = [
                'kind' => 'home', 'url' => (string)$row['url'], 'title' => (string)$row['title'],
                'source' => (string)$row['source'], 'price' => $row['price'] !== null ? number_format((int)$row['price'], 0, ',', ' ') . ' zł' : '',
                'image' => (string)$row['image_url'], 'seen' => (string)$row['first_seen'],
            ];
        }
        // Only use streets already located in Kwidzyn. This keeps the main panel fast
        // and avoids requesting a geocode for every offer when the page is opened.
        $knownStreets = [];
        if ($homeDb->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='rcn_geocodes'")->fetchColumn()) {
            foreach ($homeDb->query("SELECT street FROM rcn_geocodes WHERE geometry_json<>'[]' AND checked_at>=datetime('now','-30 days')") as $row) {
                $knownStreets[(string)$row['street']] = true;
            }
        }
        if ($knownStreets) {
            $candidates = $homeDb->query("SELECT h.url,h.title,h.source,h.price,h.area,h.rooms,h.street,h.image_url,h.last_seen,
                    COALESCE(w.purchase_cost,0) AS purchase_cost,COALESCE(w.renovation,0) AS renovation,COALESCE(w.reserve_cost,0) AS reserve_cost
                FROM homes h LEFT JOIN watched w ON w.url=h.url
                WHERE h.source<>'Adradar' AND h.price BETWEEN 50000 AND 2000000 AND h.area BETWEEN 15 AND 250
                  AND h.street<>'' AND h.last_seen>=datetime('now','-30 days')
                ORDER BY h.last_seen DESC LIMIT 250")->fetchAll(PDO::FETCH_ASSOC);
            $seenHomes = [];
            foreach ($candidates as $row) {
                $street = valuation_street((string)$row['street']);
                if (!isset($knownStreets[$street])) continue;
                $signature = $street . '|' . round((float)$row['area'], 1) . '|' . (int)$row['rooms'] . '|' . (int)$row['price'];
                if (isset($seenHomes[$signature])) continue;
                $seenHomes[$signature] = true;
                $market = market_valuation($homeDb, (float)$row['area'], (string)$row['street'],
                    $row['rooms'] !== null ? (int)$row['rooms'] : null, 'dobry', (string)$row['url']);
                if ($market['basis'] !== 'both' || $market['estimate'] === null) continue;
                $costs = (int)$row['purchase_cost'] + (int)$row['renovation'] + (int)$row['reserve_cost'];
                $gap = (int)$market['estimate'] - (int)$row['price'] - $costs;
                $percent = (int)round($gap / (int)$market['estimate'] * 100);
                if ($gap < 15000 || $percent < 8 || (int)$row['price'] >= (int)$market['listing_value']) continue;
                $homeOpportunities[] = [
                    'url'=>(string)$row['url'], 'title'=>(string)$row['title'], 'source'=>(string)$row['source'],
                    'price'=>(int)$row['price'], 'area'=>(float)$row['area'], 'street'=>(string)$row['street'],
                    'image'=>(string)$row['image_url'], 'estimate'=>(int)$market['estimate'], 'gap'=>$gap,
                    'percent'=>$percent, 'costs'=>$costs, 'transactions'=>(int)$market['rcn']['count'],
                    'comparables'=>(int)$market['listings']['count'],
                ];
            }
            usort($homeOpportunities, static fn(array $a, array $b): int => $b['percent'] <=> $a['percent'] ?: $b['gap'] <=> $a['gap']);
            $homeOpportunities = array_slice($homeOpportunities, 0, 4);
        }
    }
} catch (Throwable $e) {
    error_log('IFS panel home feed: ' . $e->getMessage());
    $errors[] = 'Nie można teraz odczytać ofert mieszkań.';
}
usort($offers, static function (array $a, array $b): int { return strcmp($b['seen'], $a['seen']); });
$offers = array_slice($offers, 0, 12);
?>
<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Panel główny IFS</title>
<style>
:root{color-scheme:light}*{box-sizing:border-box}body{margin:0;background:#f3f6f5;color:#17342e;font:16px/1.5 system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}a{color:#0b685a}a:hover{text-decoration:underline}.shell{max-width:1160px;margin:auto;padding:0 22px}.hero{background:#103e37;color:#fff;padding:30px 0 36px}.hero-inner{display:flex;align-items:flex-start;justify-content:space-between;gap:24px}.eyebrow{margin:0 0 5px;color:#a8d7c8;font-size:12px;font-weight:800;letter-spacing:.12em}.hero h1{margin:0;font-size:clamp(30px,4vw,43px);line-height:1.1}.hero p{margin:9px 0 0;color:#d2e6df}.hero a{color:#fff}.logout{margin:2px 0 0}.logout button{background:none;color:#fff;border:1px solid #91b9ad;border-radius:9px;padding:9px 14px;font:inherit;cursor:pointer}.intro{margin:26px 0 18px;color:#526d65}.modules{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.module{background:#fff;border:1px solid #d9e6df;border-radius:17px;padding:22px;box-shadow:0 4px 18px #153a2610}.module small{font-weight:800;letter-spacing:.08em;color:#47846c}.module h2{margin:7px 0 5px;font-size:23px}.module p{margin:0 0 17px;color:#59716a}.stats{display:flex;flex-wrap:wrap;gap:16px;margin-bottom:17px}.stats strong{display:block;font-size:25px;line-height:1.1}.stats span{color:#617770;font-size:13px}.buttons{display:flex;flex-wrap:wrap;gap:10px}.button{display:inline-block;background:#0d6655;color:#fff!important;text-decoration:none;border-radius:9px;padding:10px 14px;font-weight:700}.button.secondary{background:#e8f3ef;color:#155645!important}.section-head{display:flex;align-items:end;justify-content:space-between;gap:18px;margin:32px 0 14px}.section-head h2{margin:0;font-size:24px}.muted{color:#657d74;font-size:13px}.filters{display:flex;gap:8px;flex-wrap:wrap}.filters a{padding:8px 13px;border:1px solid #c9ddd3;border-radius:999px;text-decoration:none;font-weight:700;background:#fff}.filters a.active{background:#103e37;color:#fff;border-color:#103e37}.offers{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.offer{display:grid;grid-template-columns:112px minmax(0,1fr);gap:15px;background:#fff;border:1px solid #d9e6df;border-radius:14px;padding:12px;min-width:0}.photo{width:112px;height:112px;object-fit:cover;border-radius:9px;background:#e9eeeb}.placeholder{display:grid;place-items:center;color:#657d74;font-weight:800;font-size:13px;text-align:center;padding:7px}.badge{display:inline-block;border-radius:99px;padding:3px 9px;background:#e4f1eb;color:#12644d;font-size:12px;font-weight:800}.badge.home{background:#e7edf7;color:#315689}.offer h3{font-size:16px;line-height:1.3;margin:7px 0 5px;overflow-wrap:anywhere}.price{font-weight:800;color:#153f33}.meta{margin:6px 0 0;font-size:12px;color:#657d74}.offer .action{display:inline-block;margin-top:8px;font-size:13px;font-weight:750}.notice{background:#fff2e4;border:1px solid #f0cc9d;padding:13px 16px;border-radius:10px;margin:15px 0}.empty{background:#fff;border:1px dashed #c4d4ca;border-radius:12px;padding:22px}.footer{padding:25px 0 45px;color:#70867e;font-size:13px}@media(max-width:760px){.modules,.offers{grid-template-columns:1fr}.section-head{align-items:start;flex-direction:column}.hero-inner{align-items:start}.offer{grid-template-columns:96px minmax(0,1fr)}.photo{width:96px;height:96px}}
.opportunity-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.opportunity-module{background:#fff;border:1px solid #d3e2d8;border-radius:17px;padding:18px;min-width:0;box-shadow:0 4px 18px #153a2610}.opportunity-module h3{margin:0 0 4px;font-size:20px}.opportunity-module>p{margin:0 0 14px}.opportunity-card{display:grid;grid-template-columns:88px minmax(0,1fr);gap:12px;padding:12px 0;border-top:1px solid #e3ece6}.opportunity-card .photo{width:88px;height:88px}.opportunity-card h4{margin:3px 0 5px;line-height:1.25;font-size:15px;overflow-wrap:anywhere}.opportunity-card h4 a{text-decoration:none}.opportunity-card h4 a:hover{text-decoration:underline}.opportunity-score{display:inline-block;background:#def5e6;color:#075c3b;border-radius:8px;padding:2px 8px;font-size:13px;font-weight:850}.opportunity-score.camper{background:#fff0d8;color:#805000}.opportunity-details{font-size:13px;color:#47665b;line-height:1.35}.opportunity-details strong{color:#123f30}.opportunity-card .action{display:inline-block;font-size:13px;font-weight:750;margin-top:4px}.method{background:#e9f1ed;border-radius:9px;padding:10px 12px;font-size:12px;color:#4f695d;line-height:1.4}.opportunity-empty{padding:16px 0;color:#657d74;font-size:14px}@media(max-width:760px){.opportunity-grid{grid-template-columns:1fr}}
</style></head><body>
<header class="hero"><div class="shell hero-inner"><div><p class="eyebrow">PANEL ADMINISTRACYJNY</p><h1>IFS</h1><p>Kampery i mieszkania w jednym miejscu.</p></div><form class="logout" action="index.php" method="post"><input type="hidden" name="csrf" value="<?= h(csrf_token($config)) ?>"><button type="submit" name="action" value="logout">Wyloguj</button></form></div></header>
<main class="shell"><p class="intro">Wybierz moduł lub przejrzyj ostatnio wykryte i dodane oferty.</p>
<div class="modules"><section class="module"><small>MODUŁ 01</small><h2>Kampery</h2><p>Nowe ogłoszenia, katalog i analiza opłacalności zakupu.</p><div class="stats"><div><strong><?= $camperCount ?></strong><span>ofert w bazie</span></div><div><strong><?= $camperScan ? h(substr($camperScan, 0, 16)) : '—' ?></strong><span>ostatni skan (UTC)</span></div></div><div class="buttons"><a class="button" href="index.php">Otwórz panel kamperów</a><a class="button secondary" href="katalog.php">Katalog</a><a class="button secondary" href="facebook_groups.php">Grupy Facebook</a></div></section>
<section class="module"><small>MODUŁ 02</small><h2>Mieszkania · Kwidzyn</h2><p>Oferty sprzedaży, obserwowane mieszkania i wycena.</p><div class="stats"><div><strong><?= $homeCount ?></strong><span>wykrytych ogłoszeń</span></div><div><strong><?= $homeScan ? h(substr($homeScan, 0, 16)) : '—' ?></strong><span>ostatni skan (UTC)</span></div></div><div class="buttons"><a class="button" href="mieszkania/">Otwórz panel mieszkań</a><a class="button secondary" href="mieszkania/watch.php">Obserwowane</a></div></section></div>
<?php foreach ($errors as $error): ?><p class="notice"><?= h($error) ?></p><?php endforeach; ?>
<div class="section-head" id="okazje"><div><h2>Okazje do sprawdzenia</h2><div class="muted">Sygnały z wyliczeń, uporządkowane według różnicy do porównywalnych cen. Przed zakupem sprawdź stan, koszty i aktualność ogłoszenia.</div></div></div>
<div class="opportunity-grid">
<?php if ($view !== 'campers'): ?><section class="opportunity-module"><h3>Mieszkania · Kwidzyn</h3><p class="muted">Cena zakupu wobec podobnych transakcji i ofert</p>
<?php if (!$homeOpportunities): ?><div class="opportunity-empty">Obecnie nie ma ofert z wystarczającymi danymi i wyraźną różnicą cenową.</div><?php endif; ?>
<?php foreach ($homeOpportunities as $item): ?><article class="opportunity-card"><div><?php if (filter_var($item['image'], FILTER_VALIDATE_URL) && parse_url($item['image'], PHP_URL_SCHEME) === 'https'): ?><img class="photo" src="<?= h($item['image']) ?>" alt="" loading="lazy" referrerpolicy="no-referrer"><?php else: ?><div class="photo placeholder">Brak zdjęcia</div><?php endif; ?></div><div><span class="opportunity-score">+<?= number_format($item['gap'], 0, ',', ' ') ?> zł różnicy</span><h4><a href="mieszkania/history.php?url=<?= rawurlencode($item['url']) ?>#analiza"><?= h($item['title']) ?></a></h4><div class="opportunity-details"><strong><?= number_format($item['price'], 0, ',', ' ') ?> zł</strong> · <?= number_format($item['area'], 1, ',', ' ') ?> m²<?= $item['street'] !== '' ? ' · ' . h($item['street']) : '' ?><br><?= $item['percent'] ?>% poniżej punktu odniesienia · <?= $item['transactions'] ?> transakcji RCN i <?= $item['comparables'] ?> ofert<?= $item['costs'] > 0 ? ' · uwzględniono wpisane koszty' : ' · przed kosztami zakupu i remontu' ?></div><a class="action" href="mieszkania/history.php?url=<?= rawurlencode($item['url']) ?>#analiza">Zobacz analizę →</a></div></article><?php endforeach; ?>
<div class="method">Punkt odniesienia to mediana transakcji RCN do 500 m i przy metrażu ±20%. Podobne oferty z ostatnich 30 dni muszą potwierdzać porównanie. Pokazujemy różnicę od 15 000 zł i 8%; to nie jest prognoza zysku.</div></section><?php endif; ?>
<?php if ($view !== 'homes'): ?><section class="opportunity-module"><h3>Kampery</h3><p class="muted">Ceny z katalogu wobec podobnych ogłoszeń</p>
<?php if (!$camperOpportunities): ?><div class="opportunity-empty">Brak aktualnych kandydatów z potwierdzoną ceną katalogową.</div><?php endif; ?>
<?php foreach ($camperOpportunities as $item): ?><article class="opportunity-card"><div><?php if (filter_var($item['image'], FILTER_VALIDATE_URL) && parse_url($item['image'], PHP_URL_SCHEME) === 'https'): ?><img class="photo" src="<?= h($item['image']) ?>" alt="" loading="lazy" referrerpolicy="no-referrer"><?php else: ?><div class="photo placeholder">Brak zdjęcia</div><?php endif; ?></div><div><span class="opportunity-score camper"><?= $item['discount'] ?>% poniżej mediany</span><h4><a href="index.php?analyze=<?= rawurlencode($item['url']) ?>#analysis"><?= h($item['title']) ?></a></h4><div class="opportunity-details"><strong><?= number_format($item['price'], 0, ',', ' ') ?> zł</strong> · <?= h($item['type']) ?> · <?= $item['year'] ?> · dane z WybierzKampera<br>Ostatnio widziano <?= h(substr($item['seen'], 0, 16)) ?> UTC</div><a class="action" href="index.php?analyze=<?= rawurlencode($item['url']) ?>#analysis">Analizuj zakup →</a></div></article><?php endforeach; ?>
<div class="method">Porównanie z cenami ogłoszeń tego samego typu, pięcioletniego rocznika i zbliżonego przebiegu. Cena i dostępność wymagają potwierdzenia; wynik nie uwzględnia stanu, wyposażenia ani naprawy.</div></section><?php endif; ?>
</div>
<div class="section-head"><div><h2>Ostatnie oferty</h2><div class="muted">Data pochodzi z monitora IFS lub dodania posta z Facebooka. Ceny i dostępność sprawdź w ogłoszeniu.</div></div><nav class="filters" aria-label="Filtr ofert"><a class="<?= $view === 'all' ? 'active' : '' ?>" href="panel.php">Wszystkie</a><a class="<?= $view === 'campers' ? 'active' : '' ?>" href="panel.php?view=campers">Kampery</a><a class="<?= $view === 'homes' ? 'active' : '' ?>" href="panel.php?view=homes">Mieszkania</a></nav></div>
<?php if ($offers): ?><div class="offers"><?php foreach ($offers as $offer): ?><article class="offer"><div><?php if (filter_var($offer['image'], FILTER_VALIDATE_URL) && parse_url($offer['image'], PHP_URL_SCHEME) === 'https'): ?><img class="photo" src="<?= h($offer['image']) ?>" alt="" loading="lazy" referrerpolicy="no-referrer"><?php else: ?><div class="photo placeholder">Brak zdjęcia</div><?php endif; ?></div><div><span class="badge <?= $offer['kind'] === 'home' ? 'home' : '' ?>"><?= $offer['kind'] === 'home' ? 'Mieszkanie' : 'Kamper' ?></span> <span class="muted"><?= h($offer['source']) ?></span><h3><?= h($offer['title']) ?></h3><div class="price"><?= $offer['price'] !== '' ? h($offer['price']) : 'Cena do sprawdzenia' ?></div><div class="meta"><?= $offer['source'] === 'Facebook' ? 'Dodano ręcznie' : 'Wykryto' ?> <?= h($offer['seen']) ?> UTC</div><a class="action" href="<?= h($offer['url']) ?>" target="_blank" rel="noopener noreferrer">Zobacz ogłoszenie ↗</a><?php if ($offer['kind'] === 'camper'): ?> · <a class="action" href="index.php?analyze=<?= rawurlencode($offer['url']) ?>#analysis">Analizuj</a><?php else: ?> · <a class="action" href="mieszkania/history.php?url=<?= rawurlencode($offer['url']) ?>#analiza">Analiza ceny</a><?php endif; ?></div></article><?php endforeach; ?></div><?php else: ?><p class="empty">Nie ma jeszcze ofert w tym widoku. Sprawdź ostatni skan w wybranym module.</p><?php endif; ?>
<p class="footer">Oferty mogą pojawić się ponownie w kilku portalach. Daty skanowania są podane w UTC.</p></main></body></html>

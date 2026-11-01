<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app.php';
require __DIR__ . '/core.php';
require __DIR__ . '/rcn.php';
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
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf($config);
    $url = (string)($_POST['url'] ?? '');
    $action = (string)($_POST['action'] ?? '');
    $known = $db->prepare('SELECT 1 FROM homes WHERE url=?');
    $known->execute([$url]);
    if (!$known->fetchColumn()) { http_response_code(400); exit('Nieznana oferta.'); }
    if ($action === 'add') {
        $db->prepare('INSERT OR IGNORE INTO watched(url) VALUES (?)')->execute([$url]);
    } elseif ($action === 'add_candidate' || $action === 'candidate_add') {
        $db->prepare('INSERT OR IGNORE INTO watched(url) VALUES (?)')->execute([$url]);
        $db->prepare("UPDATE watched SET purchase_candidate=1,candidate_at=CURRENT_TIMESTAMP WHERE url=?")->execute([$url]);
    } elseif ($action === 'candidate_remove') {
        $db->prepare("UPDATE watched SET purchase_candidate=0,candidate_at='' WHERE url=?")->execute([$url]);
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
    $returnPage = max(1, min(1000, (int)($_POST['page'] ?? 1)));
    $returnView = in_array((string)($_POST['view'] ?? ''), ['all', 'candidates'], true) ? (string)$_POST['view'] : ($action === 'add_candidate' ? 'candidates' : 'all');
    header('Location: watch.php?view=' . $returnView . '&page=' . $returnPage . '#zapisane'); exit;
}
$watched = $db->query("SELECT h.*,w.saved_at,w.purchase_cost,w.renovation,w.reserve_cost,w.resale_target,w.note,w.purchase_candidate,w.candidate_at FROM watched w JOIN homes h ON h.url=w.url ORDER BY CASE WHEN w.purchase_candidate=1 THEN 0 ELSE 1 END,w.candidate_at DESC,w.saved_at DESC,h.title ASC")->fetchAll(PDO::FETCH_ASSOC);
$candidateCount = count(array_filter($watched, static fn(array $item): bool => (int)$item['purchase_candidate'] === 1));
$view = (string)($_GET['view'] ?? 'all');
if (!in_array($view, ['all', 'candidates'], true)) $view = 'all';
$histQuery = $db->prepare('SELECT old_price,new_price,changed_at FROM price_history WHERE url=? AND old_price BETWEEN 1 AND 100000000 AND new_price BETWEEN 1 AND 100000000 ORDER BY id DESC LIMIT 8');
$rcnSynced = $db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='rcn_meta'")->fetchColumn()
    ? $db->query("SELECT value FROM rcn_meta WHERE key='synced_at'")->fetchColumn() : false;
$ranking = [];
foreach ($watched as $item) {
    if (!$item['price'] || !$item['area'] || ($item['source'] === 'Adradar' && mb_stripos($item['title'], 'Kwidzyn') === false)) continue;
    $market = market_valuation($db, (float)$item['area'], (string)$item['street'], $item['rooms'] !== null ? (int)$item['rooms'] : null, 'dobry', (string)$item['url']);
    if ($market['basis'] !== 'both') continue;
    $allCosts = (int)$item['price'] + (int)$item['purchase_cost'] + (int)$item['renovation'] + (int)$item['reserve_cost'];
    $ranking[] = ['url'=>$item['url'], 'title'=>$item['title'], 'source'=>$item['source'], 'price'=>(int)$item['price'], 'area'=>(float)$item['area'], 'street'=>(string)$item['street'],
        'median'=>$market['estimate'], 'costs'=>$allCosts, 'spread'=>$market['estimate']-$allCosts, 'count'=>$market['rcn']['count'], 'offers'=>$market['listings']['count']];
}
usort($ranking, static fn(array $a, array $b): int => $b['spread'] <=> $a['spread']);
$displayRanking = array_slice($ranking, 0, 12);
$rankingMax = 1;
foreach ($displayRanking as $row) $rankingMax = max($rankingMax, abs($row['spread']));
$perPage = 20;
$filteredWatched = $view === 'candidates' ? array_values(array_filter($watched, static fn(array $item): bool => (int)$item['purchase_candidate'] === 1)) : $watched;
$pages = max(1, (int)ceil(count($filteredWatched) / $perPage));
$page = max(1, min($pages, (int)($_GET['page'] ?? 1)));
$shownWatched = array_slice($filteredWatched, ($page - 1) * $perPage, $perPage);
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
<style>:root{color-scheme:light}*{box-sizing:border-box}body{margin:0;background:#f4f7f6;color:#15312e;font:16px/1.5 system-ui,-apple-system,sans-serif}header{background:#123c38;color:white;padding:22px}header div,main{max-width:1100px;margin:auto}header a{color:#d7eee9}h1{margin:0;font-size:28px}h2{font-size:21px}main{padding:24px}.card{background:#fff;border:1px solid #dae5e1;border-radius:14px;padding:20px;margin:16px 0;box-shadow:0 2px 8px #1a44330b}.offer{display:grid;grid-template-columns:230px 1fr;gap:20px}.photo{width:100%;height:180px;object-fit:cover;border-radius:9px;background:#e7efec}.price{font-size:24px;font-weight:750;color:#0e604d}.muted{color:#637a75;font-size:14px}.bad{color:#a32626}.good{color:#16744c}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px}label{display:block;font-size:13px;color:#52716d}input,textarea{width:100%;border:1px solid #b8cbc5;border-radius:8px;padding:9px;font:inherit;color:#15312e}textarea{min-height:75px}button{background:#0c6958;color:#fff;border:0;border-radius:9px;padding:10px 16px;font:inherit;cursor:pointer}.secondary{background:#e8f2ee;color:#165546}a{color:#147668}form{margin-top:15px}.result{padding:12px;background:#eaf4ef;border-radius:9px;margin:12px 0}.rank{display:grid;grid-template-columns:45px minmax(160px,1fr) 185px 200px;align-items:center;gap:12px;padding:13px 0;border-bottom:1px solid #e4ece8}.rank:last-child{border:0}.rank-number{font-size:20px;font-weight:800;color:#0b685a}.rank-title{font-weight:700}.rank small{display:block;color:#637a75}.rank-value{font-weight:800;text-align:right}.rank-track{height:12px;background:#edf2ef;border-radius:99px;overflow:hidden}.rank-fill{height:100%;border-radius:99px;background:#1a9a72}.rank-fill.negative{background:#dc7565}.offer-tools{border-top:1px solid #d8e7df;padding-top:12px;margin-top:12px}.offer-tools summary{cursor:pointer;font-weight:700;color:#0b685a}@media(max-width:760px){.rank{grid-template-columns:36px 1fr 120px}.rank-track{grid-column:2/-1}.offer{grid-template-columns:1fr}.photo{height:220px}}</style><link rel="stylesheet" href="watch.css?v=1"></head><body>
<header><div><h1>Obserwowane mieszkania</h1><a href="./">Oferty</a> · <a href="wycena.php">Wycena i negocjacja</a> · <a href="rcn_market.php">Transakcje RCN</a> · <a href="../panel.php">IFS</a></div></header><main>
<p>Tu zapisujesz oferty i własne koszty. Każda karta pokazuje osobno ceny z ogłoszeń i transakcji RCN; ranking obejmuje tylko oferty, dla których oba źródła mają co najmniej 5 porównań. Historia IFS i import Adradar pozostają oddzielne.</p>
<p class="muted">Źródło cen transakcyjnych: <a href="https://www.geoportal.gov.pl/pl/dane/rejestr-cen-nieruchomosci-rcn/" target="_blank" rel="noopener noreferrer">GUGiK, Rejestr Cen Nieruchomości ↗</a><?= $rcnSynced ? ' · pobrano ' . homes_e($rcnSynced) . ' UTC' : ' · dane jeszcze niepobrane' ?>.</p>
<p><a href="import_adradar.php">Importuj historię Adradar →</a></p>
<p><a href="rcn_market.php">Przeglądaj transakcje RCN w Kwidzynie →</a></p>
<p><a href="wycena.php">Wyceń dowolne mieszkanie po ulicy i metrażu →</a></p>
<nav class="watch-nav" aria-label="Widok zapisanych ofert"><a class="<?= $view === 'all' ? 'active' : '' ?>" href="?view=all#zapisane">Wszystkie obserwowane · <?= count($watched) ?></a><a class="<?= $view === 'candidates' ? 'active' : '' ?>" href="?view=candidates#zapisane">Moje kandydatury do zakupu · <?= $candidateCount ?></a></nav>
<p class="muted">„Kandydat do zakupu” to Twoje własne oznaczenie. Nie oznacza potwierdzonej okazji ani dostępności ogłoszenia.</p>
<section class="card"><h2>Porównanie obserwowanych</h2><p class="muted">Różnica = mediana transakcji RCN − cena zakupu − wpisane koszty. Ranking pokazuje też liczbę ofert porównawczych. To sygnał do sprawdzenia, a nie prognoza zysku. Pokazano <?= min(12, count($ranking)) ?> z <?= count($ranking) ?> ofert z dwoma źródłami danych.</p>
<?php if (!$ranking): ?><p>Dodaj do obserwowanych ofertę z ceną i metrażem, aby zobaczyć porównanie.</p><?php endif ?>
<?php foreach ($displayRanking as $position=>$row): ?><div class="rank"><span class="rank-number"><?= $position+1 ?></span><div><a class="rank-title" href="history.php?url=<?= rawurlencode($row['url']) ?>#analiza"><?= homes_e($row['title']) ?></a><small><?= $row['source'] === 'Adradar' ? 'Import Adradar · aktualność do sprawdzenia' : homes_e($row['source']) ?> · <?= number_format($row['area'], 1, ',', ' ') ?> m²<?= $row['street'] !== '' ? ' · ' . homes_e($row['street']) : '' ?> · <?= $row['count'] ?> transakcji i <?= $row['offers'] ?> ofert · cena <?= homes_money($row['price']) ?></small></div><div class="rank-value <?= $row['spread'] >= 0 ? 'good' : 'bad' ?>"><?= $row['spread'] > 0 ? '+' : '' ?><?= homes_money($row['spread']) ?><small>po kosztach</small></div><div class="rank-track" aria-label="Wielkość różnicy do mediany"><div class="rank-fill <?= $row['spread'] < 0 ? 'negative' : '' ?>" style="width:<?= max(3, (int)round(abs($row['spread'])/$rankingMax*100)) ?>%"></div></div></div><?php endforeach ?></section>
<h2 id="zapisane"><?= $view === 'candidates' ? 'Moje kandydatury do zakupu' : 'Obserwowane oferty' ?> · strona <?= $page ?> z <?= $pages ?></h2>
<p class="muted">Pokazano <?= count($shownWatched) ?> z <?= count($filteredWatched) ?> ofert w tym widoku. Otwórz analizę, aby zobaczyć argumenty cenowe i szkic wiadomości.</p>
<?php if ($view === 'candidates' && !$filteredWatched): ?><div class="candidate-empty">Nie oznaczono jeszcze żadnego mieszkania jako kandydata do zakupu. Wybierz „Dodaj do kandydatów” przy obserwowanej ofercie albo na liście nowych ogłoszeń.</div><?php endif ?>
<?php foreach ($shownWatched as $home):
$market = $home['area'] && ($home['source'] !== 'Adradar' || mb_stripos($home['title'], 'Kwidzyn') !== false)
    ? market_valuation($db, (float)$home['area'], (string)$home['street'], $home['rooms'] !== null ? (int)$home['rooms'] : null, 'dobry', (string)$home['url']) : null;
$rcn = $market['rcn'] ?? null;
$offers = $market['listings'] ?? null;
$rcnBenchmark = $market['rcn_value'] ?? null;
$offerBenchmark = $market['listing_value'] ?? null;
$jointBenchmark = $market && $market['basis'] === 'both' ? $market['estimate'] : null;
$histQuery->execute([$home['url']]);
$history = $histQuery->fetchAll(PDO::FETCH_ASSOC);
$resale = (int)$home['resale_target'] > 0 ? (int)$home['resale_target'] : $jointBenchmark;
$investment = (int)$home['price'] + (int)$home['purchase_cost'] + (int)$home['renovation'] + (int)$home['reserve_cost'];
$profit = $resale !== null && $home['price'] ? $resale - $investment : null;
$spreadPercent = $profit !== null && $investment > 0 ? abs($profit) / $investment * 100 : 0;
?>
<article class="card offer"><div><?php if ($home['image_url']): ?><img class="photo" src="<?= homes_e($home['image_url']) ?>" alt="" loading="lazy" referrerpolicy="no-referrer"><?php endif ?></div><div>
<?php if ((int)$home['purchase_candidate'] === 1): ?><span class="candidate-pill">★ Mój kandydat do zakupu</span><?php endif ?>
<h2><?= homes_e($home['title']) ?></h2><p class="muted"><?= homes_e($home['source']) ?> · obserwowane od <?= homes_e($home['saved_at']) ?> UTC</p><div class="price"><?= $home['price'] ? homes_money((int)$home['price']) : 'Cena do sprawdzenia' ?></div><p><?= $home['area'] ? number_format((float)$home['area'], 1, ',', ' ') . ' m²' : 'Brak metrażu' ?><?= $home['rooms'] ? ' · ' . (int)$home['rooms'] . ' pok.' : '' ?><?= $home['street'] ? ' · ' . homes_e($home['street']) : '' ?></p><p><a href="<?= homes_e($home['url']) ?>" target="_blank" rel="noopener noreferrer">Otwórz ofertę w portalu ↗</a> · <a href="history.php?url=<?= rawurlencode($home['url']) ?>#analiza">Analiza ceny i historia</a></p>
<form method="post" class="candidate-form"><input type="hidden" name="page" value="<?= $page ?>"><input type="hidden" name="view" value="<?= $view ?>"><input type="hidden" name="csrf" value="<?= homes_e(csrf_token($config)) ?>"><input type="hidden" name="url" value="<?= homes_e($home['url']) ?>"><button class="<?= (int)$home['purchase_candidate'] === 1 ? 'selected' : '' ?>" name="action" value="<?= (int)$home['purchase_candidate'] === 1 ? 'candidate_remove' : 'candidate_add' ?>" type="submit"><?= (int)$home['purchase_candidate'] === 1 ? 'Usuń z kandydatów' : '★ Dodaj do kandydatów' ?></button></form>
<div class="valuation-panel" aria-label="Wycena oferty"><div class="valuation-head"><strong>Porównanie ceny</strong><span class="valuation-basis"><?= $jointBenchmark !== null ? 'RCN i oferty mają wspólny zakres' : 'Dane do oceny' ?></span></div>
<div class="valuation-metrics"><div class="valuation-metric ask"><span>Cena ogłoszenia</span><strong><?= $home['price'] ? homes_money((int)$home['price']) : '—' ?></strong><small><?= $home['area'] && $home['price'] ? homes_money((int)round((int)$home['price'] / (float)$home['area'])) . '/m²' : 'brak ceny za m²' ?></small></div>
<div class="valuation-metric rcn"><span>Sprzedane · RCN</span><strong><?= valuation_money($rcnBenchmark) ?></strong><small><?= $home['street'] === '' ? 'Brak ulicy · uzupełnij w analizie' : (int)($rcn['count'] ?? 0) . ' transakcji · do 500 m' ?></small></div>
<div class="valuation-metric offers"><span>Podobne ogłoszenia</span><strong><?= valuation_money($offerBenchmark) ?></strong><small><?= (int)($offers['count'] ?? 0) ?> ofert · ostatnie 30 dni</small></div></div>
<div class="valuation-outcome <?= $profit === null ? 'unknown' : ($profit >= 0 ? 'positive' : 'negative') ?>"><div><span>Różnica do punktu odniesienia po kosztach</span><strong><?= $profit !== null ? ($profit > 0 ? '+' : '') . homes_money($profit) : 'Brak wspólnego wniosku' ?></strong></div><em><?= $profit !== null ? number_format($spreadPercent, 1, ',', ' ') . '% kosztu całkowitego' : 'Uzupełnij dane lub własną cenę sprzedaży' ?><br><?= (int)$home['resale_target'] ? 'Twoja cena sprzedaży' : 'Mediana RCN przy zgodnych źródłach' ?></em></div>
<?php if ($profit !== null): ?><div class="valuation-scale" aria-hidden="true"><span class="<?= $profit < 0 ? 'negative' : '' ?>" style="width:<?= max(3, min(100, (int)round($spreadPercent))) ?>%"></span></div><?php endif ?>
<p class="valuation-foot">Różnica porównuje punkt odniesienia z ceną i wpisanymi kosztami. Nie jest prognozą zysku. Ceny ofertowe nie są cenami sprzedaży; stan, piętro i wyposażenie trzeba zweryfikować.</p></div>
<?php if ($rcnBenchmark !== null): ?><p><a href="rcn_market.php?area=<?= rawurlencode((string)$home['area']) ?><?= $home['street'] !== '' ? '&amp;street=' . rawurlencode((string)$home['street']) : '' ?>">Zobacz transakcje RCN użyte w analizie →</a></p><?php endif ?>
<details class="offer-tools"><summary>Koszty, notatka i historia ceny</summary><form method="post" class="grid"><input type="hidden" name="page" value="<?= $page ?>"><input type="hidden" name="view" value="<?= $view ?>"><input type="hidden" name="csrf" value="<?= homes_e(csrf_token($config)) ?>"><input type="hidden" name="url" value="<?= homes_e($home['url']) ?>"><div><label>Koszty transakcji (zł)<input type="number" name="purchase_cost" min="0" max="100000000" value="<?= (int)$home['purchase_cost'] ?>"></label></div><div><label>Remont (zł)<input type="number" name="renovation" min="0" max="100000000" value="<?= (int)$home['renovation'] ?>"></label></div><div><label>Rezerwa (zł)<input type="number" name="reserve_cost" min="0" max="100000000" value="<?= (int)$home['reserve_cost'] ?>"></label></div><div><label>Moja cena sprzedaży (zł)<input type="number" name="resale_target" min="0" max="100000000" value="<?= (int)$home['resale_target'] ?>"></label></div><div style="grid-column:1/-1"><label>Notatka<textarea name="note" maxlength="1000"><?= homes_e($home['note']) ?></textarea></label></div><div><button name="action" value="save" type="submit">Zapisz analizę</button></div></form>
<p><b>Historia ceny od monitorowania:</b> <?php if (!$history): ?>bez wykrytych zmian<?php else: ?><?php foreach ($history as $change): ?><?= homes_money((int)$change['old_price']) ?> → <?= homes_money((int)$change['new_price']) ?> (<?= homes_e($change['changed_at']) ?> UTC); <?php endforeach ?><?php endif ?></p>
<form method="post"><input type="hidden" name="page" value="<?= $page ?>"><input type="hidden" name="view" value="<?= $view ?>"><input type="hidden" name="csrf" value="<?= homes_e(csrf_token($config)) ?>"><input type="hidden" name="url" value="<?= homes_e($home['url']) ?>"><button class="secondary" name="action" value="remove" type="submit">Usuń z obserwowanych</button></form></details>
</div></article>
<?php endforeach ?><nav aria-label="Strony obserwowanych" style="display:flex;gap:10px;flex-wrap:wrap;margin:20px 0"><?php for ($n=1;$n<=$pages;$n++): ?><a href="?view=<?= $view ?>&amp;page=<?= $n ?>#zapisane" style="padding:7px 11px;border-radius:8px;background:<?= $n===$page?'#0c6958':'#e5efeb' ?>;color:<?= $n===$page?'white':'#15312e' ?>;font-weight:700"><?= $n ?></a><?php endfor ?></nav><?php if (!$watched): ?><div class="card">Nie obserwujesz jeszcze ofert. Otwórz <a href="./">listę mieszkań</a> i wybierz „Obserwuj i wyceń”.</div><?php endif ?>
</main></body></html>

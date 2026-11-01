<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app.php';
require __DIR__ . '/core.php';
require __DIR__ . '/market_analysis.php';
$config = config();
session_name('kampery_session');
session_set_cookie_params(['httponly'=>true,'secure'=>true,'samesite'=>'Lax']);
session_start();
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
if (empty($_SESSION['auth'])) { header('Location: ../index.php'); exit; }

$input = ['street'=>'','area'=>'','rooms'=>'','condition'=>'dobry','asking'=>'','renovation'=>'','costs'=>''];
foreach (['street','area','rooms','asking'] as $key) {
    if (isset($_GET[$key]) && is_string($_GET[$key])) $input[$key] = mb_substr(trim($_GET[$key]), 0, 100);
}
$conditionLabels = ['remont'=>'Do remontu','dobry'=>'Stan dobry','bardzo_dobry'=>'Stan bardzo dobry'];
$error = '';
$market = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf($config);
    foreach ($input as $key=>$unused) $input[$key] = trim((string)($_POST[$key] ?? ''));
    $area = filter_var(str_replace(',', '.', $input['area']), FILTER_VALIDATE_FLOAT);
    if ($area === false || $area < 15 || $area > 250) $error = 'Podaj metraż od 15 do 250 m².';
    elseif (mb_strlen($input['street']) > 100) $error = 'Ulica może mieć maksymalnie 100 znaków.';
    elseif ($input['rooms'] !== '' && (!ctype_digit($input['rooms']) || (int)$input['rooms'] < 1 || (int)$input['rooms'] > 8)) $error = 'Podaj liczbę pokoi od 1 do 8.';
    elseif (!isset($conditionLabels[$input['condition']])) $error = 'Wybierz stan mieszkania.';
    foreach (['asking','renovation','costs'] as $key) {
        if ($input[$key] !== '' && (!ctype_digit($input[$key]) || (int)$input[$key] > 10000000)) $error = 'Kwoty podaj w pełnych złotych, maksymalnie 10 mln zł.';
    }
    if ($error === '') $market = market_valuation(homes_db(), (float)$area, $input['street'], $input['rooms'] !== '' ? (int)$input['rooms'] : null, $input['condition'], '', true);
}
$asking = $input['asking'] !== '' ? (int)$input['asking'] : null;
$renovation = $input['renovation'] !== '' ? (int)$input['renovation'] : null;
$costs = $input['costs'] !== '' ? (int)$input['costs'] : 0;
$talk = $market ? market_negotiation($market, $asking, $renovation, $costs) : null;
$reference = $market ? market_display_reference($market) : null;
$spread = $market && $market['basis'] === 'both' && $asking !== null && ($input['condition'] !== 'remont' || $renovation !== null)
    ? $market['estimate'] - $asking - ($renovation ?? 0) - $costs : null;
$comparisonMax = $market ? max(1, (int)($reference['value'] ?? 0), (int)($market['listing_value'] ?? 0), (int)($asking ?? 0)) : 1;
?>
<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Wycena mieszkania · IFS</title>
<style>
:root{color-scheme:light}*{box-sizing:border-box}body{margin:0;background:#f3f7f5;color:#17342e;font:16px/1.5 system-ui,-apple-system,sans-serif}a{color:#0b685a}header{background:#123c38;color:white;padding:24px}header .wrap,main{max-width:1080px;margin:auto}header h1{margin:0;font-size:29px}header p{margin:5px 0;color:#d7eee9}header a{color:#d7eee9}.nav{display:flex;gap:18px;flex-wrap:wrap;margin-top:14px}.nav a{font-weight:700}main{padding:20px}.card{background:#fff;border:1px solid #d7e4df;border-radius:16px;padding:22px;margin:16px 0;box-shadow:0 3px 14px #153c300d}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(205px,1fr));gap:13px}.field label{display:block;font-size:14px;font-weight:700;margin-bottom:5px}.field input,.field select{width:100%;border:1px solid #b8cbc5;border-radius:9px;padding:10px;font:inherit;background:#fff;color:#17342e}.eyebrow{font-size:12px;font-weight:800;letter-spacing:.1em;color:#0c8067}.hint{font-size:14px;color:#58756b}.metrics{display:grid;grid-template-columns:repeat(auto-fit,minmax(225px,1fr));gap:12px}.metric{background:#eaf4ef;border-radius:12px;padding:16px}.metric.primary{background:#0d5b4a;color:#fff}.metric span{font-size:13px;display:block}.metric strong{font-size:25px;line-height:1.2;display:block;margin-top:8px}.button{border:0;border-radius:9px;padding:11px 18px;background:#d9ed64;color:#17342e;font:inherit;font-weight:800;cursor:pointer}.alert{padding:12px;border-radius:9px;background:#fff0ec;color:#9b3526}.notice{background:#fff8e6;border-left:4px solid #daa22d;padding:12px}.good{color:#126742}.bad{color:#a22d2d}textarea{width:100%;min-height:170px;padding:12px;border:1px solid #b8cbc5;border-radius:9px;font:inherit;line-height:1.5}.steps{display:flex;gap:10px;flex-wrap:wrap;margin:0 0 18px;padding:0;list-style:none}.steps li{background:#e5efeb;padding:6px 11px;border-radius:99px;font-size:13px;font-weight:700}details{border-top:1px solid #d7e4df;padding-top:12px}summary{cursor:pointer;font-weight:700}@media(max-width:650px){main{padding:12px}.card{padding:17px}header h1{font-size:25px}}
</style><link rel="stylesheet" href="ui.css?v=2"></head><body class="page-valuation">
<header><div class="wrap"><div class="eyebrow" style="color:#bfe7d8">IFS · NIERUCHOMOŚCI</div><h1>Wycena i rozmowa o cenie</h1><p>Jedna analiza: oferty, transakcje, budżet zakupu.</p><nav class="nav" aria-label="Nawigacja"><a href="./">Oferty</a><a href="watch.php">Obserwowane</a><a href="rcn_market.php">Transakcje RCN</a><a href="../panel.php">IFS</a></nav></div></header><main>
<ol class="steps"><li>1 · Dane mieszkania</li><li>2 · Dwa źródła cen</li><li>3 · Decyzja i rozmowa</li></ol>
<section class="card"><div class="eyebrow">KROK 1</div><h2>Opisz mieszkanie</h2><p class="hint">Transakcje: do 500 m od ulicy, metraż ±20%, ostatnie 24 miesiące. Bez ulicy pokazujemy wstępny punkt RCN dla całego Kwidzyna. Oferty: Kwidzyn, metraż ±20%, ta sama liczba pokoi, jeśli ją podasz, widziane w ostatnich 30 dniach. Wspólny wniosek i propozycja negocjacyjna wymagają co najmniej 5 porównań z każdego źródła; przy rozbieżności pokazujemy wstępny punkt cenowy.</p>
<?php if ($error): ?><p class="alert" role="alert"><?= homes_e($error) ?></p><?php endif ?>
<form method="post"><input type="hidden" name="csrf" value="<?= homes_e(csrf_token($config)) ?>"><div class="grid">
<div class="field"><label for="street">Ulica lub ulica i numer (jeśli znasz)</label><input id="street" name="street" maxlength="100" placeholder="np. Mickiewicza 12" value="<?= homes_e($input['street']) ?>"></div>
<div class="field"><label for="area">Powierzchnia (m²)</label><input id="area" name="area" type="number" step="0.01" min="15" max="250" required value="<?= homes_e($input['area']) ?>"></div>
<div class="field"><label for="rooms">Liczba pokoi</label><select id="rooms" name="rooms"><option value="">Nie wiem</option><?php for ($n=1;$n<=8;$n++): ?><option value="<?= $n ?>" <?= $input['rooms']===(string)$n?'selected':'' ?>><?= $n ?></option><?php endfor ?></select></div>
<div class="field"><label for="condition">Stan mieszkania</label><select id="condition" name="condition"><?php foreach ($conditionLabels as $key=>$label): ?><option value="<?= $key ?>" <?= $input['condition']===$key?'selected':'' ?>><?= homes_e($label) ?></option><?php endforeach ?></select></div>
<div class="field"><label for="asking">Cena w ogłoszeniu (zł)</label><input id="asking" name="asking" type="number" min="0" max="10000000" placeholder="Jeśli znana" value="<?= homes_e($input['asking']) ?>"></div>
<div class="field"><label for="renovation">Planowany remont (zł)</label><input id="renovation" name="renovation" type="number" min="0" max="10000000" placeholder="Wpisz 0, jeśli nie planujesz" value="<?= homes_e($input['renovation']) ?>"></div>
<div class="field"><label for="costs">Inne koszty (zł)</label><input id="costs" name="costs" type="number" min="0" max="10000000" placeholder="Podatek, notariusz..." value="<?= homes_e($input['costs']) ?>"></div>
</div><p><button class="button" type="submit">Porównaj ceny →</button></p></form></section>
<?php if ($market): $rcn=$market['rcn']; $city=$market['rcn_city']; $rcnShown=$rcn['available']?$rcn:$city; $offers=$market['listings']; ?>
<section class="card" id="wynik"><div class="eyebrow">KROK 2</div><h2>Co mówią ceny?</h2>
<div class="metrics"><div class="metric"><span>Sprzedane mieszkania · RCN<?= !$rcn['available'] && ($city['available'] ?? false) ? ' · całe miasto' : '' ?></span><strong><?= valuation_money($rcnShown['median'] ?? null) ?></strong><small><?= (int)($rcnShown['count'] ?? 0) ?> porównywalnych transakcji · <?= homes_e($rcnShown['scope'] ?? $rcn['scope']) ?></small></div>
<div class="metric"><span>Ceny z ogłoszeń</span><strong><?= valuation_money($market['listing_value']) ?></strong><small><?= (int)$offers['count'] ?> porównywalnych ofert · <?= homes_e($offers['scope']) ?></small></div>
<div class="metric primary"><span><?= homes_e($reference['label']) ?></span><strong><?= valuation_money($reference['value']) ?></strong><small><?= homes_e($reference['note']) ?></small></div></div>
<div class="compare-figure" role="img" aria-label="Porównanie mediany cen transakcyjnych RCN, cen podobnych ogłoszeń i ceny analizowanej oferty">
<?php foreach ([['label'=>$rcn['available']?'Transakcje RCN · 500 m':'Transakcje RCN · Kwidzyn','value'=>$rcnShown['median'] ?? null,'class'=>'rcn'],['label'=>'Podobne oferty','value'=>$market['listing_value'],'class'=>'listings'],['label'=>'Cena analizowanej oferty','value'=>$asking,'class'=>'asking']] as $bar): if ($bar['value'] === null) continue; ?>
<div class="compare-row"><div class="compare-label"><strong><?= $bar['label'] ?></strong><span><?= valuation_money($bar['value']) ?></span></div><div class="compare-track"><span class="<?= $bar['class'] ?>" style="width:<?= max(3, min(100, (int)round($bar['value'] / $comparisonMax * 100))) ?>%"></span></div></div>
<?php endforeach ?></div>
<?php if ($market['basis'] !== 'both'): ?><p class="notice"><?= $market['basis']==='conflict'?'Zakresy 20.–80. percentyla RCN i ofert nie pokrywają się. RCN podaje ceny sprzedaży, a ogłoszenia ceny oczekiwane; różnica może też wynikać ze stanu lub błędnych cen.':($market['basis']==='divergent'?'Mediany RCN i ogłoszeń znacznie się różnią, a wspólny fragment zakresów 20.–80. percentyla jest wąski. Punkt RCN pozostaje orientacyjny; sprawdź stan lokalu i porównywane transakcje.':('Pełne porównanie obu źródeł jest niedostępne. ' . (!$rcn['available'] ? ($input['street']===''?'Bez ulicy używamy tylko wstępnego punktu RCN dla całego Kwidzyna; wpisz ulicę dla dokładniejszego porównania. ':($rcn['reason']==='unknown_street'?'Nie znaleziono wskazanej ulicy w usłudze adresowej GUGiK. ':'RCN blisko ulicy: mniej niż 5 pasujących transakcji lub brak danych. ')) : '') . (!$offers['available']?'Oferty: mniej niż 5 pasujących ogłoszeń.':''))) ?> Nie wyznaczamy automatycznej propozycji negocjacyjnej z takiego wyniku.</p><?php endif ?>
<?php if (in_array($market['basis'], ['conflict','divergent'], true)): ?><p class="hint"><strong>Różnica median:</strong> <?= valuation_money(abs($market['listing_value'] - $market['rcn_value'])) ?> (<?= (int)round(abs($market['listing_value'] - $market['rcn_value']) / max(1, $market['rcn_value']) * 100) ?>% względem RCN). RCN: <?= valuation_money($rcn['low']) ?>–<?= valuation_money($rcn['high']) ?>; oferty: <?= valuation_money($offers['low']) ?>–<?= valuation_money($offers['high']) ?> (20.–80. percentyl).</p><?php endif ?>
<p class="hint">Punkt RCN to mediana cen sprzedaży, a ceny w ogłoszeniach pokazujemy osobno. Przy małej próbie lub rozbieżnych źródłach jest to wstępne porównanie, nie ustalona wartość lokalu. Stan mieszkania nie koryguje mediany: źródła nie opisują standardu w porównywalny sposób. Remont jest osobną pozycją budżetu.</p>
<details><summary>Zakres danych i metoda</summary><p>Wspólny fragment zakresów: <?= $market['ranges_overlap'] ? valuation_money($market['joint_low']) . '–' . valuation_money($market['joint_high']) : 'brak' ?>. RCN: <?= $rcn['available'] ? valuation_money($rcn['low']) . '–' . valuation_money($rcn['high']) . ' (20.–80. percentyl), ' . homes_e($rcn['from']) . '–' . homes_e($rcn['to']) : 'brak wystarczających danych' ?>. Oferty: <?= $offers['available'] ? valuation_money($offers['low']) . '–' . valuation_money($offers['high']) . ' (20.–80. percentyl)' : 'brak wystarczających danych' ?>. Transakcje RCN: <?= (int)$rcn['raw_count'] ?> pasujących, wyłączone ceny skrajne: <?= (int)$rcn['excluded_count'] ?>. Przy co najmniej 10 obserwacjach cena za m² musi wypadać poza granicę 1,5 IQR i różnić się od mediany o co najmniej 25%. Powód skrajnej ceny pozostaje nieznany. Ogłoszenia pokazują ceny oczekiwane, a nie ceny sprzedaży. Podobna powierzchnia i okolica nie gwarantują podobnego standardu.</p><p><a href="rcn_market.php?area=<?= rawurlencode($input['area']) ?>&amp;street=<?= rawurlencode($input['street']) ?>">Zobacz transakcje RCN i odrzucone wartości →</a></p></details></section>
<?php if ($asking !== null): ?><section class="card" id="decyzja"><div class="eyebrow">KROK 3</div><h2>Czy cena jest za wysoka?</h2>
<div class="metrics"><div class="metric"><span>Cena w ogłoszeniu</span><strong><?= valuation_money($asking) ?></strong></div><div class="metric"><span><?= homes_e($reference['label']) ?></span><strong><?= valuation_money($reference['value']) ?></strong><small><?= homes_e($reference['note']) ?></small></div><div class="metric"><span>Różnica po wpisanych kosztach</span><strong class="<?= $spread===null?'':($spread>=0?'good':'bad') ?>"><?= valuation_money($spread) ?></strong></div></div>
<p class="hint">Różnica = mediana RCN − cena ogłoszenia − remont − inne koszty. Nie jest prognozą zysku; nie uwzględnia kosztów sprzedaży, finansowania i podatków, jeśli ich nie wpiszesz.<?php if ($input['condition']==='remont' && $renovation===null): ?> Wpisz koszt remontu, aby ją policzyć.<?php endif ?></p>
<?php if ($talk && $talk['high']): ?><h3>Argumenty za niższą ceną</h3><ul><?php foreach ($talk['reasons'] as $reason): ?><li><?= homes_e($reason) ?></li><?php endforeach ?></ul><p class="hint">Propozycja <?= valuation_money($talk['target']) ?> opiera się na medianie transakcji; osobno sprawdzono, że cena ofertowa przekracza też 80. percentyl ogłoszeń. To punkt rozmowy, nie gwarantowana cena sprzedaży. Zweryfikuj stan i dokumenty podczas oględzin.</p><h3>Szkic wiadomości do sprzedawcy</h3><textarea readonly aria-label="Szkic wiadomości do sprzedawcy"><?= homes_e($talk['draft']) ?></textarea><p class="hint">Skopiuj i dostosuj po sprawdzeniu mieszkania. Panel nie wysyła wiadomości.</p>
<?php elseif ($market['basis']==='both'): ?><p class="notice">Cena nie przekracza 80. percentyla jednocześnie w obu źródłach. Same dane cenowe nie dają mocnego argumentu do obniżki. Po oględzinach możesz dodać udokumentowane koszty lub wady.</p><?php else: ?><p class="notice">Do propozycji ceny potrzebne są oba źródła i zgodne zakresy porównawcze.</p><?php endif ?></section><?php endif ?>
<?php endif ?>
<p class="hint">Źródła: <a href="https://www.geoportal.gov.pl/pl/dane/rejestr-cen-nieruchomosci-rcn/" target="_blank" rel="noopener noreferrer">GUGiK, Rejestr Cen Nieruchomości ↗</a> oraz oferty zapisane w monitorze IFS. Dane RCN mogą być publikowane z opóźnieniem; aktualność ogłoszeń potwierdź w portalach.</p>
</main></body></html>

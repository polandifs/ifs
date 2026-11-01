<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app.php';
require __DIR__ . '/core.php';
require __DIR__ . '/valuation_core.php';
$config = config();
session_name('kampery_session');
session_set_cookie_params(['httponly'=>true,'secure'=>true,'samesite'=>'Lax']);
session_start();
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
if (empty($_SESSION['auth'])) { header('Location: ../index.php'); exit; }
$input = ['street'=>'','area'=>'','condition'=>'dobry','asking'=>'','renovation'=>'','costs'=>''];
foreach (['street','area','asking'] as $key) {
    if (isset($_GET[$key]) && is_string($_GET[$key])) $input[$key] = mb_substr(trim($_GET[$key]), 0, 100);
}
$error = '';
$result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf($config);
    foreach ($input as $key => $ignored) $input[$key] = trim((string)($_POST[$key] ?? ''));
    $area = filter_var(str_replace(',', '.', $input['area']), FILTER_VALIDATE_FLOAT);
    if ($area === false || $area < 15 || $area > 250) $error = 'Podaj metraż od 15 do 250 m².';
    elseif (mb_strlen($input['street']) > 100) $error = 'Nazwa ulicy jest za długa.';
    elseif (!in_array($input['condition'], ['remont','dobry','bardzo_dobry'], true)) $error = 'Wybierz stan mieszkania.';
    else {
        foreach (['asking','renovation','costs'] as $key) {
            if ($input[$key] !== '' && (!ctype_digit($input[$key]) || (int)$input[$key] > 10000000)) { $error = 'Kwoty podaj w pełnych złotych, maksymalnie 10 mln zł.'; break; }
        }
    }
    if ($error === '') {
        $db = homes_db();
        $result = valuation_summary($db, (float)$area, $input['street']);
    }
}
$asking = $input['asking'] !== '' ? (int)$input['asking'] : null;
$renovation = $input['renovation'] !== '' ? (int)$input['renovation'] : null;
$costs = $input['costs'] !== '' ? (int)$input['costs'] : 0;
$estimate = $result ? valuation_scenario($result, $input['condition']) : null;
$resale = $result && $result['available'] ? ($input['condition'] === 'remont' ? $result['upper_quartile'] : $estimate) : null;
$spread = $resale !== null && $asking !== null && ($input['condition'] !== 'remont' || $renovation !== null)
    ? $resale - $asking - ($renovation ?? 0) - $costs : null;
$conditionLabels = ['remont'=>'Do remontu','dobry'=>'Stan dobry','bardzo_dobry'=>'Stan bardzo dobry'];
?>
<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Wycena mieszkania · IFS</title>
<style>:root{color-scheme:light}*{box-sizing:border-box}body{margin:0;background:#f3f7f5;color:#17342e;font:16px/1.5 system-ui,-apple-system,sans-serif}header{background:linear-gradient(125deg,#123c38,#0e6959);color:white;padding:28px 20px}header .wrap,main{max-width:1100px;margin:auto}header h1{font-size:32px;margin:0}header p{margin:6px 0 0;color:#d7eee9}a{color:#0b685a}header a{color:#e6f8f0}main{padding:24px}.card{background:white;border:1px solid #d7e4df;border-radius:18px;padding:24px;margin:18px 0;box-shadow:0 6px 24px #153c3010}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:16px}.field label{display:block;font-size:14px;font-weight:700;margin-bottom:6px}.field input,.field select{width:100%;border:1px solid #b8cbc5;border-radius:10px;padding:12px;font:inherit;background:#fff;color:#17342e}.hint{color:#607b73;font-size:14px}.eyebrow{font-size:12px;font-weight:800;letter-spacing:.12em;text-transform:uppercase;color:#0c8067}.metrics{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:12px}.metric{padding:18px;border-radius:14px;background:#eaf4ef}.metric.primary{background:#0d5b4a;color:white}.metric span{display:block;font-size:13px}.metric strong{display:block;font-size:26px;line-height:1.15;margin-top:9px}.range{position:relative;height:16px;border-radius:99px;background:linear-gradient(90deg,#dae9e3,#4da889);margin:22px 0 9px}.range i{position:absolute;top:-8px;width:3px;height:32px;background:#1c3130}.range-labels{display:flex;justify-content:space-between;font-weight:700}.button{border:0;border-radius:10px;padding:12px 19px;background:#d9ed64;color:#163b32;font:inherit;font-weight:800;cursor:pointer}.alert{padding:12px;border-radius:10px;background:#fff0ec;color:#9b3526}.positive{color:#126742}.negative{color:#a22d2d}.result-title{margin-bottom:6px}.note{border-left:4px solid #75b99f;padding-left:14px;color:#526d65}.links{display:flex;gap:16px;flex-wrap:wrap}@media(max-width:650px){main{padding:14px}.card{padding:18px}header h1{font-size:27px}}</style></head><body>
<header><div class="wrap"><div class="eyebrow" style="color:#ccefdc">IFS · NIERUCHOMOŚCI</div><h1>Wycena mieszkania w Kwidzynie</h1><p>Porównanie z rzeczywistymi transakcjami RCN</p><p class="links"><a href="./">← Oferty mieszkań</a><a href="watch.php">Obserwowane</a><a href="rcn_market.php">Transakcje RCN</a></p></div></header><main>
<section class="card"><div class="eyebrow">DANE MIESZKANIA</div><h2>Uzupełnij parametry</h2><p class="hint">Ulica jest opcjonalna. Jeżeli znajdziemy co najmniej 5 zbliżonych metrażem transakcji na tej ulicy, użyjemy ich do wyceny. W innym przypadku użyjemy całego Kwidzyna.</p>
<?php if ($error): ?><p class="alert" role="alert"><?= homes_e($error) ?></p><?php endif ?>
<form method="post"><input type="hidden" name="csrf" value="<?= homes_e(csrf_token($config)) ?>"><div class="grid">
<div class="field"><label for="street">Ulica</label><input id="street" name="street" maxlength="100" placeholder="np. Mickiewicza" value="<?= homes_e($input['street']) ?>"></div>
<div class="field"><label for="area">Powierzchnia, m²</label><input id="area" name="area" type="number" step="0.1" min="15" max="250" required value="<?= homes_e($input['area']) ?>"></div>
<div class="field"><label for="condition">Stan mieszkania</label><select id="condition" name="condition"><?php foreach ($conditionLabels as $key=>$label): ?><option value="<?= $key ?>" <?= $input['condition']===$key?'selected':'' ?>><?= homes_e($label) ?></option><?php endforeach ?></select></div>
<div class="field"><label for="asking">Cena zakupu / ofertowa, zł</label><input id="asking" name="asking" type="number" min="0" max="10000000" placeholder="Opcjonalnie" value="<?= homes_e($input['asking']) ?>"></div>
<div class="field"><label for="renovation">Szacowany koszt remontu, zł</label><input id="renovation" name="renovation" type="number" min="0" max="10000000" placeholder="Wymagany do symulacji po remoncie" value="<?= homes_e($input['renovation']) ?>"></div>
<div class="field"><label for="costs">Pozostałe koszty, zł</label><input id="costs" name="costs" type="number" min="0" max="10000000" placeholder="Podatek, notariusz, prowizje..." value="<?= homes_e($input['costs']) ?>"></div>
</div><p><button class="button" type="submit">Oblicz wycenę →</button></p></form></section>
<?php if ($result): ?><section class="card" id="wynik"><div class="eyebrow">WYNIK ANALIZY</div><h2 class="result-title"><?= $result['available'] ? 'Orientacyjna wartość' : 'Za mało danych do wyceny' ?></h2>
<?php if ($result['available']): ?><p class="hint"><?= homes_e($result['scope']) ?> · <?= (int)$result['count'] ?> transakcji · <?= homes_e($result['from']) ?>–<?= homes_e($result['to']) ?> · RCN pobrano <?= homes_e($result['synced_at'] ?? 'brak daty') ?> UTC</p>
<div class="metrics"><div class="metric primary"><span>Scenariusz: <?= homes_e($conditionLabels[$input['condition']]) ?></span><strong><?= valuation_money($estimate) ?></strong></div><div class="metric"><span>Zakres cen porównawczych (20–80 percentyl)</span><strong><?= valuation_money($result['low']) ?> – <?= valuation_money($result['high']) ?></strong></div><div class="metric"><span>Mediana transakcji</span><strong><?= valuation_money($result['median']) ?></strong><small><?= valuation_money($result['price_m2']) ?>/m²</small></div></div>
<div class="range"><i style="left:50%"></i></div><div class="range-labels"><span><?= valuation_money($result['low']) ?></span><span>mediana</span><span><?= valuation_money($result['high']) ?></span></div>
<p class="note">Stan lokalu nie jest podany w RCN. „Do remontu” używa dolnego kwartyla cen, stan dobry mediany, a bardzo dobry górnego kwartyla. To scenariusze, nie zmierzone korekty za stan. Piętro, budynek, standard i prawa związane z lokalem mogą istotnie zmienić cenę.</p>
<?php if ($asking !== null): ?><h3>Porównanie z ceną zakupu</h3><div class="metrics"><div class="metric"><span>Cena zakupu / ofertowa</span><strong><?= valuation_money($asking) ?></strong></div><div class="metric"><span>Scenariusz sprzedaży<?= $input['condition']==='remont'?' po remoncie':'' ?></span><strong><?= valuation_money($resale) ?></strong></div><div class="metric"><span>Różnica po kosztach</span><strong class="<?= $spread !== null ? ($spread >= 0 ? 'positive' : 'negative') : '' ?>"><?= valuation_money($spread) ?></strong></div></div><p class="hint">Różnica = scenariusz sprzedaży − cena zakupu − koszt remontu − pozostałe koszty. To nie jest prognoza zysku ani gwarantowana cena sprzedaży.<?php if ($input['condition']==='remont' && $renovation === null): ?> Wpisz koszt remontu, aby policzyć różnicę.<?php endif ?></p><?php endif ?>
<p><a href="rcn_market.php?area=<?= rawurlencode((string)$result['area']) ?>">Zobacz porównywane transakcje →</a></p>
<?php else: ?><p>W ostatnich 24 miesiącach znaleziono tylko <?= (int)$result['count'] ?> transakcji mieszkań o zbliżonym metrażu. Wynik pojawi się przy co najmniej 5 transakcjach.</p><?php endif ?></section><?php endif ?>
<p class="hint">Źródło: <a href="https://www.geoportal.gov.pl/pl/dane/rejestr-cen-nieruchomosci-rcn/" target="_blank" rel="noopener noreferrer">GUGiK, Rejestr Cen Nieruchomości</a>. Uwzględniono lokale mieszkalne z rynku wtórnego w Kwidzynie, sprzedane w ostatnich 24 miesiącach, o metrażu ±20%. Rejestr może być aktualizowany z opóźnieniem.</p>
</main></body></html>

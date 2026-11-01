<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app.php';
require __DIR__ . '/core.php';
$config = config();
session_name('kampery_session');
session_set_cookie_params(['httponly' => true, 'secure' => true, 'samesite' => 'Lax']);
session_start();
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
if (empty($_SESSION['auth'])) { header('Location: ../index.php'); exit; }
$db = homes_db();
$groupId = '1572854416367362';
$groupUrl = 'https://www.facebook.com/groups/' . $groupId . '/';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        require_csrf($config);
        $rawUrl = trim((string)($_POST['post_url'] ?? ''));
        $parts = parse_url($rawUrl);
        if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
            || !in_array(strtolower((string)($parts['host'] ?? '')), ['facebook.com', 'www.facebook.com', 'm.facebook.com'], true)
            || !preg_match('~^/groups/' . $groupId . '/(?:posts|permalink)/([0-9]{5,25})/?$~', (string)($parts['path'] ?? ''), $match)) {
            throw new InvalidArgumentException('Wklej bezpośredni link do posta z tej grupy.');
        }
        $url = 'https://www.facebook.com/groups/' . $groupId . '/posts/' . $match[1] . '/';
        $title = trim(preg_replace('/\s+/u', ' ', (string)($_POST['title'] ?? '')) ?? '');
        if (mb_strlen($title) < 8 || mb_strlen($title) > 180) throw new InvalidArgumentException('Opis musi mieć od 8 do 180 znaków.');
        if (empty($_POST['kwidzyn_sale'])) throw new InvalidArgumentException('Potwierdź, że post dotyczy sprzedaży mieszkania w Kwidzynie.');
        $priceRaw = trim((string)($_POST['price'] ?? ''));
        $areaRaw = str_replace(',', '.', trim((string)($_POST['area'] ?? '')));
        $roomsRaw = trim((string)($_POST['rooms'] ?? ''));
        $price = $priceRaw === '' ? null : (ctype_digit($priceRaw) && (int)$priceRaw >= 50000 && (int)$priceRaw <= 10000000 ? (int)$priceRaw : false);
        $area = $areaRaw === '' ? null : (is_numeric($areaRaw) && (float)$areaRaw >= 15 && (float)$areaRaw <= 250 ? (float)$areaRaw : false);
        $rooms = $roomsRaw === '' ? null : (ctype_digit($roomsRaw) && (int)$roomsRaw >= 1 && (int)$roomsRaw <= 8 ? (int)$roomsRaw : false);
        if ($price === false || $area === false || $rooms === false) throw new InvalidArgumentException('Sprawdź cenę, metraż i liczbę pokoi.');
        $street = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($_POST['street'] ?? '')) ?? ''), 0, 100);
        $db->prepare("INSERT OR IGNORE INTO homes(url,source,title,price,area,rooms,street,image_url) VALUES (?,'Facebook',?,?,?,?,?,'')")
            ->execute([$url, $title, $price, $area, $rooms, $street]);
        $db->prepare('INSERT OR IGNORE INTO watched(url) VALUES (?)')->execute([$url]);
        if (!empty($_POST['favorite'])) $db->prepare("UPDATE watched SET purchase_candidate=1,candidate_at=CURRENT_TIMESTAMP WHERE url=?")->execute([$url]);
        header('Location: watch.php?view=' . (!empty($_POST['favorite']) ? 'candidates' : 'all') . '#zapisane'); exit;
    } catch (Throwable $e) { $error = $e->getMessage(); }
}
?>
<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Grupa Facebook · mieszkania IFS</title>
<style>:root{color-scheme:light}*{box-sizing:border-box}body{margin:0;background:#f4f7f6;color:#15312e;font:16px/1.5 system-ui,-apple-system,sans-serif}header{background:#123c38;color:white;padding:22px}header div,main{max-width:850px;margin:auto}header a{color:#d7eee9}main{padding:24px}.card{background:#fff;border:1px solid #dae5e1;border-radius:14px;padding:22px;margin:18px 0;box-shadow:0 2px 8px #1a44330b}h1{margin:0;font-size:28px}h2{margin-top:0}a{color:#147668}.muted{color:#637a75}.notice{background:#fff3d8;border-left:4px solid #c89128;padding:12px 16px;border-radius:6px}.error{background:#fde8e6;color:#a32626;padding:12px 16px;border-radius:8px}label{display:block;font-weight:650;margin:13px 0 5px}input:not([type=checkbox]){width:100%;border:1px solid #b8cbc5;border-radius:8px;padding:10px;font:inherit;color:#15312e}.fields{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:0 14px}.check{display:flex;align-items:flex-start;gap:9px;font-weight:400}.check input{margin-top:5px}button{background:#0c6958;color:white;border:0;border-radius:9px;padding:11px 16px;font:inherit;cursor:pointer}</style><link rel="stylesheet" href="ui.css?v=2"></head><body class="page-facebook">
<header><div><h1>Grupa Facebook · nieruchomości</h1><a href="./">← Oferty mieszkań</a> · <a href="watch.php">Obserwowane</a></div></header><main>
<section class="card"><h2>KWIDZYN Sprzedam Wynajme mieszkanie Okolice</h2><p><a href="<?= homes_e($groupUrl) ?>" target="_blank" rel="noopener noreferrer">Otwórz grupę na Facebooku ↗</a></p><p class="notice">Facebook nie udostępnia postów tej grupy do publicznego odczytu przez serwer IFS. Grupa jest zapisana jako kanał do ręcznego przeglądania; nowe posty nie pojawią się automatycznie w ofertach ani powiadomieniach.</p></section>
<section class="card"><h2>Zapisz ofertę z grupy</h2><p class="muted">Wklej bezpośredni link do posta o sprzedaży mieszkania w Kwidzynie. Podaj dane widoczne w poście; puste pola pozostaną nieznane. Przed zakupem sprawdź aktualność i szczegóły u autora.</p>
<?php if ($error): ?><p class="error" role="alert"><?= homes_e($error) ?></p><?php endif ?>
<form method="post"><input type="hidden" name="csrf" value="<?= homes_e(csrf_token($config)) ?>">
<label for="post_url">Link do posta</label><input id="post_url" name="post_url" type="url" required maxlength="2048" placeholder="https://www.facebook.com/groups/<?= $groupId ?>/posts/…/" value="<?= homes_e((string)($_POST['post_url'] ?? '')) ?>">
<label for="title">Krótki opis</label><input id="title" name="title" required minlength="8" maxlength="180" placeholder="np. Mieszkanie 3 pokoje przy ul. Polnej" value="<?= homes_e((string)($_POST['title'] ?? '')) ?>">
<div class="fields"><div><label for="price">Cena (zł)</label><input id="price" name="price" type="number" min="50000" max="10000000" step="1" value="<?= homes_e((string)($_POST['price'] ?? '')) ?>"></div><div><label for="area">Metraż (m²)</label><input id="area" name="area" type="number" min="15" max="250" step="0.01" value="<?= homes_e((string)($_POST['area'] ?? '')) ?>"></div><div><label for="rooms">Pokoje</label><input id="rooms" name="rooms" type="number" min="1" max="8" step="1" value="<?= homes_e((string)($_POST['rooms'] ?? '')) ?>"></div></div>
<label for="street">Ulica w Kwidzynie</label><input id="street" name="street" maxlength="100" value="<?= homes_e((string)($_POST['street'] ?? '')) ?>">
<label class="check"><input type="checkbox" name="kwidzyn_sale" value="1" required <?= !empty($_POST['kwidzyn_sale']) ? 'checked' : '' ?>>Post dotyczy sprzedaży mieszkania w Kwidzynie</label>
<label class="check"><input type="checkbox" name="favorite" value="1" <?= !empty($_POST['favorite']) ? 'checked' : '' ?>>Zapisz też do ulubionych ofert</label>
<button type="submit">Zapisz do obserwowanych</button></form></section>
</main></body></html>

<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app.php';
require __DIR__ . '/core.php';
$config = config();
$cli = PHP_SAPI === 'cli';
if ($cli) {
    $archivePath = '/home/ifs/adradar-mieszkania-2026-11-01.json';
    if (!is_file($archivePath)) { fwrite(STDERR, "Brak prywatnego archiwum Adradar.\n"); exit(1); }
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_FILES['archive'] = ['error' => UPLOAD_ERR_OK, 'size' => filesize($archivePath), 'tmp_name' => $archivePath];
} else {
    session_name('kampery_session');
    session_set_cookie_params(['httponly' => true, 'secure' => true, 'samesite' => 'Lax']);
    session_start();
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    if (empty($_SESSION['auth'])) { header('Location: ../index.php'); exit; }
}
$db = homes_db();
$result = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!$cli) require_csrf($config);
        $file = $_FILES['archive'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || (int)$file['size'] > 4_000_000) {
            throw new RuntimeException('Wybierz poprawny plik JSON o wielkości do 4 MB.');
        }
        $archive = json_decode((string)file_get_contents((string)$file['tmp_name']), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($archive) || ($archive['source'] ?? '') !== 'https://www.adradar.pl/' || !is_array($archive['offers'] ?? null) || count($archive['offers']) > 5000) {
            throw new RuntimeException('Plik nie jest eksportem mieszkań Adradar.');
        }
        $db->exec("CREATE TABLE IF NOT EXISTS adradar_offers (group_url TEXT PRIMARY KEY, description TEXT NOT NULL DEFAULT '', facts TEXT NOT NULL DEFAULT '', status TEXT NOT NULL DEFAULT '', summary_json TEXT NOT NULL DEFAULT '[]', source_date TEXT NOT NULL DEFAULT '', imported_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
        $db->exec("CREATE TABLE IF NOT EXISTS adradar_history (group_url TEXT NOT NULL, row_number INTEGER NOT NULL, price TEXT NOT NULL DEFAULT '', seller_type TEXT NOT NULL DEFAULT '', portal_url TEXT NOT NULL DEFAULT '', city TEXT NOT NULL DEFAULT '', street TEXT NOT NULL DEFAULT '', added TEXT NOT NULL DEFAULT '', updated TEXT NOT NULL DEFAULT '', PRIMARY KEY(group_url,row_number))");
        $newHomes = $newWatched = $historyCount = 0;
        $db->beginTransaction();
        $homeInsert = $db->prepare("INSERT OR IGNORE INTO homes(url,source,title,price,area,rooms,street,image_url) VALUES (?,'Adradar',?,?,?,?,?,?)");
        $watchInsert = $db->prepare('INSERT OR IGNORE INTO watched(url) VALUES (?)');
        $offerInsert = $db->prepare('INSERT INTO adradar_offers(group_url,description,facts,status,summary_json,source_date) VALUES (?,?,?,?,?,?) ON CONFLICT(group_url) DO UPDATE SET description=excluded.description,facts=excluded.facts,status=excluded.status,summary_json=excluded.summary_json,source_date=excluded.source_date,imported_at=CURRENT_TIMESTAMP');
        $historyDelete = $db->prepare('DELETE FROM adradar_history WHERE group_url=?');
        $historyInsert = $db->prepare('INSERT INTO adradar_history(group_url,row_number,price,seller_type,portal_url,city,street,added,updated) VALUES (?,?,?,?,?,?,?,?,?)');
        foreach ($archive['offers'] as $offer) {
            if (!is_array($offer)) continue;
            $url = (string)($offer['group'] ?? '');
            $parts = parse_url($url);
            if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== 'www.adradar.pl' || ($parts['path'] ?? '') !== '/szczegoly_oferty' || !preg_match('/(?:^|&)grupa=\d+(?:&|$)/', (string)($parts['query'] ?? ''))) continue;
            $title = mb_substr(trim((string)($offer['title'] ?? '')), 0, 180);
            if ($title === '') continue;
            $facts = mb_substr((string)($offer['facts'] ?? ''), 0, 1000);
            $priceText = (string)($offer['price'] ?? '');
            $price = preg_match('/([\d\s\x{00a0}]+)\s*zł/u', $priceText, $m) ? (int)preg_replace('/\D/u', '', $m[1]) : null;
            $area = preg_match('/(\d+(?:[,.]\d+)?)\s*m[²2]/u', $facts, $m) ? (float)str_replace(',', '.', $m[1]) : null;
            $rooms = preg_match('/(\d+)\s*pok/iu', $facts, $m) ? (int)$m[1] : null;
            $street = mb_substr(trim((string)(explode(',', $title, 2)[1] ?? '')), 0, 100);
            $image = (string)($offer['image'] ?? '');
            if (!filter_var($image, FILTER_VALIDATE_URL) || parse_url($image, PHP_URL_SCHEME) !== 'https') $image = '';
            $homeInsert->execute([$url, $title, $price, $area, $rooms, $street, mb_substr($image, 0, 2048)]);
            $newHomes += $homeInsert->rowCount();
            $watchInsert->execute([$url]);
            $newWatched += $watchInsert->rowCount();
            $summary = is_array($offer['summary'] ?? null) ? array_slice(array_values(array_filter($offer['summary'], 'is_string')), 0, 30) : [];
            $offerInsert->execute([$url, mb_substr((string)($offer['description'] ?? ''), 0, 5000), $facts, mb_substr((string)($offer['status'] ?? ''), 0, 100), json_encode($summary, JSON_UNESCAPED_UNICODE), mb_substr((string)($offer['firstAdded'] ?? ''), 0, 30)]);
            $historyDelete->execute([$url]);
            if (is_array($offer['links'] ?? null)) foreach ($offer['links'] as $i => $link) {
                if (!is_array($link) || $i >= 1000) break;
                $portalUrl = (string)($link['url'] ?? '');
                if (!filter_var($portalUrl, FILTER_VALIDATE_URL) || parse_url($portalUrl, PHP_URL_SCHEME) !== 'https') $portalUrl = '';
                $historyInsert->execute([$url, $i + 1, mb_substr((string)($link['price'] ?? ''), 0, 50), mb_substr((string)($link['sellerType'] ?? ''), 0, 100), mb_substr($portalUrl, 0, 2048), mb_substr((string)($link['city'] ?? ''), 0, 100), mb_substr((string)($link['street'] ?? ''), 0, 100), mb_substr((string)($link['added'] ?? ''), 0, 30), mb_substr((string)($link['updated'] ?? ''), 0, 30)]);
                $historyCount++;
            }
        }
        $db->commit();
        $result = 'Zaimportowano ' . count($archive['offers']) . ' mieszkań i ' . $historyCount . ' wpisów historii. Do obserwowanych dodano ' . $newWatched . ' nowych pozycji; w bazie ofert dodano ' . $newHomes . '.';
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        $error = $e->getMessage();
    }
}
if ($cli) {
    if ($error !== '') { fwrite(STDERR, $error . "\n"); exit(1); }
    fwrite(STDOUT, $result . "\n");
    exit;
}
?>
<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Import Adradar · IFS</title><style>*{box-sizing:border-box}body{margin:0;background:#f4f7f6;color:#15312e;font:16px/1.5 system-ui,sans-serif}header{background:#123c38;color:white;padding:22px}header div,main{max-width:850px;margin:auto}header a{color:#d7eee9}main{padding:24px}.card{background:white;border:1px solid #dae5e1;border-radius:14px;padding:22px;margin:18px 0}input,button{font:inherit}input{display:block;margin:12px 0}button{background:#0c6958;color:white;border:0;border-radius:9px;padding:11px 17px;cursor:pointer}.ok{color:#13704b}.error{color:#a32626}a{color:#0b685a}</style></head><body><header><div><h1>Import historii Adradar</h1><a href="watch.php">← Obserwowane mieszkania</a></div></header><main><div class="card"><p>Import zapisuje mieszkania z eksportu w obserwowanych. Historia Adradar pozostaje oddzielona od zmian wykrytych przez monitor IFS. Ponowny import aktualizuje dane Adradar i zachowuje Twoje wyceny oraz notatki.</p><?php if ($result): ?><p class="ok"><b><?= homes_e($result) ?></b></p><p><a href="watch.php">Otwórz obserwowane →</a></p><?php endif ?><?php if ($error): ?><p class="error"><?= homes_e($error) ?></p><?php endif ?><form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?= homes_e(csrf_token($config)) ?>"><label>Plik JSON z eksportem mieszkań<input type="file" name="archive" accept=".json,application/json" required></label><button type="submit">Importuj do obserwowanych</button></form></div></main></body></html>

<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/core.php';
require __DIR__ . '/push.php';
require __DIR__ . '/email.php';

$lock = fopen(dirname(__DIR__) . '/data/mieszkania.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit("Skan już trwa\n");
$db = homes_db();
$insert = $db->prepare('INSERT OR IGNORE INTO homes (url,source,title,price,area,rooms,street,image_url) VALUES (:url,:source,:title,:price,:area,:rooms,:street,:image_url)');
$update = $db->prepare('UPDATE homes SET title=:title,price=:price,area=:area,rooms=:rooms,street=:street,image_url=:image_url,last_seen=CURRENT_TIMESTAMP WHERE url=:url');
$log = $db->prepare('INSERT INTO scans (source,found,added,error) VALUES (?,?,?,?)');
$oldPrice = $db->prepare('SELECT price FROM homes WHERE url=?');
$history = $db->prepare('INSERT INTO price_history (url,old_price,new_price) VALUES (?,?,?)');
$new = [];
foreach (homes_sources() as $source => $url) {
    $found = 0; $added = 0; $error = '';
    try {
        $offers = homes_offers(homes_fetch($url), $source);
        $found = count($offers);
        $previous = (int)$db->query("SELECT COUNT(*) FROM scans WHERE source = " . $db->quote($source) . " AND error = ''")->fetchColumn();
        $db->beginTransaction();
        foreach ($offers as $offer) {
            $insert->execute($offer);
            if ($insert->rowCount()) {
                $added++;
                $duplicate = false;
                if ($source === 'Morizon' || $source === 'Domiporta' || $source === 'Gratka' || $source === 'Nieruchomosci-online') {
                    $match = $db->prepare("SELECT 1 FROM homes WHERE source<>? AND price=? AND area IS NOT NULL AND ABS(area-?)<0.15 AND rooms=? AND street=? AND street<>'' LIMIT 1");
                    $match->execute([$source, $offer['price'], $offer['area'], $offer['rooms'], $offer['street']]);
                    $duplicate = (bool)$match->fetchColumn();
                }
                if ($previous > 0 && !$duplicate) $new[$offer['url']] = $offer;
            } else {
                $oldPrice->execute([$offer['url']]);
                $before = $oldPrice->fetchColumn();
                if ($before !== false && $before !== null && $offer['price'] !== null && (int)$before !== $offer['price']) {
                    $history->execute([$offer['url'], (int)$before, $offer['price']]);
                    if ($offer['price'] < (int)$before) $new[$offer['url']] = ['title' => 'Obniżka ceny: ' . $offer['title'], 'url' => $offer['url']];
                }
                $update->execute(array_diff_key($offer, ['source' => true]));
            }
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        $error = mb_substr($e->getMessage(), 0, 180);
    }
    $log->execute([$source, $found, $added, $error]);
    echo "$source: $found ofert, $added nowych" . ($error ? ", $error" : '') . "\n";
}
if ($new) {
    $alert = $db->prepare('INSERT INTO alerts (title,url) VALUES (?,?)');
    foreach ($new as $offer) $alert->execute([$offer['title'], $offer['url']]);
    if (!homes_email_alerts(array_values($new))) error_log('Kwidzyn email: mail() odmówił przyjęcia wiadomości');
    try { homes_push_all($db); } catch (Throwable $e) { error_log('Kwidzyn push: ' . $e->getMessage()); }
}

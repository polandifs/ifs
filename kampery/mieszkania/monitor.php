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
$update = $db->prepare("UPDATE homes SET title=COALESCE(NULLIF(:title,''),title),price=COALESCE(:price,price),area=COALESCE(:area,area),rooms=COALESCE(:rooms,rooms),street=COALESCE(NULLIF(:street,''),street),image_url=COALESCE(NULLIF(:image_url,''),image_url),last_seen=CURRENT_TIMESTAMP WHERE url=:url");
$log = $db->prepare('INSERT INTO scans (source,found,added,error) VALUES (?,?,?,?)');
$oldOffer = $db->prepare('SELECT title,price,area,rooms,street,last_seen FROM homes WHERE url=?');
$history = $db->prepare('INSERT INTO price_history (url,old_price,new_price) VALUES (?,?,?)');
$event = $db->prepare('INSERT INTO offer_events (url,kind,old_value,new_value) VALUES (?,?,?,?)');
$scansDuringGap = $db->prepare("SELECT COUNT(*) FROM scans WHERE source=? AND error='' AND found>0 AND checked_at>?");
$new = [];
$baselineOlxPages = in_array('--baseline-olx-pages', $argv, true);
foreach (homes_sources() as $source => $url) {
    $found = 0; $added = 0; $error = '';
    try {
        $offers = homes_offers(homes_fetch($url), $source);
        $firstPageUrls = array_fill_keys(array_column($offers, 'url'), true);
        if ($source === 'OLX') {
            // OLX mixes Kwidzyn results with nearby towns; stop once a page has no Kwidzyn offers.
            for ($page = 2; $page <= 5; $page++) {
                try { $pageOffers = homes_html_offers(homes_fetch($url . '?page=' . $page), $source); }
                catch (Throwable $e) { break; }
                if (!$pageOffers) break;
                $offers = array_merge($offers, $pageOffers);
            }
            $offers = array_values(array_column($offers, null, 'url'));
        }
        $found = count($offers);
        $previous = (int)$db->query("SELECT COUNT(*) FROM scans WHERE source = " . $db->quote($source) . " AND error = ''")->fetchColumn();
        $db->beginTransaction();
        foreach ($offers as $offer) {
            $insert->execute($offer);
            if ($insert->rowCount()) {
                $added++;
                $duplicate = false;
                if ($offer['price'] !== null && $offer['area'] !== null && $offer['rooms'] !== null && $offer['street'] !== '') {
                    $match = $db->prepare("SELECT 1 FROM homes WHERE source<>? AND price=? AND area IS NOT NULL AND ABS(area-?)<0.15 AND rooms=? AND street=? AND street<>'' LIMIT 1");
                    $match->execute([$source, $offer['price'], $offer['area'], $offer['rooms'], $offer['street']]);
                    $duplicate = (bool)$match->fetchColumn();
                }
                if ($previous > 0 && !$duplicate && !($baselineOlxPages && $source === 'OLX' && !isset($firstPageUrls[$offer['url']]))) $new[$offer['url']] = $offer;
            } else {
                $oldOffer->execute([$offer['url']]);
                $old = $oldOffer->fetch(PDO::FETCH_ASSOC);
                $before = $old['price'] ?? null;
                if ($before !== false && $before !== null && $offer['price'] !== null && (int)$before !== $offer['price']) {
                    $history->execute([$offer['url'], (int)$before, $offer['price']]);
                    if ($offer['price'] < (int)$before) $new[$offer['url']] = ['title' => 'Obniżka ceny: ' . $offer['title'], 'url' => $offer['url']];
                } elseif ($before === null && $offer['price'] !== null) {
                    $event->execute([$offer['url'], 'price_added', '', (string)$offer['price']]);
                }
                if ($old) {
                    foreach (['title', 'area', 'rooms', 'street'] as $field) {
                        $beforeValue = trim((string)$old[$field]);
                        $afterValue = trim((string)$offer[$field]);
                        $changed = $beforeValue !== $afterValue;
                        if ($changed && in_array($field, ['area', 'rooms'], true) && is_numeric($beforeValue) && is_numeric($afterValue)) {
                            $changed = abs((float)$beforeValue - (float)$afterValue) > 0.001;
                        }
                        if ($afterValue !== '' && $changed) {
                            $event->execute([$offer['url'], $field, $beforeValue, $afterValue]);
                        }
                    }
                    if (time() - strtotime($old['last_seen']) >= 3600) {
                        $scansDuringGap->execute([$source, $old['last_seen']]);
                        if ((int)$scansDuringGap->fetchColumn() > 0) $event->execute([$offer['url'], 'returned', '', '']);
                    }
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
$missingImages = $db->query("SELECT h.url FROM homes h LEFT JOIN image_attempts a ON a.url=h.url WHERE h.source='OLX' AND h.image_url='' AND (a.url IS NULL OR a.attempted_at < datetime('now','-1 day')) ORDER BY h.first_seen DESC LIMIT 8")->fetchAll(PDO::FETCH_COLUMN);
$markImageAttempt = $db->prepare('INSERT OR REPLACE INTO image_attempts (url,attempted_at) VALUES (?,CURRENT_TIMESTAMP)');
$saveImage = $db->prepare('UPDATE homes SET image_url=? WHERE url=? AND image_url=\'\'');
foreach ($missingImages as $url) {
    $markImageAttempt->execute([$url]);
    $image = homes_detail_image($url);
    if ($image !== '') $saveImage->execute([$image, $url]);
}
if ($missingImages) echo 'Zdjęcia OLX: sprawdzono ' . count($missingImages) . " brakujących ofert\n";

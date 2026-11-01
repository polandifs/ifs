<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/core.php';
require __DIR__ . '/push.php';

$lock = fopen(dirname(__DIR__) . '/data/mieszkania.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit("Skan już trwa\n");
$db = homes_db();
$insert = $db->prepare('INSERT OR IGNORE INTO homes (url,source,title,price,area,rooms,street) VALUES (:url,:source,:title,:price,:area,:rooms,:street)');
$update = $db->prepare('UPDATE homes SET title=:title,price=:price,area=:area,rooms=:rooms,street=:street,last_seen=CURRENT_TIMESTAMP WHERE url=:url');
$log = $db->prepare('INSERT INTO scans (source,found,added,error) VALUES (?,?,?,?)');
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
                if ($source === 'Morizon') {
                    $match = $db->prepare("SELECT 1 FROM homes WHERE source='Gratka' AND title=? AND price=? AND area=? LIMIT 1");
                    $match->execute([$offer['title'], $offer['price'], $offer['area']]);
                    $duplicate = (bool)$match->fetchColumn();
                }
                if ($previous > 0 && !$duplicate) $new[$offer['url']] = $offer;
            } else {
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
    try { homes_push_all($db); } catch (Throwable $e) { error_log('Kwidzyn push: ' . $e->getMessage()); }
}

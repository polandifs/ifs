<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/app.php';

$lock = fopen(__DIR__ . '/data/photos.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit("Zdjęcia: skan już trwa\n");
$database = db();

function photo_fetch(string $url): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 2,
        CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; IFSCamperFinder/1.0)']);
    $html = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return is_string($html) && $code === 200 ? $html : '';
}

function photo_url(string $html, bool $reference): string {
    $pattern = $reference
        ? '~<section[^>]*class="[^"]*o-gal-wrap[^"]*"[\s\S]*?<img[^>]+src="([^"]+)"~i'
        : '~<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)~i';
    if (!preg_match($pattern, $html, $m)) return '';
    $url = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return strlen($url) <= 2048 && filter_var($url, FILTER_VALIDATE_URL) && parse_url($url, PHP_URL_SCHEME) === 'https' ? $url : '';
}

$count = 0;
$rows = $database->query("SELECT c.url,c.reference_url FROM catalog_offers c WHERE c.image_url='' AND c.reference_url LIKE 'https://wybierzkampera.pl/oferta/%' ORDER BY CASE WHEN EXISTS (SELECT 1 FROM discoveries d WHERE d.url=c.url) THEN 0 ELSE 1 END, c.price_pln ASC LIMIT 24")->fetchAll(PDO::FETCH_ASSOC);
$saveCatalog = $database->prepare('UPDATE catalog_offers SET image_url=? WHERE url=?');
foreach ($rows as $row) {
    if (parse_url($row['reference_url'], PHP_URL_HOST) !== 'wybierzkampera.pl') continue;
    $image = photo_url(photo_fetch($row['reference_url']), true);
    $saveCatalog->execute([$image ?: '-', $row['url']]);
    if ($image) $count++;
}
$saveDiscovery = $database->prepare('UPDATE discoveries SET image_url=? WHERE url=?');
$database->exec("UPDATE discoveries SET image_url=(SELECT image_url FROM catalog_offers c WHERE c.url=discoveries.url) WHERE image_url='' AND EXISTS (SELECT 1 FROM catalog_offers c WHERE c.url=discoveries.url AND c.image_url<>'' AND c.image_url<>'-')");
$others = $database->query("SELECT url FROM discoveries WHERE image_url='' AND source<>'WybierzKampera' ORDER BY first_seen DESC LIMIT 8")->fetchAll(PDO::FETCH_COLUMN);
foreach ($others as $url) {
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    if (!in_array($host, ['www.otomoto.pl','www.kleinanzeigen.de','www.marktplaats.nl','www.2dehands.be'], true)) continue;
    $image = photo_url(photo_fetch($url), false);
    $saveDiscovery->execute([$image ?: '-', $url]);
    if ($image) $count++;
}
echo 'Zdjęcia: ' . $count . ' nowych; sprawdzono ' . count($rows) . ' kart katalogu i ' . count($others) . " odkryć\n";

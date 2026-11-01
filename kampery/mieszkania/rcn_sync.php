<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/core.php';

// Official GUGiK WFS. The BBOX covers Kwidzyn; the municipality ID below
// excludes neighbouring villages returned by the same spatial query.
$endpoint = 'https://mapy.geoportal.gov.pl/wss/service/rcn?SERVICE=WFS&REQUEST=GetFeature&VERSION=2.0.0&TYPENAMES=ms%3Alokale&COUNT=10000&BBOX=53.68%2C18.88%2C53.76%2C19.04%2Curn%3Aogc%3Adef%3Acrs%3AEPSG%3A%3A4326';
$lock = fopen(dirname(__DIR__) . '/data/rcn.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit("Synchronizacja RCN już trwa\n");
$tmp = tempnam(sys_get_temp_dir(), 'ifs-rcn-');
if ($tmp === false) throw new RuntimeException('Nie można utworzyć pliku tymczasowego.');
try {
    $file = fopen($tmp, 'wb');
    if (!$file) throw new RuntimeException('Nie można otworzyć pliku tymczasowego.');
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [CURLOPT_FILE => $file, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 90, CURLOPT_MAXFILESIZE => 30000000,
        CURLOPT_USERAGENT => 'IFS-Kwidzyn-RCN/1.0 (publiczna usluga WFS GUGiK)']);
    $ok = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    fclose($file);
    if (!$ok || $status !== 200 || filesize($tmp) < 1000) throw new RuntimeException('WFS RCN: HTTP ' . $status . ' ' . $error);

    $reader = new XMLReader();
    if (!$reader->open($tmp, null, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) throw new RuntimeException('Nie można odczytać XML RCN.');
    $rows = [];
    $transactionCounts = [];
    while ($reader->read()) {
        if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'FeatureCollection' && $reader->getAttribute('next')) {
            throw new RuntimeException('WFS RCN zwrócił niepełną stronę wyników; zachowano poprzednie dane.');
        }
        if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'lokale' || $reader->namespaceURI !== 'http://mapserver.gis.umn.edu/mapserver') continue;
        $feature = simplexml_load_string($reader->readOuterXML(), SimpleXMLElement::class, LIBXML_NONET);
        if ($feature === false) continue;
        $ms = $feature->children('http://mapserver.gis.umn.edu/mapserver');
        $localId = (string)$ms->LOK_ID_LOKALU;
        if (strpos($localId, '220701_1.') !== 0 || (string)$ms->LOK_FUNKCJA !== 'mieszkalna' || (string)$ms->TRAN_RODZAJ_TRANS !== 'wolnyRynek') continue;
        $date = (string)$ms->DOK_DATA->children('http://www.opengis.net/gml/3.2')->timePosition;
        $area = (float)$ms->LOK_POW_UZYT;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $area < 15 || $area > 250) continue;
        $transactionId = (string)$ms->TRAN_LOKALNY_ID_IIP;
        if ($transactionId === '') continue;
        // RCN point coordinates are EPSG:2180 (easting, northing), in metres.
        $position = trim((string)$ms->GEOMETRIA->children('http://www.opengis.net/gml/3.2')->Point->pos);
        $coordinates = preg_split('/\s+/', $position);
        $easting = isset($coordinates[0]) && is_numeric($coordinates[0]) ? (float)$coordinates[0] : null;
        $northing = isset($coordinates[1]) && is_numeric($coordinates[1]) ? (float)$coordinates[1] : null;
        if ($easting === null || $northing === null || $easting < 600000 || $easting > 700000 || $northing < 450000 || $northing > 550000) continue;
        $transactionCounts[$transactionId] = ($transactionCounts[$transactionId] ?? 0) + 1;
        $rows[] = [
            'id' => (string)$ms->gid, 'transaction_id' => $transactionId, 'date' => $date,
            'area' => $area, 'market' => (string)$ms->TRAN_RODZAJ_RYNKU,
            'local_price' => (string)$ms->LOK_CENA_BRUTTO,
            'property_price' => (string)$ms->NIER_CENA_BRUTTO,
            'transaction_price' => (string)$ms->TRAN_CENA_BRUTTO,
            'address' => mb_substr((string)$ms->LOK_ADRES, 0, 200),
            'easting' => $easting, 'northing' => $northing,
        ];
    }
    $reader->close();
    if (count($rows) < 100) throw new RuntimeException('RCN zwrócił zbyt mało lokali; zachowano poprzednie dane.');

    $db = homes_db();
    $db->exec('CREATE TABLE IF NOT EXISTS rcn_transactions (id TEXT PRIMARY KEY, transaction_id TEXT NOT NULL, sold_at TEXT NOT NULL, price INTEGER NOT NULL, area REAL NOT NULL, price_m2 REAL NOT NULL, market TEXT NOT NULL, address TEXT NOT NULL, easting REAL, northing REAL)');
    $columns = array_column($db->query('PRAGMA table_info(rcn_transactions)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('easting', $columns, true)) $db->exec('ALTER TABLE rcn_transactions ADD COLUMN easting REAL');
    if (!in_array('northing', $columns, true)) $db->exec('ALTER TABLE rcn_transactions ADD COLUMN northing REAL');
    $db->exec('CREATE INDEX IF NOT EXISTS rcn_comparables ON rcn_transactions(market,sold_at,area)');
    $db->exec('CREATE TABLE IF NOT EXISTS rcn_meta (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
    $db->beginTransaction();
    try {
        $db->exec('DELETE FROM rcn_transactions');
        $insert = $db->prepare('INSERT OR IGNORE INTO rcn_transactions (id,transaction_id,sold_at,price,area,price_m2,market,address,easting,northing) VALUES (?,?,?,?,?,?,?,?,?,?)');
        $saved = 0;
        foreach ($rows as $row) {
            $priceText = $row['local_price'] !== '' ? $row['local_price'] : ($row['property_price'] !== '' ? $row['property_price'] : (($transactionCounts[$row['transaction_id']] ?? 0) === 1 ? $row['transaction_price'] : ''));
            if (!is_numeric($priceText)) continue;
            $price = (int)round((float)$priceText);
            $priceM2 = $price / $row['area'];
            if ($price < 50000 || $price > 2000000 || $priceM2 < 1000 || $priceM2 > 50000) continue;
            $insert->execute([$row['id'], $row['transaction_id'], $row['date'], $price, $row['area'], $priceM2, $row['market'], $row['address'], $row['easting'], $row['northing']]);
            $saved += $insert->rowCount();
        }
        if ($saved < 100) throw new RuntimeException('Za mało prawidłowych cen RCN; zachowano poprzednie dane.');
        $meta = $db->prepare('INSERT OR REPLACE INTO rcn_meta (key,value) VALUES (?,?)');
        $meta->execute(['synced_at', gmdate('Y-m-d H:i:s')]);
        $meta->execute(['source_url', 'https://mapy.geoportal.gov.pl/wss/service/rcn']);
        $meta->execute(['count', (string)$saved]);
        $db->commit();
        echo "RCN Kwidzyn: zapisano $saved transakcji lokali mieszkalnych\n";
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
} finally {
    unlink($tmp);
    flock($lock, LOCK_UN);
    fclose($lock);
}

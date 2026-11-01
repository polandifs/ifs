<?php
declare(strict_types=1);

require __DIR__ . '/app.php';

// Public search pages only. Run from CLI via a cPanel cron job.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$sources = [
    ['PL', 'Otomoto', 'https://www.otomoto.pl/dostawcze/kamper/q-uszkodzony', 'otomoto.pl', '/dostawcze/oferta/'],
    ['DE', 'Kleinanzeigen', 'https://www.kleinanzeigen.de/s-wohnmobil-beschadigt/k0', 'kleinanzeigen.de', '/s-anzeige/'],
    ['NL', 'Marktplaats', 'https://www.marktplaats.nl/q/camper+schade/', 'marktplaats.nl', '/v/'],
    ['BE', '2dehands', 'https://www.2dehands.be/q/camper+schade/', '2dehands.be', '/v/'],
    ['PL', 'WybierzKampera', 'https://wybierzkampera.pl/pobierz/kampery-oferty.csv', '', ''],
];

function fetch_page(string $url): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; IFSCamperFinder/1.0)',
        CURLOPT_ENCODING => '',
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!is_string($body) || $status !== 200 || strlen($body) < 1000) {
        throw new RuntimeException('HTTP ' . $status . ' lub pusta odpowiedź');
    }
    return $body;
}

function clean_text(string $text): string
{
    return trim(preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
}

function candidates(string $html, array $source): array
{
    [$country, $name, $searchUrl, $host, $path] = $source;
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    $xp = new DOMXPath($dom);
    foreach ($xp->query('//script|//style') as $noise) $noise->parentNode->removeChild($noise);
    $found = [];
    foreach ($xp->query('//a[@href]') as $a) {
        $href = $a->getAttribute('href');
        if (strpos($href, $path) === false) continue;
        if (strpos($href, 'http') === 0) {
            $urlHost = strtolower((string)parse_url($href, PHP_URL_HOST));
            if ($urlHost !== $host && $urlHost !== 'www.' . $host) continue;
            $url = $href;
        } elseif (strpos($href, '/') === 0) {
            $url = 'https://www.' . $host . $href;
        } else continue;
        $url = preg_replace('/[?#].*$/', '', $url);
        if (!is_string($url) || isset($found[$url])) continue;
        $card = $a;
        for ($i = 0; $i < 5 && $card->parentNode; $i++) {
            $card = $card->parentNode;
            if (in_array(strtolower($card->nodeName), ['article', 'li'], true)) break;
        }
        $title = clean_text($a->getAttribute('aria-label') ?: $a->textContent);
        if (mb_strlen($title) < 12 || mb_strlen($title) > 180) {
            $imgs = $xp->query('.//img[@alt or @title]', $a);
            if ($imgs->length) $title = clean_text($imgs->item(0)->getAttribute('title') ?: $imgs->item(0)->getAttribute('alt'));
        }
        if (mb_strlen($title) < 12 || mb_strlen($title) > 180) {
            $headings = $xp->query('.//*[self::h2 or self::h3]', $card);
            if ($headings->length) $title = clean_text($headings->item(0)->textContent);
        }
        if (mb_strlen($title) < 12 || mb_strlen($title) > 200) continue;
        $body = clean_text($card->textContent);
        if (preg_match('/\b(gezocht|gesucht|kupię|kupimy|skup|ankauf|aankoop|inkoop|reparatie|naprawa|serwis|service|werkplaats|onderdelen|teile|sloop|verkocht|sprzedany)\b/ui', $title)) continue;
        $signal = '';
        $signalText = in_array($country, ['NL', 'BE'], true) ? $title : $title . ' ' . mb_substr($body, 0, 500);
        if (preg_match('/\b(uszkodzony|uszkodzona|powypadkowy|do remontu|zalany|beschädigt|unfall|defekt|reparaturbedürftig|schade|opknapper|defect|accident|ongeval)\b/ui', $signalText, $m)) {
            $signal = mb_strtolower($m[0]);
        }
        if ($signal === '') continue;
        // Search pages can include unrelated promoted ads. Require a relevant vehicle term.
        if (!preg_match('/kamper|camper|wohnmobil|wohnwagen|przyczep|caravan/ui', $title . ' ' . mb_substr($body, 0, 250))) continue;
        $price = '';
        if (in_array($country, ['PL', 'DE'], true) && preg_match('/(?<!\d)(?:\d{1,3}(?:[ .\x{00A0}]\d{3})+|\d{4,7})(?:,\d{2})?\s?(?:zł|PLN|€|EUR)/u', $body, $m)) $price = clean_text($m[0]);
        $found[$url] = ['url' => $url, 'title' => mb_substr($title, 0, 180), 'country' => $country,
            'source' => $name, 'price_text' => $price, 'signal' => $signal];
        if (count($found) >= 60) break;
    }
    return array_values($found);
}

function reference_candidates(string $csv): array
{
    $stream = fopen('php://temp', 'w+');
    fwrite($stream, $csv);
    rewind($stream);
    $header = fgetcsv($stream, 0, ';');
    if (!is_array($header)) throw new RuntimeException('Nieprawidłowy plik CSV');
    $header = array_map(static function ($x) { return trim(str_replace("\xEF\xBB\xBF", '', $x)); }, $header);
    $required = ['Stan', 'Typ', 'Marka', 'Model', 'Rok', 'Przebieg km', 'Cena zł brutto', 'Link do ogłoszenia'];
    if (array_diff($required, $header)) throw new RuntimeException('Zmieniły się kolumny CSV');
    $rows = []; $groups = [];
    while (($values = fgetcsv($stream, 0, ';')) !== false) {
        if (count($values) !== count($header)) continue;
        $r = array_combine($header, $values);
        if (mb_strtolower(trim($r['Stan'])) !== 'używany') continue;
        $year = (int)$r['Rok'];
        $price = (int)preg_replace('/\D/', '', $r['Cena zł brutto']);
        if ($year < 1980 || $year > (int)date('Y') || $price < 30000 || $price > 300000) continue;
        $type = trim($r['Typ']);
        $key = $type . '|' . (int)(floor($year / 5) * 5);
        $km = (int)preg_replace('/\D/', '', $r['Przebieg km']);
        if ($km <= 0) continue;
        $rows[] = [$r, $price, $key, $km];
        $groups[$key][] = [$price, $km];
    }
    fclose($stream);
    $out = [];
    foreach ($rows as [$r, $price, $key, $km]) {
        $prices = [];
        $kmRange = max(50000, (int)($km * 0.4));
        foreach ($groups[$key] as [$otherPrice, $otherKm]) {
            if (abs($otherKm - $km) <= $kmRange) $prices[] = $otherPrice;
        }
        if (count($prices) < 8) continue;
        sort($prices, SORT_NUMERIC);
        $median = $prices[(int)floor(count($prices) / 2)];
        if ($price >= $median * 0.70) continue;
        $url = trim($r['Link do ogłoszenia']);
        if (!filter_var($url, FILTER_VALIDATE_URL) || strtolower((string)parse_url($url, PHP_URL_SCHEME)) !== 'https') continue;
        $discount = (int)round((1 - $price / $median) * 100);
        $title = clean_text($r['Marka'] . ' ' . $r['Model'] . ' (' . $r['Rok'] . ', ' . $r['Przebieg km'] . ' km)');
        $out[$url] = ['url' => $url, 'title' => mb_substr($title, 0, 180), 'country' => 'PL',
            'source' => 'WybierzKampera', 'price_text' => number_format($price, 0, ',', ' ') . ' zł',
            'signal' => $discount . '% poniżej mediany typu i rocznika'];
    }
    uasort($out, static function ($a, $b) { return (int)$b['signal'] <=> (int)$a['signal']; });
    return array_slice(array_values($out), 0, 30);
}

$database = db();
$insert = $database->prepare('INSERT OR IGNORE INTO discoveries (url,title,country,source,price_text,signal) VALUES (:url,:title,:country,:source,:price_text,:signal)');
$previous = $database->prepare('SELECT price_text FROM discoveries WHERE url = ?');
$drop = $database->prepare('INSERT INTO price_drops (url,old_price,new_price) VALUES (?,?,?)');
$update = $database->prepare('UPDATE discoveries SET last_seen = CURRENT_TIMESTAMP, title = :title, price_text = :price_text, signal = :signal WHERE url = :url');
$log = $database->prepare('INSERT INTO scan_runs (source,found,added,error) VALUES (?,?,?,?)');
foreach ($sources as $source) {
    $found = 0; $added = 0; $error = '';
    try {
        if ($source[1] === 'WybierzKampera') {
            $last = $database->query("SELECT scanned_at FROM scan_runs WHERE source = 'WybierzKampera' AND error = '' ORDER BY id DESC LIMIT 1")->fetchColumn();
            if (is_string($last) && strtotime($last . ' UTC') > time() - 20 * 3600) continue;
            $items = reference_candidates(fetch_page($source[2]));
        } else {
            $items = candidates(fetch_page($source[2]), $source);
        }
        $found = count($items);
        foreach ($items as $item) {
            $previous->execute([$item['url']]);
            $old = $previous->fetchColumn();
            $insert->execute($item);
            $added += $insert->rowCount();
            if (is_string($old) && $old !== '' && $item['price_text'] !== '' && $old !== $item['price_text']) {
                $oldNumber = (float)str_replace([',', '.', ' '], ['', '', ''], preg_replace('/[^\d., ]/', '', $old));
                $newNumber = (float)str_replace([',', '.', ' '], ['', '', ''], preg_replace('/[^\d., ]/', '', $item['price_text']));
                if ($newNumber > 0 && $newNumber < $oldNumber && (strpos($old, '€') !== false) === (strpos($item['price_text'], '€') !== false)) {
                    $drop->execute([$item['url'], $old, $item['price_text']]);
                }
            }
            $update->execute(['url' => $item['url'], 'title' => $item['title'],
                'price_text' => $item['price_text'], 'signal' => $item['signal']]);
        }
        if ($found === 0) $error = 'Brak kandydatów w tej próbie';
    } catch (Throwable $e) { $error = mb_substr($e->getMessage(), 0, 180); }
    $log->execute([$source[1], $found, $added, $error]);
    echo $source[1] . ': ' . $found . ' wyników, ' . $added . ' nowych' . ($error ? ', ' . $error : '') . PHP_EOL;
}

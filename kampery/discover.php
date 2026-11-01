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
        if (preg_match('/\b(gezocht|gesucht|kupię|kupimy|skup|ankauf)\b/ui', $title)) continue;
        $signal = '';
        if (preg_match('/\b(uszkodzony|uszkodzona|powypadkowy|do remontu|zalany|beschädigt|unfall|defekt|reparaturbedürftig|schade|opknapper|defect|accident|ongeval)\b/ui', $title . ' ' . mb_substr($body, 0, 500), $m)) {
            $signal = mb_strtolower($m[0]);
        }
        // Search pages can include unrelated promoted ads. Require a relevant vehicle term.
        if (!preg_match('/kamper|camper|wohnmobil|wohnwagen|przyczep|caravan/ui', $title . ' ' . mb_substr($body, 0, 250))) continue;
        $price = '';
        if (preg_match('/(?<!\d)(?:\d{1,3}(?:[ .\x{00A0}]\d{3})+|\d{4,7})(?:,\d{2})?\s?(?:zł|PLN|€|EUR)/u', $body, $m)) $price = clean_text($m[0]);
        $found[$url] = ['url' => $url, 'title' => mb_substr($title, 0, 180), 'country' => $country,
            'source' => $name, 'price_text' => $price, 'signal' => $signal];
        if (count($found) >= 60) break;
    }
    return array_values($found);
}

$database = db();
$insert = $database->prepare('INSERT OR IGNORE INTO discoveries (url,title,country,source,price_text,signal) VALUES (:url,:title,:country,:source,:price_text,:signal)');
$update = $database->prepare('UPDATE discoveries SET last_seen = CURRENT_TIMESTAMP, title = :title, price_text = :price_text, signal = :signal WHERE url = :url');
$log = $database->prepare('INSERT INTO scan_runs (source,found,added,error) VALUES (?,?,?,?)');
foreach ($sources as $source) {
    $found = 0; $added = 0; $error = '';
    try {
        $items = candidates(fetch_page($source[2]), $source);
        $found = count($items);
        foreach ($items as $item) {
            $insert->execute($item);
            $added += $insert->rowCount();
            $update->execute(['url' => $item['url'], 'title' => $item['title'],
                'price_text' => $item['price_text'], 'signal' => $item['signal']]);
        }
        if ($found === 0) $error = 'Brak pasujących wyników lub zmieniony układ strony';
    } catch (Throwable $e) { $error = mb_substr($e->getMessage(), 0, 180); }
    $log->execute([$source[1], $found, $added, $error]);
    echo $source[1] . ': ' . $found . ' wyników, ' . $added . ' nowych' . ($error ? ', ' . $error : '') . PHP_EOL;
}

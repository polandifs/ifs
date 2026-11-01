<?php
declare(strict_types=1);

// Separate database; the existing camper application supplies only its login session.
function homes_db(): PDO {
    static $db;
    if ($db instanceof PDO) return $db;
    $dir = dirname(__DIR__) . '/data';
    if (!is_dir($dir)) mkdir($dir, 0700, true);
    $db = new PDO('sqlite:' . $dir . '/mieszkania_kwidzyn.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec('PRAGMA busy_timeout=5000');
    $db->exec("CREATE TABLE IF NOT EXISTS homes (url TEXT PRIMARY KEY, source TEXT NOT NULL, title TEXT NOT NULL, price INTEGER, area REAL, rooms INTEGER, street TEXT NOT NULL DEFAULT '', first_seen TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, last_seen TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
    $columns = $db->query('PRAGMA table_info(homes)')->fetchAll(PDO::FETCH_ASSOC);
    if (!in_array('image_url', array_column($columns, 'name'), true)) $db->exec("ALTER TABLE homes ADD COLUMN image_url TEXT NOT NULL DEFAULT ''");
    $db->exec("CREATE TABLE IF NOT EXISTS scans (id INTEGER PRIMARY KEY, source TEXT NOT NULL, checked_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, found INTEGER NOT NULL DEFAULT 0, added INTEGER NOT NULL DEFAULT 0, error TEXT NOT NULL DEFAULT '')");
    $db->exec("CREATE TABLE IF NOT EXISTS subscriptions (endpoint TEXT PRIMARY KEY, public_key TEXT NOT NULL, auth TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, last_sent TEXT NOT NULL DEFAULT '')");
    $db->exec("CREATE TABLE IF NOT EXISTS alerts (id INTEGER PRIMARY KEY, title TEXT NOT NULL, url TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
    $db->exec("CREATE TABLE IF NOT EXISTS price_history (id INTEGER PRIMARY KEY, url TEXT NOT NULL, old_price INTEGER NOT NULL, new_price INTEGER NOT NULL, changed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
    $db->exec("CREATE TABLE IF NOT EXISTS offer_events (id INTEGER PRIMARY KEY, url TEXT NOT NULL, kind TEXT NOT NULL, old_value TEXT NOT NULL DEFAULT '', new_value TEXT NOT NULL DEFAULT '', occurred_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
    $db->exec("CREATE TABLE IF NOT EXISTS image_attempts (url TEXT PRIMARY KEY, attempted_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
    $db->exec("CREATE INDEX IF NOT EXISTS price_history_url ON price_history(url)");
    $db->exec("CREATE INDEX IF NOT EXISTS offer_events_url_time ON offer_events(url,occurred_at)");
    $db->exec("CREATE TABLE IF NOT EXISTS watched (url TEXT PRIMARY KEY, saved_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, purchase_cost INTEGER NOT NULL DEFAULT 0, renovation INTEGER NOT NULL DEFAULT 0, reserve_cost INTEGER NOT NULL DEFAULT 0, resale_target INTEGER NOT NULL DEFAULT 0, note TEXT NOT NULL DEFAULT '')");
    $watchedColumns = $db->query('PRAGMA table_info(watched)')->fetchAll(PDO::FETCH_ASSOC);
    if (!in_array('purchase_candidate', array_column($watchedColumns, 'name'), true)) {
        $db->exec("ALTER TABLE watched ADD COLUMN purchase_candidate INTEGER NOT NULL DEFAULT 0");
    }
    if (!in_array('candidate_at', array_column($watchedColumns, 'name'), true)) {
        $db->exec("ALTER TABLE watched ADD COLUMN candidate_at TEXT NOT NULL DEFAULT ''");
    }
    return $db;
}

function homes_sources(): array {
    return [
        'Adresowo' => 'https://adresowo.pl/mieszkania/kwidzyn-M/',
        'Nieruchomosci-online' => 'https://kwidzyn.nieruchomosci-online.pl/mieszkania,sprzedaz/',
        'Gratka' => 'https://gratka.pl/nieruchomosci/mieszkania/kwidzyn/sprzedaz',
        'Morizon' => 'https://www.morizon.pl/mieszkania/kwidzyn/',
        'Domiporta' => 'https://www.domiporta.pl/mieszkanie/sprzedam/pomorskie/kwidzyn',
        'GetHome' => 'https://gethome.pl/mieszkania/na-sprzedaz/kwidzyn/',
        'OLX' => 'https://www.olx.pl/nieruchomosci/mieszkania/sprzedaz/kwidzyn/',
        'Sprzedajemy' => 'https://sprzedajemy.pl/kwidzyn/nieruchomosci/mieszkania/sprzedaz',
    ];
}

function homes_requested_sources(): array {
    return homes_sources() + [
        'Lento' => 'https://kwidzyn.lento.pl/nieruchomosci/mieszkania/sprzedaz.html',
        'Okolica' => 'https://www.okolica.pl/mieszkanie/sprzedam/kwidzyn/',
        'Otodom' => 'https://www.otodom.pl/pl/wyniki/sprzedaz/mieszkanie/pomorskie/kwidzyn/kwidzyn/kwidzyn',
        'Szybko' => 'https://www.szybko.pl/l/na-sprzedaz/lokal-mieszkalny/Kwidzyn?inne=ogloszenia',
        'Tabelaofert' => 'https://tabelaofert.pl/sprzedaz/mieszkania/kwidzyn',
    ];
}

function homes_valid_price($price): ?int {
    if (!is_int($price) && !is_float($price) && !(is_string($price) && is_numeric($price))) return null;
    $value = (float)$price;
    return is_finite($value) && $value >= 1 && $value <= 100000000 ? (int)round($value) : null;
}

function homes_card_offer(string $url, string $source, string $title, ?int $price, ?float $area, ?int $rooms, string $street, string $image): array {
    return ['url' => $url, 'source' => $source, 'title' => mb_substr(trim($title), 0, 180),
        'price' => homes_valid_price($price), 'area' => $area, 'rooms' => $rooms, 'street' => mb_substr(trim($street), 0, 100),
        'image_url' => filter_var($image, FILTER_VALIDATE_URL) && parse_url($image, PHP_URL_SCHEME) === 'https' ? mb_substr($image, 0, 2048) : ''];
}

function homes_html_offers(string $html, string $source): array {
    $dom = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $xpath = new DOMXPath($dom);
    $out = [];
    if ($source === 'Adresowo') {
        foreach ($xpath->query('//*[@data-offer-card]') as $card) {
            $link = $xpath->query('.//a[starts-with(@href,"/o/mieszkanie-")]', $card)->item(0);
            $image = $xpath->query('.//img[@src]', $card)->item(0);
            $bold = $xpath->query('.//p[contains(@class,"flex-auto")]/span[contains(concat(" ",normalize-space(@class)," ")," font-bold ")]', $card);
            if (!$link || $bold->length < 3) continue;
            $parts = [];
            for ($i = 0; $i < 3; $i++) $parts[] = str_replace(["\xc2\xa0", ' '], '', trim($bold->item($i)->textContent));
            $url = 'https://adresowo.pl' . $link->getAttribute('href');
            $title = $image ? $image->getAttribute('alt') : 'Mieszkanie Kwidzyn';
            if (!preg_match('/\bKwidzyn\b/u', $title)) continue;
            $price = ctype_digit($parts[0]) ? (int)$parts[0] : null;
            $area = is_numeric(str_replace(',', '.', $parts[1])) ? (float)str_replace(',', '.', $parts[1]) : null;
            $rooms = ctype_digit($parts[2]) ? (int)$parts[2] : null;
            $out[$url] = homes_card_offer($url, $source, $title, $price, $area, $rooms, '', $image ? $image->getAttribute('src') : '');
        }
    } elseif ($source === 'OLX') {
        foreach ($xpath->query('//*[@data-testid="l-card"]') as $card) {
            $link = $xpath->query('.//a[@data-testid="card-title-link"]', $card)->item(0);
            $location = $xpath->query('.//*[@data-testid="location-date"]', $card)->item(0);
            if (!$link || !$location || !preg_match('/^Kwidzyn\s*-/u', trim($location->textContent))) continue;
            $url = $link->getAttribute('href');
            if (strpos($url, '/d/oferta/') === 0) $url = 'https://www.olx.pl' . $url;
            $url = strtok($url, '?') ?: $url;
            if (parse_url($url, PHP_URL_HOST) !== 'www.olx.pl' || strpos($url, '/d/oferta/') === false) continue;
            $title = trim($link->textContent);
            $priceNode = $xpath->query('.//*[@data-testid="ad-price"]', $card)->item(0);
            $priceDigits = $priceNode ? preg_replace('/\D+/', '', preg_replace('/\s*do negocjacji.*/iu', '', $priceNode->textContent)) : '';
            $areaNode = $xpath->query('.//*[@data-testid="blueprint-card-param-icon"]/..', $card)->item(0);
            $area = $areaNode && preg_match('/(\d+(?:[,.]\d+)?)\s*m[²2]/u', $areaNode->textContent, $m)
                ? (float)str_replace(',', '.', $m[1]) : null;
            $rooms = preg_match('/(\d+)\s*(?:-|\s*)\s*(?:pok|poko)/iu', $title, $m) ? (int)$m[1] : null;
            $image = $xpath->query('.//img[@src]', $card)->item(0);
            $out[$url] = homes_card_offer($url, $source, $title, ctype_digit($priceDigits) ? (int)$priceDigits : null,
                $area, $rooms, '', $image ? $image->getAttribute('src') : '');
        }
    } elseif ($source === 'GetHome') {
        foreach ($xpath->query('//*[@data-testid="offer-box"]') as $card) {
            $link = $xpath->query('.//a[@data-testid="offer-box-link"]', $card)->item(0);
            if (!$link) continue;
            $titleNode = $xpath->query('.//*[@data-testid="header-offerbox"]', $link)->item(0);
            $address = $xpath->query('.//address', $link)->item(0);
            $roomsNode = $xpath->query('.//*[@data-testid="number-of-rooms-offerbox"]', $link)->item(0);
            $priceNode = $xpath->query('.//*[contains(concat(" ",normalize-space(@class)," ")," o1bbpdyd ")]', $link)->item(0);
            $areaNode = $xpath->query('.//*[contains(concat(" ",normalize-space(@class)," ")," o16iaxib ")]/div[1]/span[4]', $link)->item(0);
            $image = $xpath->query('.//img[@src]', $link)->item(0);
            if (!$titleNode || !$address || stripos($address->textContent, 'Kwidzyn') === false) continue;
            $url = 'https://gethome.pl' . $link->getAttribute('href');
            $price = $priceNode ? preg_replace('/\D+/', '', $priceNode->textContent) : '';
            $area = '';
            if ($areaNode && preg_match('/(\d+(?:[,.]\d+)?)/u', $areaNode->textContent, $areaMatch)) $area = str_replace(',', '.', $areaMatch[1]);
            $street = trim(str_ireplace('Kwidzyn', '', $address->textContent), " ,\t\n\r\0\x0B");
            $out[$url] = homes_card_offer($url, $source, trim($titleNode->textContent), ctype_digit($price) ? (int)$price : null,
                is_numeric($area) ? (float)$area : null, $roomsNode ? (int)$roomsNode->textContent : null, $street, $image ? $image->getAttribute('src') : '');
        }
    } elseif ($source === 'Lento') {
        foreach ($xpath->query('//div[contains(concat(" ",normalize-space(@class)," ")," tablelist-tr ")]') as $card) {
            $link = $xpath->query('.//a[contains(concat(" ",normalize-space(@class)," ")," title-list-item ")]', $card)->item(0);
            if (!$link || parse_url($link->getAttribute('href'), PHP_URL_HOST) !== 'kwidzyn.lento.pl') continue;
            $url = $link->getAttribute('href');
            $priceNode = $xpath->query('.//span[contains(concat(" ",normalize-space(@class)," ")," price-list-item ")]', $card)->item(0);
            $price = $priceNode ? preg_replace('/\D+/', '', $priceNode->textContent) : '';
            $area = null; $rooms = null;
            foreach ($xpath->query('.//span[contains(concat(" ",normalize-space(@class)," ")," list-atrr-item-tab ")]', $card) as $attribute) {
                if (preg_match('/(\d+(?:[,.]\d+)?)\s*m2/u', $attribute->textContent, $m)) $area = (float)str_replace(',', '.', $m[1]);
                if (preg_match('/(\d+)\s*pokoj/u', $attribute->textContent, $m)) $rooms = (int)$m[1];
            }
            $image = $xpath->query('.//img[@src]', $card)->item(0);
            $out[$url] = homes_card_offer($url, $source, trim($link->textContent) . ' · Kwidzyn', ctype_digit($price) ? (int)$price : null,
                $area, $rooms, '', $image ? $image->getAttribute('src') : '');
        }
    } elseif ($source === 'Szybko') {
        foreach ($xpath->query('//*[@data-assetid and contains(concat(" ",normalize-space(@class)," ")," listing-item ")]') as $card) {
            $link = $xpath->query('.//a[contains(concat(" ",normalize-space(@class)," ")," listing-title-heading ")]', $card)->item(0);
            $priceNode = $xpath->query('.//*[@itemprop="price"]', $card)->item(0);
            if (!$link || !$priceNode || strpos($link->getAttribute('href'), '/Kwidzyn/oferta-') === false) continue;
            $url = 'https://www.szybko.pl' . $link->getAttribute('href');
            $areaNode = $xpath->query('.//li[contains(concat(" ",normalize-space(@class)," ")," area ")]', $card)->item(0);
            $roomsNode = $xpath->query('.//li[contains(concat(" ",normalize-space(@class)," ")," rooms ")]', $card)->item(0);
            $image = $xpath->query('.//img[@src]', $card)->item(0);
            $area = $areaNode && preg_match('/(\d+(?:[,.]\d+)?)/u', $areaNode->textContent, $m) ? (float)str_replace(',', '.', $m[1]) : null;
            $rooms = $roomsNode && preg_match('/(\d+)/u', $roomsNode->textContent, $m) ? (int)$m[1] : null;
            $out[$url] = homes_card_offer($url, $source, trim($link->textContent) . ' · Kwidzyn', (int)$priceNode->getAttribute('content'),
                $area, $rooms, '', $image ? $image->getAttribute('src') : '');
        }
    }
    if (!$out) throw new RuntimeException('Nie znaleziono poprawnych ofert; możliwa zmiana strony');
    return array_values($out);
}

function homes_fetch(string $url): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 2,
        CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_ENCODING => '',
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; IFSKwidzynMonitor/1.0)']);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!is_string($body) || $status !== 200 || strlen($body) < 1000) throw new RuntimeException('HTTP ' . $status . ' lub pusta odpowiedź');
    return $body;
}

function homes_detail_image(string $url): string {
    if (parse_url($url, PHP_URL_HOST) !== 'www.olx.pl' || strpos($url, '/d/oferta/') === false) return '';
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 2,
        CURLOPT_TIMEOUT => 7, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_ENCODING => '',
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; IFSKwidzynMonitor/1.0)']);
    $html = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!is_string($html) || $status !== 200) return '';
    if (!preg_match('~<meta[^>]*property=["\']og:image["\'][^>]*>~i', $html, $tag)) return '';
    if (!preg_match('~content=["\']([^"\']+)["\']~i', $tag[0], $match)) return '';
    $image = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $host = strtolower((string)parse_url($image, PHP_URL_HOST));
    return filter_var($image, FILTER_VALIDATE_URL) && parse_url($image, PHP_URL_SCHEME) === 'https'
        && ($host === 'olxcdn.com' || substr($host, -11) === '.olxcdn.com') ? mb_substr($image, 0, 2048) : '';
}

function homes_offers(string $html, string $source): array {
    if (in_array($source, ['Adresowo', 'GetHome', 'Lento', 'Szybko'], true)) return homes_html_offers($html, $source);
    $matches = [];
    preg_match_all('~<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>~si', $html, $matches);
    $out = [];
    foreach ($matches[1] ?? [] as $json) {
        $data = json_decode($json, true);
        if (!is_array($data)) continue;
        $offers = $data['offers']['offers'] ?? $data['mainEntity']['offers'][0]['offers'] ?? [];
        if ($source === 'OLX' && ($data['@type'] ?? '') === 'Product') {
            foreach ($data['offers']['offers'] ?? [] as $offer) {
                if (!is_array($offer) || mb_strtolower((string)($offer['areaServed']['name'] ?? '')) !== 'kwidzyn') continue;
                $url = (string)($offer['url'] ?? '');
                if (parse_url($url, PHP_URL_HOST) !== 'www.olx.pl' || strpos($url, '/d/oferta/') === false) continue;
                $title = trim((string)($offer['name'] ?? 'Mieszkanie Kwidzyn'));
                $price = filter_var($offer['price'] ?? null, FILTER_VALIDATE_FLOAT);
                $area = null; $rooms = null;
                if (preg_match('/(\d+(?:[,.]\d+)?)\s*m(?:2|²|\b)/iu', $title, $m)) $area = (float)str_replace(',', '.', $m[1]);
                if (preg_match('/(\d+)\s*(?:-|\s*)\s*(?:pok|poko)/iu', $title, $m)) $rooms = (int)$m[1];
                $image = $offer['image'] ?? '';
                if (is_array($image)) $image = $image[0] ?? '';
                $out[$url] = homes_card_offer($url, $source, $title, $price === false ? null : (int)round($price), $area, $rooms, '', (string)$image);
            }
            continue;
        }
        if ($source === 'Domiporta') {
            foreach ($data['@graph'] ?? [] as $node) {
                if (($node['@type'] ?? '') === 'ItemList') {
                    $offers = [];
                    foreach ($node['itemListElement'] ?? [] as $entry) {
                        $item = $entry['item'] ?? [];
                        if (!is_array($item)) continue;
                        $offer = $item['offers'] ?? [];
                        if (!is_array($offer)) continue;
                        $offer['url'] = $item['url'] ?? '';
                        $offer['name'] = $item['name'] ?? '';
                        $offer['image'] = $item['image'] ?? '';
                        $offers[] = $offer;
                    }
                    break;
                }
            }
        }
        if ($source === 'Sprzedajemy' && ($data['@type'] ?? '') === 'ItemList') {
            $offers = $data['itemListElement'] ?? [];
            foreach ($offers as $offer) {
                if (!is_array($offer)) continue;
                $url = (string)($offer['url'] ?? '');
                if (parse_url($url, PHP_URL_HOST) !== 'sprzedajemy.pl' || strpos($url, 'kwidzyn') === false) continue;
                $title = (string)($offer['name'] ?? 'Mieszkanie Kwidzyn');
                $price = filter_var($offer['price'] ?? null, FILTER_VALIDATE_INT);
                $area = null; $rooms = null;
                if (preg_match('/(\d+(?:[,.]\d+)?)\s*m(?:2|²)/iu', $title, $m)) $area = (float)str_replace(',', '.', $m[1]);
                if (preg_match('/(\d+)\s*(?:-|\s*)\s*(?:pok|poko)/iu', $title, $m)) $rooms = (int)$m[1];
                $out[$url] = homes_card_offer($url, $source, $title, $price === false ? null : (int)$price, $area, $rooms, '', (string)($offer['image'] ?? ''));
            }
            continue;
        }
        if (!is_array($offers)) continue;
        foreach ($offers as $offer) {
            if (!is_array($offer)) continue;
            $url = (string)($offer['url'] ?? '');
            $host = strtolower((string)parse_url($url, PHP_URL_HOST));
            $allowed = ['Nieruchomosci-online' => 'kwidzyn.nieruchomosci-online.pl', 'Gratka' => 'gratka.pl', 'Morizon' => 'www.morizon.pl', 'Domiporta' => 'www.domiporta.pl'];
            if (!isset($allowed[$source]) || $host !== $allowed[$source]) continue;
            if ($source === 'Nieruchomosci-online' && !preg_match('~/mieszkanie[^/]*?/\d+\.html$~', $url)) continue;
            if ($source === 'Gratka' && strpos($url, '/ob/') === false) continue;
            if ($source === 'Morizon' && strpos($url, '/oferta/sprzedaz-mieszkanie-') === false) continue;
            if ($source === 'Domiporta' && strpos($url, '/nieruchomosci/sprzedam-') === false) continue;
            $home = $offer['itemOffered'] ?? [];
            if (!is_array($home)) continue;
            $address = $home['address'] ?? [];
            if (!is_array($address) || mb_strtolower(trim((string)($address['addressLocality'] ?? ''))) !== 'kwidzyn') continue;
            $title = trim((string)($offer['name'] ?? $home['name'] ?? 'Mieszkanie w Kwidzynie'));
            $price = filter_var($offer['price'] ?? null, FILTER_VALIDATE_FLOAT);
            $area = filter_var($home['floorSize']['value'] ?? null, FILTER_VALIDATE_FLOAT);
            $rooms = filter_var($home['numberOfRooms'] ?? null, FILTER_VALIDATE_INT);
            $image = $offer['image'] ?? $home['image'] ?? '';
            if (is_array($image)) $image = $image['url'] ?? $image[0] ?? '';
            $image = is_string($image) && filter_var($image, FILTER_VALIDATE_URL) && parse_url($image, PHP_URL_SCHEME) === 'https' ? $image : '';
            $out[$url] = ['url' => $url, 'source' => $source, 'title' => mb_substr($title, 0, 180),
                'price' => homes_valid_price($price), 'area' => $area === false ? null : $area,
                'rooms' => $rooms === false ? null : $rooms, 'street' => mb_substr(trim((string)($address['streetAddress'] ?? '')), 0, 100), 'image_url' => mb_substr($image, 0, 2048)];
        }
    }
    if ($source === 'OLX') {
        // The JSON-LD list often omits photos that are present in the listing cards.
        try {
            foreach (homes_html_offers($html, 'OLX') as $card) {
                $url = $card['url'];
                if (!isset($out[$url])) {
                    $out[$url] = $card;
                } else {
                    if ($out[$url]['price'] === null && $card['price'] !== null) $out[$url]['price'] = $card['price'];
                    if ($card['image_url'] !== '') $out[$url]['image_url'] = $card['image_url'];
                }
            }
        } catch (RuntimeException $e) {
            // Keep the structured results if OLX changes its card markup.
        }
    }
    if (!$out) throw new RuntimeException('Nie znaleziono poprawnych ofert; możliwa zmiana strony');
    return array_values($out);
}

function homes_e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function homes_b64url(string $raw): string { return rtrim(strtr(base64_encode($raw), '+/', '-_'), '='); }
function homes_b64decode(string $value): string { return base64_decode(strtr($value, '-_', '+/'), true) ?: ''; }

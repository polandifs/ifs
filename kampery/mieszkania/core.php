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
    $db->exec("CREATE TABLE IF NOT EXISTS scans (id INTEGER PRIMARY KEY, source TEXT NOT NULL, checked_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, found INTEGER NOT NULL DEFAULT 0, added INTEGER NOT NULL DEFAULT 0, error TEXT NOT NULL DEFAULT '')");
    $db->exec("CREATE TABLE IF NOT EXISTS subscriptions (endpoint TEXT PRIMARY KEY, public_key TEXT NOT NULL, auth TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, last_sent TEXT NOT NULL DEFAULT '')");
    $db->exec("CREATE TABLE IF NOT EXISTS alerts (id INTEGER PRIMARY KEY, title TEXT NOT NULL, url TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
    return $db;
}

function homes_sources(): array {
    return [
        'Nieruchomosci-online' => 'https://kwidzyn.nieruchomosci-online.pl/mieszkania,sprzedaz/',
        'Gratka' => 'https://gratka.pl/nieruchomosci/mieszkania/kwidzyn/sprzedaz',
        'Morizon' => 'https://www.morizon.pl/mieszkania/kwidzyn/',
    ];
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

function homes_offers(string $html, string $source): array {
    $matches = [];
    preg_match_all('~<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>~si', $html, $matches);
    $out = [];
    foreach ($matches[1] ?? [] as $json) {
        $data = json_decode($json, true);
        if (!is_array($data)) continue;
        $offers = $data['offers']['offers'] ?? $data['mainEntity']['offers'][0]['offers'] ?? [];
        if (!is_array($offers)) continue;
        foreach ($offers as $offer) {
            if (!is_array($offer)) continue;
            $url = (string)($offer['url'] ?? '');
            $host = strtolower((string)parse_url($url, PHP_URL_HOST));
            $allowed = ['Nieruchomosci-online' => 'kwidzyn.nieruchomosci-online.pl', 'Gratka' => 'gratka.pl', 'Morizon' => 'www.morizon.pl'];
            if (!isset($allowed[$source]) || $host !== $allowed[$source]) continue;
            if ($source === 'Nieruchomosci-online' && !preg_match('~/mieszkanie[^/]*?/\d+\.html$~', $url)) continue;
            if ($source === 'Gratka' && strpos($url, '/ob/') === false) continue;
            if ($source === 'Morizon' && strpos($url, '/oferta/sprzedaz-mieszkanie-') === false) continue;
            $home = $offer['itemOffered'] ?? [];
            if (!is_array($home)) continue;
            $address = $home['address'] ?? [];
            if (!is_array($address) || mb_strtolower(trim((string)($address['addressLocality'] ?? ''))) !== 'kwidzyn') continue;
            $title = trim((string)($offer['name'] ?? $home['name'] ?? 'Mieszkanie w Kwidzynie'));
            $price = filter_var($offer['price'] ?? null, FILTER_VALIDATE_FLOAT);
            $area = filter_var($home['floorSize']['value'] ?? null, FILTER_VALIDATE_FLOAT);
            $rooms = filter_var($home['numberOfRooms'] ?? null, FILTER_VALIDATE_INT);
            $out[$url] = ['url' => $url, 'source' => $source, 'title' => mb_substr($title, 0, 180),
                'price' => $price === false ? null : (int)round($price), 'area' => $area === false ? null : $area,
                'rooms' => $rooms === false ? null : $rooms, 'street' => mb_substr(trim((string)($address['streetAddress'] ?? '')), 0, 100)];
        }
    }
    if (!$out) throw new RuntimeException('Nie znaleziono poprawnych ofert; możliwa zmiana strony');
    return array_values($out);
}

function homes_e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function homes_b64url(string $raw): string { return rtrim(strtr(base64_encode($raw), '+/', '-_'), '='); }
function homes_b64decode(string $value): string { return base64_decode(strtr($value, '-_', '+/'), true) ?: ''; }

<?php
declare(strict_types=1);

function config(): array
{
    $file = __DIR__ . '/config.php';
    if (!is_file($file)) {
        http_response_code(503);
        exit('Brak konfiguracji. Zobacz README.md.');
    }
    $config = require $file;
    if (!is_array($config) || empty($config['app_key']) || empty($config['password_hash'])
        || str_starts_with((string)$config['app_key'], 'REPLACE_')
        || str_starts_with((string)$config['password_hash'], 'REPLACE_')) {
        http_response_code(503);
        exit('Uzupełnij config.php zgodnie z README.md.');
    }
    return $config;
}

function db(): PDO
{
    static $db;
    if ($db instanceof PDO) return $db;
    $dir = __DIR__ . '/data';
    if (!is_dir($dir)) mkdir($dir, 0700, true);
    $db = new PDO('sqlite:' . $dir . '/kampery.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec('CREATE TABLE IF NOT EXISTS listings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        url TEXT NOT NULL UNIQUE,
        title TEXT NOT NULL,
        country TEXT NOT NULL,
        kind TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT "nowa",
        currency TEXT NOT NULL,
        asking_price REAL NOT NULL,
        eur_rate REAL,
        negotiated_price REAL,
        repair_cost REAL NOT NULL DEFAULT 0,
        transport_cost REAL NOT NULL DEFAULT 0,
        risk_reserve REAL NOT NULL DEFAULT 0,
        resale_price REAL,
        notes TEXT NOT NULL DEFAULT "",
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');
    return $db;
}

function h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(array $config): string
{
    return hash_hmac('sha256', session_id(), (string)$config['app_key']);
}

function require_csrf(array $config): void
{
    if (!hash_equals(csrf_token($config), (string)($_POST['csrf'] ?? ''))) {
        http_response_code(403);
        exit('Błędny token formularza.');
    }
}

function amount(string $name, bool $required = false): ?float
{
    $raw = trim(str_replace(',', '.', (string)($_POST[$name] ?? '')));
    if ($raw === '') {
        if ($required) throw new InvalidArgumentException('Uzupełnij pole: ' . $name);
        return null;
    }
    if (!is_numeric($raw) || !is_finite((float)$raw) || (float)$raw < 0) {
        throw new InvalidArgumentException('Nieprawidłowa kwota: ' . $name);
    }
    return round((float)$raw, 2);
}

function normalize_url(string $url): string
{
    $url = trim($url);
    if (strlen($url) > 2048 || !filter_var($url, FILTER_VALIDATE_URL)) {
        throw new InvalidArgumentException('Podaj poprawny adres ogłoszenia.');
    }
    $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
    if (!in_array($scheme, ['http', 'https'], true)) {
        throw new InvalidArgumentException('Adres musi zaczynać się od http lub https.');
    }
    return $url;
}

function listing_from_post(): array
{
    $country = (string)($_POST['country'] ?? 'PL');
    $kind = (string)($_POST['kind'] ?? 'kamper');
    $status = (string)($_POST['status'] ?? 'nowa');
    $currency = (string)($_POST['currency'] ?? 'PLN');
    if (!in_array($country, ['PL','DE','NL','BE'], true) || !in_array($kind, ['kamper','przyczepa'], true)
        || !in_array($status, ['nowa','sprawdzana','odrzucona','kupiona','sprzedana'], true)
        || !in_array($currency, ['PLN','EUR'], true)) {
        throw new InvalidArgumentException('Nieprawidłowy wybór w formularzu.');
    }
    $title = trim((string)($_POST['title'] ?? ''));
    if ($title === '' || mb_strlen($title) > 180) throw new InvalidArgumentException('Tytuł musi mieć 1–180 znaków.');
    $rate = amount('eur_rate');
    if ($currency === 'EUR' && ($rate === null || $rate <= 0)) throw new InvalidArgumentException('Podaj ręcznie kurs EUR/PLN większy od zera.');
    $asking = amount('asking_price', true);
    if ($asking <= 0) throw new InvalidArgumentException('Cena musi być większa od zera.');
    $negotiated = amount('negotiated_price');
    if ($negotiated !== null && $negotiated > $asking) throw new InvalidArgumentException('Cena po negocjacji nie może przewyższać ofertowej.');
    $notes = trim((string)($_POST['notes'] ?? ''));
    if (mb_strlen($notes) > 3000) throw new InvalidArgumentException('Notatka jest za długa.');
    return [
        'url' => normalize_url((string)($_POST['url'] ?? '')),
        'title' => $title, 'country' => $country, 'kind' => $kind, 'status' => $status,
        'currency' => $currency, 'asking_price' => $asking, 'eur_rate' => $currency === 'EUR' ? $rate : null,
        'negotiated_price' => $negotiated, 'repair_cost' => amount('repair_cost') ?? 0,
        'transport_cost' => amount('transport_cost') ?? 0, 'risk_reserve' => amount('risk_reserve') ?? 0,
        'resale_price' => amount('resale_price'), 'notes' => $notes,
    ];
}

function scenarios(array $row): array
{
    if ($row['resale_price'] === null) return [null, null];
    $rate = $row['currency'] === 'EUR' ? (float)$row['eur_rate'] : 1.0;
    $costs = (float)$row['repair_cost'] + (float)$row['transport_cost'] + (float)$row['risk_reserve'];
    $asking = (float)$row['resale_price'] - (float)$row['asking_price'] * $rate - $costs;
    $negotiated = $row['negotiated_price'] === null ? null : (float)$row['resale_price'] - (float)$row['negotiated_price'] * $rate - $costs;
    return [$asking, $negotiated];
}

function money(?float $value): string
{
    return $value === null ? '—' : number_format($value, 0, ',', ' ') . ' zł';
}

function search_public(string $country, string $source, string $key): array
{
    $queries = [
        'portals' => [
            'PL' => 'site:otomoto.pl kamper uszkodzony OR przyczepa do remontu',
            'DE' => 'site:kleinanzeigen.de wohnmobil beschädigt OR wohnwagen renovierungsbedürftig',
            'NL' => 'site:marktplaats.nl schade camper OR caravan opknapper',
            'BE' => 'site:2dehands.be camper schade OR caravan opknapper',
        ],
        'facebook' => [
            'PL' => 'site:facebook.com (kamper uszkodzony OR przyczepa do remontu) Polska',
            'DE' => 'site:facebook.com (wohnmobil beschädigt OR wohnwagen renovierungsbedürftig) Deutschland',
            'NL' => 'site:facebook.com (schade camper OR caravan opknapper) Nederland',
            'BE' => 'site:facebook.com (camper schade OR caravan opknapper) België',
        ],
    ];
    if (!isset($queries[$source][$country])) throw new InvalidArgumentException('Nieprawidłowe źródło lub kraj.');
    $url = 'https://serpapi.com/search.json?' . http_build_query([
        'engine' => 'google', 'q' => $queries[$source][$country], 'num' => 10, 'api_key' => $key,
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_FOLLOWLOCATION => false]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $code !== 200) throw new RuntimeException('Wyszukiwanie nie powiodło się. Sprawdź klucz i limit SerpAPI.');
    $data = json_decode($body, true);
    if (!is_array($data)) throw new RuntimeException('Nieprawidłowa odpowiedź wyszukiwarki.');
    return array_values(array_filter($data['organic_results'] ?? [], static fn($x) => is_array($x) && !empty($x['link']) && !empty($x['title'])));
}

<?php
declare(strict_types=1);
require_once __DIR__ . '/core.php';

function homes_push_config(): array {
    $path = __DIR__ . '/push-config.php';
    return is_file($path) ? (array)require $path : [];
}

function homes_der_to_raw(string $der): string {
    if (strlen($der) < 8 || ord($der[0]) !== 0x30) throw new RuntimeException('Nieprawidłowy podpis VAPID');
    $pos = 2;
    if (ord($der[$pos++]) !== 0x02) throw new RuntimeException('Nieprawidłowy podpis VAPID');
    $rLen = ord($der[$pos++]); $r = substr($der, $pos, $rLen); $pos += $rLen;
    if (ord($der[$pos++]) !== 0x02) throw new RuntimeException('Nieprawidłowy podpis VAPID');
    $sLen = ord($der[$pos++]); $s = substr($der, $pos, $sLen);
    $r = str_pad(ltrim($r, "\0"), 32, "\0", STR_PAD_LEFT);
    $s = str_pad(ltrim($s, "\0"), 32, "\0", STR_PAD_LEFT);
    if (strlen($r) !== 32 || strlen($s) !== 32) throw new RuntimeException('Nieprawidłowy podpis VAPID');
    return $r . $s;
}

function homes_vapid_jwt(string $endpoint, array $cfg): string {
    $parts = parse_url($endpoint);
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) throw new RuntimeException('Nieprawidłowy endpoint push');
    $aud = 'https://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    $head = homes_b64url(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $body = homes_b64url(json_encode(['aud' => $aud, 'exp' => time() + 3600, 'sub' => 'https://ifs-poland.com/ifskampery/mieszkania/']));
    $data = $head . '.' . $body;
    if (!openssl_sign($data, $signature, $cfg['private_pem'], OPENSSL_ALGO_SHA256)) throw new RuntimeException('Nie udało się podpisać VAPID');
    return $data . '.' . homes_b64url(homes_der_to_raw($signature));
}

function homes_push_all(PDO $db): void {
    $cfg = homes_push_config();
    if (empty($cfg['private_pem']) || empty($cfg['public_key'])) throw new RuntimeException('Brak konfiguracji push');
    foreach ($db->query('SELECT endpoint FROM subscriptions')->fetchAll(PDO::FETCH_COLUMN) as $endpoint) {
        try {
            $jwt = homes_vapid_jwt($endpoint, $cfg);
            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => '', CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_HTTPHEADER => ['Authorization: vapid t=' . $jwt . ', k=' . $cfg['public_key'], 'TTL: 3600', 'Content-Length: 0']]);
            curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($status === 404 || $status === 410) {
                $db->prepare('DELETE FROM subscriptions WHERE endpoint=?')->execute([$endpoint]);
            } elseif ($status >= 200 && $status < 300) {
                $db->prepare('UPDATE subscriptions SET last_sent=CURRENT_TIMESTAMP WHERE endpoint=?')->execute([$endpoint]);
            } else {
                error_log('Kwidzyn push HTTP ' . $status);
            }
        } catch (Throwable $e) { error_log('Kwidzyn push: ' . $e->getMessage()); }
    }
}

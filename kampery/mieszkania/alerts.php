<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app.php';
require __DIR__ . '/core.php';
$config = config();
session_name('kampery_session');
session_set_cookie_params(['httponly' => true, 'secure' => true, 'samesite' => 'Lax']);
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (empty($_SESSION['auth'])) { http_response_code(401); echo '{}'; exit; }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(csrf_token($config), (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) { http_response_code(403); echo '{}'; exit; }
    $json = json_decode(file_get_contents('php://input'), true);
    if (!is_array($json) || !isset($json['endpoint'], $json['keys']['p256dh'], $json['keys']['auth'])) { http_response_code(400); echo '{}'; exit; }
    $endpoint = (string)$json['endpoint'];
    $parts = parse_url($endpoint);
    if (strlen($endpoint) > 2000 || !is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
        || !preg_match('/(?:^|\.)(?:googleapis\.com|mozilla\.com|apple\.com|windows\.com)$/', (string)$parts['host'])) {
        http_response_code(400); echo '{}'; exit;
    }
    $stmt = homes_db()->prepare('INSERT OR REPLACE INTO subscriptions (endpoint,public_key,auth) VALUES (?,?,?)');
    $stmt->execute([$endpoint, (string)$json['keys']['p256dh'], (string)$json['keys']['auth']]);
    echo '{"ok":true}'; exit;
}
$since = (int)($_GET['since'] ?? 0);
$stmt = homes_db()->prepare('SELECT id,title,url FROM alerts WHERE id > ? ORDER BY id DESC LIMIT 10');
$stmt->execute([$since]);
echo json_encode(['alerts' => $stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

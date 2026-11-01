<?php
declare(strict_types=1);

$base = __DIR__;
$configFile = $base . '/config.php';
$tokenFile = '/home/ifs/.ifskampery-setup-token';
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

if (is_file($configFile)) {
    http_response_code(410);
    exit('Panel jest już skonfigurowany.');
}
if (!is_file($tokenFile) || time() - filemtime($tokenFile) > 3600) {
    http_response_code(403);
    exit('Konfiguracja nie jest dostępna.');
}
$token = trim((string)file_get_contents($tokenFile));
if (strlen($token) !== 48) {
    http_response_code(403);
    exit('Konfiguracja nie jest dostępna.');
}

session_name('ifskampery_setup');
session_set_cookie_params(['httponly' => true, 'secure' => true, 'samesite' => 'Strict']);
session_start();
if (isset($_GET['t'])) {
    if (!hash_equals($token, (string)$_GET['t'])) {
        http_response_code(403);
        exit('Nieprawidłowy link.');
    }
    session_regenerate_id(true);
    $_SESSION['authorized'] = true;
    header('Location: setup.php');
    exit;
}
if (empty($_SESSION['authorized'])) {
    http_response_code(403);
    exit('Konfiguracja wymaga jednorazowego linku.');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = hash_hmac('sha256', session_id(), $token);
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        $error = 'Sesja wygasła. Otwórz ponownie link.';
    } else {
        $password = (string)($_POST['password'] ?? '');
        $confirmation = (string)($_POST['confirmation'] ?? '');
        if (strlen($password) < 12) {
            $error = 'Hasło musi mieć co najmniej 12 znaków.';
        } elseif (!hash_equals($password, $confirmation)) {
            $error = 'Hasła nie są jednakowe.';
        } else {
            $configuration = [
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'app_key' => bin2hex(random_bytes(32)),
                'serpapi_key' => '',
            ];
            unset($password, $confirmation);
            $tmp = tempnam('/home/ifs', 'ifskampery-');
            if ($tmp === false || !chmod($tmp, 0600)) {
                $error = 'Nie można utworzyć pliku konfiguracji.';
            } else {
                $content = "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export($configuration, true) . ";\n";
                if (file_put_contents($tmp, $content, LOCK_EX) === false || !rename($tmp, $configFile)) {
                    $error = 'Nie można zapisać konfiguracji.';
                } else {
                    unlink($tokenFile);
                    $_SESSION = [];
                    session_destroy();
                    header('Location: index.php');
                    exit;
                }
            }
        }
    }
}
$csrf = hash_hmac('sha256', session_id(), $token);
?>
<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Ustaw hasło · ifskampery</title><link rel="stylesheet" href="style.css"></head>
<body><main class="shell"><header class="top"><div><p class="eyebrow">PIERWSZE URUCHOMIENIE</p><h1>Ustaw hasło panelu</h1><p>Hasło zapisze się na serwerze wyłącznie jako hash.</p></div></header><section class="card login">
<?php if ($error): ?><div class="alert error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><label>Nowe hasło, co najmniej 12 znaków<input type="password" name="password" minlength="12" required autocomplete="new-password"></label><label>Powtórz hasło<input type="password" name="confirmation" minlength="12" required autocomplete="new-password"></label><button type="submit">Ustaw hasło</button></form>
</section></main></body></html>

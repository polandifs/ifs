<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$target = '/home/ifs/public_html/ifs-poland.com/ifskampery/config.php';
if (is_file($target)) {
    fwrite(STDERR, "Konfiguracja już istnieje. Nie nadpisuję jej.\n");
    exit(1);
}

$output = [];
$status = 1;
exec('stty -echo 2>/dev/null', $output, $status);
if ($status !== 0) {
    fwrite(STDERR, "Nie można ukryć wpisywanego hasła w terminalu.\n");
    exit(1);
}

try {
    fwrite(STDOUT, "Wpisz nowe hasło panelu (min. 12 znaków) i naciśnij Enter: ");
    $password = fgets(STDIN);
} finally {
    exec('stty echo 2>/dev/null');
    fwrite(STDOUT, "\n");
}

if ($password === false) {
    fwrite(STDERR, "Nie odczytano hasła.\n");
    exit(1);
}
$password = rtrim($password, "\r\n");
if (strlen($password) < 12) {
    fwrite(STDERR, "Hasło jest za krótkie. Nie zapisano konfiguracji.\n");
    exit(1);
}

$configuration = [
    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    'app_key' => bin2hex(random_bytes(32)),
    'serpapi_key' => '',
];
unset($password);
$content = "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export($configuration, true) . ";\n";
$written = file_put_contents($target, $content, LOCK_EX);
if ($written === false || !chmod($target, 0600)) {
    fwrite(STDERR, "Nie udało się bezpiecznie zapisać konfiguracji.\n");
    exit(1);
}
fwrite(STDOUT, "Hasło ustawione. Konfiguracja gotowa.\n");

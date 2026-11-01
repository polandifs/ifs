<?php
declare(strict_types=1);
require __DIR__ . '/app.php';
require __DIR__ . '/facebook_group_core.php';
$config = config();
session_name('kampery_session');
session_set_cookie_params(['httponly' => true, 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'samesite' => 'Lax']);
session_start();
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
if (empty($_SESSION['auth'])) { header('Location: index.php'); exit; }
$db = db();
$error = '';
$message = (string)($_SESSION['facebook_message'] ?? '');
unset($_SESSION['facebook_message']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        require_csrf($config);
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'group_add') {
            $id = fb_group_id((string)($_POST['group_url'] ?? ''));
            $name = fb_clean((string)($_POST['group_name'] ?? ''));
            if (mb_strlen($name) < 3 || mb_strlen($name) > 120) throw new InvalidArgumentException('Nazwa grupy musi mieć 3–120 znaków.');
            $db->prepare('INSERT INTO facebook_groups(id,name,url) VALUES (?,?,?) ON CONFLICT(id) DO UPDATE SET name=excluded.name, enabled=1')
                ->execute([$id, $name, 'https://www.facebook.com/groups/' . $id . '/']);
            $_SESSION['facebook_message'] = 'Grupa została dodana. Automatyczny skan sprawdzi, czy serwer widzi jej publiczne posty.';
        } elseif ($action === 'group_toggle' || $action === 'group_check') {
            $id = (string)($_POST['group_id'] ?? '');
            if (!preg_match('/^[0-9]{5,25}$/', $id)) throw new InvalidArgumentException('Nieprawidłowa grupa.');
            $stmt = $db->prepare('SELECT * FROM facebook_groups WHERE id=?');
            $stmt->execute([$id]);
            $group = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$group) throw new InvalidArgumentException('Nie znaleziono grupy.');
            if ($action === 'group_toggle') {
                $db->prepare('UPDATE facebook_groups SET enabled=? WHERE id=?')->execute([(int)!$group['enabled'], $id]);
                $_SESSION['facebook_message'] = $group['enabled'] ? 'Grupa wyłączona ze skanowania.' : 'Grupa ponownie włączona do skanowania.';
            } else {
                [$status] = fb_scan_group($db, $group);
                $_SESSION['facebook_message'] = $status;
            }
        } elseif ($action === 'post_add') {
            $id = (string)($_POST['group_id'] ?? '');
            $stmt = $db->prepare('SELECT 1 FROM facebook_groups WHERE id=? AND enabled=1');
            $stmt->execute([$id]);
            if (!$stmt->fetchColumn()) throw new InvalidArgumentException('Wybierz aktywną grupę.');
            $url = fb_post_url((string)($_POST['post_url'] ?? ''), $id);
            $title = fb_clean((string)($_POST['post_title'] ?? ''));
            if (mb_strlen($title) < 12 || mb_strlen($title) > 180) throw new InvalidArgumentException('Opis oferty musi mieć 12–180 znaków.');
            $price = trim((string)($_POST['post_price'] ?? ''));
            if ($price !== '' && (!ctype_digit($price) || (int)$price < 1 || (int)$price > 10000000)) throw new InvalidArgumentException('Podaj cenę w pełnych złotych albo zostaw puste pole.');
            $stmt = $db->prepare("INSERT OR IGNORE INTO discoveries(url,title,country,source,price_text,signal) VALUES (?,?,'PL','Facebook',?,?)");
            $stmt->execute([$url, $title, $price === '' ? '' : number_format((int)$price, 0, ',', ' ') . ' zł', 'Wpis z grupy ' . $id . '; cena i dostępność do sprawdzenia']);
            $_SESSION['facebook_message'] = $stmt->rowCount() ? 'Post dodany do ofert kamperów.' : 'Ten post jest już zapisany.';
        } else {
            throw new InvalidArgumentException('Nieznana czynność.');
        }
        header('Location: facebook_groups.php'); exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
$groups = $db->query('SELECT * FROM facebook_groups ORDER BY enabled DESC, name COLLATE NOCASE')->fetchAll(PDO::FETCH_ASSOC);
$active = array_values(array_filter($groups, static fn($g) => (int)$g['enabled'] === 1));
?>
<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Grupy Facebook · IFS</title><link rel="stylesheet" href="style.css?v=20261101-fb-groups"></head><body><main class="shell">
<header class="top"><div><p class="eyebrow">IFS · KAMPERY</p><h1>Grupy Facebook</h1><p><a href="index.php">← Łowca Kamperów</a> · <a href="panel.php">Panel główny</a></p></div></header>
<?php if ($error): ?><div class="alert error" role="alert"><?= h($error) ?></div><?php endif; ?>
<?php if ($message): ?><div class="alert success" role="status"><?= h($message) ?></div><?php endif; ?>
<section class="card"><p class="eyebrow">ŹRÓDŁA</p><h2>Dodaj grupę</h2><p class="hint">IFS sprawdza publiczną stronę grupy przy skanie kamperów co 3 godziny. Jeżeli Facebook kieruje serwer do logowania, automatyczny odczyt postów z tej grupy nie działa. Panel pokaże powód. Nie używamy hasła ani prywatnej sesji Facebooka na serwerze.</p>
<form method="post" class="listing-form"><input type="hidden" name="csrf" value="<?= h(csrf_token($config)) ?>">
<label class="wide">Adres grupy<input name="group_url" type="url" required maxlength="200" placeholder="https://www.facebook.com/groups/123456789/"></label>
<label class="wide">Nazwa grupy<input name="group_name" required minlength="3" maxlength="120" placeholder="Nazwa widoczna w IFS"></label>
<button name="action" value="group_add">Dodaj grupę</button></form></section>
<section class="card"><div class="section-head"><div><p class="eyebrow">MONITORING</p><h2>Zapisane grupy</h2></div><span class="muted"><?= count($active) ?> aktywnych</span></div>
<?php foreach ($groups as $group): ?><article class="listing"><div class="listing-top"><div><span class="tag"><?= $group['enabled'] ? 'AKTYWNA W IFS' : 'WYŁĄCZONA' ?></span><h3><?= h($group['name']) ?></h3><p class="source"><?= h($group['url']) ?></p><p><strong>Status:</strong> <?= h($group['status']) ?></p><p class="hint">Ostatnie sprawdzenie: <?= $group['checked_at'] ? h($group['checked_at']) . ' UTC' : 'jeszcze nie wykonano' ?></p></div><a class="small-button secondary" href="<?= h($group['url']) ?>" target="_blank" rel="noopener noreferrer">Otwórz grupę ↗</a></div>
<div class="actions"><form method="post"><input type="hidden" name="csrf" value="<?= h(csrf_token($config)) ?>"><input type="hidden" name="group_id" value="<?= h($group['id']) ?>"><button name="action" value="group_check" class="quiet">Sprawdź teraz</button></form><form method="post"><input type="hidden" name="csrf" value="<?= h(csrf_token($config)) ?>"><input type="hidden" name="group_id" value="<?= h($group['id']) ?>"><button name="action" value="group_toggle" class="quiet"><?= $group['enabled'] ? 'Wyłącz' : 'Włącz' ?></button></form></div></article><?php endforeach; ?>
</section>
<section class="card"><p class="eyebrow">GDY FACEBOOK WYMAGA LOGOWANIA</p><h2>Zapisz post z grupy</h2><p class="hint">Wklej bezpośredni link do posta, który widzisz na Facebooku. IFS zapisze go na liście ofert jako wpis dodany ręcznie. Dane z posta i dostępność sprawdź przed zakupem.</p>
<form method="post" class="listing-form"><input type="hidden" name="csrf" value="<?= h(csrf_token($config)) ?>">
<label class="wide">Grupa<select name="group_id" required><?php foreach ($active as $group): ?><option value="<?= h($group['id']) ?>"><?= h($group['name']) ?></option><?php endforeach; ?></select></label>
<label class="wide">Link do posta<input name="post_url" type="url" required maxlength="2048" placeholder="https://www.facebook.com/groups/123456789/posts/987654321/"></label>
<label class="wide">Tytuł oferty<input name="post_title" required minlength="12" maxlength="180" placeholder="Marka, model, rok i krótki opis"></label>
<label>Cena z posta, zł <small>jeśli podana</small><input name="post_price" type="number" min="1" max="10000000" step="1"></label>
<button name="action" value="post_add" <?= $active ? '' : 'disabled' ?>>Dodaj post do ofert</button></form></section>
<footer>Automatyczny odczyt działa wyłącznie dla wpisów udostępnionych w publicznym HTML grupy. Facebook może udostępniać je tylko zalogowanym użytkownikom, nawet gdy grupa jest publiczna.</footer>
</main></body></html>

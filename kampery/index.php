<?php
declare(strict_types=1);

require __DIR__ . '/app.php';
$config = config();
session_name('kampery_session');
session_set_cookie_params(['httponly' => true, 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'samesite' => 'Lax']);
session_start();
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: no-store');

$error = '';
$message = (string)($_SESSION['message'] ?? '');
unset($_SESSION['message']);
$action = (string)($_POST['action'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($action === 'login') {
            $last = (int)($_SESSION['last_login_attempt'] ?? 0);
            $failures = (int)($_SESSION['login_failures'] ?? 0);
            if ($failures >= 5 && time() - $last < 300) throw new RuntimeException('Odczekaj 5 minut przed kolejną próbą.');
            $_SESSION['last_login_attempt'] = time();
            if (!password_verify((string)($_POST['password'] ?? ''), (string)$config['password_hash'])) {
                $_SESSION['login_failures'] = $failures + 1;
                throw new RuntimeException('Nieprawidłowe hasło.');
            }
            session_regenerate_id(true);
            $_SESSION['auth'] = true;
            $_SESSION['login_failures'] = 0;
            header('Location: panel.php'); exit;
        }
        require_csrf($config);
        if (empty($_SESSION['auth'])) throw new RuntimeException('Zaloguj się ponownie.');
        if ($action === 'logout') {
            $_SESSION = [];
            session_destroy();
            header('Location: index.php'); exit;
        }
        if ($action === 'save') {
            $row = listing_from_post();
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
            $fields = array_keys($row);
            if ($id !== null) {
                $sets = implode(', ', array_map(static fn($key) => "$key = :$key", $fields));
                $stmt = db()->prepare("UPDATE listings SET $sets, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
                $row['id'] = $id;
                $stmt->execute($row);
                if ($stmt->rowCount() === 0) throw new RuntimeException('Nie znaleziono wpisu do zmiany.');
            } else {
                $columns = implode(', ', $fields);
                $values = implode(', ', array_map(static fn($key) => ':' . $key, $fields));
                $stmt = db()->prepare("INSERT INTO listings ($columns) VALUES ($values)");
                $stmt->execute($row);
            }
            $_SESSION['message'] = 'Oferta została zapisana.';
            header('Location: index.php'); exit;
        }
        if ($action === 'delete') {
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$id) throw new InvalidArgumentException('Nieprawidłowe ID.');
            $stmt = db()->prepare('DELETE FROM listings WHERE id = ?');
            $stmt->execute([$id]);
            $_SESSION['message'] = 'Oferta została usunięta.';
            header('Location: index.php'); exit;
        }
        if ($action === 'search') {
            if (empty($config['serpapi_key'])) throw new RuntimeException('Dodaj klucz SerpAPI w config.php.');
            $country = (string)($_POST['search_country'] ?? 'PL');
            $source = (string)($_POST['search_source'] ?? 'portals');
            $results = search_public($country, $source, (string)$config['serpapi_key']);
            $_SESSION['search_results'] = array_slice($results, 0, 10);
            $_SESSION['search_country'] = $country;
            $_SESSION['search_source'] = $source;
            header('Location: index.php#search'); exit;
        }
    } catch (PDOException $e) {
        $error = strpos($e->getMessage(), 'UNIQUE') !== false ? 'Ten adres ogłoszenia jest już zapisany.' : 'Błąd bazy danych. Sprawdź uprawnienia katalogu data/.';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$logged = !empty($_SESSION['auth']);
if ($logged && !isset($_GET['analyze']) && isset($_GET['add_url']) && !isset($_GET['add_price'])) {
    $legacyUrl = (string)$_GET['add_url'];
    $stmt = db()->prepare('SELECT 1 FROM discoveries WHERE url = ?');
    $stmt->execute([$legacyUrl]);
    if ($stmt->fetchColumn()) {
        header('Location: index.php?analyze=' . rawurlencode($legacyUrl) . '#analysis');
        exit;
    }
}
$edit = null;
$listings = [];
$discoveries = [];
$scanRuns = [];
$priceDrops = [];
$selected = null;
$selectedPrice = '';
$selectedCurrency = 'PLN';
$selectedSavedId = null;
if ($logged) {
    $editId = filter_var($_GET['edit'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($editId) {
        $stmt = db()->prepare('SELECT * FROM listings WHERE id = ?');
        $stmt->execute([$editId]);
        $edit = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    $listings = db()->query("SELECT * FROM listings ORDER BY CASE status WHEN 'nowa' THEN 0 WHEN 'sprawdzana' THEN 1 ELSE 2 END, updated_at DESC, id DESC")->fetchAll(PDO::FETCH_ASSOC);
    $discoveries = db()->query("SELECT * FROM discoveries ORDER BY first_seen DESC, last_seen DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
    $photos = [];
    foreach (db()->query("SELECT url,image_url FROM catalog_offers WHERE image_url<>'' AND image_url<>'-'")->fetchAll(PDO::FETCH_ASSOC) as $photo) $photos[$photo['url']] = $photo['image_url'];
    foreach (db()->query("SELECT url,image_url FROM discoveries WHERE image_url<>'' AND image_url<>'-'")->fetchAll(PDO::FETCH_ASSOC) as $photo) $photos[$photo['url']] = $photo['image_url'];
    $scanRuns = db()->query("SELECT r.* FROM scan_runs r JOIN (SELECT source, MAX(id) id FROM scan_runs GROUP BY source) latest ON r.id = latest.id ORDER BY r.source")->fetchAll(PDO::FETCH_ASSOC);
    foreach (db()->query('SELECT * FROM price_drops ORDER BY id DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC) as $drop) {
        if (!isset($priceDrops[$drop['url']])) $priceDrops[$drop['url']] = $drop;
    }
    $analyzeUrl = (string)($_GET['analyze'] ?? '');
    if ($analyzeUrl !== '') {
        $stmt = db()->prepare('SELECT * FROM discoveries WHERE url = ?');
        $stmt->execute([$analyzeUrl]);
        $selected = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($selected) {
            $selectedCurrency = preg_match('/€|EUR/u', $selected['price_text']) ? 'EUR' : 'PLN';
            if (preg_match('/(\d[\d\s.,]*)/u', $selected['price_text'], $matches)) {
                $pricePart = preg_replace('/[,.]\d{2}$/', '', trim($matches[1]));
                $digits = preg_replace('/\D/u', '', $pricePart);
                if ($digits !== '' && (int)$digits > 0) $selectedPrice = (string)(int)$digits;
            }
            $stmt = db()->prepare('SELECT id FROM listings WHERE url = ?');
            $stmt->execute([$selected['url']]);
            $selectedSavedId = $stmt->fetchColumn() ?: null;
        }
    }
}

function field(?array $row, string $name, string $fallback = ''): string { return h(isset($row[$name]) ? (string)$row[$name] : $fallback); }
?>
<!doctype html>
<html lang="pl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Łowca Kamperów</title>
  <link rel="stylesheet" href="style.css?v=20261101-all">
</head>
<body>
<main class="shell">
  <header class="top"><div><p class="eyebrow">PRYWATNY PANEL</p><h1>Łowca Kamperów</h1><p>Oferty, weryfikacja i scenariusze zakupu pod remont.</p><?php if ($logged): ?><p><a href="panel.php">← Panel główny IFS</a> · <a href="katalog.php">Przeglądaj wszystkie kampery →</a></p><?php endif; ?></div><?php if ($logged): ?><form method="post"><input type="hidden" name="csrf" value="<?= csrf_token($config) ?>"><button class="quiet" name="action" value="logout">Wyloguj</button></form><?php endif; ?></header>
  <?php if ($error): ?><div class="alert error" role="alert"><?= h($error) ?></div><?php endif; ?>
  <?php if ($message): ?><div class="alert success" role="status"><?= h($message) ?></div><?php endif; ?>
  <?php if (!$logged): ?>
    <section class="card login"><h2>Logowanie</h2><form method="post"><input type="hidden" name="csrf" value="<?= csrf_token($config) ?>"><label>Hasło<input type="password" name="password" required autocomplete="current-password"></label><button name="action" value="login">Wejdź do panelu</button></form></section>
  <?php else: ?>
    <?php if (isset($_GET['analyze']) && !$selected): ?><div class="alert error" role="alert">Nie znaleziono tej oferty w aktualnych wynikach skanowania.</div><?php endif; ?>
    <?php if ($selected): ?>
    <section id="analysis" class="card selected-analysis" tabindex="-1">
      <div class="section-head"><div><p class="eyebrow">WYBRANA OFERTA</p><h2>Wstępna analiza</h2></div><a href="index.php#search">Wróć do wyników</a></div>
      <h3><?= h($selected['title']) ?></h3>
      <div class="analysis-facts"><div><span>Cena z ostatniego skanu</span><strong><?= $selected['price_text'] ? h($selected['price_text']) : 'Nie odczytano' ?></strong></div><div><span>Sygnał</span><strong><?= $selected['signal'] ? h($selected['signal']) : 'Brak sygnału cenowego' ?></strong></div><div><span>Źródło i data</span><strong><?= h($selected['source']) ?> · <?= h($selected['last_seen']) ?> UTC</strong></div></div>
      <p class="hint">To kandydat do sprawdzenia. Cena i dostępność mogą się zmienić; porównanie statystyczne nie uwzględnia stanu, wyposażenia ani kosztu naprawy.</p>
      <div class="analysis-actions"><a class="small-button" href="<?= h($selected['url']) ?>" target="_blank" rel="noopener noreferrer">Otwórz ogłoszenie</a><?php if ($selectedSavedId): ?><a class="small-button secondary" href="?edit=<?= (int)$selectedSavedId ?>#form">Otwórz zapisaną kalkulację</a><?php elseif ($selectedPrice !== '' && $selectedCurrency === 'PLN'): ?><a class="small-button secondary" href="#form">Uzupełnij koszty i zapisz kalkulację</a><?php else: ?><a class="small-button secondary" href="#form">Wpisz cenę i koszty</a><?php endif; ?></div>
    </section>
    <?php endif; ?>
    <div class="summary"><div><span>Zapisane oferty</span><strong><?= count($listings) ?></strong></div><div><span>Próg do sprawdzenia</span><strong>15 000 zł</strong></div><div><span>Priorytet</span><strong>25 000 zł</strong></div></div>
    <section id="search" class="card"><div class="section-head"><div><p class="eyebrow">ODKRYWANIE</p><h2>Automatycznie znalezione ogłoszenia</h2></div><span class="muted">Od najnowszych · stan i cena do potwierdzenia</span></div>
      <p class="hint">Porównanie cen korzysta z publicznego eksportu WybierzKampera. Medianę liczymy dla typu, pięcioletniego przedziału roczników i zbliżonego przebiegu; nie uwzględnia stanu, wyposażenia ani kosztu naprawy.</p>
      <?php if (!$scanRuns): ?><p class="hint">Pierwszy skan jeszcze się nie zakończył.</p><?php endif; ?>
      <?php foreach ($scanRuns as $run): ?><p class="hint"><?= h($run['source']) ?>: <?= h($run['scanned_at']) ?> UTC · <?= (int)$run['found'] ?> wyników<?= $run['error'] ? ' · ' . h($run['error']) : '' ?></p><?php endforeach; ?>
      <?php if (!$discoveries): ?><p class="empty">Nie znaleziono jeszcze kandydatów.</p><?php endif; ?>
      <?php foreach ($discoveries as $result): ?><div class="result"><?php if (!empty($photos[$result['url']])): ?><img class="result-photo" src="<?= h($photos[$result['url']]) ?>" alt="" loading="lazy" referrerpolicy="no-referrer"><?php endif; ?><div><a href="<?= h($result['url']) ?>" target="_blank" rel="noopener noreferrer"><?= h($result['title']) ?></a><p><?= h($result['country']) ?> · <?= h($result['source']) ?><?= $result['price_text'] ? ' · Cena z listy, niepotwierdzona: ' . h($result['price_text']) : '' ?><?= $result['signal'] ? ' · Sygnał: ' . h($result['signal']) : '' ?><?= isset($priceDrops[$result['url']]) ? ' · Obniżka z ' . h($priceDrops[$result['url']]['old_price']) : '' ?> · Wykryto: <?= h($result['first_seen']) ?> UTC</p></div><a class="small-button" href="?analyze=<?= rawurlencode($result['url']) ?>#analysis">Analizuj</a></div><?php endforeach; ?>
    </section>
    <section id="form" class="card"><div class="section-head"><div><p class="eyebrow">ANALIZA</p><h2><?= $edit ? 'Edytuj ofertę' : 'Dodaj ofertę' ?></h2></div><?php if ($edit): ?><a href="index.php#form">Nowa oferta</a><?php endif; ?></div>
      <form method="post" class="listing-form"><input type="hidden" name="csrf" value="<?= csrf_token($config) ?>"><input type="hidden" name="id" value="<?= field($edit, 'id') ?>">
        <label class="wide">Link do ogłoszenia<input type="url" name="url" required maxlength="2048" value="<?= field($edit, 'url', (string)($selected['url'] ?? $_GET['add_url'] ?? '')) ?>"></label>
        <label class="wide">Tytuł<input name="title" required maxlength="180" value="<?= field($edit, 'title', (string)($selected['title'] ?? $_GET['add_title'] ?? '')) ?>"></label>
        <label>Kraj<select name="country"><?php foreach (['PL'=>'Polska','DE'=>'Niemcy','NL'=>'Holandia','BE'=>'Belgia'] as $code=>$label): ?><option value="<?= $code ?>" <?= (($edit['country'] ?? $selected['country'] ?? $_GET['add_country'] ?? 'PL') === $code) ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label>
        <label>Rodzaj<select name="kind"><option value="kamper" <?= (($edit['kind'] ?? '') === 'kamper') ? 'selected' : '' ?>>Kamper</option><option value="przyczepa" <?= (($edit['kind'] ?? '') === 'przyczepa') ? 'selected' : '' ?>>Przyczepa</option></select></label>
        <label>Status<select name="status"><?php foreach (['nowa'=>'Nowa','sprawdzana'=>'Sprawdzana','odrzucona'=>'Odrzucona','kupiona'=>'Kupiona','sprzedana'=>'Sprzedana'] as $code=>$label): ?><option value="<?= $code ?>" <?= (($edit['status'] ?? 'nowa') === $code) ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label>
        <label>Waluta<select name="currency"><option value="PLN" <?= (($edit['currency'] ?? $selectedCurrency) === 'PLN') ? 'selected' : '' ?>>PLN</option><option value="EUR" <?= (($edit['currency'] ?? $selectedCurrency) === 'EUR') ? 'selected' : '' ?>>EUR</option></select></label>
        <label>Cena ofertowa<input type="number" name="asking_price" min="0.01" step="0.01" required value="<?= field($edit, 'asking_price', $selectedPrice ?: (string)($_GET['add_price'] ?? '')) ?>"></label>
        <label>Kurs EUR/PLN, wpisany ręcznie<input type="number" name="eur_rate" min="0.0001" step="0.0001" value="<?= field($edit, 'eur_rate') ?>"></label>
        <label>Cena po negocjacji <small>założenie</small><input type="number" name="negotiated_price" min="0" step="0.01" value="<?= field($edit, 'negotiated_price') ?>"></label>
        <label>Naprawa, zł<input type="number" name="repair_cost" min="0" step="0.01" value="<?= field($edit, 'repair_cost', '0') ?>"></label>
        <label>Transport i formalności, zł<input type="number" name="transport_cost" min="0" step="0.01" value="<?= field($edit, 'transport_cost', '0') ?>"></label>
        <label>Rezerwa na ryzyko, zł<input type="number" name="risk_reserve" min="0" step="0.01" value="<?= field($edit, 'risk_reserve', '0') ?>"></label>
        <label>Szacowana cena odsprzedaży, zł<input type="number" name="resale_price" min="0" step="0.01" value="<?= field($edit, 'resale_price') ?>"></label>
        <label class="wide">Notatki i źródło wyceny<textarea name="notes" maxlength="3000" rows="3"><?= field($edit, 'notes') ?></textarea></label>
        <p class="wide hint">Podaj koszty i cenę odsprzedaży na podstawie własnej oceny. Wynik jest przed podatkami i kosztami stałymi. Cena po negocjacji nie jest potwierdzona.</p>
        <button name="action" value="save"><?= $edit ? 'Zapisz zmiany' : 'Zapisz ofertę' ?></button>
      </form>
    </section>
    <section class="card"><div class="section-head"><div><p class="eyebrow">LISTA</p><h2>Zapisane oferty</h2></div></div>
      <?php if (!$listings): ?><p class="empty">Nie ma jeszcze ofert. Dodaj pierwszy link, aby zacząć analizę.</p><?php endif; ?>
      <?php foreach ($listings as $row): ?><?php [$base, $neg] = scenarios($row); $best = $neg ?? $base; $badge = $best === null ? 'Brak wyceny' : ($best >= 25000 ? 'Priorytet do sprawdzenia' : ($best >= 15000 ? 'Do sprawdzenia' : 'Poniżej progu')); ?>
        <article class="listing"><?php if (!empty($photos[$row['url']])): ?><img class="listing-photo" src="<?= h($photos[$row['url']]) ?>" alt="" loading="lazy" referrerpolicy="no-referrer"><?php endif; ?><div class="listing-top"><div><span class="tag"><?= h($row['country']) ?> · <?= h($row['kind']) ?> · <?= h($row['status']) ?></span><h3><a href="<?= h($row['url']) ?>" target="_blank" rel="noopener noreferrer"><?= h($row['title']) ?></a></h3><p class="source"><?= h($row['url']) ?></p></div><span class="badge <?= $best !== null && $best >= 25000 ? 'priority' : '' ?>"><?= $badge ?></span></div>
          <div class="numbers"><div><span>Cena ofertowa</span><strong><?= number_format((float)$row['asking_price'], 0, ',', ' ') ?> <?= h($row['currency']) ?></strong></div><div><span>Wynik przy cenie ofertowej</span><strong><?= money($base) ?></strong></div><div><span>Wynik po negocjacji</span><strong><?= money($neg) ?></strong></div></div>
          <?php if ($row['notes']): ?><p class="notes"><?= nl2br(h($row['notes'])) ?></p><?php endif; ?>
          <div class="actions"><a href="?edit=<?= (int)$row['id'] ?>#form">Edytuj</a><form method="post" onsubmit="return confirm('Usunąć tę ofertę?')"><input type="hidden" name="csrf" value="<?= csrf_token($config) ?>"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><button class="text-button" name="action" value="delete">Usuń</button></form></div>
        </article>
      <?php endforeach; ?>
    </section>
  <?php endif; ?>
  <footer>Wyniki to scenariusze robocze. Sprawdź aktualność ogłoszenia, dokumenty, wilgoć, zakres uszkodzeń i koszty przed zakupem.</footer>
</main>
</body>
</html>

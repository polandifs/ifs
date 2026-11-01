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
if (empty($_SESSION['auth'])) { header('Location: index.php'); exit; }

$database = db();
$types = $database->query("SELECT type, COUNT(*) AS total FROM catalog_offers WHERE type <> '' GROUP BY type ORDER BY total DESC")->fetchAll(PDO::FETCH_ASSOC);
$typeNames = array_column($types, 'type');
$type = (string)($_GET['type'] ?? '');
if (!in_array($type, $typeNames, true)) $type = '';
$brands = $database->query("SELECT DISTINCT brand FROM catalog_offers WHERE brand <> '' ORDER BY brand")->fetchAll(PDO::FETCH_COLUMN);
$search = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($search) > 100) $search = mb_substr($search, 0, 100);
$condition = (string)($_GET['condition'] ?? '');
$brand = (string)($_GET['brand'] ?? '');
$sort = (string)($_GET['sort'] ?? 'price_asc');
if (!in_array($condition, ['', 'Nowy', 'Używany'], true)) $condition = '';
if (!in_array($brand, $brands, true)) $brand = '';
$sortSql = [
    'price_asc' => 'price_pln IS NULL, price_pln ASC, year DESC',
    'price_desc' => 'price_pln DESC, year DESC',
    'year_desc' => 'year DESC, price_pln ASC',
    'mileage_asc' => 'mileage_km IS NULL, mileage_km ASC, price_pln ASC',
][$sort] ?? 'price_pln IS NULL, price_pln ASC, year DESC';
$priceMax = filter_var($_GET['price_max'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
$yearMin = filter_var($_GET['year_min'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1950, 'max_range' => (int)date('Y') + 1]]) ?: null;
$mileageMax = filter_var($_GET['mileage_max'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
$page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;

$where = ['1 = 1'];
$params = [];
if ($type !== '') { $where[] = 'type = :type'; $params['type'] = $type; }
if ($search !== '') {
    $where[] = '(brand LIKE :qbrand OR model LIKE :qmodel OR city LIKE :qcity)';
    $params['qbrand'] = '%' . $search . '%';
    $params['qmodel'] = '%' . $search . '%';
    $params['qcity'] = '%' . $search . '%';
}
if ($condition !== '') { $where[] = 'condition = :condition'; $params['condition'] = $condition; }
if ($brand !== '') { $where[] = 'brand = :brand'; $params['brand'] = $brand; }
if ($priceMax !== null) { $where[] = 'price_pln <= :price_max'; $params['price_max'] = $priceMax; }
if ($yearMin !== null) { $where[] = 'year >= :year_min'; $params['year_min'] = $yearMin; }
if ($mileageMax !== null) { $where[] = 'mileage_km <= :mileage_max'; $params['mileage_max'] = $mileageMax; }
$whereSql = implode(' AND ', $where);
$stmt = $database->prepare("SELECT COUNT(*) FROM catalog_offers WHERE $whereSql");
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();
$pageCount = max(1, (int)ceil($total / 40));
$page = min($page, $pageCount);
$offset = ($page - 1) * 40;
$stmt = $database->prepare("SELECT * FROM catalog_offers WHERE $whereSql ORDER BY $sortSql LIMIT 40 OFFSET $offset");
$stmt->execute($params);
$offers = $stmt->fetchAll(PDO::FETCH_ASSOC);
$overview = $database->query("SELECT COUNT(*) total, SUM(CASE WHEN condition = 'Nowy' THEN 1 ELSE 0 END) fresh, SUM(CASE WHEN condition = 'Używany' THEN 1 ELSE 0 END) used, MAX(imported_at) imported_at FROM catalog_offers")->fetch(PDO::FETCH_ASSOC);
$prices = $database->query("SELECT price_pln FROM catalog_offers WHERE price_pln > 0 ORDER BY price_pln")->fetchAll(PDO::FETCH_COLUMN);
$median = $prices ? (int)$prices[(int)floor((count($prices) - 1) / 2)] : null;
function page_link(int $page): string { $query = $_GET; $query['page'] = $page; return 'katalog.php?' . http_build_query($query); }
?>
<!doctype html>
<html lang="pl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Wszystkie kampery · Łowca Kamperów</title>
  <link rel="stylesheet" href="style.css?v=20261101-all">
</head>
<body>
<main class="shell">
  <header class="top"><div><p class="eyebrow">ŁOWCA KAMPERÓW · KATALOG</p><h1><?= $type ? h($type) : 'Wszystkie kampery' ?></h1><p>Porównaj ceny i parametry ofert w jednym miejscu.</p><p><a href="index.php">← Wróć do Łowcy Kamperów</a></p></div></header>
  <div class="summary catalog-summary"><div><span>Oferty w katalogu</span><strong><?= (int)$overview['total'] ?></strong></div><div><span>Nowe / używane</span><strong><?= (int)$overview['fresh'] ?> / <?= (int)$overview['used'] ?></strong></div><div><span>Mediana cen z katalogu</span><strong><?= $median === null ? '—' : number_format($median, 0, ',', ' ') . ' zł' ?></strong></div></div>
  <nav class="catalog-types" aria-label="Typy kamperów"><a class="<?= $type === '' ? 'active' : '' ?>" href="katalog.php">Wszystkie <span><?= (int)$overview['total'] ?></span></a><?php foreach ($types as $item): ?><a class="<?= $type === $item['type'] ? 'active' : '' ?>" href="katalog.php?type=<?= rawurlencode($item['type']) ?>"><?= h($item['type']) ?> <span><?= (int)$item['total'] ?></span></a><?php endforeach; ?></nav>
  <section class="card">
    <div class="section-head"><div><p class="eyebrow">ZAWĘŹ WYNIKI</p><h2>Filtry ofert</h2></div><span class="muted"><?= $total ?> wyników</span></div>
    <form method="get" class="catalog-filters">
      <label>Typ<select name="type"><option value="">Wszystkie</option><?php foreach ($types as $item): ?><option value="<?= h($item['type']) ?>" <?= $type === $item['type'] ? 'selected' : '' ?>><?= h($item['type']) ?></option><?php endforeach; ?></select></label>
      <label>Szukaj marki, modelu lub miasta<input name="q" maxlength="100" value="<?= h($search) ?>"></label>
      <label>Stan<select name="condition"><option value="">Wszystkie</option><option value="Nowy" <?= $condition === 'Nowy' ? 'selected' : '' ?>>Nowe</option><option value="Używany" <?= $condition === 'Używany' ? 'selected' : '' ?>>Używane</option></select></label>
      <label>Marka<select name="brand"><option value="">Wszystkie</option><?php foreach ($brands as $item): ?><option value="<?= h($item) ?>" <?= $brand === $item ? 'selected' : '' ?>><?= h($item) ?></option><?php endforeach; ?></select></label>
      <label>Cena do, zł<input type="number" min="1" name="price_max" value="<?= $priceMax ?: '' ?>"></label>
      <label>Rok od<input type="number" min="1950" max="<?= (int)date('Y') + 1 ?>" name="year_min" value="<?= $yearMin ?: '' ?>"></label>
      <label>Przebieg do, km<input type="number" min="1" name="mileage_max" value="<?= $mileageMax ?: '' ?>"></label>
      <label>Sortuj<select name="sort"><option value="price_asc" <?= $sort === 'price_asc' ? 'selected' : '' ?>>Cena rosnąco</option><option value="price_desc" <?= $sort === 'price_desc' ? 'selected' : '' ?>>Cena malejąco</option><option value="year_desc" <?= $sort === 'year_desc' ? 'selected' : '' ?>>Rok malejąco</option><option value="mileage_asc" <?= $sort === 'mileage_asc' ? 'selected' : '' ?>>Przebieg rosnąco</option></select></label>
      <div class="filter-actions"><button>Filtruj</button><a href="katalog.php">Wyczyść</a></div>
    </form>
  </section>
  <section class="card">
    <div class="section-head"><div><p class="eyebrow">KATALOG</p><h2><?= $total ?> ofert<?= $type ? ' · ' . h($type) : '' ?></h2></div><span class="muted">Strona <?= $page ?> / <?= $pageCount ?></span></div>
    <?php if (!$overview['total']): ?><p class="empty">Katalog zostanie wypełniony podczas najbliższego pobrania danych.</p><?php elseif (!$offers): ?><p class="empty">Nie ma ofert dla tych filtrów.</p><?php endif; ?>
    <div class="catalog-list">
      <?php foreach ($offers as $offer): ?>
      <article class="catalog-offer">
        <?php if ($offer['image_url'] && $offer['image_url'] !== '-'): ?><a class="catalog-photo-link" href="<?= h($offer['url']) ?>" target="_blank" rel="noopener noreferrer"><img class="catalog-photo" src="<?= h($offer['image_url']) ?>" alt="" loading="lazy" referrerpolicy="no-referrer"></a><?php endif; ?>
        <div><span class="tag"><?= h($offer['type']) ?> · <?= h($offer['condition']) ?> · <?= $offer['year'] ? (int)$offer['year'] : 'Rok niepodany' ?><?= $offer['mileage_km'] ? ' · ' . number_format((int)$offer['mileage_km'], 0, ',', ' ') . ' km' : '' ?></span><h3><a href="<?= h($offer['url']) ?>" target="_blank" rel="noopener noreferrer"><?= h($offer['title']) ?></a></h3><p class="source"><?= h($offer['city']) ?><?= $offer['region'] ? ' · ' . h($offer['region']) : '' ?><?= $offer['seller'] ? ' · ' . h($offer['seller']) : '' ?></p><p class="hint"><?= $offer['length_m'] ? number_format((float)$offer['length_m'], 2, ',', ' ') . ' m · ' : '' ?><?= $offer['sleeping_places'] ? (int)$offer['sleeping_places'] . ' miejsc do spania · ' : '' ?><?= h($offer['availability']) ?><?= $offer['checked_at'] ? ' · Sprawdzono: ' . h($offer['checked_at']) : '' ?></p></div>
        <div class="catalog-price"><strong><?= $offer['price_pln'] ? number_format((int)$offer['price_pln'], 0, ',', ' ') . ' zł' : 'Cena niepodana' ?></strong><?php if ($offer['previous_price_pln'] && (int)$offer['previous_price_pln'] > (int)$offer['price_pln']): ?><small>Poprzednio <?= number_format((int)$offer['previous_price_pln'], 0, ',', ' ') ?> zł</small><?php endif; ?><small><?= h($offer['price_certainty']) ?></small><a class="small-button" href="index.php?add_url=<?= rawurlencode($offer['url']) ?>&amp;add_title=<?= rawurlencode($offer['title']) ?>&amp;add_country=PL&amp;add_price=<?= (int)$offer['price_pln'] ?>#form">Dodaj do kalkulacji</a></div>
      </article>
      <?php endforeach; ?>
    </div>
    <?php if ($pageCount > 1): ?><nav class="pagination" aria-label="Strony wyników"><?php if ($page > 1): ?><a href="<?= h(page_link($page - 1)) ?>">← Poprzednia</a><?php endif; ?><span><?= $page ?> / <?= $pageCount ?></span><?php if ($page < $pageCount): ?><a href="<?= h(page_link($page + 1)) ?>">Następna →</a><?php endif; ?></nav><?php endif; ?>
  </section>
  <footer>Dane ofert: publiczny eksport <a href="https://wybierzkampera.pl/pobierz/kampery-oferty.csv" target="_blank" rel="noopener noreferrer">WybierzKampera</a>; ostatni import <?= h($overview['imported_at'] ?? '') ?> UTC. Cena, dostępność i stan wymagają potwierdzenia u sprzedawcy. Katalog służy do porównania, nie jest gwarancją okazji.</footer>
</main>
</body>
</html>

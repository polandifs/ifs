<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/app.php';
require_once __DIR__ . '/facebook_group_core.php';

$db = db();
$groups = $db->query('SELECT * FROM facebook_groups WHERE enabled=1 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
foreach ($groups as $group) {
    try {
        [$status, $found, $added] = fb_scan_group($db, $group);
    } catch (Throwable $e) {
        $status = 'Błąd sprawdzania: ' . mb_substr($e->getMessage(), 0, 100);
        $db->prepare('UPDATE facebook_groups SET checked_at=CURRENT_TIMESTAMP, status=? WHERE id=?')->execute([$status, $group['id']]);
        $found = 0;
        $added = 0;
    }
    echo $group['id'] . ': ' . $status . PHP_EOL;
}

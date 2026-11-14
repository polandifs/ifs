<?php
declare(strict_types=1);
require __DIR__ . '/../kampery/mieszkania/valuation_core.php';
require __DIR__ . '/../kampery/mieszkania/rcn_chart.php';
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE rcn_transactions(sold_at TEXT,price REAL,area REAL,price_m2 REAL,address TEXT,easting REAL,northing REAL,market TEXT)');
$db->exec('CREATE TABLE rcn_geocodes(street TEXT PRIMARY KEY,geometry_json TEXT,checked_at TEXT)');
$db->exec("INSERT INTO rcn_geocodes VALUES ('testowa', '[[650000,500000]]', CURRENT_TIMESTAMP)");
$insert = $db->prepare("INSERT INTO rcn_transactions VALUES(?,?,?,?,?,?,?,'rynekWtorny')");
foreach ([5000,5500,6000,6500,7000,7500] as $i=>$price) $insert->execute([gmdate('Y-m-d', strtotime('-' . ($i + 1) . ' months')), $price * 50,50,$price,'MSC:Kwidzyn;UL:Testowa;NR_PORZ:1',650010,500010]);
$local = valuation_summary($db,50,'Testowa'); $city = valuation_city_summary($db,50);
check(count($local['chart_rows']) === $local['count'], 'Local chart and valuation must use same sample');
check(count($city['chart_rows']) === $city['count'], 'City chart and valuation must use same sample');
$html = rcn_chart_render($local,$city,50,375000);
check(strpos($html,'20,0% powyżej mediany') !== false, 'Offer difference should use exact price/m2 median');
check(substr_count($html,'<circle ') === 6, 'Show one point per selected transaction');
check(strpos($html,'Cały Kwidzyn') === false, 'Local sample must not be labelled citywide');
$local['scope'] = '<script>alert(1)</script>';
check(strpos(rcn_chart_render($local,$city,50,375000),'<script>') === false, 'Escape source scope');
$missing = ['available'=>false];
check(strpos(rcn_chart_render($missing,$city,50,375000),'Cały Kwidzyn') !== false, 'Label city fallback');
check(strpos(rcn_chart_render($missing,$missing,50,375000),'<svg') === false, 'No chart for insufficient sample');
check(strpos(rcn_chart_render($local,$city,50,null),'class="rcn-asking"') === false, 'No invented asking price');
check(rcn_chart_render($local,$city,0,375000) === '', 'Reject invalid area');
check(strpos(rcn_chart_render($local,$city,50,100000000), 'INF') === false, 'Scale handles expensive offer');
file_put_contents(__DIR__.'/../../rcn_preview.html','<!doctype html><html lang="pl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Podgląd modułu RCN · dane testowe</title><style>body{max-width:950px;margin:30px auto;padding:16px;font-family:system-ui;background:#f4f7f6}'.file_get_contents(__DIR__.'/../kampery/mieszkania/rcn_chart.css').'</style><h1>Podgląd modułu · dane testowe</h1>'.$html);
echo "RCN chart: 10 checks passed\n";

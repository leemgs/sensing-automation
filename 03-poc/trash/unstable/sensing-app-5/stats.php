<?php
// stats.php — simple time-series counts for last 14 days
declare(strict_types=1);
require_once __DIR__ . '/lib/db.php';
header('Content-Type: application/json; charset=UTF-8');
db_init(); $pdo = db();
$labels = []; $counts = [];
for ($i=13; $i>=0; $i--) {
    $day = (new DateTime("-$i days"))->format('Y-m-d');
    $labels[] = $day;
    $st = $pdo->prepare("SELECT COUNT(*) FROM results WHERE created_at LIKE ?");
    $st->execute([$day.'%']);
    $counts[] = intval($st->fetchColumn());
}
echo json_encode(['labels'=>$labels,'counts'=>$counts], JSON_UNESCAPED_UNICODE);

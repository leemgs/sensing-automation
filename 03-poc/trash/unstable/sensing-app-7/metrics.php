<?php
// metrics.php — Prometheus metrics endpoint
declare(strict_types=1);
require_once __DIR__ . '/lib/db.php';
header('Content-Type: text/plain; version=0.0.4; charset=UTF-8');

db_init(); $pdo = db();

$total = $pdo->query("SELECT COUNT(*) FROM results")->fetchColumn();
$needs = $pdo->query("SELECT COUNT(*) FROM results WHERE needs_review=1")->fetchColumn();

echo "# HELP sensing_results_total 총 분석 결과 건수\n";
echo "# TYPE sensing_results_total counter\n";
echo "sensing_results_total {$total}\n\n";

echo "# HELP sensing_results_needs_review 검토 필요 건수\n";
echo "# TYPE sensing_results_needs_review gauge\n";
echo "sensing_results_needs_review {$needs}\n\n";

// by category
$cats = ['governance','contract','lawsuit','data','model','agent'];
echo "# HELP sensing_results_by_category 카테고리별 결과 건수\n";
echo "# TYPE sensing_results_by_category gauge\n";
foreach ($cats as $c) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM results WHERE regulation_category=? OR asset_category=?");
    $st->execute([$c,$c]);
    $cnt = $st->fetchColumn();
    echo "sensing_results_by_category{category=\"$c\"} {$cnt}\n";
}
?>

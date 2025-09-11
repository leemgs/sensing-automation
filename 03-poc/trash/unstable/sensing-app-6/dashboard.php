<?php
// dashboard.php — 검색/필터/다운로드 링크
declare(strict_types=1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/config.php';

db_init();
$pdo = db();

$q = $_GET['q'] ?? '';
$cat = $_GET['cat'] ?? '';
$need = $_GET['need'] ?? '';
$from = $_GET['from'] ?? '';
$to   = $_GET['to'] ?? '';

$sql = "SELECT * FROM results WHERE 1=1";
$prm = [];

if ($q !== '') { $sql .= " AND (subject LIKE :q OR url LIKE :q)"; $prm[':q'] = "%$q%"; }
if ($cat !== '') { $sql .= " AND (regulation_category = :cat OR asset_category = :cat)"; $prm[':cat'] = $cat; }
if ($from !== '') { $sql .= " AND created_at >= :from"; $prm[':from'] = $from . " 00:00:00"; }
if ($to !== '') { $sql .= " AND created_at <= :to"; $prm[':to'] = $to . " 23:59:59"; }

$need = ($need==='1') ? 1 : (($need==='0') ? 0 : null);
if ($need!==null) { $sql .= " AND needs_review = :need"; $prm[':need']=$need; }
$sql .= " ORDER BY created_at DESC LIMIT 500";
$st = $pdo->prepare($sql);
$st->execute($prm);
$rows = $st->fetchAll();
?>
<!doctype html>
<html lang="ko">
<head>
  <meta charset="utf-8">
  <title>센싱 대시보드</title>
  <link rel="stylesheet" href="public/style.css">
</head>
<body>
  <h1>센싱 대시보드</h1>
  <form method="get" class="filters" style="margin-bottom:12px;">
    키워드 <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" />
    카테고리 
    <select name="cat">
      <option value="">(전체)</option>
      <option<?= $cat==='governance'?' selected':'' ?>>governance</option>
      <option<?= $cat==='contract'?' selected':'' ?>>contract</option>
      <option<?= $cat==='lawsuit'?' selected':'' ?>>lawsuit</option>
      <option<?= $cat==='data'?' selected':'' ?>>data</option>
      <option<?= $cat==='model'?' selected':'' ?>>model</option>
      <option<?= $cat==='agent'?' selected':'' ?>>agent</option>
    </select> 필요검토 <select name=\"need\"><option value=\"\">(전체)</option><option value=\"1\"<?= $need==="1"?" selected":"" ?></option><option value="0"<?= $need==="0"?" selected":"" ?>>아님</option></select>
    기간 <input type="date" name="from" value="<?= htmlspecialchars($from) ?>"> ~ <input type="date" name="to" value="<?= htmlspecialchars($to) ?>">
    <button class="btn" type="submit">검색</button>
    <a class="btn" href="dashboard.php">초기화</a>
  </form>

  <table>
    <thead>
      <tr>
        <th>시각</th><th>제목</th><th>URL</th><th>규제</th><th>에셋</th><th>파일</th><th>상태</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
      <tr>
        <td><small><?= htmlspecialchars($r['created_at']) ?></small></td>
        <td><?= htmlspecialchars($r['subject'] ?? '') ?></td>
        <td><a target="_blank" href="<?= htmlspecialchars($r['url']) ?>"><?= htmlspecialchars($r['url']) ?></a></td>
        <td><?= htmlspecialchars($r['regulation_category'] ?? '-') ?></td>
        <td><?= htmlspecialchars($r['asset_category'] ?? '-') ?></td>
        <td>
          <?php if ($r['regulation_path']): ?>
            <a target="_blank" href="<?= htmlspecialchars($r['regulation_path']) ?>">규제HTML</a>
          <?php endif; ?>
          <?php if ($r['asset_path']): ?>
            <?= $r['regulation_path'] ? ' | ' : '' ?>
            <a target="_blank" href="<?= htmlspecialchars($r['asset_path']) ?>">에셋HTML</a>
          <?php endif; ?>
        </td>
        <td><?= $r['status']==='success'?'✅':'❌' ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <hr>
  <h2>지표</h2>
  <canvas id="chart1" width="800" height="240"></canvas>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script>
  fetch('stats.php').then(r=>r.json()).then(d=>{
    const ctx = document.getElementById('chart1').getContext('2d');
    new Chart(ctx, {
      type: 'line',
      data: { labels: d.labels, datasets: [{ label: '저장 건수', data: d.counts }] },
      options: { responsive: false }
    });
  });
  </script>
</body>
</html>

</html>

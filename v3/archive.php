<?php
mb_internal_encoding('UTF-8');
$cfg = require __DIR__ . '/config.php';

if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool {
        return $needle === '' || strpos($haystack, $needle) === 0;
    }
}

$dirs = [
  'lawsuit'    => rtrim($cfg['lawsuit_dir']    ?? (__DIR__.'/소송'), '/'),
  'contract'   => rtrim($cfg['contract_dir']   ?? (__DIR__.'/계약'), '/'),
  'governance' => rtrim($cfg['governance_dir'] ?? (__DIR__.'/거버넌스'), '/'),
];
$branding = $cfg['branding'] ?? [];
$logo = $branding['logo_url'] ?? '';
$disc = $branding['disclaimer_html'] ?? '';
$adminToken = $cfg['admin_token'] ?? '';
$archiveRootRel = ltrim(str_replace(__DIR__, '', realpath($cfg['archive_dir'] ?? (__DIR__.'/보관')) ?: ''), '/');

$category  = $_GET['category'] ?? 'all';
$q         = trim($_GET['q'] ?? '');
$order     = ($_GET['order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
$page      = max(1, (int)($_GET['page'] ?? 1));
$perPage   = max(5, min(100, (int)($_GET['per_page'] ?? 20)));

$labelFilter = trim($_GET['label'] ?? '');
$labelMode   = ($_GET['label_mode'] ?? 'contains') === 'exact' ? 'exact' : 'contains';

$labelsSelected = $_GET['labels'] ?? [];
if (!is_array($labelsSelected)) $labelsSelected = [];
$labelsSelected = array_values(array_filter(array_map('trim', $labelsSelected), fn($v)=>$v!==''));
$labelsLogic = ($_GET['labels_logic'] ?? 'or') === 'and' ? 'and' : 'or';
$labelsMatch = ($_GET['labels_match'] ?? 'contains') === 'exact' ? 'exact' : 'contains';

$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo   = trim($_GET['date_to'] ?? '');
$ftsQuery = trim($_GET['fts'] ?? '');

$selectedRels = $_GET['sel'] ?? [];
if (!is_array($selectedRels)) $selectedRels = [];
$selectedRels = array_values(array_filter(array_map('trim',$selectedRels), fn($v)=>$v!==''));

$export = $_GET['export'] ?? '';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
function human_category($k){ return ['lawsuit'=>'소송','contract'=>'계약','governance'=>'거버넌스'][$k] ?? '알수없음'; }
function parse_filename($basename){
  $name = preg_replace('/\.html?$/i','',$basename);
  if (preg_match('/^(\d{8})-(.+)$/u', $name, $m)) {
    $ymd = $m[1]; $title = $m[2];
    $date = substr($ymd,0,4).'-'.substr($ymd,4,2).'-'.substr($ymd,6,2);
    return [$date, $title];
  }
  return ['', $name];
}
function extract_label_from_html($path){
  $html = @file_get_contents($path, false, null, 0, 4096);
  if (!$html) return '';
  if (preg_match('/라벨<\/span>\s*:\s*([^<]+)/u', $html, $m)) return trim($m[1]);
  return '';
}
function labels_from_meta(string $labelString): array {
  if ($labelString==='') return [];
  $arr = preg_split('/\s*,\s*/u', $labelString, -1, PREG_SPLIT_NO_EMPTY);
  return array_values(array_filter(array_map('trim', $arr), fn($v)=>$v!==''));
}
function labels_match_multi(array $itemLabels, array $selected, string $logic, string $match): bool {
  if (!$selected) return true;
  $have = array_map(fn($v)=>mb_strtolower($v,'UTF-8'), $itemLabels);
  $want = array_map(fn($v)=>mb_strtolower($v,'UTF-8'), $selected);
  $test = function($w) use($have,$match){
    if ($match==='exact') return in_array($w, $have, true);
    foreach ($have as $h) { if (mb_strpos($h, $w, 0, 'UTF-8') !== false) return true; }
    return false;
  };
  if ($logic==='and') { foreach ($want as $w) if (!$test($w)) return false; return true; }
  foreach ($want as $w) if ($test($w)) return true; return false;
}
function in_date_range(string $date, string $from, string $to): bool {
  if ($from!=='' && $date<$from) return false;
  if ($to!=='' && $date>$to) return false;
  return true;
}
function file_contains_text(string $path, string $needle, int $maxBytes=1048576): bool {
  if ($needle==='') return true;
  if (!is_file($path) || !is_readable($path)) return false;
  $fh = @fopen($path, 'rb'); if (!$fh) return false;
  $read=0; $buf='';
  while(!feof($fh) && $read<$maxBytes){
    $chunk = fread($fh, min(65536, $maxBytes - $read));
    if ($chunk===false) break;
    $buf .= $chunk;
  }
  fclose($fh);
  $text = mb_strtolower(strip_tags($buf), 'UTF-8');
  $q = mb_strtolower($needle, 'UTF-8');
  return mb_strpos($text, $q) !== false;
}

$items = [];
$scanTargets = ($category==='all') ? array_keys($dirs) : [ $category ];
foreach ($scanTargets as $cat) {
  $dir = $dirs[$cat] ?? '';
  if (!$dir || !is_dir($dir)) continue;
  $it = new DirectoryIterator($dir);
  foreach ($it as $f) {
    if ($f->isDot() || !$f->isFile()) continue;
    if (!preg_match('/\.html?$/i', $f->getFilename())) continue;
    [$date, $title] = parse_filename($f->getFilename());
    $basename = $f->getFilename();
    $full = $f->getPathname();
    $rel  = ltrim(str_replace(__DIR__, '', $full), '/');
    $hay = mb_strtolower($basename, 'UTF-8');
    $qq  = mb_strtolower($q, 'UTF-8');
    if ($q !== '' && mb_strpos($hay, $qq) === false) continue;
    $label = extract_label_from_html($full);

    $items[] = [
      'cat'   => $cat,
      'date'  => $date ?: date('Y-m-d', $f->getMTime()),
      'title' => $title ?: $basename,
      'name'  => $basename,
      'url'   => './' . $rel,
      'rel'   => $rel,
      'label' => $label,
      'mtime' => $f->getMTime(),
      'full'  => $full,
    ];
  }
}

$distinctLabels = [];
foreach ($items as $it) {
  if (!empty($it['label'])) foreach (labels_from_meta($it['label']) as $lv) $distinctLabels[$lv]=true;
}
$distinctLabels = array_keys($distinctLabels);
sort($distinctLabels, SORT_NATURAL);

if ($labelsSelected) {
  $items = array_values(array_filter($items, function($it) use($labelsSelected,$labelsLogic,$labelsMatch){
    $arr = labels_from_meta($it['label'] ?? '');
    return labels_match_multi($arr, $labelsSelected, $labelsLogic, $labelsMatch);
  }));
} elseif ($labelFilter !== '') {
  $items = array_values(array_filter($items, function($it) use($labelFilter,$labelMode){
    $lab = $it['label'] ?? '';
    if ($lab==='') return false;
    if ($labelMode==='exact') {
      foreach (labels_from_meta($lab) as $lv) if ($lv === $labelFilter) return true;
      return false;
    }
    return mb_stripos($lab, $labelFilter, 0, 'UTF-8') !== false;
  }));
}

if ($dateFrom !== '' || $dateTo !== '') {
  $items = array_values(array_filter($items, function($it) use($dateFrom,$dateTo){
    return in_date_range($it['date'], $dateFrom, $dateTo);
  }));
}

if ($ftsQuery !== '') {
  $items = array_values(array_filter($items, function($it) use($ftsQuery){
    return file_contains_text($it['full'], $ftsQuery);
  }));
}

if (!empty($selectedRels)) {
  $set = array_flip($selectedRels);
  $items = array_values(array_filter($items, fn($it)=>isset($set[$it['rel']])));
}

if ($export === 'csv' || $export === 'xls') {
  $rows = [];
  $rows[] = ['카테고리','날짜','제목','파일명','라벨','URL'];
  foreach ($items as $it) {
    $rows[] = [
      ['lawsuit'=>'소송','contract'=>'계약','governance'=>'거버넌스'][$it['cat']] ?? $it['cat'],
      $it['date'],$it['title'],$it['name'],$it['label'] ?? '',
      (isset($_SERVER['REQUEST_SCHEME'])?$_SERVER['REQUEST_SCHEME']:'http').'://'.($_SERVER['HTTP_HOST'] ?? '').dirname($_SERVER['REQUEST_URI'] ?? '/').'/'.ltrim($it['url'],'./')
    ];
  }
  if ($export === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="archive_export.csv"');
    echo "\xEF\xBB\xBF";
    $fp = fopen('php://output', 'w');
    foreach ($rows as $r) fputcsv($fp, $r);
    fclose($fp); exit;
  }
  if ($export === 'xls') {
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="archive_export.xls"');
    echo "<html><meta charset='utf-8'><body><table border='1'>";
    foreach ($rows as $r) {
      echo "<tr>";
      foreach ($r as $c) echo "<td>".h($c)."</td>";
      echo "</tr>";
    }
    echo "</table></body></html>";
    exit;
  }
}

usort($items, function($a,$b) use($order){
  if ($a['date']===$b['date']) return $order==='asc' ? ($a['mtime']<=>$b['mtime']) : ($b['mtime']<=>$a['mtime']);
  return $order==='asc' ? strcmp($a['date'],$b['date']) : strcmp($b['date'],$a['date']);
});
$total = count($items); $pages = (int)ceil($total/$perPage);
$offset = ($page-1)*$perPage; $view = array_slice($items, $offset, $perPage);

$counts = ['lawsuit'=>0,'contract'=>0,'governance'=>0]; foreach ($items as $it) $counts[$it['cat']]++;
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<title>문서 아카이브 (소송/계약/거버넌스)</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;margin:20px;line-height:1.6}
  h1{margin:0 0 12px}
  .bar{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:12px}
  input,select,button{padding:8px 10px;border:1px solid #e5e7eb;border-radius:10px}
  button{cursor:pointer}
  .muted{color:#6b7280}
  .stats{display:flex;gap:10px;flex-wrap:wrap;margin:8px 0 16px}
  .chip{border:1px solid #e5e7eb;border-radius:999px;padding:4px 10px}
  .grid{display:grid;grid-template-columns:1fr 420px;gap:16px}
  .list{display:grid;gap:12px}
  .card{border:1px solid #e5e7eb;border-radius:12px;padding:14px}
  .row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
  .tag{font-size:.8rem;border:1px solid #e5e7eb;border-radius:999px;padding:2px 8px;background:#f9fafb}
  .btn-danger{border-color:#fecaca;background:#fff5f5}
  .btn-archive{border-color:#dbeafe;background:#eff6ff}
  .viewer{border:1px solid #e5e7eb;border-radius:12px;height:70vh}
  .viewer iframe{width:100%;height:100%;border:0;border-radius:12px}
  .pager{display:flex;gap:6px;flex-wrap:wrap;margin-top:14px}
  .logo{max-height:40px}
  .row-actions button{padding:6px 10px}
</style>
</head>
<body>

<?php if ($logo): ?>
  <div style="margin-bottom:12px"><img class="logo" src="<?=h($logo)?>" alt="logo"></div>
<?php endif; ?>

<h1>문서 아카이브</h1>

<form class="bar" method="get" action="">
  <select name="category">
    <option value="all"        <?= $category==='all'?'selected':'' ?>>전체</option>
    <option value="lawsuit"    <?= $category==='lawsuit'?'selected':'' ?>>소송</option>
    <option value="contract"   <?= $category==='contract'?'selected':'' ?>>계약</option>
    <option value="governance" <?= $category==='governance'?'selected':'' ?>>거버넌스</option>
  </select>

  <input type="text" name="q" value="<?=h($q)?>" placeholder="파일명/제목 검색" />

  <select name="label">
    <option value="">라벨(전체)</option>
    <?php foreach ($distinctLabels as $lv): ?>
      <option value="<?=h($lv)?>" <?= $labelFilter===$lv?'selected':'' ?>><?=h($lv)?></option>
    <?php endforeach; ?>
  </select>
  <select name="label_mode" title="라벨 매칭">
    <option value="contains" <?= $labelMode==='contains'?'selected':'' ?>>포함</option>
    <option value="exact"    <?= $labelMode==='exact'?'selected':'' ?>>정확일치</option>
  </select>

  <select name="labels[]" multiple size="1" title="라벨(다중)">
    <?php foreach ($distinctLabels as $lv): ?>
      <option value="<?=h($lv)?>" <?= in_array($lv,$labelsSelected,true)?'selected':'' ?>><?=h($lv)?></option>
    <?php endforeach; ?>
  </select>
  <select name="labels_logic" title="라벨 논리">
    <option value="or"  <?= $labelsLogic==='or'?'selected':''  ?>>라벨 OR</option>
    <option value="and" <?= $labelsLogic==='and'?'selected':'' ?>>라벨 AND</option>
  </select>
  <select name="labels_match" title="라벨 매칭">
    <option value="contains" <?= $labelsMatch==='contains'?'selected':'' ?>>포함</option>
    <option value="exact"    <?= $labelsMatch==='exact'?'selected':'' ?>>정확일치</option>
  </select>

  <input type="date" name="date_from" value="<?=h($dateFrom)?>" title="시작일">
  <input type="date" name="date_to"   value="<?=h($dateTo)?>"   title="종료일">
  <input type="text" name="fts" value="<?=h($ftsQuery)?>" placeholder="본문 전체 검색">

  <select name="order">
    <option value="desc" <?= $order==='desc'?'selected':'' ?>>날짜 최신순</option>
    <option value="asc"  <?= $order==='asc'?'selected':''  ?>>날짜 오래된순</option>
  </select>
  <select name="per_page">
    <?php foreach ([10,20,30,50,100] as $pp): ?>
      <option value="<?=$pp?>" <?= $perPage===$pp?'selected':'' ?>><?=$pp?>개</option>
    <?php endforeach; ?>
  </select>

  <label style="display:flex;align-items:center;gap:6px">
    <input type="checkbox" id="toggle-all"><span>현재 페이지 전체선택</span>
  </label>

  <button>적용</button>
  <button name="export" value="csv">CSV 다운로드</button>
  <button name="export" value="xls">Excel 다운로드</button>

  <?php if ($q!=='' || $category!=='all' || $order!=='desc' || $perPage!==20 || $labelFilter!=='' || $labelsSelected || $dateFrom!=='' || $dateTo!=='' || $ftsQuery!==''): ?>
    <a class="muted" href="archive.php" style="text-decoration:none">초기화</a>
  <?php endif; ?>
</form>

<div class="stats">
  <div class="chip">소송: <?= number_format($counts['lawsuit']) ?>건</div>
  <div class="chip">계약: <?= number_format($counts['contract']) ?>건</div>
  <div class="chip">거버넌스: <?= number_format($counts['governance']) ?>건</div>
  <div class="chip">총 <?= number_format($total) ?>건</div>
</div>

<div class="grid">
  <div>
    <div class="list">
      <?php if (!$view): ?>
        <div class="muted">표시할 문서가 없습니다.</div>
      <?php else: foreach ($view as $it):
        $isArchived = $archiveRootRel && str_starts_with($it['url'], './'.$archiveRootRel);
      ?>
        <div class="card" data-url="<?=h($it['url'])?>">
          <div class="row" style="justify-content:space-between">
            <div class="row">
              <input type="checkbox" name="sel[]" value="<?=h($it['rel'])?>" class="selbox" />
              <span class="tag"><?=h(human_category($it['cat']))?></span>
              <strong style="margin-left:6px"><?=h($it['title'])?></strong>
            </div>
            <div class="muted"><?=h($it['date'])?></div>
          </div>
          <div class="row" style="margin-top:6px;justify-content:space-between">
            <div class="muted">파일명: <?=h($it['name'])?></div>
            <div class="muted">라벨: <?= $it['label'] ? h($it['label']) : '—' ?></div>
          </div>
          <div class="row row-actions" style="margin-top:10px">
            <button class="btn-preview">미리보기</button>
            <a class="btn-open" href="<?=h($it['url'])?>" target="_blank" rel="noopener">새 탭에서 열기</a>
            <a class="btn-download" href="<?=h($it['url'])?>" download="<?=h($it['name'])?>">다운로드</a>
            <?php if ($isArchived): ?>
              <button class="btn-archive" data-rel="<?=h($it['rel'])?>" data-mode="restore">복원</button>
            <?php else: ?>
              <button class="btn-archive" data-rel="<?=h($it['rel'])?>" data-mode="archive">보관</button>
            <?php endif; ?>
            <button class="btn-danger btn-delete" data-rel="<?=h($it['rel'])?>">삭제</button>
          </div>
        </div>
      <?php endforeach; endif; ?>
    </div>

    <?php if ($pages>1): ?>
      <div class="pager">
        <?php
          $mk = function($p) use($category,$q,$order,$perPage,$labelFilter,$labelMode,$labelsSelected,$labelsLogic,$labelsMatch,$dateFrom,$dateTo,$ftsQuery){
            return 'archive.php?'.http_build_query([
              'category'=>$category,'q'=>$q,'order'=>$order,'per_page'=>$perPage,
              'label'=>$labelFilter,'label_mode'=>$labelMode,
              'labels'=>$labelsSelected,'labels_logic'=>$labelsLogic,'labels_match'=>$labelsMatch,
              'date_from'=>$dateFrom,'date_to'=>$dateTo,'fts'=>$ftsQuery,'page'=>$p
            ]);
          };
        ?>
        <?php if ($page>1): ?>
          <a href="<?=$mk(1)?>">« 처음</a>
          <a href="<?=$mk($page-1)?>">‹ 이전</a>
        <?php endif; ?>
        <span class="muted">페이지 <?=$page?> / <?=$pages?></span>
        <?php if ($page<$pages): ?>
          <a href="<?=$mk($page+1)?>">다음 ›</a>
          <a href="<?=$mk($pages)?>">마지막 »</a>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

  <div>
    <div class="viewer" id="viewer"><iframe title="미리보기" src="about:blank"></iframe></div>
  </div>
</div>

<?php if ($disc): ?>
  <div style="margin-top:18px;color:#6b7280;font-size:.95rem"><?=$disc?></div>
<?php endif; ?>

<script>
const token = <?= json_encode($adminToken) ?>;
const viewer = document.querySelector('#viewer iframe');

document.querySelectorAll('.btn-preview').forEach(btn=>{
  btn.addEventListener('click', (e)=>{
    e.preventDefault();
    const card = e.target.closest('.card');
    const url = card?.getAttribute('data-url');
    if (url) viewer.src = url;
  });
});

const toggleAll = document.getElementById('toggle-all');
if (toggleAll) {
  toggleAll.addEventListener('change', ()=>{
    document.querySelectorAll('.selbox').forEach(cb=>{ cb.checked = toggleAll.checked; });
  });
}

document.querySelectorAll('button[name="export"]').forEach(btn=>{
  btn.addEventListener('click', (e)=>{
    const anyChecked = Array.from(document.querySelectorAll('.selbox')).some(cb=>cb.checked);
    if (!anyChecked) {
      if (!confirm('선택된 항목이 없습니다. 현재 필터 전체를 내보낼까요?')) e.preventDefault();
    }
  });
});

async function callAction(action, rel){
  if (!token) { alert('관리자 토큰이 설정되지 않았습니다. config.php의 admin_token을 확인하세요.'); return; }
  if (action==='delete' && !confirm('정말 삭제하시겠습니까? 이 작업은 되돌릴 수 없습니다.')) return;
  try{
    const res = await fetch('admin_action.php', {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({action, rel, token})
    });
    const data = await res.json();
    if (!data.ok) throw new Error(data.error || '실패');
    alert(data.message || '완료');
    location.reload();
  }catch(err){
    alert('오류: ' + err.message);
  }
}

document.querySelectorAll('.btn-archive').forEach(btn=>{
  btn.addEventListener('click', (e)=>{
    e.preventDefault();
    const mode = e.target.getAttribute('data-mode') || 'archive';
    callAction(mode, e.target.getAttribute('data-rel'));
  });
});
document.querySelectorAll('.btn-delete').forEach(btn=>{
  btn.addEventListener('click', (e)=>{
    e.preventDefault();
    callAction('delete', e.target.getAttribute('data-rel'));
  });
});
</script>

</body>
</html>

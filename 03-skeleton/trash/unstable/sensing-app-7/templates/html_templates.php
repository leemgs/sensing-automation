
<?php
function category_banner(string $group, string $cat): string {
    $label = strtoupper($group) . ' / ' . strtoupper($cat);
    $color = '#999';
    if ($group==='regulation') {
      if ($cat==='governance') $color='#0a7';
      elseif ($cat==='contract') $color='#07a';
      elseif ($cat==='lawsuit') $color='#a00';
    } else {
      if ($cat==='data') $color='#750';
      elseif ($cat==='model') $color='#570';
      elseif ($cat==='agent') $color='#057';
    }
    return '<div style="padding:8px 12px;border-left:8px solid '.$color.';background:#f9f9f9;margin:10px 0;border-radius:8px"><b>'.$label.'</b></div>';
}
?>

<?php
// templates/html_templates.php
declare(strict_types=1);

function render_regulation_html(array $reg): string {
    $cat = $reg['category'] ?? 'unknown';
    $f = $reg['fields'] ?? [];
    ob_start();
    ?>
<!doctype html>
<html lang="ko">
<head>
  <meta charset="utf-8">
  <title><?= htmlspecialchars("AI 규제 - $cat") ?></title>
  <link rel="stylesheet" href="public/style.css">
</head>
<body>
  <h1>AI 규제</h1><?= category_banner('regulation', $cat) ?>
  <section>
    <?php if ($cat === 'governance'): ?>
      <h2><?= htmlspecialchars($f['title'] ?? '') ?></h2>
      <ul>
        <li><b>제공자:</b> <?= htmlspecialchars($f['provider'] ?? '') ?></li>
        <li><b>제공처:</b> <?= htmlspecialchars($f['source'] ?? '') ?></li>
        <li><b>발생일자:</b> <?= htmlspecialchars($f['date'] ?? '') ?></li>
      </ul>
      <h3>요약 (한 줄)</h3><p><?= nl2br(htmlspecialchars($f['summary_one_liner'] ?? '')) ?></p>
      <h3>요점 포인트</h3>
      <ol>
        <?php foreach (($f['key_points'] ?? []) as $p): ?>
          <li><?= htmlspecialchars($p) ?></li>
        <?php endforeach; ?>
      </ol>
      <h3>개요</h3><p><?= nl2br(htmlspecialchars($f['overview'] ?? '')) ?></p>
      <h3>배경</h3><p><?= nl2br(htmlspecialchars($f['background'] ?? '')) ?></p>
      <h3>결론</h3><p><?= nl2br(htmlspecialchars($f['conclusion'] ?? '')) ?></p>
      <h3>파급효과</h3><p><?= nl2br(htmlspecialchars($f['impact'] ?? '')) ?></p>
      <h3>시사점</h3><p><?= nl2br(htmlspecialchars($f['insights'] ?? '')) ?></p>
      <?php if (!empty($f['links'])): ?>
        <h3>관련 링크</h3><ul>
          <?php foreach ($f['links'] as $L): ?>
            <li><a target="_blank" href="<?= htmlspecialchars($L) ?>"><?= htmlspecialchars($L) ?></a></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    <?php elseif ($cat === 'lawsuit'): ?>
      <ul>
        <li><b>소송 날짜:</b> <?= htmlspecialchars($f['lawsuit_date'] ?? '') ?></li>
        <li><b>소송번호:</b> <?= htmlspecialchars($f['case_number'] ?? '') ?></li>
        <li><b>소송 유형:</b> <?= htmlspecialchars($f['case_type'] ?? '') ?></li>
        <li><b>원고:</b> <?= htmlspecialchars($f['plaintiff'] ?? '') ?></li>
        <li><b>원고측 변호사:</b> <?= htmlspecialchars($f['plaintiff_attorney'] ?? '') ?></li>
        <li><b>피고:</b> <?= htmlspecialchars($f['defendant'] ?? '') ?></li>
        <li><b>피고측 변호사:</b> <?= htmlspecialchars($f['defendant_attorney'] ?? '') ?></li>
        <li><b>법원:</b> <?= htmlspecialchars($f['court'] ?? '') ?></li>
        <li><b>소송 대상 제품/데이터:</b> <?= htmlspecialchars($f['target_product_or_data'] ?? '') ?></li>
        <li><b>소송 이유:</b> <?= htmlspecialchars($f['cause'] ?? '') ?></li>
        <li><b>소송 금액:</b> <?= htmlspecialchars($f['claim_amount'] ?? '') ?></li>
        <li><b>Tracker:</b> <?= htmlspecialchars($f['tracker'] ?? '') ?></li>
        <li><b>진행현황:</b> <?= htmlspecialchars($f['status'] ?? '') ?></li>
        <li><b>비고:</b> <?= htmlspecialchars($f['note'] ?? '') ?></li>
      </ul>
      <?php if (!empty($f['links'])): ?>
        <h3>관련 링크</h3><ul>
          <?php foreach ($f['links'] as $L): ?>
            <li><a target="_blank" href="<?= htmlspecialchars($L) ?>"><?= htmlspecialchars($L) ?></a></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    <?php else: /* contract */ ?>
      <h2><?= htmlspecialchars($f['title'] ?? '') ?></h2>
      <ul>
        <li><b>계약일자:</b> <?= htmlspecialchars($f['contract_date'] ?? '') ?></li>
        <li><b>계약 유형:</b> <?= htmlspecialchars($f['contract_type'] ?? '') ?></li>
        <li><b>계약 데이터:</b> <?= htmlspecialchars($f['contract_data'] ?? '') ?></li>
        <li><b>계약 금액:</b> <?= htmlspecialchars($f['contract_amount'] ?? '') ?></li>
        <li><b>데이터 타입:</b> <?= htmlspecialchars($f['data_type'] ?? '') ?></li>
        <li><b>공급자:</b> <?= htmlspecialchars($f['supplier'] ?? '') ?></li>
        <li><b>구매자:</b> <?= htmlspecialchars($f['buyer'] ?? '') ?></li>
        <li><b>적용 대상:</b> <?= htmlspecialchars($f['applicable_scope'] ?? '') ?></li>
        <li><b>진행현황:</b> <?= htmlspecialchars($f['status'] ?? '') ?></li>
      </ul>
      <h3>개요</h3><p><?= nl2br(htmlspecialchars($f['overview'] ?? '')) ?></p>
      <?php if (!empty($f['links'])): ?>
        <h3>관련 링크</h3><ul>
          <?php foreach ($f['links'] as $L): ?>
            <li><a target="_blank" href="<?= htmlspecialchars($L) ?>"><?= htmlspecialchars($L) ?></a></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    <?php endif; ?>
  </section>
  <footer><small>Generated at <?= date('Y-m-d H:i') ?> KST</small></footer>
</body>
</html>
<?php
    return ob_get_clean();
}

function render_asset_html(array $asset): string {
    $cat = $asset['category'] ?? 'unknown';
    $f = $asset['fields'] ?? [];
    ob_start();
    ?>
<!doctype html>
<html lang="ko">
<head>
  <meta charset="utf-8">
  <title><?= htmlspecialchars("AI 에셋 - $cat") ?></title>
  <link rel="stylesheet" href="public/style.css">
</head>
<body>
  <h1>AI 에셋</h1><?= category_banner('asset', $cat) ?>
  <section>
    <?php if ($cat === 'data'): ?>
      <ul>
        <li><b>제공자:</b> <?= htmlspecialchars($f['provider'] ?? '') ?></li>
        <li><b>공개 일자:</b> <?= htmlspecialchars($f['release_date'] ?? '') ?></li>
        <li><b>데이터셋 명칭:</b> <?= htmlspecialchars($f['dataset_name'] ?? '') ?></li>
        <li><b>라이센스:</b> <?= htmlspecialchars($f['license'] ?? '') ?></li>
        <li><b>수집방법:</b> <?= htmlspecialchars($f['collection_method'] ?? '') ?></li>
      </ul>
      <h3>데이터셋 정보</h3><p><?= nl2br(htmlspecialchars($f['dataset_info'] ?? '')) ?></p>
      <h3>파급효과</h3><p><?= nl2br(htmlspecialchars($f['impact'] ?? '')) ?></p>
      <h3>시사점</h3><p><?= nl2br(htmlspecialchars($f['insights'] ?? '')) ?></p>
      <?php if (!empty($f['links'])): ?>
        <h3>관련 주소</h3><ul>
          <?php foreach ($f['links'] as $L): ?>
            <li><a target="_blank" href="<?= htmlspecialchars($L) ?>"><?= htmlspecialchars($L) ?></a></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>

    <?php elseif ($cat === 'model'): ?>
      <ul>
        <li><b>제공자:</b> <?= htmlspecialchars($f['provider'] ?? '') ?></li>
        <li><b>공개 일자:</b> <?= htmlspecialchars($f['release_date'] ?? '') ?></li>
        <li><b>모델 명칭:</b> <?= htmlspecialchars($f['model_name'] ?? '') ?></li>
        <li><b>라이센스:</b> <?= htmlspecialchars($f['license'] ?? '') ?></li>
        <li><b>상업적 사용:</b> <?= htmlspecialchars($f['commercial_use'] ?? '') ?></li>
        <li><b>필요 GPU VRAM:</b> <?= htmlspecialchars($f['required_gpu_vram'] ?? '') ?></li>
      </ul>
      <h3>요약 (한 줄)</h3><p><?= nl2br(htmlspecialchars($f['one_liner'] ?? '')) ?></p>
      <h3>요점 포인트</h3>
      <ol>
        <?php foreach (($f['key_points'] ?? []) as $p): ?>
          <li><?= htmlspecialchars($p) ?></li>
        <?php endforeach; ?>
      </ol>
      <h3>파급효과</h3><p><?= nl2br(htmlspecialchars($f['impact'] ?? '')) ?></p>
      <h3>시사점</h3><p><?= nl2br(htmlspecialchars($f['insights'] ?? '')) ?></p>
      <?php if (!empty($f['links'])): ?>
        <h3>관련 주소</h3><ul>
          <?php foreach ($f['links'] as $L): ?>
            <li><a target="_blank" href="<?= htmlspecialchars($L) ?>"><?= htmlspecialchars($L) ?></a></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>

    <?php else: /* agent */ ?>
      <ul>
        <li><b>제공자:</b> <?= htmlspecialchars($f['provider'] ?? '') ?></li>
        <li><b>공개 일자:</b> <?= htmlspecialchars($f['release_date'] ?? '') ?></li>
        <li><b>Agent 명칭:</b> <?= htmlspecialchars($f['agent_name'] ?? '') ?></li>
        <li><b>Agent 프레임워크:</b> <?= htmlspecialchars($f['framework'] ?? '') ?></li>
        <li><b>프레임워크 라이센스:</b> <?= htmlspecialchars($f['framework_license'] ?? '') ?></li>
      </ul>
      <h3>Agent 정보</h3><p><?= nl2br(htmlspecialchars($f['agent_info'] ?? '')) ?></p>
      <h3>파급효과</h3><p><?= nl2br(htmlspecialchars($f['impact'] ?? '')) ?></p>
      <h3>시사점</h3><p><?= nl2br(htmlspecialchars($f['insights'] ?? '')) ?></p>
      <?php if (!empty($f['links'])): ?>
        <h3>관련 주소</h3><ul>
          <?php foreach ($f['links'] as $L): ?>
            <li><a target="_blank" href="<?= htmlspecialchars($L) ?>"><?= htmlspecialchars($L) ?></a></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    <?php endif; ?>
  </section>
  <footer><small>Generated at <?= date('Y-m-d H:i') ?> KST</small></footer>
</body>
</html>
<?php
    return ob_get_clean();
}

<?php
// analyze_email.php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/article_fetch.php';
require_once __DIR__ . '/lib/llm.php';
require_once __DIR__ . '/templates/html_templates.php';

$urls = $_POST['urls'] ?? [];
if (!$urls || !is_array($urls)) {
    die('<p>선택된 링크가 없습니다. <a href="index.php">돌아가기</a></p>');
}

$results = [];
foreach ($urls as $url) {
    $url = trim($url);
    if ($url === '') continue;

    try {
        $text = fetch_url_text($url);
        $title = $url;
        if (!$text) {
            // 수집 실패 시 최소 텍스트로라도 분석
            $text = '수집 실패로 인해 본문을 가져오지 못했습니다. URL과 앵커 텍스트를 기반으로 분석해주세요.';
        }
        $parsed = llm_analyze_article($title, $url, $text);

        // 저장: regulation
        $reg = $parsed['regulation'] ?? null;
        $asset = $parsed['asset'] ?? null;

        $saved = [];
        if (is_array($reg) && isset($reg['category'])) {
            $cat = $reg['category'];
            if (!in_array($cat, ['governance','contract','lawsuit'], true)) $cat = 'governance';
            $html = render_regulation_html($reg);
            [$ok, $path] = save_html_file($SENSING_BASE, 'regulation', $cat, $html, $url);
            $saved['regulation'] = $ok ? $path : null;
        }
        if (is_array($asset) && isset($asset['category'])) {
            $cat = $asset['category'];
            if (!in_array($cat, ['data','model','agent'], true)) $cat = 'agent';
            $html = render_asset_html($asset);
            [$ok, $path] = save_html_file($SENSING_BASE, 'asset', $cat, $html, $url);
            $saved['asset'] = $ok ? $path : null;
        }
        $results[] = ['url'=>$url, 'saved'=>$saved, 'ok'=>true, 'error'=>null];
    } catch (Throwable $e) {
        log_msg($LOG_FILE, "Analyze error for $url : " . $e->getMessage());
        $results[] = ['url'=>$url, 'saved'=>[], 'ok'=>false, 'error'=>$e->getMessage()];
    }
}
?>
<!doctype html>
<html lang="ko">
<head>
  <meta charset="utf-8">
  <title>분석 결과</title>
  <link rel="stylesheet" href="public/style.css">
</head>
<body>
  <a class="btn" href="index.php">← 목록</a>
  <h1>분석 결과</h1>

  <table>
    <thead><tr><th>#</th><th>URL</th><th>규제 파일</th><th>에셋 파일</th><th>상태</th></tr></thead>
    <tbody>
      <?php foreach ($results as $i => $r): ?>
        <tr>
          <td><?= $i+1 ?></td>
          <td><a target="_blank" href="<?= htmlspecialchars($r['url']) ?>"><?= htmlspecialchars($r['url']) ?></a></td>
          <td>
            <?php if (!empty($r['saved']['regulation'])): ?>
              <a target="_blank" href="<?= htmlspecialchars($r['saved']['regulation']) ?>"><?= htmlspecialchars(basename($r['saved']['regulation'])) ?></a>
            <?php else: ?>
              <em>-</em>
            <?php endif; ?>
          </td>
          <td>
            <?php if (!empty($r['saved']['asset'])): ?>
              <a target="_blank" href="<?= htmlspecialchars($r['saved']['asset']) ?>"><?= htmlspecialchars(basename($r['saved']['asset'])) ?></a>
            <?php else: ?>
              <em>-</em>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($r['ok']): ?>
              ✅ 성공
            <?php else: ?>
              ❌ 실패: <?= htmlspecialchars($r['error']) ?>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <p><small>저장 경로는 권한 상황에 따라 <code>/var/www/html/sensing</code> 또는 <code>./sensing_out</code>입니다.</small></p>
</body>
</html>

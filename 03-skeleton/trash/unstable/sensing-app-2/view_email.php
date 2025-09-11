<?php
// view_email.php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/functions.php';

$uid = isset($_GET['uid']) ? $_GET['uid'] : null;
if (!$uid) die('uid required.');

$mbox = @imap_open("{" . $IMAP_HOST . ":" . $IMAP_PORT . $IMAP_ENCRYPTION . "}INBOX", $IMAP_USER, $IMAP_PASS);
if (!$mbox) {
    $err = imap_last_error();
    die("<h1>IMAP 연결 실패</h1><p>" . htmlspecialchars($err) . "</p>");
}

$overview = imap_fetch_overview($mbox, (string)$uid, FT_UID);
$subject = $overview && isset($overview[0]->subject) ? imap_utf8($overview[0]->subject) : '(제목 없음)';
$from = $overview && isset($overview[0]->from) ? $overview[0]->from : '';
$date = $overview && isset($overview[0]->date) ? $overview[0]->date : '';

// 본문(MIME) 단순화: html > plain
$body = imap_fetchbody($mbox, (string)$uid, "1", FT_UID);
$structure = imap_fetchstructure($mbox, (string)$uid, FT_UID);
$html = '';
if ($structure && isset($structure->parts) && is_array($structure->parts)) {
    // 파트 스캔
    foreach ($structure->parts as $idx => $part) {
        $section = (string)($idx+1);
        $partBody = imap_fetchbody($mbox, (string)$uid, $section, FT_UID);
        $encoding = $part->encoding ?? 0;
        if ($encoding == 3) $partBody = base64_decode($partBody);
        elseif ($encoding == 4) $partBody = quoted_printable_decode($partBody);

        if (isset($part->subtype) && strtoupper($part->subtype) === 'HTML') {
            $html = $partBody;
            break;
        } elseif (isset($part->subtype) && strtoupper($part->subtype) === 'PLAIN' && $html === '') {
            $html = nl2br(htmlspecialchars($partBody));
        }
    }
} else {
    // 단일 파트
    $encoding = $structure->encoding ?? 0;
    if ($encoding == 3) $body = base64_decode($body);
    elseif ($encoding == 4) $body = quoted_printable_decode($body);
    $html = nl2br(htmlspecialchars($body));
}
imap_close($mbox);

// 링크 추출
$links = extract_links_from_html($html);
?>
<!doctype html>
<html lang="ko">
<head>
  <meta charset="utf-8">
  <title>메일 보기</title>
  <link rel="stylesheet" href="public/style.css">
</head>
<body>
  <a class="btn" href="index.php">← 목록</a>
  <h1><?= htmlspecialchars($subject) ?></h1>
  <p><b>From:</b> <?= htmlspecialchars($from) ?> &nbsp; <b>Date:</b> <?= htmlspecialchars($date) ?></p>
  <hr>
  <h2>본문</h2>
  <div style="white-space:normal;"><?= $html ?></div>

  <hr>
  <h2>추출된 기사 링크 (<?= count($links) ?>)</h2>
  <?php if (!$links): ?>
    <p>링크가 발견되지 않았습니다.</p>
  <?php else: ?>
    <form method="post" action="analyze_email.php">
      <input type="hidden" name="uid" value="<?= htmlspecialchars($uid) ?>">
      <table>
        <thead><tr><th>#</th><th>링크</th><th>링크텍스트</th><th>선택</th></tr></thead>
        <tbody>
          <?php foreach ($links as $i => $L): ?>
          <tr>
            <td><?= $i+1 ?></td>
            <td><a target="_blank" href="<?= htmlspecialchars($L['url']) ?>"><?= htmlspecialchars($L['url']) ?></a></td>
            <td><?= htmlspecialchars($L['text'] ?: '') ?></td>
            <td><input type="checkbox" name="urls[]" value="<?= htmlspecialchars($L['url']) ?>" checked></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <p>
        <button class="btn" type="submit">선택 링크 분석 & 저장</button>
      </p>
    </form>
  <?php endif; ?>
</body>
</html>

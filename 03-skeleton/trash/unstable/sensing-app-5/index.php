<?php
// index.php
require_once __DIR__ . '/config.php';

$mbox = @imap_open("{" . $IMAP_HOST . ":" . $IMAP_PORT . $IMAP_ENCRYPTION . "}INBOX", $IMAP_USER, $IMAP_PASS);
if (!$mbox) {
    $err = imap_last_error();
    die("<h1>IMAP 연결 실패</h1><p>" . htmlspecialchars($err) . "</p><p>config.php의 IMAP 설정을 확인하세요.</p>");
}

$emails = @imap_search($mbox, 'ALL', SE_UID);
$rows = [];
if ($emails) {
    // 최근 메일이 위로
    rsort($emails);
    // 최대 50개
    $emails = array_slice($emails, 0, 50);
    foreach ($emails as $uid) {
        $ov = imap_fetch_overview($mbox, $uid, FT_UID);
        if (!$ov || !isset($ov[0])) continue;
        $o = $ov[0];
        $from = isset($o->from) ? htmlspecialchars($o->from) : '';
        $subject = isset($o->subject) ? htmlspecialchars(imap_utf8($o->subject)) : '(제목 없음)';
        $date = isset($o->date) ? htmlspecialchars($o->date) : '';
        $rows[] = ['uid'=>$uid, 'from'=>$from, 'subject'=>$subject, 'date'=>$date];
    }
}
imap_close($mbox);
?>
<!doctype html>
<html lang="ko">
<head>
  <meta charset="utf-8">
  <title>Gmail IMAP 센싱</title>
  <link rel="stylesheet" href="public/style.css">
</head>
<body>
  <h1>Gmail IMAP 센싱 — 받은편지함</h1>
  <table>
    <thead><tr><th>보낸사람</th><th>제목</th><th>날짜</th></tr></thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
      <tr onclick="location.href='view_email.php?uid=<?= $r['uid'] ?>'">
        <td><?= $r['from'] ?></td>
        <td><a href="view_email.php?uid=<?= $r['uid'] ?>"><?= $r['subject'] ?></a></td>
        <td><?= $r['date'] ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</body>
</html>

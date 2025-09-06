<?php
require __DIR__ . '/config.php';
require __DIR__ . '/imap_client.php';

$cfg = require __DIR__ . '/config.php';

$uid  = $_GET['uid']  ?? '';
$part = $_GET['part'] ?? '1';

if ($uid === '') { http_response_code(400); echo 'uid 필요'; exit; }

try {
    $imap = open_mailbox($cfg);
    $msgno = imap_msgno($imap, (int)$uid);
    if (!$msgno) throw new RuntimeException('메시지를 찾을 수 없음');

    $body = imap_fetchbody($imap, $msgno, $part);
    if ($body === false) throw new RuntimeException('본문/첨부 로드 실패');

    $decoded = base64_decode($body, true);
    if ($decoded === false) {
        $decoded = quoted_printable_decode($body);
    }

    $ov = imap_fetch_overview($imap, (int)$uid, FT_UID)[0] ?? null;
    $filename = 'attachment.bin';
    if ($ov && !empty($ov->subject)) $filename = 'attachment_' . preg_replace('/\W+/', '_', $ov->subject) . '.bin';

    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($decoded));
    echo $decoded;

    imap_close($imap);
} catch (Throwable $e) {
    http_response_code(500);
    echo '에러: ' . $e->getMessage();
}

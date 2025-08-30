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

    $structure = imap_fetchstructure($imap, $msgno);
    if (!$structure) throw new RuntimeException('구조 없음');

    $body = imap_fetchbody($imap, $msgno, $part);
    if ($body === false) throw new RuntimeException('본문/첨부 로드 실패');

    // 파트 찾아 파일명/인코딩 파악
    // 간단 탐색(정확도를 위해 구조 트래버스 생략: collect_attachments()에서 part 제공 가정)
    $encoding = ENCBASE64;
    $filename = 'attachment.bin';
    // 포괄적으로 헤더에서 filename 추출 시도
    $ov = imap_fetch_overview($imap, (int)$uid, FT_UID)[0] ?? null;
    if ($ov && !empty($ov->subject)) $filename = 'attachment_' . preg_replace('/\W+/','_', $ov->subject) . '.bin';

    $decoded = base64_decode($body, true);
    if ($decoded === false) {
        $decoded = quoted_printable_decode($body);
    }

    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($decoded));
    echo $decoded;

    imap_close($imap);
} catch (Throwable $e) {
    http_response_code(500);
    echo '에러: ' . $e->getMessage();
}

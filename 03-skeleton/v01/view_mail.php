<?php
header('Content-Type: application/json; charset=UTF-8');
require __DIR__ . '/imap_client.php';
$cfg = require __DIR__ . '/config.php';

$uid = isset($_GET['uid']) ? (int)$_GET['uid'] : 0;
if ($uid <= 0) {
    http_response_code(400);
    echo json_encode(['ok'=>false, 'error'=>'uid 파라미터가 필요합니다.']);
    exit;
}

try {
    $imap = open_mailbox($cfg);
    $overview = imap_fetch_overview($imap, (string)$uid, FT_UID)[0] ?? null;
    if (!$overview) {
        throw new RuntimeException('해당 UID의 메일을 찾을 수 없습니다.');
    }
    $msgno = imap_msgno($imap, $uid);

    $from    = isset($overview->from) ? decode_mime_str($overview->from) : '';
    $subject = isset($overview->subject) ? decode_mime_str($overview->subject) : '(No Subject)';
    $date    = $overview->date ?? '';
    $seen    = !empty($overview->seen);

    // HTML 우선, 없으면 text/plain을 HTML로 변환
    $body_html = get_body_html_or_text($imap, $msgno);

    if ($cfg['mark_as_seen'] && !$seen) {
        imap_setflag_full($imap, (string)$uid, "\Seen", ST_UID);
        $seen = true;
    }

    imap_close($imap);
    echo json_encode([
        'ok' => true,
        'message' => [
            'uid' => $uid,
            'from' => $from,
            'subject' => $subject,
            'date' => $date,
            'seen' => $seen,
            'body_html' => $body_html,
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

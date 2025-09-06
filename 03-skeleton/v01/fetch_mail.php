<?php
header('Content-Type: application/json; charset=UTF-8');

require __DIR__ . '/imap_client.php';
$cfg = require __DIR__ . '/config.php';

try {
    $imap = open_mailbox($cfg);

    // 최근 7일 내 메일만 필터(원하면 ALL로 변경)
    $criteria = 'SINCE "' . date('d-M-Y', strtotime('-7 days')) . '"';
    $ids = imap_search($imap, $criteria, SE_UID);

    $result = [];
    if ($ids) {
        // 최신순 정렬
        rsort($ids);

        $count = 0;
        foreach ($ids as $uid) {
            if ($count >= $cfg['max_messages']) break;

            $overview = imap_fetch_overview($imap, $uid, FT_UID)[0] ?? null;
            if (!$overview) continue;

            $msgno = imap_msgno($imap, $uid);

            $from    = isset($overview->from) ? decode_mime_str($overview->from) : '';
            $subject = isset($overview->subject) ? decode_mime_str($overview->subject) : '(No Subject)';
            $date    = $overview->date ?? '';
            $seen    = !empty($overview->seen);

            // 본문 일부(스니펫)
            $body = get_body_prefer_text($imap, $msgno);
            $snippet = mb_substr(preg_replace('/\s+/u', ' ', $body), 0, 160, 'UTF-8');

            if ($cfg['mark_as_seen'] && !$seen) {
                imap_setflag_full($imap, (string)$uid, "\\Seen", ST_UID);
                $seen = true;
            }

            $result[] = [
                'uid'     => $uid,
                'from'    => $from,
                'subject' => $subject,
                'date'    => $date,
                'seen'    => $seen,
                'snippet' => $snippet,
            ];
            $count++;
        }
    }

    imap_close($imap);
    echo json_encode([
        'ok' => true,
        'refreshed_at' => date('c'),
        'messages' => $result,
        'poll_interval_seconds' => $cfg['poll_interval'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

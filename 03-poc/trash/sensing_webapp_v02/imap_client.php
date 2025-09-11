<?php
declare(strict_types=1);

/** IMAP 연결 */
function imap_connect(string $server, string $user, string $pass, string $folder='INBOX') {
    $mailbox = "{".$server.":993/imap/ssl}".$folder;
    return @imap_open($mailbox, $user, $pass);
}

/** 최신 메일 목록 */
function imap_list_latest($imap, int $limit=50): array {
    $out = [];
    $ids = @imap_search($imap, 'ALL');
    if (!$ids) return $out;
    rsort($ids);
    $ids = array_slice($ids, 0, $limit);
    foreach ($ids as $num) {
        $ov = @imap_fetch_overview($imap, (string)$num, 0);
        if (!$ov || !isset($ov[0])) continue;
        $o = $ov[0];
        $out[] = [
            'num' => $num,
            'subject' => isset($o->subject) ? imap_utf8($o->subject) : '(제목 없음)',
            'from' => isset($o->from) ? imap_utf8($o->from) : '(보낸사람 없음)',
            'date' => isset($o->date) ? $o->date : '',
        ];
    }
    return $out;
}

/** 본문 가져오기 (text/plain + text/html에서 텍스트 취득) */
function imap_fetch_text($imap, int $num): string {
    $structure = @imap_fetchstructure($imap, $num);
    $body = '';
    if ($structure && isset($structure->parts)) {
        $parts = $structure->parts;
        for ($i=1; $i<=count($parts); $i++) {
            $part = $parts[$i-1];
            $isText = (isset($part->subtype) && (strtoupper($part->subtype)==='PLAIN' || strtoupper($part->subtype)==='HTML'));
            if ($isText) {
                $b = imap_fetchbody($imap, $num, (string)$i);
                if ($part->encoding == 3) $b = base64_decode($b);
                if ($part->encoding == 4) $b = quoted_printable_decode($b);
                $body .= "\n\n".strip_tags($b);
            }
        }
    } else {
        $b = imap_body($imap, $num);
        $body = quoted_printable_decode($b);
    }
    $body = trim($body);
    return $body === '' ? '(본문 없음)' : $body;
}

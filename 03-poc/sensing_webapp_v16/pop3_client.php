<?php
declare(strict_types=1);

/**
 * POP3 client implemented via PHP IMAP extension using POP3 mailbox syntax.
 * Provides the same function signatures as imap_client.php for listing.
 */

function imap_connect(string $server, string $user, string $pass, string $folder='INBOX') {
    // POP3 typically uses 995/ssl and only INBOX
    $mailbox = "{".$server.":995/pop3/ssl}INBOX";
    return @imap_open($mailbox, $user, $pass);
}

function imap_list_page($imap, int $limit=10, int $page=1): array {
    $out = ['total'=>0, 'items'=>[]];
    $total = @imap_num_msg($imap);
    if (!$total) return $out;
    $out['total'] = (int)$total;

    // POP3 message numbers are 1..N, newest is often highest number but not guaranteed; we approximate by descending
    $ids = range(1, (int)$total);
    rsort($ids);
    $offset = max(0, ($page-1)*$limit);
    $slice = array_slice($ids, $offset, $limit);

    $i = 0;
    foreach ($slice as $num) {
        $ov = @imap_fetch_overview($imap, (string)$num, 0);
        if (!$ov || !isset($ov[0])) continue;
        $o = $ov[0];
        $uid = 0; // POP3 has no UID in the same way; keep 0 for compatibility
        $out['items'][] = [
            'idx' => $offset + (++$i),
            'num' => $num,
            'uid' => $uid,
            'subject' => isset($o->subject) ? imap_utf8($o->subject) : '(제목 없음)',
            'from' => isset($o->from) ? imap_utf8($o->from) : '(보낸사람 없음)',
            'date' => isset($o->date) ? $o->date : '',
            'is_new' => (isset($o->seen) ? !$o->seen : true),
        ];
    }
    return $out;
}

// For message body fetching, reuse the same functions as imap_client.php names if needed:
function imap_fetch_best($imap, int $num): array {
    $structure = @imap_fetchstructure($imap, $num);
    $html = null; $text = null;

    if ($structure && isset($structure->parts)) {
        $parts = $structure->parts;
        for ($i=1; $i<=count($parts); $i++) {
            $p = $parts[$i-1];
            $body = @imap_fetchbody($imap, $num, (string)$i);
            if (!$body) continue;
            $encoding = $p->encoding ?? 0;
            if ($encoding == 3) $body = base64_decode($body);
            elseif ($encoding == 4) $body = quoted_printable_decode($body);

            $type = ($p->subtype ?? '') ? strtoupper($p->subtype) : '';
            if ($type === 'HTML' && $html === null) $html = $body;
            if ($type === 'PLAIN' && $text === null) $text = $body;
        }
    } else {
        $body = @imap_body($imap, $num, FT_PEEK);
        $html = $body;
    }
    return [$html, $text];
}


/**
 * POP3: delete messages immediately (no Trash folder).
 * Returns (array $deletedNums, string $destUsed='DELETED')
 */
function imap_move_to_trash($imap, array $nums): array {
    $deleted = [];
    foreach ($nums as $n) {
        $n = (int)$n;
        if ($n <= 0) continue;
        if (@imap_delete($imap, (string)$n)) {
            $deleted[] = $n;
        }
    }
    @imap_expunge($imap); // finalize deletions
    return [$deleted, 'DELETED'];
}

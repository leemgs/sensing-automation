<?php
declare(strict_types=1);

function imap_connect(string $server, string $user, string $pass, string $folder='INBOX') {
    $mailbox = "{".$server.":993/imap/ssl}".$folder;
    return @imap_open($mailbox, $user, $pass);
}

function imap_list_page($imap, int $limit=10, int $page=1): array {
    $out = ['total'=>0, 'items'=>[]];
    $ids = @imap_search($imap, 'ALL');
    if (!$ids) return $out;
    rsort($ids);
    $out['total'] = count($ids);
    $offset = max(0, ($page-1)*$limit);
    $slice = array_slice($ids, $offset, $limit);
    $i = 0;
    foreach ($slice as $num) {
        $ov = @imap_fetch_overview($imap, (string)$num, 0);
        if (!$ov || !isset($ov[0])) continue;
        $o = $ov[0];
        $uid = @imap_uid($imap, (int)$num);
        $out['items'][] = [
            'idx' => $offset + (++$i),
            'num' => $num,
            'uid' => $uid ?: 0,
            'subject' => isset($o->subject) ? imap_utf8($o->subject) : '(제목 없음)',
            'from' => isset($o->from) ? imap_utf8($o->from) : '(보낸사람 없음)',
            'date' => isset($o->date) ? $o->date : '',
            'is_new' => (isset($o->seen) ? !$o->seen : true),
        ];
    }
    return $out;
}

function imap_fetch_best($imap, int $num): array {
    $structure = @imap_fetchstructure($imap, $num);
    $html = null; $text = null;

    if ($structure && isset($structure->parts)) {
        $parts = $structure->parts;
        for ($i=1; $i<=count($parts); $i++) {
            $part = $parts[$i-1];
            $sub = isset($part->subtype) ? strtoupper($part->subtype) : '';
            if ($sub === 'HTML' || $sub === 'PLAIN') {
                $b = imap_fetchbody($imap, $num, (string)$i);
                if ($part->encoding == 3) $b = base64_decode($b);
                if ($part->encoding == 4) $b = quoted_printable_decode($b);
                if ($sub === 'HTML' && $html === null) $html = $b;
                if ($sub === 'PLAIN' && $text === null) $text = $b;
            }
        }
    } else {
        $b = imap_body($imap, $num);
        $text = quoted_printable_decode($b);
    }

    if ($html !== null) return ['html', $html];
    $text = trim(strip_tags($text ?? ''));
    if ($text === '') $text = '(본문 없음)';
    return ['text', "<pre>".htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')."</pre>"];
}

function imap_delete_nums($imap, array $nums): array {
    $deleted = [];
    foreach ($nums as $n) {
        $n = intval($n);
        if ($n > 0) {
            if (@imap_delete($imap, (string)$n)) $deleted[] = $n;
        }
    }
    @imap_expunge($imap);
    return $deleted;
}


function imap_move_to_trash($imap, array $nums): array {
    // Try to move messages to a "Trash" mailbox (server-dependent naming)
    $candidates = [
        "[Gmail]/Trash", "[Google Mail]/Trash", 
        "Trash", "INBOX.Trash", 
        "Deleted Items", "Deleted Messages"
    ];
    $moved = [];
    $destUsed = '';

    // Move each message individually so partial success is captured
    foreach ($nums as $n) {
        $n = intval($n);
        if ($n <= 0) continue;
        $ok = false;
        foreach ($candidates as $dest) {
            if (@imap_mail_move($imap, (string)$n, $dest)) {
                $ok = true;
                $destUsed = $dest;
                break;
            }
        }
        if ($ok) { $moved[] = $n; }
    }
    // Apply expunge to finalize move out of source mailbox
    @imap_expunge($imap);
    return [$moved, $destUsed];
}

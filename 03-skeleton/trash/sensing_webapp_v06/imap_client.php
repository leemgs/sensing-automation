<?php
declare(strict_types=1);

function imap_connect(string $server, string $user, string $pass, string $folder='INBOX') {
    $mailbox = "{".$server.":993/imap/ssl}".$folder;
    return @imap_open($mailbox, $user, $pass);
}

function imap_list_latest($imap, int $limit=20): array {
    $out = [];
    $ids = @imap_search($imap, 'ALL');
    if (!$ids) return $out;
    rsort($ids);
    $ids = array_slice($ids, 0, $limit);
    $i = 0;
    foreach ($ids as $num) {
        $ov = @imap_fetch_overview($imap, (string)$num, 0);
        if (!$ov || !isset($ov[0])) continue;
        $o = $ov[0];
        $out[] = [
            'idx' => ++$i,
            'num' => $num,
            'subject' => isset($o->subject) ? imap_utf8($o->subject) : '(제목 없음)',
            'from' => isset($o->from) ? imap_utf8($o->from) : '(보낸사람 없음)',
            'date' => isset($o->date) ? $o->date : '',
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

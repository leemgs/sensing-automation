<?php
declare(strict_types=1);

function imap_connect(string $server, string $user, string $pass, string $folder='INBOX') {
    $mailbox = "{".$server.":993/imap/ssl}".$folder;
    return @imap_open($mailbox, $user, $pass);
}

function decode_part_body($imap, int $num, $part, string $section) {
    $body = imap_fetchbody($imap, $num, $section);
    $enc = isset($part->encoding) ? intval($part->encoding) : 0;
    if ($enc === 3) $body = base64_decode($body);
    elseif ($enc === 4) $body = quoted_printable_decode($body);
    return $body;
}

function extract_parts_recursive($imap, int $num, $structure, string $section=''): array {
    $html = null; $text = null;
    if (!isset($structure->parts) || !is_array($structure->parts) || count($structure->parts)===0) {
        $sub = isset($structure->subtype) ? strtoupper($structure->subtype) : '';
        if ($section === '') {
            $b = imap_body($imap, $num);
            $enc = isset($structure->encoding) ? intval($structure->encoding) : 0;
            if ($enc === 3) $b = base64_decode($b);
            elseif ($enc === 4) $b = quoted_printable_decode($b);
        } else {
            $b = decode_part_body($imap, $num, $structure, $section);
        }
        if ($sub === 'HTML') $html = $b;
        else $text = $b;
        return [$html, $text];
    }

    for ($i=1; $i<=count($structure->parts); $i++) {
        $part = $structure->parts[$i-1];
        $sec = $section === '' ? (string)$i : $section.'.'.$i;
        if (isset($part->type) && intval($part->type) === 2) { // message/rfc822
            if (isset($part->parts) && is_array($part->parts)) {
                $tmp = extract_parts_recursive($imap, $num, $part, $sec);
            } else {
                $b = decode_part_body($imap, $num, $part, $sec);
                $tmp = [null, $b];
            }
        } elseif (isset($part->parts) && is_array($part->parts)) {
            $tmp = extract_parts_recursive($imap, $num, $part, $sec);
        } else {
            $sub = isset($part->subtype) ? strtoupper($part->subtype) : '';
            $b = decode_part_body($imap, $num, $part, $sec);
            $tmp = [$sub==='HTML' ? $b : null, $sub==='PLAIN' ? $b : null];
        }
        if ($tmp[0] !== null && $html === null) $html = $tmp[0];
        if ($tmp[1] !== null && $text === null) $text = $tmp[1];
        if ($html !== null && $text !== null) break;
    }
    return [$html, $text];
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
        ];
    }
    return $out;
}

function imap_fetch_best($imap, int $num): array {
    $structure = @imap_fetchstructure($imap, $num);
    if ($structure) {
        list($html, $text) = extract_parts_recursive($imap, $num, $structure, '');
    } else {
        $b = imap_body($imap, $num);
        $text = quoted_printable_decode($b);
        $html = null;
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

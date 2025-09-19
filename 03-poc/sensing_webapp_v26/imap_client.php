<?php
declare(strict_types=1);

/** ===== Charset/Encoding helpers ===== */
function _best_charset_convert(string $s, ?string $charset): string {
    $charset = strtoupper((string)$charset);
    if ($charset === '' || $charset === 'UTF-8' or $charset === 'UTF8') return $s;
    $aliases = ['KS_C_5601-1987'=>'CP949','X-EUC-KR'=>'EUC-KR','X-GBK'=>'GBK','ISO-2022-JP-MS'=>'ISO-2022-JP'];
    if (isset($aliases[$charset])) $charset = $aliases[$charset];
    if (function_exists('mb_convert_encoding')) {
        $out = @mb_convert_encoding($s, 'UTF-8', $charset);
        if (is_string($out) && $out !== '') return $out;
    }
    if (function_exists('iconv')) {
        $out = @iconv($charset, 'UTF-8//TRANSLIT', $s);
        if (is_string($out) && $out !== '') return $out;
    }
    return $s;
}
function _decode_mime_header(?string $s): string {
    $s = (string)$s;
    if ($s === '') return '';
    $decoded = @imap_mime_header_decode($s);
    if (!is_array($decoded)) return imap_utf8($s);
    $out = '';
    foreach ($decoded as $d) {
        $part = $d->text ?? '';
        $cs = $d->charset ?? 'UTF-8';
        if (strtoupper($cs) === 'DEFAULT') $cs = 'UTF-8';
        $out .= _best_charset_convert($part, $cs);
    }
    return $out;
}
function _decode_part_body(string $body, int $encoding): string {
    if ($encoding == 3) return base64_decode($body);
    if ($encoding == 4) return quoted_printable_decode($body);
    return $body;
}
function _extract_charset($params): ?string {
    if (is_object($params) || is_array($params)) {
        foreach ($params as $k=>$v) {
            $kk = is_string($k) ? strtolower($k) : (is_object($v) && isset($v->attribute) ? strtolower($v->attribute) : '');
            $vv = is_array($params) ? $v : (is_object($v) && isset($v->value) ? $v->value : '');
            if ($kk === 'charset') return (string)$vv;
        }
    }
    return null;
}
function _fetch_best_recursive($imap, int $num, $structure, string $prefix='') {
    $html = null; $text = null;
    if ($structure && isset($structure->parts) && is_array($structure->parts)) {
        for ($i=0; $i<count($structure->parts); $i++) {
            $p = $structure->parts[$i];
            $section = $prefix.($i+1);
            if (isset($p->parts) && is_array($p->parts)) {
                list($h, $t) = _fetch_best_recursive($imap, $num, $p, $section.'.');
                if ($h !== null && $html === null) $html = $h;
                if ($t !== null && $text === null) $text = $t;
                continue;
            }
            $body = @imap_fetchbody($imap, $num, $section ?: '1', FT_PEEK);
            if (!$body) continue;
            $encoding = (int)($p->encoding ?? 0);
            $body = _decode_part_body($body, $encoding);
            $subtype = strtoupper((string)($p->subtype ?? ''));
            $charset = _extract_charset($p->parameters ?? null) ?? _extract_charset($p->dparameters ?? null) ?? 'UTF-8';
            $bodyUtf8 = _best_charset_convert($body, $charset);
            if ($subtype === 'HTML' && $html === null) $html = $bodyUtf8;
            if ($subtype === 'PLAIN' && $text === null) $text = $bodyUtf8;
        }
    } else {
        $body = @imap_body($imap, $num, FT_PEEK);
        if ($body) {
            $encoding = (int)($structure->encoding ?? 0);
            $body = _decode_part_body($body, $encoding);
            $charset = _extract_charset($structure->parameters ?? null) ?? 'UTF-8';
            $bodyUtf8 = _best_charset_convert($body, $charset);
            $subtype = strtoupper((string)($structure->subtype ?? 'HTML'));
            if ($subtype === 'HTML') $html = $bodyUtf8; else $text = $bodyUtf8;
        }
    }
    return [$html, $text];
}


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
            'subject' => isset($o->subject) ? _decode_mime_header($o->subject) : '(제목 없음)',
            'from' => isset($o->from) ? _decode_mime_header($o->from) : '(보낸사람 없음)',
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

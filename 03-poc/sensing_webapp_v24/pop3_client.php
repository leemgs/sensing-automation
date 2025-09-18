<?php
declare(strict_types=1);

function _format_date_kst($raw) {
    if (!$raw) return '';
    // Try to parse RFC2822-like date from overview
    $ts = @strtotime($raw);
    if ($ts === false || $ts <= 0) return $raw;
    // Convert to KST (Asia/Seoul, UTC+9)
    $dt = new DateTime('@'.$ts);
    try {
        $tz = new DateTimeZone('Asia/Seoul');
        $dt->setTimezone($tz);
    } catch (Exception $e) {
        // fallback: manual +9 hours
        $dt = new DateTime('@'.($ts + 9*3600));
    }
    return $dt->format('Y.m.d (D) H:i');
}

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
    $mailbox = "{".$server.":995/pop3/ssl}INBOX";
    return @imap_open($mailbox, $user, $pass);
}


function imap_list_page($imap, int $limit=10, int $page=1): array {
    $out = ['total'=>0, 'items'=>[]];
    $total = @imap_num_msg($imap);
    if (!$total) return $out;
    $out['total'] = (int)$total;

    $ids = range(1, (int)$total);
    rsort($ids);

    $offset = max(0, ($page-1)*$limit);
    $slice = array_slice($ids, $limit > 0 ? $offset : 0, $limit > 0 ? $limit : NULL);

    $i = 0;
    foreach ($slice as $num) {
        $subject = '(제목 없음)';
        $from = '(보낸사람 없음)';
        $dateStr = '';

        $ov = @imap_fetch_overview($imap, (string)$num, 0);
        if ($ov && isset($ov[0])) {
            $o = $ov[0];
            if (isset($o->subject)) $subject = _decode_mime_header($o->subject);
            if (isset($o->from))    $from    = _decode_mime_header($o->from);
            if (isset($o->date))    $dateStr = _format_date_kst($o->date);
        } else {
            $hi = @imap_headerinfo($imap, $num);
            if ($hi) {
                if (isset($hi->subject)) $subject = _decode_mime_header($hi->subject);
                if (isset($hi->fromaddress)) $from = _decode_mime_header($hi->fromaddress);
                if (isset($hi->date)) $dateStr = _format_date_kst($hi->date);
            } else {
                $raw = @imap_fetchheader($imap, $num, FT_PEEK);
                if (is_string($raw) && $raw !== '') {
                    if (preg_match('/^Subject:\s*(.+)$/mi', $raw, $m)) $subject = _decode_mime_header(trim($m[1]));
                    if (preg_match('/^From:\s*(.+)$/mi', $raw, $m))    $from    = _decode_mime_header(trim($m[1]));
                    if (preg_match('/^Date:\s*(.+)$/mi', $raw, $m))    $dateStr = _format_date_kst(trim($m[1]));
                }
            }
        }

        $out['items'][] = [
            'idx' => $offset + (++$i),
            'num' => $num,
            'uid' => (int)$num,
            'subject' => $subject,
            'from' => $from,
            'date' => $dateStr,
            'is_new' => false,
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
            $p = $parts[$i-1];
            $body = @imap_fetchbody($imap, $num, (string)$i, FT_PEEK);
            if (!$body) continue;
            $enc = $p->encoding ?? 0;
            if ($enc == 3) $body = base64_decode($body);
            elseif ($enc == 4) $body = quoted_printable_decode($body);
            $sub = ($p->subtype ?? '') ? strtoupper($p->subtype) : '';
            if ($sub === 'HTML' && $html === null) $html = $body;
            if ($sub === 'PLAIN' && $text === null) $text = $body;
        }
    } else {
        $body = @imap_body($imap, $num, FT_PEEK);
        $html = $body;
    }
    return [$html, $text];
}

/** POP3: delete immediately (no Trash) */
function imap_move_to_trash($imap, array $nums): array {
    $deleted = [];
    foreach ($nums as $n) {
        $n = (int)$n; if ($n <= 0) continue;
        if (@imap_delete($imap, (string)$n)) { $deleted[] = $n; }
    }
    @imap_expunge($imap);
    return [$deleted, 'DELETED'];
}

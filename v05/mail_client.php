<?php
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool {
        return $needle === '' || strpos($haystack, $needle) === 0;
    }
}

function open_mailbox(array $cfg) {
    $protocol = strtolower($cfg['mail_protocol'] ?? 'imap');
    $username = $cfg['username'] ?? '';
    $password = $cfg['password'] ?? '';

    if ($protocol === 'pop3') {
        $host = $cfg['pop3_host'] ?? 'pop.gmail.com';
        $port = (int)($cfg['pop3_port'] ?? 995);
        // POP3 supports only INBOX. Flags: /pop3/ssl
        $mboxStr = sprintf('{%s:%d/pop3/ssl/novalidate-cert}INBOX', $host, $port);
    } else {
        $host    = $cfg['imap_host'] ?? 'imap.gmail.com';
        $port    = (int)($cfg['imap_port'] ?? 993);
        $mailbox = $cfg['mailbox'] ?? 'INBOX';
        $mboxStr = sprintf('{%s:%d/imap/ssl/novalidate-cert}%s', $host, $port, $mailbox);
    }

    $imap = @imap_open($mboxStr, $username, $password, 0, 1, [

        'DISABLE_AUTHENTICATOR' => 'GSSAPI',
    ]);
    if (!$imap) {
        throw new RuntimeException('IMAP 연결 실패: ' . imap_last_error());
    }
    return $imap;
}

function decode_mime_str(string $s): string {
    $out = '';
    foreach (imap_mime_header_decode($s) as $el) {
        $charset = strtoupper($el->charset);
        $text = $el->text;
        if ($charset !== 'DEFAULT') {
            $text = @iconv($charset, 'UTF-8//IGNORE', $text) ?: $text;
        }
        $out .= $text;
    }
    return $out;
}

function get_body_prefer_text($imap, int $msgno): string {
    $structure = imap_fetchstructure($imap, $msgno);
    if (!$structure) {
        $body = imap_body($imap, $msgno);
        return trim(html_entity_decode(strip_tags($body)));
    }
    $plainPart = find_part_by_mime($imap, $msgno, $structure, 'TEXT/PLAIN');
    if ($plainPart !== null) return $plainPart;
    $htmlPart = find_part_by_mime($imap, $msgno, $structure, 'TEXT/HTML');
    if ($htmlPart !== null) return trim(html_entity_decode(strip_tags($htmlPart)));
    $body = imap_body($imap, $msgno);
    return trim(html_entity_decode(strip_tags($body)));
}

function find_part_by_mime($imap, int $msgno, $structure, string $want) {
    $want = strtoupper($want);
    $stack = [ ['part'=>$structure, 'prefix'=>''] ];
    while ($stack) {
        $cur = array_pop($stack);
        $part = $cur['part'];
        $pref = $cur['prefix'];

        $type = strtoupper( ($part->type ?? 0) === 0 ? ('TEXT/'.($part->subtype ?? 'PLAIN')) : '' );
        if ($type === $want) {
            $section = $pref === '' ? '1' : rtrim($pref, '.');
            $text = imap_fetchbody($imap, $msgno, $section);
            if ($text !== false) {
                if (!empty($part->encoding)) $text = decode_part_text($text, (int)$part->encoding);
                return $text;
            }
        }
        if (!empty($part->parts)) {
            $n = 1;
            foreach ($part->parts as $p) {
                $stack[] = ['part'=>$p, 'prefix'=>$pref . $n . '.'];
                $n++;
            }
        }
    }
    return null;
}

function decode_part_text($text, int $encoding) {
    switch ($encoding) {
        case ENCBASE64: return base64_decode($text);
        case ENCQUOTEDPRINTABLE: return quoted_printable_decode($text);
        default: return $text;
    }
}

function parse_x_gm_labels(string $raw_header): string {
    $out = [];
    foreach (preg_split('/\r?\n/', $raw_header) as $line) {
        if (stripos($line, 'X-GM-LABELS:') === 0) {
            if (preg_match('/X-GM-LABELS:\s*\((.*)\)/i', $line, $m)) {
                $inside = $m[1];
                $labels = [];
                $cur = '';
                $inQuote = false;
                $len = strlen($inside);
                for ($i=0; $i<$len; $i++) {
                    $ch = $inside[$i];
                    if ($ch === '"' && ($i===0 || $inside[$i-1] !== '\\')) {
                        if ($inQuote) { $labels[] = stripcslashes($cur); $cur = ''; $inQuote = false; }
                        else { $inQuote = true; }
                    } else {
                        if ($inQuote) { $cur .= $ch; }
                    }
                }
                if (preg_match_all('/\\\\[^\s\)]+/', $inside, $mm)) {
                    foreach ($mm[0] as $t) $labels[] = $t;
                }
                $labels = array_values(array_filter(array_unique($labels)));
                if ($labels) $out = array_merge($out, $labels);
            }
        }
    }
    return implode(', ', array_unique($out));
}

function parse_x_gm_labels_array($raw_header): array {
    $s = parse_x_gm_labels($raw_header);
    if ($s === '') return [];
    $parts = preg_split('/\s*,\s*/u', $s, -1, PREG_SPLIT_NO_EMPTY);
    return array_values(array_filter(array_map('trim', $parts), fn($v)=>$v!==''));
}

function labels_match_filter(array $cfg, array $mailLabels): bool {
    $targets = $cfg['analysis_label_filter'] ?? [];
    if (empty($targets)) return true;
    $mode = strtolower($cfg['analysis_match_mode'] ?? 'any');
    $have = array_map(fn($v)=>mb_strtolower($v,'UTF-8'), $mailLabels);
    $want = array_map(fn($v)=>mb_strtolower(trim($v),'UTF-8'), $targets);

    if ($mode === 'all') {
        foreach ($want as $w) if (!in_array($w, $have, true)) return false;
        return true;
    }
    foreach ($want as $w) if (in_array($w, $have, true)) return true;
    return false;
}

function detect_categories_by_label(array $cfg, array $mailLabels): array {
    $map = $cfg['category_label_map'] ?? [];
    if (!$map || !$mailLabels) return [];
    $mode = strtolower($cfg['category_label_match'] ?? 'exact');
    $have = array_map(fn($v)=>mb_strtolower($v,'UTF-8'), $mailLabels);

    $hit = [];
    foreach (['lawsuit','contract','governance'] as $cat) {
        foreach (($map[$cat] ?? []) as $t) {
            $t = mb_strtolower(trim($t),'UTF-8'); if ($t==='') continue;
            $matched = ($mode==='prefix')
                ? (bool)array_filter($have, fn($h)=>strpos($h,$t)===0)
                : in_array($t, $have, true);
            if ($matched) { $hit[] = $cat; break; }
        }
    }
    return array_values(array_unique($hit));
}

function collect_attachments($imap, int $msgno): array {
    $ret = [];
    $structure = imap_fetchstructure($imap, $msgno);
    if (!$structure) return $ret;
    $stack = [ ['part'=>$structure, 'prefix'=>''] ];
    while ($stack) {
        $cur = array_pop($stack);
        $part = $cur['part'];
        $pref = $cur['prefix'];
        $section = rtrim($pref, '.');

        $filename = null;
        if (!empty($part->dparameters)) {
            foreach ($part->dparameters as $p) {
                if (strtolower($p->attribute) === 'filename') {
                    $filename = decode_mime_str($p->value);
                    break;
                }
            }
        }
        if (!$filename && !empty($part->parameters)) {
            foreach ($part->parameters as $p) {
                if (strtolower($p->attribute) === 'name') {
                    $filename = decode_mime_str($p->value);
                    break;
                }
            }
        }
        if ($filename) {
            $size = isset($part->bytes) ? (int)$part->bytes : 0;
            $$ret[] = [
                'part' => $section === '' ? '1' : $section,
                'filename' => $filename,
                'size' => $size,
            ]);
        }
        if (!empty($part->parts)) {
            $i = 1;
            foreach ($part->parts as $pp) {
                $stack[] = ['part'=>$pp, 'prefix'=>$pref . $i . '.'];
                $i++;
            }
        }
    }
    return $ret;
}

<?php
function open_mailbox(array $cfg)
{
    $mailboxPath = sprintf('{%s:%d/imap/ssl}%s', $cfg['imap_host'], $cfg['imap_port'], $cfg['mailbox']);
    $imap = @imap_open($mailboxPath, $cfg['username'], $cfg['password'], 0, 1, [
        // Gmail과 TLS 유효성 검증을 기본 사용. 문제가 있으면 'novalidate-cert' 옵션 고려.
        'DISABLE_AUTHENTICATOR' => 'GSSAPI',
    ]);

    if (!$imap) {
        throw new RuntimeException('IMAP 연결 실패: ' . imap_last_error());
    }
    return $imap;
}

function decode_mime_str($string, $charset = 'UTF-8')
{
    $elements = imap_mime_header_decode($string);
    $decoded  = '';
    foreach ($elements as $element) {
        $fromCharset = $element->charset;
        $text        = $element->text;
        if ($fromCharset && strtoupper($fromCharset) !== strtoupper($charset) && $fromCharset !== 'default') {
            $decoded .= iconv($fromCharset, $charset . '//TRANSLIT', $text);
        } else {
            $decoded .= $text;
        }
    }
    return $decoded;
}

function get_body_prefer_text($imap, $msgno)
{
    // text/plain 우선, 없으면 text/html에서 태그 제거
    $structure = imap_fetchstructure($imap, $msgno);
    $body = '';

    if (!isset($structure->parts)) {
        // 단일 파트
        $body = imap_body($imap, $msgno);
        return trim(to_utf8($body, $structure->encoding ?? 0));
    }

    $plainPartNo = null;
    $htmlPartNo  = null;

    foreach ($structure->parts as $i => $part) {
        $partNo = $i + 1;
        if ($part->type === 0) { // text
            $subtype = strtolower($part->subtype ?? '');
            if ($subtype === 'plain' && $plainPartNo === null) {
                $plainPartNo = $partNo;
            } elseif ($subtype === 'html' && $htmlPartNo === null) {
                $htmlPartNo = $partNo;
            }
        }
    }

    if ($plainPartNo !== null) {
        $b = imap_fetchbody($imap, $msgno, $plainPartNo);
        return trim(to_utf8($b, $structure->parts[$plainPartNo - 1]->encoding ?? 0));
    }
    if ($htmlPartNo !== null) {
        $b = imap_fetchbody($imap, $msgno, $htmlPartNo);
        $b = to_utf8($b, $structure->parts[$htmlPartNo - 1]->encoding ?? 0);
        $b = strip_tags($b);
        return trim(html_entity_decode($b, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    // fallback
    $body = imap_body($imap, $msgno);
    return trim(to_utf8($body, $structure->encoding ?? 0));
}

function to_utf8($text, $encodingCode)
{
    switch ($encodingCode) {
        case ENC7BIT:    return $text;
        case ENC8BIT:    return quoted_printable_decode($text);
        case ENCBINARY:  return $text;
        case ENCBASE64:  return base64_decode($text);
        case ENCQUOTEDPRINTABLE: return quoted_printable_decode($text);
        case ENCOTHER:   return $text;
        default:         return $text;
    }
}

/**
 * get_body_html_or_text
 * - 가능하면 text/html을 반환(스크립트 제거), 없으면 text/plain을 <br> 변환하여 HTML로 반환
 */
function get_body_html_or_text($imap, $msgno) {
    $structure = imap_fetchstructure($imap, $msgno);

    $strip_scripts = function($html) {
        // Remove <script>...</script>
        $html = preg_replace('#<\s*script[^>]*>.*?<\s*/\s*script\s*>#is', '', $html);
        // Remove event handlers like onclick=, onload= etc.
        $html = preg_replace('/\son\w+\s*=\s*"[^"]*"/i', '', $html);
        $html = preg_replace("/\son\w+\s*=\s*'[^']*'/i", '', $html);
        $html = preg_replace('/\son\w+\s*=\s*[^\s>]+/i', '', $html);
        return $html;
    };

    if (!isset($structure->parts)) {
        $raw = imap_body($imap, $msgno);
        $decoded = to_utf8($raw, $structure->encoding ?? 0);
        // We don't know the subtype, provide safe-ish HTML
        return nl2br(htmlentities($decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    $plainPartNo = null;
    $htmlPartNo  = null;
    foreach ($structure->parts as $i => $part) {
        $mime = strtolower($part->subtype ?? '');
        $type = $part->type ?? 0;
        if ($type === TYPETEXT && $mime === 'plain')  $plainPartNo = $i + 1;
        if ($type === TYPETEXT && $mime === 'html')   $htmlPartNo  = $i + 1;
    }

    if ($htmlPartNo !== null) {
        $b = imap_fetchbody($imap, $msgno, $htmlPartNo);
        $b = to_utf8($b, $structure->parts[$htmlPartNo - 1]->encoding ?? 0);
        return $strip_scripts($b);
    }

    // Fallback to text/plain
    if ($plainPartNo !== null) {
        $b = imap_fetchbody($imap, $msgno, $plainPartNo);
        $b = to_utf8($b, $structure->parts[$plainPartNo - 1]->encoding ?? 0);
        return nl2br(htmlentities($b, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    $raw = imap_body($imap, $msgno);
    $decoded = to_utf8($raw, $structure->encoding ?? 0);
    return nl2br(htmlentities($decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

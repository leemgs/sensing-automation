<?php
// cron_scan.php — 자동 스캐너 (CLI/cron용)
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/article_fetch.php';
require_once __DIR__ . '/lib/llm.php';
require_once __DIR__ . '/templates/html_templates.php';
require_once __DIR__ . '/lib/notify.php';

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "CLI only.\n";
    exit;
}

$lookback_minutes = intval(getenv('SENSING_LOOKBACK_MIN') ?: 180);  // 최근 3시간
$max_emails       = intval(getenv('SENSING_MAX_EMAILS') ?: 10);
$max_links_each   = intval(getenv('SENSING_MAX_LINKS') ?: 5);

$since = new DateTime("-$lookback_minutes minutes", new DateTimeZone('Asia/Seoul'));
echo "[cron] Lookback since: " . $since->format('Y-m-d H:i:s') . " KST\n";

$mbox = @imap_open("{" . $IMAP_HOST . ":" . $IMAP_PORT . $IMAP_ENCRYPTION . "}INBOX", $IMAP_USER, $IMAP_PASS);
if (!$mbox) {
    $err = imap_last_error();
    log_msg($LOG_FILE, "[cron] IMAP 연결 실패: $err");
    echo "[ERROR] IMAP 연결 실패: $err\n";
    exit(2);
}
// Gmail 날짜 검색은 로케일 포맷을 사용 → 최근 UID 필터가 더 안전
$emails = @imap_search($mbox, 'ALL', SE_UID);
if (!$emails) { echo "[cron] 메일 없음.\n"; imap_close($mbox); exit(0); }
rsort($emails);

$processed = 0;
$saved_cnt = 0;
$report_lines = [];

foreach ($emails as $uid) {
    if ($processed >= $max_emails) break;
    $ov = imap_fetch_overview($mbox, (string)$uid, FT_UID);
    if (!$ov || !isset($ov[0])) continue;
    $o = $ov[0];
    $udate = isset($o->udate) ? intval($o->udate) : time();
    $msgTime = (new DateTime('@'.$udate))->setTimezone(new DateTimeZone('Asia/Seoul'));
    if ($msgTime < $since) break; // 정렬 기준상 더 이전은 중단

    $subject = isset($o->subject) ? imap_utf8($o->subject) : '(제목 없음)';
    $from = isset($o->from) ? $o->from : '';

    // 본문에서 링크 추출 (view_email와 유사)
    $structure = imap_fetchstructure($mbox, (string)$uid, FT_UID);
    $html = '';
    if ($structure && isset($structure->parts) && is_array($structure->parts)) {
        foreach ($structure->parts as $idx => $part) {
            $section = (string)($idx+1);
            $partBody = imap_fetchbody($mbox, (string)$uid, $section, FT_UID);
            $encoding = $part->encoding ?? 0;
            if ($encoding == 3) $partBody = base64_decode($partBody);
            elseif ($encoding == 4) $partBody = quoted_printable_decode($partBody);
            if (isset($part->subtype) && strtoupper($part->subtype) === 'HTML') {
                $html = $partBody; break;
            } elseif (isset($part->subtype) && strtoupper($part->subtype) === 'PLAIN' && $html === '') {
                $html = nl2br(htmlspecialchars($partBody));
            }
        }
    } else {
        $body = imap_fetchbody($mbox, (string)$uid, "1", FT_UID);
        $encoding = $structure->encoding ?? 0;
        if ($encoding == 3) $body = base64_decode($body);
        elseif ($encoding == 4) $body = quoted_printable_decode($body);
        $html = nl2br(htmlspecialchars($body));
    }

    $links = extract_links_from_html($html);
    if (!$links) { $processed++; continue; }

    $links = array_slice($links, 0, $max_links_each);
    $saved_paths = [];

    foreach ($links as $L) {
        $url = trim($L['url'] ?? '');
        if ($url === '' || !preg_match('#^https?://#i', $url)) continue;
        try {
            $text = fetch_url_text($url) ?: '수집 실패. URL 기반 요약 요청.';
            $parsed = llm_analyze_article($subject, $url, $text);

            $reg = $parsed['regulation'] ?? null;
            $asset = $parsed['asset'] ?? null;

            if (is_array($reg) && isset($reg['category'])) {
                $cat = $reg['category'];
                if (!in_array($cat, ['governance','contract','lawsuit'], true)) $cat = 'governance';
                $htmlOut = render_regulation_html($reg);
                [$ok, $path] = save_html_file($SENSING_BASE, 'regulation', $cat, $htmlOut, $url);
                if ($ok) { $saved_paths[] = $path; $saved_cnt++; }
            }
            if (is_array($asset) && isset($asset['category'])) {
                $cat = $asset['category'];
                if (!in_array($cat, ['data','model','agent'], true)) $cat = 'agent';
                $htmlOut = render_asset_html($asset);
                [$ok, $path] = save_html_file($SENSING_BASE, 'asset', $cat, $htmlOut, $url);
                if ($ok) { $saved_paths[] = $path; $saved_cnt++; }
            }
        } catch (Throwable $e) {
            log_msg($LOG_FILE, "[cron] Analyze error {$url} : ".$e->getMessage());
        }
    }

    if ($saved_paths) {
        $report_lines[] = "• {$subject} — 저장 " . count($saved_paths) . "건";
    }
    $processed++;
}
imap_close($mbox);

$summary = "[cron] 완료: 메일 {$processed}건 처리, 파일 {$saved_cnt}건 저장";
echo $summary . "\n";
if ($report_lines) {
    $summary .= "\n" . implode("\n", $report_lines);
}
post_webhook($summary);

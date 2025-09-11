<?php
// cron_scan.php — 자동 스캐너 (CLI/cron용)
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/article_fetch.php';
require_once __DIR__ . '/lib/llm.php';
require_once __DIR__ . '/templates/html_templates.php';
require_once __DIR__ . '/lib/notify.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/schema.php';
require_once __DIR__ . '/lib/similarity.php';


if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "CLI only.\n";
    exit;
}

$lookback_minutes = intval(getenv('SENSING_LOOKBACK_MIN') ?: 180);  // 최근 3시간
$retry_base = intval(getenv('SENSING_RETRY_BASE_SEC') ?: 600);
$retry_factor = floatval(getenv('SENSING_RETRY_FACTOR') ?: 2.0);
$retry_max = intval(getenv('SENSING_RETRY_MAX_ATTEMPTS') ?: 5);
$max_emails       = intval(getenv('SENSING_MAX_EMAILS') ?: 10);
$max_links_each   = intval(getenv('SENSING_MAX_LINKS') ?: 5);

$since = new DateTime("-$lookback_minutes minutes", new DateTimeZone('Asia/Seoul'));
$sim_jaccard_min = floatval(getenv('SENSING_SIMILARITY_JACCARD_MIN') ?: 0.90);

db_init();
$pdo = db();

function url_hash(string $u): string { return substr(sha1($u), 0, 16); }

function already_done(PDO $pdo, string $h): bool {
    $st = $pdo->prepare('SELECT 1 FROM results WHERE url_hash = ? AND status = "success" LIMIT 1');
    $st->execute([$h]);
    return (bool)$st->fetchColumn();
}

function record_result(PDO $pdo, array $row): void {
    $st = $pdo->prepare('INSERT OR REPLACE INTO results(created_at,email_uid,subject,from_addr,url,url_hash,regulation_category,asset_category,regulation_path,asset_path,status,error) VALUES (:created_at,:email_uid,:subject,:from_addr,:url,:url_hash,:reg_cat,:asset_cat,:reg_path,:asset_path,:status,:error)');
    $st->execute([
        ':created_at'=>date('Y-m-d H:i:s'),
        ':email_uid'=>$row['email_uid'] ?? null,
        ':subject'=>$row['subject'] ?? null,
        ':from_addr'=>$row['from_addr'] ?? null,
        ':url'=>$row['url'],
        ':url_hash'=>$row['url_hash'],
        ':reg_cat'=>$row['regulation_category'] ?? null,
        ':asset_cat'=>$row['asset_category'] ?? null,
        ':reg_path'=>$row['regulation_path'] ?? null,
        ':asset_path'=>$row['asset_path'] ?? null,
        ':status'=>$row['status'],
        ':error'=>$row['error'] ?? null,
    ]);
}

function fail_job(PDO $pdo, string $url, string $err, int $delaySec = null): void {
    global $retry_base;
    if ($delaySec===null) $delaySec = max(60, intval($retry_base));
    $h = url_hash($url);
    $pdo->exec('INSERT INTO failed_jobs(url,url_hash,attempts,created_at,last_error,next_try_at) VALUES('.$pdo->quote($url).','.$pdo->quote($h).',0,'.$pdo->quote(date('Y-m-d H:i:s')).','.$pdo->quote($err).','.$pdo->quote(date('Y-m-d H:i:s', time()+$delaySec)).') ON CONFLICT(url_hash) DO UPDATE SET attempts = attempts+1, last_error = excluded.last_error, next_try_at = excluded.next_try_at');
}

function retry_failed(PDO $pdo, int $maxRetry = null): int {
    global $retry_max, $retry_base, $retry_factor;
    if ($maxRetry===null) $maxRetry = $retry_max;
    $now = date('Y-m-d H:i:s');
    $st = $pdo->prepare('SELECT * FROM failed_jobs WHERE attempts < ? AND (next_try_at IS NULL OR next_try_at <= ?) LIMIT 10');
    $st->execute([$maxRetry, $now]);
    $rows = $st->fetchAll();
    $ret = 0;
    foreach ($rows as $r) {
        // schedule: just re-process via main flow by returning count; cron loop will include it by merging list?
        // For simplicity, process inline here:
        $url = $r['url'];
        echo "[retry] ".$url."\n";
        try {
            $text = fetch_url_text($url) ?: '수집 실패. URL 기반 요약 요청.';
            $parsed = llm_analyze_article('Retry', $url, $text);
            $reg = $parsed['regulation'] ?? null;
            $asset = $parsed['asset'] ?? null;
            $reg_path = $asset_path = null;
            $reg_cat = $asset_cat = null;
            $confidence = floatval($parsed['confidence'] ?? 0.6);
            $needs_review = !empty($parsed['needs_review']);
            $review_notes = '';
            if (!empty($parsed['review_reasons'])) { $review_notes = implode('; ', (array)$parsed['review_reasons']); }
            $valNotes=[]; $v1=true; $v2=true;
            if (isset($parsed['regulation'])) $v1 = validate_regulation($parsed['regulation'], $valNotes);
            if (isset($parsed['asset'])) $v2 = validate_asset($parsed['asset'], $valNotes);
            if (!$v1 || !$v2) { $needs_review = true; $review_notes .= ($review_notes?'; ':'') . implode('; ', $valNotes); }

            if (is_array($reg) && isset($reg['category'])) {
                $reg_cat = $reg['category'];
                if (!in_array($reg_cat, ['governance','contract','lawsuit'], true)) $reg_cat = 'governance';
                $htmlOut = render_regulation_html($reg);
                [$ok, $reg_path] = save_html_file($GLOBALS['SENSING_BASE'], 'regulation', $reg_cat, $htmlOut, $url);
            }
            if (is_array($asset) && isset($asset['category'])) {
                $asset_cat = $asset['category'];
                if (!in_array($asset_cat, ['data','model','agent'], true)) $asset_cat = 'agent';
                $htmlOut = render_asset_html($asset);
                [$ok2, $asset_path] = save_html_file($GLOBALS['SENSING_BASE'], 'asset', $asset_cat, $htmlOut, $url);
            }
            record_result($pdo, [
                'url'=>$url, 'url_hash'=>url_hash($url),
                'status'=>'success','error'=>null,
                'regulation_category'=>$reg_cat,'asset_category'=>$asset_cat,
                'regulation_path'=>$reg_path,'asset_path'=>$asset_path
            ]);
            $pdo->prepare('DELETE FROM failed_jobs WHERE url_hash = ?')->execute([url_hash($url)]);
            $ret++;
        } catch (Throwable $e) {
            $next = time() + intval($retry_base * pow(max(1,$r['attempts']+1), $retry_factor));
            $pdo->prepare('UPDATE failed_jobs SET attempts = attempts+1, last_error = ?, next_try_at = ? WHERE url_hash = ?')
                ->execute([$e->getMessage(), date('Y-m-d H:i:s', $next), url_hash($url)]);
            echo "[retry-fail] ".$e->getMessage()."\n";
        }
    }
    return $ret;
}


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
        $h = url_hash(trim($L['url'] ?? ''));
        if ($h && already_done($pdo, $h)) { continue; }
        // 유사도 검사용 최근 샘플 로드
        $recent = $pdo->query("SELECT url, text_sig FROM results ORDER BY id DESC LIMIT 500")->fetchAll();
        $url = trim($L['url'] ?? '');
        if ($url === '' || !preg_match('#^https?://#i', $url)) continue;
        try {
            $text = fetch_url_text($url) ?: '수집 실패. URL 기반 요약 요청.';
            $parsed = llm_analyze_article($subject, $url, $text);
            // 유사도 검사
            $sig = text_signature($text ?: $url);
            foreach ($recent as $rr) {
                if (!empty($rr['text_sig']) && $sig && $rr['text_sig']!==$sig) {
                    $sim = jaccard3($text ?: $url, $rr['url']);
                    if ($sim >= $sim_jaccard_min) { continue 2; }
                }
            }

            $reg = $parsed['regulation'] ?? null;
            $asset = $parsed['asset'] ?? null;

            $confidence = floatval($parsed['confidence'] ?? 0.6);
            $needs_review = !empty($parsed['needs_review']);
            $review_notes = '';
            if (!empty($parsed['review_reasons'])) { $review_notes = implode('; ', (array)$parsed['review_reasons']); }
            $valNotes=[]; $v1=true; $v2=true;
            if (isset($parsed['regulation'])) $v1 = validate_regulation($parsed['regulation'], $valNotes);
            if (isset($parsed['asset'])) $v2 = validate_asset($parsed['asset'], $valNotes);
            if (!$v1 || !$v2) { $needs_review = true; $review_notes .= ($review_notes?'; ':'') . implode('; ', $valNotes); }

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

$retried = retry_failed($pdo);
$summary = "[cron] 완료: 메일 {$processed}건 처리, 파일 {$saved_cnt}건 저장, 재시도 성공 {$retried}건";
echo $summary . "\n";
if ($report_lines) {
    $summary .= "\n" . implode("\n", $report_lines);
}
post_webhook($summary);

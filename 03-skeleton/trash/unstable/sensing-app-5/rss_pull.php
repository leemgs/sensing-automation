<?php
// rss_pull.php — pull RSS feeds and analyze items
declare(strict_types=1);
if (php_sapi_name() !== 'cli') { http_response_code(403); echo "CLI only\n"; exit; }

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/article_fetch.php';
require_once __DIR__ . '/lib/llm.php';
require_once __DIR__ . '/templates/html_templates.php';
require_once __DIR__ . '/lib/schema.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/notify.php';
require_once __DIR__ . '/lib/similarity.php';

db_init(); $pdo = db();

$feedsFile = __DIR__ . '/feeds.json';
if (!is_file($feedsFile)) { file_put_contents($feedsFile, json_encode(["https://news.google.com/rss/search?q=EU+AI+Act&hl=ko&gl=KR&ceid=KR:ko"], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)); }
$feeds = json_decode(file_get_contents($feedsFile), true);
if (!is_array($feeds)) { echo "Invalid feeds.json\n"; exit(1); }

$maxItems = intval(getenv('RSS_MAX_ITEMS') ?: 10);
$total_saved = 0;
foreach ($feeds as $feed) {
    echo "[rss] $feed\n";
    $xml = @simplexml_load_string(@file_get_contents($feed));
    if (!$xml) continue;
    $items = $xml->channel->item ?? [];
    $i = 0;
    foreach ($items as $it) {
        if ($i >= $maxItems) break;
        $title = (string)$it->title;
        $link  = (string)$it->link;
        if (!$link) continue;
        $h = substr(sha1($link),0,16);
        // dedupe
        $st = $pdo->prepare('SELECT 1 FROM results WHERE url_hash = ? AND status = "success" LIMIT 1');
        $st->execute([$h]);
        if ($st->fetchColumn()) { continue; }

        try {
            $text = fetch_url_text($link) ?: '수집 실패. URL 기반 요약 요청.';
            $parsed = llm_analyze_article($title, $link, $text);
            $reg = $parsed['regulation'] ?? null;
            $asset = $parsed['asset'] ?? null;
            $confidence = floatval($parsed['confidence'] ?? 0.6);
            $needs_review = !empty($parsed['needs_review']);
            $review_notes = '';
            if (!empty($parsed['review_reasons'])) { $review_notes = implode('; ', (array)$parsed['review_reasons']); }
            $valNotes=[]; $v1=true; $v2=true;
            if ($reg) $v1 = validate_regulation($reg, $valNotes);
            if ($asset) $v2 = validate_asset($asset, $valNotes);
            if (!$v1 || !$v2) { $needs_review = true; $review_notes .= ($review_notes?'; ':'') . implode('; ', $valNotes); }

            $saved = [];
            if ($reg && isset($reg['category'])) {
                $cat = in_array($reg['category'], ['governance','contract','lawsuit'], true) ? $reg['category'] : 'governance';
                $html = render_regulation_html($reg);
                [$ok, $path] = save_html_file($SENSING_BASE, 'regulation', $cat, $html, $link);
                if ($ok) $saved['regulation'] = $path;
            }
            if ($asset && isset($asset['category'])) {
                $cat = in_array($asset['category'], ['data','model','agent'], true) ? $asset['category'] : 'agent';
                $html = render_asset_html($asset);
                [$ok, $path] = save_html_file($SENSING_BASE, 'asset', $cat, $html, $link);
                if ($ok) $saved['asset'] = $path;
            }
            $sev = ($reg && $reg['category']==='lawsuit') ? 'high' : 'info';
            $sig = text_signature($text ?: $link);
            $st = $pdo->prepare('INSERT INTO results(created_at,email_uid,subject,from_addr,url,url_hash,regulation_category,asset_category,regulation_path,asset_path,status,confidence,needs_review,review_notes,severity,source_type,text_sig) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,\"rss\",?)');
            $st->execute([date('Y-m-d H:i:s'), null, $title, null, $link, $h, $reg['category'] ?? null, $asset['category'] ?? null, $saved['regulation'] ?? null, $saved['asset'] ?? null, 'success', $confidence, $needs_review?1:0, $review_notes, $sev, $sig]);
            $total_saved++;
            if ($needs_review) { post_webhook("RSS 검토 필요: $title", null, 'warn'); }
        } catch (Throwable $e) {
            fail_job($pdo, $link, $e->getMessage());
        }
        $i++;
    }
}
echo "[rss] saved: $total_saved\n";

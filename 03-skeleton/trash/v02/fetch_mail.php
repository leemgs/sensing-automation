<?php
header('Content-Type: application/json; charset=UTF-8');

require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
require __DIR__ . '/imap_client.php';
require __DIR__ . '/ai_extractor.php';

$cfg = require __DIR__ . '/config.php';
$pdo = db();

$q      = trim($_GET['q'] ?? '');
$labelQ = trim($_GET['label'] ?? '');
$limit  = (int)($_GET['limit'] ?? $cfg['max_messages']);
$limit  = max(1, min(200, $limit));

try {
    $imap = open_mailbox($cfg);

    // --- 검색 조건 ---
    if (!empty($cfg['restrict_imap_search']) && !empty($cfg['analysis_label_filter']) && !$q && !$labelQ) {
        $raw = '';
        foreach (($cfg['analysis_label_filter'] ?? []) as $L) {
            $raw .= ' label:"' . addcslashes($L, '"') . '"';
        }
        $criteria = 'X-GM-RAW "' . trim($raw) . '"';
    } elseif ($q || $labelQ) {
        $raw = trim($q . ' ' . ($labelQ ? 'label:"' . $labelQ . '"' : ''));
        $criteria = 'X-GM-RAW "' . addcslashes($raw, '"') . '"';
    } else {
        $criteria = 'SINCE "' . date('d-M-Y', strtotime('-7 days')) . '"';
    }

    $uids = imap_search($imap, $criteria, SE_UID);
    $out = [];
    if ($uids) {
        rsort($uids);
        $cnt = 0;
        foreach ($uids as $uid) {
            if ($cnt >= $limit) break;

            $ov = imap_fetch_overview($imap, $uid, FT_UID)[0] ?? null;
            if (!$ov) continue;
            $msgno = imap_msgno($imap, $uid);

            $from    = isset($ov->from) ? decode_mime_str($ov->from) : '';
            $subject = isset($ov->subject) ? decode_mime_str($ov->subject) : '(No Subject)';
            $date    = $ov->date ?? '';
            $seen    = !empty($ov->seen);

            $body = get_body_prefer_text($imap, $msgno);
            $snippet = $body ? mb_substr(preg_replace('/\s+/u',' ',$body), 0, 180, 'UTF-8') : '';

            $raw_header = imap_fetchheader($imap, $msgno);
            $labelsStr  = parse_x_gm_labels($raw_header);
            $labelsArr  = parse_x_gm_labels_array($raw_header);

            // === 캐시 저장 ===
            upsert_message($pdo, [
                'uid' => (string)$uid,
                'subject' => $subject,
                'from_addr' => $from,
                'date_utc'  => gmdate('c', strtotime($date ?: 'now')),
                'seen'      => $seen ? 1 : 0,
                'labels'    => $labelsStr,
                'snippet'   => $snippet,
                'deleted'   => 0,
            ]);

            // === AI 분석/저장 파이프라인 ===
            $canRunAnalysis = labels_match_filter($cfg, $labelsArr);
            $stmt = $pdo->prepare("SELECT lawsuit_saved, contract_saved, governance_saved FROM messages WHERE uid=?");
            $stmt->execute([(string)$uid]);
            $flags = $stmt->fetch() ?: ['lawsuit_saved'=>0,'contract_saved'=>0,'governance_saved'=>0];

            // 실행 후보 계산
            $toRun = [];
            if (!empty($cfg['always_analysis'])) {
                $toRun = ['lawsuit','contract','governance'];
            } else {
                $byLabel = detect_categories_by_label($cfg, $labelsArr);
                $toRun = array_merge($toRun, $byLabel);
                if (!$byLabel) {
                    foreach (['lawsuit','contract','governance'] as $cat) {
                        $kws = $cfg['keywords'][$cat] ?? [];
                        if (contains_keywords($subject, $body, $kws)) $toRun[] = $cat;
                    }
                }
                $toRun = array_values(array_unique($toRun));
            }
            if (!$canRunAnalysis) $toRun = [];

            if (!empty($cfg['exclusive_routing']) && $toRun) {
                $prio = $cfg['exclusive_priority'] ?? ['lawsuit','contract','governance'];
                foreach ($prio as $p) {
                    if (in_array($p, $toRun, true)) { $toRun = [$p]; break; }
                }
            }

            $metaForSave = [
                '제목'     => $subject,
                '날짜'     => gmdate('Y-m-d', strtotime($date ?: 'now')),
                '보낸사람'  => $from,
                '라벨'     => $labelsStr,
                '본문'     => $body,
            ];

            $execCategory = function(string $cat) use ($cfg, $subject, $body, $date, $metaForSave, $pdo, $uid, $flags) {
                try {
                    if ($cat==='contract' && !(int)$flags['contract_saved']) {
                        $res  = openai_extract_contract($cfg, $subject, $body);
                        if (is_array($res) && !empty($res['is_contract'])) {
                            $data = $res['data'] ?? [];
                            if (empty($data['계약일자']) && !empty($date)) $data['계약일자'] = gmdate('Y-m-d', strtotime($date));
                            save_contract_html($cfg, $data, $metaForSave);
                            mark_contract_saved($pdo, (string)$uid);
                        }
                    } elseif ($cat==='governance' && !(int)$flags['governance_saved']) {
                        $res  = openai_extract_governance($cfg, $subject, $body);
                        if (is_array($res) && !empty($res['is_governance'])) {
                            $data = $res['data'] ?? [];
                            if (empty($data['발효일자']) && !empty($date)) $data['발효일자'] = gmdate('Y-m-d', strtotime($date));
                            save_governance_html($cfg, $data, $metaForSave);
                            mark_governance_saved($pdo, (string)$uid);
                        }
                    } elseif ($cat==='lawsuit' && !(int)$flags['lawsuit_saved']) {
                        $res  = openai_extract_lawsuit($cfg, $subject, $body);
                        if (is_array($res) && !empty($res['is_lawsuit'])) {
                            $data = $res['data'] ?? [];
                            if (empty($data['소송날짜']) && !empty($date)) $data['소송날짜'] = gmdate('Y-m-d', strtotime($date));
                            save_lawsuit_html($cfg, $data, $metaForSave);
                            mark_lawsuit_saved($pdo, (string)$uid);
                        }
                    }
                } catch (Throwable $e) { /* error_log($e->getMessage()); */ }
            };
            foreach ($toRun as $cat) { $execCategory($cat); }

            // 첨부 수집
            $attachments = collect_attachments($imap, $msgno);
            foreach ($attachments as &$a) {
                $a['download_url'] = 'download_attachment.php?uid=' . urlencode((string)$uid) . '&part=' . urlencode($a['part']);
            }

            $out[] = [
                'uid' => (string)$uid,
                'from' => $from,
                'subject' => $subject,
                'date' => $date,
                'seen' => $seen,
                'labels' => $labelsStr,
                'snippet' => $snippet,
                'attachments' => $attachments,
            ];
            $cnt++;
        }
    }

    imap_close($imap);
    echo json_encode([
        'ok' => true,
        'refreshed_at' => date('c'),
        'messages' => $out,
        'poll_interval_seconds' => (int)$cfg['poll_interval'],
        'criteria' => $criteria,
    ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}

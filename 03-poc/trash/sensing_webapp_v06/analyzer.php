<?php
declare(strict_types=1);
require_once __DIR__.'/config.php';
require_once __DIR__.'/utils.php';
require_once __DIR__.'/llm_client.php';

function build_system_prompt(): string {
    return <<<S
응답의 최상단 단 한 줄에 아래 메타를 반드시 출력하세요:
META: GROUP=<AI규제|AI에셋|해당없음>; CATEGORY=<governance|contract|lawsuit|data|model|agent|해당없음>
- GROUP: 소송/거버넌스/계약=AI규제, 데이터셋/AI 모델/Agent=AI에셋, 그 외=해당없음.
- CATEGORY: 규제=governance|contract|lawsuit, 에셋=data|model|agent, 기타=해당없음.
S;
}

function load_user_prompt(): string {
    $path = __DIR__.'/prompt.txt';
    if (is_file($path)) {
        $s = @file_get_contents($path);
        if (is_string($s) && trim($s) !== '') return $s;
    }
    return "이메일 본문에 포함된 기사/요약을 중복 제거 후 카테고리 기준으로 정리하고, 끝에 표 요약을 만드세요.";
}

function analyze_and_save(string $emailBody): array {
    ensure_dirs();
    $messages = [
        ['role' => 'system', 'content' => build_system_prompt()],
        ['role' => 'user',   'content' => load_user_prompt()."\n\n----- 이메일 본문 시작 -----\n".$emailBody."\n----- 이메일 본문 종료 -----"],
    ];
    list($ok, $resp) = llm_chat_complete($messages);
    if (!$ok) return [false, $resp];

    $group = '해당없음'; $category = '해당없음';
    if (preg_match('/^META:\s*GROUP\s*=\s*([^;]+);\s*CATEGORY\s*=\s*([^\r\n]+)/u', $resp, $m)) {
        $group = trim($m[1]); $category = trim($m[2]);
        $resp = preg_replace('/^META:.*\R/u', '', $resp, 1);
    } else {
        if (preg_match('/소송|원고|피고|법원/u', $resp)) { $group='AI규제'; $category='lawsuit'; }
        elseif (preg_match('/거버넌스|정책|가이드라인/u', $resp)) { $group='AI규제'; $category='governance'; }
        elseif (preg_match('/계약|공급자|구매자/u', $resp)) { $group='AI규제'; $category='contract'; }
        elseif (preg_match('/데이터셋|데이타셋|크롤링/u', $resp)) { $group='AI에셋'; $category='data'; }
        elseif (preg_match('/AI 모델|모델 명칭|GPU/u', $resp)) { $group='AI에셋'; $category='model'; }
        elseif (preg_match('/Agent|에이전트/u', $resp)) { $group='AI에셋'; $category='agent'; }
    }

    $html = "<!doctype html><meta charset='utf-8'>\n";
    $html .= "<style>body{font-family:system-ui,Segoe UI,Arial,sans-serif;line-height:1.6;max-width:900px;margin:2rem auto;padding:0 1rem} h1{font-size:1.4rem} pre{white-space:pre-wrap}</style>\n";
    $html .= "<h1>LLM 분석 결과</h1>\n<p><b>GROUP:</b> ".h($group)." &nbsp; <b>CATEGORY:</b> ".h($category)."</p><hr/>\n";
    $resp_html = nl2br(h($resp));
    $html .= "<div class='analysis'>".$resp_html."</div>\n";

    list($path, $fname) = save_html_by_route($group, $category, $html);
    return [true, ['group'=>$group, 'category'=>$category, 'path'=>$path, 'filename'=>$fname, 'preview'=>$html]];
}

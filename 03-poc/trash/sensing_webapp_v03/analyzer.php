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

function build_user_prompt(): string {
    return <<<U
위의 기사들중에서 중복 기사는 제거해야합니다. 그리고나서 각각의 기사 건에 대해 아래의 LLM에게 요청하는 프롬프트 메세지의 실행결과를 순서대로 출력하세요.

1. "소송"이면: 소송 날자, 소송번호, 소송 유형, 원고, 원고측 변호사, 피고, 피고측 변호사, 법원, 소송 대상 제품, 데이터, 소송이유, 소송 금액, Tracker, 관련 링크, 개요, 배경, 진행현황(시작/진행중/판결/항소/종료), 비고.
2. "거버넌스"이면: 제목, 제공자, 제공처, 발생일자, 개요, 배경, 요점(한줄+포인트3), 결론, 파급효과, 시사점, 관련 링크.
3. "계약"이면: 제목, 계약일자, 계약 유형, 계약 데이터, 계약 금액, 데이터 타입, 개요(A4 반장), 관련 링크, 공급자, 구매자, 적용 대상, 진행 현황.
4. "데이타셋"이면: 제공자, 공개 일자, 명칭, 라이센스, 정보, 수집방법, 파급효과, 시사점, 관련 주소.
5. "AI 모델"이면: 제공자, 공개 일자, 모델 명칭, 라이센스, 상업적 사용 여부, 모델 정보(한줄+포인트3), 추론용 GPU 메모리, 파급효과, 시사점, 관련 주소.
6. "Agent"이면: 제공자, 공개 일자, 명칭, 라이센스, 설명(의미/목적/기능), 파급효과, 시사점, 관련 주소.
7. 어느 것도 아니면: "해당없음"으로, 제목과 개요 2가지만.

각 기사에 대해 위 기준으로 정리한 뒤, 마지막에 표 형태 요약본도 만드세요.
U;
}

function analyze_and_save(string $emailBody): array {
    ensure_dirs();
    $messages = [
        ['role' => 'system', 'content' => build_system_prompt()],
        ['role' => 'user',   'content' => build_user_prompt()."\n\n----- 이메일 본문 시작 -----\n".$emailBody."\n----- 이메일 본문 종료 -----"],
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
    $html .= "<pre>".h($resp)."</pre>\n";

    list($path, $fname) = save_html_by_route($group, $category, $html);
    return [true, ['group'=>$group, 'category'=>$category, 'path'=>$path, 'filename'=>$fname, 'preview'=>$html]];
}

<?php
declare(strict_types=1);
require_once __DIR__.'/config.php';
require_once __DIR__.'/utils.php';
require_once __DIR__.'/llm_client.php';

/** 시스템 프롬프트: META 헤더 강제 */
function build_system_prompt(): string {
    return <<<S
당신은 이메일로 전송된 여러 기사/요약을 분석하여 분류·정리하는 전문가입니다.
응답의 최상단 단 한 줄에 다음 메타 정보를 반드시 출력하세요:

META: GROUP=<AI규제|AI에셋|해당없음>; CATEGORY=<governance|contract|lawsuit|data|model|agent|해당없음>

- GROUP은 "소송/거버넌스/계약"은 AI규제, "데이타셋/AI 모델/Agent"는 AI에셋, 그 외는 해당없음.
- CATEGORY는 규제: governance|contract|lawsuit, 에셋: data|model|agent, 그 외: 해당없음.
S;
}

/** 사용자 프롬프트(요구사항 그대로) */
function build_user_prompt(): string {
    return <<<U
위의 기사들중에서 중복 기사는 제거해야합니다. 그리고나서 각각의 기사 건에 대해 아래의 LLM에게 요청하는 프롬프트 메세지의 실행결과를 순서대로 출력하세요.

1. 위의 내용이 "소송" 카테고리에 해당한다면, 소송 날자, 소송번호, 소송 유형, 원고, 원고측 변호사, 피고, 피고측 변호사, 법원, 소송 대상 제품, 데이터, 소송이유, 소송 금액, Tracker, 관련 링크, 개요, 배경, 진행현황 (예:시작, 소송진행중, 판결, 항소진행중, 종료), 비고 등을 한국어로 작성하여주세요. 

2. 위의 내용이 "거버넌스" 카테고리에 해당한다면,  제목, 제공자, 제공처, 발생일자, 개요, 배경, 거버넌스 정보 요점 (요약 한줄, 요점 포인트 3가지), 결론, 파급효과, 시사점, 관련 기사 링크 등을 한국어로 작성하여 주세요

3. 위의 내용이 AI 학습에 사용되는 데이타들에 대한 "계약" 카테고리에 해당한다면, 제목, 계약일자, 계약 유형, 계약 데이터, 계약 금액, 데이터 타입 (예: 텍스트, 이미지, 오디오, 비디오), 개요(A4 반장 분량), 관련 기사 링크, 공급자, 구매자, 적용 대상, 진행 현황 등을 한국어로 작성하여주세요.

4. 위의 내용이 AI 모델의 학습용 "데이타셋" 카테고리에 해당한다면, 제공자, 공개 일자, 데이타셋 명칭, 라이센스, 데이타셋 정보, 데이타셋 수집방법  (예: 크롤링, 자체제작, 구매, 기타), 파급효과, 시사점, 관련 주소 등을  한국어로 정리해주세요.  

5. 위의 내용이 학습된 "AI 모델" 카테고리에 해당한다면,  제공자, 공개 일자, 모델 명칭, 라이센스, 상업적 사용 가능여부, 모델 정보 (요약 한줄, 요점 포인트 3가지), 추론위해 필요한 GPU 메모리 용량, 파급 효과, 시사점, 관련 주소 등을 한국어로 정리해주세요.  

6. 위의 내용이 AI 기반 "Agent" 카테고리에 해당한다면,  제공자, 공개 일자, Agent 명칭, Agent 라이센스, Agent 설명 (의미, 목적, 주요기능), 파급 효과, 시사점, 관련 주소 등을 한국어로 정리해주세요.
 
7. 위의 6가지 중에 어느 곳에도 해당이 되지 않는다면, "해당없음" 카테고리로 분류하고, 간단히 기사의 제목과 개요 2가지만 한국어로 정리해주세요.

기사 각각을 위의 프롬프트 기준에 맞춰 분류 및 정리하고 나면, 한눈에 비교할 수 있도록 표 형태 요약본도 만들어 주세요.
U;
}

/** 이메일 본문을 받아 분석 수행 및 저장 */
function analyze_and_save(string $emailBody): array {
    ensure_dirs();

    $messages = [
        ['role' => 'system', 'content' => build_system_prompt()],
        ['role' => 'user',   'content' => build_user_prompt()."\n\n----- 이메일 본문 시작 -----\n".$emailBody."\n----- 이메일 본문 종료 -----"],
    ];
    list($ok, $resp) = llm_chat_complete($messages);
    if (!$ok) return [false, $resp];

    // META 라인 파싱
    $group = '해당없음'; $category = '해당없음';
    if (preg_match('/^META:\s*GROUP\s*=\s*([^;]+);\s*CATEGORY\s*=\s*([^\r\n]+)/u', $resp, $m)) {
        $group = trim($m[1]); $category = trim($m[2]);
        $resp = preg_replace('/^META:.*\R/u', '', $resp, 1);
    } else {
        // 백업 룰(간이 추정)
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
    return [true, ['group'=>$group, 'category'=>$category, 'path'=>$path, 'filename'=>$fname]];
}

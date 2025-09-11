<?php
// lib/llm.php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

function llm_analyze_article(string $title, string $url, string $text): array {
    $apiKey = aah_api_key();
    if (!$apiKey) {
        throw new RuntimeException('AAH_API_KEY not found (env or /etc/environment).');
    }

    $prompt = [
        "role" => "user",
        "content" => build_prompt($title, $url, $text),
    ];
    $payload = [
        "model" => $LLM_MODEL,
        "messages" => [ $prompt ],
        "stream" => false,
    ];
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

    $ch = curl_init($LLM_ENDPOINT);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: Basic $apiKey",
            "Content-Type: application/json",
        ],
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_TIMEOUT => 45,
    ]);
    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if ($errno) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("LLM curl error: $errno $err");
    }
    curl_close($ch);

    $obj = @json_decode($raw, true);
    if (!is_array($obj)) {
        throw new RuntimeException("LLM invalid JSON response (HTTP $http): " . substr($raw ?? '', 0, 500));
    }
    // OpenAI 스타일 응답 가정
    $content = $obj["choices"][0]["message"]["content"] ?? null;
    if (!$content) {
        throw new RuntimeException("LLM no content in response (HTTP $http).");
    }

    // 모델에 JSON만 출력하도록 요청하지만, 안전하게 파싱
    $parsed = json_decode(trim($content), true);
    if (!is_array($parsed)) {
        // 혹시 코드블럭에 감싸진 경우 제거
        $content2 = preg_replace('~^```(?:json)?\s*~', '', $content);
        $content2 = preg_replace('~\s*```$~', '', $content2);
        $parsed = json_decode(trim($content2), true);
    }
    if (!is_array($parsed)) {
        throw new RuntimeException("LLM content is not valid JSON.\n$content");
    }
    return $parsed;
}

function build_prompt(string $title, string $url, string $text): string {
    $instructions = <<<EOT
당신은 AI 규제/에셋 전문 분석가입니다. 아래 기사(제목, URL, 본문요약 텍스트)를 읽고
**두 가지 관점**으로 동시에 구조화된 JSON만 반환하세요.

1) "regulation" (분류: governance | contract | lawsuit)
2) "asset"     (분류: data | model | agent)

아래 **정확한 스키마**로만 JSON을 출력하세요. 추가 문장/설명 금지. 코드블록 금지.
{
  "regulation": {
    "category": "governance | contract | lawsuit",
    "fields": {
      // governance일 때
      "title": "...",
      "provider": "...",            // 제공자(작성/배포 주체)
      "source": "...",              // 제공처(매체/기관/부서)
      "date": "YYYY-MM-DD",
      "summary_one_liner": "...",
      "key_points": ["...", "...", "..."],
      "overview": "...",
      "background": "...",
      "conclusion": "...",
      "impact": "...",
      "insights": "...",
      "links": ["...","..."],

      // lawsuit일 때 (필요 필드만 사용, 나머진 생략 가능)
      "lawsuit_date": "YYYY-MM-DD",
      "case_number": "...",
      "case_type": "...",
      "plaintiff": "...",
      "plaintiff_attorney": "...",
      "defendant": "...",
      "defendant_attorney": "...",
      "court": "...",
      "target_product_or_data": "...",
      "cause": "...",
      "claim_amount": "...",
      "tracker": "...",
      "status": "시작 | 소송진행중 | 판결 | 항소 진행중 | 종료",
      "note": "...",
      "links": ["...","..."],

      // contract일 때
      "title": "...",
      "contract_date": "YYYY-MM-DD",
      "contract_type": "...",
      "contract_data": "...",
      "contract_amount": "...",
      "data_type": "텍스트 | 이미지 | 오디오 | 비디오 | 혼합",
      "overview": "...",
      "supplier": "...",
      "buyer": "...",
      "applicable_scope": "...",
      "status": "...",
      "links": ["...","..."]
    }
  },
  "asset": {
    "category": "data | model | agent",
    "fields": {
      // data일 때
      "provider": "...",
      "release_date": "YYYY-MM-DD",
      "dataset_name": "...",
      "license": "...",
      "collection_method": "크롤링 | 자체제작 | 구매 | 기타",
      "dataset_info": "...",
      "impact": "...",
      "insights": "...",
      "links": ["...","..."],

      // model일 때
      "provider": "...",
      "release_date": "YYYY-MM-DD",
      "model_name": "...",
      "license": "...",
      "commercial_use": "가능 | 제한 | 불가",
      "one_liner": "...",
      "key_points": ["...", "...", "..."],
      "required_gpu_vram": "...",
      "impact": "...",
      "insights": "...",
      "links": ["...","..."],

      // agent일 때
      "provider": "...",
      "release_date": "YYYY-MM-DD",
      "agent_name": "...",
      "framework": "...",
      "framework_license": "...",
      "agent_info": "...",
      "impact": "...",
      "insights": "...",
      "links": ["...","..."]
    }
  }
}

기사 정보:
- 제목: {$title}
- URL: {$url}
- 본문요약(최대 4000자): {$text}

출력: 위 JSON만. 다른 텍스트 금지.
EOT;
    return $instructions;
}

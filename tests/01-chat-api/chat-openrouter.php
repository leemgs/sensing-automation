<?php
// .env 파일 로드
$env = parse_ini_file(__DIR__ . '/.env');
$apiKey = $env["OPENROUTER_API_KEY"] ?? null;

if (!$apiKey) {
    die("❌ OPENROUTER_API_KEY 가 .env 파일에 설정되어 있지 않습니다.\n");
}

// API 요청 데이터 (Chat Completions)
$data = [
    "model" => "openai/gpt-4o",   // 사용할 OpenRouter 모델
    "messages" => [
        ["role" => "system", "content" => "You are a helpful assistant."],
        ["role" => "user", "content" => "OpenAI API를 PHP에서 사용하는 예제를 보여줘."]
    ],
    "max_tokens" => 512,
    "temperature" => 0.7
];

// cURL 초기화
$ch = curl_init("https://openrouter.ai/api/v1/chat/completions");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Content-Type: application/json",
    "Authorization: " . "Bearer " . $apiKey
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));

// 실행
$response = curl_exec($ch);
if (curl_errno($ch)) {
    echo "cURL Error: " . curl_error($ch);
    exit;
}
curl_close($ch);

// 결과 파싱
$result = json_decode($response, true);
$output = $result['choices'][0]['message']['content'] ?? "응답 없음";

// 실행 환경 판별: CLI vs Web
if (php_sapi_name() === 'cli') {
    // CLI 모드 → 줄바꿈 유지
    echo "🤖 모델 응답 (CLI):\n";
    echo $output . "\n";
} else {
    // Web 모드 → HTML 줄바꿈 변환
    echo "🤖 모델 응답 (Web):<br>";
    echo nl2br(htmlspecialchars($output));
}

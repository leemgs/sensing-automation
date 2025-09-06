<?php
// .env 파일에서 설정 로드
$env = parse_ini_file(__DIR__ . '/.env');

$apiKey = $env['GROK_API_KEY'] ?? null;
$model  = $env['GROK_MODEL'] ?? 'grok-2-latest';
$base   = $env['GROK_BASE_URL'] ?? 'https://api.x.ai';

if (!$apiKey) {
    die("❌ GROK_API_KEY 가 .env 파일에 설정되어 있지 않습니다.\n");
}

// API 요청 데이터 (Chat Completions)
$data = [
    "model" => $model,
    "messages" => [
        ["role" => "system", "content" => "You are a helpful assistant."],
        ["role" => "user", "content" => "PHP에서 Grok API 사용 예제를 보여줘."]
    ],
    "temperature" => 0.7
];

// cURL 초기화
$url = rtrim($base, '/') . '/v1/chat/completions';
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Content-Type: application/json",
    "Authorization: Bearer " . $apiKey
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));

// 실행
$response = curl_exec($ch);
if (curl_errno($ch)) {
    echo "cURL Error: " . curl_error($ch);
    exit;
}
curl_close($ch);

// 결과 출력
$result = json_decode($response, true);
echo "🤖 모델 응답:\n";
echo $result['choices'][0]['message']['content'] ?? "응답 없음";

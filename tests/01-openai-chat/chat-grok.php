<?php
// Grok API 키 입력
$apiKey = "gsk_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx";

// API 요청 데이터 (Chat Completions)
$data = [
    "model" => "grok-2-latest",   // 사용할 Grok 모델
    "messages" => [
        ["role" => "system", "content" => "You are a helpful assistant."],
        ["role" => "user", "content" => "PHP에서 Grok API 사용 예제를 보여줘."]
    ],
    "temperature" => 0.7
];

// cURL 초기화
$ch = curl_init("https://api.x.ai/v1/chat/completions");
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

// 결과 출력
$result = json_decode($response, true);
echo "🤖 모델 응답:\n";
echo $result['choices'][0]['message']['content'] ?? "응답 없음";

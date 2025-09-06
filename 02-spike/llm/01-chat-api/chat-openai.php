<?php
// .env 파일에서 설정 로드 (chat-openrouter.php와 동일한 방식)
$env = parse_ini_file(__DIR__ . '/.env');

$apiKey = $env['OPENAI_API_KEY'] ?? null;              // 필수: OpenAI 키
$model  = $env['OPENAI_MODEL']  ?? 'gpt-4o-mini';      // 선택: 기본 모델 (없으면 gpt-4o-mini 사용)
$base   = $env['OPENAI_BASE_URL'] ?? 'https://api.openai.com'; // 선택: 프록시/전용 엔드포인트 사용 시

if (!$apiKey) {
    die("❌ OPENAI_API_KEY 가 .env 파일에 설정되어 있지 않습니다.\n");
}

// 요청 페이로드 (Chat Completions)
$data = [
    'model' => $model,
    'messages' => [
        ['role' => 'system', 'content' => 'You are a helpful assistant.'],
        ['role' => 'user',   'content' => 'OpenAI API를 PHP에서 사용하는 예제를 보여줘.']
    ],
    'temperature' => 0.7,
];

// cURL 초기화
$url = rtrim($base, '/') . '/v1/chat/completions';
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
    ],
    CURLOPT_POSTFIELDS     => json_encode($data, JSON_UNESCAPED_UNICODE),
]);

// 실행
$response = curl_exec($ch);
if (curl_errno($ch)) {
    echo 'cURL Error: ' . curl_error($ch);
    exit;
}
curl_close($ch);

// 결과 출력
$result = json_decode($response, true);
echo "🤖 모델 응답:\n";
echo $result['choices'][0]['message']['content'] ?? '응답 없음';

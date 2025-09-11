<?php
@ini_set('display_errors', '1');
@ini_set('display_startup_errors', '1');
@error_reporting(E_ALL);
header('Content-Type: text/plain; charset=UTF-8');

$CONFIG = require __DIR__ . '/config.php';

$key = trim($CONFIG['openrouter']['api_key'] ?? '');
$endpoint = trim($CONFIG['openrouter']['endpoint'] ?? 'https://openrouter.ai/api/v1/chat/completions');
$model = trim($CONFIG['openrouter']['model'] ?? 'openai/gpt-4o-mini');
$temperature = (float)($CONFIG['openrouter']['temperature'] ?? 0.2);
$max_tokens  = (int)($CONFIG['openrouter']['max_tokens'] ?? 64);

if ($key === '') {
    echo "ERROR: OPENROUTER_API_KEY missing.\n";
    exit(1);
}

$payload = [
    "model" => $model,
    "messages" => [
        ["role"=>"system", "content"=>"You are a healthcheck probe."],
        ["role"=>"user",   "content"=>"Reply with the single word: PONG"]
    ],
    "max_tokens" => $max_tokens,
    "temperature" => $temperature
];

$ch = curl_init($endpoint);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => array_filter([
        "Content-Type: application/json",
        "Authorization: Bearer " . $key,
        // Optional metadata
        ($CONFIG['openrouter']['http_referer'] ?? '') ? ("Referer: " . $CONFIG['openrouter']['http_referer']) : null,
        ($CONFIG['openrouter']['x_title'] ?? '') ? ("X-Title: " . $CONFIG['openrouter']['x_title']) : null,
    ]),
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_TIMEOUT => 20,
]);

$resp = curl_exec($ch);
if ($resp === false) {
    echo "cURL error: " . curl_error($ch) . "\n";
    curl_close($ch);
    exit(1);
}
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP " . $code . "\n";

$data = json_decode($resp, true);
if ($data === null) {
    echo "Body (raw):\n" . $resp . "\n";
    exit(0);
}

$txt = $data['choices'][0]['message']['content'] ?? '(no content)';
echo "Choice[0]: " . $txt . "\n";

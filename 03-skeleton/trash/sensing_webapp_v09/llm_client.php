<?php
declare(strict_types=1);
require_once __DIR__.'/config.php';

function get_openrouter_key(): string {
    return get_env_value('OPENROUTER_API_KEY');
}

function llm_chat_complete(array $messages, int $max_tokens, float $temperature, array $api, ?string &$raw_response_out=null): array {
    // $api: ['endpoint','model','auth_env','headers']
    $apiKey = get_env_value($api['auth_env'] ?? 'OPENROUTER_API_KEY');
    if ($apiKey === '') return [false, 'API 키를 읽을 수 없습니다. /etc/environment 확인 필요 (env: '.($api['auth_env'] ?? '').')'];

    $payload = [
        'model' => $api['model'] ?? 'openai/gpt-4o',
        'messages' => $messages,
        'max_tokens' => $max_tokens,
        'temperature' => $temperature,
    ];

    $headers = ['Content-Type: application/json', 'Authorization: Bearer '.$apiKey];
    if (!empty($api['headers']) && is_array($api['headers'])) {
        foreach ($api['headers'] as $k=>$v) {
            if (strtolower($k) === 'authorization') continue; // override with token
            $headers[] = $k.': '.$v;
        }
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $api['endpoint'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 60,
    ]);
    $resp = curl_exec($ch);
    if ($resp === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return [false, "cURL 에러: $err"];
    }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $raw_response_out = $resp;
    if ($code < 200 || $code >= 300) {
        return [false, "HTTP $code 응답: $resp"];
    }
    $json = json_decode($resp, true);
    $content = $json['choices'][0]['message']['content'] ?? null;
    if (!$content) return [false, '응답 파싱 실패'];
    return [true, $content, $payload];
}

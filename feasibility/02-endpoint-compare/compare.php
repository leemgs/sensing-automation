<?php
declare(strict_types=1);

/**
 * Chat Completions 비교 실행 스크립트
 * - provider=both   : OpenAI + LGStrial 모두 호출 (기본)
 * - provider=openai : OpenAI만
 * - provider=lgstrial: LGStrial만
 *
 * 실행 예:
 *   php compare.php
 *   php compare.php both
 *   php -S localhost:8000  => http://localhost:8000/compare.php?provider=both
 */

// ============== 공통 상수/유틸 ==============
const PROVIDER_OPENAI   = 'openai';
const PROVIDER_LGSTRIAL = 'lgstrial';
const PROVIDER_BOTH     = 'both';

function out(string $s = ''): void { echo $s . PHP_EOL; }

function authHeaderBearer(string $key): string { return "Authorization: Bearer {$key}"; }
function authHeaderBasic(string $key): string  { return "Authorization: Basic {$key}"; }

// ============== 설정 ==============
$providerArg = $_GET['provider'] ?? ($argv[1] ?? PROVIDER_BOTH);
$provider = strtolower(trim((string)$providerArg));

$configs = [
    PROVIDER_OPENAI => [
        'name'    => 'OpenAI',
        'url'     => 'https://api.openai.com/v1/chat/completions',
        'apiKey'  => 'sk-xxxxxxxxxxxxxxxxxxxx', // TODO: 실제 키로 교체
        'auth'    => 'bearer',
        'payload' => [
            "model"       => "gpt-4o-mini",
            "messages"    => [
                ["role" => "system", "content" => "You are a helpful assistant."],
                ["role" => "user",   "content" => "OpenAI API를 PHP에서 사용하는 예제를 보여줘."]
            ],
            "temperature" => 0.7,
            "stream"      => false,
        ],
    ],
    PROVIDER_LGSTRIAL => [
        'name'    => 'LGStrial',
        'url'     => 'https://inference-lgstrial-api.mycloud.com/falcon-30b-instruct/v1/chat/completions',
        'apiKey'  => getenv('api_key') ?: '',
        'auth'    => 'basic',
        'payload' => [
            "model"       => "falcon-30b-instruct",
            "messages"    => [
                ["role" => "user", "content" => "Please create three sentences starting with the word LLM in Korean."]
            ],
            "temperature" => 0.7,
            "stream"      => false,
        ],
    ],
];

// ============== HTTP 호출 ==============
function postJson(string $url, array $headers, array $payload): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT        => 60,
    ]);
    $start = microtime(true);
    $body  = curl_exec($ch);
    $took  = microtime(true) - $start;

    $errNo   = curl_errno($ch);
    $errMsg  = curl_error($ch);
    $status  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'ok'       => ($errNo === 0),
        'errno'    => $errNo,
        'error'    => $errMsg,
        'httpCode' => $status,
        'took'     => $took,
        'raw'      => $body ?: '',
        'json'     => json_decode($body ?? '', true),
    ];
}

// ============== 응답 파서 ==============
function extractAssistantText(?array $json): string
{
    if (!is_array($json)) return 'Invalid JSON';
    $content = $json['choices'][0]['message']['content'] ?? null;
    if (is_string($content) && $content !== '') return $content;

    $text = $json['choices'][0]['text'] ?? null;
    if (is_string($text) && $text !== '') return $text;

    return json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '응답 파싱 실패';
}

// ============== 단일 프로바이더 실행 ==============
function runProvider(string $key, array $cfg): array
{
    // 키 체크
    if ($cfg['apiKey'] === '') {
        return [
            'provider' => $key,
            'error'    => "API key missing. For '{$key}', set proper key."
        ];
    }

    $headers = ["Content-Type: application/json"];
    $headers[] = $cfg['auth'] === 'bearer'
        ? authHeaderBearer($cfg['apiKey'])
        : authHeaderBasic($cfg['apiKey']);

    $res = postJson($cfg['url'], $headers, $cfg['payload']);

    return [
        'provider'  => $key,
        'name'      => $cfg['name'],
        'endpoint'  => $cfg['url'],
        'httpCode'  => $res['httpCode'],
        'elapsed'   => $res['took'],
        'ok'        => $res['ok'],
        'curlErrno' => $res['errno'],
        'curlError' => $res['error'],
        'answer'    => extractAssistantText($res['json']),
        'raw'       => $res['raw'],
    ];
}

// ============== 실행 분기 ==============
$targets = match ($provider) {
    PROVIDER_OPENAI   => [PROVIDER_OPENAI],
    PROVIDER_LGSTRIAL => [PROVIDER_LGSTRIAL],
    default           => [PROVIDER_OPENAI, PROVIDER_LGSTRIAL], // both
};

// ============== 실행 & 출력 ==============
foreach ($targets as $p) {
    $cfg = $configs[$p];

    out(str_repeat('=', 70));
    out("Provider : {$cfg['name']} ({$p})");
    out("Endpoint : {$cfg['url']}");

    $result = runProvider($p, $cfg);

    if (isset($result['error'])) {
        out("ERROR    : {$result['error']}");
        continue;
    }

    out(sprintf("HTTP Code: %d", $result['httpCode']));
    out(sprintf("Elapsed  : %.3f sec", $result['elapsed']));
    if (!$result['ok']) {
        out(sprintf("cURL     : (%d) %s", $result['curlErrno'], $result['curlError']));
    }

    out("----- 🤖 모델 응답 -----");
    out($result['answer']);
    out();
}

out(str_repeat('=', 70));
out("Done.");

<?php
/**
 * llm-api-unified.php — One endpoint for Grok(xAI), OpenAI, OpenRouter
 * PHP 8.1+ / cURL
 *
 * ✅ 보안: /etc/environment 직접 파싱(파일 I/O) — getenv()/$_ENV/$_SERVER 미사용
 * ✅ 통합: provider별로 공통 인터페이스(messages/prompt)와 통일된 JSON 응답
 * ✅ 유연: model/temperature/max_tokens 오버라이드 가능
 * ✅ 안전: 민감값 로깅/노출 없음, 에러 메시지에서 토큰 제거
 *
 * POST fields:
 *   - provider: "openai" | "openrouter" | "grok"   (required)
 *   - messages: JSON array of {role, content}       (optional)
 *   - prompt  : string                              (optional; messages 없을 때 사용)
 *   - model, temperature, max_tokens                (optional overrides)
 */

declare(strict_types=1);
header('Content-Type: application/json; charset=UTF-8');
mb_internal_encoding('UTF-8');

/* -------------------- utils -------------------- */
function jexit(int $code, array $payload): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}
function sanitize_err(string $s): string {
    // API 키 모양 토큰/시크릿을 단순 마스킹(우연 노출 방지)
    $s = preg_replace('/(sk-[a-z0-9]{8,}|xai-[A-Za-z0-9_\-]{16,}|gsk_[A-Za-z0-9_\-]{16,}|hf_[A-Za-z0-9_\-]{16,})/i', '***', $s);
    $s = preg_replace('/(sk-or-[A-Za-z0-9_\-]{16,})/i', '***', $s);
    return $s ?? 'error';
}
function read_post(string $key, ?string $default=null): ?string {
    return array_key_exists($key, $_POST) ? (is_string($_POST[$key]) ? $_POST[$key] : $default) : $default;
}

/* -------------------- /etc/environment parser (no getenv) -------------------- */
function loadEnvFile(string $path): array {
    $env = [];
    if (!is_readable($path)) return $env;
    $raw = @file_get_contents($path);
    if ($raw === false) return $env;
    $lines = preg_split('/\r\n|\n|\r/', $raw);
    foreach ($lines as $line) {
        $t = trim($line);
        if ($t === '' || $t[0] === '#') continue;

        // strip inline comment outside quotes
        $len = strlen($t);
        $inS=false; $inD=false; $buf='';
        for ($i=0;$i<$len;$i++){
            $ch = $t[$i];
            if ($ch==="'" && !$inD){ $inS=!$inS; $buf.=$ch; continue; }
            if ($ch==='"' && !$inS){ $inD=!$inD; $buf.=$ch; continue; }
            if ($ch==='#' && !$inS && !$inD) break;
            $buf.=$ch;
        }
        if (!preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)\s*$/', $buf, $m)) continue;
        $key = $m[1]; $val = trim($m[2]);

        if ($val !== '' && (($val[0] === '"' && substr($val,-1)==='"') || ($val[0] === "'" && substr($val,-1)==="'"))) {
            $q = $val[0];
            $val = substr($val,1,-1);
            if ($q === '"') {
                $val = str_replace(['\\"','\\n','\\r','\\t','\\\\'], ['"',"\n","\r","\t",'\\'], $val);
            }
        }
        $env[$key] = $val;
    }
    return $env;
}
function env_str(array $E, string $k, ?string $d=null): ?string {
    return array_key_exists($k,$E) ? (string)$E[$k] : $d;
}
function env_int(array $E, string $k, int $d): int {
    return (isset($E[$k]) && $E[$k] !== '') ? (int)$E[$k] : $d;
}
function env_float(array $E, string $k, float $d): float {
    return (isset($E[$k]) && $E[$k] !== '') ? (float)$E[$k] : $d;
}

/* -------------------- load config -------------------- */
$ENV = loadEnvFile('/etc/environment');

$CFG = [
    // OpenAI
    'OPENAI_API_KEY' => env_str($ENV, 'OPENAI_API_KEY', ''),
    'OPENAI_MODEL'   => env_str($ENV, 'OPENAI_MODEL', 'gpt-4o-mini'),
    'OPENAI_ENDPOINT'=> env_str($ENV, 'OPENAI_ENDPOINT', 'https://api.openai.com/v1/chat/completions'),

    // OpenRouter
    'OPENROUTER_API_KEY'   => env_str($ENV, 'OPENROUTER_API_KEY', ''),
    'OPENROUTER_MODEL'     => env_str($ENV, 'OPENROUTER_MODEL', 'openai/gpt-4o-mini'),
    'OPENROUTER_ENDPOINT'  => env_str($ENV, 'OPENROUTER_ENDPOINT', 'https://openrouter.ai/api/v1/chat/completions'),
    'OPENROUTER_REFERER'   => env_str($ENV, 'OPENROUTER_HTTP_REFERER', ''),
    'OPENROUTER_X_TITLE'   => env_str($ENV, 'OPENROUTER_X_TITLE', 'Unified LLM Client'),

    // Grok (xAI)
    'XAI_API_KEY'    => env_str($ENV, 'XAI_API_KEY', env_str($ENV,'GROK_API_KEY','')),
    'XAI_MODEL'      => env_str($ENV, 'XAI_MODEL', 'grok-2-latest'),
    'XAI_ENDPOINT'   => env_str($ENV, 'XAI_ENDPOINT', 'https://api.x.ai/v1/chat/completions'),

    // common defaults
    'TEMPERATURE'    => env_float($ENV, 'LLM_TEMPERATURE', 0.2),
    'MAX_TOKENS'     => env_int($ENV, 'LLM_MAX_TOKENS', 512),
];

/* -------------------- normalize input -------------------- */
$provider = strtolower(trim((string)(read_post('provider',''))));
if (!in_array($provider, ['openai','openrouter','grok'], true)) {
    jexit(400, ['error' => 'provider must be one of: openai | openrouter | grok']);
}

$rawMessages = read_post('messages');
$prompt      = read_post('prompt', '');

$messages = null;
if ($rawMessages) {
    $decoded = json_decode($rawMessages, true);
    if (!is_array($decoded)) jexit(400, ['error'=>'messages must be a JSON array']);
    $messages = $decoded;
} elseif (is_string($prompt) && $prompt !== '') {
    $messages = [
        ['role'=>'system', 'content'=>'You are a helpful assistant.'],
        ['role'=>'user',   'content'=>$prompt],
    ];
} else {
    jexit(400, ['error'=>'either messages (JSON) or prompt (string) is required']);
}

$model       = read_post('model');
$temperature = read_post('temperature');
$max_tokens  = read_post('max_tokens');

$temperature = is_null($temperature) ? $CFG['TEMPERATURE'] : (float)$temperature;
$max_tokens  = is_null($max_tokens)  ? $CFG['MAX_TOKENS']  : (int)$max_tokens;

/* -------------------- providers -------------------- */
function call_openai(array $CFG, array $messages, ?string $overrideModel, float $temperature, int $maxTokens): array {
    $key = $CFG['OPENAI_API_KEY'];
    if (!$key) jexit(400, ['error'=>'OPENAI_API_KEY is not set in /etc/environment']);
    $endpoint = $CFG['OPENAI_ENDPOINT'];
    $model    = $overrideModel ?: $CFG['OPENAI_MODEL'];

    $payload = [
        'model'       => $model,
        'messages'    => $messages,
        'temperature' => $temperature,
        'max_tokens'  => $maxTokens,
    ];

    $headers = [
        'Authorization: Bearer '.$key,
        'Content-Type: application/json',
    ];

    $res = http_json_post($endpoint, $headers, $payload, 90);
    return normalize_openai_like('openai', $model, $res);
}

function call_openrouter(array $CFG, array $messages, ?string $overrideModel, float $temperature, int $maxTokens): array {
    $key = $CFG['OPENROUTER_API_KEY'];
    if (!$key) jexit(400, ['error'=>'OPENROUTER_API_KEY is not set in /etc/environment']);
    $endpoint = $CFG['OPENROUTER_ENDPOINT'];
    $model    = $overrideModel ?: $CFG['OPENROUTER_MODEL'];

    $payload = [
        'model'       => $model,
        'messages'    => $messages,
        'temperature' => $temperature,
        'max_tokens'  => $maxTokens,
    ];

    $headers = [
        'Authorization: Bearer '.$key,
        'Content-Type: application/json',
    ];
    if (!empty($CFG['OPENROUTER_REFERER'])) $headers[] = 'Referer: '.$CFG['OPENROUTER_REFERER'];
    if (!empty($CFG['OPENROUTER_REFERER'])) $headers[] = 'HTTP-Referer: '.$CFG['OPENROUTER_REFERER'];
    if (!empty($CFG['OPENROUTER_X_TITLE'])) $headers[] = 'X-Title: '.$CFG['OPENROUTER_X_TITLE'];

    $res = http_json_post($endpoint, $headers, $payload, 90);
    return normalize_openai_like('openrouter', $model, $res);
}

function call_grok(array $CFG, array $messages, ?string $overrideModel, float $temperature, int $maxTokens): array {
    $key = $CFG['XAI_API_KEY'];
    if (!$key) jexit(400, ['error'=>'XAI_API_KEY (or GROK_API_KEY) is not set in /etc/environment']);
    $endpoint = $CFG['XAI_ENDPOINT']; // xAI는 OpenAI 호환 /v1/chat/completions
    $model    = $overrideModel ?: $CFG['XAI_MODEL'];

    $payload = [
        'model'       => $model,
        'messages'    => $messages,
        'temperature' => $temperature,
        'max_tokens'  => $maxTokens,
    ];

    $headers = [
        'Authorization: Bearer '.$key,
        'Content-Type: application/json',
    ];

    $res = http_json_post($endpoint, $headers, $payload, 90);
    return normalize_openai_like('grok', $model, $res);
}

/* -------------------- HTTP helper -------------------- */
function http_json_post(string $url, array $headers, array $payload, int $timeoutSec=60): array {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT        => $timeoutSec,
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err) jexit(502, ['error'=>'cURL error', 'detail'=>sanitize_err($err)]);
    if ($code < 200 || $code >= 300) {
        $snippet = is_string($resp) ? mb_substr($resp,0,600) : '';
        jexit($code, ['error'=>'HTTP error', 'status'=>$code, 'detail'=>sanitize_err($snippet)]);
    }

    $json = json_decode((string)$resp, true);
    if (!is_array($json)) jexit(500, ['error'=>'Invalid JSON from upstream']);
    return $json;
}

/* -------------------- normalize (OpenAI-style) -------------------- */
function normalize_openai_like(string $provider, string $model, array $raw): array {
    $choice = $raw['choices'][0]['message']['content'] ?? null;
    $usage  = $raw['usage'] ?? null;
    return [
        'provider' => $provider,
        'model'    => $raw['model'] ?? $model,
        'created'  => $raw['created'] ?? null,
        'content'  => is_string($choice) ? $choice : null,
        'usage'    => is_array($usage) ? $usage : null,
        'raw'      => $raw, // 디버깅 참고 (민감키 없음)
    ];
}

/* -------------------- dispatch -------------------- */
try {
    if ($provider === 'openai') {
        $out = call_openai($CFG, $messages, $model, $temperature, $max_tokens);
    } elseif ($provider === 'openrouter') {
        $out = call_openrouter($CFG, $messages, $model, $temperature, $max_tokens);
    } else { // grok (xAI)
        $out = call_grok($CFG, $messages, $model, $temperature, $max_tokens);
    }
    jexit(200, $out);
} catch (Throwable $e) {
    jexit(500, ['error'=>'unhandled', 'detail'=>sanitize_err($e->getMessage())]);
}

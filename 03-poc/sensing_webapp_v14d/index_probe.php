<?php
/**
 * index_probe.php — Minimal page to prove DB + LLM + template are fine
 * Safe to run alongside your main app.
 */
require __DIR__ . '/errorguard.php';
$CONFIG = require __DIR__ . '/config.php';

function or_ping(array $CONFIG): array {
    if (!function_exists('curl_init')) {
        return [false, 'curl extension missing'];
    }
    $key = trim($CONFIG['openrouter']['api_key'] ?? '');
    $endpoint = trim($CONFIG['openrouter']['endpoint'] ?? 'https://openrouter.ai/api/v1/chat/completions');
    if ($key === '') return [false, 'OPENROUTER_API_KEY missing'];
    $payload = [
        "model" => $CONFIG['openrouter']['model'] ?? 'openai/gpt-4o-mini',
        "messages" => [
            ["role"=>"system", "content"=>"You are a healthcheck probe."],
            ["role"=>"user",   "content"=>"Say: OK"]
        ],
        "max_tokens" => 8,
        "temperature" => (float)($CONFIG['openrouter']['temperature'] ?? 0.2)
    ];
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => array_filter([
            "Content-Type: application/json",
            "Authorization: Bearer " . $key,
            ($CONFIG['openrouter']['http_referer'] ?? '') ? ("Referer: " . $CONFIG['openrouter']['http_referer']) : null,
            ($CONFIG['openrouter']['x_title'] ?? '') ? ("X-Title: " . $CONFIG['openrouter']['x_title']) : null,
        ]),
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 15,
    ]);
    $resp = curl_exec($ch);
    if ($resp === false) return [false, 'cURL: ' . curl_error($ch)];
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode($resp, true);
    $msg = $data['choices'][0]['message']['content'] ?? ('HTTP ' . $code);
    return [$code === 200, $msg];
}

$llm_ok = null; $llm_msg = '';
try {
    [$llm_ok, $llm_msg] = or_ping($CONFIG);
} catch (Throwable $e) {
    $llm_ok = false; $llm_msg = $e->getMessage();
}

$db_ok = null; $db_msg = '';
try {
    require __DIR__ . '/pdo_helper.php';
    $pdo = pdo_connect_or_throw($CONFIG);
    $res = $pdo->query('SELECT 1 AS ok')->fetch();
    $db_ok = isset($res['ok']); $db_msg = $db_ok ? 'SELECT 1 ok' : 'SELECT 1 failed';
} catch (Throwable $e) {
    $db_ok = false; $db_msg = $e->getMessage();
}

header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html lang="ko"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Index Probe</title>
<style>
 body{background:#0f172a;color:#e5e7eb;font-family:system-ui, Segoe UI, Roboto, 'Noto Sans KR', sans-serif;margin:0}
 .wrap{max-width:900px;margin:32px auto;padding:0 16px}
 .card{background:#111827;border:1px solid #1f2937;border-radius:16px;padding:20px;margin-bottom:16px}
 .ok{color:#86efac}.err{color:#fca5a5}.muted{color:#94a3b8}
</style></head><body>
<div class="wrap">
  <h1 class="muted">Index Probe</h1>

  <div class="card">
    <h3>LLM (OpenRouter)</h3>
    <p>Status: <?= $llm_ok ? "<span class='ok'>OK</span>" : "<span class='err'>ERR</span>" ?></p>
    <pre><?= htmlspecialchars($llm_msg, ENT_QUOTES, 'UTF-8') ?></pre>
  </div>

  <div class="card">
    <h3>Database</h3>
    <p>Status: <?= $db_ok ? "<span class='ok'>OK</span>" : "<span class='err'>ERR</span>" ?></p>
    <pre><?= htmlspecialchars($db_msg, ENT_QUOTES, 'UTF-8') ?></pre>
  </div>

  <div class="card">
    <h3>Environment</h3>
    <ul>
      <li>APP_ENV: <?= htmlspecialchars($CONFIG['app']['env'] ?? '(missing)', ENT_QUOTES, 'UTF-8') ?></li>
      <li>APP_TIMEZONE: <?= htmlspecialchars($CONFIG['app']['timezone'] ?? '(missing)', ENT_QUOTES, 'UTF-8') ?></li>
      <li>DB: <?= htmlspecialchars(($CONFIG['database']['driver'] ?? 'mysql') . '://' . ($CONFIG['database']['host'] ?? '?') . '/' . ($CONFIG['database']['name'] ?? '?'), ENT_QUOTES, 'UTF-8') ?></li>
    </ul>
    <p class="muted">TIP: add <code>?debug=1</code> to this URL for verbose errors.</p>
  </div>
</div>
</body></html>
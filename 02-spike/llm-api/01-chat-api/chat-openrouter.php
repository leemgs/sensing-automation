<?php
// .env 파일 로드
$env = @parse_ini_file(__DIR__ . '/.env');
$apiKey = $env["OPENROUTER_API_KEY"] ?? null;

if (!$apiKey) {
    http_response_code(500);
    echo "<pre>❌ OPENROUTER_API_KEY 가 .env 파일에 설정되어 있지 않습니다.</pre>";
    exit;
}

// -------------------------------
// 1) 요청 페이로드 정의
// -------------------------------
$data = [
    "model" => "openai/gpt-4o",
    "messages" => [
        ["role" => "system", "content" => "You are a helpful assistant."],
        ["role" => "user", "content" => "2010년, 2020년, 2030년(미래)의 시대가 각각 기술적으로 어떤 큰 변화가 생기는지 테이블로 요약 정리하고 시사점을 작성하세요."]
    ],
    "max_tokens" => 512,
    "temperature" => 0.7
];

// 사용자 질문 텍스트 (웹 출력용)
$userQuestion = $data["messages"][1]["content"] ?? "";

// -------------------------------
// 2) OpenRouter Chat Completions 호출
// -------------------------------
$ch = curl_init("https://openrouter.ai/api/v1/chat/completions");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        "Content-Type: application/json",
        "Authorization: Bearer " . $apiKey
    ],
    CURLOPT_POSTFIELDS => json_encode($data, JSON_UNESCAPED_UNICODE)
]);

$response = curl_exec($ch);
if ($response === false) {
    $err = curl_error($ch);
    curl_close($ch);
    http_response_code(502);
    echo "<pre>cURL Error: " . htmlspecialchars($err, ENT_QUOTES, 'UTF-8') . "</pre>";
    exit;
}
curl_close($ch);

// -------------------------------
// 3) 응답 파싱
// -------------------------------
$result = json_decode($response, true);
$answer = $result['choices'][0]['message']['content'] ?? "응답 없음";

// -------------------------------
// 4) 출력 (CLI vs Web)
// -------------------------------
if (php_sapi_name() === 'cli') {
    echo "🤖 모델 응답 (CLI)\n";
    echo "Question (by User):\n" . $userQuestion . "\n\n";
    echo "Answer (by LLM):\n" . $answer . "\n";
    exit;
}

// -------------------------------
// 5) 웹 HTML 렌더링 (가독성 높은 카드 UI)
// -------------------------------
$qEsc = htmlspecialchars($userQuestion, ENT_QUOTES, 'UTF-8');
$aEsc = htmlspecialchars($answer, ENT_QUOTES, 'UTF-8');

// HTML 템플릿
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Chat Completion Result</title>
<style>
  :root{
    --bg:#0f172a;         /* deep slate */
    --card:#111827;       /* near-black */
    --text:#e5e7eb;       /* slate-200 */
    --muted:#94a3b8;      /* slate-400 */
    --accent:#60a5fa;     /* blue-400 */
    --border:#1f2937;     /* gray-800 */
    --shadow:0 10px 30px rgba(0,0,0,.35);
  }
  html,body{background:var(--bg);color:var(--text);margin:0;font-family:ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,'Apple SD Gothic Neo','Noto Sans KR',sans-serif;}
  .wrap{max-width:960px;margin:48px auto;padding:0 16px;}
  .title{font-size:1.5rem;margin:0 0 18px 4px;color:var(--muted);letter-spacing:.2px}
  .card{
    background:linear-gradient(180deg, rgba(255,255,255,.02), rgba(255,255,255,.00));
    border:1px solid var(--border); border-radius:16px; box-shadow:var(--shadow);
    padding:22px 22px; margin-bottom:18px;
  }
  .label{display:flex;align-items:center;gap:10px;font-size:.9rem;color:var(--muted);margin-bottom:10px;letter-spacing:.3px}
  .label .dot{width:8px;height:8px;border-radius:999px;background:var(--accent);box-shadow:0 0 0 4px rgba(96,165,250,.15)}
  .content{white-space:pre-wrap;line-height:1.6;font-size:1.05rem}
  .grid{display:grid;grid-template-columns:1fr;gap:16px}
  @media (min-width:900px){ .grid{grid-template-columns:1fr 1fr} }
  .badge{display:inline-block;font-size:.72rem;color:#0b1220;background:var(--accent);padding:4px 8px;border-radius:999px;margin-left:8px}
</style>
</head>
<body>
  <div class="wrap">
    <div class="title">OpenRouter · Chat Completions</div>

    <div class="grid">
      <!-- 1) Question -->
      <section class="card">
        <div class="label"><span class="dot"></span><strong>1. Question (by User)</strong></div>
        <div class="content"><?= $qEsc ?></div>
      </section>

      <!-- 2) Answer -->
      <section class="card">
        <div class="label"><span class="dot"></span><strong>2. Answer (by LLM)</strong><span class="badge">generated</span></div>
        <div class="content"><?= nl2br($aEsc) ?></div>
      </section>
    </div>
  </div>
</body>
</html>

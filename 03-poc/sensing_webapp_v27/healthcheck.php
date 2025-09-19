<?php
declare(strict_types=1);
require_once __DIR__."/config.php";

header("Content-Type: text/plain; charset=UTF-8");

echo "=== PHP Info ===\n";
echo "PHP Version: ".phpversion()."\n";
echo "IMAP loaded: ".(extension_loaded('imap') ? 'yes' : 'no')."\n";
echo "Extensions: ".implode(", ", get_loaded_extensions())."\n\n";



/** ===== Helpers ===== */
function check_mailbox(string $label, string $mailbox, string $email, string $pass): void {
    echo "Protocol: {$label}\n";
    echo "Mailbox:  {$mailbox}\n";
    echo "Login:    {$email}\n";
    if (!$mailbox || !$email || !$pass) {
        echo "⚠️  Skipped: missing server/email/password in .env\n\n";
        return;
    }
    $mbox = @imap_open($mailbox, $email, $pass);
    if ($mbox === false) {
        $err = imap_last_error();
        echo "❌ LOGIN FAIL: ".($err ?: 'unknown error')."\n\n";
        return;
    }
    $num = @imap_num_msg($mbox);
    if ($num === false) $num = 0;
    echo "✅ LOGIN OK, Messages: {$num}\n";
    if ($num > 0) {
        $first = 1;
        $ov = @imap_fetch_overview($mbox, (string)$first, 0);
        if ($ov && isset($ov[0])) {
            $subj = isset($ov[0]->subject) ? @imap_utf8($ov[0]->subject) : '(no subject)';
            echo "   First message subject: ".(is_string($subj) ? $subj : '(decode fail)')."\n";
        }
    }
    imap_close($mbox);
    echo "\n";
}

function build_llm_headers(array $api): array {
    $apiKey = get_env_value($api['auth_env'] ?? '');
    $headers_kv = is_array($api['headers'] ?? null) ? ($api['headers'] ?? []) : [];
    if (!isset($headers_kv['Content-Type'])) { $headers_kv['Content-Type'] = 'application/json'; }

    $authType = strtoupper((string)($api['authorization'] ?? 'Bearer'));
    if ($authType === 'BASIC') {
        if ($apiKey !== '') $headers_kv['Authorization'] = 'Basic '.base64_encode($apiKey);
    } elseif ($authType === 'BEARER') {
        if ($apiKey !== '') $headers_kv['Authorization'] = 'Bearer '.$apiKey;
    }
    $out = [];
    foreach ($headers_kv as $k=>$v) $out[] = $k.': '.$v;
    return [$out, $apiKey];
}

function check_llm_api(array $api): void {
    $id = $api['id'] ?? '(no-id)';
    $endpoint = $api['endpoint'] ?? '';
    $model = $api['model'] ?? '';
    echo "- API: ".$id."\n";
    echo "  Endpoint: ".$endpoint."\n";
    echo "  Model: ".$model."\n";

    list($headers, $apiKey) = build_llm_headers($api);
    if ($apiKey === '') {
        echo "  ⚠️  Skipped: missing env key ".($api['auth_env'] ?? '(none)')."\n\n";
        return;
    }

    $payload = [
        'model' => $model,
        'messages' => [
            ['role'=>'system', 'content'=>'You are healthcheck.'],
            ['role'=>'user', 'content'=>'ping']
        ],
        'max_tokens' => 1,
        'temperature' => (float)($api['default_temperature'] ?? 0.0),
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $endpoint,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 20,
    ]);
    $resp = curl_exec($ch);
    if ($resp === false) {
        $err = curl_error($ch);
        curl_close($ch);
        echo "  ❌ Request error: ".$err."\n\n";
        return;
    }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code >= 200 && $code < 300) {
        $j = json_decode($resp, true);
        $ok = is_array($j) && isset($j['choices'][0]['message']['content']);
        echo $ok ? "  ✅ Auth OK (HTTP $code)\n\n" : "  ✅ HTTP $code but response not fully parsed\n\n";
    } else {
        $j = json_decode($resp, true);
        $emsg = is_array($j) && isset($j['error']) ? json_encode($j['error'], JSON_UNESCAPED_UNICODE) : $resp;
        echo "  ❌ HTTP $code: ".$emsg."\n\n";
    }
}

/** ===== Run checks ===== */
echo "=== Mail Checks ===\n";
// IMAP (993/ssl)
$imapServer = get_env_value("IMAP_SERVER");
$imapEmail  = get_env_value("IMAP_EMAIL");
$imapPass   = get_env_value("IMAP_PASSWORD");
$imapMbox   = $imapServer ? "{".$imapServer.":993/imap/ssl}INBOX" : "";
$imapUser = get_env_value("IMAP_USER") ?: $imapEmail;
check_mailbox("IMAP", $imapMbox, $imapUser, $imapPass);

// POP3 (995/ssl)
$popServer = get_env_value("POP3_SERVER");
$popEmail  = get_env_value("POP3_EMAIL");
$popPass   = get_env_value("POP3_PASSWORD");
$popMbox   = $popServer ? "{".$popServer.":995/pop3/ssl}INBOX" : "";
$popUser = get_env_value("POP3_USER") ?: $popEmail;
check_mailbox("POP3", $popMbox, $popUser, $popPass);

$proto = strtoupper(get_env_value("DEFAULT_MAIL_PROTOCOL") ?: "IMAP");
echo "Configured DEFAULT_MAIL_PROTOCOL: ".$proto."\n\n";

echo "=== LLM API Auth Checks ===\n";
$apilist = api_list_load();
$items = $apilist['items'] ?? [];
if (!$items) {
    echo "No LLM items found in llm-api-list.json\n";
} else {
    foreach ($items as $api) {
        check_llm_api($api);
    }
}

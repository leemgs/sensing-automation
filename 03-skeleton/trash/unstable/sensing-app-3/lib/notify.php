<?php
// lib/notify.php
declare(strict_types=1);

function post_webhook(string $message, ?string $webhook = null): bool {
    $url = $webhook ?: (getenv('SENSING_WEBHOOK_URL') ?: '');
    if (!$url) return false;

    $payload = json_encode([ "text" => $message ], JSON_UNESCAPED_UNICODE);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 10,
    ]);
    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return $errno === 0 and $http < 400;
}

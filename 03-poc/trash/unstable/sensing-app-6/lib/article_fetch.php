<?php
// lib/article_fetch.php
declare(strict_types=1);

function fetch_url_text(string $url, int $timeout = 12): ?string {
    $allowAll = getenv('SENSING_ALLOW_ALL_DOMAINS');
    $wlFile = __DIR__ . '/../domains_whitelist.json';
    $whitelist = [];
    if (is_file($wlFile)) { $whitelist = json_decode(@file_get_contents($wlFile), true) ?: []; }
    $host = parse_url($url, PHP_URL_HOST);
    if (!$allowAll && $whitelist && $host) {
        $ok=false;
        foreach ($whitelist as $dom) { if ($host === $dom || substr($host, -strlen('.'.$dom)) === '.'.$dom) { $ok=true; break; } }
        if (!$ok) { return null; }
    }
    if (!preg_match('#^https?://#i', $url)) return null;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; SensingBot/1.0)',
        CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'],
    ]);
    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    
    $proxy = getenv('HTTPS_PROXY') ?: getenv('HTTP_PROXY');
    if ($proxy) { curl_setopt($ch, CURLOPT_PROXY, $proxy); }
curl_close($ch);
    if ($errno || !$raw || $http >= 400) return null;

    // naive HTML -> text
    $raw = @mb_convert_encoding($raw, 'UTF-8', 'UTF-8, EUC-KR, CP949, ISO-8859-1, ASCII');
    $text = strip_tags($raw);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $text = preg_replace('/\s+/', ' ', $text);
    $text = trim($text);
    if ($text === '') return null;

    // LLM prompt 길이 보호
    $max = 4000;
    if (mb_strlen($text, 'UTF-8') > $max) {
        $text = mb_substr($text, 0, $max, 'UTF-8');
    }
    return $text;
}

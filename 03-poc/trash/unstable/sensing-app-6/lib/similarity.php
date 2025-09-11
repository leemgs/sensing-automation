<?php
// lib/similarity.php — lightweight duplicate detection
declare(strict_types=1);

function normalize_text(string $t): string {
    $t = mb_strtolower($t, 'UTF-8');
    $t = preg_replace('/\s+/', ' ', $t);
    return trim($t);
}
function text_signature(string $t): string {
    $n = normalize_text($t);
    return substr(sha1(mb_substr($n, 0, 256, 'UTF-8')), 0, 16);
}
function jaccard3(string $a, string $b): float {
    $a = preg_replace('/[^a-z0-9가-힣 ]+/u', ' ', mb_strtolower($a, 'UTF-8'));
    $b = preg_replace('/[^a-z0-9가-힣 ]+/u', ' ', mb_strtolower($b, 'UTF-8'));
    $aw = preg_split('/\s+/', trim($a));
    $bw = preg_split('/\s+/', trim($b));
    $ash = []; $bsh = [];
    for ($i=0; $i < max(0,count($aw)-2); $i++) $ash[implode(' ', array_slice($aw,$i,3))]=1;
    for ($i=0; $i < max(0,count($bw)-2); $i++) $bsh[implode(' ', array_slice($bw,$i,3))]=1;
    if (!$ash || !$bsh) return 0.0;
    $inter = count(array_intersect(array_keys($ash), array_keys($bsh)));
    $union = count($ash) + count($bsh) - $inter;
    if ($union <= 0) return 0.0;
    return $inter / $union;
}

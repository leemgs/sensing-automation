<?php
// lib/functions.php
declare(strict_types=1);

function ensure_dir(string $path): bool {
    if (is_dir($path)) return true;
    return @mkdir($path, 0775, true);
}

function first_writable_base(string $primary, string $fallbackLocal): string {
    if (@is_writable($primary) || (!file_exists($primary) && @mkdir($primary, 0775, true))) {
        return $primary;
    }
    ensure_dir($fallbackLocal);
    return $fallbackLocal;
}

function slugify(string $text): string {
    $text = preg_replace('~[^\pL\d]+~u', '-', $text);
    $text = trim($text, '-');
    $text = iconv('UTF-8', 'ASCII//TRANSLIT', $text);
    $text = preg_replace('~[^-\w]+~', '', $text);
    $text = preg_replace('~-+~', '-', $text);
    $text = strtolower($text);
    return $text ?: 'n-a';
}

function short_hash(string $s, int $len=8): string {
    return substr(sha1($s), 0, $len);
}

function log_msg(string $file, string $msg): void {
    $ts = date('Y-m-d H:i:s');
    @file_put_contents($file, "[$ts] $msg\n", FILE_APPEND);
}

function extract_links_from_html(string $html): array {
    $links = [];

    // 1) a[href] 태그
    if (preg_match_all('#<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is', $html, $m, PREG_SET_ORDER)) {
        foreach ($m as $x) {
            $url = html_entity_decode($x[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $text = strip_tags($x[2]);
            $links[] = ['url' => $url, 'text' => trim($text)];
        }
    }
    // 2) 맨 텍스트 URL
    if (preg_match_all('#https?://[^\s<>"\']+#i', $html, $m2)) {
        foreach ($m2[0] as $url) {
            $url = html_entity_decode($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            // 중복 제거
            $exists = false;
            foreach ($links as $L) {
                if ($L['url'] === $url) { $exists = true; break; }
            }
            if (!$exists) $links[] = ['url' => $url, 'text' => ''];
        }
    }
    return $links;
}

function save_html_file(string $base, string $category, string $subcat, string $html, string $urlForHash): array {
    // base: /var/www/html/sensing
    $fallback = __DIR__ . '/../sensing_out';
    $root = first_writable_base($base, $fallback);

    // 경로 선택
    if ($category === 'regulation') {
        $dir = "$root/regulation/$subcat";
    } elseif ($category === 'asset') {
        $dir = "$root/asset/$subcat";
    } else {
        $dir = "$root/misc/$subcat";
    }
    ensure_dir($dir);

    $ts = date('Ymd-Hi');
    $hash = short_hash($urlForHash, 8);
    $prefix = ($category === 'regulation') ? 'AI규제' : 'AI에셋';
    $fn = sprintf('%s-%s-%s-%s.html', $prefix, $subcat, $ts, $hash);
    $path = $dir . '/' . $fn;
    $ok = @file_put_contents($path, $html) !== false;
    return [$ok, $path];
}

function html_escape(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

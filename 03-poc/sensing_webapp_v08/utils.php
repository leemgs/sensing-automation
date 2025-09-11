<?php
declare(strict_types=1);

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function now_stamp(): string { return date('Ymd-Hi'); }
function now_fullstamp(): string { return date('Ymd-His'); }

function slug(string $s, int $max=60): string {
    $s = trim($s);
    $s = mb_substr($s, 0, $max);
    $s = preg_replace('/[^\p{L}\p{N}\-_. ]/u', '', $s) ?? '';
    $s = str_replace([' ', '__'], ['-', '_'], $s);
    return $s === '' ? 'untitled' : $s;
}

function save_html_by_route(string $group, string $category, string $html, string $uid='', string $subject=''): array {
    $map = [
        'AI규제' => [
            'governance' => SAVE_BASE.'/regulation/governance',
            'contract'   => SAVE_BASE.'/regulation/contract',
            'lawsuit'    => SAVE_BASE.'/regulation/lawsuit',
        ],
        'AI에셋' => [
            'data'  => SAVE_BASE.'/asset/data',
            'model' => SAVE_BASE.'/asset/model',
            'agent' => SAVE_BASE.'/asset/agent',
        ],
        '해당없음' => [
            '해당없음' => SAVE_BASE,
        ]
    ];
    $dir = $map[$group][$category] ?? SAVE_BASE;
    $uidp = $uid !== '' ? $uid : 'noUID';
    $sub = $subject !== '' ? slug($subject, 50) : 'untitled';
    $fname = sprintf('%s-%s-%s-%s-%s.html',
        ($group==='AI규제'||$group==='AI에셋')?$group:'해당없음',
        $category?:'misc',
        now_stamp(),
        $uidp,
        $sub
    );
    $path = $dir.'/'.$fname;
    @file_put_contents($path, $html);
    return [$path, $fname];
}

function save_llm_log(array $record): ?string {
    if (!is_dir(LOG_DIR)) @mkdir(LOG_DIR, 0775, true);
    if (!is_dir(LOG_DIR)) return null;
    $uid = $record['uid'] ?? 'noUID';
    $fname = 'llm-'.now_fullstamp().'-'.$uid.'.txt';
    $full = LOG_DIR.'/'.$fname;
    $txt = "=== LLM REQUEST ===\n".json_encode($record['request'], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).
           "\n\n=== LLM RESPONSE ===\n".$record['response'];
    @file_put_contents($full, $txt);
    return $full;
}

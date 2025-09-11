<?php
declare(strict_types=1);

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function now_stamp(): string { return date('Ymd-Hi'); }

/** 안전한 파일명용 슬러그 */
function slug(string $s, int $max=60): string {
    $s = trim($s);
    $s = mb_substr($s, 0, $max);
    $s = preg_replace('/[^\p{L}\p{N}\-_. ]/u', '', $s) ?? '';
    $s = str_replace([' ', '__'], ['-', '_'], $s);
    return $s === '' ? 'untitled' : $s;
}

/** HTML 저장 (경로 계산 포함) */
function save_html_by_route(string $group, string $category, string $html): array {
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
    ];
    $dir = $map[$group][$category] ?? SAVE_BASE;
    $fname = sprintf('%s-%s-%s.html', ($group==='AI규제'||$group==='AI에셋')?$group:'해당없음', $category?:'misc', now_stamp());
    $path = $dir.'/'.$fname;
    @file_put_contents($path, $html);
    return [$path, $fname];
}

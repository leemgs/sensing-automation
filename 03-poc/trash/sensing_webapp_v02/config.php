<?php
declare(strict_types=1);

mb_internal_encoding('UTF-8');
date_default_timezone_set('Asia/Seoul');

define('OPENROUTER_ENDPOINT', 'https://openrouter.ai/api/v1/chat/completions');
define('OPENROUTER_MODEL', 'openai/gpt-4o');
define('SAVE_BASE', '/var/www/html/sensing');

/** 환경 준비: 디렉터리 생성 */
function ensure_dirs(): void {
    $paths = [
        SAVE_BASE,
        SAVE_BASE.'/regulation',
        SAVE_BASE.'/regulation/governance',
        SAVE_BASE.'/regulation/contract',
        SAVE_BASE.'/regulation/lawsuit',
        SAVE_BASE.'/asset',
        SAVE_BASE.'/asset/data',
        SAVE_BASE.'/asset/model',
        SAVE_BASE.'/asset/agent',
    ];
    foreach ($paths as $p) { if (!is_dir($p)) @mkdir($p, 0775, true); }
}

/** OpenRouter 키 로딩: getenv → env → /etc/environment */
function get_openrouter_key(): string {
    $k = getenv('OPENROUTER_API_KEY');
    if ($k && trim($k) !== '') return trim($k);

    $env = @shell_exec('/usr/bin/env');
    if (is_string($env) && preg_match('/OPENROUTER_API_KEY=(.+)/', $env, $m)) {
        $k = trim($m[1]);
        if ($k !== '') return $k;
    }
    $k = @shell_exec('/bin/bash -lc "source /etc/environment >/dev/null 2>&1; echo -n $OPENROUTER_API_KEY"');
    if (is_string($k) && trim($k) !== '') return trim($k);

    return '';
}


<?php
declare(strict_types=1);
mb_internal_encoding('UTF-8');
date_default_timezone_set('Asia/Seoul');

define('OPENROUTER_ENDPOINT', 'https://openrouter.ai/api/v1/chat/completions');
define('OPENROUTER_MODEL', 'openai/gpt-4o');
define('SAVE_BASE', '/var/www/html/sensing');
define('LOG_DIR', SAVE_BASE.'/logs');

// 기본 LLM 파라미터
define('DEFAULT_MAX_TOKENS', 1400);
define('DEFAULT_TEMPERATURE', 0.2);

function load_env_array(): array {
    $arr = [];
    if (is_file('/etc/environment')) {
        $parsed = @parse_ini_file('/etc/environment', false, INI_SCANNER_RAW);
        if (is_array($parsed)) $arr = $parsed;
    }
    return $arr;
}

function get_env_value(string $key): string {
    $v = getenv($key);
    if ($v && trim($v) !== '') return trim($v);

    $arr = load_env_array();
    if (isset($arr[$key])) {
        $vv = trim((string)$arr[$key]);
        return trim($vv, "\"'");
    }

    $vv = @shell_exec('/bin/bash -lc "source /etc/environment >/dev/null 2>&1; echo -n $'.$key.'"');
    if (is_string($vv) && trim($vv) !== '') return trim($vv);
    return '';
}

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
        LOG_DIR,
    ];
    foreach ($paths as $p) if (!is_dir($p)) @mkdir($p, 0775, true);
}

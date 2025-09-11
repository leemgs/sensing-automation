<?php
declare(strict_types=1);
mb_internal_encoding('UTF-8');
date_default_timezone_set('Asia/Seoul');

define('SAVE_BASE', '/var/www/html/sensing');
define('LOG_DIR', SAVE_BASE.'/logs');
define('API_LIST_FILE', __DIR__.'/llm-api-list.json');

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

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

// ---- LLM API List helpers ----
function api_list_load(): array {
    $path = API_LIST_FILE;
    if (!is_file($path)) return ['active'=>'openrouter', 'items'=>[]];
    $txt = @file_get_contents($path);
    $json = json_decode((string)$txt, true);
    if (!is_array($json)) $json = ['active'=>'openrouter', 'items'=>[]];
    return $json;
}

function api_list_save(array $data): bool {
    $path = API_LIST_FILE;
    $dir = dirname($path);
    $can_write = (file_exists($path) ? is_writable($path) : is_writable($dir));
    if (!$can_write) return false;
    $txt = json_encode($data, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
    return @file_put_contents($path, $txt) !== false;
}

function api_get_active(array $apilist, ?string $force_id=null): array {
    $id = $force_id ?: ($apilist['active'] ?? 'openrouter');
    $items = $apilist['items'] ?? [];
    foreach ($items as $it) if (($it['id'] ?? '') === $id) return $it;
    // fallback 첫 항목
    return $items ? $items[0] : [
        'id'=>'openrouter',
        'label'=>'OpenRouter GPT-4o',
        'endpoint'=>'https://openrouter.ai/api/v1/chat/completions',
        'model'=>'openai/gpt-4o',
        'auth_env'=>'OPENROUTER_API_KEY',
        'headers'=>['Content-Type'=>'application/json'],
    ];
}

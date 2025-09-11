<?php
// config.php
declare(strict_types=1);
mb_internal_encoding('UTF-8');
date_default_timezone_set('Asia/Seoul');

// ===== IMAP 설정 =====
$IMAP_HOST = getenv('IMAP_HOST') ?: 'imap.gmail.com';
$IMAP_PORT = intval(getenv('IMAP_PORT') ?: 993);
$IMAP_ENCRYPTION = getenv('IMAP_ENCRYPTION') ?: '/imap/ssl/validate-cert';
$IMAP_USER = getenv('IMAP_USER') ?: 'leemgs.sensing@gmail.com'; // TODO
$IMAP_PASS = getenv('IMAP_PASS') ?: 'jvbdfjhrhcdxtvmr';     // TODO

// ===== 저장 기본 경로 (권한 없으면 lib/functions.php에서 로컬로 폴백) =====
$SENSING_BASE = '/var/www/html/sensing';

// ===== LLM API 설정 =====
$LLM_ENDPOINT = 'https://inference-webtrial-api.shuttle.sr-cloud.com/gauss2-37b-instruct/v1/chat/completions';
$LLM_MODEL = 'gauss2-37b-instruct';

// API 키는 /etc/environment 의 AAH_API_KEY 또는 환경변수 AAH_API_KEY 에서 읽음
function aah_api_key(): ?string {
    $env = getenv('AAH_API_KEY');
    if ($env && trim($env) !== '') return $env;

    $etc = @file('/etc/environment', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($etc !== false) {
        foreach ($etc as $line) {
            if (strpos($line, 'AAH_API_KEY=') === 0) {
                $val = trim(substr($line, strlen('AAH_API_KEY=')));
                $val = trim($val, "\"'");
                if ($val !== '') return $val;
            }
        }
    }
    return null;
}

// ===== 로그 =====
$LOG_FILE = __DIR__ . '/sensing.log';

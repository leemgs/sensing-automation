
<?php
// Load .env file if present (very small loader, no external deps)
(function() {
    $envPath = __DIR__ . '/.env';
    if (file_exists($envPath) && is_readable($envPath)) {
        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || $line[0] === ';') continue;
            $pos = strpos($line, '=');
            if ($pos === false) continue;
            $name = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));
            if ($value !== '' && ($value[0] === '"' || $value[0] === "'") && substr($value, -1) === $value[0]) {
                $value = substr($value, 1, -1);
            }
            if (!getenv($name)) {
                putenv($name . '=' . $value);
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }
    }
})();

return [
    // === Mail protocol (choose: 'imap' or 'pop3') ===
    'mail_protocol' => (getenv('MAIL_PROTOCOL') ?: 'imap'),

    // === Gmail IMAP/POP settings ===
    'username'   => (getenv('GMAIL_USERNAME') ?: 'yourname@gmail.com'),
    'password'   => (getenv('GMAIL_APP_PASSWORD') ?: 'xxxx xxxx xxxx xxxx'), // 앱 비밀번호
    'imap_host'  => (getenv('IMAP_HOST') ?: 'imap.gmail.com'),
    'imap_port'  => (int)(getenv('IMAP_PORT') ?: 993),
    'pop3_host'  => (getenv('POP3_HOST') ?: 'pop.gmail.com'),
    'pop3_port'  => (int)(getenv('POP3_PORT') ?: 995),
    // IMAP 전용: 폴더(라벨) 경로. POP3는 INBOX만 지원
    'mailbox'    => (getenv('MAILBOX') ?: 'INBOX'),

    // === Gmail IMAP 접속 ===
    'username'   => (getenv('GMAIL_USERNAME') ?: 'yourname@gmail.com'),
    'password'   => (getenv('GMAIL_APP_PASSWORD') ?: 'xxxx xxxx xxxx xxxx'), // 앱 비밀번호 또는 IMAP 비밀번호
    'imap_host'  => 'imap.gmail.com',
    'imap_port'  => 993,
    'mailbox'    => 'INBOX',

    // === 동작 옵션 ===
    'mark_as_seen'   => false, // fetch 시 읽음 표시 여부
    'poll_interval'  => 30,    // index.php 자동 갱신 주기(초)
    'max_messages'   => 30,    // fetch 최대 메일 개수

    // === DB (SQLite 또는 MySQL) ===
    'pdo_dsn'   => (getenv('DB_DSN') ?: 'sqlite:/var/www/data/mailcache.sqlite'),
    'pdo_user'  => (getenv('DB_USER') ?: null),
    'pdo_pass'  => (getenv('DB_PASS') ?: null),
    // MySQL 예시(사용 시 윗 줄 대신 아래 주석 해제):
    // 'pdo_dsn'   => (getenv('DB_DSN') ?: 'sqlite:/var/www/data/mailcache.sqlite'),
    // 'pdo_user'  => (getenv('DB_USER') ?: null),
    // 'pdo_pass'  => (getenv('DB_PASS') ?: null),

    // === API 공급자 선택 ===
    'api_provider' => 'openai', // 'openai' | 'leemgs'

    // --- OpenAI 설정 ---
    'openai_api_key' => (getenv('OPENAI_API_KEY') ?: 'sk-xxxxxxxxxxxxxxxxxxxxxxxx'),
    'openai_model'   => 'gpt-4o-mini',
    'openai_url'     => 'https://api.openai.com/v1/chat/completions',

    // --- leemgs (Inference Cloud) 설정 ---
    'leemgs_api_key' => (getenv('OPENROUTER_API_KEY') ?: 'sk-or-xxxxxxxx...'), 
    'leemgs_model'   => 'openai/gpt-4o',
    'leemgs_url'     => 'https://openrouter.ai/api/v1/chat/completions',

    // === 저장 폴더(웹에서 바로 접근 가능 경로 권장) ===
    'lawsuit_dir'     => __DIR__ . '/소송',
    'contract_dir'    => __DIR__ . '/계약',
    'governance_dir'  => __DIR__ . '/거버넌스',
    'archive_dir'     => __DIR__ . '/보관',

    // === 브랜딩(저장 HTML/아카이브 상단/하단) ===
    'branding' => [
        'logo_url'        => '/static/logo.png', // 없으면 표시 안 함
        'disclaimer_html' => '※ 본 문서는 내부 검토용입니다. 무단 배포 금지.',
    ],

    // === 관리자 액션 보호용 토큰(보관/삭제/복원) ===
    'admin_token' => (getenv('ADMIN_TOKEN') ?: 'change-this-long-random-token'),

    // === 감사 로그 파일 ===
    'audit_log'  => __DIR__ . '/audit_log.csv',

    // === 분석 실행 전역 가드: 특정 라벨이 붙은 메일만 실행 ===
    'analysis_label_filter' => ['업무'], // 예: ['업무','Legal']
    'analysis_match_mode'   => 'any',    // any | all
    'restrict_imap_search'  => false,    // true면 수집 자체를 라벨로 제한

    // === 라벨 → 카테고리 매핑(키워드 없이도 라우팅) ===
    'category_label_map' => [
        'lawsuit'    => ['업무-소송', 'legal-lawsuit', '소송'],
        'contract'   => ['업무-계약', 'contract', '계약'],
        'governance' => ['업무-거버넌스', 'governance', '거버넌스', 'policy'],
    ],
    'category_label_match' => 'exact', // exact | prefix

    // === 키워드(라벨 매핑 미히트 시 보조 트리거) ===
    'keywords' => [
        'lawsuit'    => ['소송','complaint','lawsuit','집단소송'],
        'contract'   => ['계약','계약서','합의','agreement','contract','mou'],
        'governance' => ['거버넌스','governance','policy','policies','정책','규정','compliance'],
    ],

    // === 선택 옵션 ===
    'always_analysis'   => false,                        // 모든 메일 분석(비용↑)
    'exclusive_routing' => true,                         // 배타 라우팅
    'exclusive_priority'=> ['lawsuit','contract','governance'], // 우선순위
];

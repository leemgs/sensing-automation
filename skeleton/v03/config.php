<?php
return [
    // === Gmail IMAP 접속 ===
    'username'   => 'yourname@gmail.com',
    'password'   => 'xxxx xxxx xxxx xxxx', // 앱 비밀번호 또는 IMAP 비밀번호
    'imap_host'  => 'imap.gmail.com',
    'imap_port'  => 993,
    'mailbox'    => 'INBOX',

    // === 동작 옵션 ===
    'mark_as_seen'   => false, // fetch 시 읽음 표시 여부
    'poll_interval'  => 30,    // index.php 자동 갱신 주기(초)
    'max_messages'   => 30,    // fetch 최대 메일 개수

    // === DB (SQLite 또는 MySQL) ===
    'pdo_dsn'   => 'sqlite:/var/www/data/mailcache.sqlite',
    'pdo_user'  => null,
    'pdo_pass'  => null,
    // MySQL 예시(사용 시 윗 줄 대신 아래 주석 해제):
    // 'pdo_dsn'  => 'mysql:host=127.0.0.1;dbname=mailcache;charset=utf8mb4',
    // 'pdo_user' => 'dbuser',
    // 'pdo_pass' => 'dbpass',

    // === API 공급자 선택 ===
    'api_provider' => 'openai', // 'openai' | 'leemgs'

    // --- OpenAI 설정 ---
    'openai_api_key' => 'sk-xxxxxxxxxxxxxxxxxxxxxxxx',
    'openai_model'   => 'gpt-4o-mini',
    'openai_url'     => 'https://api.openai.com/v1/chat/completions',

    // --- leemgs (Inference Cloud) 설정 ---
    'leemgs_api_key' => 'base64encoded-key-here', 
    'leemgs_model'   => 'myllm-30b-instruct',
    'leemgs_url'     => 'https://www.inference-cloud.com/myllm-30b-instruct/v1/chat/completions',

    // === 저장 폴더(웹에서 바로 접근 가능 경로 권장) ===
    'lawsuit_dir'     => __DIR__ . '/소송',
    'contract_dir'    => __DIR__ . '/계약',
    'governance_dir'  => __DIR__ . '/거버넌스',
    'archive_dir'     => __DIR__ . '/보관',

    // === 브랜딩(저장 HTML/아카이브 상단/하단) ===
    'branding' => [
        'logo_url'        => './static/logo.png', // 없으면 표시 안 함
        'disclaimer_html' => '※ 본 문서는 내부 검토용입니다. 무단 배포 금지.',
    ],

    // === 관리자 액션 보호용 토큰(보관/삭제/복원) ===
    'admin_token' => 'change-this-long-random-token',

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

<?php
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
function safe_filename(string $s): string {
    $s = trim($s);
    $s = preg_replace('/[^\p{Hangul}\p{Han}\p{Latin}\p{N}\s\-\_\.]/u', '_', $s);
    $s = preg_replace('/\s+/u', ' ', $s);
    return $s;
}
function ymd_compact(string $ymd): string {
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) return str_replace('-', '', $ymd);
    return $ymd;
}
function contains_keywords(string $subject, string $body, array $keywords): bool {
    $hay = mb_strtolower($subject . "\n" . $body, 'UTF-8');
    foreach ($keywords as $kw) {
        $kw = mb_strtolower($kw, 'UTF-8');
        if (mb_strpos($hay, $kw) !== false) return true;
    }
    return false;
}

function call_chat_api(array $cfg, array $messages, float $temperature=0.0) {
    $provider = $cfg['api_provider'] ?? 'openai';

    if ($provider === 'openai') {
        $url = $cfg['openai_url'];
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $cfg['openai_api_key'],
        ];
        $body = [
            'model' => $cfg['openai_model'],
            'messages' => $messages,
            'temperature' => $temperature,
        ];
    } elseif ($provider === 'leemgs') {
        $url = $cfg['leemgs_url'];
        $headers = [
            'Content-Type: application/json',
            'Authorization: Basic ' . $cfg['leemgs_api_key'],
        ];
        $body = [
            'model' => $cfg['leemgs_model'],
            'messages' => $messages,
            'stream' => false,
        ];
    } else {
        throw new RuntimeException('Unknown API provider: ' . $provider);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 30,
    ]);
    $res = curl_exec($ch);
    if ($res === false) throw new RuntimeException('HTTP Error: ' . curl_error($ch));
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($status < 200 || $status >= 300) throw new RuntimeException('API HTTP ' . $status . ' : ' . $res);
    $obj = json_decode($res, true);
    $content = $obj['choices'][0]['message']['content'] ?? '';
    return $content;
}

function openai_chat_json(array $cfg, array $messages): array {
    $content = call_chat_api($cfg, $messages, 0.0);
    $json = json_decode($content, true);
    if (!is_array($json)) return [];
    return $json;
}

function openai_extract_lawsuit(array $cfg, string $subject, string $body): ?array {
    $messages = [
        ['role'=>'system','content'=>'한국어 JSON만. 이메일이 "AI 모델 학습용 데이터 소송" 관련인지 판별하고 필드를 추출.'],
        ['role'=>'user','content'=>
            "제목:\n{$subject}\n\n본문:\n{$body}\n\n".
            "출력 스키마:\n".
            "{ \"is_lawsuit\": true|false, \"data\": { ".
            "\"소송제목\":\"\",\"소송번호\":\"\",\"원고\":\"\",\"피고\":\"\",\"소송이유\":\"\",\"소송데이타\":\"\",\"해당제품\":\"\",\"배경\":\"\",\"개요\":\"\",\"소송금액\":\"\",\"소송법원\":\"\",\"소송국가\":\"\",\"소송날짜\":\"YYYY-MM-DD\" }, \"신뢰도\":0.0~1.0 }\n".
            "모르면 빈 문자열. JSON 외 텍스트 금지."
        ],
    ];
    return openai_chat_json($cfg, $messages);
}

function openai_extract_contract(array $cfg, string $subject, string $body): ?array {
    $messages = [
        ['role'=>'system','content'=>'한국어 JSON만. 이메일이 "AI 데이터셋 계약" 관련인지 판별하고 필드를 추출.'],
        ['role'=>'user','content'=>
            "제목:\n{$subject}\n\n본문:\n{$body}\n\n".
            "출력 스키마:\n".
            "{ \"is_contract\": true|false, \"data\": { ".
            "\"계약제목\":\"\",\"계약번호\":\"\",\"계약상대방\":\"\",\"계약일자\":\"YYYY-MM-DD\",\"데이터셋명\":\"\",\"데이터범위\":\"\",\"사용목적\":\"\",\"권리의무\":\"\",\"보안준수\":\"\",\"관할/준거법\":\"\",\"기간\":\"\" }, \"신뢰도\":0.0~1.0 }\n".
            "모르면 빈 문자열. JSON 외 텍스트 금지."
        ],
    ];
    return openai_chat_json($cfg, $messages);
}

function openai_extract_governance(array $cfg, string $subject, string $body): ?array {
    $messages = [
        ['role'=>'system','content'=>'한국어 JSON만. 이메일이 "AI 거버넌스/정책" 관련인지 판별하고 필드를 추출.'],
        ['role'=>'user','content'=>
            "제목:\n{$subject}\n\n본문:\n{$body}\n\n".
            "출력 스키마:\n".
            "{ \"is_governance\": true|false, \"data\": { ".
            "\"정책명\":\"\",\"적용범위\":\"\",\"관련규정\":\"\",\"데이터유형\":\"\",\"처리원칙\":\"\",\"보관기간\":\"\",\"책임부서\":\"\",\"국가\":\"\",\"발효일자\":\"YYYY-MM-DD\" }, \"신뢰도\":0.0~1.0 }\n".
            "모르면 빈 문자열. JSON 외 텍스트 금지."
        ],
    ];
    return openai_chat_json($cfg, $messages);
}

function save_lawsuit_html(array $cfg, array $data, array $meta = []): string {
    $dir = $cfg['lawsuit_dir'] ?? (__DIR__ . '/소송');
    if (!is_dir($dir)) { if (!mkdir($dir, 0775, true)) throw new RuntimeException('소송 폴더 생성 실패: ' . $dir); }

    $branding = $cfg['branding'] ?? [];
    $logo = $branding['logo_url'] ?? '';
    $disc = $branding['disclaimer_html'] ?? '';

    $제목 = $data['소송제목'] ?: '제목미상';
    $날짜 = $data['소송날짜'] ?: ($meta['날짜'] ?? date('Y-m-d'));
    $라벨 = $meta['라벨'] ?? '';
    $보낸사람 = $meta['보낸사람'] ?? '';

    $파일날짜 = ymd_compact($날짜);
    $파일제목 = safe_filename($제목);
    $filename = "{$파일날짜}-{$파일제목}.html";
    $path = rtrim($dir, '/').'/'.$filename;

    $logoHtml = $logo ? '<div style="margin-bottom:12px"><img src="'.h($logo).'" alt="logo" style="max-height:40px"></div>' : '';
    $discHtml = $disc ? '<div style="margin-top:16px;color:#6b7280;font-size:.95rem">'.$disc.'</div>' : '';

    $html = '<!doctype html><html lang="ko"><meta charset="utf-8">'.
    '<title>'.h($제목).'</title>'.
    '<style>
        body{font-family:system-ui,Segoe UI,Roboto,Arial,sans-serif;line-height:1.65;padding:20px;max-width:980px;margin:auto}
        h1{margin:0 0 12px}
        h2{margin:22px 0 8px;font-size:1.05rem}
        .grid{display:grid;grid-template-columns:180px 1fr;gap:8px 16px}
        .k{color:#555}.meta{margin-bottom:14px}
        .box{border:1px solid #e5e7eb;border-radius:10px;padding:12px}
    </style><body>'.
    $logoHtml.
    '<h1>소송 정보</h1>'.
    '<div class="meta"><span class="k">소송 날짜:</span> '.h($날짜).
    ' &nbsp; <span class="k">보낸사람:</span> '.h($보낸사람).
    ' &nbsp; <span class="k">라벨:</span> '.h($라벨).'</div>'.
    '<div class="grid box">
        <div class="k">소송제목</div><div>'.h($data['소송제목'] ?? '').'</div>
        <div class="k">소송번호</div><div>'.h($data['소송번호'] ?? '').'</div>
        <div class="k">원고</div><div>'.h($data['원고'] ?? '').'</div>
        <div class="k">피고</div><div>'.h($data['피고'] ?? '').'</div>
        <div class="k">소송 금액</div><div>'.h($data['소송금액'] ?? '').'</div>
        <div class="k">소송 법원</div><div>'.h($data['소송법원'] ?? '').'</div>
        <div class="k">소송 국가</div><div>'.h($data['소송국가'] ?? '').'</div>
    </div>'.
    '<h2>소송 이유</h2><div class="box">'.nl2br(h($data['소송이유'] ?? '')).'</div>'.
    '<h2>소송 데이타</h2><div class="box">'.nl2br(h($data['소송데이타'] ?? '')).'</div>'.
    '<h2>해당 제품</h2><div class="box">'.nl2br(h($data['해당제품'] ?? '')).'</div>'.
    '<h2>배경</h2><div class="box">'.nl2br(h($data['배경'] ?? '')).'</div>'.
    '<h2>개요</h2><div class="box">'.nl2br(h($data['개요'] ?? '')).'</div>'.
    $discHtml.
    '</body></html>';

    file_put_contents($path, $html);
    return $path;
}

function save_contract_html(array $cfg, array $data, array $meta = []): string {
    $dir = $cfg['contract_dir'] ?? (__DIR__ . '/계약');
    if (!is_dir($dir)) { if (!mkdir($dir, 0775, true)) throw new RuntimeException('계약 폴더 생성 실패: ' . $dir); }

    $branding = $cfg['branding'] ?? [];
    $logo = $branding['logo_url'] ?? '';
    $disc = $branding['disclaimer_html'] ?? '';

    $제목 = $data['계약제목'] ?: ($meta['제목'] ?? '제목미상');
    $날짜 = $data['계약일자'] ?: ($meta['날짜'] ?? date('Y-m-d'));
    $라벨 = $meta['라벨'] ?? '';
    $보낸사람 = $meta['보낸사람'] ?? '';

    $파일날짜 = ymd_compact($날짜);
    $파일제목 = safe_filename($제목);
    $filename = "{$파일날짜}-{$파일제목}.html";
    $path = rtrim($dir, '/').'/'.$filename;

    $logoHtml = $logo ? '<div style="margin-bottom:12px"><img src="'.h($logo).'" alt="logo" style="max-height:40px"></div>' : '';
    $discHtml = $disc ? '<div style="margin-top:16px;color:#6b7280;font-size:.95rem">'.$disc.'</div>' : '';

    $html = '<!doctype html><html lang="ko"><meta charset="utf-8">'.
    '<title>'.h($제목).'</title>'.
    '<style>
      body{font-family:system-ui,Segoe UI,Roboto,Arial,sans-serif;line-height:1.65;padding:20px;max-width:980px;margin:auto}
      h1{margin:0 0 12px}.grid{display:grid;grid-template-columns:180px 1fr;gap:8px 16px}
      .k{color:#555}.meta{margin-bottom:14px}.box{border:1px solid #e5e7eb;border-radius:10px;padding:12px}
    </style><body>'.
    $logoHtml.
    '<h1>AI 데이터셋 계약</h1>'.
    '<div class="meta"><span class="k">계약 일자:</span> '.h($날짜).
    ' &nbsp; <span class="k">보낸사람:</span> '.h($보낸사람).
    ' &nbsp; <span class="k">라벨:</span> '.h($라벨).'</div>'.
    '<div class="grid box">
      <div class="k">계약제목</div><div>'.h($data['계약제목'] ?? '').'</div>
      <div class="k">계약번호</div><div>'.h($data['계약번호'] ?? '').'</div>
      <div class="k">계약상대방</div><div>'.h($data['계약상대방'] ?? '').'</div>
      <div class="k">데이터셋명</div><div>'.h($data['데이터셋명'] ?? '').'</div>
      <div class="k">데이터범위</div><div>'.h($data['데이터범위'] ?? '').'</div>
      <div class="k">사용목적</div><div>'.h($data['사용목적'] ?? '').'</div>
      <div class="k">권리/의무</div><div>'.h($data['권리의무'] ?? '').'</div>
      <div class="k">보안/준수</div><div>'.h($data['보안준수'] ?? '').'</div>
      <div class="k">관할/준거법</div><div>'.h($data['관할/준거법'] ?? '').'</div>
      <div class="k">기간</div><div>'.h($data['기간'] ?? '').'</div>
    </div>'.
    $discHtml.
    '</body></html>';

    file_put_contents($path, $html);
    return $path;
}

function save_governance_html(array $cfg, array $data, array $meta = []): string {
    $dir = $cfg['governance_dir'] ?? (__DIR__ . '/거버넌스');
    if (!is_dir($dir)) { if (!mkdir($dir, 0775, true)) throw new RuntimeException('거버넌스 폴더 생성 실패: ' . $dir); }

    $branding = $cfg['branding'] ?? [];
    $logo = $branding['logo_url'] ?? '';
    $disc = $branding['disclaimer_html'] ?? '';

    $제목 = $data['정책명'] ?: ($meta['제목'] ?? '제목미상');
    $날짜 = $data['발효일자'] ?: ($meta['날짜'] ?? date('Y-m-d'));
    $라벨 = $meta['라벨'] ?? '';
    $보낸사람 = $meta['보낸사람'] ?? '';

    $파일날짜 = ymd_compact($날짜);
    $파일제목 = safe_filename($제목);
    $filename = "{$파일날짜}-{$파일제목}.html";
    $path = rtrim($dir, '/').'/'.$filename;

    $logoHtml = $logo ? '<div style="margin-bottom:12px"><img src="'.h($logo).'" alt="logo" style="max-height:40px"></div>' : '';
    $discHtml = $disc ? '<div style="margin-top:16px;color:#6b7280;font-size:.95rem">'.$disc.'</div>' : '';

    $html = '<!doctype html><html lang="ko"><meta charset="utf-8">'.
    '<title>'.h($제목).'</title>'.
    '<style>
      body{font-family:system-ui,Segoe UI,Roboto,Arial,sans-serif;line-height:1.65;padding:20px;max-width:980px;margin:auto}
      h1{margin:0 0 12px}.grid{display:grid;grid-template-columns:180px 1fr;gap:8px 16px}
      .k{color:#555}.meta{margin-bottom:14px}.box{border:1px solid #e5e7eb;border-radius:10px;padding:12px}
    </style><body>'.
    $logoHtml.
    '<h1>AI 거버넌스 문서</h1>'.
    '<div class="meta"><span class="k">발효 일자:</span> '.h($날짜).
    ' &nbsp; <span class="k">보낸사람:</span> '.h($보낸사람).
    ' &nbsp; <span class="k">라벨:</span> '.h($라벨).'</div>'.
    '<div class="grid box">
      <div class="k">정책명</div><div>'.h($data['정책명'] ?? '').'</div>
      <div class="k">적용범위</div><div>'.h($data['적용범위'] ?? '').'</div>
      <div class="k">관련규정</div><div>'.h($data['관련규정'] ?? '').'</div>
      <div class="k">데이터유형</div><div>'.h($data['데이터유형'] ?? '').'</div>
      <div class="k">처리원칙</div><div>'.h($data['처리원칙'] ?? '').'</div>
      <div class="k">보관기간</div><div>'.h($data['보관기간'] ?? '').'</div>
      <div class="k">책임부서</div><div>'.h($data['책임부서'] ?? '').'</div>
      <div class="k">국가</div><div>'.h($data['국가'] ?? '').'</div>
    </div>'.
    $discHtml.
    '</body></html>';

    file_put_contents($path, $html);
    return $path;
}

function save_generic_html(array $cfg, string $targetDir, array $meta): string {
    $dir = $targetDir;
    if (!is_dir($dir)) if (!mkdir($dir, 0775, true)) throw new RuntimeException('폴더 생성 실패: '.$dir);

    $branding = $cfg['branding'] ?? [];
    $logo = $branding['logo_url'] ?? '';
    $disc = $branding['disclaimer_html'] ?? '';

    $제목 = $meta['제목'] ?: '제목미상';
    $날짜 = $meta['날짜'] ?: date('Y-m-d');
    $파일날짜 = ymd_compact($날짜);
    $파일제목 = safe_filename($제목);
    $filename = "{$파일날짜}-{$파일제목}.html";
    $path = rtrim($dir, '/').'/'.$filename;

    $logoHtml = $logo ? '<div style="margin-bottom:12px"><img src="'.h($logo).'" alt="logo" style="max-height:40px"></div>' : '';
    $discHtml = $disc ? '<div style="margin-top:16px;color:#6b7280;font-size:.95rem">'.$disc.'</div>' : '';

    $html = '<!doctype html><html lang="ko"><meta charset="utf-8">'.
    '<title>'.h($제목).'</title>'.
    '<style>body{font-family:system-ui,Segoe UI,Roboto,Arial,sans-serif;line-height:1.65;padding:20px;max-width:980px;margin:auto}
    h1{margin:0 0 12px}.k{color:#555}.meta{margin-bottom:14px}</style><body>'.
    $logoHtml.
    '<h1>'.h($제목).'</h1>'.
    '<div class="meta"><span class="k">날짜:</span> '.h($날짜).
    ' &nbsp; <span class="k">보낸사람:</span> '.h($meta['보낸사람'] ?? '').
    ' &nbsp; <span class="k">라벨:</span> '.h($meta['라벨'] ?? '').'</div>'.
    '<hr><div>'.nl2br(h($meta['본문'] ?? '')).'</div>'.
    $discHtml.
    '</body></html>';

    file_put_contents($path, $html);
    return $path;
}

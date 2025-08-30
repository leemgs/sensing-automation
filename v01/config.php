<?php
// ★ 보안상 실제 운영에선 웹루트 밖에 두고 include 하세요.
return [
    // Gmail 주소(아이디)
    'username' => 'yourname@gmail.com',
    // 2단계 인증 계정의 "앱 비밀번호" (16자리 공백x)
    'password' => 'xxxx xxxx xxxx xxxx',
    // IMAP 서버 설정
    'imap_host' => 'imap.gmail.com',
    'imap_port' => 993,
    // 갱신 주기(초) - index.php의 프론트 폴링 간격과 맞춰도 되고 각각 다르게 해도 됩니다.
    'poll_interval' => 30,
    // 초기 로드 시 가져올 최대 메일 수
    'max_messages' => 20,
    // 수신함 폴더
    'mailbox' => 'INBOX',
    // 메일을 읽을 때 읽음 표시로 바꿀지 여부 (true면 \Seen 플래그 설정)
    'mark_as_seen' => false,
];

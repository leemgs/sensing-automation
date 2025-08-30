



0) 사전 준비

PHP imap 확장 활성화 (예: Ubuntu sudo apt-get install php-imap && sudo phpenmod imap && sudo systemctl restart apache2)

Gmail 설정

설정 → 전달 및 POP/IMAP → IMAP 사용

계정 보안이 2단계 인증이면 앱 비밀번호를 생성해 사용자/앱비밀번호로 로그인하세요. (권장)
OAuth2로도 가능하지만 구현이 길어지니 여기선 앱 비밀번호 방식으로 설명합니다.


1) 폴더 구조 

/var/www/html/
  ├─ config.php           # Gmail 로그인/환경설정
  ├─ imap_client.php      # IMAP 공용 함수
  ├─ fetch_mail.php       # JSON: 새 메일 목록을 반환 (AJAX 폴링용)
  └─ index.php            # 웹 UI: 메일 목록 표시, 자동 갱신

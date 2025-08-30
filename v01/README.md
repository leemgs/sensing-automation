

# Gmail IMAP → Web 연동 (PHP)

이 프로젝트는 **Gmail 계정의 메일을 Apache 웹 서버에서 실시간 확인**할 수 있도록 IMAP을 이용한 PHP 예제를 제공합니다.
웹 브라우저에서 `index.php`에 접속하면 Gmail의 최신 메일 목록이 자동으로 갱신됩니다.

---

## 📌 사전 준비

### 1. PHP IMAP 확장 활성화

Ubuntu 예시:

```bash
sudo apt-get install php-imap
sudo phpenmod imap
sudo systemctl restart apache2
```

### 2. Gmail 설정

1. Gmail → **설정 > 전달 및 POP/IMAP > IMAP 사용** 활성화
2. 계정이 **2단계 인증**이면 앱 비밀번호 생성 후 로그인에 사용

   * 사용자 이메일 + 앱 비밀번호 (권장)
3. OAuth2 방식도 가능하지만 구현이 복잡하므로 여기서는 앱 비밀번호 방식을 권장

---

## 📂 폴더 구조

```
/var/www/html/
  ├─ config.php           # Gmail 로그인/환경설정
  ├─ imap_client.php      # IMAP 공용 함수
  ├─ fetch_mail.php       # JSON: 새 메일 목록 반환 (AJAX 폴링)
  └─ index.php            # 웹 UI: 메일 목록 표시, 자동 갱신
```

---

## ⚙️ 동작 개요

* 브라우저가 `index.php`를 열면
  → `fetch_mail.php`를 30초 간격으로 AJAX 호출하여 최신 메일을 갱신합니다.
* `config.php`의 `mark_as_seen` 옵션을 `true`로 설정하면 읽음 처리(`\Seen`)됩니다.
* 기본적으로 **최근 7일 메일 중 최신 20개**를 가져옵니다.
  필요 시 `criteria`, `max_messages` 값 조정 가능.

---

## 🚀 실행 방법

1. Gmail 준비

   * Gmail 설정에서 IMAP 활성화
   * 2단계 인증 계정이면 앱 비밀번호 생성
2. PHP 서버 준비

   * php-imap 설치 및 Apache 재시작
   * `config.php`에 Gmail 아이디와 앱 비밀번호 입력
   * `poll_interval` 값으로 새로고침 주기 지정 (예: 30초)
3. 브라우저에서 `http://서버주소/index.php` 접속

---

## 🔑 config.php 예시

```php
<?php
return [
    // === Gmail IMAP 접속 ===
    'username'   => 'yourname@gmail.com',
    'password'   => 'xxxx xxxx xxxx xxxx', // 앱 비밀번호
    'imap_host'  => 'imap.gmail.com',
    'imap_port'  => 993,
    'mailbox'    => 'INBOX',

    // === 동작 옵션 ===
    'mark_as_seen'   => false, // fetch 시 읽음 표시 여부
    'poll_interval'  => 30,    // index.php 자동 갱신 주기(초)
    'max_messages'   => 20,    // 최대 불러올 메일 개수
    'days_limit'     => 7      // 최근 7일 메일만 가져오기
];
```

---

## 🔐 운영 팁

* **보안**

  * `config.php`는 웹 루트 외부에 두고 `require` 하는 것을 권장
  * Git에 올리지 말 것
* **성능/한도**

  * Gmail은 동시 연결 수 제한이 있음 → 폴링 주기를 15\~60초로 설정
* **OAuth2 전환**

  * 기업/보안 요구가 높다면 XOAUTH2(OAuth)로 전환하여 암호 저장을 피할 수 있음
* **읽음 처리 전략**

  * 기본은 `mark_as_seen = false` 유지
  * 별도의 “읽음” 버튼을 만들어 운영할 수도 있음

---

## ✅ 요약

* Gmail → IMAP 사용 설정
* PHP → imap 확장 설치
* `config.php`에서 계정/비밀번호 설정
* `index.php` 접속 → Gmail 최신 메일 자동 표시

---

👉 이 예제 세트를 Apache 웹 루트에 배치하면 바로 동작합니다.


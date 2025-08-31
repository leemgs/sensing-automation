# LLM 기반 IMAP/POP3 메일 수집·분류·요약 프레임워크
(데이터 흐름: Gmail(IMAP/POP3) → LLM 분석 → 웹 UI 저장/조회)

이 저장소는 **Gmail IMAP/POP3**를 통해 메일을 수집하고, **키워드/라벨 기반 라우팅 + LLM 분석**을 거쳐  
`소송/계약/거버넌스/보관` 폴더에 자동 저장하며, 웹 UI에서 열람/관리할 수 있는 PHP 애플리케이션입니다.

- 메일 수집(IMAP/POP3) → 분류(라벨/키워드/LLM) → HTML 아카이브 생성 → 웹 UI 조회
- OpenAI 또는 **OpenRouter(= `api_provider: leemgs`)** API 호출 지원

---

## ✨ 주요 기능
- **메일 수집**: IMAP 또는 POP3 프로토콜 사용  
- **자동 분류/저장**: 소송/계약/거버넌스/보관으로 라우팅 후 HTML 문서 자동 생성  
- **AI 분석**: 제목/일자/상대방/번호 등 주요 필드 추출(JSON)  
- **웹 UI**: 목록/검색/다운로드/삭제/보관/복원 (자동 갱신 포함)  
- **감사 로그**: `audit_log.csv`에 관리자 액션 기록  
- **프로토콜 뱃지**: 현재 프로토콜(IMAP=파랑, POP3=초록) 표시  

---

## 🧱 아키텍처 다이어그램 (Mermaid)
```mermaid
graph LR
  subgraph Gmail
    A[Mailbox (IMAP/POP3)]
  end

  subgraph WebApp
    B1[fetch_mail.php: 수집/분류/AI 저장]
    B2[index.php: 뷰어]
    B3[archive.php: 아카이브 UI]
    B4[admin_action.php: 관리]
  end

  subgraph AI
    C[LLM (OpenAI / OpenRouter)]
  end

  subgraph Storage
    D1[(DB SQLite/MySQL)]
    D2[HTML 저장소]
    D3[Archive 폴더]
    D4[(audit_log.csv)]
  end

  A -->|IMAP/POP3| B1
  B1 -->|요청| C
  C -->|응답| B1
  B1 -->|저장| D1
  B1 -->|HTML 생성| D2
  B2 <-->|API| B1
  B3 <-->|파일 조회| D2
  B4 --> D2
  B4 --> D3
  B4 --> D4
````

---

## 📂 폴더 구조

```
project/
  config.php
  db.php
  mail_client.php  
  ai_extractor.php
  fetch_mail.php
  download_attachment.php
  set_seen.php
  delete_mail.php
  index.php
  admin_action.php
  archive.php
  .gitignore
  .env.example
  Dockerfile
  docker-compose.yml
  docker/
    apache-vhost.conf
  static/
    logo.png
  (생성됨) 소송/ 계약/ 거버넌스/ 보관/
  (생성됨) /var/www/data/mailcache.sqlite (기본 SQLite 사용 시)
```

---

## ⚙️ 요구 사항

* PHP 8.1+
  * 확장: `imap`, `pdo_sqlite`(또는 `pdo_mysql`), `curl`, `mbstring`, `json`
* Apache/Nginx 또는 PHP 내장 서버
* (선택) Docker & Docker Compose
```bash
sudo apt-get install php-imap
sudo phpenmod imap
sudo systemctl restart apache2
```


---

## 🚀 설치

### 1) 환경변수 준비

```bash
cp .env.example .env
vim .env
```

`.env` 주요 항목:

```dotenv
# Gmail 계정
GMAIL_USERNAME=yourname@gmail.com
GMAIL_APP_PASSWORD=앱비밀번호

# 메일 프로토콜 선택
MAIL_PROTOCOL=imap   # imap | pop3
IMAP_HOST=imap.gmail.com
IMAP_PORT=993
POP3_HOST=pop.gmail.com
POP3_PORT=995
MAILBOX=INBOX

# DB
DB_DSN=sqlite:/var/www/data/mailcache.sqlite
DB_USER=
DB_PASS=

# API Keys
OPENAI_API_KEY=sk-...
OPENROUTER_API_KEY=sk-or-...

# 관리자 토큰
ADMIN_TOKEN=랜덤토큰
```

### 2) Gmail 준비

* Gmail → **전달 및 POP/IMAP** → `IMAP 사용` 또는 `POP 사용` 활성화
* 2단계 인증 + 앱 비밀번호 생성

### 3) 권한 설정

```bash
sudo mkdir -p /var/www/data
sudo chown -R www-data:www-data /var/www/data
sudo chmod 775 /var/www/data
```

---

## 📨 IMAP vs POP3 비교

| 항목        | IMAP                   | POP3                |
| --------- | ---------------------- | ------------------- |
| 기본 포트     | 993 (SSL)              | 995 (SSL)           |
| 폴더/라벨 지원  | ✅ (X-GM-RAW 라벨 검색 지원)  | ❌ (INBOX만 가능)       |
| 검색 기능     | 제목/본문 + 라벨/스레드 검색 가능   | 본문/제목 `TEXT` 검색만 가능 |
| 다중 기기 동기화 | ✅ 지원                   | ❌ 미지원 (로컬 수집 위주)    |
| 추천 시나리오   | Gmail 라벨 기반 분류가 필요한 경우 | 단순 수집/보관/분석 중심      |

---

## 🗄️ 데이터베이스 선택: SQLite vs MySQL

| 항목      | SQLite (기본)               | MySQL (선택)           |
| ------- | ------------------------- | -------------------- |
| 설정 난이도  | 매우 간단 (서버 불필요)            | DB 서버 설치/계정 설정 필요    |
| 성능      | 소규모/개인/테스트 적합             | 대규모 동시 사용자/운영 환경 적합  |
| 저장 위치   | 로컬 파일(`mailcache.sqlite`) | 독립 DB 서버             |
| 확장성     | 제한적 (단일 연결 중심)            | 높은 확장성 (멀티유저, 클러스터링) |
| 백업/이동   | 파일 복사로 간단                 | 덤프/복원 절차 필요          |
| 권장 시나리오 | 개발·테스트, 소규모 서비스           | 기업 환경, 고가용성·성능 요구 환경 |

👉 설치 직후 **기본은 SQLite**이며, `.env`의 `DB_DSN`을 수정하면 **MySQL**로 전환할 수 있습니다.
```bash
DB_DSN=mysql:host=127.0.0.1;dbname=mailcache;charset=utf8mb4
DB_USER=dbuser
DB_PASS=dbpass
```

---

## 🔒 보안 가이드

* `.gitignore`에 **`.env`** 반드시 포함
* `ADMIN_TOKEN`은 긴 랜덤 문자열로 설정
* `.env` 및 아카이브 폴더는 웹 루트 밖에 두는 것 권장

---

## 🛠️ 트러블슈팅

* **IMAP 인증 실패**: IMAP 허용 + 앱 비밀번호 확인
* **POP3 라벨 검색 불가**: POP3는 Gmail 라벨/스레드 미지원
* **API 오류**: 키 값 확인, Bearer 토큰 여부 확인
* **권한 오류**: `/var/www/data` 권한 점검

---

## 📝 감사 로그

* `audit_log.csv`에 관리자 액션 기록

```


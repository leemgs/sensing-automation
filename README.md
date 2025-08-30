# 📬 Gmail IMAP → AI 분석 아카이브 (소송/계약/거버넌스)

PHP + Apache 기반으로 Gmail(IMAP)에서 메일을 수집하고, OpenAI 또는 leemgs(Inference Cloud) 모델을 이용해 **소송 / 계약 / 거버넌스** 관련 메일을 **자동 분석**하여 **구조화된 HTML 문서**로 저장·아카이브하는 웹앱입니다.

> ✅ 요구사항: PHP 8.1+, php-imap 확장, cURL, (선택) Docker


---

## ✨ 주요 기능

- **IMAP 연동**: Gmail 메일함에서 최근 메일 검색/수집 (라벨 필터 지원)
- **자동 분석**: OpenAI 또는 leemgs 모델을 통해 소송/계약/거버넌스 메타데이터 추출
- **문서화 & 아카이브**: `YYYYMMDD-제목.html` 포맷으로 폴더 저장 (소송/계약/거버넌스/보관)
- **첨부 다운로드**: 각 메일의 첨부파일을 직접 다운로드
- **읽음/삭제 제어**: UI에서 읽음(Seen) / 삭제(Delete) 수행 → DB/IMAP 반영
- **검색/필터**: 라벨(단일/다중), 기간, 본문 FTS, 키워드, 정렬
- **CSV/Excel 내보내기**: 현재 필터 결과를 CSV/XLS로 내보내기
- **감사 로그**: 보관/복원/삭제 액션을 `audit_log.csv`로 기록
- **멀티 프로바이더**: `api_provider=openai|leemgs` 스위치

---

## 🏗 폴더 구조

```
v3/
├─ config.php
├─ db.php
├─ imap_client.php
├─ ai_extractor.php
├─ fetch_mail.php
├─ index.php
├─ archive.php
├─ admin_action.php
├─ download_attachment.php
├─ set_seen.php
├─ delete_mail.php
├─ .htaccess
├─ docker/
│  └─ apache-vhost.conf
├─ Dockerfile
├─ docker-compose.yml
└─ static/
   └─ logo.png
```

> 운영 환경에서는 `public/`, `src/`, `storage/` 구조 분리를 권장합니다.

---

## ⚙️ 설치

### 1) ZIP 다운로드
- 이 저장소의 `project.zip`을 받아 원하는 서버로 업로드/압축해제 합니다.

### 2) 의존성 (php-imap 등)
- 리눅스: `apt-get install php-imap` 후 `phpenmod imap && systemctl restart apache2`
- Docker 사용 시 본 저장소의 Dockerfile에 포함되어 있습니다.

### 3) 설정 파일 수정: `config.php`
- **Gmail IMAP 계정/앱 비밀번호**
- **API 프로바이더/키/모델/URL**
- **저장 폴더 경로, 라벨 필터, 키워드, 토큰** 등
```php
return [
  'username' => 'yourname@gmail.com',
  'password' => 'app-password',
  'api_provider' => 'openai', // 또는 'leemgs'
  'openai_api_key' => 'sk-...',
  'openai_model'   => 'gpt-4o-mini',
  'openai_url'     => 'https://api.openai.com/v1/chat/completions',
  'leemgs_api_key' => 'base64encoded==',
  'leemgs_model'   => 'myllm-30b-instruct',
  'leemgs_url'     => 'https://www.inference-cloud.com/myllm-30b-instruct/v1/chat/completions',
  'analysis_label_filter' => ['업무'],
  'category_label_map' => [
    'lawsuit'    => ['업무-소송','legal-lawsuit','소송'],
    'contract'   => ['업무-계약','contract','계약'],
    'governance' => ['업무-거버넌스','governance','거버넌스','policy'],
  ],
  'admin_token' => 'change-this-long-random-token',
];
```

---

## ▶️ 실행 방법

### A) 로컬 PHP 내장서버(개발용)
```bash
php -S localhost:8000
# 브라우저 http://localhost:8000/index.php
```

### B) Apache 배포
- DocumentRoot를 프로젝트 루트로 설정 (동봉 `.htaccess` 사용)
- PHP 8.1+ 및 php-imap 확장 활성화

### C) Docker / Docker Compose
```bash
docker compose up -d --build
# 브라우저 http://localhost:8080
```

---

## 🖥 사용 방법

### 1) 메일 뷰어: `index.php`
- 검색어(X-GM-RAW), 라벨, 최대 개수를 입력하여 조회
- 각 메일 카드에서 **읽음/삭제** 및 **첨부 다운로드** 가능
- 자동 새로고침(간격: `config.php`의 `poll_interval`)

### 2) 수집/분석: `fetch_mail.php`
- IMAP에서 메일을 수집하고, 라벨/키워드/설정에 따라
  - **소송 / 계약 / 거버넌스** 중 대상만 **AI 분석**
  - 결과를 **HTML 문서**로 저장 (제목/날짜/라벨/보낸사람 메타 포함)

### 3) 아카이브: `archive.php`
- 카테고리/라벨(단일/다중)/기간/본문 FTS/정렬 필터
- **CSV/XLS 내보내기**
- **미리보기/새탭/다운로드**, **보관/복원/삭제** (토큰 필요)

---

## 🔐 보안

- `config.php`에는 민감 정보(API 키, 비밀번호)가 있으니 **비공개 관리**
- `admin_action.php` 호출 시 `admin_token` 필수
- 운영 환경에선 `public/`/`src/`/`storage/` 구조로 분리해 **코드/키**가 웹에서 노출되지 않게 하세요.
- HTTPS 사용 권장

---

## 🔄 프로바이더 스위치 (OpenAI ↔ leemgs)

- `config.php`에서 `api_provider` 값을 변경
  - `openai` → OpenAI Chat Completions
  - `leemgs` → Inference Cloud(`myllm-30b-instruct`) Chat Completions
- 두 API 모두 `choices[0].message.content`를 사용하는 응답 포맷을 전제로 함

---

## 🧠 분석 로직 (요약)

1. **라벨 가드**: `analysis_label_filter` 조건을 만족한 메일만 분석
2. **카테고리 결정**:
   - (A) **라벨→카테고리** 매핑 우선
   - (B) 미히트 시 **키워드**로 보조 트리거
   - 옵션) `exclusive_routing=true` 일 때 우선순위 한 개만 실행
3. **저장 포맷**:
   - `소송/YYYYMMDD-소송제목.html`
   - `계약/YYYYMMDD-계약제목.html`
   - `거버넌스/YYYYMMDD-정책명.html`

---

## 📡 주요 엔드포인트

- `GET fetch_mail.php?q=&label=&limit=` → 메일 목록 JSON
- `POST set_seen.php { uid, seen }` → 읽음/해제
- `POST delete_mail.php { uid }` → 삭제
- `GET download_attachment.php?uid=&part=` → 첨부 다운로드
- `POST admin_action.php { token, action: archive|restore|delete, rel }` → 문서 보관/복원/삭제

---

## 🗃 데이터베이스

- `db.php`가 SQLite(기본) 또는 MySQL 스키마를 자동 생성
- `messages` 테이블: `uid, subject, from_addr, date_utc, seen, labels, snippet, deleted, *_saved`

---

## 🧱 아키텍처 다이어그램 (Mermaid)


```mermaid
graph LR
  A[Mailbox IMAP] -->|IMAP| B1[fetch_mail.php: IMAP, label trigger, AI, save HTML]
  B1 -->|request| C[Chat Completions (OpenAI/leemgs)]
  C -->|response| B1
  B1 -->|upsert| D1[(DB SQLite/MySQL)]
  B1 -->|save HTML| D2[HTML store]
  B2[index.php: viewer] <-->|fetch_mail JSON| B1
  B3[archive.php: archive UI] <-->|files meta| D2
  B4[admin_action.php: archive restore delete audit] --> D2
  B4 --> D3[Archive folder]
  B4 --> D4[(audit_log.csv)]
```
---

## 🧩 운영 팁

- **권한**: `소송/`, `계약/`, `거버넌스/`, `보관/`, `audit_log.csv`, `/var/www/data`는 웹서버 계정에 쓰기 허용
- **라벨 기반 수집 제한**: `restrict_imap_search=true` 사용 시 기본 조회도 라벨로 제한
- **성능**: `max_messages`, `poll_interval` 조정, 분석 요청은 최소화(비용 주의)

---

## 🧪 권장 구조(배포형)

```
project-root/
├─ public/            # DocumentRoot
├─ src/               # 애플리케이션 코드
├─ storage/           # 문서/아카이브/DB/로그
├─ config/            # example 설정
├─ .env               # 실제 비밀값
└─ docker/            # 배포 스크립트
```

---

## ⚠️ 주의사항 / 한계

- 메일 콘텐츠를 AI 프로바이더로 전송하므로 **내부 정책/법무 검토** 필요
- 라벨 파싱은 Gmail 헤더/X-GM-LABELS 의존 → 환경에 따라 차이 가능
- Inference Cloud 응답 포맷이 OpenAI와 달라질 경우 `ai_extractor.php`의 `call_chat_api()` 조정 필요

---

## 🤝 기여

1. 이슈 등록: 버그/개선 제안
2. 브랜치: `feature/your-feature`
3. PR 제출: 설명/테스트 포함

---

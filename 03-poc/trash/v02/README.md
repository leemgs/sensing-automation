좋습니다 👍
여기서는 지금까지 만든 **Gmail → Apache/PHP 웹앱 (소송/계약/거버넌스 문서 자동분류 & 아카이브)** 프로젝트를 GitHub에 올릴 때 사용할 **README.md 템플릿**을 드릴게요.
협업과 기능개선을 이끌어내기 위해, 설치/구동법/기여가이드/로드맵 등을 모두 포함했습니다.

---

# 📄 README.md

````markdown
# 📬 Gmail IMAP → AI 분석 아카이브 (소송/계약/거버넌스)

이 프로젝트는 **Gmail로 수신된 메일을 자동으로 수집**하고,  
OpenAI API를 활용해 **소송 / 계약 / 거버넌스 문서**를 인식·구조화하여  
**HTML 문서로 저장 및 아카이브 관리**할 수 있는 PHP + Apache 기반 웹 애플리케이션입니다.

---

## ✨ 주요 기능

- **IMAP 연동**  
  Gmail 계정에서 POP/IMAP을 통해 메일 수집
- **자동 분류 & 저장**  
  - "소송" 키워드/라벨 → `소송/` 폴더
  - "계약" 키워드/라벨 → `계약/` 폴더
  - "거버넌스" 키워드/라벨 → `거버넌스/` 폴더
- **OpenAI 기반 내용 분석**  
  메일 내용을 GPT 모델로 분석 → JSON 추출 → HTML 문서 생성
- **첨부파일 다운로드**  
  메일 첨부파일 UI 제공
- **라벨/검색 필터**  
  Gmail 라벨 조건(label:"업무" 등) 기반 실행
- **아카이브 관리**  
  보관, 복원, 삭제 / CSV·Excel 내보내기
- **관리자 감사로그**  
  모든 보관/삭제/복원 이벤트를 CSV 로그로 기록
- **Docker 지원**  
  Dockerfile + docker-compose로 쉽게 배포 가능

---

## 🚀 빠른 시작

### 1) 저장소 클론
```bash
git clone https://github.com/your-org/gmail-ai-archive.git
cd gmail-ai-archive
````

### 2) 설정 파일 수정

`config.php` 편집:

```php
return [
  'username'   => 'yourname@gmail.com',
  'password'   => 'your-app-password',   // Gmail 앱 비밀번호
  'openai_api_key' => 'sk-xxxxxxxx',
  'openai_model'   => 'gpt-4o-mini',
  'admin_token'    => 'change-this-long-random-token',
];
```

### 3) Docker로 실행

```bash
docker compose up -d --build
```

브라우저에서 확인 👉 [http://localhost:8080](http://localhost:8080)

---

## 🗂 프로젝트 구조

```
├─ config.php              # 환경설정
├─ db.php                  # SQLite/MySQL 스키마 & 함수
├─ imap_client.php         # IMAP 연결 및 파싱
├─ ai_extractor.php        # OpenAI 분석 + HTML 생성
├─ fetch_mail.php          # Gmail → DB/파일 저장
├─ index.php               # 메일 뷰어 UI
├─ archive.php             # 아카이브 관리 UI
├─ admin_action.php        # 보관/삭제/복원 API
├─ download_attachment.php # 첨부파일 다운로드
├─ docker/                 # Apache vhost 설정
├─ Dockerfile              # PHP+Apache 환경
└─ docker-compose.yml      # Compose 실행 정의
```

---

## 🛠 개발자 가이드

### 코드 스타일

* **PHP 8.2** 이상 권장
* 함수 이름: snake\_case
* DB: PDO 사용 (SQLite/MySQL 지원)
* UI: 바닐라 JS + CSS (외부 프레임워크 없음)

### 주요 파일 역할

* `imap_client.php` → Gmail 메일 수집/라벨 파싱
* `ai_extractor.php` → OpenAI API 호출, JSON 응답을 HTML 변환
* `archive.php` → 생성된 문서 관리 (검색/필터/내보내기)

### 테스트

```bash
php -S localhost:8000
```

→ [http://localhost:8000](http://localhost:8000) 에서 확인

---

## 🤝 기여하기

1. 이슈 등록 → 개선/버그 제안
2. 브랜치 생성 → `feature/your-feature`
3. PR 제출 → 리뷰 후 머지

### 기여 아이디어

* 🔐 Gmail OAuth2 인증 (앱 비밀번호 불필요)
* 📊 대시보드: 문서 통계 시각화
* 🌐 다국어 UI (영/한)
* 📝 더 많은 문서 카테고리(인보이스, 견적서 등)
* 🧾 정식 `.xlsx` 내보내기 (PhpSpreadsheet 연동)

---

## 📜 라이선스

[MIT](LICENSE)

---

## 🙌 참여자

* 초기 개발: [Your Name](https://github.com/yourname)
* 기여자: 여러분의 PR을 환영합니다!

```

---

👉 이 `README.md`는 **설치 → 실행 → 아키텍처 → 기여 방법** 흐름으로 되어 있어, 새로운 개발자들이 빠르게 이해하고 참여할 수 있도록 작성되었습니다.  

원하시면 제가 **GitHub Issue/PR 템플릿**, **CODE_OF_CONDUCT.md**, **CONTRIBUTING.md**까지 추가해드릴 수도 있는데요, 그 부분도 만들어드릴까요?
```

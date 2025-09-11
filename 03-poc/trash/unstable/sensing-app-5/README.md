# Gmail IMAP + LLM 센싱 자동화 시스템

이 프로젝트는 **Gmail IMAP**으로 메일을 불러오고, 본문에 포함된 *기사 링크*를 추출한 뒤, **AAH Inference API (gauss2-37b-instruct)**로
**AI 규제(AI Regulation)** 및 **AI 에셋(AI Asset)** 관점에서 자동 분류/요약하여 결과를 HTML로 저장합니다.

---

## ✨ 기능
- Gmail IMAP 연결 → 받은편지함 목록 표시, 특정 메일 클릭 시 본문/링크 확인
- 기사 링크별로 LLM 추론 실행 (규제: governance/contract/lawsuit · 에셋: data/model/agent)
- 결과 HTML 자동 생성/저장 (고유 파일명, 한국시간 기준)
- 저장 경로 (기본):
  - 규제: `/var/www/html/sensing/regulation/{governance,contract,lawsuit}/`
  - 에셋: `/var/www/html/sensing/asset/{data,model,agent}/`
- 위치 권한 문제 시 **자동으로 로컬 폴더**(`./sensing_out/...`)로 폴백 저장

---

## 📦 구성 파일
```
.
├── README.md
├── config.php                 # 환경설정 (IMAP 계정, 타임존 등)
├── index.php                  # 메일 목록
├── view_email.php             # 단일 메일 보기 + 링크 추출
├── analyze_email.php          # 링크 단위 LLM 분석/저장
├── lib/
│   ├── functions.php          # 공용 유틸 (ENV 로딩, 파일저장 등)
│   ├── llm.php                # AAH Inference API 호출
│   └── article_fetch.php      # URL 콘텐츠 가져오기 (curl with timeouts)
├── templates/
│   └── html_templates.php     # 규제/에셋 HTML 템플릿
└── public/
    └── style.css              # 간단한 스타일
```

---

## 🔧 사전 준비
1) PHP 확장 설치 (Ubuntu 예시)
```bash
sudo apt update
sudo apt install -y php php-imap php-curl php-mbstring php-xml php-zip
sudo phpenmod imap mbstring
sudo systemctl restart apache2 || sudo systemctl restart php8.3-fpm
```

2) **AAH_API_KEY** 보안 설정  
`/etc/environment` 파일에 아래와 같이 키를 저장합니다.
```
AAH_API_KEY=Base64EncodedBasicTokenHere
```
> Apache/PHP-FPM이 `/etc/environment`를 자동 로드하지 않는 시스템이 있습니다. 본 프로젝트는
> ① `getenv('AAH_API_KEY')` ② `/etc/environment` 파싱 두 경로를 모두 시도합니다.

3) Gmail IMAP 계정 설정  
`config.php` 파일을 열어 아래 값을 설정하세요.
```php
$IMAP_HOST = 'imap.gmail.com';
$IMAP_PORT = 993;
$IMAP_ENCRYPTION = '/imap/ssl/validate-cert'; // 또는 '/imap/ssl/novalidate-cert'
$IMAP_USER = 'your.name@gmail.com';
$IMAP_PASS = 'app-specific-password';
```
> Gmail은 일반 비밀번호 대신 **앱 비밀번호** 사용을 권장합니다.  
> 2단계 인증 및 보안 설정을 확인하세요.

4) 웹 루트 배치
```bash
sudo mkdir -p /var/www/html/sensing
sudo chown -R www-data:www-data /var/www/html/sensing
sudo chmod -R 775 /var/www/html/sensing

# 앱 배치 (예: /var/www/html/sensing-app)
sudo mkdir -p /var/www/html/sensing-app
sudo cp -r * /var/www/html/sensing-app/
sudo chown -R www-data:www-data /var/www/html/sensing-app
```

5) 접속  
브라우저에서 `http://<서버>/sensing-app/index.php` 를 엽니다.

---

## 🧠 동작 개요
- **index.php**: 받은편지함 목록 출력 (보낸사람/제목/날짜). 행 클릭 → `view_email.php?uid=...`
- **view_email.php**: 본문 표시, URL 자동 추출, 각 링크를 개별 분석 버튼/전체 분석 버튼 제공
- **analyze_email.php**: 선택한 링크들에 대해 순차적으로
  1) `article_fetch.php`로 URL 콘텐츠 수집 (문자열 정리 및 길이 제한)
  2) `llm.php`를 통해 **규제 + 에셋** JSON 결과 수신
  3) `html_templates.php`로 예쁜 HTML 렌더링 후 파일 저장 (폴더 자동 생성, 권한 오류 시 로컬 폴백)
  4) 저장 파일 경로를 화면에 표시 (클릭 시 새 탭)

---

## 📁 저장 경로 규칙 & 고유 파일명
- 시간대: `Asia/Seoul`
- 파일명:  
  - 규제: `AI규제-{governance|contract|lawsuit}-YYYYMMDD-HHMM-{hash}.html`
  - 에셋: `AI에셋-{data|model|agent}-YYYYMMDD-HHMM-{hash}.html`
- 해시: 기사 URL 기준 `sha1(url)`의 앞 8자리

---

## ⚠️ 권한/네트워크 오류 처리
- `/var/www/html/sensing/...` 미쓰기 가능 → `./sensing_out/...`로 자동 폴백
- cURL 실패 시: 링크 텍스트만으로 추론 수행
- LLM API 오류 시: 원인/응답코드를 화면과 로그에 표시

---

## 🧪 수동 테스트 (API만)
```bash
curl -X POST 'https://inference-webtrial-api.shuttle.sr-cloud.com/gauss2-37b-instruct/v1/chat/completions'   -H "Authorization: Basic $AAH_API_KEY"   -H 'Content-Type: application/json'   -d '{
    "model": "gauss2-37b-instruct",
    "messages": [{"role":"user","content":"Please say hello in Korean."}],
    "stream": false
  }'
```

---

## 🔒 보안 주의
- 로그에 **민감 정보**를 남기지 마세요.
- 외부 URL 수집 시 도메인 화이트리스트/블랙리스트 정책을 고려하세요.
- 필요 시 프록시/아웃바운드 제한 정책 적용을 권장합니다.


---

## ⏱️ 자동 실행(크론) & 🔔 웹훅 알림

### 크론 설정
최근 N분 내 도착 메일을 자동 분석하려면(기본 180분, 최대 메일 10건/메일당 링크 5개):
```bash
# 환경변수 (선택)
echo SENSING_LOOKBACK_MIN=120 | sudo tee -a /etc/environment
echo SENSING_MAX_EMAILS=20     | sudo tee -a /etc/environment
echo SENSING_MAX_LINKS=5       | sudo tee -a /etc/environment
source /etc/environment

# 크론 (5분마다)
(crontab -l; echo "*/5 * * * * /usr/bin/php /var/www/html/sensing-app/cron_scan.php >> /var/www/html/sensing-app/sensing.log 2>&1") | crontab -
```

### 웹훅 알림(선택)
Slack/Discord/Teams 등 단순 JSON 웹훅에 요약을 전송합니다.
```bash
echo SENSING_WEBHOOK_URL="https://hooks.slack.com/services/XXX/YYY/ZZZ" | sudo tee -a /etc/environment
source /etc/environment
```
- 성공 시: “메일 n건 처리, 파일 m건 저장”과 제목 요약 목록을 전송합니다.
- 실패/오류는 `sensing.log`에서 확인하세요.


---

## 📊 대시보드 / 중복 방지 / 재시도 큐

- 저장소: SQLite (`sensing.db`, WAL 모드)
- 중복 방지: 같은 URL 해시로 이미 성공 처리된 경우 재분석 스킵
- 실패 재시도: `failed_jobs`에 적재 → 크론이 10~15분 간격으로 자동 재시도
- 대시보드: `dashboard.php` (키워드/기간/카테고리 검색, 결과 HTML 바로 열람)


---

## ✅ 확장 기능 (v2)
- 신뢰도(0~1), 검토 필요 플래그, 검토 사유 저장
- 2-모델 교차검증(선택): `SECONDARY_LLM_ENDPOINT`, `SECONDARY_LLM_MODEL`, `SECONDARY_LLM_KEY`
- 스키마 검증 실패 시 자동 검토 플래그 + 재시도 큐
- 향상된 중복 방지: URL 해시 + 텍스트 시그니처
- 대시보드 필터(검토 필요) + 간단 지표(최근 14일)
- 알림 심각도: `info/warn/high` (소송 자동 high)
- RSS 수집기: `rss_pull.php` + `feeds.json`

### RSS 크론 예시
```bash
# 매 30분
(crontab -l; echo "*/30 * * * * /usr/bin/php /var/www/html/sensing-app/rss_pull.php >> /var/www/html/sensing-app/sensing.log 2>&1") | crontab -
```

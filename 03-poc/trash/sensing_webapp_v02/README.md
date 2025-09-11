# Gmail IMAP → OpenRouter LLM 센싱 자동화 (v02)

**구성(총 6개 파일)**
- `index.php`        : 단일 진입점(UI) — IMAP 접속/목록/미리보기/LLM 분석 트리거
- `config.php`       : 설정/도우미 — API 키 로딩(/etc/environment), 저장 디렉터리 보장
- `imap_client.php`  : IMAP 연결/목록/본문 가져오기
- `llm_client.php`   : OpenRouter Chat Completions 호출 래퍼
- `analyzer.php`     : 프롬프트 구성, LLM 응답 파싱(META), 카테고리/파일명 결정, HTML 저장
- `utils.php`        : 공통 유틸(escape, slug, timestamp 등)

## 요구사항 구현 요약
- `/etc/environment` 에서 `OPENROUTER_API_KEY`를 읽습니다. (getenv → env → bash source 순으로 탐색)
- Gmail IMAP(ssl/993)로 최신 50개 목록을 표시하고, **보기**/**LLM 분석** 버튼 제공
- LLM 프롬프트는 질문자님이 주신 사양을 그대로 포함하며, 분석 결과 최상단에 `META: GROUP=...; CATEGORY=...` 를 반드시 출력하도록 system 지시 추가
- 카테고리 → 저장경로 (없으면 자동 생성)
  - 규제: `/var/www/html/sensing/regulation/{governance,contract,lawsuit}`
  - 에셋: `/var/www/html/sensing/asset/{data,model,agent}`
  - 그 외: `/var/www/html/sensing/`
- 파일명: `AI규제-contract-YYYYMMDD-HHMM.html` 형식(중복 방지 타임스탬프)
- 목록 행 왼쪽에 **LLM 분석** 버튼 포함
- 선택한 메일의 본문 미리보기 제공

## 서버 준비
```bash
sudo apt-get update
sudo apt-get install -y php php-imap php-curl php-mbstring php-xml unzip
sudo phpenmod imap mbstring
sudo systemctl restart apache2 || sudo systemctl restart php8.3-fpm
```

### 환경변수
`/etc/environment` 파일에 다음 라인이 있어야 합니다.
```bash
export OPENROUTER_API_KEY="sk-or-..."
```
키가 적용되지 않으면, 웹앱이 보조적으로 `/bin/bash -lc "source /etc/environment; echo -n $OPENROUTER_API_KEY"` 를 시도합니다.

## 배포
```bash
sudo mkdir -p /var/www/html/sensing /var/www/html/sensing_webapp_v02
sudo chown -R www-data:www-data /var/www/html/sensing /var/www/html/sensing_webapp_v02
sudo chmod -R 775 /var/www/html/sensing

unzip sensing_webapp_v02.zip
sudo cp *.php /var/www/html/sensing_webapp_v02/
sudo chown -R www-data:www-data /var/www/html/sensing_webapp_v02
```

## 사용법
브라우저에서 `http://<서버>/sensing_webapp_v02/index.php` 접속 → IMAP 서버/이메일/비밀번호 입력 → **연결**  
메일 리스트에서 **보기**/**LLM 분석** 이용 → 저장 경로와 파일명이 화면 상단 알림으로 표시됩니다.

## 보안/운영 팁
- 공개 환경에서는 `index.php` 상단의 `ALLOWED_HOSTS` 화이트리스트를 반드시 서버 도메인으로 갱신하세요.
- 실서비스 전 로그인/CSRF 토큰/리퍼러 검증/레이트리밋 등을 추가하시길 권장합니다.

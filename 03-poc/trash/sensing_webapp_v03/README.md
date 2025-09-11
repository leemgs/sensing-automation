# Gmail IMAP → OpenRouter LLM 센싱 자동화 (v03)

요청하신 4가지 개선 반영:
1) **환경변수 로딩 강화**: `/etc/environment`를 `parse_ini_file()`로 읽어 `OPENROUTER_API_KEY` 감지 개선  
2) **IMAP 자격 자동 로드**: `IMAP_SERVER/IMAP_EMAIL/IMAP_PASSWORD`를 `/etc/environment`에서 로드하여 상단에 표시  
3) **팝업 표시**: “보기”, “LLM 분석” 결과를 페이지 우상단 팝업으로 표시  
4) **게시판 + 출력 개수 설정**: 기본 20개, 드롭다운으로 10/20/30/40/50 선택

## 파일 구성 (5 PHP + 1 README)
- `index.php`        : UI/라우팅 (목록, 보기, LLM 분석, 팝업)
- `config.php`       : 환경설정/경로/환경변수 로딩(get_env_value)
- `utils.php`        : 공통 유틸(h, now_stamp, slug, save_html_by_route)
- `imap_client.php`  : IMAP 연결/목록/본문
- `llm_client.php`   : OpenRouter Chat Completions 호출
- `analyzer.php`     : 프롬프트/분석/META 파싱/저장

## 배포
```bash
sudo apt-get update
sudo apt-get install -y php php-imap php-curl php-mbstring php-xml unzip
sudo phpenmod imap mbstring
sudo systemctl restart apache2 || sudo systemctl restart php8.3-fpm

sudo mkdir -p /var/www/html/sensing /var/www/html/sensing_webapp_v03
sudo chown -R www-data:www-data /var/www/html/sensing /var/www/html/sensing_webapp_v03
sudo chmod -R 775 /var/www/html/sensing

unzip sensing_webapp_v03.zip
sudo cp *.php /var/www/html/sensing_webapp_v03/
sudo chown -R www-data:www-data /var/www/html/sensing_webapp_v03
```

## 환경변수 예시 (/etc/environment)
```
OPENROUTER_API_KEY="sk-or-..."
IMAP_SERVER="imap.gmail.com"
IMAP_EMAIL="leemgs.sensing@gmail.com"
IMAP_PASSWORD="앱비밀번호"
```

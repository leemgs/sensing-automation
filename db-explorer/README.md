
# 📘 NL2SQL for MariaDB (`nl2sql_mariadb.php`)

자연어 질문을 입력하면 **OpenAI ChatGPT API (LLM)** 를 이용하여
MariaDB 데이터베이스 스키마를 분석한 뒤,
해당 질문에 맞는 적절한 **SQL(SELECT 전용)** 쿼리를 자동 생성해줍니다.

---

## ✨ 기능

* ✅ 자연어 → SQL 자동 변환 (OpenAI Responses API 활용)
* ✅ **MariaDB 스키마 자동 요약** 후 프롬프트에 반영
* ✅ **안전성 검사** (INSERT/UPDATE/DELETE/DDL 차단)
* ✅ 자동 `LIMIT 200` 강제 (대용량 쿼리 방지)
* ✅ 웹 UI에서 SQL 생성 결과 확인
* ✅ (옵션) SQL 실행 결과도 테이블로 표시

---

## 📂 디렉토리 구조

```
project-root/
├── nl2sql_mariadb.php       # 메인 프로그램
├── config/
│   └── db_config.php        # DB 설정 파일 (환경변수 미사용 시)
├── logs/
│   └── queries.log          # SQL 로그
├── schema_cache/
│   └── schema.json          # 스키마 캐시
├── public/
│   └── index.php            # UI 포털
└── README.md                # 설치/실행 가이드
```

---

## 🔧 설치 방법

### 1) PHP + MariaDB 환경 준비

```bash
sudo apt update
sudo apt install -y php php-mysql mariadb-client
```

### 2) OpenAI API Key 설정

```bash
export OPENAI_API_KEY="sk-xxxx"
```

👉 `.bashrc`나 systemd 서비스 환경에도 추가하세요.

### 3) DB 계정 준비

MariaDB에서 읽기 전용 계정 생성:

```sql
CREATE USER 'readonly_user'@'localhost' IDENTIFIED BY 'readonly_password';
GRANT SELECT ON regulation.* TO 'readonly_user'@'localhost';
```

### 4) 웹 서버에 배포

```bash
ln -s /var/www/project-root/nl2sql_mariadb.php /var/www/html/nl2sql.php
```

브라우저에서:

```
http://localhost/nl2sql.php
```

---

## ⚙️ 설정

* `config/db_config.php`
  DB 연결 정보 (환경변수 사용 불가할 때만).

* `nl2sql_mariadb.php` 상단의 상수:

  * `EXECUTION_ENABLED` = `false` → 기본은 SQL 실행 차단 (생성만)
  * `MAX_SCHEMA_TABLES` = 스키마 요약 시 포함할 테이블 수
  * `AUTO_LIMIT_DEFAULT` = 자동 LIMIT 값

---

## 🔒 보안 주의사항

* **DB 계정은 반드시 읽기 전용**(`SELECT`) 권한만 부여하세요.
* 생성된 SQL은 내부적으로 `SELECT/WITH`만 허용하며, 위험 쿼리는 자동 차단됩니다.
* 로그(`logs/queries.log`)에는 사용자 질문/SQL이 기록됩니다 → 개인정보 포함 가능성이 있으니 접근 권한 제한 필요.
* 운영 환경에서는 반드시 HTTPS를 사용하세요.

---

## 🚀 사용 예시

사용자 질문:

```
지난 30일 동안 AI기본법(본문)에서 "위험" 키워드가 포함된 조항 번호와 제목을 최신순으로 30건 보여줘
```

생성 SQL (예시):

```sql
SELECT `no`, `조항번호`, `제목`
FROM `AI기본법_본문`
WHERE `본문` LIKE '%위험%'
  AND `날짜` >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
ORDER BY `날짜` DESC
LIMIT 30;
```

---

## 📖 참고

* [OpenAI Responses API 문서](https://platform.openai.com/docs/guides/responses)
* [MariaDB Documentation](https://mariadb.com/kb/en/documentation/)

---




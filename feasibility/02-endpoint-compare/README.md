# Chat Completions 비교 실행 스크립트 (PHP)

OpenAI와 LGStrial(사내 Inference) **두 엔드포인트를 한 번에 또는 선택적으로 호출**하여 응답을 비교·검증할 수 있는 PHP 예제입니다.
응답 형식 차이(`message.content` vs `text`)와 실행 시간, HTTP 코드까지 깔끔하게 출력합니다.

---

## 주요 기능

* `provider=both` (기본), `openai`, `lgstrial` 중 선택 실행
* OpenAI / LGStrial 각각 **엔드포인트·API 키·페이로드 분리**
* 응답 스키마 차이 자동 처리
* HTTP 상태 코드, cURL 오류, 소요 시간(ms) 출력
* CLI와 내장 서버(웹) **둘 다 지원**

---

## 준비 사항

* **PHP 8.0+** (권장: 8.1 이상)
* PHP 확장: `curl`, `json`
* 인터넷 연결
* OpenAI API Key (Bearer)
* LGStrial Inference API Key (Basic)

---

## 파일 구성 예시

```
project/
├─ compare.php        # 두 엔드포인트 비교 실행 스크립트
└─ README.md          # 이 문서
```

> 이미 단일 프로바이더용 스크립트를 사용 중이라면, `compare.php`만 추가로 두고 함께 쓰면 됩니다.

---

## 설정

### 1) OpenAI API 키

`compare.php` 내부 설정을 열고 실제 키로 교체하세요.

```php
'apiKey'  => 'sk-xxxxxxxxxxxxxxxxxxxx', // TODO: 실제 키로 교체
```

> 환경 변수로 분리하고 싶다면 `getenv('OPENAI_API_KEY')`를 사용하셔도 됩니다.

### 2) LGStrial API 키

LGStrial은 **환경 변수** `api_key` 를 사용합니다.

#### Linux (bash)

```bash
echo 'api_key=YOUR_LGSTRIAL_KEY' | sudo tee -a /etc/environment
source /etc/environment
# 또는 세션 한정
export api_key=YOUR_LGSTRIAL_KEY
```

#### Windows (PowerShell)

```powershell
setx api_key "YOUR_LGSTRIAL_KEY"
# 새 터미널을 열어야 적용됩니다.
```

---

## 실행 방법

### A. CLI 실행

```bash
# 둘 다 실행(기본)
php compare.php

# 특정 프로바이더만
php compare.php openai
php compare.php lgstrial
```

### B. 내장 서버(웹) 실행

```bash
php -S localhost:8000
```

브라우저에서:

* 둘 다 실행: `http://localhost:8000/compare.php?provider=both`
* OpenAI만: `http://localhost:8000/compare.php?provider=openai`
* LGStrial만: `http://localhost:8000/compare.php?provider=lgstrial`

---

## 출력 예시

```
======================================================================
Provider : OpenAI (openai)
Endpoint : https://api.openai.com/v1/chat/completions
HTTP Code: 200
Elapsed  : 0.842 sec
----- 🤖 모델 응답 -----
(응답 내용...)

======================================================================
Provider : LGStrial (lgstrial)
Endpoint : https://inference-lgstrial-api.mycloud.com/falcon-30b-instruct/v1/chat/completions
HTTP Code: 200
Elapsed  : 0.511 sec
----- 🤖 모델 응답 -----
(응답 내용...)

======================================================================
Done.
```

> HTTP 코드가 200이 아니거나 cURL 에러가 발생하면 해당 정보가 함께 표시됩니다.

---

## 커스터마이징

### 모델/프롬프트 변경

`compare.php` 내 각 프로바이더의 `payload`를 수정하세요.

```php
// OpenAI
'payload' => [
  "model"       => "gpt-4o-mini",
  "messages"    => [
    ["role" => "system", "content" => "You are a helpful assistant."],
    ["role" => "user",   "content" => "OpenAI API를 PHP에서 사용하는 예제를 보여줘."]
  ],
  "temperature" => 0.7,
  "stream"      => false,
],
```

```php
// LGStrial
'payload' => [
  "model"       => "falcon-30b-instruct",
  "messages"    => [
    ["role" => "user", "content" => "Please create three sentences starting with the word LLM in Korean."]
  ],
  "temperature" => 0.7,
  "stream"      => false,
],
```

### 엔드포인트 교체

사내 인퍼런스 주소가 바뀌면 해당 `url`만 바꾸면 됩니다.

---

## 보안 권장사항

* API 키는 **소스 코드에 직접 하드코딩하지 말고** 환경 변수/비밀 관리 도구를 사용하세요.
* 저장소에 올리기 전에 `.gitignore`에 민감 파일/스크립트를 등록하세요.
* 서버 로그나 에러 페이지에 키가 노출되지 않도록 주의하세요.

---

## 트러블슈팅

| 현상                   | 원인          | 조치                                                   |
| -------------------- | ----------- | ---------------------------------------------------- |
| `API key missing` 에러 | 키 미설정       | OpenAI: 코드/환경 변수 설정  /  LGStrial: `api_key` 환경 변수 설정 |
| HTTP 401/403         | 인증 실패       | 키 값/형식(Bearer vs Basic) 재확인                          |
| HTTP 404/405         | 잘못된 URL/메서드 | 엔드포인트 주소 점검                                          |
| cURL timeout         | 네트워크 지연     | 재시도, 타임아웃 상향, 네트워크 확인                                |
| 응답 파싱 실패             | 예외적 스키마     | 원문(JSON) 출력 확인 후 `extractAssistantText` 보강           |

---

## 응답 스키마 참고

* **OpenAI**: `choices[0].message.content`
* **일부 Inference**: `choices[0].text`

본 스크립트는 두 케이스를 모두 커버하며, 다른 스키마가 올 경우 **원문 JSON**을 예쁘게 출력합니다.

---

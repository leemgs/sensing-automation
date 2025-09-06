

이 프로젝트는 PHP에서 사용하는 간단한 예제입니다.  
API 키를 보안상 안전하게 관리하기 위해 `.env` 파일을 사용합니다.

---

## 📦 준비 사항

1. **PHP 7.4+** 또는 최신 버전 설치
2. **cURL 확장** 활성화 확인 (`php -m | grep curl`)
3. LLM 웹사이트 (예: OpenRouter) 계정에서 발급받은 **API Key**

---

## ⚙️ 환경 설정


### `.env` 파일 생성

프로젝트 루트에 `.env` 파일을 만들고 다음 내용을 작성하세요:

```env
# OpenRouter API Key
OPENROUTER_API_KEY=sk-or-v1-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

> ⚠️ `.env` 파일은 반드시 **.gitignore**에 추가하여 버전 관리에서 제외하세요.

---

## 💻 실행 방법


### 2. 실행

```bash
php chat.php
```

---

## 🧪 테스트 (cURL 직접 호출)

PHP 대신 `curl` 명령으로도 테스트할 수 있습니다:

```bash
export OPENROUTER_API_KEY="sk-or-v1-xxxxxxxxxxxxxxxxxxxx"

curl https://openrouter.ai/api/v1/chat/completions \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $OPENROUTER_API_KEY" \
  -d '{
    "model": "openai/gpt-4o",
    "messages": [
      {"role": "user", "content": "Hello from curl!"}
    ],
    "max_tokens": 256
  }'
```

---

## 📌 참고 사항

* OpenRouter는 **여러 LLM 모델**을 제공합니다.
  [모델 목록](https://openrouter.ai/models) 에서 원하는 모델명을 확인하여 `model` 필드에 입력하세요.
* 무료 계정은 **토큰 크레딧 한도**가 있으므로, `max_tokens` 값을 조절하세요.
* 에러 예시:

  * `"Invalid API Key"` → 키가 잘못되었거나 누락됨
  * `"Payment Required"` → 무료 크레딧 소진

---

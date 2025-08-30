
# OpenAI Chat Completions PHP 예제

이 프로젝트는 **OpenAI Chat Completions API**를 PHP에서 호출하는 간단한 예제 코드입니다.  
`gpt-4o-mini` 모델을 사용하여 메시지를 주고받고, 결과를 JSON으로 파싱해 출력합니다.

---

## 📂 파일 구성

```

project/
├─ chat.php        # OpenAI Chat Completions 호출 예제
└─ README.md       # 이 문서

````

---

## ⚙️ 준비 사항

- **PHP 7.4+** (권장: PHP 8 이상)
- PHP 확장: `curl`, `json`
- OpenAI API Key (`sk-...` 형식)

---

## 🔑 OpenAI API 키 설정

코드 상단의 `$apiKey` 변수에 본인의 OpenAI API 키를 입력하세요:

```php
$apiKey = "sk-xxxxxxxxxxxxxxxxxxxx";
````

> 보안상 실제 운영 환경에서는 **환경 변수**나 별도 설정 파일을 이용하는 것이 안전합니다.
> 예:
>
> ```bash
> export OPENAI_API_KEY="sk-xxxxxxxxxxxxxxxxxxxx"
> ```
>
> ```php
> $apiKey = getenv("OPENAI_API_KEY");
> ```

---

## ▶️ 실행 방법

```bash
php chat.php
```

---

## 📜 코드 설명

1. **요청 데이터 준비**

   ```php
   $data = [
       "model" => "gpt-4o-mini",
       "messages" => [
           ["role" => "system", "content" => "You are a helpful assistant."],
           ["role" => "user", "content" => "OpenAI API를 PHP에서 사용하는 예제를 보여줘."]
       ],
       "temperature" => 0.7
   ];
   ```

2. **cURL 초기화 및 요청**

   * 엔드포인트: `https://api.openai.com/v1/chat/completions`
   * 메서드: `POST`
   * 인증 헤더: `Authorization: Bearer {API_KEY}`

3. **응답 처리**

   ```php
   $result = json_decode($response, true);
   echo $result['choices'][0]['message']['content'] ?? "응답 없음";
   ```

---

## 📌 출력 예시

```
🤖 모델 응답:
PHP에서 OpenAI API를 사용하려면 cURL을 활용하여 요청을 전송할 수 있습니다. ...
```

---

## 🚀 확장 아이디어

* `$_GET` 또는 CLI 인자를 받아 사용자 입력을 동적으로 API에 전달
* 다양한 모델(`gpt-4o`, `gpt-4o-mini`, `gpt-3.5-turbo`) 테스트
* `stream` 옵션 활성화하여 실시간 스트리밍 응답 구현
* 에러 로깅 및 재시도 정책 추가

---

## 🔒 보안 권장 사항

* API 키를 **코드에 직접 하드코딩하지 말고**, 환경 변수나 별도 보안 저장소를 이용하세요.
* 저장소에 올릴 때는 `.gitignore`로 API 키가 포함된 파일을 제외하세요.

---

## 📖 참고 문서

* [OpenAI API Reference – Chat Completions](https://platform.openai.com/docs/api-reference/chat/create)
* [PHP cURL Documentation](https://www.php.net/manual/en/book.curl.php)

```

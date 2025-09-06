# PHP Examples for OpenAI & Self-Inference APIs

이 저장소는 **OpenAI Chat Completions API** 및 **OpenAI vs Self-Inference (LGStrial) 엔드포인트 비교**를 위한 PHP 예제를 포함합니다.  
각 폴더에는 독립적인 예제 코드와 README가 있습니다.

---

## 📂 폴더 구성

### `01-openai-chat`
- **내용**: OpenAI Chat Completions API(`gpt-4o-mini`)를 PHP `cURL`로 호출하는 단일 예제
- **파일**:
  - `chat.php` : OpenAI API 호출 예제
  - `README.md` : 실행 방법 및 설명
- **최근 커밋**: *Add PHP example for OpenAI Chat Completions API* (now)

---

### `02-endpoint-compare`
- **내용**: OpenAI와 LGStrial Inference API 두 엔드포인트를 선택/비교 실행하는 PHP 스크립트
- **파일**:
  - `compare.php` : 두 API 결과를 나란히 출력
  - `README.md` : 설정 및 사용법 설명
- **최근 커밋**: *Remove license and contact sections from README* (now)

---

## 🚀 사용 방법

각 폴더 내부의 `README.md`를 참고하여 예제를 실행하면 됩니다.

- **OpenAI 단일 실행** → [`01-openai-chat`](./01-openai-chat)  
- **OpenAI vs Inference 비교 실행** → [`02-endpoint-compare`](./02-endpoint-compare)  

---

## 📌 참고

- [OpenAI API Docs](https://platform.openai.com/docs/api-reference/chat/create)
- PHP 7.4 이상 권장, `curl` 확장 필요

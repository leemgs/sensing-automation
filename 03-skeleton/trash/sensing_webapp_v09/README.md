# Gmail IMAP → OpenRouter/HF Router LLM 센싱 자동화 (v09)

### 이번 변경
1) **LLM API 선택 드롭다운** (헤더 우측): `openai/gpt-4o`(OpenRouter) 또는 `moonshotai/Kimi-K2-Instruct-0905:together`(HF Router) 선택
2) **LLM API 설정 편집/저장**: `llm-api-list.json` 내용을 팝업에서 수정 → 저장 (권한 없으면 에러 표시)
3) 기존 v08 기능 유지: 드래그/리사이즈 팝업, 삭제 미리보기, LLM 로그 다운로드, 파일명에 UID/제목 슬러그 포함, max_tokens/temperature 제어 등

### 파일
- `llm-api-list.json`: API 정의 및 기본 선택값 저장

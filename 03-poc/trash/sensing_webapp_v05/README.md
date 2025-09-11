# Gmail IMAP → OpenRouter LLM 센싱 자동화 (v05)

### 이번 변경
- 헤더에 **“프롬프트 열람”** 메뉴 추가
- 팝업에서 `prompt.txt` 내용을 확인/수정 후 **저장** 가능
- 저장된 내용은 즉시 `LLM 분석`에 반영 (분석 시 `prompt.txt`를 매번 읽음)

### 파일 구성
- index.php / config.php / utils.php / imap_client.php / llm_client.php / analyzer.php / prompt.txt / README.md

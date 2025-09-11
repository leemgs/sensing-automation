#!/usr/bin/env bash
set -euo pipefail
: "${AAH_API_KEY:?AAH_API_KEY is not set. Try: source /etc/environment}"

curl -sS -X POST 'https://inference-webtrial-api.shuttle.sr-cloud.com/gauss2-37b-instruct/v1/chat/completions' \
  -H "Authorization: Basic $AAH_API_KEY" \
  -H 'Content-Type: application/json' \
  -d '{
    "model":"gauss2-37b-instruct",
    "messages":[{"role":"user","content":"안녕하세요. 한 줄로 인사해 주세요."}],
    "stream": false
  }' | sed 's/\\n/\n/g' | head -c 1200
echo

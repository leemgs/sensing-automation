# LLM API Unified (OpenAI · OpenRouter · Grok/xAI)

Single PHP endpoint to talk to three LLM providers with one interface.

## Files
- `llm-api-unified.php` — main endpoint (no getenv; parses `/etc/environment` directly)
- `environment` — template for `/etc/environment` (fill in secrets, then copy to `/etc/environment`)
- `README.md` — this file

## Install
1. Copy files:
   ```bash
   sudo cp llm-api-unified.php /var/www/html/
   sudo cp environment /etc/environment
   ```
2. Edit `/etc/environment` and set real keys.
3. Restart services so PHP can read the new environment file:
   ```bash
   sudo systemctl restart php-fpm || sudo systemctl restart php8.1-fpm
   sudo systemctl restart nginx || sudo systemctl restart apache2
   ```

> This project **does not use `getenv()`**. It parses `/etc/environment` via file I/O, so no PHP-FPM `clear_env` changes are required.

## Usage
HTTP POST to the PHP file:
```bash
curl -s -X POST https://YOUR_HOST/llm-api-unified.php \
  -d 'provider=openrouter' \
  --data-urlencode 'prompt=Say hello from unified client.' | jq .
```

### Body parameters
- `provider` *(required)*: `openai` | `openrouter` | `grok`
- `messages` *(optional)*: JSON array of OpenAI-style messages
- `prompt` *(optional)*: string, used if `messages` is not provided
- `model`, `temperature`, `max_tokens` *(optional)*: override defaults

### Response (normalized)
```jsonc
{
  "provider": "openrouter",
  "model": "openai/gpt-4o-mini",
  "created": 1710000000,
  "content": "Hello from the unified client!",
  "usage": { "prompt_tokens": 9, "completion_tokens": 7, "total_tokens": 16 },
  "raw": { "...": "provider original payload" }
}
```

## Security Notes
- **Never commit real API keys**. Keep `/etc/environment` private.
- Errors mask token-looking strings to avoid accidental leaks.
- Use TLS (HTTPS) in production.

## Troubleshooting
- 4xx from upstream: token invalid/permissions/malformed payload.
- 5xx/cURL error: connectivity or provider outage.
- Check webserver/PHP error logs if endpoint returns 500.

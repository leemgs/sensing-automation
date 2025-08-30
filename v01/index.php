<?php $cfg = require __DIR__ . '/config.php'; ?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<title>Gmail IMAP 뷰어 (PHP)</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  body{font-family:system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif; margin:20px;}
  h1{font-size:1.25rem;margin-bottom:8px}
  .meta{color:#666;margin-bottom:14px}
  .list{display:grid;gap:12px}
  .card{border:1px solid #e5e7eb;border-radius:12px;padding:14px}
  .sub{color:#111;font-weight:600;margin:0 0 6px}
  .from{color:#374151;font-size:0.95rem;margin:0 0 4px}
  .date{color:#6b7280;font-size:0.85rem;margin:0 0 8px}
  .snippet{color:#111;line-height:1.45}
  .badge{display:inline-block;font-size:0.75rem;padding:2px 6px;border-radius:8px;background:#eef2ff;color:#3730a3;margin-left:6px}
  .head{display:flex;align-items:baseline;gap:10px;flex-wrap:wrap}
  .muted{color:#6b7280}
</style>
</head>
<body>
  <div class="head">
    <h1>Gmail IMAP 메일함 (<?=htmlspecialchars($cfg['mailbox'])?>)</h1>
    <span class="muted">자동 새로고침: <?=$cfg['poll_interval']?>초</span>
  </div>
  <div class="meta">최근 메일을 불러옵니다…</div>
  <div id="list" class="list"></div>

<script>
const list = document.getElementById('list');
const meta = document.querySelector('.meta');
let interval = <?=$cfg['poll_interval']?> * 1000;

async function load() {
  meta.textContent = '불러오는 중…';
  try {
    const res = await fetch('fetch_mail.php', {cache: 'no-store'});
    const data = await res.json();
    if (!data.ok) throw new Error(data.error || 'unknown error');
    interval = (data.poll_interval_seconds ?? <?=$cfg['poll_interval']?>) * 1000;

    meta.textContent = `마지막 갱신: ${new Date(data.refreshed_at).toLocaleString()}`;

    list.innerHTML = '';
    if (!data.messages.length) {
      list.innerHTML = '<div class="muted">표시할 메일이 없습니다.</div>';
      return;
    }
    for (const m of data.messages) {
      const card = document.createElement('div');
      card.className = 'card';
      card.innerHTML = `
        <div class="sub">${escapeHtml(m.subject)} ${m.seen ? '' : '<span class="badge">NEW</span>'}</div>
        <div class="from">From: ${escapeHtml(m.from || '')}</div>
        <div class="date">${escapeHtml(m.date || '')}</div>
        <div class="snippet">${escapeHtml(m.snippet || '')}</div>
      `;
      list.appendChild(card);
    }
  } catch (e) {
    meta.textContent = '오류: ' + e.message;
  }
}

function escapeHtml(s){ return (s||'').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }

load();
setInterval(load, interval);
</script>
</body>
</html>

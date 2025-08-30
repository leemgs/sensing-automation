<?php
$cfg = require __DIR__ . '/config.php';
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<title>IMAP 뷰어 + 분석 저장</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;margin:20px;line-height:1.6}
  h1{margin:0 0 8px}
  .bar{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:12px}
  input,select,button{padding:8px 10px;border:1px solid #e5e7eb;border-radius:10px}
  button{cursor:pointer}
  .muted{color:#6b7280}
  .list{display:grid;gap:12px}
  .card{border:1px solid #e5e7eb;border-radius:12px;padding:14px}
  .row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
  .from{font-weight:600}
  .subject{font-size:1.05rem}
  .badge{font-size:.8rem;border:1px solid #e5e7eb;border-radius:999px;padding:2px 8px;background:#f9fafb}
  .att a{margin-right:8px}
</style>
</head>
<body>
<h1>IMAP 메일 뷰어</h1>

<form class="bar" id="f">
  <input type="text" name="q" placeholder="검색어 (X-GM-RAW)" />
  <input type="text" name="label" placeholder='라벨 필터 (예: 업무)' />
  <select name="limit">
    <?php foreach ([20,30,50,100,200] as $n): ?>
      <option value="<?=$n?>" <?= $n==($cfg['max_messages']??30)?'selected':'' ?>>최대 <?=$n?>개</option>
    <?php endforeach; ?>
  </select>
  <button>조회</button>
  <button type="button" id="btn-archive">문서 아카이브</button>
  <label class="muted"><input type="checkbox" id="auto" checked> 자동 갱신</label>
  <span class="muted" id="info"></span>
</form>

<div class="list" id="list"></div>

<script>
const f = document.getElementById('f');
const list = document.getElementById('list');
const info = document.getElementById('info');
const auto = document.getElementById('auto');
const btnArchive = document.getElementById('btn-archive');
btnArchive.addEventListener('click', ()=> location.href='archive.php');

let pollTimer = null;
let pollInterval = <?= (int)$cfg['poll_interval'] ?> * 1000;

f.addEventListener('submit', (e)=>{
  e.preventDefault(); fetchList();
});
auto.addEventListener('change', ()=>{
  if (auto.checked) startPoll(); else stopPoll();
});

function startPoll(){
  stopPoll();
  pollTimer = setInterval(fetchList, pollInterval);
}
function stopPoll(){
  if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
}

async function fetchList(){
  const params = new URLSearchParams(new FormData(f));
  const res = await fetch('fetch_mail.php?' + params.toString());
  const data = await res.json();
  if (!data.ok) { info.textContent = '오류: ' + data.error; return; }
  pollInterval = (data.poll_interval_seconds || 30) * 1000;
  render(data.messages);
  info.textContent = `기준: ${new Date(data.refreshed_at).toLocaleString()} (criteria: ${data.criteria})`;
}
function render(items){
  list.innerHTML = '';
  if (!items.length) { list.innerHTML = '<div class="muted">메시지가 없습니다.</div>'; return; }
  items.forEach(m=>{
    const div = document.createElement('div');
    div.className = 'card';
    div.innerHTML = `
      <div class="row" style="justify-content:space-between">
        <div class="row">
          <span class="from">${escapeHtml(m.from || '')}</span>
          <span class="subject">· ${escapeHtml(m.subject || '')}</span>
          ${m.seen ? '<span class="badge">읽음</span>' : '<span class="badge">안읽음</span>'}
        </div>
        <div class="muted">${escapeHtml(m.date || '')}</div>
      </div>
      <div class="muted" style="margin-top:6px">라벨: ${escapeHtml(m.labels || '')}</div>
      <div class="muted" style="margin-top:6px">${escapeHtml(m.snippet || '')}</div>
      <div class="row" style="margin-top:8px">
        <button data-uid="${m.uid}" class="btn-seen">${m.seen?'읽음 해제':'읽음 처리'}</button>
        <button data-uid="${m.uid}" class="btn-del">삭제</button>
      </div>
      <div class="att" style="margin-top:6px"></div>
    `;
    const att = div.querySelector('.att');
    (m.attachments || []).forEach(a=>{
      const aEl = document.createElement('a');
      aEl.href = a.download_url;
      aEl.textContent = `첨부: ${a.filename} (${a.size||0}B)`;
      aEl.download = a.filename;
      att.appendChild(aEl);
    });
    list.appendChild(div);
  });

  document.querySelectorAll('.btn-seen').forEach(btn=>{
    btn.addEventListener('click', async (e)=>{
      const uid = e.target.getAttribute('data-uid');
      const isRead = e.target.textContent.includes('해제');
      const res = await fetch('set_seen.php', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body: new URLSearchParams({uid, seen: isRead?0:1})
      });
      const data = await res.json();
      if (!data.ok) alert('오류: '+(data.error||''));
      else fetchList();
    });
  });
  document.querySelectorAll('.btn-del').forEach(btn=>{
    btn.addEventListener('click', async (e)=>{
      const uid = e.target.getAttribute('data-uid');
      if (!confirm('정말 삭제하시겠습니까?')) return;
      const res = await fetch('delete_mail.php', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body: new URLSearchParams({uid})
      });
      const data = await res.json();
      if (!data.ok) alert('오류: '+(data.error||''));
      else fetchList();
    });
  });
}

function escapeHtml(s){ return (s||'').replace(/[&<>"']/g, m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[m])); }

fetchList();
startPoll();
</script>
</body>
</html>

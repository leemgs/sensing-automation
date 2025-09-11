<?php
declare(strict_types=1);
require_once __DIR__.'/config.php';
require_once __DIR__.'/utils.php';
require_once __DIR__.'/imap_client.php';
require_once __DIR__.'/analyzer.php';

$env_server = get_env_value('IMAP_SERVER');
$env_email  = get_env_value('IMAP_EMAIL');
$env_pass   = get_env_value('IMAP_PASSWORD');

$server = $_REQUEST['server'] ?? $env_server;
$email  = $_REQUEST['email']  ?? $env_email;
$pass   = $_REQUEST['pass']   ?? $env_pass;

$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 20;
if (!in_array($limit, [10,20,30,40,50], true)) $limit = 20;

$action = $_GET['action'] ?? '';
$popup  = '';
$error  = '';

// 프롬프트 열람 팝업
if ($action === 'prompt') {
    $pfile = __DIR__.'/prompt.txt';
    $content = is_file($pfile) ? file_get_contents($pfile) : '';
    $content = htmlspecialchars($content ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $popup = <<<HTML
    <h4>프롬프트 열람/수정</h4>
    <form method="post" action="?action=save_prompt">
      <textarea name="prompt" style="width:100%;height:300px">{$content}</textarea>
      <div style="margin-top:8px;display:flex;gap:8px;justify-content:flex-end">
        <button class="btn" type="submit">저장</button>
        <button class="btn" type="button" onclick="document.getElementById('popup').style.display='none'">닫기</button>
      </div>
    </form>
HTML;
}

// 프롬프트 저장
if ($action === 'save_prompt' && $_SERVER['REQUEST_METHOD']==='POST') {
    $new = $_POST['prompt'] ?? '';
    $pfile = __DIR__.'/prompt.txt';
    if (trim($new) !== '') {
        file_put_contents($pfile, $new);
        $popup = "<div><b>프롬프트 저장 완료</b></div>";
    } else {
        $error = "프롬프트가 비어 있어 저장하지 않았습니다.";
    }
}

// 보기
if ($action === 'view' && $server && $email && $pass && isset($_GET['num'])) {
    $imap = imap_connect($server, $email, $pass);
    if ($imap) {
        $num = intval($_GET['num']);
        list($kind, $content) = imap_fetch_best($imap, $num);
        $popup = "<h4>메일 본문 (#{$num})</h4><div style='max-height:360px;overflow:auto;background:#fff'>{$content}</div>";
        imap_close($imap);
    } else {
        $error = "IMAP 접속 실패. 서버/계정/비밀번호를 확인하세요.";
    }
}

// 분석
if ($action === 'analyze' && $_SERVER['REQUEST_METHOD']==='POST') {
    $server = $_POST['server'] ?? $server;
    $email  = $_POST['email'] ?? $email;
    $pass   = $_POST['pass'] ?? $pass;
    $num    = isset($_POST['num']) ? intval($_POST['num']) : 0;

    if ($server && $email && $pass && $num > 0) {
        $imap = imap_connect($server, $email, $pass);
        if ($imap) {
            list($k, $content) = imap_fetch_best($imap, $num);
            $plain_for_llm = ($k==='html') ? strip_tags($content) : $content;
            list($ok, $res) = analyze_and_save($plain_for_llm);
            if ($ok) {
                $popup = "<div><b>저장 완료</b><br><code>".h($res['path'])."</code></div>";
                $popup .= "<hr><details open><summary><b>미리보기</b></summary><div style='max-height:340px;overflow:auto;background:#fff'>".$res['preview']."</div></details>";
            } else {
                $error = $res;
                $popup = "<div style='color:#991b1b'><b>오류</b><br>".h($error)."</div>";
            }
            imap_close($imap);
        } else {
            $error = "IMAP 접속 실패. 서버/계정/비밀번호를 확인하세요.";
            $popup = "<div style='color:#991b1b'><b>오류</b><br>".h($error)."</div>";
        }
    } else {
        $error = "필수 항목 누락(서버/이메일/비밀번호/메일번호).";
        $popup = "<div style='color:#991b1b'><b>오류</b><br>".h($error)."</div>";
    }
}

// 목록
$emails = [];
if ($server && $email && $pass) {
    $imap = imap_connect($server, $email, $pass);
    if ($imap) {
        $emails = imap_list_latest($imap, $limit);
        imap_close($imap);
    } else if (!$error) {
        $error = "IMAP 접속 실패. 서버/계정/비밀번호를 확인하세요.";
    }
}

$or_key = get_env_value('OPENROUTER_API_KEY');
$key_status = $or_key ? "<b style='color:#065f46'>감지됨</b>" : "<b style='color:#991b1b'>미검출</b>";
?><!doctype html>
<meta charset="utf-8">
<title>Gmail IMAP → LLM 센싱 자동화 (v05)</title>
<style>
body{font-family:system-ui,Segoe UI,Arial,sans-serif;margin:0;color:#111}
header{background:#111;color:#fff;padding:10px 16px;display:flex;justify-content:space-between;align-items:center}
main{padding:16px;max-width:1200px;margin:0 auto}
input,button,select,textarea{font:inherit;padding:6px 10px}
table{border-collapse:collapse;width:100%}
th,td{border-bottom:1px solid #ececec;padding:8px;vertical-align:top}
.btn{background:#111;color:#fff;border:none;border-radius:10px;padding:6px 10px;text-decoration:none;display:inline-block;cursor:pointer}
.btn:hover{opacity:.9}
.card{border:1px solid #e5e7eb;border-radius:12px;padding:12px;margin:8px 0}
.small{font-size:.9rem;color:#6b7280}
.err{color:#991b1b;background:#fef2f2;border:1px solid #fecaca;padding:8px 10px;border-radius:8px}
#popup{display:none;position:fixed;top:12px;right:12px;width:560px;max-height:560px;overflow:auto;
  background:#f8fafc;border:1px solid #1f2937;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,.15);z-index:9999}
#popup header{background:#1f2937;color:#fff;border-radius:12px 12px 0 0;padding:8px 12px}
#popup .content{padding:10px}
#popup .close{float:right;background:transparent;border:0;color:#fff;font-size:16px;cursor:pointer}
</style>

<header>
  <div>
    <strong>Gmail IMAP → OpenRouter LLM 센싱 자동화 (v05)</strong>
    <div class="small">IMAP 서버: <?=h($server ?: $env_server)?> &nbsp;|&nbsp; IMAP 이메일: <?=h($email ?: $env_email)?> &nbsp;|&nbsp; OpenRouter 키: <?=$key_status?></div>
  </div>
  <div>
    <a class="btn" href="?action=prompt&server=<?=urlencode($server)?>&email=<?=urlencode($email)?>&pass=<?=urlencode($pass)?>&limit=<?=$limit?>">프롬프트 열람</a>
  </div>
</header>

<main>
  <?php if ($error): ?><div class="err"><?=h($error)?></div><?php endif; ?>

  <div class="card">
    <form method="get">
      <div style="display:flex;gap:12px;flex-wrap:wrap">
        <div>
          <label>IMAP 서버</label><br>
          <input name="server" value="<?=h($server)?>" size="28" placeholder="imap.gmail.com" required>
        </div>
        <div>
          <label>이메일</label><br>
          <input name="email" value="<?=h($email)?>" size="28" required>
        </div>
        <div>
          <label>비밀번호/앱 비밀번호</label><br>
          <input name="pass" value="<?=h($pass)?>" size="20" type="password" required>
        </div>
        <div>
          <label>출력 개수</label><br>
          <select name="limit" onchange="this.form.submit()">
            <?php foreach([10,20,30,40,50] as $n): ?>
              <option value="<?=$n?>" <?=$limit==$n?'selected':''?>><?=$n?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div style="align-self:flex-end">
          <button class="btn" type="submit">연결/새로고침</button>
        </div>
      </div>
    </form>
  </div>

  <?php if ($emails): ?>
    <div class="card">
      <h3>메일 목록 (최신 <?=$limit?>개)</h3>
      <table>
        <thead><tr><th style="width:60px">No</th><th>제목</th><th>보낸사람</th><th style="width:180px">날짜</th><th style="width:220px">동작</th></tr></thead>
        <tbody>
        <?php foreach ($emails as $m): ?>
          <tr>
            <td class="small">#<?=h((string)$m['idx'])?></td>
            <td><?=h($m['subject'])?></td>
            <td><?=h($m['from'])?></td>
            <td class="small"><?=h($m['date'])?></td>
            <td>
              <a class="btn" href="?action=view&num=<?=$m['num']?>&server=<?=urlencode($server)?>&email=<?=urlencode($email)?>&pass=<?=urlencode($pass)?>&limit=<?=$limit?>">보기</a>
              <form method="post" action="?action=analyze&limit=<?=$limit?>" style="display:inline">
                <input type="hidden" name="server" value="<?=h($server)?>">
                <input type="hidden" name="email"  value="<?=h($email)?>">
                <input type="hidden" name="pass"   value="<?=h($pass)?>">
                <input type="hidden" name="num"    value="<?=$m['num']?>">
                <button class="btn" type="submit">LLM 분석</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</main>

<div id="popup">
  <header>
    <span>결과</span>
    <button class="close" onclick="document.getElementById('popup').style.display='none'">×</button>
  </header>
  <div class="content" id="popupContent"></div>
</div>

<script>
function showPopup(html){
  var box = document.getElementById('popup');
  var body = document.getElementById('popupContent');
  body.innerHTML = html;
  box.style.display = 'block';
}
<?php if ($popup): ?>
  showPopup(<?=json_encode($popup)?>);
<?php endif; ?>
</script>

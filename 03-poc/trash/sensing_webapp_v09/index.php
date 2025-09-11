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

// LLM 파라미터
$max_tokens = isset($_GET['max_tokens']) ? intval($_GET['max_tokens']) : DEFAULT_MAX_TOKENS;
if ($max_tokens <= 0) $max_tokens = DEFAULT_MAX_TOKENS;
$temperature = isset($_GET['temperature']) ? floatval($_GET['temperature']) : DEFAULT_TEMPERATURE;
if ($temperature < 0) $temperature = 0.0; if ($temperature > 2) $temperature = 2.0;

// API 리스트/선택
$apilist = api_list_load();
$api_id  = $_GET['api_id'] ?? ($apilist['active'] ?? 'openrouter');
$active_api = api_get_active($apilist, $api_id);

$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 20;
if (!in_array($limit, [10,20,30,40,50], true)) $limit = 20;

$action = $_GET['action'] ?? '';
$popup  = '';
$error  = '';

// LLM API 수정 팝업
if ($action === 'api_edit') {
    $active_id = h($api_id);
    $item = $active_api;
    $endpoint = h($item['endpoint'] ?? '');
    $model = h($item['model'] ?? '');
    $auth_env = h($item['auth_env'] ?? '');
    $headers = h(json_encode($item['headers'] ?? ['Content-Type'=>'application/json'], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
    $popup = <<<HTML
    <h4>LLM API 설정 편집 (현재 선택: {$active_id})</h4>
    <form method="post" action="?action=api_save&api_id={$active_id}&server={urlencode($server)}&email={urlencode($email)}&pass={urlencode($pass)}&limit={$limit}&max_tokens={$max_tokens}&temperature={$temperature}">
      <div>Endpoint URL</div>
      <input name="endpoint" value="{$endpoint}" style="width:100%">
      <div style="margin-top:8px">Model</div>
      <input name="model" value="{$model}" style="width:100%">
      <div style="margin-top:8px">Auth ENV 변수명</div>
      <input name="auth_env" value="{$auth_env}" style="width:100%">
      <div style="margin-top:8px">추가 헤더(JSON)</div>
      <textarea name="headers" style="width:100%;height:140px">{$headers}</textarea>
      <div style="margin-top:10px;display:flex;gap:8px;justify-content:flex-end">
        <button class="btn" type="submit">저장</button>
        <button class="btn" type="button" onclick="document.getElementById('popup').style.display='none'">닫기</button>
      </div>
    </form>
HTML;
}

// LLM API 저장
if ($action === 'api_save' && $_SERVER['REQUEST_METHOD']==='POST') {
    $endpoint = trim($_POST['endpoint'] ?? '');
    $model = trim($_POST['model'] ?? '');
    $auth_env = trim($_POST['auth_env'] ?? '');
    $headers_raw = $_POST['headers'] ?? '';
    $hdr = json_decode($headers_raw, true);
    if (!is_array($hdr)) $hdr = ['Content-Type'=>'application/json'];

    $apilist['active'] = $api_id;
    $items = $apilist['items'] ?? [];
    $found = false;
    foreach ($items as &$it) {
        if (($it['id'] ?? '') === $api_id) {
            $it['endpoint'] = $endpoint;
            $it['model'] = $model;
            $it['auth_env'] = $auth_env;
            $it['headers'] = $hdr;
            $found = true; break;
        }
    }
    if (!$found) {
        $items[] = ['id'=>$api_id,'label'=>$api_id,'endpoint'=>$endpoint,'model'=>$model,'auth_env'=>$auth_env,'headers'=>$hdr];
    }
    $apilist['items'] = $items;
    if (!api_list_save($apilist)) {
        $popup = "<div style='color:#991b1b'><b>오류</b><br>llm-api-list.json 파일에 쓸 권한이 없습니다. (경로: ".h(API_LIST_FILE).")</div>";
    } else {
        $popup = "<div><b>LLM API 설정 저장 완료</b></div>";
        $active_api = api_get_active($apilist, $api_id);
    }
}

// 프롬프트 열람/수정
if ($action === 'prompt') {
    $pfile = __DIR__.'/prompt.txt';
    $content = is_file($pfile) ? file_get_contents($pfile) : '';
    $content = htmlspecialchars($content ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $popup = <<<HTML
    <h4>프롬프트 열람/수정</h4>
    <form method="post" action="?action=save_prompt&server={urlencode($server)}&email={urlencode($email)}&pass={urlencode($pass)}&limit={$limit}&max_tokens={$max_tokens}&temperature={$temperature}&api_id={$api_id}">
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
    if (trim($new) === '') {
        $error = "프롬프트가 비어 있어 저장하지 않았습니다.";
    } else {
        $dir = dirname($pfile);
        $can_write = (file_exists($pfile) ? is_writable($pfile) : is_writable($dir));
        if (!$can_write) {
            $error = "프롬프트 파일에 쓸 권한이 없습니다. (경로: $pfile)";
            $popup = "<div style='color:#991b1b'><b>오류</b><br>".h($error)."</div>";
        } else {
            $ok = @file_put_contents($pfile, $new);
            if ($ok === false) {
                $error = "프롬프트 저장 중 오류가 발생했습니다. 파일 권한을 확인하세요. (경로: $pfile)";
                $popup = "<div style='color:#991b1b'><b>오류</b><br>".h($error)."</div>";
            } else {
                $popup = "<div><b>프롬프트 저장 완료</b></div>";
            }
        }
    }
}

// 보기
if ($action === 'view' && $server && $email && $pass && isset($_GET['num'])) {
    $imap = imap_connect($server, $email, $pass);
    if ($imap) {
        $num = intval($_GET['num']);
        list($kind, $content) = imap_fetch_best($imap, $num);
        if ($kind === 'html') {
            $srcdoc = htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $popup = "<h4>메일 본문 (#{$num})</h4><iframe style='width:100%;height:380px;border:0;background:#fff' srcdoc='{$srcdoc}'></iframe>";
        } else {
            $popup = "<h4>메일 본문 (#{$num})</h4><div style='max-height:380px;overflow:auto;background:#fff'>{$content}</div>";
        }
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
    $uid    = isset($_POST['uid']) ? (string)$_POST['uid'] : '';
    $subject= isset($_POST['subject']) ? (string)$_POST['subject'] : '';
    $max_tokens_p = isset($_POST['max_tokens']) ? intval($_POST['max_tokens']) : $max_tokens;
    if ($max_tokens_p <= 0) $max_tokens_p = DEFAULT_MAX_TOKENS;
    $temperature_p = isset($_POST['temperature']) ? floatval($_POST['temperature']) : $temperature;
    if ($temperature_p < 0) $temperature_p = 0.0; if ($temperature_p > 2) $temperature_p = 2.0;

    // API: 현재 선택값 재로딩
    $apilist = api_list_load();
    $api_id_p = $_POST['api_id'] ?? ($apilist['active'] ?? 'openrouter');
    $active_api = api_get_active($apilist, $api_id_p);

    if ($server && $email && $pass && $num > 0) {
        $imap = imap_connect($server, $email, $pass);
        if ($imap) {
            list($k, $content) = imap_fetch_best($imap, $num);
            $plain_for_llm = ($k==='html') ? strip_tags($content) : $content;
            list($ok, $res) = analyze_and_save($plain_for_llm, $max_tokens_p, $temperature_p, $active_api, $uid, $subject);
            if ($ok) {
                $srcdoc = htmlspecialchars($res['preview'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $popup = "<div><b>저장 완료</b><br><code>".h($res['path'])."</code></div>";
                if (!empty($res['log'])) {
                    $rel = str_replace(SAVE_BASE, '', $res['log']);
                    $popup .= "<div class='small'>로그: <a href='".h($rel)."' target='_blank'>다운로드</a></div>";
                }
                $popup .= "<hr><iframe style='width:100%;height:360px;border:0;background:#fff' srcdoc='{$srcdoc}'></iframe>";
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

// 삭제 전 미리보기
if ($action === 'delete_preview' && $_SERVER['REQUEST_METHOD']==='POST') {
    $nums = isset($_POST['nums']) && is_array($_POST['nums']) ? array_map('intval', $_POST['nums']) : [];
    if (!$nums) {
        $popup = "<div style='color:#991b1b'><b>오류</b><br>선택된 메일이 없습니다.</div>";
    } else {
        $rows = "";
        $subjects = $_POST['subjects'] ?? [];
        $froms = $_POST['froms'] ?? [];
        $dates = $_POST['dates'] ?? [];
        foreach ($nums as $n) {
            $sj = isset($subjects[$n]) ? h($subjects[$n]) : '';
            $fr = isset($froms[$n]) ? h($froms[$n]) : '';
            $dt = isset($dates[$n]) ? h($dates[$n]) : '';
            $rows .= "<tr><td>#{$n}</td><td>{$sj}</td><td>{$fr}</td><td class='small'>{$dt}</td></tr>";
        }
        $hidden = "";
        foreach ($nums as $n) { $hidden .= "<input type='hidden' name='nums[]' value='{$n}'>"; }
        $popup = <<<HTML
        <h4>삭제 미리보기</h4>
        <div class="small">아래 메일을 삭제하시겠습니까? 이 작업은 되돌릴 수 없습니다.</div>
        <div style="max-height:300px;overflow:auto;background:#fff">
          <table style="width:100%;border-collapse:collapse">
            <thead><tr><th style="width:80px">No</th><th>제목</th><th>보낸사람</th><th style="width:200px">날짜</th></tr></thead>
            <tbody>{$rows}</tbody>
          </table>
        </div>
        <form method="post" action="?action=delete&server={urlencode($server)}&email={urlencode($email)}&pass={urlencode($pass)}&limit={$limit}&max_tokens={$max_tokens}&temperature={$temperature}&api_id={$api_id}" style="margin-top:8px;display:flex;gap:8px;justify-content:flex-end">
          {$hidden}
          <button class="btn" type="submit">확인 후 삭제</button>
          <button class="btn" type="button" onclick="document.getElementById('popup').style.display='none'">취소</button>
        </form>
HTML;
    }
}

// 최종 삭제
if ($action === 'delete' && $_SERVER['REQUEST_METHOD']==='POST') {
    $server = $_POST['server'] ?? $server;
    $email  = $_POST['email'] ?? $email;
    $pass   = $_POST['pass'] ?? $pass;
    $nums   = isset($_POST['nums']) && is_array($_POST['nums']) ? $_POST['nums'] : [];
    if ($server && $email && $pass && $nums) {
        $imap = imap_connect($server, $email, $pass);
        if ($imap) {
            $deleted = imap_delete_nums($imap, $nums);
            imap_close($imap);
            if ($deleted) {
                $popup = "<div><b>삭제 완료</b> (" . count($deleted) . "개) : " . h(implode(', ', $deleted)) . "</div>";
            } else {
                $popup = "<div style='color:#991b1b'><b>오류</b><br>삭제할 항목이 없거나 실패했습니다.</div>";
            }
        } else {
            $popup = "<div style='color:#991b1b'><b>오류</b><br>IMAP 접속 실패</div>";
        }
    } else {
        $popup = "<div style='color:#991b1b'><b>오류</b><br>선택된 메일이 없습니다.</div>";
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

// Key 존재 여부
$token_env = $active_api['auth_env'] ?? 'OPENROUTER_API_KEY';
$token_val = get_env_value($token_env);
$key_status = $token_val ? "<b style='color:#065f46'>감지됨</b>" : "<b style='color:#991b1b'>미검출</b>";
?><!doctype html>
<meta charset="utf-8">
<title>Gmail IMAP → LLM 센싱 자동화 (v09)</title>
<style>
body{font-family:system-ui,Segoe UI,Arial,sans-serif;margin:0;color:#111}
header{background:#111;color:#fff;padding:10px 16px;display:flex;justify-content:space-between;align-items:center;gap:12px}
main{padding:16px;max-width:1200px;margin:0 auto}
input,button,select,textarea{font:inherit;padding:6px 10px}
input[type=number]{width:100px}
table{border-collapse:collapse;width:100%}
th,td{border-bottom:1px solid #ececec;padding:8px;vertical-align:top}
.btn{background:#111;color:#fff;border:none;border-radius:10px;padding:6px 10px;text-decoration:none;display:inline-block;cursor:pointer}
.btn:hover{opacity:.9}
.card{border:1px solid #e5e7eb;border-radius:12px;padding:12px;margin:8px 0}
.small{font-size:.9rem;color:#d1d5db}
.err{color:#991b1b;background:#fef2f2;border:1px solid #fecaca;padding:8px 10px;border-radius:8px}
#popup{display:none;position:fixed;top:12px;right:12px;width:620px;max-height:620px;overflow:auto;
  background:#f8fafc;border:1px solid #1f2937;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,.15);z-index:9999;
  resize: both; min-width:360px; min-height:240px; max-width:90vw; max-height:90vh;}
#popup header{background:#1f2937;color:#fff;border-radius:12px 12px 0 0;padding:8px 12px;cursor:move}
#popup .content{padding:10px}
#popup .close{float:right;background:transparent;border:0;color:#fff;font-size:16px;cursor:pointer}
.header-right{display:flex;align-items:center;gap:8px}
</style>

<header>
  <div>
    <strong>Gmail IMAP → LLM 센싱 자동화 (v09)</strong>
    <div class="small">
      API: <?=h($active_api['id'] ?? '')?> (모델: <?=h($active_api['model'] ?? '')?>) &nbsp;|&nbsp; 토큰: <?=$key_status?>
      &nbsp;|&nbsp; IMAP 서버: <?=h($server ?: $env_server)?> &nbsp;|&nbsp; IMAP 이메일: <?=h($email ?: $env_email)?>
    </div>
  </div>
  <div class="header-right">
    <form method="get" style="display:flex;align-items:center;gap:6px">
      <input type="hidden" name="server" value="<?=h($server)?>">
      <input type="hidden" name="email" value="<?=h($email)?>">
      <input type="hidden" name="pass" value="<?=h($pass)?>">
      <input type="hidden" name="limit" value="<?=$limit?>">
      <label class="small">API</label>
      <select name="api_id">
        <?php foreach (($apilist['items'] ?? []) as $it): $id=$it['id']; ?>
          <option value="<?=$id?>" <?=$api_id===$id?'selected':''?>><?=h($it['label'] ?? $id)?></option>
        <?php endforeach; ?>
      </select>
      <label class="small">max_tokens</label><input type="number" name="max_tokens" value="<?=$max_tokens?>" min="1" max="8192">
      <label class="small">temperature</label><input type="number" name="temperature" value="<?=$temperature?>" step="0.1" min="0" max="2">
      <button class="btn" type="submit">적용</button>
    </form>
    <a class="btn" href="?action=api_edit&server=<?=urlencode($server)?>&email=<?=urlencode($email)?>&pass=<?=urlencode($pass)?>&limit=<?=$limit?>&max_tokens=<?=$max_tokens?>&temperature=<?=$temperature?>&api_id=<?=$api_id?>">LLM API 설정</a>
    <a class="btn" href="?action=prompt&server=<?=urlencode($server)?>&email=<?=urlencode($email)?>&pass=<?=urlencode($pass)?>&limit=<?=$limit?>&max_tokens=<?=$max_tokens?>&temperature=<?=$temperature?>&api_id=<?=$api_id?>">프롬프트 열람</a>
  </div>
</header>

<main>
  <?php if ($error): ?><div class="err"><?=h($error)?></div><?php endif; ?>

  <div class="card">
    <form method="get">
      <div style="display:flex;gap:12px;flex-wrap:wrap">
        <input type="hidden" name="api_id" value="<?=h($api_id)?>">
        <input type="hidden" name="max_tokens" value="<?=$max_tokens?>">
        <input type="hidden" name="temperature" value="<?=$temperature?>">
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

  <?php
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
  if ($emails):
  ?>
    <form method="post" action="?action=delete_preview&server=<?=urlencode($server)?>&email=<?=urlencode($email)?>&pass=<?=urlencode($pass)?>&limit=<?=$limit?>&max_tokens=<?=$max_tokens?>&temperature=<?=$temperature?>&api_id=<?=$api_id?>" class="card">
      <h3>메일 목록 (최신 <?=$limit?>개)</h3>
      <div style="margin-bottom:8px;display:flex;gap:8px;align-items:center">
        <button class="btn" type="submit">선택 삭제</button>
        <span class="small">* 삭제 전 미리보기 창이 열립니다.</span>
      </div>
      <table>
        <thead><tr><th style="width:40px"><input type="checkbox" onclick="document.querySelectorAll('.chk').forEach(c=>c.checked=this.checked)"></th><th style="width:60px">No</th><th>제목</th><th>보낸사람</th><th style="width:180px">날짜</th><th style="width:280px">동작</th></tr></thead>
        <tbody>
        <?php foreach ($emails as $m): ?>
          <tr>
            <td><input type="checkbox" class="chk" name="nums[]" value="<?=$m['num']?>"></td>
            <td class="small">#<?=h((string)$m['idx'])?></td>
            <td><?=h($m['subject'])?></td>
            <td><?=h($m['from'])?></td>
            <td class="small"><?=h($m['date'])?></td>
            <td>
              <a class="btn" href="?action=view&num=<?=$m['num']?>&server=<?=urlencode($server)?>&email=<?=urlencode($email)?>&pass=<?=urlencode($pass)?>&limit=<?=$limit?>&max_tokens=<?=$max_tokens?>&temperature=<?=$temperature?>&api_id=<?=$api_id?>">보기</a>
              <form method="post" action="?action=analyze&limit=<?=$limit?>&max_tokens=<?=$max_tokens?>&temperature=<?=$temperature?>&api_id=<?=$api_id?>" style="display:inline">
                <input type="hidden" name="server" value="<?=h($server)?>">
                <input type="hidden" name="email"  value="<?=h($email)?>">
                <input type="hidden" name="pass"   value="<?=h($pass)?>">
                <input type="hidden" name="num"    value="<?=$m['num']?>">
                <input type="hidden" name="uid"    value="<?=$m['uid']?>">
                <input type="hidden" name="subject" value="<?=h($m['subject'])?>">
                <input type="hidden" name="max_tokens" value="<?=$max_tokens?>">
                <input type="hidden" name="temperature" value="<?=$temperature?>">
                <input type="hidden" name="api_id" value="<?=h($api_id)?>">
                <button class="btn" type="submit">LLM 분석</button>
              </form>
              <!-- 삭제 미리보기용 메타 -->
              <input type="hidden" name="subjects[<?=$m['num']?>]" value="<?=h($m['subject'])?>">
              <input type="hidden" name="froms[<?=$m['num']?>]" value="<?=h($m['from'])?>">
              <input type="hidden" name="dates[<?=$m['num']?>]" value="<?=h($m['date'])?>">
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </form>
  <?php endif; ?>
</main>

<div id="popup">
  <header id="popupHeader">
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

// 드래그 이동
(function(){
  const box = document.getElementById('popup');
  const header = document.getElementById('popupHeader');
  let offsetX=0, offsetY=0, dragging=false;
  header.addEventListener('mousedown', function(e){
    dragging = true;
    const rect = box.getBoundingClientRect();
    offsetX = e.clientX - rect.left;
    offsetY = e.clientY - rect.top;
    document.body.style.userSelect='none';
  });
  window.addEventListener('mousemove', function(e){
    if(!dragging) return;
    let x = e.clientX - offsetX;
    let y = e.clientY - offsetY;
    if (x < 0) x = 0;
    if (y < 0) y = 0;
    box.style.left = x + 'px';
    box.style.top  = y + 'px';
    box.style.right = 'auto';
  });
  window.addEventListener('mouseup', function(){
    dragging = false;
    document.body.style.userSelect='';
  });
})();

<?php if ($popup): ?>
  showPopup(<?=json_encode($popup)?>);
<?php endif; ?>
</script>

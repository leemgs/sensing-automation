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

$apilist = api_list_load();
$api_id  = $_GET['api_id'] ?? ($apilist['active'] ?? 'openrouter');
if (isset($_GET['api_id']) && $api_id !== ($apilist['active'] ?? '')) {
    $apilist['active'] = $api_id;
    if (!api_list_save($apilist)) { $header_err = "활성 API 저장 실패: llm-api-list.json 쓰기 권한을 확인하세요."; }
}
$active_api = api_get_active($apilist);

$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 20;
if (!in_array($limit, [10,20,30,40,50], true)) $limit = 20;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;

$action = $_GET['action'] ?? '';
$popup  = '';
$error  = '';

// ---- API 관리/프롬프트 ----
if ($action === 'api_manage') {
    $items = $apilist['items'] ?? [];
    $rows = "";
    foreach ($items as $it) {
        $id = h($it['id'] ?? '');
        $label = h($it['label'] ?? '');
        $model = h($it['model'] ?? '');
        $endpoint = h($it['endpoint'] ?? '');
        $auth_env = h($it['auth_env'] ?? '');
        $dmt = h((string)($it['default_max_tokens'] ?? 1400));
        $dtemp = h((string)($it['default_temperature'] ?? 0.2));
        $rows .= "<tr><td>{$id}</td><td>{$label}</td><td>{$model}</td><td class='small'>{$endpoint}</td><td>{$auth_env}</td><td>{$dmt}</td><td>{$dtemp}</td>
        <td>
          <form method='get' style='display:inline' class='no-anim'>
            <input type='hidden' name='action' value='api_edit'>
            <input type='hidden' name='api_id' value='{$id}'>
            <button class='btn' type='submit'>편집</button>
          </form>
          <form method='post' action='?action=api_delete' style='display:inline' onsubmit='return confirm(\"정말 삭제하시겠습니까?\")' class='no-anim'>
            <input type='hidden' name='api_id' value='{$id}'>
            <button class='btn' type='submit'>삭제</button>
          </form>
        </td></tr>";
    }
    $popup = <<<HTML
    <h4>LLM API 관리</h4>
    <div style="max-height:300px;overflow:auto;background:#fff">
      <table style="width:100%;border-collapse:collapse">
        <thead><tr><th>ID</th><th>Label</th><th>Model</th><th>Endpoint</th><th>Auth ENV</th><th>max_tokens</th><th>temperature</th><th>동작</th></tr></thead>
        <tbody>{$rows}</tbody>
      </table>
    </div>
    <hr>
    <h4>새 항목 추가</h4>
    <form method="post" action="?action=api_new" class="no-anim">
      <div>ID</div><input name="id" style="width:100%" required>
      <div style="margin-top:6px">Label</div><input name="label" style="width:100%" required>
      <div style="margin-top:6px">Endpoint</div><input name="endpoint" style="width:100%" required>
      <div style="margin-top:6px">Model</div><input name="model" style="width:100%" required>
      <div style="margin-top:6px">Auth ENV</div><input name="auth_env" style="width:100%" placeholder="예: OPENROUTER_API_KEY / HF_TOKEN" required>
      <div style="margin-top:6px">추가 헤더(JSON)</div><textarea name="headers" style="width:100%;height:80px">{ "Content-Type": "application/json" }</textarea>
      <div style="margin-top:6px">default_max_tokens</div><input name="default_max_tokens" type="number" value="1400" min="1">
      <div style="margin-top:6px">default_temperature</div><input name="default_temperature" type="number" value="0.2" step="0.1" min="0" max="2">
      <div style="margin-top:10px;display:flex;gap:8px;justify-content:flex-end">
        <button class="btn" type="submit">추가</button>
        <button class="btn" type="button" onclick="__hideLoading(); document.getElementById('popup').style.display='none'">닫기</button>
      </div>
    </form>
HTML;
}
if ($action === 'api_new' && $_SERVER['REQUEST_METHOD']==='POST') {
    $id = trim($_POST['id'] ?? '');
    $label = trim($_POST['label'] ?? '');
    $endpoint = trim($_POST['endpoint'] ?? '');
    $model = trim($_POST['model'] ?? '');
    $auth_env = trim($_POST['auth_env'] ?? '');
    $headers = json_decode($_POST['headers'] ?? '', true);
    if (!is_array($headers)) $headers = ['Content-Type'=>'application/json'];
    $dmt = (int)($_POST['default_max_tokens'] ?? 1400);
    $dtemp = (float)($_POST['default_temperature'] ?? 0.2);
    $apilist = api_list_load();
    if ($id && $endpoint && $model && $auth_env) {
        $exists = api_get_by_id($apilist, $id);
        if ($exists) {
            $popup = "<div style='color:#991b1b'><b>오류</b><br>이미 존재하는 ID 입니다.</div>";
        } else {
            $apilist['items'][] = [
                'id'=>$id,'label'=>$label?:$id,'endpoint'=>$endpoint,'model'=>$model,'auth_env'=>$auth_env,'headers'=>$headers,
                'default_max_tokens'=>$dmt,'default_temperature'=>$dtemp
            ];
            if (!api_list_save($apilist)) {
                $popup = "<div style='color:#991b1b'><b>오류</b><br>llm-api-list.json 파일에 쓸 권한이 없습니다.</div>";
            } else { $popup = "<div><b>추가 완료</b></div>"; }
        }
    } else { $popup = "<div style='color:#991b1b'><b>오류</b><br>필수 항목이 누락되었습니다.</div>"; }
}
if ($action === 'api_edit') {
    $apilist = api_list_load();
    $id = $_GET['api_id'] ?? '';
    $it = api_get_by_id($apilist, $id) ?? [];
    $endpoint = h($it['endpoint'] ?? '');
    $model = h($it['model'] ?? '');
    $auth_env = h($it['auth_env'] ?? '');
    $headers = h(json_encode($it['headers'] ?? ['Content-Type'=>'application/json'], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
    $dmt = h((string)($it['default_max_tokens'] ?? 1400));
    $dtemp = h((string)($it['default_temperature'] ?? 0.2));
    $popup = <<<HTML
    <h4>LLM API 설정 편집 (ID: {$id})</h4>
    <form method="post" action="?action=api_save&api_id={$id}" class="no-anim">
      <div>Endpoint URL</div><input name="endpoint" value="{$endpoint}" style="width:100%">
      <div style="margin-top:8px">Model</div><input name="model" value="{$model}" style="width:100%">
      <div style="margin-top:8px">Auth ENV 변수명</div><input name="auth_env" value="{$auth_env}" style="width:100%">
      <div style="margin-top:8px">추가 헤더(JSON)</div><textarea name="headers" style="width:100%;height:120px">{$headers}</textarea>
      <div style="margin-top:8px">default_max_tokens</div><input name="default_max_tokens" type="number" value="{$dmt}" min="1">
      <div style="margin-top:8px">default_temperature</div><input name="default_temperature" type="number" value="{$dtemp}" step="0.1" min="0" max="2">
      <div style="margin-top:10px;display:flex;gap:8px;justify-content:flex-end">
        <button class="btn" type="submit">저장</button>
        <button class="btn" type="button" onclick="__hideLoading(); document.getElementById('popup').style.display='none'">닫기</button>
      </div>
    </form>
HTML;
}
if ($action === 'api_save' && $_SERVER['REQUEST_METHOD']==='POST') {
    $id = $_GET['api_id'] ?? '';
    $endpoint = trim($_POST['endpoint'] ?? '');
    $model = trim($_POST['model'] ?? '');
    $auth_env = trim($_POST['auth_env'] ?? '');
    $headers = json_decode($_POST['headers'] ?? '', true);
    if (!is_array($headers)) $headers = ['Content-Type'=>'application/json'];
    $dmt = (int)($_POST['default_max_tokens'] ?? 1400);
    $dtemp = (float)($_POST['default_temperature'] ?? 0.2);

    $apilist = api_list_load();
    $updated = false;
    foreach ($apilist['items'] as &$it) {
        if (($it['id'] ?? '') === $id) {
            $it['endpoint']=$endpoint; $it['model']=$model; $it['auth_env']=$auth_env; $it['headers']=$headers;
            $it['default_max_tokens']=$dmt; $it['default_temperature']=$dtemp;
            $updated = true; break;
        }
    }
    if ($updated) {
        if (!api_list_save($apilist)) {
            $popup = "<div style='color:#991b1b'><b>오류</b><br>llm-api-list.json 파일에 쓸 권한이 없습니다.</div>";
        } else { $popup = "<div><b>LLM API 설정 저장 완료</b></div>"; }
    } else { $popup = "<div style='color:#991b1b'><b>오류</b><br>대상 ID를 찾을 수 없습니다.</div>"; }
}
if ($action === 'api_delete' && $_SERVER['REQUEST_METHOD']==='POST') {
    $id = $_POST['api_id'] ?? '';
    $apilist = api_list_load();
    $items = $apilist['items'] ?? [];
    $items = array_values(array_filter($items, function($it) use ($id){ return ($it['id'] ?? '') !== $id; }));
    $apilist['items'] = $items;
    if (($apilist['active'] ?? '') === $id) {
        $apilist['active'] = $items ? ($items[0]['id'] ?? 'openrouter') : 'openrouter';
    }
    if (!api_list_save($apilist)) {
        $popup = "<div style='color:#991b1b'><b>오류</b><br>llm-api-list.json 파일에 쓸 권한이 없습니다.</div>";
    } else { $popup = "<div><b>삭제 완료</b></div>"; }
}

// 프롬프트 열람/저장
if ($action === 'prompt') {
    $pfile = __DIR__.'/prompt.txt';
    $content = is_file($pfile) ? file_get_contents($pfile) : '';
    $content = htmlspecialchars($content ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $popup = <<<HTML
    <h4>프롬프트 열람/수정</h4>
    <form method="post" action="?action=save_prompt" class="no-anim">
      <textarea name="prompt" style="width:100%;height:300px">{$content}</textarea>
      <div style="margin-top:8px;display:flex;gap:8px;justify-content:flex-end">
        <button class="btn" type="submit">저장</button>
        <button class="btn" type="button" onclick="__hideLoading(); document.getElementById('popup').style.display='none'">닫기</button>
      </div>
    </form>
HTML;
}
if ($action === 'save_prompt' && $_SERVER['REQUEST_METHOD']==='POST') {
    $new = $_POST['prompt'] ?? '';
    $pfile = __DIR__.'/prompt.txt';
    if (trim($new) === '') {
        $error = "프롬프트가 비어 있어 저장하지 않았습니다.";
    } else {
        $dir = dirname($pfile);
        $can_write = (file_exists($pfile) ? is_writable($pfile) : is_writable($dir));
        if (!$can_write) { $error = "프롬프트 파일에 쓸 권한이 없습니다. (경로: $pfile)"; $popup = "<div style='color:#991b1b'><b>오류</b><br>".h($error)."</div>"; }
        else {
            $ok = @file_put_contents($pfile, $new);
            if ($ok === false) { $error = "프롬프트 저장 중 오류가 발생했습니다. 파일 권한을 확인하세요. (경로: $pfile)"; $popup = "<div style='color:#991b1b'><b>오류</b><br>".h($error)."</div>"; }
            else { $popup = "<div><b>프롬프트 저장 완료</b></div>"; }
        }
    }
}

// 보기
if ($action === 'view' && $server && $email && $pass && isset($_GET['num'])) {
    $imap = imap_connect($server, $email, $pass);
    if ($imap) {
        $num = intval($_GET['num']); $disp_idx = isset($_GET['idx']) ? intval($_GET['idx']) : $num;
        list($kind, $content) = imap_fetch_best($imap, $num);
        if ($kind === 'html') {
            $srcdoc = htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $popup = "<h4>메일 본문 (#{$disp_idx})</h4><iframe style='width:100%;height:380px;border:0;background:#fff' srcdoc='{$srcdoc}'></iframe>";
        } else {
            $popup = "<h4>메일 본문 (#{$disp_idx})</h4><div style='max-height:380px;overflow:auto;background:#fff'>{$content}</div>";
        }
        imap_close($imap);
    } else { $error = "IMAP 접속 실패. 서버/계정/비밀번호를 확인하세요."; }
}

// 분석
if ($action === 'analyze' && $_SERVER['REQUEST_METHOD']==='POST') {
    $server = $_POST['server'] ?? $server;
    $email  = $_POST['email'] ?? $email;
    $pass   = $_POST['pass'] ?? $pass;
    $num    = isset($_POST['num']) ? intval($_POST['num']) : 0;
    $uid    = isset($_POST['uid']) ? (string)$_POST['uid'] : '';
    $uid    = isset($_POST['uid']) ? (string)$_POST['uid'] : '';
    $subject= isset($_POST['subject']) ? (string)$_POST['subject'] : '';
    $disp_idx = isset($_POST['idx']) ? intval($_POST['idx']) : $num;

    $apilist = api_list_load();
    $active_api = api_get_active($apilist);

    if ($server && $email && $pass) {
        $imap = imap_connect($server, $email, $pass);
        if ($imap) {
            if ($num <= 0 && $uid !== '') { $num = @imap_msgno($imap, (int)$uid); }
            if ($num <= 0) { $error = '선택된 메일이 없습니다. (번호 변환 실패)'; $popup = "<div style='color:#991b1b'><b>오류</b><br>".h($error)."</div>"; imap_close($imap);  }
            list($k, $content) = imap_fetch_best($imap, $num);
            $plain_for_llm = ($k==='html') ? strip_tags($content) : $content;
            list($ok, $res) = analyze_and_save($plain_for_llm, $active_api, $uid, $subject);
            if ($ok) {
                $srcdoc = htmlspecialchars($res['preview'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $popup = "<div><b>저장 완료</b> (메일 #".h((string)$disp_idx).")<br><code>".h($res['path'])."</code></div>";
                if (!empty($res['log'])) {
                    $rel = str_replace(SAVE_BASE, '', $res['log']);
                    $popup .= "<div class='small'>로그: <a href='".h($rel)."' target='_blank'>다운로드</a></div>";
                }
                $popup .= "<hr><iframe style='width:100%;height:360px;border:0;background:#fff' srcdoc='{$srcdoc}'></iframe>";
            } else { $error = $res; $popup = "<div style='color:#991b1b'><b>오류</b><br>".h($error)."</div>"; }
            imap_close($imap);
        } else { $error = "IMAP 접속 실패. 서버/계정/비밀번호를 확인하세요."; $popup = "<div style='color:#991b1b'><b>오류</b><br>".h($error)."</div>"; }
    } else { $error = "필수 항목 누락(서버/이메일/비밀번호/메일번호)."; $popup = "<div style='color:#991b1b'><b>오류</b><br>".h($error)."</div>"; }
}

// 삭제 전 미리보기
if ($action === 'delete_preview' && $_SERVER['REQUEST_METHOD']==='POST') {
    $nums = isset($_POST['nums']) && is_array($_POST['nums']) ? array_map('intval', $_POST['nums']) : [];
    $server = $_POST['server'] ?? '';
    $email = $_POST['email'] ?? '';
    $pass = $_POST['pass'] ?? '';
    if (!$nums) { $popup = "<div style='color:#991b1b'><b>오류</b><br>선택된 메일이 없습니다.</div>"; }
    else {
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
        $hidden .= "<input type='hidden' name='server' value='".h($server)."'>";
        $hidden .= "<input type='hidden' name='email' value='".h($email)."'>";
        $hidden .= "<input type='hidden' name='pass' value='".h($pass)."'>";
        $popup = <<<HTML
        <h4>삭제 미리보기</h4>
        <div class="small">아래 메일을 삭제하시겠습니까? 이 작업은 되돌릴 수 없습니다.</div>
        <div style="max-height:300px;overflow:auto;background:#fff">
          <table style="width:100%;border-collapse:collapse">
            <thead><tr><th style="width:80px">No</th><th>제목</th><th>보낸사람</th><th style="width:200px">날짜</th></tr></thead>
            <tbody>{$rows}</tbody>
          </table>
        </div>
        <form method="post" action="?action=delete" style="margin-top:8px;display:flex;gap:8px;justify-content:flex-end" class="no-anim">
          {$hidden}
          <button class="btn" type="submit">확인 후 삭제</button>
          <button class="btn" type="button" onclick="__hideLoading(); document.getElementById('popup').style.display='none'">취소</button>
        </form>
HTML;
    }
}
if ($action === 'delete' && $_SERVER['REQUEST_METHOD']==='POST') {
    $server = $_POST['server'] ?? '';
    $email  = $_POST['email'] ?? '';
    $pass   = $_POST['pass'] ?? '';
    $nums   = isset($_POST['nums']) && is_array($_POST['nums']) ? $_POST['nums'] : [];
    if ($server && $email && $pass && $nums) {
        $imap = imap_connect($server, $email, $pass);
        if ($imap) {
            if ($num <= 0 && $uid !== '') { $num = @imap_msgno($imap, (int)$uid); }
            $deleted = imap_delete_nums($imap, $nums);
            imap_close($imap);
            if ($deleted) { $popup = "<div><b>삭제 완료</b> (" . count($deleted) . "개) : " . h(implode(', ', $deleted)) . "</div>"; }
            else { $popup = "<div style='color:#991b1b'><b>오류</b><br>삭제할 항목이 없거나 실패했습니다.</div>"; }
        } else { $popup = "<div style='color:#991b1b'><b>오류</b><br>IMAP 접속 실패</div>"; }
    } else { $popup = "<div style='color:#991b1b'><b>오류</b><br>선택된 메일이 없습니다.</div>"; }
}

// --- Render ---
$token_env = $active_api['auth_env'] ?? '';
$token_val = $token_env ? get_env_value($token_env) : '';
$key_status = $token_val ? "<b style='color:#065f46'>감지됨</b>" : "<b style='color:#991b1b'>미검출</b>";

$emails_page = [];
$total = 0;
if ($server && $email && $pass) {
    $imap = imap_connect($server, $email, $pass);
    if ($imap) {
        $res = imap_list_page($imap, $limit, $page);
        $emails_page = $res['items'];
        $total = $res['total'];
        imap_close($imap);
    } else if (!$error) { $error = "IMAP 접속 실패. 서버/계정/비밀번호를 확인하세요."; }
}
$total_pages = max(1, (int)ceil($total / max(1,$limit)));
$has_prev = $page > 1;
$has_next = $page < $total_pages;
?><?php if (isset($_GET['debug'])) { ini_set('display_errors','1'); error_reporting(E_ALL); } ?>
<!doctype html>
<meta charset="utf-8">
<title>Gmail IMAP → LLM 센싱 자동화 (v13)</title>
<style>
body{font-family:system-ui,Segoe UI,Arial,sans-serif;margin:0;color:#111}
header{background:#111;color:#fff;padding:10px 16px;display:flex;justify-content:space-between;align-items:center;gap:12px}
main{padding:16px;max-width:1200px;margin:0 auto}
input,button,select,textarea{font:inherit;padding:6px 10px}
table{border-collapse:collapse;width:100%}
th,td{border-bottom:1px solid #ececec;padding:10px;vertical-align:top}
tbody tr:hover{background:#f8fafc}
tbody tr{transition:background .12s ease}
.btn{background:linear-gradient(135deg,#1f2937,#111);color:#fff;border:1px solid #111;border-radius:12px;padding:8px 12px;text-decoration:none;display:inline-block;cursor:pointer;box-shadow:0 4px 10px rgba(0,0,0,.08)}
.btn:hover{filter:brightness(1.08)}
select, input[type=password], input[type=text]{border:1px solid #e5e7eb;border-radius:10px}
.card{border:1px solid #e5e7eb;border-radius:12px;padding:12px;margin:8px 0;background:#fff}
.small{font-size:.9rem;color:#6b7280}
.err{color:#991b1b;background:#fef2f2;border:1px solid #fecaca;padding:8px 10px;border-radius:8px}
#popup{display:none;position:fixed;top:12px;right:12px;width:640px;max-height:640px;overflow:auto;
  background:#f8fafc;border:1px solid #1f2937;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,.15);z-index:9999;
  resize: both; min-width:360px; min-height:240px; max-width:90vw; max-height:90vh;}
#popup header{background:#1f2937;color:#fff;border-radius:12px 12px 0 0;padding:8px 12px;cursor:move}
#popup .content{padding:10px}
#popup .close{float:right;background:transparent;border:0;color:#fff;font-size:16px;cursor:pointer}
.header-right{display:flex;align-items:center;gap:8px}
.pill{background:#111;color:#fff;border:1px solid #111;border-radius:16px;padding:6px 10px;display:inline-flex;align-items:center;gap:6px;box-shadow:0 4px 10px rgba(0,0,0,.08)}
.pager{display:flex;gap:8px;align-items:center;justify-content:flex-end;margin-top:8px}
.pager .btn[disabled]{opacity:.4;cursor:not-allowed}
#loading{position:fixed;inset:0;background:rgba(255,255,255,.6);backdrop-filter:saturate(180%) blur(2px);display:none;z-index:99999}
#loading .box{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:#111;color:#fff;padding:12px 16px;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,.25)}
#spinner{width:22px;height:22px;border:3px solid #fff;border-top-color:transparent;border-radius:50%;display:inline-block;animation:spin 0.8s linear infinite;vertical-align:-4px;margin-right:8px}
@keyframes spin{to{transform:rotate(360deg)}}
</style>

<header>
  <div>
    <strong>Gmail IMAP → LLM 센싱 자동화 (v13)</strong>
    <div class="small">
      토큰: <?=$key_status?>
      &nbsp;|&nbsp; IMAP 서버: <?=h($server ?: $env_server)?> &nbsp;|&nbsp; IMAP 이메일: <?=h($email ?: $env_email)?>
    </div>
  </div>
  <div class="header-right">
    <form method="get" style="display:flex;align-items:center;gap:6px" class="no-anim">
      <input type="hidden" name="server" value="<?=h($server)?>">
      <input type="hidden" name="email" value="<?=h($email)?>">
      <input type="hidden" name="pass" value="<?=h($pass)?>">
      <input type="hidden" name="limit" value="<?=$limit?>">
      <input type="hidden" name="page" value="<?=$page?>">
      <label class="small">API</label>
      <span class="pill">
        <select name="api_id" id="apiSelect">
          <?php foreach (($apilist['items'] ?? []) as $it): $id=$it['id']; ?>
            <option value="<?=$id?>" <?=$api_id===$id?'selected':''?>><?=h($it['label'] ?? $id)?></option>
          <?php endforeach; ?>
        </select>
      </span>
    </form>
    <a class="btn" href="?action=api_manage&<?=http_build_query(['server'=>$server,'email'=>$email,'pass'=>$pass,'limit'=>$limit,'page'=>$page,'api_id'=>$api_id])?>">⚙️ LLM API 관리</a>
    <a class="btn" href="?action=prompt&<?=http_build_query(['server'=>$server,'email'=>$email,'pass'=>$pass,'limit'=>$limit,'page'=>$page,'api_id'=>$api_id])?>">📝 프롬프트 열람</a>
  </div>
</header>

<main>
  <?php if (!empty($header_err)): ?><div class="err"><?=h($header_err)?></div><?php endif; ?>
  <?php if ($error): ?><div class="err"><?=h($error)?></div><?php endif; ?>

  <div class="card">
    <form method="get" class="no-anim">
      <div style="display:flex;gap:12px;flex-wrap:wrap">
        <input type="hidden" name="api_id" value="<?=h($apilist['active'] ?? '')?>">
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
          <select name="limit" onchange="this.form.page.value=1; this.form.submit()">
            <?php foreach([10,20,30,40,50] as $n): ?>
              <option value="<?=$n?>" <?=$limit==$n?'selected':''?>><?=$n?></option>
            <?php endforeach; ?>
          </select>
          <input type="hidden" name="page" value="<?=$page?>">
        </div>
        <div style="align-self:flex-end">
          <input type="hidden" name="page" value="1">
          <button class="btn" type="submit">연결/새로고침</button>
        </div>
      </div>
    </form>
  </div>

  <?php if ($emails_page): ?>
    <form method="post" action="?action=delete_preview" class="card">
      <h3>메일 목록 (<?=$page?> / <?=$total_pages?> 페이지 · 총 <?=$total?>개)</h3>
      <div style="margin-bottom:8px;display:flex;gap:8px;align-items:center">
        <button class="btn" type="submit">선택 삭제</button>
        <span class="small">* 삭제 전 미리보기 창이 열립니다.</span>
      </div>
      <input type="hidden" name="server" value="<?=h($server)?>">
      <input type="hidden" name="email" value="<?=h($email)?>">
      <input type="hidden" name="pass" value="<?=h($pass)?>">
      <table>
        <thead><tr><th style="width:40px"><input type="checkbox" onclick="document.querySelectorAll('.chk').forEach(c=>c.checked=this.checked)"></th><th style="width:60px">No</th><th>제목</th><th>보낸사람</th><th style="width:180px">날짜</th><th style="width:280px">동작</th></tr></thead>
        <tbody>
        <?php foreach ($emails_page as $m): ?>
          <tr>
            <td><input type="checkbox" class="chk" name="nums[]" value="<?=$m['num']?>"></td>
            <td class="small">#<?=h((string)$m['idx'])?></td>
            <td><?=h($m['subject'])?></td>
            <td><?=h($m['from'])?></td>
            <td class="small"><?=h($m['date'])?></td>
            <td>
              <a class="btn" href="?<?=http_build_query(['server'=>$server,'email'=>$email,'pass'=>$pass,'limit'=>$limit,'page'=>$page,'api_id'=>$api_id,'action'=>'view','num'=>$m['num'],'idx'=>$m['idx']])?>">보기</a>
              <form method="post" action="?action=analyze" style="display:inline" class="no-anim">
                <input type="hidden" name="server" value="<?=h($server)?>">
                <input type="hidden" name="email"  value="<?=h($email)?>">
                <input type="hidden" name="pass"   value="<?=h($pass)?>">
                <input type="hidden" name="num"    value="<?=$m['num']?>">
                <input type="hidden" name="uid"    value="<?=$m['uid']?>">
                <input type="hidden" name="subject" value="<?=h($m['subject'])?>">
                <input type="hidden" name="idx" value="<?=$m['idx']?>">
                <button class="btn" type="submit">LLM 분석</button>
              </form>
              <input type="hidden" name="subjects[<?=$m['num']?>]" value="<?=h($m['subject'])?>">
              <input type="hidden" name="froms[<?=$m['num']?>]" value="<?=h($m['from'])?>">
              <input type="hidden" name="dates[<?=$m['num']?>]" value="<?=h($m['date'])?>">
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>

      <div class="pager">
        <?php $has_prev = $page > 1; $has_next = $page < $total_pages; ?>
        <form method="get" style="display:inline" class="no-anim">
          <input type="hidden" name="server" value="<?=h($server)?>">
          <input type="hidden" name="email" value="<?=h($email)?>">
          <input type="hidden" name="pass" value="<?=h($pass)?>">
          <input type="hidden" name="limit" value="<?=$limit?>">
          <input type="hidden" name="api_id" value="<?=h($api_id)?>">
          <input type="hidden" name="page" value="1">
          <button class="btn" type="submit" <?=$has_prev?'':'disabled'?>>« 처음</button>
        </form>
        <form method="get" style="display:inline" class="no-anim">
          <input type="hidden" name="server" value="<?=h($server)?>">
          <input type="hidden" name="email" value="<?=h($email)?>">
          <input type="hidden" name="pass" value="<?=h($pass)?>">
          <input type="hidden" name="limit" value="<?=$limit?>">
          <input type="hidden" name="api_id" value="<?=h($api_id)?>">
          <input type="hidden" name="page" value="<?=$page-1?>">
          <button class="btn" type="submit" <?=$has_prev?'':'disabled'?>>‹ 이전</button>
        </form>
        <?php
          $win = 2;
          $start = max(1, $page - $win);
          $end = min($total_pages, $page + $win);
          if ($start > 1) { echo '<span class="small">…</span>'; }
          for ($p=$start; $p <= $end; $p++) {
              echo '<form method="get" style="display:inline" class="no-anim">';
              echo '<input type="hidden" name="server" value="'.h($server).'">';
              echo '<input type="hidden" name="email" value="'.h($email).'">';
              echo '<input type="hidden" name="pass" value="'.h($pass).'">';
              echo '<input type="hidden" name="limit" value="'.$limit.'">';
              echo '<input type="hidden" name="api_id" value="'.h($api_id).'">';
              echo '<input type="hidden" name="page" value="'.$p.'">';
              $dis = $p===$page ? 'disabled' : '';
              echo '<button class="btn" type="submit" '.$dis.'>'.$p.'</button>';
              echo '</form>';
          }
          if ($end < $total_pages) { echo '<span class="small">…</span>'; }
        ?>
        <form method="get" style="display:inline" class="no-anim">
          <input type="hidden" name="server" value="<?=h($server)?>">
          <input type="hidden" name="email" value="<?=h($email)?>">
          <input type="hidden" name="pass" value="<?=h($pass)?>">
          <input type="hidden" name="limit" value="<?=$limit?>">
          <input type="hidden" name="api_id" value="<?=h($api_id)?>">
          <input type="hidden" name="page" value="<?=$page+1?>">
          <button class="btn" type="submit" <?=$has_next?'':'disabled'?>>다음 ›</button>
        </form>
        <form method="get" style="display:inline" class="no-anim">
          <input type="hidden" name="server" value="<?=h($server)?>">
          <input type="hidden" name="email" value="<?=h($email)?>">
          <input type="hidden" name="pass" value="<?=h($pass)?>">
          <input type="hidden" name="limit" value="<?=$limit?>">
          <input type="hidden" name="api_id" value="<?=h($api_id)?>">
          <input type="hidden" name="page" value="<?=$total_pages?>">
          <button class="btn" type="submit" <?=$page<$total_pages?'':'disabled'?>>끝 »</button>
        </form>
      </div>
    </form>
  <?php endif; ?>
</main>

<div id="popup">
  <header id="popupHeader">
    <span>결과</span>
    <button class="close" onclick="__hideLoading(); document.getElementById('popup').style.display='none'">×</button>
  </header>
  <div class="content" id="popupContent"></div>
</div>

<div id="loading"><div class="box"><span id="spinner"></span><span>실행중...</span></div></div>

<script>
function showPopup(html){
  var box = document.getElementById('popup');
  var body = document.getElementById('popupContent');
  body.innerHTML = html;
  box.style.display = 'block';
}
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

// Auto-apply API selection + Loading indicator
var __loadTimer=null, __loadStart=0;
function __showLoading(){
  var el=document.getElementById('loading'); if(!el) return;
  el.style.display='block';
  __loadStart=Date.now();
  var box=el.querySelector('.box'); if(box){ var span=box.querySelector('span:last-child'); if(span){ span.textContent='실행중... 0s'; } }
  if(__loadTimer) clearInterval(__loadTimer);
  __loadTimer=setInterval(function(){ var el=document.getElementById('loading'); if(!el||el.style.display==='none'){clearInterval(__loadTimer);__loadTimer=null;return;} var box=el.querySelector('.box'); var secs=Math.floor((Date.now()-__loadStart)/1000); if(box){ var span=box.querySelector('span:last-child'); if(span){ span.textContent='실행중... '+secs+'s'; } } },1000);
}
function __hideLoading(){ var el=document.getElementById('loading'); if(el) el.style.display='none'; if(__loadTimer){clearInterval(__loadTimer); __loadTimer=null;} }
(function(){
  const sel = document.getElementById('apiSelect');
  if(sel){
    sel.addEventListener('change', function(){
      const form = sel.closest('form');
      if(form){ __showLoading(); form.submit(); }
    });
  }
  function showLoading(){ var el = document.getElementById('loading'); if(el) el.style.display='block'; }
  // Forms submit except those tagged .no-anim? We'll still show loading, but allow opt-out when needed.
  document.querySelectorAll('form').forEach(f=>{
    f.addEventListener('submit', __showLoading);
  });
  // Action links
  document.querySelectorAll('a.btn').forEach(a=>{
    a.addEventListener('click', __showLoading);
  });
})();

<?php if (!empty($popup)): ?>
  showPopup(<?=json_encode($popup)?>);
<?php endif; ?>
</script>

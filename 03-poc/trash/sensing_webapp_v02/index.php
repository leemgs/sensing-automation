<?php
declare(strict_types=1);
require_once __DIR__.'/config.php';
require_once __DIR__.'/utils.php';
require_once __DIR__.'/imap_client.php';
require_once __DIR__.'/analyzer.php';

// 최소 보안: 화이트리스트 (실서비스 전 서버 도메인 추가 권장)
$ALLOWED_HOSTS = ['localhost','127.0.0.1'];
$host_hdr = $_SERVER['HTTP_HOST'] ?? 'localhost';
// if (!in_array($host_hdr, $ALLOWED_HOSTS, true)) { http_response_code(403); exit('Forbidden'); }

$action = $_GET['action'] ?? '';
$notice = '';
$error  = '';

if ($action === 'analyze' && $_SERVER['REQUEST_METHOD']==='POST') {
    $server = $_POST['server'] ?? '';
    $email  = $_POST['email'] ?? '';
    $pass   = $_POST['pass'] ?? '';
    $num    = isset($_POST['num']) ? intval($_POST['num']) : 0;

    if ($server && $email && $pass && $num > 0) {
        $imap = imap_connect($server, $email, $pass);
        if ($imap) {
            $body = imap_fetch_text($imap, $num);
            list($ok, $data) = analyze_and_save($body);
            if ($ok) {
                $notice = "저장 완료: ".h($data['path']);
            } else {
                $error = $data;
            }
            imap_close($imap);
        } else {
            $error = "IMAP 접속 실패. 서버/계정/비밀번호를 확인하세요.";
        }
    } else {
        $error = "필수 항목 누락(서버/이메일/비밀번호/메일번호).";
    }
}

$server = $_REQUEST['server'] ?? '';
$email  = $_REQUEST['email'] ?? '';
$pass   = $_REQUEST['pass'] ?? '';
$view   = isset($_GET['view']) ? intval($_GET['view']) : 0;

$emails = [];
$preview = '';
if ($server && $email && $pass) {
    $imap = imap_connect($server, $email, $pass);
    if ($imap) {
        $emails = imap_list_latest($imap, 50);
        if ($view > 0) $preview = imap_fetch_text($imap, $view);
        imap_close($imap);
    } else {
        $error = $error ?: "IMAP 접속 실패. 서버/계정/비밀번호를 확인하세요.";
    }
}
?><!doctype html>
<meta charset="utf-8">
<title>Gmail IMAP → LLM 센싱 자동화 (v02)</title>
<style>
body{font-family:system-ui,Segoe UI,Arial,sans-serif;margin:0;color:#111}
header{background:#111;color:#fff;padding:12px 16px}
main{padding:16px;max-width:1200px;margin:0 auto}
input,button{font:inherit;padding:6px 10px}
table{border-collapse:collapse;width:100%}
th,td{border-bottom:1px solid #ececec;padding:8px;vertical-align:top}
.btn{background:#111;color:#fff;border:none;border-radius:10px;padding:6px 10px;text-decoration:none;display:inline-block}
.btn:hover{opacity:.9}
.card{border:1px solid #e5e7eb;border-radius:12px;padding:12px;margin:8px 0}
.small{font-size:.9rem;color:#6b7280}
.notice{color:#065f46;background:#ecfdf5;border:1px solid #a7f3d0;padding:8px 10px;border-radius:8px}
.err{color:#991b1b;background:#fef2f2;border:1px solid #fecaca;padding:8px 10px;border-radius:8px}
pre{white-space:pre-wrap}
</style>
<header>
  <div><strong>Gmail IMAP → OpenRouter LLM 센싱 자동화 (v02)</strong></div>
</header>
<main>
  <?php if ($notice): ?><div class="notice"><?=$notice?></div><?php endif; ?>
  <?php if ($error): ?><div class="err"><?=h($error)?></div><?php endif; ?>

  <div class="card">
    <form method="get" class="row">
      <div>
        <label>IMAP 서버 (예: imap.gmail.com)</label><br>
        <input name="server" value="<?=h($server)?>" size="28" required>
      </div>
      <div>
        <label>이메일</label><br>
        <input name="email" value="<?=h($email)?>" size="28" required>
      </div>
      <div>
        <label>비밀번호/앱 비밀번호</label><br>
        <input name="pass" value="<?=h($pass)?>" size="18" type="password" required>
      </div>
      <div style="margin-top:20px">
        <button class="btn" type="submit">연결</button>
      </div>
    </form>
    <div class="small" style="margin-top:8px">* 2단계 인증 사용 시 <b>앱 비밀번호</b> 필요</div>
  </div>

  <?php if ($emails): ?>
    <div class="card">
      <h3>메일 목록</h3>
      <table>
        <thead><tr><th>제목</th><th>보낸사람</th><th>날짜</th><th>동작</th></tr></thead>
        <tbody>
        <?php foreach ($emails as $m): ?>
          <tr>
            <td><?=h($m['subject'])?></td>
            <td><?=h($m['from'])?></td>
            <td class="small"><?=h($m['date'])?></td>
            <td>
              <a class="btn" href="?server=<?=urlencode($server)?>&email=<?=urlencode($email)?>&pass=<?=urlencode($pass)?>&view=<?=$m['num']?>">보기</a>
              <form method="post" action="?action=analyze" style="display:inline">
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

  <?php if ($view>0 && $preview): ?>
    <div class="card">
      <h3>선택 메일 본문 (번호: <?=$view?>)</h3>
      <pre><?=h($preview)?></pre>
    </div>
  <?php endif; ?>

  <div class="card small">
    <details><summary><strong>연동 점검</strong></summary>
      <ul>
        <li>OpenRouter 키: <?= get_openrouter_key()? "<b style='color:#065f46'>감지됨</b>":"<b style='color:#991b1b'>미검출</b>" ?></li>
        <li>저장 경로: <code><?=h(SAVE_BASE)?></code> (없으면 자동 생성)</li>
        <li>현재 시각: <?=h(date('Y-m-d H:i'))?></li>
      </ul>
    </details>
  </div>
</main>

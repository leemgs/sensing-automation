<?php
mb_internal_encoding('UTF-8');
$cfg = require __DIR__ . '/config.php';

if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool {
        return $needle === '' || strpos($haystack, $needle) === 0;
    }
}

header('Content-Type: application/json; charset=UTF-8');

$input  = $_POST ?: json_decode(file_get_contents('php://input'), true);
$token  = $input['token']  ?? '';
$action = $input['action'] ?? '';
$rel    = $input['rel']    ?? '';

if (!$token || $token !== ($cfg['admin_token'] ?? '')) {
  http_response_code(403);
  echo json_encode(['ok'=>false, 'error'=>'권한 없음 (토큰 불일치)'], JSON_UNESCAPED_UNICODE);
  exit;
}
if (!$action || !$rel) {
  http_response_code(400);
  echo json_encode(['ok'=>false, 'error'=>'action/rel 필요'], JSON_UNESCAPED_UNICODE);
  exit;
}

$roots = [
  realpath($cfg['lawsuit_dir'] ?? (__DIR__.'/소송')),
  realpath($cfg['contract_dir'] ?? (__DIR__.'/계약')),
  realpath($cfg['governance_dir'] ?? (__DIR__.'/거버넌스')),
];
$archiveRoot = realpath($cfg['archive_dir'] ?? (__DIR__.'/보관'));

$targetAbs = realpath(__DIR__ . '/' . ltrim($rel, '/'));
if (!$targetAbs || !preg_match('/\.html?$/i', $targetAbs)) {
  http_response_code(400);
  echo json_encode(['ok'=>false, 'error'=>'잘못된 파일 경로'], JSON_UNESCAPED_UNICODE);
  exit;
}
$allowed = false;
foreach ($roots as $r) {
  if ($r && str_starts_with($targetAbs, $r)) { $allowed = true; break; }
}
if (!$allowed && !($archiveRoot && str_starts_with($targetAbs, $archiveRoot))) {
  http_response_code(403);
  echo json_encode(['ok'=>false, 'error'=>'허용되지 않은 경로'], JSON_UNESCAPED_UNICODE);
  exit;
}

function audit_log($cfg, $action, $rel, $status, $detail='') {
  $line = [
    date('c'), $action, $rel, $status, $_SERVER['REMOTE_ADDR'] ?? '', $detail,
  ];
  @file_put_contents($cfg['audit_log'], implode(',', array_map(fn($v)=>str_replace(',', ';', $v), $line))."\n", FILE_APPEND);
}

try {
  if ($action === 'archive') {
    if (!$archiveRoot || !is_dir($archiveRoot)) throw new RuntimeException('보관 폴더가 없습니다.');
    $cat = '기타';
    foreach (['소송','계약','거버넌스'] as $c) {
      if (str_contains($targetAbs, DIRECTORY_SEPARATOR.$c.DIRECTORY_SEPARATOR)) { $cat = $c; break; }
    }
    $dstDir = $archiveRoot . DIRECTORY_SEPARATOR . $cat;
    if (!is_dir($dstDir)) { @mkdir($dstDir, 0775, true); }
    $dst = $dstDir . DIRECTORY_SEPARATOR . basename($targetAbs);
    if (!@rename($targetAbs, $dst)) throw new RuntimeException('보관 이동 실패');
    audit_log($cfg, 'archive', $rel, 'OK');
    echo json_encode(['ok'=>true, 'message'=>'보관 완료']);

  } elseif ($action === 'delete') {
    if (!@unlink($targetAbs)) throw new RuntimeException('삭제 실패');
    audit_log($cfg, 'delete', $rel, 'OK');
    echo json_encode(['ok'=>true, 'message'=>'삭제 완료']);

  } elseif ($action === 'restore') {
    if (!$archiveRoot || !is_dir($archiveRoot)) throw new RuntimeException('보관 폴더가 없습니다.');
    if (!str_starts_with($targetAbs, $archiveRoot)) throw new RuntimeException('보관 폴더 내 파일만 복원 가능');

    $cat = '소송';
    foreach (['소송','계약','거버넌스'] as $c) {
      if (str_contains($targetAbs, DIRECTORY_SEPARATOR.$c.DIRECTORY_SEPARATOR)) { $cat = $c; break; }
    }
    $dstRoot = [
      '소송' => realpath($cfg['lawsuit_dir'] ?? (__DIR__.'/소송')),
      '계약' => realpath($cfg['contract_dir'] ?? (__DIR__.'/계약')),
      '거버넌스' => realpath($cfg['governance_dir'] ?? (__DIR__.'/거버넌스')),
    ][$cat] ?? null;
    if (!$dstRoot || !is_dir($dstRoot)) throw new RuntimeException('대상 카테고리 폴더가 없습니다.');

    $dst = $dstRoot . DIRECTORY_SEPARATOR . basename($targetAbs);
    if (!@rename($targetAbs, $dst)) throw new RuntimeException('복원 이동 실패');
    audit_log($cfg, 'restore', $rel, 'OK');
    echo json_encode(['ok'=>true, 'message'=>'복원 완료']);

  } else {
    throw new RuntimeException('지원하지 않는 action');
  }
} catch (Throwable $e) {
  audit_log($cfg, $action, $rel, 'ERR', $e->getMessage());
  http_response_code(500);
  echo json_encode(['ok'=>false, 'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}

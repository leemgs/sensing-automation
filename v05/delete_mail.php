<?php
require __DIR__ . '/config.php';
require __DIR__ . '/mail_client.php';
require __DIR__ . '/db.php';

header('Content-Type: application/json; charset=UTF-8');

$cfg = require __DIR__ . '/config.php';
$pdo = db();

$uid = $_POST['uid'] ?? $_GET['uid'] ?? '';
if ($uid === '') { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'uid 필요']); exit; }

try {
    $imap = open_mailbox($cfg);
    imap_delete($imap, $uid, FT_UID);
    imap_expunge($imap);
    imap_close($imap);

    mark_deleted_db($pdo, (string)$uid);

    echo json_encode(['ok'=>true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}

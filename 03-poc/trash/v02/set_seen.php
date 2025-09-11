<?php
require __DIR__ . '/config.php';
require __DIR__ . '/imap_client.php';
require __DIR__ . '/db.php';

header('Content-Type: application/json; charset=UTF-8');

$cfg = require __DIR__ . '/config.php';
$pdo = db();

$uid  = $_POST['uid'] ?? $_GET['uid'] ?? '';
$seen = isset($_POST['seen']) ? (int)$_POST['seen'] : (isset($_GET['seen']) ? (int)$_GET['seen'] : 1);

if ($uid === '') { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'uid 필요']); exit; }

try {
    $imap = open_mailbox($cfg);
    if ($seen) {
        imap_setflag_full($imap, $uid, "\\Seen", ST_UID);
    } else {
        imap_clearflag_full($imap, $uid, "\\Seen", ST_UID);
    }
    imap_close($imap);

    mark_seen_db($pdo, (string)$uid, (bool)$seen);

    echo json_encode(['ok'=>true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}

<?php
function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $cfg = require __DIR__ . '/config.php';
    $pdo = new PDO($cfg['pdo_dsn'], $cfg['pdo_user'], $cfg['pdo_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    init_schema($pdo);
    return $pdo;
}

function init_schema(PDO $pdo) {
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $pdo->exec("
        CREATE TABLE IF NOT EXISTS messages (
          uid TEXT PRIMARY KEY,
          subject TEXT,
          from_addr TEXT,
          date_utc TEXT,
          seen INTEGER DEFAULT 0,
          labels TEXT,
          snippet TEXT,
          deleted INTEGER DEFAULT 0,
          lawsuit_saved INTEGER DEFAULT 0,
          contract_saved INTEGER DEFAULT 0,
          governance_saved INTEGER DEFAULT 0,
          updated_at TEXT
        );
        ");
    } else {
        $pdo->exec("
        CREATE TABLE IF NOT EXISTS messages (
          uid VARCHAR(64) PRIMARY KEY,
          subject TEXT,
          from_addr TEXT,
          date_utc VARCHAR(40),
          seen TINYINT DEFAULT 0,
          labels TEXT,
          snippet TEXT,
          deleted TINYINT DEFAULT 0,
          lawsuit_saved TINYINT DEFAULT 0,
          contract_saved TINYINT DEFAULT 0,
          governance_saved TINYINT DEFAULT 0,
          updated_at VARCHAR(40)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    }
}

function upsert_message(PDO $pdo, array $m) {
    $m['updated_at'] = gmdate('c');
    $m += ['lawsuit_saved'=>0,'contract_saved'=>0,'governance_saved'=>0];
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $sql = "INSERT INTO messages
          (uid,subject,from_addr,date_utc,seen,labels,snippet,deleted,lawsuit_saved,contract_saved,governance_saved,updated_at)
          VALUES (:uid,:subject,:from_addr,:date_utc,:seen,:labels,:snippet,:deleted,:lawsuit_saved,:contract_saved,:governance_saved,:updated_at)
          ON CONFLICT(uid) DO UPDATE SET
            subject=excluded.subject,
            from_addr=excluded.from_addr,
            date_utc=excluded.date_utc,
            seen=excluded.seen,
            labels=excluded.labels,
            snippet=excluded.snippet,
            deleted=excluded.deleted,
            lawsuit_saved = CASE WHEN messages.lawsuit_saved=1 THEN 1 ELSE excluded.lawsuit_saved END,
            contract_saved = CASE WHEN messages.contract_saved=1 THEN 1 ELSE excluded.contract_saved END,
            governance_saved = CASE WHEN messages.governance_saved=1 THEN 1 ELSE excluded.governance_saved END,
            updated_at=excluded.updated_at";
    } else {
        $sql = "INSERT INTO messages
          (uid,subject,from_addr,date_utc,seen,labels,snippet,deleted,lawsuit_saved,contract_saved,governance_saved,updated_at)
          VALUES (:uid,:subject,:from_addr,:date_utc,:seen,:labels,:snippet,:deleted,:lawsuit_saved,:contract_saved,:governance_saved,:updated_at)
          ON DUPLICATE KEY UPDATE
            subject=VALUES(subject),
            from_addr=VALUES(from_addr),
            date_utc=VALUES(date_utc),
            seen=VALUES(seen),
            labels=VALUES(labels),
            snippet=VALUES(snippet),
            deleted=VALUES(deleted),
            lawsuit_saved = IF(messages.lawsuit_saved=1,1,VALUES(lawsuit_saved)),
            contract_saved = IF(messages.contract_saved=1,1,VALUES(contract_saved)),
            governance_saved = IF(messages.governance_saved=1,1,VALUES(governance_saved)),
            updated_at=VALUES(updated_at)";
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($m);
}

function mark_seen_db(PDO $pdo, string $uid, bool $seen) {
    $stmt = $pdo->prepare("UPDATE messages SET seen=?, updated_at=? WHERE uid=?");
    $stmt->execute([$seen?1:0, gmdate('c'), $uid]);
}
function mark_deleted_db(PDO $pdo, string $uid) {
    $stmt = $pdo->prepare("UPDATE messages SET deleted=1, updated_at=? WHERE uid=?");
    $stmt->execute([gmdate('c'), $uid]);
}
function mark_lawsuit_saved(PDO $pdo, string $uid) {
    $stmt = $pdo->prepare("UPDATE messages SET lawsuit_saved=1, updated_at=? WHERE uid=?");
    $stmt->execute([gmdate('c'), $uid]);
}
function mark_contract_saved(PDO $pdo, string $uid) {
    $stmt = $pdo->prepare("UPDATE messages SET contract_saved=1, updated_at=? WHERE uid=?");
    $stmt->execute([gmdate('c'), $uid]);
}
function mark_governance_saved(PDO $pdo, string $uid) {
    $stmt = $pdo->prepare("UPDATE messages SET governance_saved=1, updated_at=? WHERE uid=?");
    $stmt->execute([gmdate('c'), $uid]);
}

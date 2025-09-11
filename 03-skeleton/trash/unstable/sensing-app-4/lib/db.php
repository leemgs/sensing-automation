<?php
// lib/db.php — SQLite storage for results & jobs
declare(strict_types=1);

function db_path(): string {
    return __DIR__ . '/../sensing.db';
}

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = new PDO('sqlite:' . db_path(), null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL');
    return $pdo;
}

function db_init(): void {
    $pdo = db();
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS results (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    created_at TEXT NOT NULL,
    email_uid TEXT,
    subject TEXT,
    from_addr TEXT,
    url TEXT NOT NULL,
    url_hash TEXT NOT NULL,
    regulation_category TEXT,
    asset_category TEXT,
    regulation_path TEXT,
    asset_path TEXT,
    status TEXT NOT NULL,           -- success | failed
    error TEXT
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_results_urlhash ON results(url_hash);

CREATE TABLE IF NOT EXISTS failed_jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    created_at TEXT NOT NULL,
    url TEXT NOT NULL,
    url_hash TEXT NOT NULL,
    attempts INTEGER NOT NULL DEFAULT 0,
    last_error TEXT,
    next_try_at TEXT
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_failed_urlhash ON failed_jobs(url_hash);
SQL);
}

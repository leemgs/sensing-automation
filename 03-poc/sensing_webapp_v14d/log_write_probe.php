<?php
@ini_set('display_errors', '1');
@ini_set('display_startup_errors', '1');
@error_reporting(E_ALL);
header('Content-Type: text/plain; charset=UTF-8');

$CONFIG = require __DIR__ . '/config.php';
$logDir = $CONFIG['app']['log_dir'] ?? (__DIR__ . '/logs');
$file   = rtrim($logDir, '/').'/write_test.log';

echo "Log dir: $logDir\n";
if (!is_dir($logDir)) {
    echo "Creating log dir...\n";
    if (!@mkdir($logDir, 0775, true)) {
        echo "ERROR: mkdir failed. Check permissions (www-data).\n";
        exit(1);
    }
}

$data = date('c')." write test\n";
$ok = @file_put_contents($file, $data, FILE_APPEND);
if ($ok === false) {
    echo "ERROR: file_put_contents failed. Check directory ownership/permissions.\n";
    clearstatcache();
    $perms = @substr(sprintf('%o', fileperms($logDir)), -4);
    echo "Dir perms: $perms\n";
    exit(1);
}

echo "OK: wrote to $file\n";

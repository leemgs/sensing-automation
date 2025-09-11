<?php
/**
 * imap_check.php — optional IMAP connectivity test (requires php-imap)
 */
@ini_set('display_errors', '1');
@ini_set('display_startup_errors', '1');
@error_reporting(E_ALL);
header('Content-Type: text/plain; charset=UTF-8');

$CONFIG = require __DIR__ . '/config.php';

if (!function_exists('imap_open')) {
    echo "php-imap extension not available. Install with: apt install php-imap\n";
    exit(0);
}

$server = $CONFIG['imap']['server'] ?? ($CONFIG['IMAP_SERVER'] ?? '');
$email  = $CONFIG['imap']['email']  ?? ($CONFIG['IMAP_EMAIL'] ?? '');
$pass   = $CONFIG['imap']['password'] ?? ($CONFIG['IMAP_PASSWORD'] ?? '');

if (!$server || !$email || !$pass) {
    echo "IMAP config missing (IMAP_SERVER/IMAP_EMAIL/IMAP_PASSWORD)\n";
    exit(1);
}

$mboxStr = sprintf('{%s:993/imap/ssl}INBOX', $server);
$mbox = @imap_open($mboxStr, $email, $pass, 0, 1);
if ($mbox === false) {
    echo "imap_open failed: " . imap_last_error() . "\n";
    exit(1);
}
echo "IMAP connection OK.\n";
imap_close($mbox);

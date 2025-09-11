<?php
/*
 * healthcheck_min.php — ultra-minimal crash isolator
 * - Forces error display (even if php.ini hides it)
 * - No includes, no modern PHP 8-only helpers
 * - Plain echos only
 */
@ini_set('display_errors', '1');
@ini_set('display_startup_errors', '1');
@error_reporting(E_ALL);

header('Content-Type: text/plain; charset=UTF-8');

echo "OK: entered script\n";

echo "PHP_VERSION=" . PHP_VERSION . "\n";
echo "SAPI=" . PHP_SAPI . "\n";
echo "Loaded extensions count=" . count(get_loaded_extensions()) . "\n";

// basic function check
if (function_exists('curl_init')) {
    echo "curl: available\n";
} else {
    echo "curl: MISSING\n";
}

if (class_exists('PDO')) {
    echo "PDO: available\n";
} else {
    echo "PDO: MISSING\n";
}

// try read /etc/environment without any helper
$envPath = '/etc/environment';
echo "ENV readable? " . (is_readable($envPath) ? 'yes' : 'no') . "\n";
if (is_readable($envPath)) {
    $firstBytes = @file_get_contents($envPath, false, null, 0, 512);
    if ($firstBytes === false) {
        echo "ENV read: FAILED\n";
    } else {
        echo "ENV read: OK (" . strlen($firstBytes) . " bytes sample)\n";
    }
}

echo "DONE\n";

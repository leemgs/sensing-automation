<?php
@ini_set('display_errors', '1');
@ini_set('display_startup_errors', '1');
@error_reporting(E_ALL);
header('Content-Type: text/plain; charset=UTF-8');

function loadEnvFile_min($path) {
    if (!is_readable($path)) return [];
    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) return [];
    $env = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '=') === false) continue;
        list($key, $val) = explode('=', $line, 2);
        $key = trim($key);
        $val = trim($val);
        // strip surrounding single/double quotes
        if ($val !== '' && (($val[0] === '"' && substr($val,-1) === '"') || ($val[0] === "'" && substr($val,-1) === "'"))) {
            $val = substr($val, 1, -1);
        } else {
            $val = trim($val, " \t\n\r\0\x0B\"'");
        }
        // refuse multiline
        if (strpos($val, "\n") !== false || strpos($val, "\r") !== false) {
            continue;
        }
        $env[$key] = $val;
    }
    return $env;
}

$env = loadEnvFile_min('/etc/environment');

echo "Keys loaded: " . count($env) . "\n";
foreach ($env as $k => $v) {
    if (preg_match('/(KEY|PASS|TOKEN|SECRET|API|PWD)/i', $k)) {
        echo $k . " = (hidden)\n";
    } else {
        echo $k . " = " . $v . "\n";
    }
}

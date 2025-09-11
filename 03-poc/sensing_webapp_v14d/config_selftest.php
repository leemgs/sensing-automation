<?php
@ini_set('display_errors', '1');
@ini_set('display_startup_errors', '1');
@error_reporting(E_ALL);
header('Content-Type: text/plain; charset=UTF-8');

echo "STEP 1: requiring config.php ...\n";
$cfgPath = __DIR__ . '/config.php';
if (!is_readable($cfgPath)) {
    echo "ERROR: config.php not readable at: $cfgPath\n";
    exit(1);
}

$CONFIG = require $cfgPath;
if (!is_array($CONFIG)) {
    echo "ERROR: config.php did not return an array.\n";
    var_dump($CONFIG);
    exit(1);
}
echo "OK: config.php loaded.\n";

// Show essential presence
$checks = [
    'app.env'                   => $CONFIG['app']['env'] ?? '(missing)',
    'app.debug'                 => ($CONFIG['app']['debug'] ?? false) ? '1' : '0',
    'app.timezone'              => $CONFIG['app']['timezone'] ?? '(missing)',
    'openrouter.api_key.len'    => isset($CONFIG['openrouter']['api_key']) ? strlen($CONFIG['openrouter']['api_key']) : 0,
    'openrouter.model'          => $CONFIG['openrouter']['model'] ?? '(missing)',
    'openrouter.endpoint'       => $CONFIG['openrouter']['endpoint'] ?? '(missing)',
    'database.driver'           => $CONFIG['database']['driver'] ?? '(missing)',
    'database.host'             => $CONFIG['database']['host'] ?? '(missing)',
    'database.name'             => $CONFIG['database']['name'] ?? '(missing)',
];

foreach ($checks as $k => $v) {
    echo $k . ' = ' . $v . "\n";
}

echo "STEP 2: sanity checks passed.\n";
echo "DONE\n";

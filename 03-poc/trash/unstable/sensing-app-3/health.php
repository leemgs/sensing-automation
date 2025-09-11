<?php
header('Content-Type: text/plain; charset=UTF-8');
echo "Sensing App Health Check\n";
echo "Time (KST): " . date('Y-m-d H:i') . "\n";
echo "AAH_API_KEY: " . (getenv('AAH_API_KEY') ? "present\n" : "NOT found\n");
echo "IMAP ext: " . (extension_loaded('imap') ? "loaded\n" : "NOT loaded\n");
echo "cURL ext: " . (extension_loaded('curl') ? "loaded\n" : "NOT loaded\n");
echo "mbstring: " . (extension_loaded('mbstring') ? "loaded\n" : "NOT loaded\n");
?>

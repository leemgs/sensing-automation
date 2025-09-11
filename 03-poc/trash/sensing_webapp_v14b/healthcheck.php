<?php
if (isset($_GET['debug'])) { ini_set('display_errors','1'); error_reporting(E_ALL); }
echo "<pre>";
echo "PHP Version: ".phpversion()."\n";
echo "IMAP loaded: ".(function_exists('imap_open')?'yes':'no')."\n";
echo "Extensions: ".implode(', ', get_loaded_extensions())."\n";
echo "</pre>";

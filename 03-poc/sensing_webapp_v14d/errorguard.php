<?php
/**
 * errorguard.php — Drop-in error/exception handler to avoid blank HTTP 500 pages.
 * Usage (very first lines of your page):
 *   require __DIR__ . '/errorguard.php';
 *   $CONFIG = require __DIR__ . '/config.php';
 */
(function () {
    // Decide whether to show errors
    $show = (isset($_GET['debug']) && $_GET['debug'] === '1');
    if (!$show && isset($_SERVER['APP_DEBUG'])) {
        $show = $_SERVER['APP_DEBUG'] === '1';
    }
    @ini_set('display_errors', $show ? '1' : '0');
    @ini_set('display_startup_errors', $show ? '1' : '0');
    @error_reporting(E_ALL);

    set_exception_handler(function ($e) use ($show) {
        http_response_code(500);
        if ($show) {
            $msg = htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
            $trace = htmlspecialchars($e->getTraceAsString(), ENT_QUOTES, 'UTF-8');
            echo "<!doctype html><meta charset='utf-8'><style>
                    body{background:#0f172a;color:#e5e7eb;font-family:system-ui,Segoe UI,Roboto,'Noto Sans KR',sans-serif;margin:32px}
                    pre{background:#111827;border:1px solid #1f2937;border-radius:12px;padding:16px;white-space:pre-wrap;line-height:1.5}
                    h1{color:#93c5fd}
                  </style>
                  <h1>Unhandled Exception</h1>
                  <pre>$msg</pre>
                  <h2>Stack Trace</h2>
                  <pre>$trace</pre>";
        } else {
            // Minimal safe text for production; details should be in server logs
            echo "<!doctype html><meta charset='utf-8'><p>Internal Server Error</p>";
        }
        exit;
    });

    set_error_handler(function ($errno, $errstr, $errfile, $errline) use ($show) {
        // Convert errors to exceptions for unified handling
        throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
    });
})();
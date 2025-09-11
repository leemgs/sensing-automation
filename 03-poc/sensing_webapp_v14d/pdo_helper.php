<?php
/**
 * pdo_helper.php — Safe PDO connection helper using config.php
 * Usage:
 *   $CONFIG = require __DIR__ . '/config.php';
 *   require __DIR__ . '/pdo_helper.php';
 *   $pdo = pdo_connect_or_throw($CONFIG);
 *   // $pdo->query('SELECT 1');
 */

function pdo_connect_or_throw(array $CONFIG): PDO {
    if (!class_exists('PDO')) {
        throw new RuntimeException('PDO extension is not available.');
    }
    $db = $CONFIG['database'] ?? [];
    $driver  = $db['driver']  ?? 'mysql';
    $host    = $db['host']    ?? '127.0.0.1';
    $port    = (int)($db['port'] ?? 3306);
    $name    = $db['name']    ?? '';
    $user    = $db['user']    ?? '';
    $pass    = $db['pass']    ?? '';
    $charset = $db['charset'] ?? 'utf8mb4';

    switch ($driver) {
        case 'mysql':
        case 'mariadb':
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $name, $charset);
            break;
        case 'pgsql':
            $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $host, $port, $name);
            break;
        default:
            throw new InvalidArgumentException('Unsupported DB driver: ' . $driver);
    }

    $options = [];
    if (defined('PDO::ATTR_ERRMODE')) {
        $options[PDO::ATTR_ERRMODE] = PDO::ERRMODE_EXCEPTION;
    }
    if (defined('PDO::ATTR_DEFAULT_FETCH_MODE') && defined('PDO::FETCH_ASSOC')) {
        $options[PDO::ATTR_DEFAULT_FETCH_MODE] = PDO::FETCH_ASSOC;
    }
    if (defined('PDO::ATTR_EMULATE_PREPARES')) {
        $options[PDO::ATTR_EMULATE_PREPARES] = false;
    }

    try {
        $pdo = new PDO($dsn, $user, $pass, $options);
    } catch (Throwable $e) {
        throw new RuntimeException('DB connection failed: ' . $e->getMessage());
    }
    return $pdo;
}
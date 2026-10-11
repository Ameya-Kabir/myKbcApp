<?php
declare(strict_types=1);

/**
 * Returns a shared PDO connection (lazy, one per request).
 * Credentials come from config/config.local.php (git-ignored).
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $file = __DIR__ . '/config.local.php';
    if (!is_file($file)) {
        http_response_code(500);
        error_log('KBC: config/config.local.php is missing.');
        exit('Server configuration error.');
    }
    $c = require $file;

    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $c['db_host'], $c['db_name'], $c['db_charset'] ?? 'utf8mb4');
    try {
        $pdo = new PDO($dsn, $c['db_user'], $c['db_pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        error_log('KBC DB connection failed: ' . $e->getMessage());
        http_response_code(500);
        exit('Database connection error.');
    }
    return $pdo;
}

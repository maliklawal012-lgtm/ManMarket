<?php
declare(strict_types=1);

require_once __DIR__ . '/env.php';

function get_db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = 'mysql:host=' . env_required('DB_HOST') . ';dbname=' . env_required('DB_NAME') . ';charset=utf8mb4';
        $pdo = new PDO($dsn, env_required('DB_USER'), env('DB_PASS', ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    return $pdo;
}

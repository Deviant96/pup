<?php

require_once __DIR__ . '/load_env.php';

denyDirectAccess(__FILE__);
loadEnv();

$host = env('DB_HOST');
$db = env('DB_NAME');
$user = env('DB_USER');
$pass = env('DB_PASS');

try {
    $pdo = new PDO("mysql:host={$host};dbname={$db};charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    $debug = env('APP_DEBUG', '0') === '1';
    $message = $debug ? ('Database connection failed: ' . $e->getMessage()) : 'Database connection failed.';

    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    http_response_code(500);
    echo $message;
    exit(1);
}

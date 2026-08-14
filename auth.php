<?php

require_once __DIR__ . '/load_env.php';

denyDirectAccess(__FILE__);

function startAuthSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => $secure,
    ]);

    session_start();
}

function isAuthenticated(): bool
{
    startAuthSession();
    return !empty($_SESSION['authenticated']);
}

function loginRedirectTarget(?string $next): string
{
    if ($next === null || $next === '') {
        return 'index.php';
    }

    if (!preg_match('#^[a-zA-Z0-9._/-]+\.php(?:\?.*)?$#', $next)) {
        return 'index.php';
    }

    if (str_contains($next, '..')) {
        return 'index.php';
    }

    return $next;
}

function wantsJsonResponse(): bool
{
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $requestedWith = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';

    return str_contains($accept, 'application/json')
        || strcasecmp($requestedWith, 'XMLHttpRequest') === 0;
}

function requireAuth(): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }

    try {
        loadEnv();
    } catch (RuntimeException $e) {
        http_response_code(500);
        echo 'Missing .env. Copy .env.example to .env and fill in the values.';
        exit;
    }

    startAuthSession();

    if (isAuthenticated()) {
        return;
    }

    $script = basename($_SERVER['SCRIPT_FILENAME'] ?? '');
    $apiScripts = ['get_history.php', 'subscribe.php', 'unsubscribe.php'];

    if (in_array($script, $apiScripts, true) || wantsJsonResponse()) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }

    $next = $_SERVER['REQUEST_URI'] ?? 'index.php';
    header('Location: login.php?next=' . rawurlencode($next));
    exit;
}

function attemptLogin(string $username, string $password): bool
{
    loadEnv();
    startAuthSession();

    $expectedUser = env('APP_LOGIN_USER');
    $expectedPassword = env('APP_LOGIN_PASSWORD');

    $userOk = hash_equals($expectedUser, $username);
    $passOk = hash_equals($expectedPassword, $password);

    if (!$userOk || !$passOk) {
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['authenticated'] = true;
    $_SESSION['username'] = $expectedUser;

    return true;
}

function logoutCurrentUser(): void
{
    startAuthSession();
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', $params['secure'], $params['httponly']);
    }

    session_destroy();
}

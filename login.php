<?php

require_once __DIR__ . '/auth.php';

startAuthSession();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$next = loginRedirectTarget($_GET['next'] ?? $_POST['next'] ?? 'index.php');
$error = '';

if (isAuthenticated()) {
    header('Location: ' . $next);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        $error = 'Session expired. Please try again.';
    } else {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        try {
            if (attemptLogin($username, $password)) {
                header('Location: ' . $next);
                exit;
            }
            $error = 'Invalid username or password.';
        } catch (RuntimeException $e) {
            $error = 'Server is not configured. Copy .env.example to .env.';
        }
    }
}

$csrf = htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES);
$nextSafe = htmlspecialchars($next, ENT_QUOTES);
$errorSafe = htmlspecialchars($error, ENT_QUOTES);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign in — Price Sentinel</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700&family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: Manrope, sans-serif;
            background: #f8fafc;
            color: #1f2937;
            padding: 1.5rem;
        }
        .card {
            width: 100%;
            max-width: 400px;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 2rem;
        }
        h1 {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 1.5rem;
            margin: 0 0 0.35rem;
            color: #0f766e;
        }
        p {
            margin: 0 0 1.5rem;
            color: #6b7280;
            font-size: 0.95rem;
        }
        label {
            display: block;
            font-size: 0.8rem;
            font-weight: 700;
            margin-bottom: 0.35rem;
        }
        input {
            width: 100%;
            padding: 0.7rem 0.85rem;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font: inherit;
            margin-bottom: 1rem;
        }
        input:focus {
            outline: none;
            border-color: #0f766e;
        }
        button {
            width: 100%;
            padding: 0.8rem;
            border: 0;
            border-radius: 8px;
            background: #0f766e;
            color: #fff;
            font: inherit;
            font-weight: 700;
            cursor: pointer;
        }
        button:hover { background: #115e59; }
        .error {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
            border-radius: 8px;
            padding: 0.7rem 0.85rem;
            margin-bottom: 1rem;
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
    <form class="card" method="post" action="login.php">
        <h1>Price Sentinel</h1>
        <p>Sign in to view prices and manage products.</p>
        <?php if ($errorSafe !== ''): ?>
            <div class="error"><?= $errorSafe ?></div>
        <?php endif ?>
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="next" value="<?= $nextSafe ?>">
        <label for="username">Username</label>
        <input id="username" name="username" autocomplete="username" required>
        <label for="password">Password</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required>
        <button type="submit">Sign in</button>
    </form>
</body>
</html>

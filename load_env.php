<?php

function denyDirectAccess(string $file): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }

    $script = $_SERVER['SCRIPT_FILENAME'] ?? '';
    if ($script !== '' && realpath($script) === realpath($file)) {
        http_response_code(403);
        exit;
    }
}

denyDirectAccess(__FILE__);

function loadEnv(?string $path = null): void
{
    static $loaded = false;

    if ($loaded) {
        return;
    }

    $loaded = true;
    $path = $path ?? (__DIR__ . '/.env');

    if (!is_readable($path)) {
        throw new RuntimeException('Missing .env file. Copy .env.example to .env and fill in the values.');
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        throw new RuntimeException('Unable to read .env file.');
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        if (!str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);

        if ($key === '') {
            continue;
        }

        $len = strlen($value);
        if ($len >= 2) {
            $quote = $value[0];
            if (($quote === '"' || $quote === "'") && $value[$len - 1] === $quote) {
                $value = stripslashes(substr($value, 1, -1));
            }
        }

        if (getenv($key) === false) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
}

function env(string $key, ?string $default = null): string
{
    loadEnv();

    $value = $_ENV[$key] ?? getenv($key);
    if ($value === false || $value === null || $value === '') {
        if ($default !== null) {
            return $default;
        }

        throw new RuntimeException('Missing environment variable: ' . $key);
    }

    return (string) $value;
}

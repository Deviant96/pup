<?php

require_once __DIR__ . '/load_env.php';

denyDirectAccess(__FILE__);
loadEnv();

if (!defined('VAPID_PUBLIC_KEY')) {
    define('VAPID_PUBLIC_KEY', env('VAPID_PUBLIC_KEY'));
}
if (!defined('VAPID_PRIVATE_KEY')) {
    define('VAPID_PRIVATE_KEY', env('VAPID_PRIVATE_KEY'));
}
if (!defined('VAPID_SUBJECT')) {
    define('VAPID_SUBJECT', env('VAPID_SUBJECT', 'mailto:admin@example.com'));
}

<?php

require_once __DIR__ . '/auth.php';

logoutCurrentUser();
header('Location: login.php');
exit;

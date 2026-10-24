<?php
declare(strict_types=1);
define('SESSION_WRITABLE', true);
require __DIR__ . '/lib/auth.php';   // POST only: CSRF is verified by the guard

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    logout_user();
}
redirect('login.php');

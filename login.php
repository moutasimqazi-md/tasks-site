<?php
declare(strict_types=1);
define('PUBLIC_PAGE', true);
define('SESSION_WRITABLE', true);
require __DIR__ . '/lib/auth.php';

if (is_logged_in()) {
    redirect('index.php');
}

$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
    $wait = lock_remaining($ip);
    if ($wait > 0) {
        $error = 'Too many failed attempts. Try again in ' . (int)ceil($wait / 60) . ' minute(s).';
    } else {
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $pw = (string)($_POST['password'] ?? '');
        $okEmail = hash_equals(strtolower((string)cfg('user_email')), $email);
        $okPw = password_verify($pw, (string)cfg('password_hash'));   // always run, to keep timing equal
        if ($okEmail && $okPw) {
            clear_failures($ip);
            login_user();
            redirect('index.php');
        }
        register_failure($ip);
        $wait = lock_remaining($ip);
        $error = $wait > 0
            ? 'Too many failed attempts. Try again in ' . (int)ceil($wait / 60) . ' minute(s).'
            : 'Invalid email or password.';
    }
}
header('Content-Type: text/html; charset=utf-8');
$a = fn(string $p) => e(base_path() . '/assets/' . $p);
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Sign in · Timeline Tracker</title>
<link rel="icon" type="image/svg+xml" href="<?= $a('favicon.svg') ?>">
<link rel="stylesheet" href="<?= $a('app.css') ?>">
</head>
<body class="login-body">
<main class="login-card">
  <img class="logo" src="<?= $a('logo.png') ?>" alt="Movenetics Digital" height="52">
  <h1>Timeline Tracker</h1>
  <p class="muted">Private dashboard — sign in to continue.</p>
  <?php if ($error !== ''): ?><div class="alert" role="alert"><?= e($error) ?></div><?php endif; ?>
  <form method="post" action="login.php" autocomplete="on">
    <?= csrf_field() ?>
    <label>Email<input type="email" name="email" required autofocus autocomplete="username" maxlength="191"></label>
    <label>Password<input type="password" name="password" required autocomplete="current-password" maxlength="200"></label>
    <button class="btn primary block" type="submit">Sign in</button>
  </form>
</main>
</body>
</html>

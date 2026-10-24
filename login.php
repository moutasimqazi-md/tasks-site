<?php
declare(strict_types=1);
define('PUBLIC_PAGE', true);
define('SESSION_WRITABLE', true);
require __DIR__ . '/lib/auth.php';

if (is_logged_in()) {
    redirect('index.php');
}

$error = '';
$prefill = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
    $wait = lock_remaining($ip);
    $prefill = mb_substr(trim((string)($_POST['email'] ?? '')), 0, 191);
    if ($wait > 0) {
        $error = 'Too many failed attempts. Try again in ' . (int)ceil($wait / 60) . ' minute(s).';
    } else {
        $email = strtolower($prefill);
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
$a = function (string $p): string {
    $f = APP_DIR . '/assets/' . $p;
    return e(base_path() . '/assets/' . $p . (is_file($f) ? '?v=' . filemtime($f) : ''));
};
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
<main class="auth">
  <section class="auth-side" aria-hidden="true">
    <img class="auth-logo" src="<?= $a('logo.png') ?>" alt="">
    <h2>Timelines,<br>tracked honestly.</h2>
    <p>See which deadlines slip, which tickets disappear, and who owns them — updated every hour from your boards.</p>
    <ul>
      <li><span>⏱</span> Spot inflated deadlines the moment they move</li>
      <li><span>🗑</span> Track every deleted ticket and restore</li>
      <li><span>💬</span> Comment on any event and export to CSV</li>
    </ul>
  </section>

  <section class="auth-form">
    <img class="auth-logo mobile-only" src="<?= $a('logo.png') ?>" alt="Movenetics Digital">
    <h1>Welcome back</h1>
    <p class="muted">Sign in to the Timeline Tracker.</p>

    <?php if ($error !== ''): ?><div class="alert" role="alert"><?= e($error) ?></div><?php endif; ?>

    <form method="post" action="login.php" autocomplete="on">
      <?= csrf_field() ?>
      <label>Email
        <input type="email" name="email" value="<?= e($prefill) ?>" required<?= $prefill === '' ? ' autofocus' : '' ?> autocomplete="username" maxlength="191" placeholder="you@moveneticsdigital.com">
      </label>
      <label>Password
        <span class="pw">
          <input type="password" name="password" id="pw" required<?= $prefill !== '' ? ' autofocus' : '' ?> autocomplete="current-password" maxlength="200" placeholder="Your password">
          <button type="button" id="pw-toggle" class="pw-toggle" aria-label="Show password" aria-pressed="false">Show</button>
        </span>
      </label>
      <button class="btn primary block big" type="submit">Sign in</button>
    </form>
    <p class="auth-foot muted small">🔒 Private to Movenetics Digital. Repeated failed attempts lock this device for 15 minutes.</p>
  </section>
</main>
<script src="<?= $a('login.js') ?>"></script>
</body>
</html>

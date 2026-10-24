<?php
// ONE-TIME helper: prints a bcrypt hash for a typed password.
// Paste the hash into timeline_config.php ('password_hash'), then DELETE THIS FILE.
$hash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pw']) && strlen((string)$_POST['pw']) >= 10) {
    $hash = password_hash((string)$_POST['pw'], PASSWORD_BCRYPT);
}
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex"><title>Make password hash</title>
<style>body{font-family:Inter,system-ui,sans-serif;background:#F8F6F3;color:#1C1C1C;max-width:640px;margin:48px auto;padding:0 16px}
input,button{font:inherit;padding:10px;border-radius:8px;border:1px solid #ccc}button{background:#FF5428;color:#fff;border-color:#FF5428;cursor:pointer}
code{display:block;word-break:break-all;background:#fff;border:1px solid #EAE1D8;padding:12px;border-radius:8px;margin:12px 0}
.warn{background:#fff3ee;border:1px solid #FF5428;padding:12px;border-radius:8px}</style></head><body>
<h1>Password hash generator</h1>
<p class="warn"><strong>Delete this file</strong> from the server as soon as you have copied the hash.</p>
<form method="post"><label>Password (min 10 characters)<br><input type="password" name="pw" minlength="10" required size="40" autocomplete="new-password"></label> <button type="submit">Generate</button></form>
<?php if ($hash !== ''): ?>
<p>Copy this into <code style="display:inline">timeline_config.php</code> as <code style="display:inline">'password_hash'</code>:</p>
<code><?= htmlspecialchars($hash, ENT_QUOTES, 'UTF-8') ?></code>
<?php endif; ?>
</body></html>

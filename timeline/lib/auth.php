<?php
declare(strict_types=1);
/**
 * Auth guard. Include at the top of every page and API endpoint.
 * Before including, a script may define:
 *   PUBLIC_PAGE      - login page: no session required
 *   SESSION_WRITABLE - keep the session open for writing (login/logout)
 *   IS_API           - JSON endpoint: 401/403 as JSON instead of redirect/HTML
 */
require_once __DIR__ . '/bootstrap.inc.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.inc.php';

const IDLE_TIMEOUT = 28800;   // 8 hours
const MAX_FAILS    = 5;
const LOCK_SECONDS = 900;     // 15 minutes

function session_fingerprint(): string
{
    return hash_hmac('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '', (string)cfg('session_secret', ''));
}

function is_logged_in(): bool
{
    return !empty($_SESSION['uid']);
}

function current_user(): array
{
    return ['email' => (string)($_SESSION['email'] ?? ''), 'name' => (string)($_SESSION['name'] ?? '')];
}

function login_user(): void
{
    session_regenerate_id(true);
    $_SESSION['uid']   = 1;
    $_SESSION['email'] = (string)cfg('user_email');
    $_SESSION['name']  = (string)cfg('user_name', 'Yusuf Shaikh');
    $_SESSION['last']  = time();
    $_SESSION['fp']    = session_fingerprint();
    $_SESSION['csrf']  = bin2hex(random_bytes(32));
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'],
            'httponly' => true, 'samesite' => 'Lax']);
    }
    session_destroy();
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_verify(): void
{
    $sent = (string)($_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    $have = (string)($_SESSION['csrf'] ?? '');
    if ($have === '' || !hash_equals($have, $sent)) {
        fail_page(403, 'Your form token is missing or expired. Go back, reload the page and try again.');
    }
}

/* ---- Login lockout (login_attempts table, times in UTC) ---- */

function lock_remaining(string $ip): int
{
    $until = db_val('SELECT locked_until FROM login_attempts WHERE ip = ?', [$ip]);
    if (!$until) {
        return 0;
    }
    return max(0, (new DateTimeImmutable((string)$until, tz_utc()))->getTimestamp() - time());
}

function register_failure(string $ip): void
{
    $pdo = db();
    $now = gmdate('Y-m-d H:i:s');
    $pdo->beginTransaction();
    try {
        db_exec('INSERT IGNORE INTO login_attempts (ip, fail_count, last_attempt_at, locked_until) VALUES (?, 0, ?, NULL)', [$ip, $now]);
        $row = db_row('SELECT fail_count, last_attempt_at, locked_until FROM login_attempts WHERE ip = ? FOR UPDATE', [$ip]);
        $count = (int)$row['fail_count'];
        $last  = (new DateTimeImmutable((string)$row['last_attempt_at'], tz_utc()))->getTimestamp();
        $lockExpired = $row['locked_until'] && (new DateTimeImmutable((string)$row['locked_until'], tz_utc()))->getTimestamp() <= time();
        if ($lockExpired || time() - $last > LOCK_SECONDS) {
            $count = 0;
        }
        $count++;
        $lockedUntil = $count >= MAX_FAILS ? gmdate('Y-m-d H:i:s', time() + LOCK_SECONDS) : null;
        db_exec('UPDATE login_attempts SET fail_count = ?, last_attempt_at = ?, locked_until = ? WHERE ip = ?',
            [$count, $now, $lockedUntil, $ip]);
        $pdo->commit();
    } catch (Throwable $t) {
        $pdo->rollBack();
        throw $t;
    }
}

function clear_failures(string $ip): void
{
    db_exec('DELETE FROM login_attempts WHERE ip = ?', [$ip]);
}

/* ---- The guard ---- */

if (is_logged_in()) {
    $expired = time() - (int)($_SESSION['last'] ?? 0) > IDLE_TIMEOUT;
    $hijack  = !hash_equals((string)($_SESSION['fp'] ?? ''), session_fingerprint());
    if ($expired || $hijack) {
        unset($_SESSION['uid'], $_SESSION['email'], $_SESSION['name'], $_SESSION['last'], $_SESSION['fp']);
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_verify();
}

if (is_logged_in()) {
    $_SESSION['last'] = time();
} elseif (!defined('PUBLIC_PAGE')) {
    if (is_api()) {
        json_out(['error' => 'Not authenticated'], 401);
    }
    redirect('login.php');
}

if (!defined('SESSION_WRITABLE')) {
    csrf_token();
    session_write_close();   // release the session lock so parallel requests don't queue
}

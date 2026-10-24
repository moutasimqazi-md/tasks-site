<?php
declare(strict_types=1);

// The ONE place that decides where the config lives. Default: the cPanel home
// directory (three levels above this lib/ folder), i.e. outside public_html.
define('TIMELINE_CONFIG_PATH', dirname(__DIR__, 3) . '/timeline_config.php');
define('APP_DIR', dirname(__DIR__));

ini_set('display_errors', '0');
ini_set('html_errors', '0');
error_reporting(E_ALL);
date_default_timezone_set('UTC');

function cfg(string $key, mixed $default = null): mixed
{
    static $c = null;
    if ($c === null) {
        $c = is_file(TIMELINE_CONFIG_PATH) ? (require TIMELINE_CONFIG_PATH) : [];
        if (!is_array($c)) {
            $c = [];
        }
    }
    return $c[$key] ?? $default;
}

function log_error(string $msg): void
{
    $line = '[' . gmdate('Y-m-d H:i:s') . ' UTC] ' . $msg . "\n";
    $file = (string)cfg('log_file', '');
    if ($file !== '') {
        @error_log($line, 3, $file);
    } else {
        @error_log($msg);
    }
}

function is_api(): bool
{
    return defined('IS_API');
}

function base_path(): string
{
    static $b = null;
    if ($b === null) {
        $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
        if (str_ends_with($dir, '/api')) {
            $dir = substr($dir, 0, -4);
        }
        $b = $dir;
    }
    return $b;
}

function fail_page(int $code, string $message): never
{
    if (!headers_sent()) {
        http_response_code($code);
    }
    if (is_api()) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['error' => $message]);
        exit;
    }
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
    }
    $m = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Error</title><style>body{font-family:Inter,system-ui,sans-serif;background:#F8F6F3;color:#1C1C1C;display:grid;place-items:center;min-height:100vh;margin:0}'
        . '.b{max-width:440px;padding:32px;text-align:center}h1{font-size:20px}p{color:#6D818D}a{color:#FF5428}'
        . '@media(prefers-color-scheme:dark){body{background:#1C1C1C;color:#F8F6F3}}</style></head><body><div class="b"><h1>'
        . $code . ' &middot; ' . ($code === 403 ? 'Not allowed' : 'Something went wrong') . '</h1><p>' . $m
        . '</p><p><a href="' . htmlspecialchars(base_path(), ENT_QUOTES) . '/index.php">Back to the dashboard</a></p></div></body></html>';
    exit;
}

set_error_handler(function (int $no, string $str, string $file, int $line): bool {
    if (!(error_reporting() & $no)) {
        return false;
    }
    log_error("PHP[$no] $str at $file:$line");
    return true;
});

set_exception_handler(function (Throwable $t): void {
    log_error(get_class($t) . ': ' . $t->getMessage() . ' at ' . $t->getFile() . ':' . $t->getLine());
    fail_page(500, 'An unexpected error occurred. It has been logged.');
});

register_shutdown_function(function (): void {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        log_error("FATAL {$e['message']} at {$e['file']}:{$e['line']}");
        if (!headers_sent()) {
            fail_page(500, 'An unexpected error occurred. It has been logged.');
        }
    }
});

if (!is_file(TIMELINE_CONFIG_PATH)) {
    log_error('Config file missing: ' . TIMELINE_CONFIG_PATH);
    fail_page(500, 'The application is not configured yet.');
}

// Security headers. No inline scripts are used anywhere, so the CSP can be strict.
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'self'; script-src 'self' https://cdnjs.cloudflare.com; "
    . "style-src 'self' 'unsafe-inline'; img-src 'self' data:; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");

// Session
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_name('tl_sess');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => (base_path() === '' ? '/' : base_path() . '/'),
    'secure'   => $https,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

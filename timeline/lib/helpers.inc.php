<?php
declare(strict_types=1);

function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function tz_ist(): DateTimeZone
{
    static $z = null;
    return $z ??= new DateTimeZone('Asia/Kolkata');
}

function tz_utc(): DateTimeZone
{
    static $z = null;
    return $z ??= new DateTimeZone('UTC');
}

/** UTC DATETIME string -> IST display string. */
function fmt_ist(?string $utc, string $fmt = 'j M Y, H:i'): string
{
    if ($utc === null || $utc === '' || str_starts_with($utc, '0000')) {
        return '—';
    }
    try {
        return (new DateTimeImmutable($utc, tz_utc()))->setTimezone(tz_ist())->format($fmt);
    } catch (Throwable) {
        return '—';
    }
}

/** 'YYYY-MM-DD' text -> "29 Oct 2026" (otherwise returned as-is). */
function fmt_date(?string $v): string
{
    $v = trim((string)$v);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v, tz_utc());
        if ($d && $d->format('Y-m-d') === $v) {
            return $d->format('j M Y');
        }
    }
    return $v;
}

function fmt_num(float|int|null $n, int $dec = 0): string
{
    return $n === null ? '—' : number_format((float)$n, $dec);
}

function event_labels(): array
{
    return [
        'D1_MOVED'         => ['Original deadline moved', 'Original deadline moved later (commitment rewritten — most serious)'],
        'D2_SET'           => ['Revised deadline set', 'Revised deadline set'],
        'D2_MOVED'         => ['Revised deadline moved again', 'Revised deadline moved again'],
        'START_PUSHED'     => ['Start date pushed', 'Start date pushed'],
        'ESTIMATE_UP'      => ['Estimate increased', 'Estimate increased'],
        'DEADLINE_REMOVED' => ['Deadline removed', 'Deadline removed (hides the slip)'],
        'ALREADY_EXTENDED' => ['Already extended (pre-tracking)', 'Already extended before tracking began (baseline from first run)'],
        'TASK_DELETED'     => ['Ticket deleted', 'Ticket deleted'],
        'TASK_RESTORED'    => ['Ticket restored', 'Deleted ticket came back'],
    ];
}

function event_label(string $type): string
{
    return event_labels()[$type][0] ?? $type;
}

function event_desc(string $type): string
{
    return event_labels()[$type][1] ?? $type;
}

function event_colors(): array
{
    return [
        'D1_MOVED' => '#E5391A', 'D2_SET' => '#FF8A3D', 'D2_MOVED' => '#F5B83D',
        'START_PUSHED' => '#6D818D', 'ESTIMATE_UP' => '#3F8F8B', 'DEADLINE_REMOVED' => '#8C2F5B',
        'ALREADY_EXTENDED' => '#B8B0A6', 'TASK_DELETED' => '#1C1C1C', 'TASK_RESTORED' => '#4C9A5E',
    ];
}

/** Old/new value text for an event row. */
function fmt_change(array $r): string
{
    $type = (string)$r['event_type'];
    if ($type === 'TASK_RESTORED') {
        return '—';
    }
    $f = function (?string $v) use ($type): string {
        $v = trim((string)$v);
        if ($v === '') {
            return 'empty';
        }
        if (is_numeric($v) && $type === 'ESTIMATE_UP') {
            return rtrim(rtrim(number_format((float)$v, 1, '.', ''), '0'), '.') . ' d';
        }
        return fmt_date($v);
    };
    return $f($r['old_value'] ?? null) . ' → ' . $f($r['new_value'] ?? null);
}

function safe_url(?string $u): string
{
    $u = trim((string)$u);
    if ($u !== '' && preg_match('#^https?://#i', $u) && filter_var($u, FILTER_VALIDATE_URL)) {
        return $u;
    }
    return '';
}

function badge(string $text, string $cls = 'gray', string $title = ''): string
{
    return '<span class="badge ' . e($cls) . '"' . ($title !== '' ? ' title="' . e($title) . '"' : '') . '>' . e($text) . '</span>';
}

function info(string $text): string
{
    return ' <span class="info" tabindex="0" role="note" aria-label="' . e($text) . '" data-tip="' . e($text) . '">i</span>';
}

function redirect(string $path): never
{
    header('Location: ' . base_path() . '/' . ltrim($path, '/'));
    exit;
}

function json_out(mixed $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

/** Current script URL with query overrides. A null value removes the key. */
function self_url(array $set = []): string
{
    $q = $_GET;
    foreach ($set as $k => $v) {
        if ($v === null) {
            unset($q[$k]);
        } else {
            $q[$k] = $v;
        }
    }
    return basename($_SERVER['SCRIPT_NAME']) . ($q ? '?' . http_build_query($q) : '');
}

function ba_label(?string $email, ?string $name = null): string
{
    $name = trim((string)$name);
    if ($name !== '') {
        return $name;
    }
    $email = (string)$email;
    if ($email === '') {
        return '(Unassigned)';
    }
    return ba_directory()[$email] ?? $email;
}

function ba_directory(): array
{
    static $dir = null;
    if ($dir !== null) {
        return $dir;
    }
    $fallback = [
        'akruti.patel@moveneticsdigital.com'    => 'Akruti Patel',
        'asad.dongri@moveneticsdigital.com'     => 'Asad Dongri',
        'binson.abraham@moveneticsdigital.com'  => 'Binson Abraham',
        'moutasim.qazi@moveneticsdigital.com'   => 'Umama Qazi',
        'sapna.kaintu@moveneticsdigital.com'    => 'Sapna Kaintu',
    ];
    $rows = db_all("SELECT ba_email, MAX(ba_name) AS ba_name FROM tasks WHERE ba_email IS NOT NULL AND ba_email <> '' GROUP BY ba_email
                    UNION ALL
                    SELECT ba_email, MAX(ba_name) AS ba_name FROM events WHERE ba_email IS NOT NULL AND ba_email <> '' GROUP BY ba_email");
    $out = [];
    foreach ($rows as $r) {
        $em = (string)$r['ba_email'];
        $nm = trim((string)$r['ba_name']);
        if (!isset($out[$em]) || ($out[$em] === '' && $nm !== '')) {
            $out[$em] = $nm;
        }
    }
    foreach ($out as $em => $nm) {
        if ($nm === '') {
            $out[$em] = $fallback[strtolower($em)] ?? $em;
        }
    }
    asort($out, SORT_NATURAL | SORT_FLAG_CASE);
    return $dir = $out;
}

function project_list(): array
{
    $rows = db_all("SELECT project FROM (
                      SELECT DISTINCT project FROM tasks WHERE project IS NOT NULL AND project <> ''
                      UNION
                      SELECT DISTINCT project FROM events WHERE project IS NOT NULL AND project <> ''
                    ) p ORDER BY project");
    return array_column($rows, 'project');
}

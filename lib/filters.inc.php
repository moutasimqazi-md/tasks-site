<?php
declare(strict_types=1);

const NO_BA = '__none__';

/**
 * The date an event is ABOUT (UTC DATETIME), not when n8n noticed it:
 *  - changed_at when the source knows it (Notion),
 *  - the original deadline (IST midnight) for pre-tracking baseline rows,
 *  - otherwise detected_at (n8n runs hourly, so this is within the hour).
 */
function event_date_expr(string $a = 'e'): string
{
    return "CASE WHEN $a.changed_at IS NOT NULL THEN $a.changed_at
                 WHEN $a.event_type = 'ALREADY_EXTENDED' AND $a.old_value REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}\$'
                      THEN DATE_SUB(STR_TO_DATE($a.old_value, '%Y-%m-%d'), INTERVAL 330 MINUTE)
                 ELSE $a.detected_at END";
}

function valid_ymd(?string $s): ?DateTimeImmutable
{
    if (!is_string($s) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
        return null;
    }
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $s, tz_ist());
    return ($d && $d->format('Y-m-d') === $s) ? $d : null;
}

function str_list(mixed $v, int $max = 60): array
{
    if (!is_array($v)) {
        return [];
    }
    $out = [];
    foreach ($v as $x) {
        if (is_string($x) && $x !== '' && strlen($x) <= 191) {
            $out[$x] = $x;
        }
        if (count($out) >= $max) {
            break;
        }
    }
    return array_values($out);
}

function utc_str(DateTimeImmutable $ist): string
{
    return $ist->setTimezone(tz_utc())->format('Y-m-d H:i:s');
}

/** Parse the global filter bar from $_GET. All dates are IST days; *_utc are SQL bounds (end exclusive). */
function load_filters(): array
{
    $g = $_GET;
    $today = new DateTimeImmutable('today', tz_ist());
    $preset = in_array($g['range'] ?? '', ['today', '7d', '30d', 'month', 'all', 'custom'], true) ? $g['range'] : '30d';
    $from = $to = $today;
    switch ($preset) {
        case 'today': break;
        case '7d': $from = $today->modify('-6 days'); break;
        case 'month': $from = $today->modify('first day of this month'); break;
        case 'all':
            $min = db_val('SELECT MIN(' . event_date_expr('e') . ') FROM events e');
            $from = $min ? (new DateTimeImmutable((string)$min, tz_utc()))->setTimezone(tz_ist())->setTime(0, 0) : $today->modify('-29 days');
            break;
        case 'custom':
            $f = valid_ymd($g['from'] ?? null);
            $t = valid_ymd($g['to'] ?? null);
            if ($f && $t) {
                $from = $f; $to = $t;
                if ($from > $to) { [$from, $to] = [$to, $from]; }
            } else {
                $preset = '30d';
                $from = $today->modify('-29 days');
            }
            break;
        default: $from = $today->modify('-29 days');
    }
    $toExcl = $to->modify('+1 day');
    $len = (int)$from->diff($to)->days + 1;
    $prevFrom = $from->modify("-{$len} days");

    $source = in_array($g['source'] ?? '', ['sheet', 'notion'], true) ? $g['source'] : '';
    $q = mb_substr(trim((string)($g['q'] ?? '')), 0, 100);

    $f = [
        'preset' => $preset,
        'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'),
        'from_utc' => utc_str($from), 'to_utc' => utc_str($toExcl),
        'prev_from_utc' => utc_str($prevFrom), 'prev_to_utc' => utc_str($from),
        'prev_from' => $prevFrom->format('Y-m-d'), 'prev_to' => $from->modify('-1 day')->format('Y-m-d'),
        'ba' => str_list($g['ba'] ?? null), 'project' => str_list($g['project'] ?? null),
        'assignee' => str_list($g['assignee'] ?? null, 40),
        'source' => $source, 'q' => $q,
        'types' => preg_grep('/^[A-Z0-9_]{1,30}$/', str_list($g['types'] ?? null)),
        'pre' => true,   // pre-tracking (ALREADY_EXTENDED) rows are always included 'restores' => !empty($g['restores']),
    ];
    $f['types'] = array_values($f['types']);

    // Core params carried between pages (nav links, scorecard -> inflations).
    $qs = ['range' => $preset];
    if ($preset === 'custom') { $qs['from'] = $f['from']; $qs['to'] = $f['to']; }
    if ($f['ba']) $qs['ba'] = $f['ba'];
    if ($f['project']) $qs['project'] = $f['project'];
    if ($f['assignee']) $qs['assignee'] = $f['assignee'];
    if ($source !== '') $qs['source'] = $source;
    if ($q !== '') $qs['q'] = $q;
    $f['_qs'] = $qs;
    return $f;
}

/** "col IN (...)" that also supports the NO_BA sentinel meaning NULL/empty. */
function in_nullable(string $col, array $vals, array &$params): string
{
    $real = array_values(array_filter($vals, fn($v) => $v !== NO_BA));
    $parts = [];
    if ($real) {
        $parts[] = "$col IN (" . placeholders(count($real)) . ')';
        array_push($params, ...$real);
    }
    if (count($real) !== count($vals)) {
        $parts[] = "($col IS NULL OR $col = '')";
    }
    return '(' . implode(' OR ', $parts) . ')';
}

/**
 * Assignee column holds "A, B" lists with hand-typed spellings. A chosen (canonical) person matches every
 * known spelling, as a whole list element, so "Abdul" never matches "Abdul Khalik".
 */
function assignee_clause(string $col, array $names, array &$params): string
{
    $parts = [];
    foreach ($names as $n) {
        foreach (person_variants($n) as $v) {
            $parts[] = "CONCAT(',', REPLACE($col, ', ', ','), ',') LIKE ?";
            $params[] = '%,' . addcslashes($v, '%_\\') . ',%';
        }
    }
    return '(' . implode(' OR ', $parts) . ')';
}

function like_escape(string $q): string
{
    return '%' . addcslashes($q, '%_\\') . '%';
}

/** WHERE clause (without the word WHERE) for events. scope: inflation | deletion | all. */
function events_where(array $f, string $scope = 'all', ?array $range = null, string $a = 'e'): array
{
    $w = []; $p = [];
    $when = '(' . event_date_expr($a) . ')';
    $w[] = "$when >= ?"; $p[] = $range['from_utc'] ?? $f['from_utc'];
    $w[] = "$when < ?";  $p[] = $range['to_utc'] ?? $f['to_utc'];

    if ($scope === 'inflation') {
        $w[] = "$a.category = 'inflation'";
        if ($f['types']) {
            $w[] = "$a.event_type IN (" . placeholders(count($f['types'])) . ')';
            array_push($p, ...$f['types']);
        }
    } elseif ($scope === 'deletion') {
        $w[] = $f['restores'] ? "$a.category = 'deletion'" : "$a.event_type = 'TASK_DELETED'";
    }
    $explicitPre = $scope === 'inflation' && in_array('ALREADY_EXTENDED', $f['types'], true);
    if (!$f['pre'] && !$explicitPre) {
        $w[] = "$a.event_type <> 'ALREADY_EXTENDED'";
    }
    if ($f['ba'])      $w[] = in_nullable("$a.ba_email", ba_expand($f['ba']), $p);
    if ($f['project']) { $pj = project_expand($f['project']); $w[] = "$a.project IN (" . placeholders(count($pj)) . ')'; array_push($p, ...$pj); }
    if ($f['assignee']) $w[] = assignee_clause("$a.assignee", $f['assignee'], $p);
    if ($f['source'] !== '') { $w[] = "$a.source = ?"; $p[] = $f['source']; }
    if ($f['q'] !== '') {
        $w[] = "($a.title LIKE ? OR $a.assignee LIKE ?)";
        $l = like_escape($f['q']); $p[] = $l; $p[] = $l;
    }
    return [implode(' AND ', $w), $p];
}

/** WHERE for the current board (tasks): not date-filtered, not search-filtered. */
function tasks_where(array $f, string $a = 't'): array
{
    $w = ["$a.deleted_at IS NULL"]; $p = [];
    if ($f['ba'])      $w[] = in_nullable("$a.ba_email", ba_expand($f['ba']), $p);
    if ($f['project']) { $pj = project_expand($f['project']); $w[] = "$a.project IN (" . placeholders(count($pj)) . ')'; array_push($p, ...$pj); }
    if ($f['assignee']) $w[] = assignee_clause("$a.assignee", $f['assignee'], $p);
    if ($f['source'] !== '') { $w[] = "$a.source = ?"; $p[] = $f['source']; }
    return [implode(' AND ', $w), $p];
}

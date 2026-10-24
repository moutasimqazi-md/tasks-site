<?php
declare(strict_types=1);

const INFL_DAY_TYPES = "'D1_MOVED','D2_SET','D2_MOVED'";
const DAY_TYPES = "'D1_MOVED','D2_SET','D2_MOVED','ALREADY_EXTENDED'";   // days counted in totals

function kpi_counts(array $f, ?array $range = null): array
{
    [$w, $p] = events_where($f, 'all', $range);
    $r = db_row("SELECT
        COALESCE(SUM(e.category = 'inflation'), 0) AS infl,
        COALESCE(SUM(CASE WHEN e.event_type IN (" . DAY_TYPES . ") THEN e.days_added ELSE 0 END), 0) AS days,
        COALESCE(SUM(e.event_type IN ('D1_MOVED','DEADLINE_REMOVED')), 0) AS rewritten,
        COALESCE(SUM(e.event_type = 'TASK_DELETED'), 0) AS deleted,
        COALESCE(SUM(e.category = 'inflation' AND e.after_deadline = 1), 0) AS after
        FROM events e WHERE $w", $p);
    return array_map('intval', $r ?? ['infl' => 0, 'days' => 0, 'rewritten' => 0, 'deleted' => 0, 'after' => 0]);
}

function scorecard_metrics(): array
{
    // key => [label, group, format, tooltip]
    return [
        'tasks'      => ['Tasks', 'board', 'int', 'Open (non-deleted) tasks on the boards. Not coloured.'],
        'extended'   => ['Extended', 'board', 'int', 'Tasks where times_moved > 0 or total_added > 0.'],
        'pct_ext'    => ['% extended', 'board', 'pct', 'Extended / Tasks.'],
        'days_total' => ['Total days added', 'board', 'int', 'SUM(total_added) — working days from first D1 to the current deadline.'],
        'avg_slip'   => ['Avg slip / extended', 'board', 'dec', 'Total days added / Extended.'],
        'padding'    => ['Padding ratio', 'board', 'x', 'SUM(planned_days) / SUM(est_days) over tasks where both > 0. 1.0× = plan window equals the estimate.'],
        'padded'     => ['Padded tasks', 'board', 'int', 'planned_days ≥ 1.5 × est_days and planned − est ≥ 2.'],
        'infl'       => ['Inflation events', 'period', 'int', 'Inflation events detected in the date range.'],
        'days'       => ['Days added', 'period', 'int', 'Working days added by deadline moves (incl. the pre-tracking baseline) in range.'],
        'rewritten'  => ['Originals rewritten', 'period', 'int', 'D1_MOVED + DEADLINE_REMOVED.'],
        'after'      => ['Ext. after deadline', 'period', 'int', 'Inflation events where the old date had already passed.'],
        'noreason'   => ['Ext. no reason', 'period', 'int', 'D1_MOVED / D2_SET / D2_MOVED with an empty reason.'],
        'deleted'    => ['Tickets deleted', 'period', 'int', 'TASK_DELETED events in range.'],
        'overdue_del'=> ['Overdue deleted', 'period', 'int', 'TASK_DELETED where the ticket was already overdue.'],
    ];
}

function scorecard_compute(array $f): array
{
    [$tw, $tp] = tasks_where($f);
    $board = db_all("SELECT COALESCE(t.ba_email,'') AS k, MAX(t.ba_name) AS nm, COUNT(*) AS tasks,
        COALESCE(SUM(t.times_moved > 0 OR t.total_added > 0), 0) AS extended,
        COALESCE(SUM(t.total_added), 0) AS days_total,
        COALESCE(SUM(CASE WHEN t.planned_days > 0 AND t.est_days > 0 THEN t.planned_days END), 0) AS sp,
        COALESCE(SUM(CASE WHEN t.planned_days > 0 AND t.est_days > 0 THEN t.est_days END), 0) AS se,
        COALESCE(SUM(t.est_days > 0 AND t.planned_days >= 1.5 * t.est_days AND t.planned_days - t.est_days >= 2), 0) AS padded
        FROM tasks t WHERE $tw GROUP BY COALESCE(t.ba_email,'')", $tp);

    [$ew, $ep] = events_where($f, 'all');
    $per = db_all("SELECT COALESCE(e.ba_email,'') AS k, MAX(e.ba_name) AS nm,
        COALESCE(SUM(e.category = 'inflation'), 0) AS infl,
        COALESCE(SUM(CASE WHEN e.event_type IN (" . DAY_TYPES . ") THEN e.days_added ELSE 0 END), 0) AS days,
        COALESCE(SUM(e.event_type IN ('D1_MOVED','DEADLINE_REMOVED')), 0) AS rewritten,
        COALESCE(SUM(e.category = 'inflation' AND e.after_deadline = 1), 0) AS after,
        COALESCE(SUM(e.event_type IN (" . INFL_DAY_TYPES . ") AND (e.reason IS NULL OR TRIM(e.reason) = '')), 0) AS noreason,
        COALESCE(SUM(e.event_type = 'TASK_DELETED'), 0) AS deleted,
        COALESCE(SUM(e.event_type = 'TASK_DELETED' AND e.after_deadline = 1), 0) AS overdue_del
        FROM events e WHERE $ew GROUP BY COALESCE(e.ba_email,'')", $ep);

    $rows = [];
    $blank = array_fill_keys(array_keys(scorecard_metrics()), 0);
    foreach ($board as $r) {
        $k = (string)$r['k'];
        $rows[$k] = $blank + ['key' => $k, 'name' => (string)$r['nm'], 'sp' => 0.0, 'se' => 0.0];
        foreach (['tasks', 'extended', 'days_total', 'padded'] as $m) $rows[$k][$m] = (int)$r[$m];
        $rows[$k]['sp'] = (float)$r['sp']; $rows[$k]['se'] = (float)$r['se'];
    }
    foreach ($per as $r) {
        $k = (string)$r['k'];
        $rows[$k] ??= $blank + ['key' => $k, 'name' => '', 'sp' => 0.0, 'se' => 0.0];
        if ($rows[$k]['name'] === '') $rows[$k]['name'] = (string)$r['nm'];
        foreach (['infl', 'days', 'rewritten', 'after', 'noreason', 'deleted', 'overdue_del'] as $m) $rows[$k][$m] = (int)$r[$m];
    }
    // Same person under an old and a new address -> one row.
    $sumKeys = ['tasks', 'extended', 'days_total', 'padded', 'sp', 'se', 'infl', 'days', 'rewritten', 'after', 'noreason', 'deleted', 'overdue_del'];
    foreach (ba_alias() as $from => $to) {
        if (!isset($rows[$from])) continue;
        if (isset($rows[$to])) {
            foreach ($sumKeys as $m) $rows[$to][$m] += $rows[$from][$m];
        } else {
            $rows[$to] = $rows[$from];
            $rows[$to]['key'] = $to;
        }
        unset($rows[$from]);
    }
    foreach ($rows as $k => &$r) {
        $r['name'] = ba_label($k, $r['name']);
        $r['pct_ext']  = $r['tasks'] > 0 ? $r['extended'] / $r['tasks'] * 100 : null;
        $r['avg_slip'] = $r['extended'] > 0 ? $r['days_total'] / $r['extended'] : null;
        $r['padding']  = $r['se'] > 0 ? $r['sp'] / $r['se'] : null;
    }
    unset($r);
    uasort($rows, fn($a, $b) => strcasecmp($a['name'], $b['name']));

    $n = max(1, count($rows));
    $team = ['key' => '', 'name' => 'Team average'];
    foreach (array_keys(scorecard_metrics()) as $m) {
        $team[$m] = array_sum(array_column($rows, $m)) / $n;
    }
    $sumExt = array_sum(array_column($rows, 'extended'));
    $sumTasks = array_sum(array_column($rows, 'tasks'));
    $sumSe = array_sum(array_column($rows, 'se'));
    $team['pct_ext']  = $sumTasks > 0 ? $sumExt / $sumTasks * 100 : null;
    $team['avg_slip'] = $sumExt > 0 ? array_sum(array_column($rows, 'days_total')) / $sumExt : null;
    $team['padding']  = $sumSe > 0 ? array_sum(array_column($rows, 'sp')) / $sumSe : null;
    return ['rows' => $rows, 'team' => $team];
}

/** Rows for the BAs in the filter (team average always uses the whole team). */
function scorecard_rows(array $f, string $sort = 'name', string $dir = 'asc'): array
{
    $fAll = $f; $fAll['ba'] = [];
    $c = scorecard_compute($fAll);
    $rows = $c['rows'];
    if ($f['ba']) {
        $rows = array_filter($rows, fn($r) => in_array($r['key'] === '' ? NO_BA : $r['key'], $f['ba'], true));
    }
    $rows = array_values($rows);
    $valid = array_merge(['name'], array_keys(scorecard_metrics()));
    if (!in_array($sort, $valid, true)) $sort = 'name';
    usort($rows, function ($a, $b) use ($sort, $dir) {
        $x = $a[$sort]; $y = $b[$sort];
        if ($x === null && $y === null) return 0;
        if ($x === null) return 1;
        if ($y === null) return -1;
        $c = $sort === 'name' ? strcasecmp((string)$x, (string)$y) : $x <=> $y;
        return $dir === 'desc' ? -$c : $c;
    });
    return [$rows, $c['team']];
}

/** green / amber / red vs team average; lower is better; within ±15% is amber. */
function metric_class(float|int|null $v, float|int|null $avg, string $metric): string
{
    if ($metric === 'tasks' || $v === null || $avg === null) return '';
    if ($avg == 0) return $v == 0 ? 'good' : 'bad';
    $ratio = $v / $avg;
    if (abs($ratio - 1) <= 0.15) return 'mid';
    return $ratio < 1 ? 'good' : 'bad';
}

function fmt_metric(float|int|null $v, string $fmt): string
{
    if ($v === null) return '—';
    return match ($fmt) {
        'pct' => number_format((float)$v, 1) . '%',
        'x'   => number_format((float)$v, 1) . '×',
        'dec' => number_format((float)$v, 1),
        default => (floor((float)$v) == (float)$v) ? number_format((float)$v) : number_format((float)$v, 1),
    };
}

/* ---------------- Chart payload ---------------- */

function ba_palette(): array
{
    return ['#FF5428', '#1C6E8C', '#E0A100', '#5B8C5A', '#8C2F5B', '#6D818D', '#C77D4A', '#3F8F8B', '#9A6BD1', '#B8B0A6'];
}

function chart_payload(array $f): array
{
    $dir = ba_directory();
    $allKeys = array_keys($dir);
    sort($allKeys);
    $pal = ba_palette();
    $colorOf = fn(string $k) => $k === '' ? '#B8B0A6' : $pal[array_search($k, $allKeys, true) % count($pal)];

    // Week axis: last 12 weeks or the selected range if longer (Mon-start, IST).
    $toD = valid_ymd($f['to']);
    $fromD = valid_ymd($f['from']);
    $monTo = $toD->modify('monday this week');
    $monFrom = $fromD->modify('monday this week');
    $start = $monFrom < $monTo->modify('-11 weeks') ? $monFrom : $monTo->modify('-11 weeks');
    if ($start < $monTo->modify('-103 weeks')) {   // cap the axis at ~2 years of weeks
        $start = $monTo->modify('-103 weeks');
    }
    $weeks = [];
    for ($d = $start; $d <= $monTo && count($weeks) < 260; $d = $d->modify('+1 week')) {
        $weeks[$d->format('Y-m-d')] = $d->format('j M');
    }
    $wkRange = ['from_utc' => utc_str($start), 'to_utc' => $f['to_utc']];
    $ist = 'DATE_ADD(' . event_date_expr('e') . ', INTERVAL 330 MINUTE)';
    $wkExpr = "DATE_SUB(DATE($ist), INTERVAL WEEKDAY($ist) DAY)";

    [$w, $p] = events_where($f, 'all', $wkRange);
    $daysRows = db_all("SELECT $wkExpr AS wk, COALESCE(e.ba_email,'') AS k, SUM(e.days_added) AS v
        FROM events e WHERE $w AND e.event_type IN (" . DAY_TYPES . ") GROUP BY wk, k", $p);
    $delRows = db_all("SELECT $wkExpr AS wk, COALESCE(e.ba_email,'') AS k, COUNT(*) AS v
        FROM events e WHERE $w AND e.event_type = 'TASK_DELETED' GROUP BY wk, k", $p);

    [$w2, $p2] = events_where($f, 'inflation');
    $typeRows = db_all("SELECT COALESCE(e.ba_email,'') AS k, e.event_type AS t, COUNT(*) AS v
        FROM events e WHERE $w2 GROUP BY k, t", $p2);

    [$sc, $team] = scorecard_rows($f);

    $bas = [];
    $ensure = function (string $k, string $name = '') use (&$bas, $colorOf, $weeks) {
        if (!isset($bas[$k])) {
            $bas[$k] = ['key' => $k, 'name' => $name !== '' ? $name : ba_label($k), 'color' => $colorOf($k),
                'weekly_days' => array_fill(0, count($weeks), 0), 'weekly_deleted' => array_fill(0, count($weeks), 0),
                'pct_ext' => null, 'avg_slip' => null, 'padding' => null, 'tasks' => 0, 'types' => []];
        }
    };
    foreach ($sc as $r) { $ensure($r['key'], $r['name']); }
    foreach ($dir as $k => $nm) {
        if (!$f['ba'] || in_array($k, $f['ba'], true)) $ensure($k, $nm);
    }
    $idx = array_flip(array_keys($weeks));
    foreach ($daysRows as $r) { $k = canon_email($r['k']); $ensure($k); if (isset($idx[$r['wk']])) $bas[$k]['weekly_days'][$idx[$r['wk']]] += (int)$r['v']; }
    foreach ($delRows as $r)  { $k = canon_email($r['k']); $ensure($k); if (isset($idx[$r['wk']])) $bas[$k]['weekly_deleted'][$idx[$r['wk']]] += (int)$r['v']; }
    foreach ($typeRows as $r) { $k = canon_email($r['k']); $ensure($k); $bas[$k]['types'][$r['t']] = ($bas[$k]['types'][$r['t']] ?? 0) + (int)$r['v']; }
    foreach ($sc as $r) {
        $bas[$r['key']]['pct_ext'] = $r['pct_ext']; $bas[$r['key']]['avg_slip'] = $r['avg_slip'];
        $bas[$r['key']]['padding'] = $r['padding']; $bas[$r['key']]['tasks'] = $r['tasks'];
    }
    uasort($bas, fn($a, $b) => strcasecmp($a['name'], $b['name']));

    $order = ['D1_MOVED', 'D2_SET', 'D2_MOVED', 'START_PUSHED', 'ESTIMATE_UP', 'DEADLINE_REMOVED'];
    if ($f['pre'] || in_array('ALREADY_EXTENDED', $f['types'], true)) $order[] = 'ALREADY_EXTENDED';
    $types = [];
    foreach ($order as $t) $types[] = ['key' => $t, 'label' => event_label($t), 'color' => event_colors()[$t] ?? '#999'];

    return [
        'weeks' => array_values($weeks),
        'bas' => array_values($bas),
        'types' => $types,
        'team' => ['pct_ext' => $team['pct_ext'], 'avg_slip' => $team['avg_slip'], 'padding' => $team['padding']],
    ];
}

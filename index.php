<?php
declare(strict_types=1);
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/common.inc.php';

$f = load_filters();
$cur = kpi_counts($f);
$prev = kpi_counts($f, ['from_utc' => $f['prev_from_utc'], 'to_utc' => $f['prev_to_utc']]);
$todayIst = (new DateTimeImmutable('today', tz_ist()))->format('Y-m-d');
$qs = fn(array $extra = []) => http_build_query($f['_qs'] + $extra);

// ---- things that need attention ----
[$ew, $ep] = events_where($f, 'all');
$noReason = (int)db_val("SELECT COUNT(*) FROM events e WHERE $ew AND e.event_type IN (" . INFL_DAY_TYPES . ") AND (e.reason IS NULL OR TRIM(e.reason) = '')", $ep);
[$tw, $tp] = tasks_where($f);
$dl = "COALESCE(NULLIF(t.d2, ''), NULLIF(t.d1, ''))";
$op = $tp; $oc = overdue_clause($dl, 't', $todayIst, $op);
$overdueOpen = (int)db_val("SELECT COUNT(*) FROM tasks t WHERE $tw AND $oc", $op);
$sp = $tp; $sc = stale_clause($dl, 't', $todayIst, $sp);
$staleOpen = (int)db_val("SELECT COUNT(*) FROM tasks t WHERE $tw AND $sc", $sp);
$snap = db_row("SELECT COUNT(*) AS tasks, COALESCE(SUM(t.is_done = 0), 0) AS open_t,
                COALESCE(SUM(t.times_moved > 0 OR t.total_added > 0), 0) AS ext, COALESCE(SUM(t.total_added), 0) AS days
                FROM tasks t WHERE $tw", $tp);

// ---- the one-line answer ----
$top = db_row("SELECT COALESCE(e.ba_email,'') AS k, MAX(e.ba_name) AS nm,
               SUM(CASE WHEN e.event_type IN (" . DAY_TYPES . ") THEN e.days_added ELSE 0 END) AS d
               FROM events e WHERE $ew AND e.category = 'inflation' GROUP BY COALESCE(e.ba_email,'') ORDER BY d DESC LIMIT 1", $ep);
$periodTxt = match ($f['preset']) {
    'today' => 'today', '7d' => 'the last 7 days', '30d' => 'the last 30 days', 'month' => 'this month', 'all' => 'all time',
    default => 'the selected period (' . fmt_date($f['from']) . ' – ' . fmt_date($f['to']) . ')',
};
$plural = fn(int $n, string $one, string $many) => number_format($n) . ' ' . ($n === 1 ? $one : $many);
if ($cur['infl'] === 0 && $cur['deleted'] === 0) {
    $summary = 'Nothing has moved in ' . e($periodTxt) . ': no deadline was pushed and no ticket was deleted.';
} else {
    $summary = 'In ' . e($periodTxt) . ', <strong>' . $plural($cur['infl'], 'deadline move', 'deadline moves') . '</strong> added <strong>'
        . $plural($cur['days'], 'working day', 'working days') . '</strong>'
        . ($top && (int)$top['d'] > 0 ? ' — most from ' . e(ba_label($top['k'], $top['nm'])) . ' (' . (int)$top['d'] . ' days)' : '')
        . ($cur['deleted'] > 0 ? '; <strong>' . $plural($cur['deleted'], 'ticket was', 'tickets were') . '</strong> deleted' : '') . '.';
}

// ---- KPI cards (4) ----
$kpis = [
    ['Deadline moves', 'infl', 'inflations.php', [], 'Every time a start date, deadline or estimate was pushed later.'],
    ['Working days added', 'days', 'inflations.php', [], 'Working days added by deadline moves (Mon–Sat).'],
    ['Moved after the deadline', 'after', 'inflations.php', [], 'The date was pushed after the old date had already passed.'],
    ['Tickets deleted', 'deleted', 'deletions.php', [], 'Tickets that disappeared from the boards.'],
];

// ---- short tables ----
[$w, $p] = events_where($f, 'inflation');
$worst = db_all("SELECT e.*, (" . event_date_expr('e') . ") AS ev_at, (SELECT COUNT(*) FROM comments c WHERE c.event_id = e.id) AS ccnt FROM events e
                 WHERE $w AND e.days_added > 0 ORDER BY e.days_added DESC, ev_at DESC LIMIT 5", $p);
$f2 = $f; $f2['restores'] = false;
[$w, $p] = events_where($f2, 'deletion');
$dels = db_all("SELECT e.*, (" . event_date_expr('e') . ") AS ev_at, (SELECT COUNT(*) FROM comments c WHERE c.event_id = e.id) AS ccnt FROM events e
                WHERE $w ORDER BY ev_at DESC, e.id DESC LIMIT 5", $p);

render_header('Overview', 'overview', $f);
render_filter_bar($f);
?>
<p class="summary"><?= $summary ?></p>

<section class="kpis">
<?php foreach ($kpis as [$label, $k, $page, $extra, $tip]):
    $c = $cur[$k]; $pv = $prev[$k];
    if ($c === 0 && $pv === 0) { $delta = 'No change'; $cls = 'flat'; }
    elseif ($pv === 0) { $delta = '▲ was 0 before'; $cls = 'up'; }
    else {
        $pct = (int)round(($c - $pv) / $pv * 100);
        $cls = $pct > 0 ? 'up' : ($pct < 0 ? 'down' : 'flat');
        $delta = $pct === 0 ? 'No change' : ($pct > 0 ? '▲ +' . $pct . '%' : '▼ ' . $pct . '%') . ' vs before (' . number_format($pv) . ')';
    } ?>
  <a class="kpi" href="<?= e(base_path() . '/' . $page . '?' . $qs($extra)) ?>" title="<?= e($tip) ?> Click to see the list.">
    <div class="kpi-label"><?= e($label) ?></div>
    <div class="kpi-value"><?= number_format($c) ?></div>
    <div class="kpi-delta <?= $cls ?>"><?= e($delta) ?></div>
  </a>
<?php endforeach; ?>
</section>
<p class="muted small">“Before” = the previous equal period (<?= e(fmt_date($f['prev_from'])) ?> – <?= e(fmt_date($f['prev_to'])) ?>). Red is up, green is down — fewer is better.</p>

<div class="ov-grid">
  <div id="charts" data-endpoint="<?= e(base_path() . '/api/charts.php') ?>">
    <?php render_chart_card('weekly_days', 'Working days added per week', 'One line per BA.'); ?>
  </div>
  <section class="card attn">
    <h3>Needs attention</h3>
    <ul>
      <?php $items = [
          [$overdueOpen, 'tasks in progress are overdue', 'tasks.php?' . $qs(['view' => 'tasks', 'show' => 'overdue'])],
          [$staleOpen, 'old tickets are ' . STALE_DAYS . '+ days late (never closed?)', 'tasks.php?' . $qs(['view' => 'tasks', 'show' => 'stale'])],
          [$noReason, 'deadline moves have no reason', 'inflations.php?' . $qs()],
          [$cur['rewritten'], 'original deadlines were rewritten', 'inflations.php?' . $qs(['types' => ['D1_MOVED', 'DEADLINE_REMOVED']])],
      ]; $any = false;
      foreach ($items as [$n, $txt, $href]): if ($n <= 0) continue; $any = true; ?>
        <li class="bad"><a href="<?= e(base_path() . '/' . $href) ?>"><span><?= e($txt) ?></span><b><?= number_format($n) ?></b></a></li>
      <?php endforeach; if (!$any): ?><li class="ok">✓ Nothing needs attention right now.</li><?php endif; ?>
    </ul>
  </section>
</div>

<p class="snapshot">Board right now: <strong><?= number_format((int)$snap['tasks']) ?></strong> tasks,
  <strong><?= number_format((int)$snap['open_t']) ?></strong> open,
  <strong><?= number_format((int)$snap['ext']) ?></strong> ever extended<?= $snap['tasks'] > 0 ? ' (' . number_format($snap['ext'] / $snap['tasks'] * 100, 1) . '%)' : '' ?>,
  <strong><?= number_format((int)$snap['days']) ?></strong> working days slipped in total.
  <a href="<?= e(base_path() . '/tasks.php?' . $qs()) ?>">Browse tasks →</a></p>

<?php $ic = event_columns('inflations'); $dc = event_columns('deletions'); ?>
<div class="sec-head"><h2>Biggest moves</h2><a class="more" href="<?= e(base_path() . '/inflations.php?' . $qs()) ?>">See all →</a></div>
<?php render_events_table($ic, ['detected', 'task', 'ba', 'change', 'oldnew', 'days'], $worst, ['rowClass' => 'row_class_inflation']); ?>

<div class="sec-head"><h2>Recent deletions</h2><a class="more" href="<?= e(base_path() . '/deletions.php?' . $qs()) ?>">See all →</a></div>
<?php render_events_table($dc, ['detected', 'task', 'ba', 'last_deadline', 'overdue'], $dels); ?>
<?php render_footer(['chartjs' => true]); ?>

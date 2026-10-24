<?php
declare(strict_types=1);
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/common.inc.php';

$f = load_filters();
$cur = kpi_counts($f);
$prev = kpi_counts($f, ['from_utc' => $f['prev_from_utc'], 'to_utc' => $f['prev_to_utc']]);

$link = fn(string $page, array $extra = []) => e(base_path() . '/' . $page . '?' . http_build_query($f['_qs'] + $extra));
$kpis = [
    ['Inflation events', 'infl', 'inflations.php', [], 'Every time a date was pushed later (start, deadline, estimate).'],
    ['Working days added', 'days', 'inflations.php', [], 'Working days added by D1_MOVED + D2_SET + D2_MOVED events.'],
    ['Original deadlines rewritten', 'rewritten', 'inflations.php', ['types' => ['D1_MOVED', 'DEADLINE_REMOVED']], 'The most serious kind: the original commitment was changed or removed.'],
    ['Tickets deleted', 'deleted', 'deletions.php', [], 'Tickets that disappeared from the boards.'],
    ['Extended after the deadline', 'after', 'inflations.php', [], 'Dates pushed after the old date had already passed.'],
];

[$w, $p] = events_where($f, 'inflation');
$worst = db_all("SELECT e.*, (SELECT COUNT(*) FROM comments c WHERE c.event_id = e.id) AS ccnt FROM events e
                 WHERE $w AND e.days_added > 0 ORDER BY e.days_added DESC, e.detected_at DESC LIMIT 10", $p);
$f2 = $f; $f2['restores'] = false;
[$w, $p] = events_where($f2, 'deletion');
$dels = db_all("SELECT e.*, (SELECT COUNT(*) FROM comments c WHERE c.event_id = e.id) AS ccnt FROM events e
                WHERE $w ORDER BY e.detected_at DESC, e.id DESC LIMIT 10", $p);

[$tw, $tp] = tasks_where($f);
$snap = db_row("SELECT COUNT(*) AS tasks, COALESCE(SUM(t.is_done = 0), 0) AS open_t,
                COALESCE(SUM(t.times_moved > 0 OR t.total_added > 0), 0) AS ext, COALESCE(SUM(t.total_added), 0) AS days
                FROM tasks t WHERE $tw", $tp);
$snapTiles = [
    ['Tasks on the boards', number_format((int)$snap['tasks']), number_format((int)$snap['open_t']) . ' still open'],
    ['Tasks ever extended', number_format((int)$snap['ext']), ($snap['tasks'] > 0 ? number_format($snap['ext'] / $snap['tasks'] * 100, 1) : '0') . '% of all tasks'],
    ['Working days slipped', number_format((int)$snap['days']), 'sum of total slip, all time'],
    ['Avg slip per extended task', $snap['ext'] > 0 ? number_format($snap['days'] / $snap['ext'], 1) . ' d' : '—', 'working days'],
];

render_header('Overview', 'overview', $f, 'How timelines are slipping and what is being deleted — for the selected period.');
render_filter_bar($f);
?>
<section class="kpis">
<?php foreach ($kpis as [$label, $k, $page, $extra, $tip]):
    $c = $cur[$k]; $pv = $prev[$k];
    if ($c === 0 && $pv === 0) { $delta = 'No change'; $cls = 'flat'; }
    elseif ($pv === 0) { $delta = '▲ new (previous: 0)'; $cls = 'up'; }
    else {
        $pct = (int)round(($c - $pv) / $pv * 100);
        $cls = $pct > 0 ? 'up' : ($pct < 0 ? 'down' : 'flat');
        $delta = $pct === 0 ? 'No change' : ($pct > 0 ? '▲ +' . $pct . '%' : '▼ ' . $pct . '%') . ' vs previous (' . number_format($pv) . ')';
    } ?>
  <a class="kpi" href="<?= $link($page, $extra) ?>" title="Click to see the events">
    <div class="kpi-label"><?= e($label) ?><?= info($tip) ?></div>
    <div class="kpi-value"><?= number_format($c) ?></div>
    <div class="kpi-delta <?= $cls ?>"><?= e($delta) ?></div>
  </a>
<?php endforeach; ?>
</section>
<p class="muted small">Compared with the previous equal period (<?= e(fmt_date($f['prev_from'])) ?> – <?= e(fmt_date($f['prev_to'])) ?>). Up is bad (red), down is good (green).</p>

<?php if (!array_sum($cur)) render_empty_hint($f, 'all'); ?>

<h2>Board snapshot <span class="muted small">— live from the boards, not limited to the period</span></h2>
<section class="tiles">
<?php foreach ($snapTiles as [$l, $v, $s]): ?>
  <div class="tile"><div class="tile-label"><?= e($l) ?></div><div class="tile-value"><?= e($v) ?></div><div class="muted small"><?= e($s) ?></div></div>
<?php endforeach; ?>
</section>

<div id="charts" class="chart-grid two" data-endpoint="<?= e(base_path() . '/api/charts.php') ?>">
  <?php render_chart_card('pct_ext', '% of tasks extended per BA', 'Dashed line = team average.');
        render_chart_card('weekly_days', 'Working days added per week', 'Per BA, IST weeks (Mon–Sun).'); ?>
</div>
<p><a class="more" href="<?= $link('graphs.php') ?>">See all graphs →</a></p>

<?php
$ic = event_columns('inflations');
$dc = event_columns('deletions');
?>
<h2>Worst this period <span class="muted small">— the 10 events that added the most working days</span></h2>
<?php render_events_table($ic, ['detected', 'project', 'task', 'ba', 'change', 'oldnew', 'days', 'after', 'comments'], $worst, ['rowClass' => 'row_class_inflation']); ?>

<h2>Recent deletions</h2>
<?php render_events_table($dc, ['detected', 'project', 'task', 'ba', 'last_deadline', 'overdue', 'status', 'comments'], $dels); ?>
<?php render_footer(['chartjs' => true]); ?>

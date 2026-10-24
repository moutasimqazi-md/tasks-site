<?php
declare(strict_types=1);
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/common.inc.php';

$f = load_filters();
[$tw, $tp] = tasks_where($f);
$top = db_all("SELECT t.* FROM tasks t WHERE $tw AND t.is_done = 0 ORDER BY t.total_added DESC, t.task_key LIMIT 10", $tp);

$charts = [
    ['weekly_days', 'Working days added per week', 'Each line is a BA. Weeks start on Monday (IST).'],
    ['pct_ext', 'Tasks extended', 'Share of each BA\'s tasks whose deadline has moved. Dashed line = team average.'],
    ['avg_slip', 'Average slip per extended task', 'How many working days a moved task loses on average.'],
    ['padding', 'Plan padding', 'Plan window ÷ estimate. 1.0× = no padding.'],
    ['weekly_deleted', 'Tickets deleted per week', ''],
    ['by_type', 'What kind of inflation', 'Inflation events by type, per BA.'],
];

render_header('BA graphs', 'graphs', $f, 'Who inflates timelines, who gets tickets deleted, and how padded the plans are.');
render_filter_bar($f);
?>
<div id="charts" class="chart-grid" data-endpoint="<?= e(base_path() . '/api/charts.php') ?>">
<?php foreach ($charts as [$id, $title, $sub]) render_chart_card($id, $title, $sub); ?>
</div>

<h2>10 most inflated open tasks</h2>
<div class="table-wrap"><table class="grid">
  <thead><tr><th class="wide">Task<?= info('Open tasks from the board (deleted_at is empty, not done), ordered by total days added.') ?></th><th>Project</th><th>BA</th><th>Assignee</th>
    <th>First D1 → current deadline</th><th class="num">+Days</th><th class="num">% over plan</th><th class="num">Times moved</th></tr></thead>
  <tbody>
  <?php if (!$top): ?><tr><td colspan="8" class="empty-cell">No data for these filters.</td></tr><?php endif; ?>
  <?php foreach ($top as $t):
      $cur = trim((string)$t['d2']) !== '' ? $t['d2'] : $t['d1'];
      $over = (int)$t['planned_days'] > 0 ? round((int)$t['total_added'] / (int)$t['planned_days'] * 100) . '%' : '—'; ?>
    <tr>
      <td><?= td_task($t) ?></td>
      <td><?= e($t['project']) ?></td>
      <td class="nowrap"><?= e(ba_label($t['ba_email'], $t['ba_name'])) ?></td>
      <td><?= e($t['assignee']) ?></td>
      <td class="nowrap"><?= e(fmt_date($t['first_d1']) ?: '—') ?> → <?= e(fmt_date($cur) ?: '—') ?></td>
      <td class="num strong">+<?= (int)$t['total_added'] ?></td>
      <td class="num"><?= e($over) ?></td>
      <td class="num"><?= (int)$t['times_moved'] ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php render_footer(['chartjs' => true]); ?>

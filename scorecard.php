<?php
declare(strict_types=1);
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/common.inc.php';

$f = load_filters();
$sort = (string)($_GET['sort'] ?? 'name');
$dir = (($_GET['dir'] ?? 'asc') === 'desc') ? 'desc' : 'asc';
[$rows, $team] = scorecard_rows($f, $sort, $dir);
$metrics = scorecard_metrics();
if (!isset($metrics[$sort]) && $sort !== 'name') $sort = 'name';

$th = function (string $key, string $label, string $tip = '') use ($sort, $dir) {
    $active = $sort === $key;
    $next = $active && $dir === 'asc' ? 'desc' : 'asc';
    $arrow = $active ? ($dir === 'asc' ? ' ▲' : ' ▼') : '';
    return '<th class="' . ($key === 'name' ? '' : 'num') . '"><a class="sort" href="' . e(self_url(['sort' => $key, 'dir' => $next])) . '">'
        . e($label) . $arrow . '</a>' . ($tip !== '' ? info($tip) : '') . '</th>';
};
$board = array_filter($metrics, fn($m) => $m[1] === 'board');
$period = array_filter($metrics, fn($m) => $m[1] === 'period');

render_header('BA scorecard', 'scorecard', $f, 'One row per BA. Green is better than the team average, red is worse. Click a name to see their inflations.');
render_filter_bar($f, ['export' => 'scorecard']);
?>
<p class="muted small">Board columns use today's open tasks (project and source filters only, not the date range).
  Period columns use events in the date range. Cells are coloured against the team average:
  <span class="cell good inline">better</span> <span class="cell mid inline">within ±15%</span> <span class="cell bad inline">worse</span> (lower is better).</p>
<div class="table-wrap"><table class="grid score">
  <thead>
    <tr class="group"><th></th><th colspan="<?= count($board) ?>">Board (current tasks)</th><th colspan="<?= count($period) ?>">Period (events in range)</th></tr>
    <tr><?= $th('name', 'BA') ?>
      <?php foreach ($metrics as $k => $m) echo $th($k, $m[0], $m[3]); ?></tr>
  </thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="<?= count($metrics) + 1 ?>" class="empty-cell">No BAs match these filters.</td></tr><?php endif; ?>
  <?php foreach ($rows as $r):
      $qs = $f['_qs']; $qs['ba'] = [$r['key'] === '' ? NO_BA : $r['key']]; ?>
    <tr>
      <td><a class="ba-link" href="<?= e(base_path() . '/inflations.php?' . http_build_query($qs)) ?>"><?= e($r['name']) ?></a></td>
      <?php foreach ($metrics as $k => $m): ?>
        <?php $mc = metric_class($r[$k], $team[$k], $k);
              $verdict = ['good' => 'better than the team average', 'mid' => 'within \u00B115% of the team average', 'bad' => 'worse than the team average', '' => ''][$mc];
              $tipTxt = $r['name'] . ' \u2014 ' . $m[0] . ': ' . fmt_metric($r[$k], $m[2]) . ' | team average: ' . fmt_metric($team[$k], $m[2]) . ($verdict ? ' | ' . $verdict : '') . ' | ' . $m[3]; ?>
        <td class="num cell <?= $mc ?>" title="<?= e($tipTxt) ?>"><?= e(fmt_metric($r[$k], $m[2])) ?></td>
      <?php endforeach; ?>
    </tr>
  <?php endforeach; ?>
  </tbody>
  <tfoot><tr class="team">
    <td>Team average<?= info('Counts are the mean per BA across the whole team. % extended, avg slip and padding ratio are pooled across all tasks. The team is not affected by the BA filter.') ?></td>
    <?php foreach ($metrics as $k => $m): ?><td class="num"><?= e(fmt_metric($team[$k], $m[2])) ?></td><?php endforeach; ?>
  </tr></tfoot>
</table></div>
<?php render_footer(); ?>

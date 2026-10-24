<?php
declare(strict_types=1);
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/common.inc.php';

$f = load_filters();
$levels = ['inflated' => ['Over worst case', 'red'], 'above_plan' => ['Above plan', 'orange'], 'unclear' => ['Unclear scope', 'gray']];
$level = isset($levels[$_GET['level'] ?? '']) ? $_GET['level'] : '';
$dept = in_array($_GET['dept'] ?? '', ['marketing', 'development', 'qa', 'design'], true) ? $_GET['dept'] : '';
$deptLabel = ['marketing' => 'Marketing', 'development' => 'Development', 'qa' => 'QA', 'design' => 'UI/UX Design'];

function names_from_emails(?string $emails): string
{
    $out = [];
    foreach (preg_split('/\s*,\s*/', (string)$emails) as $e) {
        $e = trim($e);
        if ($e === '') continue;
        $out[] = ucwords(str_replace(['.', '_', '-'], ' ', strstr($e, '@', true) ?: $e));
    }
    return implode(', ', $out);
}

// ---- WHERE (flagged_at is UTC, like every DATETIME here) ----
$w = ['x.flagged_at >= ?', 'x.flagged_at < ?']; $p = [$f['from_utc'], $f['to_utc']];
if ($level !== '') { $w[] = 'x.level = ?'; $p[] = $level; }
if ($dept !== '')  { $w[] = 'x.dept = ?';  $p[] = $dept; }
if ($f['project']) { $w[] = 'x.project IN (' . placeholders(count($f['project'])) . ')'; array_push($p, ...$f['project']); }
if ($f['q'] !== '') { $w[] = '(x.task LIKE ? OR x.assignees LIKE ?)'; $l = like_escape($f['q']); $p[] = $l; $p[] = $l; }
$where = implode(' AND ', $w);

render_header('Estimates', 'estimates', $f, 'Tasks given more time than the BA estimation sheets allow — from the daily estimate check.');

$setupMissing = false; $counts = ['inflated' => 0, 'above_plan' => 0, 'unclear' => 0]; $rows = []; $total = 0;
$page = max(1, (int)($_GET['page'] ?? 1));
try {
    foreach (db_all("SELECT x.level AS lv, COUNT(*) AS n FROM estimate_flags x WHERE " . implode(' AND ', array_filter($w, fn($c) => !str_starts_with($c, 'x.level'))) . " GROUP BY x.level",
        array_values(array_filter(array_merge([$f['from_utc'], $f['to_utc']], $dept !== '' ? [$dept] : [], $f['project'], $f['q'] !== '' ? [like_escape($f['q']), like_escape($f['q'])] : []), fn($v) => true))) as $r) {
        if (isset($counts[$r['lv']])) $counts[$r['lv']] = (int)$r['n'];
    }
    $total = (int)db_val("SELECT COUNT(*) FROM estimate_flags x WHERE $where", $p);
    $page = min($page, max(1, (int)ceil($total / PER_PAGE)));
    $rows = db_all("SELECT x.* FROM estimate_flags x WHERE $where ORDER BY x.flagged_at DESC, x.flag_key LIMIT " . PER_PAGE . ' OFFSET ' . (($page - 1) * PER_PAGE), $p);
} catch (PDOException $e) {
    if (($e->errorInfo[0] ?? '') !== '42S02') throw $e;
    $setupMissing = true;
}

render_filter_bar($f, ['only' => ['project']]);
?>
<div class="view-bar">
  <div class="pills" role="group" aria-label="Level">
    <a class="pill<?= $level === '' ? ' active' : '' ?>" href="<?= e(self_url(['level' => null, 'page' => null])) ?>">All</a>
    <?php foreach ($levels as $k => [$t]): ?><a class="pill<?= $level === $k ? ' active' : '' ?>" href="<?= e(self_url(['level' => $k, 'page' => null])) ?>"><?= e($t) ?></a><?php endforeach; ?>
  </div>
  <div class="pills" role="group" aria-label="Department">
    <a class="pill<?= $dept === '' ? ' active' : '' ?>" href="<?= e(self_url(['dept' => null, 'page' => null])) ?>">All depts</a>
    <?php foreach ($deptLabel as $k => $t): ?><a class="pill<?= $dept === $k ? ' active' : '' ?>" href="<?= e(self_url(['dept' => $k, 'page' => null])) ?>"><?= e($t) ?></a><?php endforeach; ?>
  </div>
</div>

<?php if ($setupMissing): ?>
<div class="hint" role="status"><div class="hint-icon">🛠</div><div><strong>Estimates are not connected yet.</strong>
  <ul><li>Run <code>estimate_flags.sql</code> once in phpMyAdmin (creates the table).</li>
      <li>Import <code>n8n-estimate-sync.json</code> into n8n and press “Run now”.</li></ul></div></div>
<?php else: ?>
<section class="kpis three">
  <?php foreach ($levels as $k => [$t, $cls]): ?>
    <a class="kpi" href="<?= e(self_url(['level' => $k, 'page' => null])) ?>" title="Show only: <?= e($t) ?>">
      <div class="kpi-label"><?= e($t) ?><?= info(['inflated' => 'Given more days than the BA sheet allows even with no brief at all (worst case).',
          'above_plan' => 'Given more than the sheet plan but within the worst case. Fine if the brief was missing, worth a question if not.',
          'unclear' => 'The task title is too vague to match to the BA sheets, but it was given several days.'][$k]) ?></div>
      <div class="kpi-value"><?= number_format($counts[$k]) ?></div>
      <div class="kpi-delta <?= $k === 'inflated' ? 'up' : 'flat' ?>">in this period</div>
    </a>
  <?php endforeach; ?>
</section>

<p class="count-line" style="margin-top:18px"><strong><?= number_format($total) ?></strong> flagged tasks. Click a task to open it.</p>
<div class="table-wrap"><table class="grid">
  <thead><tr><th>Flagged (IST)</th><th>Level</th><th class="wide">Task</th><th>Project</th><th>Dept</th><th>Assigned to</th>
    <th class="num">Given<?= info('Days the task was given: its estimate, or the start-to-deadline window.') ?></th>
    <th class="num">Sheet plan<?= info('What the BA sheet says the task should take with a proper brief (best case), review and revision included.') ?></th>
    <th class="num">Worst case<?= info('The most the BA sheet allows even with no brief at all.') ?></th>
    <th class="num">Over by</th></tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="10" class="empty-cell">No flagged tasks match these filters.</td></tr><?php endif; ?>
  <?php foreach ($rows as $r):
      $lv = $levels[$r['level']] ?? [$r['level'], 'gray'];
      $limit = $r['level'] === 'inflated' ? (float)$r['ceiling_days'] : (float)$r['plan_days'];
      $over = $limit > 0 && $r['estimate_days'] !== null ? number_format((float)$r['estimate_days'] / $limit, 1) . '×' : '—';
      $trim = fn($v) => $v === null ? '—' : rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.'); ?>
    <tr class="<?= $r['level'] === 'inflated' ? 'row-red' : ($r['level'] === 'above_plan' ? 'row-orange' : '') ?>">
      <td class="nowrap"><?= e(fmt_ist($r['flagged_at'], 'j M Y')) ?></td>
      <td><?= badge($lv[0], $lv[1]) ?></td>
      <td class="wide"><span class="clip" title="<?= e($r['task']) ?>"><?= e($r['task']) ?></span></td>
      <td><?= e($r['project']) ?></td>
      <td><?= e($deptLabel[$r['dept']] ?? (string)$r['dept']) ?></td>
      <td><?= e(names_from_emails($r['assignees'])) ?></td>
      <td class="num strong"><?= $trim($r['estimate_days']) ?> d</td>
      <td class="num"><?= $r['plan_days'] === null ? '—' : $trim($r['plan_days']) . ' d' ?></td>
      <td class="num"><?= $r['ceiling_days'] === null ? '—' : $trim($r['ceiling_days']) . ' d' ?></td>
      <td class="num <?= $r['level'] === 'inflated' ? 'warn' : '' ?>"><?= e($over) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php render_pagination($total, $page); endif; ?>
<?php render_footer(); ?>

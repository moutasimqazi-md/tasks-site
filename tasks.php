<?php
declare(strict_types=1);
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/common.inc.php';

$f = load_filters();
$view = in_array($_GET['view'] ?? '', ['project', 'assignee', 'tasks'], true) ? $_GET['view'] : 'project';
$show = in_array($_GET['show'] ?? '', ['open', 'all', 'extended', 'overdue', 'stale'], true) ? $_GET['show'] : 'open';
$todayIst = (new DateTimeImmutable('today', tz_ist()))->format('Y-m-d');

// ---- WHERE: board filters + the "show" pill + search ----
[$w, $p] = tasks_where($f);
$dl = "COALESCE(NULLIF(t.d2, ''), NULLIF(t.d1, ''))";
if ($show === 'open')     $w .= ' AND t.is_done = 0';
if ($show === 'extended') $w .= ' AND (t.times_moved > 0 OR t.total_added > 0)';
if ($show === 'overdue') { $w .= ' AND ' . overdue_clause($dl, 't', $todayIst, $p); }
if ($show === 'stale')   { $w .= ' AND ' . stale_clause($dl, 't', $todayIst, $p); }
if ($f['q'] !== '') {
    $w .= ' AND (t.title LIKE ? OR t.assignee LIKE ?)';
    $l = like_escape($f['q']); $p[] = $l; $p[] = $l;
}

function view_url(array $set): string
{
    return self_url($set + ['page' => null, 'sort' => null, 'dir' => null]);
}

render_header('Tasks', 'tasks', $f, 'What is on the boards right now — by project, by person, or as one list.');
?>
<div class="view-bar">
  <div class="pills" role="group" aria-label="Group by">
    <?php foreach (['project' => 'By project', 'assignee' => 'By assignee', 'tasks' => 'All tasks'] as $v => $t): ?>
      <a class="pill<?= $view === $v ? ' active' : '' ?>" href="<?= e(view_url(['view' => $v])) ?>"><?= $t ?></a>
    <?php endforeach; ?>
  </div>
  <div class="pills" role="group" aria-label="Which tasks">
    <?php foreach (['open' => 'Open', 'overdue' => 'Overdue', 'stale' => 'Stale ' . STALE_DAYS . '+ d', 'extended' => 'Extended', 'all' => 'All (incl. done)'] as $v => $t): ?>
      <a class="pill<?= $show === $v ? ' active' : '' ?>" href="<?= e(view_url(['show' => $v])) ?>"><?= $t ?></a>
    <?php endforeach; ?>
  </div>
</div>
<?php render_filter_bar($f, ['period' => false, 'hidden' => ['view' => $view, 'show' => $show]]);

if ($view === 'tasks') {
    $sorts = [
        'title' => 't.title', 'project' => 't.project', 'assignee' => 't.assignee',
        'ba' => "COALESCE(NULLIF(t.ba_name, ''), t.ba_email)", 'status' => 't.status',
        'deadline' => "(dl IS NULL), dl", 'slip' => 't.total_added', 'moved' => 't.times_moved',
    ];
    $sort = isset($sorts[$_GET['sort'] ?? '']) ? $_GET['sort'] : 'deadline';
    $dir = (($_GET['dir'] ?? 'asc') === 'desc') ? 'desc' : 'asc';
    $page = max(1, (int)($_GET['page'] ?? 1));
    $total = (int)db_val("SELECT COUNT(*) FROM tasks t WHERE $w", $p);
    $page = min($page, max(1, (int)ceil($total / PER_PAGE)));
    $rows = db_all("SELECT t.*, $dl AS dl FROM tasks t WHERE $w ORDER BY {$sorts[$sort]} $dir, t.task_key LIMIT " . PER_PAGE . ' OFFSET ' . (($page - 1) * PER_PAGE), $p);

    $cols = ['title' => 'Task', 'project' => 'Project', 'assignee' => 'Assignee', 'ba' => 'BA', 'status' => 'Status',
             'deadline' => 'Deadline', 'slip' => 'Slip (days)', 'moved' => 'Times moved'];
    echo '<p class="count-line"><strong>' . number_format($total) . '</strong> tasks</p>';
    echo '<div class="table-wrap"><table class="grid"><thead><tr>';
    foreach ($cols as $k => $label) {
        $next = $sort === $k && $dir === 'asc' ? 'desc' : 'asc';
        $arrow = $sort === $k ? ($dir === 'asc' ? ' ▲' : ' ▼') : '';
        echo '<th class="' . (in_array($k, ['slip', 'moved'], true) ? 'num' : '') . '"><a class="sort" href="' . e(self_url(['sort' => $k, 'dir' => $next, 'page' => null])) . '">' . e($label) . $arrow . '</a></th>';
    }
    echo '</tr></thead><tbody>';
    if (!$rows) echo '<tr><td colspan="8" class="empty-cell">No tasks match these filters.</td></tr>';
    foreach ($rows as $t) {
        $timing = task_timing($t, $todayIst);
        $overdue = $timing === 'overdue';
        $url = safe_url($t['link'] ?? '');
        $title = (string)$t['title'] !== '' ? (string)$t['title'] : '(untitled)';
        $tHtml = $url !== '' ? '<a href="' . e($url) . '" target="_blank" rel="noopener noreferrer">' . e($title) . '</a>' : e($title);
        $d1 = fmt_date($t['d1']); $d2 = fmt_date($t['d2']);
        $dTip = trim((string)$t['d2']) !== '' && $d1 !== '' ? 'Original deadline ' . $d1 . ', revised to ' . $d2 : ($t['dl'] ? 'Deadline' : 'No deadline set');
        echo '<tr>'
            . '<td class="wide"><span class="clip" title="' . e($title) . '">' . $tHtml . '</span></td>'
            . '<td>' . e(project_display($t['project'] ?? '')) . '</td>'
            . '<td>' . e(assignee_display($t['assignee'] ?? '')) . '</td>'
            . '<td class="nowrap">' . e(ba_label($t['ba_email'], $t['ba_name'])) . '</td>'
            . '<td>' . ((int)$t['is_done'] ? '<span class="badge done">' . e($t['status']) . '</span>' : e($t['status'])) . '</td>'
            . '<td class="nowrap"><span class="deadline' . ($overdue ? ' overdue' : '') . '" title="' . e($dTip . ($timing === 'overdue' ? ' — overdue' : ($timing === 'stale' ? ' — stale: more than ' . STALE_DAYS . ' days past, probably never closed' : ($timing === 'waiting' ? ' — past the date but work is finished or parked (' . $t['status'] . ')' : '')))) . '">'
                . ($t['dl'] ? e(fmt_date($t['dl'])) : '<span class="muted">—</span>') . '</span>' . ($timing === 'overdue' ? ' ' . badge('Overdue', 'red') : ($timing === 'stale' ? ' ' . badge('Stale', 'gray') : '')) . '</td>'
            . '<td class="num">' . ((int)$t['total_added'] > 0 ? '+' . (int)$t['total_added'] : '<span class="muted">0</span>') . '</td>'
            . '<td class="num">' . (int)$t['times_moved'] . '</td>'
            . '</tr>';
    }
    echo '</tbody></table></div>';
    render_pagination($total, $page);
    render_footer();
    exit;
}

// ---- grouped views (aggregate in PHP: a few thousand rows at most) ----
$all = db_all("SELECT t.project, t.assignee, t.status, t.ba_email, t.ba_name, t.is_done, t.times_moved, t.total_added, $dl AS dl
               FROM tasks t WHERE $w", $p);
$groups = [];
foreach ($all as $t) {
    if ($view === 'project') {
        $names = [project_display($t['project'] ?? '')];
    } else {
        $names = person_names((string)$t['assignee']);
        if (!$names) $names = ['(unassigned)'];
    }
    foreach ($names as $n) {
        $g = &$groups[$n];
        $g ??= ['name' => $n, 'tasks' => 0, 'open' => 0, 'overdue' => 0, 'stale' => 0, 'extended' => 0, 'slip' => 0, 'who' => []];
        $g['tasks']++;
        if (!(int)$t['is_done']) {
            $g['open']++;
            $tm = task_timing($t, $todayIst);
            if ($tm === 'overdue') $g['overdue']++;
            if ($tm === 'stale') $g['stale']++;
        }
        if ((int)$t['times_moved'] > 0 || (int)$t['total_added'] > 0) $g['extended']++;
        $g['slip'] += (int)$t['total_added'];
        $other = $view === 'project' ? ba_label($t['ba_email'], $t['ba_name']) : project_display($t['project'] ?? '');
        if ($other !== '') $g['who'][$other] = ($g['who'][$other] ?? 0) + 1;
        unset($g);
    }
}
$gs = $_GET['sort'] ?? 'open';
if (!in_array($gs, ['name', 'tasks', 'open', 'overdue', 'stale', 'extended', 'slip'], true)) $gs = 'open';
$gd = (($_GET['dir'] ?? ($gs === 'name' ? 'asc' : 'desc')) === 'asc') ? 'asc' : 'desc';
$groups = array_values($groups);
usort($groups, function ($a, $b) use ($gs, $gd) {
    $c = $gs === 'name' ? strcasecmp($a['name'], $b['name']) : ($a[$gs] <=> $b[$gs]);
    return ($gd === 'desc' ? -$c : $c) ?: strcasecmp($a['name'], $b['name']);
});
$unit = $view === 'project' ? 'projects' : 'people';
echo '<p class="count-line"><strong>' . number_format(count($groups)) . '</strong> ' . $unit . ' · <strong>' . number_format(count($all)) . '</strong> tasks. Click a name to see its tasks.</p>';
$heads = ['name' => ($view === 'project' ? 'Project' : 'Assignee'), 'who' => ($view === 'project' ? 'BA' : 'Projects'),
          'tasks' => 'Tasks', 'open' => 'Open', 'overdue' => 'Overdue', 'stale' => 'Stale', 'extended' => 'Extended', 'slip' => 'Total slip (days)'];
$tips = ['overdue' => 'Tasks still being worked on whose deadline passed in the last ' . STALE_DAYS . ' days. Work waiting on review or parked is not counted.', 'stale' => 'Open tasks more than ' . STALE_DAYS . ' days past their deadline: usually tickets nobody closed.', 'extended' => 'Tasks whose deadline has moved at least once.',
         'slip' => 'Working days added between the first original deadline and the current one.'];
echo '<div class="table-wrap"><table class="grid"><thead><tr>';
foreach ($heads as $k => $label) {
    if ($k === 'who') { echo '<th>' . e($label) . '</th>'; continue; }
    $next = $gs === $k && $gd === 'asc' ? 'desc' : 'asc';
    $arrow = $gs === $k ? ($gd === 'asc' ? ' ▲' : ' ▼') : '';
    echo '<th class="' . ($k === 'name' ? '' : 'num') . '"><a class="sort" href="' . e(self_url(['sort' => $k, 'dir' => $next])) . '">' . e($label) . $arrow . '</a>' . (isset($tips[$k]) ? info($tips[$k]) : '') . '</th>';
}
echo '</tr></thead><tbody>';
if (!$groups) echo '<tr><td colspan="8" class="empty-cell">No tasks match these filters.</td></tr>';
foreach ($groups as $g) {
    arsort($g['who']);
    $who = array_slice(array_keys($g['who']), 0, 3);
    $whoTxt = implode(', ', $who) . (count($g['who']) > 3 ? ' +' . (count($g['who']) - 3) : '');
    $pick = $view === 'project' ? ['project' => [$g['name']]] : ['assignee' => [$g['name']]];
    $href = view_url(['view' => 'tasks'] + $pick);
    echo '<tr><td><a class="group-link" href="' . e($href) . '">' . e($g['name']) . '</a></td>'
        . '<td class="wide"><span class="clip" title="' . e(implode(', ', array_keys($g['who']))) . '">' . e($whoTxt) . '</span></td>'
        . '<td class="num">' . number_format($g['tasks']) . '</td><td class="num">' . number_format($g['open']) . '</td>'
        . '<td class="num' . ($g['overdue'] > 0 ? ' warn' : '') . '">' . number_format($g['overdue']) . '</td>'
        . '<td class="num">' . number_format($g['stale']) . '</td><td class="num">' . number_format($g['extended']) . '</td><td class="num">' . number_format($g['slip']) . '</td></tr>';
}
echo '</tbody></table></div>';
render_footer();

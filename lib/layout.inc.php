<?php
declare(strict_types=1);

function asset(string $p): string
{
    $f = APP_DIR . '/assets/' . $p;
    return e(base_path() . '/assets/' . $p . (is_file($f) ? '?v=' . filemtime($f) : ''));
}

function render_header(string $title, string $active, ?array $f = null, string $sub = ''): void
{
    header('Content-Type: text/html; charset=utf-8');
    $sync = db_val('SELECT MAX(run_at) FROM runs');
    $stale = true;
    if ($sync) {
        // Red only while a run should have happened: Mon-Sat, 09:30-22:00 IST, and nothing for 3 hours.
        $nowIst = new DateTimeImmutable('now', tz_ist());
        $minutes = (int)$nowIst->format('G') * 60 + (int)$nowIst->format('i');
        $inWindow = $nowIst->format('N') !== '7' && $minutes >= 9 * 60 + 30 && $minutes <= 22 * 60;
        $age = time() - (new DateTimeImmutable((string)$sync, tz_utc()))->getTimestamp();
        $stale = $inWindow && $age > 3 * 3600;
    }
    $syncTxt = $sync ? fmt_ist((string)$sync, 'j M Y, H:i') . ' IST' : 'never';
    $qs = $f ? '?' . http_build_query($f['_qs']) : '';
    $nav = ['index.php' => ['overview', 'Overview'], 'tasks.php' => ['tasks', 'Tasks'], 'inflations.php' => ['inflations', 'Date changes'], 'estimates.php' => ['estimates', 'Estimates'],
        'deletions.php' => ['deletions', 'Deleted'], 'scorecard.php' => ['scorecard', 'People'],
        'graphs.php' => ['graphs', 'Charts']];
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<meta name="csrf" content="<?= e(csrf_token()) ?>">
<meta name="base" content="<?= e(base_path()) ?>">
<title><?= e($title) ?> · Timeline Tracker</title>
<link rel="icon" type="image/svg+xml" href="<?= asset('favicon.svg') ?>">
<link rel="stylesheet" href="<?= asset('app.css') ?>">
<script src="<?= asset('theme.js') ?>"></script>
</head>
<body>
<header class="topbar">
  <a class="brand" href="<?= e(base_path()) ?>/index.php" aria-label="Timeline Tracker home">
    <img class="logo" src="<?= asset('logo.png') ?>" alt="Movenetics Digital" height="38">
    <span class="app-name">Timeline Tracker</span>
  </a>
  <nav class="tabs" aria-label="Main">
    <?php foreach ($nav as $file => [$key, $label]): ?>
      <a href="<?= e(base_path() . '/' . $file . $qs) ?>" class="<?= $active === $key ? 'active' : '' ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
  </nav>
  <div class="top-actions">
    <span class="sync<?= $stale ? ' stale' : '' ?>" title="Time of the latest n8n run<?= $stale ? ' — older than 3 hours' : '' ?>"><span class="dot"></span>Last sync: <?= e($syncTxt) ?></span>
    <button type="button" class="btn ghost icon-btn" data-role="theme-toggle" aria-label="Switch theme" title="Switch theme">🌙</button>
    <form method="post" action="<?= e(base_path()) ?>/logout.php"><?= csrf_field() ?><button class="btn ghost" type="submit">Logout</button></form>
  </div>
</header>
<main class="wrap">
<div class="page-head">
  <h1 class="page-title"><?= e($title) ?></h1>
  <?php if ($sub !== ''): ?><p class="page-sub"><?= e($sub) ?></p><?php endif; ?>
</div>
<?php
}

function ms_dropdown(string $name, string $label, array $options, array $selected): void
{
    $n = count($selected);
    $txt = $n === 0 ? 'All' : ($n === 1 ? (string)($options[$selected[0]] ?? $selected[0]) : "$n selected");
    echo '<details class="ms"><summary><span class="k">' . e($label) . '</span> <span class="count">' . e($txt) . '</span></summary><div class="ms-panel">';
    if (!$options) echo '<span class="muted pad">No options</span>';
    foreach ($options as $val => $text) {
        echo '<label><input type="checkbox" name="' . e($name) . '[]" value="' . e((string)$val) . '"'
            . (in_array((string)$val, $selected, true) ? ' checked' : '') . '> ' . e((string)$text) . '</label>';
    }
    echo '</div></details>';
}

function chip_url(string $key, string $val = ''): string
{
    $q = $_GET;
    if ($val !== '' && isset($q[$key]) && is_array($q[$key])) {
        $q[$key] = array_values(array_filter($q[$key], fn($x) => $x !== $val));
        if (!$q[$key]) unset($q[$key]);
    } else {
        unset($q[$key]);
    }
    unset($q['page']);
    return basename($_SERVER['SCRIPT_NAME']) . ($q ? '?' . http_build_query($q) : '');
}

/**
 * Compact filter bar: period pills + search + one "Filters" popover; active filters show as removable chips.
 * opts: types(bool), restores(bool), export(page), period(bool, default true), hidden(array of extra hidden fields)
 */
function render_filter_bar(array $f, array $opts = []): void
{
    $baOpts = ba_directory() + [NO_BA => '(Unassigned)'];
    $presets = ['today' => 'Today', '7d' => '7 days', '30d' => '30 days', 'month' => 'This month', 'all' => 'All time', 'custom' => 'Custom'];
    $period = ($opts['period'] ?? true) !== false;
    $active = count($f['ba']) + count($f['project']) + count($f['assignee']) + count($f['types']) + ($f['source'] !== '' ? 1 : 0) + ($f['restores'] ? 1 : 0);
    $self = basename($_SERVER['SCRIPT_NAME']);
    $keep = array_intersect_key($_GET, array_flip(['view', 'show', 'level', 'dept']));
    $only = $opts['only'] ?? null;
    $has = fn(string $k) => $only === null || in_array($k, $only, true);
    ?>
<form class="filters" method="get" action="<?= e($self) ?>">
  <input type="hidden" name="range" value="<?= e($f['preset']) ?>" data-role="range">
  <?php foreach (array_intersect_key($_GET, array_flip(['level', 'dept'])) as $hk => $hv): if (is_string($hv)): ?><input type="hidden" name="<?= e($hk) ?>" value="<?= e($hv) ?>"><?php endif; endforeach; ?>
  <?php foreach (($opts['hidden'] ?? []) as $hk => $hv): ?><input type="hidden" name="<?= e($hk) ?>" value="<?= e((string)$hv) ?>"><?php endforeach; ?>
  <div class="filter-top">
    <?php if ($period): ?>
    <div class="pills" role="group" aria-label="Period (IST)">
      <?php foreach ($presets as $v => $t): ?>
        <?php if ($v === 'custom'): ?>
          <button type="button" class="pill<?= $f['preset'] === 'custom' ? ' active' : '' ?>" data-role="custom-toggle"><?= $t ?></button>
        <?php else: ?>
          <a class="pill<?= $f['preset'] === $v ? ' active' : '' ?>" href="<?= e(self_url(['range' => $v, 'from' => null, 'to' => null, 'page' => null])) ?>"><?= $t ?></a>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
    <span class="custom-dates<?= $f['preset'] === 'custom' ? ' show' : '' ?>" data-role="custom">
      <input type="date" name="from" value="<?= e($f['from']) ?>" aria-label="From date">
      <span class="muted">to</span>
      <input type="date" name="to" value="<?= e($f['to']) ?>" aria-label="To date">
    </span>
    <?php endif; ?>
    <span class="grow"></span>
    <input class="search" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Search task or person…" maxlength="100" aria-label="Search">
    <details class="fpanel">
      <summary class="btn">Filters<?= $active ? ' <span class="n">' . $active . '</span>' : '' ?></summary>
      <div class="fpanel-body">
        <?php if ($has('ba')) ms_dropdown('ba', 'BA', $baOpts, $f['ba']); ?>
        <?php if ($has('project')) { $pr = project_list(); ms_dropdown('project', 'Project', array_combine($pr, $pr) ?: [], $f['project']); } ?>
        <?php if ($has('assignee')) { $as = assignee_list(); ms_dropdown('assignee', 'Assignee', array_combine($as, $as) ?: [], $f['assignee']); } ?>
        <?php if (!empty($opts['types'])):
          $tys = array_column(db_all("SELECT DISTINCT event_type FROM events WHERE category = 'inflation' ORDER BY event_type"), 'event_type');
          $to = []; foreach ($tys as $t) $to[$t] = event_label($t);
          ms_dropdown('types', 'Change type', $to, $f['types']);
        endif; ?>
        <?php if ($has('source')): ?><select name="source" aria-label="Source" class="src">
          <option value="">Source: All</option>
          <option value="sheet"<?= $f['source'] === 'sheet' ? ' selected' : '' ?>>Source: Sheets</option>
          <option value="notion"<?= $f['source'] === 'notion' ? ' selected' : '' ?>>Source: Notion</option>
        </select><?php endif; ?>
        <?php if (!empty($opts['restores'])): ?>
          <label class="chk"><input type="checkbox" name="restores" value="1"<?= $f['restores'] ? ' checked' : '' ?>> Show restores</label>
        <?php endif; ?>
      </div>
    </details>
    <button class="btn primary" type="submit">Apply</button>
    <?php if (!empty($opts['export'])): ?>
      <a class="btn ghost" href="<?= e(base_path() . '/export.php?page=' . $opts['export'] . '&' . http_build_query(array_diff_key($_GET, ['page' => 1]))) ?>" title="Download exactly what is filtered as CSV">⬇ CSV</a>
    <?php endif; ?>
  </div>
  <?php if ($active || $f['q'] !== '' || $period): ?>
  <div class="chips">
    <?php if ($period): ?><span class="range-note">Showing <strong><?= e(fmt_date($f['from'])) ?></strong> – <strong><?= e(fmt_date($f['to'])) ?></strong> <span class="muted">(IST)</span></span><?php endif; ?>
    <?php foreach ($f['ba'] as $v): ?><a class="chip" href="<?= e(chip_url('ba', $v)) ?>" title="Remove"><?= e($baOpts[$v] ?? $v) ?> <i>✕</i></a><?php endforeach; ?>
    <?php foreach ($f['project'] as $v): ?><a class="chip" href="<?= e(chip_url('project', $v)) ?>" title="Remove"><?= e($v) ?> <i>✕</i></a><?php endforeach; ?>
    <?php foreach ($f['assignee'] as $v): ?><a class="chip" href="<?= e(chip_url('assignee', $v)) ?>" title="Remove"><?= e($v) ?> <i>✕</i></a><?php endforeach; ?>
    <?php foreach ($f['types'] as $v): ?><a class="chip" href="<?= e(chip_url('types', $v)) ?>" title="Remove"><?= e(event_label($v)) ?> <i>✕</i></a><?php endforeach; ?>
    <?php if ($f['source'] !== ''): ?><a class="chip" href="<?= e(chip_url('source')) ?>" title="Remove">Source: <?= e($f['source'] === 'sheet' ? 'Sheets' : 'Notion') ?> <i>✕</i></a><?php endif; ?>
    <?php if ($f['q'] !== ''): ?><a class="chip" href="<?= e(chip_url('q')) ?>" title="Remove">“<?= e($f['q']) ?>” <i>✕</i></a><?php endif; ?>
    <?php if ($active || $f['q'] !== ''): ?><a class="chip-clear" href="<?= e($self . ($keep ? '?' . http_build_query($keep) : '')) ?>">Clear all</a><?php endif; ?>
  </div>
  <?php endif; ?>
</form>
<?php
}

/** Explains WHY a view is empty. scope: inflation | deletion | all */
function render_empty_hint(array $f, string $scope = 'all'): void
{
    $bare = $f; $bare['ba'] = []; $bare['project'] = []; $bare['source'] = ''; $bare['q'] = ''; $bare['types'] = []; $bare['restores'] = true;
    $withPre = $bare; $withPre['pre'] = true;
    [$w1, $p1] = events_where($withPre, $scope);
    $inRange = (int)db_val("SELECT COUNT(*) FROM events e WHERE $w1", $p1);
    [$w2, $p2] = events_where($bare, $scope);
    $inRangeNoPre = (int)db_val("SELECT COUNT(*) FROM events e WHERE $w2", $p2);
    $all = db_row("SELECT COUNT(*) AS n, MIN(detected_at) AS mn, MAX(detected_at) AS mx, COALESCE(SUM(event_type = 'ALREADY_EXTENDED'), 0) AS pre,
                          COALESCE(SUM(category = 'inflation' AND event_type <> 'ALREADY_EXTENDED'), 0) AS infl,
                          COALESCE(SUM(event_type = 'TASK_DELETED'), 0) AS del FROM events");
    $runs = db_row('SELECT COUNT(*) AS n, MAX(run_at) AS last_run, MAX(tasks_seen) AS seen FROM runs');

    $msgs = [];
    if ((int)$all['n'] === 0) {
        $msgs[] = 'n8n has not written any events yet. Events appear when a task date is pushed later or a ticket is deleted.';
    } else {
        $real = $scope === 'deletion' ? (int)$all['del'] : ($scope === 'inflation' ? (int)$all['infl'] : (int)$all['infl'] + (int)$all['del']);
        if ($real === 0) {
            $msgs[] = 'No <strong>' . ($scope === 'deletion' ? 'deletions' : ($scope === 'inflation' ? 'new date inflations' : 'inflations or deletions')) . '</strong> have been recorded since tracking began'
                . ' — n8n has logged ' . number_format((int)$all['pre']) . ' baseline rows from its first run (' . fmt_ist($all['mn'], 'j M Y') . '), nothing since. That is good news: no deadline has moved and no ticket was deleted.';
        } elseif ($inRangeNoPre === 0) {
            $msgs[] = 'There are real events, but none in this period. The latest one was on <strong>' . e(fmt_ist($all['mx'], 'j M Y')) . '</strong>. '
                . '<a href="' . e(self_url(['range' => 'all', 'from' => null, 'to' => null, 'page' => null])) . '">Show all time</a>.';
        } elseif ($inRangeNoPre > 0) {
            $msgs[] = 'The BA / project / source / search / type filters are hiding <strong>' . number_format($inRangeNoPre) . '</strong> events in this period. '
                . '<a href="' . e(basename($_SERVER['SCRIPT_NAME'])) . '">Reset filters</a>.';
        }
    }
    if ($runs && (int)$runs['n'] > 0) {
        $msgs[] = 'The sync is healthy: ' . number_format((int)$runs['n']) . ' n8n runs so far, the last one ' . e(fmt_ist((string)$runs['last_run'], 'j M Y, H:i')) . ' IST'
            . ((int)$runs['seen'] > 0 ? ', watching up to ' . number_format((int)$runs['seen']) . ' tasks' : '') . '.';
    }
    echo '<div class="hint" role="status"><div class="hint-icon">💡</div><div><strong>Why is this empty?</strong><ul>';
    foreach ($msgs as $m) echo '<li>' . $m . '</li>';   // $m is built from escaped parts only
    echo '</ul></div></div>';
}

function render_chart_card(string $id, string $title, string $sub = ''): void
{
    ?>
  <section class="card chart-card" data-chart="<?= e($id) ?>">
    <header><div><h3><?= e($title) ?></h3><?php if ($sub !== ''): ?><p class="muted small"><?= e($sub) ?></p><?php endif; ?></div>
      <button type="button" class="btn small" data-png="<?= e($id) ?>">⬇ PNG</button></header>
    <p class="insight" data-role="insight"></p>
    <div class="chart-box"><canvas id="c-<?= e($id) ?>" aria-label="<?= e($title) ?>" role="img"></canvas></div>
    <div class="empty" hidden><div><strong>No data in this range</strong><span class="muted small" data-role="why"></span></div></div>
  </section>
<?php
}

function render_footer(array $opts = []): void
{
    ?>
</main>
<div id="scrim" hidden></div>
<aside id="drawer" hidden aria-label="Event details" role="dialog" aria-modal="true">
  <div class="drawer-head"><strong>Event details</strong><button type="button" class="btn ghost" id="drawer-close" aria-label="Close">✕</button></div>
  <div id="drawer-body"></div>
</aside>
<?php if (!empty($opts['chartjs'])): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js" crossorigin="anonymous"></script>
<?php endif; ?>
<script src="<?= asset('app.js') ?>"></script>
</body>
</html>
<?php
}

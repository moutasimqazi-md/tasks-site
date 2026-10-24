<?php
declare(strict_types=1);
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/common.inc.php';

$f = load_filters();
$defs = event_columns('inflations');
[$sortKey, $dir, $expr] = resolve_sort($defs);
$page = max(1, (int)($_GET['page'] ?? 1));
$total = events_count($f, 'inflations');
$page = min($page, max(1, (int)ceil($total / PER_PAGE)));
[$sql, $p] = events_sql($f, 'inflations', $expr, $dir, PER_PAGE, ($page - 1) * PER_PAGE);
$rows = db_all($sql, $p);

render_header('Date changes', 'inflations', $f, 'Every time a start date, deadline or estimate was pushed later. Click a row for the history and comments.');
render_filter_bar($f, ['types' => true, 'export' => 'inflations']);
render_events_table($defs, table_keys($defs), $rows,
    ['sortable' => true, 'sort' => $sortKey, 'dir' => $dir, 'rowClass' => 'row_class_inflation']);
if ($total === 0) render_empty_hint($f, 'inflation');
render_pagination($total, $page);
render_footer();

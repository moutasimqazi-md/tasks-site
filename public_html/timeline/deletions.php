<?php
declare(strict_types=1);
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/common.inc.php';

$f = load_filters();
$defs = event_columns('deletions');
[$sortKey, $dir, $expr] = resolve_sort($defs);
$page = max(1, (int)($_GET['page'] ?? 1));
$total = events_count($f, 'deletions');
$page = min($page, max(1, (int)ceil($total / PER_PAGE)));
[$sql, $p] = events_sql($f, 'deletions', $expr, $dir, PER_PAGE, ($page - 1) * PER_PAGE);
$rows = db_all($sql, $p);

render_header('Deleted tickets', 'deletions', $f, 'Tickets that disappeared from the boards. Click a row for details and comments.');
render_filter_bar($f, ['restores' => true, 'export' => 'deletions']);
render_events_table($defs, array_keys(array_filter($defs, fn($d) => empty($d['csv_only']))), $rows,
    ['sortable' => true, 'sort' => $sortKey, 'dir' => $dir]);
if ($total === 0) render_empty_hint($f, 'deletion');
render_pagination($total, $page);
render_footer();

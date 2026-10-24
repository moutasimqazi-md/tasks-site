<?php
declare(strict_types=1);
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/common.inc.php';

$page = (string)($_GET['page'] ?? '');
if (!in_array($page, ['inflations', 'deletions', 'scorecard'], true)) {
    fail_page(400, 'Unknown export.');
}
$f = load_filters();

function csv_cell(mixed $v): mixed
{
    if (is_string($v) && $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) {
        return "'" . $v;   // CSV-injection guard
    }
    return $v;
}

function csv_row($out, array $cells): void
{
    fputcsv($out, array_map('csv_cell', $cells));
}

$name = $page . '_' . $f['from'] . '_to_' . $f['to'] . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $name . '"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");   // UTF-8 BOM so Excel shows emojis

if ($page === 'scorecard') {
    $metrics = scorecard_metrics();
    [$rows, $team] = scorecard_rows($f, (string)($_GET['sort'] ?? 'name'), ($_GET['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc');
    csv_row($out, array_merge(['BA'], array_map(fn($m) => ($m[1] === 'board' ? 'Board: ' : 'Period: ') . $m[0], $metrics)));
    $cell = fn($v, $fmt) => $v === null ? '' : ($fmt === 'int' ? round((float)$v, 1) : round((float)$v, 2));
    foreach (array_merge($rows, [$team]) as $r) {
        csv_row($out, array_merge([$r['name']], array_map(fn($k) => $cell($r[$k], $metrics[$k][2]), array_keys($metrics))));
    }
    fclose($out);
    exit;
}

$defs = event_columns($page);
[, $dir, $expr] = resolve_sort($defs);
[$sql, $p] = events_sql($f, $page, $expr, $dir);
$heads = array_map(fn($d) => $d['label'], $defs);
csv_row($out, array_values($heads));
$st = db()->prepare($sql);
$st->execute($p);
while ($r = $st->fetch()) {
    csv_row($out, array_map(fn($d) => ($d['csv'])($r), array_values($defs)));
}
fclose($out);

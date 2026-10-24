<?php
declare(strict_types=1);

const PER_PAGE = 50;

function fmt_event_when(array $r): string
{
    $utc = (string)($r['ev_at'] ?? $r['detected_at']);
    $baseline = ($r['event_type'] ?? '') === 'ALREADY_EXTENDED' && empty($r['changed_at']);
    return $baseline ? fmt_ist($utc, 'j M Y') : fmt_ist($utc);
}

function when_cell(array $r): string
{
    $baseline = ($r['event_type'] ?? '') === 'ALREADY_EXTENDED' && empty($r['changed_at']);
    $t = $baseline ? 'Pre-tracking: original deadline that was already extended when tracking began. Detected by n8n on ' . fmt_ist($r['detected_at']) . ' IST.'
                   : 'Happened ' . fmt_ist((string)($r['ev_at'] ?? $r['detected_at'])) . ' IST. Detected by n8n ' . fmt_ist($r['detected_at']) . ' IST.';
    return '<span title="' . e($t) . '">' . e(fmt_event_when($r)) . '</span>';
}

function td_task(array $r): string
{
    $t = (string)($r['title'] ?? '');
    $t = $t === '' ? '(untitled)' : $t;
    $url = safe_url($r['link'] ?? '');
    $h = $url !== '' ? '<a href="' . e($url) . '" target="_blank" rel="noopener noreferrer">' . e($t) . '</a>' : e($t);
    if (($r['event_type'] ?? '') === 'TASK_RESTORED') {
        $h .= ' ' . badge('Restored', 'green');
    }
    return '<span class="clip" title="' . e($t) . '">' . $h . '</span>';
}

function ba_cell(array $r): string
{
    return e(ba_label($r['ba_email'] ?? null, $r['ba_name'] ?? null));
}

function yes_badge(array $r, string $label): string
{
    return (int)($r['after_deadline'] ?? 0) === 1 ? badge($label, 'orange') : '<span class="muted">—</span>';
}

function reason_cell(array $r): string
{
    $x = trim((string)($r['reason'] ?? ''));
    if ($x === '') {
        return badge('No reason', 'gray');
    }
    $short = mb_strlen($x) > 70 ? mb_substr($x, 0, 70) . '…' : $x;
    return '<span title="' . e($x) . '">' . e($short) . '</span>';
}

function event_columns(string $page): array
{
    $tipTask = 'Renaming a sheet ticket looks like a deletion plus a new ticket.';
    $common = [
        'detected' => ['label' => 'Date (IST)', 'sort' => 'ev_at', 'class' => 'nowrap',
            'tip' => 'The date the inflation happened. Pre-tracking rows show the original deadline that was extended (the real change date was before tracking began).',
            'html' => 'when_cell', 'csv' => fn($r) => fmt_event_when($r)],
        'project'  => ['label' => 'Project', 'sort' => 'e.project',
            'html' => fn($r) => e($r['project'] ?? ''), 'csv' => fn($r) => (string)$r['project']],
        'board'    => ['label' => 'Board', 'sort' => 'e.board',
            'html' => fn($r) => e($r['board'] ?? ''), 'csv' => fn($r) => (string)$r['board']],
        'task'     => ['label' => 'Task', 'sort' => 'e.title', 'tip' => $tipTask, 'class' => 'wide',
            'html' => 'td_task', 'csv' => fn($r) => (string)$r['title']],
        'assignee' => ['label' => 'Assignee', 'sort' => 'e.assignee',
            'html' => fn($r) => e($r['assignee'] ?? ''), 'csv' => fn($r) => (string)$r['assignee']],
        'ba'       => ['label' => 'BA', 'sort' => 'COALESCE(NULLIF(e.ba_name, \'\'), e.ba_email)', 'class' => 'nowrap',
            'html' => 'ba_cell', 'csv' => fn($r) => ba_label($r['ba_email'], $r['ba_name'])],
        'moved'    => ['extra' => true, 'label' => 'Times moved', 'sort' => 'e.times_moved', 'class' => 'num',
            'html' => fn($r) => e((string)($r['times_moved'] ?? '')), 'csv' => fn($r) => (string)$r['times_moved']],
        'total'    => ['extra' => true, 'label' => 'Total slip', 'sort' => 'e.total_added', 'class' => 'num',
            'html' => fn($r) => e((string)($r['total_added'] ?? '')), 'csv' => fn($r) => (string)$r['total_added']],
        'comments' => ['label' => 'Comments', 'sort' => 'ccnt', 'class' => 'num',
            'html' => fn($r) => '<span class="ccnt" data-comment-count="' . (int)$r['id'] . '">' . (int)$r['ccnt'] . '</span>',
            'csv' => fn($r) => (int)$r['ccnt']],
    ];
    if ($page === 'inflations') {
        $keys = ['detected', 'project', 'task', 'assignee', 'ba', 'change', 'oldnew', 'days', 'total', 'moved', 'after', 'reason', 'changed_by', 'comments'];
        $extra = [
            'change' => ['label' => 'Change', 'sort' => 'e.event_type', 'class' => 'nowrap',
                'html' => fn($r) => badge(event_label($r['event_type']), in_array($r['event_type'], ['D1_MOVED', 'DEADLINE_REMOVED'], true) ? 'red' : 'gray', event_desc($r['event_type'])),
                'csv' => fn($r) => event_label($r['event_type'])],
            'oldnew' => ['label' => 'Old → New', 'sort' => '', 'class' => 'nowrap',
                'html' => fn($r) => e(fmt_change($r)), 'csv' => fn($r) => fmt_change($r)],
            'days' => ['label' => '+Days', 'sort' => 'e.days_added', 'class' => 'num strong',
                'html' => fn($r) => '+' . (int)$r['days_added'], 'csv' => fn($r) => (int)$r['days_added']],
            'after' => ['label' => 'After deadline', 'sort' => 'e.after_deadline',
                'html' => fn($r) => yes_badge($r, 'After deadline'), 'csv' => fn($r) => ((int)$r['after_deadline'] === 1 ? 'Yes' : 'No')],
            'reason' => ['label' => 'Reason', 'sort' => 'e.reason', 'class' => 'wide',
                'html' => 'reason_cell', 'csv' => fn($r) => trim((string)$r['reason'])],
            'changed_by' => ['extra' => true, 'label' => 'Changed by', 'sort' => 'e.changed_by',
                'tip' => 'Notion tickets only, and only "probably": it is the last editor of the page. Sheet tickets show —.',
                'html' => fn($r) => ($r['changed_by'] ?? '') !== '' ? e($r['changed_by']) : '<span class="muted">—</span>',
                'csv' => fn($r) => ($r['changed_by'] ?? '') !== '' ? (string)$r['changed_by'] : '—'],
            'link' => ['label' => 'Task link', 'csv_only' => true, 'csv' => fn($r) => safe_url($r['link'] ?? '')],
        ];
    } else {
        $keys = ['detected', 'project', 'board', 'task', 'assignee', 'ba', 'last_deadline', 'overdue', 'status', 'moved', 'total', 'restored', 'comments'];
        $extra = [
            'last_deadline' => ['label' => 'Last deadline', 'sort' => 'e.old_value', 'class' => 'nowrap',
                'html' => fn($r) => e(fmt_date($r['old_value'] ?? '') ?: '—'), 'csv' => fn($r) => fmt_date($r['old_value'] ?? '')],
            'overdue' => ['label' => 'Was overdue', 'sort' => 'e.after_deadline',
                'html' => fn($r) => yes_badge($r, 'Overdue'), 'csv' => fn($r) => ((int)$r['after_deadline'] === 1 ? 'Yes' : 'No')],
            'status' => ['label' => 'Status at deletion', 'sort' => 'e.status',
                'html' => fn($r) => e($r['status'] ?? ''), 'csv' => fn($r) => (string)$r['status']],
            'restored' => ['label' => 'Restored?', 'sort' => 'restored',
                'html' => fn($r) => $r['event_type'] === 'TASK_RESTORED' ? '<span class="muted">—</span>' : ((int)$r['restored'] === 1 ? badge('Yes', 'green') : 'No'),
                'csv' => fn($r) => $r['event_type'] === 'TASK_RESTORED' ? '—' : ((int)$r['restored'] === 1 ? 'Yes' : 'No')],
            'link' => ['label' => 'Task link', 'csv_only' => true, 'csv' => fn($r) => safe_url($r['link'] ?? '')],
        ];
    }
    $defs = $common + $extra;
    if ($page === 'deletions') {
        $defs['detected']['label'] = 'Deleted (IST)';
        $defs['detected']['tip'] = 'When n8n noticed the ticket was gone.';
    } else {
        $defs['detected']['label'] = 'Inflation date (IST)';
    }
    $ordered = [];
    foreach ($keys as $k) $ordered[$k] = $defs[$k];
    $ordered['link'] = $defs['link'];
    return $ordered;
}

function row_class_inflation(array $r): string
{
    if (in_array($r['event_type'], ['D1_MOVED', 'DEADLINE_REMOVED'], true)) return 'row-red';
    if ((int)$r['after_deadline'] === 1 && trim((string)$r['reason']) === ''
        && in_array($r['event_type'], ['D1_MOVED', 'D2_SET', 'D2_MOVED'], true)) return 'row-orange';
    return '';
}

/** Resolve sort/dir from $_GET against the column whitelist. */
function resolve_sort(array $defs, string $default = 'detected'): array
{
    $key = (string)($_GET['sort'] ?? $default);
    if (!isset($defs[$key]) || empty($defs[$key]['sort'])) $key = $default;
    $dir = (($_GET['dir'] ?? 'desc') === 'asc') ? 'asc' : 'desc';
    if (!isset($_GET['sort'])) $dir = 'desc';
    return [$key, $dir, $defs[$key]['sort']];
}

function events_sql(array $f, string $page, string $orderExpr, string $dir, ?int $limit = null, int $offset = 0): array
{
    $scope = $page === 'inflations' ? 'inflation' : 'deletion';
    [$w, $p] = events_where($f, $scope);
    $extra = $page === 'deletions'
        ? ", EXISTS(SELECT 1 FROM events r WHERE r.task_key = e.task_key AND r.event_type = 'TASK_RESTORED' AND r.detected_at > e.detected_at) AS restored"
        : '';
    $sql = "SELECT e.*, (" . event_date_expr('e') . ") AS ev_at, (SELECT COUNT(*) FROM comments c WHERE c.event_id = e.id) AS ccnt$extra
            FROM events e WHERE $w ORDER BY $orderExpr $dir, e.id $dir";
    if ($limit !== null) {
        $sql .= ' LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset;
    }
    return [$sql, $p];
}

function events_count(array $f, string $page): int
{
    [$w, $p] = events_where($f, $page === 'inflations' ? 'inflation' : 'deletion');
    return (int)db_val("SELECT COUNT(*) FROM events e WHERE $w", $p);
}

function render_info_tip_header(array $d): string
{
    return e($d['label']) . (!empty($d['tip']) ? info($d['tip']) : '');
}

/** opts: sortable(bool), sort, dir, rowClass(callable) */
function render_events_table(array $defs, array $keys, array $rows, array $opts = []): void
{
    $sortable = !empty($opts['sortable']);
    echo '<div class="table-wrap"><table class="grid"><thead><tr>';
    foreach ($keys as $k) {
        $d = $defs[$k];
        $cls = $d['class'] ?? '';
        if ($sortable && !empty($d['sort'])) {
            $active = ($opts['sort'] ?? '') === $k;
            $next = $active && ($opts['dir'] ?? 'desc') === 'asc' ? 'desc' : 'asc';
            $arrow = $active ? (($opts['dir'] ?? 'desc') === 'asc' ? ' ▲' : ' ▼') : '';
            echo '<th class="' . e($cls) . '"><a class="sort" href="' . e(self_url(['sort' => $k, 'dir' => $next, 'page' => null])) . '">'
                . e($d['label']) . $arrow . '</a>' . (!empty($d['tip']) ? info($d['tip']) : '') . '</th>';
        } else {
            echo '<th class="' . e($cls) . '">' . render_info_tip_header($d) . '</th>';
        }
    }
    echo '</tr></thead><tbody>';
    if (!$rows) {
        echo '<tr><td colspan="' . count($keys) . '" class="empty-cell">No events match these filters.</td></tr>';
    }
    foreach ($rows as $r) {
        $rc = isset($opts['rowClass']) ? ($opts['rowClass'])($r) : '';
        echo '<tr class="clickable ' . e($rc) . '" data-event-id="' . (int)$r['id'] . '" tabindex="0">';
        foreach ($keys as $k) {
            echo '<td class="' . e($defs[$k]['class'] ?? '') . '">' . ($defs[$k]['html'])($r) . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}

function render_pagination(int $total, int $page, int $per = PER_PAGE): void
{
    $pages = max(1, (int)ceil($total / $per));
    $from = $total === 0 ? 0 : ($page - 1) * $per + 1;
    $to = min($total, $page * $per);
    echo '<nav class="pager"><span class="muted">Showing ' . number_format($from) . '–' . number_format($to) . ' of ' . number_format($total) . '</span><span class="pages">';
    $link = fn(int $p, string $label, bool $cur = false, bool $dis = false) => $dis
        ? '<span class="pg dis">' . $label . '</span>'
        : '<a class="pg' . ($cur ? ' cur' : '') . '" href="' . e(self_url(['page' => $p === 1 ? null : $p])) . '">' . $label . '</a>';
    echo $link(max(1, $page - 1), '‹ Prev', false, $page <= 1);
    $show = array_unique(array_filter(array_merge([1, $pages], range(max(1, $page - 2), min($pages, $page + 2))), fn($x) => $x >= 1 && $x <= $pages));
    sort($show);
    $prev = 0;
    foreach ($show as $p) {
        if ($p - $prev > 1) echo '<span class="pg dis">…</span>';
        echo $link($p, (string)$p, $p === $page);
        $prev = $p;
    }
    echo $link(min($pages, $page + 1), 'Next ›', false, $page >= $pages);
    echo '</span></nav>';
}

/** Columns shown in the on-screen table (extras and csv-only columns stay in the CSV and the drawer). */
function table_keys(array $defs): array
{
    return array_keys(array_filter($defs, fn($d) => empty($d['csv_only']) && empty($d['extra'])));
}

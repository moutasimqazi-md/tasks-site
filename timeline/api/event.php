<?php
declare(strict_types=1);
define('IS_API', true);
require __DIR__ . '/../lib/auth.php';

function comment_list(int $eventId): array
{
    $rows = db_all('SELECT id, author_email, author_name, body, created_at FROM comments WHERE event_id = ? ORDER BY created_at ASC, id ASC', [$eventId]);
    return array_map(fn($c) => [
        'id' => (int)$c['id'],
        'author' => ($c['author_name'] ?: $c['author_email']),
        'body' => $c['body'],
        'created' => fmt_ist($c['created_at'], 'j M Y, H:i') . ' IST',
    ], $rows);
}

function event_view(array $r): array
{
    $type = (string)$r['event_type'];
    return [
        'id' => (int)$r['id'],
        'type' => $type,
        'label' => event_label($type),
        'desc' => event_desc($type),
        'category' => $r['category'],
        'title' => (string)$r['title'],
        'link' => safe_url($r['link'] ?? ''),
        'project' => $r['project'], 'board' => $r['board'], 'source' => $r['source'],
        'assignee' => $r['assignee'],
        'ba' => ba_label($r['ba_email'], $r['ba_name']),
        'detected' => fmt_ist($r['detected_at']) . ' IST',
        'changed_at' => $r['changed_at'] ? fmt_ist($r['changed_at']) . ' IST' : '—',
        'changed_by' => ($r['changed_by'] ?? '') !== '' ? $r['changed_by'] : '—',
        'change' => fmt_change($r),
        'days_added' => (int)$r['days_added'],
        'total_added' => $r['total_added'],
        'times_moved' => $r['times_moved'],
        'after_deadline' => (int)$r['after_deadline'] === 1,
        'reason' => trim((string)$r['reason']),
        'status' => $r['status'],
    ];
}

$id = (int)($_GET['id'] ?? 0);
$ev = $id > 0 ? db_row('SELECT * FROM events WHERE id = ?', [$id]) : null;
if (!$ev) {
    json_out(['error' => 'Event not found'], 404);
}

$history = db_all('SELECT * FROM events WHERE task_key = ? ORDER BY detected_at ASC, id ASC LIMIT 500', [$ev['task_key']]);
$hist = array_map(fn($h) => [
    'id' => (int)$h['id'], 'detected' => fmt_ist($h['detected_at']), 'label' => event_label($h['event_type']),
    'change' => fmt_change($h), 'days_added' => (int)$h['days_added'], 'reason' => trim((string)$h['reason']),
    'current' => (int)$h['id'] === $id,
], $history);

$t = db_row('SELECT * FROM tasks WHERE task_key = ?', [$ev['task_key']]);
$task = null;
if ($t) {
    $task = [
        'Status' => $t['status'], 'Done' => $t['is_done'] ? 'Yes' : 'No',
        'Start date' => fmt_date($t['start_date']) ?: '—', 'Original deadline (D1)' => fmt_date($t['d1']) ?: '—',
        'Revised deadline (D2)' => fmt_date($t['d2']) ?: '—', 'Estimate (days)' => $t['est_days'] === null ? '—' : rtrim(rtrim((string)$t['est_days'], '0'), '.'),
        'First D1 ever' => fmt_date($t['first_d1']) ?: '—', 'Planned days' => $t['planned_days'] ?? '—',
        'Times moved' => $t['times_moved'], 'Total slip (days)' => $t['total_added'],
        'First seen' => fmt_ist($t['first_seen_at']), 'Last seen' => fmt_ist($t['last_seen_at']),
        'Deleted at' => $t['deleted_at'] ? fmt_ist($t['deleted_at']) . ' IST' : '—',
        'Assignee' => $t['assignee'], 'BA' => ba_label($t['ba_email'], $t['ba_name']),
    ];
}

json_out(['event' => event_view($ev), 'history' => $hist, 'task' => $task, 'comments' => comment_list($id)]);

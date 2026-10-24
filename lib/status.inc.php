<?php
declare(strict_types=1);
/**
 * When is an open task "overdue"?
 *
 * Only tasks that are still being worked on count. Two kinds of open task do not:
 *  - work that is finished and waiting on someone else ("Under review", "Ready to commit" ...)
 *  - parked work (on hold, blocked, backlog, cancelled ...)
 * Anything open and more than STALE_DAYS past its deadline is "stale": almost always a ticket
 * nobody closed, so it is reported separately instead of drowning the real overdue ones.
 * Edit the lists below to match your Notion / sheet statuses (lower case).
 */
const STALE_DAYS = 60;

/** Work is done, waiting on review / release / the client. */
function waiting_statuses(): array
{
    return ['under review', 'in review', 'ready for review', 'review', 'ready to commit', 'ready to merge', 'ready to deploy',
        'ready for qa', 'in qa', 'qa', 'testing', 'qa passed', 'ready for release', 'released', 'deployed', 'merged',
        'awaiting approval', 'awaiting feedback', 'waiting for client', 'client review'];
}

/** Not being worked on right now. */
function parked_statuses(): array
{
    return ['on hold', 'hold', 'blocked', 'paused', 'backlog', 'cancelled', 'canceled', 'archived', "won't do", 'duplicate', 'deferred'];
}

function status_key(?string $s): string
{
    return strtolower(trim((string)preg_replace('/[^\p{L}\p{N}\s\']/u', '', (string)$s)));
}

function stale_cutoff(string $todayIst): string
{
    return (new DateTimeImmutable($todayIst, tz_ist()))->modify('-' . STALE_DAYS . ' days')->format('Y-m-d');
}

/** 'overdue' | 'stale' | 'waiting' | '' for one task row (needs is_done, status, dl). */
function task_timing(array $t, string $todayIst): string
{
    if ((int)($t['is_done'] ?? 0) === 1 || empty($t['dl']) || $t['dl'] >= $todayIst) return '';
    if ($t['dl'] < stale_cutoff($todayIst)) return 'stale';
    return in_array(status_key($t['status'] ?? ''), array_merge(waiting_statuses(), parked_statuses()), true) ? 'waiting' : 'overdue';
}

/** SQL for "overdue" (active, late, not stale). $dl is the deadline expression. Appends to $params in order. */
function overdue_clause(string $dl, string $a, string $todayIst, array &$params): string
{
    $skip = array_merge(waiting_statuses(), parked_statuses());
    array_push($params, $todayIst, stale_cutoff($todayIst), ...$skip);
    return "($a.is_done = 0 AND $dl IS NOT NULL AND $dl < ? AND $dl >= ? AND LOWER(TRIM($a.status)) NOT IN (" . placeholders(count($skip)) . '))';
}

function stale_clause(string $dl, string $a, string $todayIst, array &$params): string
{
    $params[] = stale_cutoff($todayIst);
    return "($a.is_done = 0 AND $dl IS NOT NULL AND $dl < ?)";
}

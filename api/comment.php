<?php
declare(strict_types=1);
define('IS_API', true);
require __DIR__ . '/../lib/auth.php';   // verifies the CSRF token on POST

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_out(['error' => 'POST only'], 405);
}

$id = (int)($_POST['event_id'] ?? 0);
$body = str_replace(["\r\n", "\r"], "\n", trim((string)($_POST['body'] ?? '')));
if ($body === '') {
    json_out(['error' => 'Comment cannot be empty.'], 422);
}
if (mb_strlen($body) > 2000) {
    json_out(['error' => 'Comment is too long (max 2000 characters).'], 422);
}
if (!db_val('SELECT 1 FROM events WHERE id = ?', [$id])) {
    json_out(['error' => 'Event not found'], 404);
}

$u = current_user();
db_exec('INSERT INTO comments (event_id, author_email, author_name, body, created_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())',
    [$id, $u['email'], $u['name'], $body]);

$rows = db_all('SELECT author_email, author_name, body, created_at FROM comments WHERE event_id = ? ORDER BY created_at ASC, id ASC', [$id]);
json_out(['count' => count($rows), 'comments' => array_map(fn($c) => [
    'author' => ($c['author_name'] ?: $c['author_email']), 'body' => $c['body'],
    'created' => fmt_ist($c['created_at']) . ' IST',
], $rows)]);

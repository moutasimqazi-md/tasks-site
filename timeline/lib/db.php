<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.inc.php';

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . cfg('db_host', 'localhost') . ';dbname=' . cfg('db_name') . ';charset=utf8mb4';
        $pdo = new PDO($dsn, (string)cfg('db_user'), (string)cfg('db_pass'), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $pdo->exec("SET NAMES utf8mb4");
        $pdo->exec("SET time_zone = '+00:00'");
    }
    return $pdo;
}

function db_run(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute(array_values($params));
    return $st;
}

function db_all(string $sql, array $params = []): array
{
    return db_run($sql, $params)->fetchAll();
}

function db_row(string $sql, array $params = []): ?array
{
    $r = db_run($sql, $params)->fetch();
    return $r === false ? null : $r;
}

function db_val(string $sql, array $params = []): mixed
{
    $v = db_run($sql, $params)->fetchColumn();
    return $v === false ? null : $v;
}

function db_exec(string $sql, array $params = []): int
{
    return db_run($sql, $params)->rowCount();
}

function placeholders(int $n): string
{
    return implode(',', array_fill(0, max(1, $n), '?'));
}

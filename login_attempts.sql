-- Run once in phpMyAdmin (database movenetics_tasks). The only new table.
CREATE TABLE IF NOT EXISTS login_attempts (
  ip              VARCHAR(45) NOT NULL PRIMARY KEY,
  fail_count      INT         NOT NULL DEFAULT 0,
  last_attempt_at DATETIME    NOT NULL,   -- UTC
  locked_until    DATETIME    NULL        -- UTC
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

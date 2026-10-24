# Build: "Timeline Tracker" — a private dashboard for Yusuf

## 1. Background
Movenetics Digital runs projects across 2 Notion boards and 12 Google Sheet tabs.
Each task has an original deadline (D1), an optional revised deadline (D2), a start
date and an estimate in days. Each project has a BA (business analyst / manager).

An n8n workflow runs every hour (09:00–21:00 IST, Mon–Sat). It compares every task
with its previous state and writes to MySQL:
- every time a date is INFLATED (pushed later), and
- every time a ticket is DELETED (or reappears after deletion).

The goal of this site: let Yusuf Shaikh (the owner) see which BAs inflate timelines,
which tickets get deleted, comment on each event, and export data. n8n writes the
data; the site only reads it, except for comments.

## 2. Hosting constraints (important)
- Shared cPanel hosting. Use **PHP 8.1+ with PDO (MySQL)**. No Node, no Composer,
  no build step, no framework install. Plain PHP files I can upload via File
  Manager or FTP.
- Frontend: server-rendered PHP pages plus vanilla JavaScript. Charts via
  **Chart.js from cdnjs.cloudflare.com**. No other external dependencies.
- MySQL is on the same cPanel account (connect via `localhost`). The database is
  utf8mb4. Set the PDO charset to utf8mb4. Statuses contain emojis like "Done 🙌".
- All config (DB credentials, login hash, session secret) goes in `config.php`
  **outside public_html** (e.g. `/home/<cpaneluser>/timeline_config.php`), loaded
  with a path set in one place.

## 3. Login — exactly one user
- Only Yusuf can log in: email `yusuf.shaikh@moveneticsdigital.com`.
- Email + password login. The password is stored only as a `password_hash()`
  (bcrypt) in config. Include a tiny one-time script `make_hash.php` that prints a
  hash for a typed password, with instructions to delete it after use.
- Security requirements:
  - `session_regenerate_id(true)` on login.
  - Session cookie flags: HttpOnly, Secure, SameSite=Lax.
  - 8-hour idle timeout.
  - Logout button.
  - Lock out for 15 minutes after 5 failed attempts. Track attempts in a small
    `login_attempts` table keyed by IP; include its CREATE TABLE.
  - CSRF token on every POST.
- Every page and every API/CSV endpoint requires the session. Unauthenticated
  users are redirected to the login page, or get 401 for JSON.

## 4. Database (already exists — do NOT change these tables)
All DATETIME columns are **UTC**. Display everything in **IST (Asia/Kolkata)**.
Date columns d1, d2, start_date, first_d1, old_value and new_value hold
`'YYYY-MM-DD'` text, or a number for estimates.

```sql
CREATE TABLE tasks (                -- current state of every task, 1 row per task
  task_key VARCHAR(191) PRIMARY KEY,
  source VARCHAR(10) NOT NULL,      -- 'sheet' | 'notion'
  project VARCHAR(150), board VARCHAR(150), doc_id VARCHAR(100), row_num INT,
  title VARCHAR(500), assignee VARCHAR(255),
  ba_email VARCHAR(191), ba_name VARCHAR(150),
  status VARCHAR(100), is_done TINYINT(1) NOT NULL DEFAULT 0,
  d1 CHAR(10), d2 CHAR(10), start_date CHAR(10), est_days DECIMAL(6,1),
  first_d1 CHAR(10),                -- first D1 ever seen
  planned_days INT,                 -- working days start_date → first_d1
  times_moved INT NOT NULL DEFAULT 0,
  total_added INT NOT NULL DEFAULT 0, -- working days first_d1 → current deadline (d2 or d1)
  link VARCHAR(500),
  first_seen_at DATETIME, last_seen_at DATETIME,
  deleted_at DATETIME               -- set when the ticket disappeared
);

CREATE TABLE events (               -- append-only log
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  detected_at DATETIME NOT NULL,    -- when n8n noticed (UTC)
  changed_at DATETIME,              -- when it happened, if known (Notion only)
  category VARCHAR(12) NOT NULL,    -- 'inflation' | 'deletion'
  event_type VARCHAR(30) NOT NULL,
  task_key VARCHAR(191) NOT NULL, source VARCHAR(10), project VARCHAR(150),
  board VARCHAR(150), title VARCHAR(500), assignee VARCHAR(255),
  ba_email VARCHAR(191), ba_name VARCHAR(150),
  changed_by VARCHAR(191),          -- Notion last editor (email); NULL for sheets
  old_value VARCHAR(40), new_value VARCHAR(40),
  days_added INT NOT NULL DEFAULT 0, -- working days added by THIS change
  total_added INT,                  -- task's total slip at that moment
  times_moved INT,
  after_deadline TINYINT(1) NOT NULL DEFAULT 0, -- old date had already passed
  reason TEXT,                      -- "Reason for Delay" from the board, if any
  status VARCHAR(100), link VARCHAR(500)
);

CREATE TABLE comments (             -- the ONLY table the site writes to
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  event_id BIGINT NOT NULL,         -- FK → events.id
  author_email VARCHAR(191) NOT NULL, author_name VARCHAR(150),
  body TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
-- Insert comments with created_at = UTC_TIMESTAMP() explicitly (server TZ may not be UTC).

CREATE TABLE runs (                 -- one row per n8n run
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  run_at DATETIME NOT NULL, tasks_seen INT, events_found INT, boards_loaded TEXT
);
```

### Event types and display labels
| event_type | Label | old → new |
|---|---|---|
| D1_MOVED | Original deadline moved later (commitment rewritten — most serious) | date → date |
| D2_SET | Revised deadline set | previous deadline → D2 |
| D2_MOVED | Revised deadline moved again | date → date |
| START_PUSHED | Start date pushed | date → date |
| ESTIMATE_UP | Estimate increased | days → days |
| DEADLINE_REMOVED | Deadline removed (hides the slip) | date → empty |
| ALREADY_EXTENDED | Already extended before tracking began (baseline from first run) | D1 → D2 |
| TASK_DELETED | Ticket deleted | its deadline → empty |
| TASK_RESTORED | Deleted ticket came back | — |

### Data caveats to show in the UI (small info tooltips)
- `changed_by` exists only for Notion tickets and means "probably" (last page
  editor). For sheet tickets show "—".
- Renaming a sheet ticket looks like a deletion plus a new ticket.
- ALREADY_EXTENDED rows are a one-time baseline. Exclude them from period
  statistics by default, with a checkbox "Include pre-tracking extensions".
- Working days = Mon–Sat (Sunday excluded). This is already computed in the data.
  Don't recompute it.

### People
BAs (ba_email → name): akruti.patel@moveneticsdigital.com (Akruti Patel),
asad.dongri@moveneticsdigital.com (Asad Dongri),
binson.abraham@moveneticsdigital.com (Binson Abraham),
moutasim.qazi@moveneticsdigital.com (Umama Qazi),
sapna.kaintu@moveneticsdigital.com (Sapna Kaintu).
Prefer `ba_name` from the data; fall back to this list. Do not hard-code projects.
Build every filter list from `SELECT DISTINCT`.

## 5. Pages
Shared layout: top bar with app name, "Last sync: <MAX(runs.run_at) in IST>"
(show it in red if older than 3 hours on a working day), nav tabs and logout.
Responsive, clean and minimal, light and dark aware. Tables scroll horizontally
on mobile.

**Global filter bar** (on the Inflations, Deletions and Scorecard pages; kept in
the URL query string so links are shareable):
- Date range on `detected_at` in IST. Presets: Today, 7 days, 30 days (default),
  This month, Custom.
- BA (multi-select).
- Project (multi-select).
- Source (Sheet / Notion).
- Search (title or assignee).

### 5.1 Overview (home)
KPI cards for the period, each showing the change vs the previous equal period:
- Inflation events
- Working days added (sum of days_added for D1_MOVED + D2_SET + D2_MOVED)
- Original deadlines rewritten (D1_MOVED + DEADLINE_REMOVED)
- Tickets deleted
- Extended after the deadline

Below the cards:
- "Worst this period": the 10 events with the highest days_added.
- "Recent deletions": the last 10 TASK_DELETED events.

### 5.2 Inflated dates
Paginated table (50 rows per page, sortable) of events where category = 'inflation'.
- Columns: Detected (IST), Project, Task (link if present), Assignee, BA,
  Change label, Old → New (dates as "29 Oct 2026"), +Days, Total slip,
  Times moved, After deadline (badge), Reason (or a "No reason" badge),
  Changed by, Comments count.
- Extra filter: event type (multi-select).
- Row highlight colours: D1_MOVED and DEADLINE_REMOVED in red; any extension
  with after_deadline = 1 and an empty reason in orange.
- Clicking a row opens a side drawer. It shows the full event, the task's full
  history (all events with the same task_key, in time order), the current task
  state from `tasks`, and the comment thread with an "Add comment" box.

### 5.3 Deleted tickets
Same table pattern for category = 'deletion'.
- Columns: Detected, Project, Board, Task, Assignee, BA, Last deadline
  (old_value), Was overdue (after_deadline), Status at deletion, Times moved
  before deletion, Total slip before deletion, Restored? (yes if a later
  TASK_RESTORED exists for the same task_key), Comments count.
- Same drawer with comments.

### 5.4 BA scorecard
One row per BA, sortable, plus a bold "Team average" row at the bottom.

Board stats come from `tasks` where `deleted_at IS NULL`, filtered by project
and source but not by date:
- Tasks.
- Extended = count where times_moved > 0 OR total_added > 0.
- % extended.
- Total days added = SUM(total_added).
- Avg slip per extended task = total days added / extended.
- Padding ratio = SUM(planned_days) / SUM(est_days), over rows where both are > 0.
  Show it as "1.8×"; 1.0× means the plan window equals the estimate.
- Padded tasks = count where planned_days ≥ 1.5 × est_days AND
  planned_days − est_days ≥ 2.

Period stats come from `events` in the date range:
- Inflation events.
- Days added (D1_MOVED + D2_SET + D2_MOVED).
- Originals rewritten (D1_MOVED + DEADLINE_REMOVED).
- Extensions after deadline.
- Extensions with no reason (D1_MOVED, D2_SET or D2_MOVED where reason is NULL
  or empty).
- Tickets deleted.
- Overdue tickets deleted (TASK_DELETED with after_deadline = 1).

Colour each metric cell green, amber or red relative to the team average:
within ±15% is amber, better is green, worse is red. Clicking a BA opens the
Inflations page filtered to that BA.

### 5.5 BA graphs (Chart.js)
Uses the same filters. Chart.js uses a consistent colour per BA across all charts.
1. Working days added per BA per week. Line chart over the last 12 weeks, or
   the selected range if longer. Weeks start Monday, in IST.
2. % of tasks extended per BA. Horizontal bar with a dashed team-average line.
3. Average slip per extended task per BA. Bar chart.
4. Padding ratio per BA. Bar chart with a reference line at 1.0×.
5. Tickets deleted per BA per week. Grouped bar chart.
6. Inflation events by type per BA. Stacked bar, one colour per event_type.
7. Table: the 10 most inflated open tasks. From `tasks` where deleted_at IS NULL
   AND is_done = 0, ordered by total_added descending. Columns: Task, Project,
   BA, Assignee, first_d1 → current deadline, +days, % over plan
   (total_added / planned_days), times moved.

Every chart has a small "Download PNG" button and an empty state ("No data in
this range").

## 6. Comments
- Only on events. Stored with author = the logged-in user's email and name.
- Plain text. Escape on output and keep line breaks.
- Max 2000 characters.
- Newest last. Show the IST timestamp.
- Comment counts appear in tables via a LEFT JOIN or subquery.
- No edit or delete in v1.

## 7. CSV export
An "Export CSV" button on the Inflations, Deletions and Scorecard pages exports
exactly the current filtered result (all pages, not just the visible one).
- UTF-8 with BOM so Excel shows emojis correctly.
- Dates in IST.
- Human-readable headers.
- Filename like `inflations_2026-10-01_to_2026-10-24.csv`.
- Protect against CSV injection: prefix cells starting with = + - @ with an
  apostrophe.

## 8. Code quality and security
- PDO prepared statements everywhere. No string-built SQL from user input.
  Whitelist sort columns.
- Escape all output with `htmlspecialchars`.
- One `db.php` helper and one `auth.php` guard included at the top of every page.
- Folder layout:

public_html/timeline/
index.php login.php logout.php
inflations.php deletions.php scorecard.php graphs.php
api/ (JSON for drawer, comments, charts)
export.php
assets/app.css assets/app.js
.htaccess (deny directory listing, deny access to *.inc.php)

- Use indexes that already exist (detected_at, ba_email, category/event_type,
  task_key). Make queries efficient for ~100k events.
- Show friendly error pages. Never print stack traces or SQL errors to the
  browser; log them to a file outside public_html.

## 9. Deliverables
1. All source files, complete, with no placeholders except the config values.
2. `login_attempts` CREATE TABLE SQL.
3. README with cPanel deployment steps:
   - Create the config file outside public_html.
   - Generate the password hash with make_hash.php, then delete it.
   - Upload files.
   - Set PHP version to 8.1+ in "MultiPHP Manager".
   - Force HTTPS.
   - Test the login.
4. A short testing checklist: login lockout, CSRF rejection, each filter, CSV
   matching the table, emoji statuses rendering, and IST times correct around
   midnight.

Before writing code, briefly list any assumption you are making. Then produce the
full implementation.
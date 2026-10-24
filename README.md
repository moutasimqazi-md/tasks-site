# Timeline Tracker — cPanel deployment

Private dashboard for Yusuf Shaikh. Plain PHP 8.1+ (PDO MySQL), no build step.
Look and feel follows the Movenetics Digital site (ivory / charcoal / orange, Inter, same logo).

## Folder structure (mirrors your cPanel account)

```
/home/<cpaneluser>/
├── timeline_config.php        ← DB credentials + login hash   (OUTSIDE public_html)
├── timeline_errors.log        ← created automatically by the app
└── public_html/
    └── timeline/              ← upload this whole folder
        ├── index.php          Overview (home)
        ├── login.php  logout.php
        ├── inflations.php     Inflated dates
        ├── deletions.php      Deleted tickets
        ├── scorecard.php      BA scorecard
        ├── graphs.php         BA graphs
        ├── export.php         CSV export
        ├── make_hash.php      one-time password-hash tool → DELETE after use
        ├── .htaccess          HTTPS redirect, no directory listing, blocks *.inc.php
        ├── api/               event.php  comment.php  charts.php  (JSON)
        ├── assets/            app.css  app.js  logo.png  favicon.svg
        └── lib/               bootstrap.inc.php  db.php  auth.php  helpers/filters/stats/columns/layout/common .inc.php
```

In this project folder: `timeline_config.php` (goes to the home folder), `login_attempts.sql`, and `public_html/timeline/` (goes into public_html).

## Deploy steps

1. **PHP version** — cPanel → *MultiPHP Manager* → set the domain to **PHP 8.1 or newer** (needs `pdo_mysql`, `mbstring`).
2. **Create the lockout table** — cPanel → *phpMyAdmin* → database `movenetics_tasks` → *SQL* tab → paste `login_attempts.sql` → Go. (The only new table; the other four are not touched.)
3. **Config file** — upload `timeline_config.php` to your **home folder** (the folder that contains `public_html`, *not* inside it).
   It already contains the DB name, user and password and the login hash for `yusuf.shaikh@moveneticsdigital.com`.
   Edit one line: `'log_file' => '/home/CPANELUSER/timeline_errors.log'` → put your real cPanel username.
   If the config lives elsewhere, change the single path at the top of `lib/bootstrap.inc.php`.
4. **Upload the site** — upload `public_html/timeline/` into `public_html/` (File Manager or FTP). Make sure hidden `.htaccess` files are uploaded (File Manager → Settings → show hidden files).
5. **Force HTTPS** — `.htaccess` already redirects to HTTPS. You can also enable *Domains → Force HTTPS Redirect*. Install/enable the free AutoSSL certificate first.
6. **Password** — already set. To change it: open `https://tasks.moveneticsdigital.com/timeline/make_hash.php`, type the new password, paste the hash into `timeline_config.php` as `password_hash`, then **delete `make_hash.php`**. If you are not changing the password, just delete `make_hash.php` now.
7. **Test the login** at `https://tasks.moveneticsdigital.com/timeline/`.

If you get "The application is not configured yet": the config is not at `/home/<user>/timeline_config.php`. If you get "An unexpected error occurred": read `timeline_errors.log`.

## Notes

- All DATETIMEs are UTC in the database; everything is shown and filtered in IST.
- The server's `REMOTE_ADDR` is used for the lockout. If the site sits behind a proxy/Cloudflare every visitor may look like one IP.
- Optional speed-up (changes a table, so only if you agree): `ALTER TABLE comments ADD INDEX idx_event (event_id);`
- Security: prepared statements only, whitelisted sort columns, escaped output, CSRF token on every POST, session cookie HttpOnly/Secure/SameSite=Lax, 8-hour idle timeout, strict CSP (no inline JS).

## Testing checklist

- [ ] **Login lockout** — 5 wrong passwords → "Try again in 15 minute(s)"; the correct password is also refused during the lock; works again after 15 minutes (or delete the row in `login_attempts`).
- [ ] **Auth everywhere** — logged out, open `inflations.php`, `export.php?page=inflations` (→ login) and `api/event.php?id=1` (→ 401 JSON).
- [ ] **CSRF** — remove/alter the `csrf` value in the browser dev tools and submit login, logout or a comment → 403 page.
- [ ] **Session** — logout button works; the cookie shows HttpOnly + Secure + SameSite=Lax.
- [ ] **Filters** — each of Today / 7 days / 30 days / This month / Custom; BA multi-select; Project multi-select; Source; Search (title and assignee); event type; the "pre-tracking" checkbox. Copy the URL into a new tab → same view.
- [ ] **Sorting & paging** — every sortable header, both directions; 50 rows per page; sort/filters survive page changes.
- [ ] **Row colours** — D1_MOVED / DEADLINE_REMOVED red; after-deadline extension with no reason orange.
- [ ] **Drawer** — opens on row click; shows the event, full history in time order, current task state; add a comment (newline kept, `<b>x</b>` shown as text, 2001 chars rejected); the table's comment count updates.
- [ ] **Scorecard** — green/amber/red against the team average; Team average row; clicking a BA opens Inflations filtered to them.
- [ ] **Graphs** — all six charts, same BA colour everywhere, dashed reference lines, Download PNG, "No data in this range" on an empty range; the top-10 table.
- [ ] **CSV** — Export CSV on each of the three pages: row count equals the table total (all pages), opens in Excel with emoji statuses intact ("Done 🙌"), headers readable, filename like `inflations_2026-10-01_to_2026-10-24.csv`, a cell starting with `=` is prefixed with `'`.
- [ ] **Emoji** — statuses with emojis render correctly in tables, drawer and CSV.
- [ ] **IST around midnight** — take an event detected between 18:30 and 24:00 UTC: it must show the next calendar day in IST, and appear in the correct day when the range is a single day (Today / Custom).
- [ ] **Last sync** — shows IST time; turns red when the latest `runs.run_at` is older than 3 hours on Mon–Sat.
- [ ] **Dark mode / mobile** — switch OS theme; check at phone width (tables scroll sideways).

## Domain: tasks.moveneticsdigital.com

- Easiest: cPanel → *Domains* → set the document root of `tasks.moveneticsdigital.com` to `public_html/timeline`. The site is then at `https://tasks.moveneticsdigital.com/` (the app detects its own folder, no code change).
- Or leave the subdomain's default document root and upload the `timeline` folder inside it; the site is then at `https://tasks.moveneticsdigital.com/timeline/`.
- Either way keep `timeline_config.php` in `/home/<cpaneluser>/`, outside every document root.
- Make sure AutoSSL has issued a certificate for the subdomain before forcing HTTPS.

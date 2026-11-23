# Functional Specification Document
**KelderInventory Web: public component lookup, admin editing, Dymo printing via Raspberry Pi**

---

## Document Information

| Item | Value |
|------|-------|
| Version | 1.0 (implemented) |
| Date | 2026-11-23 |
| Author | David Steeman |
| Status | **Implemented and live (2026-11-23).** Phases 0 to 5 done; results per phase below. |
| Supersedes | `legacy/KelderInventory.ods` + `legacy/LabelPrintMacro.bas` (see `legacy/FSD_Label_Macro_12x50mm.md`) |

---

## 1. Introduction

Today the inventory of the basement lab lives in `KelderInventory.ods`. Labels are printed
from it with a LibreOffice Basic macro on a Windows PC with a Dymo LabelWriter 450 attached.

This project replaces the spreadsheet with a web application at
**https://www.steeman.be/inventory/** that:

1. lets **anyone** look up components from any device, without logging in;
2. lets the **administrator** (password protected) add, edit and delete components;
3. lets the administrator **print labels** on the Dymo, with the same 12 × 50 mm layout as
   the current macro, from any device, anywhere;
4. makes a **weekly backup** of the full database as a spreadsheet, stored in three places.

The Dymo moves from the Windows PC to a dedicated **Raspberry Pi Zero 2 W** (already
available) that acts as a print agent.

### 1.1 Go / no-go

The project only goes ahead if the Pi can print labels whose layout matches the current
macro output (Phase 0, chapter 11). If Phase 0 fails, the project stops, and the
spreadsheet and macro stay in use as they are.

---

## 2. Scope

### 2.1 In scope

- Public search page (read-only)
- Admin login (single account, password only, no 2FA)
- Create / edit / delete components
- Print one or more labels from the web UI, with a layout preview
- Configurable label font sizes (the current `Config` sheet moves into the web app)
- Print agent on the Pi Zero 2 W (CUPS + Dymo driver)
- Weekly backup to spreadsheet with retention: last 10 weekly + one per month forever
- Backup copies on: the Pi's SD card, LWS, and john-ai's Borg backup (offsite)
- One-time import of the existing `KelderInventory.ods`
- Restore procedure from a backup file

### 2.2 Out of scope (v1)

- Multiple user accounts or roles
- Two-factor authentication
- Stock quantities, prices, suppliers, photos (could be added later as extra columns)
- Label types other than the current 12 × 50 mm label
- Printing from the Windows PC (the macro stays in git as legacy, but is no longer maintained)
- Two-way sync with the spreadsheet (the web app is the single source of truth)

---

## 3. System Overview

```
 Phone / laptop (anywhere)
        │  HTTPS
        ▼
 ┌─────────────────────────────────────────────┐
 │ LWS shared hosting: steeman.be/inventory/   │
 │  PHP 8.4  +  MariaDB 10.11 (shared schema,  │
 │  tables prefixed inv_)                      │
 │  - public search                            │
 │  - admin UI (add/edit/delete/print/export)  │
 │  - print queue API (for the Pi)             │
 │  - backup API + backup store (_backups/)    │
 └─────────────────────────────────────────────┘
        ▲  HTTPS, outbound only (polls every 5 s, weekly backup pull)
        │
 ┌─────────────────────────────┐    rsync over SSH (pull, nightly 23:30)
 │ Raspberry Pi Zero 2 W       │ ◄──────────────────── john-ai: /home/john/backups/inventory/
 │  - print agent (Python)     │                                     │ nightly Borg 00:00
 │  - CUPS + Dymo LW450 (USB)  │                                     ▼
 │  - backup store on SD card  │                  Borg repo on USB disk (/mnt/backup/john-ai)
 └─────────────────────────────┘                                     │ offsite sync 02:00
                                                                     ▼
                                                           offsite backup Pi (Tailscale)
```

Key design choices:

- **No inbound connections to the home network.** The Pi only makes outbound HTTPS calls
  to steeman.be. No port forwarding, no dynamic DNS.
- **The label is rendered on the Pi**, as a PDF of exactly the label size, and sent to CUPS
  with no scaling. This avoids the browser print dialog (margins, scaling, rotation), which
  is the same class of problem the macro had to work around.
- **The web app is the single source of truth.** The spreadsheet is only an export.

---

## 4. Hosting and Data Storage

### 4.1 LWS constraints (known from the verba project, measured 2026-09-13)

| Item | Value | Consequence |
|------|-------|-------------|
| PHP | 8.4.25, `fpm-fcgi`, `argon2id` available | Password hashing with `password_hash()` (argon2id) |
| Database | MariaDB 10.11, **one schema** (`steem1482777`), shared with verba | Inventory tables use prefix `inv_` in the same schema |
| Connections | `MAX_USER_CONNECTIONS 8` (shared with verba!) | No persistent connections; connect late, release early; the Pi polls an endpoint that is cheap |
| FTP root = docroot | No folder outside the web root for secrets | `config.php` and `_backups/` must be protected with `.htaccess` deny rules, and this must be tested (§9.6) |
| PHP version | Only change via LWS panel, never `AddHandler` in `.htaccess` | A misconfiguration once served PHP source as plain text |
| Edge cache (Anubis) | Verba's API was not cached | Must be re-verified for `/inventory/` (Phase 1 check) |

### 4.2 Sharing the database with verba

The plan allows one database, so the inventory shares it with verba.

- All tables are named `inv_*`. No inventory code touches tables without that prefix, and
  verba's code never touches `inv_*`.
- Both apps use the same DB user, so this separation is by convention, not enforced by
  MariaDB. Accepted risk: it's a personal inventory with public data, so the impact is low.
- The weekly inventory backup only dumps `inv_*` tables.
- The connection limit (8) is shared. Inventory traffic is small (one user, one Pi poll
  every 5 s, each a single short query), so verba isn't expected to notice.

**Fallback:** if sharing turns out to be a problem, the inventory can use a **SQLite** file
instead (if `pdo_sqlite` is available, checked in Phase 1). That file would also sit inside
the web root, protected by `.htaccess`, so it carries the same source-leak risk as
`config.php`. That's why the shared MariaDB schema is preferred.

### 4.3 Phase 1 results (measured 2026-11-23, probe uploaded to `/inventory/_probe/` and removed)

| Check | Result |
|-------|--------|
| PHP | 8.4.28 `fpm-fcgi`, `argon2id` available, `display_errors` must be switched off in code (as in verba) |
| Extensions | `pdo_mysql`, `pdo_sqlite`, `zip`, `sodium`, `mbstring`, `intl`, `xmlwriter`, `dom` all present |
| ODS export | `ZipArchive` writes and reads back an ODS-style zip (stored `mimetype` first): **native `.ods` is possible**, no `.fods` fallback needed |
| Database | MariaDB 10.11.20. **One schema confirmed**: `CREATE DATABASE` is refused. Schema has 7 verba tables, no `inv_*` yet. `max_user_connections` = 8 |
| Search | `utf8mb4_unicode_ci` is case- and accent-insensitive (`cafe` finds `Café`); FULLTEXT indexes are allowed. Unicode (µ, ×, Ω) round-trips intact |
| `.htaccess` deny | `Require all denied` works: `.txt`, `.php` (not executed), `.ods` and `.htaccess` in a denied folder all return **403** |
| PHP source leak | No `<?php` in any response |
| Edge cache | Two calls gave different output, both `x-cache-status: MISS`; `Cache-Control: no-store` passes through |
| HTTPS | **Plain HTTP is not redirected** (returns 200) and no HSTS header is sent. The app's `.htaccess` must add the redirect and PHP must send HSTS (§9.3); `deploy.sh` checks it |
| `robots.txt` | Belongs to the Hugo site and gets overwritten on site deploys, so the app relies on `X-Robots-Tag: noindex, nofollow` + `<meta name="robots">` instead of editing it |
| Time zone | PHP default `Europe/Paris` (same offset as Brussels); the app sets `Europe/Brussels` explicitly |
| Limits | `memory_limit` 384M, `max_execution_time` 90 s, uploads up to 288M: plenty for import/restore |
| Disabled functions | `system`, `passthru`, `proc_open`, `popen` etc. are disabled: the app must not shell out (it doesn't need to) |
| DB credentials | The probe read them from verba's server-side `config.php`, so they never left the server. For the app, `inventory/config.php` gets its own copy (§9.5) |

**Phase 1 passed.** No blockers.

---

## 5. Data Model

### 5.1 `inv_items` (one row = one component = one label)

| Column | Type | Required | Printed | Notes |
|--------|------|----------|---------|-------|
| `id` | INT, auto | yes | no | |
| `box` | VARCHAR(8) | yes | yes | Text, so leading zeros (`03`) are kept |
| `bin` | VARCHAR(8) | yes | yes | Text |
| `label1` | VARCHAR(60) | yes | yes | Main description |
| `label2` | VARCHAR(60) | no | yes | |
| `label3` | VARCHAR(60) | no | yes | |
| `keywords` | VARCHAR(255) | no | no | Search only |
| `comments` | TEXT | no | no | Public (all inventory data is public, Q1) |
| `created_at`, `updated_at` | DATETIME | yes | no | |
| `last_printed_at` | DATETIME | no | no | Shows which labels were never printed |

Mandatory fields are the same as in the macro: Box, Bin, Label row 1.

Field length limits are a first guess. Phase 0 will show how many characters fit at each
font size, and the edit form will warn when a line probably won't fit on the label.

Box + Bin isn't unique: one bin can hold several components, each with its own label.

### 5.2 Other tables

| Table | Purpose |
|-------|---------|
| `inv_settings` | Label font sizes (Box, Bin, Label 1/2/3), layout parameters from Phase 0, print offset calibration |
| `inv_admin` | One row: password hash, password changed date |
| `inv_sessions` | Session token hashes, expiry, last seen |
| `inv_login_attempts` | Per-IP and global failed login counters for rate limiting |
| `inv_print_jobs` | Print queue (§8.3) |
| `inv_audit` | Every create/update/delete: timestamp, item id, old and new values (JSON). Lets you see and undo mistakes between weekly backups |
| `inv_agent` | Print agent status: last poll time, agent version, CUPS printer state |

---

## 6. Functional Requirements: Public Part

### 6.1 Search page (`/inventory/`)

- One search box, focused on load, works well on a phone.
- Searches across all fields: Box, Bin, Label 1 to 3, Keywords and Comments.
- Case-insensitive and accent-insensitive; every word in the query must match somewhere
  (e.g. `m3 screw` finds "Screws (M3 pan head)").
- Exact box lookups: a query like `03` or `03/02` lists the contents of box 03 (bin 02).
- Results show Box/Bin prominently (that's what you need when standing in the basement),
  followed by the three label rows. Keywords and Comments are shown too (smaller), since
  all inventory data is public.
- Results appear as you type (debounced). Without JavaScript, a normal form submit still
  works.
- Results are paginated (e.g. 50 per page). There's also an "all items" list sorted by
  Box, Bin.
- No login and no cookies on the public part.

### 6.2 Abuse protection for the public part

- Search requests are rate-limited per IP (e.g. 60/minute), returning HTTP 429 beyond that.
- No search engine indexing: `robots.txt` disallows `/inventory/`, and every page sends
  `X-Robots-Tag: noindex, nofollow`.

---

## 7. Functional Requirements: Admin Part

### 7.1 Login

- `/inventory/admin/` shows a login form: password only (single admin, no username).
- Session lasts 365 days of inactivity on a device (sliding; was 30 days in v0.2, changed at David's request), so you don't have to log in
  every time on your phone. There's a "log out everywhere" button.
- Security details in chapter 9.

### 7.2 Component management

- **Add**: form with the fields from §5.1. Box and Bin default to the values used last
  (fast entry when filling one box). Save and "Save + print label" buttons.
- **Edit**: same form. Shows a live label preview (§8.5).
- **Delete**: with confirmation. Logged in `inv_audit` and restorable from there.
- **Duplicate**: copy an item as a starting point for a similar one.
- Validation of mandatory fields, with clear messages (as in the macro, §7 of the old FSD).

### 7.3 Printing from the UI

- A Print button on each item, plus checkboxes for multi-select and "Print selected".
- Preview of every label in the job before it is sent.
- Fail-fast validation as in the macro: if any selected item is missing a mandatory field,
  nothing is queued and the problem rows are listed.
- Printer status always visible in the admin header: **online** (agent polled within the
  last 30 s), **offline** (with time last seen), or **printer error** (as reported by CUPS:
  out of labels, paper jam, not connected).
- Job status per job: queued → printing → printed / failed (with error text).

### 7.4 Settings

- Font sizes for Box, Bin, Label 1, 2, 3 (taken from the current Config sheet: 12, 12, 10, 8, 6).
- Print calibration: horizontal/vertical offset in 0.1 mm steps (values found in Phase 0).
- Change admin password.

### 7.5 Export and backups

- "Export now": downloads the current inventory as `.ods`.
- Backup list: shows the backups stored on LWS (date, weekly/monthly), downloadable.
- Last backup confirmations: when the Pi last fetched a backup (§10).

---

## 8. Label Printing

### 8.1 Label and printer

| Item | Value |
|------|-------|
| Printer | Dymo LabelWriter 450, USB, on the Pi Zero 2 W |
| Label | 12 × 50 mm, an EU variant of the Dymo 30334 (exact part number checked in Phase 0) |
| Driver | CUPS with `printer-driver-dymo` (Raspberry Pi OS Lite, 64-bit) |
| CUPS queue name | `LabelWriter-450` (same name as now) |

### 8.2 Layout (same as macro v1.4)

- The label feeds lengthwise, so it is generated as a portrait page (12 mm wide × 50 mm
  high) with the content rotated 90°, so it reads as a 50 × 12 mm landscape label.
- Box/Bin section: about 18% of the length, Box above Bin, centered, bold. Macro v1.4 puts it
  on the **right** as you read the label (`LabelPrintMacro.bas` line 448), while the older macro FSD
  says left. The side is a setting; Phase 0 (T3) compares with a real macro-printed label.
- Label section: about 78% of the length, three rows left-aligned, font sizes from settings.
- Text that doesn't fit is shrunk (down to a minimum size), then clipped. No wrapping.
- Font: DejaVu Sans (installed on the Pi, also used as a web font for the preview so both
  look the same).

The exact percentages, margins and offsets are fixed in Phase 0 and then stored in
`inv_settings`.

### 8.3 Print flow

1. Admin clicks Print. PHP validates the selection, and stores one `inv_print_jobs` row per
   label with a **snapshot** of the label text and the font settings (so a later edit can't
   change a queued job). Status `queued`.
2. The Pi agent polls `GET /inventory/api/agent/jobs` every 5 s with its agent token.
   It atomically claims queued jobs (status `printing`, claimed at).
3. The agent renders each label to PDF (Python, ReportLab) at exactly the label size, and
   prints it with `lp -d LabelWriter-450` with scaling off and the matching media size.
4. The agent watches the CUPS job until it is completed or failed, then reports
   `POST /inventory/api/agent/jobs/{id}` with status `printed` or `failed` + error text.
5. A job that stays `printing` for more than 2 minutes is marked `failed (agent timeout)`.
   It is **not** automatically retried, to avoid double labels. The admin can press
   "Print again".
6. Multi-label jobs are sent to CUPS as one multi-page PDF, so labels come out in one run.

### 8.4 Print agent (Pi)

- Python 3 script as a `systemd` service, restarts automatically, starts at boot.
- Only outbound HTTPS to steeman.be. The agent token is sent in a header and stored on the
  Pi in a file readable only by the service user.
- Every poll also reports a heartbeat: agent version and CUPS printer state (`lpstat`).
- When idle, a poll is a single cheap request (one indexed `SELECT`). Polling interval:
  5 s (about 17,000 small requests per day, acceptable for LWS). If LWS doesn't like that,
  it can go up to 15 s.
- Logs to the systemd journal, rotated.
- Wi-Fi only (Pi Zero 2 W). If the network is gone, the agent keeps retrying with backoff.
- Update procedure: `git pull` + `systemctl restart` (documented in README).

### 8.5 Preview in the browser

- The admin UI draws the label as SVG, using the same layout parameters and font as the Pi.
- The preview is close but not authoritative: the Pi's PDF is what gets printed. During
  Phase 2 the two are compared side by side, and parameters are tuned until they match.
- Optional: the agent can upload the rendered PDF of each printed job, so the exact output
  can be viewed afterwards (TBD, nice to have).

---

## 9. Security

The public search is open on purpose. Everything else must be properly secured.

### 9.1 Admin authentication

- Password stored as `password_hash()` (argon2id). Minimum 12 characters.
- Initial password set with a one-time setup script, not hard-coded and not in git.
- **Brute-force protection:**
  - per IP: after 5 failed attempts, lockout that doubles each time (1 min, 2, 4 ... up to 1 h);
  - global: after 50 failed attempts per hour from all IPs, login is blocked for 15 minutes
    (protects against attacks spread over many IPs). A successful login from your own
    device with a valid session isn't affected, because a session doesn't need a login.
  - Failed logins and lockouts are logged, and visible on the admin page.
- Login takes a minimum time (constant-time compare plus a small fixed delay), so timing
  doesn't reveal anything.

### 9.2 Sessions

- Random 256-bit token in a cookie: `Secure; HttpOnly; SameSite=Lax; Path=/inventory/`. Lax instead of Strict:
  Strict dropped the cookie when the site was opened from a link in another app, which looked like being logged out.
  Lax still never sends the cookie on cross-site POSTs.
- Only a hash of the token is stored server-side (`inv_sessions`), like verba.
- New token on login (no session fixation). Logout deletes the server-side session.
- Password change invalidates all sessions.

### 9.3 Requests

- CSRF token and an Origin/Referer check on every state-changing form/request (in addition to `SameSite=Lax`).
- All database access via PDO prepared statements.
- All output HTML-escaped. A strict Content-Security-Policy (no inline scripts, no third-
  party sources), `X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin`,
  `frame-ancestors 'none'`.
- HTTPS enforced (redirect + HSTS).
- `Cache-Control: no-store` on all admin and API responses.

### 9.4 Print agent API

- Separate long random agent token (not the admin password), stored hashed on the server.
- The token only gives access to: fetch/claim print jobs, report job status, heartbeat,
  and fetch backups (§10). It cannot read sessions or change items.
- Token can be rotated from the admin settings page.

### 9.5 Secrets

- DB credentials and the token pepper live in `inventory/config.php`, which only defines
  constants and outputs nothing. It is in `.gitignore`; `config.example.php` is in git.
- No passwords in the repo, the FSD, changelogs or deploy scripts. FTP uses `~/.netrc`.

### 9.6 Web-root protection (because FTP root = docroot)

- `.htaccess` with `Require all denied` on `config.php`, `lib/`, `_backups/`, `sql/`.
- Files that must never be served carry `.php` (so they would execute, not download), or
  live in denied folders.
- **Automated check after each deploy** (`deploy.sh`):
  - `config.php`, `lib/*.php` and a file in `_backups/` return 403/404;
  - no response body contains `<?php`;
  - admin pages return `Cache-Control: no-store`;
  - responses are not cached by the edge (`x-cache-status` not `HIT`).

### 9.7 Repo

- The repo is on GitHub (`steemandavid/inventory-management`). Nothing in it may be needed
  to break in: no tokens, no hashes, no real `config.php`.

---

## 10. Backups

### 10.1 What is backed up

Every week, a backup set with:

| File | Content | Purpose |
|------|---------|---------|
| `inventory_YYYY-MM-DD.ods` | All items, same columns as the current spreadsheet (Box, Bin, Label row 1 to 3, Keywords, Comments) + a Config sheet with the label settings | Human-readable, opens in LibreOffice/Excel |
| `inventory_YYYY-MM-DD.json` | All `inv_*` tables except sessions/login attempts/agent token, with a schema version | Exact, complete restore |
| `inventory_YYYY-MM-DD.sql` | MariaDB dump (added in v2.2): DROP + CREATE + INSERT for items, settings, audit and print jobs; only `CREATE TABLE IF NOT EXISTS` for admin, sessions, login attempts, rate and agent (no credentials) | Database restore with the `mysql` client, independent of the app |

The `.ods` is generated in PHP (ODS is a zip with XML files; needs `ZipArchive`, checked in
Phase 1). Fallback if `ZipArchive` is missing: flat ODS (`.fods`, a single XML file that
LibreOffice opens directly).

### 10.2 Schedule and flow

The Pi is the trigger (LWS cron availability is uncertain, and this way no LWS cron is needed):

1. **Sunday 04:00**, a systemd timer on the Pi calls `POST /inventory/api/agent/backup`
   with its agent token.
2. LWS generates the backup set, stores it in `inventory/_backups/` (copy 1, LWS), applies
   retention there, and returns the files (or download links valid for 10 minutes).
3. The Pi verifies the files (JSON parses, row count equals the count reported by the
   server, `.ods` is a valid zip) and stores them in `/var/lib/inventory-backups/` on the
   SD card (copy 2), then applies retention there.
4. **john-ai** pulls `/var/lib/inventory-backups/` from the Pi every night at **23:30**
   with `rsync` over SSH into **`/home/john/backups/inventory/`** (copy 3).
   - Why this folder: the nightly Borg run (`/usr/local/bin/backup-nightly.sh`, 00:00)
     backs up `/`, `/home` and `/var` into the System repo `/mnt/backup/john-ai` on the USB
     backup disk, but **excludes `/storage`** (and `/mnt`, `/tmp`). A folder under
     `/home/john` is owned by john, so the pull runs from john's own crontab without root,
     and it gets picked up by the next Borg run 30 minutes later.
   - From there it follows the existing chain: `backup-offsite-sync.sh` (02:00) rsyncs the
     Borg repos to the offsite backup Pi over Tailscale. Nothing in the backup project has
     to change.
   - Not used: `/storage/fileshare` (temporary share, and `/storage` isn't in Borg) and
     `/var/backups` (root-owned and managed by Debian's own rotation).
   - `/home` is 95% full (20 GB free), but a backup set is tens of KB, so that's fine.
   - Pull, not push: the Pi gets no credentials for john-ai.
   - The rsync key on john-ai is restricted on the Pi side (read-only, that folder only, via
     `rrsync -ro`), as on the offsite Pi.
   - The pull uses `rsync` **without `--delete`**, so the john-ai copy keeps everything it
     ever received, even if the Pi's SD card is wiped or replaced.
   - The staleness check could also look at the newest file in this folder (warn when it is
     older than 8 days; decided, Q5).
5. If the Sunday backup didn't happen, the Pi retries every hour for 24 h. After that a
   warning is shown in the admin UI. It could also go into the existing john-ai Monday
   backup summary email (decided, Q5).

### 10.3 Retention (same on LWS and on the Pi)

- **Weekly:** keep the 10 most recent weekly backups.
- **Monthly:** the first backup of each calendar month is marked as monthly and is **never
  deleted**.
- A backup can be weekly and monthly at the same time. It is deleted only when it is neither
  in the last 10 weeks nor a monthly backup.
- Size: a few hundred items make a backup set of tens of KB. Forever means about 12 sets a
  year, so storage is never an issue.

Borg on john-ai has its own retention (System repo: 3 daily, 2 weekly, 6 monthly), but
every snapshot contains the full folder with all monthly sets. So nothing is lost when Borg
prunes old snapshots. The john-ai folder applies no retention at all (pull without
`--delete`); at tens of KB per set it can grow forever.

### 10.4 Restore

- `tools/restore.php` (run from the admin UI, with password confirmation): upload a `.json`
  backup and replace all items. Before it runs, it automatically makes an extra backup of
  the current state.
- Restore from `.ods` is also supported (for when you've edited the spreadsheet by hand).
- Restore is tested in Phase 4 and documented in the README.

---

## 11. Phase 0: Print Feasibility Test (go / no-go)

**Goal:** prove that the Pi Zero 2 W can print labels on the Dymo LabelWriter 450 with the
correct layout, before any web development starts.

### 11.1 Setup

1. Flash Raspberry Pi OS Lite (64-bit, Bookworm or newer) on the Pi Zero 2 W. Set the
   hostname (`labelpi`), Wi-Fi and SSH key through Raspberry Pi Imager.
2. Install `cups`, `printer-driver-dymo`, `python3-reportlab`, `fonts-dejavu-core`.
3. Move the Dymo from the Windows PC to the Pi (USB OTG adapter on the Pi's data port).
4. Create the CUPS queue `LabelWriter-450` with the Dymo LW450 PPD.
5. Check which media sizes the PPD offers. If there's no exact 12 × 50 mm size, use or add a
   custom page size.

### 11.2 Test script

`tools/print_test.py` renders the 6 rows currently in `KelderInventory.ods` with the macro
v1.4 layout and the current Config font sizes (12/12/10/8/6), and can:

- write the PDF only (inspect on the PC);
- print it with configurable offset, media size and CUPS options;
- print a calibration label: a 1 mm grid / border lines at 0.5 mm from each edge, to
  measure the real printable area and offset.

### 11.3 Test steps

| # | Test | Pass criterion |
|---|------|----------------|
| T1 | Calibration label | Border lines visible on all four sides; printable area and offset measured and written down |
| T2 | One real label (row 1) | Correct orientation (reads left to right, Box/Bin on the agreed side), no scaling, nothing cut off |
| T3 | Layout comparison | Placed next to a label printed by the current macro: same layout, same or better readability |
| T4 | Longest text in the sheet ("Ring terminals / M4, red, 100 pcs, gold plated") | Fits or is shrunk legibly, not clipped mid-character |
| T5 | Batch of all 6 rows in one job | 6 labels, no blank labels in between, no drift (the last label is aligned like the first) |
| T6 | Repeatability | Same label printed 3 times at different moments (incl. after a Pi reboot): identical |
| T7 | Leading zeros and special characters (`03`, `×`, `µ`, `Ω`, `é`) | Printed correctly |
| T8 | Performance | Time from command to label < 5 s on the Pi Zero 2 W |

### 11.4 Decision

- **All of T1 to T7 pass:** go. The measured layout parameters go into the FSD (v0.2) and
  later into `inv_settings`.
- **Layout can't be made right after reasonable tuning** (orientation, scaling, cut-off
  text or drift that can't be compensated): no-go. The Dymo goes back to the Windows PC,
  and the project stops.
- **T8 fails, but quality is OK:** still go. Speed is not critical.

---

### 11.5 Results (2026-11-23): GO

Hardware: Raspberry Pi 1 Model B+ V1.2 (Raspbian 13 trixie, 32-bit, Ethernet, `labelpi`,
192.168.1.20) with the Dymo LabelWriter 450 on USB. The Zero 2 W was set aside (no OTG
adapter at hand). Label: **Dymo 99017 / S0722460 suspension file label, 12 × 50 mm**, CUPS
media `w36h144` (12.7 × 50.8 mm), queue `LabelWriter-450`, driver `dymo:0/cups/model/lw450.ppd`.

| # | Result | Notes |
|---|--------|-------|
| T1 | **Pass** | Scale exactly 1:1. Printable area in reading orientation: about 5.7 to 49.3 mm along the length, 1.2 to 11.6 mm across (matches the PPD's imageable area). The content must be rotated 270° (90° gave an upside-down label) |
| T2 | **Pass** | Correct orientation, Box/Bin on the right, no scaling, nothing cut off |
| T3 | **Pass** | No macro-printed label available for comparison; layout approved by David |
| T4 | **Pass** | "M4, red, 100 pcs, gold plated" fits (shrunk 8 to 6.4 pt) |
| T5 | **Pass** | 6 labels in one job, no blank labels, no drift from first to last |
| T6 | **Pass** | Same label printed in three jobs (incl. after a Pi reboot): identical |
| T7 | **Pass** | `03`, Ω, ±, µ, ×, é, è, ü, ß all correct |
| T8 | Fail, **accepted by David** | Pi 1: about 9 s for 1 label, 25 s for 6. Speed doesn't matter |

Resulting layout defaults (now in `agent/label_render.py`): `rotate=270`, margins left 6.5,
right 2.0, top 1.2, bottom 2.0 mm. The 5.7 mm dead zone at the left leaves about 42 mm of
length for text; typical font sizes after shrink-to-fit are 5.3 to 10 pt. The smallest
(about 5.3 pt) is still legible.

## 12. Implementation Phases

| Phase | Content | Done when |
|-------|---------|-----------|
| **0** | Print feasibility on the Pi (chapter 11) | Go/no-go decision made with you |
| **1** | Hosting checks: `pdo_mysql`, `ZipArchive`, `pdo_sqlite` (fallback), `.htaccess` deny on `/inventory/_backups/`, edge cache on `/inventory/`, no PHP source leak. Probe uploaded, then removed | All checks documented in this FSD |
| **2** | Web app: schema, import of `KelderInventory.ods`, public search, admin login, CRUD, settings, audit log, SVG preview, deploy script with security checks | Search works from phone; security checks in `deploy.sh` pass |
| **3** | Print queue API + Pi print agent as systemd service; printer status in UI | Print from phone (outside the LAN) produces a correct label |
| **4** | Backups: export (`.ods` + `.json`), Pi timer, retention on LWS and Pi, john-ai rsync pull into Borg, restore tool | A restore from each of the three locations has been tested |
| **5** | Documentation (README, CHANGELOG), retire the macro (mark as legacy in README) | |

### 12.0 Status (2026-11-23)

| Phase | Result |
|-------|--------|
| 0 | GO, see §11.5. Hardware ended up as a **Pi 1 Model B+** (32-bit Raspbian, Ethernet) instead of the Zero 2 W |
| 1 | Passed, see §4.3 |
| 2 | Live at https://www.steeman.be/inventory/. Admin password set by David via the token-protected `setup.php`, which was then removed. Extra requests implemented: "Save, print label, add another", "Print label" per item, Admin link at the top of the public page, back-to-top arrow. Items show "in print queue" / "printed <date>" |
| 3 | Print agent live on labelpi (systemd `inventory-agent`, user `invagent`, hardened). End-to-end print from the live site confirmed by David. The agent authenticates with an `X-Agent-Token` header (PHP-FPM may drop `Authorization`). Temporary passwordless sudo on the Pi removed |
| 4 | Weekly backup timer on labelpi (Sun 04:00, `Persistent=true`), retention identical on server and Pi (tested over 3 simulated years), john-ai pull at 23:30 with a key restricted to `rrsync -ro`, stale-backup warning via `/var/log/backup-warnings.log` into the Monday summary (Q5; the existing backup scripts were not modified). Restore of the live backup set (.json and .ods) tested on the dev environment |
| 5 | README rewritten for the web app, CHANGELOG 2.0.0, macro README moved to `legacy/` |
| 2.1 | Follow-up requests: renamed to "Inventory management", Admin link in the admin nav, Select all / Remove selection, green/red printer status, 365-day sessions (SameSite=Lax), dated file names in the backup list, cache busting for CSS/JS. Unauthenticated access to every admin page and action re-tested on the live site: all redirect to login, agent API 401 |

### 12.1 Tests

- PHP unit tests for search matching, validation, retention logic (which backups to keep:
  tested with a fixed list of dates spanning several years) and ODS generation.
- HTTP tests against a local PHP dev server (`php -S`) + local MariaDB: login lockout,
  CSRF rejection, session expiry, agent token scope (agent token can't edit items).
- Deploy checks on production (§9.6).
- Manual tests: print from phone over 4G, printer unplugged (error shown), Pi offline
  (offline shown, jobs time out cleanly), restore.

---

## 13. Repository Layout (proposal)

```
inventory-management/
├── web/                     → deployed to /inventory/ on LWS
│   ├── index.php            public search
│   ├── admin/               admin UI
│   ├── api/                 agent API (jobs, heartbeat, backup)
│   ├── lib/                 db, auth, csrf, ods export (denied by .htaccess)
│   ├── assets/              css, js, DejaVu web font
│   ├── config.example.php
│   └── .htaccess
├── agent/                   → deployed to the Pi
│   ├── print_agent.py
│   ├── label_render.py      shared layout code (also used by print_test.py)
│   ├── backup_fetch.py
│   └── systemd/             service + timer units
├── tools/
│   ├── print_test.py        Phase 0
│   ├── import_ods.py        one-time import of KelderInventory.ods
│   └── deploy.sh            FTPS upload + security checks
├── legacy/                  KelderInventory.ods, LabelPrintMacro.bas, generate_label.py
├── FSD_Web_Inventory.md
└── FSD_Label_Macro_12x50mm.md
```

---

## 14. Open Questions

| # | Question | Proposed default |
|---|----------|------------------|
| Q1 | ~~Should Comments be public?~~ | **Decided:** all inventory data is public, all fields are searched and shown |
| Q2 | ~~Can one Box/Bin hold several components?~~ | **Decided:** yes, no uniqueness constraint; each component gets its own label on the same bin |
| Q3 | ~~Search engine indexing?~~ | **Decided:** no. `robots.txt` disallow + `X-Robots-Tag: noindex` on every page |
| Q4 | ~~Which folder on john-ai, for the nightly rsync pull?~~ | **Decided:** `/home/john/backups/inventory/` (in the nightly Borg System repo, §10.2) |
| Q5 | ~~Report a missing weekly backup in the Monday backup summary email?~~ | **Decided:** yes |
| Q6 | ~~Web UI language?~~ | **Decided:** English |
| Q7 | ~~Tailscale on the Pi?~~ | **Decided:** only if needed. The Pi stays on the home LAN/Wi-Fi; john-ai reaches it over the LAN |
| Q8 | Label part number: 12 × 50 mm, an EU variant of the 30334 | Phase 0 finding: in the Linux Dymo driver, 30334 is 2-1/4 × 1-1/4 in (57 × 32 mm). The 12 mm candidates are `w36h144` (99017, 12.7 × 50.8 mm, printable length about 43.6 mm) and `w36h136` (30346, 12.7 × 48 mm). Check the part number on the label roll box |
| Q9 | ~~"Save + print" as default when adding?~~ | **Decided:** yes |

---

## 15. Risks

| Risk | Impact | Mitigation |
|------|--------|------------|
| Dymo layout can't be made right from the Pi | Project stops | Phase 0 first, before any other work |
| LW450 driver on 64-bit ARM has issues | Phase 0 fails or needs workarounds | `printer-driver-dymo` is open source and packaged for arm64; fallback 32-bit OS |
| Shared DB connection limit (8) with verba | Errors under load | Short connections, cheap polling, 503 + retry in the agent |
| `.htaccess` misconfiguration exposes `config.php` | DB credentials leak | Automated deploy checks (§9.6) |
| Pi Zero 2 W SD card wears out or dies | Loss of the Pi backup copy | Two other copies (LWS, Borg); SD card image documented; agent is stateless and reinstallable from git |
| LWS hosting disappears | Site down | Weekly backups on Pi and in Borg; restore on another PHP/MySQL host |
| Polling load not accepted by LWS | Throttling | Interval can be raised to 15 s, or changed to long-polling |

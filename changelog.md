# Changelog — Inventory management (KelderInventory)

Session log. Release notes are in `CHANGELOG.md`; the design and test results are in `FSD_Web_Inventory.md`.

## 2026-11-23 — From spreadsheet + macro to web app with Pi label printing (v2.0 / 2.1)

### Asked
Turn `KelderInventory.ods` into a web page to look up components anywhere; password-protected adding;
print labels on the Dymo with the correct layout. Decisions along the way (David):

| Topic | Decision |
|---|---|
| Hosting | LWS, https://www.steeman.be/inventory/ (same hosting as verba) |
| Access | search public (all fields, incl. comments), admin password only, no 2FA, no search engine indexing |
| Spreadsheet | replaced by the web app (single source of truth) |
| Printer | Dymo moves from the Windows PC to a dedicated Pi; the Zero 2 W had no OTG adapter, so a **Pi 1 Model B+** is used |
| Backups | weekly, .ods spreadsheet; on the Pi SD card, on LWS and in john-ai's Borg (offsite); keep 10 weekly + one per month forever; `/storage/fileshare` is temporary, never for backups |
| Go/no-go | Phase 0 print test first; stop the project if the layout can't be made right |

### System info
| Item | Value |
|---|---|
| Web | LWS, PHP 8.4.28 fpm, MariaDB 10.11.20, **one schema shared with verba** → tables `inv_*`, max 8 connections |
| DB credentials | not in this repo: production `config.php` reads them from `/verba/api/v1/config.php` on the server |
| Production config | `deploy/config.prod.php` (gitignored, holds the IP pepper) → uploaded as `inventory/config.php` |
| Pi | `labelpi`, 192.168.1.20 (DHCP reservation), Pi 1 B+ V1.2, Raspbian 13 trixie 32-bit, Ethernet, user `john` (SSH key only) |
| Printer | Dymo LabelWriter 450, USB `0922:0020`, CUPS queue `LabelWriter-450`, PPD `dymo:0/cups/model/lw450.ppd` |
| Label | Dymo 99017 / S0722460 suspension file, 12 × 50 mm → CUPS media `w36h144` (12.7 × 50.8 mm) |
| Agent | `/opt/inventory-agent`, service `inventory-agent` (user `invagent`), config `/etc/inventory-agent/{agent.conf,token}` |
| Pi backups | `/var/lib/inventory-backups`, `inventory-backup.timer` Sun 04:00 (Persistent) |
| john-ai copy | `/home/john/backups/inventory` (in the Borg System repo; `/storage` is excluded from Borg) |
| GitHub | `steemandavid/inventory-management` (public), master at `5fdd66d` + docs commit |

### 1. Phase 0: print feasibility (GO)
- Wrote `agent/label_render.py` (ReportLab, DejaVu Sans) and `tools/print_test.py` (sample labels,
  calibration label with mm rulers, every layout parameter on the command line, print log).
- Findings: the Linux driver's "30334" is 57 × 32 mm, not 12 mm; the roll is 99017 (`w36h144`). The
  printable area is only ~5.7–49.3 mm along the length and 1.2–11.6 mm across. Content must be rotated
  **270°** (90° printed upside down). No scaling (1:1).
- Final layout: rotate 270, margins left 6.5 / right 2.0 / top 1.2 / bottom 2.0 mm, Box/Bin right.
- T1–T7 passed (incl. 6-label batch without drift, reprint after reboot identical, Ω µ × é ß);
  T8 speed (9 s/label on the Pi 1) accepted.

### 2. Phase 1: hosting checks (probe uploaded and removed)
ZipArchive, pdo_sqlite, intl present; accent/case-insensitive collation; `.htaccess` deny works (403,
PHP not executed); no edge caching; `REMOTE_ADDR` is the real client IP; **plain HTTP was not redirected**
(now done in the app's `.htaccess`); `CREATE DATABASE` refused (one schema confirmed).

### 3. Phase 2–3: web app and print agent
- `web/`: public live search (all words must match; `03/02` = box/bin; 60/min/IP), admin with argon2id
  password, per-IP (5 failures → doubling lockout) and global (50/h) lockout, hashed session tokens,
  CSRF + origin check, strict CSP, add/edit/duplicate/delete, history + restore, settings, live SVG label
  preview, print queue with snapshots, `api/agent.php` (X-Agent-Token; `Authorization` may be dropped by
  PHP-FPM).
- One-time `setup.php` protected by a setup token; David set the password; setup files then deleted and
  the token removed from the config.
- `agent/print_agent.py`: polls every 5 s, prints per batch, waits for CUPS completion, reports
  printed/failed; stuck jobs time out after 2 min (no automatic retry → no double labels).
- `tools/deploy.sh`: FTPS upload (file by file, lftp hangs on LWS) + checks (HTTPS redirect, headers,
  blocked files, no PHP source, setup gone, no-store, no edge cache).

### 4. Phase 4: backups
- Server makes `inventory_YYYY-MM-DD_HHMM[_manual|_pre-restore].{ods,json}` in `_backups/`; the Pi's
  weekly job fetches, verifies (item count, ODS structure) and stores them; same retention on both
  (PHP and Python implementations compared over 3 simulated years).
- Admin → Backups: list with dated file names, download, Back up now, restore from .json or old-layout .ods
  (pre-restore backup first). Live backup set restored successfully into the dev environment.

### 5. Changes on john-ai (outside the repo)
| What | Where |
|---|---|
| Nightly pull 23:30 (user cron) | `~/bin/inventory-pull-backups` (= `tools/pull-backups.sh`), log `~/backups/inventory/.pull.log` |
| Pull key | `~/.ssh/inventory_backup_ed25519`; on the Pi in `~john/.ssh/authorized_keys` with `command="/usr/bin/rrsync -ro /var/lib/inventory-backups/",restrict` (shell and writes refused: tested) |
| Stale check 07:00 (root) | `/usr/local/bin/inventory-backup-check`, `/etc/cron.d/inventory-backup-check`: appends to `/var/log/backup-warnings.log` → appears in the Monday summary email. The john-ai-backup-strategy scripts were **not** modified |
| Dev environment | docker project `inventory-dev` (`dev/compose.yaml`), http://192.168.1.165:8095/inventory/, DB in tmpfs |

### 6. Follow-up requests (v2.1)
"Save, print label, add another"; Print label per item; Select all / Remove selection; Admin link in the
nav; back-to-top arrow; "in print queue" / "printed <date>" per item with auto-refresh; green/red printer
status; rename to "Inventory management"; sessions 365 days sliding with `SameSite=Lax`; dated file names
in the backup list. Live re-test: every admin page and action without login → redirect to login; agent API 401.

### Notes and gotchas
- **Browser cache:** LWS sends no cache headers for static files. Asset URLs now carry `?v=<mtime>` and
  `.htaccess` sets a 1-year cache. Before this, browsers kept the first `style.css` (arrow and green status
  were invisible).
- **Passwordless sudo on labelpi was removed** after setup: `agent/install.sh` now prompts for the Pi
  password (`ssh -t`). New agent tokens: see README (scp + `ssh -t ... sudo install`).
- The old macro on the Windows PC can no longer print (the Dymo is on the Pi).
- Git didn't store executable bits for the scripts at first; fixed in `5fdd66d`.
- Dev only: `web/_backups` must be writable by the container (`tests/test_web.py` does `chmod 777`).
- The seed/setup flow can't delete files in the dev bind mount (harmless; on LWS it works).
- Possible follow-up: David's last sentence about "direct ingelogd vanaf ..." was cut off; the 365-day
  Lax session is the current answer.

### Tests
`tests/test_web.py` 128/128, `tests/test_retention.py` 7/7, `tools/deploy.sh --check` all passed on the live site.

## 2026-11-23 — Pi enclosure / Dymo stand (`enclosure/`)

### Asked
An enclosure for labelpi (Pi 1 B+) with the Dymo LabelWriter 450's footprint, so the Dymo stands on it;
heat-set inserts with David's M2.5 x 5 Phillips screws; designed for Tinkercad / Bambu P1S.
Iterations based on David's feedback and photos of the Dymo underside and the Pi:

| Round | Change |
|---|---|
| 1 | Two parts (base + cover, screwed from below so the top panel stays flat), cable notches open at the bottom, cable spool at the front |
| 2 | Footprint measured from photos: 127 x 186 mm, corner radii 20 (back) / 30 (front); deeper port bay |
| 3 | Cables can't leave through the bottom: USB/RJ45 **flush with the back wall**, power hole for the micro-USB plug, cable pocket that opens to the back |
| 4 | **Pi was mirrored** in the model (found while checking David's question against the underside photo): with components up and ports back, RJ45 and power are on the right. Fixed. Pocket opening full width (35 x 28 mm); middle screw post moved to the left side wall opposite the right one; 5 screw posts |
| 5 | Inserts are M2.5 x 3 mm long x 3.5 mm OD: holes 3.2 mm, 4.5 mm deep (screw reaches ~4.2 mm); new test coupon 3.0 to 3.4 mm |
| 6 | Consistent names everywhere (base, cover, top panel, screw posts, Pi standoffs, port openings, power hole, pocket opening, port fillers, locating rim); `shell.stl` renamed `cover.stl` (geometry verified identical) |

### Result
| File | Notes |
|---|---|
| `enclosure/pi_enclosure.scad` | parametric model; `part=` cover / base / insert_test, plus preview parts |
| `enclosure/cover.stl` | printed upside down (top panel on the build plate), no supports |
| `enclosure/base.stl` | printed flat, no supports |
| `enclosure/insert_test.stl` | insert hole test coupon |
| `enclosure/README.md` | names, layout, print settings, assembly |

Copies (incl. preview PNGs) on the fileshare: `/storage/fileshare/pi-enclosure/` (`J:\pi-enclosure\`).
Committed as `9d37d2d`.

### Notes
- OpenSCAD 2021.01 was installed on john-ai (`apt install openscad`) to generate the STLs; previews rendered
  with `xvfb-run`. Meshes checked watertight with trimesh; positions verified by slicing the STLs.
- Port positions are from the official B+ drawing, not measured: David should print the insert coupon and
  the first 8 to 10 mm of `cover.stl` to check the port openings before the full print.
- The back corners have a 5 mm radius (Dymo ~20 mm) so the back wall is straight at the ports.
- Not printed yet; insert hole size still to be confirmed with the coupon.

## 2026-11-23 — Real labels (on hold), SQL backups, enclosure base fix

### 1. Real labels from `KelderInventory.ods` (on hold)
David asked to replace the 6 sample labels with the real ones from `/storage/fileshare/KelderInventory.ods`.
Dry run on dev (Admin → Backups → Restore with the .ods): 43 items, 81 empty bins (Box/Bin only) skipped.
Open questions sent to David before the live import: 09/42 to 09/48 used twice with different contents,
02/02 to 06/06 are the old sample texts, 01/01 twice (box-level labels?), column H "To be ordered" (Yes on
09/40 and 09/41) has no field in the app, empty bins, typos "Fastners" / "Tozx". **David postponed the import
until he has checked the sheet.** Live database untouched. Plan: cleaned .ods, re-test on dev, David restores
it via Admin → Backups → Restore (REPLACE); a pre-restore backup is made automatically.

### 2. Database file in every backup set (v2.2)
| What | Where |
|---|---|
| `write_sql()` | `web/lib/backup.php`: `SHOW CREATE TABLE` + INSERTs (100 rows per statement, `PDO::quote`); DROP + CREATE for `BACKUP_TABLES`, `CREATE TABLE IF NOT EXISTS` only for the credential tables; ends with `-- end of backup` |
| Set files | `BACKUP_EXTS = ods, json, sql`: listing, retention, download (`application/sql`), agent `backup` action |
| Pi | `agent/backup_fetch.py`: sql optional (old sets stay valid), checked for completeness and item count; retention removes .sql too |
| Tests | 135/135: dump downloads, has no credentials, loads into a scratch DB with identical checksums (except `last_backup_*`, saved after the dump), Pi verify rejects a truncated dump |
| Deploy | `tools/deploy.sh` to LWS, all checks passed; `_backups/*.sql` returns 403 |

The path to john-ai and offsite didn't change: `pull-backups.sh` rsyncs every file, Borg and the offsite sync
pick it up. Restore: `mysql --default-character-set=utf8mb4 <database> < inventory_....sql`.

**Pi update:** `agent/install.sh` doesn't work from Claude Code's `!` prompt (no tty, sudo can't ask the
password): David ran it from his own terminal. Not verified yet, because David then powered the Pi off
(`sudo poweroff`) to build it into the enclosure. **Check when it is back:** printer online in Admin, and
`/opt/inventory-agent/backup_fetch.py` contains `EXTS`. Until the Pi has the new version, it would reject
sets with a .sql (the Sunday 04:00 job would then retry hourly for 24 h and fail).

### 3. Enclosure base
Screw heads fell through the base. `screw_d` 2.9 → 2.7, counterbore `head_d` 5.6 → 3.4, `head_h` 2.2 → 1.0
(David's numbers), rubber feet recesses removed. Only `base.stl` changed (verified by slicing: 5 holes,
3.4 mm up to 1 mm, 2.7 mm above, no recesses). The screw now reaches ~3 mm into a screw post (the insert's
length). If the heads don't fit, try `head_d` 3.6. Copied to `J:\pi-enclosure\`. David is waiting for the
3D printer.

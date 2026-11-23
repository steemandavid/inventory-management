# Inventory management (KelderInventory)

Inventory of the basement lab: public component lookup, password-protected editing, and
12 × 50 mm Dymo labels printed from any device.

- **Public search:** https://www.steeman.be/inventory/
- **Admin:** https://www.steeman.be/inventory/admin/ (single password)
- **Spec:** [`FSD_Web_Inventory.md`](FSD_Web_Inventory.md) (design, security, test results)

The old LibreOffice spreadsheet + macro live in [`legacy/`](legacy/) and are no longer used.

## How it fits together

```
 browser ──HTTPS──► steeman.be/inventory (LWS: PHP 8.4 + MariaDB, tables inv_*)
                         ▲  print jobs, weekly backup (outbound HTTPS, every 5 s)
                         │
 labelpi (Pi 1 B+, 192.168.1.20) ── USB ── Dymo LabelWriter 450 (labels 99017, 12 × 50 mm)
     /var/lib/inventory-backups ◄── rsync pull 23:30 ── john-ai: /home/john/backups/inventory ── Borg 00:00 ── offsite
```

| Part | Where | What |
|------|-------|------|
| `web/` | LWS `/inventory/` | the web app (public search, admin, print queue, backups, agent API) |
| `agent/` | labelpi `/opt/inventory-agent` | print agent (systemd `inventory-agent`), weekly backup (`inventory-backup.timer`, Sun 04:00) |
| `tools/` | john-ai | deploy, import, Phase 0 print test, nightly backup pull, stale-backup check |
| `tests/` | john-ai | HTTP tests and retention tests against the local docker environment |
| `dev/` | john-ai | docker environment that mimics LWS: http://192.168.1.165:8095/inventory/ |
| `enclosure/` | 3D printer (Bambu P1S) | OpenSCAD model + STLs of the Pi enclosure that doubles as a stand for the Dymo (see `enclosure/README.md`) |

## Daily use

- **Find something:** type in the search box. Every word must match somewhere; case and accents don't matter.
  `03/02` (or `3-2`) shows box 03, bin 02; `03` puts box 03 first.
- **Add:** Admin → Add. *Save, print label, add another* is the fast way to fill a box.
- **Print:** *Print label* per item, or tick several (*Select all* / *Remove selection*) and *Print selected labels*. The printer status at the top
  of the admin links to the print queue (cancel, print again, errors).
- **Undo:** Admin → History lists every change; deleted items can be restored there.

## Operations

### Deploy the web app

```bash
tools/deploy.sh            # upload web/ over FTPS (~/.netrc) and run the security checks
tools/deploy.sh --check    # only the checks: HTTPS redirect, headers, blocked files, no PHP source, no cache
```

Asset URLs carry the file's modification time (`?v=`) and static files are cached for a year, so a
changed CSS/JS file reaches browsers at once.

`config.php` is never uploaded unless asked (`--config deploy/config.prod.php`). `deploy/` is not in git: it
holds the production config with the IP pepper. The database credentials are not in it; they're read on the
server from verba's config (one shared schema, FSD §4.2).

### Print agent on the Pi

```bash
agent/install.sh           # install/update code + systemd units (asks for the Pi's sudo password)
ssh john@192.168.1.20 'journalctl -u inventory-agent -f'          # watch it
```

New agent token (Admin → Settings → Print agent → Replace agent token), then on john-ai:

```bash
printf '%s' TOKEN > /tmp/t && scp /tmp/t john@192.168.1.20:t && rm /tmp/t
ssh -t john@192.168.1.20 'sudo install -m 640 -o root -g invagent t /etc/inventory-agent/token && rm t && sudo systemctl restart inventory-agent'
```

CUPS queue `LabelWriter-450`, media `w36h144`. Label layout (fonts, margins measured in Phase 0) is set in
Admin → Settings and sent with every print job.

### Backups

| Copy | Location | Retention |
|------|----------|-----------|
| 1 | LWS `inventory/_backups/` (blocked from the web; download in Admin → Backups) | 10 newest + first of every month forever |
| 2 | labelpi `/var/lib/inventory-backups/` | same |
| 3 | john-ai `/home/john/backups/inventory/` → Borg System repo → offsite Pi | everything ever pulled |

Each set is `inventory_YYYY-MM-DD_HHMM.ods` (opens in LibreOffice/Excel, old spreadsheet columns + Config sheet)
and `.json` (exact restore). If the newest set is older than 8 days, or the nightly pull fails for 2 days,
`/usr/local/bin/inventory-backup-check` (cron 07:00) adds a warning to the Monday backup summary email.

**Restore:** Admin → Backups → Restore: upload a `.json` (items + label settings) or an `.ods` in the old
spreadsheet layout. A backup of the current state is made first.

### Development and tests

```bash
docker compose -f dev/compose.yaml up -d --build   # local LWS look-alike (DB is in tmpfs)
python3 tests/test_web.py      # 128 HTTP tests; resets the dev database
python3 tests/test_retention.py  # server and Pi retention rules agree
```

The dev environment uses `web/config.php` (dev settings, not in git) and admin password `test-password-123`.

## Security summary

Public pages are read-only, cookie-free and rate-limited (60 searches/min/IP). The admin uses an argon2id
password with per-IP and global lockouts, hashed session tokens (`Secure; HttpOnly; SameSite=Lax`, 365 days sliding), CSRF
tokens plus origin checks, and a strict CSP. The Pi only makes outbound HTTPS calls with a token that can print
and fetch backups, nothing else. Details and test results: FSD chapter 9 and §4.3.

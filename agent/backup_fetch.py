#!/usr/bin/env python3
"""Weekly backup on the Pi (FSD §10.2): ask the site to make a backup set, verify it, store
it in /var/lib/inventory-backups and apply retention (10 newest sets + first set of every
month forever, the same rule as the server). Retries every hour for 24 hours.

Run by inventory-backup.timer (Sunday 04:00). Exit code 0 = stored, 1 = gave up.
"""

import base64
import io
import json
import os
import re
import sys
import time
import zipfile

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import print_agent  # noqa: E402  (config + API helper)

DEST = os.environ.get("INVENTORY_BACKUP_DIR", "/var/lib/inventory-backups")
KEEP_RECENT = 10
NAME_RE = re.compile(r"^(inventory_(\d{4}-\d{2}-\d{2})_(\d{2})(\d{2})(?:_[a-z0-9-]+)?)\.(ods|json|sql)$")
EXTS = ("ods", "json", "sql")


def backups_to_keep(dates):
    """dates: {base: 'YYYY-MM-DD HH:MM'}. Same rule as backups_to_keep() in web/lib/backup.php."""
    newest_first = sorted(dates, key=lambda b: (dates[b], b), reverse=True)
    keep = set(newest_first[:KEEP_RECENT])
    first_of_month = {}
    for base in newest_first:          # newest first: the last one seen per month is the first of it
        first_of_month[dates[base][:7]] = base
    return keep | set(first_of_month.values())


def list_sets(dest):
    sets = {}
    for name in os.listdir(dest):
        m = NAME_RE.match(name)
        if m:
            sets[m.group(1)] = f"{m.group(2)} {m.group(3)}:{m.group(4)}"
    return sets


def apply_retention(dest):
    sets = list_sets(dest)
    keep = backups_to_keep(sets)
    removed = []
    for base in sets:
        if base not in keep:
            for ext in EXTS:
                p = os.path.join(dest, f"{base}.{ext}")
                if os.path.exists(p):
                    os.remove(p)
            removed.append(base)
    return removed


def verify(data):
    """Check the set before storing it. Returns {name: bytes}."""
    files = {name: base64.b64decode(b64) for name, b64 in data["files"].items()}
    base = data["base"]
    required = {f"{base}.ods", f"{base}.json"}
    if not required <= set(files) <= required | {f"{base}.sql"} or not NAME_RE.match(f"{base}.ods"):
        raise ValueError(f"unexpected file names: {sorted(files)}")
    backup = json.loads(files[f"{base}.json"])
    n = len(backup["tables"]["inv_items"])
    if n != data["items"]:
        raise ValueError(f"json has {n} items, server reported {data['items']}")
    with zipfile.ZipFile(io.BytesIO(files[f"{base}.ods"])) as z:
        if z.namelist()[0] != "mimetype" or b"opendocument.spreadsheet" not in z.read("mimetype"):
            raise ValueError("ods is not a valid spreadsheet")
        rows = z.read("content.xml").count(b"<table:table-row>")
        if rows < n + 1:
            raise ValueError(f"ods has {rows} rows for {n} items")
    sql = files.get(f"{base}.sql")
    if sql is not None:
        if not sql.rstrip().endswith(b"-- end of backup") or b"CREATE TABLE `inv_items`" not in sql:
            raise ValueError("sql dump is incomplete")
        insert = sql.split(b"INSERT INTO `inv_items`")
        rows = sum(part.split(b";\n", 1)[0].count(b"\n(") for part in insert[1:])
        if rows != n:
            raise ValueError(f"sql has {rows} item rows, server reported {n}")
    return files


def store(files, dest):
    os.makedirs(dest, exist_ok=True)
    for name, content in files.items():
        tmp = os.path.join(dest, f".{name}.tmp")
        with open(tmp, "wb") as f:
            f.write(content)
            f.flush()
            os.fsync(f.fileno())
        os.chmod(tmp, 0o644)
        os.replace(tmp, os.path.join(dest, name))


def main():
    conf = print_agent.load_config()
    attempts = int(os.environ.get("INVENTORY_BACKUP_ATTEMPTS", "24"))
    for attempt in range(1, attempts + 1):
        try:
            data = print_agent.api(conf, "backup", {})
            files = verify(data)
            store(files, DEST)
            removed = apply_retention(DEST)
            print(f"stored {data['base']} ({data['items']} items); retention removed {len(removed)} old set(s)")
            with open(os.path.join(DEST, ".last-success"), "w") as f:
                f.write(time.strftime("%Y-%m-%d %H:%M:%S") + f" {data['base']}\n")
            return 0
        except Exception as e:  # noqa: BLE001
            print(f"attempt {attempt}/{attempts} failed: {e}", file=sys.stderr)
            if attempt < attempts:
                time.sleep(3600)
    return 1


if __name__ == "__main__":
    sys.exit(main())

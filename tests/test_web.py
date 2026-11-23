#!/usr/bin/env python3
"""HTTP tests for the web app against the local docker environment (dev/compose.yaml).

Run:  python3 tests/test_web.py [base_url]
It resets the database (inv_* tables) first, so never point it at production.
"""

import base64
import re
import subprocess
import sys
import os

import requests

BASE = (sys.argv[1] if len(sys.argv) > 1 else "http://192.168.1.165:8095/inventory").rstrip("/")
ORIGIN = re.match(r"https?://[^/]+", BASE).group(0)
TOKEN = "devsetuptoken-0123456789abcdef0123456789"
PASSWORD = "test-password-123"
HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(HERE, "..", "agent"))
os.environ.setdefault("INVENTORY_AGENT_CONF", "/nonexistent")
COMPOSE = os.path.join(HERE, "..", "dev", "compose.yaml")

results = []


def check(name, cond, detail=""):
    results.append((name, bool(cond)))
    print(("PASS " if cond else "FAIL ") + name + ("" if cond else f"  -> {detail}"))


def sql(statement):
    subprocess.run(["docker", "compose", "-f", COMPOSE, "exec", "-T", "db", "mariadb", "-uinventory",
                    "-pdevpassword", "inventory", "-e", statement], check=True, capture_output=True)


def csrf(html):
    m = re.search(r'name="csrf" value="([0-9a-f]{64})"', html)
    return m.group(1) if m else ""


def login(s, password=PASSWORD):
    return s.post(f"{BASE}/admin/login.php", data={"password": password}, headers={"Origin": ORIGIN},
                  allow_redirects=False)


def fresh_setup():
    # dev only: the web container (www-data) must be able to write backups in the bind mount
    os.chmod(os.path.join(HERE, "..", "web", "_backups"), 0o777)
    tables = ["inv_items", "inv_settings", "inv_admin", "inv_sessions", "inv_login_attempts", "inv_rate",
              "inv_audit", "inv_print_jobs", "inv_agent"]
    sql("DROP TABLE IF EXISTS " + ", ".join(tables))
    subprocess.run([sys.executable, os.path.join(HERE, "..", "tools", "import_ods.py")], check=True,
                   capture_output=True)
    s = requests.Session()
    r = s.post(f"{BASE}/setup.php", data={"token": TOKEN, "new1": PASSWORD, "new2": PASSWORD},
               headers={"Origin": ORIGIN}, allow_redirects=False)
    assert r.status_code == 303, r.status_code


def main():
    fresh_setup()
    anon = requests.Session()

    # --- public search -------------------------------------------------------------
    r = anon.get(f"{BASE}/")
    check("public page loads", r.status_code == 200)
    check("public page sets no cookies", not r.cookies and "set-cookie" not in r.headers)
    for h in ("content-security-policy", "x-content-type-options", "x-robots-tag", "referrer-policy", "x-frame-options"):
        check(f"header {h}", h in r.headers, r.headers)
    check("noindex header", "noindex" in r.headers.get("x-robots-tag", ""))
    check("csp has no unsafe-inline", "unsafe-inline" not in r.headers.get("content-security-policy", ""))

    def api(q):
        return anon.get(f"{BASE}/api/search.php", params={"q": q}).json()

    check("all 6 imported", api("")["total"] == 6)
    check("multi-word search 'm3 screw'", [i["label1"] for i in api("m3 screw")["items"]] == ["Screws (M3 pan head)"])
    check("case-insensitive 'CABLE'", api("CABLE")["total"] == 1)
    check("box/bin '3/2' (no leading zeros)", [(i["box"], i["bin"]) for i in api("3/2")["items"]] == [("03", "02")])
    check("box/bin '03-07'", [i["label1"] for i in api("03-07")["items"]] == ["Heat shrink tubing"])
    res = api("03")["items"]
    check("number '03' lists box 03 first", res and all(i["box"] == "03" for i in res[:2]), res)
    check("search in keywords/comments", api("random comment")["total"] == 6)
    check("LIKE wildcards are literal", api("%")["total"] == 0 and api("_")["total"] == 0)
    check("no match", api("zzzznotthere")["total"] == 0)

    # --- blocked files ------------------------------------------------------------------
    for path in ("config.php", "config.example.php", "lib/auth.php", "lib/", "sql/schema.sql", "setup-seed.json",
                 "_backups/", ".htaccess", "tests/test_web.py"):
        r = anon.get(f"{BASE}/{path}", allow_redirects=False)
        check(f"blocked: {path}", r.status_code in (403, 404), r.status_code)
        check(f"no php source: {path}", "<?php" not in r.text)

    # --- setup is closed now ---------------------------------------------------------------
    r = anon.get(f"{BASE}/setup.php", params={"token": TOKEN})
    check("setup refuses a second password", "already done" in r.text)
    check("setup without token is 404", anon.get(f"{BASE}/setup.php").status_code == 404)

    # --- auth ------------------------------------------------------------------------------
    r = anon.get(f"{BASE}/admin/", allow_redirects=False)
    check("admin requires login", r.status_code == 303 and "login.php" in r.headers.get("location", ""))
    r = login(requests.Session(), "wrong-password-1")
    check("wrong password 401", r.status_code == 401 and "Wrong password" in r.text)
    sql("DELETE FROM inv_login_attempts")

    admin = requests.Session()
    r = login(admin)
    check("login redirects to admin", r.status_code == 303)
    cookie = r.headers.get("set-cookie", "")
    check("session cookie HttpOnly", "httponly" in cookie.lower(), cookie)
    check("session cookie SameSite=Lax", "samesite=lax" in cookie.lower(), cookie)
    m = re.search(r"expires=([^;]+)", cookie, re.I)
    import email.utils, time as _t
    check("session cookie lasts ~1 year", m and email.utils.parsedate_to_datetime(m.group(1)).timestamp() - _t.time() > 360 * 86400, cookie)
    check("session cookie path /inventory/", "path=/inventory/" in cookie.lower(), cookie)
    r = admin.get(f"{BASE}/admin/")
    check("admin page after login", r.status_code == 200 and "6 items" in r.text)
    check("renamed to Inventory management", "Inventory management" in r.text and "Kelder" not in r.text)
    check("Admin link in admin nav", 'class="current-area" href="/inventory/admin/">Admin</a>' in r.text)
    check("select all / remove selection", 'id="select-all"' in r.text and 'id="select-none"' in r.text and "select.js" in r.text)
    check("assets versioned by mtime", re.search(r"style\.css\?v=\d{9,}", r.text))
    check("admin no-store", "no-store" in r.headers.get("cache-control", ""))

    # --- CSRF ---------------------------------------------------------------------------
    page = admin.get(f"{BASE}/admin/edit.php").text
    token = csrf(page)
    item = {"box": "07", "bin": "03", "label1": "Resistors 4.7 kΩ ±1%", "label2": "Café", "label3": "",
            "keywords": "weerstand", "comments": "line1\nline2"}
    r = admin.post(f"{BASE}/admin/edit.php", data=item, headers={"Origin": ORIGIN}, allow_redirects=False)
    check("POST without csrf refused", r.status_code == 403)
    r = admin.post(f"{BASE}/admin/edit.php", data={**item, "csrf": token}, headers={"Origin": "https://evil.example"},
                   allow_redirects=False)
    check("POST from other origin refused", r.status_code == 403)
    r = anon.post(f"{BASE}/admin/edit.php", data={**item, "csrf": token}, headers={"Origin": ORIGIN},
                  allow_redirects=False)
    check("POST without session refused", r.status_code == 303 and "login" in r.headers.get("location", ""))

    # --- create / validate / update / delete / restore ---------------------------------
    r = admin.post(f"{BASE}/admin/edit.php", data={**item, "csrf": token}, headers={"Origin": ORIGIN},
                   allow_redirects=False)
    check("create item", r.status_code == 303)
    check("accent-insensitive 'cafe'", api("cafe")["total"] == 1)
    check("unicode round trip", api("weerstand")["items"][0]["label1"] == "Resistors 4.7 kΩ ±1%")
    check("defaults to last box/bin", 'value="07"' in admin.get(f"{BASE}/admin/edit.php").text)

    r = admin.post(f"{BASE}/admin/edit.php", data={**item, "label1": "  ", "csrf": token}, headers={"Origin": ORIGIN})
    check("missing label1 rejected", "Label row 1 is required" in r.text)
    r = admin.post(f"{BASE}/admin/edit.php", data={**item, "box": "123456789", "csrf": token}, headers={"Origin": ORIGIN})
    check("too-long box rejected", "at most 8" in r.text)

    xss = {**item, "label1": "<script>alert(1)</script>", "keywords": "xsstest", "csrf": token}
    admin.post(f"{BASE}/admin/edit.php", data=xss, headers={"Origin": ORIGIN})
    r = anon.get(f"{BASE}/", params={"q": "xsstest"})
    check("XSS escaped on public page", "<script>alert(1)</script>" not in r.text and "&lt;script&gt;" in r.text)
    xid = re.search(r"edit\.php\?id=(\d+)", admin.get(f"{BASE}/admin/", params={"q": "xsstest"}).text).group(1)

    r = admin.post(f"{BASE}/admin/edit.php?id={xid}", data={**xss, "label1": "Fixed name"}, headers={"Origin": ORIGIN},
                   allow_redirects=False)
    check("update item", r.status_code == 303 and api("xsstest")["items"][0]["label1"] == "Fixed name")

    page = admin.get(f"{BASE}/admin/delete.php?id={xid}").text
    check("delete asks confirmation", "Delete this component?" in page)
    r = admin.post(f"{BASE}/admin/delete.php?id={xid}", data={"id": xid, "csrf": token}, headers={"Origin": ORIGIN},
                   allow_redirects=False)
    check("delete item", r.status_code == 303 and api("xsstest")["total"] == 0)
    hist = admin.get(f"{BASE}/admin/audit.php").text
    check("history shows update diff", "Fixed name" in hist and "→" in hist)
    aid = re.search(r'name="audit_id" value="(\d+)"', hist).group(1)
    admin.post(f"{BASE}/admin/audit.php", data={"action": "restore", "audit_id": aid, "csrf": token},
               headers={"Origin": ORIGIN})
    check("restore deleted item", api("xsstest")["total"] == 1)

    # --- settings --------------------------------------------------------------------------
    page = admin.get(f"{BASE}/admin/settings.php").text
    check("settings page", "Label layout" in page and 'value="6.5"' in page)
    form = {"action": "layout", "csrf": token, "font_box": "12", "font_bin": "12", "font_label1": "10",
            "font_label2": "8", "font_label3": "6", "margin_left_mm": "6,5", "margin_right_mm": "2",
            "margin_top_mm": "1.2", "margin_bottom_mm": "99", "boxbin_side": "right"}
    r = admin.post(f"{BASE}/admin/settings.php", data=form, headers={"Origin": ORIGIN})
    check("settings validation", "Bottom margin (mm) must be" in r.text)
    form["margin_bottom_mm"] = "2.0"
    r = admin.post(f"{BASE}/admin/settings.php", data=form, headers={"Origin": ORIGIN}, allow_redirects=False)
    check("settings saved (comma decimal ok)", r.status_code == 303)

    # --- print queue + agent API ---------------------------------------------------------
    page = admin.get(f"{BASE}/admin/").text
    check("print label link on cards", "Print label" in page and "Print selected labels" in page)
    check("admin link at top of public page", 'href="/inventory/admin/">Admin</a></nav>' in anon.get(f"{BASE}/").text)
    check("back-to-top arrow", 'class="totop"' in anon.get(f"{BASE}/").text)
    check("printer never connected shown", "Printer not connected" in page)
    ids = re.findall(r'name="ids\[\]" value="(\d+)"', page)
    r = admin.post(f"{BASE}/admin/print.php", data={"id": ids[0], "back": "admin/?q=03", "csrf": token},
                   headers={"Origin": ORIGIN}, allow_redirects=False)
    check("print one label redirects back", r.status_code == 303 and r.headers["location"].endswith("admin/?q=03"))
    r = admin.post(f"{BASE}/admin/print.php", data={"ids[]": ids[:3], "back": "https://evil.example/", "csrf": token},
                   headers={"Origin": ORIGIN}, allow_redirects=False)
    check("print selected (bad back url ignored)", r.status_code == 303 and r.headers["location"].endswith("/inventory/admin/"))
    page = admin.get(f"{BASE}/admin/jobs.php").text
    check("4 jobs queued", page.count(">queued<") == 4, page.count(">queued<"))
    page = admin.get(f"{BASE}/admin/").text
    check("queued items show 'in print queue' + auto-refresh", "in print queue" in page and "refresh.js" in page)
    r = admin.post(f"{BASE}/admin/print.php", data={"id": "999999", "csrf": token}, headers={"Origin": ORIGIN})
    check("print missing item refused", "Nothing was printed" in r.text)
    r = admin.post(f"{BASE}/admin/edit.php", data={**item, "label1": "Fuses 5x20", "next": "print_another", "csrf": token},
                   headers={"Origin": ORIGIN}, allow_redirects=False)
    check("save, print, add another", r.status_code == 303 and r.headers["location"].endswith("admin/edit.php"))
    check("5 jobs queued", admin.get(f"{BASE}/admin/jobs.php").text.count(">queued<") == 5)

    agent_url = f"{BASE}/api/agent.php"
    r = requests.post(agent_url + "?action=poll", json={})
    check("agent API without token 401", r.status_code == 401)
    r = admin.post(f"{BASE}/admin/settings.php", data={"action": "agent_token", "csrf": token}, headers={"Origin": ORIGIN})
    agent_token = re.search(r'<code class="token">([0-9a-f]{64})</code>', r.text).group(1)
    check("agent token shown once", agent_token and agent_token not in admin.get(f"{BASE}/admin/settings.php").text)
    hdr = {"X-Agent-Token": agent_token}
    check("agent token is no admin session", requests.get(f"{BASE}/admin/", headers=hdr, allow_redirects=False).status_code == 303)
    check("agent API GET refused", requests.get(agent_url + "?action=poll", headers=hdr).status_code == 405)
    r = requests.post(agent_url + "?action=poll", headers=hdr, json={"version": "test-1", "printer": "idle"})
    jobs = r.json().get("jobs", [])
    check("agent claims 5 jobs", r.status_code == 200 and len(jobs) == 5, r.text[:200])
    check("job payload has layout", jobs and jobs[0]["label"]["layout"]["rotate"] == 270 and jobs[0]["label"]["box"])
    r = requests.post(agent_url + "?action=poll", headers=hdr, json={})
    check("second poll gets nothing (no double print)", r.json()["jobs"] == [])
    check("printer online in header", "Printer online" in admin.get(f"{BASE}/admin/").text)
    r = requests.post(agent_url + "?action=report", headers=hdr, json={"id": jobs[0]["id"], "claim": "0" * 16, "status": "printed"})
    check("report with wrong claim refused", r.status_code == 409)
    never_before = admin.get(f"{BASE}/admin/").text.count("never printed")
    r = requests.post(agent_url + "?action=report", headers=hdr, json={"id": jobs[0]["id"], "claim": jobs[0]["claim"], "status": "printed"})
    check("report printed", r.status_code == 200)
    r = requests.post(agent_url + "?action=report", headers=hdr, json={"id": jobs[1]["id"], "claim": jobs[1]["claim"], "status": "failed", "error": "out of labels"})
    check("report failed", r.status_code == 200)
    page = admin.get(f"{BASE}/admin/jobs.php").text
    check("jobs page shows printed/failed", ">printed<" in page and "out of labels" in page and "Print again" in page)
    sql("UPDATE inv_print_jobs SET claimed_at = NOW() - INTERVAL 1 DAY WHERE status = 'printing'")
    page = admin.get(f"{BASE}/admin/jobs.php").text
    check("stuck jobs time out as failed", "agent timeout" in page)
    page = admin.get(f"{BASE}/admin/").text
    check("printed item shows print date", re.search(r"printed \d\d-\d\d \d\d:\d\d", page))
    check("no auto-refresh when queue empty", "refresh.js" not in page)
    admin.post(f"{BASE}/admin/print.php", data={"id": ids[0], "csrf": token}, headers={"Origin": ORIGIN})
    admin.post(f"{BASE}/admin/jobs.php", data={"action": "cancel_all", "csrf": token}, headers={"Origin": ORIGIN})
    check("cancel all queued", ">queued<" not in admin.get(f"{BASE}/admin/jobs.php").text)

    # --- backups (Phase 4) ------------------------------------------------------------------
    import glob, io, shutil, tempfile, zipfile
    bdir = os.path.join(HERE, "..", "web", "_backups")
    for f in glob.glob(os.path.join(bdir, "inventory_*")):
        os.remove(f)
    page = admin.get(f"{BASE}/admin/backups.php").text
    check("backups page warns: never run", "has not run yet" in page)
    r = admin.post(f"{BASE}/admin/backups.php", data={"action": "backup_now", "csrf": token}, headers={"Origin": ORIGIN},
                   allow_redirects=False)
    check("back up now", r.status_code == 303)
    page = admin.get(f"{BASE}/admin/backups.php").text
    names = re.findall(r"download=(inventory_[\w-]+\.(?:ods|json|sql))", page)
    check("backup set listed (ods + json + sql)", len(names) == 3 and "(manual)" in page, names)
    sql_name = [n for n in names if n.endswith(".sql")][0]
    r = admin.get(f"{BASE}/admin/backups.php", params={"download": sql_name})
    dump = r.content
    check("download sql", r.status_code == 200 and "application/sql" in r.headers.get("content-type", "")
          and dump.rstrip().endswith(b"-- end of backup"))
    check("sql dump has no credentials", b"password_hash" in dump and b"$argon2" not in dump
          and b"INSERT INTO `inv_admin`" not in dump and b"INSERT INTO `inv_agent`" not in dump
          and b"INSERT INTO `inv_sessions`" not in dump)
    check("sql dump keeps other tables", b"CREATE TABLE IF NOT EXISTS `inv_admin`" in dump
          and b"DROP TABLE IF EXISTS `inv_admin`" not in dump and b"DROP TABLE IF EXISTS `inv_items`" in dump)
    # load the dump into a scratch database and compare with the live tables
    root = ["docker", "compose", "-f", COMPOSE, "exec", "-T", "db", "mariadb", "-uroot", "-pdevroot"]
    subprocess.run(root + ["-e", "DROP DATABASE IF EXISTS restoretest; CREATE DATABASE restoretest"], check=True, capture_output=True)
    loaded = subprocess.run(root + ["--default-character-set=utf8mb4", "restoretest"], input=dump, capture_output=True)
    check("sql dump loads into MariaDB", loaded.returncode == 0, loaded.stderr[:300])
    def checksum(db):
        # last_backup_* is saved after the dump is written, so it differs by design
        q = (f"CHECKSUM TABLE {db}.inv_items, {db}.inv_audit, {db}.inv_print_jobs; "
             f"SELECT MD5(GROUP_CONCAT(name, '=', value ORDER BY name)) FROM {db}.inv_settings WHERE name NOT LIKE 'last\\_backup%'")
        out = subprocess.run(root + ["-N", "-e", q], capture_output=True, text=True, check=True).stdout
        return [line.split("\t")[-1] for line in out.strip().splitlines()]
    check("restored sql identical to live data", checksum("restoretest") == checksum("inventory"),
          (checksum("restoretest"), checksum("inventory")))
    subprocess.run(root + ["-e", "DROP DATABASE restoretest"], check=True, capture_output=True)
    ods_name = [n for n in names if n.endswith(".ods")][0]
    r = admin.get(f"{BASE}/admin/backups.php", params={"download": ods_name})
    check("download ods", r.status_code == 200 and "attachment" in r.headers.get("content-disposition", ""))
    z = zipfile.ZipFile(io.BytesIO(r.content))
    check("ods: mimetype first and stored", z.infolist()[0].filename == "mimetype" and z.infolist()[0].compress_type == 0)
    if shutil.which("soffice"):
        with tempfile.TemporaryDirectory() as tmp:
            p = os.path.join(tmp, "b.ods")
            open(p, "wb").write(r.content)
            subprocess.run(["soffice", "--headless", "--convert-to", "csv", "--outdir", tmp, p], capture_output=True, timeout=120)
            csv_text = open(os.path.join(tmp, "b.csv"), encoding="utf-8").read()
        check("LibreOffice opens ods, leading zeros kept", csv_text.startswith("Box,Bin,Label row 1") and "03,02,Screws" in csv_text, csv_text[:120])
    for bad in ("../config.php", "inventory_2026-01-01_0000.php", "/etc/passwd", ".htaccess"):
        check(f"download refuses {bad}", admin.get(f"{BASE}/admin/backups.php", params={"download": bad}).status_code == 404)
    check("backup files not public", anon.get(f"{BASE}/_backups/{ods_name}").status_code == 403)
    check("download needs login", anon.get(f"{BASE}/admin/backups.php", params={"download": ods_name}, allow_redirects=False).status_code == 303)

    # retention on the server: 15 old weekly sets + 2 in an older month
    for d in ["2026-01-04", "2026-01-11"] + [f"2026-0{m}-{dd:02d}" for m in (5, 6, 7) for dd in (3, 10, 17, 24, 31) if not (m == 6 and dd == 31)]:
        for ext in ("ods", "json"):
            open(os.path.join(bdir, f"inventory_{d}_0400.{ext}"), "w").write("{}")
    r = requests.post(agent_url + "?action=backup", headers=hdr, json={})
    data = r.json()
    check("agent backup action", r.status_code == 200 and data["items"] >= 6 and len(data["files"]) == 3)
    left = sorted({re.sub(r"\.(ods|json|sql)$", "", os.path.basename(f)) for f in glob.glob(os.path.join(bdir, "inventory_*"))})
    months = {n[10:17] for n in left}
    check("server retention: 10 newest + firsts of month", len(left) == 13 and "inventory_2026-06-03_0400" in left and "inventory_2026-01-04_0400" in left
          and "inventory_2026-01-11_0400" not in left and months >= {"2026-01", "2026-05"}, left)
    import backup_fetch
    files = backup_fetch.verify(data)
    check("Pi-side verify accepts the set", len(files) == 3)
    old = dict(data, files={k: v for k, v in data["files"].items() if not k.endswith(".sql")})
    check("Pi-side verify accepts an old set without sql", len(backup_fetch.verify(old)) == 2)
    sql_key = data["base"] + ".sql"
    cut = base64.b64decode(data["files"][sql_key])[:-200]
    try:
        backup_fetch.verify(dict(data, files=dict(data["files"], **{sql_key: base64.b64encode(cut).decode()})))
        check("Pi-side verify rejects a truncated sql", False)
    except ValueError:
        check("Pi-side verify rejects a truncated sql", True)
    bad = dict(data, items=data["items"] + 1)
    try:
        backup_fetch.verify(bad)
        check("Pi-side verify rejects wrong count", False)
    except ValueError:
        check("Pi-side verify rejects wrong count", True)
    check("backups page shows last fetch", "last fetched" in admin.get(f"{BASE}/admin/backups.php").text)

    # restore from the .json made above, after deleting an item
    json_name = data["base"] + ".json"
    backup_json = base64.b64decode(data["files"][json_name])
    n_before = api("")["total"]
    admin.post(f"{BASE}/admin/delete.php?id={ids[0]}", data={"id": ids[0], "csrf": token}, headers={"Origin": ORIGIN})
    r = admin.post(f"{BASE}/admin/backups.php", data={"action": "restore", "confirm": "nope", "csrf": token},
                   files={"file": (json_name, backup_json)}, headers={"Origin": ORIGIN})
    check("restore needs REPLACE", "Type REPLACE" in r.text and api("")["total"] == n_before - 1)
    r = admin.post(f"{BASE}/admin/backups.php", data={"action": "restore", "confirm": "REPLACE", "csrf": token},
                   files={"file": (json_name, backup_json)}, headers={"Origin": ORIGIN}, allow_redirects=False)
    check("restore from json", r.status_code == 303 and api("")["total"] == n_before)
    check("pre-restore backup made", "(before restore)" in admin.get(f"{BASE}/admin/backups.php").text)
    ods = open(os.path.join(HERE, "..", "legacy", "KelderInventory.ods"), "rb").read()
    r = admin.post(f"{BASE}/admin/backups.php", data={"action": "restore", "confirm": "REPLACE", "csrf": token},
                   files={"file": ("KelderInventory.ods", ods)}, headers={"Origin": ORIGIN}, allow_redirects=False)
    check("restore from the old spreadsheet (.ods)", r.status_code == 303 and api("")["total"] == 6
          and api("3/2")["items"][0]["box"] == "03")
    r = admin.post(f"{BASE}/admin/backups.php", data={"action": "restore", "confirm": "REPLACE", "csrf": token},
                   files={"file": ("x.json", b'{"hello": 1}')}, headers={"Origin": ORIGIN})
    check("restore rejects foreign json", "not a backup" in r.text and api("")["total"] == 6)
    for f in glob.glob(os.path.join(bdir, "inventory_*")):
        os.remove(f)

    # --- password change logs out other sessions ---------------------------------------
    other = requests.Session()
    login(other)
    check("second device logged in", other.get(f"{BASE}/admin/", allow_redirects=False).status_code == 200)
    newpw = "another-password-456"
    r = admin.post(f"{BASE}/admin/settings.php", data={"action": "password", "csrf": token, "current": PASSWORD,
                   "new1": newpw, "new2": newpw}, headers={"Origin": ORIGIN}, allow_redirects=False)
    check("password changed", r.status_code == 303)
    check("other device logged out", other.get(f"{BASE}/admin/", allow_redirects=False).status_code == 303)
    check("this device still logged in", admin.get(f"{BASE}/admin/", allow_redirects=False).status_code == 200)
    r = admin.post(f"{BASE}/admin/settings.php", data={"action": "password", "csrf": csrf(admin.get(f"{BASE}/admin/settings.php").text),
                   "current": newpw, "new1": "short", "new2": "short"}, headers={"Origin": ORIGIN})
    check("short password rejected", "at least 12" in r.text)

    # --- logout ----------------------------------------------------------------------------
    old_cookie = admin.cookies.get("inv_session")
    token = csrf(admin.get(f"{BASE}/admin/").text)
    admin.post(f"{BASE}/admin/logout.php", data={"csrf": token}, headers={"Origin": ORIGIN})
    s2 = requests.Session()
    s2.cookies.set("inv_session", old_cookie, path="/inventory/")
    check("old session cookie dead after logout", s2.get(f"{BASE}/admin/", allow_redirects=False).status_code == 303)

    # --- login lockout ------------------------------------------------------------------
    sql("DELETE FROM inv_login_attempts")
    s = requests.Session()
    for _ in range(5):
        login(s, "wrong-password-x")
    r = login(s, newpw)
    check("locked after 5 failures (even right password)", "Too many failed attempts" in r.text, r.status_code)
    sql("DELETE FROM inv_login_attempts")
    check("unlocks after reset", login(requests.Session(), newpw).status_code == 303)
    import datetime
    stamp = datetime.datetime.now().strftime("%Y-%m-%d %H:%M:%S")  # app time (Europe/Brussels), not DB UTC
    sql(f"INSERT INTO inv_login_attempts (ip_hash, at, success) SELECT SHA2(seq, 256), '{stamp}', 0 FROM seq_1_to_50")
    r = login(requests.Session(), newpw)
    check("global lockout after 50 failures from many IPs", "Too many failed attempts" in r.text)
    sql("DELETE FROM inv_login_attempts")

    # --- rate limit (last: it blocks this IP for the rest of the minute) ---------------------
    sql("DELETE FROM inv_rate")
    codes = [anon.get(f"{BASE}/api/search.php", params={"q": "x"}).status_code for _ in range(62)]
    check("search rate limit 60/min", codes[:60].count(200) == 60 and codes[-1] == 429, codes[-5:])
    sql("DELETE FROM inv_rate")

    failed = [n for n, ok in results if not ok]
    print(f"\n{len(results) - len(failed)}/{len(results)} passed")
    sys.exit(1 if failed else 0)


if __name__ == "__main__":
    main()

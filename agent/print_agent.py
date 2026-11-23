#!/usr/bin/env python3
"""Print agent for the Raspberry Pi (FSD §8.3, §8.4).

Polls https://www.steeman.be/inventory/api/agent.php every few seconds (outbound HTTPS
only), renders claimed labels with label_render.py, prints them with CUPS and reports
each job back as printed or failed. Runs as a systemd service (agent/systemd/).

Config: /etc/inventory-agent/agent.conf (KEY=value lines), token in
/etc/inventory-agent/token (readable only by the service user).
"""

import json
import logging
import os
import re
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.request

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from label_render import Label, Layout, render_pdf  # noqa: E402

VERSION = "1.0.0"
CONF_DIR = os.environ.get("INVENTORY_AGENT_CONF", "/etc/inventory-agent")
DEFAULTS = {
    "API_URL": "https://www.steeman.be/inventory/api/agent.php",
    "PRINTER": "LabelWriter-450",
    "MEDIA": "w36h144",
    "POLL_SECONDS": "5",
    "PRINT_TIMEOUT_SECONDS": "90",
}
LAYOUT_KEYS = {"font_box", "font_bin", "font_label1", "font_label2", "font_label3", "margin_left_mm",
               "margin_right_mm", "margin_top_mm", "margin_bottom_mm", "rotate", "boxbin_side"}

log = logging.getLogger("inventory-agent")


def load_config():
    conf = dict(DEFAULTS)
    path = os.path.join(CONF_DIR, "agent.conf")
    if os.path.exists(path):
        with open(path) as f:
            for line in f:
                line = line.strip()
                if line and not line.startswith("#") and "=" in line:
                    k, v = line.split("=", 1)
                    conf[k.strip()] = v.strip()
    with open(os.path.join(CONF_DIR, "token")) as f:
        conf["TOKEN"] = f.read().strip()
    if not re.fullmatch(r"[0-9a-f]{64}", conf["TOKEN"]):
        raise SystemExit("token file does not contain a 64-character hex token")
    return conf


def api(conf, action, payload):
    req = urllib.request.Request(
        f"{conf['API_URL']}?action={action}",
        data=json.dumps(payload).encode(),
        headers={"Content-Type": "application/json", "X-Agent-Token": conf["TOKEN"],
                 "User-Agent": f"inventory-agent/{VERSION}"},
        method="POST",
    )
    with urllib.request.urlopen(req, timeout=30) as r:
        return json.loads(r.read().decode())


def printer_state(conf):
    try:
        out = subprocess.run(["lpstat", "-p", conf["PRINTER"]], capture_output=True, text=True, timeout=15)
        text = (out.stdout or out.stderr).strip().splitlines()
        state = " ".join(line.strip() for line in text)[:200]
        if not os.path.exists("/dev/usb/lp0") and not _usb_dymo_present():
            state = "Dymo not connected (USB). " + state
        return state
    except Exception as e:  # noqa: BLE001
        return f"lpstat failed: {e}"


def _usb_dymo_present():
    try:
        out = subprocess.run(["lsusb"], capture_output=True, text=True, timeout=10).stdout
        return "0922:" in out
    except Exception:  # noqa: BLE001
        return True  # unknown: don't claim it's missing


def to_label(job):
    d = job["label"]
    return Label(d["box"], d["bin"], d["label1"], d.get("label2", ""), d.get("label3", ""))


def to_layout(job):
    raw = job["label"].get("layout", {})
    kw = {k: v for k, v in raw.items() if k in LAYOUT_KEYS}
    if "rotate" in kw:
        kw["rotate"] = int(kw["rotate"])
    for k in kw:
        if k not in ("rotate", "boxbin_side"):
            kw[k] = float(kw[k])
    return Layout(**kw)


def cups_print(conf, pdf, title):
    """Send a PDF to CUPS and wait until the job is done. Returns None or an error text."""
    out = subprocess.run(["lp", "-d", conf["PRINTER"], "-t", title, "-o", f"PageSize={conf['MEDIA']}", pdf],
                         capture_output=True, text=True, timeout=30)
    m = re.search(r"request id is (\S+)", out.stdout)
    if out.returncode or not m:
        return f"lp failed: {(out.stderr or out.stdout).strip()[:200]}"
    job_id = m.group(1)
    deadline = time.time() + float(conf["PRINT_TIMEOUT_SECONDS"])
    while time.time() < deadline:
        pending = subprocess.run(["lpstat", "-o", conf["PRINTER"]], capture_output=True, text=True, timeout=15).stdout
        if job_id not in pending:
            done = subprocess.run(["lpstat", "-W", "completed", "-o", conf["PRINTER"]],
                                  capture_output=True, text=True, timeout=15).stdout
            return None if job_id in done else "CUPS job did not complete (aborted or cancelled)"
        time.sleep(1)
    subprocess.run(["cancel", job_id], capture_output=True)
    return "printer did not finish in time (is it connected and loaded with labels?)"


def handle(conf, jobs):
    """Print claimed jobs grouped by batch (one multi-page PDF per batch and layout)."""
    groups = {}
    for job in jobs:
        key = (job["batch"], json.dumps(job["label"].get("layout", {}), sort_keys=True))
        groups.setdefault(key, []).append(job)
    for (batch, _), group in groups.items():
        error = None
        try:
            layout = to_layout(group[0])
            labels = [to_label(j) for j in group]
            with tempfile.TemporaryDirectory() as tmp:
                pdf = os.path.join(tmp, f"batch-{batch}.pdf")
                render_pdf(pdf, labels, layout)
                error = cups_print(conf, pdf, f"inventory-{batch}")
        except Exception as e:  # noqa: BLE001
            error = f"render failed: {e}"[:250]
        for job in group:
            status = "failed" if error else "printed"
            log.info("job %s (%s/%s %s): %s%s", job["id"], job["label"]["box"], job["label"]["bin"],
                     job["label"]["label1"], status, f" - {error}" if error else "")
            report(conf, job, status, error or "")


def report(conf, job, status, error):
    for attempt in range(5):
        try:
            api(conf, "report", {"id": job["id"], "claim": job["claim"], "status": status, "error": error})
            return
        except urllib.error.HTTPError as e:
            if e.code == 409:  # already timed out server-side: nothing more to do
                log.warning("job %s: server refused report (%s)", job["id"], e.code)
                return
            log.warning("job %s: report failed (%s), retrying", job["id"], e.code)
        except Exception as e:  # noqa: BLE001
            log.warning("job %s: report failed (%s), retrying", job["id"], e)
        time.sleep(2 ** attempt)


def main():
    logging.basicConfig(level=logging.INFO, format="%(levelname)s %(message)s")
    conf = load_config()
    poll = float(conf["POLL_SECONDS"])
    log.info("inventory agent %s started: %s, printer %s", VERSION, conf["API_URL"], conf["PRINTER"])
    backoff = poll
    while True:
        try:
            data = api(conf, "poll", {"version": VERSION, "printer": printer_state(conf)})
            jobs = data.get("jobs", [])
            if jobs:
                log.info("claimed %d job(s)", len(jobs))
                handle(conf, jobs)
                continue  # look for more right away
            backoff = poll
        except urllib.error.HTTPError as e:
            log.error("poll failed: HTTP %s %s", e.code, e.read()[:200].decode(errors="replace"))
            if e.code == 401:
                backoff = 60  # wrong token: don't hammer the server
            else:
                backoff = min(backoff * 2, 60)
        except Exception as e:  # noqa: BLE001
            log.error("poll failed: %s", e)
            backoff = min(backoff * 2, 60)
        time.sleep(backoff)


if __name__ == "__main__":
    main()

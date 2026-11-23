#!/usr/bin/env python3
"""Retention rule tests (FSD §10.3): the PHP (server) and Python (Pi) implementations must
agree, keep the 10 newest sets and exactly the first set of every month forever.
Needs the dev docker environment for PHP (dev/compose.yaml)."""

import datetime
import json
import os
import random
import subprocess
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(HERE, "..", "agent"))
os.environ.setdefault("INVENTORY_AGENT_CONF", "/nonexistent")
from backup_fetch import backups_to_keep  # noqa: E402

COMPOSE = os.path.join(HERE, "..", "dev", "compose.yaml")


def php_keep(dates):
    code = ("require '/var/www/html/inventory/lib/backup.php';"
            "$d = json_decode(stream_get_contents(STDIN), true);"
            "echo json_encode(backups_to_keep($d));")
    out = subprocess.run(["docker", "compose", "-f", COMPOSE, "exec", "-T", "web", "php", "-r", code],
                         input=json.dumps(dates), capture_output=True, text=True, check=True)
    return set(json.loads(out.stdout))


def scenario(seed):
    rnd = random.Random(seed)
    dates = {}
    day = datetime.datetime(2026, 11, 29, 4, 3)   # Sundays, about 3 years
    for _ in range(160):
        if rnd.random() > 0.05:                    # sometimes a week is missed
            dates[f"inventory_{day:%Y-%m-%d_%H%M}"] = f"{day:%Y-%m-%d %H:%M}"
        if rnd.random() < 0.1:                     # manual / pre-restore backups in between
            extra = day + datetime.timedelta(days=rnd.randint(1, 5), hours=rnd.randint(1, 10))
            dates[f"inventory_{extra:%Y-%m-%d_%H%M}_manual"] = f"{extra:%Y-%m-%d %H:%M}"
        day += datetime.timedelta(days=7)
    return dates


def main():
    fails = 0
    for seed in range(5):
        dates = scenario(seed)
        py, php = backups_to_keep(dates), php_keep(dates)
        newest = sorted(dates, key=lambda b: dates[b], reverse=True)[:10]
        months = {}
        for b, d in sorted(dates.items(), key=lambda x: x[1]):
            months.setdefault(d[:7], b)
        expected = set(newest) | set(months.values())
        ok = py == php == expected
        print(f"{'PASS' if ok else 'FAIL'} scenario {seed}: {len(dates)} sets -> keep {len(py)} "
              f"(10 newest + {len(months)} monthly, overlap {len(set(newest) & set(months.values()))})")
        fails += not ok
    # small edge cases
    for dates in ({}, {"inventory_2026-11-29_0400": "2026-11-29 04:00"}):
        ok = backups_to_keep(dates) == php_keep(dates) == set(dates)
        print(f"{'PASS' if ok else 'FAIL'} edge case with {len(dates)} set(s)")
        fails += not ok
    sys.exit(1 if fails else 0)


if __name__ == "__main__":
    main()

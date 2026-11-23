#!/usr/bin/env python3
"""Convert the data sheet of KelderInventory.ods to setup-seed.json for web/setup.php.

Usage: import_ods.py [legacy/KelderInventory.ods] [web/setup-seed.json]

Reads the first sheet (Box, Bin, Label row 1-3, Keywords, Comments), keeps leading zeros
(cells are read as displayed text), skips empty rows. No third-party packages needed.
"""

import json
import os
import sys
import zipfile
import xml.etree.ElementTree as ET

NS = {
    "table": "urn:oasis:names:tc:opendocument:xmlns:table:1.0",
    "text": "urn:oasis:names:tc:opendocument:xmlns:text:1.0",
}
FIELDS = ["box", "bin", "label1", "label2", "label3", "keywords", "comments"]
MAX_REPEAT = 50  # guard against "repeat this empty cell 16000 times"


def cell_text(cell):
    paras = []
    for p in cell.findall("text:p", NS):
        paras.append("".join(p.itertext()))
    return "\n".join(paras).strip()


def read_first_sheet(path):
    with zipfile.ZipFile(path) as z:
        root = ET.fromstring(z.read("content.xml"))
    sheet = root.find(".//table:table", NS)
    rows = []
    for row in sheet.findall(".//table:table-row", NS):
        values = []
        for cell in row:
            rep = int(cell.get(f"{{{NS['table']}}}number-columns-repeated", "1"))
            values.extend([cell_text(cell)] * min(rep, MAX_REPEAT))
        rows.append(values)
    return rows


def main():
    here = os.path.dirname(os.path.abspath(__file__))
    src = sys.argv[1] if len(sys.argv) > 1 else os.path.join(here, "..", "legacy", "KelderInventory.ods")
    dst = sys.argv[2] if len(sys.argv) > 2 else os.path.join(here, "..", "web", "setup-seed.json")
    rows = read_first_sheet(src)
    header = [h.lower() for h in rows[0][:7]]
    if header[:3] != ["box", "bin", "label row 1"]:
        sys.exit(f"unexpected header: {rows[0][:7]}")
    items = []
    for n, values in enumerate(rows[1:], start=2):
        values = (values + [""] * 7)[:7]
        if not any(values):
            continue
        item = dict(zip(FIELDS, values))
        missing = [f for f in ("box", "bin", "label1") if not item[f]]
        if missing:
            print(f"row {n}: skipped, missing {', '.join(missing)}: {values}")
            continue
        items.append(item)
    with open(dst, "w", encoding="utf-8") as f:
        json.dump(items, f, ensure_ascii=False, indent=1)
    print(f"wrote {len(items)} items to {dst}")
    for it in items:
        print(f"  {it['box']}/{it['bin']}  {it['label1']}")


if __name__ == "__main__":
    main()

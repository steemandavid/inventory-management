#!/usr/bin/env python3
"""Phase 0 print feasibility test (FSD_Web_Inventory.md, chapter 11).

Examples:
  print_test.py --pdf out.pdf                     # all 6 sample labels to PDF, no printing
  print_test.py --calibrate --pdf cal.pdf --print # calibration label, printed
  print_test.py --only 4 --print                  # longest sample label (T4), printed
  print_test.py --print --page landscape          # try the other page orientation
  print_test.py --print --lp-option media=w36h142 --offset-x 0.5

Every printed job is listed with its settings in print_test.log, so the physical labels
can be matched to the settings afterwards.
"""

import argparse
import datetime
import os
import subprocess
import sys

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "agent"))
from label_render import Label, Layout, render_pdf  # noqa: E402

# The 6 rows from KelderInventory.ods (2026-11-23) plus a special-characters row (T7).
SAMPLES = [
    Label("03", "02", "Screws (M3 pan head)", "Stainless steel, 10 mm xxxxx", "some more random comment herexxx"),
    Label("03", "07", "Heat shrink tubing", "Black, 3:1, assorted", "some more random comment here"),
    Label("12", "05", "Cable ties", "Nylon, 200 mm", "some more random comment here"),
    Label("89", "01", "Ring terminals", "M4, red, 100 pcs, gold plated", "some more random comment here"),
    Label("09", "14", "Electrical tape", "PVC, 19 mm x 20 m", "some more random comment here"),
    Label("04", "11", "Banana plugs", "Gold plated, pair", "some more random comment here"),
    Label("03", "02", "Resistors 4.7 kΩ ±1%", "Capacitors 100 µF × 10", "Café é è ü ß"),
]


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--pdf", default="labels.pdf", help="output PDF path (default labels.pdf)")
    ap.add_argument("--calibrate", action="store_true", help="render the calibration label instead")
    ap.add_argument("--only", type=int, nargs="*", help="sample numbers to render (1-7), default 1-6")
    ap.add_argument("--print", action="store_true", help="send the PDF to the printer")
    ap.add_argument("--printer", default="LabelWriter-450")
    ap.add_argument("--lp-option", action="append", default=[], help="extra lp -o option, repeatable")
    ap.add_argument("--copies", type=int, default=1)
    lay = Layout()
    for name, val in vars(lay).items():
        ap.add_argument("--" + name.replace("_", "-"), type=type(val), default=val)
    args = ap.parse_args()

    layout = Layout(**{k: getattr(args, k) for k in vars(lay)})
    picks = args.only or [1, 2, 3, 4, 5, 6]
    labels = [SAMPLES[i - 1] for i in picks]

    report = render_pdf(args.pdf, labels, layout, calibration=args.calibrate)
    what = "calibration label" if args.calibrate else f"{len(labels)} label(s): {picks}"
    print(f"wrote {args.pdf}: {what}")
    for note in report.notes:
        print("  fit:", note)

    if args.print:
        cmd = ["lp", "-d", args.printer, "-n", str(args.copies), "-t", "print_test"]
        for opt in args.lp_option:
            cmd += ["-o", opt]
        cmd.append(args.pdf)
        print("running:", " ".join(cmd))
        out = subprocess.run(cmd, capture_output=True, text=True)
        print(out.stdout.strip() or out.stderr.strip())
        with open(os.path.join(os.path.dirname(os.path.abspath(args.pdf)), "print_test.log"), "a") as log:
            log.write(f"{datetime.datetime.now():%Y-%m-%d %H:%M:%S}  {what}  {layout}  lp={args.lp_option}  -> {out.stdout.strip() or out.stderr.strip()}\n")
        if out.returncode:
            sys.exit(out.returncode)


if __name__ == "__main__":
    main()

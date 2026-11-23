"""Render 12 x 50 mm inventory labels to PDF (shared by print_test.py and the print agent).

Coordinates are worked out in *reading* orientation: the label is 50 mm wide and
12 mm high, as you read it. The PDF page itself can be portrait (12 x 50 mm, how the
Dymo feeds the label) with the content rotated, or landscape (50 x 12 mm), depending
on what the CUPS driver expects. Phase 0 decides which.

Layout (from LabelPrintMacro.bas v1.4):
  - label rows section: 78% of the length, three left-aligned lines,
    row 1 bold, rows 2 and 3 normal
  - Box/Bin section: 18% of the length, Box above Bin, centered, both bold
  - the remaining 4% is the gap between the two sections
Text that doesn't fit is shrunk down to MIN_FONT_PT, then clipped.
"""

from dataclasses import dataclass, field

from reportlab.lib.units import mm
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.pdfgen import canvas

FONT_DIR = "/usr/share/fonts/truetype/dejavu"
FONT = "DejaVuSans"
FONT_BOLD = "DejaVuSans-Bold"
MIN_FONT_PT = 5.0
LINE_SPACING = 1.12  # line height as a multiple of the font size

_fonts_registered = False


def register_fonts(font_dir=FONT_DIR):
    global _fonts_registered
    if not _fonts_registered:
        pdfmetrics.registerFont(TTFont(FONT, f"{font_dir}/DejaVuSans.ttf"))
        pdfmetrics.registerFont(TTFont(FONT_BOLD, f"{font_dir}/DejaVuSans-Bold.ttf"))
        _fonts_registered = True


@dataclass
class Layout:
    # Dymo 99017 / S0722460 "suspension file" label, CUPS media w36h144 (36 x 144 pt).
    length_mm: float = 50.8        # label length (reading width)
    height_mm: float = 12.7        # label height (reading height)
    # Margins in reading orientation. The LW450 PPD only prints 1.52 to 45.13 mm along the
    # length and 1.02 to 11.68 mm across, so one end has a ~5.7 mm dead zone. Which end that
    # is in reading orientation depends on the rotation; the calibration label shows it.
    # Measured in Phase 0 (2026-11-23, Pi 1 B+ + LW450, rotate=270): printing covers about
    # 5.7 to 49.3 mm along the length and 1.2 to 11.6 mm across.
    margin_left_mm: float = 6.5
    margin_right_mm: float = 2.0
    margin_top_mm: float = 1.2
    margin_bottom_mm: float = 2.0
    labels_ratio: float = 0.78     # share of the usable length for label rows
    boxbin_ratio: float = 0.18     # share of the usable length for Box/Bin
    boxbin_side: str = "right"     # "right" (macro v1.4) or "left" (old FSD text)
    font_box: float = 12.0
    font_bin: float = 12.0
    font_label1: float = 10.0
    font_label2: float = 8.0
    font_label3: float = 6.0
    # Page geometry for the PDF
    page: str = "portrait"         # "portrait" = 12 x 50 mm page, content rotated; "landscape" = 50 x 12 mm
    rotate: int = 270              # 90 or 270 (270 measured correct in Phase 0): direction of the content rotation on a portrait page
    offset_x_mm: float = 0.0       # calibration shift along the label length (reading coords)
    offset_y_mm: float = 0.0       # calibration shift across the label height (reading coords)


@dataclass
class Label:
    box: str
    bin: str
    label1: str
    label2: str = ""
    label3: str = ""

    def missing_fields(self):
        return [name for name, value in (("Box", self.box), ("Bin", self.bin),
                                         ("Label row 1", self.label1))
                if not str(value or "").strip()]


@dataclass
class FitReport:
    """What happened to each text line: shrunk, clipped, or fine."""
    notes: list = field(default_factory=list)


def _fit(text, font, size, max_width, report, what):
    """Shrink text until it fits max_width (not below MIN_FONT_PT). Returns (size, clipped)."""
    width = pdfmetrics.stringWidth(text, font, size)
    if width <= max_width:
        return size, False
    fitted = size * max_width / width
    if fitted >= MIN_FONT_PT:
        report.notes.append(f"{what}: shrunk {size:g} -> {fitted:.1f} pt")
        return fitted, False
    report.notes.append(f"{what}: shrunk to {MIN_FONT_PT:g} pt and CLIPPED")
    return MIN_FONT_PT, True


def _draw_text(c, text, font, size, x, y, max_width, align, clipped):
    if clipped:
        c.saveState()
        p = c.beginPath()
        p.rect(x if align == "left" else x - max_width / 2, y - size * 0.3, max_width, size * 1.3)
        c.clipPath(p, stroke=0, fill=0)
    c.setFont(font, size)
    if align == "left":
        c.drawString(x, y, text)
    else:
        c.drawCentredString(x, y, text)
    if clipped:
        c.restoreState()


def _begin_page(c, layout):
    """Set up the transform so we can draw in reading coordinates (points)."""
    L, H = layout.length_mm * mm, layout.height_mm * mm
    if layout.page == "portrait":
        c.setPageSize((H, L))
        if layout.rotate == 90:     # reading-x runs up the page
            c.translate(H, 0)
            c.rotate(90)
        elif layout.rotate == 270:  # reading-x runs down the page
            c.translate(0, L)
            c.rotate(-90)
        else:
            raise ValueError("rotate must be 90 or 270")
    elif layout.page == "landscape":
        c.setPageSize((L, H))
    else:
        raise ValueError("page must be 'portrait' or 'landscape'")
    c.translate(layout.offset_x_mm * mm, layout.offset_y_mm * mm)


def _sections(layout):
    """Return (labels_x0, labels_w, boxbin_x0, boxbin_w) in points."""
    ml, mr = layout.margin_left_mm * mm, layout.margin_right_mm * mm
    usable = layout.length_mm * mm - ml - mr
    lw, bw = usable * layout.labels_ratio, usable * layout.boxbin_ratio
    if layout.boxbin_side == "right":
        return ml, lw, ml + usable - bw, bw
    return ml + usable - lw, lw, ml, bw


def _vertical(layout):
    """Return (center_y, usable_height) in points for the area between top and bottom margins."""
    mb, mt = layout.margin_bottom_mm * mm, layout.margin_top_mm * mm
    usable = layout.height_mm * mm - mb - mt
    return mb + usable / 2, usable


def draw_label(c, label, layout, report):
    _begin_page(c, layout)
    cy, usable_h = _vertical(layout)
    lx, lw, bx, bw = _sections(layout)

    # Label rows: a block of up to three lines, vertically centered in the usable height.
    rows = [(label.label1, FONT_BOLD, layout.font_label1, "Label 1"),
            (label.label2, FONT, layout.font_label2, "Label 2"),
            (label.label3, FONT, layout.font_label3, "Label 3")]
    rows = [r for r in rows if str(r[0] or "").strip()]
    fitted = [(t, f) + _fit(t, f, s, lw, report, what) for t, f, s, what in rows]
    block = sum(s * LINE_SPACING for _, _, s, _ in fitted)
    if block > usable_h:
        report.notes.append(f"label rows: block {block / mm:.1f} mm higher than usable {usable_h / mm:.1f} mm")
    y = cy + block / 2
    for text, font, size, clipped in fitted:
        y -= size * LINE_SPACING
        _draw_text(c, text, font, size, lx, y + size * (LINE_SPACING - 1) + size * 0.2, lw, "left", clipped)

    # Box above Bin, centered, bold.
    pair = [(label.box, layout.font_box, "Box"), (label.bin, layout.font_bin, "Bin")]
    fitted = [(t, ) + _fit(t, FONT_BOLD, s, bw, report, what) for t, s, what in pair]
    block = sum(s * LINE_SPACING for _, s, _ in fitted)
    y = cy + block / 2
    for text, size, clipped in fitted:
        y -= size * LINE_SPACING
        _draw_text(c, text, FONT_BOLD, size, bx + bw / 2, y + size * (LINE_SPACING - 1) + size * 0.2, bw, "center", clipped)
    c.showPage()


def draw_calibration(c, layout):
    """Calibration label: outline at the edge and at 0.5 / 1 mm inset, mm ticks, orientation marks."""
    _begin_page(c, layout)
    L, H = layout.length_mm * mm, layout.height_mm * mm
    c.setLineWidth(0.25)
    for inset in (0.0, 0.5, 1.0):
        c.rect(inset * mm, inset * mm, L - 2 * inset * mm, H - 2 * inset * mm, stroke=1, fill=0)
    # mm ticks along the bottom edge, longer every 5 mm, numbers every 10 mm
    c.setFont(FONT, 3.5)
    for i in range(0, int(layout.length_mm) + 1):
        t = 2.0 if i % 5 == 0 else 1.0
        c.line(i * mm, 0, i * mm, t * mm)
        if i % 10 == 0 and 0 < i < layout.length_mm:
            c.drawCentredString(i * mm, 2.4 * mm, str(i))
    # ticks along the left edge
    for i in range(0, int(layout.height_mm) + 1):
        t = 2.0 if i % 5 == 0 else 1.0
        c.line(0, i * mm, t * mm, i * mm)
    # vertical scale across the height at 25 mm: 1 mm ticks, every other one numbered,
    # so cut-off numbers show the vertical position of the printable area
    c.setFont(FONT, 3.2)
    for i in range(0, int(layout.height_mm) + 1):
        c.line(25 * mm, i * mm, (25 + (1.5 if i % 2 == 0 else 0.8)) * mm, i * mm)
        if i % 2 == 0:
            c.drawString(26.8 * mm, i * mm - 1.1, str(i))
    # section boundaries of the real layout (dashed)
    lx, lw, bx, bw = _sections(layout)
    c.setDash(1, 1)
    for x in (lx, lx + lw, bx, bx + bw):
        c.line(x, 1 * mm, x, H - 1 * mm)
    c.setDash()
    # orientation marks
    c.setFont(FONT_BOLD, 7)
    c.drawString(3 * mm, H - 4.2 * mm, "TOP-LEFT")
    c.drawRightString(L - 2 * mm, H - 4.2 * mm, "R")
    c.setFont(FONT, 5)
    c.drawString(3 * mm, H / 2 - 1.5 * mm, f"{layout.page} rot={layout.rotate} off={layout.offset_x_mm:+.1f}/{layout.offset_y_mm:+.1f}")
    c.showPage()


def render_pdf(path, labels, layout, calibration=False):
    """Write one PDF page per label (or a single calibration page). Returns a FitReport."""
    register_fonts()
    report = FitReport()
    c = canvas.Canvas(path)
    c.setTitle("labels")
    if calibration:
        draw_calibration(c, layout)
    else:
        for i, label in enumerate(labels, 1):
            missing = label.missing_fields()
            if missing:
                raise ValueError(f"label {i}: missing {', '.join(missing)}")
            before = len(report.notes)
            draw_label(c, label, layout, report)
            for j in range(before, len(report.notes)):
                report.notes[j] = f"label {i} ({label.box}/{label.bin}) {report.notes[j]}"
    c.save()
    return report

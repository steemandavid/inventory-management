#!/usr/bin/env python3
"""
Headless label generator using LibreOffice UNO API.
Replicates LabelPrintMacro.bas functionality for automated testing.

Layout: Landscape page (50mm x 12mm) - readable orientation.
Box/Bin on left, labels on right.

Usage: python3 generate_label.py [box] [bin] [label1] [label2] [label3]
"""

import subprocess
import sys
import os
import time
import socket

# Label dimensions in 1/100 mm
LABEL_WIDTH = 5000    # 50mm (readable width)
LABEL_HEIGHT = 1200   # 12mm (readable height)

# Default test data
DEFAULT_LABEL = {
    'box': '03',
    'bin': '02',
    'label1': 'Screws (M3 pan head)',
    'label2': 'Stainless steel, 10 mm',
    'label3': 'some more random comment here'
}

# Font sizes optimized for 12mm height label (~34 points total)
# Box/Bin column needs ~14pt each stacked = ~28pt
# Labels column needs 3 lines at ~8-10pt each
DEFAULT_CONFIG = {
    'font_box': 14,      # Large, bold
    'font_bin': 12,      # Slightly smaller
    'font_label1': 9,    # Main description - bold
    'font_label2': 7,    # Secondary info
    'font_label3': 6     # Additional details
}


def is_libreoffice_running(port=2002):
    """Check if LibreOffice is listening on the specified port."""
    try:
        sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        sock.settimeout(1)
        result = sock.connect_ex(('localhost', port))
        sock.close()
        return result == 0
    except:
        return False


def start_libreoffice(port=2002):
    """Start LibreOffice in headless mode with socket connection."""
    if is_libreoffice_running(port):
        print(f"LibreOffice already running on port {port}")
        return None

    print("Starting LibreOffice in headless mode...")
    cmd = [
        'soffice',
        '--headless',
        '--invisible',
        '--nologo',
        '--nofirststartwizard',
        f'--accept=socket,host=localhost,port={port};urp;StarOffice.ServiceManager'
    ]
    proc = subprocess.Popen(cmd, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)

    # Wait for LibreOffice to start
    for i in range(30):
        time.sleep(1)
        if is_libreoffice_running(port):
            print("LibreOffice started successfully")
            return proc

    print("Warning: LibreOffice may not have started properly")
    return proc


def connect_to_libreoffice(port=2002):
    """Connect to running LibreOffice instance."""
    import uno
    from com.sun.star.connection import NoConnectException

    local_context = uno.getComponentContext()
    resolver = local_context.ServiceManager.createInstanceWithContext(
        "com.sun.star.bridge.UnoUrlResolver", local_context)

    max_attempts = 10
    for attempt in range(max_attempts):
        try:
            ctx = resolver.resolve(
                f"uno:socket,host=localhost,port={port};urp;StarOffice.ComponentContext")
            return ctx.ServiceManager.createInstanceWithContext(
                "com.sun.star.frame.Desktop", ctx)
        except NoConnectException:
            if attempt < max_attempts - 1:
                time.sleep(1)
            else:
                raise


def create_label_document(desktop, label, config):
    """Create a Writer document with the label layout.

    Layout: Landscape page (50mm x 12mm) with Box/Bin on left, labels on right.
    No borders or dividing lines.
    """
    import uno
    from com.sun.star.beans import PropertyValue
    from com.sun.star.text.ControlCharacter import LINE_BREAK
    from com.sun.star.text.HoriOrientation import NONE as HORI_NONE
    from com.sun.star.text.VertOrientation import CENTER as VERT_CENTER, TOP as VERT_TOP
    from com.sun.star.style.ParagraphAdjust import CENTER, LEFT
    from com.sun.star.awt.FontWeight import BOLD
    from com.sun.star.table import BorderLine2

    # Create hidden document
    load_props = (PropertyValue(Name="Hidden", Value=True),)
    doc = desktop.loadComponentFromURL("private:factory/swriter", "_blank", 0, load_props)

    # Configure page style - LANDSCAPE (50mm wide x 12mm tall)
    page_styles = doc.StyleFamilies.getByName("PageStyles")
    page_style = page_styles.getByName("Standard")

    page_style.IsLandscape = True
    page_style.Width = LABEL_WIDTH      # 50mm
    page_style.Height = LABEL_HEIGHT    # 12mm
    page_style.LeftMargin = 0           # No margins - use full label area
    page_style.RightMargin = 0
    page_style.TopMargin = 0
    page_style.BottomMargin = 0

    # Usable dimensions - full label area
    usable_width = LABEL_WIDTH          # Full 50mm
    usable_height = LABEL_HEIGHT        # Full 12mm

    # Get text and cursor
    text = doc.Text
    cursor = text.createTextCursor()
    cursor.ParaTopMargin = 0
    cursor.ParaBottomMargin = 0

    # No borders
    no_border = BorderLine2()
    no_border.OuterLineWidth = 0
    no_border.InnerLineWidth = 0
    no_border.LineDistance = 0
    no_border.LineWidth = 0

    # Create table (1 row, 2 columns)
    # Column 1: Box/Bin (left)
    # Column 2: Labels (right)
    table = doc.createInstance("com.sun.star.text.TextTable")
    table.initialize(1, 2)
    text.insertTextContent(cursor, table, False)

    # Set table to fill page
    table.Width = usable_width
    table.HoriOrient = HORI_NONE
    table.LeftMargin = 0
    table.RightMargin = 0
    table.TopMargin = 0
    table.BottomMargin = 0

    # Column widths: ~80% for Labels (left), ~20% for Box/Bin (right)
    separators = list(table.TableColumnSeparators)
    if separators:
        separators[0].Position = 8000  # 80% for labels
        table.TableColumnSeparators = tuple(separators)

    # Row height to fill page (leave room for trailing para)
    rows = table.getRows()
    row = rows.getByIndex(0)
    row.Height = usable_height - 100    # Leave ~1mm for trailing para
    row.IsAutoHeight = False

    # Get cells - Labels on left, Box/Bin on right
    # After CW rotation: left→top (labels), right→bottom (box/bin)
    cell_labels = table.getCellByName("A1")   # Left - Labels
    cell_boxbin = table.getCellByName("B1")   # Right - Box/Bin

    # Remove all borders
    for cell in [cell_boxbin, cell_labels]:
        cell.TopBorder = no_border
        cell.BottomBorder = no_border
        cell.LeftBorder = no_border
        cell.RightBorder = no_border

    # === Box/Bin Cell (now on right, will be at bottom after CW rotation) ===
    # Order: Bin first (top in landscape), then Box (bottom in landscape)
    # After CW rotation: Box will be on top when reading, Bin below
    boxbin_text = cell_boxbin.getText()
    boxbin_cursor = boxbin_text.createTextCursor()
    cell_boxbin.VertOrient = VERT_TOP

    # Set tight line spacing (90% of normal)
    from com.sun.star.style.LineSpacingMode import PROP as LINE_SPACING_PROP
    line_spacing = uno.createUnoStruct("com.sun.star.style.LineSpacing")
    line_spacing.Mode = LINE_SPACING_PROP
    line_spacing.Height = 90

    boxbin_cursor.ParaAdjust = CENTER
    boxbin_cursor.ParaLineSpacing = line_spacing
    boxbin_cursor.ParaTopMargin = 0
    boxbin_cursor.ParaBottomMargin = 0

    # Bin number first (top in landscape → bottom when reading after CW rotation)
    boxbin_cursor.CharHeight = config['font_bin']
    boxbin_cursor.CharWeight = BOLD
    boxbin_text.insertString(boxbin_cursor, label['bin'], False)

    # Line break between bin and box
    boxbin_text.insertControlCharacter(boxbin_cursor, LINE_BREAK, False)

    # Box number (bottom in landscape → top when reading after CW rotation)
    boxbin_cursor.CharHeight = config['font_box']
    boxbin_cursor.CharWeight = BOLD
    boxbin_text.insertString(boxbin_cursor, label['box'], False)

    # === Labels Cell (now on left, will be at top after CW rotation) ===
    labels_text = cell_labels.getText()
    labels_cursor = labels_text.createTextCursor()
    cell_labels.VertOrient = VERT_TOP

    labels_cursor.ParaAdjust = LEFT
    labels_cursor.ParaTopMargin = 0
    labels_cursor.ParaBottomMargin = 0
    labels_cursor.ParaLineSpacing = line_spacing

    # Label row 1 (main description - bold)
    labels_cursor.CharHeight = config['font_label1']
    labels_cursor.CharWeight = BOLD
    labels_text.insertString(labels_cursor, label['label1'], False)

    # Label row 2
    if label.get('label2'):
        labels_text.insertControlCharacter(labels_cursor, LINE_BREAK, False)
        labels_cursor.CharHeight = config['font_label2']
        labels_cursor.CharWeight = 100  # Normal
        labels_text.insertString(labels_cursor, label['label2'], False)

    # Label row 3
    if label.get('label3'):
        labels_text.insertControlCharacter(labels_cursor, LINE_BREAK, False)
        labels_cursor.CharHeight = config['font_label3']
        labels_text.insertString(labels_cursor, label['label3'], False)

    # Minimize trailing paragraph
    cursor.gotoEnd(False)
    cursor.CharHeight = 1
    cursor.ParaTopMargin = 0
    cursor.ParaBottomMargin = 0

    return doc


def export_to_pdf(doc, output_path, rotate=True):
    """Export document to PDF, optionally rotating 90° CCW for portrait output."""
    from com.sun.star.beans import PropertyValue

    # Convert to file URL
    if not output_path.startswith("file://"):
        abs_path = os.path.abspath(output_path)
        url_path = "file://" + abs_path
    else:
        url_path = output_path
        abs_path = output_path.replace("file://", "")

    pdf_opts = (
        PropertyValue(Name="FilterName", Value="writer_pdf_Export"),
        PropertyValue(Name="Overwrite", Value=True),
    )

    if rotate:
        # Export to temp file first
        temp_path = abs_path + ".temp.pdf"
        temp_url = "file://" + temp_path
        doc.storeToURL(temp_url, pdf_opts)

        # Rotate the PDF 90° CCW using qpdf (preserves page size)
        try:
            import subprocess
            # Use qpdf for rotation - rotate CW so Box/Bin ends up at bottom
            result = subprocess.run(
                ['qpdf', '--rotate=+90:1', temp_path, abs_path],
                capture_output=True, text=True
            )
            if result.returncode != 0:
                # No rotation tool available, just copy
                import shutil
                shutil.copy(temp_path, abs_path)
                print("Note: PDF rotation tools not available, using landscape orientation")
            os.remove(temp_path)
        except Exception as e:
            # Fallback: just use the temp file
            import shutil
            shutil.copy(temp_path, abs_path)
            try:
                os.remove(temp_path)
            except:
                pass
    else:
        doc.storeToURL(url_path, pdf_opts)

    print(f"Exported PDF: {abs_path}")


def main():
    script_dir = os.path.dirname(os.path.abspath(__file__))

    # Parse arguments or use defaults
    if len(sys.argv) >= 4:
        label = {
            'box': sys.argv[1],
            'bin': sys.argv[2],
            'label1': sys.argv[3],
            'label2': sys.argv[4] if len(sys.argv) > 4 else '',
            'label3': sys.argv[5] if len(sys.argv) > 5 else ''
        }
    else:
        label = DEFAULT_LABEL

    config = DEFAULT_CONFIG

    print(f"Generating label for Box={label['box']}, Bin={label['bin']}")
    print(f"  Label1: {label['label1']}")
    print(f"  Label2: {label['label2']}")
    print(f"  Label3: {label['label3']}")

    # Start LibreOffice if needed
    lo_proc = start_libreoffice()

    try:
        # Connect to LibreOffice
        print("Connecting to LibreOffice...")
        desktop = connect_to_libreoffice()

        # Create label document
        print("Creating label document...")
        doc = create_label_document(desktop, label, config)

        # Export to PDF
        pdf_path = os.path.join(script_dir, f"label_{label['box']}_{label['bin']}.pdf")
        export_to_pdf(doc, pdf_path)

        # Close document
        doc.close(False)

        print(f"\nSuccess! PDF saved to: {pdf_path}")

    except Exception as e:
        print(f"Error: {e}")
        import traceback
        traceback.print_exc()
        sys.exit(1)


if __name__ == "__main__":
    main()

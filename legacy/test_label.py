#!/usr/bin/env python3
"""
Test script to generate label PDFs using LibreOffice UNO API.
This simulates the LabelPrintMacro.bas functionality for testing.
"""

import subprocess
import sys
import os
import time

# Start LibreOffice in listening mode if not already running
def start_libreoffice():
    """Start LibreOffice in headless mode with socket connection."""
    cmd = [
        'soffice',
        '--headless',
        '--accept=socket,host=localhost,port=2002;urp;StarOffice.ServiceManager',
        '--nofirststartwizard'
    ]
    proc = subprocess.Popen(cmd, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    time.sleep(3)  # Give it time to start
    return proc

def main():
    # First, try to import uno - this requires running inside LibreOffice context
    # or connecting to a running LibreOffice instance

    try:
        import uno
        from com.sun.star.beans import PropertyValue
        from com.sun.star.text.ControlCharacter import LINE_BREAK
        from com.sun.star.text.TextContentAnchorType import AT_PAGE
        from com.sun.star.text.HoriOrientation import CENTER as HORI_CENTER
        from com.sun.star.text.VertOrientation import CENTER as VERT_CENTER
        from com.sun.star.style.ParagraphAdjust import CENTER, LEFT
        from com.sun.star.awt.FontWeight import BOLD
    except ImportError:
        print("UNO not available in this Python environment.")
        print("Trying to connect to LibreOffice...")

        # Start LibreOffice
        lo_proc = start_libreoffice()

        # Now we need to run this script from within LibreOffice context
        # Let's create a simpler approach using a macro
        print("\nGenerating label via LibreOffice macro...")

        script_dir = os.path.dirname(os.path.abspath(__file__))
        ods_file = os.path.join(script_dir, "KelderInventory.ods")

        # We'll use a different approach: generate a simple ODT directly
        generate_test_label_odt(script_dir)
        return

def generate_test_label_odt(output_dir):
    """
    Generate a test label document manually (without UNO).
    Creates an ODT file with the label layout, then converts to PDF.
    """
    import zipfile
    from xml.etree.ElementTree import Element, SubElement, tostring, ElementTree

    # Test label data
    label = {
        'box': '03',
        'bin': '02',
        'label1': 'Screws (M3 pan head)',
        'label2': 'Stainless steel, 10 mm',
        'label3': 'some more random comment here'
    }

    # Create ODT (OpenDocument Text) file
    # ODT is a ZIP file with specific XML structure

    output_file = os.path.join(output_dir, f"label_{label['box']}_{label['bin']}.odt")

    # Simplified content.xml for the label
    content_xml = f'''<?xml version="1.0" encoding="UTF-8"?>
<office:document-content
    xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"
    xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0"
    xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"
    xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0"
    xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0"
    xmlns:svg="urn:oasis:names:tc:opendocument:xmlns:svg-compatible:1.0"
    office:version="1.3">

    <office:automatic-styles>
        <style:style style:name="P1" style:family="paragraph">
            <style:paragraph-properties fo:text-align="center" fo:margin-top="0mm" fo:margin-bottom="0mm"/>
            <style:text-properties fo:font-size="10pt" fo:font-weight="bold"/>
        </style:style>
        <style:style style:name="P2" style:family="paragraph">
            <style:paragraph-properties fo:text-align="left" fo:margin-top="0mm" fo:margin-bottom="0mm"/>
            <style:text-properties fo:font-size="8pt"/>
        </style:style>
        <style:style style:name="P3" style:family="paragraph">
            <style:paragraph-properties fo:text-align="left" fo:margin-top="0mm" fo:margin-bottom="0mm"/>
            <style:text-properties fo:font-size="6pt"/>
        </style:style>
        <style:style style:name="Table1" style:family="table">
            <style:table-properties style:width="49.8mm"/>
        </style:style>
        <style:style style:name="Table1.A" style:family="table-column">
            <style:table-column-properties style:column-width="6mm"/>
        </style:style>
        <style:style style:name="Table1.B" style:family="table-column">
            <style:table-column-properties style:column-width="43.8mm"/>
        </style:style>
    </office:automatic-styles>

    <office:body>
        <office:text>
            <table:table table:name="Table1" table:style-name="Table1">
                <table:table-column table:style-name="Table1.A"/>
                <table:table-column table:style-name="Table1.B"/>
                <table:table-row>
                    <table:table-cell>
                        <text:p text:style-name="P1">{label['box']}</text:p>
                        <text:p text:style-name="P1">{label['bin']}</text:p>
                    </table:table-cell>
                    <table:table-cell>
                        <text:p text:style-name="P2">{label['label1']}</text:p>
                        <text:p text:style-name="P3">{label['label2']}</text:p>
                        <text:p text:style-name="P3">{label['label3']}</text:p>
                    </table:table-cell>
                </table:table-row>
            </table:table>
        </office:text>
    </office:body>
</office:document-content>'''

    # styles.xml for page setup (12mm x 50mm label)
    styles_xml = '''<?xml version="1.0" encoding="UTF-8"?>
<office:document-styles
    xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"
    xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0"
    xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0"
    office:version="1.3">

    <office:automatic-styles>
        <style:page-layout style:name="pm1">
            <style:page-layout-properties
                fo:page-width="12mm"
                fo:page-height="50mm"
                fo:margin-top="0.5mm"
                fo:margin-bottom="0.5mm"
                fo:margin-left="0.5mm"
                fo:margin-right="0.5mm"
                style:print-orientation="portrait"/>
        </style:page-layout>
    </office:automatic-styles>

    <office:master-styles>
        <style:master-page style:name="Standard" style:page-layout-name="pm1"/>
    </office:master-styles>
</office:document-styles>'''

    # META-INF/manifest.xml
    manifest_xml = '''<?xml version="1.0" encoding="UTF-8"?>
<manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0" manifest:version="1.3">
    <manifest:file-entry manifest:full-path="/" manifest:media-type="application/vnd.oasis.opendocument.text"/>
    <manifest:file-entry manifest:full-path="content.xml" manifest:media-type="text/xml"/>
    <manifest:file-entry manifest:full-path="styles.xml" manifest:media-type="text/xml"/>
</manifest:manifest>'''

    # mimetype file (must be first in ZIP, uncompressed)
    mimetype = 'application/vnd.oasis.opendocument.text'

    # Create the ODT file
    with zipfile.ZipFile(output_file, 'w') as zf:
        # mimetype must be first and uncompressed
        zf.writestr('mimetype', mimetype, compress_type=zipfile.ZIP_STORED)
        zf.writestr('content.xml', content_xml)
        zf.writestr('styles.xml', styles_xml)
        zf.writestr('META-INF/manifest.xml', manifest_xml)

    print(f"Created ODT file: {output_file}")

    # Convert to PDF using LibreOffice
    pdf_file = output_file.replace('.odt', '.pdf')
    cmd = [
        'soffice',
        '--headless',
        '--convert-to', 'pdf',
        '--outdir', output_dir,
        output_file
    ]

    print(f"Converting to PDF...")
    result = subprocess.run(cmd, capture_output=True, text=True)

    if os.path.exists(pdf_file):
        print(f"Created PDF file: {pdf_file}")
    else:
        print(f"PDF conversion failed: {result.stderr}")

    return pdf_file

if __name__ == "__main__":
    main()

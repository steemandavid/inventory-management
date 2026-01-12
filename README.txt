LABEL PRINTING MACRO - KelderInventory
======================================
For Dymo LabelWriter 450 - 12mm x 50mm Labels (Dymo 30334)

INSTALLATION
------------
1. Open LibreOffice Calc
2. Open or create KelderInventory.ods
3. Go to Tools > Macros > Organize Macros > Basic
4. Select your document > Standard (or create new module)
5. Click "Edit" to open the Basic IDE
6. Copy and paste the contents of LabelPrintMacro.bas
7. Save and close the IDE

INITIAL SETUP
-------------
1. Run "InitializeConfigSheet" macro to create the Config sheet
   - Tools > Macros > Run Macro > [Your Doc] > Standard > InitializeConfigSheet

2. Set up your data sheet with these columns:
   Column A: Box (mandatory)
   Column B: Bin (mandatory)
   Column C: Label row 1 (mandatory)
   Column D: Label row 2 (optional)
   Column E: Label row 3 (optional)
   Column F: Keywords (not printed)
   Column G: Comments (not printed)

3. Add a Print button:
   - View > Toolbars > Form Controls
   - Click Push Button icon, draw button on sheet
   - Right-click button > Control Properties > set Label to "Print Label(s)"
   - Right-click button > Assign Macro > select PrintLabels

USAGE
-----
1. Select one or more rows containing label data
2. Click the "Print Label(s)" button
3. Labels will be validated and printed (or dry-run tested)

MACROS
------
- PrintLabels        : Main entry point - prints selected rows
- InitializeConfigSheet : Creates Config sheet with defaults
- ShowConfig         : Displays current configuration

CONFIGURATION (Config Sheet)
----------------------------
| Setting              | Default | Description                    |
|----------------------|---------|--------------------------------|
| Font Size - Box      | 8       | Font size for Box field        |
| Font Size - Bin      | 8       | Font size for Bin field        |
| Font Size - Label 1  | 7       | Font size for first label row  |
| Font Size - Label 2  | 6       | Font size for second label row |
| Font Size - Label 3  | 6       | Font size for third label row  |
| Output Mode          | DRY_RUN | PRINT or DRY_RUN               |

LABEL LAYOUT
------------
+--------+----------------------------------+
|  Box   |  Label row 1                     |
|  ---   |  Label row 2                     |
|  Bin   |  Label row 3                     |
+--------+----------------------------------+
  12%                 88%

- Left section (12%): Box above Bin, centered, bold
- Right section (88%): Label rows 1-3, left-aligned

VALIDATION
----------
Mandatory fields: Box (A), Bin (B), Label row 1 (C)
- Fail-fast mode: If any selected row is invalid, nothing prints
- Clear error messages show which rows/fields are missing

OUTPUT MODES
------------
- DRY_RUN: Shows a visual preview of each label at 100% zoom
           A Writer document opens showing the actual label layout
           Click OK to proceed to the next label preview
           Use this to verify content and formatting before printing
- PRINT:   Sends labels directly to the Dymo LabelWriter 450

PRINTER SETUP
-------------
Ensure printer is named "LabelWriter-450" in your system.
To check/change: System Settings > Printers

TROUBLESHOOTING
---------------
- "Config sheet not found": Run InitializeConfigSheet first
- "No rows selected": Select data rows (not header row 1)
- Validation errors: Check mandatory fields A, B, C are filled
- Printing issues: Verify printer name matches PRINTER_NAME constant

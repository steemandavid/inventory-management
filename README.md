# KelderInventory - Label Printing System

> **Status (2026-11-23):** this LibreOffice macro is being replaced by a web application
> (public search at steeman.be/inventory, admin editing, Dymo printing via a Raspberry Pi).
> See [`FSD_Web_Inventory.md`](FSD_Web_Inventory.md). The macro, spreadsheet and their
> scripts now live in [`legacy/`](legacy/); the instructions below describe that legacy setup.


A LibreOffice Calc macro system for printing inventory labels on Dymo LabelWriter printers. Designed for cross-platform use on both Windows and Linux.

## Features

- **One-click label printing** directly from LibreOffice Calc
- **Batch printing** - select multiple rows to print multiple labels
- **Cross-platform** - works on Windows and Linux without external tools
- **Configurable** - adjustable font sizes and output modes
- **Validation** - mandatory field checking with clear error messages
- **Dry-run mode** - preview labels as PDF before printing

## Requirements

- **LibreOffice Calc** (tested with LibreOffice 7.x+)
- **Dymo LabelWriter 450** (or compatible)
- **Dymo 30334 labels** (12mm × 50mm / ½" × 2")

## Installation

### 1. Download the Files

Clone this repository or download `KelderInventory.ods` and `LabelPrintMacro.bas`.

```bash
git clone https://github.com/steemandavid/inventory-management.git
```

### 2. Open the Spreadsheet

Open `KelderInventory.ods` in LibreOffice Calc. The macro is embedded in the file.

### 3. Initialize Configuration

Run the initialization macro to create the Config sheet:

1. Go to **Tools → Macros → Run Macro**
2. Navigate to **KelderInventory.ods → Standard**
3. Select **InitializeConfigSheet** and click **Run**

### 4. Printer Setup

Ensure your Dymo printer is named `LabelWriter-450` in your system:

- **Linux**: Check in CUPS (http://localhost:631) or System Settings → Printers
- **Windows**: Check in Control Panel → Devices and Printers

## Usage

### Data Entry

Enter your inventory data in the spreadsheet with these columns:

| Column | Field | Required | Printed |
|--------|-------|----------|---------|
| A | Box | Yes | Yes |
| B | Bin | Yes | Yes |
| C | Label row 1 | Yes | Yes |
| D | Label row 2 | No | Yes |
| E | Label row 3 | No | Yes |
| F | Keywords | No | No |
| G | Comments | No | No |

### Printing Labels

1. Select one or more data rows (not the header row)
2. Click the **Print Label(s)** button
3. Labels will be validated and printed

### Preview Mode (Dry-Run)

To preview labels before printing:

1. Open the **Config** sheet
2. Set **Output Mode** to `DRY_RUN`
3. Click **Print Label(s)** - a PDF preview will be generated

## Label Layout

```
┌────────────────────────────────────────────────┐
│ Label row 1 (bold)                        03   │
│ Label row 2                               02   │
│ Label row 3                                    │
└────────────────────────────────────────────────┘
         Labels (78%)                    Box/Bin
                                         (18%)
```

- **Labels section** (left, 78%): Up to 3 lines of text, left-aligned
- **Box/Bin section** (right, 18%): Box number above Bin number, centered

## Configuration

The **Config** sheet contains these settings:

| Setting | Default | Description |
|---------|---------|-------------|
| Font Size - Box | 14 | Font size in points for Box number |
| Font Size - Bin | 12 | Font size in points for Bin number |
| Font Size - Label 1 | 9 | Font size for first label line (bold) |
| Font Size - Label 2 | 7 | Font size for second label line |
| Font Size - Label 3 | 6 | Font size for third label line |
| Output Mode | DRY_RUN | `PRINT` or `DRY_RUN` |
| Show Complete Message | NO | Show completion dialog (`YES`/`NO`) |
| Export Path | (document folder) | PDF export location for dry-run |

## Available Macros

| Macro | Description |
|-------|-------------|
| `PrintLabels` | Main entry point - prints selected rows |
| `InitializeConfigSheet` | Creates/resets Config sheet with defaults |
| `ShowConfig` | Displays current configuration |

## Troubleshooting

| Issue | Solution |
|-------|----------|
| "Config sheet not found" | Run `InitializeConfigSheet` macro |
| "No rows selected" | Select data rows (not header row 1) |
| Validation errors | Check mandatory fields A, B, C are filled |
| Printing issues | Verify printer name matches `LabelWriter-450` |
| Labels not aligned | Ensure Dymo driver is installed correctly |

## Project Structure

```
inventory-management/
├── KelderInventory.ods      # Main spreadsheet with embedded macro
├── LabelPrintMacro.bas      # Macro source code (for reference/backup)
├── FSD_Label_Macro_12x50mm.md  # Functional specification document
├── README.md                # This file
├── README.txt               # Legacy readme
├── CHANGELOG.md             # Version history
├── LICENSE                  # License file
├── generate_label.py        # Python script for headless generation (optional)
└── test_label.py            # Test script (development)
```

## Technical Details

### How It Works

1. The macro creates a hidden LibreOffice Writer document
2. Page is set to portrait orientation (12mm × 50mm) matching the physical label
3. Text is rotated 90° counter-clockwise for correct reading orientation
4. Document is either exported to PDF (dry-run) or sent to printer
5. The hidden document is closed automatically

### Cross-Platform Compatibility

The macro uses only LibreOffice's built-in printing capabilities without relying on:
- Shell commands (`lp`, `print`, etc.)
- Operating system-specific APIs
- External PDF tools

This ensures consistent behavior on both Windows and Linux.

## Contributing

Contributions are welcome! Please feel free to submit issues or pull requests.

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## Acknowledgments

- Developed for basement/cellar inventory management
- Inspired by the need for quick, reliable label printing
- Built with LibreOffice Basic and UNO API

## Version History

See [CHANGELOG.md](CHANGELOG.md) for detailed version history.

---

**Author**: Barraam
**Repository**: [github.com/steemandavid/inventory-management](https://github.com/steemandavid/inventory-management)

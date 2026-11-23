# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.2.0] - 2026-11-23

### Added
- Every backup set also has an `.sql` file: a MariaDB dump of the `inv_*` tables (items, settings, history,
  print jobs; structure only for admin, sessions, login attempts, rate and agent, so no credentials). It goes
  along the existing path: LWS, the Pi, john-ai, Borg, offsite. The Pi checks that the dump is complete and
  has the same item count; older sets without `.sql` stay valid. Download in Admin, Backups.

### Changed
- Enclosure base: screw holes 2.7 mm (were 2.9: the screw heads fell through), counterbores 3.4 mm wide and
  1 mm deep (were 5.6 x 2.2), rubber feet recesses removed (didn't print well).

## [2.0.0] - 2026-11-23

### Added
- Web app at https://www.steeman.be/inventory/ (PHP 8.4 + MariaDB on LWS), replacing the spreadsheet:
  public live search, admin login, add/edit/duplicate/delete, history with restore, label settings,
  live label preview.
- Print queue and print agent on a Raspberry Pi (labelpi) with the Dymo LabelWriter 450: print one label,
  selected labels, or "Save, print label, add another" from any device.
- Weekly backups (.ods + .json) on LWS, on the Pi and in john-ai's Borg backup; retention 10 newest +
  first of every month; restore from .json or .ods; stale-backup warning in the Monday summary email.
- Deploy script with post-deploy security checks; docker dev environment; 123 HTTP tests.

### Changed
- Labels are now rendered by ReportLab on the Pi for Dymo 99017 labels (CUPS media w36h144), with the
  printable area measured in the Phase 0 print test.
- The LibreOffice macro, spreadsheet and scripts moved to `legacy/`.

## [1.4.0] - 2026-01-26

### Changed
- Refactored label layout for cross-platform Dymo printing
- Changed from landscape to portrait document orientation to match Dymo printer feed direction
- Implemented 90° counter-clockwise text rotation for correct reading orientation
- Converted 2-column table layout to 2-row table layout
- Labels section now uses 78% of label area, Box/Bin section uses 18%
- Simplified print options for better cross-platform compatibility

### Fixed
- Labels now print correctly on both Windows and Linux
- Resolved orientation issues with Dymo LabelWriter driver
- Fixed text alignment to position labels closer to Box/Bin section

### Removed
- Removed OS-specific shell commands for printing
- Removed external tool dependencies

## [1.3.0] - 2026-01-21

### Fixed
- Fixed label layout for Dymo 30334 12x50mm labels
- Adjusted margins and spacing for better label appearance
- Improved text positioning within label sections

## [1.2.0] - 2026-01-13

### Added
- Configuration sheet for customizable font sizes
- Output mode setting (PRINT/DRY_RUN)
- Show completion message option
- Export path configuration for PDF previews

### Changed
- Improved label printing workflow
- Enhanced validation with clearer error messages

### Security
- Added samba-share.conf to .gitignore to prevent credential exposure

## [1.1.0] - 2026-01-13

### Added
- Label printing improvements
- Configuration options for font sizes
- Dry-run mode for preview without printing

### Changed
- Work in progress improvements to label formatting

## [1.0.0] - 2026-01-12

### Added
- Initial release of KelderInventory label printing macro
- Support for Dymo LabelWriter 450
- Support for Dymo 30334 labels (12mm × 50mm)
- LibreOffice Calc integration with Print Label(s) button
- Batch printing via multi-row selection
- Mandatory field validation (Box, Bin, Label row 1)
- Configurable layout with Box/Bin and Label sections
- Dry-run mode for testing
- Configuration worksheet for settings

### Technical
- LibreOffice Basic macro implementation
- UNO API integration for document creation and printing
- Cross-platform design (Windows/Linux)

---

## Version Numbering

- **Major version**: Breaking changes or significant feature additions
- **Minor version**: New features, improvements, or notable fixes
- **Patch version**: Bug fixes and minor improvements

## Links

- [Repository](https://github.com/steemandavid/inventory-management)
- [Issues](https://github.com/steemandavid/inventory-management/issues)

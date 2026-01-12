# Functional Specification Document  
**Macro for Label Printing 12×50 mm**

---

## Document Information

| Item | Value |
|-----|-------|
| Version | 1.4 |
| Date | 2026-01-12 |
| Author | Barraam |

---

## 1. Introduction

This document describes the functional specifications for a macro-based system that automates the printing of **12 mm × 50 mm labels** directly from a **LibreOffice Calc** spreadsheet named **KelderInventory.ods**.

The system allows users to select one or more rows in the worksheet and generate consistently formatted labels by clicking a button, with layout and behavior controlled through a dedicated configuration worksheet.

---

## 2. System Overview

### 2.1 Purpose

The purpose of the system is to:
- Use a LibreOffice Calc spreadsheet as both **data source and control interface**
- Allow label printing to be initiated directly from the spreadsheet
- Convert selected rows into formatted labels for a specific Dymo label type
- Support both physical printing and dry-run (non-printing) output modes

---

### 2.2 Primary Goals

- Ease of use through a single in-sheet print button  
- Consistent and repeatable label layout  
- Batch printing via multi-row selection  
- User-configurable font sizes and output mode  
- Mandatory field validation with clear feedback  
- Dry-run mode for testing and validation  

---

## 3. System Architecture

### 3.1 Input File

- Filename: **KelderInventory.ods**
- Format: LibreOffice Calc
- Contents:
  - Data worksheet with label rows
  - Embedded macro
  - Configuration worksheet

Each data row represents exactly one label.

---

### 3.2 Label Printing Trigger

- Printing is initiated by clicking a **Print Label(s)** button inside the Calc file
- The macro processes the currently selected row(s)
- One label is generated per selected row

---

### 3.3 Processing Flow

1. User selects one or more rows
2. User clicks the Print Label(s) button
3. Macro validates mandatory fields
4. Layout and configuration values are read
5. Label output is generated
6. Output is either printed or suppressed (dry-run)

---

### 3.4 Printer and Label Target

- Printer model: Dymo LabelWriter 450  
- Printer name: `LabelWriter-450`

**Label specification**
- Dymo part number: 30334  
- Size: ½″ × 2″ (12 mm × 50 mm)

---

### 3.5 Print vs Dry-Run Mode

Controlled via configuration worksheet flag:

- `PRINT` – labels are sent to the printer
- `DRY_RUN` – labels are generated but not printed

---

## 4. Data Overview

### 4.1 Spreadsheet Columns

| Column | Header | Printed |
|------|--------|---------|
| A | Box | Yes |
| B | Bin | Yes |
| C | Label row 1 | Yes |
| D | Label row 2 | Yes |
| E | Label row 3 | Yes |
| F | Keywords | No |
| G | Comments | No |

---

### 4.2 Mandatory Fields

The following fields are mandatory:
- Column A – Box  
- Column B – Bin  
- Column C – Label row 1  

---

## 5. Label Layout & Formatting

### 5.1 Layout

- Label size: 12 mm × 50 mm
- Margins: minimal, printer-limited

#### Left Section – Box & Bin
- Width: 12% of label
- Alignment: centered
- Box above Bin
- Larger, configurable font sizes

#### Right Section – Label Rows
- Width: 88% of label
- Alignment: left-aligned
- Rows C, D, E stacked vertically
- Individually configurable font sizes

---

### 5.2 Formatting Rules

- A & B: centered text
- C, D, E: left-aligned text
- Text scaled, wrapped, or clipped only as required

---

## 6. Configuration Worksheet

The configuration worksheet (e.g. **Config**) contains:
- Font size for Box (A)
- Font size for Bin (B)
- Font size for Label row 1 (C)
- Font size for Label row 2 (D)
- Font size for Label row 3 (E)
- Output mode flag (`PRINT` / `DRY_RUN`)

---

## 7. Exception Handling & User Feedback

### 7.1 Missing Mandatory Fields

If the Print Label(s) button is clicked while selected rows contain missing mandatory fields:

1. Validation occurs before any printing
2. Invalid rows are not processed
3. User is notified with:
   - Row number(s)
   - Missing field(s)

---

### 7.2 Mixed Valid and Invalid Rows

Supported behaviors (one must be chosen and documented in implementation):

- **Fail-fast (default)**: nothing prints if any row is invalid  
- **Partial processing**: valid rows print, invalid rows skipped with warning  

---

### 7.3 Dry-Run Mode

- Validation rules apply identically
- No printer interaction occurs

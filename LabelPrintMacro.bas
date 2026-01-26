REM  *****  BASIC  *****
REM  Label Printing Macro for 12×50mm Dymo Labels
REM  Version: 1.4
REM  For use with KelderInventory.ods
REM
REM  Label: Dymo 30334 (12mm × 50mm)
REM  Printer: Dymo LabelWriter 450

Option Explicit

REM ============================================================
REM  Constants
REM ============================================================
Const PRINTER_NAME = "LabelWriter-450"
Const CONFIG_SHEET = "Config"

REM Label dimensions in 1/100 mm
Const LABEL_WIDTH = 5000    ' 50mm
Const LABEL_HEIGHT = 1200   ' 12mm

REM Layout proportions
Const LEFT_SECTION_RATIO = 0.12   ' 12% for Box/Bin
Const RIGHT_SECTION_RATIO = 0.88  ' 88% for Label rows

REM Column indices (0-based)
Const COL_BOX = 0        ' Column A
Const COL_BIN = 1        ' Column B
Const COL_LABEL1 = 2     ' Column C
Const COL_LABEL2 = 3     ' Column D
Const COL_LABEL3 = 4     ' Column E

REM Configuration row indices (0-based, assuming column A = label, column B = value)
Const CFG_COL_VALUE = 1
Const CFG_ROW_FONT_BOX = 1
Const CFG_ROW_FONT_BIN = 2
Const CFG_ROW_FONT_LABEL1 = 3
Const CFG_ROW_FONT_LABEL2 = 4
Const CFG_ROW_FONT_LABEL3 = 5
Const CFG_ROW_OUTPUT_MODE = 6
Const CFG_ROW_SHOW_COMPLETE = 7
Const CFG_ROW_EXPORT_PATH = 8

REM ============================================================
REM  Configuration Type
REM ============================================================
Type LabelConfig
    FontSizeBox As Single
    FontSizeBin As Single
    FontSizeLabel1 As Single
    FontSizeLabel2 As Single
    FontSizeLabel3 As Single
    OutputMode As String
    IsDryRun As Boolean
    ShowCompleteMsg As Boolean
    ExportPath As String
End Type

REM ============================================================
REM  Label Data Type
REM ============================================================
Type LabelData
    RowNum As Long
    Box As String
    Bin As String
    Label1 As String
    Label2 As String
    Label3 As String
    IsValid As Boolean
    ErrorMsg As String
End Type

REM ============================================================
REM  Main Entry Point - Called by Print Button
REM ============================================================
Sub PrintLabels()
    Dim oDoc As Object
    Dim oSheet As Object
    Dim oSelection As Object
    Dim config As LabelConfig
    Dim labels As Variant
    Dim validLabels() As Variant
    Dim invalidLabels() As Variant
    Dim currentLabel As LabelData
    Dim i As Integer
    Dim validCount As Integer
    Dim invalidCount As Integer

    On Error GoTo ErrorHandler

    oDoc = ThisComponent

    REM Get the active sheet (data sheet)
    oSheet = oDoc.CurrentController.ActiveSheet

    REM Don't process if we're on the Config sheet
    If oSheet.Name = CONFIG_SHEET Then
        MsgBox "Please select rows on the data worksheet, not the Config sheet.", _
               48, "Label Print"
        Exit Sub
    End If

    REM Get current selection
    oSelection = oDoc.CurrentController.getSelection()

    REM Read configuration
    config = ReadConfiguration(oDoc)
    If config.FontSizeBox = 0 Then
        Exit Sub  ' Error already shown
    End If

    REM Extract label data from selection
    labels = GetSelectedLabels(oSheet, oSelection)

    On Error Resume Next
    Dim upperBound As Integer
    upperBound = UBound(labels)
    If Err <> 0 Or upperBound < 0 Then
        On Error GoTo ErrorHandler
        MsgBox "No rows selected. Please select one or more data rows.", _
               48, "Label Print"
        Exit Sub
    End If
    On Error GoTo ErrorHandler

    REM Validate labels and separate valid from invalid
    validCount = 0
    invalidCount = 0

    For i = 0 To UBound(labels)
        currentLabel = labels(i)
        ValidateLabelData(currentLabel)
        labels(i) = currentLabel
        If currentLabel.IsValid Then
            validCount = validCount + 1
            ReDim Preserve validLabels(validCount - 1)
            validLabels(validCount - 1) = currentLabel
        Else
            invalidCount = invalidCount + 1
            ReDim Preserve invalidLabels(invalidCount - 1)
            invalidLabels(invalidCount - 1) = currentLabel
        End If
    Next i

    REM Handle validation results (Fail-fast mode)
    If invalidCount > 0 Then
        Dim errorReport As String
        Dim invalidLabel As LabelData
        errorReport = "Validation failed for " & invalidCount & " row(s):" & Chr(10) & Chr(10)
        For i = 0 To UBound(invalidLabels)
            invalidLabel = invalidLabels(i)
            errorReport = errorReport & "Row " & invalidLabel.RowNum & ": " & _
                         invalidLabel.ErrorMsg & Chr(10)
        Next i
        errorReport = errorReport & Chr(10) & "No labels were printed."
        MsgBox errorReport, 48, "Validation Error"
        Exit Sub
    End If

    REM Process valid labels
    Dim printedCount As Integer
    Dim validLabel As LabelData
    printedCount = 0

    For i = 0 To UBound(validLabels)
        validLabel = validLabels(i)
        If PrintSingleLabel(validLabel, config) Then
            printedCount = printedCount + 1
        End If
    Next i

    REM Show completion message (if enabled)
    If config.ShowCompleteMsg Then
        Dim modeText As String
        If config.IsDryRun Then
            modeText = "DRY-RUN: "
        Else
            modeText = ""
        End If

        MsgBox modeText & printedCount & " label(s) processed successfully.", _
               64, "Label Print Complete"
    End If

    Exit Sub

ErrorHandler:
    MsgBox "Error: " & Err & " - " & Error$, 16, "Label Print Error"
End Sub

REM ============================================================
REM  Read Configuration from Config Worksheet
REM ============================================================
Function ReadConfiguration(oDoc As Object) As LabelConfig
    Dim config As LabelConfig
    Dim oConfigSheet As Object

    On Error Resume Next
    oConfigSheet = oDoc.Sheets.getByName(CONFIG_SHEET)
    If Err <> 0 Then
        MsgBox "Configuration sheet '" & CONFIG_SHEET & "' not found!" & Chr(10) & _
               "Please create a Config sheet with the required settings.", _
               16, "Configuration Error"
        config.FontSizeBox = 0
        ReadConfiguration = config
        Exit Function
    End If
    On Error GoTo 0

    REM Read font sizes
    config.FontSizeBox = oConfigSheet.getCellByPosition(CFG_COL_VALUE, CFG_ROW_FONT_BOX).Value
    config.FontSizeBin = oConfigSheet.getCellByPosition(CFG_COL_VALUE, CFG_ROW_FONT_BIN).Value
    config.FontSizeLabel1 = oConfigSheet.getCellByPosition(CFG_COL_VALUE, CFG_ROW_FONT_LABEL1).Value
    config.FontSizeLabel2 = oConfigSheet.getCellByPosition(CFG_COL_VALUE, CFG_ROW_FONT_LABEL2).Value
    config.FontSizeLabel3 = oConfigSheet.getCellByPosition(CFG_COL_VALUE, CFG_ROW_FONT_LABEL3).Value

    REM Read output mode
    config.OutputMode = UCase(Trim(oConfigSheet.getCellByPosition(CFG_COL_VALUE, CFG_ROW_OUTPUT_MODE).String))
    config.IsDryRun = (config.OutputMode = "DRY_RUN")

    REM Read show complete message setting (YES/NO, default YES)
    Dim showCompleteValue As String
    showCompleteValue = UCase(Trim(oConfigSheet.getCellByPosition(CFG_COL_VALUE, CFG_ROW_SHOW_COMPLETE).String))
    config.ShowCompleteMsg = (showCompleteValue <> "NO")

    REM Read export path (default to document's folder)
    config.ExportPath = Trim(oConfigSheet.getCellByPosition(CFG_COL_VALUE, CFG_ROW_EXPORT_PATH).String)
    If config.ExportPath = "" Then
        REM Use document's folder as default
        Dim docURL As String
        Dim lastSlash As Integer
        Dim i As Integer
        docURL = oDoc.URL
        If docURL <> "" Then
            REM Find last slash (InStrRev replacement)
            lastSlash = 0
            For i = Len(docURL) To 1 Step -1
                If Mid(docURL, i, 1) = "/" Then
                    lastSlash = i
                    Exit For
                End If
            Next i
            If lastSlash > 0 Then
                config.ExportPath = Left(docURL, lastSlash)
            End If
        End If
    End If

    REM Apply defaults if values are missing (font sizes in points)
    REM Optimized for 12mm label height (~34 points)
    If config.FontSizeBox <= 0 Then config.FontSizeBox = 14
    If config.FontSizeBin <= 0 Then config.FontSizeBin = 12
    If config.FontSizeLabel1 <= 0 Then config.FontSizeLabel1 = 9
    If config.FontSizeLabel2 <= 0 Then config.FontSizeLabel2 = 7
    If config.FontSizeLabel3 <= 0 Then config.FontSizeLabel3 = 6

    ReadConfiguration = config
End Function

REM ============================================================
REM  Get Label Data from Selected Rows
REM ============================================================
Function GetSelectedLabels(oSheet As Object, oSelection As Object) As Variant
    Dim labels() As Variant
    Dim labelCount As Integer
    Dim i As Long
    Dim startRow As Long
    Dim endRow As Long
    Dim oRanges As Object
    Dim oRange As Object
    Dim r As Integer
    Dim tempLabel As LabelData
    Dim emptyResult() As Variant

    labelCount = 0

    REM Handle different selection types
    If oSelection.supportsService("com.sun.star.sheet.SheetCellRanges") Then
        REM Multiple non-contiguous selections
        oRanges = oSelection
        For r = 0 To oRanges.Count - 1
            oRange = oRanges.getByIndex(r)
            startRow = oRange.RangeAddress.StartRow
            endRow = oRange.RangeAddress.EndRow

            For i = startRow To endRow
                If i > 0 Then  ' Skip header row
                    tempLabel = ReadLabelFromRow(oSheet, i)
                    If labelCount = 0 Then
                        ReDim labels(0)
                    Else
                        ReDim Preserve labels(labelCount)
                    End If
                    labels(labelCount) = tempLabel
                    labelCount = labelCount + 1
                End If
            Next i
        Next r
    ElseIf oSelection.supportsService("com.sun.star.sheet.SheetCellRange") Then
        REM Single contiguous selection
        startRow = oSelection.RangeAddress.StartRow
        endRow = oSelection.RangeAddress.EndRow

        For i = startRow To endRow
            If i > 0 Then  ' Skip header row
                tempLabel = ReadLabelFromRow(oSheet, i)
                If labelCount = 0 Then
                    ReDim labels(0)
                Else
                    ReDim Preserve labels(labelCount)
                End If
                labels(labelCount) = tempLabel
                labelCount = labelCount + 1
            End If
        Next i
    ElseIf oSelection.supportsService("com.sun.star.sheet.SheetCell") Then
        REM Single cell selected
        startRow = oSelection.CellAddress.Row
        If startRow > 0 Then  ' Skip header row
            tempLabel = ReadLabelFromRow(oSheet, startRow)
            ReDim labels(0)
            labels(0) = tempLabel
            labelCount = 1
        End If
    End If

    REM Return array or empty variant
    If labelCount > 0 Then
        GetSelectedLabels = labels
    Else
        GetSelectedLabels = emptyResult
    End If
End Function

REM Helper to get label count (returns -1 if empty/invalid)
Function GetLabelCount(labels As Variant) As Integer
    On Error Resume Next
    GetLabelCount = UBound(labels)
    If Err <> 0 Then
        GetLabelCount = -1
    End If
    On Error GoTo 0
End Function

REM ============================================================
REM  Read Label Data from a Single Row
REM ============================================================
Function ReadLabelFromRow(oSheet As Object, rowIndex As Long) As LabelData
    Dim label As LabelData

    label.RowNum = rowIndex + 1  ' Convert to 1-based for display
    label.Box = Trim(oSheet.getCellByPosition(COL_BOX, rowIndex).String)
    label.Bin = Trim(oSheet.getCellByPosition(COL_BIN, rowIndex).String)
    label.Label1 = Trim(oSheet.getCellByPosition(COL_LABEL1, rowIndex).String)
    label.Label2 = Trim(oSheet.getCellByPosition(COL_LABEL2, rowIndex).String)
    label.Label3 = Trim(oSheet.getCellByPosition(COL_LABEL3, rowIndex).String)
    label.IsValid = True
    label.ErrorMsg = ""

    ReadLabelFromRow = label
End Function

REM ============================================================
REM  Validate a Label (Check Mandatory Fields)
REM ============================================================
Sub ValidateLabelData(label As LabelData)
    Dim errors As String
    errors = ""

    If label.Box = "" Then
        errors = errors & "Box (A), "
    End If

    If label.Bin = "" Then
        errors = errors & "Bin (B), "
    End If

    If label.Label1 = "" Then
        errors = errors & "Label row 1 (C), "
    End If

    If errors <> "" Then
        label.IsValid = False
        label.ErrorMsg = "Missing: " & Left(errors, Len(errors) - 2)
    Else
        label.IsValid = True
        label.ErrorMsg = ""
    End If
End Sub

REM ============================================================
REM  Print a Single Label
REM ============================================================
Function PrintSingleLabel(label As LabelData, config As LabelConfig) As Boolean
    Dim oDesktop As Object
    Dim oLabelDoc As Object
    Dim oText As Object
    Dim oCursor As Object
    Dim oTable As Object
    Dim oPageStyles As Object
    Dim oPageStyle As Object
    Dim printOpts(0) As New com.sun.star.beans.PropertyValue

    On Error GoTo PrintError

    REM Use config font sizes directly as point sizes
    Dim fontBox As Single
    Dim fontBin As Single
    Dim fontLabel1 As Single
    Dim fontLabel2 As Single
    Dim fontLabel3 As Single

    fontBox = config.FontSizeBox
    fontBin = config.FontSizeBin
    fontLabel1 = config.FontSizeLabel1
    fontLabel2 = config.FontSizeLabel2
    fontLabel3 = config.FontSizeLabel3

    REM Create a new Writer document for the label
    oDesktop = CreateUnoService("com.sun.star.frame.Desktop")

    Dim loadProps(0) As New com.sun.star.beans.PropertyValue
    loadProps(0).Name = "Hidden"
    loadProps(0).Value = True

    oLabelDoc = oDesktop.loadComponentFromURL("private:factory/swriter", "_blank", 0, loadProps())

    REM Configure page size for label - PORTRAIT orientation to match Dymo
    REM Dymo 30334: 12mm x 50mm label (portrait feed)
    REM Content is rotated 90° CCW so it reads correctly when label is rotated CW
    oPageStyles = oLabelDoc.StyleFamilies.getByName("PageStyles")
    oPageStyle = oPageStyles.getByName("Standard")

    oPageStyle.IsLandscape = False
    oPageStyle.Width = LABEL_HEIGHT   ' 12mm (1200 in 1/100mm) - narrow
    oPageStyle.Height = LABEL_WIDTH   ' 50mm (5000 in 1/100mm) - long
    oPageStyle.LeftMargin = 0
    oPageStyle.RightMargin = 0
    oPageStyle.TopMargin = 0
    oPageStyle.BottomMargin = 0

    REM Usable dimensions in portrait: 12mm wide x 50mm tall
    Dim usableWidth As Long
    Dim usableHeight As Long
    usableWidth = LABEL_HEIGHT        ' 12mm
    usableHeight = LABEL_WIDTH        ' 50mm

    REM Create document content with rotated text (90° CCW = 900 tenths of degree)
    REM Layout: Box/Bin at bottom of page (right side when rotated), Labels at top (left side when rotated)
    oText = oLabelDoc.Text
    oCursor = oText.createTextCursor()
    oCursor.ParaTopMargin = 0
    oCursor.ParaBottomMargin = 0

    REM Create table with 2 rows: Labels (top, 80%), Box/Bin (bottom, 20%)
    oTable = oLabelDoc.createInstance("com.sun.star.text.TextTable")
    oTable.initialize(2, 1)           ' 2 rows, 1 column
    oText.insertTextContent(oCursor, oTable, False)

    REM Set table dimensions
    oTable.Width = usableWidth
    oTable.HoriOrient = com.sun.star.text.HoriOrientation.NONE
    oTable.LeftMargin = 0
    oTable.RightMargin = 0
    oTable.TopMargin = 0
    oTable.BottomMargin = 0

    REM Set row heights: 80% for Labels, 20% for Box/Bin (leave room for trailing para)
    Dim oRows As Object
    Dim oRow1 As Object
    Dim oRow2 As Object
    oRows = oTable.getRows()
    oRow1 = oRows.getByIndex(0)       ' Labels
    oRow2 = oRows.getByIndex(1)       ' Box/Bin

    REM Total should be less than usableHeight to prevent page overflow
    oRow1.Height = usableHeight * 0.78   ' ~39mm for labels
    oRow1.IsAutoHeight = False
    oRow2.Height = usableHeight * 0.18   ' ~9mm for box/bin
    oRow2.IsAutoHeight = False

    REM Remove all borders
    Dim oNoBorder As New com.sun.star.table.BorderLine2
    oNoBorder.OuterLineWidth = 0
    oNoBorder.InnerLineWidth = 0
    oNoBorder.LineDistance = 0
    oNoBorder.LineWidth = 0

    Dim oCellA1 As Object
    Dim oCellA2 As Object
    oCellA1 = oTable.getCellByName("A1")  ' Labels (top = left after rotation)
    oCellA2 = oTable.getCellByName("A2")  ' Box/Bin (bottom = right after rotation)

    oCellA1.TopBorder = oNoBorder
    oCellA1.BottomBorder = oNoBorder
    oCellA1.LeftBorder = oNoBorder
    oCellA1.RightBorder = oNoBorder
    oCellA2.TopBorder = oNoBorder
    oCellA2.BottomBorder = oNoBorder
    oCellA2.LeftBorder = oNoBorder
    oCellA2.RightBorder = oNoBorder

    REM === Top Cell (A1): Label Rows - text rotated 90° CCW ===
    REM VertOrient.BOTTOM positions text at bottom of cell, which becomes right side
    REM after 90° CW rotation (closer to Box/Bin section)
    Dim oTopText As Object
    Dim oTopCursor As Object
    oTopText = oCellA1.getText()
    oTopCursor = oTopText.createTextCursor()

    oCellA1.VertOrient = com.sun.star.text.VertOrientation.BOTTOM

    REM Label row 1 (rotated text)
    oTopCursor.ParaAdjust = com.sun.star.style.ParagraphAdjust.LEFT
    oTopCursor.ParaTopMargin = 0
    oTopCursor.ParaBottomMargin = 0
    oTopCursor.CharRotation = 900     ' 90° counter-clockwise
    oTopCursor.CharHeight = fontLabel1
    oTopCursor.CharWeight = com.sun.star.awt.FontWeight.BOLD
    oTopText.insertString(oTopCursor, label.Label1, False)

    REM Label row 2 (if present)
    If label.Label2 <> "" Then
        oTopText.insertControlCharacter(oTopCursor, com.sun.star.text.ControlCharacter.LINE_BREAK, False)
        oTopCursor.CharRotation = 900
        oTopCursor.CharHeight = fontLabel2
        oTopCursor.CharWeight = com.sun.star.awt.FontWeight.NORMAL
        oTopText.insertString(oTopCursor, label.Label2, False)
    End If

    REM Label row 3 (if present)
    If label.Label3 <> "" Then
        oTopText.insertControlCharacter(oTopCursor, com.sun.star.text.ControlCharacter.LINE_BREAK, False)
        oTopCursor.CharRotation = 900
        oTopCursor.CharHeight = fontLabel3
        oTopText.insertString(oTopCursor, label.Label3, False)
    End If

    REM === Bottom Cell (A2): Box and Bin - text rotated 90° CCW ===
    Dim oBottomText As Object
    Dim oBottomCursor As Object
    oBottomText = oCellA2.getText()
    oBottomCursor = oBottomText.createTextCursor()

    oCellA2.VertOrient = com.sun.star.text.VertOrientation.CENTER

    REM Box and Bin (rotated text)
    oBottomCursor.ParaAdjust = com.sun.star.style.ParagraphAdjust.CENTER
    oBottomCursor.ParaTopMargin = 0
    oBottomCursor.ParaBottomMargin = 0
    oBottomCursor.CharRotation = 900  ' 90° counter-clockwise
    oBottomCursor.CharHeight = fontBox
    oBottomCursor.CharWeight = com.sun.star.awt.FontWeight.BOLD
    oBottomText.insertString(oBottomCursor, label.Box, False)
    oBottomText.insertControlCharacter(oBottomCursor, com.sun.star.text.ControlCharacter.LINE_BREAK, False)

    REM Bin text
    oBottomCursor.CharRotation = 900
    oBottomCursor.CharHeight = fontBin
    oBottomCursor.CharWeight = com.sun.star.awt.FontWeight.BOLD
    oBottomText.insertString(oBottomCursor, label.Bin, False)

    REM Minimize trailing paragraph to prevent second page
    oCursor.gotoEnd(False)
    oCursor.CharHeight = 1
    oCursor.ParaTopMargin = 0
    oCursor.ParaBottomMargin = 0

    REM Print or dry-run
    If Not config.IsDryRun Then
        REM Set printer
        printOpts(0).Name = "Name"
        printOpts(0).Value = PRINTER_NAME
        oLabelDoc.Printer = printOpts()

        REM Print with Wait option
        Dim printJobOpts(1) As New com.sun.star.beans.PropertyValue
        printJobOpts(0).Name = "Wait"
        printJobOpts(0).Value = True
        printJobOpts(1).Name = "Pages"
        printJobOpts(1).Value = "1"
        oLabelDoc.print(printJobOpts())

        REM Close the label document
        oLabelDoc.close(False)
    Else
        REM Dry-run: Export to PDF for preview
        Dim exportURL As String
        Dim pdfFilename As String
        Dim pdfOpts(1) As New com.sun.star.beans.PropertyValue

        REM Build filename from label data
        pdfFilename = "label_" & label.Box & "_" & label.Bin & ".pdf"
        REM Sanitize filename (replace invalid characters)
        pdfFilename = Replace(pdfFilename, "/", "-")
        pdfFilename = Replace(pdfFilename, "\", "-")
        pdfFilename = Replace(pdfFilename, ":", "-")
        pdfFilename = Replace(pdfFilename, " ", "_")

        REM Build full export URL
        If config.ExportPath <> "" Then
            exportURL = config.ExportPath & pdfFilename
        Else
            REM Fallback: use temp directory
            exportURL = "file:///tmp/" & pdfFilename
        End If

        REM PDF export filter
        pdfOpts(0).Name = "FilterName"
        pdfOpts(0).Value = "writer_pdf_Export"
        pdfOpts(1).Name = "Overwrite"
        pdfOpts(1).Value = True

        REM Export to PDF
        oLabelDoc.storeToURL(exportURL, pdfOpts())

        REM Close the label document
        oLabelDoc.close(False)
    End If

    PrintSingleLabel = True
    Exit Function

PrintError:
    On Error Resume Next
    If Not IsNull(oLabelDoc) Then
        oLabelDoc.close(False)
    End If
    PrintSingleLabel = False
End Function

REM ============================================================
REM  Utility: Show Configuration Dialog (Optional)
REM ============================================================
Sub ShowConfig()
    Dim oDoc As Object
    Dim config As LabelConfig
    Dim msg As String

    oDoc = ThisComponent
    config = ReadConfiguration(oDoc)

    msg = "Current Label Configuration:" & Chr(10) & Chr(10)
    msg = msg & "Font Size - Box: " & config.FontSizeBox & " pt" & Chr(10)
    msg = msg & "Font Size - Bin: " & config.FontSizeBin & " pt" & Chr(10)
    msg = msg & "Font Size - Label 1: " & config.FontSizeLabel1 & " pt" & Chr(10)
    msg = msg & "Font Size - Label 2: " & config.FontSizeLabel2 & " pt" & Chr(10)
    msg = msg & "Font Size - Label 3: " & config.FontSizeLabel3 & " pt" & Chr(10)
    msg = msg & Chr(10) & "Output Mode: " & config.OutputMode

    If config.IsDryRun Then
        msg = msg & " (PDF export mode)"
        msg = msg & Chr(10) & "Export Path: " & config.ExportPath
    Else
        msg = msg & " (Printing enabled)"
    End If

    MsgBox msg, 64, "Label Configuration"
End Sub

REM ============================================================
REM  Initialize Config Sheet (Run once to set up)
REM ============================================================
Sub InitializeConfigSheet()
    Dim oDoc As Object
    Dim oSheets As Object
    Dim oSheet As Object
    Dim oCell As Object
    Dim sheetExists As Boolean
    Dim i As Integer

    oDoc = ThisComponent
    oSheets = oDoc.Sheets
    sheetExists = False

    REM Check if Config sheet exists
    For i = 0 To oSheets.Count - 1
        If oSheets.getByIndex(i).Name = CONFIG_SHEET Then
            sheetExists = True
            oSheet = oSheets.getByIndex(i)
            Exit For
        End If
    Next i

    REM Create sheet if it doesn't exist
    If Not sheetExists Then
        oSheets.insertNewByName(CONFIG_SHEET, oSheets.Count)
        oSheet = oSheets.getByName(CONFIG_SHEET)
    End If

    REM Verify sheet was obtained
    If IsNull(oSheet) Then
        MsgBox "Error: Could not create or access Config sheet.", 16, "Error"
        Exit Sub
    End If

    REM Set up headers and default values
    oSheet.getCellByPosition(0, 0).String = "Setting"
    oSheet.getCellByPosition(1, 0).String = "Value"

    oSheet.getCellByPosition(0, CFG_ROW_FONT_BOX).String = "Font Size - Box"
    oSheet.getCellByPosition(1, CFG_ROW_FONT_BOX).Value = 14

    oSheet.getCellByPosition(0, CFG_ROW_FONT_BIN).String = "Font Size - Bin"
    oSheet.getCellByPosition(1, CFG_ROW_FONT_BIN).Value = 12

    oSheet.getCellByPosition(0, CFG_ROW_FONT_LABEL1).String = "Font Size - Label 1"
    oSheet.getCellByPosition(1, CFG_ROW_FONT_LABEL1).Value = 9

    oSheet.getCellByPosition(0, CFG_ROW_FONT_LABEL2).String = "Font Size - Label 2"
    oSheet.getCellByPosition(1, CFG_ROW_FONT_LABEL2).Value = 7

    oSheet.getCellByPosition(0, CFG_ROW_FONT_LABEL3).String = "Font Size - Label 3"
    oSheet.getCellByPosition(1, CFG_ROW_FONT_LABEL3).Value = 6

    oSheet.getCellByPosition(0, CFG_ROW_OUTPUT_MODE).String = "Output Mode"
    oSheet.getCellByPosition(1, CFG_ROW_OUTPUT_MODE).String = "DRY_RUN"

    oSheet.getCellByPosition(0, CFG_ROW_SHOW_COMPLETE).String = "Show Complete Message"
    oSheet.getCellByPosition(1, CFG_ROW_SHOW_COMPLETE).String = "NO"

    oSheet.getCellByPosition(0, CFG_ROW_EXPORT_PATH).String = "PDF Export Path"
    oSheet.getCellByPosition(1, CFG_ROW_EXPORT_PATH).String = ""

    REM Add note about output mode
    oSheet.getCellByPosition(0, 10).String = "Output Mode Options:"
    oSheet.getCellByPosition(0, 11).String = "  PRINT - Send to printer"
    oSheet.getCellByPosition(0, 12).String = "  DRY_RUN - Export PDF (no printing)"
    oSheet.getCellByPosition(0, 13).String = "Show Complete Message: YES or NO"
    oSheet.getCellByPosition(0, 14).String = "PDF Export Path: Leave empty for document folder"

    REM Format header row
    Dim oRange As Object
    oRange = oSheet.getCellRangeByPosition(0, 0, 1, 0)
    oRange.CharWeight = com.sun.star.awt.FontWeight.BOLD
    oRange.CellBackColor = RGB(200, 200, 200)

    REM Auto-fit columns
    oSheet.Columns.getByIndex(0).OptimalWidth = True
    oSheet.Columns.getByIndex(1).OptimalWidth = True

    MsgBox "Config sheet initialized with default values." & Chr(10) & Chr(10) & _
           "Change 'Output Mode' to PRINT when ready to print labels.", _
           64, "Configuration Initialized"
End Sub

REM ============================================================
REM  Debug Preview - Always shows preview regardless of config
REM  (Temporary debug function - assign to a button for testing)
REM ============================================================
Sub DebugPreview()
    Dim oDoc As Object
    Dim oSheet As Object
    Dim oSelection As Object
    Dim config As LabelConfig
    Dim labels As Variant
    Dim currentLabel As LabelData
    Dim i As Integer

    On Error GoTo ErrorHandler

    oDoc = ThisComponent
    oSheet = oDoc.CurrentController.ActiveSheet

    If oSheet.Name = CONFIG_SHEET Then
        MsgBox "Please select rows on the data worksheet, not the Config sheet.", _
               48, "Debug Preview"
        Exit Sub
    End If

    oSelection = oDoc.CurrentController.getSelection()

    REM Read configuration but force DRY_RUN mode
    config = ReadConfiguration(oDoc)
    config.IsDryRun = True
    REM Respect ShowCompleteMsg setting from config

    If config.FontSizeBox = 0 Then
        Exit Sub
    End If

    labels = GetSelectedLabels(oSheet, oSelection)

    On Error Resume Next
    Dim upperBound As Integer
    upperBound = UBound(labels)
    If Err <> 0 Or upperBound < 0 Then
        On Error GoTo ErrorHandler
        MsgBox "No rows selected. Please select one or more data rows.", _
               48, "Debug Preview"
        Exit Sub
    End If
    On Error GoTo ErrorHandler

    REM Process first selected label only for quick debug
    currentLabel = labels(0)
    ValidateLabelData(currentLabel)

    If Not currentLabel.IsValid Then
        MsgBox "Row " & currentLabel.RowNum & ": " & currentLabel.ErrorMsg, _
               48, "Validation Error"
        Exit Sub
    End If

    REM Show preview
    If PrintSingleLabel(currentLabel, config) Then
        If config.ShowCompleteMsg Then
            MsgBox "Debug preview generated for row " & currentLabel.RowNum, _
                   64, "Debug Preview"
        End If
    End If

    Exit Sub

ErrorHandler:
    MsgBox "Error: " & Err & " - " & Error$, 16, "Debug Preview Error"
End Sub

REM ============================================================
REM  Test Label - Simple test to understand printer behavior
REM  Creates minimal document with just text, no table/frame
REM ============================================================
Sub TestLabel()
    Dim oDesktop As Object
    Dim oDoc As Object
    Dim oText As Object
    Dim oCursor As Object
    Dim oPageStyles As Object
    Dim oPageStyle As Object

    oDesktop = CreateUnoService("com.sun.star.frame.Desktop")

    REM Create visible document for testing
    oDoc = oDesktop.loadComponentFromURL("private:factory/swriter", "_blank", 0, Array())

    oPageStyles = oDoc.StyleFamilies.getByName("PageStyles")
    oPageStyle = oPageStyles.getByName("Standard")

    REM Test 1: 50mm x 12mm (landscape-style, wide label)
    oPageStyle.IsLandscape = False
    oPageStyle.Width = 50000        ' 50mm
    oPageStyle.Height = 12000       ' 12mm
    oPageStyle.LeftMargin = 500
    oPageStyle.RightMargin = 500
    oPageStyle.TopMargin = 500
    oPageStyle.BottomMargin = 500

    REM Add simple test text
    oText = oDoc.Text
    oCursor = oText.createTextCursor()
    oCursor.CharHeight = 8
    oCursor.ParaAdjust = com.sun.star.style.ParagraphAdjust.LEFT
    oText.insertString(oCursor, "TEST ABC 123", False)

    MsgBox "Test label created (50mm x 12mm)." & Chr(10) & Chr(10) & _
           "1. Look at the preview - is text horizontal or vertical?" & Chr(10) & _
           "2. Print it and compare to preview." & Chr(10) & _
           "3. Close this document when done.", _
           64, "Test Label"
End Sub

REM ============================================================
REM  Test Label 2 - Portrait orientation (12mm x 50mm)
REM ============================================================
Sub TestLabel2()
    Dim oDesktop As Object
    Dim oDoc As Object
    Dim oText As Object
    Dim oCursor As Object
    Dim oPageStyles As Object
    Dim oPageStyle As Object

    oDesktop = CreateUnoService("com.sun.star.frame.Desktop")

    REM Create visible document for testing
    oDoc = oDesktop.loadComponentFromURL("private:factory/swriter", "_blank", 0, Array())

    oPageStyles = oDoc.StyleFamilies.getByName("PageStyles")
    oPageStyle = oPageStyles.getByName("Standard")

    REM Test 2: 12mm x 50mm (portrait-style, tall label)
    oPageStyle.IsLandscape = False
    oPageStyle.Width = 12000        ' 12mm
    oPageStyle.Height = 50000       ' 50mm
    oPageStyle.LeftMargin = 500
    oPageStyle.RightMargin = 500
    oPageStyle.TopMargin = 500
    oPageStyle.BottomMargin = 500

    REM Add simple test text
    oText = oDoc.Text
    oCursor = oText.createTextCursor()
    oCursor.CharHeight = 8
    oCursor.ParaAdjust = com.sun.star.style.ParagraphAdjust.LEFT
    oText.insertString(oCursor, "TEST ABC 123", False)

    MsgBox "Test label created (12mm x 50mm)." & Chr(10) & Chr(10) & _
           "1. Look at the preview - is text horizontal or vertical?" & Chr(10) & _
           "2. Print it and compare to preview." & Chr(10) & _
           "3. Close this document when done.", _
           64, "Test Label 2"
End Sub

REM ============================================================
REM  Test Label 3 - Portrait page with rotated text (90° CCW)
REM  Based on TestLabel2 findings - text needs rotation to be readable
REM ============================================================
Sub TestLabel3()
    Dim oDesktop As Object
    Dim oDoc As Object
    Dim oText As Object
    Dim oCursor As Object
    Dim oPageStyles As Object
    Dim oPageStyle As Object

    oDesktop = CreateUnoService("com.sun.star.frame.Desktop")

    REM Create visible document for testing
    oDoc = oDesktop.loadComponentFromURL("private:factory/swriter", "_blank", 0, Array())

    oPageStyles = oDoc.StyleFamilies.getByName("PageStyles")
    oPageStyle = oPageStyles.getByName("Standard")

    REM Portrait page: 12mm x 50mm (matching TestLabel2)
    oPageStyle.IsLandscape = False
    oPageStyle.Width = 12000        ' 12mm
    oPageStyle.Height = 50000       ' 50mm
    oPageStyle.LeftMargin = 500
    oPageStyle.RightMargin = 500
    oPageStyle.TopMargin = 500
    oPageStyle.BottomMargin = 500

    REM Add test text with 90° CCW rotation
    oText = oDoc.Text
    oCursor = oText.createTextCursor()
    oCursor.CharHeight = 8
    oCursor.ParaAdjust = com.sun.star.style.ParagraphAdjust.LEFT

    REM CharRotation is in 1/10 degrees, 900 = 90° CCW
    oCursor.CharRotation = 900

    oText.insertString(oCursor, "TEST ABC 123", False)

    MsgBox "Test label created (12mm x 50mm) with 90° CCW text rotation." & Chr(10) & Chr(10) & _
           "1. Look at the preview - text should appear vertical in preview" & Chr(10) & _
           "2. Print it - text should appear HORIZONTAL on the physical label" & Chr(10) & _
           "3. Close this document when done.", _
           64, "Test Label 3"
End Sub

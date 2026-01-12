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

    REM Show completion message
    Dim modeText As String
    If config.IsDryRun Then
        modeText = "DRY-RUN: "
    Else
        modeText = ""
    End If

    MsgBox modeText & printedCount & " label(s) processed successfully.", _
           64, "Label Print Complete"

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

    REM Apply defaults if values are missing
    If config.FontSizeBox <= 0 Then config.FontSizeBox = 8
    If config.FontSizeBin <= 0 Then config.FontSizeBin = 8
    If config.FontSizeLabel1 <= 0 Then config.FontSizeLabel1 = 7
    If config.FontSizeLabel2 <= 0 Then config.FontSizeLabel2 = 6
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
    Dim oCell As Object
    Dim oPageStyles As Object
    Dim oPageStyle As Object
    Dim args() As Object
    Dim printOpts(0) As New com.sun.star.beans.PropertyValue

    On Error GoTo PrintError

    REM Create a new Writer document for the label
    oDesktop = CreateUnoService("com.sun.star.frame.Desktop")

    Dim loadProps(0) As New com.sun.star.beans.PropertyValue
    loadProps(0).Name = "Hidden"
    loadProps(0).Value = True

    oLabelDoc = oDesktop.loadComponentFromURL("private:factory/swriter", "_blank", 0, loadProps())

    REM Configure page size for label (12mm x 50mm)
    oPageStyles = oLabelDoc.StyleFamilies.getByName("PageStyles")
    oPageStyle = oPageStyles.getByName("Standard")

    With oPageStyle
        .Width = LABEL_WIDTH * 10     ' Convert to 1/1000 mm
        .Height = LABEL_HEIGHT * 10
        .LeftMargin = 50              ' Minimal margins (0.5mm)
        .RightMargin = 50
        .TopMargin = 50
        .BottomMargin = 50
        .IsLandscape = True           ' Landscape for 50mm width
    End With

    REM Create table for layout (1 row, 2 columns)
    oText = oLabelDoc.Text
    oCursor = oText.createTextCursor()

    oTable = oLabelDoc.createInstance("com.sun.star.text.TextTable")
    oTable.initialize(1, 2)
    oText.insertTextContent(oCursor, oTable, False)

    REM Remove table borders
    Dim oBorder As New com.sun.star.table.BorderLine
    oBorder.OuterLineWidth = 0
    oTable.TableBorder.TopLine = oBorder
    oTable.TableBorder.BottomLine = oBorder
    oTable.TableBorder.LeftLine = oBorder
    oTable.TableBorder.RightLine = oBorder
    oTable.TableBorder.HorizontalLine = oBorder
    oTable.TableBorder.VerticalLine = oBorder

    REM Set column widths (12% / 88%)
    Dim oColumns As Object
    Dim oColumn As Object
    Dim totalWidth As Long
    oColumns = oTable.getColumns()
    totalWidth = LABEL_WIDTH * 10 - 100  ' Account for margins

    REM Calculate relative widths
    Dim colSeparators(0) As New com.sun.star.text.TableColumnSeparator
    colSeparators(0).Position = CInt(LEFT_SECTION_RATIO * 10000)  ' Position in 1/10000
    colSeparators(0).IsVisible = False
    oTable.TableColumnSeparators = colSeparators()

    REM === Left Cell: Box and Bin ===
    oCell = oTable.getCellByName("A1")
    Dim oLeftText As Object
    Dim oLeftCursor As Object
    oLeftText = oCell.getText()
    oLeftCursor = oLeftText.createTextCursor()

    REM Box text
    oLeftCursor.CharHeight = config.FontSizeBox
    oLeftCursor.CharWeight = com.sun.star.awt.FontWeight.BOLD
    oLeftCursor.ParaAdjust = com.sun.star.style.ParagraphAdjust.CENTER
    oLeftText.insertString(oLeftCursor, label.Box, False)
    oLeftText.insertControlCharacter(oLeftCursor, com.sun.star.text.ControlCharacter.LINE_BREAK, False)

    REM Bin text
    oLeftCursor.CharHeight = config.FontSizeBin
    oLeftCursor.CharWeight = com.sun.star.awt.FontWeight.BOLD
    oLeftText.insertString(oLeftCursor, label.Bin, False)

    REM Center vertically
    oCell.VertOrient = com.sun.star.text.VertOrientation.CENTER

    REM === Right Cell: Label Rows ===
    oCell = oTable.getCellByName("B1")
    Dim oRightText As Object
    Dim oRightCursor As Object
    oRightText = oCell.getText()
    oRightCursor = oRightText.createTextCursor()

    REM Label row 1
    oRightCursor.CharHeight = config.FontSizeLabel1
    oRightCursor.ParaAdjust = com.sun.star.style.ParagraphAdjust.LEFT
    oRightText.insertString(oRightCursor, label.Label1, False)

    REM Label row 2 (if present)
    If label.Label2 <> "" Then
        oRightText.insertControlCharacter(oRightCursor, com.sun.star.text.ControlCharacter.LINE_BREAK, False)
        oRightCursor.CharHeight = config.FontSizeLabel2
        oRightText.insertString(oRightCursor, label.Label2, False)
    End If

    REM Label row 3 (if present)
    If label.Label3 <> "" Then
        oRightText.insertControlCharacter(oRightCursor, com.sun.star.text.ControlCharacter.LINE_BREAK, False)
        oRightCursor.CharHeight = config.FontSizeLabel3
        oRightText.insertString(oRightCursor, label.Label3, False)
    End If

    REM Center vertically
    oCell.VertOrient = com.sun.star.text.VertOrientation.CENTER

    REM Print or dry-run
    If Not config.IsDryRun Then
        REM Set printer and print
        printOpts(0).Name = "Name"
        printOpts(0).Value = PRINTER_NAME
        oLabelDoc.Printer = printOpts()

        Dim printJobOpts(0) As New com.sun.star.beans.PropertyValue
        printJobOpts(0).Name = "Wait"
        printJobOpts(0).Value = True
        oLabelDoc.print(printJobOpts())

        REM Close the label document
        oLabelDoc.close(False)
    Else
        REM Dry-run: Show preview of the label
        REM Make the document visible
        oLabelDoc.CurrentController.Frame.ContainerWindow.Visible = True

        REM Set zoom for better visibility (label is very small)
        Dim oViewSettings As Object
        oViewSettings = oLabelDoc.CurrentController.getViewSettings()
        oViewSettings.ZoomValue = 100  ' 100% zoom for actual size preview

        REM Leave preview document open for user to inspect
        REM User must manually close the preview window
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
        msg = msg & " (No printing)"
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
    oSheet.getCellByPosition(1, CFG_ROW_FONT_BOX).Value = 8

    oSheet.getCellByPosition(0, CFG_ROW_FONT_BIN).String = "Font Size - Bin"
    oSheet.getCellByPosition(1, CFG_ROW_FONT_BIN).Value = 8

    oSheet.getCellByPosition(0, CFG_ROW_FONT_LABEL1).String = "Font Size - Label 1"
    oSheet.getCellByPosition(1, CFG_ROW_FONT_LABEL1).Value = 7

    oSheet.getCellByPosition(0, CFG_ROW_FONT_LABEL2).String = "Font Size - Label 2"
    oSheet.getCellByPosition(1, CFG_ROW_FONT_LABEL2).Value = 6

    oSheet.getCellByPosition(0, CFG_ROW_FONT_LABEL3).String = "Font Size - Label 3"
    oSheet.getCellByPosition(1, CFG_ROW_FONT_LABEL3).Value = 6

    oSheet.getCellByPosition(0, CFG_ROW_OUTPUT_MODE).String = "Output Mode"
    oSheet.getCellByPosition(1, CFG_ROW_OUTPUT_MODE).String = "DRY_RUN"

    REM Add note about output mode
    oSheet.getCellByPosition(0, 8).String = "Output Mode Options:"
    oSheet.getCellByPosition(0, 9).String = "  PRINT - Send to printer"
    oSheet.getCellByPosition(0, 10).String = "  DRY_RUN - Test without printing"

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

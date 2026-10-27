<?php

require_once __DIR__ . '/../includes/spreadsheet_export.php';

function checkSpreadsheetExport($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo "PASS: $message\n";
}

$columnCheck = ecoTrackSpreadsheetColumn(0) === 'A' && ecoTrackSpreadsheetColumn(26) === 'AA';
ob_start();
ecoTrackDownloadXlsx('attendance-test', 'Attendance History', ['Date', 'Status'], [['2025-01-01', 'Present'], ['2025-01-02', '=not-a-formula']], ['Generated' => '2025-01-03']);
$workbook = ob_get_clean();
$path = tempnam(sys_get_temp_dir(), 'ecotrack-xlsx-test-');
file_put_contents($path, $workbook);
$zip = new ZipArchive();
$opened = $zip->open($path) === true;
$sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
$zip->close();
@unlink($path);
checkSpreadsheetExport($columnCheck, 'XLSX column names support spreadsheet-width tables.');
checkSpreadsheetExport($opened, 'Attendance export is a valid ZIP-based XLSX workbook.');
checkSpreadsheetExport($sheet !== false && strpos($sheet, 'Attendance History') !== false && strpos($sheet, '=not-a-formula') !== false, 'XLSX export retains report metadata and writes user values as text.');

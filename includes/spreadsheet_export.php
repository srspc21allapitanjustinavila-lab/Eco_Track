<?php

/** Minimal dependency-free XLSX writer for tabular EcoTrack reports.
 * Values are written as inline strings so user-provided text is never treated
 * as an Excel formula. */
function ecoTrackSpreadsheetColumn($index)
{
    $name = '';
    $index = (int)$index + 1;
    while ($index > 0) {
        $remainder = ($index - 1) % 26;
        $name = chr(65 + $remainder) . $name;
        $index = intdiv($index - 1, 26);
    }
    return $name;
}

function ecoTrackSpreadsheetXml($value)
{
    return htmlspecialchars((string)$value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
}

function ecoTrackSpreadsheetSheetXml(array $headers, array $rows, $title, array $metadata = [])
{
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
    $rowNumber = 1;
    $writeRow = static function (array $values) use (&$xml, &$rowNumber) {
        $xml .= '<row r="' . $rowNumber . '">';
        foreach (array_values($values) as $column => $value) {
            $cell = ecoTrackSpreadsheetColumn($column) . $rowNumber;
            $xml .= '<c r="' . $cell . '" t="inlineStr"><is><t xml:space="preserve">' . ecoTrackSpreadsheetXml($value) . '</t></is></c>';
        }
        $xml .= '</row>';
        $rowNumber++;
    };

    $writeRow([$title]);
    foreach ($metadata as $label => $value) {
        $writeRow([$label, $value]);
    }
    $writeRow([]);
    $writeRow($headers);
    foreach ($rows as $row) {
        $writeRow(is_array($row) ? $row : [(string)$row]);
    }
    $xml .= '</sheetData></worksheet>';
    return $xml;
}

function ecoTrackDownloadXlsx($filename, $title, array $headers, array $rows, array $metadata = [])
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('XLSX export requires the PHP Zip extension.');
    }

    $temporaryFile = tempnam(sys_get_temp_dir(), 'ecotrack-xlsx-');
    if ($temporaryFile === false) {
        throw new RuntimeException('Could not prepare the spreadsheet download.');
    }
    $zip = new ZipArchive();
    if ($zip->open($temporaryFile, ZipArchive::OVERWRITE) !== true) {
        @unlink($temporaryFile);
        throw new RuntimeException('Could not create the spreadsheet download.');
    }

    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Report" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
    $zip->addFromString('xl/worksheets/sheet1.xml', ecoTrackSpreadsheetSheetXml($headers, $rows, $title, $metadata));
    $zip->close();

    $safeFilename = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string)$filename);
    $safeFilename = trim((string)$safeFilename, '.-') ?: 'ecotrack-report';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $safeFilename . '.xlsx"');
    header('Content-Length: ' . (string)filesize($temporaryFile));
    readfile($temporaryFile);
    @unlink($temporaryFile);
}

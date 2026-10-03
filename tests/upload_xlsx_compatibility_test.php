<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/waste_analytics.php';

function checkXlsxCompatibility($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }
    echo "PASS: $message\n";
}

// Load helpers only; the upload request handler must not run in this test.
$uploadPage = file_get_contents(__DIR__ . '/../waste_import.php');
$helpersStart = strpos($uploadPage, 'function normalizeImportWasteAmount');
$requestHandlerStart = strpos($uploadPage, "\n// Handle the first file and create its reviewable preview.", $helpersStart);
checkXlsxCompatibility($helpersStart !== false && $requestHandlerStart !== false, 'Upload parser helpers are available for XLSX compatibility testing.');
eval(substr($uploadPage, $helpersStart, $requestHandlerStart - $helpersStart));

$workbookXml = <<<'XML'
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets>
    <sheet name="Canonical Data" sheetId="1" r:id="rId1"/>
    <sheet name="3-Day Data" sheetId="2" r:id="rId2"/>
    <sheet name="Broken Link" sheetId="3" r:id="rIdMissing"/>
  </sheets>
</workbook>
XML;
$relationshipsXml = <<<'XML'
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Target="/xl/worksheets/sheet1.xml" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Id="rId1"/>
  <Relationship Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Id="rId2" Target="worksheets/sheet2.xml"/>
</Relationships>
XML;

$references = parseXlsxWorksheetReferences($workbookXml, $relationshipsXml);
checkXlsxCompatibility(
    count($references) === 3
    && $references[0]['target'] === 'xl/worksheets/sheet1.xml'
    && $references[1]['target'] === 'xl/worksheets/sheet2.xml'
    && $references[2]['target'] === '',
    'Worksheet relationships accept arbitrary attribute order plus absolute and relative targets, while missing links remain identifiable.'
);

$aliasProfile = getWasteImportHeaderProfile([
    'Name of Biosan',
    'Street',
    'No. of Houses',
    'Organic Waste (kg)',
]);
checkXlsxCompatibility(
    $aliasProfile['is_high_confidence']
    && ($aliasProfile['column_map'][0] ?? null) === 0
    && ($aliasProfile['column_map'][1] ?? null) === 1
    && ($aliasProfile['column_map'][2] ?? null) === 2
    && ($aliasProfile['column_map'][6] ?? null) === 3,
    'Trusted aliases and unit-suffixed headings map to canonical Waste Data fields.'
);

$unrecognizedDiagnostic = getWasteWorksheetHeaderDiagnostic([
    ['Location', 'Household Count', 'Unsorted Trash (kg)'],
]);
checkXlsxCompatibility(
    !$unrecognizedDiagnostic['is_high_confidence']
    && in_array('Unsorted Trash (kg)', $unrecognizedDiagnostic['unmatched_headers'], true),
    'Low-confidence layouts remain rejected with unmatched-header diagnostics.'
);

$compactProfile = getWasteImportHeaderProfile(['House #', 'Total Waste (kg)']);
checkXlsxCompatibility(
    $compactProfile['is_high_confidence']
    && ($compactProfile['column_map'][2] ?? null) === 0
    && ($compactProfile['column_map'][13] ?? null) === 1,
    'Compact House # and Total Waste headings map to Household and Unclassified Waste.'
);

[$compactRows, $compactLabels, $compactSourceRows] = parseExcelWorksheetBlocks([
    ['Monthly collection summary'],
    [],
    ['House #', 'Total Waste (kg)'],
    ['17', '1,234.50 kg'],
    ['TOTAL', '1,234.50'],
], 'Sheet 1', '2026-09-21.xlsx', [1, 2, 3, 4, 5]);
checkXlsxCompatibility(
    count($compactRows) === 1
    && $compactRows[0][2] === '17'
    && $compactRows[0][13] === '1,234.50 kg'
    && ($compactSourceRows[0] ?? 0) === 4,
    'Tables below title rows import compact totals, preserve source rows, and skip TOTAL rows.'
);
checkXlsxCompatibility(
    normalizeImportWasteAmount('1,234.50 kg') === '1234.50'
    && !isValidImportWasteAmount('-3 kg'),
    'Amount normalization accepts optional kg units and rejects negative values.'
);

[$multiTableRows] = parseExcelWorksheetBlocks([
    ['Area', 'Household', 'Total Waste'],
    ['Acacia St.', '9', '4'],
    ['Second collection block'],
    ['Area', 'Household', 'Organic Waste'],
    ['Agoho St.', '11', '6'],
], 'Sheet 2', 'Sep 21, 2026.xlsx');
checkXlsxCompatibility(
    count($multiTableRows) === 2
    && $multiTableRows[0][13] === '4'
    && $multiTableRows[1][6] === '6',
    'Separate tables in one worksheet reset their header map instead of mixing columns.'
);

[$noDoubleCountRows] = limitRowsToWasteColumnsWithPeriods(
    ['Area', 'Household', 'Residual Waste', 'Total Waste'],
    [['Curie St.', '8', '3', '10']]
);
checkXlsxCompatibility(
    count($noDoubleCountRows) === 1 && $noDoubleCountRows[0][12] === '3' && $noDoubleCountRows[0][13] === '',
    'A generic total is ignored when categorized waste columns are present, preventing double counting.'
);

function xlsxFixtureColumn($index)
{
    return chr(65 + $index);
}

function xlsxFixtureCell($columnIndex, $rowIndex, $value)
{
    $reference = xlsxFixtureColumn($columnIndex) . $rowIndex;
    return '<c r="' . $reference . '" t="inlineStr"><is><t>'
        . htmlspecialchars((string)$value, ENT_XML1 | ENT_QUOTES, 'UTF-8')
        . '</t></is></c>';
}

function xlsxFixtureWorksheet($rows)
{
    $xml = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
    foreach ($rows as $index => $values) {
        $rowNumber = $index + 1;
        $xml .= '<row r="' . $rowNumber . '">';
        foreach ($values as $columnIndex => $value) {
            $xml .= xlsxFixtureCell($columnIndex, $rowNumber, $value);
        }
        $xml .= '</row>';
    }
    return $xml . '</sheetData></worksheet>';
}

checkXlsxCompatibility(class_exists('ZipArchive'), 'ZipArchive is available for the XLSX fixture.');
$fixturePath = tempnam(sys_get_temp_dir(), 'ecotrack-xlsx-');
if ($fixturePath === false) {
    fwrite(STDERR, "FAIL: Could not create a temporary XLSX fixture.\n");
    exit(1);
}
unlink($fixturePath);
$fixturePath .= '.xlsx';

try {
    $canonicalRows = [
        ['Phase / Date', 'Phase 1-A (Nov 11-16, 2024)'],
        getWasteFormatHeaders(),
    ];
    for ($index = 1; $index <= 14; $index++) {
        $canonicalRows[] = [
            'Collector ' . $index,
            'Street ' . $index,
            '10',
            '5',
            '1',
            '0',
            '-',
            '-',
            '-',
            '3',
            '1',
            '0',
            '2',
        ];
    }
    $threeDayRows = [
        ['3-Day Collection Data'],
        ['3-Day Block: Tuesday - Wednesday - Thursday'],
        ['Name of Biosan', 'Street', 'No. of Houses', 'Tuesday Factory KL'],
        ['Collector 1', 'Street 1', '10', '5'],
    ];

    $zip = new ZipArchive();
    checkXlsxCompatibility($zip->open($fixturePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 'Temporary XLSX fixture is created.');
    $zip->addFromString('xl/workbook.xml', $workbookXml);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $relationshipsXml);
    $zip->addFromString('xl/worksheets/sheet1.xml', xlsxFixtureWorksheet($canonicalRows));
    $zip->addFromString('xl/worksheets/sheet2.xml', xlsxFixtureWorksheet($threeDayRows));
    $zip->close();

    [$headers, $rows, $period, $phaseLabels, $summary] = parseXlsxUpload($fixturePath, 'Nov 11-16.xlsx');
    checkXlsxCompatibility(
        count($headers) === 14
        && count($rows) === 14
        && count($phaseLabels) === 14
        && $period === 'Phase 1-A (Nov 11-16, 2024)',
        'Canonical worksheet imports all 14 records with its explicit 2024 reporting period.'
    );
    checkXlsxCompatibility(
        ($summary['with_waste_data'] ?? 0) === 1
        && ($summary['skipped'] ?? 0) === 1
        && ($summary['without_waste_data'] ?? 0) === 1
        && count(array_filter($summary['worksheets'], static function ($worksheet) {
            return ($worksheet['name'] ?? '') === '3-Day Data' && ($worksheet['status'] ?? '') === 'skipped';
        })) === 1,
        'Supporting 3-day worksheet is skipped when a complete canonical table exists.'
    );
    checkXlsxCompatibility(
        count(array_filter($summary['worksheets'], static function ($worksheet) {
            return ($worksheet['name'] ?? '') === 'Broken Link'
                && ($worksheet['status'] ?? '') === 'skipped'
                && ($worksheet['reason'] ?? '') === 'The worksheet relationship is missing or invalid.';
        })) === 1,
        'Missing worksheet relationships are reported as skipped instead of causing a ZIP-reader failure.'
    );
} finally {
    if (is_file($fixturePath)) {
        unlink($fixturePath);
    }
}

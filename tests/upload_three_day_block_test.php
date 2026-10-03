<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/waste_analytics.php';

function checkThreeDayUpload($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }
    echo "PASS: $message\n";
}

// Load helpers only; the authenticated request handler must not run in this test.
$uploadPage = file_get_contents(__DIR__ . '/../waste_import.php');
$helpersStart = strpos($uploadPage, 'function normalizeImportWasteAmount');
$requestHandlerStart = strpos($uploadPage, "\n// Handle the first file and create its reviewable preview.", $helpersStart);
checkThreeDayUpload($helpersStart !== false && $requestHandlerStart !== false, 'Upload parser helpers are available for testing.');
eval(substr($uploadPage, $helpersStart, $requestHandlerStart - $helpersStart));

$namespaceWorksheet = <<<'XML'
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <sheetData>
    <row r="1">
      <c r="A1" t="inlineStr"><is><r><t>Name of </t></r><r><t>Biosan</t></r></is></c>
      <c r="B1" t="inlineStr"><is><t>Street</t></is></c>
      <c r="C1" t="inlineStr"><is><t>No. of Houses</t></is></c>
    </row>
  </sheetData>
</worksheet>
XML;
$namespaceRows = parseXlsxRows($namespaceWorksheet, []);
checkThreeDayUpload(
    count($namespaceRows) === 1
    && $namespaceRows[0] === ['Name of Biosan', 'Street', 'No. of Houses'],
    'Namespaced and rich-text Excel cells are read before header detection.'
);

$sampleRows = [
    ['3-Day Collection Data'],
    ['3-Day Block: Tuesday - Wednesday - Thursday'],
    ['Name of Biosan', 'Street', 'No. of Houses', 'Tuesday Factory KL', 'Tuesday Comply', 'Wednesday KL', 'Wednesday Comply', 'Thursday KL', '3-Day Total / Recorded Value'],
    ['Rodolfo Valentino', 'Darwin St.', '28', '94', '26', '-', '-', '-', '120'],
    ['', 'TOTAL', '430', '1416.4', '397', '-', '-', '-', '1813.4'],
    ['2-Day Block: Friday - Saturday'],
    ['Name of Biosan', 'Street', 'No. of Houses', 'Friday KL', 'Friday Comply', 'Saturday Hazard', '3-Day Total / Recorded Value'],
    ['Marieta De Paz', 'Dalton St.', '38', '219.4', '35', '1', '255.4'],
    ['', 'TOTAL', '420', '2632.3', '420', '42', '3074.3'],
];

[$mappedRows, $periodLabels] = parseExcelWorksheetBlocks($sampleRows, 'Sheet1', 'Nov 11-16.xlsx');

checkThreeDayUpload(count($mappedRows) === 2, 'Both 3-day collection blocks import and TOTAL rows are ignored.');
checkThreeDayUpload(
    $mappedRows[0][0] === 'Rodolfo Valentino'
    && $mappedRows[0][2] === '28'
    && $mappedRows[0][3] === '94'
    && $mappedRows[0][6] === '-'
    && $mappedRows[0][8] === '-',
    'Tuesday–Thursday fields map to their correct Waste Data columns.'
);
checkThreeDayUpload(
    $mappedRows[1][0] === 'Marieta De Paz'
    && $mappedRows[1][2] === '38'
    && $mappedRows[1][9] === '219.4'
    && $mappedRows[1][10] === '35'
    && $mappedRows[1][11] === '1'
    && $mappedRows[1][6] === '',
    'Friday–Saturday fields map correctly and the recorded total is ignored.'
);
checkThreeDayUpload(
    str_contains($periodLabels[0], 'Nov 11-16 ' . date('Y'))
    && str_contains($periodLabels[1], 'Nov 11-16 ' . date('Y')),
    'A month/day-only filename supplies the current reporting year.'
);

$threeDayRules = [
    normalizeWasteCollectionRuleArea('Dalton St.') => ['collection_group' => 'Phase 1-A', 'collection_group_type' => 'phase'],
    normalizeWasteCollectionRuleArea('Toyota') => ['collection_group' => 'Establishments', 'collection_group_type' => 'establishment'],
];
$phaseRecord = buildWasteImportDatabaseRecord(
    ['Marieta De Paz', 'Dalton St.', '10', '', '', '', '', '', '', '4', '', '1', '2'],
    '3-Day Block: Friday - Saturday (Nov 11-16, 2024)',
    '3-Day Block: Friday - Saturday',
    'Nov 11-16, 2024',
    $threeDayRules
);
checkThreeDayUpload(
    $phaseRecord !== null && $phaseRecord['collection_group'] === 'Phase 1-A' && $phaseRecord['collection_group_type'] === 'phase',
    'A generic Friday-Saturday block resolves a known phase street through its grouping rule.'
);
$establishmentRecord = buildWasteImportDatabaseRecord(
    ['Marieta De Paz', 'Toyota', '1', '', '', '', '', '', '', '4', '', '1', '2'],
    '3-Day Block: Friday - Saturday (Nov 11-16, 2024)',
    '3-Day Block: Friday - Saturday',
    'Nov 11-16, 2024',
    $threeDayRules
);
checkThreeDayUpload(
    $establishmentRecord !== null && $establishmentRecord['collection_group'] === 'Establishments' && $establishmentRecord['collection_group_type'] === 'establishment',
    'A generic Friday-Saturday block resolves a known establishment through its grouping rule.'
);
$unknownRecord = buildWasteImportDatabaseRecord(
    ['Marieta De Paz', 'Unknown Area', '1', '', '', '', '', '', '', '4', '', '1', '2'],
    '3-Day Block: Friday - Saturday (Nov 11-16, 2024)',
    '3-Day Block: Friday - Saturday',
    'Nov 11-16, 2024',
    $threeDayRules
);
checkThreeDayUpload(
    $unknownRecord !== null && $unknownRecord['collection_group'] === '' && $unknownRecord['group_resolution_required'] === true,
    'An unknown generic block area is held for administrator review instead of being guessed.'
);

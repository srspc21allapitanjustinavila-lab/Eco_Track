<?php
require_once 'config.php';
require_once 'includes/waste_analytics.php';

// Only administrators can change shared waste data through imports.
requireUserType('admin');
$wasteImportRoute = 'admin_waste_import.php';
if (empty($canonicalRouteEntry) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $routeQuery = http_build_query($_GET);
    header('Location: ' . $wasteImportRoute . ($routeQuery === '' ? '' : '?' . $routeQuery));
    exit();
}
updateLastActivity();

$conn = getDBConnection();
$message = '';
$error = '';
$preview_data = [];
$preview_display_data = [];
$file_headers = [];
$temp_file = '';
$phase_date_label = '';
$imported = 0;
$excelWorksheetSummary = null;
$previewTotalRows = 0;
$unresolvedImportAreas = [];
$importResolutionDraft = null;
$importDraftRowCount = 0;
$importDraftIssueCount = 0;
$previewImportIssues = [];
$currentUserId = (int)(getCurrentUser()['id'] ?? 0);
$importOtpRequired = false;
$submittedImportOtp = '';
if (empty($_SESSION['waste_import_csrf'])) {
    $_SESSION['waste_import_csrf'] = bin2hex(random_bytes(32));
}
$wasteImportCsrf = $_SESSION['waste_import_csrf'];
$importPostIsValid = $_SERVER['REQUEST_METHOD'] !== 'POST'
    || (!empty($_POST['waste_import_csrf']) && hash_equals($wasteImportCsrf, (string)$_POST['waste_import_csrf']));
ensureWasteFormatColumns($conn);
ensureWasteAnalyticsColumns($conn);
ensureWasteDailyAllocationTable($conn);
ensureWasteImportTables($conn);
if ($conn) {
    try {
        cleanupExpiredWasteImportBatches($conn);
        cleanupExpiredWasteImportDrafts($conn);
        // Complete historical fingerprint indexing before accepting new imports.
        backfillWasteRecordFingerprints($conn);
    } catch (Throwable $e) {
        error_log('Waste import setup failed: ' . $e->getMessage());
    }
}
$activeImportDraftId = trim((string)($_SESSION['waste_import_draft_id'] ?? ''));
if ($activeImportDraftId !== '' && $conn) {
    $importResolutionDraft = getWasteImportDraft($conn, $activeImportDraftId, $currentUserId);
    if ($importResolutionDraft === null) {
        unset($_SESSION['waste_import_draft_id']);
    } else {
        $draftCount = $conn->prepare('SELECT COUNT(*) FROM waste_import_draft_rows WHERE draft_id = ?');
        $draftCount->execute([$activeImportDraftId]);
        $importDraftRowCount = (int)$draftCount->fetchColumn();
        $issueCount = $conn->prepare('SELECT COUNT(*) FROM waste_import_draft_issues WHERE draft_id = ?');
        $issueCount->execute([$activeImportDraftId]);
        $importDraftIssueCount = (int)$issueCount->fetchColumn();
        $draftIssues = $conn->prepare('SELECT worksheet_name, source_row_number, reason_code FROM waste_import_draft_issues WHERE draft_id = ? ORDER BY worksheet_name, source_row_number LIMIT 100');
        $draftIssues->execute([$activeImportDraftId]);
        $previewImportIssues = array_map(static function ($issue) {
            return ['worksheet' => $issue['worksheet_name'] ?? '', 'row' => (int)($issue['source_row_number'] ?? 0), 'reason' => $issue['reason_code'] ?? 'invalid_row'];
        }, $draftIssues->fetchAll());
    }
}

// Discard legacy session previews; pending imports remain server-side.
if (!empty($_SESSION['import_rows'])) {
    unset(
        $_SESSION['import_rows'], $_SESSION['import_headers'],
        $_SESSION['phase_date_label'], $_SESSION['import_phase_labels'],
        $_SESSION['import_collection_groups'], $_SESSION['import_reporting_periods']
    );
}
$activeImportBatchId = trim((string)($_SESSION['import_batch_id'] ?? ''));
if ($activeImportBatchId !== '' && $conn) {
    $activeBatch = getWasteImportBatch($conn, $activeImportBatchId, $currentUserId);
    if ($activeBatch === null) {
        unset($_SESSION['import_batch_id'], $_SESSION['import_worksheet_summary']);
    } else {
        $file_headers = array_merge(['Collection Group', 'Date / Period'], getWasteFormatHeaders());
        $preview_display_data = getWasteImportBatchPreview($conn, $activeImportBatchId);
        $previewTotalRows = (int)$activeBatch['row_count'];
        $storedWorksheetSummary = $_SESSION['import_worksheet_summary'] ?? null;
        $excelWorksheetSummary = is_array($storedWorksheetSummary) ? $storedWorksheetSummary : null;
        $unresolvedImportAreas = getWasteImportBatchUnresolvedAreas($conn, $activeImportBatchId);
        $previewImportIssues = getWasteImportBatchIssues($conn, $activeImportBatchId);
    }
}
$previousPreviewDisplayData = $preview_display_data;
$previousFileHeaders = $file_headers;
$previousWorksheetSummary = $excelWorksheetSummary;

// A preview has not changed Waste Data and can be safely discarded.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$importPostIsValid) {
    $error = 'Your upload form expired. Please refresh the page and try again.';
}
if ($importPostIsValid && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_import'])) {
    try {
        if (!empty($_SESSION['import_batch_id']) && $conn) {
            discardWasteImportBatch($conn, (string)$_SESSION['import_batch_id'], $currentUserId);
        }
        if (!empty($_SESSION['waste_import_draft_id']) && $conn) {
            discardWasteImportDraft($conn, (string)$_SESSION['waste_import_draft_id'], $currentUserId);
        }
    } catch (Throwable $e) {
        error_log('Could not discard pending waste import: ' . $e->getMessage());
    }
    unset(
        $_SESSION['import_rows'],
        $_SESSION['import_headers'],
        $_SESSION['phase_date_label'],
        $_SESSION['import_phase_labels'],
        $_SESSION['import_collection_groups'],
        $_SESSION['import_reporting_periods'],
        $_SESSION['import_worksheet_summary'],
        $_SESSION['import_batch_id'],
        $_SESSION['waste_import_draft_id']
    );
    $preview_display_data = [];
    $file_headers = [];
    $excelWorksheetSummary = null;
    $previewTotalRows = 0;
    $importResolutionDraft = null;
    $message = 'Upload cancelled. No Waste Data was added.';
}

function normalizeImportWasteAmount($value)
{
    $value = trim((string)$value);
    if (in_array($value, ['', '-', '–', '—', 'N/A', 'n/a'], true)) {
        return '';
    }
    // Accept units and non-breaking spaces, but reject other numeric text.
    $value = preg_replace('/\x{00A0}/u', ' ', $value);
    $value = preg_replace('/\s*(?:kgs?|kilograms?)\s*$/i', '', $value);
    return str_replace([',', ' '], '', $value);
}

function getWasteImportAmountIndexes()
{
    return [3, 6, 8, 9, 11, 12, 13];
}

function getWasteImportCategorizedAmountIndexes()
{
    return [3, 6, 8, 9, 11, 12];
}

function importNumber($value)
{
    $value = normalizeImportWasteAmount($value);
    return is_numeric($value) ? (float)$value : 0;
}

function isValidImportWasteAmount($value)
{
    $value = normalizeImportWasteAmount($value);
    return $value === '' || (is_numeric($value) && (float)$value >= 0);
}

function hasImportWasteCategoryAmount($row)
{
    foreach (getWasteImportAmountIndexes() as $amountIndex) {
        if (normalizeImportWasteAmount($row[$amountIndex] ?? '') !== '') {
            return true;
        }
    }
    return false;
}

function normalizeImportHeader($header)
{
    $header = trim((string)$header);

    // Normalize punctuation and accents before matching trusted column aliases.
    if (function_exists('iconv')) {
        $asciiHeader = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $header);
        if ($asciiHeader !== false) {
            $header = $asciiHeader;
        }
    }

    return preg_replace('/[^a-z0-9]+/', '', strtolower($header));
}

function getWasteImportHeaderAliases()
{
    return [
// Support MRF workbook aliases alongside EcoTrack field names.
        0 => ['nameofbioman', 'biomanname', 'bioman', 'nameofbiosan', 'biosanname', 'biosan', 'collectorname', 'collector', 'garbagecollector', 'nameofcollector', 'wastecollector', 'assignedcollector', 'personincharge'],
        1 => ['streetname', 'nameofstreet', 'streetaddress', 'street', 'address', 'location', 'area', 'barangay', 'zone', 'purok', 'sitio', 'district', 'community'],
        2 => ['numberofhouseholds', 'noofhouseholds', 'numberofhousehold', 'noofhousehold', 'numberofhouses', 'noofhouses', 'numberofhouse', 'noofhouse', 'totalhouseholds', 'householdcount', 'houses', 'households', 'household', 'householdid', 'householdnumber', 'housenumber', 'houseid', 'house', 'hh', 'nohouseholds'],
        3 => ['tuesdayfactoryreturnable', 'tuesdayfactoryreturn', 'tuesdayfactorykl', 'tuesdayreturnable', 'tuesdayreturn', 'tuesdayrecyclable', 'tuereturnable', 'tuereturn', 'factoryreturnable', 'returnable', 'recyclable', 'recyclables', 'drywaste'],
        4 => ['complytue', 'complytuesday', 'tuesdaycomply'],
        5 => ['cdprocessing', 'coprocessing', 'processing'],
        6 => ['wednesdaybiowaste', 'wedbiowaste', 'wednesdaykl', 'biowaste', 'biodegradable', 'organicwaste', 'organic'],
        7 => ['complywed', 'complywednesday', 'wednesdaycomply'],
        8 => ['thursdayfactoryreturnable', 'thursdayfactoryreturn', 'thursdaykl', 'thursdayreturnable', 'thursdayreturn', 'thursdayrecyclable', 'thureturnable', 'thureturn'],
        9 => ['fridaybiowaste', 'fribiowaste', 'fridaykl'],
        10 => ['complyfri', 'complyfriday', 'fridaycomply'],
        11 => ['saturdayhazardwaste', 'saturdayhazardouswaste', 'saturdayhazard', 'sathazardwaste', 'hazardwaste', 'hazardous', 'hazardouswaste'],
        12 => ['residualwaste', 'residual', 'nonbiodegradable', 'generalwaste', 'mixedwaste'],
        13 => ['unclassifiedwaste', 'uncategorizedwaste', 'otherwaste', 'totalwaste', 'totalwastekg', 'totalweight', 'totalweightkg', 'totalamount', 'totalamountkg', 'wasteamount', 'wasteweight', 'weight', 'amount'],
    ];
}

function getWasteImportColumnMap($headers)
{
    $normalizedHeaders = array_map('normalizeImportHeader', $headers);
    $columnMap = [];

    foreach (getWasteImportHeaderAliases() as $targetIndex => $aliases) {
        foreach ($normalizedHeaders as $sourceIndex => $header) {
            foreach ($aliases as $alias) {
                // Excel headings commonly add units, punctuation, or a short
                // descriptor (for example, "Street Name" or "Tuesday Factory
                // Returnable (kg)"). Match the known heading stem, not only
                // one exact spelling.
                if ($header === $alias || strpos($header, $alias) === 0) {
                    $columnMap[$targetIndex] = $sourceIndex;
                    break 2;
                }
            }
        }
    }

    return $columnMap;
}

function findImportHeaderColumn($headers, $aliases)
{
    foreach ($headers as $index => $header) {
        $normalizedHeader = normalizeImportHeader($header);
        foreach ($aliases as $alias) {
            if ($normalizedHeader === $alias || strpos($normalizedHeader, $alias) === 0) {
                return $index;
            }
        }
    }
    return null;
}

function getWasteImportPeriodColumn($headers)
{
    return findImportHeaderColumn($headers, [
        'collectiondate', 'datecollected', 'dateofcollection', 'reportingdate',
        'reportingperiod', 'collectionperiod', 'dateperiod', 'weekperiod',
        'monthyear', 'collectionyear', 'year', 'period', 'week', 'month', 'date',
    ]);
}

function getWasteImportLongFormatColumns($headers)
{
    return [
        'type' => findImportHeaderColumn($headers, [
            'wastetype', 'wastecategory', 'wasteclass', 'wastestream',
            'typeofwaste', 'materialtype', 'category', 'type',
        ]),
        'amount' => findImportHeaderColumn($headers, [
            'wasteweightkg', 'weightkg', 'quantitykg', 'amountkg', 'kilograms',
            'kilogram', 'weight', 'quantity', 'amount', 'volume', 'wasteamount',
        ]),
    ];
}

function getWasteImportHeaderProfile($headers)
{
    $columnMap = getWasteImportColumnMap($headers);
    $longFormatColumns = getWasteImportLongFormatColumns($headers);
    $hasLongWasteFields = $longFormatColumns['type'] !== null && $longFormatColumns['amount'] !== null;
    $hasCategorizedWasteAmount = !empty(array_intersect(array_keys($columnMap), getWasteImportCategorizedAmountIndexes()));
    $hasGenericWasteAmount = isset($columnMap[13]);

    // Resolve omitted Area and date values during preview rather than discovery.
    $hasWideWasteFields = isset($columnMap[2])
        && ($hasCategorizedWasteAmount || $hasGenericWasteAmount);
    $hasLongWasteFields = $hasLongWasteFields && isset($columnMap[2]);

    return [
        'column_map' => $columnMap,
        'long_format_columns' => $longFormatColumns,
        'has_long_waste_fields' => $hasLongWasteFields,
        'has_wide_waste_fields' => $hasWideWasteFields,
        'has_generic_waste_amount' => $hasGenericWasteAmount,
        'has_categorized_waste_amount' => $hasCategorizedWasteAmount,
        'is_high_confidence' => $hasLongWasteFields || $hasWideWasteFields,
    ];
}

function hasRecognizedWasteHeaderFields($headers)
{
    $profile = getWasteImportHeaderProfile($headers);
    return !empty(array_intersect(array_keys($profile['column_map']), getWasteImportAmountIndexes()))
        || $profile['has_long_waste_fields'];
}

function wasteAmountIndexForType($type)
{
    $type = normalizeImportHeader($type);
    if ($type === '') {
        return 12;
    }
    if (strpos($type, 'hazard') !== false || strpos($type, 'sat') === 0) {
        return 11;
    }
    if (strpos($type, 'residual') !== false || strpos($type, 'nonbio') !== false || strpos($type, 'general') !== false || strpos($type, 'mixed') !== false) {
        return 12;
    }
    if (strpos($type, 'bio') !== false || strpos($type, 'organic') !== false || strpos($type, 'wed') === 0) {
        return 6;
    }
    if (strpos($type, 'fri') === 0) {
        return 9;
    }
    if (strpos($type, 'thu') === 0) {
        return 8;
    }
    return 3;
}

function limitRowsToWasteColumnsWithPeriods($headers, $rows, $fallbackPeriod = '')
{
    $expectedCount = count(getWasteFormatHeaders());
    $headerProfile = getWasteImportHeaderProfile($headers);
    $columnMap = $headerProfile['column_map'];
    $periodColumn = getWasteImportPeriodColumn($headers);
    $longFormatColumns = $headerProfile['long_format_columns'];
    $hasLongWasteFields = $headerProfile['has_long_waste_fields'];
    $ignoreGenericTotal = !empty($headerProfile['has_categorized_waste_amount']) || $hasLongWasteFields;

    if (!$headerProfile['is_high_confidence']) {
        return [[], []];
    }

    $limitedRows = [];
    $periodLabels = [];
    foreach ($rows as $row) {
        $limitedRow = [];
        for ($i = 0; $i < $expectedCount; $i++) {
            $sourceIndex = $columnMap[$i] ?? null;
            $limitedRow[] = ($i === 13 && $ignoreGenericTotal)
                ? ''
                : ($sourceIndex === null ? '' : ($row[$sourceIndex] ?? ''));
        }
        if ($hasLongWasteFields) {
            $amountIndex = wasteAmountIndexForType($row[$longFormatColumns['type']] ?? '');
            $limitedRow[$amountIndex] = $row[$longFormatColumns['amount']] ?? '';
        }
        if (rowHasData($limitedRow)) {
            $limitedRows[] = $limitedRow;
            $periodLabels[] = trim((string)($periodColumn === null ? $fallbackPeriod : ($row[$periodColumn] ?? $fallbackPeriod)));
        }
    }
    return [$limitedRows, $periodLabels];
}

function limitRowsToWasteColumns($headers, $rows)
{
    $expectedCount = count(getWasteFormatHeaders());
    $headerProfile = getWasteImportHeaderProfile($headers);
    $columnMap = $headerProfile['column_map'];
    $ignoreGenericTotal = !empty($headerProfile['has_categorized_waste_amount']) || $headerProfile['has_long_waste_fields'];

    // Import only recognized Waste Data fields.
    if (!$headerProfile['is_high_confidence']) {
        return [];
    }

    $limitedRows = [];
    foreach ($rows as $row) {
        $limitedRow = [];
        for ($i = 0; $i < $expectedCount; $i++) {
            $sourceIndex = $columnMap[$i] ?? null;
            $limitedRow[] = ($i === 13 && $ignoreGenericTotal)
                ? ''
                : ($sourceIndex === null ? '' : ($row[$sourceIndex] ?? ''));
        }

        if (rowHasData($limitedRow)) {
            $limitedRows[] = $limitedRow;
        }
    }

    return $limitedRows;
}

function firstNonEmptyCell($row, $startIndex = 0)
{
    for ($i = $startIndex; $i < count($row); $i++) {
        $value = trim((string)($row[$i] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }
    return '';
}

function extractUploadedSheetParts($rows)
{
    $rows = array_values(array_filter($rows, 'rowHasData'));
    if (empty($rows)) {
        return [[], [], ''];
    }

    $phaseDateLabel = '';
    $firstCell = normalizeImportHeader($rows[0][0] ?? '');
    if ($firstCell === 'phasedate') {
        $phaseDateLabel = firstNonEmptyCell($rows[0], 1);
        $headers = $rows[1] ?? [];
        $dataRows = array_slice($rows, 2);
    } else {
        $headers = $rows[0];
        $dataRows = array_slice($rows, 1);
    }

    return [$headers, filterImportRows($dataRows), $phaseDateLabel];
}

function rowHasData($row)
{
    foreach ($row as $cell) {
        if (trim((string)$cell) !== '') {
            return true;
        }
    }
    return false;
}

function filterImportRows($rows)
{
    $filtered = [];
    foreach ($rows as $row) {
        if (!rowHasData($row)) {
            continue;
        }

        if (isWasteSummaryRow($row)) {
            continue;
        }

        $filtered[] = $row;
    }
    return $filtered;
}

function parseCsvUpload($path)
{
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('Could not read the CSV file.');
    }
    $rows = [];
    try {
        while (($row = fgetcsv($handle)) !== false) {
            if ($row === [null] || !rowHasData($row)) {
                continue;
            }
            $rows[] = $row;
        }
    } finally {
        fclose($handle);
    }

    if (empty($rows)) {
        return [[], [], ''];
    }
    return extractUploadedSheetParts($rows);
}

function excelColumnIndex($cellReference)
{
    $letters = preg_replace('/[^A-Z]/', '', strtoupper($cellReference));
    $index = 0;
    for ($i = 0; $i < strlen($letters); $i++) {
        $index = ($index * 26) + (ord($letters[$i]) - 64);
    }
    return max(0, $index - 1);
}

function normalizeExcelCell($value, $columnIndex)
{
    $value = trim((string)$value);
    if ($columnIndex === 0 && is_numeric($value) && (float)$value > 20000) {
        $timestamp = ((float)$value - 25569) * 86400;
        return gmdate('Y-m-d', (int)$timestamp);
    }
    return $value;
}

function zipUInt16($data, $offset)
{
    $value = unpack('v', substr($data, $offset, 2));
    return $value[1] ?? 0;
}

function zipUInt32($data, $offset)
{
    $value = unpack('V', substr($data, $offset, 4));
    return $value[1] ?? 0;
}

function readZipEntryWithoutExtension($path, $entryName)
{
    if (!function_exists('gzinflate')) {
        throw new RuntimeException('Excel upload requires either the PHP Zip extension or zlib support.');
    }

    $data = file_get_contents($path);
    if ($data === false) {
        throw new RuntimeException('Could not read the Excel file.');
    }

    $eocdOffset = strrpos($data, "PK\x05\x06");
    if ($eocdOffset === false) {
        throw new RuntimeException('The Excel file is not a valid .xlsx file.');
    }

    $centralDirectoryOffset = zipUInt32($data, $eocdOffset + 16);
    $offset = $centralDirectoryOffset;
    $dataLength = strlen($data);

    while ($offset + 46 <= $dataLength && substr($data, $offset, 4) === "PK\x01\x02") {
        $method = zipUInt16($data, $offset + 10);
        $compressedSize = zipUInt32($data, $offset + 20);
        $fileNameLength = zipUInt16($data, $offset + 28);
        $extraLength = zipUInt16($data, $offset + 30);
        $commentLength = zipUInt16($data, $offset + 32);
        $localHeaderOffset = zipUInt32($data, $offset + 42);
        $fileName = substr($data, $offset + 46, $fileNameLength);

        if ($fileName === $entryName) {
            if (substr($data, $localHeaderOffset, 4) !== "PK\x03\x04") {
                throw new RuntimeException('The Excel file has an invalid worksheet entry.');
            }

            $localFileNameLength = zipUInt16($data, $localHeaderOffset + 26);
            $localExtraLength = zipUInt16($data, $localHeaderOffset + 28);
            $contentOffset = $localHeaderOffset + 30 + $localFileNameLength + $localExtraLength;
            $compressedContent = substr($data, $contentOffset, $compressedSize);

            if ($method === 0) {
                return $compressedContent;
            }

            if ($method === 8) {
                $content = gzinflate($compressedContent);
                if ($content === false) {
                    throw new RuntimeException('Could not decompress the Excel worksheet.');
                }
                return $content;
            }

            throw new RuntimeException('This Excel compression format is not supported.');
        }

        $offset += 46 + $fileNameLength + $extraLength + $commentLength;
    }

    return false;
}

function readXlsxEntry($path, $entryName)
{
    $entryName = trim((string)$entryName);
    if ($entryName === '') {
        return false;
    }

    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Could not open the Excel file.');
        }

        $content = $zip->getFromName($entryName);
        $zip->close();
        return $content;
    }

    return readZipEntryWithoutExtension($path, $entryName);
}

function xlsxXPath($node, $query)
{
    $namespaces = $node->getDocNamespaces(true);
    $mainNamespace = $namespaces[''] ?? '';
    if ($mainNamespace !== '') {
        $node->registerXPathNamespace('xlsx', $mainNamespace);
        $result = $node->xpath($query);
        return is_array($result) ? $result : [];
    }

    // Support XML generators that omit the standard namespace.
    $result = $node->xpath(str_replace('xlsx:', '', $query));
    return is_array($result) ? $result : [];
}

function xlsxRichText($node)
{
    $text = '';
    foreach (xlsxXPath($node, './/xlsx:t') as $textNode) {
        $text .= (string)$textNode;
    }
    return $text;
}

function normalizeXlsxEntryName($entryName)
{
    $entryName = str_replace('\\', '/', trim((string)$entryName));
    $entryName = ltrim($entryName, '/');
    if (strpos($entryName, 'xl/') !== 0) {
        $entryName = 'xl/' . $entryName;
    }

    $segments = [];
    foreach (explode('/', $entryName) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            array_pop($segments);
            continue;
        }
        $segments[] = $segment;
    }
    return implode('/', $segments);
}

function parseXlsxWorksheetReferences($workbookXml, $relationshipsXml)
{
    $workbook = simplexml_load_string($workbookXml);
    $relationships = simplexml_load_string($relationshipsXml);
    if ($workbook === false || $relationships === false) {
        throw new RuntimeException('Could not read the Excel workbook worksheet metadata.');
    }

    $sheetTargets = [];
    foreach (xlsxXPath($relationships, './xlsx:Relationship') as $relationship) {
        $attributes = $relationship->attributes();
        $relationshipId = trim((string)($attributes['Id'] ?? ''));
        $target = trim((string)($attributes['Target'] ?? ''));
        if ($relationshipId !== '' && $target !== '') {
            // Normalize arbitrary relationship attribute order and target paths.
            $sheetTargets[$relationshipId] = normalizeXlsxEntryName($target);
        }
    }

    $relationshipNamespace = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    $sheets = [];
    foreach (xlsxXPath($workbook, './xlsx:sheets/xlsx:sheet') as $sheet) {
        $attributes = $sheet->attributes();
        $relationshipAttributes = $sheet->attributes($relationshipNamespace);
        $relationshipId = trim((string)($relationshipAttributes['id'] ?? ''));
        $sheetName = html_entity_decode((string)($attributes['name'] ?? ''), ENT_QUOTES | ENT_XML1, 'UTF-8');
        $sheets[] = [
            'name' => $sheetName,
            'relationship_id' => $relationshipId,
            'target' => $sheetTargets[$relationshipId] ?? '',
        ];
    }

    return $sheets;
}

function listXlsxWorksheetEntries($path)
{
    if (!class_exists('ZipArchive')) {
        return [];
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return [];
    }

    $entries = [];
    for ($index = 0; $index < $zip->numFiles; $index++) {
        $entryName = $zip->getNameIndex($index);
        if (preg_match('#^xl/worksheets/[^/]+\\.xml$#i', $entryName)) {
            $entries[] = $entryName;
        }
    }
    $zip->close();
    sort($entries, SORT_NATURAL | SORT_FLAG_CASE);
    return $entries;
}

function parseXlsxSheetRow($sheetRow, $sharedStrings)
{
    $row = [];
    $nextColumnIndex = 0;
    foreach (xlsxXPath($sheetRow, './xlsx:c') as $cell) {
        $attributes = $cell->attributes();
        $reference = (string)($attributes['r'] ?? '');
        $type = (string)($attributes['t'] ?? '');
        $columnIndex = $reference !== '' ? excelColumnIndex($reference) : $nextColumnIndex;
        $value = '';

        if ($type === 's') {
            $valueNodes = xlsxXPath($cell, './xlsx:v');
            $value = $sharedStrings[(int)($valueNodes[0] ?? -1)] ?? '';
        } elseif ($type === 'inlineStr') {
            // Inline strings can be one <t> value or rich text split across
            // several <r><t> nodes.
            $value = xlsxRichText($cell);
        } else {
            $valueNodes = xlsxXPath($cell, './xlsx:v');
            $value = (string)($valueNodes[0] ?? '');
        }
        $row[$columnIndex] = normalizeExcelCell($value, $columnIndex);
        $nextColumnIndex = $columnIndex + 1;
    }
    if (empty($row)) {
        return null;
    }
    ksort($row);
    $normalizedRow = [];
    for ($i = 0; $i <= max(array_keys($row)); $i++) {
        $normalizedRow[] = $row[$i] ?? '';
    }
    return $normalizedRow;
}

function parseXlsxRows($sheetXml, $sharedStrings, &$sourceRowNumbers = null)
{
    $sheet = simplexml_load_string($sheetXml);
    if ($sheet === false) {
        throw new RuntimeException('Could not read an Excel worksheet.');
    }

    $rows = [];
    $sourceRowNumbers = [];
    // Use namespace-aware XPath for workbook XML from common generators.
    foreach (xlsxXPath($sheet, './xlsx:sheetData/xlsx:row') as $sheetRow) {
        $row = parseXlsxSheetRow($sheetRow, $sharedStrings);
        if ($row !== null) {
            $rows[] = $row;
            $attributes = $sheetRow->attributes();
            $sourceRowNumbers[] = max(1, (int)($attributes['r'] ?? count($rows)));
        }
    }
    return $rows;
}

function parseXlsxRowsFromEntry($path, $entryName, $sharedStrings, &$sourceRowNumbers = null)
{
    if (!class_exists('XMLReader')) {
        return null;
    }

    $reader = new XMLReader();
    $entryName = normalizeXlsxEntryName($entryName);
    $uri = 'zip://' . str_replace('\\', '/', $path) . '#' . $entryName;
    if (!@$reader->open($uri, null, LIBXML_NONET | LIBXML_COMPACT)) {
        return null;
    }

    $rows = [];
    $sourceRowNumbers = [];
    try {
        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row') {
                continue;
            }
            $rowXml = $reader->readOuterXML();
            $sheetRow = $rowXml === '' ? false : simplexml_load_string($rowXml);
            if ($sheetRow === false) {
                continue;
            }
            $row = parseXlsxSheetRow($sheetRow, $sharedStrings);
            if ($row !== null) {
                $rows[] = $row;
                $attributes = $sheetRow->attributes();
                $sourceRowNumbers[] = max(1, (int)($attributes['r'] ?? count($rows)));
            }
        }
    } finally {
        $reader->close();
    }
    return $rows;
}

function isWasteHeaderRow($row)
{
    return getWasteImportHeaderProfile($row)['is_high_confidence'];
}

function isWasteDateRow($row)
{
    $firstValue = firstNonEmptyCell($row);
    return preg_match('/^date\s*:/i', $firstValue) === 1;
}

function isWasteCollectionBlockRow($row)
{
    $firstValue = firstNonEmptyCell($row);
    // Three-day block labels identify a collection group, not a data row.
    return preg_match('/^\s*(?:\d+\s*[-â€“â€”]\s*)?day\s+(?:collection\s+)?block\s*:/i', $firstValue) === 1;
}

function getWasteWorksheetBlockLabel($row)
{
    if (isWasteDateRow($row)) {
        return firstNonEmptyCell($row);
    }

    // Treat a preceding Phase / Date row as source metadata, not data.
    if (normalizeImportHeader($row[0] ?? '') === 'phasedate') {
        return firstNonEmptyCell($row, 1);
    }

    if (isWasteCollectionBlockRow($row)) {
        return firstNonEmptyCell($row);
    }

    return null;
}

function isWasteTotalRow($row)
{
    return isWasteSummaryRow($row);
}

function getFixedBiomanCycle()
{
    return [
        'Marieta De Paz',
        'Mariel Valentino',
        'Marileyz Cuyos',
        'Rodolfo Valentino',
    ];
}

function fillMissingBiomanNames($rows)
{
    $biomanNames = getFixedBiomanCycle();

    // Restart approved collector names for each imported collection block.
    foreach ($rows as $index => $row) {
        if (trim((string)($row[0] ?? '')) === '') {
            $rows[$index][0] = $biomanNames[$index % count($biomanNames)];
        }
    }
    return $rows;
}

function inferWasteImportPeriod($sourceLabel)
{
    $sourceLabel = trim((string)$sourceLabel);
    if ($sourceLabel === '') {
        return '';
    }

    // Use filename dates for three-day workbooks when no dated row is available.
    $sourceLabel = preg_replace('/\.(?:csv|xlsx|xlsm|pdf)$/i', '', $sourceLabel);
    if (preg_match('/\b(?:19|20)\d{2}\b/', $sourceLabel)) {
        return $sourceLabel;
    }

    $monthNames = 'january|jan|february|feb|march|mar|april|apr|may|june|jun|july|jul|august|aug|september|sept|sep|october|oct|november|nov|december|dec';
    $datePattern = '/\b(?:' . $monthNames . ')\.?\s*\d{1,2}(?:\s*[-â€“â€”]\s*(?:(?:' . $monthNames . ')\.?\s*)?\d{1,2})?\b/i';
    if (preg_match($datePattern, $sourceLabel, $matches)) {
        return trim($matches[0]) . ' ' . date('Y');
    }

    return '';
}

function buildWasteWorksheetSourceLabel($sheetName, $blockLabel, $fileLabel = '')
{
    $sheetName = trim((string)$sheetName);
    $blockLabel = trim((string)$blockLabel);
    $fileLabel = trim((string)$fileLabel);
    [$group, $period] = splitWasteCollectionGroupAndPeriod($blockLabel, '');

    if ($period === '') {
        $period = inferWasteImportPeriod($blockLabel);
    }
    if ($period === '') {
        $period = inferWasteImportPeriod($fileLabel);
    }
    if ($period === '') {
        $period = inferWasteImportPeriod($sheetName);
    }

    if ($group === '') {
        [$fileGroup] = splitWasteCollectionGroupAndPeriod($fileLabel, '');
        $group = $fileGroup !== '' ? $fileGroup : $sheetName;
    }

    if ($period !== '') {
        return buildWasteCollectionSourceLabel($group, $period);
    }

    return $group !== '' ? $group : $sheetName;
}

function mergeWasteHeaderRows($topRow, $bottomRow)
{
    $merged = [];
    $count = max(count($topRow), count($bottomRow));
    for ($index = 0; $index < $count; $index++) {
        $top = trim((string)($topRow[$index] ?? ''));
        $bottom = trim((string)($bottomRow[$index] ?? ''));
        $merged[$index] = trim($top . ' ' . $bottom);
    }
    return $merged;
}

/** Find a high-confidence one- or two-row header at an arbitrary worksheet row. */
function detectWasteHeaderAt($rows, $rowIndex)
{
    $row = $rows[$rowIndex] ?? [];
    if (!rowHasData($row)) {
        return null;
    }
    $candidates = [['headers' => $row, 'height' => 1]];
    $next = $rows[$rowIndex + 1] ?? [];
    if (rowHasData($next)) {
        $candidates[] = ['headers' => mergeWasteHeaderRows($row, $next), 'height' => 2];
    }

    $best = null;
    $bestScore = -1;
    foreach ($candidates as $candidate) {
        $profile = getWasteImportHeaderProfile($candidate['headers']);
        if (!$profile['is_high_confidence']) {
            continue;
        }
        $score = count($profile['column_map'])
            + ($profile['long_format_columns']['type'] === null ? 0 : 1)
            + ($profile['long_format_columns']['amount'] === null ? 0 : 1);
        // Prefer one-row headers when confidence scores tie.
        if ($score > $bestScore || ($score === $bestScore && $best !== null && $candidate['height'] < $best['height'])) {
            $candidate['profile'] = $profile;
            $best = $candidate;
            $bestScore = $score;
        }
    }
    return $best;
}

function parseExcelWorksheetBlocks($rows, $sheetName, $fileLabel = '', $sourceRowNumbers = [])
{
    $currentHeaders = [];
    $currentPhaseLabel = buildWasteWorksheetSourceLabel($sheetName, '', $fileLabel);
    $importRows = [];
    $phaseLabels = [];
    $sourceRows = [];
    $blockRows = [];
    $blockRowLabels = [];

    $blockSourceRows = [];
    $flushBlock = static function () use (&$blockRows, &$blockRowLabels, &$blockSourceRows, &$importRows, &$phaseLabels, &$sourceRows, &$currentPhaseLabel) {
        foreach (fillMissingBiomanNames($blockRows) as $index => $row) {
            $importRows[] = $row;
            $phaseLabels[] = $blockRowLabels[$index] ?? $currentPhaseLabel;
            $sourceRows[] = $blockSourceRows[$index] ?? 0;
        }
        $blockRows = [];
        $blockRowLabels = [];
        $blockSourceRows = [];
    };

    for ($rowIndex = 0; $rowIndex < count($rows); $rowIndex++) {
        $row = $rows[$rowIndex];
        if (!rowHasData($row)) {
            continue;
        }
        $blockLabel = getWasteWorksheetBlockLabel($row);
        if ($blockLabel !== null) {
            $flushBlock();
            $currentPhaseLabel = buildWasteWorksheetSourceLabel($sheetName, $blockLabel, $fileLabel);
            continue;
        }
        // Require a confident new block while supporting split header rows.
        $headerCandidate = detectWasteHeaderAt($rows, $rowIndex);
        if ($headerCandidate !== null && !empty($currentHeaders) && isset($headerCandidate['profile']['column_map'][2])) {
            // A fresh Household heading starts a separate table.
            $flushBlock();
            $currentHeaders = $headerCandidate['headers'];
            $rowIndex += $headerCandidate['height'] - 1;
            continue;
        }
        if ($headerCandidate !== null || (!empty($currentHeaders) && hasRecognizedWasteHeaderFields($row))) {
            if ($headerCandidate !== null && empty($currentHeaders)) {
                $currentHeaders = $headerCandidate['headers'];
                $rowIndex += $headerCandidate['height'] - 1;
                continue;
            }
            $headerMap = getWasteImportColumnMap($row);
            $isSplitHeader = !empty($currentHeaders) && count($headerMap) < count(getWasteFormatHeaders());

            if (!$isSplitHeader) {
                $currentHeaders = $row;
                continue;
            }

            // Merge recognized lower-row headers in split workbook layouts.
            $longFormatColumns = getWasteImportLongFormatColumns($row);
            $recognizedHeaderColumns = array_values($headerMap);
            foreach ([$longFormatColumns['type'], $longFormatColumns['amount'], getWasteImportPeriodColumn($row)] as $sourceIndex) {
                if ($sourceIndex !== null) {
                    $recognizedHeaderColumns[] = $sourceIndex;
                }
            }
            foreach (array_unique($recognizedHeaderColumns) as $sourceIndex) {
                $currentHeaders[$sourceIndex] = $row[$sourceIndex] ?? '';
                $row[$sourceIndex] = '';
            }

            // Do not carry old categories into reused calculated-value columns.
            foreach ($row as $sourceIndex => $headerValue) {
                if (trim((string)$headerValue) !== '') {
                    $currentHeaders[$sourceIndex] = '';
                }
            }

            // Keep left-side record cells that share a lower split-header row.
            if (!rowHasData($row)) {
                continue;
            }
        }
        if (empty($currentHeaders) || isWasteTotalRow($row)) {
            continue;
        }

        [$mappedRows, $mappedPeriods] = limitRowsToWasteColumnsWithPeriods($currentHeaders, [$row], $currentPhaseLabel);
        foreach ($mappedRows as $mappedIndex => $mappedRow) {
            $blockRows[] = $mappedRow;
            $mappedPeriod = trim((string)($mappedPeriods[$mappedIndex] ?? ''));
            $blockRowLabels[] = $mappedPeriod !== '' ? $mappedPeriod : $currentPhaseLabel;
            $blockSourceRows[] = $sourceRowNumbers[$rowIndex] ?? ($rowIndex + 1);
        }
    }
    $flushBlock();
    return [$importRows, $phaseLabels, $sourceRows];
}

function getWasteWorksheetHeaderDiagnostic($rows)
{
    $bestMatch = [
        'header_row' => 0,
        'headers' => [],
        'profile' => getWasteImportHeaderProfile([]),
        'recognized_indexes' => [],
    ];
    $bestScore = -1;

    foreach ($rows as $rowIndex => $row) {
        if (!rowHasData($row)) {
            continue;
        }

        $headerCandidate = detectWasteHeaderAt($rows, $rowIndex);
        $headers = $headerCandidate['headers'] ?? $row;
        $profile = $headerCandidate['profile'] ?? getWasteImportHeaderProfile($headers);
        $recognizedIndexes = array_values($profile['column_map']);
        foreach (['type', 'amount'] as $field) {
            if ($profile['long_format_columns'][$field] !== null) {
                $recognizedIndexes[] = $profile['long_format_columns'][$field];
            }
        }
        $periodColumn = getWasteImportPeriodColumn($headers);
        if ($periodColumn !== null) {
            $recognizedIndexes[] = $periodColumn;
        }
        $recognizedIndexes = array_values(array_unique($recognizedIndexes));
        $score = count($recognizedIndexes);

        if ($score > $bestScore) {
            $bestScore = $score;
            $bestMatch = [
                'header_row' => $rowIndex + 1,
                'headers' => $headers,
                'profile' => $profile,
                'recognized_indexes' => $recognizedIndexes,
            ];
        }
    }

    $recognizedHeaders = [];
    foreach ($bestMatch['profile']['column_map'] as $targetIndex => $sourceIndex) {
        $recognizedHeaders[getWasteFormatHeaders()[$targetIndex]] = trim((string)($bestMatch['headers'][$sourceIndex] ?? ''));
    }
    foreach (['type' => 'Waste Type', 'amount' => 'Weight / Amount'] as $field => $label) {
        $sourceIndex = $bestMatch['profile']['long_format_columns'][$field];
        if ($sourceIndex !== null) {
            $recognizedHeaders[$label] = trim((string)($bestMatch['headers'][$sourceIndex] ?? ''));
        }
    }

    $unmatchedHeaders = [];
    foreach ($bestMatch['headers'] as $sourceIndex => $header) {
        $header = trim((string)$header);
        if ($header !== '' && !in_array($sourceIndex, $bestMatch['recognized_indexes'], true)) {
            $unmatchedHeaders[] = $header;
        }
    }

    return [
        'header_row' => $bestMatch['header_row'],
        'recognized_headers' => $recognizedHeaders,
        'unmatched_headers' => array_values(array_unique($unmatchedHeaders)),
        'is_high_confidence' => $bestMatch['profile']['is_high_confidence'],
        'is_complete_canonical' => count($bestMatch['profile']['column_map']) === count(getWasteFormatHeaders()),
    ];
}

function parseXlsxUpload($path, $fileName = '')
{
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Could not open the Excel file.');
        }
        $uncompressedBytes = 0;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            $uncompressedBytes += (int)($stat['size'] ?? 0);
            if ($uncompressedBytes > MAX_WASTE_IMPORT_UNCOMPRESSED_BYTES) {
                $zip->close();
                throw new RuntimeException('This workbook expands beyond the 1 GB safety limit. Split it before uploading.');
            }
        }
        $zip->close();
    }
    $sharedStrings = [];
    $sharedXml = readXlsxEntry($path, 'xl/sharedStrings.xml');
    if ($sharedXml !== false) {
        $shared = simplexml_load_string($sharedXml);
        if ($shared !== false) {
            foreach (xlsxXPath($shared, './xlsx:si') as $item) {
                $sharedStrings[] = xlsxRichText($item);
            }
        }
    }

    $workbook = readXlsxEntry($path, 'xl/workbook.xml');
    $relationships = readXlsxEntry($path, 'xl/_rels/workbook.xml.rels');
    if ($workbook === false || $relationships === false) {
        throw new RuntimeException('Could not find the worksheets in this Excel file.');
    }
    $sheetReferences = parseXlsxWorksheetReferences($workbook, $relationships);
    if (empty($sheetReferences)) {
        throw new RuntimeException('Could not find any worksheets in this Excel file.');
    }

    $allRows = [];
    $rowPhaseLabels = [];
    $rowSources = [];
    $rowSourceRows = [];
    $labels = [];
    $worksheetSummary = [
        'scanned' => 0,
        'with_waste_data' => 0,
        'without_waste_data' => 0,
        'skipped' => 0,
        'worksheets' => [],
    ];
    $worksheetResults = [];
    foreach ($sheetReferences as $sheetReference) {
        $worksheetSummary['scanned']++;
        $sheetName = trim((string)($sheetReference['name'] ?? ''));
        if ($sheetName === '') {
            $sheetName = 'Worksheet ' . $worksheetSummary['scanned'];
        }
        $sheetTarget = trim((string)($sheetReference['target'] ?? ''));
        if ($sheetTarget === '') {
            $worksheetSummary['without_waste_data']++;
            $worksheetSummary['worksheets'][] = [
                'name' => $sheetName,
                'status' => 'skipped',
                'reason' => 'The worksheet relationship is missing or invalid.',
                'rows' => 0,
                'unmatched_headers' => [],
            ];
            continue;
        }

        // Stream worksheet ZIP entries when XMLReader is available.
        $sheetSourceRows = [];
        $sheetRows = parseXlsxRowsFromEntry($path, $sheetTarget, $sharedStrings, $sheetSourceRows);
        if ($sheetRows === null) {
            $sheetXml = readXlsxEntry($path, $sheetTarget);
            if ($sheetXml === false) {
                $worksheetSummary['without_waste_data']++;
                $worksheetSummary['worksheets'][] = [
                    'name' => $sheetName,
                    'status' => 'skipped',
                    'reason' => 'The worksheet data could not be read.',
                    'rows' => 0,
                    'unmatched_headers' => [],
                ];
                continue;
            }
            $sheetRows = parseXlsxRows($sheetXml, $sharedStrings, $sheetSourceRows);
        }
        $headerDiagnostic = getWasteWorksheetHeaderDiagnostic($sheetRows);
        [$rows, $phaseLabels, $sourceRows] = parseExcelWorksheetBlocks($sheetRows, $sheetName, $fileName, $sheetSourceRows);
        if (empty($rows)) {
            $worksheetSummary['without_waste_data']++;
            $worksheetSummary['worksheets'][] = [
                'name' => $sheetName,
                'status' => 'skipped',
                'reason' => 'No high-confidence Waste Data headers were found.',
                'rows' => 0,
                'unmatched_headers' => $headerDiagnostic['unmatched_headers'],
            ];
            continue;
        }

        $worksheetResults[] = [
            'name' => $sheetName,
            'rows' => $rows,
            'phase_labels' => $phaseLabels,
            'source_rows' => $sourceRows,
            'is_complete_canonical' => $headerDiagnostic['is_complete_canonical'],
            'unmatched_headers' => $headerDiagnostic['unmatched_headers'],
        ];
    }

    $hasCompleteCanonicalSheet = false;
    foreach ($worksheetResults as $worksheetResult) {
        if ($worksheetResult['is_complete_canonical']) {
            $hasCompleteCanonicalSheet = true;
            break;
        }
    }

    foreach ($worksheetResults as $worksheetResult) {
        if ($hasCompleteCanonicalSheet && !$worksheetResult['is_complete_canonical']) {
            $worksheetSummary['skipped']++;
            $worksheetSummary['worksheets'][] = [
                'name' => $worksheetResult['name'],
                'status' => 'skipped',
                'reason' => 'A complete canonical worksheet is being imported instead.',
                'rows' => count($worksheetResult['rows']),
                'unmatched_headers' => $worksheetResult['unmatched_headers'],
            ];
            continue;
        }

        $worksheetSummary['with_waste_data']++;
        $worksheetSummary['worksheets'][] = [
            'name' => $worksheetResult['name'],
            'status' => 'imported',
            'reason' => '',
            'rows' => count($worksheetResult['rows']),
            'unmatched_headers' => [],
        ];
        foreach ($worksheetResult['rows'] as $row) {
            $allRows[] = $row;
        }
        foreach ($worksheetResult['phase_labels'] as $phaseLabel) {
            $rowPhaseLabels[] = $phaseLabel;
            if ($phaseLabel !== '') {
                $labels[$phaseLabel] = true;
            }
        }
        foreach ($worksheetResult['source_rows'] as $sourceRow) {
            $rowSourceRows[] = ['worksheet' => $worksheetResult['name'], 'row' => (int)$sourceRow];
        }
    }
    if (empty($allRows)) {
        return [[], [], '', [], $worksheetSummary, []];
    }
    return [getWasteFormatHeaders(), $allRows, count($labels) === 1 ? array_key_first($labels) : '', $rowPhaseLabels, $worksheetSummary, $rowSourceRows];
}

function detectUploadFormat($file)
{
    $sample = file_get_contents($file['tmp_name'], false, null, 0, 16);
    if ($sample === false || $sample === '') {
        throw new RuntimeException('The selected file could not be read. Please wait for the download to finish, then try again.');
    }

    // Do not rely on the browser MIME type: newly downloaded files are often
    // reported as application/octet-stream until the browser finishes indexing them.
    if (strncmp($sample, '%PDF-', 5) === 0) {
        return 'pdf';
    }
    if (strncmp($sample, "PK\x03\x04", 4) === 0) {
        return 'xlsx';
    }
    if (strncmp($sample, "\xD0\xCF\x11\xE0", 4) === 0) {
        return 'xls';
    }

    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (in_array($extension, ['csv', 'txt'], true) || preg_match('//u', $sample)) {
        return 'csv';
    }
    throw new RuntimeException('Unsupported file. Please choose a CSV, Excel (.xlsx or .xlsm), or text-based PDF file.');
}

function pdfTextValue($value)
{
    return str_replace(['\\\\', '\\(', '\\)', '\\n', '\\r'], ['\\', '(', ')', ' ', ' '], $value);
}

function parsePdfUpload($path)
{
    $pdf = file_get_contents($path);
    if ($pdf === false) {
        throw new RuntimeException('Could not read the PDF file.');
    }

    // EcoTrack exports uncompressed text streams. Reading those streams lets a
    // downloaded EcoTrack PDF be imported again without an external PDF library.
    preg_match_all('/\\((?:\\\\.|[^\\\\)])*\\)\\s*Tj/', $pdf, $matches);
    $values = array_map(static function ($match) {
        return pdfTextValue(substr($match, 1, -3));
    }, $matches[0]);
    $columnCount = count(getWasteFormatHeaders());
    if (count($values) < $columnCount + 3) {
        throw new RuntimeException('This PDF has no readable table data. Use a text-based EcoTrack PDF; scanned PDFs need to be converted to CSV or Excel first.');
    }

    // Start after every page header so multi-page EcoTrack downloads can also be
    // imported. Older downloads say "Name of Bi..."; the current layout says
    // "Bioman".
    $rows = [];
    for ($i = 0; $i < count($values); $i++) {
        if (stripos($values[$i], 'Name of Bi') !== 0 && stripos($values[$i], 'Bioman') !== 0) {
            continue;
        }
        $pageValues = [];
        for ($j = $i + $columnCount; $j < count($values); $j++) {
            if (stripos($values[$j], 'EcoTrack Waste Data Export') === 0) {
                break;
            }
            $pageValues[] = $values[$j];
        }
        foreach (array_chunk($pageValues, $columnCount) as $row) {
            if (count($row) === $columnCount && rowHasData($row) && !isWasteSummaryRow($row)) {
                $rows[] = $row;
            }
        }
    }
    if (empty($rows)) {
        throw new RuntimeException('No importable rows were found in this PDF.');
    }
    return [getWasteFormatHeaders(), $rows, ''];
}

function prepareWasteFileForImport($file)
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Failed to upload ' . ($file['name'] ?? 'file') . '. Error code: ' . ($file['error'] ?? UPLOAD_ERR_NO_FILE));
    }
    if ((int)($file['size'] ?? 0) > MAX_WASTE_IMPORT_BYTES) {
        throw new RuntimeException('This file exceeds the 250 MB import limit. Split the file and try again.');
    }
    $fileType = detectUploadFormat($file);
    $rowPhaseLabels = [];
    $worksheetSummary = null;
    if ($fileType === 'csv') {
        [$fileHeaders, $rows, $phaseDateLabel] = parseCsvUpload($file['tmp_name']);
    } elseif ($fileType === 'xlsx') {
        [$fileHeaders, $rows, $phaseDateLabel, $rowPhaseLabels, $worksheetSummary, $rowSources] = parseXlsxUpload($file['tmp_name'], $file['name'] ?? '');
    } elseif ($fileType === 'pdf') {
        [$fileHeaders, $rows, $phaseDateLabel] = parsePdfUpload($file['tmp_name']);
    } elseif ($fileType === 'xls') {
        throw new RuntimeException('Old .xls files are not supported. Please save the Excel file as .xlsx or .xlsm and upload again.');
    } else {
        throw new RuntimeException('Please upload a CSV, Excel .xlsx/.xlsm, or text-based PDF file.');
    }

    if (empty($fileHeaders)) {
        $fileName = trim((string)($file['name'] ?? 'The uploaded file'));
        $errorMessage = $fileName . ' does not contain recognizable Waste Data rows. Check the column headings and try again.';
        if ($fileType === 'xlsx' && !empty($worksheetSummary['worksheets'])) {
            $worksheetMessages = [];
            foreach ($worksheetSummary['worksheets'] as $worksheet) {
                $detail = trim((string)($worksheet['name'] ?? 'Worksheet')) . ': ' . trim((string)($worksheet['reason'] ?? 'No recognizable Waste Data headers were found.'));
                $unmatchedHeaders = array_slice((array)($worksheet['unmatched_headers'] ?? []), 0, 5);
                if (!empty($unmatchedHeaders)) {
                    $detail .= ' Unmatched headings: ' . implode(', ', $unmatchedHeaders) . '.';
                }
                $worksheetMessages[] = $detail;
            }
            if (!empty($worksheetMessages)) {
                $errorMessage .= ' ' . implode(' ', $worksheetMessages);
            }
        }
        throw new RuntimeException($errorMessage);
    }
    if ($fileType !== 'xlsx') {
        [$rows, $detectedPeriodLabels] = limitRowsToWasteColumnsWithPeriods($fileHeaders, $rows, $phaseDateLabel);
        if (empty($rows)) {
            throw new RuntimeException('No recognizable Waste Data category headers were found. Include fields such as Bioman, Area/Street, Households, Date/Year, or Waste Type and Weight.');
        }
        $rowPhaseLabels = array_map(static function ($label) use ($phaseDateLabel) {
            $label = trim((string)$label);
            return $label !== '' ? $label : $phaseDateLabel;
        }, $detectedPeriodLabels);
    }

    $rows = fillMissingBiomanNames(filterImportRows($rows));
    if (empty($rowPhaseLabels)) {
        $rowPhaseLabels = array_fill(0, count($rows), $phaseDateLabel);
    }

    $validRows = [];
    $validPhaseLabels = [];
    $validCollectionGroups = [];
    $validReportingPeriods = [];
    $validSourceRows = [];
    $rowsNeedingDateResolution = [];
    $skippedNoWasteCategoryAmount = 0;
    $skippedInvalidAmounts = 0;
    $skippedMissingHousehold = 0;
    $skippedMissingCollectionDate = 0;
    $skippedRows = [];
    foreach ($rows as $index => $row) {
        $rowPhaseLabel = $rowPhaseLabels[$index] ?? $phaseDateLabel;
        [$collectionGroup, $reportingPeriod] = splitWasteCollectionGroupAndPeriod($rowPhaseLabel);
        if ($reportingPeriod === '' && normalizeWasteDateValue($rowPhaseLabel) !== null) {
            $collectionGroup = '';
            $reportingPeriod = $rowPhaseLabel;
        }
        $households = cleanWasteHouseholdsValue($row[2] ?? '', $rowPhaseLabel, '');
        if ($households === '') {
            $skippedMissingHousehold++;
            $skippedRows[] = array_merge($rowSources[$index] ?? ['worksheet' => '', 'row' => $index + 1], ['reason' => 'missing_household']);
            continue;
        }
        if (!hasImportWasteCategoryAmount($row)) {
            $skippedNoWasteCategoryAmount++;
            $skippedRows[] = array_merge($rowSources[$index] ?? ['worksheet' => '', 'row' => $index + 1], ['reason' => 'missing_waste_amount']);
            continue;
        }
        foreach (getWasteImportAmountIndexes() as $amountIndex) {
            if (!isValidImportWasteAmount($row[$amountIndex] ?? '')) {
                $skippedInvalidAmounts++;
                $skippedRows[] = array_merge($rowSources[$index] ?? ['worksheet' => '', 'row' => $index + 1], ['reason' => 'invalid_waste_amount']);
                continue 2;
            }
        }
        $periodSource = $reportingPeriod !== '' ? $reportingPeriod : $rowPhaseLabel;
        $dateRange = parseWasteReportingDateRange($periodSource, normalizeWasteDateValue($rowPhaseLabel) ?? '');
        if ($dateRange === null) {
            if (wasteReportingLabelContainsRange($periodSource)) {
                throw new RuntimeException('Could not resolve the complete reporting date range "' . $periodSource . '". Use a date such as Dec 23-28, 2026 or Dec 30-Jan 4, 2027.');
            }
            $rowsNeedingDateResolution[] = count($validRows);
        }
        $row[2] = $households;
        $validRows[] = $row;
        $validPhaseLabels[] = $rowPhaseLabel;
        $validCollectionGroups[] = $collectionGroup;
        $validReportingPeriods[] = $reportingPeriod;
        $validSourceRows[] = $rowSources[$index] ?? ['worksheet' => '', 'row' => $index + 1];
    }

    if (empty($validRows)) {
        throw new RuntimeException('No valid Waste Data rows were found. Check that the uploaded file includes a Household value and valid non-negative waste amounts.');
    }

    return [
        'rows' => $validRows,
        'headers' => array_merge(['Collection Group', 'Date / Period'], getWasteFormatHeaders()),
        'phase_date_label' => $phaseDateLabel,
        'phase_labels' => $validPhaseLabels,
        'collection_groups' => $validCollectionGroups,
        'reporting_periods' => $validReportingPeriods,
        'source_rows' => $validSourceRows,
        'rows_needing_date_resolution' => $rowsNeedingDateResolution,
        'worksheet_summary' => $worksheetSummary,
        'skipped_no_waste_category_amount' => $skippedNoWasteCategoryAmount,
        'skipped_invalid_amounts' => $skippedInvalidAmounts,
        'skipped_missing_household' => $skippedMissingHousehold,
        'skipped_missing_collection_date' => $skippedMissingCollectionDate,
        'skipped_rows' => $skippedRows,
    ];
}

function uploadedFileList($uploadedFiles)
{
    if (!is_array($uploadedFiles['name'] ?? null)) {
        return [$uploadedFiles];
    }

    $files = [];
    foreach ($uploadedFiles['name'] as $index => $name) {
        $files[] = [
            'name' => $name,
            'type' => $uploadedFiles['type'][$index] ?? '',
            'tmp_name' => $uploadedFiles['tmp_name'][$index] ?? '',
            'error' => $uploadedFiles['error'][$index] ?? UPLOAD_ERR_NO_FILE,
            'size' => $uploadedFiles['size'][$index] ?? 0,
        ];
    }
    return $files;
}

function logWasteImportUploadFailure($conn, $userId, $file, Throwable $exception)
{
    $message = strtolower((string)$exception->getMessage());
    $outcome = str_contains($message, 'already been uploaded') || str_contains($message, 'data already exists')
        ? 'duplicate'
        : ($exception instanceof RuntimeException ? 'validation_error' : 'failed');
    $reasonCode = 'validation_failed';
    if (str_contains($message, '25 mb')) {
        $reasonCode = 'spreadsheet_too_large';
    } elseif (str_contains($message, '250 mb')) {
        $reasonCode = 'file_too_large';
    } elseif (str_contains($message, 'unsupported')) {
        $reasonCode = 'unsupported_format';
    } elseif (str_contains($message, 'not installed') || str_contains($message, 'could not start')) {
        $reasonCode = 'reader_unavailable';
    } elseif (str_contains($message, 'could not be read')) {
        $reasonCode = 'unreadable_file';
    } elseif ($outcome === 'duplicate') {
        $reasonCode = 'duplicate_data';
    } elseif (str_contains($message, 'no valid') || str_contains($message, 'recognizable')) {
        $reasonCode = 'invalid_waste_data';
    }

    logWasteImportEvent($conn, [
        'actor_user_id' => $userId,
        'original_name' => $file['name'] ?? '',
        'file_format' => $file['name'] ?? '',
        'outcome' => $outcome,
        'reason_code' => $reasonCode,
    ]);
}

function wasteImportBatchId()
{
    $bytes = bin2hex(random_bytes(16));
    return substr($bytes, 0, 8) . '-' . substr($bytes, 8, 4) . '-' . substr($bytes, 12, 4) . '-' . substr($bytes, 16, 4) . '-' . substr($bytes, 20);
}

function createWasteImportDraft($conn, $userId, array $payload)
{
    $draftId = wasteImportBatchId();
    $dateIndexes = array_flip($payload['rows_needing_date_resolution'] ?? []);
    $conn->beginTransaction();
    try {
        $draft = $conn->prepare('INSERT INTO waste_import_drafts (id, created_by, file_sha256, original_name, worksheet_summary, expires_at) VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))');
        $draft->execute([
            $draftId,
            $userId,
            $payload['file_sha256'],
            $payload['original_name'],
            json_encode($payload['worksheet_summary'] ?? null),
        ]);
        $rowInsert = $conn->prepare('INSERT INTO waste_import_draft_rows (draft_id, row_number, worksheet_name, source_row_number, phase_label, collection_group, reporting_period, needs_date_resolution, row_data) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($payload['rows'] as $index => $row) {
            $source = $payload['source_rows'][$index] ?? [];
            $rowInsert->execute([
                $draftId,
                $index + 1,
                substr(trim((string)($source['worksheet'] ?? '')), 0, 255),
                max(0, (int)($source['row'] ?? 0)),
                $payload['phase_labels'][$index] ?? '',
                $payload['collection_groups'][$index] ?? '',
                $payload['reporting_periods'][$index] ?? '',
                isset($dateIndexes[$index]) ? 1 : 0,
                json_encode(array_values($row), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        }
        $issueInsert = $conn->prepare('INSERT IGNORE INTO waste_import_draft_issues (draft_id, worksheet_name, source_row_number, reason_code) VALUES (?, ?, ?, ?)');
        foreach (($payload['skipped_rows'] ?? []) as $issue) {
            $issueInsert->execute([
                $draftId,
                substr(trim((string)($issue['worksheet'] ?? '')), 0, 255),
                max(0, (int)($issue['row'] ?? 0)),
                substr(trim((string)($issue['reason'] ?? 'invalid_row')), 0, 60),
            ]);
        }
        $conn->commit();
        return $draftId;
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }
}

function getWasteImportDraft($conn, $draftId, $userId)
{
    $stmt = $conn->prepare('SELECT * FROM waste_import_drafts WHERE id = ? AND created_by = ? AND expires_at >= NOW() LIMIT 1');
    $stmt->execute([$draftId, $userId]);
    return $stmt->fetch() ?: null;
}

function cleanupExpiredWasteImportDrafts($conn)
{
    $ids = $conn->query('SELECT id FROM waste_import_drafts WHERE expires_at < NOW()')->fetchAll(PDO::FETCH_COLUMN);
    if (empty($ids)) {
        return;
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $conn->beginTransaction();
    try {
        foreach (['waste_import_draft_issues', 'waste_import_draft_rows', 'waste_import_drafts'] as $table) {
            $column = $table === 'waste_import_drafts' ? 'id' : 'draft_id';
            $stmt = $conn->prepare("DELETE FROM `$table` WHERE `$column` IN ($placeholders)");
            $stmt->execute($ids);
        }
        $conn->commit();
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }
}

function discardWasteImportDraft($conn, $draftId, $userId)
{
    if (getWasteImportDraft($conn, $draftId, $userId) === null) {
        return;
    }
    $conn->beginTransaction();
    try {
        foreach (['waste_import_draft_issues', 'waste_import_draft_rows', 'waste_import_drafts'] as $table) {
            $column = $table === 'waste_import_drafts' ? 'id' : 'draft_id';
            $stmt = $conn->prepare("DELETE FROM `$table` WHERE `$column` = ?");
            $stmt->execute([$draftId]);
        }
        $conn->commit();
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }
}

function resolveWasteImportDraftPayload($conn, $draftId, $userId, $defaultArea, $startDate, $endDate)
{
    $draft = getWasteImportDraft($conn, $draftId, $userId);
    if ($draft === null) {
        throw new RuntimeException('The pending import resolution has expired. Upload the file again.');
    }
    $defaultArea = trim((string)$defaultArea);
    $providedStartDate = trim((string)$startDate);
    $providedEndDate = trim((string)$endDate);

    $rowsStmt = $conn->prepare('SELECT * FROM waste_import_draft_rows WHERE draft_id = ? ORDER BY row_number');
    $rowsStmt->execute([$draftId]);
    $issuesStmt = $conn->prepare('SELECT worksheet_name, source_row_number, reason_code FROM waste_import_draft_issues WHERE draft_id = ?');
    $issuesStmt->execute([$draftId]);
    $rows = [];
    $phaseLabels = [];
    $collectionGroups = [];
    $reportingPeriods = [];
    $sourceRows = [];
    $requiresArea = false;
    $requiresDate = false;
    $draftRows = $rowsStmt->fetchAll();
    foreach ($draftRows as $draftRow) {
        $row = json_decode((string)$draftRow['row_data'], true);
        $requiresArea = $requiresArea || (is_array($row) && trim((string)($row[1] ?? '')) === '');
        $requiresDate = $requiresDate || (int)$draftRow['needs_date_resolution'] === 1;
    }
    $startDate = $requiresDate ? normalizeWasteDateValue($providedStartDate) : null;
    $endDate = $requiresDate ? normalizeWasteDateValue($providedEndDate ?: $providedStartDate) : null;
    if (($requiresArea && $defaultArea === '') || ($requiresDate && ($startDate === null || $endDate === null || $endDate < $startDate))) {
        throw new RuntimeException('Provide the Area and/or valid start and end dates requested for the unresolved table.');
    }
    foreach ($draftRows as $draftRow) {
        $row = json_decode((string)$draftRow['row_data'], true);
        if (!is_array($row)) {
            continue;
        }
        if (trim((string)($row[1] ?? '')) === '') {
            $row[1] = $defaultArea;
        }
        $period = trim((string)($draftRow['reporting_period'] ?? ''));
        if ((int)$draftRow['needs_date_resolution'] === 1) {
            $period = $startDate . ($endDate !== $startDate ? ' to ' . $endDate : '');
        }
        $rows[] = $row;
        $phaseLabels[] = (string)($draftRow['phase_label'] ?? '');
        $collectionGroups[] = (string)($draftRow['collection_group'] ?? '');
        $reportingPeriods[] = $period;
        $sourceRows[] = ['worksheet' => $draftRow['worksheet_name'] ?? '', 'row' => (int)($draftRow['source_row_number'] ?? 0)];
    }
    if (empty($rows)) {
        throw new RuntimeException('No valid rows remain in the pending import.');
    }
    return [
        'file_sha256' => $draft['file_sha256'],
        'original_name' => $draft['original_name'],
        'rows' => $rows,
        'phase_labels' => $phaseLabels,
        'collection_groups' => $collectionGroups,
        'reporting_periods' => $reportingPeriods,
        'source_rows' => $sourceRows,
        'skipped_rows' => array_map(static function ($issue) {
            return ['worksheet' => $issue['worksheet_name'] ?? '', 'row' => (int)($issue['source_row_number'] ?? 0), 'reason' => $issue['reason_code'] ?? 'invalid_row'];
        }, $issuesStmt->fetchAll()),
        'worksheet_summary' => json_decode((string)($draft['worksheet_summary'] ?? ''), true),
    ];
}

function buildResolvedWasteImportPayload($conn, array $draftPayload)
{
    $rules = $conn ? loadWasteCollectionGroupRules($conn) : [];
    $records = [];
    $fingerprints = [];
    $unresolvedAreas = [];
    $duplicateRowsInFile = 0;
    $skippedRows = $draftPayload['skipped_rows'] ?? [];
    foreach ($draftPayload['rows'] as $index => $row) {
        $record = buildWasteImportDatabaseRecord(
            $row,
            $draftPayload['phase_labels'][$index] ?? '',
            $draftPayload['collection_groups'][$index] ?? '',
            $draftPayload['reporting_periods'][$index] ?? '',
            $rules
        );
        if ($record === null) {
            $source = $draftPayload['source_rows'][$index] ?? ['worksheet' => '', 'row' => $index + 1];
            $skippedRows[] = array_merge($source, ['reason' => 'missing_area_or_collection_date']);
            continue;
        }
        if (isset($fingerprints[$record['record_fingerprint']])) {
            $duplicateRowsInFile++;
            continue;
        }
        $fingerprints[$record['record_fingerprint']] = true;
        if (!empty($record['group_resolution_required'])) {
            $areaKey = normalizeWasteCollectionRuleArea($record['street']);
            $unresolvedAreas[$areaKey] = ['area' => $record['street'], 'rows' => ($unresolvedAreas[$areaKey]['rows'] ?? 0) + 1];
        }
        $records[] = $record;
    }
    if (empty($records)) {
        throw new RuntimeException('No valid Waste Data rows remain after resolving Area and Date.');
    }
    return [
        'requires_resolution' => false,
        'file_sha256' => $draftPayload['file_sha256'],
        'dataset_sha256' => buildWasteImportDatasetHash(array_keys($fingerprints)),
        'original_name' => $draftPayload['original_name'],
        'records' => $records,
        'worksheet_summary' => $draftPayload['worksheet_summary'] ?? null,
        'skipped_no_waste_category_amount' => 0,
        'skipped_invalid_amounts' => 0,
        'skipped_missing_household' => 0,
        'skipped_missing_collection_date' => 0,
        'skipped_rows' => $skippedRows,
        'duplicate_rows_in_file' => $duplicateRowsInFile,
        'unresolved_areas' => array_values($unresolvedAreas),
        'skipped_existing_rows' => 0,
    ];
}

function wasteImportStageColumns()
{
    return [
        'date', 'collection_date', 'phase_number', 'street', 'kilogram_of_waste',
        'recyclable_kg', 'residual_kg', 'hazardous_kg', 'garbage_collector',
        'phase_date_label', 'collection_group', 'collection_group_type', 'reporting_period', 'name_of_bioman',
        'households', 'tuesday_factory_returnable_kg', 'comply_tue', 'cd_processing',
        'wednesday_biowaste_kg', 'comply_wed', 'thursday_factory_returnable_kg',
        'friday_biowaste_kg', 'comply_fri', 'saturday_hazard_waste_kg',
        'residual_waste_kg', 'unclassified_waste_kg',
    ];
}

function buildWasteImportDatabaseRecord($data, $phaseDateLabel, $collectionGroup = '', $reportingPeriod = '', $rules = [])
{
    if (isWasteSummaryRow($data)) {
        return null;
    }

    $phaseDateLabel = trim((string)$phaseDateLabel);
    [$fallbackGroup, $fallbackPeriod] = splitWasteCollectionGroupAndPeriod($phaseDateLabel);
    $collectionGroup = trim((string)($collectionGroup !== '' ? $collectionGroup : $fallbackGroup));
    $reportingPeriod = trim((string)($reportingPeriod !== '' ? $reportingPeriod : $fallbackPeriod));
    if ($reportingPeriod === '' && normalizeWasteDateValue($phaseDateLabel) !== null) {
        $collectionGroup = '';
        $reportingPeriod = $phaseDateLabel;
    }

    $nameOfBioman = trim((string)($data[0] ?? ''));
    $street = trim((string)($data[1] ?? ''));
    $groupResolution = resolveWasteCollectionGroup($street, $collectionGroup, $rules);
    $collectionGroup = $groupResolution['collection_group'];
    $collectionGroupType = $groupResolution['collection_group_type'];
    $legacySourceLabel = buildWasteCollectionSourceLabel($collectionGroup, $reportingPeriod);
    $households = cleanWasteHouseholdsValue($data[2] ?? '', $legacySourceLabel, $collectionGroup);
    $dateRange = parseWasteReportingDateRange($reportingPeriod !== '' ? $reportingPeriod : $legacySourceLabel, normalizeWasteDateValue($legacySourceLabel) ?? '');
    $collectionDate = $dateRange['start'] ?? null;
    if ($street === '' || $collectionDate === null) {
        return null;
    }

    $record = [
        'date' => substr($reportingPeriod !== '' ? $reportingPeriod : $collectionDate, 0, 50),
        'collection_date' => $collectionDate,
        'phase_number' => substr($collectionGroup !== '' ? $collectionGroup : 'Unassigned', 0, 50),
        'street' => substr($street, 0, 100),
        'garbage_collector' => substr($nameOfBioman, 0, 100),
        'phase_date_label' => $legacySourceLabel,
        'collection_group' => $collectionGroup,
        'collection_group_type' => $collectionGroupType !== '' ? $collectionGroupType : null,
        'reporting_period' => $reportingPeriod,
        'name_of_bioman' => $nameOfBioman,
        'households' => $households,
        'tuesday_factory_returnable_kg' => importNumber($data[3] ?? 0),
        'comply_tue' => trim((string)($data[4] ?? '')),
        'cd_processing' => trim((string)($data[5] ?? '')),
        'wednesday_biowaste_kg' => importNumber($data[6] ?? 0),
        'comply_wed' => trim((string)($data[7] ?? '')),
        'thursday_factory_returnable_kg' => importNumber($data[8] ?? 0),
        'friday_biowaste_kg' => importNumber($data[9] ?? 0),
        'comply_fri' => trim((string)($data[10] ?? '')),
        'saturday_hazard_waste_kg' => importNumber($data[11] ?? 0),
        'residual_waste_kg' => importNumber($data[12] ?? 0),
        'unclassified_waste_kg' => importNumber($data[13] ?? 0),
    ];
    $record['kilogram_of_waste'] = $record['tuesday_factory_returnable_kg'] + $record['wednesday_biowaste_kg'] + $record['thursday_factory_returnable_kg'] + $record['friday_biowaste_kg'] + $record['saturday_hazard_waste_kg'] + $record['residual_waste_kg'] + $record['unclassified_waste_kg'];
    $record['recyclable_kg'] = $record['tuesday_factory_returnable_kg'] + $record['thursday_factory_returnable_kg'];
    $record['residual_kg'] = $record['residual_waste_kg'];
    $record['hazardous_kg'] = $record['saturday_hazard_waste_kg'];
    $record['record_fingerprint'] = buildWasteRecordFingerprint($record);
    $record['group_resolution_required'] = !$groupResolution['resolved'];
    return $record;
}

function wasteImportPreviewRow($record)
{
    return [
        $record['collection_group'], $record['reporting_period'], $record['name_of_bioman'],
        $record['street'], $record['households'], $record['tuesday_factory_returnable_kg'],
        $record['comply_tue'], $record['cd_processing'], $record['wednesday_biowaste_kg'],
        $record['comply_wed'], $record['thursday_factory_returnable_kg'],
        $record['friday_biowaste_kg'], $record['comply_fri'],
        $record['saturday_hazard_waste_kg'], $record['residual_waste_kg'],
        $record['unclassified_waste_kg'],
    ];
}

function prepareWasteImportPayload($file, $conn = null)
{
    $fileHash = hash_file('sha256', $file['tmp_name'] ?? '');
    if ($fileHash === false) {
        throw new RuntimeException('The selected file could not be hashed. Please upload it again.');
    }
    $preparedFile = prepareWasteFileForImport($file);
    $rules = $conn ? loadWasteCollectionGroupRules($conn) : [];
    $needsDate = array_flip($preparedFile['rows_needing_date_resolution'] ?? []);
    $needsResolution = false;
    foreach ($preparedFile['rows'] as $index => $row) {
        if (trim((string)($row[1] ?? '')) === '' || isset($needsDate[$index])) {
            $needsResolution = true;
            break;
        }
    }
    if ($needsResolution) {
        return [
            'requires_resolution' => true,
            'file_sha256' => $fileHash,
            'original_name' => substr(basename((string)($file['name'] ?? 'upload')), 0, 255),
            'rows' => $preparedFile['rows'],
            'phase_labels' => $preparedFile['phase_labels'],
            'collection_groups' => $preparedFile['collection_groups'],
            'reporting_periods' => $preparedFile['reporting_periods'],
            'source_rows' => $preparedFile['source_rows'] ?? [],
            'rows_needing_date_resolution' => $preparedFile['rows_needing_date_resolution'] ?? [],
            'skipped_rows' => $preparedFile['skipped_rows'] ?? [],
            'worksheet_summary' => $preparedFile['worksheet_summary'],
            'skipped_no_waste_category_amount' => $preparedFile['skipped_no_waste_category_amount'],
            'skipped_invalid_amounts' => $preparedFile['skipped_invalid_amounts'],
            'skipped_missing_household' => $preparedFile['skipped_missing_household'],
            'skipped_missing_collection_date' => 0,
        ];
    }
    $records = [];
    $fingerprints = [];
    $unresolvedAreas = [];
    $duplicateRowsInFile = 0;
    foreach ($preparedFile['rows'] as $index => $row) {
        $record = buildWasteImportDatabaseRecord(
            $row,
            $preparedFile['phase_labels'][$index] ?? $preparedFile['phase_date_label'],
            $preparedFile['collection_groups'][$index] ?? '',
            $preparedFile['reporting_periods'][$index] ?? '',
            $rules
        );
        if ($record === null) {
            continue;
        }
        if (isset($fingerprints[$record['record_fingerprint']])) {
            $duplicateRowsInFile++;
            continue;
        }
        $fingerprints[$record['record_fingerprint']] = true;
        if (!empty($record['group_resolution_required'])) {
            $areaKey = normalizeWasteCollectionRuleArea($record['street']);
            $unresolvedAreas[$areaKey] = ['area' => $record['street'], 'rows' => ($unresolvedAreas[$areaKey]['rows'] ?? 0) + 1];
        }
        $records[] = $record;
    }
    if (empty($records)) {
        throw new RuntimeException('No valid Waste Data rows were found.');
    }

    return [
        'requires_resolution' => false,
        'file_sha256' => $fileHash,
        'dataset_sha256' => buildWasteImportDatasetHash(array_keys($fingerprints)),
        'original_name' => substr(basename((string)($file['name'] ?? 'upload')), 0, 255),
        'records' => $records,
        'worksheet_summary' => $preparedFile['worksheet_summary'],
        'skipped_no_waste_category_amount' => $preparedFile['skipped_no_waste_category_amount'],
        'skipped_invalid_amounts' => $preparedFile['skipped_invalid_amounts'],
        'skipped_missing_household' => $preparedFile['skipped_missing_household'],
        'skipped_missing_collection_date' => $preparedFile['skipped_missing_collection_date'],
        'skipped_rows' => $preparedFile['skipped_rows'],
        'duplicate_rows_in_file' => $duplicateRowsInFile,
        'unresolved_areas' => array_values($unresolvedAreas),
        'skipped_existing_rows' => 0,
    ];
}

function wasteImportFindExistingValue($conn, $column, $values, $table)
{
    foreach (array_chunk(array_values(array_unique($values)), 500) as $chunk) {
        if (empty($chunk)) {
            continue;
        }
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $stmt = $conn->prepare("SELECT `$column` FROM `$table` WHERE `$column` IN ($placeholders) LIMIT 1");
        $stmt->execute($chunk);
        if ($stmt->fetchColumn() !== false) {
            return true;
        }
    }
    return false;
}

function buildWasteImportDatasetHash($fingerprints)
{
    $fingerprints = array_values(array_unique(array_filter($fingerprints, static function ($value) {
        return trim((string)$value) !== '';
    })));
    sort($fingerprints, SORT_STRING);
    return hash('sha256', implode("\n", $fingerprints));
}

function assertWasteImportPayloadsAreNew($conn, $payloads, $batchId = null)
{
    $datasetHashes = [];
    foreach ($payloads as $payload) {
        if (isset($datasetHashes[$payload['dataset_sha256']])) {
            throw new RuntimeException('This data has already been uploaded.');
        }
        $datasetHashes[$payload['dataset_sha256']] = true;
    }
    if (wasteImportFindExistingValue($conn, 'dataset_sha256', array_keys($datasetHashes), 'waste_import_manifests')) {
        throw new RuntimeException('This data has already been uploaded.');
    }
}

/**
 * A mixed file is useful: keep its new rows and explicitly account for the
 * rows already stored.  The full canonical dataset hash above is still used
 * to reject exact re-uploads independent of filename or row order.
 */
function filterExistingWasteImportPayloadRows($conn, $payloads, $batchId = null)
{
    $known = [];
    foreach ($payloads as &$payload) {
        $fingerprints = array_column($payload['records'], 'record_fingerprint');
        foreach (array_chunk(array_values(array_unique($fingerprints)), 500) as $chunk) {
            if (empty($chunk)) {
                continue;
            }
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $conn->prepare("SELECT record_fingerprint FROM waste_record_fingerprints WHERE record_fingerprint IN ($placeholders)");
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $fingerprint) {
                $known[$fingerprint] = true;
            }
        }
        if ($batchId !== null) {
            foreach (array_chunk(array_values(array_unique($fingerprints)), 500) as $chunk) {
                if (empty($chunk)) {
                    continue;
                }
                $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                $stmt = $conn->prepare("SELECT record_fingerprint FROM waste_import_staging_rows WHERE batch_id = ? AND record_fingerprint IN ($placeholders)");
                $stmt->execute(array_merge([$batchId], $chunk));
                foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $fingerprint) {
                    $known[$fingerprint] = true;
                }
            }
        }
        $rows = [];
        foreach ($payload['records'] as $record) {
            if (isset($known[$record['record_fingerprint']])) {
                $payload['skipped_existing_rows']++;
                continue;
            }
            $known[$record['record_fingerprint']] = true;
            $rows[] = $record;
        }
        $payload['records'] = $rows;
    }
    unset($payload);
    return $payloads;
}

function stageWasteImportPayloads($conn, $batchId, $userId, $payloads, $append = false)
{
    if (!$append) {
        $batch = $conn->prepare("INSERT INTO waste_import_batches (id, created_by, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))");
        $batch->execute([$batchId, $userId]);
    }

    $offsetStmt = $conn->prepare('SELECT COALESCE(MAX(row_number), 0) FROM waste_import_staging_rows WHERE batch_id = ?');
    $offsetStmt->execute([$batchId]);
    $rowNumber = (int)$offsetStmt->fetchColumn();
    $fileInsert = $conn->prepare('INSERT INTO waste_import_batch_files (batch_id, file_sha256, dataset_sha256, original_name, row_count) VALUES (?, ?, ?, ?, ?)');
    $issueInsert = $conn->prepare('INSERT IGNORE INTO waste_import_batch_issues (batch_id, source_file_sha256, worksheet_name, source_row_number, reason_code) VALUES (?, ?, ?, ?, ?)');
    $stageColumns = wasteImportStageColumns();
    $allColumns = array_merge(['batch_id', 'row_number', 'source_file_sha256', 'record_fingerprint'], $stageColumns);
    $rowPlaceholder = '(' . implode(', ', array_fill(0, count($allColumns), '?')) . ')';
    $stagingPrefix = 'INSERT INTO waste_import_staging_rows (' . implode(', ', $allColumns) . ') VALUES ';
    $stagedRows = [];
    $flushStagedRows = static function () use (&$stagedRows, $conn, $stagingPrefix, $rowPlaceholder) {
        if (empty($stagedRows)) {
            return;
        }
        $params = [];
        foreach ($stagedRows as $row) {
            foreach ($row as $value) {
                $params[] = $value;
            }
        }
        $insert = $conn->prepare($stagingPrefix . implode(', ', array_fill(0, count($stagedRows), $rowPlaceholder)));
        $insert->execute($params);
        $stagedRows = [];
    };

    $fileCount = 0;
    $rowCount = 0;
    foreach ($payloads as $payload) {
        $fileInsert->execute([$batchId, $payload['file_sha256'], $payload['dataset_sha256'], $payload['original_name'], count($payload['records'])]);
        foreach (($payload['skipped_rows'] ?? []) as $issue) {
            $issueInsert->execute([
                $batchId,
                $payload['file_sha256'],
                substr(trim((string)($issue['worksheet'] ?? '')), 0, 255),
                max(0, (int)($issue['row'] ?? 0)),
                substr(trim((string)($issue['reason'] ?? 'invalid_row')), 0, 60),
            ]);
        }
        $fileCount++;
        foreach ($payload['records'] as $record) {
            $rowNumber++;
            $values = [$batchId, $rowNumber, $payload['file_sha256'], $record['record_fingerprint']];
            foreach ($stageColumns as $column) {
                $values[] = $record[$column] ?? null;
            }
            $stagedRows[] = $values;
            if (count($stagedRows) === 1000) {
                $flushStagedRows();
            }
            $rowCount++;
        }
    }
    $flushStagedRows();
    $update = $conn->prepare('UPDATE waste_import_batches SET row_count = row_count + ?, file_count = file_count + ?, expires_at = DATE_ADD(NOW(), INTERVAL 24 HOUR) WHERE id = ? AND status = \'pending\'');
    $update->execute([$rowCount, $fileCount, $batchId]);
    if ($update->rowCount() !== 1) {
        throw new RuntimeException('The pending import is no longer available. Please upload the file again.');
    }
}

function cleanupExpiredWasteImportBatches($conn)
{
    $ids = $conn->query("SELECT id FROM waste_import_batches WHERE status = 'pending' AND expires_at < NOW()")->fetchAll(PDO::FETCH_COLUMN);
    if (empty($ids)) {
        return;
    }
    foreach ($ids as $expiredBatchId) {
        logWasteImportBatchOutcome($conn, $expiredBatchId, 'expired', 'preview_expired');
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $conn->beginTransaction();
    try {
        foreach (['waste_import_batch_issues', 'waste_import_staging_rows', 'waste_import_batch_files', 'waste_import_batches'] as $table) {
            $stmt = $conn->prepare("DELETE FROM `$table` WHERE " . ($table === 'waste_import_batches' ? 'id' : 'batch_id') . " IN ($placeholders)");
            $stmt->execute($ids);
        }
        $conn->commit();
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }
}

function getWasteImportBatch($conn, $batchId, $userId)
{
    $batch = $conn->prepare("SELECT * FROM waste_import_batches WHERE id = ? AND created_by = ? AND status = 'pending' AND expires_at >= NOW()");
    $batch->execute([$batchId, $userId]);
    return $batch->fetch() ?: null;
}

function getWasteImportBatchPreview($conn, $batchId)
{
    $stmt = $conn->prepare('SELECT ' . implode(', ', wasteImportStageColumns()) . ' FROM waste_import_staging_rows WHERE batch_id = ? ORDER BY row_number ASC LIMIT 100');
    $stmt->execute([$batchId]);
    return array_map('wasteImportPreviewRow', $stmt->fetchAll());
}

function getWasteImportBatchIssues($conn, $batchId, $limit = 100)
{
    $stmt = $conn->prepare('SELECT worksheet_name, source_row_number, reason_code FROM waste_import_batch_issues WHERE batch_id = ? ORDER BY worksheet_name, source_row_number LIMIT ' . max(1, (int)$limit));
    $stmt->execute([$batchId]);
    return array_map(static function ($issue) {
        return ['worksheet' => $issue['worksheet_name'] ?? '', 'row' => (int)($issue['source_row_number'] ?? 0), 'reason' => $issue['reason_code'] ?? 'invalid_row'];
    }, $stmt->fetchAll());
}

function getWasteImportBatchUnresolvedAreas($conn, $batchId)
{
    $stmt = $conn->prepare("SELECT street, COUNT(*) AS row_count
        FROM waste_import_staging_rows
        WHERE batch_id = ? AND (collection_group IS NULL OR TRIM(collection_group) = '' OR collection_group_type IS NULL)
        GROUP BY street ORDER BY street");
    $stmt->execute([$batchId]);
    return $stmt->fetchAll();
}

function refreshWasteImportBatchDatasetHashes($conn, $batchId)
{
    $files = $conn->prepare('SELECT file_sha256 FROM waste_import_batch_files WHERE batch_id = ?');
    $files->execute([$batchId]);
    $fingerprints = $conn->prepare('SELECT record_fingerprint FROM waste_import_staging_rows WHERE batch_id = ? AND source_file_sha256 = ? ORDER BY record_fingerprint');
    $update = $conn->prepare('UPDATE waste_import_batch_files SET dataset_sha256 = ?, row_count = ? WHERE batch_id = ? AND file_sha256 = ?');
    foreach ($files->fetchAll(PDO::FETCH_COLUMN) as $fileHash) {
        $fingerprints->execute([$batchId, $fileHash]);
        $values = $fingerprints->fetchAll(PDO::FETCH_COLUMN);
        $update->execute([buildWasteImportDatasetHash($values), count($values), $batchId, $fileHash]);
    }
}

function resolveWasteImportBatchGroups($conn, $batchId, $userId, array $resolutions, $saveRules = [])
{
    if (getWasteImportBatch($conn, $batchId, $userId) === null) {
        throw new RuntimeException('The pending import is no longer available. Please upload the file again.');
    }
    $normalized = [];
    foreach ($resolutions as $area => $resolution) {
        $areaKey = normalizeWasteCollectionRuleArea($area);
        $group = trim((string)($resolution['collection_group'] ?? ''));
        $type = strtolower(trim((string)($resolution['collection_group_type'] ?? '')));
        if ($areaKey === '' || $group === '' || !in_array($type, ['phase', 'establishment'], true)) {
            continue;
        }
        $normalized[$areaKey] = ['collection_group' => $group, 'collection_group_type' => $type, 'area' => trim((string)$area)];
    }
    if (empty($normalized)) {
        throw new RuntimeException('Choose a Collection Group and type for every unresolved area.');
    }

    $conn->beginTransaction();
    try {
        $ruleInsert = $conn->prepare("INSERT INTO waste_collection_group_rules (normalized_area, collection_group, collection_group_type, created_by)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE collection_group = VALUES(collection_group), collection_group_type = VALUES(collection_group_type), is_active = 1");
        $rows = $conn->prepare('SELECT * FROM waste_import_staging_rows WHERE batch_id = ? ORDER BY row_number');
        $rows->execute([$batchId]);
        $update = $conn->prepare("UPDATE waste_import_staging_rows
            SET collection_group = ?, collection_group_type = ?, phase_number = ?, phase_date_label = ?, record_fingerprint = ?
            WHERE batch_id = ? AND row_number = ?");
        foreach ($rows->fetchAll() as $row) {
            $areaKey = normalizeWasteCollectionRuleArea($row['street'] ?? '');
            if (!isset($normalized[$areaKey])) {
                continue;
            }
            $resolution = $normalized[$areaKey];
            if (!empty($saveRules[$areaKey])) {
                $ruleInsert->execute([$areaKey, $resolution['collection_group'], $resolution['collection_group_type'], $userId]);
            }
            $row['collection_group'] = $resolution['collection_group'];
            $row['collection_group_type'] = $resolution['collection_group_type'];
            $row['phase_number'] = substr($resolution['collection_group'], 0, 50);
            $row['phase_date_label'] = buildWasteCollectionSourceLabel($resolution['collection_group'], $row['reporting_period'] ?? '');
            $row['record_fingerprint'] = buildWasteRecordFingerprint($row);
            $update->execute([$row['collection_group'], $row['collection_group_type'], $row['phase_number'], $row['phase_date_label'], $row['record_fingerprint'], $batchId, $row['row_number']]);
        }
        if (!empty(getWasteImportBatchUnresolvedAreas($conn, $batchId))) {
            throw new RuntimeException('Choose a Collection Group and type for every unresolved area.');
        }
        refreshWasteImportBatchDatasetHashes($conn, $batchId);
        $conn->commit();
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }
}

function discardWasteImportBatch($conn, $batchId, $userId, $auditOutcome = 'cancelled')
{
    $batch = getWasteImportBatch($conn, $batchId, $userId);
    if ($batch === null) {
        return;
    }
    if ($auditOutcome !== '') {
        logWasteImportBatchOutcome($conn, $batchId, $auditOutcome, 'cancelled_by_admin');
    }
    $conn->beginTransaction();
    try {
        $deleteIssues = $conn->prepare('DELETE FROM waste_import_batch_issues WHERE batch_id = ?');
        $deleteIssues->execute([$batchId]);
        $deleteRows = $conn->prepare('DELETE FROM waste_import_staging_rows WHERE batch_id = ?');
        $deleteRows->execute([$batchId]);
        $deleteFiles = $conn->prepare('DELETE FROM waste_import_batch_files WHERE batch_id = ?');
        $deleteFiles->execute([$batchId]);
        $deleteBatch = $conn->prepare('DELETE FROM waste_import_batches WHERE id = ? AND created_by = ? AND status = \'pending\'');
        $deleteBatch->execute([$batchId, $userId]);
        $conn->commit();
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }
}

function confirmWasteImportBatch($conn, $batchId, $userId)
{
    // The audit table must exist before opening the transaction because MySQL
    // DDL performs an implicit commit.
    ensureWasteImportAuditTable($conn);
    $conn->beginTransaction();
    try {
        $batchStmt = $conn->prepare("SELECT * FROM waste_import_batches WHERE id = ? AND created_by = ? AND status = 'pending' AND expires_at >= NOW() FOR UPDATE");
        $batchStmt->execute([$batchId, $userId]);
        $batch = $batchStmt->fetch();
        if (!$batch) {
            throw new RuntimeException('The pending import is no longer available. Please upload the file again.');
        }

        $unresolved = $conn->prepare("SELECT COUNT(*) FROM waste_import_staging_rows WHERE batch_id = ? AND (collection_group IS NULL OR TRIM(collection_group) = '' OR collection_group_type IS NULL)");
        $unresolved->execute([$batchId]);
        if ((int)$unresolved->fetchColumn() > 0) {
            throw new RuntimeException('Resolve every unmatched area before importing.');
        }
        $existingDataset = $conn->prepare('SELECT 1 FROM waste_import_batch_files bf INNER JOIN waste_import_manifests m ON m.dataset_sha256 = bf.dataset_sha256 WHERE bf.batch_id = ? LIMIT 1');
        $existingDataset->execute([$batchId]);
        if ($existingDataset->fetchColumn() !== false) {
            throw new RuntimeException('This data has already been uploaded.');
        }

        // Recheck immediately before committing. A different administrator may
        // have imported matching rows while this batch was being reviewed.
        $removeKnownRows = $conn->prepare('DELETE s FROM waste_import_staging_rows s INNER JOIN waste_record_fingerprints f ON f.record_fingerprint = s.record_fingerprint WHERE s.batch_id = ?');
        $removeKnownRows->execute([$batchId]);
        $skippedExisting = $removeKnownRows->rowCount();
        $remaining = $conn->prepare('SELECT COUNT(*) FROM waste_import_staging_rows WHERE batch_id = ?');
        $remaining->execute([$batchId]);
        if ((int)$remaining->fetchColumn() === 0) {
            throw new RuntimeException('This data has already been uploaded.');
        }

        $manifest = $conn->prepare('INSERT INTO waste_import_manifests (file_sha256, dataset_sha256, original_name, row_count, imported_by) SELECT file_sha256, dataset_sha256, original_name, row_count, ? FROM waste_import_batch_files WHERE batch_id = ?');
        try {
            $manifest->execute([$userId, $batchId]);
        } catch (PDOException $e) {
            $fileRace = $conn->prepare('SELECT 1 FROM waste_import_batch_files bf INNER JOIN waste_import_manifests m ON m.dataset_sha256 = bf.dataset_sha256 WHERE bf.batch_id = ? LIMIT 1');
            $fileRace->execute([$batchId]);
            if ($fileRace->fetchColumn() !== false) {
                throw new RuntimeException('This data has already been uploaded.');
            }
            throw new RuntimeException('This data has already been uploaded.');
        }
        $fingerprints = $conn->prepare('INSERT INTO waste_record_fingerprints (record_fingerprint) SELECT record_fingerprint FROM waste_import_staging_rows WHERE batch_id = ?');
        try {
            $fingerprints->execute([$batchId]);
        } catch (PDOException $e) {
            throw new RuntimeException('Data already exists');
        }

        $columns = wasteImportStageColumns();
        $recordColumns = array_merge($columns, ['record_fingerprint']);
        $insertRecords = $conn->prepare('INSERT INTO waste_records (' . implode(', ', $recordColumns) . ') SELECT ' . implode(', ', $recordColumns) . ' FROM waste_import_staging_rows WHERE batch_id = ? ORDER BY row_number ASC');
        $insertRecords->execute([$batchId]);
        $imported = $insertRecords->rowCount();
        // Allocate only after parent records exist, while the staging rows are
        // still available to identify precisely this confirmed batch.
        storeWasteImportBatchDailyAllocations($conn, $batchId);
        $finish = $conn->prepare("UPDATE waste_import_batches SET status = 'imported', imported_at = NOW() WHERE id = ?");
        $finish->execute([$batchId]);
        logWasteImportBatchOutcome($conn, $batchId, 'imported', 'confirmed_import');
        $cleanupRows = $conn->prepare('DELETE FROM waste_import_staging_rows WHERE batch_id = ?');
        $cleanupRows->execute([$batchId]);
        $cleanupIssues = $conn->prepare('DELETE FROM waste_import_batch_issues WHERE batch_id = ?');
        $cleanupIssues->execute([$batchId]);
        $cleanupFiles = $conn->prepare('DELETE FROM waste_import_batch_files WHERE batch_id = ?');
        $cleanupFiles->execute([$batchId]);
        touchWasteDataVersion($conn);
        $conn->commit();
        return ['imported' => $imported, 'skipped_existing' => $skippedExisting];
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }
}

// Handle the first file and create its reviewable preview.
if ($importPostIsValid && $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['csv_file']) && !isset($_POST['confirm_import'])) {
    try {
        if (!$conn) {
            throw new RuntimeException('The database is unavailable. Please try again later.');
        }
        $payload = prepareWasteImportPayload($_FILES['csv_file'], $conn);
        if (!empty($payload['requires_resolution'])) {
            if (!empty($_SESSION['waste_import_draft_id'])) {
                discardWasteImportDraft($conn, (string)$_SESSION['waste_import_draft_id'], $currentUserId);
            }
            $draftId = createWasteImportDraft($conn, $currentUserId, $payload);
            $_SESSION['waste_import_draft_id'] = $draftId;
            $importResolutionDraft = getWasteImportDraft($conn, $draftId, $currentUserId);
            $importDraftRowCount = count($payload['rows']);
            $importDraftIssueCount = count($payload['skipped_rows'] ?? []);
            $message = 'Table recognized. Provide the missing Area and collection date range to continue; valid rows and skipped-row reasons have been retained for 24 hours.';
        } else {
            assertWasteImportPayloadsAreNew($conn, [$payload]);
            $payload = filterExistingWasteImportPayloadRows($conn, [$payload])[0];
            if (empty($payload['records'])) {
                throw new RuntimeException('This data has already been uploaded.');
            }
            $activeImportBatchId = wasteImportBatchId();
            $conn->beginTransaction();
            try {
                stageWasteImportPayloads($conn, $activeImportBatchId, $currentUserId, [$payload]);
                $conn->commit();
            } catch (Throwable $e) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                throw $e;
            }

            $_SESSION['import_batch_id'] = $activeImportBatchId;
            $_SESSION['import_worksheet_summary'] = $payload['worksheet_summary'];
            $file_headers = array_merge(['Collection Group', 'Date / Period'], getWasteFormatHeaders());
            $preview_display_data = array_map('wasteImportPreviewRow', array_slice($payload['records'], 0, 100));
            $previewTotalRows = count($payload['records']);
            $excelWorksheetSummary = $payload['worksheet_summary'];
            $unresolvedImportAreas = getWasteImportBatchUnresolvedAreas($conn, $activeImportBatchId);
            $message = 'File loaded! Showing the first ' . count($preview_display_data) . ' of ' . $previewTotalRows . ' valid rows with Waste Data columns.';
            if ($excelWorksheetSummary !== null) {
                $message .= ' Scanned ' . $excelWorksheetSummary['scanned'] . ' worksheet(s); ' . $excelWorksheetSummary['with_waste_data'] . ' worksheet(s) contain recognizable Waste Data columns and will be imported.';
                if (($excelWorksheetSummary['skipped'] ?? 0) > 0) {
                    $message .= ' Skipped ' . $excelWorksheetSummary['skipped'] . ' supporting worksheet(s) because a complete canonical table was found.';
                }
            }
            if ($payload['skipped_no_waste_category_amount'] > 0) {
                $message .= ' Skipped ' . $payload['skipped_no_waste_category_amount'] . ' row(s) without a Waste Data category amount.';
            }
            if ($payload['skipped_invalid_amounts'] > 0) {
                $message .= ' Skipped ' . $payload['skipped_invalid_amounts'] . ' row(s) with invalid or negative waste amounts.';
            }
            if (($payload['skipped_missing_household'] ?? 0) > 0) {
                $message .= ' Skipped ' . $payload['skipped_missing_household'] . ' row(s) without a Household value.';
            }
            if ($payload['skipped_missing_collection_date'] > 0) {
                $message .= ' Skipped ' . $payload['skipped_missing_collection_date'] . ' row(s) without a readable Date / Period.';
            }
            if ($payload['skipped_existing_rows'] > 0 || $payload['duplicate_rows_in_file'] > 0) {
                $message .= ' Skipped ' . ((int)$payload['skipped_existing_rows'] + (int)$payload['duplicate_rows_in_file']) . ' duplicate row(s).';
            }
            $message .= " Add more files or click 'UPLOAD ALL FILES' to import.";
        }
    } catch (Throwable $e) {
        if (!empty($previousPreviewDisplayData)) {
            $preview_display_data = $previousPreviewDisplayData;
            $file_headers = $previousFileHeaders;
            $excelWorksheetSummary = $previousWorksheetSummary;
        }
        $error = $e instanceof RuntimeException ? $e->getMessage() : 'Could not prepare the import. Please try again.';
        logWasteImportUploadFailure($conn, $currentUserId, $_FILES['csv_file'], $e);
    }
}

// Compact worksheets can be valid Waste Data while omitting a shared Area or
// date. Resolve those two values from a temporary, owner-scoped draft before
// rows enter the normal staging and duplicate-protection workflow.
if ($importPostIsValid && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resolve_import_draft'])) {
    try {
        if (!$conn || empty($_SESSION['waste_import_draft_id'])) {
            throw new RuntimeException('No pending import resolution was found. Upload the file again.');
        }
        $draftId = (string)$_SESSION['waste_import_draft_id'];
        $draftPayload = resolveWasteImportDraftPayload(
            $conn,
            $draftId,
            $currentUserId,
            $_POST['resolution_area_default'] ?? '',
            $_POST['resolution_start_date'] ?? '',
            $_POST['resolution_end_date'] ?? ''
        );
        $payload = buildResolvedWasteImportPayload($conn, $draftPayload);
        assertWasteImportPayloadsAreNew($conn, [$payload]);
        $payload = filterExistingWasteImportPayloadRows($conn, [$payload])[0];
        if (empty($payload['records'])) {
            throw new RuntimeException('This data has already been uploaded.');
        }
        $activeImportBatchId = wasteImportBatchId();
        $conn->beginTransaction();
        try {
            stageWasteImportPayloads($conn, $activeImportBatchId, $currentUserId, [$payload]);
            $conn->commit();
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            throw $e;
        }
        discardWasteImportDraft($conn, $draftId, $currentUserId);
        unset($_SESSION['waste_import_draft_id']);
        $importResolutionDraft = null;
        $_SESSION['import_batch_id'] = $activeImportBatchId;
        $_SESSION['import_worksheet_summary'] = $payload['worksheet_summary'];
        $file_headers = array_merge(['Collection Group', 'Date / Period'], getWasteFormatHeaders());
        $preview_display_data = array_map('wasteImportPreviewRow', array_slice($payload['records'], 0, 100));
        $previewTotalRows = count($payload['records']);
        $excelWorksheetSummary = $payload['worksheet_summary'];
        $unresolvedImportAreas = getWasteImportBatchUnresolvedAreas($conn, $activeImportBatchId);
        $message = 'Area and date range applied. Showing ' . count($preview_display_data) . ' of ' . $previewTotalRows . ' validated rows.';
    } catch (Throwable $e) {
        $error = $e instanceof RuntimeException ? $e->getMessage() : 'Could not resolve the import fields. Please try again.';
    }
}

// Queue extra files before import so empty browser slots are ignored.
if ($importPostIsValid && $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_files']) && isset($_FILES['additional_files'])) {
    $failedAdditionalFile = null;
    try {
        if (!$conn || empty($_SESSION['import_batch_id'])) {
            throw new RuntimeException('No pending import was found. Please upload the first file again.');
        }
        $activeImportBatchId = (string)$_SESSION['import_batch_id'];
        if (getWasteImportBatch($conn, $activeImportBatchId, $currentUserId) === null) {
            throw new RuntimeException('The pending import is no longer available. Please upload the first file again.');
        }
        $payloads = [];
        foreach (uploadedFileList($_FILES['additional_files']) as $additionalFile) {
            // Ignore untouched browser file inputs.
            if (($additionalFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE || (int)($additionalFile['size'] ?? 0) === 0) {
                continue;
            }
            $failedAdditionalFile = $additionalFile;
            $payloads[] = prepareWasteImportPayload($additionalFile, $conn);
        }
        if (!empty($payloads)) {
            assertWasteImportPayloadsAreNew($conn, $payloads, $activeImportBatchId);
            $payloads = filterExistingWasteImportPayloadRows($conn, $payloads, $activeImportBatchId);
            $payloads = array_values(array_filter($payloads, static function ($payload) {
                return !empty($payload['records']);
            }));
            if (empty($payloads)) {
                throw new RuntimeException('This data has already been uploaded.');
            }
            $conn->beginTransaction();
            try {
                stageWasteImportPayloads($conn, $activeImportBatchId, $currentUserId, $payloads, true);
                $conn->commit();
            } catch (Throwable $e) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                throw $e;
            }
            $activeBatch = getWasteImportBatch($conn, $activeImportBatchId, $currentUserId);
            $preview_display_data = getWasteImportBatchPreview($conn, $activeImportBatchId);
            $previewTotalRows = (int)($activeBatch['row_count'] ?? 0);
            $file_headers = array_merge(['Collection Group', 'Date / Period'], getWasteFormatHeaders());
            $unresolvedImportAreas = getWasteImportBatchUnresolvedAreas($conn, $activeImportBatchId);
            $message = count($payloads) . ' file(s) added to the import queue. Showing the first ' . count($preview_display_data) . ' of ' . $previewTotalRows . ' total rows.';
        }
    } catch (Throwable $e) {
        $error = $e instanceof RuntimeException ? $e->getMessage() : 'Could not add the selected files. Please try again.';
        if ($failedAdditionalFile !== null) {
            logWasteImportUploadFailure($conn, $currentUserId, $failedAdditionalFile, $e);
        }
    }
}

// Resolve unknown three-day areas during review before they are persisted.
if ($importPostIsValid && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resolve_groups'])) {
    try {
        if (!$conn || empty($_SESSION['import_batch_id'])) {
            throw new RuntimeException('No pending import was found. Please upload the file again.');
        }
        $resolutions = [];
        $saveRules = [];
        foreach ((array)($_POST['resolution_area'] ?? []) as $key => $area) {
            $areaKey = normalizeWasteCollectionRuleArea($area);
            $resolutions[$area] = [
                'collection_group' => $_POST['resolution_group'][$key] ?? '',
                'collection_group_type' => $_POST['resolution_type'][$key] ?? '',
            ];
            if (!empty($_POST['resolution_save'][$key])) {
                $saveRules[$areaKey] = true;
            }
        }
        $activeImportBatchId = (string)$_SESSION['import_batch_id'];
        resolveWasteImportBatchGroups($conn, $activeImportBatchId, $currentUserId, $resolutions, $saveRules);
        $activeBatch = getWasteImportBatch($conn, $activeImportBatchId, $currentUserId);
        $preview_display_data = getWasteImportBatchPreview($conn, $activeImportBatchId);
        $previewTotalRows = (int)($activeBatch['row_count'] ?? 0);
        $file_headers = array_merge(['Collection Group', 'Date / Period'], getWasteFormatHeaders());
        $unresolvedImportAreas = [];
        $message = 'Collection Groups resolved. Review the updated preview, then import the validated rows.';
    } catch (Throwable $e) {
        $error = $e instanceof RuntimeException ? $e->getMessage() : 'Could not resolve the selected Collection Groups.';
    }
}

// The preview is intentionally available without an OTP. Only the final,
// irreversible database write requires a purpose-scoped verification code.
if ($importPostIsValid && $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['confirm_import']) && $error === '') {
    $submittedImportOtp = trim((string)($_POST['import_otp'] ?? ''));
    $currentUser = getCurrentUser();

    if (empty($_SESSION['import_batch_id']) || !$conn) {
        $error = 'No file data found. Please upload again.';
    } elseif (!verifyAndConsumeActionOtp($conn, $currentUser, 'waste_import', $submittedImportOtp)) {
        $wasAlreadyPending = false;
        $otpReady = ensurePendingActionOtp($conn, $currentUser, 'waste_import', $wasAlreadyPending);
        $importOtpRequired = $otpReady;

        if (!$otpReady) {
            $error = 'A verification code could not be sent to your registered email address. Check the email in your account and try again.';
            logActivity('Requested Waste Data import verification', 'Waste Data', 'Failed', $conn);
        } elseif ($submittedImportOtp !== '') {
            $error = $wasAlreadyPending
                ? 'The verification code is invalid. Enter the current six-digit code sent to your email.'
                : 'The verification code expired. A new six-digit code was sent to your registered email address.';
            logActivity('Verified Waste Data import code', 'Waste Data', 'Failed', $conn);
        } else {
            $message = $wasAlreadyPending
                ? 'Enter the current six-digit verification code sent to your registered email address to import this batch.'
                : 'A six-digit verification code was sent to your registered email address. Enter it to import this batch.';
            logActivity('Requested Waste Data import verification', 'Waste Data', 'Success', $conn);
        }
    } else {
        $activeImportBatchId = (string)$_SESSION['import_batch_id'];
        try {
            $importResult = confirmWasteImportBatch($conn, $activeImportBatchId, $currentUserId);
            $imported = (int)$importResult['imported'];
            unset($_SESSION['import_batch_id'], $_SESSION['import_worksheet_summary']);
            $preview_display_data = [];
            $file_headers = [];
            $excelWorksheetSummary = null;
            $previewTotalRows = 0;
            $message = "Import completed! $imported records imported successfully.";
            if (!empty($importResult['skipped_existing'])) {
                $message .= ' ' . (int)$importResult['skipped_existing'] . ' duplicate row(s) were skipped.';
            }
            logActivity('Imported ' . $imported . ' waste record(s)', 'Waste Data', 'Success', $conn);
        } catch (Throwable $e) {
            $error = $e instanceof RuntimeException ? $e->getMessage() : 'Could not complete the import. Please try again.';
            if ($error === 'File already uploaded' || $error === 'Data already exists' || $error === 'This data has already been uploaded.') {
                logWasteImportBatchOutcome($conn, $activeImportBatchId, 'duplicate', 'duplicate_data');
                try {
                    discardWasteImportBatch($conn, $activeImportBatchId, $currentUserId, '');
                } catch (Throwable $discardError) {
                    error_log('Could not discard duplicate waste import: ' . $discardError->getMessage());
                }
                unset($_SESSION['import_batch_id'], $_SESSION['import_worksheet_summary']);
                $preview_display_data = [];
                $file_headers = [];
                $excelWorksheetSummary = null;
                $previewTotalRows = 0;
            } else {
                logWasteImportBatchOutcome($conn, $activeImportBatchId, 'failed', 'confirm_failed');
            }
            logActivity('Imported waste records', 'Waste Data', 'Failed', $conn);
        }
    }
}
$notificationItems = getNotificationItems($conn, getCurrentUser());
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload File - EcoTrack</title>
    <?php include 'includes/theme_head.php'; ?>
    <style>
        /* CSS Variables for Light/Dark Mode */
        :root {
          --bg-primary: #f5f5f5;
          --bg-secondary: white;
          --bg-sidebar: linear-gradient(180deg, #8bc34a 0%, #689f38 100%);
          --text-primary: #333;
          --text-secondary: #666;
          --text-muted: #999;
          --border-color: #ddd;
          --card-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
          --toggle-bg: #ccc;
          --toggle-active: #8bc34a;
          --input-bg: white;
          --input-border: #ddd;
          --modal-overlay: rgba(0, 0, 0, 0.5);
        }

        [data-theme="dark"] {
          --bg-primary: #1a1a1a;
          --bg-secondary: #2d2d2d;
          --bg-sidebar: linear-gradient(180deg, #2e4a20 0%, #1e3a10 100%);
          --text-primary: #ffffff;
          --text-secondary: #cccccc;
          --text-muted: #888888;
          --border-color: #444;
          --card-shadow: 0 2px 10px rgba(0, 0, 0, 0.3);
          --toggle-bg: #555;
          --toggle-active: #7cb342;
          --input-bg: #3d3d3d;
          --input-border: #555;
          --modal-overlay: rgba(0, 0, 0, 0.7);
        }

        * {
          margin: 0;
          padding: 0;
          box-sizing: border-box;
        }
        body {
          font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
          background: var(--bg-primary);
          color: var(--text-primary);
          transition:
            background 0.3s,
            color 0.3s;
          display: flex;
          min-height: 100vh;
        }

        /* Sidebar - Matching Admin Dashboard */
        .sidebar {
          width: 280px;
          background: var(--bg-sidebar);
          color: white;
          display: flex;
          flex-direction: column;
          padding: 20px 0;
          position: fixed;
          height: 100vh;
        }

        .logo-section {
          padding: 20px;
          text-align: center;
          border-bottom: 1px solid rgba(255, 255, 255, 0.2);
          margin-bottom: 20px;
        }

        .logo {
          width: 60px;
          height: 60px;
          background: white;
          border-radius: 50%;
          display: flex;
          align-items: center;
          justify-content: center;
          margin: 0 auto 10px;
          font-size: 28px;
        }

        .logo-text {
          font-size: 13px;
          font-weight: bold;
          line-height: 1.3;
          text-transform: uppercase;
        }

        .nav-menu {
          flex: 1;
          padding: 0 15px;
        }

        .nav-item {
          display: flex;
          align-items: center;
          padding: 12px 20px;
          margin: 5px 0;
          border-radius: 25px;
          cursor: pointer;
          transition: all 0.3s;
          text-decoration: none;
          color: white;
        }

        .nav-item:hover,
        .nav-item.active {
          background: rgba(255, 255, 255, 0.2);
        }

        .nav-icon {
          width: 24px;
          height: 24px;
          margin-right: 15px;
        }

        .nav-text {
          font-size: 13px;
          font-weight: 500;
        }

        .bottom-menu {
          padding: 0 15px 20px;
        }

        /* Main Content */
        .main-content {
          flex: 1;
          margin-left: 280px;
          padding: 20px;
          --text-primary: #3f4a46;
          --text-secondary: #57645f;
          --text-muted: #6f7b76;
        }

        html[data-theme="dark"] .main-content {
          --text-primary: #d5ddd8;
          --text-secondary: #c1cbc5;
          --text-muted: #aab5af;
        }

        .header {
          display: flex;
          justify-content: space-between;
          align-items: center;
          margin-bottom: 30px;
        }

        .header h1 {
          color: var(--text-primary);
          font-size: 28px;
          font-weight: 600;
          letter-spacing: 1px;
        }

        .header-icons {
          display: flex;
          gap: 20px;
        }

        .header-icons a {
          color: var(--text-secondary);
          text-decoration: none;
          font-size: 20px;
        }

        .btn {
          padding: 12px 25px;
          border: none;
          border-radius: 25px;
          cursor: pointer;
          font-size: 13px;
          font-weight: 600;
          text-transform: uppercase;
          transition: all 0.3s;
          display: inline-flex;
          align-items: center;
          gap: 8px;
        }

        /* Upload Section */
        .upload-container {
          background: var(--bg-secondary);
          border-radius: 15px;
          padding: 40px;
          box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .upload-area {
          border: 3px dashed var(--eco-accent);
          border-radius: 15px;
          padding: 60px 40px;
          text-align: center;
          background: var(--eco-surface-soft);
          transition: all 0.3s;
        }

        .upload-area:hover {
          border-color: var(--eco-primary);
          background: var(--eco-primary-soft);
        }

        .upload-icon {
          font-size: 60px;
          color: var(--eco-primary);
          margin-bottom: 20px;
        }

        .upload-text {
          font-size: 18px;
          color: var(--text-secondary);
          margin-bottom: 10px;
        }

        .upload-hint {
          font-size: 14px;
          color: var(--text-muted);
          margin-bottom: 25px;
        }

        .file-input {
          display: none;
        }

        .btn-upload {
          background: #4caf50;
          color: white;
          padding: 15px 40px;
          font-size: 16px;
        }

        .btn-upload:hover {
          background: #45a049;
        }

        .btn-upload.is-submitting {
          cursor: wait;
          opacity: 0.72;
          pointer-events: none;
        }

        .btn-cancel {
          background: #6b7280;
          color: white;
        }

        .btn-cancel:hover {
          background: #4b5563;
        }

        .preview-actions {
          display: flex;
          align-items: center;
          justify-content: center;
          flex-wrap: wrap;
          gap: 10px;
          margin-top: 25px;
        }

        .add-files-panel {
          max-width: 760px;
          margin: 22px auto 0;
          padding: 16px 18px;
          border: 1px solid var(--border-color);
          border-radius: 12px;
          background: var(--eco-surface-soft);
        }

        .add-files-panel .preview-actions {
          margin-top: 0;
        }

        .btn-add-files {
          background: var(--eco-primary);
          color: #fff;
        }

        .btn-add-files:hover {
          background: var(--eco-primary-strong);
        }

        .add-files-copy {
          color: var(--text-secondary);
          font-size: 14px;
          margin-bottom: 10px;
        }

        .preview-file-name {
          color: var(--text-secondary);
          font-size: 13px;
          font-weight: 600;
          overflow-wrap: anywhere;
        }

        #selectedFile p,
        #selectedFile p span {
          color: var(--text-secondary) !important;
        }

        #selectedFile #fileName {
          color: var(--eco-primary) !important;
        }

        /* Messages */
        .message {
          padding: 15px;
          border-radius: 10px;
          margin-bottom: 20px;
        }

        .message.success {
          background: #e8f5e9;
          color: #2e7d32;
          border: 1px solid #c8e6c9;
        }

        .message.error {
          background: #ffebee;
          color: #c62828;
          border: 1px solid #ffcdd2;
        }

        .import-status-card {
          display: grid;
          grid-template-columns: repeat(3, minmax(0, 1fr));
          gap: 12px;
          padding: 18px;
          margin: 0 0 18px;
          border: 1px solid #b8ddc0;
          border-radius: 12px;
          background: linear-gradient(135deg, #f0fbf2, #e5f4ec);
        }
        .import-status-card strong {
          display: block;
          font-size: 22px;
          color: #146c2e;
        }
        .import-status-card span {
          color: var(--text-secondary);
          font-size: 13px;
          font-weight: 600;
        }
        .preview-section {
          border: 1px solid var(--border-color);
        }
        .preview-heading {
          display: flex;
          justify-content: space-between;
          align-items: start;
          gap: 18px;
          margin-bottom: 12px;
        }
        .preview-heading h2 {
          margin: 0;
          color: var(--text-primary);
        }
        .preview-table-wrap {
          overflow: auto;
          max-height: 460px;
          border: 1px solid var(--border-color);
          border-radius: 10px;
          background: var(--bg-secondary);
        }
        .preview-table-wrap thead {
          position: sticky;
          top: 0;
          z-index: 1;
        }
        .resolution-panel {
          margin: 22px 0;
          padding: 18px;
          border: 2px solid #e6a317;
          border-radius: 12px;
          background: #fff9e8;
          color: #563d00;
        }
        .resolution-panel h3 {
          margin: 0 0 6px;
        }
        .resolution-grid {
          display: grid;
          gap: 10px;
          margin-top: 14px;
        }
        .resolution-row {
          display: grid;
          grid-template-columns: minmax(120px, 1fr) minmax(140px, 1fr) 150px auto;
          gap: 10px;
          align-items: center;
          padding: 10px;
          background: #fff;
          border-radius: 8px;
        }
        .resolution-row input,
        .resolution-row select {
          min-width: 0;
          padding: 9px;
          border: 1px solid #b58a20;
          border-radius: 6px;
          color: #312300;
          background: #fff;
        }
        .resolution-row label {
          font-size: 13px;
          font-weight: 700;
          white-space: nowrap;
        }
        @media (max-width: 760px) {
          .import-status-card {
            grid-template-columns: 1fr;
          }
          .resolution-row {
            grid-template-columns: 1fr;
          }
          .preview-heading {
            flex-direction: column;
          }
        }
    </style>
    <link rel="stylesheet" href="assets/css/ecotrack-theme.css">
</head>
<body>
    <?php $active_page = $wasteImportRoute;
$useLogoutModal = false;
include 'includes/sidebar.php'; ?>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <div class="header">
            <h1>FILE OVERVIEW</h1>
            <div class="header-icons">
                <?php include 'includes/notification_bell.php'; ?>
            </div>
        </div>

        <?php if (!empty($message)): ?>
            <div class="message success" role="status" aria-live="polite"><?php echo htmlspecialchars($message); ?></div>
            <?php if ($imported > 0): ?>
            <script>
                localStorage.setItem("ecotrackWasteDataUpdated", String(Date.now()));
            </script>
            <?php endif; ?>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="message error" role="alert"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($importResolutionDraft !== null): ?>
        <section class="preview-section resolution-panel" style="background: var(--bg-secondary); padding: 30px; border-radius: 15px; box-shadow: var(--card-shadow); margin-bottom: 30px;">
            <h2 style="color: var(--text-primary);">Resolve missing import fields</h2>
            <p style="color: var(--text-secondary);">The workbook contains <?php echo (int)$importDraftRowCount; ?> valid Waste Data row(s) but does not supply a reliable Area and/or collection date. Complete only the fields missing from the workbook; supplied values are preserved.</p>
            <?php if ($importDraftIssueCount > 0): ?><p style="color: var(--text-muted);"><?php echo (int)$importDraftIssueCount; ?> invalid row(s) were skipped and their worksheet row/reason will be recorded in the import audit.</p><?php endif; ?>
            <form method="post" action="" class="resolution-grid" style="margin-top: 20px;">
                <input type="hidden" name="waste_import_csrf" value="<?php echo htmlspecialchars($wasteImportCsrf); ?>">
                <div><label for="resolution_area_default">Area for missing values</label><input id="resolution_area_default" type="text" name="resolution_area_default"></div>
                <div><label for="resolution_start_date">Start date for missing values</label><input id="resolution_start_date" type="date" name="resolution_start_date"></div>
                <div><label for="resolution_end_date">End date for missing values</label><input id="resolution_end_date" type="date" name="resolution_end_date"></div>
                <div class="preview-actions"><button type="submit" name="resolve_import_draft" class="btn btn-upload">Apply and review import</button><button type="submit" name="cancel_import" class="btn btn-cancel">Cancel</button></div>
            </form>
        </section>
        <?php endif; ?>

        <!-- File Preview Table -->
        <?php if (!empty($preview_display_data)): ?>
        <div class="preview-section" style="background: var(--bg-secondary); padding: 30px; border-radius: 15px; box-shadow: var(--card-shadow); margin-bottom: 30px;">
            <h2 style="margin-bottom: 20px; color: var(--text-primary);">📋 FILE PREVIEW</h2>
            <p style="color: var(--text-secondary); margin-bottom: 15px;">Review the Waste Data columns below before importing. Extra uploaded file columns are ignored.</p>
            <div class="import-status-card" aria-label="Import status">
                <div><strong><?php echo (int)$previewTotalRows; ?></strong><span>validated row(s)</span></div>
                <div><strong><?php echo count($preview_display_data); ?></strong><span>row(s) shown below</span></div>
                <div><strong><?php echo count($unresolvedImportAreas); ?></strong><span>area(s) requiring review</span></div>
            </div>
            <?php if ($excelWorksheetSummary !== null): ?>
                <p style="color: var(--text-secondary); margin-bottom: 15px;">
                    Scanned <?php echo (int)$excelWorksheetSummary['scanned']; ?> worksheet(s). Using Waste Data rows from <?php echo (int)$excelWorksheetSummary['with_waste_data']; ?> worksheet(s).
                </p>
                <?php if (!empty($excelWorksheetSummary['worksheets'])): ?>
                    <ul style="color: var(--text-secondary); margin: -5px 0 15px 20px;">
                        <?php foreach ($excelWorksheetSummary['worksheets'] as $worksheet): ?>
                            <li>
                                <strong><?php echo htmlspecialchars((string)($worksheet['name'] ?? 'Worksheet')); ?></strong>
                                <?php if (($worksheet['status'] ?? '') === 'imported'): ?>
                                    — importing <?php echo (int)($worksheet['rows'] ?? 0); ?> row(s).
                                <?php else: ?>
                                    — skipped: <?php echo htmlspecialchars((string)($worksheet['reason'] ?? 'No recognizable Waste Data headers were found.')); ?>
                                    <?php $unmatchedHeaders = array_slice((array)($worksheet['unmatched_headers'] ?? []), 0, 5); ?>
                                    <?php if (!empty($unmatchedHeaders)): ?>
                                        Unmatched headings: <?php echo htmlspecialchars(implode(', ', $unmatchedHeaders)); ?>.
                                    <?php endif; ?>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            <?php endif; ?>
            <div class="preview-table-wrap">
                <table class="data-table" style="width: 100%; border-collapse: collapse; margin-top: 20px;">
                    <thead>
                        <tr style="background: #8bc34a; color: white;">
                            <?php foreach ($file_headers as $header): ?>
                                <th style="padding: 12px; text-align: left; font-weight: 600;"><?php echo htmlspecialchars($header); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($preview_display_data as $row): ?>
                            <tr style="border-bottom: 1px solid var(--border-color);">
                                <?php foreach ($row as $cell): ?>
                                    <td style="padding: 10px 12px; color: var(--text-primary);"><?php echo htmlspecialchars($cell); ?></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <p style="color: var(--text-muted); margin-top: 15px; font-size: 14px;">
                Showing the first <?php echo count($preview_display_data); ?> of <?php echo (int)$previewTotalRows; ?> validated rows using only the Waste Data columns
            </p>

            <?php if (!empty($unresolvedImportAreas)): ?>
            <form method="post" action="" class="resolution-panel">
                <input type="hidden" name="waste_import_csrf" value="<?php echo htmlspecialchars($wasteImportCsrf); ?>">
                <h3>Action required: assign unmatched areas</h3>
                <p>These rows came from a generic 3-Day Block heading. Choose their detailed Collection Group and classification before importing. Saving a rule lets future files resolve the same area automatically.</p>
                <div class="resolution-grid">
                    <?php foreach ($unresolvedImportAreas as $index => $unresolvedArea): ?>
                    <div class="resolution-row">
                        <div><strong><?php echo htmlspecialchars($unresolvedArea['street']); ?></strong><br><small><?php echo (int)$unresolvedArea['row_count']; ?> row(s)</small></div>
                        <input type="hidden" name="resolution_area[<?php echo $index; ?>]" value="<?php echo htmlspecialchars($unresolvedArea['street']); ?>">
                        <input type="text" name="resolution_group[<?php echo $index; ?>]" required>
                        <select name="resolution_type[<?php echo $index; ?>]" required><option value="phase">Phase</option><option value="establishment">Establishment</option></select>
                        <label><input type="checkbox" name="resolution_save[<?php echo $index; ?>]" value="1" checked> Save rule</label>
                    </div>
                    <?php endforeach; ?>
                </div>
                <button type="submit" name="resolve_groups" class="btn btn-upload" style="margin-top: 14px;">Resolve areas and update preview</button>
            </form>
            <?php endif; ?>

            <!-- The loaded rows remain available until the user imports or discards them. -->
            <form method="post" action="" enctype="multipart/form-data" id="confirmImportForm" style="margin-top: 25px; text-align: center;" data-confirm-title="Import Waste Data?" data-confirm-message="Are you sure you want to import the <?php echo (int)$previewTotalRows; ?> validated Waste Data record(s) in this batch?" data-confirm-action="Continue to verification">
                <input type="hidden" name="waste_import_csrf" value="<?php echo htmlspecialchars($wasteImportCsrf); ?>">
                <button type="submit" name="confirm_import" id="confirmImportButton" class="btn btn-upload" <?php echo !empty($unresolvedImportAreas) ? 'disabled aria-disabled="true" title="Resolve unmatched areas first"' : ''; ?> style="background: #2196f3; font-size: 16px; padding: 15px 40px;">
                    📤 UPLOAD ALL FILES
                </button>
                <button type="submit" name="cancel_import" class="btn btn-cancel" style="font-size: 16px; padding: 15px 32px; margin-left: 10px;">
                    Cancel
                </button>
                <p style="color: var(--text-muted); margin-top: 10px; font-size: 13px;">
                    Add more files if needed, then upload all previewed and added files together.
                </p>
            </form>
            <form method="post" action="" enctype="multipart/form-data" id="addFilesForm" class="add-files-panel">
                <input type="hidden" name="waste_import_csrf" value="<?php echo htmlspecialchars($wasteImportCsrf); ?>">
                <input type="hidden" name="add_files" value="1">
                <div class="add-files-copy">
                    Have more files? Add one or select multiple files; they will join this preview before anything is uploaded.
                </div>
                <div class="preview-actions">
                    <input type="file" name="additional_files[]" id="additional_files" class="file-input" multiple accept=".csv,.xlsx,.xlsm,.xls,.pdf,text/csv,application/pdf,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel.sheet.macroEnabled.12" onchange="queueAdditionalFiles(this)">
                    <label for="additional_files" class="btn btn-add-files" id="addFilesButton">
                        + Add file(s)
                    </label>
                    <span id="additionalFilesSelected" class="preview-file-name" hidden></span>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <!-- Upload Area -->
        <?php if (empty($preview_display_data) && $importResolutionDraft === null): ?>
        <div class="upload-container">
            <form method="post" action="" enctype="multipart/form-data" id="uploadForm">
                <input type="hidden" name="waste_import_csrf" value="<?php echo htmlspecialchars($wasteImportCsrf); ?>">
                <div class="upload-area" onclick="document.getElementById('csv_file').click()">
                    <div class="upload-icon">📁</div>
                    <div class="upload-text">Click to upload file</div>
                    <div class="upload-hint">Supported formats: CSV, Excel .xlsx/.xlsm, or text-based PDF. Every worksheet in an Excel workbook is scanned; only rows with recognized Waste Data columns are imported. Extra columns, unrelated worksheets, Excel macros, and TOTAL/summary rows are ignored. The MRF 3-Day Block layout is supported, including Biosan, Street, No. of Houses, Tuesday–Thursday, and Friday–Saturday tables. PDF imports support EcoTrack table PDFs; scanned PDFs should be converted to CSV or Excel. Include a year in the filename or sheet date for historical records; a month/day-only filename uses the current year.</div>
                    <button type="button" class="btn btn-upload" onclick="event.stopPropagation(); document.getElementById('csv_file').click();">Choose File</button>
                    <input type="file" name="csv_file" id="csv_file" class="file-input" accept=".csv,.xlsx,.xlsm,.xls,.pdf,text/csv,application/pdf,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel.sheet.macroEnabled.12" onchange="showFileName(this, 'selectedFile', 'fileName')">
                </div>

                <div id="selectedFile" style="margin-top: 20px; text-align: center; display: none;">
                    <p style="color: #666; margin-bottom: 15px;">Selected file: <span id="fileName" style="font-weight: 600; color: #4caf50;"></span></p>
                    <p style="color: #999; margin-bottom: 15px; font-size: 13px;">Click below to validate the file and preview its first 100 rows</p>
                    <button type="submit" class="btn btn-upload">LOAD FILE DATA</button>
                </div>
            </form>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($importOtpRequired): ?>
        <?php
        $otpModalId = 'importOtpModal';
        $otpModalTitle = 'Verify your import';
        $otpModalMessage = 'Enter the six-digit verification code sent to your registered email address before importing this batch.';
        $otpModalError = $submittedImportOtp !== '' ? $error : '';
        $otpModalAction = $wasteImportRoute;
        $otpModalField = 'import_otp';
        $otpModalSubmitLabel = 'Verify and import';
        $otpModalCancelHref = $wasteImportRoute;
        $otpModalCancelLabel = 'Cancel import';
        $otpModalHiddenInputs = [
            'waste_import_csrf' => $wasteImportCsrf,
            'confirm_import' => '1',
        ];
        include 'includes/otp_modal.php';
        ?>
    <?php endif; ?>

    <script>
        function showFileName(input, selectedFileId, fileNameId, submitButtonId) {
          if (input.files && input.files[0]) {
            const fileName = input.files[0].name;
            const fileNameElement = document.getElementById(fileNameId);
            const selectedFileElement = document.getElementById(selectedFileId);
            const submitButton = submitButtonId ? document.getElementById(submitButtonId) : null;

            fileNameElement.textContent = fileName;
            if (selectedFileElement.hasAttribute("hidden")) {
              selectedFileElement.hidden = false;
            } else {
              selectedFileElement.style.display = "block";
            }
            if (submitButton) {
              submitButton.disabled = false;
            }
          }
        }

        function queueAdditionalFiles(input) {
          if (!input.files || !input.files[0]) {
            return;
          }

          const names = Array.from(input.files, (file) => file.name).join(", ");
          const selectedFiles = document.getElementById("additionalFilesSelected");
          selectedFiles.textContent = "Adding " + input.files.length + " file(s): " + names;
          selectedFiles.hidden = false;
          document.getElementById("addFilesButton").classList.add("is-submitting");
          document.getElementById("addFilesButton").textContent = "ADDING FILE(S)...";
          input.form.requestSubmit();
        }

    </script>
</body>
</html>

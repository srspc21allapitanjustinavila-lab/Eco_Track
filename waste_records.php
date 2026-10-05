<?php
require_once 'config.php';
require_once 'includes/waste_analytics.php';

requireLogin();

// Get current user info
$user_type = $_SESSION['user']['user_type'] ?? 'staff';
$wasteRecordsRoute = $user_type === 'staff' ? 'staff_waste_records.php' : 'admin_waste_records.php';
if (empty($canonicalRouteEntry) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $routeQuery = http_build_query($_GET);
    header('Location: ' . $wasteRecordsRoute . ($routeQuery === '' ? '' : '?' . $routeQuery));
    exit();
}
$user = getCurrentUser();
$username = $_SESSION['user']['username'] ?? 'User';

$conn = getDBConnection();
$error = '';
$success = '';
$exportDownloadSuccessCookie = 'ecotrack_waste_export_downloaded';
ensureWasteFormatColumns($conn);
ensureWasteRecordsArchiveTable($conn);
ensureWasteAnalyticsColumns($conn);
ensureWasteDataVersionTable($conn);
ensureWasteImportTables($conn);
$availableEntryCollectionGroups = getActiveWasteCollectionGroups($conn);

function wasteRecordFormattedValues($record)
{
    $households = cleanWasteHouseholdsValue(
        $record['households'] ?? '',
        buildWasteCollectionSourceLabel(wasteRecordCollectionGroup($record), wasteRecordDatePeriod($record)),
        wasteRecordCollectionGroup($record)
    );

    return [
        $record['name_of_bioman'] ?: ($record['garbage_collector'] ?? ''),
        $record['street'] ?? '',
        $households,
        ($record['tuesday_factory_returnable_kg'] ?? 0) ?: ($record['recyclable_kg'] ?? ''),
        $record['comply_tue'] ?? '',
        $record['cd_processing'] ?? '',
        ($record['wednesday_biowaste_kg'] ?? 0) ?: ($record['kilogram_of_waste'] ?? ''),
        $record['comply_wed'] ?? '',
        $record['thursday_factory_returnable_kg'] ?? '',
        $record['friday_biowaste_kg'] ?? '',
        $record['comply_fri'] ?? '',
        ($record['saturday_hazard_waste_kg'] ?? 0) ?: ($record['hazardous_kg'] ?? ''),
        ($record['residual_waste_kg'] ?? 0) ?: ($record['residual_kg'] ?? ''),
        $record['unclassified_waste_kg'] ?? '',
    ];
}

function wasteRecordCollectionGroup($record)
{
    $collectionGroup = trim((string)($record['collection_group'] ?? ''));
    if ($collectionGroup !== '') {
        return $collectionGroup;
    }

    foreach (['phase_number', 'phase_date_label', 'date'] as $column) {
        [$group] = splitWasteCollectionGroupAndPeriod($record[$column] ?? '');
        if ($group !== '') {
            return $group;
        }
    }

    return 'Unassigned';
}

function wasteRecordDatePeriod($record)
{
    $reportingPeriod = trim((string)($record['reporting_period'] ?? ''));
    if ($reportingPeriod !== '') {
        return $reportingPeriod;
    }

    foreach (['phase_date_label', 'date', 'phase_number'] as $column) {
        [, $period] = splitWasteCollectionGroupAndPeriod($record[$column] ?? '');
        if ($period !== '') {
            return $period;
        }
    }

    $collectionDate = normalizeWasteDateValue($record['collection_date'] ?? '');
    if ($collectionDate !== null) {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $collectionDate);
        return $date ? $date->format('M j, Y') : $collectionDate;
    }

    return '';
}

function wasteRecordDisplayValues($record)
{
    return array_merge([
        wasteRecordCollectionGroup($record),
        wasteRecordDatePeriod($record),
    ], wasteRecordFormattedValues($record));
}

function buildWasteTableHeader($includeActions = false)
{
    $headers = array_merge(['Collection Group', 'Date / Period'], getWasteFormatHeaders());
    $html = '<tr>';
    foreach ($headers as $header) {
        $html .= '<th>' . htmlspecialchars($header) . '</th>';
    }
    if ($includeActions) {
        $html .= '<th>Actions</th>';
    }
    $html .= '</tr>';
    return $html;
}

function buildWasteRecordsExcel($records, $filterDescription)
{
    $headers = array_merge(['Collection Group', 'Date / Period'], getWasteFormatHeaders());
    $html = '<html><head><meta charset="UTF-8"></head><body>';
    $html .= '<h2>Waste Data Entries Export</h2>';
    $html .= '<p>Generated: ' . htmlspecialchars(date('Y-m-d H:i:s')) . '</p>';
    $html .= '<p>Filters: ' . htmlspecialchars($filterDescription !== '' ? $filterDescription : 'None') . '</p>';
    $html .= '<table border="1"><thead>';
    $html .= '<tr>';
    foreach ($headers as $header) {
        $html .= '<th>' . htmlspecialchars($header) . '</th>';
    }

    $html .= '</tr></thead><tbody>';
    if (empty($records)) {
        $html .= '<tr><td colspan="' . count($headers) . '">No waste records found.</td></tr>';
    } else {
        foreach ($records as $record) {
            $html .= '<tr>';
            foreach (wasteRecordDisplayValues($record) as $value) {
                $html .= '<td>' . htmlspecialchars($value) . '</td>';
            }
            $html .= '</tr>';
        }
    }

    $html .= '</tbody></table></body></html>';
    return $html;
}

function wasteRecordInputValues()
{
    return [
        trim($_POST['name_of_bioman'] ?? ''), trim($_POST['street'] ?? ''), trim($_POST['households'] ?? ''),
        trim($_POST['tuesday_factory_returnable_kg'] ?? ''), trim($_POST['comply_tue'] ?? ''), trim($_POST['cd_processing'] ?? ''),
        trim($_POST['wednesday_biowaste_kg'] ?? ''), trim($_POST['comply_wed'] ?? ''), trim($_POST['thursday_factory_returnable_kg'] ?? ''),
        trim($_POST['friday_biowaste_kg'] ?? ''), trim($_POST['comply_fri'] ?? ''), trim($_POST['saturday_hazard_waste_kg'] ?? ''), trim($_POST['residual_waste_kg'] ?? ''), trim($_POST['unclassified_waste_kg'] ?? ''),
    ];
}

function wasteRecordDatabaseValues($collectionGroup, $reportingPeriod)
{
    $values = wasteRecordInputValues();
    $legacySourceLabel = buildWasteCollectionSourceLabel($collectionGroup, $reportingPeriod);
    if (isWasteTotalLabel($collectionGroup) || isWasteTotalLabel($reportingPeriod) || isWasteSummaryRow($values)) {
        return [$values, 0.0, 'TOTAL/summary rows cannot be saved as Waste Data records.'];
    }
    $numbers = [3, 6, 8, 9, 11, 12, 13];
    foreach ($numbers as $index) {
        if ($values[$index] === '') {
            $values[$index] = 0.0;
            continue;
        }
        if (!is_numeric($values[$index]) || (float)$values[$index] < 0) {
            return [$values, 0.0, 'Waste amounts must be valid non-negative numbers.'];
        }
        $values[$index] = (float)$values[$index];
    }
    $values[2] = cleanWasteHouseholdsValue($values[2], $legacySourceLabel, $collectionGroup);
    $total = $values[3] + $values[6] + $values[8] + $values[9] + $values[11] + $values[12] + $values[13];
    return [$values, $total, ''];
}

// Handle ADD waste record
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_record'])) {
    if ($user_type !== 'admin') {
        header('Location: access_denied.php');
        exit();
    }
    $collectionGroup = trim($_POST['collection_group'] ?? '');
    $collectionGroupType = inferWasteCollectionGroupType($collectionGroup) ?: null;
    $reportingPeriod = trim($_POST['reporting_period'] ?? '');
    $legacySourceLabel = buildWasteCollectionSourceLabel($collectionGroup, $reportingPeriod);
    $collectionDate = normalizeWasteDateValue($_POST['collection_date'] ?? '');
    $displayDate = substr($reportingPeriod !== '' ? $reportingPeriod : (string)$collectionDate, 0, 50);
    [$values, $total, $numericError] = wasteRecordDatabaseValues($collectionGroup, $reportingPeriod);
    $formatValues = array_merge([$values[0]], array_slice($values, 2));

    if (!in_array($collectionGroup, $availableEntryCollectionGroups, true)) {
        $error = 'Select a Collection Group that exists in the saved or imported Waste Data.';
    } elseif ($numericError !== '') {
        $error = $numericError;
    } elseif ($collectionGroup === '' || $values[1] === '' || $collectionDate === null) {
        $error = "Please fill in Collection Group, Collection Date, and Area.";
    } else {
        try {
            $stmt = $conn->prepare("INSERT INTO waste_records (date, collection_date, phase_number, street, kilogram_of_waste, recyclable_kg, residual_kg, hazardous_kg, garbage_collector, phase_date_label, collection_group, collection_group_type, reporting_period, name_of_bioman, households, tuesday_factory_returnable_kg, comply_tue, cd_processing, wednesday_biowaste_kg, comply_wed, thursday_factory_returnable_kg, friday_biowaste_kg, comply_fri, saturday_hazard_waste_kg, residual_waste_kg, unclassified_waste_kg) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$displayDate, $collectionDate, substr($collectionGroup, 0, 50), $values[1], $total, $values[3] + $values[8], $values[12], $values[11], $values[0], $legacySourceLabel, $collectionGroup, $collectionGroupType, $reportingPeriod, ...$formatValues]);
            $recordId = (int)$conn->lastInsertId();
            syncWasteRecordFingerprint($conn, $recordId);
            rebuildWasteDailyAllocationsForRecord($conn, $recordId);
            touchWasteDataVersion($conn);
            $success = "Waste record added successfully!";
            logActivity('Added waste record', 'Waste Data', 'Success', $conn);
        } catch (PDOException $e) {
            $error = "Failed to add record. " . $e->getMessage();
            logActivity('Added waste record', 'Waste Data', 'Failed', $conn);
        }
    }
}

// Handle UPDATE waste record
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_record'])) {
    if ($user_type !== 'admin') {
        header('Location: access_denied.php');
        exit();
    }
    $record_id = intval($_POST['record_id'] ?? 0);
    $collectionGroup = trim($_POST['collection_group'] ?? '');
    $collectionGroupType = inferWasteCollectionGroupType($collectionGroup) ?: null;
    $reportingPeriod = trim($_POST['reporting_period'] ?? '');
    $legacySourceLabel = buildWasteCollectionSourceLabel($collectionGroup, $reportingPeriod);
    $collectionDate = normalizeWasteDateValue($_POST['collection_date'] ?? '');
    $displayDate = substr($reportingPeriod !== '' ? $reportingPeriod : (string)$collectionDate, 0, 50);
    [$values, $total, $numericError] = wasteRecordDatabaseValues($collectionGroup, $reportingPeriod);
    $formatValues = array_merge([$values[0]], array_slice($values, 2));

    if (!in_array($collectionGroup, $availableEntryCollectionGroups, true)) {
        $error = 'Select a Collection Group that exists in the saved or imported Waste Data.';
    } elseif ($numericError !== '') {
        $error = $numericError;
    } elseif ($collectionGroup === '' || $values[1] === '' || $collectionDate === null) {
        $error = "Please fill in Collection Group, Collection Date, and Area.";
    } else {
        try {
            $oldFingerprintStmt = $conn->prepare('SELECT record_fingerprint FROM waste_records WHERE id = ? AND is_active = 1');
            $oldFingerprintStmt->execute([$record_id]);
            $previousFingerprint = (string)$oldFingerprintStmt->fetchColumn();
            $stmt = $conn->prepare("UPDATE waste_records SET date = ?, collection_date = ?, phase_number = ?, street = ?, kilogram_of_waste = ?, recyclable_kg = ?, residual_kg = ?, hazardous_kg = ?, garbage_collector = ?, phase_date_label = ?, collection_group = ?, collection_group_type = ?, reporting_period = ?, name_of_bioman = ?, households = ?, tuesday_factory_returnable_kg = ?, comply_tue = ?, cd_processing = ?, wednesday_biowaste_kg = ?, comply_wed = ?, thursday_factory_returnable_kg = ?, friday_biowaste_kg = ?, comply_fri = ?, saturday_hazard_waste_kg = ?, residual_waste_kg = ?, unclassified_waste_kg = ? WHERE id = ? AND is_active = 1");
            $stmt->execute([$displayDate, $collectionDate, substr($collectionGroup, 0, 50), $values[1], $total, $values[3] + $values[8], $values[12], $values[11], $values[0], $legacySourceLabel, $collectionGroup, $collectionGroupType, $reportingPeriod, ...$formatValues, $record_id]);
            syncWasteRecordFingerprint($conn, $record_id, $previousFingerprint);
            rebuildWasteDailyAllocationsForRecord($conn, $record_id);
            touchWasteDataVersion($conn);
            $success = "Waste record updated successfully!";
            logActivity('Edited waste record', 'Waste Data', 'Success', $conn);
        } catch (PDOException $e) {
            $error = "Failed to update record. " . $e->getMessage();
            logActivity('Edited waste record', 'Waste Data', 'Failed', $conn);
        }
    }
}

// Handle DELETE waste record
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_record'])) {
    if ($user_type !== 'admin') {
        header('Location: access_denied.php');
        exit();
    }
    $record_id = intval($_POST['record_id'] ?? 0);
    try {
        $removedRecord = archiveWasteRecord($conn, $record_id, (int)$user['id']);
        if (!$removedRecord) {
            throw new RuntimeException('This waste record is unavailable or could not be removed.');
        }
        touchWasteDataVersion($conn);
        $success = 'Waste record archived successfully.';
        logActivity('Archived waste record', 'Waste Data', 'Success', $conn);
    } catch (Throwable $e) {
        $error = "Failed to delete record. " . $e->getMessage();
        logActivity('Deleted waste record', 'Waste Data', 'Failed', $conn);
    }
}

// Combine non-empty Waste Data filters; exports keep their POST-only filters.
$filterInput = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['export'])) ? $_POST : $_GET;
$wasteTypeFilter = trim($filterInput['waste_type'] ?? ($filterInput['search'] ?? ''));
$collectionGroupFilter = trim($filterInput['collection_group'] ?? ($filterInput['phase'] ?? ''));
$yearFilter = trim($filterInput['year'] ?? '');
$monthFilter = trim($filterInput['month'] ?? '');

// Match entry-form values while retaining legacy total fallbacks.
$detailedTotalExpr = "COALESCE(tuesday_factory_returnable_kg, 0) + COALESCE(wednesday_biowaste_kg, 0) + COALESCE(thursday_factory_returnable_kg, 0) + COALESCE(friday_biowaste_kg, 0) + COALESCE(saturday_hazard_waste_kg, 0) + COALESCE(residual_waste_kg, 0) + COALESCE(unclassified_waste_kg, 0)";
$factoryReturnableExpr = "CASE WHEN (COALESCE(tuesday_factory_returnable_kg, 0) + COALESCE(thursday_factory_returnable_kg, 0)) > 0 THEN (COALESCE(tuesday_factory_returnable_kg, 0) + COALESCE(thursday_factory_returnable_kg, 0)) ELSE COALESCE(recyclable_kg, 0) END";
$wasteTypeExprs = [
    'factory_returnable' => $factoryReturnableExpr,
    'biowaste' => "COALESCE(wednesday_biowaste_kg, 0) + COALESCE(friday_biowaste_kg, 0)",
    'residual' => "CASE WHEN COALESCE(residual_waste_kg, 0) > 0 THEN COALESCE(residual_waste_kg, 0) ELSE COALESCE(residual_kg, 0) END",
    'hazardous' => "CASE WHEN COALESCE(saturday_hazard_waste_kg, 0) > 0 THEN COALESCE(saturday_hazard_waste_kg, 0) ELSE COALESCE(hazardous_kg, 0) END",
    'unclassified' => "COALESCE(unclassified_waste_kg, 0)",
];
$allWasteExpr = "CASE WHEN ($detailedTotalExpr) > 0 THEN ($detailedTotalExpr) ELSE COALESCE(kilogram_of_waste, 0) END";
$searchWasteTypes = [
    'factory returnable' => ['label' => 'Factory Returnable', 'expression' => $wasteTypeExprs['factory_returnable']],
    'returnable' => ['label' => 'Factory Returnable', 'expression' => $wasteTypeExprs['factory_returnable']],
    'recyclable' => ['label' => 'Factory Returnable', 'expression' => $wasteTypeExprs['factory_returnable']],
    'recyclable waste' => ['label' => 'Factory Returnable', 'expression' => $wasteTypeExprs['factory_returnable']],
    'biowaste' => ['label' => 'Biowaste', 'expression' => $wasteTypeExprs['biowaste']],
    'bio waste' => ['label' => 'Biowaste', 'expression' => $wasteTypeExprs['biowaste']],
    'residual' => ['label' => 'Residual Waste', 'expression' => $wasteTypeExprs['residual']],
    'residual waste' => ['label' => 'Residual Waste', 'expression' => $wasteTypeExprs['residual']],
    'hazardous' => ['label' => 'Hazardous Waste', 'expression' => $wasteTypeExprs['hazardous']],
    'hazardous waste' => ['label' => 'Hazardous Waste', 'expression' => $wasteTypeExprs['hazardous']],
    'unclassified' => ['label' => 'Unclassified Waste', 'expression' => $wasteTypeExprs['unclassified']],
    'total waste' => ['label' => 'Unclassified Waste', 'expression' => $wasteTypeExprs['unclassified']],
];
$wasteTypeOptions = [];
try {
    $presenceStmt = $conn->query('SELECT tuesday_factory_returnable_kg, thursday_factory_returnable_kg, recyclable_kg, wednesday_biowaste_kg, friday_biowaste_kg, residual_waste_kg, residual_kg, saturday_hazard_waste_kg, hazardous_kg, unclassified_waste_kg FROM waste_records WHERE is_active = 1');
    $wasteTypeOptions = buildAvailableWasteTypeOptions($presenceStmt->fetchAll());
} catch (PDOException $e) {
    error_log('Failed to load Waste Type filter options: ' . $e->getMessage());
}
$collectionGroupOptions = $availableEntryCollectionGroups;
$wastePeriodOptions = getActiveWasteCollectionPeriods($conn);
$wasteYearOptions = $wastePeriodOptions['years'];
$wasteMonthsByYearJson = json_encode($wastePeriodOptions['months_by_year'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$invalidWasteFilter = false;
$invalidWasteFilterMessage = '';
$normalizedWasteType = strtolower(preg_replace('/\s+/', ' ', $wasteTypeFilter));
$normalizedWasteType = trim($normalizedWasteType);
if ($wasteTypeFilter !== '' && !isset($wasteTypeOptions[$normalizedWasteType])) {
    $invalidWasteFilter = true;
    $invalidWasteFilterMessage = 'The selected Waste Type is no longer available.';
    $wasteTypeFilter = '';
    $normalizedWasteType = '';
}
if ($collectionGroupFilter !== '' && !in_array($collectionGroupFilter, $collectionGroupOptions, true)) {
    $invalidWasteFilter = true;
    $invalidWasteFilterMessage = 'The selected Collection Group is no longer available.';
    $collectionGroupFilter = '';
}
if ($monthFilter !== '') {
    $monthMatches = preg_match('/^(19|20)\d{2}-(0[1-9]|1[0-2])$/', $monthFilter);
    $monthYear = $monthMatches ? substr($monthFilter, 0, 4) : '';
    if (!$monthMatches || !in_array($monthYear, $wasteYearOptions, true)) {
        $invalidWasteFilter = true;
        $invalidWasteFilterMessage = 'The selected reporting month is no longer available.';
        $monthFilter = '';
    } else {
        // The Month picker includes its own year, so it is the authoritative
        // reporting period whenever a calendar month is selected.
        $yearFilter = $monthYear;
    }
}
if ($yearFilter !== '' && !in_array($yearFilter, $wasteYearOptions, true)) {
    $invalidWasteFilter = true;
    $invalidWasteFilterMessage = 'The selected reporting year is no longer available.';
    $yearFilter = '';
}
if ($invalidWasteFilter) {
    $error = $invalidWasteFilterMessage;
}
$selectedWasteExpr = $allWasteExpr;
$selectedWasteLabel = 'All Waste Categories';

$where = ['is_active = 1'];
$params = [];
if ($wasteTypeFilter !== '') {
    if (isset($searchWasteTypes[$normalizedWasteType])) {
        $selectedWasteExpr = $searchWasteTypes[$normalizedWasteType]['expression'];
        $selectedWasteLabel = $searchWasteTypes[$normalizedWasteType]['label'];
        $where[] = '(' . $selectedWasteExpr . ') > 0';
    } else {
        // Unknown Waste Type values must not expose unrelated records.
        $where[] = '0 = 1';
    }
}
if ($collectionGroupFilter !== '') {
    $collectionGroupExpr = "TRIM(COALESCE(NULLIF(TRIM(collection_group), ''), NULLIF(TRIM(phase_number), ''), NULLIF(TRIM(phase_date_label), '')))";
    $legacyGroupSource = "TRIM(CONCAT_WS(' ', NULLIF(TRIM(phase_number), ''), NULLIF(TRIM(phase_date_label), ''), NULLIF(TRIM(date), '')))";
    $where[] = "($collectionGroupExpr = ? OR (NULLIF(TRIM(collection_group), '') IS NULL AND LOWER($legacyGroupSource) LIKE LOWER(CONCAT(?, '%'))))";
    array_push($params, $collectionGroupFilter, $collectionGroupFilter);
}
// A selected calendar month already includes its calendar year. Keep the
// legacy reporting-year matching only for year-only searches.
if ($yearFilter !== '' && $monthFilter === '') {
    if (preg_match('/^\d{4}$/', $yearFilter)) {
        // Prefer source-file years over stored fallback dates.
        $sourceLabelExpr = "CONCAT_WS(' ', NULLIF(TRIM(reporting_period), ''), NULLIF(TRIM(phase_date_label), ''), NULLIF(TRIM(date), ''), NULLIF(TRIM(phase_number), ''))";
        $yearPattern = '(^|[^0-9])' . $yearFilter . '([^0-9]|$)';
        $anyYearPattern = '(^|[^0-9])(19|20)[0-9]{2}([^0-9]|$)';
        $where[] = "(($sourceLabelExpr REGEXP ?) OR (NOT ($sourceLabelExpr REGEXP ?) AND YEAR(collection_date) = ?))";
        array_push($params, $yearPattern, $anyYearPattern, (int)$yearFilter);
    } else {
        // Invalid year text returns no records instead of removing the filter.
        $where[] = '0 = 1';
    }
}
if ($monthFilter !== '') {
    $monthStart = $monthFilter . '-01';
    $monthEnd = (new DateTimeImmutable($monthStart))->modify('+1 month')->format('Y-m-d');
    $where[] = 'collection_date >= ? AND collection_date < ?';
    array_push($params, $monthStart, $monthEnd);
}
$where_clause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$recordTotalExpr = '(' . $selectedWasteExpr . ')';
$hasActiveFilters = $wasteTypeFilter !== '' || $collectionGroupFilter !== '' || $yearFilter !== '' || $monthFilter !== '';
$filterSummary = [];
if ($wasteTypeFilter !== '') {
    $filterSummary[] = 'Waste Type: ' . $wasteTypeFilter;
}
if ($collectionGroupFilter !== '') {
    $filterSummary[] = 'Collection Group: ' . $collectionGroupFilter;
}
if ($yearFilter !== '' && ($monthFilter === '' || $yearFilter !== substr($monthFilter, 0, 4))) {
    $filterSummary[] = 'Reporting Year: ' . $yearFilter;
}
if ($monthFilter !== '') {
    $selectedMonth = DateTimeImmutable::createFromFormat('!Y-m', $monthFilter);
    $filterSummary[] = 'Reporting Month: ' . ($selectedMonth ? $selectedMonth->format('F Y') : $monthFilter);
}
if (empty($filterSummary)) {
    $filterSummary = ['All Waste Data'];
}

// Default results are oldest-first; filtered and exported results are newest-first.
$recordOrder = $hasActiveFilters
    ? 'ORDER BY collection_date DESC, reporting_period DESC, collection_group, phase_number'
    : 'ORDER BY id ASC';

// Handle Excel downloads using the same records currently visible after search.
// Keep export OTPs out of URLs by accepting POST requests only.
$export_format = strtolower(trim($_POST['export'] ?? ''));
$exportOtpRequired = false;
if ($export_format !== '' && $export_format !== 'excel') {
    $error = 'Only Excel downloads are available.';
    logActivity('Requested unsupported waste data export', 'Waste Data', 'Failed', $conn);
} elseif ($export_format === 'excel' && $invalidWasteFilter) {
    $error = $invalidWasteFilterMessage;
    logActivity('Exported waste data (EXCEL)', 'Waste Data', 'Failed', $conn);
} elseif ($export_format === 'excel') {
    $exportSettings = getUserSettings($conn, (int)($user['id'] ?? 0));
    $submittedExportOtp = trim($_POST['export_otp'] ?? '');

    if (empty($exportSettings['data_export_enabled'])) {
        $error = 'Data export is disabled in your Data Privacy settings.';
        logActivity('Exported waste data (' . strtoupper($export_format) . ')', 'Waste Data', 'Failed', $conn);
    } elseif (verifyAndConsumeDataExportOtp($conn, $user, $submittedExportOtp)) {
        try {
            $sql = "SELECT *, $recordTotalExpr AS search_waste_total FROM waste_records $where_clause $recordOrder";
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $export_records = $stmt->fetchAll();
            $filename = 'waste-data-' . date('Y-m-d-His');
            $excel = buildWasteRecordsExcel($export_records, implode(' | ', $filterSummary));
            logActivity('Exported waste data (EXCEL)', 'Waste Data', 'Success', $conn);
            if (ob_get_length()) {
                ob_clean();
            }
            setcookie($exportDownloadSuccessCookie, '1', [
                'expires' => time() + 60,
                'path' => '/',
                'secure' => isHttpsRequest(),
                'httponly' => false,
                'samesite' => 'Lax',
            ]);
            header('Content-Type: application/vnd.ms-excel; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
            header('Content-Length: ' . strlen($excel));
            echo $excel;
            exit();
        } catch (Throwable $e) {
            $error = "Failed to export waste records.";
            logActivity('Exported waste data (' . strtoupper($export_format) . ')', 'Waste Data', 'Failed', $conn);
        }
    } else {
        $wasAlreadyPending = false;
        $otpReady = ensurePendingDataExportOtp($conn, $user, $wasAlreadyPending);
        $exportOtpRequired = $otpReady;
        if (!$otpReady) {
            $error = 'A verification code could not be sent to your registered email address. Check the email in your account and try again.';
            logActivity('Requested waste data export verification', 'Waste Data', 'Failed', $conn);
        } elseif ($submittedExportOtp !== '') {
            $error = $wasAlreadyPending
                ? 'The verification code is invalid. Enter the current six-digit code sent to your email.'
                : 'The verification code expired. A new six-digit code was sent to your registered email address.';
            logActivity('Verified waste data export code', 'Waste Data', 'Failed', $conn);
        } else {
            $error = $wasAlreadyPending
                ? 'Enter the six-digit verification code already sent to your registered email address.'
                : 'A six-digit verification code was sent to your registered email address. Enter it to download one file.';
            logActivity('Requested waste data export verification', 'Waste Data', 'Success', $conn);
        }
    }
}

// Fetch staff users for dropdown
$staff_users = [];
try {
    $stmt = $conn->query("SELECT id, first_name, last_name FROM users WHERE user_type = 'staff' AND is_active = 1 ORDER BY first_name");
    $staff_users = $stmt->fetchAll();
} catch (PDOException $e) {
    // Handle error silently
}

// Paginate the view while retaining unpaginated filtered exports.
$perPage = 25;
$requestedPage = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
$records = [];
$totalRecordCount = 0;
$totalPages = 1;
$currentPage = 1;
$filteredWasteTotal = 0.0;
try {
    $totalsStmt = $conn->prepare("SELECT COUNT(*) AS total_records, COALESCE(SUM($recordTotalExpr), 0) AS filtered_total FROM waste_records $where_clause");
    $totalsStmt->execute($params);
    $totals = $totalsStmt->fetch() ?: [];
    $totalRecordCount = (int)($totals['total_records'] ?? 0);
    $filteredWasteTotal = (float)($totals['filtered_total'] ?? 0);
    $totalPages = max(1, (int)ceil($totalRecordCount / $perPage));
    $currentPage = min($requestedPage, $totalPages);
    $offset = ($currentPage - 1) * $perPage;

    $sql = "SELECT *, $recordTotalExpr AS search_waste_total FROM waste_records $where_clause ORDER BY collection_date DESC, id DESC LIMIT $perPage OFFSET $offset";
    $stmt = $conn->prepare($sql);
    foreach ($params as $index => $value) {
        $stmt->bindValue($index + 1, $value);
    }
    $stmt->execute();
    $records = $stmt->fetchAll();
} catch (PDOException $e) {
    $error = "Failed to load waste records.";
}
$paginationParams = array_filter([
    'waste_type' => $wasteTypeFilter,
    'collection_group' => $collectionGroupFilter,
    'year' => $yearFilter,
    'month' => $monthFilter,
], static function ($value) {
    return $value !== '';
});
$notificationItems = getNotificationItems($conn, $user);

// Get record for editing
$edit_record = null;
if (isset($_GET['edit_id'])) {
    $edit_id = intval($_GET['edit_id']);
    try {
        $stmt = $conn->prepare("SELECT * FROM waste_records WHERE id = ? AND is_active = 1");
        $stmt->execute([$edit_id]);
        $edit_record = $stmt->fetch();
    } catch (PDOException $e) {
        $error = "Failed to load record for editing.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Waste Data Entries - EcoTrack</title>
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
        }

        .header {
          background: var(--bg-secondary);
          padding: 20px 30px;
          border-radius: 15px;
          box-shadow: var(--card-shadow);
          display: flex;
          justify-content: space-between;
          align-items: center;
          margin-bottom: 30px;
        }

        .header h1 {
          color: var(--text-primary);
          font-size: 24px;
        }

        .header-icons {
          display: flex;
          gap: 20px;
        }

        .header-icons a {
          color: var(--text-secondary);
          text-decoration: none;
          font-size: 20px;
          position: relative;
        }
        .header-actions {
          display: flex;
          align-items: center;
          gap: 18px;
        }
        .header-actions .header-icons > a {
          display: none !important;
        }
        .btn-add-waste {
          padding: 11px 16px;
          border: 0;
          border-radius: 8px;
          background: var(--eco-primary, #16735f);
          color: #fff;
          cursor: pointer;
          font-size: 13px;
          font-weight: 700;
        }
        .btn-add-waste:hover {
          background: var(--eco-primary-strong, #0b4f43);
        }

        /* Search Section */
        .search-section {
          background: var(--bg-secondary);
          padding: 22px 24px;
          border-radius: 15px;
          box-shadow: var(--card-shadow);
          margin-bottom: 20px;
          display: grid;
          grid-template-columns: minmax(0, 1fr) auto;
          gap: 18px;
          align-items: end;
        }

        .filter-form {
          display: grid;
          grid-template-columns: repeat(3, minmax(145px, 1fr)) auto auto;
          gap: 12px;
          align-items: flex-end;
          min-width: 0;
        }

        .waste-filter-field {
          min-width: 0;
        }

        .waste-filter-field label {
          display: block;
          margin: 0 0 6px;
          color: var(--text-secondary);
          font-size: 12px;
          font-weight: 700;
          letter-spacing: 0.03em;
          text-transform: uppercase;
        }

        .search-section .waste-filter-field input,
        .search-section .waste-filter-field select,
        .search-section .waste-filter-field .year-picker-trigger {
          width: 100%;
          padding: 12px 15px;
          border: 2px solid var(--input-border);
          border-radius: 10px;
          font-size: 14px;
          background: var(--input-bg);
          color: var(--text-primary);
        }

        /* The collection-period field is rendered as a custom picker after
           JavaScript loads.  Match its visible button to the native selects
           above it so it does not grow taller and shift upward in the filter
           row. */
        .search-section .waste-filter-field .year-picker {
          position: relative;
          width: 100%;
        }

        .search-section .waste-filter-field .year-picker-trigger {
          display: flex;
          align-items: center;
          min-height: 42px;
          height: 42px;
          padding: 8px 34px 8px 10px;
          font-size: 13px;
          line-height: 1.3;
        }

        .search-section .waste-filter-field .year-picker::after {
          content: "";
          position: absolute;
          top: 50%;
          right: 14px;
          width: 7px;
          height: 7px;
          border-right: 2px solid currentColor;
          border-bottom: 2px solid currentColor;
          color: var(--text-secondary);
          opacity: 0.8;
          pointer-events: none;
          transform: translateY(-70%) rotate(45deg);
        }

        .search-section input:focus {
          outline: none;
          border-color: var(--toggle-active);
        }

        .btn {
          min-height: 44px;
          padding: 12px 25px;
          border: none;
          border-radius: 10px;
          cursor: pointer;
          font-size: 14px;
          font-weight: 600;
          transition: all 0.3s;
        }

        .btn-search {
          background: var(--toggle-active);
          color: white;
        }

        .btn-search:hover {
          background: #45a049;
        }

        .btn-clear {
          background: var(--text-secondary);
          color: white;
        }

        .btn-clear:hover {
          background: var(--text-muted);
        }

        .export-form {
          display: flex;
          gap: 12px;
          align-items: center;
        }

        .btn-download {
          background: var(--toggle-active);
          color: white;
        }

        .btn-download:hover {
          background: #45a049;
        }

        .filter-result-summary {
          background: var(--bg-secondary);
          border: 1px solid var(--border-color);
          border-left: 4px solid var(--toggle-active);
          border-radius: 12px;
          box-shadow: var(--card-shadow);
          margin-bottom: 20px;
          padding: 16px 20px;
        }

        .filter-result-summary h2 {
          color: var(--text-secondary);
          font-size: 12px;
          letter-spacing: 0.06em;
          margin: 0 0 5px;
        }

        .filter-total {
          color: var(--text-primary);
          font-size: 24px;
          font-weight: 700;
          margin: 0;
        }

        .filter-details {
          color: var(--text-secondary);
          display: flex;
          flex-wrap: wrap;
          font-size: 13px;
          gap: 6px 16px;
          margin: 10px 0 0;
        }

        .no-filter-results {
          color: #c62828;
          font-size: 13px;
          font-weight: 600;
          margin: 10px 0 0;
        }

        @media (max-width: 1250px) {
          .search-section {
            grid-template-columns: 1fr;
          }

          .export-form {
            justify-content: start;
          }
        }

        @media (max-width: 900px) {
          .filter-form {
            grid-template-columns: repeat(2, minmax(145px, 1fr));
          }

          .filter-form .btn {
            width: 100%;
          }
        }

        @media (max-width: 620px) {
          .search-section {
            padding: 18px;
          }
          .filter-form {
            grid-template-columns: 1fr;
          }
          .filter-form .btn,
          .export-form .btn {
            width: 100%;
          }
          .export-form {
            width: 100%;
          }
          .waste-filter-field {
            width: 100%;
          }
        }

        /* Data Table */
        .data-table-container {
          background: var(--bg-secondary);
          border-radius: 15px;
          box-shadow: var(--card-shadow);
          overflow-x: auto;
          overflow-y: hidden;
          width: 100%;
          padding-bottom: 8px;
          scrollbar-color: var(--toggle-active) #d9eee8;
          scrollbar-width: thin;
        }

        .data-table-container::-webkit-scrollbar {
          height: 12px;
        }

        .data-table-container::-webkit-scrollbar-track {
          background: #d9eee8;
          border-radius: 999px;
        }

        .data-table-container::-webkit-scrollbar-thumb {
          background: var(--toggle-active);
          border-radius: 999px;
        }

        .data-table {
          width: max(100%, 1750px);
          border-collapse: collapse;
          table-layout: auto;
        }

        .data-table th {
          background: var(--toggle-active);
          color: white;
          padding: 15px;
          text-align: left;
          font-weight: 600;
          white-space: nowrap;
          vertical-align: middle;
        }

        .data-table td {
          padding: 15px;
          border-bottom: 1px solid var(--border-color);
          white-space: nowrap;
          vertical-align: middle;
        }

        .data-table tr:hover {
          background: var(--bg-primary);
        }

        .data-table .no-data {
          text-align: center;
          padding: 50px;
          color: var(--text-muted);
        }

        .table-pagination {
          display: flex;
          flex-wrap: wrap;
          align-items: center;
          justify-content: space-between;
          gap: 12px;
          margin-top: 18px;
        }
        .pagination-summary {
          color: var(--text-secondary);
          font-size: 13px;
        }
        .pagination-links {
          display: flex;
          flex-wrap: wrap;
          gap: 6px;
        }
        .pagination-links a,
        .pagination-links span {
          min-width: 36px;
          padding: 8px 10px;
          text-align: center;
          border: 1px solid var(--border-color);
          border-radius: 6px;
          text-decoration: none;
          color: var(--text-primary);
          background: var(--bg-secondary);
        }
        .pagination-links a:hover {
          border-color: var(--toggle-active);
          color: var(--toggle-active);
        }
        .pagination-links .active {
          background: var(--toggle-active);
          border-color: var(--toggle-active);
          color: #fff;
          font-weight: 700;
        }
        .pagination-links .disabled {
          opacity: 0.5;
        }

        /* Modal */
        .modal-overlay {
          display: none;
          position: fixed;
          top: 0;
          left: 0;
          width: 100%;
          height: 100%;
          background: rgba(0, 0, 0, 0.5);
          z-index: 1000;
          justify-content: center;
          align-items: center;
        }

        .modal-overlay.active {
          display: flex;
        }

        .modal {
          background: var(--bg-secondary);
          border: 1px solid var(--border-color);
          border-radius: 12px;
          width: min(94vw, 720px);
          max-height: 90vh;
          overflow-y: auto;
          overflow-x: hidden;
          padding: 24px;
          color: var(--text-primary);
        }

        .modal h2 {
          margin-bottom: 20px;
          font-size: 20px;
        }

        .entry-help {
          color: var(--text-secondary);
          font-size: 13px;
          margin: -8px 0 16px;
        }
        .entry-form {
          display: grid;
          gap: 14px;
        }
        .entry-field {
          display: grid;
          gap: 6px;
        }
        .entry-field label {
          color: var(--text-secondary);
          font-size: 12px;
          font-weight: 700;
          letter-spacing: 0.03em;
          text-transform: uppercase;
        }
        .entry-field input,
        .entry-field select {
          width: 100%;
          min-height: 42px;
          padding: 10px 12px;
          border: 1px solid var(--input-border);
          border-radius: 7px;
          background: var(--input-bg);
          color: var(--text-primary);
          font-size: 14px;
        }
        .entry-field input:focus,
        .entry-field select:focus {
          outline: none;
          border-color: var(--toggle-active);
          box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.14);
        }

        .form-row {
          display: flex;
          gap: 20px;
          margin-bottom: 15px;
        }

        .form-group {
          flex: 1;
        }

        .form-group label {
          display: block;
          margin-bottom: 5px;
          font-size: 12px;
          text-transform: uppercase;
          color: #aaa;
        }

        .form-group input,
        .form-group select {
          width: 100%;
          padding: 10px;
          border: 1px solid #555;
          border-radius: 5px;
          background: #444;
          color: white;
          font-size: 14px;
        }

        .form-group input:focus,
        .form-group select:focus {
          outline: none;
          border-color: #4caf50;
        }

        .modal-buttons {
          display: flex;
          justify-content: flex-end;
          gap: 10px;
          margin-top: 20px;
        }

        .btn-cancel {
          background: #d32f2f;
          color: white;
          padding: 12px 25px;
        }

        .btn-cancel:hover {
          background: #c62828;
        }

        .btn-save {
          background: #4caf50;
          color: white;
          padding: 12px 25px;
        }

        .btn-save:hover {
          background: #45a049;
        }

        .btn-modal-clear {
          background: #757575;
          color: white;
          padding: 12px 25px;
        }

        .btn-modal-clear:hover {
          background: #616161;
        }

        /* Messages */
        .message {
          padding: 15px;
          border-radius: 10px;
          margin-bottom: 20px;
        }

        .message.success {
          background: #e8f5e8;
          color: #2e7d32;
          border: 1px solid #c8e6c9;
        }

        .message.error {
          background: #ffebee;
          color: #c62828;
          border: 1px solid #ffcdd2;
        }
    </style>
    <link rel="stylesheet" href="assets/css/ecotrack-theme.css?v=<?php echo filemtime(__DIR__ . '/assets/css/ecotrack-theme.css'); ?>">
    <script defer src="assets/js/year-navigable-picker.js?v=<?php echo filemtime(__DIR__ . '/assets/js/year-navigable-picker.js'); ?>"></script>
    <style>
        /* Scope local record-editor styles away from shared logout dialogs. */
        .modal-overlay.active > .modal {
          display: block !important;
          position: relative !important;
          inset: auto !important;
          z-index: 1001 !important;
          width: min(94vw, 720px) !important;
          max-height: 90vh !important;
          overflow-y: auto !important;
          overflow-x: hidden !important;
          padding: 24px !important;
          background: var(--bg-secondary) !important;
          color: var(--text-primary) !important;
          border: 1px solid var(--border-color) !important;
          border-radius: 12px !important;
        }
    </style>
</head>
<body>
    <?php $active_page = $wasteRecordsRoute;
$useLogoutModal = true;
include 'includes/sidebar.php'; ?>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <div class="header">
            <h1>WASTE DATA ENTRIES</h1>
            <div class="header-actions">
                <?php if ($user_type == 'admin'): ?><button type="button" class="btn-add-waste" onclick="openModal()">+ Add Waste Data</button><?php endif; ?>
            <div class="header-icons">
                <?php include 'includes/notification_bell.php'; ?>
            </div>
            </div>
        </div>

        <?php if (!empty($success)): ?>
            <div class="message success"><?php echo htmlspecialchars($success); ?></div>
            <script>
                // Lets an already-open Heatmap tab know that its source data changed.
                localStorage.setItem("ecotrackWasteDataUpdated", String(Date.now()));
            </script>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="message error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <!-- Search Section -->
        <div class="search-section">
            <form method="get" action="<?php echo $wasteRecordsRoute; ?>" class="filter-form">
                <div class="waste-filter-field"><label for="waste_type">Waste Type</label><select id="waste_type" name="waste_type"><option value="">All Waste Categories</option><?php foreach ($wasteTypeOptions as $value => $label): ?><option value="<?php echo htmlspecialchars($value); ?>" <?php echo $normalizedWasteType === $value ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option><?php endforeach; ?></select></div>
                <div class="waste-filter-field"><label for="collection_group">Collection Group</label><select id="collection_group" name="collection_group"><option value="">All Collection Groups</option><?php foreach ($collectionGroupOptions as $group): ?><option value="<?php echo htmlspecialchars($group); ?>" <?php echo $collectionGroupFilter === $group ? 'selected' : ''; ?>><?php echo htmlspecialchars($group); ?></option><?php endforeach; ?></select></div>
                <div class="waste-filter-field"><label for="month">Collection Period</label><select id="year" name="year" aria-label="Collection year"><option value="">All Years</option><?php foreach ($wasteYearOptions as $yearOption): ?><option value="<?php echo htmlspecialchars($yearOption); ?>" <?php echo $yearFilter === $yearOption ? 'selected' : ''; ?>><?php echo htmlspecialchars($yearOption); ?></option><?php endforeach; ?></select><input id="month" type="month" name="month" data-year-navigable-picker="month" data-year-picker-external-year="year" data-year-picker-combined-period="true" data-year-picker-allow-empty="true" data-year-picker-placeholder="All periods" data-calendar-years='<?php echo htmlspecialchars(json_encode($wasteYearOptions, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>' data-calendar-months='<?php echo htmlspecialchars($wasteMonthsByYearJson, ENT_QUOTES, 'UTF-8'); ?>' value="<?php echo htmlspecialchars($monthFilter); ?>"></div>
                <button type="submit" class="btn btn-search">Search</button>
                <a href="<?php echo $wasteRecordsRoute; ?>" class="btn btn-clear">Clear</a>
            </form>
            <form method="post" action="<?php echo $wasteRecordsRoute; ?>" class="export-form" autocomplete="off" data-confirm-title="Confirm download" data-confirm-message="Are you sure you want to download this file?" data-confirm-action="Continue to verification">
                <?php if ($wasteTypeFilter !== ''): ?><input type="hidden" name="waste_type" value="<?php echo htmlspecialchars($wasteTypeFilter); ?>"><?php endif; ?>
                <?php if ($collectionGroupFilter !== ''): ?><input type="hidden" name="collection_group" value="<?php echo htmlspecialchars($collectionGroupFilter); ?>"><?php endif; ?>
                <?php if ($yearFilter !== ''): ?><input type="hidden" name="year" value="<?php echo htmlspecialchars($yearFilter); ?>"><?php endif; ?>
                <?php if ($monthFilter !== ''): ?><input type="hidden" name="month" value="<?php echo htmlspecialchars($monthFilter); ?>"><?php endif; ?>
                <input type="hidden" name="export" value="excel">
                <button type="submit" class="btn btn-download"><?php echo $exportOtpRequired ? 'REQUEST NEW CODE' : 'DOWNLOAD EXCEL'; ?></button>
            </form>
        </div>

        <?php if ($hasActiveFilters): ?>
            <section class="filter-result-summary" aria-live="polite">
                <h2>TOTAL WASTE COLLECTED</h2>
                <p class="filter-total"><?php echo number_format($filteredWasteTotal, 2); ?> kg</p>
                <div class="filter-details">
                    <?php foreach ($filterSummary as $filter): ?>
                        <span><?php echo htmlspecialchars($filter); ?></span>
                    <?php endforeach; ?>
                </div>
                <?php if (empty($records)): ?>
                    <p class="no-filter-results">No waste collection records found for the selected filters.</p>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <!-- Data Table -->
        <div class="data-table-container">
            <table class="data-table">
                <thead>
                    <?php echo buildWasteTableHeader($user_type == 'admin'); ?>
                </thead>
                <tbody>
                    <?php if (empty($records)): ?>
                        <tr>
                            <td colspan="<?php echo count(getWasteFormatHeaders()) + 2 + (($user_type == 'admin') ? 1 : 0); ?>" class="no-data"><?php echo $hasActiveFilters ? 'No waste collection records found for the selected filters.' : 'No waste collection data found.'; ?></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($records as $record): ?>
                            <tr>
                                <?php foreach (wasteRecordDisplayValues($record) as $value): ?>
                                    <td><?php echo htmlspecialchars($value); ?></td>
                                <?php endforeach; ?>
                                <?php if ($user_type == 'admin'): ?>
                                <td>
                                    <a href="?edit_id=<?php echo $record['id']; ?>" class="btn btn-edit" style="padding: 5px 10px; font-size: 11px;">Edit</a>
                                    <form method="post" action="" style="display: inline;" data-confirm-title="Archive waste record?" data-confirm-message="This Waste Data record will be removed from active reporting. Continue?" data-confirm-action="Archive record">
                                        <input type="hidden" name="record_id" value="<?php echo $record['id']; ?>">
                                        <button type="submit" name="delete_record" class="btn btn-delete" style="padding: 5px 10px; font-size: 11px; background: #f44336; color: white; border: none; border-radius: 5px; cursor: pointer;">Delete</button>
                                    </form>
                                </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($totalRecordCount > 0): ?>
        <?php
        $rangeStart = (($currentPage - 1) * $perPage) + 1;
            $rangeEnd = min($currentPage * $perPage, $totalRecordCount);
            $windowStart = max(1, min($currentPage - 4, max(1, $totalPages - 9)));
            $windowEnd = min($totalPages, $windowStart + 9);
            $pageUrl = static function ($page) use ($paginationParams, $wasteRecordsRoute) {
                return $wasteRecordsRoute . '?' . http_build_query(array_merge($paginationParams, ['page' => $page]));
            };
            ?>
        <nav class="table-pagination" aria-label="Waste Data pages">
            <span class="pagination-summary">Showing <?php echo $rangeStart; ?>–<?php echo $rangeEnd; ?> of <?php echo $totalRecordCount; ?> records</span>
            <div class="pagination-links">
                <?php if ($currentPage > 1): ?><a href="<?php echo htmlspecialchars($pageUrl($currentPage - 1)); ?>" rel="prev">Previous</a><?php else: ?><span class="disabled">Previous</span><?php endif; ?>
                <?php if ($windowStart > 1): ?><a href="<?php echo htmlspecialchars($pageUrl(1)); ?>">1</a><span>…</span><?php endif; ?>
                <?php for ($page = $windowStart; $page <= $windowEnd; $page++): ?>
                    <?php if ($page === $currentPage): ?><span class="active" aria-current="page"><?php echo $page; ?></span><?php else: ?><a href="<?php echo htmlspecialchars($pageUrl($page)); ?>"><?php echo $page; ?></a><?php endif; ?>
                <?php endfor; ?>
                <?php if ($windowEnd < $totalPages): ?><span>…</span><a href="<?php echo htmlspecialchars($pageUrl($totalPages)); ?>"><?php echo $totalPages; ?></a><?php endif; ?>
                <?php if ($currentPage < $totalPages): ?><a href="<?php echo htmlspecialchars($pageUrl($currentPage + 1)); ?>" rel="next">Next</a><?php else: ?><span class="disabled">Next</span><?php endif; ?>
            </div>
        </nav>
        <?php endif; ?>
    </div>

    <?php if ($exportOtpRequired): ?>
        <?php
        $otpModalId = 'exportOtpModal';
        $otpModalTitle = 'Verify your download';
        $otpModalMessage = 'Enter the six-digit verification code sent to your registered email address to download this file.';
        $otpModalError = $submittedExportOtp !== '' ? $error : '';
        $otpModalAction = $wasteRecordsRoute;
        $otpModalField = 'export_otp';
        $otpModalSubmitLabel = 'Verify and download';
        $otpModalCancelHref = $wasteRecordsRoute . '?' . http_build_query($paginationParams);
        $otpModalCancelLabel = 'Cancel download';
        $otpModalSuccessCookie = $exportDownloadSuccessCookie;
        $otpModalSuccessMessage = 'Successfully downloaded.';
        $otpModalHiddenInputs = array_filter([
            'export' => $export_format,
            'waste_type' => $wasteTypeFilter,
            'collection_group' => $collectionGroupFilter,
            'year' => $yearFilter,
            'month' => $monthFilter,
        ], static function ($value) { return $value !== ''; });
        include 'includes/otp_modal.php';
        ?>
    <?php endif; ?>

    <?php if ($user_type == 'admin'): ?>
    <!-- Add Record Modal -->
    <div class="modal-overlay" id="modal">
        <div class="modal">
            <h2><?php echo $edit_record ? 'EDIT WASTE RECORD' : 'ADD NEW WASTE RECORD'; ?></h2>

            <p class="entry-help">Enter one record from top to bottom. Collection Group, Collection Date, and Area are required. Date / Period is optional.</p>
            <form method="post" action="" id="addRecordForm" data-confirm-title="<?php echo $edit_record ? 'Update waste record?' : 'Save waste record?'; ?>" data-confirm-message="Review the entered Waste Data before saving. Continue?" data-confirm-action="<?php echo $edit_record ? 'Update record' : 'Save record'; ?>">
                <?php if ($edit_record): ?>
                    <input type="hidden" name="record_id" value="<?php echo $edit_record['id']; ?>">
                <?php endif; ?>
                <?php $formCollectionDate = $edit_record ? ($edit_record['collection_date'] ?: wasteRecordCollectionDate($edit_record) ?: date('Y-m-d')) : date('Y-m-d'); ?>
                <div class="entry-form">
                    <div class="entry-field"><label for="entry_collection_group">Collection Group</label><select id="entry_collection_group" name="collection_group" required <?php echo empty($availableEntryCollectionGroups) ? 'disabled' : ''; ?>><option value=""><?php echo empty($availableEntryCollectionGroups) ? 'No saved Collection Groups available' : 'Select Collection Group'; ?></option><?php foreach ($availableEntryCollectionGroups as $group): ?><option value="<?php echo htmlspecialchars($group); ?>" <?php echo $edit_record && wasteRecordCollectionGroup($edit_record) === $group ? 'selected' : ''; ?>><?php echo htmlspecialchars($group); ?></option><?php endforeach; ?></select><?php if (empty($availableEntryCollectionGroups)): ?><small class="entry-help">Import or save Waste Data with a Collection Group before adding manual records.</small><?php endif; ?></div>
                    <div class="entry-field"><label for="reporting_period">Date / Period</label><input id="reporting_period" type="text" name="reporting_period" value="<?php echo $edit_record ? htmlspecialchars(trim((string)($edit_record['reporting_period'] ?? '')) !== '' ? $edit_record['reporting_period'] : wasteRecordDatePeriod($edit_record)) : ''; ?>"></div>
                    <div class="entry-field"><label for="collection_date">Collection Date</label><input id="collection_date" type="date" name="collection_date" value="<?php echo htmlspecialchars($formCollectionDate); ?>" required></div>
                    <div class="entry-field"><label for="name_of_bioman">Bioman</label><input id="name_of_bioman" type="text" name="name_of_bioman" value="<?php echo $edit_record ? htmlspecialchars($edit_record['name_of_bioman'] ?: $edit_record['garbage_collector']) : ''; ?>"></div>
                    <div class="entry-field"><label for="street">Area</label><input id="street" type="text" name="street" value="<?php echo $edit_record ? htmlspecialchars($edit_record['street']) : ''; ?>" required></div>
                    <div class="entry-field"><label for="households">Households</label><input id="households" type="text" name="households" value="<?php echo $edit_record ? htmlspecialchars(cleanWasteHouseholdsValue($edit_record['households'] ?? '', $edit_record['phase_date_label'] ?? '', $edit_record['phase_number'] ?? '')) : ''; ?>"></div>
                    <div class="entry-field"><label for="tuesday_factory_returnable_kg">Tuesday Factory KL</label><input id="tuesday_factory_returnable_kg" type="number" name="tuesday_factory_returnable_kg" step="0.01" min="0" value="<?php echo $edit_record ? htmlspecialchars($edit_record['tuesday_factory_returnable_kg'] ?: $edit_record['recyclable_kg']) : ''; ?>"></div>
                    <div class="entry-field"><label for="comply_tue">Tuesday Comply</label><input id="comply_tue" type="text" name="comply_tue" value="<?php echo $edit_record ? htmlspecialchars($edit_record['comply_tue'] ?? '') : ''; ?>"></div>
                    <div class="entry-field"><label for="cd_processing">Co/Processing</label><input id="cd_processing" type="text" name="cd_processing" value="<?php echo $edit_record ? htmlspecialchars($edit_record['cd_processing'] ?? '') : ''; ?>"></div>
                    <div class="entry-field"><label for="wednesday_biowaste_kg">Wednesday KL</label><input id="wednesday_biowaste_kg" type="number" name="wednesday_biowaste_kg" step="0.01" min="0" value="<?php echo $edit_record ? htmlspecialchars($edit_record['wednesday_biowaste_kg'] ?: '') : ''; ?>"></div>
                    <div class="entry-field"><label for="comply_wed">Wednesday Comply</label><input id="comply_wed" type="text" name="comply_wed" value="<?php echo $edit_record ? htmlspecialchars($edit_record['comply_wed'] ?? '') : ''; ?>"></div>
                    <div class="entry-field"><label for="thursday_factory_returnable_kg">Thursday KL</label><input id="thursday_factory_returnable_kg" type="number" name="thursday_factory_returnable_kg" step="0.01" min="0" value="<?php echo $edit_record ? htmlspecialchars($edit_record['thursday_factory_returnable_kg'] ?? '') : ''; ?>"></div>
                    <div class="entry-field"><label for="friday_biowaste_kg">Friday KL</label><input id="friday_biowaste_kg" type="number" name="friday_biowaste_kg" step="0.01" min="0" value="<?php echo $edit_record ? htmlspecialchars($edit_record['friday_biowaste_kg'] ?? '') : ''; ?>"></div>
                    <div class="entry-field"><label for="comply_fri">Friday Comply</label><input id="comply_fri" type="text" name="comply_fri" value="<?php echo $edit_record ? htmlspecialchars($edit_record['comply_fri'] ?? '') : ''; ?>"></div>
                    <div class="entry-field"><label for="saturday_hazard_waste_kg">Saturday Hazard</label><input id="saturday_hazard_waste_kg" type="number" name="saturday_hazard_waste_kg" step="0.01" min="0" value="<?php echo $edit_record ? htmlspecialchars($edit_record['saturday_hazard_waste_kg'] ?: $edit_record['hazardous_kg']) : ''; ?>"></div>
                    <div class="entry-field"><label for="residual_waste_kg">Residual Waste (kg)</label><input id="residual_waste_kg" type="number" name="residual_waste_kg" step="0.01" min="0" value="<?php echo $edit_record ? htmlspecialchars($edit_record['residual_waste_kg'] ?: $edit_record['residual_kg']) : ''; ?>"></div>
                    <div class="entry-field"><label for="unclassified_waste_kg">Unclassified Waste (kg)</label><input id="unclassified_waste_kg" type="number" name="unclassified_waste_kg" step="0.01" min="0" value="<?php echo $edit_record ? htmlspecialchars($edit_record['unclassified_waste_kg'] ?? '') : ''; ?>"></div>
                </div>

                <div class="modal-buttons">
                    <button type="submit" name="<?php echo $edit_record ? 'update_record' : 'add_record'; ?>" class="btn btn-save"><?php echo $edit_record ? 'Update Record' : 'Save Record'; ?></button>
                    <button type="button" class="btn btn-modal-clear" onclick="clearForm()">Clear</button>
                    <button type="button" class="btn btn-cancel" onclick="closeModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($user_type == 'admin'): ?>
    <script>
        function openModal() {
          document.getElementById("modal").classList.add("active");
        }

        function closeModal() {
          document.getElementById("modal").classList.remove("active");
        }

        function clearForm() {
          document.getElementById("addRecordForm").reset();
        }

        // Close modal when clicking outside
        document.getElementById("modal").addEventListener("click", function (e) {
          if (e.target === this) {
            closeModal();
          }
        });
        <?php if ($edit_record): ?>
            openModal();
        <?php endif; ?>
    </script>
    <?php endif; ?>

    <script>
        // Refresh open Waste Data tabs after imports, additions, or updates.
        window.addEventListener("storage", function (event) {
          if (event.key === "ecotrackWasteDataUpdated" && event.newValue) {
            window.location.reload();
          }
        });
    </script>

    <?php include 'includes/logout_confirmation_modal.php'; ?>
</body>
</html>

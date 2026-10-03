<?php

/** Shared waste calculations for entry, dashboard, heatmap, and reports. */

function ensureWasteAnalyticsColumns($conn)
{
    static $checked = false;
    if (!$conn || $checked) {
        return;
    }

    try {
        $columns = $conn->query('SHOW COLUMNS FROM waste_records')->fetchAll();
        $existingColumns = [];
        foreach ($columns as $column) {
            $existingColumns[$column['Field']] = true;
        }

        if (!isset($existingColumns['collection_date'])) {
            $conn->exec('ALTER TABLE waste_records ADD COLUMN collection_date DATE NULL AFTER date');
        }
        if (!isset($existingColumns['is_active'])) {
            $conn->exec('ALTER TABLE waste_records ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER collection_date');
        }

        $indexes = $conn->query('SHOW INDEX FROM waste_records')->fetchAll();
        $hasCollectionDateIndex = false;
        foreach ($indexes as $index) {
            if (($index['Key_name'] ?? '') === 'idx_waste_records_collection_date') {
                $hasCollectionDateIndex = true;
                break;
            }
        }
        if (!$hasCollectionDateIndex) {
            $conn->exec('CREATE INDEX idx_waste_records_collection_date ON waste_records (collection_date)');
        }

        $checked = true;
    } catch (PDOException $e) {
        error_log('Failed to ensure waste analytics columns: ' . $e->getMessage());
    }
}

/** Build calendar-day allocations from the authoritative uploaded rows. */
function ensureWasteDailyAllocationTable($conn)
{
    static $checked = false;
    if (!$conn || $checked) {
        return;
    }

    try {
        $conn->exec("CREATE TABLE IF NOT EXISTS waste_daily_allocations (
            waste_record_id INT NOT NULL,
            allocation_date DATE NOT NULL,
            tuesday_factory_returnable_kg DECIMAL(10,2) NOT NULL DEFAULT 0,
            wednesday_biowaste_kg DECIMAL(10,2) NOT NULL DEFAULT 0,
            thursday_factory_returnable_kg DECIMAL(10,2) NOT NULL DEFAULT 0,
            friday_biowaste_kg DECIMAL(10,2) NOT NULL DEFAULT 0,
            saturday_hazard_waste_kg DECIMAL(10,2) NOT NULL DEFAULT 0,
            residual_waste_kg DECIMAL(10,2) NOT NULL DEFAULT 0,
            unclassified_waste_kg DECIMAL(10,2) NOT NULL DEFAULT 0,
            kilogram_of_waste DECIMAL(10,2) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (waste_record_id, allocation_date),
            INDEX idx_waste_daily_allocations_date (allocation_date)
        )");
        $allocationColumns = $conn->query('SHOW COLUMNS FROM waste_daily_allocations')->fetchAll();
        $hasUnclassified = false;
        foreach ($allocationColumns as $column) {
            if (($column['Field'] ?? '') === 'unclassified_waste_kg') {
                $hasUnclassified = true;
                break;
            }
        }
        if (!$hasUnclassified) {
            $conn->exec('ALTER TABLE waste_daily_allocations ADD COLUMN unclassified_waste_kg DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER residual_waste_kg');
        }
        $checked = true;
    } catch (PDOException $e) {
        error_log('Failed to ensure waste daily allocations table: ' . $e->getMessage());
    }
}

function wasteNumericValue($value)
{
    return is_numeric($value) ? (float)$value : 0.0;
}

function wasteRecordEffectiveKg($record)
{
    $componentColumns = [
        'tuesday_factory_returnable_kg',
        'wednesday_biowaste_kg',
        'thursday_factory_returnable_kg',
        'friday_biowaste_kg',
        'saturday_hazard_waste_kg',
        'residual_waste_kg',
        'unclassified_waste_kg',
    ];

    $componentTotal = 0.0;
    foreach ($componentColumns as $column) {
        $componentTotal += wasteNumericValue($record[$column] ?? 0);
    }

    return $componentTotal > 0 ? $componentTotal : wasteNumericValue($record['kilogram_of_waste'] ?? 0);
}

function wasteRecordPhaseSource($record)
{
    // New records use collection_group; legacy fields remain supported.
    foreach (['collection_group', 'phase_number', 'phase_date_label', 'date'] as $column) {
        $value = trim((string)($record[$column] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }
    return '';
}

function normalizeWastePhaseLabel($record)
{
    // Preserve non-phase groups such as Establishments before legacy fallbacks.
    $collectionGroup = trim((string)($record['collection_group'] ?? ''));
    if ($collectionGroup !== '') {
        return $collectionGroup;
    }

    $source = wasteRecordPhaseSource($record);
    if (preg_match('/\bphase\s*([0-9]+(?:\s*-?\s*[a-z](?![a-z]))?)/i', $source, $matches)) {
        $phaseToken = strtoupper(preg_replace('/\s+/', '', trim($matches[1])));
        return 'Phase ' . $phaseToken;
    }

    $street = trim((string)($record['street'] ?? ''));
    if ($street !== '') {
        return 'Area - ' . $street;
    }

    $source = preg_replace('/\s*[-–—]?\s*date\s*:.*$/i', '', $source);
    return trim($source) !== '' ? trim($source) : 'Unassigned area';
}

function normalizeWasteDateValue($value)
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }

    // Year-only uploads use the first day of that year for date-based charts.
    if (preg_match('/^(19|20)\d{2}$/', $value)) {
        return $value . '-01-01';
    }

    // Excel may send an unformatted serial date from a non-leading column.
    if (is_numeric($value) && (float)$value > 20000 && (float)$value < 70000) {
        return gmdate('Y-m-d', (int)(((float)$value - 25569) * 86400));
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if ($date && $date->format('Y-m-d') === $value) {
        return $date->format('Y-m-d');
    }

    foreach (['!Y/m/d', '!m/d/Y', '!d/m/Y'] as $format) {
        $date = DateTimeImmutable::createFromFormat($format, $value);
        if ($date) {
            return $date->format('Y-m-d');
        }
    }

    $searchValue = preg_replace('/[–—]/u', '-', $value);
    $monthAliases = [
        'jan' => 'January', 'january' => 'January',
        'feb' => 'February', 'february' => 'February',
        'mar' => 'March', 'march' => 'March',
        'apr' => 'April', 'aprl' => 'April', 'april' => 'April',
        'may' => 'May',
        'jun' => 'June', 'june' => 'June',
        'jul' => 'July', 'july' => 'July',
        'aug' => 'August', 'august' => 'August',
        'sep' => 'September', 'sept' => 'September', 'september' => 'September',
        'oct' => 'October', 'october' => 'October',
        'nov' => 'November', 'november' => 'November',
        'dec' => 'December', 'december' => 'December',
    ];
    $searchValue = preg_replace_callback(
        '/\b(january|jan|february|feb|march|mar|april|aprl|apr|may|june|jun|july|jul|august|aug|september|sept|sep|october|oct|november|nov|december|dec)(?=\s|\/|,|-|\d|$)/i',
        static function ($match) use ($monthAliases) {
            return $monthAliases[strtolower($match[1])];
        },
        $searchValue
    );
    $monthNames = 'January|February|March|April|May|June|July|August|September|October|November|December';
    $monthDatePattern = '(?<month>' . $monthNames . ')(?:\s*\/\s*(?:' . $monthNames . '))?\s*(?<day>\d{1,2})(?:\s*-\s*(?:(?:' . $monthNames . ')\s*)?\d{1,2})?';

    if (preg_match('~\b' . $monthDatePattern . '\s*,?\s*(?<year>(?:19|20)\d{2})\b~i', $searchValue, $matches)) {
        $date = DateTimeImmutable::createFromFormat('!F j Y', $matches['month'] . ' ' . $matches['day'] . ' ' . $matches['year']);
        if ($date) {
            return $date->format('Y-m-d');
        }
    }

    // Prefer a year stated in the source label over the current year.
    if (
        preg_match('/\b((?:19|20)\d{2})\b/', $searchValue, $yearMatch) &&
        preg_match('~\b' . $monthDatePattern . '~i', $searchValue, $dateMatch)
    ) {
        $date = DateTimeImmutable::createFromFormat('!F j Y', $dateMatch['month'] . ' ' . $dateMatch['day'] . ' ' . $yearMatch[1]);
        if ($date) {
            return $date->format('Y-m-d');
        }
    }

    return null;
}

function wasteMonthNumber($month)
{
    $months = [
        'jan' => 1, 'january' => 1, 'feb' => 2, 'february' => 2,
        'mar' => 3, 'march' => 3, 'apr' => 4, 'aprl' => 4, 'april' => 4,
        'may' => 5, 'jun' => 6, 'june' => 6, 'jul' => 7, 'july' => 7,
        'aug' => 8, 'august' => 8, 'sep' => 9, 'sept' => 9, 'september' => 9,
        'oct' => 10, 'october' => 10, 'nov' => 11, 'november' => 11,
        'dec' => 12, 'december' => 12,
    ];
    return $months[strtolower(trim((string)$month))] ?? 0;
}

function createStrictWasteDate($year, $month, $day)
{
    $date = DateTimeImmutable::createFromFormat('!Y-n-j', (int)$year . '-' . (int)$month . '-' . (int)$day);
    if (!$date || $date->format('Y-n-j') !== (int)$year . '-' . (int)$month . '-' . (int)$day) {
        return null;
    }
    return $date;
}

/** Resolve an inclusive reporting range, using a normalized fallback when needed. */
function parseWasteReportingDateRange($value, $fallbackDate = '')
{
    $value = trim((string)$value);
    $fallback = normalizeWasteDateValue($fallbackDate);
    if ($value === '') {
        return $fallback === null ? null : ['start' => $fallback, 'end' => $fallback];
    }

    $normalized = preg_replace('/[\x{2010}-\x{2015}]/u', '-', $value);
    if (preg_match('/^((?:19|20)\d{2}-\d{2}-\d{2})(?:\s*(?:to|-)\s*((?:19|20)\d{2}-\d{2}-\d{2}))?$/i', $normalized, $isoMatches)) {
        $start = normalizeWasteDateValue($isoMatches[1]);
        $end = normalizeWasteDateValue($isoMatches[2] ?? $isoMatches[1]);
        if ($start !== null && $end !== null && $end >= $start) {
            return ['start' => $start, 'end' => $end];
        }
        return null;
    }
    $monthNames = 'january|jan|february|feb|march|mar|april|aprl|apr|may|june|jun|july|jul|august|aug|september|sept|sep|october|oct|november|nov|december|dec';
    $rangePattern = '/\\b(' . $monthNames . ')\\.?\\s*(\d{1,2})\\s*(?:-|to)\\s*(?:(' . $monthNames . ')\\.?\\s*)?(\d{1,2})(?:\\s*,?\\s*((?:19|20)\\d{2}))?\\b/i';
    $fallbackObject = $fallback === null ? null : DateTimeImmutable::createFromFormat('!Y-m-d', $fallback);

    if (preg_match($rangePattern, $normalized, $matches)) {
        $startMonth = wasteMonthNumber($matches[1]);
        $endMonth = wasteMonthNumber($matches[3] ?? '') ?: $startMonth;
        $startDay = (int)$matches[2];
        $endDay = (int)$matches[4];
        $endYear = isset($matches[5]) && $matches[5] !== '' ? (int)$matches[5] : ($fallbackObject ? (int)$fallbackObject->format('Y') : 0);
        if ($endYear === 0) {
            return null;
        }
        $startYear = $endYear;
        if ($startMonth > $endMonth) {
            $startYear--;
        }
        $start = createStrictWasteDate($startYear, $startMonth, $startDay);
        $end = createStrictWasteDate($endYear, $endMonth, $endDay);
        if ($start === null || $end === null) {
            return null;
        }
        if ($end < $start || $start->diff($end)->days > 366) {
            return null;
        }
        return ['start' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d')];
    }

    // Normalized collection dates supply missing month values in legacy labels.
    if ($fallbackObject && preg_match('/(?:date\\s*:\\s*)?(\d{1,2})\\s*(?:-|to)\\s*(\d{1,2})\\b/i', $normalized, $matches)) {
        $year = (int)$fallbackObject->format('Y');
        $month = (int)$fallbackObject->format('m');
        $start = createStrictWasteDate($year, $month, (int)$matches[1]);
        $end = createStrictWasteDate($year, $month, (int)$matches[2]);
        if ($start === null || $end === null) {
            return null;
        }
        if ($end < $start) {
            return null;
        }
        return ['start' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d')];
    }

    if (wasteReportingLabelContainsRange($normalized)) {
        return null;
    }
    $single = normalizeWasteDateValue($value) ?: $fallback;
    return $single === null ? null : ['start' => $single, 'end' => $single];
}

function wasteReportingLabelContainsRange($value)
{
    $value = preg_replace('/[\x{2010}-\x{2015}]/u', '-', trim((string)$value));
    return preg_match('/(?:[a-z]{3,9}\.?\s*)?\d{1,2}\s*(?:-|to)\s*(?:[a-z]{3,9}\.?\s*)?\d{1,2}\b/i', $value) === 1;
}

function wasteReportingRangeForRecord($record)
{
    $fallback = normalizeWasteDateValue($record['collection_date'] ?? '');
    foreach (['reporting_period', 'phase_date_label', 'date'] as $column) {
        $value = trim((string)($record[$column] ?? ''));
        if ($value === '') {
            continue;
        }
        $range = parseWasteReportingDateRange($value, $fallback ?? '');
        if ($range !== null) {
            return $range;
        }
        if (wasteReportingLabelContainsRange($value)) {
            return null;
        }
    }
    return $fallback === null ? null : ['start' => $fallback, 'end' => $fallback];
}

function wasteDailyAllocationComponentColumns()
{
    return [
        'tuesday_factory_returnable_kg', 'wednesday_biowaste_kg',
        'thursday_factory_returnable_kg', 'friday_biowaste_kg',
        'saturday_hazard_waste_kg', 'residual_waste_kg',
        'unclassified_waste_kg',
    ];
}

function splitWasteAmountAcrossDays($amount, $days)
{
    $days = max(1, (int)$days);
    $cents = (int)round(max(0, wasteNumericValue($amount)) * 100);
    $base = intdiv($cents, $days);
    $remainder = $cents - ($base * $days);
    $parts = array_fill(0, $days, $base / 100);
    $parts[$days - 1] = ($base + $remainder) / 100;
    return $parts;
}

/** Build equal, inclusive daily rows without mutating the source record. */
function buildWasteDailyAllocations($record)
{
    $range = wasteReportingRangeForRecord($record);
    if ($range === null) {
        return [];
    }
    $start = new DateTimeImmutable($range['start']);
    $end = new DateTimeImmutable($range['end']);
    $days = $start->diff($end)->days + 1;
    $components = [];
    $componentTotal = 0.0;
    foreach (wasteDailyAllocationComponentColumns() as $column) {
        $components[$column] = wasteNumericValue($record[$column] ?? 0);
        $componentTotal += $components[$column];
    }
    // Preserve recorded totals from older imports in period analytics.
    if ($componentTotal <= 0 && wasteNumericValue($record['kilogram_of_waste'] ?? 0) > 0) {
        $components['residual_waste_kg'] = wasteNumericValue($record['kilogram_of_waste']);
    }
    $splitComponents = [];
    foreach ($components as $column => $amount) {
        $splitComponents[$column] = splitWasteAmountAcrossDays($amount, $days);
    }

    $allocations = [];
    for ($index = 0; $index < $days; $index++) {
        $date = $start->modify('+' . $index . ' days')->format('Y-m-d');
        $allocation = ['allocation_date' => $date];
        $total = 0.0;
        foreach (wasteDailyAllocationComponentColumns() as $column) {
            $allocation[$column] = $splitComponents[$column][$index];
            $total += $allocation[$column];
        }
        $allocation['kilogram_of_waste'] = round($total, 2);
        $allocations[] = $allocation;
    }
    return $allocations;
}

function storeWasteDailyAllocations($conn, $record)
{
    ensureWasteDailyAllocationTable($conn);
    $recordId = (int)($record['id'] ?? 0);
    if ($recordId <= 0) {
        throw new RuntimeException('A saved waste record is required before daily allocations can be created.');
    }
    $allocations = buildWasteDailyAllocations($record);
    if (empty($allocations)) {
        return 0;
    }
    $columns = array_merge(['waste_record_id'], array_keys($allocations[0]));
    $updates = [];
    foreach (array_slice($columns, 2) as $column) {
        $updates[] = $column . ' = VALUES(' . $column . ')';
    }
    $stmt = $conn->prepare('INSERT INTO waste_daily_allocations (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ') ON DUPLICATE KEY UPDATE ' . implode(', ', $updates));
    foreach ($allocations as $allocation) {
        $stmt->execute(array_merge([$recordId], array_values($allocation)));
    }
    return count($allocations);
}

/** Rebuild derived calendar rows after a source record changes. */
function rebuildWasteDailyAllocationsForRecord($conn, $recordId)
{
    ensureWasteDailyAllocationTable($conn);
    $recordId = (int)$recordId;
    if ($recordId <= 0) {
        return 0;
    }
    $delete = $conn->prepare('DELETE FROM waste_daily_allocations WHERE waste_record_id = ?');
    $delete->execute([$recordId]);
    $recordQuery = $conn->prepare('SELECT * FROM waste_records WHERE id = ? AND is_active = 1 LIMIT 1');
    $recordQuery->execute([$recordId]);
    $record = $recordQuery->fetch();
    return $record ? storeWasteDailyAllocations($conn, $record) : 0;
}

/** Remove derived rows before a source waste record is deleted. */
function removeWasteDailyAllocationsForRecord($conn, $recordId)
{
    ensureWasteDailyAllocationTable($conn);
    $recordId = (int)$recordId;
    if ($recordId <= 0) {
        return;
    }
    $stmt = $conn->prepare('DELETE FROM waste_daily_allocations WHERE waste_record_id = ?');
    $stmt->execute([$recordId]);
}

function storeWasteImportBatchDailyAllocations($conn, $batchId)
{
    $stmt = $conn->prepare('SELECT wr.* FROM waste_records wr INNER JOIN waste_import_staging_rows s ON s.record_fingerprint = wr.record_fingerprint WHERE s.batch_id = ? AND wr.is_active = 1 ORDER BY wr.id');
    $stmt->execute([$batchId]);
    $count = 0;
    foreach ($stmt->fetchAll() as $record) {
        $count += storeWasteDailyAllocations($conn, $record);
    }
    return $count;
}

function backfillWasteDailyAllocations($conn, $batchSize = 500)
{
    ensureWasteDailyAllocationTable($conn);
    $batchSize = max(1, (int)$batchSize);
    $stmt = $conn->prepare('SELECT wr.* FROM waste_records wr LEFT JOIN waste_daily_allocations da ON da.waste_record_id = wr.id WHERE da.waste_record_id IS NULL AND wr.is_active = 1 ORDER BY wr.id ASC LIMIT ' . $batchSize);
    $stmt->execute();
    $result = ['records' => 0, 'allocations' => 0, 'unparseable' => []];
    foreach ($stmt->fetchAll() as $record) {
        $result['records']++;
        $allocations = buildWasteDailyAllocations($record);
        if (empty($allocations)) {
            $result['unparseable'][] = (int)$record['id'];
            continue;
        }
        $result['allocations'] += storeWasteDailyAllocations($conn, $record);
    }
    return $result;
}

/**
 * Accept an optional calendar year supplied by a visualization filter.
 */
function normalizeWasteCollectionYear($year)
{
    $year = trim((string)$year);
    if (!preg_match('/^(19|20)\d{2}$/', $year)) {
        return '';
    }

    return $year;
}

/**
 * Resolve a requested reporting year against values actually represented by
 * active saved/imported waste data.  URL tampering must not create a phantom
 * calendar option in a visualisation control.
 */
function resolveImportedWasteCollectionYear($requestedYear, array $availableYears)
{
    $requestedYear = normalizeWasteCollectionYear($requestedYear);
    if ($requestedYear === '') {
        return '';
    }

    foreach ($availableYears as $year) {
        if ((string)$year === $requestedYear) {
            return $requestedYear;
        }
    }

    return '';
}

function normalizeWasteCollectionMonth($month)
{
    $month = filter_var($month, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 12]]);
    return $month === false ? '' : (string)$month;
}

/** Normalize an exact calendar period used by data-backed dashboard controls. */
function normalizeWasteCollectionPeriod($period)
{
    $period = trim((string)$period);
    return preg_match('/^(19|20)\d{2}-(0[1-9]|1[0-2])$/', $period) ? $period : '';
}

/** Build the selectable years and months from active records with stored dates. */
function buildActiveWasteCollectionPeriodOptions(array $periods)
{
    $validPeriods = [];
    foreach ($periods as $period) {
        $period = normalizeWasteCollectionPeriod($period);
        if ($period !== '') {
            $validPeriods[$period] = true;
        }
    }

    $validPeriods = array_keys($validPeriods);
    rsort($validPeriods, SORT_STRING);
    $monthsByYear = [];
    foreach ($validPeriods as $period) {
        $year = substr($period, 0, 4);
        $month = (int)substr($period, 5, 2);
        $monthsByYear[$year][$month] = true;
    }
    foreach ($monthsByYear as &$months) {
        $months = array_keys($months);
        rsort($months, SORT_NUMERIC);
    }
    unset($months);

    return [
        'periods' => $validPeriods,
        'years' => array_map('strval', array_keys($monthsByYear)),
        'months_by_year' => $monthsByYear,
    ];
}

/** Return exact YYYY-MM periods represented by active, stored waste records. */
function getActiveWasteCollectionPeriods($conn)
{
    if (!$conn) {
        return [];
    }

    ensureWasteAnalyticsColumns($conn);
    try {
        $stmt = $conn->query("\n            SELECT DISTINCT DATE_FORMAT(collection_date, '%Y-%m') AS collection_period\n            FROM waste_records\n            WHERE is_active = 1 AND collection_date IS NOT NULL\n            ORDER BY collection_period DESC\n        ");
        return buildActiveWasteCollectionPeriodOptions($stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (PDOException $e) {
        error_log('Failed to load active waste collection periods: ' . $e->getMessage());
        return ['periods' => [], 'years' => [], 'months_by_year' => []];
    }
}

/** Resolve a requested period to an actual period, optionally within one year. */
function resolveActiveWasteCollectionPeriod($requestedPeriod, $requestedYear, array $periodOptions)
{
    $periods = $periodOptions['periods'] ?? [];
    $requestedPeriod = normalizeWasteCollectionPeriod($requestedPeriod);
    $requestedYear = normalizeWasteCollectionYear($requestedYear);

    if ($requestedYear !== '') {
        if ($requestedPeriod !== '' && substr($requestedPeriod, 0, 4) === $requestedYear && in_array($requestedPeriod, $periods, true)) {
            return $requestedPeriod;
        }
        foreach ($periods as $period) {
            if (substr($period, 0, 4) === $requestedYear) {
                return $period;
            }
        }
    }

    if ($requestedPeriod !== '' && in_array($requestedPeriod, $periods, true)) {
        return $requestedPeriod;
    }

    return $periods[0] ?? '';
}

/** Return saved collection years, newest first, for shared controls. */
function getWasteCollectionYears($conn)
{
    ensureWasteAnalyticsColumns($conn);
    $stmt = $conn->query("\n        SELECT DISTINCT YEAR(collection_date) AS collection_year\n        FROM waste_records\n        WHERE collection_date IS NOT NULL AND is_active = 1\n        ORDER BY collection_year DESC\n    ");
    $years = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $year) {
        $year = normalizeWasteCollectionYear($year);
        if ($year !== '') {
            $years[] = $year;
        }
    }
    return $years;
}

/**
 * Return only calendar years represented by active imported waste data.
 * Daily allocation years are included for reporting periods spanning years.
 */
function getImportedWasteCalendarYears($conn)
{
    if (!$conn) {
        return [];
    }

    ensureWasteAnalyticsColumns($conn);
    ensureWasteDailyAllocationTable($conn);
    try {
        $stmt = $conn->query("SELECT DISTINCT collection_year FROM (
            SELECT YEAR(collection_date) AS collection_year
            FROM waste_records
            WHERE is_active = 1 AND collection_date IS NOT NULL
            UNION
            SELECT YEAR(da.allocation_date) AS collection_year
            FROM waste_daily_allocations da
            INNER JOIN waste_records wr ON wr.id = da.waste_record_id
            WHERE wr.is_active = 1 AND da.allocation_date IS NOT NULL
        ) imported_years
        WHERE collection_year IS NOT NULL
        ORDER BY collection_year DESC");
        $years = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $year) {
            $year = normalizeWasteCollectionYear($year);
            if ($year !== '') {
                $years[] = (int)$year;
            }
        }
        return $years;
    } catch (PDOException $e) {
        error_log('Failed to load imported waste calendar years: ' . $e->getMessage());
        return [];
    }
}

/** Return imported calendar years as HTML-safe string options, newest first. */
function getImportedWasteCollectionYearOptions($conn)
{
    return array_map('strval', getImportedWasteCalendarYears($conn));
}

/**
 * Return the real Collection Group labels that can be selected for active
 * Waste Data filters and manual entries.  Historic rows can use the legacy
 * phase columns, so they remain visible through the same fallback used by the
 * Waste Data page.
 */
function getActiveWasteCollectionGroups($conn)
{
    if (!$conn) {
        return [];
    }

    ensureWasteAnalyticsColumns($conn);
    try {
        $stmt = $conn->query('SELECT collection_group, phase_number, phase_date_label, date FROM waste_records WHERE is_active = 1');
        return buildActiveWasteCollectionGroups($stmt->fetchAll());
    } catch (PDOException $e) {
        error_log('Failed to load active waste collection groups: ' . $e->getMessage());
        return [];
    }
}

/** Build distinct saved Collection Group labels from active database rows. */
function buildActiveWasteCollectionGroups(array $records)
{
    $groups = [];
    foreach ($records as $record) {
        $group = trim((string)($record['collection_group'] ?? ''));
        if ($group === '') {
            foreach (['phase_number', 'phase_date_label', 'date'] as $column) {
                $group = wasteAnalyticsCollectionGroupFromLegacyLabel($record[$column] ?? '');
                if ($group !== '') {
                    break;
                }
            }
        }
        if ($group !== '') {
            $groups[$group] = true;
        }
    }
    $groups = array_keys($groups);
    natcasesort($groups);
    return array_values($groups);
}

/**
 * Extract the saved collection-group portion of the older import labels.
 *
 * This deliberately lives in the analytics module rather than relying on the
 * application bootstrap helper so analytics and its unit tests remain usable
 * on their own.
 */
function wasteAnalyticsCollectionGroupFromLegacyLabel($sourceLabel)
{
    $sourceLabel = trim((string)$sourceLabel);
    if ($sourceLabel === '') {
        return '';
    }

    if (preg_match('/^(.*?)\s*(?:[-–—]\s*)?date\s*:\s*(.+)$/iu', $sourceLabel, $matches)) {
        return trim($matches[1], " \t\n\r\0\x0B-–—");
    }

    if (preg_match('/^(.*?)\s*\((.+)\)\s*$/u', $sourceLabel, $matches) && trim($matches[2]) !== '') {
        return trim($matches[1]);
    }

    return $sourceLabel;
}

/** Return Waste Type filter values only for categories with saved positive data. */
function buildAvailableWasteTypeOptions(array $records)
{
    $available = [
        'factory returnable' => false,
        'biowaste' => false,
        'residual waste' => false,
        'hazardous waste' => false,
        'unclassified' => false,
    ];
    foreach ($records as $record) {
        $factoryDetail = wasteNumericValue($record['tuesday_factory_returnable_kg'] ?? 0) + wasteNumericValue($record['thursday_factory_returnable_kg'] ?? 0);
        $factoryReturnable = $factoryDetail > 0 ? $factoryDetail : wasteNumericValue($record['recyclable_kg'] ?? 0);
        $biowaste = wasteNumericValue($record['wednesday_biowaste_kg'] ?? 0) + wasteNumericValue($record['friday_biowaste_kg'] ?? 0);
        $residualDetail = wasteNumericValue($record['residual_waste_kg'] ?? 0);
        $residual = $residualDetail > 0 ? $residualDetail : wasteNumericValue($record['residual_kg'] ?? 0);
        $hazardousDetail = wasteNumericValue($record['saturday_hazard_waste_kg'] ?? 0);
        $hazardous = $hazardousDetail > 0 ? $hazardousDetail : wasteNumericValue($record['hazardous_kg'] ?? 0);
        $unclassified = wasteNumericValue($record['unclassified_waste_kg'] ?? 0);
        $available['factory returnable'] = $available['factory returnable'] || $factoryReturnable > 0;
        $available['biowaste'] = $available['biowaste'] || $biowaste > 0;
        $available['residual waste'] = $available['residual waste'] || $residual > 0;
        $available['hazardous waste'] = $available['hazardous waste'] || $hazardous > 0;
        $available['unclassified'] = $available['unclassified'] || $unclassified > 0;
    }

    $labels = [
        'factory returnable' => 'Factory Returnable',
        'biowaste' => 'Biowaste',
        'residual waste' => 'Residual Waste',
        'hazardous waste' => 'Hazardous Waste',
        'unclassified' => 'Unclassified Waste',
    ];
    return array_filter($labels, static function ($key) use ($available) {
        return $available[$key];
    }, ARRAY_FILTER_USE_KEY);
}

/** Apply a visualization's optional year without changing other views. */
function filterWasteRecordsByCollectionYear($records, $year)
{
    $year = normalizeWasteCollectionYear($year);
    if ($year === '') {
        return $records;
    }

    return array_values(array_filter($records, static function ($record) use ($year) {
        $collectionDate = normalizeWasteDateValue($record['collection_date'] ?? '');
        return $collectionDate !== null && substr($collectionDate, 0, 4) === $year;
    }));
}

function filterWasteRecordsByCollectionPeriod($records, $year = '', $month = '')
{
    $year = normalizeWasteCollectionYear($year);
    $month = normalizeWasteCollectionMonth($month);
    if ($year === '' && $month === '') {
        return $records;
    }
    return array_values(array_filter($records, static function ($record) use ($year, $month) {
        $date = normalizeWasteDateValue($record['collection_date'] ?? '');
        if ($date === null) {
            return false;
        }
        return ($year === '' || substr($date, 0, 4) === $year)
            && ($month === '' || (int)substr($date, 5, 2) === (int)$month);
    }));
}

function wasteRecordReturnableKg($record)
{
    $componentTotal = wasteNumericValue($record['tuesday_factory_returnable_kg'] ?? 0)
        + wasteNumericValue($record['thursday_factory_returnable_kg'] ?? 0);
    return $componentTotal > 0 ? $componentTotal : wasteNumericValue($record['recyclable_kg'] ?? 0);
}

function wasteRecordBiowasteKg($record)
{
    return wasteNumericValue($record['wednesday_biowaste_kg'] ?? 0)
        + wasteNumericValue($record['friday_biowaste_kg'] ?? 0);
}

function wasteRecordResidualKg($record)
{
    $componentTotal = wasteNumericValue($record['residual_waste_kg'] ?? 0);
    return $componentTotal > 0 ? $componentTotal : wasteNumericValue($record['residual_kg'] ?? 0);
}

function wasteRecordHazardousKg($record)
{
    $componentTotal = wasteNumericValue($record['saturday_hazard_waste_kg'] ?? 0);
    return $componentTotal > 0 ? $componentTotal : wasteNumericValue($record['hazardous_kg'] ?? 0);
}

function wasteRecordUnclassifiedKg($record)
{
    return wasteNumericValue($record['unclassified_waste_kg'] ?? 0);
}

function wasteRecordCollectorName($record)
{
    foreach (['name_of_bioman', 'garbage_collector'] as $column) {
        $value = trim((string)($record[$column] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }
    return 'Unassigned';
}

function buildWasteCategorySummary($records)
{
    $summary = [
        'total_waste' => 0.0,
        'diverted' => 0.0,
        'returnable' => 0.0,
        'biowaste' => 0.0,
        'residual' => 0.0,
        'hazardous' => 0.0,
        'unclassified' => 0.0,
        'total_records' => count($records),
        'active_collectors' => 0,
    ];
    $collectors = [];

    foreach ($records as $record) {
        $returnable = wasteRecordReturnableKg($record);
        $biowaste = wasteRecordBiowasteKg($record);
        $residual = wasteRecordResidualKg($record);
        $hazardous = wasteRecordHazardousKg($record);
        $unclassified = wasteRecordUnclassifiedKg($record);
        $summary['total_waste'] += wasteRecordEffectiveKg($record);
        $summary['returnable'] += $returnable;
        $summary['biowaste'] += $biowaste;
        $summary['diverted'] += $returnable + $biowaste;
        $summary['residual'] += $residual;
        $summary['hazardous'] += $hazardous;
        $summary['unclassified'] += $unclassified;

        $collector = wasteRecordCollectorName($record);
        if ($collector !== 'Unassigned') {
            $collectors[strtolower($collector)] = true;
        }
    }

    $summary['active_collectors'] = count($collectors);
    return $summary;
}

function buildWasteCollectorComparison($records, $limit = 5)
{
    $collectors = [];
    foreach ($records as $record) {
        $collector = wasteRecordCollectorName($record);
        if (!isset($collectors[$collector])) {
            $collectors[$collector] = ['garbage_collector' => $collector, 'total_waste' => 0.0];
        }
        $collectors[$collector]['total_waste'] += wasteRecordEffectiveKg($record);
    }

    $comparison = array_values($collectors);
    usort($comparison, static function ($left, $right) {
        $difference = (float)$right['total_waste'] <=> (float)$left['total_waste'];
        return $difference !== 0 ? $difference : strcasecmp($left['garbage_collector'], $right['garbage_collector']);
    });
    return array_slice($comparison, 0, max(0, (int)$limit));
}

function wasteRecordCollectionDate($record)
{
    foreach (['collection_date', 'reporting_period', 'date', 'phase_date_label', 'phase_number'] as $column) {
        $date = normalizeWasteDateValue($record[$column] ?? '');
        if ($date !== null) {
            return $date;
        }
    }
    return null;
}

/**
 * Return the label that identifies a record's uploaded reporting period.  The
 * worksheet's Phase / Date label is kept where possible so the dashboard can
 * show the same period staff see in the source file.
 */
function wasteRecordReportingPeriodLabel($record)
{
    foreach (['reporting_period', 'phase_date_label', 'date', 'phase_number'] as $column) {
        $value = trim((string)($record[$column] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }

    $collectionDate = wasteRecordCollectionDate($record);
    return $collectionDate !== null ? $collectionDate : 'Unassigned reporting period';
}

/** Prefer the reporting-label date over incomplete legacy fallback dates. */
function wasteRecordReportingPeriodDate($record)
{
    $periodSource = '';
    foreach (['reporting_period', 'phase_date_label', 'date', 'phase_number'] as $column) {
        $value = trim((string)($record[$column] ?? ''));
        if ($value === '') {
            continue;
        }

        if ($periodSource === '') {
            $periodSource = $value;
        }
        $parsedDate = normalizeWasteDateValue($value);
        if ($parsedDate !== null) {
            return $parsedDate;
        }
    }

    $storedDate = normalizeWasteDateValue($record['collection_date'] ?? '');
    if ($storedDate === null) {
        return null;
    }

    if (
        preg_match('/\bdate\s*:/i', $periodSource) &&
        preg_match('/\b((?:19|20)\d{2})\b/', $periodSource, $yearMatch) &&
        substr($storedDate, 0, 4) !== $yearMatch[1]
    ) {
        return null;
    }

    return $storedDate;
}

/** Build the highest-record Collection Group and Area comparison. */
function buildPhaseStreetRecordComparison($records, $limit = 6)
{
    $areas = [];

    foreach ($records as $record) {
        $phaseName = normalizeWastePhaseLabel($record);
        $totalWaste = wasteRecordEffectiveKg($record);
        $street = trim(preg_replace('/\s+/u', ' ', (string)($record['street'] ?? '')));
        $streetLabel = $street !== '' ? $street : 'Unassigned street';
        $areaKey = strtolower($phaseName . "\x1F" . $streetLabel);
        if (!isset($areas[$areaKey]) || $totalWaste > $areas[$areaKey]['total_waste']) {
            $areas[$areaKey] = [
                'phase_name' => $phaseName,
                'street' => $streetLabel,
                'period_label' => wasteRecordReportingPeriodLabel($record),
                'total_waste' => $totalWaste,
                'record_count' => 1,
            ];
        }
    }

    $comparison = array_values($areas);
    usort($comparison, static function ($left, $right) {
        $difference = (float)$right['total_waste'] <=> (float)$left['total_waste'];
        if ($difference !== 0) {
            return $difference;
        }
        return strcasecmp($left['phase_name'] . ' ' . $left['street'], $right['phase_name'] . ' ' . $right['street']);
    });

    return array_slice($comparison, 0, max(0, (int)$limit));
}

function getEcoTrackHeatmapLocations()
{
    return [
        ['id' => 'phase6-north', 'name' => 'Phase 6 - Acacia to Lauan', 'address' => 'Acacia, Almond, Apitong, Lauan, Tanguile', 'lat' => 14.7835, 'lng' => 121.0706],
        ['id' => 'phase6-south', 'name' => 'Phase 6 - Agoho to Yakal', 'address' => 'Agoho, Mahogany, Molave, Narra, Tindalo, Yakal', 'lat' => 14.7827, 'lng' => 121.0719],
        ['id' => 'phase1a-bell-einstein', 'name' => 'Phase 1A - Bell cor. Einstein', 'address' => 'Block 8 Lot 42, Bell cor. Einstein', 'lat' => 14.7767, 'lng' => 121.0683],
        ['id' => 'phase1a-wright-darwin', 'name' => 'Phase 1A - Wright cor. Darwin', 'address' => 'Block 11 Lot 36, Wright cor. Darwin', 'lat' => 14.7773, 'lng' => 121.0693],
        ['id' => 'phase5-samaria-main', 'name' => 'Phase 5 - Samaria cor. Main Road', 'address' => 'Block 14 Lot 38, Samaria cor. Main Road', 'lat' => 14.7812, 'lng' => 121.0704],
        ['id' => 'phase5-rhodes-samaria', 'name' => 'Phase 5 - Rhodes cor. Samaria', 'address' => 'Block 14 Lot 2, Rhodes cor. Samaria', 'lat' => 14.7806, 'lng' => 121.0714],
        ['id' => 'toyota-pleasant', 'name' => 'Toyota - Quirino Highway', 'address' => 'Quirino Highway cor. Pleasant Hills Road, SJDM, Bulacan', 'lat' => 14.7846, 'lng' => 121.0719],
        ['id' => 'jollibee-tungko', 'name' => 'Jollibee - Tungkong Mangga', 'address' => 'Quirino Highway, Tungkong Mangga, SJDM, Bulacan', 'lat' => 14.7890, 'lng' => 121.0747],
    ];
}

function heatmapLocationIdForWasteRecord($record)
{
    $phase = strtolower((string)($record['phase_number'] ?? ''));
    $street = strtolower((string)($record['street'] ?? ''));

    if (strpos($phase, 'phase 1') !== false && (
        strpos($street, 'almeda') !== false || strpos($street, 'bell') !== false ||
        strpos($street, 'curie') !== false || strpos($street, 'dalton') !== false ||
        strpos($street, 'darwin') !== false || strpos($street, 'edison') !== false ||
        strpos($street, 'einstein') !== false
    )) {
        return 'phase1a-bell-einstein';
    }
    if (strpos($phase, 'phase 1') !== false && (
        strpos($street, 'faraday') !== false || strpos($street, 'flores') !== false ||
        strpos($street, 'inventor') !== false || strpos($street, 'newton') !== false ||
        strpos($street, 'pascal') !== false || strpos($street, 'sampaguita') !== false ||
        strpos($street, 'wright') !== false
    )) {
        return 'phase1a-wright-darwin';
    }
    if (strpos($phase, 'phase 5') !== false && (
        strpos($street, 'aluminum') !== false || strpos($street, 'beryllium') !== false ||
        strpos($street, 'boron') !== false || strpos($street, 'carbon') !== false ||
        strpos($street, 'fluorine') !== false || strpos($street, 'helium') !== false ||
        strpos($street, 'hydrogen') !== false
    )) {
        return 'phase5-samaria-main';
    }
    if (strpos($phase, 'phase 5') !== false && (
        strpos($street, 'lithium') !== false || strpos($street, 'magnesium') !== false ||
        strpos($street, 'neon') !== false || strpos($street, 'nitrogen') !== false ||
        strpos($street, 'oxygen') !== false || strpos($street, 'silicon') !== false ||
        strpos($street, 'sodium') !== false
    )) {
        return 'phase5-rhodes-samaria';
    }
    if (strpos($phase, 'phase 6') !== false && (
        strpos($street, 'acacia') !== false || strpos($street, 'almond') !== false ||
        strpos($street, 'apitong') !== false || strpos($street, 'lauan') !== false ||
        strpos($street, 'tanguile') !== false ||
// Map former Phase 6 street labels to their current heat area.
        strpos($street, 'bell pepper') !== false || strpos($street, 'cabbage') !== false ||
        strpos($street, 'carrot') !== false || strpos($street, 'eggplant') !== false ||
        strpos($street, 'garlic') !== false || strpos($street, 'ginger') !== false ||
        strpos($street, 'lettuce') !== false
    )) {
        return 'phase6-north';
    }
    if (strpos($phase, 'phase 6') !== false && (
        strpos($street, 'agoho') !== false || strpos($street, 'mahogany') !== false ||
        strpos($street, 'molave') !== false || strpos($street, 'narra') !== false ||
        strpos($street, 'tindalo') !== false || strpos($street, 'yakal') !== false ||
        // Keep historical records from the former Phase 6 street labels
        // attached to the same visual heat area.
        strpos($street, 'okra') !== false || strpos($street, 'onion') !== false ||
        strpos($street, 'pechay') !== false || strpos($street, 'radish') !== false ||
        strpos($street, 'spinach') !== false || strpos($street, 'squash') !== false ||
        strpos($street, 'tomato') !== false
    )) {
        return 'phase6-south';
    }
    if (strpos($street, 'toyota') !== false || strpos($street, 'pleasant hills') !== false) {
        return 'toyota-pleasant';
    }
    if (strpos($street, 'jollibee') !== false || strpos($street, 'tungkong mangga') !== false) {
        return 'jollibee-tungko';
    }

    return null;
}

function calculateWasteBands($values)
{
    $positiveValues = [];
    foreach ($values as $value) {
        $value = wasteNumericValue($value);
        if ($value > 0) {
            $positiveValues[] = $value;
        }
    }
    sort($positiveValues, SORT_NUMERIC);
    $uniqueValues = array_values(array_unique(array_map(static function ($value) {
        return sprintf('%.10F', $value);
    }, $positiveValues)));
    $uniqueCount = count($uniqueValues);

    $bands = [
        'has_data' => !empty($positiveValues),
        'mode' => 'empty',
        'min' => 0.0,
        'max' => 0.0,
        'q1' => 0.0,
        'q2' => 0.0,
        'q3' => 0.0,
    ];
    if (empty($positiveValues)) {
        return $bands;
    }

    $bands['min'] = $positiveValues[0];
    $bands['max'] = $positiveValues[count($positiveValues) - 1];
    if ($uniqueCount === 1) {
        $bands['mode'] = 'homogeneous';
        $bands['q1'] = $bands['q2'] = $bands['q3'] = $bands['min'];
        return $bands;
    }

    if ($uniqueCount < 4) {
        $bands['mode'] = 'range';
        $width = ($bands['max'] - $bands['min']) / 4;
        $bands['q1'] = $bands['min'] + $width;
        $bands['q2'] = $bands['min'] + ($width * 2);
        $bands['q3'] = $bands['min'] + ($width * 3);
        return $bands;
    }

    $bands['mode'] = 'quartile';
    $count = count($positiveValues);
    foreach ([1 => 'q1', 2 => 'q2', 3 => 'q3'] as $quartile => $key) {
        $index = max(0, (int)ceil(($count * $quartile) / 4) - 1);
        $bands[$key] = $positiveValues[$index];
    }
    return $bands;
}

function classifyWasteTotal($totalWaste, $bands)
{
    $totalWaste = wasteNumericValue($totalWaste);
    if ($totalWaste <= 0 || empty($bands['has_data'])) {
        return ['label' => 'Low', 'color' => '#388e3c', 'color_name' => 'Green', 'slug' => 'low'];
    }
    if (($bands['mode'] ?? '') === 'homogeneous') {
        return ['label' => 'Medium-Low', 'color' => '#fbc02d', 'color_name' => 'Yellow', 'slug' => 'medium-low'];
    }
    if ($totalWaste <= $bands['q1']) {
        return ['label' => 'Low', 'color' => '#388e3c', 'color_name' => 'Green', 'slug' => 'low'];
    }
    if ($totalWaste <= $bands['q2']) {
        return ['label' => 'Medium-Low', 'color' => '#fbc02d', 'color_name' => 'Yellow', 'slug' => 'medium-low'];
    }
    if ($totalWaste <= $bands['q3']) {
        return ['label' => 'Medium-High', 'color' => '#f57c00', 'color_name' => 'Orange', 'slug' => 'medium-high'];
    }
    return ['label' => 'High', 'color' => '#d32f2f', 'color_name' => 'Red', 'slug' => 'high'];
}

function formatWasteKgRange($start, $end = null, $exclusiveStart = false)
{
    if ($end === null) {
        return number_format((float)$start, 2) . ' kg';
    }
    return ($exclusiveStart ? '>' : '') . number_format((float)$start, 2) . '-' . number_format((float)$end, 2) . ' kg';
}

function buildWasteLegend($bands)
{
    $legend = [
        ['label' => 'High', 'color' => '#d32f2f', 'color_name' => 'Red', 'range' => 'No values'],
        ['label' => 'Medium-High', 'color' => '#f57c00', 'color_name' => 'Orange', 'range' => 'No values'],
        ['label' => 'Medium-Low', 'color' => '#fbc02d', 'color_name' => 'Yellow', 'range' => 'No values'],
        ['label' => 'Low', 'color' => '#388e3c', 'color_name' => 'Green', 'range' => '0.00 kg'],
    ];
    if (empty($bands['has_data'])) {
        return $legend;
    }
    if (($bands['mode'] ?? '') === 'homogeneous') {
        $legend[2]['range'] = formatWasteKgRange($bands['min']);
        return $legend;
    }

    $legend[3]['range'] = formatWasteKgRange(0, $bands['q1']);
    $legend[2]['range'] = formatWasteKgRange($bands['q1'], $bands['q2'], true);
    $legend[1]['range'] = formatWasteKgRange($bands['q2'], $bands['q3'], true);
    $legend[0]['range'] = formatWasteKgRange($bands['q3'], $bands['max'], true);
    return $legend;
}

/**
 * One heatmap entry per named street/establishment, summed across its saved
 * records. Map-area IDs are only geographic references, never grouping keys.
 * Rows without a street cannot be attributed to an individual location.
 */
function buildHeatmapStreetLocations($records)
{
    $mapAreas = [];
    foreach (getEcoTrackHeatmapLocations() as $area) {
        $mapAreas[$area['id']] = $area;
    }
    $phaseKeys = ['Phase 1' => 'phase1a', 'Phase 1A' => 'phase1a', 'Phase 5' => 'phase5', 'Phase 6' => 'phase6'];
    $locations = [];
    $usedMapAreas = [];

    foreach ($records as $record) {
        $street = trim(preg_replace('/\s+/u', ' ', (string)($record['street'] ?? '')));
        if ($street === '') {
            continue;
        }

        $phaseName = preg_replace('/^Phase (\d+)-([A-Z])$/', 'Phase $1$2', normalizeWastePhaseLabel($record));
        $hasPhase = preg_match('/^Phase \d+[A-Z]?$/', $phaseName) === 1;
        $phaseKey = $phaseKeys[$phaseName] ?? null;
        $groupKey = strtolower(($hasPhase ? $phaseName : '') . "\x1F" . $street);

        if (!isset($locations[$groupKey])) {
            $mapAreaId = heatmapLocationIdForWasteRecord(['phase_number' => $phaseName, 'street' => $street]);
            // Do not inherit coordinates from a phase with a shared prefix.
            if ($mapAreaId !== null && strpos($mapAreaId, 'phase') === 0 && $phaseKey === null) {
                $mapAreaId = null;
            }
            $area = $mapAreas[$mapAreaId] ?? null;
            $locations[$groupKey] = [
                'id' => 'street-' . sha1($groupKey),
                'name' => $hasPhase ? $phaseName . ' - ' . $street : $street,
                'street' => $street,
                'phase_name' => $hasPhase ? $phaseName : '',
                'phase_key' => $phaseKey,
                'map_location_id' => $mapAreaId,
                'address' => $hasPhase ? $phaseName : 'Barangay San Manuel',
                'lat' => $area['lat'] ?? null,
                'lng' => $area['lng'] ?? null,
                'total_waste' => 0.0,
                'record_count' => 0,
            ];
            if ($mapAreaId !== null) {
                $usedMapAreas[$mapAreaId] = true;
            }
        }
        $locations[$groupKey]['total_waste'] += wasteRecordEffectiveKg($record);
        $locations[$groupKey]['record_count']++;
    }

    // Keep approved establishments available before their first import.
    foreach (['toyota-pleasant', 'jollibee-tungko'] as $mapAreaId) {
        if (isset($usedMapAreas[$mapAreaId])) {
            continue;
        }
        $area = $mapAreas[$mapAreaId];
        $locations['establishment-' . $mapAreaId] = array_merge($area, [
            'id' => 'establishment-' . $mapAreaId,
            'street' => $area['name'],
            'phase_name' => '',
            'phase_key' => null,
            'map_location_id' => $mapAreaId,
            'total_waste' => 0.0,
            'record_count' => 0,
        ]);
    }

    $locations = array_values($locations);
    $bands = calculateWasteBands(array_column($locations, 'total_waste'));
    foreach ($locations as &$location) {
        $location['waste_level'] = classifyWasteTotal($location['total_waste'], $bands);
    }
    unset($location);
    usort($locations, static function ($left, $right) {
        return strnatcasecmp($left['name'], $right['name']);
    });
    return $locations;
}

/** Build the source-date collection trend shared by Dashboard and Reports. */
function buildCollectionTrend($records, $limit = 0)
{
    $limit = max(0, (int)$limit);
    $periods = [];

    foreach ($records as $record) {
        $collectionDate = normalizeWasteDateValue($record['collection_date'] ?? '');
        if ($collectionDate === null) {
            // Rows without dates remain in totals but cannot appear in date trends.
            continue;
        }

        if (!isset($periods[$collectionDate])) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $collectionDate);
            $periods[$collectionDate] = [
                'collection_date' => $collectionDate,
// Retain the date key used by chart templates and explanations.
                'date' => $date ? $date->format('M j, Y') : $collectionDate,
                'total_waste' => 0.0,
                'phases' => [],
            ];
        }

        $periods[$collectionDate]['total_waste'] += wasteRecordEffectiveKg($record);
        $phaseName = normalizeWastePhaseLabel($record);
        if ($phaseName !== '') {
            $periods[$collectionDate]['phases'][$phaseName] = true;
        }
    }

    // ISO dates sort naturally; a positive limit selects the latest periods.
    if ($limit > 0) {
        krsort($periods, SORT_STRING);
        $periods = array_slice($periods, 0, $limit, true);
    }
    ksort($periods, SORT_STRING);

    foreach ($periods as &$period) {
        $phaseNames = array_keys($period['phases']);
        natcasesort($phaseNames);
        $period['phase_label'] = !empty($phaseNames) ? implode(', ', $phaseNames) : 'Unassigned phase';
        unset($period['phases']);
    }
    unset($period);

    return array_values($periods);
}

function buildWasteAnalyticsFromRecords($records)
{
    $heatmapLocations = getEcoTrackHeatmapLocations();
    $locationIndex = [];
    foreach ($heatmapLocations as $index => $location) {
        $heatmapLocations[$index]['total_waste'] = 0.0;
        $heatmapLocations[$index]['record_count'] = 0;
        $locationIndex[$location['id']] = $index;
    }

    $summary = ['total_waste' => 0.0, 'total_collected_waste' => 0.0, 'monthly_waste' => 0.0, 'total_records' => count($records)];
    $phaseGroups = [];
    $currentMonth = date('Y-m');

    foreach ($records as $record) {
        $totalWaste = wasteRecordEffectiveKg($record);
        $summary['total_waste'] += $totalWaste;
        $summary['total_collected_waste'] += $totalWaste;
        $collectionDate = normalizeWasteDateValue($record['collection_date'] ?? '');
        if ($collectionDate !== null && substr($collectionDate, 0, 7) === $currentMonth) {
            $summary['monthly_waste'] += $totalWaste;
        }

        $phaseName = normalizeWastePhaseLabel($record);
        if (!isset($phaseGroups[$phaseName])) {
            $phaseGroups[$phaseName] = ['phase_name' => $phaseName, 'total_waste' => 0.0, 'record_count' => 0, 'average_waste' => 0.0];
        }
        $phaseGroups[$phaseName]['total_waste'] += $totalWaste;
        $phaseGroups[$phaseName]['record_count']++;

        $locationId = heatmapLocationIdForWasteRecord($record);
        if ($locationId !== null && isset($locationIndex[$locationId])) {
            $locationIndexValue = $locationIndex[$locationId];
            $heatmapLocations[$locationIndexValue]['total_waste'] += $totalWaste;
            $heatmapLocations[$locationIndexValue]['record_count']++;
        }
    }

    $phaseTotals = array_values($phaseGroups);
    foreach ($phaseTotals as &$phase) {
        $phase['average_waste'] = $phase['record_count'] > 0 ? $phase['total_waste'] / $phase['record_count'] : 0.0;
    }
    unset($phase);
    usort($phaseTotals, static function ($left, $right) {
        $difference = $right['total_waste'] <=> $left['total_waste'];
        return $difference !== 0 ? $difference : strcasecmp($left['phase_name'], $right['phase_name']);
    });

    $mapBands = calculateWasteBands(array_column($heatmapLocations, 'total_waste'));
    foreach ($heatmapLocations as &$location) {
        $location['waste_level'] = classifyWasteTotal($location['total_waste'], $mapBands);
    }
    unset($location);

    $phaseBands = calculateWasteBands(array_column($phaseTotals, 'total_waste'));
    foreach ($phaseTotals as &$phase) {
        $phase['waste_level'] = classifyWasteTotal($phase['total_waste'], $phaseBands);
    }
    unset($phase);

    $streetLocations = buildHeatmapStreetLocations($records);
    $streetBands = calculateWasteBands(array_column($streetLocations, 'total_waste'));

    return [
        'summary' => $summary,
        'phase_totals' => $phaseTotals,
        'phase_bands' => $phaseBands,
        'heatmap_locations' => $heatmapLocations,
        'heatmap_bands' => $mapBands,
        'heatmap_legend' => buildWasteLegend($mapBands),
        'heatmap_street_locations' => $streetLocations,
        'heatmap_street_legend' => buildWasteLegend($streetBands),
    ];
}

/**
 * Sum one exact stored collection month from a supplied active record set.
 */
function buildWasteMonthlySummary(array $records, $selectedPeriod = '')
{
    $selectedPeriod = normalizeWasteCollectionPeriod($selectedPeriod);
    $summary = ['monthly_waste' => 0.0, 'total_records' => 0];

    if ($selectedPeriod === '') {
        return $summary;
    }

    foreach ($records as $record) {
        $collectionDate = normalizeWasteDateValue($record['collection_date'] ?? '');
        if ($collectionDate === null || substr($collectionDate, 0, 7) !== $selectedPeriod) {
            continue;
        }

        $summary['monthly_waste'] += wasteRecordEffectiveKg($record);
        $summary['total_records']++;
    }

    return $summary;
}

function fetchWasteAnalytics($conn, $whereSql = '', $params = [])
{
    ensureWasteAnalyticsColumns($conn);
    $whereSql = trim($whereSql);
    $whereSql = $whereSql === '' ? 'WHERE is_active = 1' : $whereSql . ' AND is_active = 1';
    $sql = 'SELECT * FROM waste_records ' . $whereSql;
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    return buildWasteAnalyticsFromRecords($stmt->fetchAll());
}

function normalizeHeatmapPeriodView($view)
{
    return in_array($view, ['week', 'month', 'year'], true) ? $view : 'week';
}

function heatmapPeriodBoundsForDate($view, $date)
{
    $view = normalizeHeatmapPeriodView($view);
    $date = $date instanceof DateTimeInterface ? DateTimeImmutable::createFromInterface($date) : new DateTimeImmutable($date);
    $year = (int)$date->format('Y');
    if ($view === 'year') {
        $start = new DateTimeImmutable(sprintf('%04d-01-01', $year));
        $end = new DateTimeImmutable(sprintf('%04d-12-31', $year));
        return ['view' => 'year', 'period' => (string)$year, 'start' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d'), 'label' => (string)$year];
    }
    if ($view === 'month') {
        $start = $date->modify('first day of this month');
        $end = $date->modify('last day of this month');
        return ['view' => 'month', 'period' => $start->format('Y-m'), 'start' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d'), 'label' => $start->format('F Y')];
    }

    $yearStart = new DateTimeImmutable(sprintf('%04d-01-01', $year));
    $week = intdiv((int)$yearStart->diff($date)->days, 7) + 1;
    $start = $yearStart->modify('+' . (($week - 1) * 7) . ' days');
    $yearEnd = new DateTimeImmutable(sprintf('%04d-12-31', $year));
    $end = $start->modify('+6 days');
    if ($end > $yearEnd) {
        $end = $yearEnd;
    }
    return [
        'view' => 'week',
        'period' => sprintf('%04d-W%02d', $year, $week),
        'start' => $start->format('Y-m-d'),
        'end' => $end->format('Y-m-d'),
        'label' => 'Week ' . $week . ' (' . $start->format('M j') . '–' . $end->format('M j, Y') . ')',
    ];
}

function heatmapPeriodBounds($view, $period)
{
    $view = normalizeHeatmapPeriodView($view);
    $period = trim((string)$period);
    if ($view === 'week' && preg_match('/^((?:19|20)\d{2})-W(\d{2})$/', $period, $matches)) {
        $year = (int)$matches[1];
        $week = (int)$matches[2];
        $yearStart = new DateTimeImmutable(sprintf('%04d-01-01', $year));
        $maxWeek = intdiv((int)$yearStart->format('z') + 365, 7) + 1;
        if ($week >= 1 && $week <= $maxWeek) {
            return heatmapPeriodBoundsForDate('week', $yearStart->modify('+' . (($week - 1) * 7) . ' days'));
        }
    }
    if ($view === 'month' && preg_match('/^((?:19|20)\d{2})-(0[1-9]|1[0-2])$/', $period)) {
        return heatmapPeriodBoundsForDate('month', $period . '-01');
    }
    if ($view === 'year' && preg_match('/^(?:19|20)\d{2}$/', $period)) {
        return heatmapPeriodBoundsForDate('year', $period . '-01-01');
    }
    return null;
}

function heatmapPreviousPeriodBounds($selection)
{
    $view = $selection['view'];
    $start = new DateTimeImmutable($selection['start']);
    if ($view === 'week') {
        // Weeks reset at Jan 1; prior-day lookups can enter the previous year.
        return heatmapPeriodBoundsForDate('week', $start->modify('-1 day'));
    }
    if ($view === 'month') {
        return heatmapPeriodBoundsForDate('month', $start->modify('-1 month'));
    }
    return heatmapPeriodBoundsForDate('year', $start->modify('-1 year'));
}

function resolveHeatmapPeriodSelection($dates, $view, $requestedPeriod = '', $allowEmptyRequestedPeriod = false)
{
    $view = normalizeHeatmapPeriodView($view);
    $options = [];
    foreach ($dates as $date) {
        $date = normalizeWasteDateValue($date);
        if ($date === null) {
            continue;
        }
        $bounds = heatmapPeriodBoundsForDate($view, $date);
        $options[$bounds['period']] = $bounds;
    }
    krsort($options, SORT_STRING);
    $requested = heatmapPeriodBounds($view, $requestedPeriod);
    if ($requested !== null && (isset($options[$requested['period']]) || $allowEmptyRequestedPeriod)) {
        $selection = $options[$requested['period']] ?? $requested;
    } elseif (!empty($options)) {
        $selection = reset($options);
    } else {
        $selection = heatmapPeriodBoundsForDate($view, date('Y-m-d'));
    }
    $selection['day_count'] = (new DateTimeImmutable($selection['start']))->diff(new DateTimeImmutable($selection['end']))->days + 1;
    $selection['previous'] = heatmapPreviousPeriodBounds($selection);
    $selection['previous']['day_count'] = (new DateTimeImmutable($selection['previous']['start']))->diff(new DateTimeImmutable($selection['previous']['end']))->days + 1;
    $selection['options'] = array_values($options);
    return $selection;
}

/** Accept calendar ranges even when no saved rows fall within them. */
function normalizeHeatmapDateInput($value)
{
    $value = trim((string)$value);
    if (!preg_match('/^(?:19|20)\d{2}-\d{2}-\d{2}$/', $value)) {
        return null;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || ($errors !== false && (($errors['warning_count'] ?? 0) || ($errors['error_count'] ?? 0))) || $date->format('Y-m-d') !== $value) {
        return null;
    }
    return $value;
}

function heatmapRangeLabel($start, $end)
{
    $startDate = new DateTimeImmutable($start);
    $endDate = new DateTimeImmutable($end);
    if ($start === $end) {
        return $startDate->format('M j, Y');
    }
    if ($startDate->format('Y') === $endDate->format('Y')) {
        return $startDate->format('M j') . '–' . $endDate->format('M j, Y');
    }
    return $startDate->format('M j, Y') . '–' . $endDate->format('M j, Y');
}

function resolveHeatmapDateRangeSelection($startValue, $endValue)
{
    $start = normalizeHeatmapDateInput($startValue);
    $end = normalizeHeatmapDateInput($endValue);
    if ($start === null || $end === null || $start > $end) {
        return null;
    }
    $dayCount = (new DateTimeImmutable($start))->diff(new DateTimeImmutable($end))->days + 1;
    $previousEnd = (new DateTimeImmutable($start))->modify('-1 day');
    $previousStart = $previousEnd->modify('-' . ($dayCount - 1) . ' days');
    $previous = [
        'view' => 'range',
        'period' => $previousStart->format('Y-m-d') . '..' . $previousEnd->format('Y-m-d'),
        'start' => $previousStart->format('Y-m-d'),
        'end' => $previousEnd->format('Y-m-d'),
        'label' => heatmapRangeLabel($previousStart->format('Y-m-d'), $previousEnd->format('Y-m-d')),
        'day_count' => $dayCount,
    ];
    return [
        'view' => 'range',
        'period' => $start . '..' . $end,
        'start' => $start,
        'end' => $end,
        'label' => heatmapRangeLabel($start, $end),
        'day_count' => $dayCount,
        'previous' => $previous,
        'options' => [],
    ];
}

function resolveDssWeekDateRangeSelection($startValue, $endValue)
{
    $selection = resolveHeatmapDateRangeSelection($startValue, $endValue);
    if ($selection === null) {
        return null;
    }

    $start = new DateTimeImmutable($selection['start']);
    return resolveHeatmapDateRangeSelection(
        $start->format('Y-m-d'),
        $start->modify('+6 days')->format('Y-m-d')
    );
}

function buildHeatmapLocationDescriptor($record)
{
    $street = trim(preg_replace('/\s+/u', ' ', (string)($record['street'] ?? '')));
    if ($street === '') {
        return null;
    }
    $phaseKeys = ['Phase 1' => 'phase1a', 'Phase 1A' => 'phase1a', 'Phase 5' => 'phase5', 'Phase 6' => 'phase6'];
    $phaseName = preg_replace('/^Phase (\d+)-([A-Z])$/', 'Phase $1$2', normalizeWastePhaseLabel($record));
    $hasPhase = preg_match('/^Phase \d+[A-Z]?$/', $phaseName) === 1;
    $phaseKey = $phaseKeys[$phaseName] ?? null;
    $groupKey = strtolower(($hasPhase ? $phaseName : '') . "\x1F" . $street);
    $mapAreaId = heatmapLocationIdForWasteRecord(['phase_number' => $phaseName, 'street' => $street]);
    if ($mapAreaId !== null && strpos($mapAreaId, 'phase') === 0 && $phaseKey === null) {
        $mapAreaId = null;
    }
    $mapAreas = [];
    foreach (getEcoTrackHeatmapLocations() as $area) {
        $mapAreas[$area['id']] = $area;
    }
    $area = $mapAreas[$mapAreaId] ?? [];
    return [
        'metric_key' => $groupKey,
        'id' => 'street-' . sha1($groupKey),
        'name' => $hasPhase ? $phaseName . ' - ' . $street : $street,
        'street' => $street,
        'phase_name' => $hasPhase ? $phaseName : '',
        'phase_key' => $phaseKey,
        'map_location_id' => $mapAreaId,
        'address' => $hasPhase ? $phaseName : 'Barangay San Manuel',
        'lat' => $area['lat'] ?? null,
        'lng' => $area['lng'] ?? null,
    ];
}

function buildHeatmapLocationCatalog($records)
{
    $catalog = [];
    $usedMapAreas = [];
    foreach ($records as $record) {
        $descriptor = buildHeatmapLocationDescriptor($record);
        if ($descriptor === null) {
            continue;
        }
        $catalog[$descriptor['metric_key']] = $descriptor;
        if ($descriptor['map_location_id'] !== null) {
            $usedMapAreas[$descriptor['map_location_id']] = true;
        }
    }
    foreach (getEcoTrackHeatmapLocations() as $area) {
        if (!in_array($area['id'], ['toyota-pleasant', 'jollibee-tungko'], true) || isset($usedMapAreas[$area['id']])) {
            continue;
        }
        $key = 'establishment-' . $area['id'];
        $catalog[$key] = array_merge($area, [
            'metric_key' => $key,
            'id' => $key,
            'street' => $area['name'],
            'phase_name' => '',
            'phase_key' => null,
            'map_location_id' => $area['id'],
        ]);
    }
    return $catalog;
}

function dssMatrixMultiplier($selection)
{
    $view = is_array($selection) ? ($selection['view'] ?? '') : $selection;
    if ($view === 'year') {
        return 360;
    }
    if ($view === 'month') {
        return 30;
    }
    return 7;
}

function buildDssWasteMatrix($selection)
{
    $multiplier = dssMatrixMultiplier($selection);
    $view = is_array($selection) ? ($selection['view'] ?? '') : $selection;
    $unit = $view === 'year'
        ? 'kg/year'
        : ($view === 'month' ? 'kg/month' : 'kg/week');

    return [
        'multiplier' => $multiplier,
        'unit' => $unit,
        'low_max' => 20 * $multiplier,
        'medium_low_max' => 40 * $multiplier,
        'medium_high_max' => 80 * $multiplier,
    ];
}

function classifyDssWasteTotal($totalWaste, $hasData, $selection)
{
    if (!$hasData) {
        // Preserve a non-display no-data value for API consumers.
        return ['label' => 'No data', 'color' => '#9e9e9e', 'color_name' => 'No data', 'slug' => 'no-data'];
    }
    $totalWaste = max(0, wasteNumericValue($totalWaste));
    $matrix = buildDssWasteMatrix($selection);
    if ($totalWaste <= $matrix['low_max']) {
        return ['label' => 'Low', 'color' => '#388e3c', 'color_name' => 'Green', 'slug' => 'low'];
    }
    if ($totalWaste <= $matrix['medium_low_max']) {
        return ['label' => 'Medium-Low', 'color' => '#fbc02d', 'color_name' => 'Yellow', 'slug' => 'medium-low'];
    }
    if ($totalWaste <= $matrix['medium_high_max']) {
        return ['label' => 'Medium-High', 'color' => '#f57c00', 'color_name' => 'Orange', 'slug' => 'medium-high'];
    }
    return ['label' => 'High', 'color' => '#d32f2f', 'color_name' => 'Red', 'slug' => 'high'];
}

function classifyDailyWasteAverage($dailyAverage, $hasData)
{
    if (!$hasData) {
        return ['label' => 'No data', 'color' => '#9e9e9e', 'color_name' => 'No data', 'slug' => 'no-data'];
    }
    $dailyAverage = max(0, wasteNumericValue($dailyAverage));
    if ($dailyAverage <= 20) {
        return ['label' => 'Low', 'color' => '#388e3c', 'color_name' => 'Green', 'slug' => 'low'];
    }
    if ($dailyAverage <= 40) {
        return ['label' => 'Medium-Low', 'color' => '#fbc02d', 'color_name' => 'Yellow', 'slug' => 'medium-low'];
    }
    if ($dailyAverage <= 80) {
        return ['label' => 'Medium-High', 'color' => '#f57c00', 'color_name' => 'Orange', 'slug' => 'medium-high'];
    }
    return ['label' => 'High', 'color' => '#d32f2f', 'color_name' => 'Red', 'slug' => 'high'];
}

function dssMatrixRange($start, $end, $unit, $exclusiveStart = false)
{
    if ($end === null) {
        return '>' . number_format((float)$start, 2) . ' ' . $unit;
    }
    return ($exclusiveStart ? '>' : '') . number_format((float)$start, 2) . '-' . number_format((float)$end, 2) . ' ' . $unit;
}

function buildDssWasteLegend($selection)
{
    $matrix = buildDssWasteMatrix($selection);
    return [
        ['label' => 'High', 'color' => '#d32f2f', 'color_name' => 'Red', 'range' => dssMatrixRange($matrix['medium_high_max'], null, $matrix['unit'])],
        ['label' => 'Medium-High', 'color' => '#f57c00', 'color_name' => 'Orange', 'range' => dssMatrixRange($matrix['medium_low_max'], $matrix['medium_high_max'], $matrix['unit'], true)],
        ['label' => 'Medium-Low', 'color' => '#fbc02d', 'color_name' => 'Yellow', 'range' => dssMatrixRange($matrix['low_max'], $matrix['medium_low_max'], $matrix['unit'], true)],
        ['label' => 'Low', 'color' => '#388e3c', 'color_name' => 'Green', 'range' => dssMatrixRange(0, $matrix['low_max'], $matrix['unit'])],
    ];
}

function buildHeatmapDssMetric($total, $recordIds, $dayCount, $previousTotal, $previousRecordIds, $previousDayCount)
{
    $hasData = !empty($recordIds);
    $hasPreviousData = !empty($previousRecordIds);
    $dailyAverage = $hasData ? round(wasteNumericValue($total) / max(1, $dayCount), 2) : null;
    $previousAverage = $hasPreviousData ? round(wasteNumericValue($previousTotal) / max(1, $previousDayCount), 2) : null;
    $classification = classifyDailyWasteAverage($dailyAverage, $hasData);
    $success = $hasData && $hasPreviousData && $classification['slug'] === 'low' && $dailyAverage < $previousAverage;
    $dssStatus = !$hasData ? 'no-data' : (!$hasPreviousData ? 'no-baseline' : ($success ? 'success' : ($dailyAverage >= $previousAverage ? 'not-reduced' : 'not-low')));
    return [
        'total_waste' => round(wasteNumericValue($total), 2),
        'record_count' => count($recordIds),
        'has_data' => $hasData,
        'daily_average_kg' => $dailyAverage,
        'previous_total_waste' => $hasPreviousData ? round(wasteNumericValue($previousTotal), 2) : null,
        'previous_daily_average_kg' => $previousAverage,
        'change_kg_per_day' => $hasPreviousData ? round($dailyAverage - $previousAverage, 2) : null,
        'classification' => $classification,
        'dss' => ['status' => $dssStatus, 'success' => $success],
        // DSS improvement remains text-only; map color always represents the
        // requested four-band waste classification.
        'waste_level' => $classification,
    ];
}

function buildDailyWasteLegend()
{
    return [
        ['label' => 'High', 'color' => '#d32f2f', 'color_name' => 'Red', 'range' => '>80.00 kg/day'],
        ['label' => 'Medium-High', 'color' => '#f57c00', 'color_name' => 'Orange', 'range' => '40.01–80.00 kg/day'],
        ['label' => 'Medium-Low', 'color' => '#fbc02d', 'color_name' => 'Yellow', 'range' => '20.01–40.00 kg/day'],
        ['label' => 'Low', 'color' => '#388e3c', 'color_name' => 'Green', 'range' => '0.00–20.00 kg/day'],
    ];
}

function buildHeatmapPeriodAnalytics($sourceRecords, $currentAllocations, $previousAllocations, $selection)
{
    $catalog = buildHeatmapLocationCatalog($sourceRecords);
    $current = [];
    $previous = [];
    $phaseCurrent = [];
    $phasePrevious = [];
    foreach ($catalog as $key => $location) {
        $current[$key] = ['total' => 0.0, 'records' => []];
        $previous[$key] = ['total' => 0.0, 'records' => []];
    }
    foreach (['current' => $currentAllocations, 'previous' => $previousAllocations] as $side => $allocations) {
        foreach ($allocations as $allocation) {
            $descriptor = buildHeatmapLocationDescriptor($allocation);
            if ($descriptor === null) {
                continue;
            }
            $key = $descriptor['metric_key'];
            if (!isset($catalog[$key])) {
                $catalog[$key] = $descriptor;
                $current[$key] = ['total' => 0.0, 'records' => []];
                $previous[$key] = ['total' => 0.0, 'records' => []];
            }
            if ($side === 'current') {
                $current[$key]['total'] += wasteNumericValue($allocation['kilogram_of_waste'] ?? 0);
                $current[$key]['records'][(int)($allocation['waste_record_id'] ?? $allocation['id'] ?? 0)] = true;
            } else {
                $previous[$key]['total'] += wasteNumericValue($allocation['kilogram_of_waste'] ?? 0);
                $previous[$key]['records'][(int)($allocation['waste_record_id'] ?? $allocation['id'] ?? 0)] = true;
            }
            $phaseKey = $descriptor['phase_key'];
            if ($phaseKey !== null) {
                if ($side === 'current') {
                    if (!isset($phaseCurrent[$phaseKey])) {
                        $phaseCurrent[$phaseKey] = ['total' => 0.0, 'records' => []];
                    }
                    $phaseCurrent[$phaseKey]['total'] += wasteNumericValue($allocation['kilogram_of_waste'] ?? 0);
                    $phaseCurrent[$phaseKey]['records'][(int)($allocation['waste_record_id'] ?? $allocation['id'] ?? 0)] = true;
                } else {
                    if (!isset($phasePrevious[$phaseKey])) {
                        $phasePrevious[$phaseKey] = ['total' => 0.0, 'records' => []];
                    }
                    $phasePrevious[$phaseKey]['total'] += wasteNumericValue($allocation['kilogram_of_waste'] ?? 0);
                    $phasePrevious[$phaseKey]['records'][(int)($allocation['waste_record_id'] ?? $allocation['id'] ?? 0)] = true;
                }
            }
        }
    }

    $locations = [];
    foreach ($catalog as $key => $location) {
        $metric = buildHeatmapDssMetric(
            $current[$key]['total'],
            $current[$key]['records'],
            $selection['day_count'],
            $previous[$key]['total'],
            $previous[$key]['records'],
            $selection['previous']['day_count'] ?? ((new DateTimeImmutable($selection['previous']['start']))->diff(new DateTimeImmutable($selection['previous']['end']))->days + 1)
        );
        $locations[] = array_merge($location, $metric);
    }
    usort($locations, static function ($left, $right) {
        return strnatcasecmp($left['name'], $right['name']);
    });

    $phaseMetrics = [];
    foreach (array_unique(array_merge(array_keys($phaseCurrent), array_keys($phasePrevious))) as $phaseKey) {
        $phaseMetrics[$phaseKey] = buildHeatmapDssMetric(
            $phaseCurrent[$phaseKey]['total'] ?? 0,
            $phaseCurrent[$phaseKey]['records'] ?? [],
            $selection['day_count'],
            $phasePrevious[$phaseKey]['total'] ?? 0,
            $phasePrevious[$phaseKey]['records'] ?? [],
            $selection['previous']['day_count'] ?? 1
        );
    }
    return [
        'locations' => $locations,
        'legend' => buildDailyWasteLegend(),
        'selection' => $selection,
        'phase_metrics' => $phaseMetrics,
        'summary' => [
            'total_waste' => round(array_sum(array_column($locations, 'total_waste')), 2),
            'location_count' => count($locations),
        ],
    ];
}

function fetchHeatmapPeriodAnalytics($conn, $view, $period = '', $startDate = '', $endDate = '', $allowEmptyRequestedPeriod = false)
{
    ensureWasteAnalyticsColumns($conn);
    ensureWasteDailyAllocationTable($conn);
    $dates = $conn->query('SELECT DISTINCT da.allocation_date FROM waste_daily_allocations da INNER JOIN waste_records wr ON wr.id = da.waste_record_id WHERE wr.is_active = 1 ORDER BY da.allocation_date')->fetchAll(PDO::FETCH_COLUMN);
    $selection = resolveHeatmapDateRangeSelection($startDate, $endDate) ?: resolveHeatmapPeriodSelection($dates, $view, $period, $allowEmptyRequestedPeriod);
    $sourceRecords = $conn->query("SELECT * FROM waste_records WHERE is_active = 1 AND street IS NOT NULL AND TRIM(street) <> ''")->fetchAll();
    $currentStmt = $conn->prepare('SELECT wr.*, da.waste_record_id, da.allocation_date, da.kilogram_of_waste FROM waste_daily_allocations da INNER JOIN waste_records wr ON wr.id = da.waste_record_id WHERE wr.is_active = 1 AND da.allocation_date BETWEEN ? AND ?');
    $currentStmt->execute([$selection['start'], $selection['end']]);
    $previousStmt = $conn->prepare('SELECT wr.*, da.waste_record_id, da.allocation_date, da.kilogram_of_waste FROM waste_daily_allocations da INNER JOIN waste_records wr ON wr.id = da.waste_record_id WHERE wr.is_active = 1 AND da.allocation_date BETWEEN ? AND ?');
    $previousStmt->execute([$selection['previous']['start'], $selection['previous']['end']]);
    return buildHeatmapPeriodAnalytics($sourceRecords, $currentStmt->fetchAll(), $previousStmt->fetchAll(), $selection);
}

function buildDssPeriodAnalytics($allocations, $selection)
{
    $phases = [];
    $recordIds = [];
    foreach ($allocations as $allocation) {
        $phaseName = normalizeWastePhaseLabel($allocation);
        if ($phaseName === '') {
            $phaseName = 'Unassigned phase';
        }
        $key = strtolower($phaseName);
        if (!isset($phases[$key])) {
            $phases[$key] = [
                'phase_name' => $phaseName,
                'total_waste' => 0.0,
                'record_ids' => [],
            ];
        }
        $recordId = (int)($allocation['waste_record_id'] ?? $allocation['id'] ?? 0);
        $phases[$key]['total_waste'] += wasteNumericValue($allocation['kilogram_of_waste'] ?? 0);
        if ($recordId > 0) {
            $phases[$key]['record_ids'][$recordId] = true;
            $recordIds[$recordId] = true;
        }
    }

    $phaseTotals = array_values($phases);
    foreach ($phaseTotals as &$phase) {
        $phase['total_waste'] = round($phase['total_waste'], 2);
        $phase['record_count'] = count($phase['record_ids']);
        $phase['average_waste'] = $phase['record_count'] > 0
            ? round($phase['total_waste'] / $phase['record_count'], 2)
            : 0.0;
        $phase['waste_level'] = classifyDssWasteTotal(
            $phase['total_waste'],
            $phase['record_count'] > 0,
            $selection
        );
        unset($phase['record_ids']);
    }
    unset($phase);

    usort($phaseTotals, static function ($left, $right) {
        $difference = $right['total_waste'] <=> $left['total_waste'];
        return $difference !== 0 ? $difference : strcasecmp($left['phase_name'], $right['phase_name']);
    });

    return [
        'phase_totals' => $phaseTotals,
        'legend' => buildDssWasteLegend($selection),
        'selection' => $selection,
        'summary' => [
            'total_waste' => round(array_sum(array_column($phaseTotals, 'total_waste')), 2),
            'record_count' => count($recordIds),
        ],
    ];
}

function fetchDssPeriodAnalytics($conn, $view, $period = '', $startDate = '', $endDate = '', $collectionGroup = '', $allowEmptyRequestedPeriod = false)
{
    ensureWasteAnalyticsColumns($conn);
    ensureWasteDailyAllocationTable($conn);

    $collectionGroup = trim((string)$collectionGroup);
    $where = ['wr.is_active = 1'];
    $params = [];
    if ($collectionGroup !== '') {
        $where[] = 'wr.collection_group = ?';
        $params[] = $collectionGroup;
    }
    $whereSql = implode(' AND ', $where);

    $datesStmt = $conn->prepare('SELECT DISTINCT da.allocation_date FROM waste_daily_allocations da INNER JOIN waste_records wr ON wr.id = da.waste_record_id WHERE ' . $whereSql . ' ORDER BY da.allocation_date');
    $datesStmt->execute($params);
    $dates = $datesStmt->fetchAll(PDO::FETCH_COLUMN);
    $selection = resolveDssWeekDateRangeSelection($startDate, $endDate)
        ?: resolveHeatmapPeriodSelection($dates, $view, $period, $allowEmptyRequestedPeriod);

    $allocationStmt = $conn->prepare(
        'SELECT wr.*, da.waste_record_id, da.allocation_date, da.kilogram_of_waste '
        . 'FROM waste_daily_allocations da INNER JOIN waste_records wr ON wr.id = da.waste_record_id '
        . 'WHERE ' . $whereSql . ' AND da.allocation_date BETWEEN ? AND ?'
    );
    $allocationStmt->execute(array_merge($params, [$selection['start'], $selection['end']]));

    return buildDssPeriodAnalytics($allocationStmt->fetchAll(), $selection);
}

function buildDssRecommendations($phaseTotals, $phaseBands, $limit = 3)
{
    $nextMonday = new DateTimeImmutable('monday next week');
    $recommendations = [];
    foreach ($phaseTotals as $phase) {
        if (wasteNumericValue($phase['total_waste'] ?? 0) <= 0) {
            continue;
        }

        $level = $phase['waste_level'] ?? classifyWasteTotal($phase['total_waste'], $phaseBands);
        if ($level['label'] === 'High') {
            $dayOffsets = [0, 2, 4];
        } elseif ($level['label'] === 'Medium-High') {
            $dayOffsets = [1, 4];
        } else {
            $dayOffsets = [3];
        }

        $days = [];
        foreach ($dayOffsets as $offset) {
            $days[] = $nextMonday->modify('+' . $offset . ' days')->format('l, M j');
        }

        $phase['rank'] = count($recommendations) + 1;
        $phase['frequency'] = count($dayOffsets);
        $phase['suggested_days'] = $days;
        $phase['reason'] = $phase['rank'] === 1
            ? 'Highest recorded waste total among the analyzed areas/phases.'
            : 'Ranks #' . $phase['rank'] . ' by recorded waste total in the analyzed data.';
        $recommendations[] = $phase;
        if (count($recommendations) >= $limit) {
            break;
        }
    }

    return $recommendations;
}

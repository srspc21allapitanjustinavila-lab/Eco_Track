<?php
/** Shared weekly trend data and rendering for Dashboard and Reports. */

require_once __DIR__ . '/waste_analytics.php';

function ensureWeeklyWasteTrendColumns($conn)
{
    static $checked = false;
    if (!$conn || $checked) {
        return;
    }

    ensureWasteAnalyticsColumns($conn);

    try {
        $columns = $conn->query('SHOW COLUMNS FROM waste_records')->fetchAll(PDO::FETCH_ASSOC);
        $existingColumns = [];
        foreach ($columns as $column) {
            $existingColumns[$column['Field']] = true;
        }

        if (!isset($existingColumns['is_active'])) {
            // Existing rows receive the active default after the schema upgrade.
            $conn->exec('ALTER TABLE waste_records ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER collection_date');
        }

        $indexes = $conn->query('SHOW INDEX FROM waste_records')->fetchAll(PDO::FETCH_ASSOC);
        $hasActiveDateIndex = false;
        foreach ($indexes as $index) {
            if (($index['Key_name'] ?? '') === 'idx_waste_records_active_collection_date') {
                $hasActiveDateIndex = true;
                break;
            }
        }
        if (!$hasActiveDateIndex) {
            $conn->exec('CREATE INDEX idx_waste_records_active_collection_date ON waste_records (is_active, collection_date)');
        }

        $checked = true;
    } catch (PDOException $e) {
        error_log('Failed to ensure weekly waste trend columns: ' . $e->getMessage());
    }
}

/** Return the active-record source used by both visualizations. */
function fetchActiveWeeklyWasteTrendRecords($conn)
{
    ensureWeeklyWasteTrendColumns($conn);
    $stmt = $conn->prepare('
        SELECT *
        FROM waste_records
        WHERE is_active = 1
          AND collection_date IS NOT NULL
        ORDER BY collection_date ASC, id ASC
    ');
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function weeklyWasteTrendRecordDate($record)
{
    // Weekly trends use the actual Collection Date only.
    return normalizeWasteDateValue($record['collection_date'] ?? '');
}

function weeklyWasteTrendIsActiveRecord($record)
{
    return (int)($record['is_active'] ?? 1) === 1;
}

function weeklyWasteTrendLocationDescriptor($record)
{
    $street = trim((string)($record['street'] ?? ''));
    $street = preg_replace('/\s+/u', ' ', $street);
    $group = trim(normalizeWastePhaseLabel($record));
    $fallbackGroup = $street === '' ? '' : 'Area - ' . $street;

    if ($street === '') {
        $label = $group !== '' ? $group : 'Unassigned location';
    } elseif ($group === '' || strcasecmp($group, $fallbackGroup) === 0) {
        $label = $street;
    } else {
        $label = $group . ' - ' . $street;
    }

    $groupKey = 'group-' . sha1(strtolower(trim($group)));
    $keySource = strtolower(trim($group)) . "\x1F" . strtolower($street);
    return [
        'key' => 'location-' . sha1($keySource),
        'label' => $label,
        'group_key' => $groupKey,
        'group_label' => $group,
        'is_groupable' => $group !== '' && strcasecmp($group, $fallbackGroup) !== 0,
    ];
}

function buildWeeklyWasteTrendLocationOptions($records)
{
    $options = [];
    foreach ($records as $record) {
        if (!weeklyWasteTrendIsActiveRecord($record) || weeklyWasteTrendRecordDate($record) === null) {
            continue;
        }
        $location = weeklyWasteTrendLocationDescriptor($record);
        if ($location['is_groupable']) {
            $options[$location['group_key']] = [
                'key' => $location['group_key'],
                'label' => $location['group_label'] . ' (all locations)',
            ];
        }
        $options[$location['key']] = $location;
    }

    uasort($options, static function ($left, $right) {
        return strnatcasecmp($left['label'], $right['label']);
    });
    return $options;
}

function weeklyWasteTrendMonthName($month)
{
    $month = (int)$month;
    if ($month < 1 || $month > 12) {
        return '';
    }
    return DateTimeImmutable::createFromFormat('!m', (string)$month)->format('F');
}

function weeklyWasteTrendRecordsForPeriod($records, $year, $month)
{
    $periodRecords = [];
    foreach ($records as $record) {
        if (!weeklyWasteTrendIsActiveRecord($record)) {
            continue;
        }
        $date = weeklyWasteTrendRecordDate($record);
        if ($date === null || (int)substr($date, 0, 4) !== (int)$year || (int)substr($date, 5, 2) !== (int)$month) {
            continue;
        }
        $periodRecords[] = $record;
    }
    return $periodRecords;
}

/**
 * Resolves only the trend query parameters. Other page filters stay intact.
 */
function resolveWeeklyWasteTrendFilters($records, $request)
{
    $datedRecords = [];
    $years = [];
    $latestDate = null;
    foreach ($records as $record) {
        if (!weeklyWasteTrendIsActiveRecord($record)) {
            continue;
        }
        $date = weeklyWasteTrendRecordDate($record);
        if ($date === null) {
            continue;
        }
        $datedRecords[] = $record;
        $years[(int)substr($date, 0, 4)] = true;
        if ($latestDate === null || $date > $latestDate) {
            $latestDate = $date;
        }
    }

    // Trend selectors may only expose years represented by active saved data.
    $yearOptions = array_map('strval', array_keys($years));
    rsort($yearOptions, SORT_NUMERIC);
    $latestYear = $latestDate !== null ? (int)substr($latestDate, 0, 4) : (int)date('Y');
    // Retain trend_period for bookmarked URLs; the control is year-only.
    $requestedPeriod = trim((string)($request['trend_period'] ?? ''));
    $periodYear = '';
    if (preg_match('/^((?:19|20)\d{2})-(0[1-9]|1[0-2])$/', $requestedPeriod, $periodMatches)) {
        $periodYear = $periodMatches[1];
    }
    $requestedYear = resolveImportedWasteCollectionYear($periodYear !== '' ? $periodYear : ($request['trend_year'] ?? ''), $yearOptions);
    $year = $requestedYear !== '' ? (int)$requestedYear : $latestYear;

    $view = ($request['trend_view'] ?? '') === 'week' ? 'week' : 'month';

    $locationOptions = buildWeeklyWasteTrendLocationOptions($datedRecords);
    $requestedLocation = trim((string)($request['trend_location'] ?? ''));
    $location = isset($locationOptions[$requestedLocation]) ? $requestedLocation : '';

    return [
        'view' => $view,
        'year' => $year,
        'location' => $location,
        'location_label' => $location !== '' ? $locationOptions[$location]['label'] : 'All streets / locations',
        'year_options' => $yearOptions,
        'location_options' => $locationOptions,
    ];
}

function weeklyWasteTrendWeekBuckets($year, $month)
{
    $periodStart = new DateTimeImmutable(sprintf('%04d-%02d-01', (int)$year, (int)$month));
    $lastDay = (int)$periodStart->format('t');
    $buckets = [];
    for ($week = 1; $week <= 5; $week++) {
        $startDay = (($week - 1) * 7) + 1;
        $endDay = min($week * 7, $lastDay);
        if ($startDay > $lastDay) {
            // Keep five chart positions for every month, including empty Week 5 slots.
            $buckets[] = [
                'week' => $week,
                'label' => 'Week ' . $week,
                'start' => '',
                'end' => '',
                'range' => 'No calendar days',
                'total_waste' => 0.0,
                'event_count' => 0,
                'records' => [],
                'phase_label' => 'No collection',
            ];
            continue;
        }
        $start = $periodStart->setDate((int)$year, (int)$month, $startDay);
        $end = $periodStart->setDate((int)$year, (int)$month, $endDay);
        $buckets[] = [
            'week' => $week,
            'label' => 'Week ' . $week,
            'start' => $start->format('Y-m-d'),
            'end' => $end->format('Y-m-d'),
            'range' => $start->format('M j') . '–' . $end->format('j'),
            'total_waste' => 0.0,
            'event_count' => 0,
            'records' => [],
            'phase_label' => 'No collection',
        ];
    }
    return $buckets;
}

function describeWeeklyWasteTrend($weeks, $view, $language = 'en')
{
    $isTagalog = $language === 'tl';
    if (empty($weeks)) {
        return $isTagalog
            ? 'Walang active waste records para sa napiling period.'
            : 'No active waste records are available for this selected period.';
    }

    if ($view === 'week') {
        $week = $weeks[0];
        if ((int)$week['event_count'] === 0) {
            return $isTagalog
                ? 'Walang naitalang waste collection event sa ' . $week['label'] . '.'
                : $week['label'] . ' has no recorded waste collection events.';
        }
        return $isTagalog
            ? 'Ang ' . $week['label'] . ' ay may ' . number_format((float)$week['total_waste'], 2) . ' kg mula sa ' . (int)$week['event_count'] . ' collection event' . ((int)$week['event_count'] === 1 ? '.' : 's.')
            : $week['label'] . ' recorded ' . number_format((float)$week['total_waste'], 2) . ' kg across ' . (int)$week['event_count'] . ' collection event' . ((int)$week['event_count'] === 1 ? '.' : 's.');
    }

    $hasWaste = false;
    foreach ($weeks as $week) {
        if ((float)$week['total_waste'] > 0) {
            $hasWaste = true;
            break;
        }
    }
    if (!$hasWaste) {
        return $isTagalog
            ? 'Walang naitalang waste sa limang weekly period ng napiling buwan.'
            : 'No waste was recorded across the five weekly periods in this selected month.';
    }

    $movements = [];
    for ($index = 1; $index < count($weeks); $index++) {
        $previous = (float)$weeks[$index - 1]['total_waste'];
        $current = (float)$weeks[$index]['total_waste'];
        if ($current > $previous) {
            $movement = $isTagalog ? 'Tumaas' : 'Waste increased';
        } elseif ($current < $previous) {
            $movement = $isTagalog ? 'Bumaba' : 'Waste decreased';
        } else {
            $movement = $isTagalog ? 'Nanatiling pareho ang waste' : 'Waste remained unchanged';
        }
        $movements[] = $isTagalog
            ? $movement . ' mula ' . $weeks[$index - 1]['label'] . ' hanggang ' . $weeks[$index]['label']
            : $movement . ' from ' . $weeks[$index - 1]['label'] . ' to ' . $weeks[$index]['label'];
    }

    $highest = $weeks[0];
    $lowest = $weeks[0];
    foreach ($weeks as $week) {
        if ((float)$week['total_waste'] > (float)$highest['total_waste']) {
            $highest = $week;
        }
        if ((float)$week['total_waste'] < (float)$lowest['total_waste']) {
            $lowest = $week;
        }
    }

    if ($isTagalog) {
        return implode(', ', $movements) . '. Pinakamataas ang naitalang waste sa ' . $highest['label'] . ' (' . number_format((float)$highest['total_waste'], 2) . ' kg) at pinakamababa sa ' . $lowest['label'] . ' (' . number_format((float)$lowest['total_waste'], 2) . ' kg).';
    }
    return implode(', ', $movements) . '. It reached its highest level in ' . $highest['label'] . ' (' . number_format((float)$highest['total_waste'], 2) . ' kg) and its lowest level in ' . $lowest['label'] . ' (' . number_format((float)$lowest['total_waste'], 2) . ' kg).';
}

/** Build shared weekly visualization data after records are fetched. */
function yearlyWasteTrendBuckets($year, $view)
{
    $year = (int)$year;
    $yearStart = new DateTimeImmutable(sprintf('%04d-01-01', $year));
    $yearEnd = $yearStart->setDate($year, 12, 31);
    $buckets = [];

    if ($view === 'week') {
        $start = $yearStart;
        $week = 1;
        while ($start <= $yearEnd) {
            $end = $start->modify('+6 days');
            if ($end > $yearEnd) {
                $end = $yearEnd;
            }
            $buckets[] = [
                'period' => $week,
                'label' => 'Week ' . $week,
                'start' => $start->format('Y-m-d'),
                'end' => $end->format('Y-m-d'),
                'range' => $start->format('M j') . '–' . $end->format('M j'),
                'total_waste' => 0.0,
                'event_count' => 0,
                'records' => [],
                'phase_label' => 'No collection',
            ];
            $start = $start->modify('+7 days');
            $week++;
        }
        return $buckets;
    }

    for ($month = 1; $month <= 12; $month++) {
        $start = $yearStart->setDate($year, $month, 1);
        $end = $start->modify('last day of this month');
        $buckets[] = [
            'period' => $month,
            'label' => $start->format('M'),
            'start' => $start->format('Y-m-d'),
            'end' => $end->format('Y-m-d'),
            'range' => $start->format('M j') . '–' . $end->format('j'),
            'total_waste' => 0.0,
            'event_count' => 0,
            'records' => [],
            'phase_label' => 'No collection',
        ];
    }
    return $buckets;
}

function buildWeeklyWasteTrendAnalysis($records, $filters)
{
    $view = ($filters['view'] ?? 'month') === 'week' ? 'week' : 'month';
    $year = (int)$filters['year'];
    $buckets = yearlyWasteTrendBuckets($year, $view);
    $bucketIndexes = [];
    foreach ($buckets as $index => $bucket) {
        $bucketIndexes[(int)$bucket['period']] = $index;
    }
    $yearStart = new DateTimeImmutable(sprintf('%04d-01-01', $year));

    foreach ($records as $record) {
        if (!weeklyWasteTrendIsActiveRecord($record)) {
            continue;
        }
        $date = weeklyWasteTrendRecordDate($record);
        if ($date === null || (int)substr($date, 0, 4) !== $year) {
            continue;
        }
        $location = weeklyWasteTrendLocationDescriptor($record);
        if (($filters['location'] ?? '') !== '' && $location['key'] !== $filters['location'] && $location['group_key'] !== $filters['location']) {
            continue;
        }

        if ($view === 'week') {
            $recordDate = new DateTimeImmutable($date);
            $period = intdiv($yearStart->diff($recordDate)->days, 7) + 1;
        } else {
            $period = (int)substr($date, 5, 2);
        }
        if (!isset($bucketIndexes[$period])) {
            continue;
        }
        $bucketIndex = $bucketIndexes[$period];
        $buckets[$bucketIndex]['total_waste'] += wasteRecordEffectiveKg($record);
        $buckets[$bucketIndex]['event_count']++;
        $buckets[$bucketIndex]['records'][] = $record;
    }

    foreach ($buckets as &$bucket) {
        $phaseNames = [];
        foreach ($bucket['records'] as $record) {
            $phaseName = trim(normalizeWastePhaseLabel($record));
            if ($phaseName !== '') {
                $phaseNames[$phaseName] = true;
            }
        }
        $phaseNames = array_keys($phaseNames);
        natcasesort($phaseNames);
        $bucket['phase_label'] = !empty($phaseNames) ? implode(', ', $phaseNames) : 'No collection';
    }
    unset($bucket);

    $totalWaste = 0.0;
    $eventCount = 0;
    foreach ($buckets as $bucket) {
        $totalWaste += (float)$bucket['total_waste'];
        $eventCount += (int)$bucket['event_count'];
    }
    $highestPeriod = $buckets[0];
    $lowestPeriod = $buckets[0];
    foreach ($buckets as $bucket) {
        if ((float)$bucket['total_waste'] > (float)$highestPeriod['total_waste']) {
            $highestPeriod = $bucket;
        }
        if ((float)$bucket['total_waste'] < (float)$lowestPeriod['total_waste']) {
            $lowestPeriod = $bucket;
        }
    }

    return [
        'filters' => $filters,
        'weeks' => $buckets,
        'all_weeks' => $buckets,
        'total_waste' => $totalWaste,
        'event_count' => $eventCount,
        'highest_week' => $highestPeriod,
        'lowest_week' => $lowestPeriod,
        'average_weekly_waste' => !empty($buckets) ? $totalWaste / count($buckets) : 0.0,
        'description' => describeWeeklyWasteTrend($buckets, $view),
        'description_tl' => describeWeeklyWasteTrend($buckets, $view, 'tl'),
    ];
}

function weeklyWasteTrendPreservedParams($request)
{
    $preserved = [];
    foreach ($request as $name => $value) {
        if (strpos((string)$name, 'trend_') === 0 || $name === 'export' || is_array($value)) {
            continue;
        }
        $preserved[$name] = (string)$value;
    }
    return $preserved;
}

function renderWeeklyWasteTrend($trend, $options = [])
{
    $filters = $trend['filters'];
    $action = $options['action'] ?? '';
    $title = $options['title'] ?? 'Waste Collection Trend';
    $headerActions = $options['header_actions'] ?? '';
    $preservedParams = $options['preserved_params'] ?? [];
    $periodControlId = $options['period_control_id'] ?? 'weeklyTrendYear';
    $isWeekView = ($filters['view'] ?? 'month') === 'week';
    $maxTrendWaste = 0.0;
    foreach ($trend['weeks'] as $week) {
        $maxTrendWaste = max($maxTrendWaste, (float)$week['total_waste']);
    }
    $chartPalette = ['#2E7D32', '#8B5A2B', '#6B7280', '#DC2626', '#0F766E'];
    ?>
    <section class="viz-card" id="waste-trend" data-visualization-key="trend">
        <div class="viz-card-header">
            <h2><?php echo htmlspecialchars($title); ?></h2>
            <div class="viz-card-header-actions">
                <?php echo $headerActions; ?>
                <form method="get" action="<?php echo htmlspecialchars($action); ?>" class="visualization-year-control visualization-filter-control trend-period-control">
                    <?php foreach ($preservedParams as $name => $value): ?>
                        <input type="hidden" name="<?php echo htmlspecialchars($name); ?>" value="<?php echo htmlspecialchars($value); ?>">
                    <?php endforeach; ?>
                    <label for="<?php echo htmlspecialchars($periodControlId); ?>-view" class="sr-only">Trend view</label>
                    <select id="<?php echo htmlspecialchars($periodControlId); ?>-view" name="trend_view" class="trend-view-select" onchange="this.form.requestSubmit()" title="Choose weekly or monthly trend view">
                        <option value="month" <?php echo !$isWeekView ? 'selected' : ''; ?>>Per month</option>
                        <option value="week" <?php echo $isWeekView ? 'selected' : ''; ?>>Per week</option>
                    </select>
                    <label for="<?php echo htmlspecialchars($periodControlId); ?>" class="sr-only">Trend year</label>
                    <select id="<?php echo htmlspecialchars($periodControlId); ?>" name="trend_year" onchange="this.form.requestSubmit()" title="Select trend year" <?php echo empty($filters['year_options']) ? 'disabled' : ''; ?>>
                        <?php if (empty($filters['year_options'])): ?><option value="">No imported years</option><?php endif; ?>
                        <?php foreach ($filters['year_options'] as $yearOption): ?>
                            <option value="<?php echo (int)$yearOption; ?>" <?php echo (int)$filters['year'] === (int)$yearOption ? 'selected' : ''; ?>><?php echo (int)$yearOption; ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>
        </div>

        <?php if ((int)$trend['event_count'] === 0): ?>
            <div class="empty-state"><strong>No trend data</strong><span>No active waste records match the selected location and year.</span></div>
        <?php else: ?>
            <div class="trend-chart-scroll" tabindex="0" aria-label="Scrollable <?php echo $isWeekView ? 'weekly' : 'monthly'; ?> waste comparison for <?php echo (int)$filters['year']; ?>">
                <div class="trend-bars trend-bars-yearly" style="min-width: <?php echo max(100, count($trend['weeks']) * 70); ?>px;">
                <?php foreach ($trend['weeks'] as $trendIndex => $week): ?>
                    <?php
                        $barHeight = $maxTrendWaste > 0 ? max(8, round(((float)$week['total_waste'] / $maxTrendWaste) * 180)) : 8;
                    $barColor = $chartPalette[$trendIndex % count($chartPalette)];
                    $tooltip = $week['label'] . ($week['range'] !== '' ? ' — ' . $week['range'] : '')
                        . ': ' . number_format((float)$week['total_waste'], 2) . ' kg'
                        . ' from ' . (int)$week['event_count'] . ' collection event' . ((int)$week['event_count'] === 1 ? '' : 's')
                        . ' at ' . $filters['location_label'] . '.';
                    ?>
                    <div class="trend-item" title="<?php echo htmlspecialchars($tooltip); ?>">
                        <div class="trend-bar" style="--bar-height: <?php echo $barHeight; ?>px; --chart-color: <?php echo $barColor; ?>;"></div>
                        <div class="trend-label"><?php echo htmlspecialchars($week['label']); ?></div>
                        <div class="trend-phase"><?php echo htmlspecialchars($week['phase_label']); ?></div>
                        <div class="trend-label"><?php echo htmlspecialchars($week['range']); ?></div>
                    </div>
                <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </section>
    <?php
}
?>

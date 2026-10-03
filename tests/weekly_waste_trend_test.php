<?php

require_once __DIR__ . '/../includes/weekly_waste_trend.php';

$checks = 0;
function checkWeeklyTrend($condition, $message)
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
}

function weeklyTrendRecord($date, $total, $street = 'Curie St.', $active = 1)
{
    return [
        'collection_date' => $date,
        'collection_group' => 'Phase 1A',
        'street' => $street,
        'kilogram_of_waste' => $total,
        'is_active' => $active,
    ];
}

$records = [
    weeklyTrendRecord('2026-01-01', 100),
    weeklyTrendRecord('2026-01-07', 20),
    weeklyTrendRecord('2026-01-08', 145),
    weeklyTrendRecord('2026-02-01', 110),
    weeklyTrendRecord('2026-09-22', 160),
    weeklyTrendRecord('2026-12-31', 90),
    weeklyTrendRecord('2026-09-03', 50, 'Einstein St.'),
    weeklyTrendRecord('2026-09-04', 999, 'Curie St.', 0),
    weeklyTrendRecord('2025-09-01', 55),
];

$locationOptions = buildWeeklyWasteTrendLocationOptions($records);
$curieLocation = null;
foreach ($locationOptions as $option) {
    if ($option['label'] === 'Phase 1A - Curie St.') {
        $curieLocation = $option;
        break;
    }
}
checkWeeklyTrend($curieLocation !== null, 'Location choices include the saved collection group and street.');

$monthlyFilters = [
    'view' => 'month',
    'year' => 2026,
    'location' => $curieLocation['key'],
    'location_label' => $curieLocation['label'],
    'year_options' => [2026, 2025],
    'location_options' => $locationOptions,
];
$monthlyTrend = buildWeeklyWasteTrendAnalysis($records, $monthlyFilters);

checkWeeklyTrend(count($monthlyTrend['weeks']) === 12, 'Per-month view compares all twelve months of the selected year.');
checkWeeklyTrend(
    array_column($monthlyTrend['weeks'], 'total_waste') === [265.0, 110.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 160.0, 0.0, 0.0, 90.0],
    'Per-month view totals actual active records in each month without hard-coded values.'
);
checkWeeklyTrend($monthlyTrend['total_waste'] === 625.0 && $monthlyTrend['event_count'] === 6, 'Archived rows and records from another year are excluded from the selected annual trend.');
checkWeeklyTrend($monthlyTrend['weeks'][0]['phase_label'] === 'Phase 1A', 'Each monthly bar keeps its actual collection group beside the month.');

$weeklyFilters = $monthlyFilters;
$weeklyFilters['view'] = 'week';
$weeklyTrend = buildWeeklyWasteTrendAnalysis($records, $weeklyFilters);
checkWeeklyTrend(count($weeklyTrend['weeks']) === 53, 'Per-week view compares every sequential seven-day period in the selected year.');
checkWeeklyTrend($weeklyTrend['weeks'][0]['total_waste'] === 120.0 && $weeklyTrend['weeks'][1]['total_waste'] === 145.0, 'Per-week view assigns January 1–7 and January 8–14 to separate chronological weeks.');
checkWeeklyTrend($weeklyTrend['weeks'][52]['total_waste'] === 90.0, 'The last partial calendar week remains visible and uses its actual waste total.');
checkWeeklyTrend($weeklyTrend['total_waste'] === $monthlyTrend['total_waste'], 'Weekly and monthly annual views use the same underlying active records and total waste.');

$resolved = resolveWeeklyWasteTrendFilters($records, ['trend_view' => 'week']);
checkWeeklyTrend($resolved['year'] === 2026 && $resolved['view'] === 'week', 'Default filters use the latest active Collection Date and keep the requested annual view.');
$yearResolved = resolveWeeklyWasteTrendFilters($records, ['trend_year' => '2025']);
checkWeeklyTrend($yearResolved['year'] === 2025 && !array_key_exists('month', $yearResolved) && !array_key_exists('week', $yearResolved), 'The shared trend control resolves a year only, with no month or single-week selector.');
$unavailableYearResolved = resolveWeeklyWasteTrendFilters($records, ['trend_year' => '2024']);
checkWeeklyTrend(
    $unavailableYearResolved['year'] === 2026
    && $unavailableYearResolved['year_options'] === ['2026', '2025']
    && !in_array('2024', $unavailableYearResolved['year_options'], true),
    'The shared trend control ignores unavailable years and lists only imported years.'
);

ob_start();
renderWeeklyWasteTrend($monthlyTrend, ['action' => 'operations_reports.php', 'title' => 'Waste Collection Trend']);
$trendMarkup = ob_get_clean();
checkWeeklyTrend(
    strpos($trendMarkup, 'trend-bars-yearly') !== false
    && strpos($trendMarkup, 'name="trend_year"') !== false
    && strpos($trendMarkup, 'name="trend_view"') !== false
    && strpos($trendMarkup, 'name="trend_week"') === false
    && strpos($trendMarkup, 'name="trend_period"') === false
    && strpos($trendMarkup, 'Trend:</strong>') === false,
    'The shared renderer has Year and View controls only, compares annual periods, and does not render a trend description.'
);

echo 'PASS: ' . $checks . " shared yearly trend checks\n";

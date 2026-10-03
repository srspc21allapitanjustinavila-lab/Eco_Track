<?php

require_once __DIR__ . '/../includes/waste_analytics.php';

$checks = 0;
function checkDailyHeatmap($condition, $message)
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
}

$sameMonth = parseWasteReportingDateRange('Dec 23-28, 2026');
checkDailyHeatmap($sameMonth === ['start' => '2026-12-23', 'end' => '2026-12-28'], 'Same-month reporting ranges are inclusive.');
$crossYear = parseWasteReportingDateRange('Dec 30-Jan 4, 2027');
checkDailyHeatmap($crossYear === ['start' => '2026-12-30', 'end' => '2027-01-04'], 'Cross-year reporting ranges retain the correct start year.');
$compact = parseWasteReportingDateRange('DATE: 23-25', '2026-12-23');
checkDailyHeatmap($compact === ['start' => '2026-12-23', 'end' => '2026-12-25'], 'Compact day-only ranges use their stored collection month and year.');
checkDailyHeatmap(parseWasteReportingDateRange('Dec 23-99, 2026', '2026-12-23') === null, 'Unresolvable range labels are not converted into invented daily records.');
checkDailyHeatmap(parseWasteReportingDateRange('2026-09-21 to 2026-09-23') === ['start' => '2026-09-21', 'end' => '2026-09-23'], 'Resolution-preview ISO date ranges remain usable for daily allocations.');

$allocationRecord = [
    'id' => 25,
    'reporting_period' => 'Dec 23-28, 2026',
    'collection_date' => '2026-12-23',
    'tuesday_factory_returnable_kg' => 1,
    'wednesday_biowaste_kg' => 1,
    'thursday_factory_returnable_kg' => 1,
    'friday_biowaste_kg' => 1,
    'saturday_hazard_waste_kg' => 1,
    'residual_waste_kg' => 1,
];
$allocations = buildWasteDailyAllocations($allocationRecord);
checkDailyHeatmap(count($allocations) === 6 && $allocations[0]['allocation_date'] === '2026-12-23' && $allocations[5]['allocation_date'] === '2026-12-28', 'Bulk records create one row for every included calendar day.');
checkDailyHeatmap($allocations[0]['tuesday_factory_returnable_kg'] === 0.16 && $allocations[5]['tuesday_factory_returnable_kg'] === 0.2, 'Final-day rounding preserves each source waste stream total.');
checkDailyHeatmap(array_sum(array_column($allocations, 'kilogram_of_waste')) === 6.0, 'Daily allocation totals reconcile to the source total.');

$unclassifiedRecord = [
    'reporting_period' => '2026-09-21 to 2026-09-22',
    'collection_date' => '2026-09-21',
    'unclassified_waste_kg' => 5,
];
$unclassifiedAllocations = buildWasteDailyAllocations($unclassifiedRecord);
$unclassifiedSummary = buildWasteCategorySummary([$unclassifiedRecord]);
checkDailyHeatmap(count($unclassifiedAllocations) === 2 && array_sum(array_column($unclassifiedAllocations, 'kilogram_of_waste')) === 5.0, 'Unclassified waste is included in total daily allocations.');
checkDailyHeatmap($unclassifiedSummary['total_waste'] === 5.0 && $unclassifiedSummary['unclassified'] === 5.0 && $unclassifiedSummary['diverted'] === 0.0, 'Unclassified waste contributes to totals but not diversion.');

$expectedLevels = [
    [0, 'Low'], [20, 'Low'], [20.01, 'Medium-Low'], [40, 'Medium-Low'], [40.01, 'Medium-High'],
    [60, 'Medium-High'], [60.01, 'Medium-High'], [80, 'Medium-High'], [80.01, 'High'],
];
foreach ($expectedLevels as [$value, $label]) {
    checkDailyHeatmap(classifyDailyWasteAverage($value, true)['label'] === $label, $value . ' kg/day receives the correct fixed classification.');
}
checkDailyHeatmap(classifyDailyWasteAverage(0, false)['label'] === 'No data', 'No allocated rows remain distinct from a recorded zero.');
$legend = buildDailyWasteLegend();
checkDailyHeatmap(count($legend) === 4 && array_column($legend, 'color_name') === ['Red', 'Orange', 'Yellow', 'Green'], 'The legend contains exactly the requested four display colors.');
checkDailyHeatmap(array_column($legend, 'label') === ['High', 'Medium-High', 'Medium-Low', 'Low'], 'The legend uses the requested four classification labels.');

checkDailyHeatmap($legend[3]['range'] === '0.00–20.00 kg/day' && $legend[0]['range'] === '>80.00 kg/day', 'Heatmap legend remains on fixed daily ranges.');

$weekSelection = resolveHeatmapPeriodSelection(['2026-01-01', '2026-01-08'], 'week', '2026-W02');
checkDailyHeatmap($weekSelection['start'] === '2026-01-08' && $weekSelection['end'] === '2026-01-14' && $weekSelection['previous']['start'] === '2026-01-01', 'Weekly periods use the Jan 1 sequential seven-day convention.');
$dataDrivenWeeks = resolveHeatmapPeriodSelection(['2026-01-01', '2026-02-03'], 'week');
checkDailyHeatmap(array_column($dataDrivenWeeks['options'], 'period') === ['2026-W05', '2026-W01'], 'Week options contain only buckets with saved allocation dates.');
$dataDrivenMonths = resolveHeatmapPeriodSelection(['2025-12-31', '2026-02-03'], 'month');
checkDailyHeatmap(array_column($dataDrivenMonths['options'], 'period') === ['2026-02', '2025-12'], 'Month options contain only months with saved allocation dates.');
$emptyRequestedMonth = resolveHeatmapPeriodSelection([], 'month', '2024-02', true);
checkDailyHeatmap($emptyRequestedMonth['period'] === '2024-02' && $emptyRequestedMonth['start'] === '2024-02-01' && $emptyRequestedMonth['options'] === [], 'An explicitly selected empty historical month remains selected.');
$yearSelection = resolveHeatmapPeriodSelection(['2025-12-31', '2026-02-03'], 'year', '2026');
checkDailyHeatmap($yearSelection['start'] === '2026-01-01' && $yearSelection['end'] === '2026-12-31' && $yearSelection['previous']['period'] === '2025', 'Yearly selections cover the calendar year and compare against the preceding year.');
$unavailableYearSelection = resolveHeatmapPeriodSelection(['2026-02-03'], 'year', '2024');
checkDailyHeatmap($unavailableYearSelection['period'] === '2026', 'Unavailable yearly selections fall back to the latest imported year.');
$emptyDailyRange = resolveHeatmapDateRangeSelection('2023-12-30', '2024-01-02');
checkDailyHeatmap($emptyDailyRange['start'] === '2023-12-30' && $emptyDailyRange['end'] === '2024-01-02' && $emptyDailyRange['day_count'] === 4, 'Heatmap supports flexible daily ranges, including across years.');
$leapMonth = resolveHeatmapPeriodSelection(['2024-02-01'], 'month', '2024-02');
checkDailyHeatmap($leapMonth['day_count'] === 29, 'Monthly averages use every calendar day in leap-year February.');

$source = ['id' => 1, 'collection_group' => 'Phase 1-A', 'street' => 'Bell St.'];
$current = [array_merge($source, ['waste_record_id' => 1, 'kilogram_of_waste' => 140])];
$previous = [array_merge($source, ['waste_record_id' => 1, 'kilogram_of_waste' => 210])];
$analytics = buildHeatmapPeriodAnalytics([$source], $current, $previous, $weekSelection);
$byName = array_column($analytics['locations'], null, 'name');
$location = $byName['Phase 1A - Bell St.'];
checkDailyHeatmap($location['daily_average_kg'] === 20.0 && $location['previous_daily_average_kg'] === 30.0, 'DSS compares daily averages from matching calendar periods.');
checkDailyHeatmap($location['dss']['success'] === true && $location['waste_level']['color_name'] === 'Green', 'A Low reduction keeps the Green Low display color while preserving its DSS result.');

$notLow = buildHeatmapPeriodAnalytics([$source], [array_merge($source, ['waste_record_id' => 1, 'kilogram_of_waste' => 350])], [array_merge($source, ['waste_record_id' => 1, 'kilogram_of_waste' => 420])], $weekSelection);
$notLowByName = array_column($notLow['locations'], null, 'name');
checkDailyHeatmap($notLowByName['Phase 1A - Bell St.']['dss']['status'] === 'not-low' && $notLowByName['Phase 1A - Bell St.']['waste_level']['color_name'] === 'Orange', 'The removed Medium band is absorbed by Orange and a higher reduction remains non-successful.');
$noBaseline = buildHeatmapPeriodAnalytics([$source], $current, [], $weekSelection);
$noBaselineByName = array_column($noBaseline['locations'], null, 'name');
checkDailyHeatmap($noBaselineByName['Phase 1A - Bell St.']['dss']['status'] === 'no-baseline' && $noBaselineByName['Phase 1A - Bell St.']['waste_level']['color_name'] === 'Green', 'Low waste without a baseline remains Green instead of becoming a separate neutral category.');

echo 'PASS: ' . $checks . " daily heatmap analytics checks\n";

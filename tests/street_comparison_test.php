<?php

require_once __DIR__ . '/../includes/waste_analytics.php';

$checks = 0;
function checkStreetComparison($condition, $message)
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
}

$records = [
    ['collection_date' => '2025-01-02', 'collection_group' => 'Phase 1', 'street' => 'Acacia St.', 'kilogram_of_waste' => 10, 'tuesday_factory_returnable_kg' => 10],
    ['collection_date' => '2025-01-03', 'collection_group' => 'Phase 1', 'street' => 'Acacia St.', 'kilogram_of_waste' => 15, 'residual_waste_kg' => 15],
    ['collection_date' => '2025-02-04', 'collection_group' => 'Phase 1', 'street' => 'Birch St.', 'kilogram_of_waste' => 35, 'wednesday_biowaste_kg' => 35],
    ['collection_date' => '2025-03-03', 'collection_group' => 'Phase 2', 'street' => 'Acacia St.', 'kilogram_of_waste' => 40, 'saturday_hazard_waste_kg' => 40],
    ['collection_date' => '2025-12-18', 'collection_group' => 'Phase 4', 'street' => 'Cross Year St.', 'kilogram_of_waste' => 12, 'residual_waste_kg' => 12],
    ['collection_date' => '2026-02-05', 'collection_group' => 'Phase 4', 'street' => 'Cross Year St.', 'kilogram_of_waste' => 18, 'residual_waste_kg' => 18],
];
for ($index = 1; $index <= 9; $index++) {
    $records[] = ['collection_date' => '2025-04-01', 'collection_group' => 'Phase 3', 'street' => 'Rank ' . $index, 'kilogram_of_waste' => $index, 'residual_waste_kg' => $index];
}

$comparison = buildWasteStreetComparison($records);
checkStreetComparison(count($comparison['rows']) === 8, 'The default visualization returns only the Top 8 Phase + Street pairs.');
$acaciaRows = array_values(array_filter($comparison['rows'], static function ($row) {
    return $row['street'] === 'Acacia St.';
}));
checkStreetComparison(
    count($acaciaRows) === 2
    && $acaciaRows[0]['phase_name'] !== $acaciaRows[1]['phase_name'],
    'Identical street names in different phases remain separate chart identities.'
);
checkStreetComparison(
    $comparison['summary']['street_count'] > count($comparison['rows'])
    && $comparison['summary']['total_waste'] > $comparison['summary']['displayed_total'],
    'Summary cards use every matching street rather than only the displayed Top 8.'
);
checkStreetComparison(
    count($comparison['details']) === count($comparison['rows'])
    && array_keys($comparison['details']) === array_column($comparison['rows'], 'key'),
    'A database-derived drill-down payload exists only for the displayed Top 8 rows and keeps their chart order.'
);
$phaseOneAcaciaKey = wasteStreetComparisonKey('Phase 1', 'Acacia St.');
$phaseTwoAcaciaKey = wasteStreetComparisonKey('Phase 2', 'Acacia St.');
$phaseOneAcaciaDetail = $comparison['details'][$phaseOneAcaciaKey];
checkStreetComparison(
    $phaseOneAcaciaDetail['phase_name'] === 'Phase 1'
    && $phaseOneAcaciaDetail['street'] === 'Acacia St.'
    && $phaseOneAcaciaDetail['total_waste'] === 25.0
    && $phaseOneAcaciaDetail['categories']['returnable']['amount'] === 10.0
    && $phaseOneAcaciaDetail['categories']['residual']['amount'] === 15.0
    && $phaseOneAcaciaDetail['categories']['returnable']['percent'] === 40.0
    && $phaseOneAcaciaDetail['categories']['residual']['percent'] === 60.0,
    'Street details use the exact Phase + Street identity and category amounts sum to its database total.'
);
checkStreetComparison(
    $comparison['details'][$phaseTwoAcaciaKey]['total_waste'] === 40.0
    && $comparison['details'][$phaseTwoAcaciaKey]['categories']['hazardous']['amount'] === 40.0,
    'Same-named streets in different phases retain independent drill-down totals.'
);

$residualOnly = buildWasteStreetComparison($records, ['category' => 'residual']);
checkStreetComparison(
    $residualOnly['rows'][0]['street'] === 'Cross Year St.'
    && $residualOnly['rows'][0]['total_waste'] === 30.0,
    'A selected category ranks only its actual category values.'
);
checkStreetComparison(
    $residualOnly['details'][$phaseOneAcaciaKey]['total_waste'] === 15.0
    && array_keys($residualOnly['details'][$phaseOneAcaciaKey]['categories']) === ['residual']
    && $residualOnly['details'][$phaseOneAcaciaKey]['categories']['residual']['percent'] === 100.0,
    'A selected category excludes other categories from the drill-down total and percentage.'
);

$combinedFilter = buildWasteStreetComparison($records, [
    'phase' => 'Phase 1',
    'category' => 'residual',
    'start_date' => '2025-01-03',
    'end_date' => '2025-01-03',
]);
checkStreetComparison(
    count($combinedFilter['rows']) === 1
    && $combinedFilter['rows'][0]['street'] === 'Acacia St.'
    && $combinedFilter['rows'][0]['total_waste'] === 15.0
    && $combinedFilter['summary']['total_waste'] === 15.0
    && $combinedFilter['filters']['start_date'] === '2025-01-03'
    && $combinedFilter['filters']['end_date'] === '2025-01-03',
    'Combined Phase, category, and custom-date filters use only matching records and exact inclusive bounds.'
);
checkStreetComparison(
    $combinedFilter['details'][$phaseOneAcaciaKey]['total_waste'] === 15.0
    && $combinedFilter['details'][$phaseOneAcaciaKey]['record_count'] === 1,
    'Drill-down details honor the same exact Phase, category, and date bounds as the chart.'
);

$singleMonth = buildWasteStreetComparison($records, ['phase' => 'Phase 1', 'start_date' => '2025-02-04']);
checkStreetComparison(
    count($singleMonth['rows']) === 1
    && $singleMonth['rows'][0]['street'] === 'Birch St.'
    && $singleMonth['filters']['start_date'] === '2025-02-04'
    && $singleMonth['filters']['end_date'] === '2025-02-04',
    'A single custom date returns only that database date.'
);

$crossYearRange = buildWasteStreetComparison($records, ['phase' => 'Phase 4', 'start_date' => '2026-02-05', 'end_date' => '2025-12-18']);
checkStreetComparison(
    count($crossYearRange['rows']) === 1
    && $crossYearRange['rows'][0]['total_waste'] === 30.0
    && $crossYearRange['filters']['start_date'] === '2025-12-18'
    && $crossYearRange['filters']['end_date'] === '2026-02-05',
    'Reversed cross-year selections reorder and include every date between the endpoints.'
);

$singleMonthRange = resolveWasteStreetMonthRange('2025-02', '');
checkStreetComparison(
    $singleMonthRange['start_month'] === '2025-02'
    && $singleMonthRange['end_month'] === '2025-02'
    && $singleMonthRange['start_date'] === '2025-02-01'
    && $singleMonthRange['end_date'] === '2025-02-28',
    'One Month Range selection expands to the complete selected month.'
);
$reversedMonthRange = resolveWasteStreetMonthRange('2026-02', '2025-12');
checkStreetComparison(
    $reversedMonthRange['start_month'] === '2025-12'
    && $reversedMonthRange['end_month'] === '2026-02'
    && $reversedMonthRange['start_date'] === '2025-12-01'
    && $reversedMonthRange['end_date'] === '2026-02-28',
    'Reversed Month Range selections are reordered with inclusive whole-month bounds.'
);
$noRecordCustomDate = buildWasteStreetComparison($records, ['phase' => 'Phase 1', 'category' => 'residual', 'start_date' => '2025-01-21']);
checkStreetComparison(
    empty($noRecordCustomDate['rows'])
    && $noRecordCustomDate['summary']['total_waste'] === 0.0
    && $noRecordCustomDate['filters']['start_date'] === '2025-01-21'
    && $noRecordCustomDate['filters']['end_date'] === '2025-01-21',
    'A selectable custom day without a record returns an exact empty result instead of estimated data.'
);

$calendarState = buildWasteStreetFilterState($records, ['phase' => 'Phase 1', 'category' => 'residual']);
checkStreetComparison(
    $calendarState['calendar']['years'] === [2025]
    && $calendarState['calendar']['months_by_year']['2025'] === [1]
    && $calendarState['calendar']['dates_by_month']['2025-01'] === ['2025-01-03'],
    'Calendar availability is derived from the active Phase, waste category, and exact record dates.'
);
$bioCalendarState = buildWasteStreetFilterState($records, ['phase' => 'Phase 1', 'category' => 'biowaste']);
checkStreetComparison(
    $bioCalendarState['calendar']['months_by_year']['2025'] === [2]
    && $bioCalendarState['phase_options'] === ['Phase 1', 'Phase 2', 'Phase 3', 'Phase 4'],
    'Changing category changes enabled months while Phase choices remain database driven.'
);

$zeroOnly = buildWasteStreetComparison([
    ['collection_date' => '2025-04-01', 'collection_group' => 'Phase 5', 'street' => 'Zero St.', 'kilogram_of_waste' => 0, 'residual_waste_kg' => 0],
]);
checkStreetComparison(
    empty($zeroOnly['rows'])
    && $zeroOnly['summary']['street_count'] === 0
    && empty($zeroOnly['filter_state']['calendar']['years']),
    'Zero and missing values do not create chart rows or selectable calendar months.'
);

$activeOnly = buildWasteStreetComparison([
    ['collection_date' => '2025-04-01', 'collection_group' => 'Phase 5', 'street' => 'Archived St.', 'kilogram_of_waste' => 90, 'residual_waste_kg' => 90, 'is_active' => 0],
    ['collection_date' => '2025-04-01', 'collection_group' => 'Phase 5', 'street' => 'Current St.', 'kilogram_of_waste' => 5, 'residual_waste_kg' => 5, 'is_active' => 1],
    ['collection_group' => 'Phase 5', 'street' => 'Undated St.', 'kilogram_of_waste' => 7, 'residual_waste_kg' => 7, 'is_active' => 1],
]);
checkStreetComparison(
    count($activeOnly['rows']) === 2
    && $activeOnly['summary']['total_waste'] === 12.0
    && $activeOnly['filter_state']['calendar']['months_by_year']['2025'] === [4]
    && !isset($activeOnly['details'][wasteStreetComparisonKey('Phase 5', 'Archived St.')])
    && $activeOnly['details'][wasteStreetComparisonKey('Phase 5', 'Current St.')]['total_waste'] === 5.0,
    'Inactive rows are excluded from chart and drill-down data, and undated active rows do not create calendar availability.'
);

$deduplicated = buildWasteStreetComparison([
    ['collection_date' => '2025-04-01', 'collection_group' => 'Phase 6', 'street' => 'Duplicate St.', 'kilogram_of_waste' => 20, 'residual_waste_kg' => 20, 'record_fingerprint' => 'same-record'],
    ['collection_date' => '2025-04-01', 'collection_group' => 'Phase 6', 'street' => 'Duplicate St.', 'kilogram_of_waste' => 20, 'residual_waste_kg' => 20, 'record_fingerprint' => 'same-record'],
]);
checkStreetComparison(
    $deduplicated['rows'][0]['total_waste'] === 20.0
    && $deduplicated['rows'][0]['record_count'] === 1
    && $deduplicated['details'][$deduplicated['rows'][0]['key']]['total_waste'] === 20.0
    && $deduplicated['details'][$deduplicated['rows'][0]['key']]['record_count'] === 1,
    'Persisted fingerprints prevent duplicate records from inflating chart and drill-down totals.'
);

$streetDefinitions = getWasteStreetCategoryDefinitions();
checkStreetComparison(
    $streetDefinitions['returnable']['color'] === '#228B22'
    && $streetDefinitions['biowaste']['color'] === '#8B5A2B'
    && $streetDefinitions['residual']['color'] === '#6B7280'
    && $streetDefinitions['hazardous']['color'] === '#DC2626'
    && $streetDefinitions['unclassified']['color'] === '#64748B',
    'Street visualization category colors match the approved palette.'
);

$unclassifiedOnly = buildWasteStreetComparison([
    ['collection_date' => '2025-05-03', 'collection_group' => 'Phase 7', 'street' => 'Generic St.', 'kilogram_of_waste' => 8],
], ['category' => 'unclassified']);
checkStreetComparison(
    $unclassifiedOnly['filters']['category'] === 'unclassified'
    && $unclassifiedOnly['rows'][0]['total_waste'] === 8.0,
    'Unclassified remains available when legacy generic records exist.'
);

echo 'PASS: ' . $checks . " street comparison checks\n";

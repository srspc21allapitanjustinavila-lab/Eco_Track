<?php

require_once __DIR__ . '/../includes/waste_analytics.php';

$checks = 0;
function checkTrendResult($condition, $message)
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
}

$records = [];
for ($day = 1; $day <= 10; $day++) {
    $date = sprintf('2025-01-%02d', $day);
    $records[] = [
        'collection_date' => $date,
        'date' => 'Imported label ' . $day,
        'phase_number' => 'Phase 1',
        'street' => 'Street ' . $day,
        'kilogram_of_waste' => $day * 10,
    ];
}

// The duplicate source label and blank street must aggregate into Jan. 9's
// trend total, while only the Heatmap (not the trend) omits its location.
$records[] = [
    'collection_date' => '2025-01-09',
    'date' => 'A different imported label',
    'phase_number' => 'Phase 2',
    'street' => '',
    'kilogram_of_waste' => 25,
];
$records[] = [
    'date' => 'Unparseable legacy label',
    'phase_number' => 'Phase 1',
    'street' => 'No Date Street',
    'kilogram_of_waste' => 999,
];

$trend = buildCollectionTrend($records);

checkTrendResult(count($trend) === 10, 'Every uploaded collection date is returned.');
checkTrendResult($trend[0]['collection_date'] === '2025-01-01' && $trend[9]['collection_date'] === '2025-01-10', 'All dates are displayed chronologically with the newest on the right.');
checkTrendResult($trend[8]['date'] === 'Jan 9, 2025' && $trend[8]['total_waste'] === 115.0, 'Rows with the same collection date aggregate despite different source labels or a blank street.');
checkTrendResult($trend[8]['phase_label'] === 'Phase 1, Phase 2', 'Each trend period visibly identifies every phase included in its total.');
checkTrendResult(!in_array('Unparseable legacy label', array_column($trend, 'date'), true), 'Rows without a usable collection date are excluded only from the date trend.');
checkTrendResult(count(buildCollectionTrend($records, 8)) === 8, 'A page can still explicitly request a shorter latest-period trend when needed.');
checkTrendResult(
    count(filterWasteRecordsByCollectionYear($records, '2025')) === 11,
    'A visualization year filter keeps only records in its selected collection year.'
);
checkTrendResult(
    count(filterWasteRecordsByCollectionYear($records, '2024')) === 0,
    'A visualization year filter does not leak records from another year.'
);
checkTrendResult(
    resolveImportedWasteCollectionYear('2025', ['2026', '2025']) === '2025'
    && resolveImportedWasteCollectionYear('2024', ['2026', '2025']) === '',
    'Year controls accept only years represented by imported Waste Data.'
);
$collectionGroups = buildActiveWasteCollectionGroups([
    ['collection_group' => 'Phase 6'],
    ['collection_group' => 'Phase 1A'],
    ['collection_group' => 'Phase 6'],
    ['phase_number' => 'Establishments - Date: Mar 4, 2026'],
]);
checkTrendResult(
    $collectionGroups === ['Establishments', 'Phase 1A', 'Phase 6'],
    'Collection Group options contain only distinct saved/imported groups.'
);
$wasteTypeOptions = buildAvailableWasteTypeOptions([
    ['recyclable_kg' => 12, 'hazardous_kg' => 3],
    ['residual_waste_kg' => 0, 'unclassified_waste_kg' => 0],
]);
checkTrendResult(
    array_keys($wasteTypeOptions) === ['factory returnable', 'hazardous waste'],
    'Waste Type options contain only categories represented by positive saved data.'
);
checkTrendResult(
    count(filterWasteRecordsByCollectionYear($records, '')) === count($records),
    'The All years visualization option retains every saved record.'
);
checkTrendResult(
    count(filterWasteRecordsByCollectionYear([[
        'collection_date' => '2025-09-12',
        'reporting_period' => 'October 2, 2024',
    ]], '2025')) === 1,
    'Dashboard year filters use the stored Collection Date instead of a conflicting reporting label.'
);
checkTrendResult(normalizeWasteDateValue('PHASE 1-A 2023 - DATE: MAR/APRL 27-1') === '2023-03-27', 'Abbreviated cross-month source labels use the first source month and day.');
checkTrendResult(normalizeWasteDateValue('PHASE 1-A 2023 - DATE: APRIL10-15') === '2023-04-10', 'Compact month/day source labels are parsed without a separating space.');
checkTrendResult(normalizeWasteDateValue('PHASE 1-A 2023 - DATE: 23-27') === null, 'A source label without a month requires an explicit fallback collection date.');
checkTrendResult(
    wasteRecordReportingPeriodDate([
        'phase_date_label' => 'PHASE 1-A 2023 - DATE: 23-27',
        'collection_date' => '2023-01-23',
    ]) === '2023-01-23',
    'A supplied fallback date with the same source-file year remains available to visualizations.'
);

$areaComparison = buildPhaseStreetRecordComparison([
    [
        'collection_group' => 'Phase 1-A',
        'reporting_period' => 'Sept 30-Oct 5, 2024',
        'street' => 'Curie St.',
        'kilogram_of_waste' => 120,
    ],
    [
        'collection_group' => 'Phase 1-A',
        'reporting_period' => 'Oct 28-Nov 2, 2024',
        'street' => 'Curie St.',
        'kilogram_of_waste' => 80,
    ],
    [
        'collection_group' => 'Phase 1-A',
        'reporting_period' => 'Oct 28-Nov 2, 2024',
        'street' => 'Bell St.',
        'kilogram_of_waste' => 150,
    ],
]);
checkTrendResult($areaComparison[0]['street'] === 'Bell St.' && $areaComparison[0]['total_waste'] === 150.0, 'Area comparison ranks every Collection Group + Area from highest to lowest record.');
$curieComparison = array_values(array_filter($areaComparison, static function ($row) {
    return $row['street'] === 'Curie St.';
}));
checkTrendResult(isset($curieComparison[0]) && $curieComparison[0]['total_waste'] === 120.0, 'Area comparison considers every upload but keeps only an area\'s highest record instead of adding repeated weeks.');
checkTrendResult($curieComparison[0]['period_label'] === 'Sept 30-Oct 5, 2024', 'The displayed comparison keeps the period of that area\'s highest saved record.');
checkTrendResult(
    normalizeWastePhaseLabel(['collection_group' => 'Establishments', 'street' => 'Sample Store']) === 'Establishments',
    'A non-phase Collection Group remains intact in analytics and visualizations.'
);

$periodFiltered = filterWasteRecordsByCollectionPeriod([
    ['collection_date' => '2024-01-03', 'reporting_period' => 'Jan 3, 2024'],
    ['collection_date' => '2024-02-03', 'reporting_period' => 'Feb 3, 2024'],
    ['collection_date' => '2025-01-03', 'reporting_period' => 'Jan 3, 2025'],
], '2024', '1');
checkTrendResult(
    count($periodFiltered) === 1 && $periodFiltered[0]['collection_date'] === '2024-01-03',
    'Collection Group / Area month filtering combines the selected year and month.'
);
$allYearsJanuary = filterWasteRecordsByCollectionPeriod([
    ['collection_date' => '2024-01-03', 'reporting_period' => 'Jan 3, 2024'],
    ['collection_date' => '2024-02-03', 'reporting_period' => 'Feb 3, 2024'],
    ['collection_date' => '2025-01-03', 'reporting_period' => 'Jan 3, 2025'],
], '', '1');
checkTrendResult(count($allYearsJanuary) === 2, 'Month filtering can compare the selected month across all available years.');

echo 'PASS: ' . $checks . " collection visualization checks\n";

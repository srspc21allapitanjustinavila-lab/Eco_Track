<?php

require_once __DIR__ . '/../includes/waste_analytics.php';

function monthlySummaryCheck($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$month = date('m');
$currentYear = date('Y');
$otherYear = (string)((int)$currentYear - 1);
$alternateMonth = $month === '01' ? '02' : '01';
$records = [
    ['collection_date' => $currentYear . '-' . $month . '-04', 'kilogram_of_waste' => 10],
    ['collection_date' => $otherYear . '-' . $month . '-11', 'kilogram_of_waste' => 20],
    ['collection_date' => $currentYear . '-' . $alternateMonth . '-18', 'kilogram_of_waste' => 99],
];

$selectedPeriod = $currentYear . '-' . $month;
$selectedMonth = buildWasteMonthlySummary($records, $selectedPeriod);
monthlySummaryCheck($selectedMonth['monthly_waste'] === 10.0 && $selectedMonth['total_records'] === 1, 'Monthly summary uses one exact stored collection month.');

$otherPeriod = $otherYear . '-' . $month;
$otherMonth = buildWasteMonthlySummary($records, $otherPeriod);
monthlySummaryCheck($otherMonth['monthly_waste'] === 20.0 && $otherMonth['total_records'] === 1, 'Monthly summary does not aggregate the same month across years.');

$storedDateWins = buildWasteMonthlySummary([[
    'collection_date' => $currentYear . '-' . $month . '-20',
    'reporting_period' => 'January 1, 2020',
    'kilogram_of_waste' => 15,
]], $selectedPeriod);
monthlySummaryCheck($storedDateWins['monthly_waste'] === 15.0, 'Monthly summary uses Collection Date instead of a conflicting reporting label.');

$additionalMonth = $alternateMonth;
$expectedCurrentMonths = [(int)$month, (int)$additionalMonth];
rsort($expectedCurrentMonths, SORT_NUMERIC);
$periodOptions = buildActiveWasteCollectionPeriodOptions([$otherPeriod, $selectedPeriod, $currentYear . '-' . $additionalMonth, 'not-a-period']);
monthlySummaryCheck(
    $periodOptions['years'] === [$currentYear, $otherYear]
        && $periodOptions['months_by_year'][$currentYear] === $expectedCurrentMonths,
    'Period options expose only valid stored-data years and months.'
);
monthlySummaryCheck(
    resolveActiveWasteCollectionPeriod('', $otherYear, $periodOptions) === $otherPeriod
        && resolveActiveWasteCollectionPeriod($otherPeriod, $currentYear, $periodOptions) === $currentYear . '-' . str_pad((string)$expectedCurrentMonths[0], 2, '0', STR_PAD_LEFT),
    'Period resolution uses an actual period within the requested year.'
);

echo "PASS: monthly waste summary checks\n";

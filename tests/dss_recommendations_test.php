<?php

require_once __DIR__ . '/../includes/waste_analytics.php';

function dssCheck($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$phases = [
    ['phase_name' => 'Phase High', 'total_waste' => 500, 'record_count' => 5, 'average_waste' => 100, 'waste_level' => ['label' => 'High', 'color' => '#d32f2f', 'color_name' => 'Red']],
    ['phase_name' => 'Phase Medium', 'total_waste' => 250, 'record_count' => 5, 'average_waste' => 50, 'waste_level' => ['label' => 'Medium-High', 'color' => '#f57c00', 'color_name' => 'Orange']],
    ['phase_name' => 'Phase Low', 'total_waste' => 40, 'record_count' => 2, 'average_waste' => 20, 'waste_level' => ['label' => 'Low', 'color' => '#fbc02d', 'color_name' => 'Yellow']],
    ['phase_name' => 'No Waste', 'total_waste' => 0, 'record_count' => 1, 'average_waste' => 0, 'waste_level' => ['label' => 'Low', 'color' => '#fbc02d', 'color_name' => 'Yellow']],
];
$recommendations = buildDssRecommendations($phases, [], 3);
dssCheck(count($recommendations) === 3, 'DSS excludes zero-waste areas and keeps the three highest positive recommendations.');
dssCheck(array_column($recommendations, 'rank') === [1, 2, 3], 'DSS recommendation ranks remain sequential.');
dssCheck(array_column($recommendations, 'frequency') === [3, 2, 1], 'High, Medium-High, and lower waste bands receive 3, 2, and 1 weekly collections.');
dssCheck(count($recommendations[0]['suggested_days']) === 3 && count($recommendations[1]['suggested_days']) === 2 && count($recommendations[2]['suggested_days']) === 1, 'Every frequency has the same number of suggested next-week days.');

$weekMatrix = buildDssWasteMatrix(['view' => 'range']);
$monthMatrix = buildDssWasteMatrix(['view' => 'month']);
$yearMatrix = buildDssWasteMatrix(['view' => 'year']);
dssCheck($weekMatrix['multiplier'] === 7 && $weekMatrix['low_max'] === 140 && $weekMatrix['medium_low_max'] === 280 && $weekMatrix['medium_high_max'] === 560, 'DSS weekly matrix thresholds scale daily values by seven.');
dssCheck($monthMatrix['multiplier'] === 30 && $monthMatrix['low_max'] === 600 && $monthMatrix['medium_low_max'] === 1200 && $monthMatrix['medium_high_max'] === 2400, 'DSS monthly matrix thresholds scale daily values by thirty.');
dssCheck($yearMatrix['multiplier'] === 360 && $yearMatrix['low_max'] === 7200 && $yearMatrix['medium_low_max'] === 14400 && $yearMatrix['medium_high_max'] === 28800, 'DSS yearly matrix thresholds scale daily values by twelve thirty-day months.');
foreach ([[140, 'Low'], [140.01, 'Medium-Low'], [280, 'Medium-Low'], [280.01, 'Medium-High'], [560, 'Medium-High'], [560.01, 'High']] as [$value, $label]) {
    dssCheck(classifyDssWasteTotal($value, true, ['view' => 'range'])['label'] === $label, $value . ' kg receives the correct DSS weekly classification.');
}
$monthLegend = buildDssWasteLegend(['view' => 'month']);
dssCheck($monthLegend[3]['range'] === '0.00-600.00 kg/month' && $monthLegend[0]['range'] === '>2,400.00 kg/month', 'DSS monthly legend uses the scaled fixed matrix.');

$weeklySelection = resolveDssWeekDateRangeSelection('2026-03-01', '2026-03-20');
$periodAnalytics = buildDssPeriodAnalytics([
    ['phase_number' => 'Phase 1', 'waste_record_id' => 101, 'kilogram_of_waste' => 140],
    ['phase_number' => 'Phase 2', 'waste_record_id' => 102, 'kilogram_of_waste' => 140.01],
    ['phase_number' => 'Phase 3', 'waste_record_id' => 103, 'kilogram_of_waste' => 560.01],
], $weeklySelection);
$periodPhases = array_column($periodAnalytics['phase_totals'], null, 'phase_name');
dssCheck($weeklySelection['end'] === '2026-03-07' && $weeklySelection['day_count'] === 7, 'DSS daily analysis caps direct date ranges to one inclusive week.');
dssCheck($periodPhases['Phase 1']['waste_level']['color_name'] === 'Green', 'DSS uses the weekly Low threshold at 140 kg.');
dssCheck($periodPhases['Phase 2']['waste_level']['color_name'] === 'Yellow', 'DSS uses the weekly Yellow threshold immediately above 140 kg.');
dssCheck($periodPhases['Phase 3']['waste_level']['color_name'] === 'Red', 'DSS uses the weekly Red threshold above 560 kg.');
dssCheck(array_column($periodAnalytics['legend'], 'color_name') === ['Red', 'Orange', 'Yellow', 'Green'], 'DSS exposes the Heatmap color legend in the same order.');

echo "PASS: DSS scheduling recommendation checks\n";

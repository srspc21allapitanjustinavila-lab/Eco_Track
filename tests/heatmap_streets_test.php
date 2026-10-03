<?php

require_once __DIR__ . '/../includes/waste_analytics.php';
$records = require __DIR__ . '/heatmap_street_fixtures.php';
$checks = 0;
function checkStreetResult($condition, $message)
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
}

$analytics = buildWasteAnalyticsFromRecords($records);
$locations = $analytics['heatmap_street_locations'];
$byName = array_column($locations, null, 'name');
checkStreetResult(count($locations) === 11, 'Every named street/establishment must have a separate entry.');
checkStreetResult(array_sum(array_column($locations, 'total_waste')) === 5000.0, 'Street totals must not duplicate or lose saved waste.');
$agoho = $byName['Phase 6 - Agoho St.'];
$yakal = $byName['Phase 6 - Yakal St.'];
checkStreetResult($agoho['total_waste'] === 600.0 && $agoho['record_count'] === 2, 'Sum repeat street records across periods.');
checkStreetResult($yakal['total_waste'] === 900.0 && $agoho['id'] !== $yakal['id'], 'Agoho and Yakal must not be merged.');
checkStreetResult($agoho['map_location_id'] === $yakal['map_location_id'], 'Separate streets retain their existing geographic area reference.');
checkStreetResult($agoho['waste_level']['label'] === 'Medium-Low' && $yakal['waste_level']['label'] === 'High', 'Waste levels must use individual street totals.');
checkStreetResult($byName['Phase 1A - Bell St.']['total_waste'] === 800.0 && $byName['Phase 1A - Bell St.']['record_count'] === 2, 'Phase 1-A and Phase 1A aliases must combine the same street.');
checkStreetResult(isset($byName['Phase 5 - Aluminum St.'], $byName['Phase 5 - Sodium St.']), 'Phase 5 streets must be listed individually.');
checkStreetResult($byName['Toyota']['total_waste'] === 200.0 && $byName['Jollibee']['total_waste'] === 100.0, 'Establishments must use their own saved totals.');
checkStreetResult($byName['San Manuel Bakery']['lat'] === null && $byName['Corner Store']['lng'] === null, 'Unmapped establishments stay listed without fabricated coordinates.');
checkStreetResult(count($analytics['heatmap_locations']) === 8, 'Existing area analytics used by other screens remain available.');
checkStreetResult(count($analytics['heatmap_street_legend']) === 4, 'All four individual-location bands have legend entries.');

$analyticsWithBlankStreet = buildWasteAnalyticsFromRecords(array_merge($records, [
    ['collection_date' => '2025-03-01', 'phase_number' => 'Phase 6', 'street' => '', 'kilogram_of_waste' => 77],
]));
checkStreetResult($analyticsWithBlankStreet['summary']['total_waste'] === 5077.0, 'Blank-street records remain in total waste analytics.');
checkStreetResult(count($analyticsWithBlankStreet['heatmap_street_locations']) === 11, 'Blank-street records do not create a Heatmap location.');
checkStreetResult(
    array_sum(array_column($analyticsWithBlankStreet['heatmap_locations'], 'total_waste')) === array_sum(array_column($analytics['heatmap_locations'], 'total_waste')),
    'Blank-street records do not add to mapped Heatmap area totals.'
);

$additional = buildHeatmapStreetLocations(array_merge($records, [
    ['phase_number' => 'Phase 5', 'street' => 'Bell St.', 'kilogram_of_waste' => 999, 'residual_waste_kg' => 10],
    ['phase_number' => 'Phase 10', 'street' => 'Bell St.', 'kilogram_of_waste' => 20],
    ['phase_number' => 'Phase 6', 'street' => '  agoho   St. ', 'kilogram_of_waste' => 30],
    ['phase_number' => 'Phase 1A', 'street' => '', 'kilogram_of_waste' => 77],
]));
$additionalByName = array_column($additional, null, 'name');
checkStreetResult($additionalByName['Phase 5 - Bell St.']['total_waste'] === 10.0, 'The same street in different phases stays separate and uses effective waste components.');
checkStreetResult($additionalByName['Phase 10 - Bell St.']['map_location_id'] === null, 'Phase 10 must not inherit Phase 1 coordinates.');
checkStreetResult($additionalByName['Phase 6 - Agoho St.']['total_waste'] === 630.0, 'Street whitespace/case variants must not create duplicate entries.');
checkStreetResult(count($additional) === 13, 'Blank-street records must not create a fictitious named location.');
checkStreetResult($additionalByName['Phase 6 - Agoho St.']['id'] === $agoho['id'], 'A location ID stays stable as its totals change.');
checkStreetResult(count(buildHeatmapStreetLocations([])) === 2, 'Known establishments are available before their records are uploaded.');
echo 'PASS: ' . $checks . " street analytics checks\n";

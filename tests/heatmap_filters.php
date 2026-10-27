<?php

// Browser regression fixture: renders the real heatmap with synthetic data only.
// Open /EcoTrack/tests/heatmap_filters.php with Apache/PHP running.
require_once __DIR__ . '/../includes/waste_analytics.php';

$records = require __DIR__ . '/heatmap_street_fixtures.php';
$selection = resolveHeatmapPeriodSelection(['2026-01-01', '2026-01-08'], 'week', '2026-W02');
$current = [];
$previous = [];
foreach ($records as $index => $record) {
    $record['id'] = $index + 1;
    $record['waste_record_id'] = $index + 1;
    $record['kilogram_of_waste'] = wasteRecordEffectiveKg($record);
    $current[] = $record;
    $previousRecord = $record;
    $previousRecord['kilogram_of_waste'] = $record['kilogram_of_waste'] * 1.5;
    $previous[] = $previousRecord;
}
$analytics = buildHeatmapPeriodAnalytics($records, $current, $previous, $selection);
$locations = $analytics['locations'];
$payload = $analytics;
$annualFixtures = [];
foreach ([2025, 2026] as $fixtureYear) {
    $annualCurrent = $current;
    foreach ($annualCurrent as $index => &$allocation) {
        $allocation['allocation_date'] = $fixtureYear . '-12-31';
        $allocation['kilogram_of_waste'] *= $fixtureYear === 2025 ? ($index % 3) + 1 : 1;
    }
    unset($allocation);
    $annualSelection = resolveHeatmapPeriodSelection([$fixtureYear . '-12-31'], 'year', (string)$fixtureYear);
    $annualFixtures[$fixtureYear] = buildHeatmapPeriodAnalytics($records, $annualCurrent, [], $annualSelection);
}
$fixtureSearch = (string)($_GET['search'] ?? '');

$source = file_get_contents(__DIR__ . '/../waste_heatmap.php');
$html = substr($source, strpos($source, '<!DOCTYPE html>'));
if (isset($_GET['without_leaflet'])) {
    $html = preg_replace('#<script src="https://unpkg\.com/leaflet@1\.9\.4/dist/leaflet\.js"[^>]*></script>#', '', $html, 1);
}
$html = preg_replace_callback('/<\?php(.*?)\?>/s', static function ($match) use ($locations, $analytics, $fixtureSearch) {
    if (strpos($match[1], '$heatmapLocationSearch') !== false) {
        return htmlspecialchars($fixtureSearch, ENT_QUOTES, 'UTF-8');
    }
    if (strpos($match[1], 'foreach') !== false) {
        return '';
    }
    if (strpos($match[1], '$heatmapCalendarYearsJson') !== false) {
        return json_encode([2026, 2025, 2024]);
    }
    if (strpos($match[1], '$heatmapCalendarMonthsJson') !== false) {
        return json_encode(['2026' => [1, 2, 3], '2025' => [9], '2024' => [12]]);
    }
    if (strpos($match[1], '$heatmapLocationsJson') !== false) {
        return json_encode($locations);
    }
    if (strpos($match[1], '$heatmapSelection, JSON_HEX') !== false) {
        return json_encode($analytics['selection']);
    }
    if (strpos($match[1], '$heatmapPhaseMetrics, JSON_HEX') !== false) {
        return json_encode($analytics['phase_metrics']);
    }
    if (strpos($match[1], '$heatmapDataUrl, JSON_HEX') !== false) {
        return json_encode('waste_heatmap.php?format=json&view=week&period=2026-W02');
    }
    if (strpos($match[1], "\$_SESSION['user_id']") !== false || strpos($match[1], "\$user['id']") !== false) {
        return json_encode('browser-regression');
    }
    return '';
}, $html);
$html = preg_replace(
    '#<select id="heatmapYear" name="period"[^>]*>.*?</select>#s',
    '<select id="heatmapYear" name="period"><option value="2026">2026</option><option value="2025">2025</option><option value="2024">2024</option></select>',
    $html,
    1
);
$setup = '<base href="../"><script>
    window.heatmapFixture = ' . json_encode($payload) . ';
    window.heatmapAnnualFixtures = ' . json_encode($annualFixtures) . ';
    window.heatmapFixtureErrors = [];
    window.addEventListener("error", function (event) {
        window.heatmapFixtureErrors.push(event.message || "Unknown browser error");
    });
</script>' .
    '<script src="tests/heatmap_filters.browser.js"></script>';
$html = str_replace('<head>', '<head>' . $setup, $html);
echo $html;

<?php

// Browser regression fixture for the Street month-range and custom-date filters.
// Open /EcoTrack/tests/street_chart_filters.php with Apache/PHP running.
$dashboardSource = file_get_contents(__DIR__ . '/../admin_dashboard.php');
$rangeStart = strpos($dashboardSource, '        function initializeStreetFilters');
$tooltipStart = strpos($dashboardSource, '        function initializeStreetChartTooltips', $rangeStart);
$refreshStart = strpos($dashboardSource, '        async function refreshVisualization', $tooltipStart);
$refreshEnd = strpos($dashboardSource, '        document.addEventListener("submit",', $refreshStart);
if ($rangeStart === false || $tooltipStart === false || $refreshStart === false || $refreshEnd === false) {
    http_response_code(500);
    exit('Street filter script could not be loaded.');
}
$rangeScript = substr($dashboardSource, $rangeStart, $tooltipStart - $rangeStart);
$visualizationScript = substr($dashboardSource, $tooltipStart, $refreshStart - $tooltipStart);
$refreshScript = substr($dashboardSource, $refreshStart, $refreshEnd - $refreshStart);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Street month-range filter fixture</title>
    <style>
        [hidden] { display: none !important; }
        .year-picker-popover { position: fixed; width: 280px; padding: 8px; background: #fff; border: 1px solid #aaa; }
        .street-chart-tooltip { position: fixed; background: #163629; color: white; padding: 6px; white-space: pre-line; }
    </style>
    <script src="../assets/js/year-navigable-picker.js"></script>
</head>
<body>
    <section data-visualization-key="area">
        <div data-street-main-view>
        <form class="street-chart-filters visualization-filter-control" data-street-chart-filter data-street-filter-state-url="street_chart_filter_state.php">
            <label>Phase
                <select id="street-phase" name="street_phase"><option value="">All Phases</option><option value="Phase 1">Phase 1</option><option value="Phase 2">Phase 2</option></select>
            </label>
            <label>Category
                <select id="street-category" name="street_category"><option value="all">All Categories</option><option value="residual">Residual</option></select>
            </label>
            <label>Period
                <select id="street-period" name="street_period" data-street-period-mode><option value="all">All dates</option><option value="range" selected>Month Range</option><option value="custom">Custom Dates</option></select>
            </label>
            <div data-street-period-field="range">
                <label for="street-range-start-month">From month</label>
                <input id="street-range-start-month" name="street_range_start_month" type="month" value="2025-01" data-year-navigable-picker="month" data-year-picker-allow-empty="true" data-calendar-years='[2026,2025]' data-calendar-months='{"2026":[2],"2025":[1,12]}'>
            </div>
            <div data-street-period-field="range">
                <label for="street-range-end-month">To month</label>
                <input id="street-range-end-month" name="street_range_end_month" type="month" value="2025-01" data-year-navigable-picker="month" data-year-picker-allow-empty="true" data-calendar-years='[2026,2025]' data-calendar-months='{"2026":[2],"2025":[1,12]}'>
            </div>
            <div data-street-period-field="custom" hidden>
                <label for="street-custom-start">Start date</label>
                <input id="street-custom-start" name="street_custom_start" type="date" data-year-navigable-picker="date" data-year-picker-all-days-in-available-month="true" data-year-picker-allow-empty="true" data-calendar-years='[2025]' data-calendar-months='{"2025":[1]}'>
            </div>
            <div data-street-period-field="custom" hidden>
                <label for="street-custom-end">End date</label>
                <input id="street-custom-end" name="street_custom_end" type="date" data-year-navigable-picker="date" data-year-picker-all-days-in-available-month="true" data-year-picker-allow-empty="true" data-calendar-years='[2025]' data-calendar-months='{"2025":[1]}'>
            </div>
            <button type="submit" data-street-filter-submit>Filter</button>
            <button type="button" data-street-filter-reset>Reset</button>
        </form>
        <p id="street-filter-status" class="street-filter-status" role="status"></p>
        <div data-street-chart-order="Acacia St.|Birch St.">
            <button type="button" class="street-chart-row-label street-chart-detail-trigger" data-street-detail-key="phase-1-acacia">Acacia St.</button>
            <button type="button" class="street-chart-bar street-chart-detail-trigger" data-street-detail-key="phase-1-acacia">Acacia bar</button>
            <button type="button" class="street-chart-details-button street-chart-detail-trigger" data-street-detail-key="phase-1-acacia">View Details</button>
        </div>
        <button type="button" data-street-tooltip="Phase 1 · Acacia St.&#10;Total: 12.00 kg">Bar</button>
        <div data-street-chart-tooltip role="tooltip" hidden></div>
        </div>
        <script type="application/json" data-street-details-data>{"phase-1-acacia":{"key":"phase-1-acacia","phase_name":"Phase 1","street":"Acacia St.","total_waste":12,"categories":{"returnable":{"label":"Factory Returnable","color":"#228B22","amount":5,"percent":41.6667},"residual":{"label":"Residual","color":"#6B7280","amount":7,"percent":58.3333}}}}</script>
        <section data-street-detail-view hidden aria-labelledby="street-detail-title">
            <button type="button" data-street-detail-back>Back to Street Collection</button>
            <div data-street-detail-content>
                <h3 id="street-detail-title" tabindex="-1">Street Collection Details</h3>
                <strong data-street-detail-street></strong>
                <strong data-street-detail-phase></strong>
                <strong data-street-detail-total></strong>
                <div data-street-detail-stack role="img"></div>
                <div data-street-detail-category-list></div>
            </div>
            <div data-street-detail-empty hidden></div>
        </section>
    </section>
    <script>
        let dashboardExplanationData = {};
        const explanationCache = new Map();
<?= $rangeScript ?>
<?= $visualizationScript ?>
<?= $refreshScript ?>
        document.addEventListener("submit", function (event) {
            const form = event.target.closest("form.visualization-filter-control");
            if (!form) return;
            event.preventDefault();
            refreshVisualization(form);
        });
        initializeStreetVisualization(document);
    </script>
    <script src="street_chart_filters.browser.js"></script>
</body>
</html>

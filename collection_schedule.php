<?php
require_once 'config.php';
require_once 'includes/waste_analytics.php';

requireUserType('admin');
$collectionScheduleRoute = 'admin_collection_schedule.php';
if (empty($canonicalRouteEntry) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $routeQuery = http_build_query($_GET);
    header('Location: ' . $collectionScheduleRoute . ($routeQuery === '' ? '' : '?' . $routeQuery));
    exit();
}
if (isSessionTimeout()) {
    logoutUser();
    header('Location: login.php?timeout=1');
    exit();
}
updateLastActivity();

$conn = getDBConnection();
ensureWasteFormatColumns($conn);
ensureWasteAnalyticsColumns($conn);
ensureWasteDailyAllocationTable($conn);
$error = '';
$collectionGroup = trim((string)($_GET['collection_group'] ?? ''));
$requestedDssMode = trim((string)($_GET['mode'] ?? ''));
$dssMode = in_array($requestedDssMode, ['daily', 'month', 'year'], true) ? $requestedDssMode : 'daily';
$dssView = $dssMode === 'daily' ? 'week' : $dssMode;
$dssPeriod = trim((string)($_GET['period'] ?? ''));
$dssStartDate = $dssMode === 'daily' ? (normalizeHeatmapDateInput($_GET['start_date'] ?? '') ?? '') : '';
$dssEndDate = $dssMode === 'daily' ? (normalizeHeatmapDateInput($_GET['end_date'] ?? '') ?? '') : '';
$groups = [];
$recommendations = [];
$dssSelection = resolveDssWeekDateRangeSelection($dssStartDate, $dssEndDate)
    ?: resolveHeatmapPeriodSelection([], $dssView, $dssPeriod, $dssMode === 'month');
$dssLegend = buildDssWasteLegend($dssSelection);
$dssSummary = ['total_waste' => 0.0, 'record_count' => 0];
$dssCalendarYears = [];

try {
    $groups = getActiveWasteCollectionGroups($conn);
    if ($collectionGroup !== '' && !in_array($collectionGroup, $groups, true)) {
        $collectionGroup = '';
    }

    $analytics = fetchDssPeriodAnalytics(
        $conn,
        $dssView,
        $dssPeriod,
        $dssStartDate,
        $dssEndDate,
        $collectionGroup,
        $dssMode === 'month'
    );
    $dssSelection = $analytics['selection'];
    $dssLegend = $analytics['legend'];
    $dssSummary = $analytics['summary'];
    $recommendations = buildDssRecommendations($analytics['phase_totals'], [], 3);
    $dssCalendarYears = getImportedWasteCalendarYears($conn);
} catch (PDOException $e) {
    $error = 'DSS data could not load. Please check the Waste Data records and refresh.';
}

$scopeLabel = ($collectionGroup !== '' ? $collectionGroup : 'All Collection Groups') . ' / ' . $dssSelection['label'];
$dssCalendarYearsJson = json_encode($dssCalendarYears, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Collection DSS - EcoTrack</title>
    <?php include 'includes/theme_head.php'; ?>
    <link rel="stylesheet" href="assets/css/ecotrack-theme.css?v=<?php echo filemtime(__DIR__ . '/assets/css/ecotrack-theme.css'); ?>">
    <link rel="stylesheet" href="assets/css/dss-responsive.css?v=<?php echo filemtime(__DIR__ . '/assets/css/dss-responsive.css'); ?>">
    <script defer src="assets/js/year-navigable-picker.js?v=<?php echo filemtime(__DIR__ . '/assets/js/year-navigable-picker.js'); ?>"></script>
</head>
<body class="dss-page">
    <?php
    $active_page = $collectionScheduleRoute;
    $useLogoutModal = true;
    include 'includes/sidebar.php';
    ?>
    <main class="main-content dss-page__content">
        <header class="header dss-page__header">
            <div>
                <h1>Collection Scheduling DSS</h1>
                <p class="page-kicker">Prioritize collection frequency using selected-period totals and timeframe-scaled waste thresholds.</p>
            </div>
            <div class="header-icons">
                <?php include 'includes/notification_bell.php'; ?>
            </div>
        </header>

        <?php if ($error !== ''): ?>
            <div class="message error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form class="dss-toolbar" method="get" action="<?php echo $collectionScheduleRoute; ?>">
            <div class="dss-period-filter">
                <span class="filter-field__label">Time View</span>
                <div class="dss-mode-selector" role="radiogroup" aria-label="Collection DSS time view">
                    <label class="dss-mode-option"><input type="radio" name="mode" value="daily" <?php echo $dssMode === 'daily' ? 'checked' : ''; ?>> Daily 1-week range</label>
                    <label class="dss-mode-option"><input type="radio" name="mode" value="month" <?php echo $dssMode === 'month' ? 'checked' : ''; ?>> Monthly view</label>
                    <label class="dss-mode-option"><input type="radio" name="mode" value="year" <?php echo $dssMode === 'year' ? 'checked' : ''; ?>> Yearly view</label>
                </div>
                <input type="hidden" name="view" value="<?php echo $dssMode === 'daily' ? 'week' : $dssMode; ?>">
                <div class="dss-mode-panel" data-dss-mode-panel="daily" <?php echo $dssMode === 'daily' ? '' : 'hidden'; ?>>
                    <div class="dss-date-fields">
                        <div class="dss-date-field"><label for="dssStartDate">Start date</label><input id="dssStartDate" type="date" name="start_date" data-year-navigable-picker="date" data-calendar-years='<?php echo htmlspecialchars($dssCalendarYearsJson, ENT_QUOTES, 'UTF-8'); ?>' value="<?php echo htmlspecialchars($dssSelection['start']); ?>" <?php echo $dssMode === 'daily' ? 'required' : 'disabled'; ?>></div>
                        <div class="dss-date-field"><label for="dssEndDate">End date</label><input id="dssEndDate" type="date" name="end_date" data-year-navigable-picker="date" data-calendar-years='<?php echo htmlspecialchars($dssCalendarYearsJson, ENT_QUOTES, 'UTF-8'); ?>' value="<?php echo htmlspecialchars($dssSelection['end']); ?>" <?php echo $dssMode === 'daily' ? 'required' : 'disabled'; ?>></div>
                    </div>
                </div>
                <div class="dss-mode-panel" data-dss-mode-panel="month" <?php echo $dssMode === 'month' ? '' : 'hidden'; ?>>
                    <div class="dss-date-field"><label for="dssMonth">Month</label><input id="dssMonth" type="month" name="period" data-year-navigable-picker="month" data-calendar-years='<?php echo htmlspecialchars($dssCalendarYearsJson, ENT_QUOTES, 'UTF-8'); ?>' value="<?php echo htmlspecialchars($dssMode === 'month' ? $dssSelection['period'] : substr($dssSelection['start'], 0, 7)); ?>" <?php echo $dssMode === 'month' ? 'required' : 'disabled'; ?>></div>
                </div>
                <div class="dss-mode-panel" data-dss-mode-panel="year" <?php echo $dssMode === 'year' ? '' : 'hidden'; ?>>
                    <div class="dss-date-field"><label for="dssYear">Year</label><select id="dssYear" name="period" <?php echo $dssMode === 'year' && !empty($dssCalendarYears) ? 'required' : 'disabled'; ?>>
                        <?php if (empty($dssCalendarYears)): ?>
                            <option value="">No imported years available</option>
                        <?php else: ?>
                            <?php foreach ($dssCalendarYears as $calendarYear): ?>
                                <option value="<?php echo (int)$calendarYear; ?>" <?php echo $dssMode === 'year' && (string)$calendarYear === $dssSelection['period'] ? 'selected' : ''; ?>><?php echo (int)$calendarYear; ?></option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select></div>
                </div>
            </div>
            <div class="filter-field">
                <label for="collection_group">Collection Group</label>
                <select id="collection_group" name="collection_group">
                    <option value="">All Collection Groups</option>
                    <?php foreach ($groups as $group): ?>
                        <option value="<?php echo htmlspecialchars($group); ?>" <?php echo $collectionGroup === $group ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($group); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="dss-toolbar__actions">
                <button type="submit" class="btn btn-green" id="dssPeriodSubmit"><?php echo $dssMode === 'month' ? 'Apply month' : ($dssMode === 'year' ? 'Apply year' : 'Apply dates'); ?></button>
                <a href="<?php echo $collectionScheduleRoute; ?>" class="btn btn-gray">Reset</a>
            </div>
        </form>

        <p class="scope"><strong>Analyzing:</strong> <?php echo htmlspecialchars($scopeLabel); ?> &middot; <?php echo (int)$dssSummary['record_count']; ?> record<?php echo (int)$dssSummary['record_count'] === 1 ? '' : 's'; ?></p>

        <section class="dss-legend" aria-label="Timeframe-scaled waste range legend">
            <?php foreach ($dssLegend as $legendItem): ?>
                <div class="dss-legend__item"><span class="dss-legend__swatch" style="background: <?php echo htmlspecialchars($legendItem['color']); ?>;"></span><span><strong><?php echo htmlspecialchars($legendItem['color_name']); ?></strong> &mdash; <?php echo htmlspecialchars($legendItem['range']); ?> &mdash; <?php echo htmlspecialchars($legendItem['label']); ?></span></div>
            <?php endforeach; ?>
        </section>

        <?php if (empty($recommendations)): ?>
            <div class="empty-state">
                <strong>No scheduling recommendation yet</strong>
                <span>Add positive Waste Data records in this selected timeframe and collection group to identify priority areas.</span>
            </div>
        <?php else: ?>
            <div class="dss-grid">
                <?php foreach ($recommendations as $recommendation): ?>
                    <?php $level = $recommendation['waste_level']; ?>
                    <article class="dss-card" style="--dss-color:<?php echo htmlspecialchars($level['color']); ?>">
                        <div class="dss-card__header">
                            <span class="dss-rank">Priority <?php echo (int)$recommendation['rank']; ?></span>
                            <h2><?php echo htmlspecialchars($recommendation['phase_name']); ?></h2>
                        </div>
                        <div class="dss-card__details">
                            <p class="dss-card__detail"><strong>Selected-period total:</strong> <?php echo number_format((float)$recommendation['total_waste'], 2); ?> kg</p>
                            <p class="dss-card__detail"><strong>Average per record:</strong> <?php echo number_format((float)$recommendation['average_waste'], 2); ?> kg across <?php echo (int)$recommendation['record_count']; ?> record<?php echo (int)$recommendation['record_count'] === 1 ? '' : 's'; ?></p>
                            <p class="dss-card__detail"><strong>Level:</strong> <span style="color: <?php echo htmlspecialchars($level['color']); ?>; font-weight: 700;"><?php echo htmlspecialchars($level['label']); ?> (<?php echo htmlspecialchars($level['color_name']); ?>)</span></p>
                            <p class="dss-card__detail"><strong>Recommended:</strong> <?php echo (int)$recommendation['frequency']; ?> time<?php echo (int)$recommendation['frequency'] === 1 ? '' : 's'; ?>/week</p>
                            <p class="dss-card__detail"><strong>Suggested:</strong> <?php echo htmlspecialchars(implode(', ', $recommendation['suggested_days'])); ?></p>
                            <p class="dss-card__detail dss-card__detail--reason"><strong>Reason:</strong> <?php echo htmlspecialchars($recommendation['reason']); ?></p>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <section class="method-card">
            <h2>Recommendation method</h2>
            <ul>
                <li>Areas are ranked by total waste in the selected timeframe.</li>
                <li>Color levels use the fixed, timeframe-scaled Red, Orange, Yellow, and Green DSS thresholds.</li>
                <li>High areas are scheduled three times weekly (Monday, Wednesday, Friday); Medium-High areas twice (Tuesday, Friday); lower levels once (Thursday).</li>
                <li>Suggested dates always begin on the next Monday and are planning recommendations, not automatically assigned routes.</li>
            </ul>
        </section>
    </main>
    <?php include 'includes/logout_confirmation_modal.php'; ?>
    <script>
      (function () {
        var form = document.querySelector('.dss-toolbar');
        if (!form) return;

        var modeInputs = form.querySelectorAll('input[name="mode"]');
        var viewInput = form.querySelector('input[name="view"]');
        var dailyPanel = form.querySelector('[data-dss-mode-panel="daily"]');
        var monthPanel = form.querySelector('[data-dss-mode-panel="month"]');
        var yearPanel = form.querySelector('[data-dss-mode-panel="year"]');
        var dateInputs = dailyPanel.querySelectorAll('input[type="date"]');
        var startInput = document.getElementById('dssStartDate');
        var endInput = document.getElementById('dssEndDate');
        var monthInput = document.getElementById('dssMonth');
        var yearInput = document.getElementById('dssYear');
        var submitButton = document.getElementById('dssPeriodSubmit');

        function selectedDssMode() {
          var selectedInput = form.querySelector('input[name="mode"]:checked');
          return selectedInput ? selectedInput.value : 'daily';
        }

        function shiftIsoDate(value, days) {
          if (!/^\d{4}-\d{2}-\d{2}$/.test(value || '')) return '';
          var parts = value.split('-');
          var date = new Date(Date.UTC(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2])));
          date.setUTCDate(date.getUTCDate() + days);
          return date.toISOString().slice(0, 10);
        }

        function setDailyWeekFromStart() {
          var endDate = shiftIsoDate(startInput.value, 6);
          if (endDate) endInput.value = endDate;
        }

        function setDailyWeekFromEnd() {
          var startDate = shiftIsoDate(endInput.value, -6);
          if (startDate) startInput.value = startDate;
        }

        function setDssMode(mode) {
          var isDaily = mode === 'daily';
          var isMonthly = mode === 'month';
          var isYearly = mode === 'year';
          dailyPanel.hidden = !isDaily;
          monthPanel.hidden = !isMonthly;
          yearPanel.hidden = !isYearly;
          viewInput.value = isDaily ? 'week' : mode;
          dateInputs.forEach(function (input) {
            input.disabled = !isDaily;
            input.required = isDaily;
          });
          monthInput.disabled = !isMonthly;
          monthInput.required = isMonthly;
          var hasAvailableYear = Array.prototype.some.call(yearInput.options, function (option) {
            return option.value !== '';
          });
          yearInput.disabled = !isYearly || !hasAvailableYear;
          yearInput.required = isYearly && !yearInput.disabled;
          if (isDaily) setDailyWeekFromStart();
          if (window.EcoTrackYearPicker) {
            dateInputs.forEach(function (input) { window.EcoTrackYearPicker.sync(input); });
            window.EcoTrackYearPicker.sync(monthInput);
          }
          submitButton.textContent = isMonthly ? 'Apply month' : (isYearly ? 'Apply year' : 'Apply dates');
          submitButton.disabled = isYearly && !hasAvailableYear;
          modeInputs.forEach(function (input) {
            input.closest('.dss-mode-option').classList.toggle('is-selected', input.checked);
          });
        }

        modeInputs.forEach(function (input) {
          input.addEventListener('change', function () {
            if (input.checked) setDssMode(input.value);
          });
        });
        startInput.addEventListener('change', setDailyWeekFromStart);
        endInput.addEventListener('change', setDailyWeekFromEnd);
        document.addEventListener('ecotrackyearpickerready', function () {
          setDssMode(selectedDssMode());
        });
        setDssMode(selectedDssMode());
      })();
    </script>
</body>
</html>

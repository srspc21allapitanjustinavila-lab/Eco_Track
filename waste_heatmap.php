<?php
require_once 'config.php';
require_once 'includes/waste_analytics.php';

$isHeatmapDataRequest = ($_GET['format'] ?? '') === 'json';
if ($isHeatmapDataRequest) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
}

if (!$isHeatmapDataRequest) {
    requireLogin();
} elseif (!isLoggedIn()) {
    if ($isHeatmapDataRequest) {
        http_response_code(401);
        echo json_encode(['error' => 'Authentication required.']);
        exit();
    }
    header("Location: login.php");
    exit();
}

if ($isHeatmapDataRequest && isSessionTimeout()) {
    logoutUser();
    if ($isHeatmapDataRequest) {
        http_response_code(401);
        echo json_encode(['error' => 'Session expired.']);
        exit();
    }
    header("Location: login.php?timeout=1");
    exit();
}

// Get current user info
// Keep saved streets and establishments separate while using approved map areas.
$conn = getDBConnection();
if ($isHeatmapDataRequest && $conn) {
    try {
        $sessionUserId = (int)($_SESSION['user_id'] ?? 0);
        $liveUserStmt = $conn->prepare('SELECT id, username, first_name, last_name, email, user_type, is_active FROM users WHERE id = ? AND is_active = 1 LIMIT 1');
        $liveUserStmt->execute([$sessionUserId]);
        $liveUser = $liveUserStmt->fetch();
        if (!$liveUser) {
            logoutUser();
            http_response_code(401);
            echo json_encode(['error' => 'Authentication required.']);
            exit();
        }
        $_SESSION['user'] = array_merge((array)($_SESSION['user'] ?? []), $liveUser);
        updateLastActivity();
    } catch (PDOException $e) {
        http_response_code(401);
        echo json_encode(['error' => 'Authentication required.']);
        exit();
    }
}
$user_type = $_SESSION['user']['user_type'] ?? 'staff';
$wasteHeatmapRoute = $user_type === 'staff' ? 'staff_waste_heatmap.php' : 'admin_waste_heatmap.php';
if (empty($canonicalRouteEntry) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $routeQuery = http_build_query($_GET);
    header('Location: ' . $wasteHeatmapRoute . ($routeQuery === '' ? '' : '?' . $routeQuery));
    exit();
}
$user = getCurrentUser();
ensureWasteFormatColumns($conn);
ensureWasteAnalyticsColumns($conn);
ensureWasteDailyAllocationTable($conn);
$wasteDataState = getWasteDataVersion($conn);
$legacyYear = normalizeWasteCollectionYear($_GET['year'] ?? '');
$requestedHeatmapMode = trim((string)($_GET['mode'] ?? ''));
$requestedHeatmapView = normalizeHeatmapPeriodView($_GET['view'] ?? '');
$heatmapMode = in_array($requestedHeatmapMode, ['daily', 'month', 'year'], true)
    ? $requestedHeatmapMode
    : (($requestedHeatmapMode === '' && ($_GET['view'] ?? '') === 'month') || $requestedHeatmapView === 'month'
        ? 'month'
        : (($legacyYear !== '' || $requestedHeatmapView === 'year') ? 'year' : 'daily'));
$heatmapView = $heatmapMode === 'daily' ? 'week' : $heatmapMode;
$heatmapPeriod = trim((string)($_GET['period'] ?? ($legacyYear !== '' ? $legacyYear : '')));
$heatmapStartDate = $heatmapMode === 'daily' ? (normalizeHeatmapDateInput($_GET['start_date'] ?? '') ?? '') : '';
$heatmapEndDate = $heatmapMode === 'daily' ? (normalizeHeatmapDateInput($_GET['end_date'] ?? '') ?? '') : '';
$heatmapSelection = $heatmapMode === 'daily'
    ? (resolveHeatmapDateRangeSelection($heatmapStartDate, $heatmapEndDate) ?: resolveHeatmapPeriodSelection([], $heatmapView, $heatmapPeriod))
    : resolveHeatmapPeriodSelection([], $heatmapView, $heatmapPeriod, $heatmapMode === 'month');
$heatmapLocations = [];
$heatmapLegend = buildDailyWasteLegend();
$heatmapPhaseMetrics = [];
$heatmapSummary = ['total_waste' => 0.0, 'location_count' => 0];
$heatmapCalendarYears = [];

try {
    if (!$conn) {
        throw new PDOException('Database connection unavailable.');
    }
    $heatmapAnalytics = fetchHeatmapPeriodAnalytics($conn, $heatmapView, $heatmapPeriod, $heatmapStartDate, $heatmapEndDate, $heatmapMode === 'month');
    $heatmapLocations = $heatmapAnalytics['locations'];
    $heatmapLegend = $heatmapAnalytics['legend'];
    $heatmapSelection = $heatmapAnalytics['selection'];
    $heatmapPhaseMetrics = $heatmapAnalytics['phase_metrics'];
    $heatmapSummary = $heatmapAnalytics['summary'];
    $heatmapCalendarYears = getImportedWasteCalendarYears($conn);
} catch (PDOException $e) {
    error_log('Shared heatmap analytics could not load: ' . $e->getMessage());
    if ($isHeatmapDataRequest) {
        http_response_code(503);
        echo json_encode(['error' => 'Waste data is temporarily unavailable.']);
        exit();
    }
}
if ($isHeatmapDataRequest) {
    echo json_encode([
        'locations' => $heatmapLocations,
        'legend' => $heatmapLegend,
        'selection' => $heatmapSelection,
        'phase_metrics' => $heatmapPhaseMetrics,
        'summary' => $heatmapSummary,
    ]);
    exit();
}
$heatmapLocationsJson = json_encode($heatmapLocations, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$heatmapCalendarYearsJson = json_encode($heatmapCalendarYears, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$heatmapDataUrl = $heatmapMode === 'daily'
    ? $wasteHeatmapRoute . '?format=json&mode=daily&start_date=' . rawurlencode($heatmapSelection['start']) . '&end_date=' . rawurlencode($heatmapSelection['end'])
    : $wasteHeatmapRoute . '?format=json&mode=' . rawurlencode($heatmapMode) . '&view=' . rawurlencode($heatmapView) . '&period=' . rawurlencode($heatmapSelection['period']);
$notificationItems = getNotificationItems($conn, $user);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Heatmap - EcoTrack</title>
    <!-- Leaflet CSS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />
    <!-- Leaflet JS -->
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    <?php include 'includes/theme_head.php'; ?>
    <style>
        /* CSS Variables for Light/Dark Mode */
        :root {
          --bg-primary: #f5f5f5;
          --bg-secondary: white;
          --bg-sidebar: linear-gradient(180deg, #8bc34a 0%, #689f38 100%);
          --text-primary: #333;
          --text-secondary: #666;
          --text-muted: #999;
          --border-color: #ddd;
          --card-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }
        [data-theme="dark"] {
          --bg-primary: #1a1a1a;
          --bg-secondary: #2d2d2d;
          --bg-sidebar: linear-gradient(180deg, #2e4a20 0%, #1e3a10 100%);
          --text-primary: #ffffff;
          --text-secondary: #cccccc;
          --text-muted: #888888;
          --border-color: #444;
          --card-shadow: 0 2px 10px rgba(0, 0, 0, 0.3);
        }
        * {
          margin: 0;
          padding: 0;
          box-sizing: border-box;
        }

        body {
          font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
          background: var(--bg-primary);
          color: var(--text-primary);
          transition:
            background 0.3s,
            color 0.3s;
          display: flex;
          min-height: 100vh;
        }

        /* Sidebar - Matching Admin Dashboard */
        .sidebar {
          width: 280px;
          background: var(--bg-sidebar);
          color: white;
          display: flex;
          flex-direction: column;
          padding: 20px 0;
          position: fixed;
          height: 100vh;
        }

        .logo-section {
          padding: 20px;
          text-align: center;
          border-bottom: 1px solid rgba(255, 255, 255, 0.2);
          margin-bottom: 20px;
        }

        .logo {
          width: 60px;
          height: 60px;
          background: white;
          border-radius: 50%;
          display: flex;
          align-items: center;
          justify-content: center;
          margin: 0 auto 10px;
          font-size: 28px;
        }

        .logo-text {
          font-size: 13px;
          font-weight: bold;
          line-height: 1.3;
          text-transform: uppercase;
        }

        .nav-menu {
          flex: 1;
          padding: 0 15px;
        }

        .nav-item {
          display: flex;
          align-items: center;
          padding: 12px 20px;
          margin: 5px 0;
          border-radius: 25px;
          cursor: pointer;
          transition: all 0.3s;
          text-decoration: none;
          color: white;
        }

        .nav-item:hover,
        .nav-item.active {
          background: rgba(255, 255, 255, 0.2);
        }

        .nav-icon {
          width: 24px;
          height: 24px;
          margin-right: 15px;
        }

        .nav-text {
          font-size: 13px;
          font-weight: 500;
        }

        .bottom-menu {
          padding: 0 15px 20px;
        }

        /* Main Content */
        .main-content {
          flex: 1;
          margin-left: 280px;
          padding: 20px;
        }

        .header {
          display: flex;
          justify-content: space-between;
          align-items: center;
          margin-bottom: 30px;
        }

        .header h1 {
          color: #8bc34a;
          font-size: 28px;
          font-weight: 600;
          letter-spacing: 1px;
        }

        .header-icons {
          display: flex;
          gap: 20px;
        }

        .header-icons a {
          color: var(--text-secondary);
          text-decoration: none;
          font-size: 20px;
        }

        /* Filter Buttons Section */
        .filter-section {
          display: flex;
          gap: 15px;
          margin-bottom: 30px;
          flex-wrap: wrap;
        }

        .btn {
          padding: 12px 25px;
          border: none;
          border-radius: 25px;
          cursor: pointer;
          font-size: 13px;
          font-weight: 600;
          text-transform: uppercase;
          transition: all 0.3s;
          display: inline-flex;
          align-items: center;
          gap: 8px;
          text-decoration: none;
        }

        .btn-green {
          background: var(--toggle-active);
          color: white;
        }

        .btn-green:hover {
          background: #7cb342;
        }

        .btn-gray {
          background: var(--toggle-bg);
          color: var(--text-primary);
        }

        .btn-gray:hover {
          background: var(--text-secondary);
        }

        /* Map Container */
        .map-container {
          background: var(--bg-secondary);
          border-radius: 15px;
          padding: 30px;
          box-shadow: var(--card-shadow);
          min-height: 500px;
          display: block;
        }

        .map-placeholder {
          display: none;
        }

        #map {
          width: 100%;
          max-width: none;
          height: 560px;
          border-radius: 10px;
          box-shadow: var(--card-shadow);
          z-index: 1;
        }

        /* Custom scrollbar for dark mode */
        [data-theme="dark"] #map {
          filter: brightness(0.9);
        }

        /* Legend */
        .legend {
          display: flex;
          gap: 20px;
          margin-top: 20px;
          justify-content: center;
          flex-wrap: wrap;
        }

        .legend-item {
          display: flex;
          align-items: center;
          gap: 8px;
        }

        .legend-color {
          width: 20px;
          height: 20px;
          border-radius: 4px;
        }

        .legend-text {
          font-size: 13px;
          color: #666;
        }
        .heatmap-no-records-alert {
          margin: 0 0 16px;
          padding: 14px 16px;
          color: #7f1d1d;
          background: #fef2f2;
          border: 1px solid #fecaca;
          border-radius: 10px;
          font-weight: 700;
          text-align: center;
        }
        .heatmap-summary {
          display: grid;
          grid-template-columns: repeat(4, minmax(0, 1fr));
          gap: 16px;
          margin-bottom: 18px;
        }
        .heatmap-stat {
          padding: 18px;
          background: var(--bg-secondary);
          border: 1px solid var(--border-color);
          border-radius: 12px;
          box-shadow: var(--card-shadow);
        }
        .heatmap-stat-label {
          display: block;
          color: var(--text-secondary);
          font-size: 12px;
          font-weight: 700;
          letter-spacing: 0.04em;
          text-transform: uppercase;
        }
        .heatmap-stat-value {
          display: block;
          margin-top: 7px;
          color: var(--text-primary);
          font-size: 25px;
          font-weight: 700;
        }
        .heatmap-period-form {
          display: grid;
          grid-template-columns: minmax(280px, 1fr) auto;
          gap: 12px;
          align-items: end;
          margin-bottom: 12px;
          padding: 16px 18px;
          background: var(--bg-secondary);
          border: 2px solid var(--eco-primary, #689f38);
          border-radius: 12px;
          box-shadow: var(--card-shadow);
        }
        .heatmap-mode-selector {
          display: flex;
          flex-wrap: wrap;
          gap: 8px;
          margin-bottom: 12px;
        }
        .heatmap-mode-option {
          display: inline-flex;
          align-items: center;
          gap: 7px;
          min-height: 36px;
          padding: 8px 11px;
          border: 1px solid var(--border-color);
          border-radius: 8px;
          color: var(--text-primary);
          background: var(--bg-primary);
          cursor: pointer;
          font-size: 12px;
          font-weight: 700;
        }
        .heatmap-mode-option.is-selected {
          border-color: var(--eco-primary, #689f38);
          background: var(--eco-surface-soft, #edf7ed);
          color: var(--eco-primary-strong, #356b24);
        }
        .heatmap-mode-option input {
          margin: 0;
          accent-color: var(--eco-primary, #689f38);
        }
        .heatmap-mode-panel[hidden] {
          display: none;
        }
        .heatmap-period-label {
          display: block;
          margin-bottom: 7px;
          color: var(--text-secondary);
          font-size: 11px;
          font-weight: 700;
          letter-spacing: 0.04em;
          text-transform: uppercase;
        }
        .heatmap-date-fields {
          display: flex;
          gap: 10px;
          flex-wrap: wrap;
        }
        .heatmap-date-field {
          min-width: 170px;
          flex: 1;
        }
        .heatmap-date-field label {
          display: block;
          margin-bottom: 5px;
          color: var(--text-secondary);
          font-size: 11px;
          font-weight: 700;
        }
        .heatmap-period-form input[type="date"],
        .heatmap-period-form input[type="month"],
        .heatmap-period-form select {
          width: 100%;
          min-height: 42px;
          padding: 9px 11px;
          border: 1px solid var(--border-color);
          border-radius: 8px;
          background: var(--input-bg);
          color: var(--text-primary);
          font: inherit;
        }
        .heatmap-month-field,
        .heatmap-year-field {
          max-width: 280px;
        }
        .heatmap-period-submit {
          min-height: 42px;
          padding: 9px 16px;
          border: 0;
          border-radius: 8px;
          background: var(--eco-primary, #689f38);
          color: #fff;
          font: inherit;
          font-weight: 800;
          cursor: pointer;
          white-space: nowrap;
        }
        .heatmap-toolbar {
          display: grid;
          grid-template-columns: minmax(220px, 1.5fr) 1fr auto;
          gap: 12px;
          align-items: end;
          margin-bottom: 18px;
          padding: 18px;
          background: var(--bg-secondary);
          border: 1px solid var(--border-color);
          border-radius: 12px;
          box-shadow: var(--card-shadow);
        }
        .heatmap-control label {
          display: block;
          margin-bottom: 6px;
          color: var(--text-secondary);
          font-size: 11px;
          font-weight: 700;
          letter-spacing: 0.04em;
          text-transform: uppercase;
        }
        .heatmap-control input,
        .heatmap-control select {
          width: 100%;
          min-height: 40px;
          padding: 9px 11px;
          border: 1px solid var(--border-color);
          border-radius: 8px;
          background: var(--input-bg);
          color: var(--text-primary);
        }
        .heatmap-reset {
          min-height: 40px;
          padding: 10px 15px;
          border: 1px solid var(--border-color);
          border-radius: 8px;
          color: var(--text-primary);
          background: var(--bg-primary);
          cursor: pointer;
          font-weight: 700;
        }
        .map-layout {
          display: grid;
          grid-template-columns: minmax(0, 1fr) 300px;
          gap: 20px;
          align-items: start;
        }
        .location-panel {
          max-height: 560px;
          overflow: auto;
          padding: 12px;
          border: 1px solid var(--border-color);
          border-radius: 10px;
          background: var(--bg-primary);
        }
        .location-panel h2 {
          margin: 4px 4px 12px;
          font-size: 15px;
        }
        .location-result-count {
          margin: 0 4px 12px;
          color: var(--text-secondary);
          font-size: 12px;
        }
        .location-card {
          width: 100%;
          padding: 12px;
          margin-bottom: 8px;
          text-align: left;
          border: 1px solid var(--border-color);
          border-left: 4px solid var(--marker-color);
          border-radius: 8px;
          background: var(--bg-secondary);
          color: var(--text-primary);
          cursor: pointer;
        }
        .location-card:not(:disabled):hover,
        .location-card:focus-visible {
          background: var(--eco-surface-soft);
          outline: 2px solid var(--eco-primary);
          outline-offset: 1px;
        }
        .location-card:disabled {
          cursor: default;
        }
        .location-card-title {
          display: block;
          font-size: 13px;
          font-weight: 700;
        }
        .location-card-meta {
          display: block;
          margin-top: 5px;
          color: var(--text-secondary);
          font-size: 12px;
        }
        .location-empty {
          padding: 24px 12px;
          color: var(--text-muted);
          text-align: center;
          font-size: 13px;
        }
        .phase-boundary-label {
          background: transparent;
          border: 0;
          box-shadow: none;
          color: #174f70;
          font-size: 13px;
          font-weight: 800;
          letter-spacing: 0.08em;
          text-shadow:
            0 1px 2px #fff,
            1px 0 2px #fff,
            -1px 0 2px #fff;
        }
        .phase-boundary-label::before {
          display: none;
        }
        @media (max-width: 1050px) {
          .map-layout {
            grid-template-columns: 1fr;
          }
          .location-panel {
            max-height: 280px;
          }
          .heatmap-summary {
            grid-template-columns: 1fr 1fr;
          }
          .heatmap-period-form,
          .heatmap-toolbar {
            grid-template-columns: 1fr 1fr;
          }
          .heatmap-reset {
            width: 100%;
          }
        }
        @media (max-width: 620px) {
          .heatmap-summary,
          .heatmap-period-form,
          .heatmap-toolbar {
            grid-template-columns: 1fr;
          }
          .map-container {
            padding: 15px;
          }
          #map {
            height: 420px;
          }
        }
    </style>
    <link rel="stylesheet" href="assets/css/ecotrack-theme.css?v=<?php echo filemtime(__DIR__ . '/assets/css/ecotrack-theme.css'); ?>">
    <script defer src="assets/js/year-navigable-picker.js?v=<?php echo filemtime(__DIR__ . '/assets/js/year-navigable-picker.js'); ?>"></script>
</head>
<body>
    <?php $active_page = $wasteHeatmapRoute;
$useLogoutModal = true;
include 'includes/sidebar.php'; ?>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <div class="header">
            <div>
                <h1>Waste Heatmap</h1>
                <p class="page-kicker">Daily-normalized waste for <?php echo htmlspecialchars($heatmapSelection['label']); ?>. Colors show the fixed daily waste bands for the selected period.</p>
            </div>
            <div class="header-icons">
                <?php include 'includes/notification_bell.php'; ?>
            </div>
        </div>

        <section class="heatmap-summary" aria-label="Heatmap summary">
            <div class="heatmap-stat"><span class="heatmap-stat-label">Streets / establishments</span><span id="mappedLocationCount" class="heatmap-stat-value">0</span></div>
            <div class="heatmap-stat"><span class="heatmap-stat-label">Selected-period waste</span><span id="mappedWasteTotal" class="heatmap-stat-value">0.00 kg</span></div>
            <div class="heatmap-stat"><span class="heatmap-stat-label">Daily average</span><span id="heatmapDailyAverage" class="heatmap-stat-value">No data</span></div>
            <div class="heatmap-stat"><span class="heatmap-stat-label">Highest location</span><span id="highestLocation" class="heatmap-stat-value">No data</span></div>
        </section>

        <form class="heatmap-period-form" method="get" action="<?php echo $wasteHeatmapRoute; ?>" aria-label="Waste heatmap period filter">
            <div>
                <div class="heatmap-mode-selector" role="radiogroup" aria-label="Heatmap view">
                    <label class="heatmap-mode-option"><input type="radio" name="mode" value="daily" <?php echo $heatmapMode === 'daily' ? 'checked' : ''; ?>> Daily date range</label>
                    <label class="heatmap-mode-option"><input type="radio" name="mode" value="month" <?php echo $heatmapMode === 'month' ? 'checked' : ''; ?>> Monthly view</label>
                    <label class="heatmap-mode-option"><input type="radio" name="mode" value="year" <?php echo $heatmapMode === 'year' ? 'checked' : ''; ?>> Yearly view</label>
                </div>
                <input type="hidden" name="view" value="<?php echo $heatmapMode === 'daily' ? 'week' : $heatmapMode; ?>">
                <div class="heatmap-mode-panel" data-heatmap-mode-panel="daily" <?php echo $heatmapMode === 'daily' ? '' : 'hidden'; ?>>
                    <span class="heatmap-period-label">Daily waste date range</span>
                    <div class="heatmap-date-fields">
                        <div class="heatmap-date-field"><label for="heatmapStartDate">Start date</label><input id="heatmapStartDate" type="date" name="start_date" data-year-navigable-picker="date" data-calendar-years='<?php echo htmlspecialchars($heatmapCalendarYearsJson, ENT_QUOTES, 'UTF-8'); ?>' value="<?php echo htmlspecialchars($heatmapSelection['start']); ?>" <?php echo $heatmapMode === 'daily' ? 'required' : 'disabled'; ?>></div>
                        <div class="heatmap-date-field"><label for="heatmapEndDate">End date</label><input id="heatmapEndDate" type="date" name="end_date" data-year-navigable-picker="date" data-calendar-years='<?php echo htmlspecialchars($heatmapCalendarYearsJson, ENT_QUOTES, 'UTF-8'); ?>' value="<?php echo htmlspecialchars($heatmapSelection['end']); ?>" <?php echo $heatmapMode === 'daily' ? 'required' : 'disabled'; ?>></div>
                    </div>
                </div>
                <div class="heatmap-mode-panel" data-heatmap-mode-panel="month" <?php echo $heatmapMode === 'month' ? '' : 'hidden'; ?>>
                    <div class="heatmap-date-field heatmap-month-field"><label for="heatmapMonth">Month</label><input id="heatmapMonth" type="month" name="period" data-year-navigable-picker="month" data-calendar-years='<?php echo htmlspecialchars($heatmapCalendarYearsJson, ENT_QUOTES, 'UTF-8'); ?>' value="<?php echo htmlspecialchars($heatmapMode === 'month' ? $heatmapSelection['period'] : substr($heatmapSelection['start'], 0, 7)); ?>" <?php echo $heatmapMode === 'month' ? 'required' : 'disabled'; ?>></div>
                </div>
                <div class="heatmap-mode-panel" data-heatmap-mode-panel="year" <?php echo $heatmapMode === 'year' ? '' : 'hidden'; ?>>
                    <div class="heatmap-date-field heatmap-year-field">
                        <label for="heatmapYear">Year</label>
                        <select id="heatmapYear" name="period" <?php echo $heatmapMode === 'year' && !empty($heatmapCalendarYears) ? 'required' : 'disabled'; ?>>
                            <?php if (empty($heatmapCalendarYears)): ?>
                                <option value="">No imported years available</option>
                            <?php else: ?>
                                <?php foreach ($heatmapCalendarYears as $calendarYear): ?>
                                    <option value="<?php echo (int)$calendarYear; ?>" <?php echo $heatmapMode === 'year' && (string)$calendarYear === $heatmapSelection['period'] ? 'selected' : ''; ?>><?php echo (int)$calendarYear; ?></option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                </div>
            </div>
            <button class="heatmap-period-submit" type="submit"><?php echo $heatmapMode === 'month' ? 'Apply month' : ($heatmapMode === 'year' ? 'Apply year' : 'Apply dates'); ?></button>
        </form>

        <section class="heatmap-toolbar" aria-label="Heatmap filters">
            <div class="heatmap-control"><label for="locationSearch">Search location</label><input id="locationSearch" type="search"></div>
            <div class="heatmap-control"><label for="sortFilter">Sort locations</label><select id="sortFilter"><option value="highest">Highest waste first</option><option value="lowest">Lowest waste first</option><option value="name">Name A–Z</option></select></div>
            <button id="resetHeatmapFilters" type="button" class="heatmap-reset">Reset</button>
        </section>

        <!-- Map Area -->
        <div class="map-container">
            <div id="heatmapNoRecordsAlert" class="heatmap-no-records-alert" role="alert" hidden>No available records</div>
            <div class="map-layout"><div id="map"></div><aside class="location-panel"><h2>Streets / establishments</h2><p id="locationResultCount" class="location-result-count" role="status"></p><div id="locationList"></div></aside></div>

            <div class="map-placeholder">
                <div class="map-placeholder-icon">&#128506;</div>
                <div class="map-placeholder-text">Waste Collection Heatmap</div>
                <div class="map-placeholder-hint">Interactive map will be displayed here</div>
            </div>

            <section id="heatmapLegend" class="legend" aria-label="Waste range legend">
                <?php foreach ($heatmapLegend as $legendItem): ?>
                    <div class="legend-item">
                        <div class="legend-color" style="background: <?php echo htmlspecialchars($legendItem['color']); ?>;"></div>
                        <span class="legend-text"><strong><?php echo htmlspecialchars($legendItem['color_name']); ?></strong> — <?php echo htmlspecialchars($legendItem['range']); ?> — <?php echo htmlspecialchars($legendItem['label']); ?></span>
                    </div>
                <?php endforeach; ?>
            </section>
        </div>
    </div>
    <?php include 'includes/logout_confirmation_modal.php'; ?>

    <script>
      (function () {
        var form = document.querySelector('.heatmap-period-form');
        if (!form) return;

        var modeInputs = form.querySelectorAll('input[name="mode"]');
        var viewInput = form.querySelector('input[name="view"]');
        var dailyPanel = form.querySelector('[data-heatmap-mode-panel="daily"]');
        var monthPanel = form.querySelector('[data-heatmap-mode-panel="month"]');
        var yearPanel = form.querySelector('[data-heatmap-mode-panel="year"]');
        var dateInputs = dailyPanel.querySelectorAll('input[type="date"]');
        var monthInput = document.getElementById('heatmapMonth');
        var yearInput = document.getElementById('heatmapYear');
        var submitButton = form.querySelector('.heatmap-period-submit');

        function selectedHeatmapMode() {
          var selectedInput = form.querySelector('input[name="mode"]:checked');
          return selectedInput ? selectedInput.value : 'daily';
        }

        function syncPicker(input) {
          if (window.EcoTrackYearPicker) window.EcoTrackYearPicker.sync(input);
        }

        function setHeatmapMode(mode) {
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
            syncPicker(input);
          });
          monthInput.disabled = !isMonthly;
          monthInput.required = isMonthly;
          syncPicker(monthInput);
          var hasAvailableYear = Array.prototype.some.call(yearInput.options, function (option) {
            return option.value !== '';
          });
          yearInput.disabled = !isYearly || !hasAvailableYear;
          yearInput.required = isYearly && !yearInput.disabled;
          submitButton.textContent = isMonthly ? 'Apply month' : (isYearly ? 'Apply year' : 'Apply dates');
          submitButton.disabled = isYearly && !hasAvailableYear;
          modeInputs.forEach(function (input) {
            input.closest('.heatmap-mode-option').classList.toggle('is-selected', input.checked);
          });
        }

        modeInputs.forEach(function (input) {
          input.addEventListener('change', function () {
            if (input.checked) setHeatmapMode(input.value);
          });
        });
        document.addEventListener('ecotrackyearpickerready', function () {
          setHeatmapMode(selectedHeatmapMode());
        });
        setHeatmapMode(selectedHeatmapMode());
      })();
    </script>

    <!-- Leaflet Map Initialization -->
    <script>
      try {
        // Refresh saved waste data without leaving the current view.
        var heatmapLoadedAt = Date.now();
        var heatmapWasteVersion = Number(<?php echo json_encode((int)($wasteDataState['version'] ?? 0)); ?>);

        var locationData = <?php echo $heatmapLocationsJson; ?>;
        var heatmapSelection = <?php echo json_encode($heatmapSelection, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        var phaseMetrics = <?php echo json_encode($heatmapPhaseMetrics, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        var heatmapDataUrl = <?php echo json_encode($heatmapDataUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        var locationDataSnapshot = JSON.stringify(locationData);
        // Keep map navigation inside the approved Brgy. San Manuel envelope.
        var barangayBounds = L.latLngBounds([
          [14.7739695, 121.06612],
          [14.789, 121.07482],
        ]);
        var map = L.map("map", {
          maxBounds: barangayBounds,
          maxBoundsViscosity: 1.0,
          maxZoom: 19,
        });

        // Add OpenStreetMap tiles
        L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
          attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
          maxZoom: 19,
        }).addTo(map);

        // Visual phase areas are simplified street-cluster buffers, not cadastral boundaries.
        var phaseBoundaryGeoJson = {
          phase1a: {
            type: "Feature",
            properties: { name: "PHASE 1", color: "#176b87" },
            geometry: {
              type: "Polygon",
              coordinates: [
                [
                  [121.0687213, 14.7743288],
                  [121.0697432, 14.7745084],
                  [121.0691858, 14.777922],
                  [121.069, 14.7781017],
                  [121.0664916, 14.7781017],
                  [121.0663058, 14.777922],
                  [121.0663058, 14.777383],
                  [121.0667703, 14.7772034],
                  [121.0672348, 14.7757661],
                  [121.067049, 14.7748678],
                  [121.0665845, 14.774239],
                  [121.0666774, 14.7739695],
                  [121.067049, 14.7739695],
                  [121.0672348, 14.774239],
                  [121.0687213, 14.7743288],
                ],
              ],
            },
          },
          phase5: {
            type: "Feature",
            properties: { name: "PHASE 5", color: "#6a4c93" },
            geometry: {
              type: "Polygon",
              coordinates: [
                [
                  [121.0687213, 14.7821441],
                  [121.0687213, 14.7831322],
                  [121.0685355, 14.7833119],
                  [121.068071, 14.7833119],
                  [121.067978, 14.7839407],
                  [121.0676993, 14.7839407],
                  [121.0675135, 14.7836712],
                  [121.0667703, 14.7836712],
                  [121.0666774, 14.7839407],
                  [121.0663058, 14.7839407],
                  [121.06612, 14.780078],
                  [121.0663987, 14.7798085],
                  [121.0682568, 14.7798983],
                  [121.0685355, 14.7781915],
                  [121.0686284, 14.7780119],
                  [121.069, 14.7780119],
                  [121.0690929, 14.7785508],
                  [121.0687213, 14.7798983],
                  [121.0687213, 14.7821441],
                ],
              ],
            },
          },
          phase6: {
            type: "Feature",
            properties: { name: "PHASE 6", color: "#2e7d32" },
            geometry: {
              type: "Polygon",
              coordinates: [
                [
                  [121.06915, 14.78188],
                  [121.07198, 14.78188],
                  [121.07198, 14.7827],
                  [121.07482, 14.7827],
                  [121.07482, 14.7835],
                  [121.07398, 14.7835],
                  [121.07398, 14.78358],
                  [121.07198, 14.78358],
                  [121.07198, 14.78428],
                  [121.07165, 14.78428],
                  [121.07165, 14.78398],
                  [121.06915, 14.78398],
                  [121.06915, 14.78188],
                ],
              ],
            },
          },
        };

        var phaseBoundaryLayers = {};
        Object.keys(phaseBoundaryGeoJson).forEach(function (phaseKey) {
          var feature = phaseBoundaryGeoJson[phaseKey];
          var outline = L.geoJSON(feature, {
            style: {
              color: feature.properties.color,
              weight: 4,
              opacity: 1,
              dashArray: "10 6",
              fill: true,
              fillOpacity: 0,
              interactive: false,
            },
            interactive: false,
          }).addTo(map);
          phaseBoundaryLayers[phaseKey] = outline;
          outline.bindTooltip(feature.properties.name, {
            permanent: true,
            direction: "center",
            className: "phase-boundary-label",
            interactive: false,
          });
        });

        var barangayMinZoom = map.getBoundsZoom(barangayBounds);
        map.setMinZoom(barangayMinZoom);
        map.setView(barangayBounds.getCenter(), barangayMinZoom);

        var maxWaste = Math.max.apply(
          null,
          [0].concat(
            locationData.map(function (location) {
              return Number(location.total_waste) || 0;
            }),
          ),
        );

        function getWasteLevel(location) {
          if (location && location.waste_level) return location.waste_level;
          return { label: "No data", color: "#9e9e9e", color_name: "No data", slug: "no-data" };
        }

        function highestWasteLevel(locations) {
          var rank = { "no-data": 0, low: 1, "medium-low": 2, "medium-high": 3, high: 4 };
          return locations.reduce(
            function (highest, location) {
              var level = getWasteLevel(location);
              return (rank[level.slug] || 0) > (rank[highest.slug] || 0) ? level : highest;
            },
            { label: "No data", color: "#9e9e9e", color_name: "No data", slug: "no-data" },
          );
        }

        var searchInput = document.getElementById("locationSearch");
        var sortFilter = document.getElementById("sortFilter");
        var locationList = document.getElementById("locationList");
        var heatmapNoRecordsAlert = document.getElementById("heatmapNoRecordsAlert");
        var filterStorageKey = "ecotrackHeatmapFilters:" + <?php echo json_encode((int)($user['id'] ?? 0)); ?>;

        function saveHeatmapFilters() {
          try {
            sessionStorage.setItem(
              filterStorageKey,
              JSON.stringify({
                search: searchInput.value,
                sort: sortFilter.value,
              }),
            );
          } catch (error) {
            // Keep filters usable when browser storage is unavailable.
          }
        }

        function restoreHeatmapFilters() {
          try {
            var saved = JSON.parse(sessionStorage.getItem(filterStorageKey));
            if (!saved || typeof saved !== "object") return;
            if (typeof saved.search === "string") searchInput.value = saved.search;
            if (["highest", "lowest", "name"].indexOf(saved.sort) !== -1) sortFilter.value = saved.sort;
          } catch (error) {
            // Ignore invalid saved preferences.
          }
        }

        function escapeHtml(value) {
          return String(value).replace(/[&<>'"]/g, function (character) {
            return { "&": "&amp;", "<": "&lt;", ">": "&gt;", "'": "&#039;", '"': "&quot;" }[character];
          });
        }

        // Use verified street anchors only for clipped phase heat areas.
        var phaseHeatAnchors = {
          "phase1a-bell-einstein": [14.7745436, 121.067178],
          "phase1a-wright-darwin": [14.77648, 121.06761],
          "phase5-samaria-main": [14.7801246, 121.0684892],
          "phase5-rhodes-samaria": [14.7804005, 121.068486],
          "phase6-north": [14.78343, 121.0705],
          "phase6-south": [14.78272, 121.0718],
        };
        var phaseWasteOverlays = {};

        function phaseKeyForLocation(location) {
          return location.phase_key || null;
        }

        function mapPointForLocation(location) {
          var phaseKey = phaseKeyForLocation(location);
          if (phaseKey && phaseBoundaryLayers[phaseKey]) {
            return phaseHeatAnchors[location.map_location_id] || phaseBoundaryLayers[phaseKey].getBounds().getCenter();
          }
          return Number.isFinite(location.lat) && Number.isFinite(location.lng) ? [location.lat, location.lng] : null;
        }

        function isVisibleHeatmapLocation(location) {
          return !!(location && location.has_data && mapPointForLocation(location));
        }

        function locationPopupContent(location, level) {
          var totalWaste = Number(location.total_waste) || 0;
          var hasData = !!location.has_data;
          var dailyAverage =
            location.daily_average_kg === null || location.daily_average_kg === undefined
              ? null
              : Number(location.daily_average_kg);
          var previousAverage =
            location.previous_daily_average_kg === null || location.previous_daily_average_kg === undefined
              ? null
              : Number(location.previous_daily_average_kg);
          var change =
            location.change_kg_per_day === null || location.change_kg_per_day === undefined
              ? null
              : Number(location.change_kg_per_day);
          var dss = location.dss || {};
          var dssText = dss.success
            ? "Reduced from previous period."
            : dss.status === "no-baseline"
              ? "No previous-period data available."
              : dss.status === "not-reduced"
                ? "Waste did not decrease from the previous period."
                : dss.status === "not-low"
                  ? "Reduced from the previous period, but remains above Low."
                  : "No selected-period data.";
          return (
            "<strong>" +
            escapeHtml(location.name) +
            "</strong>" +
            "<div>" +
            escapeHtml(location.address) +
            "</div>" +
            "<div>Selected period: <b>" +
            escapeHtml(heatmapSelection.label || "") +
            "</b></div>" +
            "<div>Total waste: <b>" +
            (hasData ? totalWaste.toFixed(2) + " kg" : "No data") +
            "</b></div>" +
            "<div>Daily average: <b>" +
            (dailyAverage === null ? "No data" : dailyAverage.toFixed(2) + " kg/day") +
            "</b></div>" +
            "<div>Previous daily average: <b>" +
            (previousAverage === null ? "No data" : previousAverage.toFixed(2) + " kg/day") +
            "</b></div>" +
            "<div>Change: <b>" +
            (change === null ? "No comparison" : (change > 0 ? "+" : "") + change.toFixed(2) + " kg/day") +
            "</b></div>" +
            '<div>Classification: <b style="color:' +
            level.color +
            '">' +
            escapeHtml((location.classification || level).label) +
            "</b></div>" +
            '<div>Assigned color: <b style="color:' +
            level.color +
            '">' +
            (level.color_name || level.label) +
            "</b></div>" +
            "<div>Matched records: " +
            location.record_count +
            "</div>" +
            "<div><small>" +
            escapeHtml(dssText) +
            "</small></div>" +
            (phaseKeyForLocation(location) ? "<div><small>Map shows the phase area.</small></div>" : "") +
            "<div><small>Updates from saved waste data.</small></div>"
          );
        }

        function addMarker(location, level) {
          var totalWaste = Number(location.total_waste) || 0;
          var markerRadius = maxWaste > 0 ? Math.min(42, Math.max(15, 15 + (totalWaste / maxWaste) * 27)) : 15;
          location.marker = L.circleMarker([location.lat, location.lng], {
            color: level.color,
            fillColor: level.color,
            fillOpacity: 0.78,
            radius: markerRadius,
            weight: 3,
          }).addTo(map);
          location.marker.bindTooltip(location.name, { direction: "top", offset: [0, -10] });
          location.marker.bindPopup(locationPopupContent(location, level));
        }

        function createSvgElement(name) {
          return document.createElementNS("http://www.w3.org/2000/svg", name);
        }

        function updatePhaseBoundaryVisibility(visiblePhaseKeys) {
          Object.keys(phaseBoundaryLayers).forEach(function (phaseKey) {
            var layer = phaseBoundaryLayers[phaseKey];
            var shouldShow = visiblePhaseKeys.indexOf(phaseKey) !== -1;
            if (shouldShow && !map.hasLayer(layer)) layer.addTo(map);
            if (!shouldShow && map.hasLayer(layer)) map.removeLayer(layer);
          });
        }

        function renderPhaseWasteAreas(locations, visiblePhaseKeys) {
          Object.keys(phaseWasteOverlays).forEach(function (phaseKey) {
            map.removeLayer(phaseWasteOverlays[phaseKey]);
          });
          phaseWasteOverlays = {};

          Object.keys(phaseBoundaryGeoJson).forEach(function (phaseKey) {
            if (visiblePhaseKeys.indexOf(phaseKey) === -1) return;
            var feature = phaseBoundaryGeoJson[phaseKey];
            var heatLocations = locations
              .filter(function (location) {
                return phaseKeyForLocation(location) === phaseKey;
              })
              .sort(function (a, b) {
                return a.name.localeCompare(b.name);
              });
            if (!heatLocations.length) return;
            var phaseTotal = heatLocations.reduce(function (sum, location) {
              return sum + (Number(location.total_waste) || 0);
            }, 0);
            var phaseMetric = phaseMetrics[phaseKey] || null;
            var phaseLevel =
              phaseMetric && phaseMetric.waste_level ? phaseMetric.waste_level : highestWasteLevel(heatLocations);
            // Classify phase fills from phase totals, not individual streets.
            phaseBoundaryLayers[phaseKey].setStyle({
              color: phaseLevel.color,
              fillColor: phaseLevel.color,
              fillOpacity: 0.34,
            });

            var polygon = feature.geometry.coordinates[0];
            var bounds = phaseBoundaryLayers[phaseKey].getBounds();
            var north = bounds.getNorth(),
              south = bounds.getSouth(),
              east = bounds.getEast(),
              west = bounds.getWest();
            var width = 1000,
              height = 1000;
            var svg = createSvgElement("svg");
            svg.setAttribute("viewBox", "0 0 " + width + " " + height);
            svg.setAttribute("preserveAspectRatio", "none");
            var defs = createSvgElement("defs");
            var clipPath = createSvgElement("clipPath");
            var clipId = "phase-waste-clip-" + phaseKey;
            clipPath.setAttribute("id", clipId);
            var clipPolygon = createSvgElement("polygon");
            clipPolygon.setAttribute(
              "points",
              polygon
                .map(function (point) {
                  return ((point[0] - west) / (east - west)) * width + "," + ((north - point[1]) / (north - south)) * height;
                })
                .join(" "),
            );
            clipPath.appendChild(clipPolygon);
            defs.appendChild(clipPath);
            svg.appendChild(defs);
            var heatGroup = createSvgElement("g");
            heatGroup.setAttribute("clip-path", "url(#" + clipId + ")");

            // Use phase-wide translucent fills from existing location totals.
            var baseGradient = createSvgElement("linearGradient");
            var baseGradientId = "phase-waste-base-" + phaseKey;
            baseGradient.setAttribute("id", baseGradientId);
            baseGradient.setAttribute("x1", "0%");
            baseGradient.setAttribute("y1", "0%");
            baseGradient.setAttribute("x2", "100%");
            baseGradient.setAttribute("y2", "100%");
            heatLocations.forEach(function (location, index) {
              var stop = createSvgElement("stop");
              var offset = heatLocations.length === 1 ? "50%" : (index / (heatLocations.length - 1)) * 100 + "%";
              stop.setAttribute("offset", offset);
              stop.setAttribute("stop-color", getWasteLevel(location).color);
              stop.setAttribute("stop-opacity", "0.30");
              baseGradient.appendChild(stop);
            });
            defs.appendChild(baseGradient);
            var baseFill = createSvgElement("rect");
            baseFill.setAttribute("x", "0");
            baseFill.setAttribute("y", "0");
            baseFill.setAttribute("width", width);
            baseFill.setAttribute("height", height);
            baseFill.setAttribute("fill", "url(#" + baseGradientId + ")");
            heatGroup.appendChild(baseFill);

            // Draw one heat blob per map area; the sidebar remains street-level.
            var heatGroups = {};
            heatLocations.forEach(function (location) {
              var areaId = location.map_location_id || phaseKey;
              if (!heatGroups[areaId]) heatGroups[areaId] = [];
              heatGroups[areaId].push(location);
            });
            Object.keys(heatGroups).forEach(function (areaId, index) {
              var areaLocations = heatGroups[areaId];
              var anchor = phaseHeatAnchors[areaId] || [(north + south) / 2, (east + west) / 2];
              var totalWaste = areaLocations.reduce(function (sum, location) {
                return sum + (Number(location.total_waste) || 0);
              }, 0);
              var level = highestWasteLevel(areaLocations);
              var x = ((anchor[1] - west) / (east - west)) * width;
              var y = ((north - anchor[0]) / (north - south)) * height;
              var radius = phaseTotal > 0 ? 170 + (totalWaste / phaseTotal) * 230 : 170;
              var gradientId = "phase-waste-gradient-" + phaseKey + "-" + index;
              var gradient = createSvgElement("radialGradient");
              gradient.setAttribute("id", gradientId);
              [
                ["0%", "0.82"],
                ["55%", "0.42"],
                ["100%", "0"],
              ].forEach(function (stopData) {
                var stop = createSvgElement("stop");
                stop.setAttribute("offset", stopData[0]);
                stop.setAttribute("stop-color", level.color);
                stop.setAttribute("stop-opacity", stopData[1]);
                gradient.appendChild(stop);
              });
              defs.appendChild(gradient);
              var blob = createSvgElement("path");
              blob.setAttribute(
                "d",
                "M " +
                  (x - radius) +
                  " " +
                  y +
                  " C " +
                  (x - radius * 0.55) +
                  " " +
                  (y - radius * 0.82) +
                  ", " +
                  (x + radius * 0.46) +
                  " " +
                  (y - radius * 0.88) +
                  ", " +
                  (x + radius * 0.92) +
                  " " +
                  (y - radius * 0.23) +
                  " C " +
                  (x + radius * 1.02) +
                  " " +
                  (y + radius * 0.42) +
                  ", " +
                  (x + radius * 0.3) +
                  " " +
                  (y + radius * 0.92) +
                  ", " +
                  (x - radius * 0.46) +
                  " " +
                  (y + radius * 0.76) +
                  " C " +
                  (x - radius * 0.94) +
                  " " +
                  (y + radius * 0.28) +
                  ", " +
                  (x - radius * 0.96) +
                  " " +
                  (y - radius * 0.46) +
                  ", " +
                  (x - radius) +
                  " " +
                  y +
                  " Z",
              );
              blob.setAttribute("fill", "url(#" + gradientId + ")");
              heatGroup.appendChild(blob);
            });
            svg.appendChild(heatGroup);
            phaseWasteOverlays[phaseKey] = L.svgOverlay(svg, bounds, { interactive: false }).addTo(map);
            phaseBoundaryLayers[phaseKey].bringToFront();
          });
        }

        function openLocationDetails(location, level) {
          var phaseKey = phaseKeyForLocation(location);
          var point = mapPointForLocation(location);
          if (!point) return;
          map.setView(point, Math.max(map.getZoom(), 17));
          if (phaseKey) {
            L.popup().setLatLng(point).setContent(locationPopupContent(location, level)).openOn(map);
          } else if (location.marker) {
            location.marker.openPopup();
          }
        }

        function locationWasteTotal(location) {
          var totalWaste = Number(location && location.total_waste);
          return Number.isFinite(totalWaste) ? totalWaste : 0;
        }

        function compareLocationNames(left, right) {
          return String((left && left.name) || "").localeCompare(String((right && right.name) || ""), "en", {
            numeric: true,
            sensitivity: "base",
          });
        }

        function compareHeatmapLocations(left, right) {
          var nameOrder = compareLocationNames(left, right);
          if (sortFilter.value === "name") return nameOrder;

          var wasteDifference = locationWasteTotal(left) - locationWasteTotal(right);
          if (wasteDifference !== 0) {
            return sortFilter.value === "lowest" ? wasteDifference : -wasteDifference;
          }
          return nameOrder;
        }

        function renderLocations(preservePosition) {
          var panel = locationList.parentElement;
          var scrollTop = preservePosition ? panel.scrollTop : 0;
          var focusedLocationId = preservePosition && document.activeElement ? document.activeElement.dataset.locationId : null;
          var query = searchInput.value.trim().toLowerCase();
          locationData.forEach(function (location) {
            if (location.marker) map.removeLayer(location.marker);
          });
          var filtered = locationData.filter(function (location) {
            var searchable = (location.name + " " + location.address).toLowerCase();
            return isVisibleHeatmapLocation(location) && (!query || searchable.indexOf(query) !== -1);
          });
          filtered.sort(compareHeatmapLocations);
          var visiblePhaseKeys = filtered.map(phaseKeyForLocation).filter(function (key) {
            return key !== null;
          });
          updatePhaseBoundaryVisibility(visiblePhaseKeys);
          renderPhaseWasteAreas(filtered, visiblePhaseKeys);
          var visibleLocationCount = locationData.filter(isVisibleHeatmapLocation).length;
          document.getElementById("locationResultCount").textContent =
            "Showing " + filtered.length + " of " + visibleLocationCount + " mapped locations";
          locationList.innerHTML = "";
          if (!filtered.length) locationList.innerHTML = '<div class="location-empty">No locations match these filters.</div>';
          filtered.forEach(function (location) {
            var totalWaste = locationWasteTotal(location);
            var level = getWasteLevel(location);
            if (!phaseKeyForLocation(location)) addMarker(location, level);
            var card = document.createElement("button");
            card.type = "button";
            card.className = "location-card";
            card.style.setProperty("--marker-color", level.color);
            card.dataset.locationId = location.id;
            card.innerHTML =
              '<span class="location-card-title">' +
              escapeHtml(location.name) +
              '</span><span class="location-card-meta">' +
              totalWaste.toFixed(2) +
              " kg · " +
              level.label +
              "</span>";
            var average =
              location.daily_average_kg === null || location.daily_average_kg === undefined
                ? null
                : Number(location.daily_average_kg);
            var classification = location.classification || level;
            card.innerHTML =
              '<span class="location-card-title">' +
              escapeHtml(location.name) +
              '</span><span class="location-card-meta">' +
              (location.has_data
                ? totalWaste.toFixed(2) +
                  " kg &middot; " +
                  (average === null ? "No daily average" : average.toFixed(2) + " kg/day")
                : "No selected-period data") +
              " &middot; " +
              escapeHtml(classification.label) +
              (location.dss && location.dss.success ? " &middot; Reduced from previous period" : "") +
              "</span>";
            card.addEventListener("click", function () {
              openLocationDetails(location, level);
            });
            locationList.appendChild(card);
            if (location.id === focusedLocationId) card.focus({ preventScroll: true });
          });
          panel.scrollTop = scrollTop;
        }

        function updateHeatmapSummary() {
          var mappedLocations = locationData.filter(isVisibleHeatmapLocation);
          var totalMappedWaste = mappedLocations.reduce(function (sum, location) {
            return sum + (Number(location.total_waste) || 0);
          }, 0);
          var highest = mappedLocations.slice().sort(function (a, b) {
            return (Number(b.total_waste) || 0) - (Number(a.total_waste) || 0);
          })[0];
          document.getElementById("mappedLocationCount").textContent = mappedLocations.length;
          document.getElementById("mappedWasteTotal").textContent = totalMappedWaste.toFixed(2) + " kg";
          document.getElementById("heatmapDailyAverage").textContent = mappedLocations.length
            ? (totalMappedWaste / Math.max(1, Number(heatmapSelection.day_count) || 1)).toFixed(2) + " kg/day"
            : "No data";
          document.getElementById("highestLocation").textContent = highest
            ? highest.name + " — " + (Number(highest.total_waste) || 0).toFixed(2) + " kg"
            : "No data";
          heatmapNoRecordsAlert.hidden = mappedLocations.length !== 0;
        }

        function updateHeatmapLegend(legend) {
          document.getElementById("heatmapLegend").innerHTML = legend
            .map(function (item) {
              return (
                '<div class="legend-item"><div class="legend-color" style="background:' +
                escapeHtml(item.color) +
                '"></div>' +
                '<span class="legend-text"><strong>' +
                escapeHtml(item.color_name) +
                "</strong> &mdash; " +
                escapeHtml(item.range) +
                " &mdash; " +
                escapeHtml(item.label) +
                "</span></div>"
              );
            })
            .join("");
        }

        [searchInput, sortFilter].forEach(function (control) {
          control.addEventListener(control === searchInput ? "input" : "change", function () {
            saveHeatmapFilters();
            renderLocations();
          });
        });
        document.getElementById("resetHeatmapFilters").addEventListener("click", function () {
          searchInput.value = "";
          sortFilter.value = "highest";
          saveHeatmapFilters();
          renderLocations();
          map.setView(barangayBounds.getCenter(), barangayMinZoom);
        });
        restoreHeatmapFilters();
        updateHeatmapSummary();
        renderLocations();

        var heatmapRefreshInFlight = false;
        var heatmapRefreshQueued = false;

        function updateHeatmapPeriodOptions(selection) {
          var select = document.getElementById("heatmapPeriod");
          if (!select || !selection || !Array.isArray(selection.options)) return;
          select.innerHTML = "";
          selection.options.forEach(function (optionData) {
            var option = document.createElement("option");
            option.value = optionData.period;
            option.textContent = optionData.label;
            option.selected = optionData.period === selection.period;
            select.appendChild(option);
          });
          select.disabled = selection.options.length === 0;
        }

        function refreshHeatmapData() {
          if (document.hidden) return;
          if (heatmapRefreshInFlight) {
            heatmapRefreshQueued = true;
            return;
          }
          heatmapRefreshInFlight = true;
          var requestedAt = Date.now();
          return fetch(heatmapDataUrl, { cache: "no-store", credentials: "same-origin" })
            .then(function (response) {
              if (!response.ok) throw new Error("Heatmap data unavailable.");
              return response.json();
            })
            .then(function (payload) {
              if (!payload || !Array.isArray(payload.locations) || !Array.isArray(payload.legend)) return;
              var snapshot = JSON.stringify(payload.locations);
              heatmapSelection = payload.selection || heatmapSelection;
              updateHeatmapPeriodOptions(heatmapSelection);
              phaseMetrics = payload.phase_metrics || phaseMetrics;
              if (snapshot !== locationDataSnapshot) {
                locationData.forEach(function (location) {
                  if (location.marker) map.removeLayer(location.marker);
                });
                locationData = payload.locations;
                maxWaste = Math.max.apply(
                  null,
                  [0].concat(
                    locationData.map(function (location) {
                      return Number(location.total_waste) || 0;
                    }),
                  ),
                );
                updateHeatmapSummary();
                renderLocations(true);
                locationDataSnapshot = snapshot;
              }
              updateHeatmapLegend(payload.legend);
              updateHeatmapSummary();
              heatmapLoadedAt = requestedAt;
            })
            .catch(function () {
              // Keep the last successful data and retry on the next refresh.
            })
            .finally(function () {
              heatmapRefreshInFlight = false;
              if (heatmapRefreshQueued) {
                heatmapRefreshQueued = false;
                refreshHeatmapData();
              }
            });
        }

        function refreshHeatmapWhenActive() {
          var wasteUpdatedAt = 0;
          try {
            wasteUpdatedAt = Number(localStorage.getItem("ecotrackWasteDataUpdated") || 0);
          } catch (error) {
            // Periodic refresh works without cross-tab browser storage.
          }
          if (wasteUpdatedAt > heatmapLoadedAt) refreshHeatmapData();
        }

        function refreshHeatmapVersion() {
          if (document.hidden) return;
          fetch("waste_data_version.php", { cache: "no-store", credentials: "same-origin" })
            .then(function (response) {
              return response.ok ? response.json() : null;
            })
            .then(function (state) {
              if (state && Number(state.version || 0) > heatmapWasteVersion) {
                heatmapWasteVersion = Number(state.version || 0);
                refreshHeatmapData();
              }
            })
            .catch(function () {});
        }

        window.addEventListener("focus", refreshHeatmapWhenActive);
        document.addEventListener("visibilitychange", function () {
          if (!document.hidden) refreshHeatmapWhenActive();
        });
        window.addEventListener("storage", function (event) {
          if (event.key === "ecotrackWasteDataUpdated" && Number(event.newValue || 0) > heatmapLoadedAt) refreshHeatmapData();
        });
        setInterval(refreshHeatmapVersion, 5000);
      } catch (error) {
        var mapLayout = document.querySelector('.map-layout');
        var mapPlaceholder = document.querySelector('.map-placeholder');
        if (mapLayout) mapLayout.hidden = true;
        if (mapPlaceholder) mapPlaceholder.style.display = 'block';
      }
    </script>
</body>
</html>

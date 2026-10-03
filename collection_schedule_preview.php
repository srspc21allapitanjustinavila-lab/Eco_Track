<?php
require_once 'config.php';

requireUserType('admin');
$collectionSchedulePreviewRoute = 'admin_collection_schedule_preview.php';
if (empty($canonicalRouteEntry) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $routeQuery = http_build_query($_GET);
    header('Location: ' . $collectionSchedulePreviewRoute . ($routeQuery === '' ? '' : '?' . $routeQuery));
    exit();
}
if (isSessionTimeout()) {
    logoutUser();
    header('Location: login.php?timeout=1');
    exit();
}
updateLastActivity();

$previewRecommendations = [
    [
        'rank' => 1,
        'phase' => 'Phase 1-A',
        'total' => '467.60 kg',
        'average' => '77.93 kg across 6 records',
        'level' => 'High (Red)',
        'frequency' => '3 times/week',
        'suggested' => 'Monday, Wednesday, Friday',
        'reason' => 'Highest recorded waste total in this scope.',
        'color' => '#c2413a',
    ],
    [
        'rank' => 2,
        'phase' => 'Phase 1-B',
        'total' => '311.20 kg',
        'average' => '62.24 kg across 5 records',
        'level' => 'Medium-High (Orange)',
        'frequency' => '2 times/week',
        'suggested' => 'Tuesday, Friday',
        'reason' => 'Consistent waste volume needs an additional route.',
        'color' => '#d97706',
    ],
    [
        'rank' => 3,
        'phase' => 'Phase 2',
        'total' => '126.40 kg',
        'average' => '31.60 kg across 4 records',
        'level' => 'Lower (Yellow)',
        'frequency' => '1 time/week',
        'suggested' => 'Thursday',
        'reason' => 'Current collection volume remains manageable weekly.',
        'color' => '#ca8a04',
    ],
];

function renderDssResponsivePreviewScreen($label, $screenClass, $controlPrefix, array $recommendations)
{
    ?>
    <section class="dss-preview__panel" aria-label="<?php echo htmlspecialchars($label); ?>">
        <p class="dss-preview__panel-label"><?php echo htmlspecialchars($label); ?></p>
        <div class="dss-preview__screen <?php echo htmlspecialchars($screenClass); ?>">
            <aside class="dss-preview__sidebar" aria-label="Preview navigation">
                <div class="dss-preview__brand">EcoTrack<span>MRF Management</span></div>
                <nav class="dss-preview__nav" aria-label="Preview sections">
                    <span>Dashboard</span>
                    <span class="is-active">Collection DSS</span>
                    <span>Waste Data</span>
                    <span>Heatmap</span>
                    <span>Reports</span>
                </nav>
            </aside>
            <div class="dss-preview__content">
                <header class="dss-preview__content-header">
                    <div>
                        <h2>Collection Scheduling DSS</h2>
                        <p>Prioritize collection frequency from recorded waste totals.</p>
                    </div>
                    <span class="dss-preview__status" aria-hidden="true"></span>
                </header>

                <div class="dss-toolbar">
                    <div class="filter-field">
                        <label for="<?php echo htmlspecialchars($controlPrefix); ?>-collection-group">Collection Group</label>
                        <select id="<?php echo htmlspecialchars($controlPrefix); ?>-collection-group" aria-label="Preview collection group">
                            <option>All Collection Groups</option>
                        </select>
                    </div>
                    <div class="filter-field">
                        <label for="<?php echo htmlspecialchars($controlPrefix); ?>-collection-year">Collection Year</label>
                        <select id="<?php echo htmlspecialchars($controlPrefix); ?>-collection-year" aria-label="Preview collection year">
                            <option>All Years</option>
                            <option selected>2026</option>
                        </select>
                    </div>
                    <div class="dss-toolbar__actions">
                        <button type="button" class="btn btn-green">Apply filters</button>
                        <button type="button" class="btn btn-gray">Reset</button>
                    </div>
                </div>

                <p class="scope"><strong>Analyzing:</strong> All Collection Groups &middot; 2026 &middot; 15 records</p>

                <div class="dss-grid">
                    <?php foreach ($recommendations as $recommendation): ?>
                        <article class="dss-card" style="--dss-color: <?php echo htmlspecialchars($recommendation['color']); ?>;">
                            <div class="dss-card__header">
                                <span class="dss-rank">Priority <?php echo (int)$recommendation['rank']; ?></span>
                                <h2><?php echo htmlspecialchars($recommendation['phase']); ?></h2>
                            </div>
                            <div class="dss-card__details">
                                <p class="dss-card__detail"><strong>Total waste:</strong> <?php echo htmlspecialchars($recommendation['total']); ?></p>
                                <p class="dss-card__detail"><strong>Average per record:</strong> <?php echo htmlspecialchars($recommendation['average']); ?></p>
                                <p class="dss-card__detail"><strong>Level:</strong> <span style="color: <?php echo htmlspecialchars($recommendation['color']); ?>; font-weight: 700;"><?php echo htmlspecialchars($recommendation['level']); ?></span></p>
                                <p class="dss-card__detail"><strong>Recommended:</strong> <?php echo htmlspecialchars($recommendation['frequency']); ?></p>
                                <p class="dss-card__detail"><strong>Suggested:</strong> <?php echo htmlspecialchars($recommendation['suggested']); ?></p>
                                <p class="dss-card__detail dss-card__detail--reason"><strong>Reason:</strong> <?php echo htmlspecialchars($recommendation['reason']); ?></p>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>

                <section class="method-card">
                    <h2>Recommendation method</h2>
                    <ul>
                        <li>Areas are ranked by total waste in the selected records.</li>
                        <li>Collection frequency follows the resulting priority band.</li>
                    </ul>
                </section>
            </div>
        </div>
    </section>
    <?php
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DSS Responsive Preview - EcoTrack</title>
    <?php include 'includes/theme_head.php'; ?>
    <link rel="stylesheet" href="assets/css/ecotrack-theme.css?v=<?php echo filemtime(__DIR__ . '/assets/css/ecotrack-theme.css'); ?>">
    <link rel="stylesheet" href="assets/css/dss-responsive.css?v=<?php echo filemtime(__DIR__ . '/assets/css/dss-responsive.css'); ?>">
</head>
<body class="dss-preview-page">
    <main class="dss-preview">
        <header class="dss-preview__heading">
            <p class="dss-preview__eyebrow">Admin-only layout reference</p>
            <h1>Collection Scheduling DSS responsiveness</h1>
            <p>Fixed sample data makes the desktop and narrowed browser layouts directly comparable without relying on production records.</p>
        </header>

        <div class="dss-preview__screens">
            <?php renderDssResponsivePreviewScreen('Full desktop view', 'dss-preview__screen--desktop', 'desktop-preview', $previewRecommendations); ?>
            <?php renderDssResponsivePreviewScreen('Narrowed / 90% browser view', 'dss-preview__screen--compact', 'compact-preview', $previewRecommendations); ?>
        </div>
    </main>
</body>
</html>

<?php
require_once 'config.php';
requireLogin();

// Redirect legacy route bookmarks to active task and dashboard pages.
$legacyRouteUser = getCurrentUser();
header('Location: ' . (($legacyRouteUser['user_type'] ?? '') === 'staff' ? 'staff_daily_tasks.php' : 'admin_dashboard.php'));
exit();

// Check session timeout
if (isSessionTimeout()) {
    logoutUser();
    header("Location: login.php?timeout=1");
    exit();
}
$_SESSION['last_activity'] = time();

$user = getCurrentUser();
$conn = getDBConnection();

// Get today's route schedule
$routes = [];
try {
    $stmt = $conn->prepare("SELECT * FROM route_schedules WHERE staff_id = ? AND schedule_date = CURDATE() ORDER BY sequence_order ASC");
    $stmt->execute([$user['id']]);
    $routes = $stmt->fetchAll();
} catch (PDOException $e) {
    // Use sample data if no database table
    $routes = [
        ['id' => 1, 'zone_name' => 'Purok 3 - Residential', 'collection_time' => '06:00 AM', 'status' => 'completed', 'waste_type' => 'Biodegradable', 'address' => 'Zone 3, San Manuel'],
        ['id' => 2, 'zone_name' => 'Purok 4 - Commercial', 'collection_time' => '08:30 AM', 'status' => 'in_progress', 'waste_type' => 'Non-Biodegradable', 'address' => 'Zone 4, San Manuel'],
        ['id' => 3, 'zone_name' => 'Purok 5 - Residential', 'collection_time' => '11:00 AM', 'status' => 'pending', 'waste_type' => 'Recyclable', 'address' => 'Zone 5, San Manuel'],
        ['id' => 4, 'zone_name' => 'Purok 6 - Market Area', 'collection_time' => '02:00 PM', 'status' => 'pending', 'waste_type' => 'Mixed Waste', 'address' => 'Zone 6, San Manuel'],
    ];
}

// Calculate progress
$completed = count(array_filter($routes, fn ($r) => $r['status'] == 'completed'));
$total = count($routes);
$progress = $total > 0 ? ($completed / $total) * 100 : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Route Schedule - EcoTrack Staff</title>
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

        /* Sidebar - Staff Menu */
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
          color: #4a4a4a;
          font-size: 24px;
          font-weight: 600;
        }
        .header-icons {
          display: flex;
          gap: 20px;
        }
        .header-icons a {
          color: #666;
          text-decoration: none;
          font-size: 20px;
        }

        /* Progress Bar */
        .progress-container {
          background: white;
          border-radius: 15px;
          padding: 25px;
          box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
          margin-bottom: 30px;
        }
        .progress-header {
          display: flex;
          justify-content: space-between;
          align-items: center;
          margin-bottom: 15px;
        }
        .progress-title {
          font-size: 16px;
          font-weight: 600;
          color: #333;
        }
        .progress-text {
          font-size: 14px;
          color: #666;
        }
        .progress-bar {
          height: 10px;
          background: #e0e0e0;
          border-radius: 5px;
          overflow: hidden;
        }
        .progress-fill {
          height: 100%;
          background: linear-gradient(90deg, #8bc34a, #4caf50);
          border-radius: 5px;
          transition: width 0.5s ease;
        }

        /* Route Cards */
        .route-container {
          display: flex;
          flex-direction: column;
          gap: 15px;
        }
        .route-card {
          background: white;
          border-radius: 15px;
          padding: 25px;
          box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
          display: flex;
          gap: 20px;
          align-items: center;
        }

        .route-status {
          width: 60px;
          height: 60px;
          border-radius: 50%;
          display: flex;
          align-items: center;
          justify-content: center;
          font-size: 24px;
          flex-shrink: 0;
        }
        .route-status.completed {
          background: #e8f5e9;
          color: #4caf50;
        }
        .route-status.in_progress {
          background: #e3f2fd;
          color: #2196f3;
        }
        .route-status.pending {
          background: #fff3e0;
          color: #ff9800;
        }

        .route-details {
          flex: 1;
        }
        .route-title {
          font-size: 18px;
          font-weight: 600;
          color: #333;
          margin-bottom: 5px;
        }
        .route-meta {
          display: flex;
          gap: 20px;
          font-size: 13px;
          color: #666;
        }
        .route-meta span {
          display: flex;
          align-items: center;
          gap: 5px;
        }

        .route-actions {
          display: flex;
          gap: 10px;
        }
        .btn {
          padding: 10px 20px;
          border: none;
          border-radius: 20px;
          cursor: pointer;
          font-size: 12px;
          font-weight: 600;
          text-transform: uppercase;
          transition: all 0.3s;
          display: inline-flex;
          align-items: center;
          gap: 8px;
          text-decoration: none;
        }
        .btn-green {
          background: #8bc34a;
          color: white;
        }
        .btn-green:hover {
          background: #7cb342;
        }
        .btn-blue {
          background: #2196f3;
          color: white;
        }
        .btn-blue:hover {
          background: #1976d2;
        }
        .btn-gray {
          background: #9e9e9e;
          color: white;
        }
        .btn-gray:hover {
          background: #757575;
        }

        .status-badge {
          padding: 5px 15px;
          border-radius: 15px;
          font-size: 12px;
          font-weight: 600;
          text-transform: uppercase;
        }
        .status-badge.completed {
          background: #e8f5e9;
          color: #4caf50;
        }
        .status-badge.in_progress {
          background: #e3f2fd;
          color: #2196f3;
        }
        .status-badge.pending {
          background: #fff3e0;
          color: #ff9800;
        }

        /* Timeline */
        .timeline {
          position: relative;
          padding-left: 30px;
        }
        .timeline::before {
          content: "";
          position: absolute;
          left: 15px;
          top: 0;
          bottom: 0;
          width: 2px;
          background: #e0e0e0;
        }

        .empty-state {
          text-align: center;
          padding: 60px;
          color: #999;
          background: white;
          border-radius: 15px;
          box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }
        .empty-state-icon {
          font-size: 64px;
          margin-bottom: 20px;
        }
    </style>
    <link rel="stylesheet" href="assets/css/ecotrack-theme.css">
</head>
<body class="staff-page">
    <?php $active_page = 'role_landing_redirect.php';
$useLogoutModal = false;
include 'includes/sidebar.php'; ?>

    <div class="main-content">
        <div class="header">
            <div>
                <h1>Route Schedule</h1>
                <p class="staff-subtitle">Follow today's collection sequence and monitor route progress.</p>
            </div>
            <div class="header-icons">
                <a href="#" title="Notifications">&#128276;</a>
                <a href="staff_home.php" title="Staff home">&#8962;</a>
            </div>
        </div>

        <!-- Progress -->
        <div class="progress-container">
            <div class="progress-header">
                <span class="progress-title">Collection progress - <?php echo date('F d, Y'); ?></span>
                <span class="progress-text"><?php echo $completed; ?> of <?php echo $total; ?> zones completed</span>
            </div>
            <div class="progress-bar">
                <div class="progress-fill" style="width: <?php echo $progress; ?>%"></div>
            </div>
        </div>

        <!-- Route List -->
        <div class="route-container">
            <?php if (empty($routes)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">&#128663;</div>
                    <h3>No routes scheduled for today</h3>
                    <p>Check back tomorrow or contact your supervisor</p>
                </div>
            <?php else: ?>
                <?php foreach ($routes as $index => $route): ?>
                    <div class="route-card">
                        <div class="route-status <?php echo $route['status']; ?>">
                            <?php
                        if ($route['status'] == 'completed') {
                            echo '&#10004;';
                        } elseif ($route['status'] == 'in_progress') {
                            echo '&#128663;';
                        } else {
                            echo '&#128204;';
                        }
                    ?>
                        </div>
                        <div class="route-details">
                            <div class="route-title"><?php echo htmlspecialchars($route['zone_name']); ?></div>
                            <div class="route-meta">
                                <span>&#128337; <?php echo htmlspecialchars($route['collection_time']); ?></span>
                                <span>&#128205; <?php echo htmlspecialchars($route['address']); ?></span>
                                <span>&#9851; <?php echo htmlspecialchars($route['waste_type']); ?></span>
                            </div>
                        </div>
                        <div class="route-actions">
                            <span class="status-badge <?php echo $route['status']; ?>"><?php echo str_replace('_', ' ', ucfirst($route['status'])); ?></span>
                            <?php if ($route['status'] == 'pending'): ?>
                                <button class="btn btn-blue" onclick="alert('Starting collection...')">Start</button>
                            <?php elseif ($route['status'] == 'in_progress'): ?>
                                <button class="btn btn-green" onclick="alert('Completing collection...')">Complete</button>
                            <?php else: ?>
                                <button class="btn btn-gray" disabled>Done</button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>

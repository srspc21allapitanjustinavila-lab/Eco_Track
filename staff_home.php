<?php
require_once 'config.php';
requireUserType('staff');

// Check session timeout
if (isSessionTimeout()) {
    logoutUser();
    header("Location: login.php?timeout=1");
    exit();
}
$_SESSION['last_activity'] = time();

$user = getCurrentUser();
$conn = getDBConnection();
$wasteDataState = getWasteDataVersion($conn);

// Read-only waste overview for staff. No update or delete actions are exposed here.
$staffWasteOverview = [
    'total_waste' => 0,
    'returnable' => 0,
    'biowaste' => 0,
    'residual' => 0,
    'hazardous' => 0,
    'total_records' => 0,
    'active_collectors' => 0,
];
$staffDiversionRate = 0;
try {
    ensureWasteFormatColumns($conn);
    $newTotalExpr = "COALESCE(tuesday_factory_returnable_kg, 0) + COALESCE(wednesday_biowaste_kg, 0) + COALESCE(thursday_factory_returnable_kg, 0) + COALESCE(friday_biowaste_kg, 0) + COALESCE(saturday_hazard_waste_kg, 0) + COALESCE(residual_waste_kg, 0) + COALESCE(unclassified_waste_kg, 0)";
    $totalExpr = "CASE WHEN ($newTotalExpr) > 0 THEN ($newTotalExpr) ELSE COALESCE(kilogram_of_waste, 0) END";
    $returnableExpr = "CASE WHEN (COALESCE(tuesday_factory_returnable_kg, 0) + COALESCE(thursday_factory_returnable_kg, 0)) > 0 THEN (COALESCE(tuesday_factory_returnable_kg, 0) + COALESCE(thursday_factory_returnable_kg, 0)) ELSE COALESCE(recyclable_kg, 0) END";
    $biowasteExpr = "COALESCE(wednesday_biowaste_kg, 0) + COALESCE(friday_biowaste_kg, 0)";
    $residualExpr = "CASE WHEN COALESCE(residual_waste_kg, 0) > 0 THEN COALESCE(residual_waste_kg, 0) ELSE COALESCE(residual_kg, 0) END";
    $hazardousExpr = "CASE WHEN COALESCE(saturday_hazard_waste_kg, 0) > 0 THEN COALESCE(saturday_hazard_waste_kg, 0) ELSE COALESCE(hazardous_kg, 0) END";
    $collectorLabelExpr = "COALESCE(NULLIF(name_of_bioman, ''), NULLIF(garbage_collector, ''), 'Unassigned')";
    $staffWasteOverviewQuery = $conn->query("SELECT COALESCE(SUM($totalExpr), 0) AS total_waste, COALESCE(SUM($returnableExpr), 0) AS returnable, COALESCE(SUM($biowasteExpr), 0) AS biowaste, COALESCE(SUM($residualExpr), 0) AS residual, COALESCE(SUM($hazardousExpr), 0) AS hazardous, COALESCE(SUM(COALESCE(unclassified_waste_kg, 0)), 0) AS unclassified, COUNT(*) AS total_records, COUNT(DISTINCT NULLIF($collectorLabelExpr, 'Unassigned')) AS active_collectors FROM waste_records WHERE is_active = 1");
    $staffWasteOverview = array_merge($staffWasteOverview, $staffWasteOverviewQuery->fetch() ?: []);
    $categoryTotal = (float)$staffWasteOverview['returnable'] + (float)$staffWasteOverview['biowaste'] + (float)$staffWasteOverview['residual'] + (float)$staffWasteOverview['hazardous'];
    if ($categoryTotal > 0) {
        $staffDiversionRate = round((((float)$staffWasteOverview['returnable'] + (float)$staffWasteOverview['biowaste']) / $categoryTotal) * 100);
    }
} catch (PDOException $e) {
    // The dashboard remains available even before waste records are set up.
}
$notificationItems = getNotificationItems($conn, $user);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Home - EcoTrack</title>
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
          --toggle-bg: #ccc;
          --toggle-active: #8bc34a;
          --input-bg: white;
          --input-border: #ddd;
          --modal-overlay: rgba(0, 0, 0, 0.5);
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
          --toggle-bg: #555;
          --toggle-active: #7cb342;
          --input-bg: #3d3d3d;
          --input-border: #555;
          --modal-overlay: rgba(0, 0, 0, 0.7);
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

        /* Sidebar */
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
          color: var(--text-primary);
          font-size: 24px;
          font-weight: 600;
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

        /* Profile Section */
        .profile-container {
          display: flex;
          gap: 30px;
          margin-bottom: 30px;
          align-items: stretch;
        }

        .profile-card {
          background: var(--bg-secondary);
          border-radius: 20px;
          padding: 40px 30px;
          box-shadow: var(--card-shadow);
          text-align: center;
          flex: 0 0 280px;
          display: flex;
          flex-direction: column;
          align-items: center;
          justify-content: center;
          border: 1px solid var(--border-color);
        }

        .profile-avatar {
          width: 140px;
          height: 140px;
          border-radius: 50%;
          background: linear-gradient(135deg, #8bc34a 0%, #689f38 100%);
          display: flex;
          align-items: center;
          justify-content: center;
          margin: 0 auto 20px;
          font-size: 60px;
          color: white;
          overflow: hidden;
          box-shadow: 0 4px 15px rgba(139, 195, 74, 0.4);
          border: 4px solid var(--bg-secondary);
        }

        .profile-avatar img {
          width: 100%;
          height: 100%;
          object-fit: cover;
        }

        .profile-name {
          font-size: 18px;
          font-weight: 700;
          color: var(--text-primary);
          margin-bottom: 5px;
        }

        .profile-status {
          display: inline-block;
          padding: 6px 16px;
          border-radius: 20px;
          font-size: 12px;
          font-weight: 600;
          text-transform: uppercase;
          letter-spacing: 0.5px;
        }

        .profile-status.active {
          background: #e8f5e9;
          color: #2e7d32;
        }

        [data-theme="dark"] .profile-status.active {
          background: rgba(139, 195, 74, 0.2);
          color: #7cb342;
        }

        .profile-role {
          font-size: 13px;
          color: var(--text-muted);
          margin-top: 10px;
          font-weight: 500;
        }

        .profile-details {
          background: var(--bg-secondary);
          border-radius: 20px;
          padding: 35px;
          box-shadow: var(--card-shadow);
          flex: 1;
          border: 1px solid var(--border-color);
        }

        .profile-details h2 {
          font-size: 18px;
          font-weight: 700;
          color: var(--text-primary);
          margin-bottom: 25px;
          border-bottom: 3px solid #8bc34a;
          padding-bottom: 12px;
          text-transform: uppercase;
          letter-spacing: 1px;
        }

        .detail-row {
          display: flex;
          margin-bottom: 18px;
          padding-bottom: 18px;
          border-bottom: 1px solid var(--border-color);
          align-items: center;
        }

        .detail-row:last-of-type {
          border-bottom: none;
          margin-bottom: 0;
          padding-bottom: 0;
        }

        .detail-label {
          font-weight: 600;
          color: var(--text-secondary);
          width: 160px;
          text-transform: uppercase;
          font-size: 12px;
          letter-spacing: 0.5px;
        }

        .detail-value {
          color: var(--text-primary);
          flex: 1;
          font-size: 15px;
          font-weight: 500;
        }

        /* Quick Actions */
        .quick-actions {
          display: flex;
          gap: 15px;
          flex-wrap: wrap;
          margin-bottom: 30px;
        }
        .btn {
          padding: 15px 25px;
          border: none;
          border-radius: 25px;
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
          background: #5c6bc0;
          color: white;
        }
        .btn-blue:hover {
          background: #3f51b5;
        }
        .btn-orange {
          background: #ff9800;
          color: white;
        }
        .btn-orange:hover {
          background: #f57c00;
        }
        .btn-red {
          background: #f44336;
          color: white;
        }
        .btn-red:hover {
          background: #d32f2f;
        }

        /* Permissions Table */
        .permissions-section {
          background: var(--bg-secondary);
          border-radius: 15px;
          padding: 25px;
          box-shadow: var(--card-shadow);
        }
        .permissions-section h3 {
          font-size: 16px;
          font-weight: 600;
          color: var(--text-primary);
          margin-bottom: 20px;
          border-bottom: 2px solid #8bc34a;
          padding-bottom: 10px;
          text-transform: uppercase;
        }
        .permissions-table {
          width: 100%;
          border-collapse: collapse;
        }
        .permissions-table th {
          background: var(--bg-primary);
          padding: 15px;
          text-align: left;
          font-weight: 600;
          color: var(--text-primary);
          border: 1px solid var(--border-color);
        }
        .permissions-table td {
          padding: 15px;
          border: 1px solid var(--border-color);
          vertical-align: top;
        }
        .permissions-table ul {
          list-style: none;
          padding: 0;
        }
        .permissions-table li {
          margin-bottom: 8px;
          font-size: 13px;
          color: var(--text-secondary);
        }
        .read-only-overview {
          background: var(--bg-secondary);
          border: 1px solid var(--border-color);
          border-radius: 15px;
          padding: 25px;
          margin-bottom: 30px;
          box-shadow: var(--card-shadow);
        }
        .read-only-heading {
          display: flex;
          align-items: center;
          justify-content: space-between;
          gap: 15px;
          margin-bottom: 20px;
        }
        .read-only-heading h2 {
          font-size: 18px;
          color: var(--text-primary);
        }
        .read-only-badge {
          color: #176b59;
          background: #d9eee8;
          border-radius: 999px;
          padding: 6px 10px;
          font-size: 12px;
          font-weight: 700;
        }
        .staff-summary-grid {
          display: grid;
          grid-template-columns: repeat(4, minmax(0, 1fr));
          gap: 15px;
        }
        .staff-summary-card {
          background: var(--bg-primary);
          border: 1px solid var(--border-color);
          border-left: 4px solid #16735f;
          border-radius: 10px;
          padding: 18px;
        }
        .staff-summary-card.blue {
          border-left-color: #256fa8;
        }
        .staff-summary-card.amber {
          border-left-color: #b96f14;
        }
        .staff-summary-card.red {
          border-left-color: #b8423b;
        }
        .staff-summary-value {
          color: var(--text-primary);
          font-size: 25px;
          font-weight: 700;
          margin-bottom: 6px;
        }
        .staff-summary-label {
          color: var(--text-secondary);
          font-size: 12px;
          text-transform: uppercase;
          font-weight: 600;
        }
        .staff-category-list {
          margin-top: 20px;
          display: grid;
          grid-template-columns: repeat(4, minmax(0, 1fr));
          gap: 12px;
        }
        .staff-category-item {
          min-width: 0;
          color: var(--text-secondary);
          font-size: 12px;
        }
        .staff-category-name {
          display: flex;
          justify-content: space-between;
          gap: 8px;
          margin-bottom: 6px;
        }
        .staff-category-name span {
          min-width: 0;
          overflow: hidden;
          text-overflow: ellipsis;
          white-space: nowrap;
        }
        .staff-category-name strong {
          flex: 0 0 auto;
        }
        .staff-category-bar {
          height: 7px;
          border-radius: 99px;
          background: var(--bg-primary);
          overflow: hidden;
        }
        .staff-category-bar span {
          display: block;
          height: 100%;
          border-radius: inherit;
          background: var(--category-color, #16735f);
          width: var(--category-width, 0%);
        }
        @media (max-width: 1050px) {
          .staff-summary-grid,
          .staff-category-list {
            grid-template-columns: repeat(2, minmax(0, 1fr));
          }
        }
        @media (max-width: 600px) {
          .staff-summary-grid,
          .staff-category-list {
            grid-template-columns: 1fr;
          }
        }
    </style>
    <link rel="stylesheet" href="assets/css/ecotrack-theme.css?v=<?php echo filemtime(__DIR__ . '/assets/css/ecotrack-theme.css'); ?>">
</head>
<body class="staff-page">
    <?php $active_page = 'staff_home.php';
$useLogoutModal = true;
include 'includes/sidebar.php'; ?>

    <div class="main-content">
        <div class="header">
            <div>
                <h1>Staff Home</h1>
                <p class="staff-subtitle">Review your profile, assigned tasks, route work, and latest updates.</p>
            </div>
            <div class="header-icons">
                <?php include 'includes/notification_bell.php'; ?>
                <a href="staff_settings.php" title="Account settings">&#9881;</a>
            </div>
        </div>

        <section class="read-only-overview" aria-label="Read-only waste overview">
            <div class="read-only-heading">
                <div><h2>Waste Overview</h2><p class="staff-subtitle">All saved waste records &middot; View only.</p></div>
                <span class="read-only-badge">View only</span>
            </div>
            <div class="staff-summary-grid">
                <article class="staff-summary-card"><div class="staff-summary-value"><?php echo number_format((float)$staffWasteOverview['total_waste'], 2); ?> kg</div><div class="staff-summary-label">Total Waste</div></article>
                <article class="staff-summary-card blue"><div class="staff-summary-value"><?php echo $staffDiversionRate; ?>%</div><div class="staff-summary-label">Diversion Rate</div></article>
                <article class="staff-summary-card amber"><div class="staff-summary-value"><?php echo (int)$staffWasteOverview['total_records']; ?></div><div class="staff-summary-label">Waste Records</div></article>
                <article class="staff-summary-card red"><div class="staff-summary-value"><?php echo (int)$staffWasteOverview['active_collectors']; ?></div><div class="staff-summary-label">Active Collectors</div></article>
            </div>
            <?php $staffCategoryTotal = max(1, (float)$staffWasteOverview['returnable'] + (float)$staffWasteOverview['biowaste'] + (float)$staffWasteOverview['residual'] + (float)$staffWasteOverview['hazardous']); ?>
            <div class="staff-category-list">
                <div class="staff-category-item"><div class="staff-category-name"><span>Factory Returnable</span><strong><?php echo number_format((float)$staffWasteOverview['returnable'], 2); ?> kg</strong></div><div class="staff-category-bar" style="--category-color:#2E7D32; --category-width:<?php echo ((float)$staffWasteOverview['returnable'] / $staffCategoryTotal) * 100; ?>%"><span></span></div></div>
                <div class="staff-category-item"><div class="staff-category-name"><span>Biowaste</span><strong><?php echo number_format((float)$staffWasteOverview['biowaste'], 2); ?> kg</strong></div><div class="staff-category-bar" style="--category-color:#8B5A2B; --category-width:<?php echo ((float)$staffWasteOverview['biowaste'] / $staffCategoryTotal) * 100; ?>%"><span></span></div></div>
                <div class="staff-category-item"><div class="staff-category-name"><span>Residual</span><strong><?php echo number_format((float)$staffWasteOverview['residual'], 2); ?> kg</strong></div><div class="staff-category-bar" style="--category-color:#6B7280; --category-width:<?php echo ((float)$staffWasteOverview['residual'] / $staffCategoryTotal) * 100; ?>%"><span></span></div></div>
                <div class="staff-category-item"><div class="staff-category-name"><span>Hazardous</span><strong><?php echo number_format((float)$staffWasteOverview['hazardous'], 2); ?> kg</strong></div><div class="staff-category-bar" style="--category-color:#DC2626; --category-width:<?php echo ((float)$staffWasteOverview['hazardous'] / $staffCategoryTotal) * 100; ?>%"><span></span></div></div>
                <div class="staff-category-item"><div class="staff-category-name"><span>Unclassified <small>(excluded from diversion)</small></span><strong><?php echo number_format((float)($staffWasteOverview['unclassified'] ?? 0), 2); ?> kg</strong></div></div>
            </div>
        </section>

        <!-- Profile Section -->
        <div class="profile-container">
            <div class="profile-card">
                <div class="profile-avatar">
                    <?php if ($user && $user['photo']): ?>
                        <img src="<?php echo htmlspecialchars($user['photo']); ?>" alt="Staff Photo">
                    <?php else: ?>&#128100;<?php endif; ?>
                </div>
                <div class="profile-name"><?php echo $user ? htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) : 'Staff Member'; ?></div>
                <div class="profile-status active"><?php echo $user && $user['is_active'] ? 'Active' : 'Inactive'; ?></div>
                <div class="profile-role">Staff Member</div>
            </div>

            <div class="profile-details">
                <h2>PROFILE DETAILS</h2>
                <div class="detail-row">
                    <div class="detail-label">Full Name:</div>
                    <div class="detail-value"><?php echo $user ? htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) : 'N/A'; ?></div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Employee ID:</div>
                    <div class="detail-value">BSM-MRF-2024-<?php echo str_pad($user['id'], 3, '0', STR_PAD_LEFT); ?></div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Position:</div>
                    <div class="detail-value">MRF Collection Staff</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Assigned Sector:</div>
                    <div class="detail-value">Purok 3 - Purok 6, West BRGY Area</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Contact Number:</div>
                    <div class="detail-value">0912-XXX-XXXX</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Work Email:</div>
                    <div class="detail-value"><?php echo $user ? htmlspecialchars($user['email']) : 'N/A'; ?></div>
                </div>
            </div>
        </div>

        <!-- Staff Permissions -->
        <div class="permissions-section">
            <h3>Staff Permissions</h3>
            <table class="permissions-table">
                <thead>
                    <tr>
                        <th>Permission Level</th>
                        <th>Allowed Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Standard Field Access</td>
                        <td>
                            <ul>
                                <li>&#8226; Log Daily Waste Collection</li>
                                <li>&#8226; View Waste Generation Heatmap</li>
                                <li>&#8226; Receive Work Notifications</li>
                            </ul>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <?php include 'includes/logout_confirmation_modal.php'; ?>
    <script>
        const staffDashboardLoadedAt = Date.now();
        let staffWasteVersion = Number(<?php echo json_encode((int)($wasteDataState['version'] ?? 0)); ?>);

        function refreshStaffDashboardWhenWasteDataChanges() {
          if (document.hidden) return;

          let wasteUpdatedAt = 0;
          try {
            wasteUpdatedAt = Number(localStorage.getItem("ecotrackWasteDataUpdated") || 0);
          } catch (error) {
            // The visible-tab interval still refreshes the overview when
            // browser storage is unavailable.
          }

          if (wasteUpdatedAt > staffDashboardLoadedAt) {
            window.location.reload();
          }
        }

        async function refreshStaffDashboardDataVersion() {
          if (document.hidden) return;
          try {
            const response = await fetch("waste_data_version.php", { cache: "no-store", credentials: "same-origin" });
            const state = await response.json();
            if (response.ok && Number(state.version || 0) > staffWasteVersion) window.location.reload();
          } catch (error) {}
        }

        window.addEventListener("storage", (event) => {
          if (event.key === "ecotrackWasteDataUpdated" && Number(event.newValue || 0) > staffDashboardLoadedAt) {
            window.location.reload();
          }
        });
        window.addEventListener("focus", refreshStaffDashboardWhenWasteDataChanges);
        document.addEventListener("visibilitychange", () => {
          if (!document.hidden) refreshStaffDashboardWhenWasteDataChanges();
        });
        window.setInterval(refreshStaffDashboardDataVersion, 5000);
    </script>
</body>
</html>

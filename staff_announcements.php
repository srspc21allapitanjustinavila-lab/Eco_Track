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
ensureNotificationsTable($conn);
ensureReportsTable($conn);

$message = '';
$error = '';

// Handle report submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_report'])) {
    $report_type = trim($_POST['report_type'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $zone = trim($_POST['zone'] ?? '');

    if (empty($report_type) || empty($description)) {
        $error = "Please fill in all required fields.";
        logActivity('Submitted staff report', 'Staff Reports', 'Failed', $conn);
    } else {
        try {
            $stmt = $conn->prepare("INSERT INTO reports (staff_id, report_type, description, zone, status, created_at) VALUES (?, ?, ?, ?, 'pending', NOW())");
            $stmt->execute([$user['id'], $report_type, $description, $zone]);
            $reportId = (int)$conn->lastInsertId();
            $staffName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
            if ($staffName === '') {
                $staffName = $user['username'] ?? 'A staff member';
            }
            $reportSummary = $staffName . ' submitted a ' . $report_type . ' report';
            if ($zone !== '') {
                $reportSummary .= ' for ' . $zone;
            }
            $reportSummary .= ': ' . $description;
            $adminRecipients = $conn->query("SELECT id FROM users WHERE user_type = 'admin' AND is_active = 1")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($adminRecipients as $adminRecipientId) {
                createUserNotification(
                    $conn,
                    $adminRecipientId,
                    'New staff report',
                    $reportSummary,
                    'admin_operations_reports.php?tab=staff_reports&report_id=' . $reportId,
                    'report_submitted',
                    $user['id']
                );
            }
            $activityAction = 'Submitted ' . ucwords(str_replace('_', ' ', $report_type)) . ' report';
            if ($zone !== '') {
                $activityAction .= ' for ' . $zone;
            }
            logActivity(substr($activityAction, 0, 150), 'Staff Reports', 'Success', $conn);
            $message = "Report submitted successfully!";
        } catch (PDOException $e) {
            $error = "Failed to submit report.";
            logActivity('Submitted staff report', 'Staff Reports', 'Failed', $conn);
        }
    }
}

$allowedTabs = ['announcements', 'report', 'my_reports'];
$activeTab = $_GET['tab'] ?? 'announcements';
if (!in_array($activeTab, $allowedTabs, true)) {
    $activeTab = 'announcements';
}
$highlightReportId = max(0, (int)($_GET['report_id'] ?? 0));
$reportStatusLabels = [
    'pending' => 'Pending',
    'in_review' => 'In Review',
    'resolved' => 'Resolved',
];

// Staff can view only their reports, including the last reviewer when available.
$myReports = [];
try {
    $stmt = $conn->prepare("SELECT r.*, reviewer.first_name AS reviewer_first_name, reviewer.last_name AS reviewer_last_name
        FROM reports r
        LEFT JOIN users reviewer ON reviewer.id = r.reviewed_by
        WHERE r.staff_id = ?
        ORDER BY r.created_at DESC, r.id DESC");
    $stmt->execute([(int)$user['id']]);
    $myReports = $stmt->fetchAll();
} catch (PDOException $e) {
    $error = 'Your submitted reports could not load right now.';
}

// Get announcements from database
$announcements = [];
try {
    // Check if announcements table exists
    $table_check = $conn->query("SHOW TABLES LIKE 'announcements'");
    if ($table_check->rowCount() > 0) {
        ensureStaffActivityTables($conn);
        // Opening Updates records a read receipt for this staff account.
        $markRead = $conn->prepare("INSERT IGNORE INTO announcement_reads (announcement_id, user_id) SELECT id, ? FROM announcements");
        $markRead->execute([$user['id']]);
        $stmt = $conn->prepare("SELECT a.*, u.first_name, u.last_name, ar.read_at FROM announcements a LEFT JOIN users u ON a.created_by = u.id LEFT JOIN announcement_reads ar ON ar.announcement_id = a.id AND ar.user_id = ? ORDER BY a.created_at DESC");
        $stmt->execute([$user['id']]);
        $announcements = $stmt->fetchAll();
    }
} catch (PDOException $e) {
    // The announcements table may not exist on a new installation.
}
$notificationItems = getNotificationItems($conn, $user);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Announcements - EcoTrack Staff</title>
    <?php include 'includes/theme_head.php'; ?>
    <style>
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

        /* Messages */
        .message {
          padding: 15px;
          border-radius: 10px;
          margin-bottom: 20px;
        }
        .message.success {
          background: #e8f5e9;
          color: #2e7d32;
          border: 1px solid #c8e6c9;
        }
        .message.error {
          background: #ffebee;
          color: #c62828;
          border: 1px solid #ffcdd2;
        }

        /* Tabs */
        .tabs {
          display: flex;
          gap: 10px;
          margin-bottom: 25px;
        }
        .tab-btn {
          padding: 12px 25px;
          border: none;
          border-radius: 25px;
          cursor: pointer;
          font-size: 13px;
          font-weight: 600;
          background: #e0e0e0;
          color: #666;
          transition: all 0.3s;
        }
        .tab-btn.active {
          background: #8bc34a;
          color: white;
        }

        /* Announcement Cards */
        .announcement-list {
          display: flex;
          flex-direction: column;
          gap: 15px;
        }
        .announcement-card {
          background: white;
          border-radius: 15px;
          padding: 25px;
          box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
          border-left: 4px solid #8bc34a;
        }
        .announcement-card.high-priority {
          border-left-color: #f44336;
        }
        .announcement-header {
          display: flex;
          justify-content: space-between;
          align-items: flex-start;
          margin-bottom: 10px;
        }
        .announcement-title {
          font-size: 18px;
          font-weight: 600;
          color: #333;
        }
        .announcement-badge {
          padding: 5px 15px;
          border-radius: 15px;
          font-size: 11px;
          font-weight: 600;
          text-transform: uppercase;
        }
        .announcement-badge.high {
          background: #ffebee;
          color: #c62828;
        }
        .announcement-badge.normal {
          background: #e8f5e9;
          color: #2e7d32;
        }
        .announcement-read {
          color: #176b59;
          background: #d9eee8;
          padding: 5px 10px;
          border-radius: 15px;
          font-size: 11px;
          font-weight: 700;
        }
        .announcement-content {
          color: #666;
          font-size: 14px;
          margin-bottom: 15px;
          line-height: 1.6;
        }
        .announcement-meta {
          display: flex;
          gap: 20px;
          font-size: 12px;
          color: #999;
        }

        /* Report Form */
        .form-container {
          background: white;
          border-radius: 15px;
          padding: 25px;
          box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
          display: none;
        }
        .form-container.active {
          display: block;
        }
        .form-container h2 {
          font-size: 18px;
          font-weight: 600;
          color: #333;
          margin-bottom: 20px;
          border-bottom: 2px solid #8bc34a;
          padding-bottom: 10px;
        }
        .form-group {
          margin-bottom: 20px;
        }
        .form-group label {
          display: block;
          font-size: 13px;
          font-weight: 600;
          color: #666;
          text-transform: uppercase;
          margin-bottom: 8px;
        }
        .form-group input,
        .form-group select,
        .form-group textarea {
          width: 100%;
          padding: 12px;
          border: 1px solid #ddd;
          border-radius: 8px;
          font-size: 14px;
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
          outline: none;
          border-color: #8bc34a;
        }
        .form-group textarea {
          resize: vertical;
          min-height: 120px;
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
        }
        .btn-green {
          background: #8bc34a;
          color: white;
        }
        .btn-green:hover {
          background: #7cb342;
        }
        .btn-gray {
          background: #9e9e9e;
          color: white;
        }
        .btn-gray:hover {
          background: #757575;
        }

        .tab-content {
          display: none;
        }
        .tab-content.active {
          display: block;
        }

        /* Keep staff issue tracking beside existing updates. */
        .staff-report-list {
          display: grid;
          gap: 15px;
        }
        .staff-report-card {
          background: var(--bg-secondary);
          border: 1px solid var(--border-color);
          border-left: 4px solid #8bc34a;
          border-radius: 12px;
          padding: 20px;
          box-shadow: var(--card-shadow);
        }
        .staff-report-card.is-highlighted {
          border-color: #176b59;
          box-shadow: 0 0 0 3px rgba(23, 107, 89, 0.18);
        }
        .staff-report-header {
          display: flex;
          justify-content: space-between;
          align-items: flex-start;
          gap: 14px;
          margin-bottom: 12px;
        }
        .staff-report-title {
          font-size: 17px;
          font-weight: 700;
          color: var(--text-primary);
          text-transform: capitalize;
        }
        .staff-report-meta {
          display: flex;
          flex-wrap: wrap;
          gap: 8px 18px;
          color: var(--text-secondary);
          font-size: 12px;
          margin-bottom: 13px;
        }
        .staff-report-description {
          color: var(--text-primary);
          line-height: 1.55;
          white-space: pre-wrap;
        }
        .staff-report-note {
          margin-top: 15px;
          padding: 12px 14px;
          border-radius: 8px;
          background: #eef6f2;
          border-left: 3px solid #176b59;
          color: #235347;
          white-space: pre-wrap;
          font-size: 13px;
          line-height: 1.45;
        }
        .report-status {
          display: inline-flex;
          align-items: center;
          padding: 5px 10px;
          border-radius: 999px;
          font-size: 11px;
          font-weight: 700;
          text-transform: uppercase;
          white-space: nowrap;
        }
        .report-status.pending {
          background: #fef3c7;
          color: #92400e;
        }
        .report-status.in_review {
          background: #dbeafe;
          color: #1d4ed8;
        }
        .report-status.resolved {
          background: #dcfce7;
          color: #166534;
        }
        .empty-report-state {
          padding: 38px;
          text-align: center;
          color: var(--text-secondary);
          background: var(--bg-secondary);
          border: 1px dashed var(--border-color);
          border-radius: 12px;
        }
        @media (max-width: 640px) {
          .tabs {
            flex-wrap: wrap;
          }
          .tab-btn {
            padding: 10px 16px;
          }
          .staff-report-header {
            flex-direction: column;
          }
        }
    </style>
    <link rel="stylesheet" href="assets/css/ecotrack-theme.css?v=<?php echo filemtime(__DIR__ . '/assets/css/ecotrack-theme.css'); ?>">
</head>
<body>
    <?php $active_page = 'staff_announcements.php';
$useLogoutModal = true;
include 'includes/sidebar.php'; ?>

    <div class="main-content">
        <div class="header">
            <h1>ANNOUNCEMENTS & REPORTS</h1>
            <div class="header-icons">
                <?php include 'includes/notification_bell.php'; ?>
            </div>
        </div>

        <?php if (!empty($message)): ?><div class="message success"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
        <?php if (!empty($error)): ?><div class="message error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

        <!-- Tabs -->
        <div class="tabs">
            <button type="button" class="tab-btn <?php echo $activeTab === 'announcements' ? 'active' : ''; ?>" onclick="switchTab('announcements', this)">Announcements</button>
            <button type="button" class="tab-btn <?php echo $activeTab === 'report' ? 'active' : ''; ?>" onclick="switchTab('report', this)">Report Issue</button>
            <button type="button" class="tab-btn <?php echo $activeTab === 'my_reports' ? 'active' : ''; ?>" onclick="switchTab('my_reports', this)">My Reports</button>
        </div>

        <!-- Announcements Tab -->
        <div id="announcements" class="tab-content <?php echo $activeTab === 'announcements' ? 'active' : ''; ?>">
            <div class="announcement-list">
                <?php if (empty($announcements)): ?>
                    <div style="text-align: center; padding: 40px; color: #666;">
                        <p>No announcements yet.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($announcements as $ann): ?>
                        <div class="announcement-card <?php echo ($ann['priority'] ?? '') == 'high' ? 'high-priority' : ''; ?>">
                            <div class="announcement-header">
                                <div class="announcement-title"><?php echo htmlspecialchars($ann['title'] ?? 'No Title'); ?></div>
                                <div style="display:flex; gap:8px; align-items:center;">
                                    <?php if (!empty($ann['read_at'])): ?><span class="announcement-read">&#10003; Read</span><?php endif; ?>
                                    <span class="announcement-badge <?php echo $ann['priority'] ?? 'normal'; ?>"><?php echo ucfirst($ann['priority'] ?? 'normal'); ?></span>
                                </div>
                            </div>
                            <div class="announcement-content"><?php echo htmlspecialchars($ann['content'] ?? ''); ?></div>
                            <div class="announcement-meta">
                                <span>&#128100; By <?php echo htmlspecialchars(($ann['first_name'] ?? 'Admin') . ' ' . ($ann['last_name'] ?? '')); ?></span>
                                <span>&#128197; <?php echo !empty($ann['created_at']) ? date('M d, Y', strtotime($ann['created_at'])) : 'N/A'; ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Report Tab -->
        <div id="report" class="tab-content <?php echo $activeTab === 'report' ? 'active' : ''; ?>">
            <div class="form-container active">
                <h2>REPORT AN ISSUE</h2>
                <form method="post" action="">
                    <div class="form-group">
                        <label>Report Type</label>
                        <select name="report_type" required>
                            <option value="">Select Type</option>
                            <option value="equipment">Equipment Problem</option>
                            <option value="route">Route Issue</option>
                            <option value="waste">Waste Collection Issue</option>
                            <option value="safety">Safety Concern</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Zone/Location</label>
                        <input type="text" name="zone" maxlength="100">
                    </div>
                    <div class="form-group">
                        <label>Description</label>
                        <textarea name="description" required></textarea>
                    </div>
                    <div style="display: flex; gap: 15px;">
                        <button type="submit" name="submit_report" class="btn btn-green">SUBMIT REPORT</button>
                        <button type="reset" class="btn btn-gray">CLEAR</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- My Reports Tab -->
        <div id="my_reports" class="tab-content <?php echo $activeTab === 'my_reports' ? 'active' : ''; ?>">
            <?php if (empty($myReports)): ?>
                <div class="empty-report-state">You have not submitted any reports yet.</div>
            <?php else: ?>
                <div class="staff-report-list">
                    <?php foreach ($myReports as $staffReport): ?>
                        <?php
                        $reportStatus = $staffReport['status'] ?? 'pending';
                        if (!isset($reportStatusLabels[$reportStatus])) {
                            $reportStatus = 'pending';
                        }
                        $isHighlighted = $highlightReportId === (int)$staffReport['id'];
                        $reviewerName = trim(($staffReport['reviewer_first_name'] ?? '') . ' ' . ($staffReport['reviewer_last_name'] ?? ''));
                        ?>
                        <article id="staff-report-<?php echo (int)$staffReport['id']; ?>" class="staff-report-card <?php echo $isHighlighted ? 'is-highlighted' : ''; ?>">
                            <div class="staff-report-header">
                                <div class="staff-report-title"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $staffReport['report_type'] ?? 'Issue'))); ?> Report</div>
                                <span class="report-status <?php echo htmlspecialchars($reportStatus); ?>"><?php echo htmlspecialchars($reportStatusLabels[$reportStatus]); ?></span>
                            </div>
                            <div class="staff-report-meta">
                                <span><strong>Location:</strong> <?php echo htmlspecialchars($staffReport['zone'] ?: 'Not specified'); ?></span>
                                <span><strong>Submitted:</strong> <?php echo !empty($staffReport['created_at']) ? htmlspecialchars(date('M d, Y g:i A', strtotime($staffReport['created_at']))) : 'N/A'; ?></span>
                                <?php if (!empty($staffReport['reviewed_at'])): ?><span><strong>Last updated:</strong> <?php echo htmlspecialchars(date('M d, Y g:i A', strtotime($staffReport['reviewed_at']))); ?><?php echo $reviewerName !== '' ? ' by ' . htmlspecialchars($reviewerName) : ''; ?></span><?php endif; ?>
                            </div>
                            <div class="staff-report-description"><?php echo htmlspecialchars($staffReport['description'] ?? ''); ?></div>
                            <?php if (trim((string)($staffReport['admin_note'] ?? '')) !== ''): ?>
                                <div class="staff-report-note"><strong>Admin note:</strong><br><?php echo htmlspecialchars($staffReport['admin_note']); ?></div>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php include 'includes/logout_confirmation_modal.php'; ?>

    <script>
        function switchTab(tab, trigger) {
          document.querySelectorAll(".tab-btn").forEach((btn) => btn.classList.remove("active"));
          document.querySelectorAll(".tab-content").forEach((content) => content.classList.remove("active"));

          if (trigger) trigger.classList.add("active");
          document.getElementById(tab).classList.add("active");
        }

        const highlightedStaffReport = document.querySelector(".staff-report-card.is-highlighted");
        if (highlightedStaffReport) {
          highlightedStaffReport.scrollIntoView({ block: "center", behavior: "smooth" });
        }
    </script>
</body>
</html>

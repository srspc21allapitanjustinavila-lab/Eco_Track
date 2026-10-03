<?php
require_once 'config.php';

requireLogin();

// Get current user info
$user_id = $_SESSION['user_id'];
$user_type = $_SESSION['user']['user_type'] ?? 'staff';
$settingsRoute = $user_type === 'staff' ? 'staff_settings.php' : 'admin_system_settings.php';
if (empty($canonicalRouteEntry) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $routeQuery = http_build_query($_GET);
    header('Location: ' . $settingsRoute . ($routeQuery === '' ? '' : '?' . $routeQuery));
    exit();
}

$conn = getDBConnection();
$message = '';
$error = '';
$currentSettings = getUserSettings($conn, $user_id);
applyUserSettingsToSession($currentSettings);

function settingToggleValue($value)
{
    return (string)$value === '1' ? 1 : 0;
}

function isValidSettingToggle($value)
{
    return in_array((string)$value, ['0', '1'], true);
}

function normalizeActivityLogYear($value)
{
    $value = trim((string)$value);
    return preg_match('/^(?:19|20)\d{2}$/', $value) ? $value : '';
}

function normalizeActivityLogRole($value)
{
    $value = strtolower(trim((string)$value));
    return in_array($value, ['admin', 'staff'], true) ? ucfirst($value) : '';
}

// Handle Change Password
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['change_password'])) {
    $current_password = trim($_POST['current_password'] ?? '');
    $new_password = trim($_POST['new_password'] ?? '');
    $confirm_password = trim($_POST['confirm_password'] ?? '');

    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        $error = "Please fill in all password fields.";
    } elseif ($new_password !== $confirm_password) {
        $error = "New passwords do not match.";
    } elseif (strlen($new_password) < 6) {
        $error = "Password must be at least 6 characters.";
    } else {
        try {
            $stmt = $conn->prepare("SELECT password_hash FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch();

            if ($user && password_verify($current_password, $user['password_hash'])) {
                $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
                if (!ensurePersistentLoginTokensTable($conn)) {
                    throw new RuntimeException('Unable to prepare remembered login storage.');
                }
                $conn->beginTransaction();
                $stmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                $stmt->execute([$new_hash, $user_id]);
                if (!revokePersistentLoginsForUser($conn, $user_id)) {
                    throw new RuntimeException('Unable to revoke remembered logins.');
                }
                $conn->commit();
                logActivity('Changed password', 'Settings', 'Success', $conn);
                logoutUser();
                header('Location: login.php?password=changed');
                exit();
            } else {
                $error = "Current password is incorrect.";
                logActivity('Changed password', 'Settings', 'Failed', $conn);
            }
        } catch (Throwable $e) {
            if ($conn && $conn->inTransaction()) {
                $conn->rollBack();
            }
            $error = "Failed to change password.";
            logActivity('Changed password', 'Settings', 'Failed', $conn);
        }
    }
}

// Persist System Preferences; theme storage remains unchanged.
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_preferences'])) {
    $submittedTheme = strtolower(trim((string)($_POST['theme'] ?? '')));
    $reportsReminder = $_POST['reports_reminder'] ?? null;
    $highWasteAlerts = $_POST['high_waste_alerts'] ?? null;
    if (!in_array($submittedTheme, ['light', 'dark'], true) || !isValidSettingToggle($reportsReminder) || !isValidSettingToggle($highWasteAlerts)) {
        $error = 'Invalid or unauthorized System Preferences data was rejected. Refresh the page and try again.';
        logActivity('Updated system preferences', 'Settings', 'Failed', $conn);
    } else {
        $updatedSettings = $currentSettings;
        $updatedSettings['reports_reminder'] = settingToggleValue($reportsReminder);
        $updatedSettings['high_waste_alerts'] = settingToggleValue($highWasteAlerts);

        if (saveUserSettings($conn, $user_id, $updatedSettings)) {
            $_SESSION['theme'] = $submittedTheme;
            $currentSettings = $updatedSettings;
            applyUserSettingsToSession($currentSettings);
            $message = "Preferences saved successfully!";
            logActivity('Updated system preferences', 'Settings', 'Success', $conn);
        } else {
            $error = "Failed to save preferences.";
            logActivity('Updated system preferences', 'Settings', 'Failed', $conn);
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['reset_preferences'])) {
    $defaults = getDefaultUserSettings();
    $updatedSettings = $currentSettings;
    foreach (['reports_reminder', 'high_waste_alerts'] as $preferenceKey) {
        $updatedSettings[$preferenceKey] = $defaults[$preferenceKey];
    }
    if (saveUserSettings($conn, $user_id, $updatedSettings)) {
        $currentSettings = $updatedSettings;
        applyUserSettingsToSession($currentSettings);
        $message = "Preferences reset to their default values.";
        logActivity('Reset system preferences', 'Settings', 'Success', $conn);
    } else {
        $error = "Failed to reset preferences.";
        logActivity('Reset system preferences', 'Settings', 'Failed', $conn);
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_security_settings'])) {
    $twoFactorEnabled = $_POST['two_factor_enabled'] ?? null;
    if (!isValidSettingToggle($twoFactorEnabled)) {
        $error = 'Invalid or unauthorized Password settings data was rejected. Refresh the page and try again.';
        logActivity('Updated login security settings', 'Settings', 'Failed', $conn);
    } else {
        $updatedSettings = $currentSettings;
        $updatedSettings['two_factor_enabled'] = settingToggleValue($twoFactorEnabled);

        if (saveUserSettings($conn, $user_id, $updatedSettings)) {
            $currentSettings = $updatedSettings;
            applyUserSettingsToSession($currentSettings);
            $message = "Security settings updated. Two-factor verification applies on your next login.";
            logActivity('Updated login security settings', 'Settings', 'Success', $conn);
        } else {
            $error = "Failed to save security settings.";
            logActivity('Updated login security settings', 'Settings', 'Failed', $conn);
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_privacy_settings'])) {
    $shareAnonymizedData = $_POST['share_anonymized_data'] ?? null;
    $dataExportEnabled = $_POST['data_export_enabled'] ?? null;

    if (!isValidSettingToggle($shareAnonymizedData) || !isValidSettingToggle($dataExportEnabled)) {
        $error = 'Invalid or unauthorized Data Privacy settings data was rejected. Refresh the page and try again.';
        logActivity('Updated data privacy settings', 'Settings', 'Failed', $conn);
    }

    if ($error === '') {
        $updatedSettings = $currentSettings;
        $updatedSettings['share_anonymized_data'] = settingToggleValue($shareAnonymizedData);
        $updatedSettings['data_export_enabled'] = settingToggleValue($dataExportEnabled);
        $isEnablingDataExport = empty($currentSettings['data_export_enabled']) && !empty($updatedSettings['data_export_enabled']);
        $exportOtpSent = false;

        // Send an export authorization code before either privacy setting is persisted.
        if ($isEnablingDataExport) {
            $exportOtpSent = issueDataExportOtp($conn, getCurrentUser());
            if (!$exportOtpSent) {
                $error = 'Data export requires a verification code, but one could not be sent to your registered email address. The settings were not changed.';
                logActivity('Enabled data export', 'Data Privacy', 'Failed', $conn);
            }
        }

        if ($error === '') {
            try {
                $conn->beginTransaction();
                if (!saveUserSettings($conn, $user_id, $updatedSettings)) {
                    throw new RuntimeException('Failed to save data privacy settings.');
                }
                $conn->commit();

                $currentSettings = $updatedSettings;
                applyUserSettingsToSession($currentSettings);
                $message = $exportOtpSent
                    ? 'Data privacy settings saved. A verification code was sent to your registered email address for one export.'
                    : 'Data privacy settings saved successfully.';
                logActivity('Updated data privacy settings', 'Settings', 'Success', $conn);
            } catch (Throwable $e) {
                if ($conn && $conn->inTransaction()) {
                    $conn->rollBack();
                }
                $error = 'Failed to save data privacy settings.';
                logActivity('Updated data privacy settings', 'Settings', 'Failed', $conn);
            }
        }
    }
}

// Theme storage remains independent from persisted account preferences.
$theme = $_SESSION['theme'] ?? 'light';
$reports_reminder = $currentSettings['reports_reminder'];
$high_waste_alerts = $currentSettings['high_waste_alerts'];
$two_factor_enabled = $currentSettings['two_factor_enabled'];
$auto_delete_old_reports = $currentSettings['auto_delete_old_reports'];
$share_anonymized_data = $currentSettings['share_anonymized_data'];
$data_export_enabled = $currentSettings['data_export_enabled'];

// Get current tab
$current_tab = $_GET['tab'] ?? 'preferences';

// Activity Logs are available only to administrators.
$activityModule = trim($_GET['activity_module'] ?? '');
$activityYear = normalizeActivityLogYear($_GET['activity_year'] ?? '');
$activityRole = normalizeActivityLogRole($_GET['activity_role'] ?? '');
$activityPageSize = 25;
$requestedActivityPage = filter_input(INPUT_GET, 'activity_page', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
$activityLogs = [];
$activityModules = [];
$activityYears = [];
$activityTotalCount = 0;
$activityTotalPages = 1;
$activityCurrentPage = 1;
$activityRangeStart = 0;
$activityRangeEnd = 0;
$activityWindowStart = 1;
$activityWindowEnd = 1;
$activityError = '';
if ($current_tab === 'activity_logs' && $user_type !== 'admin') {
    header('Location: access_denied.php');
    exit();
}

if ($current_tab === 'activity_logs' && $user_type === 'admin') {
    ensureActivityLogsTable($conn);
    $activityWhere = [];
    $activityParams = [];
    if ($activityModule !== '') {
        $activityWhere[] = 'module = ?';
        $activityParams[] = $activityModule;
    }
    if ($activityYear !== '') {
        $activityWhere[] = 'YEAR(created_at) = ?';
        $activityParams[] = $activityYear;
    }
    if ($activityRole !== '') {
        $activityWhere[] = 'user_role = ?';
        $activityParams[] = $activityRole;
    }
    try {
        $activityModules = $conn->query('SELECT DISTINCT module FROM activity_logs ORDER BY module')->fetchAll(PDO::FETCH_COLUMN);
        $activityYears = $conn->query('SELECT DISTINCT YEAR(created_at) AS activity_year FROM activity_logs WHERE created_at IS NOT NULL ORDER BY activity_year DESC')->fetchAll(PDO::FETCH_COLUMN);
        $activityCountSql = 'SELECT COUNT(*) FROM activity_logs';
        if ($activityWhere) {
            $activityCountSql .= ' WHERE ' . implode(' AND ', $activityWhere);
        }
        $activityCountStmt = $conn->prepare($activityCountSql);
        $activityCountStmt->execute($activityParams);
        $activityTotalCount = (int)$activityCountStmt->fetchColumn();
        $activityTotalPages = max(1, (int)ceil($activityTotalCount / $activityPageSize));
        $activityCurrentPage = min($requestedActivityPage, $activityTotalPages);
        $activityOffset = ($activityCurrentPage - 1) * $activityPageSize;

        $activitySql = 'SELECT user_name, user_role, action, module, created_at, status FROM activity_logs';
        if ($activityWhere) {
            $activitySql .= ' WHERE ' . implode(' AND ', $activityWhere);
        }
        $activitySql .= ' ORDER BY created_at DESC, id DESC LIMIT ' . $activityPageSize . ' OFFSET ' . $activityOffset;
        $activityStmt = $conn->prepare($activitySql);
        $activityStmt->execute($activityParams);
        $activityLogs = $activityStmt->fetchAll();
    } catch (PDOException $e) {
        $activityError = 'Activity logs could not be loaded.';
    }
}

$activityPaginationParams = array_filter([
    'tab' => 'activity_logs',
    'activity_role' => $activityRole,
    'activity_module' => $activityModule,
    'activity_year' => $activityYear,
], static function ($value) {
    return $value !== '';
});
$activityPageUrl = static function ($page) use ($activityPaginationParams, $settingsRoute) {
    return $settingsRoute . '?' . http_build_query(array_merge($activityPaginationParams, ['activity_page' => $page]));
};

if ($activityTotalCount > 0) {
    $activityRangeStart = (($activityCurrentPage - 1) * $activityPageSize) + 1;
    $activityRangeEnd = min($activityCurrentPage * $activityPageSize, $activityTotalCount);
    $activityWindowStart = max(1, min($activityCurrentPage - 4, max(1, $activityTotalPages - 9)));
    $activityWindowEnd = min($activityTotalPages, $activityWindowStart + 9);
}

$notificationItems = getNotificationItems($conn, getCurrentUser());
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - EcoTrack</title>
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
          display: flex;
          min-height: 100vh;
          color: var(--text-primary);
          transition:
            background 0.3s,
            color 0.3s;
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
          margin-bottom: 20px;
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

        /* Messages */
        .message {
          padding: 15px;
          border-radius: 10px;
          margin-bottom: 20px;
        }
        .message.success {
          background: var(--bg-secondary);
          color: var(--text-primary);
          border: 1px solid var(--border-color);
        }
        .message.error {
          background: var(--bg-secondary);
          color: var(--text-primary);
          border: 1px solid var(--border-color);
        }

        /* Tab Navigation */
        .tab-nav {
          display: flex;
          gap: 10px;
          margin-bottom: 30px;
        }
        .tab-btn {
          padding: 12px 25px;
          border: none;
          border-radius: 25px;
          cursor: pointer;
          font-size: 13px;
          font-weight: 600;
          text-transform: uppercase;
          background: var(--toggle-bg);
          color: var(--text-secondary);
          text-decoration: none;
          transition: all 0.3s;
        }
        .tab-btn.active {
          background: #8bc34a;
          color: white;
        }
        .tab-btn:hover {
          background: #9ccc65;
        }

        [data-theme="dark"] .tab-btn.active {
          background: #7cb342;
        }
        [data-theme="dark"] .tab-btn:hover {
          background: #6b9e3a;
        }

        /* Content Area */
        .tab-content {
          display: none;
        }
        .tab-content.active {
          display: block;
        }

        .section-title {
          font-size: 20px;
          font-weight: bold;
          color: var(--text-primary);
          margin-bottom: 10px;
        }
        .section-desc {
          color: var(--text-secondary);
          font-size: 14px;
          margin-bottom: 30px;
        }

        /* Settings Cards */
        .settings-grid {
          display: grid;
          grid-template-columns: repeat(2, 1fr);
          gap: 20px;
          margin-bottom: 30px;
        }
        .preferences-settings {
          grid-template-columns: 1fr;
        }
        .settings-card {
          background: var(--bg-secondary);
          border-radius: 15px;
          padding: 25px;
          box-shadow: var(--card-shadow);
          transition: background 0.3s;
        }
        .settings-card h3 {
          font-size: 16px;
          font-weight: 600;
          color: var(--text-primary);
          margin-bottom: 20px;
          text-transform: uppercase;
        }

        /* Toggle Switch */
        .toggle-group {
          display: flex;
          align-items: center;
          gap: 15px;
          margin-bottom: 15px;
        }
        .toggle {
          position: relative;
          width: 50px;
          height: 26px;
          background: var(--toggle-bg);
          border-radius: 13px;
          cursor: pointer;
          transition: all 0.3s;
        }
        .toggle.active {
          background: #8bc34a;
        }
        .toggle::after {
          content: "";
          position: absolute;
          width: 22px;
          height: 22px;
          background: white;
          border-radius: 50%;
          top: 2px;
          left: 2px;
          transition: all 0.3s;
        }
        .toggle.active::after {
          left: 26px;
        }
        .toggle-label {
          font-size: 14px;
          color: var(--text-primary);
        }

        /* Radio Buttons */
        .radio-group {
          display: flex;
          flex-direction: column;
          gap: 12px;
          margin-bottom: 15px;
        }
        .radio-item {
          display: flex;
          align-items: center;
          gap: 10px;
          cursor: pointer;
        }
        .radio-item input[type="radio"] {
          width: 18px;
          height: 18px;
          accent-color: #8bc34a;
        }
        .radio-item label {
          font-size: 14px;
          color: var(--text-primary);
          cursor: pointer;
        }

        /* Select Dropdown */
        .select-group {
          margin-bottom: 15px;
        }
        .select-group label {
          display: block;
          font-size: 13px;
          color: var(--text-secondary);
          text-transform: uppercase;
          margin-bottom: 8px;
        }
        .select-group select {
          width: 100%;
          padding: 10px;
          border: 1px solid var(--input-border);
          border-radius: 5px;
          font-size: 14px;
          background: var(--input-bg);
          color: var(--text-primary);
        }
        .retention-policy {
          margin: 0;
          padding: 12px 14px;
          border: 1px solid var(--border-color);
          border-radius: 7px;
          background: var(--bg-primary);
          color: var(--text-secondary);
          font-size: 14px;
          line-height: 1.5;
        }

        /* Password Strength */
        .password-strength {
          margin-top: 10px;
        }
        .strength-bar {
          height: 8px;
          background: var(--toggle-bg);
          border-radius: 4px;
          overflow: hidden;
          margin-bottom: 5px;
        }
        .strength-fill {
          height: 100%;
          width: 30%;
          background: #f44336;
          border-radius: 4px;
          transition: all 0.3s;
        }
        .strength-fill.medium {
          width: 60%;
          background: #ff9800;
        }
        .strength-fill.strong {
          width: 100%;
          background: #4caf50;
        }
        .strength-text {
          font-size: 12px;
          color: var(--text-secondary);
        }

        /* Security Card */
        .security-card {
          background: var(--bg-primary);
          border-radius: 15px;
          padding: 25px;
          margin-bottom: 20px;
        }
        .security-card h4 {
          font-size: 14px;
          font-weight: 600;
          color: var(--text-primary);
          margin-bottom: 15px;
          display: flex;
          align-items: center;
          gap: 10px;
        }
        .security-card h4 .icon {
          font-size: 20px;
        }

        /* Form Inputs */
        .form-group {
          margin-bottom: 15px;
        }
        .form-group label {
          display: block;
          font-size: 13px;
          color: var(--text-secondary);
          text-transform: uppercase;
          margin-bottom: 8px;
        }
        .form-group input {
          width: 100%;
          padding: 12px;
          border: 1px solid var(--input-border);
          border-radius: 5px;
          font-size: 14px;
          background: var(--input-bg);
          color: var(--text-primary);
        }
        .form-group input:focus {
          outline: none;
          border-color: #8bc34a;
        }

        /* Buttons */
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
        .btn-container {
          display: flex;
          gap: 15px;
          justify-content: flex-end;
          margin-top: 30px;
        }

        /* Activity Logs inside Settings */
        .activity-filter {
          display: grid;
          grid-template-columns: 1fr 1fr 1fr auto;
          gap: 12px;
          align-items: end;
          margin-bottom: 20px;
        }
        .activity-filter label {
          display: block;
          margin-bottom: 7px;
          font-size: 12px;
          font-weight: 700;
          color: var(--text-secondary);
          text-transform: uppercase;
        }
        .activity-filter input,
        .activity-filter select {
          width: 100%;
          padding: 11px 12px;
          border: 1px solid var(--input-border);
          border-radius: 7px;
          background: var(--input-bg);
          color: var(--text-primary);
        }
        .activity-filter-actions {
          display: flex;
          align-items: center;
          gap: 10px;
        }
        .activity-filter-actions .btn {
          border-radius: 7px;
          text-transform: none;
        }
        .activity-clear {
          color: var(--text-secondary);
          font-size: 13px;
          text-decoration: none;
        }
        .activity-table-wrap {
          overflow-x: auto;
          background: var(--bg-secondary);
        }
        .activity-table {
          width: 100%;
          min-width: 850px;
          border-collapse: collapse;
        }
        .activity-table th {
          padding: 14px;
          text-align: left;
          background: var(--eco-primary-soft, #d9eee8);
          color: var(--text-primary);
          font-size: 12px;
          text-transform: uppercase;
        }
        .activity-table td {
          padding: 14px;
          border-top: 1px solid var(--border-color);
          font-size: 14px;
        }
        .activity-role,
        .activity-status {
          display: inline-block;
          padding: 4px 9px;
          border-radius: 20px;
          font-size: 12px;
          font-weight: 700;
        }
        .activity-role {
          background: #e9f3eb;
          color: #36734a;
        }
        .activity-success {
          background: #e5f5e9;
          color: #23813d;
        }
        .activity-failed {
          background: #fdeaea;
          color: #b03939;
        }
        .activity-empty {
          padding: 35px;
          text-align: center;
          color: var(--text-secondary);
        }
        .activity-pagination {
          display: flex;
          flex-wrap: wrap;
          align-items: center;
          justify-content: space-between;
          gap: 16px;
          min-height: 62px;
          padding: 5px 20px;
          border-top: 1px solid var(--border-color);
          background: var(--eco-bg, #f3f7f5);
        }
        .activity-pagination-summary {
          color: var(--text-secondary);
          font-size: 14px;
          white-space: nowrap;
        }
        .activity-pagination-links {
          display: flex;
          flex-wrap: wrap;
          justify-content: flex-end;
          gap: 6px;
        }
        .activity-pagination-links a,
        .activity-pagination-links span {
          display: inline-flex;
          align-items: center;
          justify-content: center;
          min-width: 40px;
          height: 47px;
          padding: 0 12px;
          border: 1px solid var(--input-border);
          border-radius: 6px;
          background: var(--input-bg);
          color: var(--text-primary);
          font-size: 16px;
          text-align: center;
          text-decoration: none;
        }
        .activity-pagination-links a:hover {
          border-color: var(--toggle-active);
          color: var(--toggle-active);
        }
        .activity-pagination-links .active {
          border-color: var(--toggle-active);
          background: var(--toggle-active);
          color: #fff;
          font-weight: 700;
        }
        .activity-pagination-links .disabled {
          opacity: 0.5;
        }
        @media (max-width: 900px) {
          .activity-filter {
            grid-template-columns: 1fr;
          }
          .activity-pagination {
            align-items: flex-start;
            flex-direction: column;
            padding: 12px;
          }
          .activity-pagination-links {
            justify-content: flex-start;
          }
        }
    </style>
    <link rel="stylesheet" href="assets/css/ecotrack-theme.css">
</head>
<body>
    <?php $active_page = $settingsRoute;
$useLogoutModal = true;
include 'includes/sidebar.php'; ?>

    <div class="main-content">
        <div class="header">
            <h1>SYSTEM PREFERENCES</h1>
            <div class="header-icons">
                <?php include 'includes/notification_bell.php'; ?>
            </div>
        </div>

        <?php if (!empty($message)): ?><div class="message success"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
        <?php if (!empty($error)): ?><div class="message error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
        <?php if (!empty($error)): ?>
            <div id="settingsValidationModal" class="transaction-confirmation-modal is-open" aria-hidden="false">
                <section class="transaction-confirmation-dialog" role="alertdialog" aria-modal="true" aria-labelledby="settingsValidationTitle" aria-describedby="settingsValidationMessage">
                    <div class="transaction-confirmation-icon" aria-hidden="true">!</div>
                    <h2 id="settingsValidationTitle">Changes not saved</h2>
                    <p id="settingsValidationMessage"><?php echo htmlspecialchars($error); ?></p>
                    <div class="transaction-confirmation-actions"><button type="button" class="transaction-confirmation-continue" id="closeSettingsValidation" autofocus>Okay</button></div>
                </section>
            </div>
        <?php endif; ?>

        <!-- Tab Navigation -->
        <div class="tab-nav">
            <a href="?tab=preferences" class="tab-btn <?php echo $current_tab == 'preferences' ? 'active' : ''; ?>">System Preference</a>
            <a href="?tab=security" class="tab-btn <?php echo $current_tab == 'security' ? 'active' : ''; ?>">Security</a>
            <a href="?tab=privacy" class="tab-btn <?php echo $current_tab == 'privacy' ? 'active' : ''; ?>">Data Privacy</a>
            <?php if ($user_type === 'admin'): ?><a href="?tab=activity_logs" class="tab-btn <?php echo $current_tab == 'activity_logs' ? 'active' : ''; ?>">Activity Logs</a><?php endif; ?>
        </div>

        <!-- System Preferences Tab -->
        <div class="tab-content <?php echo $current_tab == 'preferences' ? 'active' : ''; ?>" id="preferences">
            <div class="section-title">SYSTEM PREFERENCES</div>
            <div class="section-desc">CONFIGURE GENERAL SYSTEM SETTINGS FOR WASTE MANAGEMENT MONITORING AND REPORTING.</div>

            <form method="post" action="?tab=preferences" data-confirm-title="Save System Preferences?" data-confirm-message="Apply these preference changes to your account?" data-confirm-action="Save preferences">
                <div class="settings-grid preferences-settings">
                    <!-- Display Settings -->
                    <div class="settings-card">
                        <h3>DISPLAY SETTINGS</h3>
                        <div class="toggle-group">
                            <div id="themeToggle" class="toggle <?php echo $theme == 'dark' ? 'active' : ''; ?>" onclick="toggleTheme(this)"></div>
                            <span id="themeLabel" class="toggle-label"><?php echo $theme == 'dark' ? 'DARK' : 'LIGHT'; ?></span>
                            <input type="hidden" name="theme" id="theme" value="<?php echo $theme; ?>">
                        </div>
                    </div>
                </div>

                <!-- Notification Preferences -->
                <div class="settings-card">
                    <h3>NOTIFICATION PREFERENCES</h3>
                    <div class="toggle-group">
                        <div class="toggle <?php echo $reports_reminder ? 'active' : ''; ?>" role="switch" tabindex="0" aria-checked="<?php echo $reports_reminder ? 'true' : 'false'; ?>" onclick="toggleStoredToggle(this, 'reports_reminder')" onkeydown="toggleStoredToggleOnKey(event, this, 'reports_reminder')"></div>
                        <span class="toggle-label">ENABLE REPORTS REMINDER</span>
                        <input type="hidden" name="reports_reminder" id="reports_reminder" value="<?php echo $reports_reminder; ?>">
                    </div>
                    <div class="toggle-group">
                        <div class="toggle <?php echo $high_waste_alerts ? 'active' : ''; ?>" role="switch" tabindex="0" aria-checked="<?php echo $high_waste_alerts ? 'true' : 'false'; ?>" onclick="toggleStoredToggle(this, 'high_waste_alerts')" onkeydown="toggleStoredToggleOnKey(event, this, 'high_waste_alerts')"></div>
                        <span class="toggle-label">ENABLE HIGH-WASTE AREA ALERTS</span>
                        <input type="hidden" name="high_waste_alerts" id="high_waste_alerts" value="<?php echo $high_waste_alerts; ?>">
                    </div>
                </div>

                <div class="btn-container">
                    <button type="submit" name="save_preferences" class="btn btn-green">SAVE PREFERENCE</button>
                    <button type="submit" name="reset_preferences" class="btn btn-gray" data-confirm-title="Reset System Preferences?" data-confirm-message="Save your preferences first if you want to keep your current changes. Reset to the default values now?" data-confirm-action="Reset preferences">RESET</button>
                </div>
            </form>
        </div>

        <!-- Security Tab -->
        <div class="tab-content <?php echo $current_tab == 'security' ? 'active' : ''; ?>" id="security">
            <div class="section-title">SECURITY</div>
            <div class="section-desc">MANAGE ACCESS CONTROL AND SYSTEM SECURITY TO PREVENT UNAUTHORIZED USE.</div>

            <!-- Account Security -->
            <div class="security-card">
                <h4><span class="icon">&#128274;</span> CHANGE PASSWORD</h4>
                <form method="post" action="?tab=security" data-confirm-title="Change password?" data-confirm-message="Your new password will be saved and you will need to sign in again." data-confirm-action="Change password">
                    <div class="form-group">
                        <label>CURRENT PASSWORD</label>
                        <input type="password" name="current_password" required>
                    </div>
                    <div class="form-group">
                        <label>NEW PASSWORD</label>
                        <input type="password" name="new_password" id="new_password" required minlength="6" onkeyup="checkStrength(this.value)">
                        <div class="password-strength">
                            <div class="strength-bar"><div class="strength-fill" id="strengthFill"></div></div>
                            <span class="strength-text" id="strengthText">PASSWORD STRENGTH: WEAK</span>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>CONFIRM NEW PASSWORD</label>
                        <input type="password" name="confirm_password" required>
                    </div>
                    <button type="submit" name="change_password" class="btn btn-green" style="margin-top: 10px;">CHANGE PASSWORD</button>
                </form>
            </div>

            <!-- Log In Security -->
            <div class="security-card">
                <h4><span class="icon">&#128100;</span> LOG IN SECURITY</h4>
                <form method="post" action="?tab=security" data-confirm-title="Update security settings?" data-confirm-message="Apply the two-factor authentication setting to your account?" data-confirm-action="Update settings">
                    <div class="toggle-group" style="margin-bottom: 20px;">
                        <div class="toggle <?php echo $two_factor_enabled ? 'active' : ''; ?>" role="switch" tabindex="0" aria-checked="<?php echo $two_factor_enabled ? 'true' : 'false'; ?>" onclick="toggleStoredToggle(this, 'two_factor_enabled')" onkeydown="toggleStoredToggleOnKey(event, this, 'two_factor_enabled')"></div>
                        <span class="toggle-label">ENABLE TWO FACTOR AUTHENTICATION (2FA)</span>
                        <input type="hidden" name="two_factor_enabled" id="two_factor_enabled" value="<?php echo (int)$two_factor_enabled; ?>">
                    </div>

                    <div class="btn-container">
                        <button type="submit" name="update_security_settings" class="btn btn-green">UPDATE SECURITY SETTINGS</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Data Privacy Tab -->
        <div class="tab-content <?php echo $current_tab == 'privacy' ? 'active' : ''; ?>" id="privacy">
            <div class="section-title">DATA PRIVACY</div>
            <div class="section-desc">MANAGE DATA PRIVACY SETTINGS AND COMPLIANCE OPTIONS.</div>

            <form method="post" action="?tab=privacy" data-confirm-title="Save data privacy settings?" data-confirm-message="Apply the data-sharing and data-export changes to your account?" data-confirm-action="Save settings">
            <div class="settings-card">
                <h3>DATA SHARING</h3>
                <div class="toggle-group">
                    <div class="toggle <?php echo $share_anonymized_data ? 'active' : ''; ?>" role="switch" tabindex="0" aria-checked="<?php echo $share_anonymized_data ? 'true' : 'false'; ?>" onclick="toggleStoredToggle(this, 'share_anonymized_data')" onkeydown="toggleStoredToggleOnKey(event, this, 'share_anonymized_data')"></div>
                    <span class="toggle-label">SHARE ANONYMIZED DATA FOR RESEARCH</span>
                    <input type="hidden" name="share_anonymized_data" id="share_anonymized_data" value="<?php echo (int)$share_anonymized_data; ?>">
                </div>
                <div class="toggle-group">
                    <div class="toggle <?php echo $data_export_enabled ? 'active' : ''; ?>" role="switch" tabindex="0" aria-checked="<?php echo $data_export_enabled ? 'true' : 'false'; ?>" onclick="toggleStoredToggle(this, 'data_export_enabled')" onkeydown="toggleStoredToggleOnKey(event, this, 'data_export_enabled')"></div>
                    <span class="toggle-label">ENABLE DATA EXPORT</span>
                    <input type="hidden" name="data_export_enabled" id="data_export_enabled" value="<?php echo (int)$data_export_enabled; ?>">
                </div>
            </div>

            <div class="btn-container">
                <button type="submit" name="save_privacy_settings" class="btn btn-green">SAVE PRIVACY SETTINGS</button>
            </div>
            </form>
        </div>

        <?php if ($user_type === 'admin'): ?><div class="tab-content <?php echo $current_tab == 'activity_logs' ? 'active' : ''; ?>" id="activity_logs">
            <div class="section-title">ACTIVITY LOGS</div>
            <div class="section-desc">REVIEW ADMIN AND STAFF ACTIVITY. LOG ENTRIES ARE READ-ONLY.</div>
            <form method="get" action="<?php echo $settingsRoute; ?>" class="settings-card activity-filter">
                <input type="hidden" name="tab" value="activity_logs">
                <div><label for="activity_role">Actor role</label><select id="activity_role" name="activity_role"><option value="">All actors</option><option value="Staff" <?php echo $activityRole === 'Staff' ? 'selected' : ''; ?>>Staff</option><option value="Admin" <?php echo $activityRole === 'Admin' ? 'selected' : ''; ?>>Admin</option></select></div>
                <div><label for="activity_module">Module</label><select id="activity_module" name="activity_module"><option value="">All modules</option><?php foreach ($activityModules as $item): ?><option value="<?php echo htmlspecialchars($item); ?>" <?php echo $activityModule === $item ? 'selected' : ''; ?>><?php echo htmlspecialchars($item); ?></option><?php endforeach; ?></select></div>
                <div><label for="activity_year">Year</label><select id="activity_year" name="activity_year"><option value="">All years</option><?php foreach ($activityYears as $year): ?><option value="<?php echo htmlspecialchars($year); ?>" <?php echo $activityYear === $year ? 'selected' : ''; ?>><?php echo htmlspecialchars($year); ?></option><?php endforeach; ?></select></div>
                <div class="activity-filter-actions"><button type="submit" class="btn btn-green">Filter</button><a class="activity-clear" href="<?php echo $settingsRoute; ?>?tab=activity_logs">Clear</a></div>
            </form>
            <div class="settings-card" style="padding: 0; overflow: hidden;">
                <?php if ($activityError): ?><div class="activity-empty"><?php echo htmlspecialchars($activityError); ?></div>
                <?php elseif (!$activityLogs): ?><div class="activity-empty">No activity logs match the selected filters.</div>
                <?php else: ?><div class="activity-table-wrap"><table class="activity-table"><thead><tr><th>User</th><th>Role</th><th>Action</th><th>Module</th><th>Date &amp; Time</th><th>Status</th></tr></thead><tbody>
                    <?php foreach ($activityLogs as $log): ?><tr><td><?php echo htmlspecialchars($log['user_name']); ?></td><td><span class="activity-role"><?php echo htmlspecialchars(ucfirst($log['user_role'])); ?></span></td><td><?php echo htmlspecialchars($log['action']); ?></td><td><?php echo htmlspecialchars($log['module']); ?></td><td><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($log['created_at']))); ?></td><td><span class="activity-status <?php echo $log['status'] === 'Success' ? 'activity-success' : 'activity-failed'; ?>"><?php echo htmlspecialchars($log['status']); ?></span></td></tr><?php endforeach; ?>
                </tbody></table></div>
                <nav class="activity-pagination" aria-label="Activity Log pages">
                    <span class="activity-pagination-summary">Showing <?php echo $activityRangeStart; ?>–<?php echo $activityRangeEnd; ?> of <?php echo $activityTotalCount; ?> records</span>
                    <div class="activity-pagination-links">
                        <?php if ($activityCurrentPage > 1): ?><a href="<?php echo htmlspecialchars($activityPageUrl($activityCurrentPage - 1)); ?>" rel="prev">Previous</a><?php else: ?><span class="disabled">Previous</span><?php endif; ?>
                        <?php if ($activityWindowStart > 1): ?><a href="<?php echo htmlspecialchars($activityPageUrl(1)); ?>">1</a><span>…</span><?php endif; ?>
                        <?php for ($activityPage = $activityWindowStart; $activityPage <= $activityWindowEnd; $activityPage++): ?>
                            <?php if ($activityPage === $activityCurrentPage): ?><span class="active" aria-current="page"><?php echo $activityPage; ?></span><?php else: ?><a href="<?php echo htmlspecialchars($activityPageUrl($activityPage)); ?>"><?php echo $activityPage; ?></a><?php endif; ?>
                        <?php endfor; ?>
                        <?php if ($activityWindowEnd < $activityTotalPages): ?><span>…</span><a href="<?php echo htmlspecialchars($activityPageUrl($activityTotalPages)); ?>"><?php echo $activityTotalPages; ?></a><?php endif; ?>
                        <?php if ($activityCurrentPage < $activityTotalPages): ?><a href="<?php echo htmlspecialchars($activityPageUrl($activityCurrentPage + 1)); ?>" rel="next">Next</a><?php else: ?><span class="disabled">Next</span><?php endif; ?>
                    </div>
                </nav><?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script>
        // Check for saved theme preference on page load
        document.addEventListener("DOMContentLoaded", function () {
          const savedTheme = localStorage.getItem("theme") || "light";
          const themeToggle = document.getElementById("themeToggle");
          const themeLabel = document.getElementById("themeLabel");

          if (savedTheme === "dark") {
            document.documentElement.setAttribute("data-theme", "dark");
            themeToggle.classList.add("active");
            themeLabel.textContent = "DARK";
          } else {
            document.documentElement.setAttribute("data-theme", "light");
            themeToggle.classList.remove("active");
            themeLabel.textContent = "LIGHT";
          }
        });

        function toggleTheme(toggle) {
          toggle.classList.toggle("active");
          const isDark = toggle.classList.contains("active");
          const theme = isDark ? "dark" : "light";

          // Apply theme to document
          document.documentElement.setAttribute("data-theme", theme);
          document.getElementById("theme").value = theme;

          // Update label
          toggle.nextElementSibling.textContent = isDark ? "DARK" : "LIGHT";

          // Save to localStorage
          localStorage.setItem("theme", theme);
        }

        function toggleStoredToggle(toggle, inputId) {
          const hiddenInput = document.getElementById(inputId);
          if (!hiddenInput) return;
          const isActive = toggle.classList.toggle("active");
          toggle.setAttribute("aria-checked", isActive ? "true" : "false");
          hiddenInput.value = isActive ? "1" : "0";
        }

        function toggleStoredToggleOnKey(event, toggle, inputId) {
          if (event.key !== "Enter" && event.key !== " ") return;
          event.preventDefault();
          toggleStoredToggle(toggle, inputId);
        }

        function checkStrength(password) {
          const fill = document.getElementById("strengthFill");
          const text = document.getElementById("strengthText");

          let strength = 0;
          if (password.length >= 6) strength++;
          if (password.length >= 10) strength++;
          if (/[A-Z]/.test(password)) strength++;
          if (/[0-9]/.test(password)) strength++;
          if (/[^A-Za-z0-9]/.test(password)) strength++;

          fill.className = "strength-fill";
          if (strength <= 2) {
            fill.style.width = "30%";
            fill.style.background = "#f44336";
            text.textContent = "PASSWORD STRENGTH: WEAK";
          } else if (strength <= 4) {
            fill.style.width = "60%";
            fill.style.background = "#ff9800";
            text.textContent = "PASSWORD STRENGTH: MEDIUM";
          } else {
            fill.style.width = "100%";
            fill.style.background = "#4caf50";
            text.textContent = "PASSWORD STRENGTH: STRONG";
          }
        }

        document.getElementById("closeSettingsValidation")?.addEventListener("click", function () {
          document.getElementById("settingsValidationModal")?.classList.remove("is-open");
        });
    </script>

    <?php include 'includes/logout_confirmation_modal.php'; ?>
</body>
</html>

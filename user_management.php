<?php
require_once 'config.php';
requireUserType('admin');
$userManagementRoute = 'admin_user_management.php';
if (empty($canonicalRouteEntry) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $routeQuery = http_build_query($_GET);
    header('Location: ' . $userManagementRoute . ($routeQuery === '' ? '' : '?' . $routeQuery));
    exit();
}

if (isSessionTimeout()) {
    logoutUser();
    header("Location: login.php?timeout=1");
    exit();
}
$_SESSION['last_activity'] = time();

$conn = getDBConnection();
ensureReportsTable($conn);
$currentAdmin = getCurrentUser();
$currentAdminId = (int)($currentAdmin['id'] ?? 0);
$message = '';
$error = '';
$newlyAddedUserId = 0;

// Handle Add User
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_user'])) {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $user_type = trim($_POST['user_type'] ?? 'staff');
    $password = trim($_POST['password'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $gender = trim($_POST['gender'] ?? '');

    if (empty($username) || empty($email) || empty($first_name) || empty($last_name) || empty($password)) {
        $error = "Please fill in all required fields.";
    } elseif (!in_array($user_type, ['admin', 'staff'], true)) {
        $error = 'Please select a valid account role.';
    } else {
        try {
            $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
            $stmt->execute([$username, $email]);
            if ($stmt->fetch()) {
                $error = "Username or email already exists.";
            } else {
                $password_hash = password_hash($password, PASSWORD_DEFAULT);

                $photoError = '';
                $photo = storeUserPhotoUpload($_FILES['photo'] ?? null, $photoError);
                if ($photo === false) {
                    $error = $photoError;
                } else {
                    $stmt = $conn->prepare("INSERT INTO users (username, email, password_hash, first_name, last_name, user_type, photo, address, gender, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");
                    $stmt->execute([$username, $email, $password_hash, $first_name, $last_name, $user_type, $photo, $address, $gender]);
                    $newlyAddedUserId = (int)$conn->lastInsertId();
                    $message = ucfirst($user_type) . " account added successfully!";
                    logActivity('Added user: ' . $username, 'Users', 'Success', $conn);
                }
            }
        } catch (PDOException $e) {
            $error = "Failed to add user.";
            logActivity('Added user: ' . $username, 'Users', 'Failed', $conn);
        }
    }
}

// Handle Edit User
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_user'])) {
    $user_id = intval($_POST['user_id'] ?? 0);
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $user_type = trim($_POST['user_type'] ?? 'staff');
    $address = trim($_POST['address'] ?? '');
    $gender = trim($_POST['gender'] ?? '');

    if (empty($username) || empty($email) || empty($first_name) || empty($last_name)) {
        $error = "Please fill in all required fields.";
    } elseif (strlen($username) > 50) {
        $error = 'Username must be 50 characters or fewer.';
    } else {
        try {
            $existingStmt = $conn->prepare('SELECT id, username, email, user_type, is_active FROM users WHERE id = ?');
            $existingStmt->execute([$user_id]);
            $existingUser = $existingStmt->fetch();
            if (!$existingUser) {
                $error = 'The selected account no longer exists.';
            } elseif (!in_array($user_type, ['admin', 'staff'], true)) {
                $error = 'Please select a valid account role.';
            } elseif ($user_id === $currentAdminId && $user_type !== $existingUser['user_type']) {
                $error = 'Use another active administrator to change your own account role.';
            } else {
                $duplicateStmt = $conn->prepare('SELECT id FROM users WHERE (username = ? OR email = ?) AND id <> ?');
                $duplicateStmt->execute([$username, $email, $user_id]);
                if ($duplicateStmt->fetch()) {
                    $error = 'That username or email address is already in use.';
                } else {
                    if ($existingUser['user_type'] === 'admin' && $user_type !== 'admin' && (int)$existingUser['is_active'] === 1) {
                        $adminCount = (int)$conn->query("SELECT COUNT(*) FROM users WHERE user_type = 'admin' AND is_active = 1")->fetchColumn();
                        if ($adminCount <= 1) {
                            $error = 'At least one active administrator account must remain.';
                        }
                    }

                    if ($error === '') {
                        $photo_update = "";
                        $params = [$username, $email, $first_name, $last_name, $user_type, $address, $gender, $user_id];

                        $photoError = '';
                        $photo = storeUserPhotoUpload($_FILES['photo'] ?? null, $photoError);
                        if ($photo === false) {
                            $error = $photoError;
                        } elseif ($photo !== null) {
                            $photo_update = ", photo = ?";
                            array_splice($params, 7, 0, [$photo]);
                        }

                        if ($error === '') {
                            $stmt = $conn->prepare("UPDATE users SET username = ?, email = ?, first_name = ?, last_name = ?, user_type = ?, address = ?, gender = ? $photo_update WHERE id = ?");
                            $stmt->execute($params);
                            $message = "Account updated successfully!";
                            logActivity('Edited user: ' . $username, 'Users', 'Success', $conn);
                            if ($existingUser['username'] !== $username) {
                                logActivity('Changed username from ' . $existingUser['username'] . ' to ' . $username, 'Users', 'Success', $conn);
                            }
                            if ($existingUser['user_type'] !== $user_type) {
                                logActivity('Changed role for ' . $existingUser['username'] . ' to ' . ucfirst($user_type), 'Users', 'Success', $conn);
                            }
                        }
                    }
                }
            }
        } catch (PDOException $e) {
            $error = "Failed to update user.";
            logActivity('Edited user (ID ' . $user_id . ')', 'Users', 'Failed', $conn);
        }
    }
}

// Handle Deactivate/Activate User
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['deactivate_user'])) {
    $user_id = intval($_POST['user_id'] ?? 0);
    try {
        $accountStmt = $conn->prepare('SELECT username, user_type, is_active FROM users WHERE id = ?');
        $accountStmt->execute([$user_id]);
        $account = $accountStmt->fetch();
        if (!$account) {
            $error = 'The selected account no longer exists.';
        } elseif ($user_id === $currentAdminId) {
            $error = 'You cannot deactivate your own account from User Management.';
        } elseif ($account['user_type'] === 'admin' && (int)$account['is_active'] === 1) {
            $activeAdminCount = (int)$conn->query("SELECT COUNT(*) FROM users WHERE user_type = 'admin' AND is_active = 1")->fetchColumn();
            if ($activeAdminCount <= 1) {
                $error = 'At least one active administrator account must remain.';
            }
        }

        if ($error === '') {
            if (!ensurePersistentLoginTokensTable($conn)) {
                throw new RuntimeException('Unable to prepare remembered login storage.');
            }
            $conn->beginTransaction();
            $stmt = $conn->prepare("UPDATE users SET is_active = NOT is_active WHERE id = ?");
            $stmt->execute([$user_id]);
            if ((int)$account['is_active'] === 1 && !revokePersistentLoginsForUser($conn, $user_id)) {
                throw new RuntimeException('Unable to revoke remembered logins.');
            }
            $conn->commit();
            $message = "Account status updated successfully!";
            logActivity('Changed user status: ' . $account['username'], 'Users', 'Success', $conn);
        }
    } catch (Throwable $e) {
        if ($conn && $conn->inTransaction()) {
            $conn->rollBack();
        }
        $error = "Failed to update user status.";
        logActivity('Changed user status (ID ' . $user_id . ')', 'Users', 'Failed', $conn);
    }
}

// Handle Reset Password
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['reset_password'])) {
    $user_id = intval($_POST['user_id'] ?? 0);
    $new_password = trim($_POST['new_password'] ?? '');

    if ($user_id === $currentAdminId) {
        $error = 'Use the Password tab in Settings to change your own password.';
    } elseif (empty($new_password) || strlen($new_password) < 6) {
        $error = "Password must be at least 6 characters.";
    } else {
        try {
            $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
            if (!ensurePersistentLoginTokensTable($conn)) {
                throw new RuntimeException('Unable to prepare remembered login storage.');
            }
            $conn->beginTransaction();
            $stmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
            $stmt->execute([$password_hash, $user_id]);
            if (!revokePersistentLoginsForUser($conn, $user_id)) {
                throw new RuntimeException('Unable to revoke remembered logins.');
            }
            $conn->commit();
            $message = "Password reset successfully!";
            logActivity('Reset user password (ID ' . $user_id . ')', 'Users', 'Success', $conn);
        } catch (Throwable $e) {
            if ($conn && $conn->inTransaction()) {
                $conn->rollBack();
            }
            $error = "Failed to reset password.";
            logActivity('Reset user password (ID ' . $user_id . ')', 'Users', 'Failed', $conn);
        }
    }
}

// Fetch all staff and administrator accounts for the directory.
$userSearch = trim($_GET['search'] ?? '');
$roleFilter = trim($_GET['role'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$users = [];
try {
    $where = [];
    $params = [];
    if ($userSearch !== '') {
        $where[] = "(username LIKE ? OR email LIKE ? OR first_name LIKE ? OR last_name LIKE ? OR CONCAT(first_name, ' ', last_name) LIKE ?)";
        $term = '%' . $userSearch . '%';
        array_push($params, $term, $term, $term, $term, $term);
    }
    if (in_array($roleFilter, ['admin', 'staff'], true)) {
        $where[] = 'user_type = ?';
        $params[] = $roleFilter;
    }
    if ($statusFilter === 'active') {
        $where[] = 'is_active = 1';
    } elseif ($statusFilter === 'inactive') {
        $where[] = 'is_active = 0';
    }
    $sql = 'SELECT id, username, email, first_name, last_name, user_type, photo, address, gender, is_active, created_at, last_login FROM users';
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= " ORDER BY FIELD(user_type, 'admin', 'staff'), first_name ASC, last_name ASC, username ASC";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $users = $stmt->fetchAll();
} catch (PDOException $e) {
    $error = "Failed to load users.";
}
$directorySummary = ['total' => count($users), 'admin' => 0, 'staff' => 0, 'active' => 0];
foreach ($users as $directoryUser) {
    if (($directoryUser['user_type'] ?? '') === 'admin') {
        $directorySummary['admin']++;
    } elseif (($directoryUser['user_type'] ?? '') === 'staff') {
        $directorySummary['staff']++;
    }
    if (!empty($directoryUser['is_active'])) {
        $directorySummary['active']++;
    }
}
$directoryQuery = http_build_query(array_filter(['search' => $userSearch, 'role' => $roleFilter, 'status' => $statusFilter], static function ($value) {
    return $value !== '';
}));

// Get selected user details
$selected_user = null;
$selectedUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : $newlyAddedUserId;
if ($selectedUserId > 0) {
    $user_id = $selectedUserId;
    try {
        $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $selected_user = $stmt->fetch();
    } catch (PDOException $e) {
        // Error handled silently
    }
}
if (!$selected_user && !empty($users)) {
    $selected_user = $users[0];
}

$selectedUserStats = ['assigned_tasks' => 0, 'completed_tasks' => 0, 'submitted_reports' => 0];
if ($selected_user) {
    try {
        $taskStats = $conn->prepare("SELECT COUNT(*) AS assigned_tasks, COALESCE(SUM(status = 'completed'), 0) AS completed_tasks FROM daily_tasks WHERE assigned_to = ?");
        $taskStats->execute([$selected_user['id']]);
        $selectedUserStats = array_merge($selectedUserStats, $taskStats->fetch() ?: []);

        $reportTable = $conn->query("SHOW TABLES LIKE 'reports'");
        if ($reportTable->rowCount() > 0) {
            $reportStats = $conn->prepare('SELECT COUNT(*) FROM reports WHERE staff_id = ?');
            $reportStats->execute([$selected_user['id']]);
            $selectedUserStats['submitted_reports'] = (int)$reportStats->fetchColumn();
        }
    } catch (PDOException $e) {
        // Account details remain visible even if an optional activity table is unavailable.
    }
}
$notificationItems = getNotificationItems($conn, getCurrentUser());
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - EcoTrack</title>
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
        .user-info-container {
          background: white;
          border-radius: 15px;
          padding: 30px;
          box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
          margin-bottom: 30px;
          display: flex;
          gap: 30px;
          align-items: center;
        }
        .user-avatar {
          width: 150px;
          height: 150px;
          border-radius: 50%;
          background: #e0e0e0;
          display: flex;
          align-items: center;
          justify-content: center;
          font-size: 60px;
          color: #999;
          overflow: hidden;
        }
        .user-avatar img {
          width: 100%;
          height: 100%;
          object-fit: cover;
        }
        .user-details {
          flex: 1;
        }
        .user-details h2 {
          color: #333;
          margin-bottom: 10px;
        }
        .user-details p {
          color: #666;
          margin-bottom: 5px;
        }
        .info-box {
          background: #e0e0e0;
          border-radius: 10px;
          padding: 40px;
          text-align: center;
          flex: 1;
        }
        .info-box h3 {
          color: #333;
          font-size: 18px;
        }
        .action-buttons {
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
        .btn-gray {
          background: #9e9e9e;
          color: white;
        }
        .btn-gray:hover {
          background: #757575;
        }
        .user-list {
          background: white;
          border-radius: 15px;
          padding: 20px;
          box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }
        .user-list h2 {
          color: #333;
          margin-bottom: 20px;
        }
        .user-grid {
          display: grid;
          grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
          gap: 15px;
        }
        .user-card {
          background: #f5f5f5;
          border-radius: 10px;
          padding: 15px;
          cursor: pointer;
          transition: all 0.3s;
          text-align: center;
          border: 2px solid transparent;
          text-decoration: none;
          color: inherit;
        }
        .user-card:hover,
        .user-card.active {
          background: #e8f5e9;
          border-color: #8bc34a;
        }
        .user-card img {
          width: 60px;
          height: 60px;
          border-radius: 50%;
          object-fit: cover;
          margin-bottom: 10px;
        }
        .user-card .avatar-placeholder {
          width: 60px;
          height: 60px;
          border-radius: 50%;
          background: #8bc34a;
          color: white;
          display: flex;
          align-items: center;
          justify-content: center;
          font-size: 24px;
          margin: 0 auto 10px;
        }
        .user-card h4 {
          color: #333;
          font-size: 14px;
          margin-bottom: 5px;
        }
        .user-card p {
          color: #666;
          font-size: 12px;
        }
        .user-card .status {
          display: inline-block;
          padding: 3px 10px;
          border-radius: 10px;
          font-size: 11px;
          margin-top: 5px;
        }
        .user-card .status.active {
          background: #c8e6c9;
          color: #2e7d32;
        }
        .user-card .status.inactive {
          background: #ffcdd2;
          color: #c62828;
        }
        .modal-overlay {
          display: none;
          position: fixed;
          top: 0;
          left: 0;
          width: 100%;
          height: 100%;
          background: rgba(0, 0, 0, 0.5);
          z-index: 1000;
          justify-content: center;
          align-items: center;
        }
        .modal-overlay.active {
          display: flex;
        }
        /* The shared theme also uses .modal for its logout dialog. Scope this
                           rule to the account overlays so Add/Edit/Reset forms stay visible. */
        .modal-overlay .modal {
          display: block;
          position: relative;
          inset: auto;
          width: 90%;
          max-width: 500px;
          max-height: 90vh;
          padding: 30px;
          overflow-y: auto;
          border-radius: 15px;
          background: var(--bg-secondary);
          transform: none;
        }
        .modal-overlay .modal h2 {
          margin-bottom: 20px;
          color: var(--text-primary);
        }
        .form-group {
          margin-bottom: 15px;
        }
        .form-group label {
          display: block;
          margin-bottom: 5px;
          color: #666;
          font-size: 13px;
          text-transform: uppercase;
        }
        .form-group input,
        .form-group select {
          width: 100%;
          padding: 10px;
          border: 1px solid #ddd;
          border-radius: 5px;
          font-size: 14px;
        }
        .form-group input:focus,
        .form-group select:focus {
          outline: none;
          border-color: #8bc34a;
        }
        .modal-buttons {
          display: flex;
          justify-content: flex-end;
          gap: 10px;
          margin-top: 20px;
        }
        .photo-preview {
          width: 100px;
          height: 100px;
          border-radius: 50%;
          background: #f0f0f0;
          display: flex;
          align-items: center;
          justify-content: center;
          margin: 0 auto 15px;
          overflow: hidden;
        }
        .photo-preview img {
          width: 100%;
          height: 100%;
          object-fit: cover;
        }
        .directory-summary {
          display: grid;
          grid-template-columns: repeat(4, minmax(0, 1fr));
          gap: 14px;
          margin-bottom: 20px;
        }
        .directory-stat {
          padding: 16px 18px;
          background: var(--bg-secondary);
          border: 1px solid var(--border-color);
          border-radius: 12px;
          box-shadow: var(--card-shadow);
        }
        .directory-stat span {
          display: block;
          color: var(--text-secondary);
          font-size: 12px;
          font-weight: 700;
          text-transform: uppercase;
        }
        .directory-stat strong {
          display: block;
          margin-top: 6px;
          color: var(--text-primary);
          font-size: 24px;
        }
        .user-info-container {
          align-items: flex-start;
        }
        .account-header {
          display: flex;
          flex: 1;
          min-width: 0;
          gap: 24px;
          align-items: center;
        }
        .account-overview {
          min-width: 0;
          flex: 1;
        }
        .account-overview h2 {
          margin: 0 0 8px;
          color: var(--text-primary);
        }
        .account-overview .account-role {
          margin: 0;
          color: var(--text-secondary);
          font-size: 14px;
        }
        .role-badge,
        .status-badge {
          display: inline-flex;
          align-items: center;
          min-height: 26px;
          margin-right: 7px;
          padding: 4px 10px;
          border-radius: 999px;
          font-size: 12px;
          font-weight: 700;
        }
        .role-badge.admin {
          background: #e8e4fb;
          color: #5b3d9b;
        }
        .role-badge.staff {
          background: #e1f0e9;
          color: #18735d;
        }
        .status-badge.active {
          background: #dff2e3;
          color: #23733a;
        }
        .status-badge.inactive {
          background: #fde4e3;
          color: #aa3932;
        }
        .account-details-grid {
          display: grid;
          grid-template-columns: repeat(2, minmax(0, 1fr));
          gap: 11px 22px;
          margin-top: 18px;
        }
        .account-detail {
          min-width: 0;
        }
        .account-detail span {
          display: block;
          margin-bottom: 3px;
          color: var(--text-secondary);
          font-size: 11px;
          font-weight: 700;
          text-transform: uppercase;
        }
        .account-detail strong {
          display: block;
          overflow-wrap: anywhere;
          color: var(--text-primary);
          font-size: 14px;
        }
        .account-activity {
          display: grid;
          grid-template-columns: repeat(3, minmax(95px, 1fr));
          gap: 10px;
          width: min(100%, 385px);
        }
        .activity-stat {
          padding: 16px 12px;
          background: var(--eco-surface-soft, #eef6f1);
          border: 1px solid var(--border-color);
          border-radius: 10px;
          text-align: center;
        }
        .activity-stat strong {
          display: block;
          color: var(--text-primary);
          font-size: 22px;
        }
        .activity-stat span {
          display: block;
          margin-top: 4px;
          color: var(--text-secondary);
          font-size: 11px;
          line-height: 1.25;
        }
        .directory-toolbar {
          display: grid;
          grid-template-columns: minmax(220px, 2fr) repeat(2, minmax(150px, 1fr)) auto;
          gap: 12px;
          align-items: end;
          margin-bottom: 20px;
          padding: 16px;
          background: var(--eco-surface-soft, #f0f7f3);
          border: 1px solid var(--border-color);
          border-radius: 10px;
        }
        .directory-toolbar label {
          display: block;
          margin-bottom: 6px;
          color: var(--text-secondary);
          font-size: 11px;
          font-weight: 700;
          text-transform: uppercase;
        }
        .directory-toolbar input,
        .directory-toolbar select {
          width: 100%;
          min-height: 42px;
          padding: 10px 12px;
          border: 1px solid var(--input-border);
          border-radius: 7px;
          background: var(--input-bg);
          color: var(--text-primary);
        }
        .directory-filter-actions {
          display: flex;
          gap: 10px;
        }
        .directory-filter-actions .btn {
          min-height: 42px;
          justify-content: center;
        }
        .directory-filter-actions .btn-gray {
          background: #9e9e9e;
        }
        .directory-empty {
          padding: 40px 20px;
          color: var(--text-secondary);
          text-align: center;
        }
        .account-notice {
          margin-top: 12px;
          color: var(--text-secondary);
          font-size: 12px;
          line-height: 1.45;
        }
        @media (max-width: 1150px) {
          .directory-summary {
            grid-template-columns: repeat(2, minmax(0, 1fr));
          }
          .user-info-container {
            flex-direction: column;
          }
          .account-activity {
            width: 100%;
          }
        }
        @media (max-width: 760px) {
          .account-header {
            flex-direction: column;
            align-items: flex-start;
          }
          .account-details-grid {
            grid-template-columns: 1fr;
          }
          .account-activity {
            grid-template-columns: 1fr;
          }
          .directory-toolbar {
            grid-template-columns: 1fr;
          }
          .directory-filter-actions {
            width: 100%;
          }
          .directory-filter-actions .btn {
            flex: 1;
          }
        }
    </style>
    <link rel="stylesheet" href="assets/css/ecotrack-theme.css">
</head>
<body>
    <?php $active_page = $userManagementRoute;
$useLogoutModal = false;
include 'includes/sidebar.php'; ?>

    <div class="main-content">
        <div class="header">
            <h1>USER MANAGEMENT ADMIN</h1>
            <div class="header-icons">
                <?php include 'includes/notification_bell.php'; ?>
            </div>
        </div>

        <?php if (!empty($message)): ?><div class="message success"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
        <?php if (!empty($error)): ?><div class="message error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

        <div class="directory-summary">
            <article class="directory-stat"><span>Accounts shown</span><strong><?php echo (int)$directorySummary['total']; ?></strong></article>
            <article class="directory-stat"><span>Administrators</span><strong><?php echo (int)$directorySummary['admin']; ?></strong></article>
            <article class="directory-stat"><span>Staff accounts</span><strong><?php echo (int)$directorySummary['staff']; ?></strong></article>
            <article class="directory-stat"><span>Active accounts</span><strong><?php echo (int)$directorySummary['active']; ?></strong></article>
        </div>

        <div class="user-info-container">
            <?php if ($selected_user): ?>
                <div class="account-header">
                    <div class="user-avatar">
                        <?php if (!empty($selected_user['photo'])): ?>
                            <img src="<?php echo htmlspecialchars($selected_user['photo']); ?>" alt="<?php echo htmlspecialchars($selected_user['first_name'] . ' ' . $selected_user['last_name']); ?>">
                        <?php else: ?><?php echo htmlspecialchars(strtoupper(substr((string)$selected_user['first_name'], 0, 1))); ?><?php endif; ?>
                    </div>
                    <div class="account-overview">
                        <h2><?php echo htmlspecialchars(trim($selected_user['first_name'] . ' ' . $selected_user['last_name'])); ?></h2>
                        <p class="account-role"><span class="role-badge <?php echo htmlspecialchars($selected_user['user_type']); ?>"><?php echo htmlspecialchars(ucfirst($selected_user['user_type'])); ?></span><span class="status-badge <?php echo $selected_user['is_active'] ? 'active' : 'inactive'; ?>"><?php echo $selected_user['is_active'] ? 'Active' : 'Inactive'; ?></span></p>
                        <div class="account-details-grid">
                            <div class="account-detail"><span>Username</span><strong><?php echo htmlspecialchars($selected_user['username']); ?></strong></div>
                            <div class="account-detail"><span>Email address</span><strong><?php echo htmlspecialchars($selected_user['email']); ?></strong></div>
                            <div class="account-detail"><span>Gender</span><strong><?php echo htmlspecialchars($selected_user['gender'] ?: 'Not specified'); ?></strong></div>
                            <div class="account-detail"><span>Address</span><strong><?php echo htmlspecialchars($selected_user['address'] ?: 'Not specified'); ?></strong></div>
                            <div class="account-detail"><span>Account created</span><strong><?php echo !empty($selected_user['created_at']) ? htmlspecialchars(date('M j, Y', strtotime($selected_user['created_at']))) : 'Not available'; ?></strong></div>
                            <div class="account-detail"><span>Last sign-in</span><strong><?php echo !empty($selected_user['last_login']) ? htmlspecialchars(date('M j, Y g:i A', strtotime($selected_user['last_login']))) : 'No sign-in recorded'; ?></strong></div>
                        </div>
                    </div>
                </div>
                <aside class="account-activity" aria-label="Account activity summary">
                    <div class="activity-stat"><strong><?php echo (int)$selectedUserStats['assigned_tasks']; ?></strong><span>Assigned tasks</span></div>
                    <div class="activity-stat"><strong><?php echo (int)$selectedUserStats['completed_tasks']; ?></strong><span>Completed tasks</span></div>
                    <div class="activity-stat"><strong><?php echo (int)$selectedUserStats['submitted_reports']; ?></strong><span>Submitted reports</span></div>
                </aside>
            <?php else: ?>
                <div class="account-overview"><h2>No account found</h2><p class="account-role">Adjust the filters or add a new staff account.</p></div>
            <?php endif; ?>
        </div>

        <div class="action-buttons">
            <?php if ($selected_user && $selected_user['user_type'] === 'admin'): ?>
                <button type="button" class="btn btn-blue" onclick="openAddModal('admin')">&#10133; ADD ADMIN ACCOUNT</button>
            <?php else: ?>
                <button type="button" class="btn btn-green" onclick="openAddModal('staff')">&#10133; ADD STAFF ACCOUNT</button>
            <?php endif; ?>
            <?php if ($selected_user): ?>
                <button type="button" class="btn btn-blue" onclick="openModal('editModal')">&#9998; EDIT ACCOUNT</button>
                <?php if ((int)$selected_user['id'] !== $currentAdminId): ?>
                    <form method="post" action="" style="display: inline;" data-confirm-title="Change account status?" data-confirm-message="Changing account status can prevent this user from signing in." data-confirm-action="Update account">
                        <input type="hidden" name="user_id" value="<?php echo $selected_user['id']; ?>">
                        <button type="submit" name="deactivate_user" class="btn btn-orange"><?php echo $selected_user['is_active'] ? '&#128683; DEACTIVATE ACCOUNT' : '&#10004; ACTIVATE ACCOUNT'; ?></button>
                    </form>
                    <button type="button" class="btn btn-red" onclick="openModal('resetModal')">&#9851; RESET PASSWORD</button>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <div class="user-list">
            <h2>Account Directory</h2>
            <form class="directory-toolbar" method="get" action="<?php echo $userManagementRoute; ?>">
                <div><label for="search">Search account</label><input id="search" name="search" value="<?php echo htmlspecialchars($userSearch); ?>"></div>
                <div><label for="role">Role</label><select id="role" name="role"><option value="">All roles</option><option value="admin" <?php echo $roleFilter === 'admin' ? 'selected' : ''; ?>>Administrator</option><option value="staff" <?php echo $roleFilter === 'staff' ? 'selected' : ''; ?>>Staff</option></select></div>
                <div><label for="status">Status</label><select id="status" name="status"><option value="">All statuses</option><option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active</option><option value="inactive" <?php echo $statusFilter === 'inactive' ? 'selected' : ''; ?>>Inactive</option></select></div>
                <div class="directory-filter-actions"><button type="submit" class="btn btn-green">Filter</button><a href="<?php echo $userManagementRoute; ?>" class="btn btn-gray">Clear</a></div>
            </form>
            <?php if (empty($users)): ?>
                <div class="directory-empty">No accounts match the selected filters.</div>
            <?php else: ?>
                <div class="user-grid">
                    <?php foreach ($users as $directoryUser): ?>
                        <a href="?<?php echo htmlspecialchars($directoryQuery !== '' ? $directoryQuery . '&' : ''); ?>user_id=<?php echo (int)$directoryUser['id']; ?>" class="user-card <?php echo ($selected_user && $selected_user['id'] == $directoryUser['id']) ? 'active' : ''; ?>">
                        <?php if ($directoryUser['photo']): ?>
                            <img src="<?php echo htmlspecialchars($directoryUser['photo']); ?>" alt="<?php echo htmlspecialchars($directoryUser['username']); ?>">
                        <?php else: ?>
                            <div class="avatar-placeholder"><?php echo htmlspecialchars(strtoupper(substr($directoryUser['first_name'], 0, 1))); ?></div>
                        <?php endif; ?>
                        <h4><?php echo htmlspecialchars($directoryUser['first_name'] . ' ' . $directoryUser['last_name']); ?></h4>
                        <p>@<?php echo htmlspecialchars($directoryUser['username']); ?></p>
                        <p><?php echo htmlspecialchars(ucfirst($directoryUser['user_type'])); ?></p>
                        <span class="status <?php echo $directoryUser['is_active'] ? 'active' : 'inactive'; ?>"><?php echo $directoryUser['is_active'] ? 'Active' : 'Inactive'; ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Add User Modal -->
    <div class="modal-overlay" id="addModal">
        <div class="modal">
            <h2 id="addAccountTitle">ADD STAFF ACCOUNT</h2>
            <p class="account-notice" id="addAccountNotice">Create a new staff account.</p>
            <form method="post" action="" enctype="multipart/form-data">
                <div class="photo-preview" id="addPhotoPreview"><span style="font-size: 40px;">&#128100;</span></div>
                <div class="form-group"><label>Photo</label><input type="file" name="photo" accept="image/*" onchange="previewPhoto(this, 'addPhotoPreview')"></div>
                <div class="form-group"><label for="add_user_type">User Type</label><select id="add_user_type" name="user_type" required onchange="updateAddAccountLabels()"><option value="staff" selected>Staff</option><option value="admin">Admin</option></select></div>
                <div class="form-group"><label>Username</label><input type="text" name="username" required></div>
                <div class="form-group"><label>Email</label><input type="email" name="email" required></div>
                <div class="form-group"><label>First Name</label><input type="text" name="first_name" required></div>
                <div class="form-group"><label>Last Name</label><input type="text" name="last_name" required></div>
                <div class="form-group"><label>Gender</label><select name="gender"><option value="">Select Gender</option><option value="Male">Male</option><option value="Female">Female</option><option value="Other">Other</option></select></div>
                <div class="form-group"><label>Address</label><textarea name="address" rows="3" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px; font-size: 14px;"></textarea></div>
                <div class="form-group"><label>Password</label><input type="password" name="password" required minlength="6"></div>
                <div class="modal-buttons"><button type="button" class="btn btn-gray" onclick="closeModal('addModal')">CANCEL</button><button type="submit" name="add_user" id="addAccountSubmit" class="btn btn-green">ADD STAFF ACCOUNT</button></div>
            </form>
        </div>
    </div>

    <?php if ($selected_user): ?>
    <!-- Edit User Modal -->
    <div class="modal-overlay" id="editModal">
        <div class="modal">
            <h2>EDIT USER</h2>
            <form method="post" action="" enctype="multipart/form-data">
                <input type="hidden" name="user_id" value="<?php echo $selected_user['id']; ?>">
                <div class="photo-preview" id="editPhotoPreview"><?php if ($selected_user['photo']): ?><img src="<?php echo htmlspecialchars($selected_user['photo']); ?>" alt="Current Photo"><?php else: ?><span style="font-size: 40px;">&#128100;</span><?php endif; ?></div>
                <div class="form-group"><label>Photo</label><input type="file" name="photo" accept="image/*" onchange="previewPhoto(this, 'editPhotoPreview')"></div>
                <div class="form-group"><label>Username</label><input type="text" name="username" value="<?php echo htmlspecialchars($selected_user['username']); ?>" required maxlength="50" autocomplete="username"></div>
                <div class="form-group"><label>Email</label><input type="email" name="email" value="<?php echo htmlspecialchars($selected_user['email']); ?>" required></div>
                <div class="form-group"><label>First Name</label><input type="text" name="first_name" value="<?php echo htmlspecialchars($selected_user['first_name']); ?>" required></div>
                <div class="form-group"><label>Last Name</label><input type="text" name="last_name" value="<?php echo htmlspecialchars($selected_user['last_name']); ?>" required></div>
                <div class="form-group"><label>Gender</label><select name="gender"><option value="" <?php echo empty($selected_user['gender']) ? 'selected' : ''; ?>>Select Gender</option><option value="Male" <?php echo $selected_user['gender'] == 'Male' ? 'selected' : ''; ?>>Male</option><option value="Female" <?php echo $selected_user['gender'] == 'Female' ? 'selected' : ''; ?>>Female</option><option value="Other" <?php echo $selected_user['gender'] == 'Other' ? 'selected' : ''; ?>>Other</option></select></div>
                <div class="form-group"><label>Address</label><textarea name="address" rows="3" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px; font-size: 14px;"><?php echo htmlspecialchars($selected_user['address'] ?? ''); ?></textarea></div>
                <div class="form-group"><label>User Type</label><select name="user_type" required><option value="staff" <?php echo $selected_user['user_type'] == 'staff' ? 'selected' : ''; ?>>Staff</option><option value="admin" <?php echo $selected_user['user_type'] == 'admin' ? 'selected' : ''; ?>>Admin</option></select></div>
                <div class="modal-buttons"><button type="button" class="btn btn-gray" onclick="closeModal('editModal')">CANCEL</button><button type="submit" name="edit_user" class="btn btn-blue">SAVE CHANGES</button></div>
            </form>
        </div>
    </div>

    <!-- Reset Password Modal -->
    <div class="modal-overlay" id="resetModal">
        <div class="modal">
            <h2>RESET PASSWORD</h2>
            <form method="post" action="">
                <input type="hidden" name="user_id" value="<?php echo $selected_user['id']; ?>">
                <div class="form-group"><label>New Password</label><input type="password" name="new_password" required minlength="6"></div>
                <div class="modal-buttons"><button type="button" class="btn btn-gray" onclick="closeModal('resetModal')">CANCEL</button><button type="submit" name="reset_password" class="btn btn-red">RESET PASSWORD</button></div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <script>
        function updateAddAccountLabels() {
          const role = document.getElementById("add_user_type").value;
          const roleName = role === "admin" ? "ADMIN" : "STAFF";
          document.getElementById("addAccountTitle").textContent = "ADD " + roleName + " ACCOUNT";
          document.getElementById("addAccountNotice").textContent = "Create a new " + roleName.toLowerCase() + " account.";
          document.getElementById("addAccountSubmit").textContent = "ADD " + roleName + " ACCOUNT";
        }
        function openAddModal(role) {
          document.getElementById("add_user_type").value = role === "admin" ? "admin" : "staff";
          updateAddAccountLabels();
          openModal("addModal");
        }
        function openModal(modalId) {
          const overlay = document.getElementById(modalId);
          if (!overlay) return;
          overlay.classList.add("active");
          const firstField = overlay.querySelector('input:not([type="hidden"]), select, textarea, button');
          if (firstField) window.setTimeout(() => firstField.focus(), 0);
        }
        function closeModal(modalId) {
          const overlay = document.getElementById(modalId);
          if (overlay) overlay.classList.remove("active");
        }
        function previewPhoto(input, previewId) {
          if (input.files && input.files[0]) {
            const reader = new FileReader();
            reader.onload = function (e) {
              document.getElementById(previewId).innerHTML = '<img src="' + e.target.result + '">';
            };
            reader.readAsDataURL(input.files[0]);
          }
        }
        document.querySelectorAll(".modal-overlay").forEach((overlay) => {
          overlay.addEventListener("click", function (e) {
            if (e.target === this) {
              this.classList.remove("active");
            }
          });
        });
        document.addEventListener("keydown", function (event) {
          if (event.key !== "Escape") return;
          document.querySelectorAll(".modal-overlay.active").forEach((overlay) => overlay.classList.remove("active"));
        });
    </script>
</body>
</html>

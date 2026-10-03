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

$message = '';
$error = '';

// Handle task completion
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['complete_task'])) {
    $task_id = intval($_POST['task_id'] ?? 0);
    try {
        $taskLookup = $conn->prepare('SELECT title, created_by FROM daily_tasks WHERE id = ? AND assigned_to = ? AND status = \'pending\' LIMIT 1');
        $taskLookup->execute([$task_id, $user['id']]);
        $task = $taskLookup->fetch();

        $stmt = $conn->prepare("UPDATE daily_tasks SET status = 'completed', completed_at = NOW(), completed_by = ? WHERE id = ? AND assigned_to = ? AND status = 'pending'");
        $stmt->execute([$user['id'], $task_id, $user['id']]);
        if ($stmt->rowCount() === 1) {
            $staffName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
            if ($staffName === '') {
                $staffName = $user['username'] ?? 'A staff member';
            }
            if (!empty($task['created_by'])) {
                createUserNotification(
                    $conn,
                    $task['created_by'],
                    'Task completed',
                    $staffName . ' completed: ' . ($task['title'] ?? 'an assigned task'),
                    'admin_dashboard.php',
                    'task_completed',
                    $user['id']
                );
            }
            $taskTitle = trim((string)($task['title'] ?? 'an assigned task'));
            logActivity('Completed daily task: ' . substr($taskTitle, 0, 110), 'Daily Tasks', 'Success', $conn);
            $message = "Task marked as completed!";
        } else {
            $error = "This task is no longer available to complete.";
            logActivity('Completed daily task', 'Daily Tasks', 'Failed', $conn);
        }
    } catch (PDOException $e) {
        $error = "Failed to update task.";
        logActivity('Completed daily task', 'Daily Tasks', 'Failed', $conn);
    }
}

// Show all tasks assigned to this staff member. Pending tasks from earlier
// dates remain visible until the staff member completes them.
$tasks = [];
$completed_count = 0;
$pending_count = 0;
$cancelled_count = 0;
try {
    $stmt = $conn->prepare("SELECT * FROM daily_tasks WHERE assigned_to = ? ORDER BY FIELD(status, 'pending', 'completed', 'cancelled'), FIELD(priority, 'high', 'normal', 'low'), task_date DESC, created_at DESC");
    $stmt->execute([$user['id']]);
    $tasks = $stmt->fetchAll();

    foreach ($tasks as $task) {
        if ($task['status'] == 'completed') {
            $completed_count++;
        } elseif ($task['status'] == 'cancelled') {
            $cancelled_count++;
        } else {
            $pending_count++;
        }
    }
} catch (PDOException $e) {
    $error = "Failed to load tasks.";
}
$notificationItems = getNotificationItems($conn, $user);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daily Task - EcoTrack Staff</title>
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

        /* Stats Cards */
        .stats-grid {
          display: grid;
          grid-template-columns: repeat(4, 1fr);
          gap: 20px;
          margin-bottom: 30px;
        }
        .stat-card {
          background: white;
          border-radius: 15px;
          padding: 25px;
          box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
          text-align: center;
        }
        .stat-value {
          font-size: 32px;
          font-weight: bold;
          color: #8bc34a;
          margin-bottom: 5px;
        }
        .stat-label {
          font-size: 13px;
          color: #666;
          text-transform: uppercase;
        }

        /* Task Cards */
        .task-container {
          background: white;
          border-radius: 15px;
          padding: 25px;
          box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }
        .task-container h2 {
          font-size: 18px;
          font-weight: 600;
          color: #333;
          margin-bottom: 20px;
          border-bottom: 2px solid #8bc34a;
          padding-bottom: 10px;
          text-transform: uppercase;
        }

        .task-list {
          display: flex;
          flex-direction: column;
          gap: 15px;
        }
        .task-item {
          background: #f5f5f5;
          border-radius: 10px;
          padding: 20px;
          border-left: 4px solid #8bc34a;
        }
        .task-item.completed {
          border-left-color: #4caf50;
          opacity: 0.8;
        }
        .task-item.high-priority {
          border-left-color: #f44336;
        }

        .task-header {
          display: flex;
          justify-content: space-between;
          align-items: flex-start;
          margin-bottom: 10px;
        }
        .task-title {
          font-size: 16px;
          font-weight: 600;
          color: #333;
        }
        .task-status {
          padding: 5px 15px;
          border-radius: 15px;
          font-size: 12px;
          font-weight: 600;
          text-transform: uppercase;
        }
        .task-status.pending {
          background: #fff3e0;
          color: #ff9800;
        }
        .task-status.completed {
          background: #e8f5e9;
          color: #4caf50;
        }

        .task-description {
          color: #666;
          font-size: 14px;
          margin-bottom: 15px;
        }
        .task-meta {
          display: flex;
          gap: 20px;
          font-size: 12px;
          color: #999;
          margin-bottom: 15px;
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
        .btn-gray {
          background: #9e9e9e;
          color: white;
        }
        .btn-gray:hover {
          background: #757575;
        }
        .task-complete-control {
          display: inline-flex;
          align-items: center;
          gap: 9px;
          padding: 10px 16px;
          border: 0;
          color: var(--eco-on-primary, #fff);
          background: var(--eco-primary-action, #16735f);
          border-radius: 20px;
          cursor: pointer;
          font-size: 12px;
          font-weight: 700;
          text-transform: uppercase;
        }
        .task-complete-control:hover {
          background: var(--eco-primary-action-hover, #0b4f43);
        }
        .task-complete-status {
          display: inline-flex;
          align-items: center;
          gap: 9px;
          padding: 10px 16px;
          border: 1px solid var(--eco-border, #c8e6c9);
          border-radius: 20px;
          background: var(--eco-primary-soft, #e8f5e9);
          color: var(--eco-primary-strong, #0b4f43);
          font-size: 12px;
          font-weight: 700;
          text-transform: uppercase;
        }
        .task-complete-status--cancelled {
          border-color: var(--eco-danger-text, #9a302b);
          background: var(--eco-danger-surface, #fcedeb);
          color: var(--eco-danger-text, #9a302b);
        }
        .task-status.cancelled {
          background: var(--eco-danger-surface, #fcedeb);
          color: var(--eco-danger-text, #9a302b);
        }
        .task-complete-control input {
          width: 16px;
          height: 16px;
          accent-color: white;
          cursor: pointer;
        }

        .empty-state {
          text-align: center;
          padding: 40px;
          color: #999;
        }
        .empty-state-icon {
          font-size: 48px;
          margin-bottom: 15px;
        }
    </style>
    <link rel="stylesheet" href="assets/css/ecotrack-theme.css?v=<?php echo filemtime(__DIR__ . '/assets/css/ecotrack-theme.css'); ?>">
</head>
<body class="staff-page">
    <?php $active_page = 'staff_daily_tasks.php';
$useLogoutModal = true;
include 'includes/sidebar.php'; ?>

    <div class="main-content">
        <div class="header">
            <div>
                <h1>Daily Tasks</h1>
                <p class="staff-subtitle">Track today's assigned work and mark items complete when finished.</p>
            </div>
            <div class="header-icons">
                <?php include 'includes/notification_bell.php'; ?>
                <a href="staff_home.php" title="Staff home">&#8962;</a>
            </div>
        </div>

        <?php if (!empty($message)): ?><div class="message success"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
        <?php if (!empty($error)): ?><div class="message error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo count($tasks); ?></div>
                <div class="stat-label">Total tasks</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: #ff9800;"><?php echo $pending_count; ?></div>
                <div class="stat-label">Pending</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: #4caf50;"><?php echo $completed_count; ?></div>
                <div class="stat-label">Completed</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: #c62828;"><?php echo $cancelled_count; ?></div>
                <div class="stat-label">Cancelled</div>
            </div>
        </div>

        <!-- Task List -->
        <div class="task-container">
            <h2>Assigned Tasks</h2>

            <?php if (empty($tasks)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">&#128466;</div>
                    <p>No tasks assigned yet.</p>
                </div>
            <?php else: ?>
                <div class="task-list">
                    <?php foreach ($tasks as $task): ?>
                        <div class="task-item <?php echo $task['status']; ?> <?php echo $task['priority'] == 'high' ? 'high-priority' : ''; ?>">
                            <div class="task-header">
                                <div class="task-title"><?php echo htmlspecialchars($task['title']); ?></div>
                                <span class="task-status <?php echo $task['status']; ?>"><?php echo ucfirst($task['status']); ?></span>
                            </div>
                            <div class="task-description"><?php echo htmlspecialchars($task['description']); ?></div>
                            <div class="task-meta">
                                <span>&#128205; Zone: <?php echo htmlspecialchars($task['zone'] ?? 'N/A'); ?></span>
                                <span>&#128337; <?php echo htmlspecialchars($task['estimated_time'] ?? 'N/A'); ?></span>
                                <span>&#9878; Priority: <?php echo ucfirst($task['priority']); ?></span>
                            </div>
                            <?php if ($task['status'] == 'pending'): ?>
                                <form method="post" action="" class="task-complete-form" style="display: inline;" data-confirm-title="Mark task complete?" data-confirm-message="Mark “<?php echo htmlspecialchars($task['title'], ENT_QUOTES, 'UTF-8'); ?>” as completed? You can no longer complete it again." data-confirm-action="Complete task">
                                    <input type="hidden" name="task_id" value="<?php echo $task['id']; ?>">
                                    <input type="hidden" name="complete_task" value="1">
                                    <button type="submit" class="task-complete-control">&#10003; Mark task complete</button>
                                </form>
                            <?php elseif ($task['status'] == 'completed'): ?>
                                <span class="task-complete-status" role="status">&#10004; Completed</span>
                            <?php else: ?>
                                <span class="task-complete-status task-complete-status--cancelled" role="status">&#10005; Cancelled</span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php include 'includes/logout_confirmation_modal.php'; ?>
</body>
</html>

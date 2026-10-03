<?php
require_once 'config.php';
requireUserType('admin');
header('Location: admin_system_settings.php?tab=activity_logs');
exit();

$conn = getDBConnection();
ensureActivityLogsTable($conn);
updateLastActivity();

$search = trim($_GET['search'] ?? '');
$module = trim($_GET['module'] ?? '');
$date = trim($_GET['date'] ?? '');
$where = [];
$params = [];

if ($search !== '') {
    $where[] = '(user_name LIKE ? OR action LIKE ? OR module LIKE ?)';
    $term = '%' . $search . '%';
    array_push($params, $term, $term, $term);
}
if ($module !== '') {
    $where[] = 'module = ?';
    $params[] = $module;
}
if ($date !== '') {
    $where[] = 'DATE(created_at) = ?';
    $params[] = $date;
}

$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$logs = [];
$modules = [];
$error = '';
try {
    $moduleStmt = $conn->query('SELECT DISTINCT module FROM activity_logs ORDER BY module');
    $modules = $moduleStmt->fetchAll(PDO::FETCH_COLUMN);
    $stmt = $conn->prepare('SELECT user_name, user_role, action, module, created_at, status FROM activity_logs' . $whereSql . ' ORDER BY created_at DESC, id DESC LIMIT 500');
    $stmt->execute($params);
    $logs = $stmt->fetchAll();
} catch (PDOException $e) {
    $error = 'Activity logs could not be loaded.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activity Logs - EcoTrack</title>
    <?php include 'includes/theme_head.php'; ?>
    <link rel="stylesheet" href="assets/css/ecotrack-theme.css?v=<?php echo filemtime(__DIR__ . '/assets/css/ecotrack-theme.css'); ?>">
    <style>
        * {
          box-sizing: border-box;
        }
        body {
          margin: 0;
          font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
          background: var(--bg-primary, #f5f5f5);
          color: var(--text-primary, #333);
        }
        .main-content {
          margin-left: 280px;
          min-height: 100vh;
          padding: 28px 26px;
        }
        .page-header {
          background: var(--bg-secondary);
          border: 1px solid var(--border-color);
          border-radius: 8px;
          box-shadow: var(--card-shadow);
          padding: 20px 24px;
          display: flex;
          align-items: center;
          justify-content: space-between;
          gap: 16px;
          margin-bottom: 28px;
        }
        h1 {
          margin: 0;
          font-size: 24px;
          letter-spacing: 0.4px;
        }
        .subtitle {
          margin: 6px 0 0;
          color: var(--text-secondary);
          font-size: 14px;
        }
        .back-link {
          color: var(--eco-primary, #2d7a4b);
          text-decoration: none;
          font-weight: 700;
          white-space: nowrap;
          font-size: 13px;
        }
        .filter-card,
        .table-card {
          background: var(--bg-secondary, #fff);
          border: 1px solid var(--border-color);
          border-radius: 8px;
          box-shadow: var(--card-shadow);
        }
        .filter-card {
          padding: 18px 28px;
          margin-bottom: 20px;
        }
        .filter-grid {
          display: grid;
          grid-template-columns: 2fr 1fr 1fr auto;
          gap: 12px;
          align-items: end;
        }
        label {
          display: block;
          margin-bottom: 6px;
          font-size: 12px;
          color: #637168;
          font-weight: 700;
          text-transform: uppercase;
        }
        input,
        select {
          width: 100%;
          height: 42px;
          padding: 0 12px;
          border: 1px solid #d9e3dc;
          border-radius: 8px;
          background: #fff;
          color: #34423a;
        }
        .btn {
          height: 42px;
          padding: 0 18px;
          border: 0;
          border-radius: 8px;
          background: #2f7d4d;
          color: #fff;
          font-weight: 700;
          cursor: pointer;
        }
        .clear {
          display: inline-flex;
          align-items: center;
          justify-content: center;
          height: 42px;
          margin-left: 8px;
          color: #52665a;
          text-decoration: none;
          font-size: 14px;
        }
        .table-card {
          overflow: hidden;
        }
        .table-note {
          padding: 16px 20px;
          color: var(--text-secondary);
          font-size: 13px;
          border-bottom: 1px solid var(--border-color);
        }
        .table-wrap {
          overflow-x: auto;
        }
        table {
          width: 100%;
          border-collapse: collapse;
          min-width: 820px;
        }
        th,
        td {
          padding: 15px 18px;
          text-align: left;
          border-bottom: 1px solid var(--border-color);
          font-size: 14px;
        }
        th {
          background: var(--eco-primary-soft);
          color: var(--eco-text);
          font-size: 12px;
          text-transform: uppercase;
          letter-spacing: 0.3px;
        }
        tr:last-child td {
          border-bottom: 0;
        }
        .role,
        .status {
          display: inline-block;
          padding: 4px 9px;
          border-radius: 20px;
          font-size: 12px;
          font-weight: 700;
        }
        .role {
          background: #e9f3eb;
          color: #36734a;
        }
        .success {
          background: #e5f5e9;
          color: #23813d;
        }
        .failed {
          background: #fdeaea;
          color: #b03939;
        }
        .empty,
        .error {
          padding: 35px 20px;
          text-align: center;
          color: #69776f;
        }
        .error {
          color: #a03434;
        }
        @media (max-width: 800px) {
          .main-content {
            margin-left: 0;
            padding: 20px;
          }
          .sidebar {
            display: none;
          }
          .filter-grid {
            grid-template-columns: 1fr;
          }
          .clear {
            margin: 10px 0 0;
          }
          .page-header {
            align-items: flex-start;
            flex-direction: column;
          }
        }
    </style>
</head>
<body>
    <?php $active_page = 'activity_logs.php';
$useLogoutModal = true;
include 'includes/sidebar.php'; ?>
    <main class="main-content">
        <div class="page-header"><div><h1>ACTIVITY LOGS</h1><p class="subtitle">Review important system activity. Entries are read-only and cannot be changed here.</p></div><a class="back-link" href="system_settings.php">← Settings</a></div>
        <form class="filter-card" method="get" id="activityFilterForm">
            <div class="filter-grid">
                <div><label for="search">Search user</label><input id="search" name="search" value="<?php echo htmlspecialchars($search); ?>"></div>
                <div><label for="module">Module</label><select id="module" name="module"><option value="">All modules</option><?php foreach ($modules as $item): ?><option value="<?php echo htmlspecialchars($item); ?>" <?php echo $module === $item ? 'selected' : ''; ?>><?php echo htmlspecialchars($item); ?></option><?php endforeach; ?></select></div>
                <div><label for="date">Date</label><input id="date" type="date" name="date" value="<?php echo htmlspecialchars($date); ?>"></div>
                <div><button class="btn" type="submit">Filter</button><a class="clear" href="activity_logs.php">Clear</a></div>
            </div>
        </form>
        <section class="table-card">
            <div class="table-note">Showing up to the 500 most recent matching activities.</div>
            <?php if ($error): ?><div class="error"><?php echo htmlspecialchars($error); ?></div>
            <?php elseif (!$logs): ?><div class="empty">No activity logs match the selected filters.</div>
            <?php else: ?><div class="table-wrap"><table><thead><tr><th>User</th><th>Role</th><th>Action</th><th>Module</th><th>Date &amp; Time</th><th>Status</th></tr></thead><tbody>
                <?php foreach ($logs as $log): ?><tr data-log-date="<?php echo htmlspecialchars(date('Y-m-d', strtotime($log['created_at']))); ?>"><td><?php echo htmlspecialchars($log['user_name']); ?></td><td><span class="role"><?php echo htmlspecialchars(ucfirst($log['user_role'])); ?></span></td><td><?php echo htmlspecialchars($log['action']); ?></td><td><?php echo htmlspecialchars($log['module']); ?></td><td><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($log['created_at']))); ?></td><td><span class="status <?php echo $log['status'] === 'Success' ? 'success' : 'failed'; ?>"><?php echo htmlspecialchars($log['status']); ?></span></td></tr><?php endforeach; ?>
            </tbody></table></div><?php endif; ?>
        </section>
    </main>
    <?php include 'includes/logout_confirmation_modal.php'; ?>
    <script>
        (function () {
          const form = document.getElementById("activityFilterForm");
          const search = document.getElementById("search");
          const module = document.getElementById("module");
          const date = document.getElementById("date");
          const rows = Array.from(document.querySelectorAll(".table-wrap tbody tr"));

          // Search responds immediately within the visible results; Filter or
          // Enter also runs the database search for all stored log entries.
          function filterVisibleRows() {
            const term = search.value.trim().toLowerCase();
            const selectedModule = module.value.toLowerCase();
            const selectedDate = date.value;
            rows.forEach(function (row) {
              const matchesText = !term || row.textContent.toLowerCase().includes(term);
              const matchesModule = !selectedModule || row.children[3].textContent.trim().toLowerCase() === selectedModule;
              const matchesDate = !selectedDate || row.dataset.logDate === selectedDate;
              row.style.display = matchesText && matchesModule && matchesDate ? "" : "none";
            });
          }

          if (search) search.addEventListener("input", filterVisibleRows);
          if (module)
            module.addEventListener("change", function () {
              filterVisibleRows();
              form.submit();
            });
          if (date)
            date.addEventListener("change", function () {
              filterVisibleRows();
              form.submit();
            });
        })();
    </script>
</body>
</html>

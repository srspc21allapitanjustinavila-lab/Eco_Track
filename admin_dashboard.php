<?php
require_once 'config.php';
require_once 'includes/waste_analytics.php';
require_once 'includes/attendance_management.php';
require_once 'includes/weekly_waste_trend.php';
requireUserType('admin');

if (isSessionTimeout()) {
    logoutUser();
    header("Location: login.php?timeout=1");
    exit();
}
$_SESSION['last_activity'] = time();

$user = getCurrentUser();
$conn = getDBConnection();
ensureWasteFormatColumns($conn);
ensureWasteAnalyticsColumns($conn);
ensureWeeklyWasteTrendColumns($conn);
ensureNotificationsTable($conn);
ensureDailyTaskBatching($conn);
ensureCollectorAttendanceTable($conn);
$wasteDataState = getWasteDataVersion($conn);
$adminSettings = getUserSettings($conn, $user['id']);

$message = '';
$error = '';
$activeWastePeriods = ['periods' => [], 'years' => [], 'months_by_year' => []];
$visualizationYears = [];
try {
    $activeWastePeriods = getActiveWasteCollectionPeriods($conn);
    $visualizationYears = $activeWastePeriods['years'];
} catch (PDOException $e) {
    $error = 'Year choices could not load yet. Please refresh the page.';
}
$monthlyPeriod = resolveActiveWasteCollectionPeriod(
    $_GET['monthly_period'] ?? '',
    $_GET['monthly_year'] ?? '',
    $activeWastePeriods
);
$monthlyYear = $monthlyPeriod !== '' ? substr($monthlyPeriod, 0, 4) : '';
$visualizationYearFilters = [
    'total' => normalizeWasteCollectionYear($_GET['total_year'] ?? ''),
    'monthly' => $monthlyYear,
    'diversion' => normalizeWasteCollectionYear($_GET['diversion_year'] ?? ''),
    'highest' => normalizeWasteCollectionYear($_GET['highest_year'] ?? ''),
    'records' => normalizeWasteCollectionYear($_GET['records_year'] ?? ''),
    'group' => normalizeWasteCollectionYear($_GET['group_year'] ?? ''),
    'area' => normalizeWasteCollectionYear($_GET['area_year'] ?? ''),
    'category' => normalizeWasteCollectionYear($_GET['category_year'] ?? ''),
];
foreach ($visualizationYearFilters as $filterName => $requestedYear) {
    $visualizationYearFilters[$filterName] = resolveImportedWasteCollectionYear($requestedYear, $visualizationYears);
}
$areaMonthFilter = normalizeWasteCollectionMonth($_GET['area_month'] ?? '');
$streetPeriodProvided = is_string($_GET['street_period'] ?? null);
$streetFilterMode = $streetPeriodProvided ? strtolower(trim($_GET['street_period'])) : 'all';
if (!in_array($streetFilterMode, ['all', 'month', 'year', 'range', 'custom'], true)) $streetFilterMode = 'all';
$streetMonthSelection = is_string($_GET['street_month'] ?? null) ? trim($_GET['street_month']) : '';
$streetYearSelection = is_string($_GET['street_year'] ?? null) ? trim($_GET['street_year']) : '';
$streetRangeStartMonth = normalizeWasteStreetMonthValue($_GET['street_range_start_month'] ?? '');
$streetRangeEndMonth = normalizeWasteStreetMonthValue($_GET['street_range_end_month'] ?? '');
$streetCustomStart = is_string($_GET['street_custom_start'] ?? null) ? trim($_GET['street_custom_start']) : '';
$streetCustomEnd = is_string($_GET['street_custom_end'] ?? null) ? trim($_GET['street_custom_end']) : '';
$streetStart = is_string($_GET['street_start'] ?? null) ? $_GET['street_start'] : '';
$streetEnd = is_string($_GET['street_end'] ?? null) ? $_GET['street_end'] : '';
if (!$streetPeriodProvided && ($streetStart !== '' || $streetEnd !== '')) $streetFilterMode = 'range';
if ($streetFilterMode === 'month' && preg_match('/^(19|20)\d{2}-(0[1-9]|1[0-2])$/', $streetMonthSelection)) {
    $streetStart = $streetMonthSelection . '-01';
    $streetEnd = (new DateTimeImmutable($streetStart))->modify('last day of this month')->format('Y-m-d');
} elseif ($streetFilterMode === 'year' && preg_match('/^(19|20)\d{2}$/', $streetYearSelection)) {
    $streetStart = $streetYearSelection . '-01-01';
    $streetEnd = $streetYearSelection . '-12-31';
} elseif ($streetFilterMode === 'range') {
    // Legacy date-bound URLs remain valid, but ranges now always cover full months.
    if ($streetRangeStartMonth === '' && is_string($streetStart) && preg_match('/^(19|20)\d{2}-(0[1-9]|1[0-2])-\d{2}$/', $streetStart)) {
        $streetRangeStartMonth = substr($streetStart, 0, 7);
    }
    if ($streetRangeEndMonth === '' && is_string($streetEnd) && preg_match('/^(19|20)\d{2}-(0[1-9]|1[0-2])-\d{2}$/', $streetEnd)) {
        $streetRangeEndMonth = substr($streetEnd, 0, 7);
    }
    $streetMonthRange = resolveWasteStreetMonthRange($streetRangeStartMonth, $streetRangeEndMonth);
    $streetRangeStartMonth = $streetMonthRange['start_month'];
    $streetRangeEndMonth = $streetMonthRange['end_month'];
    $streetStart = $streetMonthRange['start_date'];
    $streetEnd = $streetMonthRange['end_date'];
} elseif ($streetFilterMode === 'custom') {
    $streetStart = $streetCustomStart;
    $streetEnd = $streetCustomEnd;
} elseif ($streetFilterMode === 'all') {
    $streetStart = '';
    $streetEnd = '';
}
$streetFilterInput = [
    'phase' => is_string($_GET['street_phase'] ?? null) ? trim($_GET['street_phase']) : '',
    'category' => is_string($_GET['street_category'] ?? null) ? strtolower(trim($_GET['street_category'])) : 'all',
    'start_date' => $streetStart,
    'end_date' => $streetEnd,
];
function renderDashboardVisualizationYearControl($controlName, $selectedYear, $yearOptions, $allFilters, $label)
{
    ?>
    <form method="get" action="admin_dashboard.php" class="visualization-year-control visualization-filter-control">
        <?php foreach ($allFilters as $filterName => $filterValue): ?>
            <?php if ($filterName !== $controlName && $filterValue !== ''): ?>
                <input type="hidden" name="<?php echo htmlspecialchars($filterName . '_year'); ?>" value="<?php echo htmlspecialchars($filterValue); ?>">
            <?php endif; ?>
        <?php endforeach; ?>
        <?php foreach (['trend_location', 'trend_view', 'trend_year'] as $trendField): ?>
            <?php if (isset($_GET[$trendField]) && !is_array($_GET[$trendField])): ?>
                <input type="hidden" name="<?php echo htmlspecialchars($trendField); ?>" value="<?php echo htmlspecialchars((string)$_GET[$trendField]); ?>">
            <?php endif; ?>
        <?php endforeach; ?>
        <?php if (isset($_GET['area_month']) && $controlName !== 'area' && !is_array($_GET['area_month'])): ?>
            <input type="hidden" name="area_month" value="<?php echo htmlspecialchars((string)$_GET['area_month']); ?>">
        <?php endif; ?>
        <?php if (isset($_GET['monthly_period']) && $controlName !== 'monthly' && !is_array($_GET['monthly_period'])): ?>
            <input type="hidden" name="monthly_period" value="<?php echo htmlspecialchars((string)$_GET['monthly_period']); ?>">
        <?php endif; ?>
        <label for="<?php echo htmlspecialchars($controlName); ?>-year" class="sr-only"><?php echo htmlspecialchars($label); ?> year</label>
        <select id="<?php echo htmlspecialchars($controlName); ?>-year" name="<?php echo htmlspecialchars($controlName); ?>_year" onchange="this.form.requestSubmit()" title="Filter <?php echo htmlspecialchars($label); ?> by year">
            <option value="">All years</option>
            <?php foreach ($yearOptions as $yearOption): ?>
                <option value="<?php echo htmlspecialchars($yearOption); ?>" <?php echo $selectedYear === $yearOption ? 'selected' : ''; ?>><?php echo htmlspecialchars($yearOption); ?></option>
            <?php endforeach; ?>
        </select>
    </form>
    <?php
}

function renderDashboardMonthlyPeriodControl($selectedPeriod, array $yearOptions, array $monthsByYear, array $allFilters)
{
    $calendarYears = json_encode($yearOptions, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $calendarMonths = json_encode($monthsByYear, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    ?>
    <form method="get" action="admin_dashboard.php" class="visualization-filter-control monthly-period-control">
        <?php foreach ($allFilters as $filterName => $filterValue): ?>
            <?php if ($filterName !== 'monthly' && $filterValue !== ''): ?>
                <input type="hidden" name="<?php echo htmlspecialchars($filterName . '_year'); ?>" value="<?php echo htmlspecialchars($filterValue); ?>">
            <?php endif; ?>
        <?php endforeach; ?>
        <?php foreach (['trend_location', 'trend_view', 'trend_year'] as $trendField): ?>
            <?php if (isset($_GET[$trendField]) && !is_array($_GET[$trendField])): ?>
                <input type="hidden" name="<?php echo htmlspecialchars($trendField); ?>" value="<?php echo htmlspecialchars((string)$_GET[$trendField]); ?>">
            <?php endif; ?>
        <?php endforeach; ?>
        <?php if (isset($_GET['area_month']) && !is_array($_GET['area_month'])): ?>
            <input type="hidden" name="area_month" value="<?php echo htmlspecialchars((string)$_GET['area_month']); ?>">
        <?php endif; ?>
        <label for="monthly-period" class="sr-only">Monthly Waste month</label>
        <input id="monthly-period" type="month" name="monthly_period" data-year-navigable-picker="month" data-year-picker-auto-submit="true" data-calendar-years='<?php echo htmlspecialchars($calendarYears, ENT_QUOTES, 'UTF-8'); ?>' data-calendar-months='<?php echo htmlspecialchars($calendarMonths, ENT_QUOTES, 'UTF-8'); ?>' value="<?php echo htmlspecialchars($selectedPeriod); ?>" <?php echo empty($yearOptions) ? 'disabled' : ''; ?>>
    </form>
    <?php
}

function renderDashboardAreaPeriodControl($selectedYear, $selectedMonth, $yearOptions, $allFilters)
{
    $months = [];
    for ($month = 1; $month <= 12; $month++) {
        $months[$month] = DateTimeImmutable::createFromFormat('!m', sprintf('%02d', $month))->format('F');
    }
    ?>
    <form method="get" action="admin_dashboard.php" class="visualization-year-control visualization-filter-control area-period-control">
        <?php foreach ($allFilters as $filterName => $filterValue): ?>
            <?php if ($filterName !== 'area' && $filterValue !== ''): ?><input type="hidden" name="<?php echo htmlspecialchars($filterName . '_year'); ?>" value="<?php echo htmlspecialchars($filterValue); ?>"><?php endif; ?>
        <?php endforeach; ?>
        <?php foreach (['trend_location', 'trend_view', 'trend_year'] as $trendField): ?>
            <?php if (isset($_GET[$trendField]) && !is_array($_GET[$trendField])): ?><input type="hidden" name="<?php echo htmlspecialchars($trendField); ?>" value="<?php echo htmlspecialchars((string)$_GET[$trendField]); ?>"><?php endif; ?>
        <?php endforeach; ?>
        <?php if (isset($_GET['monthly_period']) && !is_array($_GET['monthly_period'])): ?><input type="hidden" name="monthly_period" value="<?php echo htmlspecialchars((string)$_GET['monthly_period']); ?>"><?php endif; ?>
        <label for="area-year" class="sr-only">Waste by Collection Group and Area year</label>
        <select id="area-year" name="area_year" onchange="this.form.requestSubmit()" title="Filter Waste by Collection Group and Area by year"><option value="">All years</option><?php foreach ($yearOptions as $yearOption): ?><option value="<?php echo htmlspecialchars($yearOption); ?>" <?php echo $selectedYear === $yearOption ? 'selected' : ''; ?>><?php echo htmlspecialchars($yearOption); ?></option><?php endforeach; ?></select>
        <label for="area-month" class="sr-only">Waste by Collection Group and Area month</label>
        <select id="area-month" name="area_month" onchange="this.form.requestSubmit()" title="Filter Waste by Collection Group and Area by month"><option value="">All months</option><?php foreach ($months as $month => $label): ?><option value="<?php echo $month; ?>" <?php echo (string)$month === $selectedMonth ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option><?php endforeach; ?></select>
    </form>
    <?php
}

// Handle posting daily task
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['post_task'])) {
    $task_title = trim($_POST['task_title'] ?? '');
    $task_description = trim($_POST['task_description'] ?? '');
    $task_priority = $_POST['task_priority'] ?? 'normal';

    if (empty($task_title) || empty($task_description)) {
        $error = "Please fill in all task fields.";
    } else {
        try {
            // A separate task is created for each active staff account. This lets
            // each staff member complete only their own copy of the task.
            $staffStmt = $conn->query("SELECT id FROM users WHERE user_type = 'staff' AND is_active = 1");
            $staffMembers = $staffStmt->fetchAll(PDO::FETCH_COLUMN);
            if (empty($staffMembers)) {
                $error = "No active staff accounts are available to receive this task.";
            } else {
                $conn->beginTransaction();
                $taskBatchId = bin2hex(random_bytes(16));
                $stmt = $conn->prepare("INSERT INTO daily_tasks (task_batch_id, title, description, assigned_to, priority, task_date, created_by, status) VALUES (?, ?, ?, ?, ?, CURDATE(), ?, 'pending')");
                foreach ($staffMembers as $staffMemberId) {
                    $stmt->execute([$taskBatchId, $task_title, $task_description, $staffMemberId, $task_priority, $user['id']]);
                    createUserNotification(
                        $conn,
                        $staffMemberId,
                        'New task assigned',
                        'An administrator assigned you a task: ' . $task_title,
                        'staff_daily_tasks.php',
                        'task_assigned',
                        $user['id']
                    );
                }
                $conn->commit();
                $message = "Task posted to " . count($staffMembers) . " active staff account" . (count($staffMembers) === 1 ? "!" : "s!");
            }
        } catch (Throwable $e) {
            if ($conn && $conn->inTransaction()) {
                $conn->rollBack();
            }
            $error = "Failed to post task: " . $e->getMessage();
        }
    }
}

function normalizeDailyTaskBatchId($value)
{
    $batchId = strtolower(trim((string)$value));
    return preg_match('/^[a-f0-9]{32}$/', $batchId) ? $batchId : '';
}

// Handle editing the pending assignments that belong to one posted task.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_task_batch'])) {
    $taskBatchId = normalizeDailyTaskBatchId($_POST['task_batch_id'] ?? '');
    $taskTitle = trim((string)($_POST['task_title'] ?? ''));
    $taskDescription = trim((string)($_POST['task_description'] ?? ''));
    $taskPriority = trim((string)($_POST['task_priority'] ?? ''));
    if ($taskBatchId === '' || $taskTitle === '' || $taskDescription === '' || !in_array($taskPriority, ['low', 'normal', 'high'], true)) {
        $error = 'Please provide a valid task title, description, and priority.';
    } else {
        try {
            $conn->beginTransaction();
            $pendingAssignments = $conn->prepare("SELECT id, assigned_to FROM daily_tasks WHERE task_batch_id = ? AND created_by = ? AND status = 'pending' FOR UPDATE");
            $pendingAssignments->execute([$taskBatchId, (int)$user['id']]);
            $assignees = $pendingAssignments->fetchAll();
            if (empty($assignees)) {
                $conn->rollBack();
                $message = 'No pending assignments are available to update for this task.';
            } else {
                $updateTask = $conn->prepare("UPDATE daily_tasks SET title = ?, description = ?, priority = ? WHERE task_batch_id = ? AND created_by = ? AND status = 'pending'");
                $updateTask->execute([$taskTitle, $taskDescription, $taskPriority, $taskBatchId, (int)$user['id']]);
                foreach ($assignees as $assignment) {
                    if ((int)$assignment['assigned_to'] > 0) {
                        createUserNotification($conn, (int)$assignment['assigned_to'], 'Daily task updated', 'An administrator updated your assigned task: ' . $taskTitle, 'staff_daily_tasks.php', 'task_updated', (int)$user['id']);
                    }
                }
                $conn->commit();
                $message = 'Daily task updated for ' . count($assignees) . ' pending assignment' . (count($assignees) === 1 ? '.' : 's.');
            }
        } catch (Throwable $e) {
            if ($conn && $conn->inTransaction()) {
                $conn->rollBack();
            }
            $error = 'Failed to update the daily task. Please try again.';
        }
    }
}

// Preserve completed work while allowing an administrator to stop pending task assignments.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_task_batch'])) {
    $taskBatchId = normalizeDailyTaskBatchId($_POST['task_batch_id'] ?? '');
    if ($taskBatchId === '') {
        $error = 'The selected daily task is invalid.';
    } else {
        try {
            $conn->beginTransaction();
            $pendingAssignments = $conn->prepare("SELECT id, assigned_to, title FROM daily_tasks WHERE task_batch_id = ? AND created_by = ? AND status = 'pending' FOR UPDATE");
            $pendingAssignments->execute([$taskBatchId, (int)$user['id']]);
            $assignees = $pendingAssignments->fetchAll();
            if (empty($assignees)) {
                $conn->rollBack();
                $message = 'No pending assignments are available to cancel for this task.';
            } else {
                $cancelTask = $conn->prepare("UPDATE daily_tasks SET status = 'cancelled' WHERE task_batch_id = ? AND created_by = ? AND status = 'pending'");
                $cancelTask->execute([$taskBatchId, (int)$user['id']]);
                $taskTitle = (string)$assignees[0]['title'];
                foreach ($assignees as $assignment) {
                    if ((int)$assignment['assigned_to'] > 0) {
                        createUserNotification($conn, (int)$assignment['assigned_to'], 'Daily task cancelled', 'An administrator cancelled your assigned task: ' . $taskTitle, 'staff_daily_tasks.php', 'task_cancelled', (int)$user['id']);
                    }
                }
                $conn->commit();
                $message = count($assignees) . ' pending task assignment' . (count($assignees) === 1 ? ' was' : 's were') . ' cancelled.';
            }
        } catch (Throwable $e) {
            if ($conn && $conn->inTransaction()) {
                $conn->rollBack();
            }
            $error = 'Failed to cancel the daily task. Please try again.';
        }
    }
}

// Handle posting announcement
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['post_announcement'])) {
    $announcement_title = trim($_POST['announcement_title'] ?? '');
    $announcement_content = trim($_POST['announcement_content'] ?? '');
    $announcement_priority = $_POST['announcement_priority'] ?? 'normal';

    if (empty($announcement_title) || empty($announcement_content)) {
        $error = "Please fill in all announcement fields.";
    } else {
        try {
            $stmt = $conn->prepare("INSERT INTO announcements (title, content, priority, created_by) VALUES (?, ?, ?, ?)");
            $stmt->execute([$announcement_title, $announcement_content, $announcement_priority, $user['id']]);
            $staffRecipients = $conn->query("SELECT id FROM users WHERE user_type = 'staff' AND is_active = 1")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($staffRecipients as $staffRecipientId) {
                createUserNotification(
                    $conn,
                    $staffRecipientId,
                    'New announcement',
                    $announcement_title,
                    'staff_announcements.php',
                    'announcement',
                    $user['id']
                );
            }
            $message = "Announcement posted successfully!";
        } catch (PDOException $e) {
            $error = "Failed to post announcement: " . $e->getMessage();
        }
    }
}

// Handle editing announcement
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_announcement'])) {
    $announcement_id = intval($_POST['announcement_id'] ?? 0);
    $announcement_title = trim($_POST['announcement_title'] ?? '');
    $announcement_content = trim($_POST['announcement_content'] ?? '');
    $announcement_priority = $_POST['announcement_priority'] ?? 'normal';

    if (empty($announcement_title) || empty($announcement_content)) {
        $error = "Please fill in all announcement fields.";
    } else {
        try {
            $stmt = $conn->prepare("UPDATE announcements SET title = ?, content = ?, priority = ? WHERE id = ?");
            $stmt->execute([$announcement_title, $announcement_content, $announcement_priority, $announcement_id]);
            $message = "Announcement updated successfully!";
        } catch (PDOException $e) {
            $error = "Failed to update announcement: " . $e->getMessage();
        }
    }
}

// Handle deleting announcement
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_announcement'])) {
    $announcement_id = intval($_POST['announcement_id'] ?? 0);
    try {
        $stmt = $conn->prepare("DELETE FROM announcements WHERE id = ?");
        $stmt->execute([$announcement_id]);
        $message = "Announcement deleted successfully!";
    } catch (PDOException $e) {
        $error = "Failed to delete announcement: " . $e->getMessage();
    }
}

// Get existing announcements for management
$existing_announcements = [];
try {
    $stmt = $conn->query("SELECT a.*, u.first_name, u.last_name FROM announcements a JOIN users u ON a.created_by = u.id ORDER BY a.created_at DESC LIMIT 10");
    $existing_announcements = $stmt->fetchAll();
} catch (PDOException $e) {
    // Handle error silently
}

$existingTaskBatches = [];
try {
    $taskBatches = $conn->prepare("SELECT task_batch_id, MAX(title) AS title, MAX(description) AS description, MAX(priority) AS priority, MAX(task_date) AS task_date, MAX(created_at) AS created_at,
        COUNT(*) AS assignment_count,
        SUM(status = 'pending') AS pending_count,
        SUM(status = 'completed') AS completed_count,
        SUM(status = 'cancelled') AS cancelled_count
        FROM daily_tasks
        WHERE created_by = ? AND task_batch_id IS NOT NULL
        GROUP BY task_batch_id
        ORDER BY MAX(created_at) DESC
        LIMIT 10");
    $taskBatches->execute([(int)$user['id']]);
    $existingTaskBatches = $taskBatches->fetchAll();
} catch (PDOException $e) {
    // Existing task management remains optional when the table is unavailable.
}

$dashboardSummary = [
    'total_waste' => 0,
    'total_collected_waste' => 0,
    'monthly_waste' => 0,
    'diverted' => 0,
    'returnable' => 0,
    'biowaste' => 0,
    'residual' => 0,
    'hazardous' => 0,
    'total_records' => 0,
    'active_collectors' => 0,
];
$phaseStreetComparison = [];
$streetVisualization = ['rows' => [], 'details' => [], 'definitions' => getWasteStreetCategoryDefinitions(), 'summary' => [], 'filters' => $streetFilterInput];
$streetPhaseOptions = [];
$streetFilterState = ['calendar' => ['years' => [], 'months_by_year' => [], 'dates_by_month' => []], 'has_unclassified' => false];
$streetFilterNotice = '';
$streetDetailSelection = null;
$allPhaseStreetComparison = [];
$areaPhaseComparison = [];
$weeklyTrendData = buildWeeklyWasteTrendAnalysis([], resolveWeeklyWasteTrendFilters([], $_GET));
$highestPhase = [
    'phase_name' => 'No data',
    'street' => '',
    'period_label' => '',
    'total_waste' => 0,
    'record_count' => 0,
];
$diversionRate = 0;
$categoryTotal = 0;
$categoryPercentages = ['diverted' => 0, 'returnable' => 0, 'biowaste' => 0, 'residual' => 0, 'hazardous' => 0];
$categoryVisualizationSummary = ['returnable' => 0, 'biowaste' => 0, 'residual' => 0, 'hazardous' => 0];
$categoryVisualizationTotal = 0;
$categoryVisualizationDiversionRate = 0;
$categoryVisualizationPercentages = ['returnable' => 0, 'biowaste' => 0, 'residual' => 0, 'hazardous' => 0];
$totalVisualizationSummary = ['total_waste' => 0, 'total_records' => 0];
$monthlyVisualizationSummary = ['monthly_waste' => 0, 'total_records' => 0];
$diversionVisualizationSummary = ['diverted' => 0, 'residual' => 0, 'hazardous' => 0];
$diversionVisualizationRate = 0;
$highestVisualizationComparison = [];
$highestVisualization = $highestPhase;
$recordsVisualizationSummary = ['total_records' => 0, 'active_collectors' => 0];
$attendanceDashboardSummary = array_fill_keys(array_keys(attendanceStatusOptions()), 0);
$attendanceDashboardAvailable = false;
$attendanceNotRecorded = 0;
try {
    ensureBioAttendanceSchema($conn);
    syncBioCollectors($conn);
    $attendanceState = bioAttendanceQuery($conn, attendanceToday(), '', '', 1, 1);
    $attendanceDashboardSummary = $attendanceState['summary'];
    $attendanceNotRecorded = $attendanceState['summary']['not_recorded'];
    $attendanceDashboardAvailable = true;
} catch (Throwable $e) {
    error_log('Dashboard attendance unavailable: ' . $e->getMessage());
}

try {
    // Every dashboard KPI and visualization starts from this same active,
    // stored-date record set. Archived rows and undated placeholders never
    // enter a chart, total, filter option, or insight payload.
    $comparisonStmt = $conn->prepare('SELECT * FROM waste_records WHERE is_active = 1 AND collection_date IS NOT NULL ORDER BY id');
    $comparisonStmt->execute();
    $comparisonRecords = $comparisonStmt->fetchAll(PDO::FETCH_ASSOC);

    $dashboardAnalytics = buildWasteAnalyticsFromRecords($comparisonRecords);
    $dashboardSummary = array_merge($dashboardSummary, buildWasteCategorySummary($comparisonRecords));
    $dashboardSummary['total_collected_waste'] = $dashboardAnalytics['summary']['total_collected_waste'];
    $dashboardSummary['monthly_waste'] = $dashboardAnalytics['summary']['monthly_waste'];

    $categoryTotal = (float)$dashboardSummary['diverted'] + (float)$dashboardSummary['residual'] + (float)$dashboardSummary['hazardous'];
    if ($categoryTotal > 0) {
        $diversionRate = round(((float)$dashboardSummary['diverted'] / $categoryTotal) * 100);
        $categoryPercentages = [
            'diverted' => round(((float)$dashboardSummary['diverted'] / $categoryTotal) * 100),
            'returnable' => ((float)$dashboardSummary['returnable'] / $categoryTotal) * 100,
            'biowaste' => ((float)$dashboardSummary['biowaste'] / $categoryTotal) * 100,
            'residual' => ((float)$dashboardSummary['residual'] / $categoryTotal) * 100,
            'hazardous' => ((float)$dashboardSummary['hazardous'] / $categoryTotal) * 100,
        ];
    }

    $totalVisualizationSummary = buildWasteCategorySummary(
        filterWasteRecordsByCollectionYear($comparisonRecords, $visualizationYearFilters['total'])
    );

    $monthlyVisualizationSummary = buildWasteMonthlySummary(
        $comparisonRecords,
        $monthlyPeriod
    );

    $diversionVisualizationSummary = buildWasteCategorySummary(
        filterWasteRecordsByCollectionYear($comparisonRecords, $visualizationYearFilters['diversion'])
    );
    $diversionVisualizationTotal = (float)$diversionVisualizationSummary['diverted'] + (float)$diversionVisualizationSummary['residual'] + (float)$diversionVisualizationSummary['hazardous'];
    $diversionVisualizationRate = $diversionVisualizationTotal > 0
        ? round(((float)$diversionVisualizationSummary['diverted'] / $diversionVisualizationTotal) * 100)
        : 0;

    $allPhaseStreetComparison = buildPhaseStreetRecordComparison($comparisonRecords, 6);
    if (!empty($allPhaseStreetComparison)) {
        $highestPhase = $allPhaseStreetComparison[0];
    }

    $highestVisualizationComparison = buildPhaseStreetRecordComparison(
        filterWasteRecordsByCollectionYear($comparisonRecords, $visualizationYearFilters['highest']),
        6
    );
    if (!empty($highestVisualizationComparison)) {
        $highestVisualization = $highestVisualizationComparison[0];
    }

    $recordsVisualizationSummary = buildWasteCategorySummary(
        filterWasteRecordsByCollectionYear($comparisonRecords, $visualizationYearFilters['records'])
    );

    $groupRecords = filterWasteRecordsByCollectionYear($comparisonRecords, $visualizationYearFilters['group']);
    $groupAnalytics = buildWasteAnalyticsFromRecords($groupRecords);
    $areaPhaseComparison = array_slice($groupAnalytics['phase_totals'], 0, 6);

    // The street card filters the same active Phase + Street source records
    // used for its chart, cards, and View Insights payload.
    $streetVisualization = buildWasteStreetComparison($comparisonRecords, $streetFilterInput);
    $streetFilterState = $streetVisualization['filter_state'];
    $streetFilterInput = $streetVisualization['filters'];
    if ($streetFilterMode === 'custom') {
        // Reflect the normalized order back into the two visible date fields.
        $streetCustomStart = $streetFilterInput['start_date'];
        $streetCustomEnd = $streetFilterInput['end_date'];
    }
    $streetPhaseOptions = $streetFilterState['phase_options'];
    $streetCalendarPeriods = [];
    foreach ($streetFilterState['calendar']['months_by_year'] as $calendarYear => $calendarMonths) {
        foreach ($calendarMonths as $calendarMonth) {
            $streetCalendarPeriods[sprintf('%04d-%02d', (int)$calendarYear, (int)$calendarMonth)] = true;
        }
    }
    $invalidStreetSelection = false;
    if ($streetFilterMode === 'month') $invalidStreetSelection = !isset($streetCalendarPeriods[$streetMonthSelection]);
    elseif ($streetFilterMode === 'year') $invalidStreetSelection = !in_array((int)$streetYearSelection, $streetFilterState['calendar']['years'], true);
    elseif ($streetFilterMode === 'range') {
        $invalidStreetSelection = ($streetRangeStartMonth !== '' && !isset($streetCalendarPeriods[$streetRangeStartMonth]))
            || ($streetRangeEndMonth !== '' && !isset($streetCalendarPeriods[$streetRangeEndMonth]));
    } elseif ($streetFilterMode === 'custom') {
        // Custom calendars enable every day in a data-backed month, not just
        // dates that happen to have a collection record.
        $invalidStreetSelection = ($streetVisualization['filters']['start_date'] !== '' && !isset($streetCalendarPeriods[substr($streetVisualization['filters']['start_date'], 0, 7)]))
            || ($streetVisualization['filters']['end_date'] !== '' && !isset($streetCalendarPeriods[substr($streetVisualization['filters']['end_date'], 0, 7)]));
    }
    if ($invalidStreetSelection) {
        $streetFilterInput['start_date'] = '';
        $streetFilterInput['end_date'] = '';
        $streetFilterMode = 'all';
        $streetMonthSelection = '';
        $streetYearSelection = '';
        $streetRangeStartMonth = '';
        $streetRangeEndMonth = '';
        $streetCustomStart = '';
        $streetCustomEnd = '';
        $streetVisualization = buildWasteStreetComparison($comparisonRecords, $streetFilterInput);
        $streetFilterState = $streetVisualization['filter_state'];
        $streetFilterNotice = 'The requested period was cleared because it is unavailable for the current filters.';
    }
    $phaseStreetComparison = $streetVisualization['rows'];
    $requestedStreetDetailKey = is_string($_GET['street_detail'] ?? null) ? trim($_GET['street_detail']) : '';
    if ($requestedStreetDetailKey !== '' && isset($streetVisualization['details'][$requestedStreetDetailKey])) {
        // This also provides a no-JavaScript fallback for every View Details
        // link while retaining the exact data and filters used by its bar.
        $streetDetailSelection = $streetVisualization['details'][$requestedStreetDetailKey];
    }

    $categoryRecords = filterWasteRecordsByCollectionYear($comparisonRecords, $visualizationYearFilters['category']);
    $categoryVisualizationSummary = buildWasteCategorySummary($categoryRecords);
    $categoryVisualizationTotal = (float)$categoryVisualizationSummary['diverted'] + (float)$categoryVisualizationSummary['residual'] + (float)$categoryVisualizationSummary['hazardous'];
    $categoryVisualizationDiversionRate = $categoryVisualizationTotal > 0
        ? round(((float)$categoryVisualizationSummary['diverted'] / $categoryVisualizationTotal) * 100)
        : 0;
    $categoryVisualizationPercentages = $categoryVisualizationTotal > 0 ? [
        'returnable' => ((float)$categoryVisualizationSummary['returnable'] / $categoryVisualizationTotal) * 100,
        'biowaste' => ((float)$categoryVisualizationSummary['biowaste'] / $categoryVisualizationTotal) * 100,
        'residual' => ((float)$categoryVisualizationSummary['residual'] / $categoryVisualizationTotal) * 100,
        'hazardous' => ((float)$categoryVisualizationSummary['hazardous'] / $categoryVisualizationTotal) * 100,
    ] : ['returnable' => 0, 'biowaste' => 0, 'residual' => 0, 'hazardous' => 0];

    // The Dashboard and Reports pages both get their weekly trend from this
    // same active-record query and pure calculation module.
    $weeklyTrendRecords = fetchActiveWeeklyWasteTrendRecords($conn);
    $weeklyTrendFilters = resolveWeeklyWasteTrendFilters($weeklyTrendRecords, $_GET);
    $weeklyTrendData = buildWeeklyWasteTrendAnalysis($weeklyTrendRecords, $weeklyTrendFilters);

} catch (PDOException $e) {
    $error = $error ?: "Waste visualizations could not load yet. Please check the waste_records table.";
}

$monthlyPeriodDate = $monthlyPeriod !== '' ? DateTimeImmutable::createFromFormat('!Y-m', $monthlyPeriod) : null;
$monthlyPeriodLabel = $monthlyPeriodDate ? $monthlyPeriodDate->format('F Y') : 'No imported period';

$maxPhaseStreetWaste = 0;
foreach ($phaseStreetComparison as $phaseStreetRow) {
    $maxPhaseStreetWaste = max($maxPhaseStreetWaste, (float)$phaseStreetRow['total_waste']);
}
$maxAreaPhaseWaste = 0;
foreach ($areaPhaseComparison as $areaPhaseRow) {
    $maxAreaPhaseWaste = max($maxAreaPhaseWaste, (float)$areaPhaseRow['total_waste']);
}
// MRF-inspired solid colors: recyclable, organic, residual, hazardous, then
// supporting collection and processing colors for additional bars.
$chartPalette = ['#2E7D32', '#8B5A2B', '#6B7280', '#DC2626', '#0F766E', '#D97706', '#0369A1', '#7C3AED'];
$weeklyTrendExplanationPeriods = array_map(static function ($week) {
    return [
        'date' => $week['label'],
        'total_waste' => (float)$week['total_waste'],
    ];
}, $weeklyTrendData['weeks']);

// The token is used by the in-dashboard AI explanation request. The OpenAI key
// remains on the server and is never sent to the browser.
if (empty($_SESSION['dashboard_explanation_csrf'])) {
    $_SESSION['dashboard_explanation_csrf'] = bin2hex(random_bytes(32));
}
$dashboardExplanationData = [
    'total-waste' => [
        'title' => 'Total Waste Collected',
        'data' => ['total_waste_kg' => (float)$totalVisualizationSummary['total_waste'], 'total_records' => (int)$totalVisualizationSummary['total_records']],
    ],
    'monthly-waste' => [
        'title' => 'Monthly Waste',
        'data' => [
            'period' => $monthlyPeriodLabel,
            'month' => $monthlyPeriodDate ? $monthlyPeriodDate->format('F') : null,
            'year' => $monthlyPeriodDate ? (int)$monthlyPeriodDate->format('Y') : null,
            'monthly_waste_kg' => (float)$monthlyVisualizationSummary['monthly_waste'],
            'total_records' => (int)$monthlyVisualizationSummary['total_records'],
        ],
    ],
    'diversion-rate' => [
        'title' => 'Waste Diversion Rate',
        'data' => ['diversion_rate_percent' => (int)$diversionVisualizationRate, 'diverted_kg' => (float)$diversionVisualizationSummary['diverted'], 'residual_kg' => (float)$diversionVisualizationSummary['residual'], 'hazardous_kg' => (float)$diversionVisualizationSummary['hazardous']],
    ],
    'highest-household' => [
        'title' => 'Highest Waste Record',
        'data' => [
            'phase_name' => $highestVisualization['phase_name'],
            'street' => $highestVisualization['street'],
            'period_label' => $highestVisualization['period_label'],
            'total_waste_kg' => (float)$highestVisualization['total_waste'],
            'record_count' => (int)$highestVisualization['record_count'],
            'areas' => $highestVisualizationComparison,
        ],
    ],
    'records-collectors' => [
        'title' => 'Records And Collectors',
        'data' => ['total_records' => (int)$recordsVisualizationSummary['total_records'], 'active_collectors' => (int)$recordsVisualizationSummary['active_collectors']],
    ],
    'waste-household' => [
        'title' => 'Waste Collection per Street',
        'data' => [
            'summary' => $streetVisualization['summary'],
            'displayed_areas' => $phaseStreetComparison,
            'filters' => $streetVisualization['filters'],
        ],
    ],
    'category-breakdown' => [
        'title' => 'Waste Category Breakdown',
        'data' => ['factory_returnable_kg' => (float)$categoryVisualizationSummary['returnable'], 'biowaste_kg' => (float)$categoryVisualizationSummary['biowaste'], 'residual_kg' => (float)$categoryVisualizationSummary['residual'], 'hazardous_kg' => (float)$categoryVisualizationSummary['hazardous'], 'diversion_rate_percent' => (int)$categoryVisualizationDiversionRate],
    ],
    'collection-trend' => [
        'title' => 'Waste Collection Trend',
        'data' => [
            'periods' => $weeklyTrendExplanationPeriods,
            'trend_description' => $weeklyTrendData['description'],
            'trend_description_tl' => $weeklyTrendData['description_tl'],
        ],
    ],
];
// High-waste alerts are generated at most once per day for each administrator
// who enabled the preference. They use the same dynamic heatmap bands shown in
// the Heatmap module, rather than a fixed or guessed threshold.
if (!empty($adminSettings['high_waste_alerts']) && !empty($dashboardAnalytics['heatmap_locations'])) {
    $highWasteLocations = array_values(array_filter($dashboardAnalytics['heatmap_locations'], static function ($location) {
        return (($location['waste_level']['label'] ?? '') === 'High') && (float)($location['total_waste'] ?? 0) > 0;
    }));
    if (!empty($highWasteLocations)) {
        try {
            $existingHighAlert = $conn->prepare("SELECT id FROM notifications WHERE recipient_user_id = ? AND notification_type = 'high_waste_alert' AND DATE(created_at) = CURDATE() LIMIT 1");
            $existingHighAlert->execute([$user['id']]);
            if (!$existingHighAlert->fetch()) {
                $locationNames = array_map(static function ($location) {
                    return $location['name'];
                }, array_slice($highWasteLocations, 0, 3));
                createUserNotification(
                    $conn,
                    $user['id'],
                    'High-waste area alert',
                    count($highWasteLocations) . ' mapped area' . (count($highWasteLocations) === 1 ? ' is' : 's are') . ' currently classified as High: ' . implode(', ', $locationNames) . '.',
                    'admin_waste_heatmap.php',
                    'high_waste_alert'
                );
            }
        } catch (PDOException $e) {
            error_log('Failed to create high-waste alert: ' . $e->getMessage());
        }
    }
}
$notificationItems = getNotificationItems($conn, $user);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - EcoTrack</title>
    <?php include 'includes/theme_head.php'; ?>
    <style>
        /* CSS Variables for Light/Dark Mode */
        :root {
          --bg-primary: #f8f9fa;
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
        }
        .dashboard-container {
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
          background: var(--bg-secondary);
          padding: 15px 30px;
          display: flex;
          justify-content: space-between;
          align-items: center;
          border-radius: 10px;
          box-shadow: var(--card-shadow);
          margin-bottom: 25px;
        }

        .header-title {
          font-size: 24px;
          font-weight: 600;
          color: #689f38;
        }

        .header-icons {
          display: flex;
          gap: 20px;
          align-items: center;
        }

        .header-icon {
          width: 40px;
          height: 40px;
          border-radius: 50%;
          background: var(--bg-primary);
          display: flex;
          align-items: center;
          justify-content: center;
          cursor: pointer;
          position: relative;
          font-size: 18px;
        }

        .notification-badge {
          position: absolute;
          top: -5px;
          right: -5px;
          width: 18px;
          height: 18px;
          background: #e74c3c;
          border-radius: 50%;
          display: flex;
          align-items: center;
          justify-content: center;
          font-size: 10px;
          color: white;
          font-weight: bold;
        }

        /* Stats Grid */
        .stats-grid {
          display: grid;
          grid-template-columns: repeat(3, 1fr);
          gap: 20px;
          margin-bottom: 25px;
        }

        .stat-card {
          padding: 25px;
          border-radius: 15px;
          color: white;
          min-height: 140px;
          display: flex;
          flex-direction: column;
          justify-content: center;
        }

        .stat-card.green {
          background: linear-gradient(135deg, #7cb342 0%, #558b2f 100%);
        }
        .stat-card.blue {
          background: linear-gradient(135deg, #42a5f5 0%, #1e88e5 100%);
        }
        .stat-card.yellow {
          background: linear-gradient(135deg, #f9a825 0%, #f57f17 100%);
        }
        .stat-card.pink {
          background: linear-gradient(135deg, #f48fb1 0%, #ec407a 100%);
        }
        .stat-card.purple {
          background: linear-gradient(135deg, #ab47bc 0%, #8e24aa 100%);
        }
        .stat-card.gray-blue {
          background: linear-gradient(135deg, #78909c 0%, #546e7a 100%);
        }

        .stat-value {
          font-size: 32px;
          font-weight: bold;
          margin-bottom: 8px;
        }
        .stat-label {
          font-size: 13px;
          opacity: 0.9;
          text-transform: uppercase;
          font-weight: 500;
        }
        .stat-sublabel {
          font-size: 11px;
          opacity: 0.8;
          margin-top: 5px;
        }
        .action-btn {
          background: rgba(255, 255, 255, 0.3);
          border: none;
          padding: 8px 15px;
          border-radius: 20px;
          color: white;
          font-size: 11px;
          cursor: pointer;
          margin-top: 12px;
          align-self: flex-start;
        }

        /* Charts Section */
        .charts-section {
          background: var(--bg-secondary);
          border-radius: 15px;
          padding: 25px;
          box-shadow: var(--card-shadow);
        }

        .charts-grid {
          display: grid;
          grid-template-columns: repeat(3, 1fr);
          gap: 20px;
          margin-top: 20px;
        }

        .chart-card {
          padding: 15px;
          background: var(--bg-primary);
          border-radius: 10px;
          min-height: 200px;
          display: flex;
          align-items: center;
          justify-content: center;
          color: var(--text-muted);
          font-size: 14px;
        }

        @media (max-width: 1200px) {
          .stats-grid {
            grid-template-columns: repeat(2, 1fr);
          }
          .charts-grid {
            grid-template-columns: 1fr;
          }
        }

        @media (max-width: 768px) {
          .sidebar {
            width: 70px;
            padding: 20px 10px;
          }
          .logo-text,
          .nav-text {
            display: none;
          }
          .main-content {
            margin-left: 70px;
          }
          .stats-grid {
            grid-template-columns: 1fr;
          }
        }
    </style>
    <link rel="stylesheet" href="assets/css/ecotrack-theme.css?v=<?php echo filemtime(__DIR__ . '/assets/css/ecotrack-theme.css'); ?>">
    <link rel="stylesheet" href="assets/css/dashboard-kpi.css?v=<?php echo filemtime(__DIR__ . '/assets/css/dashboard-kpi.css'); ?>">
    <script defer src="assets/js/year-navigable-picker.js?v=<?php echo filemtime(__DIR__ . '/assets/js/year-navigable-picker.js'); ?>"></script>
</head>
<body>
    <div class="dashboard-container">
    <?php $active_page = 'admin_dashboard.php';
$useLogoutModal = true;
include 'includes/sidebar.php'; ?>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Header -->
            <div class="header">
                <div>
                    <h1 class="header-title">Dashboard Overview</h1>
                    <p class="page-kicker">Monitor actual waste records through visual comparison, not prediction.</p>
                </div>
                <div class="header-icons">
                    <?php include 'includes/notification_bell.php'; ?>
                </div>
            </div>

            <?php if ($message): ?>
            <div class="alert alert-success" style="background: #d4edda; color: #155724; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                <?php echo htmlspecialchars($message); ?>
            </div>
            <?php endif; ?>

            <?php if ($error): ?>
            <div class="alert alert-error" style="background: #f8d7da; color: #721c24; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                <?php echo htmlspecialchars($error); ?>
            </div>
            <?php endif; ?>

            <div class="kpi-grid" id="collection-status">
                <div class="kpi-card kpi-card-explainable" data-visualization-key="total">
                    <div class="kpi-card-tools"><button type="button" class="explain-button explain-button-kpi" data-explanation-id="total-waste" aria-label="View insights for Total Waste Collected" title="View insights for this card">&#9432;<span>View Insights</span></button><?php renderDashboardVisualizationYearControl('total', $visualizationYearFilters['total'], $visualizationYears, $visualizationYearFilters, 'Total Waste Collected'); ?></div>
                    <div class="kpi-label">Total Waste Collected</div>
                    <div class="kpi-value"><?php echo number_format((float)$totalVisualizationSummary['total_waste'], 2); ?> kg</div>
                    <div class="kpi-note">Based on <?php echo $visualizationYearFilters['total'] !== '' ? htmlspecialchars($visualizationYearFilters['total']) : 'all years'; ?> waste records</div>
                </div>
                <div class="kpi-card kpi-card-explainable" data-visualization-key="monthly">
                    <div class="kpi-card-tools"><button type="button" class="explain-button explain-button-kpi" data-explanation-id="monthly-waste" aria-label="View insights for Monthly Waste" title="View insights for this card">&#9432;<span>View Insights</span></button><?php renderDashboardMonthlyPeriodControl($monthlyPeriod, $visualizationYears, $activeWastePeriods['months_by_year'], $visualizationYearFilters); ?></div>
                    <div class="kpi-label">Monthly Waste</div>
                    <div class="kpi-value"><?php echo number_format((float)$monthlyVisualizationSummary['monthly_waste'], 2); ?> kg</div>
                    <div class="kpi-note"><?php echo htmlspecialchars($monthlyPeriodLabel); ?> collection dates</div>
                </div>
                <div class="kpi-card kpi-card-explainable" data-visualization-key="diversion">
                    <div class="kpi-card-tools"><button type="button" class="explain-button explain-button-kpi" data-explanation-id="diversion-rate" aria-label="View insights for Waste Diversion Rate" title="View insights for this card">&#9432;<span>View Insights</span></button><?php renderDashboardVisualizationYearControl('diversion', $visualizationYearFilters['diversion'], $visualizationYears, $visualizationYearFilters, 'Waste Diversion Rate'); ?></div>
                    <div class="kpi-label">Waste Diversion Rate</div>
                    <div class="kpi-value"><?php echo $diversionVisualizationRate; ?>%</div>
                    <div class="kpi-note">Returnable and biowaste vs total waste</div>
                </div>
                <div class="kpi-card kpi-card-explainable" data-visualization-key="highest">
                    <div class="kpi-card-tools"><button type="button" class="explain-button explain-button-kpi" data-explanation-id="highest-household" aria-label="View insights for Highest Waste Record" title="View insights for this card">&#9432;<span>View Insights</span></button><?php renderDashboardVisualizationYearControl('highest', $visualizationYearFilters['highest'], $visualizationYears, $visualizationYearFilters, 'Highest Waste Record'); ?></div>
                    <div class="kpi-label">Highest Waste Record</div>
                    <div class="kpi-value"><?php echo !empty($highestVisualizationComparison) ? number_format((float)$highestVisualization['total_waste'], 2) . ' kg' : 'No data'; ?></div>
                    <div class="kpi-note">
                        <?php if (!empty($highestVisualizationComparison)): ?>
                            <?php echo htmlspecialchars($highestVisualization['phase_name']); ?> &middot; <?php echo htmlspecialchars($highestVisualization['street']); ?>
                        <?php else: ?>
                            Add waste records to compare areas.
                        <?php endif; ?>
                    </div>
                </div>
                <div class="kpi-card kpi-card-explainable" data-visualization-key="records">
                    <div class="kpi-card-tools"><button type="button" class="explain-button explain-button-kpi" data-explanation-id="records-collectors" aria-label="View insights for Records and Collectors" title="View insights for this card">&#9432;<span>View Insights</span></button><?php renderDashboardVisualizationYearControl('records', $visualizationYearFilters['records'], $visualizationYears, $visualizationYearFilters, 'Records and Collectors'); ?></div>
                    <div class="kpi-label">Records And Collectors</div>
                    <div class="kpi-value"><?php echo (int)$recordsVisualizationSummary['total_records']; ?> records</div>
                    <div class="kpi-note"><?php echo (int)$recordsVisualizationSummary['active_collectors']; ?> active collectors</div>
                </div>
            </div>

            <section class="viz-card" id="zone-analysis" data-visualization-key="group" style="margin-bottom: 20px;">
                <div class="viz-card-header"><h2>Waste by Collection Group</h2><div class="viz-card-header-actions"><?php renderDashboardVisualizationYearControl('group', $visualizationYearFilters['group'], $visualizationYears, $visualizationYearFilters, 'Waste by Collection Group'); ?></div></div>
                <?php if (empty($areaPhaseComparison)): ?>
                    <div class="empty-state"><strong>No Collection Group data yet</strong><span>Add or upload waste data to compare groups.</span></div>
                <?php else: ?>
                    <div class="comparison-list">
                        <?php foreach ($areaPhaseComparison as $areaPhaseIndex => $areaPhaseRow): ?>
                            <?php $barWidth = $maxAreaPhaseWaste > 0 ? round(((float)$areaPhaseRow['total_waste'] / $maxAreaPhaseWaste) * 100) : 0;
                            $barColor = $chartPalette[$areaPhaseIndex % count($chartPalette)]; ?>
                            <div class="comparison-row">
                                <div class="comparison-meta">
                                    <span><?php echo htmlspecialchars($areaPhaseRow['phase_name']); ?></span>
                                    <span class="comparison-value"><?php echo number_format((float)$areaPhaseRow['total_waste'], 2); ?> kg</span>
                                </div>
                                <div class="comparison-track"><div class="comparison-fill" style="--bar-width: <?php echo $barWidth; ?>%; --chart-color: <?php echo $barColor; ?>;"></div></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <div class="insight-grid">
                <section class="viz-card" data-visualization-key="area">
                    <?php
                    $streetSummary = $streetVisualization['summary'];
                    $streetDisplayDefinitions = $streetVisualization['definitions'];
                    if (empty($streetFilterState['has_unclassified'])) {
                        unset($streetDisplayDefinitions['unclassified']);
                    }
                    $streetCategoryKeys = $streetVisualization['filters']['category'] === 'all'
                        ? array_keys($streetDisplayDefinitions)
                        : [$streetVisualization['filters']['category']];
                    $streetTableDefinitions = $streetVisualization['filters']['category'] === 'all'
                        ? $streetDisplayDefinitions
                        : [$streetVisualization['filters']['category'] => $streetVisualization['definitions'][$streetVisualization['filters']['category']]];
                    $streetHighest = $streetSummary['highest'] ?? null;
                    $streetDominant = $streetSummary['dominant_category'] ?? null;
                    $streetCalendarYears = json_encode($streetFilterState['calendar']['years'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
                    $streetCalendarMonths = json_encode($streetFilterState['calendar']['months_by_year'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
                    $streetDetailsJson = json_encode($streetVisualization['details'] ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
                    if ($streetDetailsJson === false) $streetDetailsJson = '{}';
                    $streetDetailQuery = $_GET;
                    unset($streetDetailQuery['street_detail']);
                    $streetDetailBackUrl = 'admin_dashboard.php' . (empty($streetDetailQuery) ? '' : '?' . http_build_query($streetDetailQuery, '', '&', PHP_QUERY_RFC3986));
                    $streetSelectedCategories = $streetDetailSelection['categories'] ?? [];
                    ?>
                    <div class="viz-card-header"><div><h2>Waste Collection per Street</h2><p class="chart-subtitle">Top 8 Phase + Street pairs by actual filtered collection. Unit: kilograms (kg).</p></div><div class="viz-card-header-actions"><button type="button" class="explain-button" data-explanation-id="waste-household" aria-label="View insights for Waste Collection per Street" title="View insights for this chart">&#9432;<span>View Insights</span></button></div></div>
                    <div data-street-main-view<?php echo $streetDetailSelection ? ' hidden' : ''; ?>>
                    <form method="get" action="admin_dashboard.php" class="street-chart-filters visualization-filter-control" data-street-chart-filter data-street-filter-state-url="street_chart_filter_state.php" aria-describedby="street-filter-status">
                        <?php foreach ($visualizationYearFilters as $filterName => $filterValue): ?><?php if ($filterValue !== ''): ?><input type="hidden" name="<?php echo htmlspecialchars($filterName . '_year'); ?>" value="<?php echo htmlspecialchars($filterValue); ?>"><?php endif; ?><?php endforeach; ?>
                        <?php if ($monthlyPeriod !== ''): ?><input type="hidden" name="monthly_period" value="<?php echo htmlspecialchars($monthlyPeriod); ?>"><?php endif; ?>
                        <div class="street-filter-field"><label for="street-phase">Phase</label><select id="street-phase" name="street_phase"><option value="">All Phases</option><?php foreach ($streetPhaseOptions as $phaseOption): ?><option value="<?php echo htmlspecialchars($phaseOption); ?>" <?php echo $streetVisualization['filters']['phase'] === $phaseOption ? 'selected' : ''; ?>><?php echo htmlspecialchars($phaseOption); ?></option><?php endforeach; ?></select></div>
                        <div class="street-filter-field"><label for="street-category">Waste Category</label><select id="street-category" name="street_category"><option value="all">All Categories</option><?php foreach ($streetVisualization['definitions'] as $categoryKey => $definition): ?><?php if ($categoryKey !== 'unclassified' || !empty($streetFilterState['has_unclassified'])): ?><option value="<?php echo htmlspecialchars($categoryKey); ?>" <?php echo $streetVisualization['filters']['category'] === $categoryKey ? 'selected' : ''; ?>><?php echo htmlspecialchars($definition['label']); ?></option><?php endif; ?><?php endforeach; ?></select></div>
                        <div class="street-filter-field"><label for="street-period">Period</label><select id="street-period" name="street_period" data-street-period-mode><option value="all" <?php echo $streetFilterMode === 'all' ? 'selected' : ''; ?>>All dates</option><option value="month" <?php echo $streetFilterMode === 'month' ? 'selected' : ''; ?>>Monthly</option><option value="year" <?php echo $streetFilterMode === 'year' ? 'selected' : ''; ?>>Yearly</option><option value="range" <?php echo $streetFilterMode === 'range' ? 'selected' : ''; ?>>Month Range</option><option value="custom" <?php echo $streetFilterMode === 'custom' ? 'selected' : ''; ?>>Custom Dates</option></select></div>
                        <div class="street-filter-field" data-street-period-field="month"<?php echo $streetFilterMode === 'month' ? '' : ' hidden'; ?>><label for="street-month">Collection month</label><input id="street-month" type="month" name="street_month" value="<?php echo htmlspecialchars($streetMonthSelection); ?>" data-year-navigable-picker="month" data-year-picker-allow-empty="true" data-year-picker-placeholder="Choose month" data-calendar-years='<?php echo htmlspecialchars($streetCalendarYears ?: '[]', ENT_QUOTES, 'UTF-8'); ?>' data-calendar-months='<?php echo htmlspecialchars($streetCalendarMonths ?: '{}', ENT_QUOTES, 'UTF-8'); ?>'></div>
                        <div class="street-filter-field" data-street-period-field="year"<?php echo $streetFilterMode === 'year' ? '' : ' hidden'; ?>><label for="street-year">Collection year</label><select id="street-year" name="street_year"><option value="">Choose year</option><?php foreach ($streetFilterState['calendar']['years'] as $streetYear): ?><option value="<?php echo (int)$streetYear; ?>" <?php echo $streetYearSelection === (string)$streetYear ? 'selected' : ''; ?>><?php echo (int)$streetYear; ?></option><?php endforeach; ?></select></div>
                        <div class="street-filter-field" data-street-period-field="range"<?php echo $streetFilterMode === 'range' ? '' : ' hidden'; ?>><label for="street-range-start-month">From month</label><input id="street-range-start-month" type="month" name="street_range_start_month" value="<?php echo htmlspecialchars($streetRangeStartMonth); ?>" data-year-navigable-picker="month" data-year-picker-allow-empty="true" data-year-picker-placeholder="Choose start month" data-calendar-years='<?php echo htmlspecialchars($streetCalendarYears ?: '[]', ENT_QUOTES, 'UTF-8'); ?>' data-calendar-months='<?php echo htmlspecialchars($streetCalendarMonths ?: '{}', ENT_QUOTES, 'UTF-8'); ?>'></div>
                        <div class="street-filter-field" data-street-period-field="range"<?php echo $streetFilterMode === 'range' ? '' : ' hidden'; ?>><label for="street-range-end-month">To month</label><input id="street-range-end-month" type="month" name="street_range_end_month" value="<?php echo htmlspecialchars($streetRangeEndMonth); ?>" data-year-navigable-picker="month" data-year-picker-allow-empty="true" data-year-picker-placeholder="Choose end month" data-calendar-years='<?php echo htmlspecialchars($streetCalendarYears ?: '[]', ENT_QUOTES, 'UTF-8'); ?>' data-calendar-months='<?php echo htmlspecialchars($streetCalendarMonths ?: '{}', ENT_QUOTES, 'UTF-8'); ?>'></div>
                        <div class="street-filter-field" data-street-period-field="custom"<?php echo $streetFilterMode === 'custom' ? '' : ' hidden'; ?>><label for="street-custom-start">Start date</label><input id="street-custom-start" type="date" name="street_custom_start" value="<?php echo htmlspecialchars($streetCustomStart); ?>" data-year-navigable-picker="date" data-year-picker-all-days-in-available-month="true" data-year-picker-allow-empty="true" data-year-picker-placeholder="Choose date" data-calendar-years='<?php echo htmlspecialchars($streetCalendarYears ?: '[]', ENT_QUOTES, 'UTF-8'); ?>' data-calendar-months='<?php echo htmlspecialchars($streetCalendarMonths ?: '{}', ENT_QUOTES, 'UTF-8'); ?>'></div>
                        <div class="street-filter-field" data-street-period-field="custom"<?php echo $streetFilterMode === 'custom' ? '' : ' hidden'; ?>><label for="street-custom-end">End date</label><input id="street-custom-end" type="date" name="street_custom_end" value="<?php echo htmlspecialchars($streetCustomEnd); ?>" data-year-navigable-picker="date" data-year-picker-all-days-in-available-month="true" data-year-picker-allow-empty="true" data-year-picker-placeholder="Choose date" data-calendar-years='<?php echo htmlspecialchars($streetCalendarYears ?: '[]', ENT_QUOTES, 'UTF-8'); ?>' data-calendar-months='<?php echo htmlspecialchars($streetCalendarMonths ?: '{}', ENT_QUOTES, 'UTF-8'); ?>'></div>
                        <div class="street-filter-actions" aria-label="Street filter actions">
                            <button class="btn btn-upload" type="submit" data-street-filter-submit>Filter</button>
                            <button class="btn btn-cancel" type="button" data-street-filter-reset>Reset</button>
                        </div>
                    </form>
                    <p id="street-filter-status" class="street-filter-status<?php echo $error !== '' ? ' is-error' : ''; ?>" role="<?php echo $error !== '' ? 'alert' : 'status'; ?>" aria-live="polite"><?php echo $error !== '' ? htmlspecialchars($error) : htmlspecialchars($streetFilterNotice); ?></p>
                    <div class="street-summary-grid" aria-label="Waste Collection per Street summary">
                            <article><span>Total Waste Collected</span><strong><?php echo number_format((float)$streetSummary['total_waste'], 2); ?> kg</strong><small>All active filtered records</small></article>
                            <article><span>Streets with Collection</span><strong><?php echo (int)$streetSummary['street_count']; ?></strong><small>Phase + Street identities</small></article>
                            <article><span>Highest Collection Street</span><strong><?php echo $streetHighest ? number_format((float)$streetHighest['total_waste'], 2) . ' kg' : 'No data'; ?></strong><small><?php echo $streetHighest ? htmlspecialchars($streetHighest['phase_name'] . ' · ' . $streetHighest['street']) : 'No matching street'; ?></small></article>
                            <article><span>Most Collected Category</span><strong><?php echo $streetDominant ? htmlspecialchars($streetDominant['label']) : 'No data'; ?></strong><small><?php echo $streetDominant ? number_format((float)$streetDominant['total_waste'], 2) . ' kg' : 'No category data'; ?></small></article>
                        </div>
                    <?php if (empty($phaseStreetComparison)): ?>
                        <div class="empty-state"><strong>No collection data available</strong><span>Adjust the filters or upload active waste records with a phase, street, and collection date.</span></div>
                    <?php else: ?>
                        <?php if ($streetVisualization['filters']['category'] === 'all'): ?><div class="street-chart-legend street-chart-modern-legend" aria-label="Waste category legend"><?php foreach ($streetDisplayDefinitions as $definition): ?><span><i style="--category-color:<?php echo htmlspecialchars($definition['color']); ?>"></i><?php echo htmlspecialchars($definition['label']); ?></span><?php endforeach; ?></div><?php endif; ?>
                        <div class="street-horizontal-chart" aria-label="Horizontal bar chart of waste collection per street in kilograms">
                            <div class="street-chart-rows">
                            <?php foreach ($phaseStreetComparison as $phaseRow): ?>
                                <?php
                                  $barWidth = $maxPhaseStreetWaste > 0 ? max(2, ((float)$phaseRow['total_waste'] / $maxPhaseStreetWaste) * 100) : 0;
                                  $stackTotal = 0.0; $tooltipLines = [];
                                  foreach ($streetCategoryKeys as $categoryKey) { $amount = (float)($phaseRow['categories'][$categoryKey] ?? 0); $stackTotal += $amount; $tooltipLines[] = ($streetVisualization['definitions'][$categoryKey]['label'] ?? $categoryKey) . ': ' . number_format($amount, 2) . ' kg'; }
                                  $tooltip = $phaseRow['phase_name'] . ' · ' . $phaseRow['street'] . "\nTotal: " . number_format((float)$phaseRow['total_waste'], 2) . " kg\n" . implode("\n", $tooltipLines);
                                  $streetDetailLinkQuery = $streetDetailQuery;
                                  $streetDetailLinkQuery['street_detail'] = $phaseRow['key'];
                                  $streetDetailUrl = 'admin_dashboard.php?' . http_build_query($streetDetailLinkQuery, '', '&', PHP_QUERY_RFC3986);
                                ?>
                                <div class="street-chart-row">
                                    <a href="<?php echo htmlspecialchars($streetDetailUrl, ENT_QUOTES, 'UTF-8'); ?>" class="street-chart-row-label street-chart-detail-trigger" data-street-detail-key="<?php echo htmlspecialchars($phaseRow['key'], ENT_QUOTES, 'UTF-8'); ?>" aria-label="View collection details for <?php echo htmlspecialchars($phaseRow['street'] . ', ' . $phaseRow['phase_name'], ENT_QUOTES, 'UTF-8'); ?>"><strong><?php echo htmlspecialchars($phaseRow['street']); ?></strong><small><?php echo htmlspecialchars($phaseRow['phase_name']); ?></small></a>
                                    <div class="street-chart-track"><a href="<?php echo htmlspecialchars($streetDetailUrl, ENT_QUOTES, 'UTF-8'); ?>" class="street-chart-bar street-chart-detail-trigger" data-street-detail-key="<?php echo htmlspecialchars($phaseRow['key'], ENT_QUOTES, 'UTF-8'); ?>" data-street-tooltip="<?php echo htmlspecialchars($tooltip, ENT_QUOTES, 'UTF-8'); ?>" aria-label="View collection details for <?php echo htmlspecialchars($phaseRow['street'] . ', ' . $phaseRow['phase_name'] . '. ' . str_replace("\n", '. ', $tooltip), ENT_QUOTES, 'UTF-8'); ?>" style="--bar-width:<?php echo number_format($barWidth, 3, '.', ''); ?>%">
                                        <?php foreach ($streetCategoryKeys as $categoryKey): $amount = (float)($phaseRow['categories'][$categoryKey] ?? 0); if ($amount <= 0 || $stackTotal <= 0) { continue; } ?><span style="--category-color:<?php echo htmlspecialchars($streetVisualization['definitions'][$categoryKey]['color']); ?>;--segment-share:<?php echo number_format(($amount / $stackTotal) * 100, 4, '.', ''); ?>%"></span><?php endforeach; ?>
                                    </a></div>
                                    <strong class="street-chart-row-value"><?php echo number_format((float)$phaseRow['total_waste'], 2); ?> kg</strong>
                                    <a href="<?php echo htmlspecialchars($streetDetailUrl, ENT_QUOTES, 'UTF-8'); ?>" class="street-chart-details-button street-chart-detail-trigger" data-street-detail-key="<?php echo htmlspecialchars($phaseRow['key'], ENT_QUOTES, 'UTF-8'); ?>" aria-label="View details for <?php echo htmlspecialchars($phaseRow['street'] . ', ' . $phaseRow['phase_name'], ENT_QUOTES, 'UTF-8'); ?>">View Details</a>
                                </div>
                            <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="street-chart-tooltip" data-street-chart-tooltip role="tooltip" hidden></div>
                        <?php if (false): /* Retired vertical chart retained only as a source-history guard. */ ?>
                        <div class="street-chart-legend street-chart-legacy-legend" aria-hidden="true"><?php foreach ($streetVisualization['definitions'] as $definition): ?><span><i style="--category-color:<?php echo htmlspecialchars($definition['color']); ?>"></i><?php echo htmlspecialchars($definition['label']); ?></span><?php endforeach; ?></div>
                        <div class="street-chart-wrap" tabindex="0" aria-label="Waste Collection per Street chart. The vertical axis is Collected Waste in kilograms. The horizontal axis is Street and Phase.">
                            <div class="street-chart-axis street-chart-axis-y">Collected Waste (kg)</div>
                            <div class="street-chart-plot">
                                <div class="street-chart-grid"><span><?php echo number_format($maxPhaseStreetWaste, 2); ?></span><span><?php echo number_format($maxPhaseStreetWaste / 2, 2); ?></span><span>0</span></div>
                                <div class="street-chart-bars">
                                <?php foreach ($phaseStreetComparison as $phaseRow): ?>
                                    <?php
                                      $barHeight = $maxPhaseStreetWaste > 0 ? max(2, ((float)$phaseRow['total_waste'] / $maxPhaseStreetWaste) * 100) : 0;
                                      $categoryKeys = $streetVisualization['filters']['category'] === 'all' ? array_keys($streetVisualization['definitions']) : [$streetVisualization['filters']['category']];
                                      $stackTotal = 0.0; $tooltipLines = [];
                                      foreach ($categoryKeys as $categoryKey) { $amount = (float)($phaseRow['categories'][$categoryKey] ?? 0); $stackTotal += $amount; $tooltipLines[] = ($streetVisualization['definitions'][$categoryKey]['label'] ?? $categoryKey) . ': ' . number_format($amount, 2) . ' kg'; }
                                      $tooltip = $phaseRow['phase_name'] . ' · ' . $phaseRow['street'] . "\nTotal: " . number_format((float)$phaseRow['total_waste'], 2) . " kg\n" . implode("\n", $tooltipLines);
                                    ?>
                                    <div class="street-chart-column">
                                        <span class="street-chart-value"><?php echo number_format((float)$phaseRow['total_waste'], 2); ?> kg</span>
                                        <div class="street-chart-bar" tabindex="0" title="<?php echo htmlspecialchars($tooltip); ?>" aria-label="<?php echo htmlspecialchars(str_replace("\n", '. ', $tooltip)); ?>" style="--bar-height:<?php echo number_format($barHeight, 3, '.', ''); ?>%">
                                            <?php foreach ($categoryKeys as $categoryKey): $amount = (float)($phaseRow['categories'][$categoryKey] ?? 0); if ($amount <= 0 || $stackTotal <= 0) { continue; } ?><span style="--category-color:<?php echo htmlspecialchars($streetVisualization['definitions'][$categoryKey]['color']); ?>;--segment-share:<?php echo number_format(($amount / $stackTotal) * 100, 4, '.', ''); ?>%" title="<?php echo htmlspecialchars($streetVisualization['definitions'][$categoryKey]['label'] . ': ' . number_format($amount, 2) . ' kg'); ?>"></span><?php endforeach; ?>
                                        </div>
                                        <span class="street-chart-x-label"><strong><?php echo htmlspecialchars($phaseRow['street']); ?></strong><small><?php echo htmlspecialchars($phaseRow['phase_name']); ?></small></span>
                                    </div>
                                <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="street-chart-axis street-chart-axis-x">Street / Phase</div>
                        </div>
                        <?php endif; ?>
                        <details class="street-chart-table"><summary>View chart data as a table</summary><div class="table-scroll"><table><thead><tr><th>Phase</th><th>Street</th><?php foreach ($streetTableDefinitions as $definition): ?><th><?php echo htmlspecialchars($definition['label']); ?> (kg)</th><?php endforeach; ?><th>Total (kg)</th></tr></thead><tbody><?php foreach ($phaseStreetComparison as $phaseRow): ?><tr><td><?php echo htmlspecialchars($phaseRow['phase_name']); ?></td><td><?php echo htmlspecialchars($phaseRow['street']); ?></td><?php foreach ($streetTableDefinitions as $categoryKey => $definition): ?><td><?php echo number_format((float)($phaseRow['categories'][$categoryKey] ?? 0), 2); ?></td><?php endforeach; ?><td><?php echo number_format((float)$phaseRow['total_waste'], 2); ?></td></tr><?php endforeach; ?></tbody></table></div></details>
                    <?php endif; ?>
                    </div>
                    <script type="application/json" data-street-details-data><?php echo $streetDetailsJson; ?></script>
                    <section class="street-detail-view" data-street-detail-view<?php echo $streetDetailSelection ? '' : ' hidden'; ?> aria-labelledby="street-detail-title">
                        <a href="<?php echo htmlspecialchars($streetDetailBackUrl, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-cancel street-detail-back" data-street-detail-back>&larr; Back to Street Collection</a>
                        <div data-street-detail-content<?php echo $streetDetailSelection ? '' : ' hidden'; ?>>
                            <h3 id="street-detail-title" tabindex="-1">Street Collection Details</h3>
                            <p class="street-detail-selection">Selected Street: <strong data-street-detail-street><?php echo htmlspecialchars($streetDetailSelection['street'] ?? ''); ?></strong><span aria-hidden="true">&middot;</span> Phase: <strong data-street-detail-phase><?php echo htmlspecialchars($streetDetailSelection['phase_name'] ?? ''); ?></strong></p>
                            <article class="street-detail-total"><span>Total Waste Collected</span><strong data-street-detail-total><?php echo number_format((float)($streetDetailSelection['total_waste'] ?? 0), 2); ?> kg</strong></article>
                            <section class="street-detail-breakdown" aria-labelledby="street-detail-breakdown-title">
                                <h4 id="street-detail-breakdown-title">Waste Collected by Type</h4>
                                <div class="street-detail-stack" data-street-detail-stack role="img" aria-label="Waste category breakdown for <?php echo htmlspecialchars($streetDetailSelection['street'] ?? 'selected street'); ?>"><?php foreach ($streetSelectedCategories as $detailCategory): ?><span style="--detail-share:<?php echo number_format((float)($detailCategory['percent'] ?? 0), 4, '.', ''); ?>%;--category-color:<?php echo htmlspecialchars($detailCategory['color'] ?? '#64748B'); ?>" title="<?php echo htmlspecialchars(($detailCategory['label'] ?? 'Waste type') . ': ' . number_format((float)($detailCategory['amount'] ?? 0), 2) . ' kg (' . number_format((float)($detailCategory['percent'] ?? 0), 1) . '%)'); ?>"></span><?php endforeach; ?></div>
                                <div class="street-detail-category-list" data-street-detail-category-list><?php foreach ($streetSelectedCategories as $detailCategory): ?><div class="street-detail-category"><span class="street-detail-category-label"><i style="--category-color:<?php echo htmlspecialchars($detailCategory['color'] ?? '#64748B'); ?>"></i><span><?php echo htmlspecialchars($detailCategory['label'] ?? 'Waste type'); ?></span></span><strong class="street-detail-category-value"><?php echo number_format((float)($detailCategory['amount'] ?? 0), 2); ?> kg</strong><small class="street-detail-category-percent"><?php echo number_format((float)($detailCategory['percent'] ?? 0), 1); ?>%</small></div><?php endforeach; ?></div>
                            </section>
                        </div>
                        <div class="empty-state" data-street-detail-empty hidden><strong>Street details are unavailable</strong><span>The selected street no longer matches the active filters. Return to the chart and select a displayed street.</span></div>
                    </section>
                </section>

                <section class="viz-card" data-visualization-key="category">
                    <div class="viz-card-header"><h2>Waste Category Breakdown</h2><div class="viz-card-header-actions"><button type="button" class="explain-button" data-explanation-id="category-breakdown" aria-label="View insights for Waste Category Breakdown" title="View insights for this chart">&#9432;<span>View Insights</span></button><?php renderDashboardVisualizationYearControl('category', $visualizationYearFilters['category'], $visualizationYears, $visualizationYearFilters, 'Waste Category Breakdown'); ?></div></div>
                    <div class="category-visual">
                        <div class="donut-chart"
                             role="img"
                             aria-label="Waste category breakdown. Diversion rate: <?php echo $categoryVisualizationDiversionRate; ?> percent."
                             style="--returnable: <?php echo number_format($categoryVisualizationPercentages['returnable'], 4, '.', ''); ?>%; --biowaste: <?php echo number_format($categoryVisualizationPercentages['biowaste'], 4, '.', ''); ?>%; --residual: <?php echo number_format($categoryVisualizationPercentages['residual'], 4, '.', ''); ?>%; --hazardous: <?php echo number_format($categoryVisualizationPercentages['hazardous'], 4, '.', ''); ?>%; --category-returnable:<?php echo getWasteCategoryDefinitions(false)['returnable']['color']; ?>; --category-biowaste:<?php echo getWasteCategoryDefinitions(false)['biowaste']['color']; ?>; --category-residual:<?php echo getWasteCategoryDefinitions(false)['residual']['color']; ?>; --category-hazardous:<?php echo getWasteCategoryDefinitions(false)['hazardous']['color']; ?>;"><span class="donut-chart-center" aria-hidden="true"><strong><?php echo $categoryVisualizationDiversionRate; ?>%</strong><span>Diversion rate</span></span></div>
                        <div class="legend-list">
                            <?php foreach (getWasteCategoryDefinitions() as $categoryKey => $definition): ?><div class="legend-item"><span class="legend-key"><span class="legend-swatch" style="--swatch:<?php echo htmlspecialchars($definition['color']); ?>;"></span><?php echo htmlspecialchars($definition['label']); ?></span><strong><?php echo number_format((float)($categoryVisualizationSummary[$categoryKey] ?? 0), 2); ?> kg</strong></div><?php endforeach; ?>
                        </div>
                    </div>
                </section>
            </div>

            <div class="insight-grid">
                <?php renderWeeklyWasteTrend($weeklyTrendData, [
                    'action' => 'admin_dashboard.php',
                    'preserved_params' => weeklyWasteTrendPreservedParams($_GET),
                    'title' => 'Waste Collection Trend',
                    'header_actions' => '<button type="button" class="explain-button" data-explanation-id="collection-trend" aria-label="View insights for Waste Collection Trend" title="View insights for this chart">&#9432;<span>View Insights</span></button>',
                ]); ?>

                <section class="viz-card" data-visualization-key="attendance">
                    <div class="viz-card-header"><div><h2>Attendance Management</h2><p class="chart-subtitle">Today&rsquo;s recorded collector attendance.</p></div><a class="btn btn-upload" href="admin_attendance.php">Manage attendance</a></div>
                    <?php if ($attendanceDashboardAvailable): ?><div class="attendance-dashboard-summary" aria-label="Today attendance summary">
                        <?php foreach (attendanceStatusOptions() as $attendanceKey => $attendanceLabel): ?><div><strong><?php echo (int)$attendanceDashboardSummary[$attendanceKey]; ?></strong><span class="attendance-status-<?php echo htmlspecialchars($attendanceKey); ?>"><?php echo htmlspecialchars($attendanceLabel); ?></span></div><?php endforeach; ?>
                    </div><p><?php echo (int)$attendanceNotRecorded; ?> Not Recorded</p><?php else: ?><p>Attendance summary is temporarily unavailable.</p><?php endif; ?>
                </section>
            </div>

            <!-- Task & Announcement Management Panel -->
            <div class="management-panel">
                <h2 class="management-title">Task & Announcement Manager</h2>
                <div class="panel-grid">
                    <div class="panel-card">
                        <h3>Post Daily Task</h3>
                        <p>Create and assign daily tasks to staff members</p>
                        <form method="POST" action="admin_dashboard.php" class="quick-form">
                            <input type="text" name="task_title" required>
                            <textarea name="task_description" rows="2" required></textarea>
                            <select name="task_priority" required>
                                <option value="normal">Normal Priority</option>
                                <option value="high">High Priority</option>
                                <option value="low">Low Priority</option>
                            </select>
                            <button type="submit" name="post_task" class="btn btn-green">Post Task</button>
                        </form>
                    </div>
                    <div class="panel-card">
                        <h3>Post Announcement</h3>
                        <p>Broadcast announcements to all staff members</p>
                        <form method="POST" action="admin_dashboard.php" class="quick-form">
                            <input type="text" name="announcement_title" required>
                            <textarea name="announcement_content" rows="2" required></textarea>
                            <select name="announcement_priority" required>
                                <option value="normal">Normal Priority</option>
                                <option value="high">High Priority</option>
                                <option value="low">Low Priority</option>
                            </select>
                            <button type="submit" name="post_announcement" class="btn btn-blue">Post Announcement</button>
                        </form>
                    </div>
                </div>

                <!-- Manage Posted Daily Tasks -->
                <div class="tasks-management" style="margin-top: 25px;">
                    <h3 class="announcements-heading">Manage Posted Daily Tasks</h3>
                    <?php if (empty($existingTaskBatches)): ?>
                        <p class="announcements-empty">No daily tasks posted yet.</p>
                    <?php else: ?>
                        <div class="posted-tasks-list">
                            <?php foreach ($existingTaskBatches as $taskBatch): ?>
                                <?php $hasPendingAssignments = (int)$taskBatch['pending_count'] > 0; ?>
                                <div class="posted-task-item">
                                    <div class="ann-priority priority-<?php echo htmlspecialchars($taskBatch['priority']); ?>"></div>
                                    <div class="posted-task-content">
                                        <div class="ann-title"><?php echo htmlspecialchars($taskBatch['title']); ?></div>
                                        <div class="ann-desc"><?php echo htmlspecialchars(substr($taskBatch['description'], 0, 100)); ?><?php echo strlen($taskBatch['description']) > 100 ? '...' : ''; ?></div>
                                        <div class="task-assignment-counts" aria-label="Task assignment status">
                                            <span class="task-assignment-count task-assignment-count--pending">Pending: <?php echo (int)$taskBatch['pending_count']; ?></span>
                                            <span class="task-assignment-count task-assignment-count--completed">Completed: <?php echo (int)$taskBatch['completed_count']; ?></span>
                                            <span class="task-assignment-count task-assignment-count--cancelled">Cancelled: <?php echo (int)$taskBatch['cancelled_count']; ?></span>
                                        </div>
                                        <div class="ann-meta">Posted <?php echo date('M d, Y', strtotime($taskBatch['created_at'])); ?> &bull; Task date: <?php echo date('M d, Y', strtotime($taskBatch['task_date'])); ?> &bull; <?php echo (int)$taskBatch['assignment_count']; ?> staff assignment<?php echo (int)$taskBatch['assignment_count'] === 1 ? '' : 's'; ?></div>
                                    </div>
                                    <div class="task-management-actions">
                                        <button type="button" class="btn-edit-task" <?php echo $hasPendingAssignments ? '' : 'disabled'; ?>
                                            data-task-batch-id="<?php echo htmlspecialchars($taskBatch['task_batch_id'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-task-title="<?php echo htmlspecialchars($taskBatch['title'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-task-description="<?php echo htmlspecialchars($taskBatch['description'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-task-priority="<?php echo htmlspecialchars($taskBatch['priority'], ENT_QUOTES, 'UTF-8'); ?>">Edit</button>
                                        <form method="post" action="" style="display: inline;" data-confirm-title="Cancel pending task assignments?" data-confirm-message="Only pending staff assignments for this task will be cancelled. Completed work will remain unchanged." data-confirm-action="Cancel pending assignments">
                                            <input type="hidden" name="task_batch_id" value="<?php echo htmlspecialchars($taskBatch['task_batch_id'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <button type="submit" name="cancel_task_batch" class="btn-cancel-task" <?php echo $hasPendingAssignments ? '' : 'disabled'; ?>>Cancel pending</button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Manage Existing Announcements -->
                <div class="announcements-management" style="margin-top: 25px;">
                    <h3 class="announcements-heading">Manage Existing Announcements</h3>
                    <?php if (empty($existing_announcements)): ?>
                        <p class="announcements-empty">No announcements posted yet.</p>
                    <?php else: ?>
                        <div class="announcements-list">
                            <?php foreach ($existing_announcements as $ann): ?>
                            <div class="announcement-item">
                                <div class="ann-priority priority-<?php echo $ann['priority']; ?>"></div>
                                <div class="ann-content">
                                    <div class="ann-title"><?php echo htmlspecialchars($ann['title']); ?></div>
                                    <div class="ann-desc"><?php echo htmlspecialchars(substr($ann['content'], 0, 80)) . '...'; ?></div>
                                    <div class="ann-meta">By <?php echo htmlspecialchars($ann['first_name'] . ' ' . $ann['last_name']); ?> • <?php echo date('M d, Y', strtotime($ann['created_at'])); ?></div>
                                </div>
                                <div class="ann-actions">
                                    <button type="button" class="btn-edit-ann"
                                        data-announcement-id="<?php echo (int)$ann['id']; ?>"
                                        data-announcement-title="<?php echo htmlspecialchars($ann['title'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-announcement-content="<?php echo htmlspecialchars($ann['content'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-announcement-priority="<?php echo htmlspecialchars($ann['priority'], ENT_QUOTES, 'UTF-8'); ?>">Edit</button>
                                    <form method="post" action="" style="display: inline;" data-confirm-title="Delete announcement?" data-confirm-message="This announcement will be permanently removed for staff members." data-confirm-action="Delete announcement">
                                        <input type="hidden" name="announcement_id" value="<?php echo $ann['id']; ?>">
                                        <button type="submit" name="delete_announcement" class="btn-delete-ann">Delete</button>
                                    </form>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Edit Announcement Modal -->
                <div id="editModal" class="edit-modal">
                    <div class="edit-modal-content">
                        <h3>Edit Announcement</h3>
                        <form method="post" action="" id="editForm">
                            <input type="hidden" name="announcement_id" id="edit_ann_id">
                            <div class="edit-form-field">
                                <label>Title</label>
                                <input type="text" name="announcement_title" id="edit_ann_title" required>
                            </div>
                            <div class="edit-form-field">
                                <label>Content</label>
                                <textarea name="announcement_content" id="edit_ann_content" rows="4" required></textarea>
                            </div>
                            <div class="edit-form-field">
                                <label>Priority</label>
                                <select name="announcement_priority" id="edit_ann_priority" required>
                                    <option value="low">Low Priority</option>
                                    <option value="normal">Normal Priority</option>
                                    <option value="high">High Priority</option>
                                </select>
                            </div>
                            <div class="edit-form-actions">
                                <button type="button" class="btn-modal-cancel" onclick="closeEditModal()">Cancel</button>
                                <button type="submit" name="edit_announcement" class="btn-modal-save">Save Changes</button>
                            </div>
                        </form>
                    </div>
                </div>

                <div id="editTaskModal" class="edit-modal">
                    <div class="edit-modal-content">
                        <h3>Edit Daily Task</h3>
                        <p class="edit-modal-note">Changes apply only to staff assignments that are still pending.</p>
                        <form method="post" action="" id="editTaskForm">
                            <input type="hidden" name="task_batch_id" id="edit_task_batch_id">
                            <div class="edit-form-field">
                                <label for="edit_task_title">Title</label>
                                <input type="text" name="task_title" id="edit_task_title" required>
                            </div>
                            <div class="edit-form-field">
                                <label for="edit_task_description">Description</label>
                                <textarea name="task_description" id="edit_task_description" rows="4" required></textarea>
                            </div>
                            <div class="edit-form-field">
                                <label for="edit_task_priority">Priority</label>
                                <select name="task_priority" id="edit_task_priority" required>
                                    <option value="low">Low Priority</option>
                                    <option value="normal">Normal Priority</option>
                                    <option value="high">High Priority</option>
                                </select>
                            </div>
                            <div class="edit-form-actions">
                                <button type="button" class="btn-modal-cancel" onclick="closeEditTaskModal()">Cancel</button>
                                <button type="submit" name="edit_task_batch" class="btn-modal-save">Save Changes</button>
                            </div>
                        </form>
                    </div>
                </div>

                <script>
                    function openEditModal(id, title, content, priority) {
                      document.getElementById("edit_ann_id").value = id;
                      document.getElementById("edit_ann_title").value = title;
                      document.getElementById("edit_ann_content").value = content;
                      document.getElementById("edit_ann_priority").value = priority;
                      document.getElementById("editModal").style.display = "flex";
                    }

                    function closeEditModal() {
                      document.getElementById("editModal").style.display = "none";
                    }

                    document.querySelectorAll(".btn-edit-ann").forEach(function (button) {
                      button.addEventListener("click", function () {
                        openEditModal(
                          button.dataset.announcementId,
                          button.dataset.announcementTitle,
                          button.dataset.announcementContent,
                          button.dataset.announcementPriority,
                        );
                      });
                    });

                    function openEditTaskModal(batchId, title, description, priority) {
                      document.getElementById("edit_task_batch_id").value = batchId;
                      document.getElementById("edit_task_title").value = title;
                      document.getElementById("edit_task_description").value = description;
                      document.getElementById("edit_task_priority").value = priority;
                      document.getElementById("editTaskModal").style.display = "flex";
                    }

                    function closeEditTaskModal() {
                      document.getElementById("editTaskModal").style.display = "none";
                    }

                    document.querySelectorAll(".btn-edit-task").forEach(function (button) {
                      button.addEventListener("click", function () {
                        openEditTaskModal(
                          button.dataset.taskBatchId,
                          button.dataset.taskTitle,
                          button.dataset.taskDescription,
                          button.dataset.taskPriority,
                        );
                      });
                    });

                    // Close modal when clicking outside
                    document.getElementById("editModal").addEventListener("click", function (e) {
                      if (e.target === this) {
                        closeEditModal();
                      }
                    });
                    document.getElementById("editTaskModal").addEventListener("click", function (e) {
                      if (e.target === this) {
                        closeEditTaskModal();
                      }
                    });
                </script>

                <style>
                    .tasks-management,
                    .announcements-management {
                      background: var(--eco-surface-soft);
                      border-radius: 12px;
                      padding: 20px;
                      border: 1px solid var(--eco-border);
                      color: var(--eco-text);
                    }
                    .announcements-heading {
                      color: var(--eco-text) !important;
                      font-size: 16px;
                      margin-bottom: 15px;
                    }
                    .announcements-empty {
                      color: var(--eco-text-soft);
                      padding: 20px;
                      text-align: center;
                    }
                    .announcements-list,
                    .posted-tasks-list {
                      display: flex;
                      flex-direction: column;
                      gap: 12px;
                    }
                    .announcement-item,
                    .posted-task-item {
                      display: flex;
                      gap: 12px;
                      padding: 15px;
                      background: var(--eco-surface);
                      border-radius: 10px;
                      border: 1px solid var(--eco-border);
                      align-items: center;
                      box-shadow: 0 4px 12px rgba(11, 24, 18, 0.08);
                    }
                    .ann-priority {
                      width: 4px;
                      min-width: 4px;
                      height: 40px;
                      border-radius: 2px;
                    }
                    .priority-high {
                      background: #d32f2f;
                    }
                    .priority-normal {
                      background: #fbc02d;
                    }
                    .priority-low {
                      background: #388e3c;
                    }
                    .ann-content,
                    .posted-task-content {
                      flex: 1;
                    }
                    .ann-title {
                      font-weight: 600;
                      color: var(--eco-text) !important;
                      margin-bottom: 5px;
                    }
                    .ann-desc {
                      color: var(--eco-text-soft);
                      font-size: 13px;
                      margin-bottom: 5px;
                    }
                    .ann-meta {
                      color: var(--eco-muted);
                      font-size: 11px;
                    }
                    .ann-actions,
                    .task-management-actions {
                      display: flex;
                      gap: 8px;
                    }
                    .btn-edit-ann,
                    .btn-delete-ann,
                    .btn-edit-task,
                    .btn-cancel-task {
                      padding: 6px 12px;
                      border: none;
                      border-radius: 6px;
                      font-size: 12px;
                      cursor: pointer;
                      transition: all 0.3s;
                    }
                    .btn-edit-ann {
                      background: rgba(37, 111, 168, 0.14);
                      border: 1px solid rgba(37, 111, 168, 0.25);
                      color: var(--eco-blue);
                    }
                    .btn-edit-ann:hover {
                      background: rgba(37, 111, 168, 0.24);
                    }
                    .btn-edit-task {
                      background: rgba(37, 111, 168, 0.14);
                      border: 1px solid rgba(37, 111, 168, 0.25);
                      color: var(--eco-blue);
                    }
                    .btn-edit-task:hover:not(:disabled) {
                      background: rgba(37, 111, 168, 0.24);
                    }
                    .btn-delete-ann,
                    .btn-cancel-task {
                      background: rgba(184, 66, 59, 0.12);
                      border: 1px solid rgba(184, 66, 59, 0.22);
                      color: var(--eco-danger);
                    }
                    .btn-delete-ann:hover,
                    .btn-cancel-task:hover:not(:disabled) {
                      background: rgba(184, 66, 59, 0.22);
                    }
                    .btn-edit-task:disabled,
                    .btn-cancel-task:disabled {
                      cursor: not-allowed;
                      opacity: 0.56;
                    }
                    .task-assignment-counts {
                      display: flex;
                      flex-wrap: wrap;
                      gap: 6px;
                      margin: 8px 0;
                    }
                    .task-assignment-count {
                      display: inline-flex;
                      padding: 3px 7px;
                      border-radius: 999px;
                      font-size: 11px;
                      font-weight: 700;
                    }
                    .task-assignment-count--pending {
                      background: var(--eco-warning-surface);
                      color: var(--eco-warning-text);
                    }
                    .task-assignment-count--completed {
                      background: var(--eco-success-surface);
                      color: var(--eco-success-text);
                    }
                    .task-assignment-count--cancelled {
                      background: var(--eco-danger-surface);
                      color: var(--eco-danger-text);
                    }
                    .edit-modal {
                      display: none;
                      position: fixed;
                      z-index: 1000;
                      inset: 0;
                      align-items: center;
                      justify-content: center;
                      background: var(--modal-overlay);
                      padding: 20px;
                    }
                    .edit-modal-content {
                      width: min(500px, 100%);
                      max-height: 80vh;
                      overflow-y: auto;
                      padding: 25px;
                      border: 1px solid var(--eco-border);
                      border-radius: 15px;
                      background: var(--eco-surface);
                      box-shadow: var(--eco-shadow);
                    }
                    .edit-modal-content h3 {
                      color: var(--eco-text) !important;
                      margin-bottom: 20px;
                    }
                    .edit-modal-note {
                      margin: -10px 0 20px;
                      color: var(--eco-text-soft);
                      font-size: 13px;
                    }
                    .edit-form-field {
                      margin-bottom: 15px;
                    }
                    .edit-form-field label {
                      display: block;
                      margin-bottom: 5px;
                      color: var(--eco-text-soft);
                      font-weight: 600;
                    }
                    .edit-form-field input,
                    .edit-form-field textarea,
                    .edit-form-field select {
                      width: 100%;
                      padding: 10px;
                      border: 1px solid var(--input-border);
                      border-radius: 8px;
                      background: var(--input-bg);
                      color: var(--eco-text);
                    }
                    .edit-form-field textarea {
                      resize: vertical;
                    }
                    .edit-form-actions {
                      display: flex;
                      justify-content: flex-end;
                      gap: 10px;
                      margin-top: 20px;
                    }
                    .btn-modal-cancel,
                    .btn-modal-save {
                      padding: 10px 20px;
                      border-radius: 8px;
                      cursor: pointer;
                      font-weight: 600;
                    }
                    .btn-modal-cancel {
                      border: 1px solid var(--eco-border);
                      background: var(--eco-surface-soft);
                      color: var(--eco-text);
                    }
                    .btn-modal-save {
                      border: none;
                      background: linear-gradient(135deg, #42a5f5 0%, #1976d2 100%);
                      color: #fff;
                    }
                    @media (max-width: 768px) {
                      .announcement-item,
                      .posted-task-item {
                        flex-direction: column;
                        align-items: flex-start;
                      }
                      .ann-actions,
                      .task-management-actions {
                        width: 100%;
                        justify-content: flex-end;
                      }
                    }
                </style>
            </div>

            <style>
                .management-panel {
                  background: var(--eco-surface);
                  border-radius: 15px;
                  padding: 25px;
                  margin-top: 25px;
                  border: 1px solid var(--eco-border);
                  box-shadow: var(--eco-shadow);
                }
                .management-title {
                  color: var(--eco-text) !important;
                  font-size: 18px;
                  margin-bottom: 20px;
                }
                .panel-grid {
                  display: grid;
                  grid-template-columns: 1fr 1fr;
                  gap: 25px;
                }
                .panel-card {
                  background: var(--eco-surface-soft);
                  border-radius: 12px;
                  padding: 20px;
                  border: 1px solid var(--eco-border);
                }
                .panel-card h3 {
                  color: var(--eco-text) !important;
                  font-size: 16px;
                  margin-bottom: 10px;
                }
                .panel-card p {
                  color: var(--eco-text-soft);
                  font-size: 13px;
                  margin-bottom: 15px;
                }
                .quick-form {
                  display: flex;
                  flex-direction: column;
                  gap: 10px;
                }
                .quick-form input,
                .quick-form textarea,
                .quick-form select {
                  padding: 10px 15px;
                  border: 1px solid var(--input-border);
                  border-radius: 8px;
                  font-size: 14px;
                  background: var(--input-bg);
                  color: var(--eco-text);
                }
                .quick-form input::placeholder,
                .quick-form textarea::placeholder {
                  color: var(--eco-muted);
                }
                .quick-form button {
                  padding: 12px;
                  border: none;
                  border-radius: 8px;
                  font-size: 14px;
                  font-weight: 600;
                  cursor: pointer;
                  transition: all 0.3s;
                }
                .btn-green {
                  background: linear-gradient(135deg, #8bc34a 0%, #689f38 100%);
                  color: white;
                }
                .btn-blue {
                  background: linear-gradient(135deg, #42a5f5 0%, #1976d2 100%);
                  color: white;
                }
                [data-theme="dark"] .management-panel .quick-form input,
                [data-theme="dark"] .management-panel .quick-form textarea,
                [data-theme="dark"] .management-panel .quick-form select {
                  background: #111d18 !important;
                  border-color: #496055 !important;
                }
                [data-theme="dark"] .management-panel .btn-green {
                  background: linear-gradient(135deg, #278d72 0%, #146451 100%);
                }
                [data-theme="dark"] .management-panel .btn-blue,
                [data-theme="dark"] .management-panel .btn-modal-save {
                  background: linear-gradient(135deg, #287eb8 0%, #175f95);
                }
                @media (max-width: 768px) {
                  .panel-grid {
                    grid-template-columns: 1fr;
                  }
                }
            </style>
        </main>
    </div>

    <!-- Reusable in-dashboard panel for all KPI and chart explanations. -->
    <div id="chartExplanationPanel" class="explanation-overlay" aria-hidden="true">
        <aside class="explanation-panel" role="dialog" aria-modal="true" aria-labelledby="explanationTitle">
            <div class="explanation-panel-header">
                <div>
                    <h2 id="explanationTitle">Chart explanation</h2>
                    <label class="explanation-language-label" for="explanationLanguage">Insight language</label>
                    <select id="explanationLanguage" class="explanation-language-select" aria-label="Choose insight language">
                        <option value="en">English</option>
                        <option value="tl">Tagalog</option>
                    </select>
                </div>
                <button type="button" class="explanation-close" aria-label="Close explanation">&times;</button>
            </div>
            <div class="explanation-panel-body">
                <div id="explanationLoading" class="explanation-loading" hidden><span></span>Reading the current dashboard data…</div>
                <p id="explanationText" class="explanation-text" aria-live="polite"></p>
                <p id="explanationError" class="explanation-error" role="alert" hidden></p>
            </div>
        </aside>
    </div>

    <?php include 'includes/logout_confirmation_modal.php'; ?>

    <style>
        .kpi-card-explainable {
          position: relative;
        }
        /* Keep the title in the normal document flow. The former absolute
                           controls left too little usable width and obscured long KPI names. */
        .kpi-card-explainable .kpi-label {
          min-height: 0;
          padding-right: 0;
          line-height: 1.4;
          overflow-wrap: normal;
          word-break: normal;
        }
        .kpi-card-tools {
          display: flex;
          width: 100%;
          align-items: center;
          justify-content: flex-end;
          gap: 6px;
          flex-wrap: wrap;
          margin: 0 0 10px;
        }
        .viz-card-header {
          display: flex;
          align-items: flex-start;
          justify-content: space-between;
          gap: 12px;
        }
        .viz-card-header h2 {
          margin: 0;
        }
        .viz-card-header-actions {
          display: inline-flex;
          align-items: center;
          justify-content: flex-end;
          gap: 8px;
          flex-wrap: wrap;
        }
        .visualization-year-control {
          margin: 0;
        }
        .visualization-year-control select {
          width: 120px;
          min-height: 36px;
          max-width: none;
          padding: 5px 28px 5px 9px;
          border: 1px solid var(--eco-border);
          border-radius: 999px;
          background: var(--eco-surface);
          color: var(--eco-text);
          font:
            600 11px/1 "Segoe UI",
            Tahoma,
            Geneva,
            Verdana,
            sans-serif;
          cursor: pointer;
        }
        .monthly-period-control {
          display: block;
          width: 100%;
        }
        .monthly-period-control .year-picker-trigger {
          width: 100%;
          min-height: 36px;
          padding: 5px 9px;
          border: 1px solid var(--eco-border);
          border-radius: 999px;
          background: var(--eco-surface);
          color: var(--eco-text);
          font:
            600 11px/1 "Segoe UI",
            Tahoma,
            Geneva,
            Verdana,
            sans-serif;
        }
        .monthly-period-control .year-picker-trigger {
          text-align: left;
          cursor: pointer;
        }
        .area-period-control {
          display: inline-flex;
          flex-wrap: wrap;
          gap: 8px;
        }
        .chart-subtitle { margin: 5px 0 0; color: var(--eco-muted); font-size: 12px; }
        .street-chart-legend { display: flex; flex-wrap: wrap; gap: 10px 14px; margin: 12px 0; color: var(--eco-text-soft); font-size: 12px; }
        .street-chart-legend span { display: inline-flex; align-items: center; gap: 6px; }.street-chart-legend i { width: 11px; height: 11px; border-radius: 50%; background: var(--category-color); }
        .street-chart-wrap { display: grid; grid-template-columns: 22px minmax(560px, 1fr); grid-template-rows: minmax(300px, 1fr) 24px; gap: 4px; overflow-x: auto; padding: 10px 4px; outline: none; }.street-chart-wrap:focus-visible { outline: 2px solid var(--eco-primary); outline-offset: 2px; }
        .street-chart-axis { color: var(--eco-text-soft); font-size: 11px; font-weight: 700; }.street-chart-axis-y { writing-mode: vertical-rl; transform: rotate(180deg); text-align: center; }.street-chart-axis-x { grid-column: 2; text-align: center; }
        .street-chart-plot { position: relative; min-height: 300px; padding-left: 40px; border-left: 1px solid var(--eco-border); border-bottom: 1px solid var(--eco-border); background: repeating-linear-gradient(to top, transparent 0, transparent calc(50% - 1px), var(--eco-border) 50%, transparent calc(50% + 1px)); }
        .street-chart-grid { position: absolute; inset: 0 auto 0 5px; display: flex; flex-direction: column; justify-content: space-between; padding: 2px 0; color: var(--eco-text-soft); font-size: 10px; }.street-chart-bars { position: absolute; inset: 0 0 0 42px; display: flex; align-items: flex-end; gap: 14px; min-width: max-content; padding: 16px 8px 0; }
        .street-chart-column { width: 118px; height: 100%; display: grid; grid-template-rows: 18px minmax(0,1fr) 42px; align-items: end; text-align: center; }.street-chart-value { font-size: 10px; color: var(--eco-text-soft); white-space: nowrap; }.street-chart-bar { width: 54px; height: var(--bar-height); min-height: 2px; align-self: end; justify-self: center; display: flex; flex-direction: column-reverse; overflow: hidden; border-radius: 6px 6px 0 0; background: var(--eco-border); cursor: help; }.street-chart-bar:focus-visible { outline: 2px solid var(--eco-primary); outline-offset: 3px; }.street-chart-bar span { display: block; width: 100%; height: var(--segment-share); background: var(--category-color); }
        .street-chart-x-label { display: grid; gap: 2px; padding-top: 5px; overflow-wrap: anywhere; font-size: 11px; line-height: 1.15; }.street-chart-x-label small { color: var(--eco-text-soft); }.street-chart-table { margin-top: 12px; color: var(--eco-text-soft); font-size: 12px; }.street-chart-table summary { cursor: pointer; font-weight: 700; }.table-scroll { overflow: auto; }.street-chart-table table { width: 100%; border-collapse: collapse; margin-top: 10px; }.street-chart-table th,.street-chart-table td { padding: 7px; border-bottom: 1px solid var(--eco-border); text-align: left; white-space: nowrap; }
        /* Waste Collection per Street: one compact Phase/category/month range row. */
        section[data-visualization-key="area"] { container: street-chart-card / inline-size; }
        .street-chart-filters { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)) auto; gap: 10px; align-items: end; margin: 14px 0 8px; padding: 12px; border-radius: 12px; background: color-mix(in srgb, var(--eco-surface-soft) 88%, #e4f4ec); }
        .street-filter-field { display: grid; min-width: 0; gap: 6px; }
        .street-filter-field[hidden] { display: none !important; }
        .street-filter-field label, .street-filter-label { display: block; color: var(--eco-text-soft); font-size: 12px; font-weight: 700; }
        .street-filter-field select { width: 100%; min-width: 0; min-height: 40px; padding: 8px 10px; border: 1px solid var(--eco-border); border-radius: 8px; background: var(--eco-surface); color: var(--eco-text); font: inherit; }
        .street-filter-actions { display: flex; align-items: end; gap: 8px; }.street-filter-actions .btn { display: inline-flex; min-width: 84px; min-height: 40px; align-items: center; justify-content: center; padding-inline: 14px; white-space: nowrap; }
        .street-filter-status { min-height: 20px; margin: 0 2px 12px; color: var(--eco-text-soft); font-size: 12px; }.street-filter-status.is-error { color: var(--eco-danger); font-weight: 700; }
        .street-summary-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; margin: 16px 0; }.street-summary-grid article { min-width: 0; padding: 13px; border: 1px solid var(--eco-border); border-radius: 11px; background: var(--eco-surface-soft); box-shadow: 0 3px 10px rgba(11, 45, 35, 0.05); }.street-summary-grid span, .street-summary-grid small { display: block; color: var(--eco-text-soft); font-size: 11px; line-height: 1.35; }.street-summary-grid strong { display: block; margin: 5px 0; overflow: hidden; color: var(--eco-text); font-size: 17px; text-overflow: ellipsis; white-space: nowrap; }
        .street-chart-modern-legend { margin: 14px 0 10px; }
        .street-horizontal-chart { margin-top: 8px; }
        .street-chart-rows { display: grid; gap: 11px; }.street-chart-row { display: grid; grid-template-columns: 170px minmax(0, 1fr) 78px auto; align-items: center; gap: 10px; }.street-chart-row-label { display: grid; min-width: 0; gap: 2px; color: inherit; text-decoration: none; }.street-chart-row-label strong, .street-chart-row-label small { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }.street-chart-row-label strong { font-size: 12px; }.street-chart-row-label small { color: var(--eco-text-soft); font-size: 11px; }button.street-chart-row-label, a.street-chart-row-label { width: 100%; padding: 0; border: 0; background: transparent; color: inherit; text-align: left; font: inherit; cursor: pointer; }.street-chart-details-button { display: inline-flex; width: auto !important; min-height: 30px; align-items: center; justify-content: center; padding: 5px 9px; border: 1px solid var(--eco-border); border-radius: 7px; background: var(--eco-surface-soft); color: var(--eco-primary-strong); font: 700 11px/1 "Segoe UI", Tahoma, Geneva, Verdana, sans-serif; text-decoration: none; white-space: nowrap; cursor: pointer; }.street-chart-details-button:hover { background: color-mix(in srgb, var(--eco-primary) 12%, var(--eco-surface-soft)); }.street-chart-details-button:focus-visible { outline: 2px solid var(--eco-primary); outline-offset: 3px; }
        .street-chart-track { display: flex; min-width: 0; height: 30px; align-items: center; border-radius: 7px; background-image: linear-gradient(to right, color-mix(in srgb, var(--eco-border) 75%, transparent) 1px, transparent 1px); background-size: 25% 100%; }.street-chart-bar { display: flex; flex-direction: row; width: var(--bar-width); max-width: 100%; height: 22px; min-height: 0; margin: 0; padding: 0; overflow: hidden; border: 0; border-radius: 6px; background: var(--eco-border); cursor: pointer; transition: width 260ms ease, box-shadow 160ms ease, filter 160ms ease; }.street-chart-bar:focus-visible { outline: 2px solid var(--eco-primary); outline-offset: 3px; }.street-chart-bar span { display: block; width: var(--segment-share); height: 100%; flex: 0 0 var(--segment-share); background: var(--category-color); }.street-chart-row-value { color: var(--eco-text-soft); font-size: 11px; text-align: right; white-space: nowrap; }
        .street-chart-detail-trigger:hover { filter: brightness(1.04); }.street-chart-bar.street-chart-detail-trigger:hover { box-shadow: 0 3px 8px rgba(11, 45, 35, 0.18); }.street-chart-row-label.street-chart-detail-trigger:hover strong { color: var(--eco-primary-strong); text-decoration: underline; text-underline-offset: 2px; }.street-chart-row-label.street-chart-detail-trigger:focus-visible { outline: 2px solid var(--eco-primary); outline-offset: 3px; border-radius: 3px; }
        .street-chart-tooltip { position: fixed; z-index: 50; max-width: min(320px, calc(100vw - 24px)); padding: 9px 11px; border-radius: 8px; background: #163629; color: #fff; box-shadow: 0 8px 24px rgba(11, 45, 35, 0.25); font-size: 12px; line-height: 1.45; white-space: pre-line; pointer-events: none; }
        .street-detail-view { display: grid; gap: 16px; padding-top: 14px; }.street-detail-view[hidden] { display: none !important; }.street-detail-back { justify-self: start; }.street-detail-view h3 { margin: 0; color: var(--eco-text); font-size: 20px; }.street-detail-selection { display: flex; flex-wrap: wrap; gap: 6px; margin: 7px 0 0; color: var(--eco-text-soft); font-size: 13px; }.street-detail-selection strong { color: var(--eco-text); }.street-detail-total { display: grid; gap: 5px; padding: 17px; border: 1px solid var(--eco-border); border-radius: 12px; background: var(--eco-surface-soft); }.street-detail-total span { color: var(--eco-text-soft); font-size: 12px; font-weight: 700; }.street-detail-total strong { color: var(--eco-text); font-size: 25px; }.street-detail-breakdown { display: grid; gap: 13px; }.street-detail-breakdown h4 { margin: 0; color: var(--eco-text); font-size: 15px; }.street-detail-stack { display: flex; min-height: 64px; overflow: hidden; border-radius: 8px; background: var(--eco-border); }.street-detail-stack:empty { display: none; }.street-detail-stack span { flex: 0 0 var(--detail-share); min-width: 0; background: var(--category-color); }.street-detail-category-list { display: grid; gap: 8px; }.street-detail-category { display: grid; grid-template-columns: minmax(0, 1fr) auto; column-gap: 12px; align-items: center; padding: 9px 0; border-bottom: 1px solid var(--eco-border); }.street-detail-category-label { display: inline-flex; min-width: 0; align-items: center; gap: 8px; color: var(--eco-text); font-size: 13px; font-weight: 700; }.street-detail-category-label i { width: 11px; height: 11px; flex: 0 0 11px; border-radius: 50%; background: var(--category-color); }.street-detail-category-value { color: var(--eco-text); font-size: 13px; text-align: right; }.street-detail-category-percent { grid-column: 2; color: var(--eco-text-soft); font-size: 12px; text-align: right; }
        @media (prefers-reduced-motion: reduce) { .street-chart-bar { transition: none; } }
        @container street-chart-card (max-width: 760px) { .street-chart-filters { grid-template-columns: repeat(3, minmax(0, 1fr)); }.street-filter-actions { grid-column: 1 / -1; justify-content: flex-end; } }
        @container street-chart-card (max-width: 480px) { .street-chart-filters { grid-template-columns: 1fr; }.street-filter-actions { grid-column: auto; width: 100%; }.street-filter-actions .btn { flex: 1 1 0; } }
        @media (max-width: 920px) { .street-chart-filters { grid-template-columns: repeat(3, minmax(0, 1fr)); }.street-filter-actions { grid-column: 1 / -1; justify-content: flex-end; }.street-summary-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 600px) { .street-chart-filters { grid-template-columns: 1fr; }.street-filter-actions { grid-column: auto; width: 100%; }.street-filter-actions .btn { flex: 1 1 0; }.street-chart-row { grid-template-columns: minmax(0, 1fr) 64px; gap: 6px; }.street-chart-row-label { grid-column: 1 / -1; }.street-chart-track { grid-column: 1; }.street-chart-row-value { grid-column: 2; }.street-chart-details-button { grid-column: 1 / -1; justify-self: start; }.street-summary-grid { grid-template-columns: 1fr; }.street-detail-category { grid-template-columns: minmax(0, 1fr) auto; }.street-detail-total strong { font-size: 22px; } }
        .attendance-dashboard-summary { display: grid; grid-template-columns: repeat(4, minmax(0,1fr)); gap: 10px; margin-top: 18px; }.attendance-dashboard-summary div { padding: 12px; border: 1px solid var(--eco-border); border-radius: 9px; background: var(--eco-surface-soft); }.attendance-dashboard-summary strong { display: block; font-size: 22px; }.attendance-dashboard-summary span { font-size: 12px; font-weight: 700; }.attendance-status-present{color:#166534}.attendance-status-absent{color:#991b1b}.attendance-status-late{color:#92400e}.attendance-status-on_leave{color:#3730a3}
        @media (max-width: 700px) { .street-chart-filters select { width: 100%; max-width: none; }.attendance-dashboard-summary { grid-template-columns: repeat(2,1fr); } .street-chart-wrap { grid-template-columns: 18px minmax(500px,1fr); } }
        .sr-only {
          position: absolute;
          width: 1px;
          height: 1px;
          padding: 0;
          margin: -1px;
          overflow: hidden;
          clip: rect(0, 0, 0, 0);
          white-space: nowrap;
          border: 0;
        }
        .explain-button {
          display: inline-flex;
          align-items: center;
          gap: 5px;
          flex: 0 0 auto;
          border: 1px solid var(--eco-border);
          border-radius: 999px;
          padding: 5px 9px;
          color: var(--eco-primary-strong);
          background: var(--eco-surface-soft);
          cursor: pointer;
          font:
            600 11px/1 "Segoe UI",
            Tahoma,
            Geneva,
            Verdana,
            sans-serif;
        }
        .explain-button:hover,
        .explain-button:focus-visible {
          background: var(--eco-primary-soft);
          outline: 2px solid var(--eco-primary);
          outline-offset: 2px;
        }
        .explain-button-kpi {
          position: static;
        }
        .explanation-overlay {
          position: fixed;
          z-index: 1100;
          inset: 0;
          display: flex;
          justify-content: flex-end;
          background: rgba(11, 24, 18, 0.38);
          opacity: 0;
          visibility: hidden;
          transition:
            opacity 0.2s ease,
            visibility 0.2s ease;
        }
        .explanation-overlay.is-open {
          opacity: 1;
          visibility: visible;
        }
        .explanation-panel {
          width: min(440px, 100%);
          height: 100%;
          background: var(--eco-surface);
          color: var(--eco-text);
          border-left: 1px solid var(--eco-border);
          box-shadow: -12px 0 32px rgba(11, 45, 35, 0.18);
          transform: translateX(100%);
          transition: transform 0.24s ease;
        }
        .explanation-overlay.is-open .explanation-panel {
          transform: translateX(0);
        }
        .explanation-panel-header {
          display: flex;
          align-items: flex-start;
          justify-content: space-between;
          gap: 16px;
          padding: 25px;
          border-bottom: 1px solid var(--eco-border);
        }
        .explanation-panel-header h2 {
          margin: 0;
          font-size: 21px;
        }
        .explanation-language-label {
          display: block;
          margin-top: 13px;
          color: var(--eco-muted);
          font-size: 12px;
          font-weight: 600;
        }
        .explanation-language-select {
          margin-top: 5px;
          min-width: 132px;
          padding: 7px 28px 7px 9px;
          border: 1px solid var(--eco-border);
          border-radius: 8px;
          background: var(--eco-surface-soft);
          color: var(--eco-text);
          font: inherit;
          font-size: 13px;
          cursor: pointer;
        }
        .explanation-close {
          width: 34px;
          height: 34px;
          border: 0;
          border-radius: 50%;
          background: var(--eco-surface-soft);
          color: var(--eco-text);
          cursor: pointer;
          font-size: 27px;
          line-height: 1;
        }
        .explanation-panel-body {
          padding: 25px;
        }
        .explanation-text {
          color: var(--eco-text-soft);
          font-size: 15px;
          line-height: 1.7;
          white-space: pre-line;
        }
        .explanation-loading {
          display: flex;
          align-items: center;
          gap: 10px;
          color: var(--eco-text-soft);
          font-size: 14px;
        }
        .explanation-loading[hidden],
        .explanation-error[hidden] {
          display: none !important;
        }
        .explanation-loading span {
          width: 17px;
          height: 17px;
          border: 2px solid var(--eco-primary-soft);
          border-top-color: var(--eco-primary);
          border-radius: 50%;
          animation: explanationSpin 0.8s linear infinite;
        }
        .explanation-error {
          color: var(--eco-danger);
          font-size: 14px;
          line-height: 1.55;
        }
        .comparison-detail {
          color: var(--eco-text-soft);
          font-size: 12px;
          line-height: 1.45;
        }
        @keyframes explanationSpin {
          to {
            transform: rotate(360deg);
          }
        }
        @media (max-width: 520px) {
          .explain-button span {
            display: none;
          }
          .explain-button {
            padding: 7px;
          }
          .explanation-panel {
            width: min(400px, 92vw);
          }
        }

        /* Modal Styles */
        .modal {
          display: none;
          position: fixed;
          z-index: 1000;
          left: 0;
          top: 0;
          width: 100%;
          height: 100%;
          background-color: rgba(0, 0, 0, 0.5);
          align-items: center;
          justify-content: center;
        }
        .modal.show {
          display: flex;
        }
        .modal-content {
          background: white;
          border-radius: 15px;
          width: 400px;
          box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
          animation: modalSlideIn 0.3s ease;
        }
        @keyframes modalSlideIn {
          from {
            transform: translateY(-50px);
            opacity: 0;
          }
          to {
            transform: translateY(0);
            opacity: 1;
          }
        }
        .modal-header {
          background: linear-gradient(135deg, #8bc34a 0%, #689f38 100%);
          color: white;
          padding: 20px;
          border-radius: 15px 15px 0 0;
          text-align: center;
        }
        .modal-icon {
          font-size: 40px;
          display: block;
          margin-bottom: 10px;
        }
        .modal-header h3 {
          margin: 0;
          font-size: 18px;
          font-weight: 600;
        }
        .modal-body {
          padding: 30px;
          text-align: center;
        }
        .modal-body p {
          margin: 0;
          font-size: 16px;
          color: #666;
        }
        .modal-footer {
          padding: 20px 30px 30px;
          display: flex;
          gap: 15px;
          justify-content: center;
        }
        .btn {
          padding: 12px 30px;
          border: none;
          border-radius: 25px;
          cursor: pointer;
          font-size: 14px;
          font-weight: 600;
          text-transform: uppercase;
          text-decoration: none;
          transition: all 0.3s;
          display: inline-flex;
          align-items: center;
          justify-content: center;
        }
        .street-detail-back {
          min-height: 40px;
          padding: 9px 14px;
          border: 1px solid var(--eco-border);
          border-radius: 8px;
          background: var(--eco-surface-soft);
          color: var(--eco-primary-strong);
          box-shadow: 0 1px 2px rgba(11, 79, 67, 0.08);
          font-weight: 700;
          text-decoration: none;
          text-transform: none;
          transition: background-color 0.18s ease, border-color 0.18s ease, box-shadow 0.18s ease, transform 0.18s ease;
        }
        .street-detail-back:hover {
          border-color: var(--eco-primary);
          background: color-mix(in srgb, var(--eco-primary) 12%, var(--eco-surface));
          color: var(--eco-primary-strong);
          box-shadow: 0 4px 10px rgba(11, 79, 67, 0.13);
          transform: translateX(-2px);
        }
        .street-detail-back:focus-visible {
          outline: 3px solid color-mix(in srgb, var(--eco-primary) 35%, transparent);
          outline-offset: 3px;
        }
        .btn-gray {
          background: #9e9e9e;
          color: white;
        }
        .btn-gray:hover {
          background: #757575;
        }
        .btn-green {
          background: #8bc34a;
          color: white;
        }
        .btn-green:hover {
          background: #7cb342;
        }
    </style>

    <script>
        let dashboardExplanationData = <?php echo json_encode($dashboardExplanationData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const dashboardExplanationCsrf = "<?php echo $_SESSION['dashboard_explanation_csrf']; ?>";
        const explanationCache = new Map();
        const explanationPanel = document.getElementById("chartExplanationPanel");
        const explanationTitle = document.getElementById("explanationTitle");
        const explanationText = document.getElementById("explanationText");
        const explanationLoading = document.getElementById("explanationLoading");
        const explanationError = document.getElementById("explanationError");
        const explanationLanguage = document.getElementById("explanationLanguage");
        let explanationTrigger = null;
        let activeExplanationId = null;
        explanationLanguage.value = localStorage.getItem("ecotrackInsightLanguage") === "tl" ? "tl" : "en";

        function closeChartExplanation() {
          explanationPanel.classList.remove("is-open");
          explanationPanel.setAttribute("aria-hidden", "true");
          if (explanationTrigger) explanationTrigger.focus();
        }

        async function openChartExplanation(id, trigger) {
          const item = dashboardExplanationData[id];
          if (!item) return;
          explanationTrigger = trigger;
          activeExplanationId = id;
          explanationTitle.textContent = item.title;
          explanationText.textContent = "";
          explanationError.hidden = true;
          explanationLoading.hidden = false;
          explanationPanel.classList.add("is-open");
          explanationPanel.setAttribute("aria-hidden", "false");
          explanationPanel.querySelector(".explanation-close").focus();

          await loadChartExplanation(id);
        }

        async function loadChartExplanation(id) {
          const item = dashboardExplanationData[id];
          if (!item) return;
          const language = explanationLanguage.value;
          const cacheKey = language + ":" + id;
          explanationText.textContent = "";
          explanationError.hidden = true;
          explanationLoading.hidden = false;
          try {
            if (!explanationCache.has(cacheKey)) {
              const response = await fetch("dashboard_explanation.php", {
                method: "POST",
                headers: { "Content-Type": "application/json", "X-CSRF-Token": dashboardExplanationCsrf },
                body: JSON.stringify({ id, title: item.title, language, data: item.data }),
              });
              const result = await response.json();
              if (!response.ok || !result.explanation)
                throw new Error(result.error || "The explanation could not be generated.");
              explanationCache.set(cacheKey, result.explanation);
            }
            if (activeExplanationId === id) explanationText.textContent = explanationCache.get(cacheKey);
          } catch (error) {
            explanationError.textContent = error.message || "The explanation could not be generated. Please try again.";
            explanationError.hidden = false;
          } finally {
            explanationLoading.hidden = true;
          }
        }

        function initializeStreetFilters(root) {
          const form = root.querySelector("[data-street-chart-filter]");
          const phaseSelect = form && form.querySelector("#street-phase");
          const categorySelect = form && form.querySelector("#street-category");
          const periodSelect = form && form.querySelector("[data-street-period-mode]");
          const monthInput = form && form.querySelector("#street-month");
          const rangeStartMonth = form && form.querySelector("#street-range-start-month");
          const rangeEndMonth = form && form.querySelector("#street-range-end-month");
          const customStart = form && form.querySelector("#street-custom-start");
          const customEnd = form && form.querySelector("#street-custom-end");
          const status = form && form.parentElement.querySelector("#street-filter-status");
          if (!form || !phaseSelect || !categorySelect || !periodSelect) return;

          if (window.EcoTrackYearPicker) window.EcoTrackYearPicker.mountAll(form);
          const showPeriodFields = () => {
            const mode = periodSelect.value;
            form.querySelectorAll("[data-street-period-field]").forEach((field) => {
              const visible = field.dataset.streetPeriodField === mode;
              field.hidden = !visible;
              field.querySelectorAll("input, select").forEach((control) => { control.disabled = !visible; });
            });
          };
          showPeriodFields();
          let stateRequest = 0;
          let stateTimer = 0;
          const requestCalendarState = async (requestId) => {
            const endpoint = form.dataset.streetFilterStateUrl;
            if (!endpoint) return;
            const requestUrl = new URL(endpoint, window.location.href);
            requestUrl.searchParams.set("street_phase", phaseSelect.value);
            requestUrl.searchParams.set("street_category", categorySelect.value);
            try {
              const response = await fetch(requestUrl.toString(), { cache: "no-store", credentials: "same-origin" });
              const state = await response.json();
              if (requestId !== stateRequest || !form.isConnected) return;
              if (!response.ok || !state.calendar) throw new Error("Calendar state unavailable");
              if (state.filters && state.filters.phase !== phaseSelect.value) phaseSelect.value = state.filters.phase || "";
              if (window.EcoTrackYearPicker) {
                [monthInput, rangeStartMonth, rangeEndMonth, customStart, customEnd].forEach((input) => {
                  if (input) window.EcoTrackYearPicker.setAvailability(input, state.calendar);
                });
              }
              const periodIsAvailable = (value) => {
                const match = /^(\d{4})-(\d{2})/.exec(value || "");
                if (!match) return false;
                const months = state.calendar.months_by_year && state.calendar.months_by_year[match[1]];
                return Array.isArray(months) && months.map(Number).includes(Number(match[2]));
              };
              let cleared = false;
              [monthInput, rangeStartMonth, rangeEndMonth, customStart, customEnd].forEach((input) => {
                if (!input || !input.value || periodIsAvailable(input.value)) return;
                input.value = "";
                if (window.EcoTrackYearPicker) window.EcoTrackYearPicker.sync(input);
                cleared = true;
              });
              if (cleared && status) {
                status.classList.remove("is-error");
                status.textContent = "Unavailable month or date selections were cleared for the current filters.";
              }
            } catch (error) {
              if (requestId === stateRequest && form.isConnected && status) {
                status.classList.add("is-error");
                status.textContent = "Calendar availability could not be updated. Apply filters to retry.";
              }
            }
          };
          const queueCalendarState = () => {
            window.clearTimeout(stateTimer);
            const requestId = ++stateRequest;
            stateTimer = window.setTimeout(() => requestCalendarState(requestId), 120);
          };
          const cancelCalendarState = () => {
            window.clearTimeout(stateTimer);
            stateRequest++;
          };
          phaseSelect.addEventListener("change", queueCalendarState);
          categorySelect.addEventListener("change", queueCalendarState);
          periodSelect.addEventListener("change", showPeriodFields);
          form.addEventListener("submit", cancelCalendarState);
          const reset = form.querySelector("[data-street-filter-reset]");
          if (reset) reset.addEventListener("click", () => {
            cancelCalendarState();
            phaseSelect.value = "";
            categorySelect.value = "all";
            periodSelect.value = "all";
            [monthInput, rangeStartMonth, rangeEndMonth, customStart, customEnd].forEach((input) => { if (input) { input.value = ""; if (window.EcoTrackYearPicker) window.EcoTrackYearPicker.sync(input); } });
            showPeriodFields();
            refreshVisualization(form, { successMessage: "Street filters reset." });
          });
        }

        function initializeStreetChartTooltips(root) {
          const tooltip = root.querySelector("[data-street-chart-tooltip]");
          if (!tooltip) return;
          const hideTooltip = () => { tooltip.hidden = true; };
          const showTooltip = (target) => {
            tooltip.textContent = target.dataset.streetTooltip || "";
            tooltip.hidden = false;
            const rect = target.getBoundingClientRect();
            tooltip.style.left = Math.min(window.innerWidth - tooltip.offsetWidth - 10, Math.max(10, rect.left)) + "px";
            tooltip.style.top = Math.max(10, rect.top - tooltip.offsetHeight - 8) + "px";
          };
          root.querySelectorAll("[data-street-tooltip]").forEach((bar) => {
            bar.addEventListener("pointerenter", () => showTooltip(bar));
            bar.addEventListener("focus", () => showTooltip(bar));
            bar.addEventListener("pointerleave", hideTooltip);
            bar.addEventListener("blur", hideTooltip);
          });
        }

        function initializeStreetDetails(root) {
          const mainView = root.querySelector("[data-street-main-view]");
          const detailView = root.querySelector("[data-street-detail-view]");
          const dataElement = root.querySelector("[data-street-details-data]");
          const content = detailView && detailView.querySelector("[data-street-detail-content]");
          const empty = detailView && detailView.querySelector("[data-street-detail-empty]");
          const back = detailView && detailView.querySelector("[data-street-detail-back]");
          const title = detailView && detailView.querySelector("#street-detail-title");
          const street = detailView && detailView.querySelector("[data-street-detail-street]");
          const phase = detailView && detailView.querySelector("[data-street-detail-phase]");
          const total = detailView && detailView.querySelector("[data-street-detail-total]");
          const stack = detailView && detailView.querySelector("[data-street-detail-stack]");
          const categoryList = detailView && detailView.querySelector("[data-street-detail-category-list]");
          if (!mainView || !detailView || !dataElement || !content || !empty || !back || !title || !street || !phase || !total || !stack || !categoryList) return;

          let details = {};
          try {
            const parsed = JSON.parse(dataElement.textContent || "{}");
            if (parsed && typeof parsed === "object") details = parsed;
          } catch (error) {
            details = {};
          }

          const formatKg = (value) => {
            const amount = Number(value);
            return (Number.isFinite(amount) ? amount : 0).toLocaleString(undefined, {
              minimumFractionDigits: 2,
              maximumFractionDigits: 2,
            }) + " kg";
          };
          const formatPercent = (value) => {
            const percent = Number(value);
            return (Number.isFinite(percent) ? percent : 0).toLocaleString(undefined, {
              minimumFractionDigits: 1,
              maximumFractionDigits: 1,
            }) + "%";
          };
          const setUnavailable = () => {
            content.hidden = true;
            empty.hidden = false;
            stack.replaceChildren();
            categoryList.replaceChildren();
          };
          const renderDetail = (detail) => {
            const categories = detail && detail.categories && typeof detail.categories === "object"
              ? Object.keys(detail.categories).map((key) => Object.assign({ key }, detail.categories[key] || {}))
              : [];
            if (!detail || !detail.key || !categories.length || Number(detail.total_waste) <= 0) {
              setUnavailable();
              return false;
            }

            content.hidden = false;
            empty.hidden = true;
            street.textContent = detail.street || "Selected street";
            phase.textContent = detail.phase_name || "Unassigned phase";
            total.textContent = formatKg(detail.total_waste);
            stack.replaceChildren();
            categoryList.replaceChildren();

            const accessibleCategories = [];
            categories.forEach((category) => {
              const amount = Number(category.amount) || 0;
              const percent = Math.max(0, Math.min(100, Number(category.percent) || 0));
              const label = category.label || category.key;
              const color = category.color || "#64748B";
              accessibleCategories.push(label + " " + formatKg(amount) + " (" + formatPercent(percent) + ")");

              const segment = document.createElement("span");
              segment.style.setProperty("--detail-share", percent + "%");
              segment.style.setProperty("--category-color", color);
              segment.title = label + ": " + formatKg(amount) + " (" + formatPercent(percent) + ")";
              stack.appendChild(segment);

              const item = document.createElement("div");
              item.className = "street-detail-category";
              const itemLabel = document.createElement("span");
              itemLabel.className = "street-detail-category-label";
              const swatch = document.createElement("i");
              swatch.style.setProperty("--category-color", color);
              const labelText = document.createElement("span");
              labelText.textContent = label;
              itemLabel.append(swatch, labelText);
              const value = document.createElement("strong");
              value.className = "street-detail-category-value";
              value.textContent = formatKg(amount);
              const percentText = document.createElement("small");
              percentText.className = "street-detail-category-percent";
              percentText.textContent = formatPercent(percent);
              item.append(itemLabel, value, percentText);
              categoryList.appendChild(item);
            });
            stack.setAttribute("aria-label", "Waste category breakdown for " + (detail.street || "selected street") + ": " + accessibleCategories.join(", ") + ".");
            return true;
          };

          let returnTrigger = null;
          const openDetail = (trigger) => {
            const detail = details[trigger.dataset.streetDetailKey || ""];
            returnTrigger = trigger;
            const rendered = renderDetail(detail);
            mainView.hidden = true;
            detailView.hidden = false;
            const tooltip = root.querySelector("[data-street-chart-tooltip]");
            if (tooltip) tooltip.hidden = true;
            detailView.scrollIntoView({ block: "start" });
            title.focus();
            return rendered;
          };
          const closeDetail = () => {
            detailView.hidden = true;
            mainView.hidden = false;
            if (returnTrigger && returnTrigger.isConnected) returnTrigger.focus();
          };

          root.querySelectorAll("[data-street-detail-trigger]").forEach((trigger) => {
            trigger.addEventListener("click", (event) => {
              event.preventDefault();
              openDetail(trigger);
            });
          });
          back.addEventListener("click", (event) => {
            event.preventDefault();
            closeDetail();
          });
        }

        function initializeStreetVisualization(root) {
          // Street drill-down is intentionally initialized first so a problem
          // in an optional filter enhancement cannot make chart rows inert.
          initializeStreetDetails(root);
          initializeStreetFilters(root);
          initializeStreetChartTooltips(root);
        }

        function streetFilterSnapshot(values) {
          if (!values) return null;
          return {
            phase: values.get("street_phase") || "",
            category: values.get("street_category") || "all",
            period: values.get("street_period") || "all",
            rangeStart: values.get("street_range_start_month") || "",
            rangeEnd: values.get("street_range_end_month") || "",
            customStart: values.get("street_custom_start") || "",
            customEnd: values.get("street_custom_end") || "",
          };
        }

        function streetFilterAdjustment(before, after) {
          if (!before || !after) return "";
          const changes = [];
          if (before.phase && before.phase !== after.phase) changes.push("phase");
          if (before.category !== after.category) changes.push("waste category");
          if (before.period !== after.period) changes.push("period");
          if (before.rangeStart && before.rangeStart !== after.rangeStart) changes.push("month range start");
          if (before.rangeEnd && before.rangeEnd !== after.rangeEnd) changes.push("month range end");
          if (before.customStart && before.customStart !== after.customStart) changes.push("custom start date");
          if (before.customEnd && before.customEnd !== after.customEnd) changes.push("custom end date");
          return changes.length ? "Unavailable " + changes.join(", ") + " filter" + (changes.length > 1 ? "s were" : " was") + " adjusted." : "";
        }

        async function refreshVisualization(form, refreshOptions = {}) {
          // Capture successful controls before temporarily disabling them. Disabled
          // form controls are intentionally omitted by FormData, which would make
          // Phase and Waste Category silently revert to their all-data values.
          const submittedFormData = new FormData(form);
          const requestUrl = new URL(form.action || window.location.href, window.location.href);
          requestUrl.search = new URLSearchParams(submittedFormData).toString();
          const currentCard = form.closest("[data-visualization-key]");
          if (!currentCard) {
            window.location.assign(requestUrl.toString());
            return false;
          }

          const isStreetFilter = form.matches("[data-street-chart-filter]");
          const previousStreetFilters = isStreetFilter ? streetFilterSnapshot(submittedFormData) : null;
          if (isStreetFilter && form.dataset.streetRefreshInFlight === "true") return false;
          if (isStreetFilter) {
            form.dataset.streetRefreshInFlight = "true";
              form.querySelectorAll("button, select, input").forEach((control) => { control.disabled = true; });
          }

          const streetStatus = isStreetFilter ? form.parentElement.querySelector("#street-filter-status") : null;
          if (streetStatus) {
            streetStatus.classList.remove("is-error");
            streetStatus.textContent = "Updating street collection data…";
          }
          currentCard.classList.add("visualization-loading");
          currentCard.setAttribute("aria-busy", "true");
          try {
            const response = await fetch(requestUrl.toString(), { headers: { "X-Requested-With": "XMLHttpRequest" } });
            if (!response.ok) throw new Error("Unable to refresh this visualization.");
            const source = await response.text();
            const refreshedPage = new DOMParser().parseFromString(source, "text/html");
            const visualizationKey = currentCard.dataset.visualizationKey;
            const replacementCard = refreshedPage.querySelector('[data-visualization-key="' + visualizationKey + '"]');
            if (!replacementCard) throw new Error("The refreshed visualization was not found.");

            const explanationMatch = source.match(/(?:const|let)\s+dashboardExplanationData\s*=\s*([\s\S]*?);\s*\n/);
            if (explanationMatch) {
              dashboardExplanationData = JSON.parse(explanationMatch[1]);
              explanationCache.clear();
            }
            currentCard.replaceWith(replacementCard);
            if (window.EcoTrackYearPicker) window.EcoTrackYearPicker.mountAll(replacementCard);
            initializeStreetVisualization(replacementCard);
            const refreshedForm = replacementCard.querySelector("[data-street-chart-filter]");
            const refreshedStatus = replacementCard.querySelector("#street-filter-status");
            const adjustment = streetFilterAdjustment(previousStreetFilters, refreshedForm ? new FormData(refreshedForm) : null);
            const successMessage = refreshOptions.successMessage || (isStreetFilter ? "Street filters applied." : "");
            if (refreshedStatus && (successMessage || adjustment)) {
              refreshedStatus.classList.remove("is-error");
              refreshedStatus.textContent = [successMessage, adjustment].filter(Boolean).join(" ");
            }
            if (refreshOptions.updateHistory !== false) window.history.replaceState({}, "", requestUrl);
            return true;
          } catch (error) {
            if (streetStatus) {
              streetStatus.classList.add("is-error");
              streetStatus.textContent = "Street data could not be refreshed. Check your connection and try again.";
            } else {
              // Other existing dashboard controls retain their full-page fallback.
              window.location.assign(requestUrl.toString());
            }
            return false;
          } finally {
            currentCard.classList.remove("visualization-loading");
            currentCard.removeAttribute("aria-busy");
            if (isStreetFilter) {
              form.dataset.streetRefreshInFlight = "false";
              form.querySelectorAll("button, select, input").forEach((control) => { control.disabled = false; });
              if (isStreetFilter) {
                const mode = form.querySelector("[data-street-period-mode]");
                form.querySelectorAll("[data-street-period-field]").forEach((field) => {
                  const visible = mode && field.dataset.streetPeriodField === mode.value;
                  field.querySelectorAll("input, select").forEach((control) => { control.disabled = !visible; });
                });
              }
            }
          }
        }

        document.addEventListener("submit", (event) => {
          const form = event.target.closest("form.visualization-filter-control");
          if (!form) return;
          event.preventDefault();
          refreshVisualization(form);
        });

        initializeStreetVisualization(document);

        document.addEventListener("click", (event) => {
          const button = event.target.closest(".explain-button[data-explanation-id]");
          if (!button) return;
          event.preventDefault();
          openChartExplanation(button.dataset.explanationId, button);
        });
        explanationLanguage.addEventListener("change", () => {
          localStorage.setItem("ecotrackInsightLanguage", explanationLanguage.value);
          if (activeExplanationId) loadChartExplanation(activeExplanationId);
        });
        const dashboardLoadedAt = Date.now();
        let dashboardWasteVersion = Number(<?php echo json_encode((int)($wasteDataState['version'] ?? 0)); ?>);
        let streetVersionRefreshInFlight = false;
        function refreshStreetCardForDataVersion() {
          if (streetVersionRefreshInFlight) return;
          const form = document.querySelector("[data-street-chart-filter]");
          if (!form) return;
          streetVersionRefreshInFlight = true;
          refreshVisualization(form, { successMessage: "Street collection data updated from the latest import." })
            .then((refreshed) => {
              if (refreshed && activeExplanationId === "waste-household") loadChartExplanation(activeExplanationId);
            })
            .finally(() => { streetVersionRefreshInFlight = false; });
        }
        async function refreshDashboardDataVersion() {
          if (document.hidden) return;
          try {
            const response = await fetch("waste_data_version.php", { cache: "no-store", credentials: "same-origin" });
            const state = await response.json();
            if (response.ok && Number(state.version || 0) > dashboardWasteVersion) {
              dashboardWasteVersion = Number(state.version || 0);
              refreshStreetCardForDataVersion();
            }
          } catch (error) {
            // Cross-tab storage still gives immediate refreshes; retry later.
          }
        }
        window.addEventListener("storage", (event) => {
          if (event.key === "ecotrackWasteDataUpdated" && Number(event.newValue || 0) > dashboardLoadedAt) {
            refreshDashboardDataVersion();
          }
        });
        explanationPanel.querySelector(".explanation-close").addEventListener("click", closeChartExplanation);
        explanationPanel.addEventListener("click", (event) => {
          if (event.target === explanationPanel) closeChartExplanation();
        });
        document.addEventListener("keydown", (event) => {
          if (event.key === "Escape" && explanationPanel.classList.contains("is-open")) closeChartExplanation();
        });

        window.addEventListener("focus", refreshDashboardDataVersion);
        document.addEventListener("visibilitychange", () => {
          if (!document.hidden) refreshDashboardDataVersion();
        });
        setInterval(refreshDashboardDataVersion, 5000);
    </script>
</body>
</html>

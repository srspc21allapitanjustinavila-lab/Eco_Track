<?php
require_once 'config.php';
requireUserType('admin');
$operationsReportsRoute = 'admin_operations_reports.php';
if (empty($canonicalRouteEntry) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $routeQuery = http_build_query($_GET);
    header('Location: ' . $operationsReportsRoute . ($routeQuery === '' ? '' : '?' . $routeQuery));
    exit();
}

$user = getCurrentUser();
$conn = getDBConnection();
ensureReportsTable($conn);
ensureWasteImportTables($conn);
ensureWasteImportAuditTable($conn);

$tab = (string)($_GET['tab'] ?? 'operations');
if (!in_array($tab, ['operations', 'import_audit', 'staff_reports'], true)) {
    $tab = 'operations';
}
$period = ($_GET['period'] ?? '') === 'all' ? 'all' : '30d';
$importAuditPerPage = 25;
$importAuditCurrentPage = max(1, (int)($_GET['audit_page'] ?? 1));
$importAuditTotalCount = 0;
$importAuditTotalPages = 1;
$importAuditEvents = [];
$reportPageTitles = [
    'operations' => 'Operations',
    'import_audit' => 'Import Audit',
    'staff_reports' => 'Staff Reports',
];
$reportPageKickers = [
    'operations' => 'Monitor import health and current staff-report workload.',
    'import_audit' => 'Review completed, blocked, duplicate, and failed file import attempts.',
    'staff_reports' => 'Review staff-submitted issues and notify the reporting staff member.',
];
$statusLabels = ['pending' => 'Pending', 'in_review' => 'In Review', 'resolved' => 'Resolved'];
$statusFilter = $_GET['report_status'] ?? 'all';
if ($statusFilter !== 'all' && !isset($statusLabels[$statusFilter])) {
    $statusFilter = 'all';
}
$highlightId = max(0, (int)($_GET['report_id'] ?? 0));
$flash = $_SESSION['staff_report_flash'] ?? '';
unset($_SESSION['staff_report_flash']);
$error = '';
$printOtpRequired = false;
$submittedPrintOtp = '';
$authorizedRecentImportPrint = false;
if (empty($_SESSION['recent_import_activity_print_csrf'])) {
    $_SESSION['recent_import_activity_print_csrf'] = bin2hex(random_bytes(32));
}
$recentImportActivityPrintCsrf = $_SESSION['recent_import_activity_print_csrf'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_staff_reports'])) {
    $tab = 'staff_reports';
    $submittedReports = $_POST['reports'] ?? [];
    $updatesById = [];

    if (!is_array($submittedReports)) {
        $error = 'The submitted staff report updates are invalid.';
    } else {
        foreach ($submittedReports as $rawReportId => $submittedReport) {
            $reportId = filter_var($rawReportId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($reportId === false || !is_array($submittedReport)) {
                $error = 'One or more submitted staff report updates are invalid.';
                break;
            }

            $newStatus = trim((string)($submittedReport['status'] ?? ''));
            $note = trim((string)($submittedReport['admin_note'] ?? ''));
            $note = $note === '' ? null : $note;
            if (!isset($statusLabels[$newStatus])) {
                $error = 'One or more submitted staff report statuses are invalid.';
                break;
            }
            if (strlen((string)($note ?? '')) > 1000) {
                $error = 'Each admin note must be 1,000 characters or fewer.';
                break;
            }

            $updatesById[(int)$reportId] = ['status' => $newStatus, 'admin_note' => $note];
        }
    }

    if ($error === '') {
        try {
            if (empty($updatesById)) {
                $_SESSION['staff_report_flash'] = 'No changes were made to the staff reports.';
            } else {
                $reportIds = array_keys($updatesById);
                $placeholders = implode(', ', array_fill(0, count($reportIds), '?'));
                $find = $conn->prepare('SELECT id, staff_id, report_type, status, admin_note FROM reports WHERE id IN (' . $placeholders . ')');
                $find->execute($reportIds);
                $existingById = [];
                foreach ($find->fetchAll() as $existing) {
                    $existingById[(int)$existing['id']] = $existing;
                }

                foreach ($reportIds as $reportId) {
                    if (!isset($existingById[$reportId])) {
                        $error = 'One or more selected staff reports no longer exist.';
                        break;
                    }
                }

                if ($error === '') {
                    $changedReports = [];
                    foreach ($updatesById as $reportId => $submittedUpdate) {
                        $existing = $existingById[$reportId];
                        $oldNote = trim((string)($existing['admin_note'] ?? ''));
                        $oldNote = $oldNote === '' ? null : $oldNote;
                        if ($existing['status'] !== $submittedUpdate['status'] || $oldNote !== $submittedUpdate['admin_note']) {
                            $changedReports[$reportId] = $submittedUpdate;
                        }
                    }

                    if (empty($changedReports)) {
                        $_SESSION['staff_report_flash'] = 'No changes were made to the staff reports.';
                    } else {
                        ensureNotificationsTable($conn);
                        ensureActivityLogsTable($conn);
                        $conn->beginTransaction();
                        $update = $conn->prepare('UPDATE reports SET status = ?, admin_note = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?');
                        $adminName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: ($user['username'] ?? 'An administrator');

                        foreach ($changedReports as $reportId => $submittedUpdate) {
                            $existing = $existingById[$reportId];
                            $update->execute([$submittedUpdate['status'], $submittedUpdate['admin_note'], (int)$user['id'], $reportId]);
                            $message = $adminName . ' updated your ' . ucwords(str_replace('_', ' ', $existing['report_type'])) . ' report to ' . $statusLabels[$submittedUpdate['status']] . '.';
                            if ($submittedUpdate['admin_note'] !== null) {
                                $message .= ' Admin note: ' . $submittedUpdate['admin_note'];
                            }
                            createUserNotification($conn, (int)$existing['staff_id'], 'Staff report updated', $message, 'staff_announcements.php?tab=my_reports&report_id=' . $reportId, 'staff_report_updated', (int)$user['id']);
                            logActivity('Updated staff report #' . $reportId . ' to ' . $statusLabels[$submittedUpdate['status']], 'Staff Reports', 'Success', $conn);
                        }

                        $conn->commit();
                        $changedCount = count($changedReports);
                        $_SESSION['staff_report_flash'] = $changedCount . ' staff report' . ($changedCount === 1 ? '' : 's') . ' updated and the submitting staff member' . ($changedCount === 1 ? ' was' : 's were') . ' notified.';
                    }
                }
            }

            if ($error === '') {
                header('Location: ' . $operationsReportsRoute . '?' . http_build_query(['tab' => 'staff_reports', 'period' => $period, 'report_status' => $statusFilter]));
                exit();
            }
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            $error = 'The staff reports could not be updated. Please try again.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['print_recent_import_activity'])) {
    if ($tab !== 'import_audit') {
        $error = 'Open Import Audit to print recent import activity.';
        logActivity('Attempted Recent Import Activity print outside Import Audit', 'Reports', 'Failed', $conn);
    } else {
        $submittedPrintOtp = trim((string)($_POST['print_otp'] ?? ''));
        $submittedCsrf = (string)($_POST['recent_import_activity_print_csrf'] ?? '');

        if (!hash_equals($recentImportActivityPrintCsrf, $submittedCsrf)) {
            $error = 'Your print request expired. Refresh the page and try again.';
            logActivity('Requested Recent Import Activity print', 'Reports', 'Failed', $conn);
        } elseif (verifyAndConsumeActionOtp($conn, $user, 'recent_import_activity_print', $submittedPrintOtp)) {
            $authorizedRecentImportPrint = true;
        } else {
            $wasAlreadyPending = false;
            $otpReady = ensurePendingActionOtp($conn, $user, 'recent_import_activity_print', $wasAlreadyPending);
            $printOtpRequired = $otpReady;

            if (!$otpReady) {
                $error = 'Printing is disabled in your Data Privacy settings or a verification code could not be sent to your registered email address.';
                logActivity('Requested Recent Import Activity print verification', 'Reports', 'Failed', $conn);
            } elseif ($submittedPrintOtp !== '') {
                $error = $wasAlreadyPending
                    ? 'The verification code is invalid. Enter the current six-digit code sent to your email.'
                    : 'The verification code expired. A new six-digit code was sent to your registered email address.';
                logActivity('Verified Recent Import Activity print code', 'Reports', 'Failed', $conn);
            } else {
                $flash = $wasAlreadyPending
                    ? 'Enter the current six-digit verification code sent to your registered email address to print this activity.'
                    : 'A six-digit verification code was sent to your registered email address. Enter it to print this activity.';
                logActivity('Requested Recent Import Activity print verification', 'Reports', 'Success', $conn);
            }
        }
    }
}

$windowStart = $period === '30d' ? (new DateTimeImmutable('today'))->modify('-29 days')->format('Y-m-d 00:00:00') : null;
$eventWhere = $windowStart === null ? '' : ' WHERE e.created_at >= ?';
$eventParams = $windowStart === null ? [] : [$windowStart];
$eventSummary = ['imported_files' => 0, 'imported_rows' => 0, 'blocked_files' => 0];
$staffSummary = ['pending' => 0, 'in_review' => 0];
$events = [];
$dailyRows = [];
$staffReports = [];
$attachImportIssueSummaries = static function (PDO $conn, array $importEvents): array {
    if (!$importEvents) {
        return $importEvents;
    }

    $eventIds = array_map(static fn ($event): int => (int)$event['id'], $importEvents);
    $issueStmt = $conn->prepare('SELECT event_id, worksheet_name, source_row_number, reason_code FROM waste_import_event_issues WHERE event_id IN (' . implode(',', array_fill(0, count($eventIds), '?')) . ') ORDER BY event_id, worksheet_name, source_row_number LIMIT 250');
    $issueStmt->execute($eventIds);
    $issuesByEvent = [];
    foreach ($issueStmt->fetchAll() as $issue) {
        $source = trim((string)($issue['worksheet_name'] ?? ''));
        if ((int)$issue['source_row_number'] > 0) {
            $source .= ($source === '' ? '' : ' ') . 'row ' . (int)$issue['source_row_number'];
        }
        $issuesByEvent[(int)$issue['event_id']][] = trim(($source === '' ? '' : $source . ': ') . str_replace('_', ' ', (string)$issue['reason_code']));
    }

    foreach ($importEvents as &$importEvent) {
        $importEvent['issue_summary'] = implode('; ', array_slice($issuesByEvent[(int)$importEvent['id']] ?? [], 0, 5));
    }
    unset($importEvent);
    return $importEvents;
};
try {
    $stmt = $conn->prepare("SELECT COALESCE(SUM(CASE WHEN e.outcome = 'imported' THEN 1 ELSE 0 END),0) AS imported_files, COALESCE(SUM(CASE WHEN e.outcome = 'imported' THEN e.valid_row_count ELSE 0 END),0) AS imported_rows, COALESCE(SUM(CASE WHEN e.outcome <> 'imported' THEN 1 ELSE 0 END),0) AS blocked_files FROM waste_import_events e" . $eventWhere);
    $stmt->execute($eventParams);
    $eventSummary = array_merge($eventSummary, $stmt->fetch() ?: []);
    $stmt = $conn->prepare("SELECT e.*, u.first_name, u.last_name, u.username FROM waste_import_events e LEFT JOIN users u ON u.id = e.actor_user_id" . $eventWhere . ' ORDER BY e.created_at DESC, e.id DESC LIMIT 50');
    $stmt->execute($eventParams);
    $events = $attachImportIssueSummaries($conn, $stmt->fetchAll());

    if ($tab === 'import_audit') {
        $countStmt = $conn->prepare('SELECT COUNT(*) FROM waste_import_events e' . $eventWhere);
        $countStmt->execute($eventParams);
        $importAuditTotalCount = (int)$countStmt->fetchColumn();
        $importAuditTotalPages = max(1, (int)ceil($importAuditTotalCount / $importAuditPerPage));
        $importAuditCurrentPage = min($importAuditCurrentPage, $importAuditTotalPages);
        $importAuditOffset = ($importAuditCurrentPage - 1) * $importAuditPerPage;

        $auditStmt = $conn->prepare("SELECT e.*, u.first_name, u.last_name, u.username FROM waste_import_events e LEFT JOIN users u ON u.id = e.actor_user_id" . $eventWhere . ' ORDER BY e.created_at DESC, e.id DESC LIMIT ' . $importAuditPerPage . ' OFFSET ' . $importAuditOffset);
        $auditStmt->execute($eventParams);
        $importAuditEvents = $attachImportIssueSummaries($conn, $auditStmt->fetchAll());
    }
    $chartStart = (new DateTimeImmutable('today'))->modify('-' . ($period === 'all' ? 89 : 29) . ' days')->format('Y-m-d 00:00:00');
    $stmt = $conn->prepare('SELECT DATE(created_at) AS event_day, outcome, COUNT(*) AS event_count FROM waste_import_events WHERE created_at >= ? GROUP BY DATE(created_at), outcome ORDER BY event_day ASC');
    $stmt->execute([$chartStart]);
    $dailyRows = $stmt->fetchAll();
    $staffWhere = $windowStart === null ? '' : ' WHERE created_at >= ?';
    $stmt = $conn->prepare("SELECT COALESCE(SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END),0) AS pending, COALESCE(SUM(CASE WHEN status = 'in_review' THEN 1 ELSE 0 END),0) AS in_review FROM reports" . $staffWhere);
    $stmt->execute($windowStart === null ? [] : [$windowStart]);
    $staffSummary = array_merge($staffSummary, $stmt->fetch() ?: []);
    if ($tab === 'staff_reports') {
        $where = $statusFilter === 'all' ? '' : ' WHERE r.status = ?';
        $stmt = $conn->prepare("SELECT r.*, staff.first_name AS staff_first_name, staff.last_name AS staff_last_name, staff.username AS staff_username, reviewer.first_name AS reviewer_first_name, reviewer.last_name AS reviewer_last_name, reviewer.username AS reviewer_username FROM reports r LEFT JOIN users staff ON staff.id = r.staff_id LEFT JOIN users reviewer ON reviewer.id = r.reviewed_by" . $where . " ORDER BY CASE r.status WHEN 'pending' THEN 0 WHEN 'in_review' THEN 1 WHEN 'resolved' THEN 2 ELSE 3 END, r.created_at DESC, r.id DESC");
        $stmt->execute($statusFilter === 'all' ? [] : [$statusFilter]);
        $staffReports = $stmt->fetchAll();
    }
} catch (PDOException $e) {
    $error = 'Operational report data could not load. Please refresh the page.';
}
$importAuditRangeStart = $importAuditTotalCount === 0 ? 0 : (($importAuditCurrentPage - 1) * $importAuditPerPage) + 1;
$importAuditRangeEnd = min($importAuditCurrentPage * $importAuditPerPage, $importAuditTotalCount);
$importAuditWindowStart = max(1, min($importAuditCurrentPage - 4, max(1, $importAuditTotalPages - 9)));
$importAuditWindowEnd = min($importAuditTotalPages, $importAuditWindowStart + 9);
$importAuditPageUrl = static function (int $page) use ($period, $operationsReportsRoute): string {
    return $operationsReportsRoute . '?' . http_build_query([
        'tab' => 'import_audit',
        'period' => $period,
        'audit_page' => $page,
    ]);
};
$daily = [];
foreach ($dailyRows as $row) {
    if (!isset($daily[$row['event_day']])) {
        $daily[$row['event_day']] = ['imported' => 0, 'blocked' => 0];
    }
    $daily[$row['event_day']][
        $row['outcome'] === 'imported' ? 'imported' : 'blocked'
    ] += (int)$row['event_count'];
}

$chartMax = 1;
foreach ($daily as $counts) {
    $chartMax = max($chartMax, $counts['imported'] + $counts['blocked']);
}

function reportActor($event)
{
    $name = trim(($event['first_name'] ?? '') . ' ' . ($event['last_name'] ?? ''));
    return $name !== '' ? $name : ($event['username'] ?? 'System / legacy');
}

function reportOutcome($outcome)
{
    return ucwords(str_replace('_', ' ', (string)$outcome));
}

if ($authorizedRecentImportPrint) {
    $printEvents = array_slice($events, 0, 8);
    logActivity('Printed Recent Import Activity', 'Reports', 'Success', $conn);
    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Recent Import Activity - EcoTrack</title>
    <style>
        @page { margin: 18mm; }
        * { box-sizing: border-box; }
        body { margin: 0; color: #17231f; background: #fff; font: 14px/1.45 Arial, sans-serif; }
        h1 { margin: 0; font-size: 24px; }
        .meta { margin: 6px 0 22px; color: #51645d; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 9px 8px; border-bottom: 1px solid #cfd8d3; text-align: left; vertical-align: top; }
        th { color: #0b4f43; background: #edf5f1; font-size: 11px; letter-spacing: .04em; text-transform: uppercase; }
        .badge { display: inline-block; padding: 3px 7px; border: 1px solid #8db9aa; border-radius: 999px; color: #0b4f43; font-size: 11px; font-weight: 700; }
        .empty { padding: 20px; color: #51645d; text-align: center; }
    </style>
</head>
<body>
    <h1>Recent Import Activity</h1>
    <p class="meta"><?php echo $period === 'all' ? 'All-time activity' : 'Last 30 days'; ?> · Printed <?php echo htmlspecialchars(date('M d, Y g:i A')); ?></p>
    <table>
        <thead><tr><th>When</th><th>File</th><th>Outcome</th><th>Rows</th></tr></thead>
        <tbody>
        <?php foreach ($printEvents as $event): ?>
            <tr>
                <td><?php echo htmlspecialchars(date('M d, Y g:i A', strtotime($event['created_at']))); ?></td>
                <td><?php echo htmlspecialchars($event['original_name'] ?: 'No filename retained'); ?></td>
                <td><span class="badge"><?php echo htmlspecialchars(reportOutcome($event['outcome'])); ?></span></td>
                <td><?php echo number_format((int)$event['valid_row_count']); ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$printEvents): ?><tr><td colspan="4" class="empty">No import activity recorded.</td></tr><?php endif; ?>
        </tbody>
    </table>
    <script>window.addEventListener('load', function () { window.print(); });</script>
</body>
</html>
    <?php
    exit();
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Reports - EcoTrack</title><?php include 'includes/theme_head.php'; ?><link rel="stylesheet" href="assets/css/ecotrack-theme.css?v=<?php echo filemtime(__DIR__ . '/assets/css/ecotrack-theme.css'); ?>"><style>
    body {
      display: flex;
      min-height: 100vh;
      margin: 0;
      background: var(--bg-primary);
      color: var(--text-primary);
      font-family:
        Segoe UI,
        Tahoma,
        sans-serif;
    }
    .main-content {
      flex: 1;
      margin-left: 280px;
      padding: 28px;
      max-width: 1600px;
    }
    .header {
      display: flex;
      justify-content: space-between;
      gap: 20px;
      align-items: flex-start;
      margin-bottom: 22px;
    }
    h1 {
      margin: 0;
      font-size: 28px;
    }
    .page-kicker,
    .sub,
    .meta {
      color: var(--text-secondary);
    }
    .page-kicker {
      margin: 7px 0 0;
    }
    .tabs,
    .legend {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
    }
    .tabs {
      margin-bottom: 16px;
    }
    .tab,
    .btn {
      display: inline-flex;
      justify-content: center;
      align-items: center;
      min-height: 40px;
      padding: 9px 14px;
      border: 1px solid var(--border-color);
      border-radius: 8px;
      background: var(--bg-secondary);
      color: var(--text-primary);
      font: inherit;
      font-weight: 700;
      text-decoration: none;
      cursor: pointer;
    }
    .tab.active,
    .btn.green {
      background: var(--eco-primary-action, #16735f);
      border-color: var(--eco-primary-action, #16735f);
      color: var(--eco-on-primary, #fff);
    }
    .btn.gray {
      background: var(--toggle-bg, #e5e7eb);
    }
    .toolbar,
    .card,
    .kpi {
      background: var(--bg-secondary);
      border: 1px solid var(--border-color);
      border-radius: 12px;
      box-shadow: var(--card-shadow);
    }
    .toolbar {
      display: flex;
      justify-content: space-between;
      gap: 14px;
      align-items: center;
      padding: 14px 17px;
      margin-bottom: 18px;
    }
    .toolbar p,
    .sub {
      margin: 0;
      font-size: 13px;
    }
    .kpis {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 15px;
      margin-bottom: 18px;
    }
    .operations-overview {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      grid-template-areas: "chart summary";
      gap: 18px;
      align-items: stretch;
      margin-bottom: 18px;
    }
    .daily-import-outcomes {
      grid-area: chart;
    }
    .operations-overview > .card {
      margin-bottom: 0;
    }
    .operations-overview .kpis {
      grid-area: summary;
      grid-template-columns: 1fr;
      grid-template-rows: repeat(3, minmax(0, 1fr));
      height: 100%;
      margin: 0;
    }
    .kpi,
    .card {
      padding: 20px;
    }
    .label {
      font-size: 11px;
      color: var(--text-secondary);
      font-weight: 800;
      letter-spacing: 0.04em;
      text-transform: uppercase;
    }
    .value {
      margin-top: 8px;
      font-size: 26px;
      font-weight: 800;
    }
    .note,
    .meta {
      display: block;
      margin-top: 5px;
      font-size: 12px;
    }
    .card {
      margin-bottom: 18px;
    }
    .card-heading {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 14px;
      margin-bottom: 12px;
    }
    .card-heading h2,
    .card-heading .sub {
      margin-bottom: 0;
    }
    .card-actions {
      flex: 0 0 auto;
    }
    .card h2 {
      margin: 0 0 8px;
      font-size: 19px;
    }
    .chart {
      height: 220px;
      display: flex;
      align-items: end;
      gap: 5px;
      padding: 24px 2px 12px;
      border-bottom: 1px solid var(--border-color);
      overflow-x: auto;
      overscroll-behavior-inline: contain;
    }
    .day {
      min-width: 13px;
      height: 100%;
      display: flex;
      flex-direction: column;
      justify-content: end;
      gap: 1px;
    }
    .ok {
      background: #2e7d32;
    }
    .bad {
      background: #dc2626;
    }
    .legend {
      margin-top: 10px;
      font-size: 12px;
    }
    .dot {
      display: inline-block;
      width: 9px;
      height: 9px;
      border-radius: 50%;
      margin-right: 4px;
    }
    .scroll {
      overflow: auto;
    }
    table {
      width: 100%;
      border-collapse: collapse;
      font-size: 13px;
    }
    th,
    td {
      padding: 10px 8px;
      border-bottom: 1px solid var(--border-color);
      text-align: left;
      vertical-align: top;
    }
    th {
      font-size: 11px;
      color: var(--text-secondary);
      letter-spacing: 0.04em;
      text-transform: uppercase;
    }
    .badge {
      display: inline-flex;
      padding: 5px 8px;
      border-radius: 999px;
      font-size: 11px;
      font-weight: 800;
      white-space: nowrap;
    }
    .badge.imported,
    .badge.resolved {
      background: #dcfce7;
      color: #166534;
    }
    .badge.duplicate,
    .badge.validation_error,
    .badge.failed,
    .badge.pending {
      background: #fef3c7;
      color: #92400e;
    }
    .badge.cancelled,
    .badge.expired,
    .badge.in_review {
      background: #dbeafe;
      color: #1d4ed8;
    }
    .filter {
      display: flex;
      gap: 12px;
      align-items: end;
      flex-wrap: wrap;
      padding: 16px;
      margin-bottom: 18px;
    }
    .staff-report-filter {
      justify-content: flex-start;
    }
    .staff-report-filter__actions {
      display: flex;
      gap: 8px;
      margin-left: 0;
    }
    .filter label,
    .review label {
      display: block;
      margin-bottom: 5px;
      font-size: 11px;
      color: var(--text-secondary);
      font-weight: 800;
      text-transform: uppercase;
    }
    .filter select,
    .review select,
    .review textarea {
      min-height: 40px;
      padding: 8px 10px;
      border: 1px solid var(--border-color);
      border-radius: 7px;
      background: var(--bg-primary);
      color: var(--text-primary);
      font: inherit;
    }
    .review select,
    .review textarea {
      display: block;
      width: 100%;
      margin-bottom: 8px;
    }
    .review textarea {
      min-height: 72px;
      resize: vertical;
    }
    .highlight td {
      background: var(--eco-surface-soft, #e6f2dc);
    }
    .message,
    .empty {
      padding: 20px;
      border-radius: 10px;
      text-align: center;
      color: var(--text-secondary);
    }
    .message {
      margin-bottom: 16px;
      background: #fef2f2;
      border: 1px solid #fecaca;
      color: #991b1b;
    }
    .message.success {
      background: #f0fdf4;
      border-color: #bbf7d0;
      color: #166534;
    }
    .import-audit-card {
      min-width: 0;
    }
    .import-audit-card .scroll {
      max-height: min(640px, 68vh);
    }
    .import-audit-pagination {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
      min-height: 62px;
      padding: 8px 20px;
      border-top: 1px solid var(--border-color);
      background: var(--eco-bg, #f3f7f5);
    }
    .import-audit-pagination-summary {
      color: var(--text-secondary);
      font-size: 14px;
      white-space: nowrap;
    }
    .import-audit-pagination-links {
      display: flex;
      flex-wrap: wrap;
      justify-content: flex-end;
      gap: 6px;
    }
    .import-audit-pagination-links a,
    .import-audit-pagination-links span {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 40px;
      height: 47px;
      padding: 0 12px;
      border: 1px solid var(--input-border, var(--border-color));
      border-radius: 6px;
      background: var(--input-bg, var(--bg-secondary));
      color: var(--text-primary);
      font-size: 16px;
      text-align: center;
      text-decoration: none;
    }
    .import-audit-pagination-links a:hover {
      border-color: var(--eco-primary-action, #16735f);
      color: var(--eco-primary-action, #16735f);
    }
    .import-audit-pagination-links .active {
      border-color: var(--eco-primary-action, #16735f);
      background: var(--eco-primary-action, #16735f);
      color: var(--eco-on-primary, #fff);
      font-weight: 700;
    }
    .import-audit-pagination-links .disabled {
      opacity: 0.5;
    }
    .import-audit-print {
      background: var(--eco-primary-soft);
      border-color: var(--eco-border);
      color: var(--eco-primary-strong);
    }
    .import-audit-print:hover,
    .import-audit-print:focus-visible {
      background: var(--eco-primary-action);
      border-color: var(--eco-primary-action);
      color: var(--eco-on-primary);
    }
    @media (max-width: 980px) {
      .operations-overview {
        grid-template-columns: 1fr;
        grid-template-areas:
          "chart"
          "summary";
      }
      .operations-overview .kpis {
        grid-template-columns: 1fr;
        height: auto;
      }
    }
    @media (max-width: 768px) {
      .main-content {
        margin-left: 76px;
        padding: 18px;
      }
      .header,
      .toolbar {
        display: block;
      }
      .card-heading {
        align-items: stretch;
        flex-direction: column;
      }
      .card-actions .btn {
        width: 100%;
      }
      .staff-report-filter__actions {
        margin-top: 12px;
        margin-left: 0;
      }
      .staff-report-filter__actions .btn {
        flex: 1 1 0;
      }
      .import-audit-pagination {
        align-items: flex-start;
        flex-direction: column;
        padding: 12px;
      }
      .import-audit-pagination-links {
        justify-content: flex-start;
      }
      table {
        min-width: 750px;
      }
    }
</style></head><body>
<?php $active_page = $operationsReportsRoute;
$useLogoutModal = true;
include 'includes/sidebar.php'; ?>
<main class="main-content"><div class="header"><div><h1><?php echo htmlspecialchars($reportPageTitles[$tab]); ?></h1><p class="page-kicker"><?php echo htmlspecialchars($reportPageKickers[$tab]); ?></p></div><div><?php include 'includes/notification_bell.php'; ?></div></div>
<?php if ($error !== ''): ?><div class="message"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
<?php if ($flash !== '' && $tab !== 'staff_reports'): ?><div class="message success"><?php echo htmlspecialchars($flash); ?></div><?php endif; ?>
<nav class="tabs"><a class="tab <?php echo $tab === 'operations' ? 'active' : ''; ?>" href="<?php echo $operationsReportsRoute; ?>?tab=operations&amp;period=<?php echo $period; ?>">Operations</a><a class="tab <?php echo $tab === 'import_audit' ? 'active' : ''; ?>" href="<?php echo $operationsReportsRoute; ?>?tab=import_audit&amp;period=<?php echo $period; ?>">Import Audit</a><a class="tab <?php echo $tab === 'staff_reports' ? 'active' : ''; ?>" href="<?php echo $operationsReportsRoute; ?>?tab=staff_reports&amp;period=<?php echo $period; ?>">Staff Reports</a></nav>
<?php if ($tab === 'staff_reports'): ?>
<?php if ($flash !== ''): ?><div class="message success"><?php echo htmlspecialchars($flash); ?></div><?php endif; ?>
<form class="filter toolbar staff-report-filter" method="get" action="<?php echo $operationsReportsRoute; ?>">
    <input type="hidden" name="tab" value="staff_reports">
    <input type="hidden" name="period" value="<?php echo htmlspecialchars($period); ?>">
    <div class="staff-report-filter__field">
        <label for="status">Status</label>
        <select id="status" name="report_status">
            <option value="all">All statuses</option>
            <?php foreach ($statusLabels as $key => $label): ?>
                <option value="<?php echo $key; ?>" <?php echo $statusFilter === $key ? 'selected' : ''; ?>><?php echo $label; ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="staff-report-filter__actions">
        <button class="btn green" type="submit">Filter reports</button>
        <a class="btn gray" href="<?php echo $operationsReportsRoute; ?>?tab=staff_reports&amp;period=<?php echo $period; ?>">Clear</a>
    </div>
</form>
<form method="post" action="<?php echo $operationsReportsRoute; ?>?tab=staff_reports&amp;period=<?php echo urlencode($period); ?>&amp;report_status=<?php echo urlencode($statusFilter); ?>">
    <input type="hidden" name="save_staff_reports" value="1">
    <section class="card">
        <div class="card-heading">
            <div>
                <h2>Submitted Staff Reports</h2>
                <p class="sub">Pending reports are listed first. Save changes to notify the submitting staff member.</p>
            </div>
            <div class="card-actions">
                <button class="btn green" type="submit" <?php echo empty($staffReports) ? 'disabled' : ''; ?>>Save update</button>
            </div>
        </div>
        <div class="scroll">
            <table>
                <thead>
                    <tr><th>Reporter</th><th>Type / location</th><th>Submitted</th><th>Description</th><th>Status</th><th>Admin review</th></tr>
                </thead>
                <tbody>
                    <?php if (!$staffReports): ?>
                        <tr><td colspan="6"><div class="empty">No staff reports match this status.</div></td></tr>
                    <?php else: ?>
                        <?php foreach ($staffReports as $report): ?>
                            <?php
                            $reportId = (int)$report['id'];
                            $reportStatus = isset($statusLabels[$report['status'] ?? '']) ? $report['status'] : 'pending';
                            $reporter = trim(($report['staff_first_name'] ?? '') . ' ' . ($report['staff_last_name'] ?? '')) ?: ($report['staff_username'] ?? 'Deleted staff account');
                            $reviewer = trim(($report['reviewer_first_name'] ?? '') . ' ' . ($report['reviewer_last_name'] ?? '')) ?: ($report['reviewer_username'] ?? '');
                            ?>
                            <tr id="staff-report-<?php echo $reportId; ?>" class="<?php echo $highlightId === $reportId ? 'highlight' : ''; ?>">
                                <td><strong><?php echo htmlspecialchars($reporter); ?></strong><span class="meta">Report #<?php echo $reportId; ?></span></td>
                                <td><strong><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $report['report_type']))); ?></strong><span class="meta"><?php echo htmlspecialchars($report['zone'] ?: 'Location not specified'); ?></span></td>
                                <td><?php echo !empty($report['created_at']) ? htmlspecialchars(date('M d, Y g:i A', strtotime($report['created_at']))) : 'N/A'; ?></td>
                                <td><?php echo nl2br(htmlspecialchars($report['description'])); ?></td>
                                <td>
                                    <span class="badge <?php echo htmlspecialchars($reportStatus); ?>"><?php echo htmlspecialchars($statusLabels[$reportStatus]); ?></span>
                                    <?php if (!empty($report['reviewed_at'])): ?>
                                        <span class="meta">Updated <?php echo htmlspecialchars(date('M d, Y g:i A', strtotime($report['reviewed_at']))); ?><?php echo $reviewer !== '' ? ' by ' . htmlspecialchars($reviewer) : ''; ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="review">
                                    <?php if (trim((string)($report['admin_note'] ?? '')) !== ''): ?>
                                        <span class="meta"><strong>Current note:</strong><br><?php echo nl2br(htmlspecialchars($report['admin_note'])); ?></span>
                                    <?php endif; ?>
                                    <label for="s-<?php echo $reportId; ?>">Update status</label>
                                    <select id="s-<?php echo $reportId; ?>" name="reports[<?php echo $reportId; ?>][status]">
                                        <?php foreach ($statusLabels as $key => $label): ?>
                                            <option value="<?php echo $key; ?>" <?php echo $reportStatus === $key ? 'selected' : ''; ?>><?php echo $label; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <label for="n-<?php echo $reportId; ?>">Admin note</label>
                                    <textarea id="n-<?php echo $reportId; ?>" name="reports[<?php echo $reportId; ?>][admin_note]" maxlength="1000"><?php echo htmlspecialchars($report['admin_note'] ?? ''); ?></textarea>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</form>
<?php elseif ($tab === 'import_audit'): ?>
<section class="card import-audit-card">
    <div class="card-heading">
        <div>
            <h2>Import Audit</h2>
            <p class="sub">Safe operational metadata only. Successful legacy imports are backfilled from manifests; blocked outcomes are retained from this release forward.</p>
        </div>
        <form method="post" action="<?php echo $operationsReportsRoute; ?>?tab=import_audit&amp;period=<?php echo htmlspecialchars($period); ?>" class="card-actions" data-transaction-confirm-skip>
            <input type="hidden" name="recent_import_activity_print_csrf" value="<?php echo htmlspecialchars($recentImportActivityPrintCsrf); ?>">
            <input type="hidden" name="print_recent_import_activity" value="1">
            <button type="submit" class="btn import-audit-print">Print recent activity</button>
        </form>
    </div>
    <div class="scroll">
        <table>
            <thead><tr><th>When</th><th>File</th><th>Format</th><th>Actor</th><th>Valid rows</th><th>Outcome</th><th>Reason</th></tr></thead>
            <tbody>
            <?php if (!$importAuditEvents): ?>
                <tr><td colspan="7"><div class="empty">No import events in this period.</div></td></tr>
            <?php else: foreach ($importAuditEvents as $event): ?>
                <tr>
                    <td><?php echo htmlspecialchars(date('M d, Y g:i A', strtotime($event['created_at']))); ?></td>
                    <td>
                        <?php echo htmlspecialchars($event['original_name'] ?: 'No filename retained'); ?>
                        <?php if (!empty($event['issue_summary'])): ?><span class="meta">Skipped: <?php echo htmlspecialchars($event['issue_summary']); ?></span><?php endif; ?>
                    </td>
                    <td><?php echo htmlspecialchars(strtoupper($event['file_format'] ?: '—')); ?></td>
                    <td><?php echo htmlspecialchars(reportActor($event)); ?></td>
                    <td><?php echo number_format((int)$event['valid_row_count']); ?></td>
                    <td><span class="badge <?php echo htmlspecialchars($event['outcome']); ?>"><?php echo htmlspecialchars(reportOutcome($event['outcome'])); ?></span></td>
                    <td><?php echo htmlspecialchars($event['reason_code'] ?: '—'); ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($importAuditTotalCount > 0): ?>
    <nav class="import-audit-pagination" aria-label="Import Audit pages">
        <span class="import-audit-pagination-summary">Showing <?php echo $importAuditRangeStart; ?>&ndash;<?php echo $importAuditRangeEnd; ?> of <?php echo number_format($importAuditTotalCount); ?> records</span>
        <div class="import-audit-pagination-links">
            <?php if ($importAuditCurrentPage > 1): ?><a href="<?php echo htmlspecialchars($importAuditPageUrl($importAuditCurrentPage - 1)); ?>" rel="prev">Previous</a><?php else: ?><span class="disabled">Previous</span><?php endif; ?>
            <?php if ($importAuditWindowStart > 1): ?><a href="<?php echo htmlspecialchars($importAuditPageUrl(1)); ?>">1</a><span>&hellip;</span><?php endif; ?>
            <?php for ($importAuditPage = $importAuditWindowStart; $importAuditPage <= $importAuditWindowEnd; $importAuditPage++): ?>
                <?php if ($importAuditPage === $importAuditCurrentPage): ?><span class="active" aria-current="page"><?php echo $importAuditPage; ?></span><?php else: ?><a href="<?php echo htmlspecialchars($importAuditPageUrl($importAuditPage)); ?>"><?php echo $importAuditPage; ?></a><?php endif; ?>
            <?php endfor; ?>
            <?php if ($importAuditWindowEnd < $importAuditTotalPages): ?><span>&hellip;</span><a href="<?php echo htmlspecialchars($importAuditPageUrl($importAuditTotalPages)); ?>"><?php echo $importAuditTotalPages; ?></a><?php endif; ?>
            <?php if ($importAuditCurrentPage < $importAuditTotalPages): ?><a href="<?php echo htmlspecialchars($importAuditPageUrl($importAuditCurrentPage + 1)); ?>" rel="next">Next</a><?php else: ?><span class="disabled">Next</span><?php endif; ?>
        </div>
    </nav>
    <?php endif; ?>
</section>
<?php else: ?>
<div class="operations-overview">
<section class="card daily-import-outcomes"><h2>Daily Import Outcomes</h2><p class="sub"><?php echo $period === 'all' ? 'Most recent 90 days shown; all-time totals are shown above.' : 'Successful imports are green; blocked or failed attempts are red.'; ?></p><?php if (!$daily): ?><div class="empty">No import events in this period.</div><?php else: ?><div class="chart" aria-label="Daily import outcome chart"><?php foreach ($daily as $date => $counts): $good = ($counts['imported'] / $chartMax) * 100;
    $bad = ($counts['blocked'] / $chartMax) * 100; ?><div class="day" title="<?php echo htmlspecialchars($date . ': ' . $counts['imported'] . ' imported, ' . $counts['blocked'] . ' blocked'); ?>"><div class="bad" style="height:<?php echo number_format($bad, 3, '.', ''); ?>%"></div><div class="ok" style="height:<?php echo number_format($good, 3, '.', ''); ?>%"></div></div><?php endforeach; ?></div><div class="legend"><span><i class="dot" style="background:#2e7d32"></i>Imported</span><span><i class="dot" style="background:#dc2626"></i>Blocked / failed</span></div><?php endif; ?></section><div class="kpis"><article class="kpi"><div class="label">Imported files</div><div class="value"><?php echo number_format((int)$eventSummary['imported_files']); ?></div><div class="note"><?php echo number_format((int)$eventSummary['imported_rows']); ?> validated rows</div></article><article class="kpi"><div class="label">Blocked / failed files</div><div class="value"><?php echo number_format((int)$eventSummary['blocked_files']); ?></div><div class="note">Duplicate, validation, cancel, expiry, or failure outcomes</div></article><article class="kpi"><div class="label">Pending staff reports</div><div class="value"><?php echo number_format((int)$staffSummary['pending']); ?></div><div class="note"><?php echo (int)$staffSummary['in_review']; ?> currently in review</div></article></div></div>
<section class="card">
    <div class="card-heading">
    <div><h2>Recent Import Activity</h2><p class="sub"><a href="<?php echo $operationsReportsRoute; ?>?tab=import_audit&amp;period=<?php echo $period; ?>">View the full import audit.</a></p></div>
    </div>
    <div class="scroll"><table><thead><tr><th>When</th><th>File</th><th>Outcome</th><th>Rows</th></tr></thead><tbody><?php foreach (array_slice($events, 0, 8) as $event): ?><tr><td><?php echo htmlspecialchars(date('M d, Y g:i A', strtotime($event['created_at']))); ?></td><td><?php echo htmlspecialchars($event['original_name'] ?: 'No filename retained'); ?></td><td><span class="badge <?php echo htmlspecialchars($event['outcome']); ?>"><?php echo htmlspecialchars(reportOutcome($event['outcome'])); ?></span></td><td><?php echo number_format((int)$event['valid_row_count']); ?></td></tr><?php endforeach; ?><?php if (!$events): ?><tr><td colspan="4"><div class="empty">No import activity recorded.</div></td></tr><?php endif; ?></tbody></table></div>
</section>
<?php endif; ?></main>
<?php if ($printOtpRequired): ?>
    <?php
    $otpModalId = 'recentImportActivityPrintOtpModal';
    $otpModalTitle = 'Verify your print request';
    $otpModalMessage = 'Enter the six-digit verification code sent to your registered email address to print Recent Import Activity.';
    $otpModalError = $submittedPrintOtp !== '' ? $error : '';
    $otpModalAction = $operationsReportsRoute . '?tab=import_audit&period=' . urlencode($period);
    $otpModalField = 'print_otp';
    $otpModalSubmitLabel = 'Verify and print';
    $otpModalCancelHref = $operationsReportsRoute . '?tab=import_audit&period=' . urlencode($period);
    $otpModalCancelLabel = 'Cancel print';
    $otpModalHiddenInputs = [
        'recent_import_activity_print_csrf' => $recentImportActivityPrintCsrf,
        'print_recent_import_activity' => '1',
    ];
    include 'includes/otp_modal.php';
    ?>
<?php endif; ?>
<?php include 'includes/logout_confirmation_modal.php'; ?>
</body></html>

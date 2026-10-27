<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/attendance_management.php';
require_once __DIR__ . '/staff_attendance_management.php';
require_once __DIR__ . '/spreadsheet_export.php';

requireUserType($attendanceRole);
if (isSessionTimeout()) { logoutUser(); header('Location: login.php?timeout=1'); exit(); }
$_SESSION['last_activity'] = time();
$user = getCurrentUser();
$db = getDBConnection();
$isAdmin = $attendanceRole === 'admin';
$isCollectorSelf = ($attendanceScope ?? '') === 'self';
$isCollectorShared = ($attendanceScope ?? '') === 'shared';
$isCollectorAccount = $isCollectorSelf || $isCollectorShared;
$isStaffAttendance = ($attendanceKind ?? '') === 'staff';
$bioRoute = $isCollectorAccount ? 'waste_collector_attendance.php' : ($isAdmin ? 'admin_attendance.php' : 'staff_attendance.php');
$staffRoute = $isCollectorAccount ? '' : ($isAdmin ? 'admin_legacy_attendance.php' : 'staff_legacy_attendance.php');
$route = $isStaffAttendance ? $staffRoute : $bioRoute;
$otherRoute = $isStaffAttendance ? $bioRoute : $staffRoute;
$attendanceLabel = $isCollectorSelf ? 'your' : ($isStaffAttendance ? 'Staff' : 'Bio Collector');
$statuses = attendanceStatusOptions();
if (empty($_SESSION['attendance_csrf'])) $_SESSION['attendance_csrf'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['attendance_csrf'];
$today = attendanceToday();
$input = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$date = attendanceValidDate($input['attendance_date'] ?? null) ? $input['attendance_date'] : $today;
$date = $isCollectorAccount ? $today : $date;
$search = is_string($input['search'] ?? null) ? trim($input['search']) : '';
$search = function_exists('mb_substr') ? mb_substr($search, 0, 100) : substr($search, 0, 100);
$statusFilter = is_string($input['status'] ?? null) ? $input['status'] : '';
if ($statusFilter !== 'not_recorded' && normalizeAttendanceStatus($statusFilter) !== $statusFilter) $statusFilter = '';
if (!$isStaffAttendance) { $search = ''; $statusFilter = ''; }
$page = max(1, (int)($input['page'] ?? 1));
$queryValues = ['attendance_date' => $date, 'search' => $search, 'status' => $statusFilter, 'page' => $page];
$historyDate = attendanceValidDate($input['history_date'] ?? null) ? $input['history_date'] : '';
$historySearch = is_string($input['history_search'] ?? null) ? trim($input['history_search']) : '';
$historySearch = function_exists('mb_substr') ? mb_substr($historySearch, 0, 100) : substr($historySearch, 0, 100);
$historyStatus = is_string($input['history_status'] ?? null) ? normalizeAttendanceStatus($input['history_status']) : '';
$historyPage = max(1, (int)($input['history_page'] ?? 1));
if ($isCollectorAccount) {
    // Waste Collector accounts only manage today's operational roster. They
    // do not receive the staff/admin history, print, or export workspace.
    $historyDate = '';
    $historySearch = '';
    $historyStatus = '';
    $historyPage = 1;
} else {
    $queryValues += ['history_date' => $historyDate, 'history_search' => $historySearch, 'history_status' => $historyStatus, 'history_page' => $historyPage];
}
$returnUrl = $route . '?' . http_build_query($queryValues);
$error = '';
$message = $_SESSION['attendance_flash'] ?? '';
unset($_SESSION['attendance_flash']);
$otpRequired = false;
$availableYears = [(int)substr($today, 0, 4)];
$result = ['rows' => [], 'summary' => array_fill_keys(array_merge(array_keys($statuses), ['not_recorded']), 0), 'total' => 0, 'page' => 1, 'pages' => 1];
$history = ['rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1];
$queryAttendance = $isStaffAttendance ? 'staffAttendanceQuery' : 'bioAttendanceQuery';
$queryHistory = $isStaffAttendance ? 'staffAttendanceHistory' : 'bioAttendanceHistory';
$isPrint = !$isCollectorAccount && $_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['view'] ?? '') === 'print';
function attendanceHtml($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }

try {
    if (!$db) throw new RuntimeException('Attendance database is unavailable.');
    if ($isCollectorAccount) {
        ensureWasteCollectorAccountSchema($db);
        if ($isCollectorShared) syncBioCollectors($db);
        $availableYears = [(int)substr($today, 0, 4)];
    } elseif ($isStaffAttendance) {
        ensureStaffAttendanceSchema($db);
        $availableYears = staffAttendanceYears($db);
    } else {
        ensureBioAttendanceSchema($db);
        syncBioCollectors($db);
        $availableYears = attendanceAvailableYears($db);
    }
    if ($isPrint) {
        $printPeriod = is_string($_GET['print_period'] ?? null) ? $_GET['print_period'] : '';
        $printDate = is_string($_GET['print_date'] ?? null) ? $_GET['print_date'] : '';
        if ($printPeriod === 'month' && array_key_exists('print_month', $_GET)) {
            $month = is_string($_GET['print_month']) ? $_GET['print_month'] : '';
            if (!preg_match('/^\d{4}-\d{2}$/', $month)) throw new InvalidArgumentException('Choose a valid month.');
            $printDate = $month . '-01';
        }
        [$printStart, $printEnd] = attendancePrintDateRange($printPeriod, $printDate);
        $printRows = [];
        $printPage = 1;
        do {
            $batch = $queryHistory($db, '', $historySearch, $historyStatus, $printPage, 200, $printStart, $printEnd);
            array_push($printRows, ...$batch['rows']);
            $printPage++;
        } while ($printPage <= $batch['pages']);
        require __DIR__ . '/attendance_print.php';
        exit();
    }
    if (!$isCollectorAccount && !in_array((int)substr($date, 0, 4), $availableYears, true)) {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') throw new InvalidArgumentException('Choose a year available in the system.');
        $date = $today;
        $queryValues['attendance_date'] = $date;
        $returnUrl = $route . '?' . http_build_query($queryValues);
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($csrf, (string)($_POST['attendance_csrf'] ?? ''))) throw new InvalidArgumentException('Your attendance form expired. Refresh and try again.');
        $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
        if ($isCollectorAccount && $action !== 'save') {
            throw new RuntimeException('This attendance action is not available for Waste Collectors.', 403);
        }
        if ($action === 'delete') {
            if (!$isAdmin) { http_response_code(403); throw new RuntimeException('Administrator access required.', 403); }
            $id = filter_var($_POST['record_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $version = filter_var($_POST['record_version'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$id || !$version) throw new InvalidArgumentException('Invalid attendance record.');
            if ($isStaffAttendance) deleteStaffAttendance($db, $id, $version, (int)$user['id'], $attendanceRole);
            else deleteBioAttendance($db, $id, $version, (int)$user['id'], $attendanceRole);
            logActivity('Deleted ' . $attendanceLabel . ' attendance for ' . $date, 'Attendance', 'Success', $db, $user);
            $_SESSION['attendance_flash'] = 'Attendance record deleted.';
            header('Location: ' . $returnUrl); exit();
        }
        if ($action === 'save') {
            if (!attendanceValidDate($_POST['attendance_date'] ?? null)) throw new InvalidArgumentException('Choose a valid attendance date.');
            if ($isCollectorSelf) {
                $entry = $_POST['self_attendance'] ?? [];
                // The shared roster markup retains its existing field names; discard
                // its posted collector key and always derive identity from the session.
                if (!is_array($entry) || !$entry) {
                    $postedEntries = $_POST['attendance'] ?? [];
                    $entry = is_array($postedEntries) ? (reset($postedEntries) ?: []) : [];
                }
                if (!is_array($entry)) throw new InvalidArgumentException('Invalid attendance entry.');
                $changed = saveOwnBioAttendance($db, (int)$user['id'], $date, $entry);
            } else {
                $entries = $_POST['attendance'] ?? [];
                if (!is_array($entries)) throw new InvalidArgumentException('Invalid attendance entries.');
                $changed = $isStaffAttendance ? saveStaffAttendance($db, $date, $entries, (int)$user['id']) : saveBioAttendance($db, $date, $entries, (int)$user['id']);
            }
            if ($changed) logActivity('Saved ' . $attendanceLabel . ' attendance for ' . $date . ' (' . $changed . ' records)', 'Attendance', 'Success', $db, $user);
            $_SESSION['attendance_flash'] = $changed ? $changed . ' attendance record' . ($changed === 1 ? ' saved.' : 's saved.') : 'No attendance changes to save.';
            header('Location: ' . $returnUrl); exit();
        }
        if ($action === 'export') {
            if (!$isAdmin) { http_response_code(403); throw new RuntimeException('Administrator access required.', 403); }
            $settings = getUserSettings($db, (int)$user['id']);
            $otp = trim((string)($_POST['export_otp'] ?? ''));
            if (empty($settings['data_export_enabled'])) {
                $error = 'Attendance export is disabled in your Data Privacy settings.';
            } elseif (!verifyAndConsumeDataExportOtp($db, $user, $otp)) {
                $pending = false;
                $otpRequired = ensurePendingDataExportOtp($db, $user, $pending);
                $error = !$otpRequired ? 'A verification code could not be sent.' : ($otp !== '' ? 'The verification code is invalid.' : 'Enter the verification code sent to your email to export.');
            } else {
                $exportRows = [];
                $exportPage = 1;
                do {
                    $batch = $queryHistory($db, $historyDate, $historySearch, $historyStatus, $exportPage, 200);
                    foreach ($batch['rows'] as $row) {
                        $exportRows[] = [$row['name'], $row['attendance_date'], $statuses[$row['status']] ?? $row['status'], $row['remarks'] ?? '', $row['recorder'] ?? '', $row['editor'] ?? $row['recorder'] ?? '', $row['updated_at']];
                    }
                    $exportPage++;
                } while ($exportPage <= $batch['pages']);
                logActivity('Exported ' . $attendanceLabel . ' attendance', 'Attendance', 'Success', $db, $user);
                $headers = [$isStaffAttendance ? 'Staff' : 'Collector', 'Date', 'Status', 'Remarks', 'Recorded by', 'Updated by', 'Updated'];
                ecoTrackDownloadXlsx(($isStaffAttendance ? 'staff-attendance-history-' : 'bio-attendance-history-') . $date, 'Attendance', $headers, $exportRows, ['Generated' => date('Y-m-d H:i:s')]);
                exit();
            }
        } elseif ($action !== 'save' && $action !== 'delete') {
            throw new InvalidArgumentException('Invalid attendance action.');
        }
    }
    if ($isCollectorSelf) {
        $result = ownBioAttendanceQuery($db, (int)$user['id'], $date);
    } else {
        $result = $queryAttendance($db, $date, $search, $statusFilter, $page, $isCollectorShared ? 200 : 30);
        if (!$isCollectorAccount) $history = $queryHistory($db, $historyDate, $historySearch, $historyStatus, $historyPage);
    }
} catch (Throwable $e) {
    if ($isPrint) {
        http_response_code($e instanceof InvalidArgumentException ? 400 : 500);
        error_log($attendanceLabel . ' attendance print error: ' . $e->getMessage());
        echo $e instanceof InvalidArgumentException ? 'Choose a valid print period and date.' : 'Attendance print could not be loaded. Please try again.';
        exit();
    }
    if ((int)$e->getCode() === 403 || (int)$e->getCode() === 409) http_response_code((int)$e->getCode());
    $error = ($e instanceof InvalidArgumentException || in_array((int)$e->getCode(), [403, 409], true)) ? $e->getMessage() : 'Attendance data could not be loaded or saved. Please try again.';
    error_log($attendanceLabel . ' attendance error: ' . $e->getMessage());
    // On a rejected update, reload the latest committed rows for a safe retry.
    if ($db && $_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            if ($isCollectorSelf) {
                $result = ownBioAttendanceQuery($db, (int)$user['id'], $date);
            } else {
                $result = $queryAttendance($db, $date, $search, $statusFilter, $page, $isCollectorShared ? 200 : 30);
                if (!$isCollectorAccount) $history = $queryHistory($db, $historyDate, $historySearch, $historyStatus, $historyPage);
            }
        } catch (Throwable $ignored) { /* Keep the original error visible. */ }
    }
}
$page = $result['page'];
$queryValues['page'] = $page;
$queryValues['history_page'] = $history['page'];
$pageUrl = static function (int $target) use ($queryValues, $route): string { $q = $queryValues; $q['page'] = $target; return $route . '?' . http_build_query($q); };
$historyPageUrl = static function (int $target) use ($queryValues, $route): string { $q = $queryValues; $q['history_page'] = $target; return $route . '?' . http_build_query($q) . '#attendance-history'; };
$yearOptions = json_encode($availableYears);
$pageTitle = $isCollectorSelf ? 'My Attendance' : ($isCollectorShared ? 'Collector Attendance' : ($isStaffAttendance ? 'Staff Attendance' : 'Attendance Management'));
$pageSubtitle = $isCollectorSelf
    ? 'Record today\'s attendance.'
    : ($isCollectorShared ? 'Record today\'s attendance for all active Bio Collectors.' : 'Record and review ' . $attendanceLabel . ' attendance.');
$rosterHeading = $isCollectorSelf ? 'Your attendance' : ($isCollectorShared ? 'All Bio Collectors' : ($isStaffAttendance ? 'Staff' : 'Collectors'));
$rosterDescription = $isCollectorSelf
    ? 'Only your attendance can be recorded here.'
    : ($isCollectorShared ? 'This shared account records today\'s attendance for every active Bio Collector.' : 'Changes apply to ' . $date . '. Select Late manually when appropriate.');
?>
<!doctype html>
<html lang="en" class="attendance-root"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= attendanceHtml($pageTitle) ?> - EcoTrack</title>
<?php include __DIR__ . '/theme_head.php'; ?>
<link rel="stylesheet" href="assets/css/ecotrack-theme.css?v=<?= filemtime(__DIR__ . '/../assets/css/ecotrack-theme.css') ?>">
<link rel="stylesheet" href="assets/css/attendance.css?v=<?= filemtime(__DIR__ . '/../assets/css/attendance.css') ?>">
<script defer src="assets/js/year-navigable-picker.js?v=<?= filemtime(__DIR__ . '/../assets/js/year-navigable-picker.js') ?>"></script>
<script defer src="assets/js/attendance.js?v=<?= filemtime(__DIR__ . '/../assets/js/attendance.js') ?>"></script>
</head><body class="attendance-body">
<?php $active_page = $bioRoute; include __DIR__ . '/sidebar.php'; ?>
<main class="main-content"><div class="attendance-page">
  <header class="attendance-header"><div><h1><?= attendanceHtml($pageTitle) ?></h1><p><?= attendanceHtml($pageSubtitle) ?></p></div><?php if (!$isCollectorAccount): ?><a class="attendance-button attendance-button-secondary" href="<?= attendanceHtml($otherRoute) ?>"><?= $isStaffAttendance ? 'Bio Collector attendance' : 'Staff Attendance' ?></a><?php endif; ?></header>
  <?php if ($message): ?><p class="attendance-message" role="status"><?= attendanceHtml($message) ?></p><?php endif; ?>
  <?php if ($error): ?><p class="attendance-error" role="alert"><?= attendanceHtml($error) ?></p><?php endif; ?>
  <section class="attendance-card" aria-labelledby="attendance-filters-heading">
    <h2 id="attendance-filters-heading">Attendance for <?= attendanceHtml($date) ?></h2>
    <form method="get" action="<?= attendanceHtml($route) ?>" class="attendance-toolbar" id="attendance-filter-form">
      <?php if (!$isCollectorAccount): ?><?php foreach (['history_date' => $historyDate, 'history_search' => $historySearch, 'history_status' => $historyStatus, 'history_page' => $historyPage] as $key => $value): ?><input type="hidden" name="<?= attendanceHtml($key) ?>" value="<?= attendanceHtml($value) ?>"><?php endforeach; ?><?php endif; ?>
      <?php if ($isCollectorAccount): ?><div class="attendance-field"><label>Attendance date</label><strong><?= attendanceHtml($today) ?></strong><input type="hidden" name="attendance_date" value="<?= attendanceHtml($today) ?>"></div><?php else: ?><div class="attendance-field"><label for="attendance-date">Date</label><input id="attendance-date" type="date" name="attendance_date" value="<?= attendanceHtml($date) ?>" data-year-navigable-picker="date" data-year-picker-today="<?= attendanceHtml($today) ?>" data-calendar-years='<?= attendanceHtml($yearOptions) ?>'></div><?php endif; ?>
      <?php if ($isStaffAttendance): ?>
      <div class="attendance-field attendance-search"><label for="attendance-search"><?= $isStaffAttendance ? 'Staff name' : 'Collector name' ?></label><input id="attendance-search" type="search" name="search" maxlength="100" placeholder="Search by name" value="<?= attendanceHtml($search) ?>"></div>
      <div class="attendance-field"><label for="attendance-status">Status</label><select id="attendance-status" name="status"><option value="">All statuses</option><?php foreach ($statuses as $key => $label): ?><option value="<?= attendanceHtml($key) ?>" <?= $statusFilter === $key ? 'selected' : '' ?>><?= attendanceHtml($label) ?></option><?php endforeach; ?><option value="not_recorded" <?= $statusFilter === 'not_recorded' ? 'selected' : '' ?>>Not Recorded</option></select></div>
      <?php endif; ?>
      <div class="attendance-actions"><?php if (!$isCollectorAccount): ?><button class="attendance-button attendance-button-primary" type="submit"><?= $isStaffAttendance ? 'Apply Filter' : 'View Date' ?></button><?php if ($isStaffAttendance): ?><a class="attendance-button attendance-button-secondary" href="<?= attendanceHtml($route) ?>" data-attendance-navigation>Reset</a><?php endif; ?><?php endif; ?></div>
    </form>
  </section>
  <section class="attendance-grid" aria-label="Attendance summary for <?= $isCollectorSelf ? 'your attendance' : ($isCollectorShared ? 'all active Bio Collectors' : ('matching ' . ($isStaffAttendance ? 'staff' : 'collectors'))) ?>">
    <?php foreach ($statuses as $key => $label): ?><article class="attendance-kpi attendance-<?= attendanceHtml($key) ?>"><strong><?= (int)$result['summary'][$key] ?></strong><span><?= attendanceHtml($label) ?></span></article><?php endforeach; ?>
  </section>
  <p class="attendance-unrecorded"><strong><?= (int)$result['summary']['not_recorded'] ?></strong> Not Recorded · <?= (int)$result['total'] ?> <?= $isCollectorShared ? 'active Bio Collectors' : ($isStaffAttendance ? 'matching staff' : 'matching collectors') ?></p>
  <section class="attendance-card" aria-labelledby="collector-table-heading"><div class="attendance-section-heading"><div><h2 id="collector-table-heading"><?= attendanceHtml($rosterHeading) ?></h2><p><?= attendanceHtml($rosterDescription) ?></p></div></div>
    <form method="post" action="<?= attendanceHtml($route) ?>" id="attendance-save-form" data-transaction-confirm-skip>
      <input type="hidden" name="attendance_csrf" value="<?= attendanceHtml($csrf) ?>"><input type="hidden" name="action" value="save">
      <?php foreach ($queryValues as $key => $value): ?><input type="hidden" name="<?= attendanceHtml($key) ?>" value="<?= attendanceHtml($value) ?>"><?php endforeach; ?>
      <div class="attendance-table-wrap"><table class="attendance-table"><thead><tr><th><?= $isStaffAttendance ? 'Staff' : 'Collector' ?></th><th>Status</th><th>Remarks</th><th>Recorded / updated by</th><?php if ($isAdmin): ?><th>Action</th><?php endif; ?></tr></thead><tbody>
      <?php foreach ($result['rows'] as $row): $id = (int)$row['id']; $recorded = $row['attendance_id'] && $row['deleted_at'] === null; $current = $recorded ? $row['status'] : ''; ?>
        <tr><td class="attendance-name"><?= attendanceHtml($row['name']) ?></td><td><label class="attendance-sr-only" for="attendance-status-<?= $id ?>">Status for <?= attendanceHtml($row['name']) ?></label><input type="hidden" name="attendance[<?= $id ?>][version]" value="<?= (int)($row['version'] ?? 0) ?>"><select id="attendance-status-<?= $id ?>" name="attendance[<?= $id ?>][status]" data-attendance-edit><option value="" <?= $recorded ? 'disabled' : '' ?>>Not Recorded</option><?php foreach ($statuses as $key => $label): ?><option value="<?= attendanceHtml($key) ?>" <?= $current === $key ? 'selected' : '' ?>><?= attendanceHtml($label) ?></option><?php endforeach; ?></select></td><td><label class="attendance-sr-only" for="attendance-remarks-<?= $id ?>">Remarks for <?= attendanceHtml($row['name']) ?></label><input id="attendance-remarks-<?= $id ?>" name="attendance[<?= $id ?>][remarks]" maxlength="500" value="<?= attendanceHtml($recorded ? $row['remarks'] : '') ?>" data-attendance-edit></td><td class="attendance-actors"><?= $recorded ? attendanceHtml(($row['recorder'] ?: 'Unknown') . ' / ' . ($row['editor'] ?: $row['recorder'] ?: 'Unknown')) : '—' ?></td><?php if ($isAdmin): ?><td><?php if ($recorded): ?><button type="button" class="attendance-delete" data-delete-id="<?= (int)$row['attendance_id'] ?>" data-delete-version="<?= (int)$row['version'] ?>" data-delete-name="<?= attendanceHtml($row['name']) ?>">Delete</button><?php else: ?>—<?php endif; ?></td><?php endif; ?></tr>
      <?php endforeach; ?>
      <?php if (!$result['rows']): ?><tr><td colspan="<?= $isAdmin ? 5 : 4 ?>"><?= $isStaffAttendance ? 'No active staff match these filters.' : 'No collectors match these filters. Imported collector names will appear here.' ?></td></tr><?php endif; ?>
      </tbody></table></div>
      <?php if ($result['rows']): ?><div class="attendance-save"><button class="attendance-button attendance-button-primary" type="submit"><?= $isCollectorSelf ? 'Save My Attendance' : ($isCollectorShared ? 'Save Collector Attendance' : 'Save Changes') ?></button></div><?php endif; ?>
    </form>
    <?php if (!$isCollectorAccount): ?><nav class="attendance-pagination" aria-label="<?= $isStaffAttendance ? 'Staff' : 'Collector' ?> pages"><span>Page <?= $page ?> of <?= (int)$result['pages'] ?></span><?php if ($page > 1): ?><a href="<?= attendanceHtml($pageUrl($page - 1)) ?>" data-attendance-navigation>Previous</a><?php endif; ?><?php if ($page < $result['pages']): ?><a href="<?= attendanceHtml($pageUrl($page + 1)) ?>" data-attendance-navigation>Next</a><?php endif; ?></nav><?php endif; ?>
  </section>
  <?php if (!$isCollectorAccount): ?><section class="attendance-card" id="attendance-history" aria-labelledby="attendance-history-heading">
    <div class="attendance-section-heading"><div><h2 id="attendance-history-heading">Attendance history</h2><p>Review saved records, including <?= $isStaffAttendance ? 'staff accounts' : 'collectors' ?> that are no longer active.</p></div></div>
    <form method="get" action="<?= attendanceHtml($route) ?>#attendance-history" class="attendance-toolbar" data-attendance-filter>
      <?php foreach (['attendance_date' => $date, 'search' => $search, 'status' => $statusFilter, 'page' => $page] as $key => $value): ?><input type="hidden" name="<?= attendanceHtml($key) ?>" value="<?= attendanceHtml($value) ?>"><?php endforeach; ?>
      <div class="attendance-field"><label for="history-date">Date</label><input id="history-date" type="date" name="history_date" value="<?= attendanceHtml($historyDate) ?>" data-year-navigable-picker="date" data-year-picker-allow-empty="true" data-year-picker-placeholder="All dates" data-year-picker-today="<?= attendanceHtml($today) ?>" data-calendar-years='<?= attendanceHtml($yearOptions) ?>'></div>
      <div class="attendance-field attendance-search"><label for="history-search"><?= $isStaffAttendance ? 'Staff name' : 'Collector name' ?></label><input id="history-search" type="search" name="history_search" maxlength="100" placeholder="Search by name" value="<?= attendanceHtml($historySearch) ?>"></div>
      <div class="attendance-field"><label for="history-status">Status</label><select id="history-status" name="history_status"><option value="">All statuses</option><?php foreach ($statuses as $key => $label): ?><option value="<?= attendanceHtml($key) ?>" <?= $historyStatus === $key ? 'selected' : '' ?>><?= attendanceHtml($label) ?></option><?php endforeach; ?></select></div>
      <div class="attendance-actions"><button type="submit" class="attendance-button attendance-button-primary">Apply Filter</button><a class="attendance-button attendance-button-secondary" href="<?= attendanceHtml($route . '?' . http_build_query(['attendance_date' => $date, 'search' => $search, 'status' => $statusFilter, 'page' => $page])) ?>#attendance-history" data-attendance-navigation>Reset</a></div>
    </form>
    <div class="attendance-table-wrap"><table class="attendance-table attendance-history-table"><thead><tr><th>Date</th><th><?= $isStaffAttendance ? 'Staff' : 'Collector' ?></th><th>Status</th><th>Remarks</th><th>Recorded / updated by</th><th>Updated</th></tr></thead><tbody>
    <?php foreach ($history['rows'] as $entry): ?><tr><td><?= attendanceHtml($entry['attendance_date']) ?></td><td class="attendance-name"><?= attendanceHtml($entry['name']) ?><?= !$entry['is_active'] ? ' (Inactive)' : '' ?></td><td><?= attendanceHtml($statuses[$entry['status']] ?? $entry['status']) ?></td><td><?= attendanceHtml($entry['remarks'] ?? '') ?></td><td><?= attendanceHtml(($entry['recorder'] ?: 'Unknown') . ' / ' . ($entry['editor'] ?: $entry['recorder'] ?: 'Unknown')) ?></td><td><?= attendanceHtml($entry['updated_at']) ?></td></tr><?php endforeach; ?>
    <?php if (!$history['rows']): ?><tr><td colspan="6">No attendance records match these filters.</td></tr><?php endif; ?>
    </tbody></table></div>
    <nav class="attendance-pagination" aria-label="Attendance history pages"><span><?= (int)$history['total'] ?> records · Page <?= (int)$history['page'] ?> of <?= (int)$history['pages'] ?></span><?php if ($history['page'] > 1): ?><a href="<?= attendanceHtml($historyPageUrl($history['page'] - 1)) ?>" data-attendance-navigation>Previous</a><?php endif; ?><?php if ($history['page'] < $history['pages']): ?><a href="<?= attendanceHtml($historyPageUrl($history['page'] + 1)) ?>" data-attendance-navigation>Next</a><?php endif; ?></nav>
    <form method="get" action="<?= attendanceHtml($route) ?>" target="_blank" class="attendance-toolbar attendance-print-form" aria-label="Print attendance history">
      <input type="hidden" name="view" value="print"><input type="hidden" name="history_search" value="<?= attendanceHtml($historySearch) ?>"><input type="hidden" name="history_status" value="<?= attendanceHtml($historyStatus) ?>">
      <div class="attendance-field"><label for="print-period">Print period</label><select id="print-period" name="print_period"><option value="week">Week</option><option value="month">Month</option></select></div>
      <div class="attendance-field" id="print-week-field"><label for="print-date">Date in week</label><input id="print-date" type="date" name="print_date" value="<?= attendanceHtml($today) ?>" min="1900-01-01" max="2099-12-31" required></div>
      <div class="attendance-field" id="print-month-field" hidden><label for="print-month">Month</label><input id="print-month" type="month" name="print_month" value="<?= attendanceHtml(substr($today, 0, 7)) ?>" min="1900-01" max="2099-12" disabled></div>
      <div class="attendance-actions"><button class="attendance-button attendance-button-primary" type="submit">Print attendance</button></div>
    </form>
    <p class="attendance-print-note">Print uses the selected week or month and the applied name and status filters.</p>
    <?php if ($isAdmin): ?><form method="post" action="<?= attendanceHtml($route) ?>#attendance-history" class="attendance-export" data-transaction-confirm-skip><input type="hidden" name="attendance_csrf" value="<?= attendanceHtml($csrf) ?>"><input type="hidden" name="action" value="export"><?php foreach ($queryValues as $key => $value): ?><input type="hidden" name="<?= attendanceHtml($key) ?>" value="<?= attendanceHtml($value) ?>"><?php endforeach; ?><?php if ($otpRequired): ?><label>Verification code <input type="text" name="export_otp" inputmode="numeric" maxlength="6" required></label><?php endif; ?><button class="btn btn-cancel" type="submit"><?= $otpRequired ? 'Verify and export' : 'Export filtered history' ?> (.xlsx)</button></form><?php endif; ?>
  </section><?php endif; ?>
  <?php if ($isAdmin): ?><dialog id="attendance-delete-dialog" class="attendance-dialog" aria-labelledby="attendance-delete-title"><h2 id="attendance-delete-title">Delete attendance?</h2><p>Delete the attendance record for <strong id="attendance-delete-name"></strong> on <?= attendanceHtml($date) ?>? The <?= $isStaffAttendance ? 'staff account' : 'collector' ?> and other dates will remain.</p><form method="post" action="<?= attendanceHtml($route) ?>" id="attendance-delete-form" data-transaction-confirm-skip><input type="hidden" name="attendance_csrf" value="<?= attendanceHtml($csrf) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="record_id" id="attendance-delete-id"><input type="hidden" name="record_version" id="attendance-delete-version"><?php foreach ($queryValues as $key => $value): ?><input type="hidden" name="<?= attendanceHtml($key) ?>" value="<?= attendanceHtml($value) ?>"><?php endforeach; ?><div class="attendance-dialog-actions"><button type="button" class="btn btn-cancel" data-close-delete>Cancel</button><button type="submit" class="btn attendance-confirm-delete">Confirm Delete</button></div></form></dialog><?php endif; ?>
</div></main></body></html>

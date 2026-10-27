<?php
// Included only after the authenticated attendance route validates the requested range.
?>
<!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= attendanceHtml($attendanceLabel) ?> attendance history</title>
<style>
  *{box-sizing:border-box}body{font:14px/1.4 Arial,sans-serif;color:#17211d;margin:24px}h1{font-size:22px;margin:0 0 8px}p{margin:4px 0}.print-actions{margin:18px 0}.print-actions button{padding:10px 18px;font:inherit;cursor:pointer}.print-table-wrap{overflow-x:auto}table{width:100%;border-collapse:collapse;margin-top:16px}th,td{padding:8px;border:1px solid #999;text-align:left;vertical-align:top;overflow-wrap:anywhere}th{background:#e2eee8}thead{display:table-header-group}tr{break-inside:avoid}.empty{text-align:center}
  @media print{@page{size:landscape;margin:12mm}body{margin:0;font-size:10pt}.print-actions{display:none}.print-table-wrap{overflow:visible}th{background:#e2eee8;print-color-adjust:exact}}
  @media screen and (max-width:700px){body{margin:16px}table{min-width:720px}}
</style>
<script>window.addEventListener('load', function () { window.print(); });</script>
</head><body>
<h1><?= attendanceHtml($attendanceLabel) ?> attendance history</h1>
<p><strong><?= $printPeriod === 'week' ? 'Week' : 'Month' ?>:</strong> <?= attendanceHtml($printStart) ?> to <?= attendanceHtml($printEnd) ?></p>
<p><strong>Name:</strong> <?= attendanceHtml($historySearch !== '' ? $historySearch : 'All names') ?> &nbsp; <strong>Status:</strong> <?= attendanceHtml($historyStatus !== '' ? ($statuses[$historyStatus] ?? $historyStatus) : 'All statuses') ?></p>
<p><?= count($printRows) ?> record<?= count($printRows) === 1 ? '' : 's' ?></p>
<div class="print-actions"><button type="button" onclick="window.print()">Print attendance</button></div>
<div class="print-table-wrap"><table><thead><tr><th>Date</th><th><?= $isStaffAttendance ? 'Staff' : 'Collector' ?></th><th>Status</th><th>Remarks</th><th>Recorded / updated by</th><th>Updated</th></tr></thead><tbody>
<?php foreach ($printRows as $entry): ?><tr><td><?= attendanceHtml($entry['attendance_date']) ?></td><td><?= attendanceHtml($entry['name']) ?><?= !$entry['is_active'] ? ' (Inactive)' : '' ?></td><td><?= attendanceHtml($statuses[$entry['status']] ?? $entry['status']) ?></td><td><?= attendanceHtml($entry['remarks'] ?? '') ?></td><td><?= attendanceHtml(($entry['recorder'] ?: 'Unknown') . ' / ' . ($entry['editor'] ?: $entry['recorder'] ?: 'Unknown')) ?></td><td><?= attendanceHtml($entry['updated_at']) ?></td></tr><?php endforeach; ?>
<?php if (!$printRows): ?><tr><td colspan="6" class="empty">No attendance records match this period and filters.</td></tr><?php endif; ?>
</tbody></table></div>
</body></html>

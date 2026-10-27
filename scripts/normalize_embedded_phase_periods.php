<?php

/** Repair legacy Collection Group values that contain both Phase and period.
 *
 * Usage:
 *   C:\xampp\php\php.exe scripts\normalize_embedded_phase_periods.php
 *   C:\xampp\php\php.exe scripts\normalize_embedded_phase_periods.php --apply
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This migration must be run from the command line.\n");
    exit(1);
}

require_once __DIR__ . '/../config.php';

$apply = in_array('--apply', $argv, true);
$conn = getDBConnection();
if (!$conn) {
    fwrite(STDERR, "Could not connect to the EcoTrack database.\n");
    exit(1);
}

ensureWasteFormatColumns($conn);
ensureWasteImportTables($conn);
$rows = $conn->query("SELECT * FROM waste_records WHERE collection_group LIKE 'Phase%' ORDER BY id")->fetchAll();
$repairs = [];

foreach ($rows as $record) {
    $savedGroup = trim((string)($record['collection_group'] ?? ''));
    [$group, $period] = splitWasteCollectionGroupAndPeriod($savedGroup);
    $savedPeriod = trim((string)($record['reporting_period'] ?? ''));
    if (
        $group === '' || $period === '' || $group === $savedGroup
        || ($savedPeriod !== '' && $savedPeriod !== $savedGroup)
    ) {
        continue;
    }

    $record['collection_group'] = $group;
    $record['collection_group_type'] = 'phase';
    $record['phase_number'] = substr($group, 0, 50);
    $record['reporting_period'] = $period;
    $record['phase_date_label'] = buildWasteCollectionSourceLabel($group, $period);
    $record['record_fingerprint'] = buildWasteRecordFingerprint($record);
    $repairs[] = $record;
}

echo 'Found ' . count($repairs) . " embedded Phase/period record(s).\n";
foreach ($repairs as $record) {
    echo '  #' . $record['id'] . ' ' . ($record['street'] ?? '') . ' -> '
        . $record['collection_group'] . ' (' . $record['reporting_period'] . ")\n";
}

if (!$apply) {
    echo "Dry run only. Re-run with --apply after reviewing this report.\n";
    exit(0);
}

try {
    $conn->beginTransaction();
    $update = $conn->prepare('UPDATE waste_records
        SET collection_group = ?, collection_group_type = ?, phase_number = ?, reporting_period = ?, phase_date_label = ?, record_fingerprint = ?
        WHERE id = ?');
    foreach ($repairs as $record) {
        $update->execute([
            $record['collection_group'], $record['collection_group_type'], $record['phase_number'],
            $record['reporting_period'], $record['phase_date_label'], $record['record_fingerprint'], (int)$record['id'],
        ]);
    }
    touchWasteDataVersion($conn);
    $conn->commit();

    // The fingerprint registry is derived data; rebuild it after the repaired
    // canonical labels are saved so future duplicate checks use the same keys.
    $conn->exec('DELETE FROM waste_record_fingerprints');
    $conn->exec("UPDATE waste_import_backfill_state SET last_record_id = 0, completed_at = NULL WHERE state_key = 'record_fingerprints'");
    $rebuilt = backfillWasteRecordFingerprints($conn, 1000);
    echo 'Applied ' . count($repairs) . ' repair(s); rebuilt ' . $rebuilt . " fingerprint record(s).\n";
} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . "\n");
    exit(1);
}

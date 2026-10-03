<?php

/** Repair generic Friday-Saturday 3-Day Block labels.
 *
 * Usage:
 *   C:\xampp\php\php.exe scripts\migrate_three_day_collection_groups.php          # preview only
 *   C:\xampp\php\php.exe scripts\migrate_three_day_collection_groups.php --apply  # write changes
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
$rules = loadWasteCollectionGroupRules($conn);

$find = $conn->query("SELECT * FROM waste_records
    WHERE LOWER(CONCAT_WS(' ', collection_group, phase_number, phase_date_label)) LIKE '%3-day block%'
      AND LOWER(CONCAT_WS(' ', collection_group, phase_number, phase_date_label)) LIKE '%friday%'
      AND LOWER(CONCAT_WS(' ', collection_group, phase_number, phase_date_label)) LIKE '%saturday%'
    ORDER BY id");
$targets = $find->fetchAll();
$resolved = [];
$unresolved = [];
foreach ($targets as $record) {
    $group = resolveWasteCollectionGroup($record['street'] ?? '', '', $rules);
    if (!$group['resolved']) {
        $unresolved[] = ['id' => (int)$record['id'], 'street' => $record['street'] ?? ''];
        continue;
    }
    $resolved[] = [$record, $group];
}

echo 'Found ' . count($targets) . " Friday-Saturday 3-Day Block record(s).\n";
echo 'Resolved: ' . count($resolved) . '; unresolved: ' . count($unresolved) . ".\n";
foreach ($resolved as [$record, $group]) {
    echo '  #' . $record['id'] . ' ' . ($record['street'] ?? '') . ' -> ' . $group['collection_group'] . ' (' . $group['collection_group_type'] . ")\n";
}
foreach ($unresolved as $record) {
    echo '  UNRESOLVED #' . $record['id'] . ' ' . $record['street'] . "\n";
}

if (!$apply) {
    echo "Dry run only. Re-run with --apply after reviewing this report.\n";
    exit(empty($unresolved) ? 0 : 2);
}
if (!empty($unresolved)) {
    fwrite(STDERR, "No records were changed because one or more areas have no grouping rule.\n");
    exit(2);
}

try {
    $conn->beginTransaction();
    $update = $conn->prepare("UPDATE waste_records
        SET collection_group = ?, collection_group_type = ?, phase_number = ?, phase_date_label = ?, record_fingerprint = ?
        WHERE id = ?");
    foreach ($resolved as [$record, $group]) {
        $record['collection_group'] = $group['collection_group'];
        $record['collection_group_type'] = $group['collection_group_type'];
        $record['phase_number'] = substr($group['collection_group'], 0, 50);
        $record['phase_date_label'] = buildWasteCollectionSourceLabel($group['collection_group'], $record['reporting_period'] ?? '');
        $record['record_fingerprint'] = buildWasteRecordFingerprint($record);
        $update->execute([
            $record['collection_group'], $record['collection_group_type'], $record['phase_number'],
            $record['phase_date_label'], $record['record_fingerprint'], (int)$record['id'],
        ]);
    }
    touchWasteDataVersion($conn);
    $conn->commit();

    // The registry is derived data. Rebuild it so the repaired values take
    // effect for content-based duplicate checks.
    $conn->exec('DELETE FROM waste_record_fingerprints');
    $conn->exec("UPDATE waste_import_backfill_state SET last_record_id = 0, completed_at = NULL WHERE state_key = 'record_fingerprints'");
    $rebuilt = backfillWasteRecordFingerprints($conn, 1000);
    echo 'Applied ' . count($resolved) . ' repair(s); rebuilt ' . $rebuilt . " fingerprint record(s).\n";
} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . "\n");
    exit(1);
}

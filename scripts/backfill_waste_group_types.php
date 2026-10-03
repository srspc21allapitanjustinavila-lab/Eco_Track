<?php

/** Classify legacy Collection Groups and rebuild content fingerprints. */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This maintenance command must be run from the command line.\n");
    exit(1);
}
require_once __DIR__ . '/../config.php';

$apply = in_array('--apply', $argv, true);
$conn = getDBConnection();
if (!$conn) {
    fwrite(STDERR, "Could not connect to the EcoTrack database.\n");
    exit(1);
}
ensureWasteImportTables($conn);
$rules = loadWasteCollectionGroupRules($conn);
$rows = $conn->query("SELECT * FROM waste_records WHERE collection_group_type IS NULL OR collection_group_type = '' ORDER BY id")->fetchAll();
$changes = [];
foreach ($rows as $row) {
    $type = inferWasteCollectionGroupType($row['collection_group'] ?? '');
    if ($type === '') {
        $resolved = resolveWasteCollectionGroup($row['street'] ?? '', '', $rules);
        $type = $resolved['collection_group_type'] ?? '';
    }
    if ($type !== '') {
        $changes[] = [(int)$row['id'], $type];
    }
}
echo 'Legacy rows without a group type: ' . count($rows) . '; classifiable: ' . count($changes) . ".\n";
if (!$apply) {
    echo "Dry run only. Re-run with --apply to classify and rebuild fingerprints.\n";
    exit(0);
}

try {
    $conn->beginTransaction();
    $update = $conn->prepare('UPDATE waste_records SET collection_group_type = ? WHERE id = ?');
    foreach ($changes as [$id, $type]) {
        $update->execute([$type, $id]);
    }
    touchWasteDataVersion($conn);
    $conn->commit();

    // Registry values use the same record fingerprint.
    $conn->exec('DELETE FROM waste_record_fingerprints');
    $conn->exec("UPDATE waste_import_backfill_state SET last_record_id = 0, completed_at = NULL WHERE state_key = 'record_fingerprints'");
    // Force refreshed values into waste_records, not just the registry.
    $select = $conn->query('SELECT * FROM waste_records ORDER BY id');
    $updateFingerprint = $conn->prepare('UPDATE waste_records SET record_fingerprint = ? WHERE id = ?');
    foreach ($select->fetchAll() as $row) {
        $updateFingerprint->execute([buildWasteRecordFingerprint($row), (int)$row['id']]);
    }
    $count = backfillWasteRecordFingerprints($conn, 1000);
    echo 'Classified ' . count($changes) . ' rows and rebuilt ' . $count . " fingerprints.\n";
} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    fwrite(STDERR, 'Backfill failed: ' . $e->getMessage() . "\n");
    exit(1);
}

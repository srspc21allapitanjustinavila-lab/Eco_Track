<?php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This maintenance command must be run from the command line.\n");
    exit(1);
}

require_once __DIR__ . '/../config.php';

$conn = getDBConnection();
if (!$conn) {
    fwrite(STDERR, "Could not connect to the EcoTrack database.\n");
    exit(1);
}

ensureWasteFormatColumns($conn);
ensureWasteImportTables($conn);

try {
    $processed = backfillWasteRecordFingerprints($conn, 500);
    echo 'Fingerprint backfill complete. Processed ' . $processed . " waste record(s).\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Fingerprint backfill failed: ' . $e->getMessage() . "\n");
    exit(1);
}

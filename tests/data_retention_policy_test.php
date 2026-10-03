<?php

$source = file_get_contents(__DIR__ . '/../config.php');

if (
    !str_contains($source, 'INSERT INTO waste_records_archive (source_record_id, archived_by, record_payload)')
    || str_contains($source, 'purgeExpiredWasteRecordArchives')
    || str_contains($source, 'getDataRetentionPolicy')
) {
    throw new RuntimeException('Deleted Waste Data must be archived without a retention expiry.');
}

echo "PASS: deleted Waste Data remains archived without retention expiry\n";

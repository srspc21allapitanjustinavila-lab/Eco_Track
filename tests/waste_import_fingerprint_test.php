<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/waste_analytics.php';

function checkImportFingerprint($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }
    echo "PASS: $message\n";
}

$uploadPage = file_get_contents(__DIR__ . '/../waste_import.php');
$helpersStart = strpos($uploadPage, 'function normalizeImportWasteAmount');
$requestHandlerStart = strpos($uploadPage, "\n// Handle the first file and create its reviewable preview.", $helpersStart);
checkImportFingerprint($helpersStart !== false && $requestHandlerStart !== false, 'Bulk import helpers are available for fingerprint testing.');
eval(substr($uploadPage, $helpersStart, $requestHandlerStart - $helpersStart));

$baseRecord = [
    'collection_date' => '2024-11-11',
    'collection_group' => 'Phase 1-A',
    'reporting_period' => 'Nov 11-16, 2024',
    'street' => 'Curie St.',
    'name_of_bioman' => 'Marieta De Paz',
    'households' => '44',
    'comply_tue' => '40',
    'cd_processing' => '87',
    'comply_wed' => '-',
    'comply_fri' => '43',
    'tuesday_factory_returnable_kg' => 137,
    'wednesday_biowaste_kg' => 0,
    'thursday_factory_returnable_kg' => 0,
    'friday_biowaste_kg' => 269,
    'saturday_hazard_waste_kg' => 2,
    'residual_waste_kg' => 40,
];
$formatVariant = $baseRecord;
$formatVariant['street'] = '  curie   st.  ';
$formatVariant['name_of_bioman'] = 'MARIETA DE PAZ';
$formatVariant['tuesday_factory_returnable_kg'] = '137.000';
checkImportFingerprint(
    buildWasteRecordFingerprint($baseRecord) === buildWasteRecordFingerprint($formatVariant),
    'Record fingerprints ignore case, spacing, and equivalent numeric formatting.'
);

$changedRecord = $baseRecord;
$changedRecord['residual_waste_kg'] = 41;
checkImportFingerprint(
    buildWasteRecordFingerprint($baseRecord) !== buildWasteRecordFingerprint($changedRecord),
    'Record fingerprints change when a waste measurement changes.'
);
$unclassifiedVariant = $baseRecord;
$unclassifiedVariant['unclassified_waste_kg'] = 12;
checkImportFingerprint(
    buildWasteRecordFingerprint($baseRecord) !== buildWasteRecordFingerprint($unclassifiedVariant),
    'Unclassified totals participate in duplicate fingerprints.'
);

checkImportFingerprint(
    buildWasteImportDatasetHash(['fingerprint-b', 'fingerprint-a', 'fingerprint-a'])
        === buildWasteImportDatasetHash(['fingerprint-a', 'fingerprint-b']),
    'Dataset hashes ignore file row order and repeated identical rows.'
);

$mapped = buildWasteImportDatabaseRecord(
    ['Marieta De Paz', 'Curie St.', '44', '137', '40', '87', '-', '-', '-', '269', '43', '2', '40'],
    'Phase 1-A (Nov 11-16, 2024)'
);
checkImportFingerprint(
    $mapped !== null
    && $mapped['collection_date'] === '2024-11-11'
    && $mapped['kilogram_of_waste'] === 448.0
    && $mapped['record_fingerprint'] === buildWasteRecordFingerprint($mapped),
    'Normalized import records calculate database totals and their stable fingerprint once.'
);
$unclassifiedMapped = buildWasteImportDatabaseRecord(
    ['Marieta De Paz', 'Curie St.', '44', '', '', '', '', '', '', '', '', '', '', '12'],
    'Phase 1-A (Nov 11-16, 2024)'
);
checkImportFingerprint(
    $unclassifiedMapped !== null
    && $unclassifiedMapped['unclassified_waste_kg'] === 12.0
    && $unclassifiedMapped['kilogram_of_waste'] === 12.0,
    'Generic totals persist as unclassified waste instead of residual waste.'
);

$preview = wasteImportPreviewRow($mapped);
checkImportFingerprint(
    count($preview) === 16
    && $preview[0] === 'Phase 1-A'
    && $preview[3] === 'Curie St.',
    'Preview rows are generated from staged normalized records rather than session row data.'
);

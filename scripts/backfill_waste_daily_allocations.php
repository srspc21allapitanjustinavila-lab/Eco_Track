<?php

/**
 * Create daily allocations for existing source rows without modifying those
 * source rows. Run from the project root with:
 * C:\xampp\php\php.exe scripts\backfill_waste_daily_allocations.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/waste_analytics.php';

$conn = getDBConnection();
if (!$conn) {
    fwrite(STDERR, "Database connection unavailable.\n");
    exit(1);
}

ensureWasteFormatColumns($conn);
ensureWasteAnalyticsColumns($conn);
ensureWasteDailyAllocationTable($conn);

$batchSize = 500;
foreach ($argv as $argument) {
    if (preg_match('/^--batch=(\d+)$/', $argument, $matches)) {
        $batchSize = max(1, min(5000, (int)$matches[1]));
    }
}

$lastId = 0;
$processed = 0;
$allocationCount = 0;
$unparseable = [];
$select = $conn->prepare('SELECT wr.* FROM waste_records wr WHERE wr.id > ? ORDER BY wr.id ASC LIMIT ' . $batchSize);

while (true) {
    $select->execute([$lastId]);
    $records = $select->fetchAll();
    if (empty($records)) {
        break;
    }
    $conn->beginTransaction();
    try {
        foreach ($records as $record) {
            $lastId = (int)$record['id'];
            $processed++;
            if (empty(buildWasteDailyAllocations($record))) {
                $unparseable[] = $lastId;
                continue;
            }
            $allocationCount += storeWasteDailyAllocations($conn, $record);
        }
        $conn->commit();
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }
}

touchWasteDataVersion($conn);
echo 'Processed ' . $processed . ' source records; stored ' . $allocationCount . ' daily allocations.' . PHP_EOL;
if (!empty($unparseable)) {
    echo 'Skipped unparseable source record IDs: ' . implode(', ', $unparseable) . PHP_EOL;
}

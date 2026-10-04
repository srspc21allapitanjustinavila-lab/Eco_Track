<?php

$password = getenv('RAILWAY_DB_PASSWORD');

if (!$password) {
    exit("RAILWAY_DB_PASSWORD is not set\n");
}

try {
    /*
     * LOCAL SOURCE
     */
    $local = new PDO(
        'mysql:host=127.0.0.1;port=3306;dbname=ecotrack_db;charset=utf8mb4',
        'root',
        '',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    /*
     * RAILWAY DESTINATION
     */
    $railway = new PDO(
        'mysql:host=127.0.0.1;port=51606;dbname=ecotrack_db;charset=utf8mb4',
        'root',
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    echo "LOCAL_CONNECTED\n";
    echo "RAILWAY_CONNECTED\n";

    /*
     * ------------------------------------------------------------
     * 1. WASTE RECORDS
     * ------------------------------------------------------------
     */

    echo "\n=== WASTE RECORDS ===\n";

    $sourceColumns = $local
        ->query("SHOW COLUMNS FROM waste_records")
        ->fetchAll();

    $destinationColumns = $railway
        ->query("SHOW COLUMNS FROM waste_records")
        ->fetchAll();

    $sourceNames = array_column($sourceColumns, 'Field');
    $destinationNames = array_column($destinationColumns, 'Field');

    $commonColumns = array_values(
        array_intersect($sourceNames, $destinationNames)
    );

    if (!$commonColumns) {
        throw new RuntimeException(
            "No common columns found for waste_records."
        );
    }

    echo "COMMON_COLUMNS=" . count($commonColumns) . "\n";

    $columnList = implode(
        ', ',
        array_map(
            fn ($column) => "$column",
            $commonColumns
        )
    );

    $placeholders = implode(
        ', ',
        array_fill(0, count($commonColumns), '?')
    );

    $localRows = $local
        ->query(
            "SELECT $columnList
             FROM waste_records
             ORDER BY id"
        )
        ->fetchAll();

    echo "LOCAL_ROWS=" . count($localRows) . "\n";

    $railwayExisting = (int) $railway
        ->query("SELECT COUNT(*) FROM waste_records")
        ->fetchColumn();

    echo "RAILWAY_EXISTING_ROWS=$railwayExisting\n";

    if ($railwayExisting !== 0) {
        throw new RuntimeException(
            "Railway waste_records is not empty. Migration stopped for safety."
        );
    }

    $insert = $railway->prepare(
        "INSERT INTO waste_records
         ($columnList)
         VALUES ($placeholders)"
    );

    $railway->beginTransaction();

    $copiedWasteRecords = 0;

    foreach ($localRows as $row) {
        $values = [];

        foreach ($commonColumns as $column) {
            $values[] = $row[$column];
        }

        $insert->execute($values);
        $copiedWasteRecords++;
    }

    $railway->commit();

    echo "COPIED_WASTE_RECORDS=$copiedWasteRecords\n";

    /*
     * ------------------------------------------------------------
     * 2. WASTE RECORDS ARCHIVE
     * ------------------------------------------------------------
     */

    echo "\n=== WASTE RECORDS ARCHIVE ===\n";

    $sourceColumns = $local
        ->query("SHOW COLUMNS FROM waste_records_archive")
        ->fetchAll();

    $destinationColumns = $railway
        ->query("SHOW COLUMNS FROM waste_records_archive")
        ->fetchAll();

    $sourceNames = array_column($sourceColumns, 'Field');
    $destinationNames = array_column($destinationColumns, 'Field');

    $commonColumns = array_values(
        array_intersect($sourceNames, $destinationNames)
    );

    if (!$commonColumns) {
        throw new RuntimeException(
            "No common columns found for waste_records_archive."
        );
    }

    echo "COMMON_COLUMNS=" . count($commonColumns) . "\n";

    $columnList = implode(
        ', ',
        array_map(
            fn ($column) => "$column",
            $commonColumns
        )
    );

    $placeholders = implode(
        ', ',
        array_fill(0, count($commonColumns), '?')
    );

    $localRows = $local
        ->query(
            "SELECT $columnList
             FROM waste_records_archive
             ORDER BY id"
        )
        ->fetchAll();

    echo "LOCAL_ROWS=" . count($localRows) . "\n";

    $railwayExisting = (int) $railway
        ->query("SELECT COUNT(*) FROM waste_records_archive")
        ->fetchColumn();

    echo "RAILWAY_EXISTING_ROWS=$railwayExisting\n";

    if ($railwayExisting !== 0) {
        throw new RuntimeException(
            "Railway waste_records_archive is not empty. Migration stopped for safety."
        );
    }

    $insert = $railway->prepare(
        "INSERT INTO waste_records_archive
         ($columnList)
         VALUES ($placeholders)"
    );

    $railway->beginTransaction();

    $copiedArchive = 0;

    foreach ($localRows as $row) {
        $values = [];

        foreach ($commonColumns as $column) {
            $values[] = $row[$column];
        }

        $insert->execute($values);
        $copiedArchive++;
    }

    $railway->commit();

    echo "COPIED_WASTE_RECORDS_ARCHIVE=$copiedArchive\n";

    /*
     * ------------------------------------------------------------
     * 3. FINAL VERIFICATION
     * ------------------------------------------------------------
     */

    echo "\n=== FINAL VERIFICATION ===\n";

    $localWaste = (int) $local
        ->query("SELECT COUNT(*) FROM waste_records")
        ->fetchColumn();

    $railwayWaste = (int) $railway
        ->query("SELECT COUNT(*) FROM waste_records")
        ->fetchColumn();

    $localArchive = (int) $local
        ->query("SELECT COUNT(*) FROM waste_records_archive")
        ->fetchColumn();

    $railwayArchive = (int) $railway
        ->query("SELECT COUNT(*) FROM waste_records_archive")
        ->fetchColumn();

    echo "LOCAL_WASTE_RECORDS=$localWaste\n";
    echo "RAILWAY_WASTE_RECORDS=$railwayWaste\n";

    echo "LOCAL_WASTE_ARCHIVE=$localArchive\n";
    echo "RAILWAY_WASTE_ARCHIVE=$railwayArchive\n";

    if (
        $localWaste !== $railwayWaste ||
        $localArchive !== $railwayArchive
    ) {
        throw new RuntimeException(
            "FINAL COUNT VERIFICATION FAILED."
        );
    }

    echo "\nMIGRATION_SUCCESS\n";

} catch (Throwable $e) {

    if (isset($railway) && $railway->inTransaction()) {
        $railway->rollBack();
    }

    echo "\nMIGRATION_FAILED\n";
    echo "ERROR=" . $e->getMessage() . "\n";

    exit(1);
}
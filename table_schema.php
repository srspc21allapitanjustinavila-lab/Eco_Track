<?php

$password = getenv('RAILWAY_DB_PASSWORD');

if (!$password) {
    exit("RAILWAY_DB_PASSWORD is not set\n");
}

try {
    $local = new PDO(
        'mysql:host=127.0.0.1;port=3306;dbname=ecotrack_db;charset=utf8mb4',
        'root',
        '',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    $railway = new PDO(
        'mysql:host=127.0.0.1;port=51606;dbname=ecotrack_db;charset=utf8mb4',
        'root',
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    foreach (['waste_records', 'waste_records_archive'] as $table) {

        echo "\n========================================\n";
        echo "TABLE: $table\n";
        echo "========================================\n";

        echo "\n--- LOCAL ---\n";

        $localColumns = $local
            ->query("SHOW COLUMNS FROM $table")
            ->fetchAll();

        foreach ($localColumns as $column) {
            echo $column['Field']
                . " | "
                . $column['Type']
                . " | NULL="
                . $column['Null']
                . " | DEFAULT="
                . ($column['Default'] ?? 'NULL')
                . "\n";
        }

        echo "\n--- RAILWAY ---\n";

        $railwayColumns = $railway
            ->query("SHOW COLUMNS FROM $table")
            ->fetchAll();

        foreach ($railwayColumns as $column) {
            echo $column['Field']
                . " | "
                . $column['Type']
                . " | NULL="
                . $column['Null']
                . " | DEFAULT="
                . ($column['Default'] ?? 'NULL')
                . "\n";
        }
    }

    echo "\nSCHEMA INSPECTION COMPLETE\n";

} catch (Throwable $e) {
    echo "INSPECTION_FAILED\n";
    echo $e->getMessage() . "\n";
    exit(1);
}
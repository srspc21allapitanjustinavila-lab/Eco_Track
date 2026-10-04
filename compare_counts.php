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

    $localTables = $local->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

    echo "TABLE | LOCAL | RAILWAY\n";
    echo str_repeat("-", 70) . "\n";

    foreach ($localTables as $table) {
        $safeTable = str_replace('`', '``', $table);

        $localCount = $local
            ->query("SELECT COUNT(*) FROM $safeTable")
            ->fetchColumn();

        $railwayCount = $railway
            ->query("SELECT COUNT(*) FROM $safeTable")
            ->fetchColumn();

        echo $table . " | " . $localCount . " | " . $railwayCount . "\n";
    }

    echo "\nCOUNT CHECK COMPLETE\n";

} catch (Throwable $e) {
    echo "CHECK_FAILED\n";
    echo $e->getMessage() . "\n";
    exit(1);
}
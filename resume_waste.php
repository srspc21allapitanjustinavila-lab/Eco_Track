<?php

$railwayPassword = getenv('RAILWAY_DB_PASSWORD');

if (!$railwayPassword) {
    exit("RAILWAY_DB_PASSWORD is not set\n");
}

function safeTable(string $table): string
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        throw new RuntimeException("Unsafe table name: " . $table);
    }

    return $table;
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
        $railwayPassword,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    echo "LOCAL_CONNECTED\n";
    echo "RAILWAY_CONNECTED\n";

    $tables = [
        'waste_records',
        'waste_records_archive',
    ];

    $railway->exec("SET FOREIGN_KEY_CHECKS=0");

    foreach ($tables as $table) {
        $table = safeTable($table);

        echo "CHECKING_SCHEMA=$table\n";

        $localColumns = $local
            ->query("SHOW COLUMNS FROM $table")
            ->fetchAll();

        $railwayColumns = $railway
            ->query("SHOW COLUMNS FROM $table")
            ->fetchAll();

        $localNames = array_column($localColumns, 'Field');
        $railwayNames = array_column($railwayColumns, 'Field');

        if ($localNames !== $railwayNames) {
            echo "SCHEMA_MISMATCH=$table\n";
            echo "LOCAL_COLUMNS=" . implode(',', $localNames) . "\n";
            echo "RAILWAY_COLUMNS=" . implode(',', $railwayNames) . "\n";
            throw new RuntimeException("Schema mismatch for $table");
        }

        echo "SCHEMA_OK=$table COLUMNS=" . count($localNames) . "\n";

        echo "CLEARING=$table\n";
        $railway->exec("DELETE FROM $table");

        $placeholders = implode(
            ', ',
            array_fill(0, count($localNames), '?')
        );

        $insert = $railway->prepare(
            "INSERT INTO $table VALUES ($placeholders)"
        );

        $rows = $local->query("SELECT * FROM $table");

        $count = 0;

        while ($row = $rows->fetch()) {
            $insert->execute(array_values($row));
            $count++;
        }

        echo "COPIED=$table ROWS=$count\n";
    }

    $railway->exec("SET FOREIGN_KEY_CHECKS=1");

    $users = $railway
        ->query("SELECT COUNT(*) FROM users")
        ->fetchColumn();

    $waste = $railway
        ->query("SELECT COUNT(*) FROM waste_records")
        ->fetchColumn();

    $archive = $railway
        ->query("SELECT COUNT(*) FROM waste_records_archive")
        ->fetchColumn();

    $tablesCount = $railway
        ->query("
            SELECT COUNT(*)
            FROM information_schema.tables
            WHERE table_schema = 'ecotrack_db'
              AND table_type = 'BASE TABLE'
        ")
        ->fetchColumn();

    echo "RESUME_COMPLETE\n";
    echo "RAILWAY_TABLES=$tablesCount\n";
    echo "RAILWAY_USERS=$users\n";
    echo "RAILWAY_WASTE_RECORDS=$waste\n";
    echo "RAILWAY_WASTE_ARCHIVE=$archive\n";

} catch (Throwable $e) {
    try {
        if (isset($railway)) {
            $railway->exec("SET FOREIGN_KEY_CHECKS=1");
        }
    } catch (Throwable $ignored) {
    }

    echo "RESUME_FAILED\n";
    echo "ERROR=" . $e->getMessage() . "\n";
    exit(1);
}
<?php

$railwayPassword = getenv('RAILWAY_DB_PASSWORD');

if (!$railwayPassword) {
    exit("RAILWAY_DB_PASSWORD is not set\n");
}

function qi(string $name): string
{
    return '' . str_replace('', '``', $name) . '`';
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

    $tables = $local
        ->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")
        ->fetchAll(PDO::FETCH_COLUMN);

    echo "SOURCE_TABLES=" . count($tables) . "\n";

    $railway->exec("SET FOREIGN_KEY_CHECKS=0");

    echo "DROPPING_EXISTING_RAILWAY_TABLES\n";

    $existingTables = $railway
        ->query("SHOW TABLES")
        ->fetchAll(PDO::FETCH_COLUMN);

    foreach ($existingTables as $table) {
        $railway->exec("DROP TABLE IF EXISTS " . qi($table));
        echo "DROPPED=$table\n";
    }

    echo "CREATING_TABLES\n";

    foreach ($tables as $table) {
        $quoted = qi($table);

        $createRow = $local
            ->query("SHOW CREATE TABLE $quoted")
            ->fetch();

        $createSql = $createRow['Create Table'];

        $railway->exec($createSql);

        echo "CREATED=$table\n";
    }

    echo "COPYING_DATA\n";

    foreach ($tables as $table) {
        $quoted = qi($table);

        $columns = $local
            ->query("SHOW COLUMNS FROM $quoted")
            ->fetchAll();

        $columnNames = array_column($columns, 'Field');

        $columnList = implode(
            ', ',
            array_map('qi', $columnNames)
        );

        $placeholders = implode(
            ', ',
            array_fill(0, count($columnNames), '?')
        );

        $insertSql =
            "INSERT INTO $quoted ($columnList) VALUES ($placeholders)";

        $insert = $railway->prepare($insertSql);

        $rows = $local->query("SELECT * FROM $quoted");

        $count = 0;

        while ($row = $rows->fetch()) {
            $values = [];

            foreach ($columnNames as $column) {
                $values[] = $row[$column];
            }

            $insert->execute($values);
            $count++;
        }

        echo "COPIED=$table ROWS=$count\n";
    }

    $railway->exec("SET FOREIGN_KEY_CHECKS=1");

    $finalTableCount = $railway
        ->query("
            SELECT COUNT(*)
            FROM information_schema.tables
            WHERE table_schema = 'ecotrack_db'
              AND table_type = 'BASE TABLE'
        ")
        ->fetchColumn();

    $userCount = $railway
        ->query("SELECT COUNT(*) FROM users")
        ->fetchColumn();

    $wasteCount = $railway
        ->query("SELECT COUNT(*) FROM waste_records")
        ->fetchColumn();

    echo "MIGRATION_COMPLETE\n";
    echo "RAILWAY_TABLES=$finalTableCount\n";
    echo "RAILWAY_USERS=$userCount\n";
    echo "RAILWAY_WASTE_RECORDS=$wasteCount\n";

} catch (Throwable $e) {
    echo "MIGRATION_FAILED\n";
    echo "ERROR=" . $e->getMessage() . "\n";
    exit(1);
}
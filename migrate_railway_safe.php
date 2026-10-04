<?php

$railwayPassword = getenv('RAILWAY_DB_PASSWORD');

if (!$railwayPassword) {
    exit("RAILWAY_DB_PASSWORD is not set\n");
}

function tableName(string $name): string
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
        throw new RuntimeException("Unsafe table name: " . $name);
    }

    return $name;
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

    $localTables = $local
        ->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")
        ->fetchAll(PDO::FETCH_COLUMN);

    $railwayTables = $railway
        ->query("SHOW TABLES")
        ->fetchAll(PDO::FETCH_COLUMN);

    $railwaySet = array_flip($railwayTables);

    echo "LOCAL_TABLES=" . count($localTables) . "\n";
    echo "RAILWAY_TABLES_BEFORE=" . count($railwayTables) . "\n";

    $railway->exec("SET FOREIGN_KEY_CHECKS=0");

    /*
     * Create missing tables first.
     */
    foreach ($localTables as $table) {
        $table = tableName($table);

        if (isset($railwaySet[$table])) {
            continue;
        }

        echo "CREATING_MISSING=$table\n";

        $create = $local
            ->query("SHOW CREATE TABLE $table")
            ->fetch();

        if (!$create || empty($create['Create Table'])) {
            throw new RuntimeException("Could not read CREATE TABLE for $table");
        }

        $railway->exec($create['Create Table']);

        echo "CREATED=$table\n";
    }

    /*
     * Clear existing Railway data without DROP TABLE.
     */
    foreach ($localTables as $table) {
        $table = tableName($table);

        echo "CLEARING=$table\n";

        $railway->exec("DELETE FROM $table");
    }

    /*
     * Copy all local rows to Railway.
     */
    foreach ($localTables as $table) {
        $table = tableName($table);

        echo "COPYING=$table\n";

        $columns = $local
            ->query("SHOW COLUMNS FROM $table")
            ->fetchAll();

        $columnNames = array_column($columns, 'Field');

        $columnList = implode(', ', $columnNames);
        $placeholders = implode(
            ', ',
            array_fill(0, count($columnNames), '?')
        );

        $insert = $railway->prepare(
            "INSERT INTO $table ($columnList) VALUES ($placeholders)"
        );

        $rows = $local->query("SELECT * FROM $table");

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

    $finalTables = $railway
        ->query("
            SELECT COUNT(*)
            FROM information_schema.tables
            WHERE table_schema = 'ecotrack_db'
              AND table_type = 'BASE TABLE'
        ")
        ->fetchColumn();

    $users = $railway
        ->query("SELECT COUNT(*) FROM users")
        ->fetchColumn();

    $waste = $railway
        ->query("SELECT COUNT(*) FROM waste_records")
        ->fetchColumn();

    echo "MIGRATION_COMPLETE\n";
    echo "RAILWAY_TABLES_AFTER=$finalTables\n";
    echo "RAILWAY_USERS=$users\n";
    echo "RAILWAY_WASTE_RECORDS=$waste\n";

} catch (Throwable $e) {
    try {
        if (isset($railway)) {
            $railway->exec("SET FOREIGN_KEY_CHECKS=1");
        }
    } catch (Throwable $ignored) {
    }

    echo "MIGRATION_FAILED\n";
    echo "ERROR=" . $e->getMessage() . "\n";
    exit(1);
}

<?php

$password = getenv('RAILWAY_DB_PASSWORD');

if (!$password) {
    exit("RAILWAY_DB_PASSWORD is not set\n");
}

try {
    $pdo = new PDO(
        'mysql:host=127.0.0.1;port=51606;dbname=ecotrack_db;charset=utf8mb4',
        'root',
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

    $fh = fopen('railway_ecotrack_backup.sql', 'wb');

    if (!$fh) {
        throw new RuntimeException('Could not create backup file.');
    }

    fwrite($fh, "-- Railway ecotrack_db backup\n");
    fwrite($fh, "SET FOREIGN_KEY_CHECKS=0;\n\n");

    foreach ($tables as $table) {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            throw new RuntimeException("Unsafe table name: " . $table);
        }

        echo "BACKING_UP=$table\n";

        $create = $pdo->query("SHOW CREATE TABLE $table")->fetch();
        $createSql = $create['Create Table'];

        fwrite($fh, "DROP TABLE IF EXISTS $table;\n");
        fwrite($fh, $createSql . ";\n\n");

        $rows = $pdo->query("SELECT * FROM $table")->fetchAll();

        foreach ($rows as $row) {
            $columns = [];
            $values = [];

            foreach ($row as $column => $value) {
                $columns[] = '' . str_replace('', '``', $column) . '`';
                $values[] = $value === null ? 'NULL' : $pdo->quote($value);
            }

            fwrite(
                $fh,
                "INSERT INTO $table (" .
                implode(', ', $columns) .
                ") VALUES (" .
                implode(', ', $values) .
                ");\n"
            );
        }

        fwrite($fh, "\n");
    }

    fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
    fclose($fh);

    echo "BACKUP_COMPLETE\n";
    echo "TABLES=" . count($tables) . "\n";
    echo "FILE=railway_ecotrack_backup.sql\n";
    echo "SIZE=" . filesize('railway_ecotrack_backup.sql') . " bytes\n";

} catch (Throwable $e) {
    echo "BACKUP_FAILED\n";
    echo "ERROR=" . $e->getMessage() . "\n";
}

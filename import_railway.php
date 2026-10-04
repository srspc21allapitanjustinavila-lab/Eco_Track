<?php

$local = new PDO(
    'mysql:host=127.0.0.1;port=3306;dbname=ecotrack_db;charset=utf8mb4',
    'root',
    '',
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);

$out = fopen('ecotrack_clean.sql', 'wb');

if (!$out) {
    exit("OUTPUT_FILE_FAILED\n");
}

fwrite($out, "SET FOREIGN_KEY_CHECKS=0;\n\n");

$tables = $local->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

echo "TABLES=" . count($tables) . "\n";

foreach ($tables as $table) {
    echo "EXPORTING=$table\n";

    $safeTable = '' . str_replace('', '``', $table) . '`';

    $create = $local->query("SHOW CREATE TABLE $table")->fetch();
    $createSql = $create['Create Table'];

    fwrite($out, "DROP TABLE IF EXISTS $safeTable;\n");
    fwrite($out, $createSql . ";\n\n");

    $rows = $local->query("SELECT * FROM $table")->fetchAll();

    foreach ($rows as $row) {
        $columns = [];
        $values = [];

        foreach ($row as $column => $value) {
            $columns[] = '' . str_replace('', '``', $column) . '`';
            $values[] = ($value === null)
                ? 'NULL'
                : $local->quote($value);
        }

        fwrite(
            $out,
            "INSERT INTO $safeTable (" .
            implode(', ', $columns) .
            ") VALUES (" .
            implode(', ', $values) .
            ");\n"
        );
    }

    fwrite($out, "\n");
}

fwrite($out, "SET FOREIGN_KEY_CHECKS=1;\n");
fclose($out);

echo "CLEAN_DUMP_COMPLETE\n";
echo "FILE=ecotrack_clean.sql\n";
echo "SIZE=" . filesize('ecotrack_clean.sql') . " bytes\n";

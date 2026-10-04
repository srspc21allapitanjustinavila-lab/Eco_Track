<?php

$password = getenv('RAILWAY_DB_PASSWORD');

$pdo = new PDO(
    'mysql:host=127.0.0.1;port=51606;dbname=ecotrack_db;charset=utf8mb4',
    'root',
    $password,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_COLUMN,
    ]
);

$tables = $pdo->query("SHOW TABLES")->fetchAll();

$table = $tables[0];

$sql = "DROP TABLE IF EXISTS " . str_replace("", "``", $table) . "`";

echo "TABLE=";
var_export($table);
echo PHP_EOL;

echo "SQL=";
var_export($sql);
echo PHP_EOL;

echo "DIAGNOSTIC_ONLY\n";
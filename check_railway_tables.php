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

echo "TABLE_COUNT=" . count($tables) . PHP_EOL;

foreach ($tables as $i => $table) {
    echo ($i + 1) . ": ";
    var_export($table);
    echo PHP_EOL;
}

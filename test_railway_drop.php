<?php

$password = getenv('RAILWAY_DB_PASSWORD');

$pdo = new PDO(
    'mysql:host=127.0.0.1;port=51606;dbname=ecotrack_db;charset=utf8mb4',
    'root',
    $password,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]
);

$sql = "DROP TABLE IF EXISTS __ecotrack_drop_test__";

echo "SQL=" . $sql . PHP_EOL;

$pdo->exec($sql);

echo "DROP_SYNTAX_OK" . PHP_EOL;
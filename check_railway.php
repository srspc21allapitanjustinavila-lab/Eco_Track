<?php

$password = getenv('RAILWAY_DB_PASSWORD');

if (!$password) {
    exit("RAILWAY_DB_PASSWORD is not set\n");
}

try {
    $pdo = new PDO(
        'mysql:host=127.0.0.1;port=51606;charset=utf8mb4',
        'root',
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    echo "CONNECTED\n";

    echo "\n=== DATABASES ===\n";

    $databases = $pdo->query("SHOW DATABASES")->fetchAll();

    foreach ($databases as $db) {
        echo $db['Database'] . "\n";
    }

    echo "\n=== ECOTRACK_DB TABLES ===\n";

    $pdo->exec("USE ecotrack_db");

    $tables = $pdo->query("SHOW TABLES")->fetchAll();

    if (!$tables) {
        echo "NO TABLES\n";
    } else {
        foreach ($tables as $table) {
            echo reset($table) . "\n";
        }
    }

} catch (Throwable $e) {
    echo "CHECK_FAILED\n";
    echo $e->getMessage() . "\n";
    exit(1);
}
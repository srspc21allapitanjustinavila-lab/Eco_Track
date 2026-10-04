<?php

$password = getenv('RAILWAY_DB_PASSWORD');

if ($password === false || $password === '') {
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

    echo "RAILWAY_PDO_CONNECTION=YES\n";
    echo "DATABASE=" . $pdo->query("SELECT DATABASE()")->fetchColumn() . "\n";
    echo "TABLES=" . $pdo->query("
        SELECT COUNT(*)
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
    ")->fetchColumn() . "\n";

} catch (Throwable $e) {
    echo "RAILWAY_PDO_CONNECTION=NO\n";
    echo "ERROR=" . $e->getMessage() . "\n";
}
<?php

$password = getenv('RAILWAY_DB_PASSWORD');

if (!$password) {
    exit("RAILWAY_DB_PASSWORD is not set\n");
}

$sqlFile = 'ecotrack_clean.sql';

if (!file_exists($sqlFile)) {
    exit("SQL_FILE_NOT_FOUND\n");
}

$sql = file_get_contents($sqlFile);

if ($sql === false || $sql === '') {
    exit("SQL_FILE_READ_FAILED\n");
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $db = new mysqli(
        '127.0.0.1',
        'root',
        $password,
        'ecotrack_db',
        51606
    );

    $db->set_charset('utf8mb4');

    echo "RAILWAY_CONNECTED\n";
    echo "SQL_SIZE=" . strlen($sql) . " bytes\n";
    echo "IMPORT_START\n";

    $db->multi_query($sql);

    do {
        if ($result = $db->store_result()) {
            $result->free();
        }
    } while ($db->more_results() && $db->next_result());

    echo "IMPORT_COMPLETE\n";

    $count = $db->query("
        SELECT COUNT(*)
        FROM information_schema.tables
        WHERE table_schema = 'ecotrack_db'
    ")->fetch_row()[0];

    echo "TABLES=" . $count . "\n";

} catch (Throwable $e) {
    echo "IMPORT_FAILED\n";
    echo "ERROR=" . $e->getMessage() . "\n";
}
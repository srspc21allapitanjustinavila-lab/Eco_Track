<?php

require _DIR_ . '/config.php';

$conn = getDBConnection();

$files = [
    _DIR_ . '/database_setup.sql',
    _DIR_ . '/waste_data_corrected.sql',
];

foreach ($files as $file) {
    if (!is_readable($file)) {
        die("FILE_NOT_READABLE: " . basename($file));
    }

    echo "Running " . basename($file) . "...\n";

    $sql = file_get_contents($file);

    if ($sql === false) {
        die("FILE_READ_FAILED: " . basename($file));
    }

    $conn->exec($sql);

    echo "OK: " . basename($file) . "\n";
}

echo "DATABASE_SETUP_COMPLETE\n";
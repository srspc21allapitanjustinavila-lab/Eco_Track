<?php
require 'config.php';

$conn = getDBConnection();

$files = ['database_setup.sql', 'waste_data_corrected.sql'];

foreach ($files as $file) {
    echo "Running " . $file . "...\n";
    $sql = file_get_contents($file);
    $conn->exec($sql);
    echo "OK: " . $file . "\n";
}

echo "DATABASE_SETUP_COMPLETE\n";

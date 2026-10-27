<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Command line only.');
}

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/database_backup.php';

try {
    $onlyIfDue = in_array('--if-due', $argv, true);
    $name = ecoCreateBackup($onlyIfDue);

    if ($name === null) {
        echo "A recent backup already exists.\n";
    } else {
        echo "Backup created: $name\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Backup failed: ' . $e->getMessage() . "\n");
    exit(1);
}

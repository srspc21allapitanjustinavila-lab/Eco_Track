<?php

function ecoBackupDirectory(): string
{
    $configured = getenv('ECOTRACK_BACKUP_DIR');
    if ($configured !== false && $configured !== '') {
        return $configured;
    }

    return dirname(dirname(__DIR__), 2) . DIRECTORY_SEPARATOR . 'EcoTrack_Backups';
}

function ecoBackupDumpExecutable(): string
{
    $configured = getenv('ECOTRACK_MYSQLDUMP');
    if ($configured !== false && $configured !== '') {
        return $configured;
    }

    return 'C:\\xampp\\mysql\\bin\\mysqldump.exe';
}

function ecoBackupPrepareDirectory(string $directory): string
{
    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('The backup folder could not be created.');
    }
    $resolved = realpath($directory);
    $project = realpath(dirname(__DIR__));
    $documentRoot = !empty($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;

    if ($resolved === false || $project === false) {
        throw new RuntimeException('The backup folder could not be resolved.');
    }

    $folder = strtolower(rtrim(str_replace('\\', '/', $resolved), '/')) . '/';
    foreach ([$project, $documentRoot] as $root) {
        if ($root === false) {
            continue;
        }

        $root = strtolower(rtrim(str_replace('\\', '/', $root), '/')) . '/';
        if (strpos($folder, $root) === 0) {
            throw new RuntimeException('The backup folder must be outside the website directory.');
        }
    }

    return $resolved;
}

function ecoBackupList(string $directory): array
{
    if (!is_dir($directory)) {
        return [];
    }

    $files = [];
    foreach (glob($directory . DIRECTORY_SEPARATOR . 'ecotrack_*.sql.gz') ?: [] as $path) {
        $name = basename($path);
        if (!preg_match('/^ecotrack_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}_[a-f0-9]{8}\.sql\.gz$/', $name)) {
            continue;
        }
        if (!is_file($path) || is_link($path)) {
            continue;
        }

        $files[] = [
            'name' => $name,
            'path' => $path,
            'time' => filemtime($path),
            'size' => filesize($path),
        ];
    }

    usort($files, static function ($a, $b) {
        return $b['time'] <=> $a['time'];
    });

    return $files;
}

function ecoBackupCommand(string $executable): array
{
    if (!preg_match('/^([a-zA-Z0-9_.:-]+)(?:;port=([0-9]{1,5}))?$/', DB_HOST, $hostParts)
        || (isset($hostParts[2]) && ((int)$hostParts[2] < 1 || (int)$hostParts[2] > 65535))
        || DB_NAME === '' || strpos(DB_NAME, "\0") !== false) {
        throw new RuntimeException('The database connection settings are not supported for backup.');
    }

    $command = [
        $executable,
        '--no-defaults',
        '--host=' . $hostParts[1],
        '--user=' . DB_USER,
    ];
    if (isset($hostParts[2])) {
        $command[] = '--port=' . $hostParts[2];
    }

    return array_merge($command, [
        '--default-character-set=utf8mb4',
        '--single-transaction',
        '--quick',
        '--routines',
        '--events',
        '--triggers',
        '--',
        DB_NAME,
    ]);
}

function ecoWriteBackupDump(array $command, string $temporary): void
{
    $gzip = gzopen($temporary, 'wb6');
    $errors = tmpfile();
    if (!$gzip || !$errors) {
        if ($gzip) {
            gzclose($gzip);
        }
        if ($errors) {
            fclose($errors);
        }
        throw new RuntimeException('The backup file could not be opened.');
    }

    // Pass the password through the process environment, not the command line.
    $environment = getenv();
    $environment['MYSQL_PWD'] = DB_PASS;
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => $errors,
    ];
    $process = proc_open($command, $descriptors, $pipes, null, $environment, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        gzclose($gzip);
        fclose($errors);
        throw new RuntimeException('The database backup process could not start.');
    }

    fclose($pipes[0]);
    $writeOk = true;
    $bytes = 0;
    while (!feof($pipes[1])) {
        $chunk = fread($pipes[1], 65536);
        if ($chunk === false) {
            $writeOk = false;
            break;
        }
        if ($chunk === '') {
            continue;
        }

        $bytes += strlen($chunk);
        if (gzwrite($gzip, $chunk) !== strlen($chunk)) {
            $writeOk = false;
            break;
        }
    }

    fclose($pipes[1]);
    $exitCode = proc_close($process);
    $closed = gzclose($gzip);
    fclose($errors);

    if (!$writeOk || !$closed || $exitCode !== 0 || $bytes === 0) {
        throw new RuntimeException('Database backup failed. Check that XAMPP MySQL is running and the database credentials are correct.');
    }
}

function ecoCreateBackup(bool $onlyIfDue = false): ?string
{
    if (PHP_OS_FAMILY !== 'Windows') {
        throw new RuntimeException('XAMPP backups are available on Windows only.');
    }
    if (!function_exists('proc_open') || !function_exists('gzopen')) {
        throw new RuntimeException('Backup support is unavailable in PHP.');
    }

    $executable = ecoBackupDumpExecutable();
    if (!is_file($executable)) {
        throw new RuntimeException('The XAMPP mysqldump program was not found.');
    }
    $command = ecoBackupCommand($executable);
    $directory = ecoBackupPrepareDirectory(ecoBackupDirectory());
    $lock = fopen($directory . DIRECTORY_SEPARATOR . '.backup.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        if ($lock) {
            fclose($lock);
        }
        throw new RuntimeException('Another backup is already running.');
    }

    $temporary = null;
    try {
        $backups = ecoBackupList($directory);
        if ($onlyIfDue && $backups && $backups[0]['time'] > time() - 86400) {
            return null;
        }

        $name = 'ecotrack_' . date('Y-m-d_H-i-s') . '_' . bin2hex(random_bytes(4)) . '.sql.gz';
        $destination = $directory . DIRECTORY_SEPARATOR . $name;
        $temporary = $destination . '.part';
        ecoWriteBackupDump($command, $temporary);

        if (!rename($temporary, $destination)) {
            throw new RuntimeException('The completed backup could not be saved.');
        }
        $temporary = null;
        return $name;
    } finally {
        if ($temporary !== null && is_file($temporary)) {
            @unlink($temporary);
        }
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

<?php

function environmentValue($name, $default = '')
{
    $value = getenv($name);
    return $value === false || $value === '' ? $default : $value;
}

function loadLocalEnvironment($path)
{
    if (!is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) {
            continue;
        }

        if (strpos($line, 'export ') === 0) {
            $line = trim(substr($line, 7));
        }

        [$name, $value] = array_pad(explode('=', $line, 2), 2, '');
        $name = trim($name);
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            continue;
        }

        $existing = getenv($name);
        if ($existing !== false && $existing !== '') {
            continue;
        }

        $value = trim($value);
        $quote = $value[0] ?? '';
        if (($quote === '"' || $quote === "'") && substr($value, -1) === $quote) {
            $value = substr($value, 1, -1);
        }

        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $_SERVER[$name] ?? $value;
    }
}

function environmentBoolean($name, $default = false)
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        return $default;
    }

    return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
}

loadLocalEnvironment(__DIR__ . '/.env');

// Database configuration
define('APP_ENV', environmentValue('APP_ENV', 'development'));
define('DB_HOST', environmentValue('DB_HOST', 'localhost'));
define('DB_NAME', environmentValue('DB_NAME', 'ecotrack_db'));
define('DB_USER', environmentValue('DB_USER', 'root'));
define('DB_PASS', environmentValue('DB_PASS'));

// Email configuration
define('EMAIL_HOST', environmentValue('EMAIL_HOST'));
define('EMAIL_PORT', (int)environmentValue('EMAIL_PORT', '587'));
define('EMAIL_USERNAME', environmentValue('EMAIL_USERNAME'));
define('EMAIL_PASSWORD', environmentValue('EMAIL_PASSWORD'));
define('EMAIL_FROM', environmentValue('EMAIL_FROM'));
define('EMAIL_FROM_NAME', environmentValue('EMAIL_FROM_NAME', 'EcoTrack System'));
define('OPENAI_API_KEY', environmentValue('OPENAI_API_KEY'));
define('OPENAI_MODEL', environmentValue('OPENAI_MODEL', 'gpt-4o-mini'));
define('PASSWORD_RESET_ENABLED', environmentBoolean('PASSWORD_RESET_ENABLED', false));

function isSmtpConfigured()
{
    return EMAIL_HOST !== ''
        && EMAIL_PORT > 0
        && EMAIL_USERNAME !== ''
        && EMAIL_PASSWORD !== ''
        && EMAIL_FROM !== '';
}

function isPasswordResetEnabled()
{
    return PASSWORD_RESET_ENABLED && isSmtpConfigured();
}

// Security settings
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_ATTEMPT_WINDOW', 15); // minutes
define('SESSION_TIMEOUT', 1800); // 30 minutes in seconds
define('PERSISTENT_LOGIN_COOKIE', 'ecotrack_persistent_login');
define('PERSISTENT_LOGIN_LIFETIME', 2592000); // 30 days in seconds
define('MAX_WASTE_IMPORT_BYTES', 262144000); // 250 MiB
define('MAX_WASTE_IMPORT_UNCOMPRESSED_BYTES', 1073741824); // 1 GiB ZIP safety limit
define('MAX_PROFILE_PHOTO_BYTES', 5 * 1024 * 1024);

// Create database connection
function getDBConnection()
{
    try {
        $conn = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [PDO::ATTR_EMULATE_PREPARES => false]
        );
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $conn;
    } catch (PDOException $e) {
        error_log("Database connection failed: " . $e->getMessage());
        return null;
    }
}

/**
 * Store a profile image without trusting the original filename or browser MIME hint.
 * Returns null when no image was selected, false on validation failure, or a web path.
 */
function storeUserPhotoUpload($upload, &$error)
{
    $error = '';
    if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || empty($upload['tmp_name']) || !is_uploaded_file($upload['tmp_name'])) {
        $error = 'The profile image could not be uploaded. Please try again.';
        return false;
    }

    $size = (int)($upload['size'] ?? 0);
    if ($size <= 0 || $size > MAX_PROFILE_PHOTO_BYTES) {
        $error = 'Profile images must be 5 MB or smaller.';
        return false;
    }

    $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    if (!isset($extensions[$mimeType])) {
        $error = 'Profile images must be JPEG, PNG, or WebP files.';
        return false;
    }

    $uploadDirectory = __DIR__ . '/uploads/users';
    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0750, true) && !is_dir($uploadDirectory)) {
        $error = 'The profile image storage is unavailable.';
        return false;
    }

    try {
        $filename = bin2hex(random_bytes(16)) . '.' . $extensions[$mimeType];
    } catch (Throwable $e) {
        error_log('Could not generate a profile image filename: ' . $e->getMessage());
        $error = 'The profile image could not be stored. Please try again.';
        return false;
    }

    $destination = $uploadDirectory . '/' . $filename;
    if (!move_uploaded_file($upload['tmp_name'], $destination)) {
        $error = 'The profile image could not be stored. Please try again.';
        return false;
    }

    @chmod($destination, 0640);
    return 'uploads/users/' . $filename;
}

function getWasteFormatColumns()
{
    return [
        'phase_date_label' => "VARCHAR(255) NULL",
        'collection_group' => "VARCHAR(255) NULL",
        'reporting_period' => "VARCHAR(255) NULL",
        'name_of_bioman' => "VARCHAR(255) NULL",
        'households' => "VARCHAR(100) NULL",
        'tuesday_factory_returnable_kg' => "DECIMAL(10,2) DEFAULT 0",
        'comply_tue' => "VARCHAR(50) NULL",
        'cd_processing' => "VARCHAR(100) NULL",
        'wednesday_biowaste_kg' => "DECIMAL(10,2) DEFAULT 0",
        'comply_wed' => "VARCHAR(50) NULL",
        'thursday_factory_returnable_kg' => "DECIMAL(10,2) DEFAULT 0",
        'friday_biowaste_kg' => "DECIMAL(10,2) DEFAULT 0",
        'comply_fri' => "VARCHAR(50) NULL",
        'saturday_hazard_waste_kg' => "DECIMAL(10,2) DEFAULT 0",
        'residual_waste_kg' => "DECIMAL(10,2) DEFAULT 0",
        // Keep workbook-wide totals separate from residual waste.
        'unclassified_waste_kg' => "DECIMAL(10,2) DEFAULT 0",
        // Keep detailed labels while exposing their Phase/Establishment type.
        'collection_group_type' => "ENUM('phase', 'establishment') NULL",
        // New records use a content fingerprint; legacy rows remain nullable.
        'record_fingerprint' => "CHAR(64) NULL",
        // Deleted records remain recoverable in the archive.
        'is_active' => "TINYINT(1) NOT NULL DEFAULT 1",
        'deleted_at' => "DATETIME NULL",
        'deleted_by' => "INT NULL",
    ];
}

function getWasteFormatHeaders()
{
    return [
        'Bioman',
        'Area',
        'Households',
        'Tuesday Factory KL',
        'Tuesday Comply',
        'Co/Processing',
        'Wednesday KL',
        'Wednesday Comply',
        'Thursday KL',
        'Friday KL',
        'Friday Comply',
        'Saturday Hazard',
        'Residual Waste',
        'Unclassified Waste',
    ];
}

/** Skip spreadsheet summary rows, where TOTAL commonly appears in Area. */
function isWasteTotalLabel($value)
{
    return preg_match('/^(?:grand\s+)?total\s*:?$/i', trim((string)$value)) === 1;
}

function isWasteSummaryRow($values)
{
    // Bioman, Area, and Households identify ordinary rows across workbook layouts.
    foreach (array_slice((array)$values, 0, 3) as $value) {
        if (isWasteTotalLabel($value)) {
            return true;
        }
    }
    return false;
}

/** Split a source label into its collection group and reporting period. */
function splitWasteCollectionGroupAndPeriod($sourceLabel, $fallbackGroup = '')
{
    $sourceLabel = trim((string)$sourceLabel);
    $fallbackGroup = trim((string)$fallbackGroup);

    if ($sourceLabel === '') {
        return [$fallbackGroup, ''];
    }

    if (preg_match('/^(.*?)\s*(?:[-–—]\s*)?date\s*:\s*(.+)$/i', $sourceLabel, $matches)) {
        $group = trim($matches[1], " \t\n\r\0\x0B-–—");
        return [$group !== '' ? $group : $fallbackGroup, trim($matches[2])];
    }

    if (preg_match('/^(.*?)\s*\((.+)\)\s*$/u', $sourceLabel, $matches)) {
        $group = trim($matches[1]);
        $period = trim($matches[2]);
        if ($group !== '' && $period !== '') {
            return [$group, $period];
        }
    }

    return [$sourceLabel, ''];
}

function normalizeWasteCollectionRuleArea($area)
{
    $area = trim((string)$area);
    $area = preg_replace('/\s+/u', ' ', $area);
    $area = preg_replace('/[\x{2010}-\x{2015}]/u', '-', $area);
    return function_exists('mb_strtolower') ? mb_strtolower($area, 'UTF-8') : strtolower($area);
}

function isGenericWasteCollectionBlockLabel($label)
{
    $label = trim((string)$label);
    return preg_match('/^\s*(?:\d+\s*[-\x{2013}\x{2014}]\s*)?day\s+(?:collection\s+)?block\s*:/iu', $label) === 1;
}

function inferWasteCollectionGroupType($collectionGroup)
{
    $collectionGroup = trim((string)$collectionGroup);
    if ($collectionGroup === '') {
        return '';
    }
    if (preg_match('/\bphase\b/i', $collectionGroup)) {
        return 'phase';
    }
    if (preg_match('/\bestablish(?:ment|ments)?\b/i', $collectionGroup)) {
        return 'establishment';
    }
    return '';
}

function buildWasteCollectionSourceLabel($collectionGroup, $reportingPeriod)
{
    $collectionGroup = trim((string)$collectionGroup);
    $reportingPeriod = trim((string)$reportingPeriod);

    if ($collectionGroup !== '' && $reportingPeriod !== '') {
        return $collectionGroup . ' (' . $reportingPeriod . ')';
    }

    return $collectionGroup !== '' ? $collectionGroup : $reportingPeriod;
}

function isWastePhaseDateValue($value)
{
    $value = trim((string)$value);
    if ($value === '') {
        return false;
    }

    return stripos($value, 'Phase') !== false && (
        preg_match('/\b(19|20)\d{2}\b/', $value) ||
        preg_match('/\b(January|February|March|April|May|June|July|August|September|October|November|December)\b/i', $value) ||
        strpos($value, '(') !== false
    );
}

function cleanWasteHouseholdsValue($households, $phaseDateLabel = '', $phaseNumber = '')
{
    $households = trim((string)$households);
    $phaseDateLabel = trim((string)$phaseDateLabel);
    $phaseNumber = trim((string)$phaseNumber);

    if ($households === '') {
        return '';
    }

    if (
        ($phaseDateLabel !== '' && strcasecmp($households, $phaseDateLabel) === 0) ||
        ($phaseNumber !== '' && isWastePhaseDateValue($phaseNumber) && strcasecmp($households, $phaseNumber) === 0) ||
        isWastePhaseDateValue($households)
    ) {
        return '';
    }

    return $households;
}

function ensureWasteFormatColumns($conn)
{
    if (!$conn) {
        return;
    }

    try {
        $existing = [];
        $columns = $conn->query("SHOW COLUMNS FROM waste_records")->fetchAll();
        foreach ($columns as $column) {
            $existing[$column['Field']] = true;
        }

        foreach (getWasteFormatColumns() as $columnName => $definition) {
            if (!isset($existing[$columnName])) {
                $conn->exec("ALTER TABLE waste_records ADD COLUMN `$columnName` $definition");
            }
        }

        // Older installations may contain duplicate historical rows.
        $indexes = $conn->query('SHOW INDEX FROM waste_records')->fetchAll();
        $hasFingerprintIndex = false;
        $hasDateGroupIndex = false;
        $hasUniqueFingerprint = false;
        $hasActiveDateIndex = false;
        foreach ($indexes as $index) {
            $hasFingerprintIndex = $hasFingerprintIndex || (($index['Key_name'] ?? '') === 'idx_waste_records_fingerprint');
            $hasDateGroupIndex = $hasDateGroupIndex || (($index['Key_name'] ?? '') === 'idx_waste_records_date_group');
            $hasUniqueFingerprint = $hasUniqueFingerprint || (($index['Key_name'] ?? '') === 'unique_waste_records_fingerprint');
            $hasActiveDateIndex = $hasActiveDateIndex || (($index['Key_name'] ?? '') === 'idx_waste_records_active_collection_date');
        }
        if (!$hasFingerprintIndex) {
            $conn->exec('CREATE INDEX idx_waste_records_fingerprint ON waste_records (record_fingerprint)');
        }
        if (!$hasDateGroupIndex) {
            $conn->exec('CREATE INDEX idx_waste_records_date_group ON waste_records (collection_date, collection_group, id)');
        }
        if (!$hasActiveDateIndex) {
            $conn->exec('CREATE INDEX idx_waste_records_active_collection_date ON waste_records (is_active, collection_date)');
        }
        if (!$hasUniqueFingerprint) {
            $duplicateFingerprint = $conn->query("SELECT record_fingerprint FROM waste_records WHERE record_fingerprint IS NOT NULL AND record_fingerprint <> '' GROUP BY record_fingerprint HAVING COUNT(*) > 1 LIMIT 1")->fetchColumn();
            if ($duplicateFingerprint === false) {
                $conn->exec('CREATE UNIQUE INDEX unique_waste_records_fingerprint ON waste_records (record_fingerprint)');
            } else {
                error_log('Waste record fingerprint uniqueness deferred because historical duplicate rows exist.');
            }
        }

        if (isset($existing['households']) || array_key_exists('households', getWasteFormatColumns())) {
            $conn->exec("
                UPDATE waste_records
                SET households = ''
                WHERE households IS NOT NULL
                  AND households <> ''
                  AND (
                    households = phase_date_label
                    OR households = phase_number
                    OR (
                        households LIKE '%Phase%'
                        AND (
                            households REGEXP '(19|20)[0-9]{2}'
                            OR households REGEXP 'January|February|March|April|May|June|July|August|September|October|November|December'
                            OR households LIKE '%(%'
                        )
                    )
                  )
            ");

            $conn->exec("
                UPDATE waste_records wr
                JOIN (
                    SELECT street, MAX(CAST(households AS UNSIGNED)) AS recovered_households
                    FROM waste_records
                    WHERE households IS NOT NULL
                      AND households <> ''
                      AND households REGEXP '^[0-9]+$'
                    GROUP BY street
                ) source_rows ON source_rows.street = wr.street
                SET wr.households = source_rows.recovered_households
                WHERE (wr.households IS NULL OR wr.households = '')
                  AND source_rows.recovered_households IS NOT NULL
            ");
        }
    } catch (PDOException $e) {
        error_log("Failed to ensure waste format columns: " . $e->getMessage());
    }
}

/** Deleted Waste Data is kept in a permanent, recoverable archive. */
function ensureWasteRecordsArchiveTable($conn)
{
    static $checked = false;
    if (!$conn || $checked) {
        return;
    }

    try {
        $conn->exec("CREATE TABLE IF NOT EXISTS waste_records_archive (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            source_record_id INT NOT NULL,
            archived_by INT NULL,
            archived_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            record_payload LONGTEXT NOT NULL,
            UNIQUE KEY unique_archived_source_record (source_record_id),
            INDEX idx_waste_records_archive_archived_at (archived_at)
        )");

        // Existing installations may have the old expiry column. Keep it
        // nullable for compatibility, but it is no longer used or purged.
        $archiveColumns = $conn->query('SHOW COLUMNS FROM waste_records_archive')->fetchAll();
        foreach ($archiveColumns as $column) {
            if (($column['Field'] ?? '') === 'retain_until' && ($column['Null'] ?? '') !== 'YES') {
                $conn->exec('ALTER TABLE waste_records_archive MODIFY retain_until DATE NULL');
                break;
            }
        }
        $checked = true;
    } catch (PDOException $e) {
        error_log('Failed to create waste records archive table: ' . $e->getMessage());
    }
}

function archiveWasteRecord($conn, $recordId, $archivedBy = null)
{
    $recordId = (int)$recordId;
    $archivedBy = $archivedBy === null ? null : (int)$archivedBy;
    if (!$conn || $recordId <= 0) {
        return false;
    }

    ensureWasteFormatColumns($conn);
    ensureWasteRecordsArchiveTable($conn);
    if (function_exists('ensureWasteDailyAllocationTable')) {
        ensureWasteDailyAllocationTable($conn);
    }
    $conn->beginTransaction();
    try {
        $find = $conn->prepare('SELECT * FROM waste_records WHERE id = ? AND is_active = 1 FOR UPDATE');
        $find->execute([$recordId]);
        $record = $find->fetch();
        if (!$record) {
            $conn->rollBack();
            return false;
        }

        // A user-facing delete is always recoverable: snapshot the record in
        // the archive table, then mark its live row inactive.
        $wasArchived = true;
        $payload = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        if ($payload === false) {
            throw new RuntimeException('The waste record could not be serialized for archiving.');
        }
        $archive = $conn->prepare('INSERT INTO waste_records_archive (source_record_id, archived_by, record_payload) VALUES (?, ?, ?)');
        $archive->execute([$recordId, $archivedBy, $payload]);

        // Clear the live fingerprint so corrected imports can be accepted.
        $deactivate = $conn->prepare('UPDATE waste_records SET is_active = 0, deleted_at = NOW(), deleted_by = ?, record_fingerprint = NULL WHERE id = ? AND is_active = 1');
        $deactivate->execute([$archivedBy, $recordId]);
        if ($deactivate->rowCount() !== 1) {
            throw new RuntimeException('The waste record could not be archived.');
        }

        $fingerprint = trim((string)($record['record_fingerprint'] ?? ''));
        if ($fingerprint !== '') {
            $removeFingerprint = $conn->prepare('DELETE FROM waste_record_fingerprints WHERE record_fingerprint = ? AND NOT EXISTS (SELECT 1 FROM waste_records WHERE record_fingerprint = ? AND is_active = 1)');
            $removeFingerprint->execute([$fingerprint, $fingerprint]);
        }

        // Remove derived rows after the source record has been archived.
        $deleteAllocations = $conn->prepare('DELETE FROM waste_daily_allocations WHERE waste_record_id = ?');
        $deleteAllocations->execute([$recordId]);
        $conn->commit();
        return [
            'record' => $record,
            'archived' => $wasArchived,
        ];
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        error_log('Failed to archive waste record: ' . $e->getMessage());
        return false;
    }
}

function normalizeWasteFingerprintValue($value)
{
    $value = trim((string)$value);
    $value = preg_replace('/\s+/u', ' ', $value);
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function buildWasteRecordFingerprint($record)
{
    $numericFields = [
        'tuesday_factory_returnable_kg', 'wednesday_biowaste_kg',
        'thursday_factory_returnable_kg', 'friday_biowaste_kg',
        'saturday_hazard_waste_kg', 'residual_waste_kg',
        'unclassified_waste_kg',
    ];
    $fields = [
        'collection_date', 'collection_group', 'collection_group_type', 'reporting_period', 'street',
        'name_of_bioman', 'households', 'comply_tue', 'cd_processing',
        'comply_wed', 'comply_fri',
    ];
    $parts = [];
    foreach ($fields as $field) {
        $parts[] = normalizeWasteFingerprintValue($record[$field] ?? '');
    }
    foreach ($numericFields as $field) {
        $parts[] = number_format((float)($record[$field] ?? 0), 2, '.', '');
    }

    return hash('sha256', implode("\x1F", $parts));
}

function ensureWasteCollectionGroupingTables($conn)
{
    if (!$conn) {
        return;
    }
    try {
        ensureWasteFormatColumns($conn);
        $conn->exec("CREATE TABLE IF NOT EXISTS waste_collection_group_rules (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            normalized_area VARCHAR(190) NOT NULL,
            collection_group VARCHAR(255) NOT NULL,
            collection_group_type ENUM('phase', 'establishment') NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_by INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_waste_group_rule_area (normalized_area),
            INDEX idx_waste_group_rule_active (is_active, normalized_area)
        )");

        $rules = [
            'Phase 1-A' => ['Almeda St.', 'Bell St.', 'Curie St.', 'Dalton St.', 'Darwin St.', 'Edison St.', 'Einstein St.', 'Faraday St.', 'Flores St.', 'Inventor St.', 'Newton St.', 'Pascal St.', 'Sampaguita St.', 'Wright St.'],
            'Phase 5' => ['Aluminum St.', 'Beryllium St.', 'Boron St.', 'Carbon St.', 'Fluorine St.', 'Helium St.', 'Hydrogen St.', 'Lithium St.', 'Magnesium St.', 'Neon St.', 'Nitrogen St.', 'Oxygen St.', 'Silicon St.', 'Sodium St.'],
            'Phase 6' => ['Acacia St.', 'Almond St.', 'Apitong St.', 'Lauan St.', 'Tanguile St.', 'Agoho St.', 'Mahogany St.', 'Molave St.', 'Narra St.', 'Tindalo St.', 'Yakal St.'],
            'Establishments' => ['Toyota', 'Toyota - Quirino Highway', 'Jollibee', 'Jollibee - Tungkong Mangga'],
        ];
        $insert = $conn->prepare("INSERT INTO waste_collection_group_rules (normalized_area, collection_group, collection_group_type)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE normalized_area = normalized_area");
        foreach ($rules as $group => $areas) {
            $type = $group === 'Establishments' ? 'establishment' : 'phase';
            foreach ($areas as $area) {
                $insert->execute([normalizeWasteCollectionRuleArea($area), $group, $type]);
            }
        }
    } catch (PDOException $e) {
        error_log('Failed to ensure waste collection grouping tables: ' . $e->getMessage());
    }
}

function loadWasteCollectionGroupRules($conn)
{
    if (!$conn) {
        return [];
    }
    ensureWasteCollectionGroupingTables($conn);
    $rules = [];
    $stmt = $conn->query("SELECT normalized_area, collection_group, collection_group_type
        FROM waste_collection_group_rules WHERE is_active = 1");
    foreach ($stmt->fetchAll() as $rule) {
        $rules[$rule['normalized_area']] = [
            'collection_group' => $rule['collection_group'],
            'collection_group_type' => $rule['collection_group_type'],
        ];
    }
    return $rules;
}

function resolveWasteCollectionGroup($area, $explicitGroup = '', $rules = [])
{
    $explicitGroup = trim((string)$explicitGroup);
    if ($explicitGroup !== '' && !isGenericWasteCollectionBlockLabel($explicitGroup)) {
        return ['collection_group' => $explicitGroup, 'collection_group_type' => inferWasteCollectionGroupType($explicitGroup), 'resolved' => true, 'source' => 'worksheet'];
    }
    $rule = $rules[normalizeWasteCollectionRuleArea($area)] ?? null;
    if ($rule !== null) {
        return ['collection_group' => $rule['collection_group'], 'collection_group_type' => $rule['collection_group_type'], 'resolved' => true, 'source' => 'rule'];
    }
    return ['collection_group' => '', 'collection_group_type' => '', 'resolved' => false, 'source' => 'review'];
}

function ensureWasteDataVersionTable($conn)
{
    if (!$conn) {
        return;
    }
    $conn->exec("CREATE TABLE IF NOT EXISTS waste_data_state (
        state_key VARCHAR(50) NOT NULL PRIMARY KEY,
        version BIGINT UNSIGNED NOT NULL DEFAULT 0,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
    $conn->exec("INSERT IGNORE INTO waste_data_state (state_key, version) VALUES ('waste_records', 0)");
}

function touchWasteDataVersion($conn)
{
    if (!$conn) {
        return;
    }
    $stmt = $conn->prepare("UPDATE waste_data_state SET version = version + 1, updated_at = NOW() WHERE state_key = 'waste_records'");
    $stmt->execute();
}

function getWasteDataVersion($conn)
{
    ensureWasteDataVersionTable($conn);
    $stmt = $conn->query("SELECT version, updated_at FROM waste_data_state WHERE state_key = 'waste_records'");
    return $stmt->fetch() ?: ['version' => 0, 'updated_at' => null];
}

function syncWasteRecordFingerprint($conn, $recordId, $previousFingerprint = '')
{
    if (!$conn || (int)$recordId <= 0) {
        return;
    }
    $select = $conn->prepare('SELECT * FROM waste_records WHERE id = ? AND is_active = 1');
    $select->execute([(int)$recordId]);
    $record = $select->fetch();
    if (!$record) {
        return;
    }
    $fingerprint = buildWasteRecordFingerprint($record);
    $update = $conn->prepare('UPDATE waste_records SET record_fingerprint = ? WHERE id = ?');
    $update->execute([$fingerprint, (int)$recordId]);
    $insert = $conn->prepare('INSERT IGNORE INTO waste_record_fingerprints (record_fingerprint) VALUES (?)');
    $insert->execute([$fingerprint]);
    $previousFingerprint = trim((string)$previousFingerprint);
    if ($previousFingerprint !== '' && $previousFingerprint !== $fingerprint) {
        $stillUsed = $conn->prepare('SELECT COUNT(*) FROM waste_records WHERE record_fingerprint = ? AND is_active = 1');
        $stillUsed->execute([$previousFingerprint]);
        if ((int)$stillUsed->fetchColumn() === 0) {
            $remove = $conn->prepare('DELETE FROM waste_record_fingerprints WHERE record_fingerprint = ?');
            $remove->execute([$previousFingerprint]);
        }
    }
}

function removeWasteRecordFingerprint($conn, $fingerprint)
{
    $fingerprint = trim((string)$fingerprint);
    if (!$conn || $fingerprint === '') {
        return;
    }
    $stillUsed = $conn->prepare('SELECT COUNT(*) FROM waste_records WHERE record_fingerprint = ? AND is_active = 1');
    $stillUsed->execute([$fingerprint]);
    if ((int)$stillUsed->fetchColumn() === 0) {
        $remove = $conn->prepare('DELETE FROM waste_record_fingerprints WHERE record_fingerprint = ?');
        $remove->execute([$fingerprint]);
    }
}

function ensureWasteImportTables($conn)
{
    if (!$conn) {
        return;
    }

    try {
        ensureWasteCollectionGroupingTables($conn);
        ensureWasteDataVersionTable($conn);
        $conn->exec("CREATE TABLE IF NOT EXISTS waste_import_batches (
            id CHAR(36) NOT NULL PRIMARY KEY,
            created_by INT NULL,
            status ENUM('pending', 'imported', 'cancelled', 'rejected') NOT NULL DEFAULT 'pending',
            row_count INT NOT NULL DEFAULT 0,
            file_count INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL,
            imported_at DATETIME NULL,
            INDEX idx_waste_import_batches_owner_status (created_by, status),
            INDEX idx_waste_import_batches_expiry (status, expires_at)
        )");
        $conn->exec("CREATE TABLE IF NOT EXISTS waste_import_batch_files (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            batch_id CHAR(36) NOT NULL,
            file_sha256 CHAR(64) NOT NULL,
            dataset_sha256 CHAR(64) NOT NULL,
            original_name VARCHAR(255) NOT NULL,
            row_count INT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_waste_import_batch_file (batch_id, file_sha256),
            INDEX idx_waste_import_batch_files_hash (file_sha256),
            INDEX idx_waste_import_batch_files_dataset (dataset_sha256),
            INDEX idx_waste_import_batch_files_batch (batch_id)
        )");
        $conn->exec("CREATE TABLE IF NOT EXISTS waste_import_staging_rows (
            batch_id CHAR(36) NOT NULL,
            row_number INT NOT NULL,
            source_file_sha256 CHAR(64) NOT NULL,
            record_fingerprint CHAR(64) NOT NULL,
            date VARCHAR(50) NOT NULL,
            collection_date DATE NOT NULL,
            phase_number VARCHAR(50) NOT NULL,
            street VARCHAR(100) NOT NULL,
            kilogram_of_waste DECIMAL(10,2) NOT NULL DEFAULT 0,
            recyclable_kg DECIMAL(10,2) NOT NULL DEFAULT 0,
            residual_kg DECIMAL(10,2) NOT NULL DEFAULT 0,
            hazardous_kg DECIMAL(10,2) NOT NULL DEFAULT 0,
            garbage_collector VARCHAR(100) NULL,
            phase_date_label VARCHAR(255) NULL,
            collection_group VARCHAR(255) NULL,
            collection_group_type ENUM('phase', 'establishment') NULL,
            reporting_period VARCHAR(255) NULL,
            name_of_bioman VARCHAR(255) NULL,
            households VARCHAR(100) NULL,
            tuesday_factory_returnable_kg DECIMAL(10,2) NOT NULL DEFAULT 0,
            comply_tue VARCHAR(50) NULL,
            cd_processing VARCHAR(100) NULL,
            wednesday_biowaste_kg DECIMAL(10,2) NOT NULL DEFAULT 0,
            comply_wed VARCHAR(50) NULL,
            thursday_factory_returnable_kg DECIMAL(10,2) NOT NULL DEFAULT 0,
            friday_biowaste_kg DECIMAL(10,2) NOT NULL DEFAULT 0,
            comply_fri VARCHAR(50) NULL,
            saturday_hazard_waste_kg DECIMAL(10,2) NOT NULL DEFAULT 0,
            residual_waste_kg DECIMAL(10,2) NOT NULL DEFAULT 0,
            unclassified_waste_kg DECIMAL(10,2) NOT NULL DEFAULT 0,
            PRIMARY KEY (batch_id, row_number),
            UNIQUE KEY unique_waste_import_staging_fingerprint (batch_id, record_fingerprint),
            INDEX idx_waste_import_staging_fingerprint (record_fingerprint)
        )");
        $conn->exec("CREATE TABLE IF NOT EXISTS waste_import_batch_issues (
            batch_id CHAR(36) NOT NULL,
            source_file_sha256 CHAR(64) NOT NULL,
            worksheet_name VARCHAR(255) NOT NULL DEFAULT '',
            source_row_number INT NOT NULL DEFAULT 0,
            reason_code VARCHAR(60) NOT NULL,
            PRIMARY KEY (batch_id, source_file_sha256, worksheet_name, source_row_number, reason_code),
            INDEX idx_waste_import_batch_issues_batch (batch_id)
        )");
        // Drafts retain normalized cells while an administrator fills missing metadata.
        $conn->exec("CREATE TABLE IF NOT EXISTS waste_import_drafts (
            id CHAR(36) NOT NULL PRIMARY KEY,
            created_by INT NOT NULL,
            file_sha256 CHAR(64) NOT NULL,
            original_name VARCHAR(255) NOT NULL,
            worksheet_summary MEDIUMTEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL,
            INDEX idx_waste_import_drafts_owner_expiry (created_by, expires_at)
        )");
        $conn->exec("CREATE TABLE IF NOT EXISTS waste_import_draft_rows (
            draft_id CHAR(36) NOT NULL,
            row_number INT NOT NULL,
            worksheet_name VARCHAR(255) NOT NULL DEFAULT '',
            source_row_number INT NOT NULL DEFAULT 0,
            phase_label VARCHAR(255) NULL,
            collection_group VARCHAR(255) NULL,
            reporting_period VARCHAR(255) NULL,
            needs_date_resolution TINYINT(1) NOT NULL DEFAULT 0,
            row_data MEDIUMTEXT NOT NULL,
            PRIMARY KEY (draft_id, row_number),
            INDEX idx_waste_import_draft_rows_draft (draft_id)
        )");
        $conn->exec("CREATE TABLE IF NOT EXISTS waste_import_draft_issues (
            draft_id CHAR(36) NOT NULL,
            worksheet_name VARCHAR(255) NOT NULL DEFAULT '',
            source_row_number INT NOT NULL DEFAULT 0,
            reason_code VARCHAR(60) NOT NULL,
            PRIMARY KEY (draft_id, worksheet_name, source_row_number, reason_code),
            INDEX idx_waste_import_draft_issues_draft (draft_id)
        )");
        $conn->exec("CREATE TABLE IF NOT EXISTS waste_import_manifests (
            file_sha256 CHAR(64) NOT NULL PRIMARY KEY,
            dataset_sha256 CHAR(64) NOT NULL,
            original_name VARCHAR(255) NOT NULL,
            row_count INT NOT NULL,
            imported_by INT NULL,
            imported_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_waste_import_dataset (dataset_sha256)
        )");
        $conn->exec("CREATE TABLE IF NOT EXISTS waste_record_fingerprints (
            record_fingerprint CHAR(64) NOT NULL PRIMARY KEY,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        $conn->exec("CREATE TABLE IF NOT EXISTS waste_import_backfill_state (
            state_key VARCHAR(100) NOT NULL PRIMARY KEY,
            last_record_id INT NOT NULL DEFAULT 0,
            completed_at DATETIME NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");
        $conn->exec("INSERT IGNORE INTO waste_import_backfill_state (state_key) VALUES ('record_fingerprints')");

        // Add group classification to disposable staging batches when needed.
        $stageColumns = [];
        foreach ($conn->query('SHOW COLUMNS FROM waste_import_staging_rows')->fetchAll() as $column) {
            $stageColumns[$column['Field']] = true;
        }
        if (!isset($stageColumns['collection_group_type'])) {
            $conn->exec("ALTER TABLE waste_import_staging_rows ADD COLUMN collection_group_type ENUM('phase', 'establishment') NULL AFTER collection_group");
        }
        if (!isset($stageColumns['unclassified_waste_kg'])) {
            $conn->exec("ALTER TABLE waste_import_staging_rows ADD COLUMN unclassified_waste_kg DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER residual_waste_kg");
        }
        $stageIndexes = $conn->query('SHOW INDEX FROM waste_import_staging_rows')->fetchAll();
        $hasStateIndex = false;
        foreach ($stageIndexes as $index) {
            if (($index['Key_name'] ?? '') === 'idx_waste_import_staging_batch_row') {
                $hasStateIndex = true;
                break;
            }
        }
        if (!$hasStateIndex) {
            $conn->exec('CREATE INDEX idx_waste_import_staging_batch_row ON waste_import_staging_rows (batch_id, row_number)');
        }
        ensureWasteImportAuditTable($conn);
    } catch (PDOException $e) {
        error_log('Failed to ensure waste import tables: ' . $e->getMessage());
    }
}

/** Keep a privacy-conscious audit after temporary staging rows are removed. */
function ensureWasteImportAuditTable($conn)
{
    static $checked = false;
    if (!$conn || $checked) {
        return;
    }

    try {
        $conn->exec("CREATE TABLE IF NOT EXISTS waste_import_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            event_key VARCHAR(160) NULL,
            actor_user_id INT NULL,
            original_name VARCHAR(255) NULL,
            file_format VARCHAR(20) NULL,
            valid_row_count INT NOT NULL DEFAULT 0,
            skipped_row_count INT NOT NULL DEFAULT 0,
            outcome ENUM('imported', 'cancelled', 'duplicate', 'validation_error', 'expired', 'failed') NOT NULL,
            reason_code VARCHAR(60) NULL,
            event_source ENUM('legacy_manifest', 'live') NOT NULL DEFAULT 'live',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_waste_import_event_key (event_key),
            INDEX idx_waste_import_events_created_outcome (created_at, outcome),
            INDEX idx_waste_import_events_actor_created (actor_user_id, created_at)
        )");
        $eventColumns = $conn->query('SHOW COLUMNS FROM waste_import_events')->fetchAll();
        $hasSkippedCount = false;
        foreach ($eventColumns as $column) {
            if (($column['Field'] ?? '') === 'skipped_row_count') {
                $hasSkippedCount = true;
                break;
            }
        }
        if (!$hasSkippedCount) {
            $conn->exec('ALTER TABLE waste_import_events ADD COLUMN skipped_row_count INT NOT NULL DEFAULT 0 AFTER valid_row_count');
        }
        $conn->exec("CREATE TABLE IF NOT EXISTS waste_import_event_issues (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            event_id BIGINT UNSIGNED NOT NULL,
            worksheet_name VARCHAR(255) NULL,
            source_row_number INT NOT NULL DEFAULT 0,
            reason_code VARCHAR(60) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_waste_import_event_issue (event_id, worksheet_name, source_row_number, reason_code),
            INDEX idx_waste_import_event_issues_event (event_id)
        )");

        // Backfill safe manifest metadata for successful imports only.
        $conn->exec("INSERT IGNORE INTO waste_import_events
            (event_key, actor_user_id, original_name, file_format, valid_row_count, skipped_row_count, outcome, reason_code, event_source, created_at)
            SELECT CONCAT('manifest:', file_sha256), imported_by, original_name,
                   LOWER(SUBSTRING_INDEX(original_name, '.', -1)), row_count,
                   0, 'imported', 'manifest_backfill', 'legacy_manifest', imported_at
            FROM waste_import_manifests");
        $checked = true;
    } catch (PDOException $e) {
        error_log('Failed to create waste import audit table: ' . $e->getMessage());
    }
}

function wasteImportAuditFormat($fileName)
{
    $format = strtolower((string)pathinfo((string)$fileName, PATHINFO_EXTENSION));
    return in_array($format, ['csv', 'xls', 'xlsx', 'xlsm', 'pdf'], true) ? $format : '';
}

function logWasteImportEvent($conn, array $event)
{
    if (!$conn) {
        return false;
    }

    $allowedOutcomes = ['imported', 'cancelled', 'duplicate', 'validation_error', 'expired', 'failed'];
    $outcome = (string)($event['outcome'] ?? 'failed');
    if (!in_array($outcome, $allowedOutcomes, true)) {
        $outcome = 'failed';
    }
    $fileName = substr(basename((string)($event['original_name'] ?? '')), 0, 255);
    $reasonCode = strtolower(trim((string)($event['reason_code'] ?? '')));
    $reasonCode = preg_replace('/[^a-z0-9_-]+/', '_', $reasonCode);
    $reasonCode = substr((string)$reasonCode, 0, 60);
    $eventKey = trim((string)($event['event_key'] ?? ''));
    $eventKey = $eventKey === '' ? null : substr($eventKey, 0, 160);

    try {
        ensureWasteImportAuditTable($conn);
        $stmt = $conn->prepare('INSERT IGNORE INTO waste_import_events
            (event_key, actor_user_id, original_name, file_format, valid_row_count, skipped_row_count, outcome, reason_code, event_source)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, \'live\')');
        $stmt->execute([
            $eventKey,
            !empty($event['actor_user_id']) ? (int)$event['actor_user_id'] : null,
            $fileName !== '' ? $fileName : null,
            wasteImportAuditFormat($event['file_format'] ?? $fileName),
            max(0, (int)($event['valid_row_count'] ?? 0)),
            max(0, (int)($event['skipped_row_count'] ?? 0)),
            $outcome,
            $reasonCode !== '' ? $reasonCode : null,
        ]);
        if ($eventKey === null) {
            return (int)$conn->lastInsertId();
        }
        $lookup = $conn->prepare('SELECT id FROM waste_import_events WHERE event_key = ? LIMIT 1');
        $lookup->execute([$eventKey]);
        return (int)$lookup->fetchColumn();
    } catch (PDOException $e) {
        error_log('Failed to write waste import audit event: ' . $e->getMessage());
        return false;
    }
}

/** Record the import outcome before preview metadata is removed. */
function logWasteImportBatchOutcome($conn, $batchId, $outcome, $reasonCode = '')
{
    if (!$conn || trim((string)$batchId) === '') {
        return;
    }

    try {
        $stmt = $conn->prepare('SELECT b.created_by, b.row_count, bf.file_sha256, bf.original_name, bf.row_count AS file_row_count
            FROM waste_import_batches b
            LEFT JOIN waste_import_batch_files bf ON bf.batch_id = b.id
            WHERE b.id = ?');
        $stmt->execute([$batchId]);
        $files = $stmt->fetchAll();
        if (empty($files)) {
            return;
        }
        foreach ($files as $file) {
            $fileHash = trim((string)($file['file_sha256'] ?? ''));
            $eventKey = $outcome === 'imported' && $fileHash !== ''
                ? 'manifest:' . $fileHash
                : 'batch:' . $batchId . ':' . $outcome . ':' . ($fileHash !== '' ? $fileHash : 'summary');
            $issueCount = 0;
            if ($fileHash !== '') {
                $issueCountStmt = $conn->prepare('SELECT COUNT(*) FROM waste_import_batch_issues WHERE batch_id = ? AND source_file_sha256 = ?');
                $issueCountStmt->execute([$batchId, $fileHash]);
                $issueCount = (int)$issueCountStmt->fetchColumn();
            }
            $eventId = logWasteImportEvent($conn, [
                'event_key' => $eventKey,
                'actor_user_id' => $file['created_by'] ?? null,
                'original_name' => $file['original_name'] ?? '',
                'file_format' => $file['original_name'] ?? '',
                'valid_row_count' => $file['file_row_count'] ?? $file['row_count'] ?? 0,
                'skipped_row_count' => $issueCount,
                'outcome' => $outcome,
                'reason_code' => $reasonCode,
            ]);
            if ($eventId > 0 && $fileHash !== '') {
                $copyIssues = $conn->prepare("INSERT IGNORE INTO waste_import_event_issues (event_id, worksheet_name, source_row_number, reason_code)
                    SELECT ?, worksheet_name, source_row_number, reason_code
                    FROM waste_import_batch_issues WHERE batch_id = ? AND source_file_sha256 = ?");
                $copyIssues->execute([$eventId, $batchId, $fileHash]);
            }
        }
    } catch (PDOException $e) {
        error_log('Failed to record waste import batch outcome: ' . $e->getMessage());
    }
}

function backfillWasteRecordFingerprints($conn, $batchSize = 500)
{
    if (!$conn) {
        return 0;
    }

    ensureWasteImportTables($conn);
    $state = $conn->query("SELECT last_record_id FROM waste_import_backfill_state WHERE state_key = 'record_fingerprints'")->fetch();
    $lastRecordId = (int)($state['last_record_id'] ?? 0);
    $processed = 0;
    $select = $conn->prepare("SELECT id, collection_date, collection_group, collection_group_type, reporting_period, street, name_of_bioman, households, comply_tue, cd_processing, comply_wed, comply_fri, tuesday_factory_returnable_kg, wednesday_biowaste_kg, thursday_factory_returnable_kg, friday_biowaste_kg, saturday_hazard_waste_kg, residual_waste_kg, unclassified_waste_kg FROM waste_records WHERE id > ? AND is_active = 1 ORDER BY id ASC LIMIT " . max(1, (int)$batchSize));
    $insert = $conn->prepare('INSERT IGNORE INTO waste_record_fingerprints (record_fingerprint) VALUES (?)');
    $updateRecord = $conn->prepare('UPDATE waste_records SET record_fingerprint = COALESCE(record_fingerprint, ?) WHERE id = ?');
    $updateState = $conn->prepare("UPDATE waste_import_backfill_state SET last_record_id = ?, completed_at = NULL WHERE state_key = 'record_fingerprints'");

    while (true) {
        $select->execute([$lastRecordId]);
        $records = $select->fetchAll();
        if (empty($records)) {
            $completed = $conn->prepare("UPDATE waste_import_backfill_state SET completed_at = NOW() WHERE state_key = 'record_fingerprints'");
            $completed->execute();
            break;
        }

        $conn->beginTransaction();
        try {
            foreach ($records as $record) {
                $fingerprint = buildWasteRecordFingerprint($record);
                $insert->execute([$fingerprint]);
                $updateRecord->execute([$fingerprint, (int)$record['id']]);
                $lastRecordId = (int)$record['id'];
                $processed++;
            }
            $updateState->execute([$lastRecordId]);
            $conn->commit();
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            throw $e;
        }
    }

    return $processed;
}

// Create announcement read receipts on demand for existing installations.
function ensureStaffActivityTables($conn)
{
    if (!$conn) {
        return;
    }

    try {
        $conn->exec("CREATE TABLE IF NOT EXISTS announcement_reads (
            id INT AUTO_INCREMENT PRIMARY KEY,
            announcement_id INT NOT NULL,
            user_id INT NOT NULL,
            read_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_announcement_read (announcement_id, user_id),
            FOREIGN KEY (announcement_id) REFERENCES announcements(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )");
    } catch (PDOException $e) {
        error_log('Failed to create staff activity tables: ' . $e->getMessage());
    }
}

// Create staff issue-report storage on demand for existing installations.
function ensureReportsTable($conn)
{
    static $checked = false;
    if (!$conn || $checked) {
        return;
    }

    try {
        $conn->exec("CREATE TABLE IF NOT EXISTS reports (
            id INT AUTO_INCREMENT PRIMARY KEY,
            staff_id INT NOT NULL,
            report_type VARCHAR(50) NOT NULL,
            description TEXT NOT NULL,
            zone VARCHAR(100) NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'pending',
            admin_note TEXT NULL,
            reviewed_by INT NULL,
            reviewed_at TIMESTAMP NULL DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_reports_staff_created (staff_id, created_at),
            INDEX idx_reports_status_created (status, created_at)
        )");

        // Add review fields without invalidating historic pending reports.
        $existingColumns = $conn->query("SHOW COLUMNS FROM reports")->fetchAll(PDO::FETCH_COLUMN);
        $reportColumnMigrations = [
            'admin_note' => "ALTER TABLE reports ADD COLUMN admin_note TEXT NULL AFTER status",
            'reviewed_by' => "ALTER TABLE reports ADD COLUMN reviewed_by INT NULL AFTER admin_note",
            'reviewed_at' => "ALTER TABLE reports ADD COLUMN reviewed_at TIMESTAMP NULL DEFAULT NULL AFTER reviewed_by",
            'updated_at' => "ALTER TABLE reports ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
        ];
        foreach ($reportColumnMigrations as $column => $migration) {
            if (!in_array($column, $existingColumns, true)) {
                $conn->exec($migration);
            }
        }
        $checked = true;
    } catch (PDOException $e) {
        error_log('Failed to create reports table: ' . $e->getMessage());
    }
}

// Create append-only activity logging on demand for existing installations.
function ensureActivityLogsTable($conn)
{
    static $checked = false;
    if (!$conn || $checked) {
        return;
    }

    try {
        $conn->exec("CREATE TABLE IF NOT EXISTS activity_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            user_name VARCHAR(150) NOT NULL,
            user_role VARCHAR(50) NOT NULL,
            action VARCHAR(150) NOT NULL,
            module VARCHAR(100) NOT NULL,
            status ENUM('Success', 'Failed') NOT NULL DEFAULT 'Success',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_activity_logs_created_at (created_at),
            INDEX idx_activity_logs_user_name (user_name),
            INDEX idx_activity_logs_action_module (action, module)
        )");
        $checked = true;
    } catch (PDOException $e) {
        error_log('Failed to create activity logs table: ' . $e->getMessage());
    }
}

function logActivity($action, $module, $status = 'Success', $conn = null, $actor = null)
{
    $conn = $conn ?: getDBConnection();
    if (!$conn) {
        return;
    }

    ensureActivityLogsTable($conn);
    $actor = $actor ?: ($_SESSION['user'] ?? []);
    $userId = !empty($actor['id']) ? (int)$actor['id'] : null;
    $userName = trim(($actor['first_name'] ?? '') . ' ' . ($actor['last_name'] ?? ''));
    if ($userName === '') {
        $userName = $actor['username'] ?? 'Unknown user';
    }
    $userRole = ucfirst($actor['user_type'] ?? $actor['role'] ?? 'Guest');
    $status = $status === 'Failed' ? 'Failed' : 'Success';

    try {
        $stmt = $conn->prepare('INSERT INTO activity_logs (user_id, user_name, user_role, action, module, status) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$userId, $userName, $userRole, $action, $module, $status]);
    } catch (PDOException $e) {
        error_log('Failed to write activity log: ' . $e->getMessage());
    }
}

function getDefaultUserSettings()
{
    return [
        'system_alerts' => 1,
        'reports_reminder' => 1,
        'high_waste_alerts' => 1,
        'two_factor_enabled' => 0,
        'session_timeout_minutes' => 30,
        'auto_delete_old_reports' => 1,
        'share_anonymized_data' => 0,
        'data_export_enabled' => 1,
    ];
}

// Persist account preferences across sessions.
function ensureUserSettingsTable($conn)
{
    static $checked = false;
    if (!$conn || $checked) {
        return;
    }

    try {
        $conn->exec("CREATE TABLE IF NOT EXISTS user_settings (
            user_id INT NOT NULL PRIMARY KEY,
            system_alerts TINYINT(1) NOT NULL DEFAULT 1,
            reports_reminder TINYINT(1) NOT NULL DEFAULT 1,
            high_waste_alerts TINYINT(1) NOT NULL DEFAULT 1,
            two_factor_enabled TINYINT(1) NOT NULL DEFAULT 0,
            session_timeout_minutes SMALLINT NOT NULL DEFAULT 30,
            auto_delete_old_reports TINYINT(1) NOT NULL DEFAULT 1,
            share_anonymized_data TINYINT(1) NOT NULL DEFAULT 0,
            data_export_enabled TINYINT(1) NOT NULL DEFAULT 1,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");
        $checked = true;
    } catch (PDOException $e) {
        error_log('Failed to create user settings table: ' . $e->getMessage());
    }
}

function getUserSettings($conn, $userId)
{
    $defaults = getDefaultUserSettings();
    $userId = (int)$userId;
    if (!$conn || $userId <= 0) {
        return $defaults;
    }

    ensureUserSettingsTable($conn);

    try {
        $stmt = $conn->prepare('SELECT system_alerts, reports_reminder, high_waste_alerts, two_factor_enabled, session_timeout_minutes, auto_delete_old_reports, share_anonymized_data, data_export_enabled FROM user_settings WHERE user_id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $stored = $stmt->fetch();
        if (!$stored) {
            return $defaults;
        }

        foreach (['system_alerts', 'reports_reminder', 'high_waste_alerts', 'two_factor_enabled', 'auto_delete_old_reports', 'share_anonymized_data', 'data_export_enabled'] as $booleanKey) {
            $stored[$booleanKey] = (int)$stored[$booleanKey] ? 1 : 0;
        }
        $stored['session_timeout_minutes'] = (int)$stored['session_timeout_minutes'];
        return array_merge($defaults, $stored);
    } catch (PDOException $e) {
        error_log('Failed to load user settings: ' . $e->getMessage());
        return $defaults;
    }
}

function saveUserSettings($conn, $userId, array $settings)
{
    $userId = (int)$userId;
    if (!$conn || $userId <= 0) {
        return false;
    }

    $settings = array_merge(getDefaultUserSettings(), $settings);

    try {
        ensureUserSettingsTable($conn);
        $stmt = $conn->prepare('INSERT INTO user_settings (user_id, system_alerts, reports_reminder, high_waste_alerts, two_factor_enabled, session_timeout_minutes, auto_delete_old_reports, share_anonymized_data, data_export_enabled)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                system_alerts = VALUES(system_alerts),
                reports_reminder = VALUES(reports_reminder),
                high_waste_alerts = VALUES(high_waste_alerts),
                two_factor_enabled = VALUES(two_factor_enabled),
                session_timeout_minutes = VALUES(session_timeout_minutes),
                auto_delete_old_reports = VALUES(auto_delete_old_reports),
                share_anonymized_data = VALUES(share_anonymized_data),
                data_export_enabled = VALUES(data_export_enabled)');
        return $stmt->execute([
            $userId,
            (int)$settings['system_alerts'],
            (int)$settings['reports_reminder'],
            (int)$settings['high_waste_alerts'],
            (int)$settings['two_factor_enabled'],
            (int)$settings['session_timeout_minutes'],
            (int)$settings['auto_delete_old_reports'],
            (int)$settings['share_anonymized_data'],
            (int)$settings['data_export_enabled'],
        ]);
    } catch (PDOException $e) {
        error_log('Failed to save user settings: ' . $e->getMessage());
        return false;
    }
}

function applyUserSettingsToSession(array $settings)
{
    initSession();
    $_SESSION['user_settings'] = $settings;
    $_SESSION['system_alerts'] = (int)$settings['system_alerts'];
    $_SESSION['reports_reminder'] = (int)$settings['reports_reminder'];
    $_SESSION['high_waste_alerts'] = (int)$settings['high_waste_alerts'];
    $_SESSION['session_timeout_seconds'] = (int)$settings['session_timeout_minutes'] * 60;
}

function completeEcoTrackLogin($conn, array $user, $ipAddress = null, $restoredFromPersistentLogin = false)
{
    initSession();
    session_regenerate_id(true);
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user'] = $user;
    $_SESSION['last_activity'] = time();
    applyUserSettingsToSession(getUserSettings($conn, $user['id']));

    $updateStmt = $conn->prepare('UPDATE users SET last_login = NOW() WHERE id = ?');
    $updateStmt->execute([$user['id']]);

    if (!issuePersistentLoginToken($conn, $user['id'])) {
        error_log('Authenticated session started without a persistent login token for user ' . (int)$user['id']);
    }

    if ($restoredFromPersistentLogin) {
        logActivity('Restored remembered login', 'Authentication', 'Success', $conn, $user);
    } else {
        $ipAddress = $ipAddress ?: ($_SERVER['REMOTE_ADDR'] ?? '');
        logLoginAttempt($user['username'] ?? '', $ipAddress, true);
        logActivity('Logged in', 'Authentication', 'Success', $conn, $user);
    }

    if (($user['user_type'] ?? '') !== 'admin') {
        return 'staff_home.php';
    }

    return 'admin_dashboard.php';
}

function ensureTwoFactorCodesTable($conn)
{
    static $checked = false;
    if (!$conn || $checked) {
        return;
    }

    try {
        $conn->exec("CREATE TABLE IF NOT EXISTS two_factor_codes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            code VARCHAR(12) NOT NULL,
            expires_at DATETIME NOT NULL,
            used TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_two_factor_codes_user (user_id, used, expires_at)
        )");
        $checked = true;
    } catch (PDOException $e) {
        error_log('Failed to create two-factor code table: ' . $e->getMessage());
    }
}

/**
 * Short-lived action codes authorize one sensitive action and never sign-in.
 *
 * Export codes existed before action-scoping was introduced. Keeping the table
 * name avoids a disruptive migration; the purpose column prevents a code for
 * one action from being replayed for another.
 */
function ensureDataExportOtpsTable($conn)
{
    static $checked = false;
    if (!$conn || $checked) {
        return;
    }

    try {
        $conn->exec("CREATE TABLE IF NOT EXISTS data_export_otps (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            purpose VARCHAR(64) NOT NULL DEFAULT 'export',
            code_hash VARCHAR(255) NOT NULL,
            expires_at DATETIME NOT NULL,
            used TINYINT(1) NOT NULL DEFAULT 0,
            used_at DATETIME NULL DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_data_export_otps_user (user_id, used, expires_at),
            INDEX idx_data_export_otps_purpose (user_id, purpose, used, expires_at)
        )");

        $purposeColumn = $conn->query("SHOW COLUMNS FROM data_export_otps LIKE 'purpose'")->fetch();
        if (!$purposeColumn) {
            // Existing rows are retained as export codes by the column default.
            $conn->exec("ALTER TABLE data_export_otps ADD COLUMN purpose VARCHAR(64) NOT NULL DEFAULT 'export' AFTER user_id");
        }

        $hasPurposeIndex = false;
        foreach ($conn->query('SHOW INDEX FROM data_export_otps')->fetchAll() as $index) {
            if (($index['Key_name'] ?? '') === 'idx_data_export_otps_purpose') {
                $hasPurposeIndex = true;
                break;
            }
        }
        if (!$hasPurposeIndex) {
            $conn->exec('CREATE INDEX idx_data_export_otps_purpose ON data_export_otps (user_id, purpose, used, expires_at)');
        }
        $checked = true;
    } catch (PDOException $e) {
        error_log('Failed to create data-export OTP table: ' . $e->getMessage());
    }
}

function isSupportedActionOtpPurpose($purpose)
{
    return in_array($purpose, [
        'export',
        'recent_import_activity_print',
        'waste_import',
    ], true);
}

function actionOtpLabel($purpose)
{
    $labels = [
        'export' => 'data download',
        'recent_import_activity_print' => 'Recent Import Activity printout',
        'waste_import' => 'Waste Data import',
    ];

    return $labels[$purpose] ?? 'EcoTrack action';
}

function actionOtpRequiresDataExportEnabled($purpose)
{
    return in_array($purpose, ['export', 'recent_import_activity_print'], true);
}

function canUseActionOtp($conn, $userId, $purpose)
{
    if (!isSupportedActionOtpPurpose($purpose)) {
        return false;
    }

    if (!actionOtpRequiresDataExportEnabled($purpose)) {
        return true;
    }

    $settings = getUserSettings($conn, $userId);
    return !empty($settings['data_export_enabled']);
}

function sendActionOtpEmail(array $user, $code, $purpose)
{
    $email = trim((string)($user['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !isSupportedActionOtpPurpose($purpose) || !isSmtpConfigured()) {
        return false;
    }

    $phpMailerPath = __DIR__ . '/PHPMailer/src/PHPMailer.php';
    $smtpPath = __DIR__ . '/PHPMailer/src/SMTP.php';
    $exceptionPath = __DIR__ . '/PHPMailer/src/Exception.php';
    if (!is_file($phpMailerPath) || !is_file($smtpPath) || !is_file($exceptionPath)) {
        error_log('Data-export OTP email could not load PHPMailer.');
        return false;
    }

    require_once $phpMailerPath;
    require_once $smtpPath;
    require_once $exceptionPath;

    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = EMAIL_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = EMAIL_USERNAME;
        $mail->Password = EMAIL_PASSWORD;
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = EMAIL_PORT;
        $mail->setFrom(EMAIL_FROM, EMAIL_FROM_NAME);
        $mail->addAddress($email);
        $mail->isHTML(true);
        $actionLabel = actionOtpLabel($purpose);
        $mail->Subject = 'EcoTrack verification code';
        $displayName = trim((string)($user['first_name'] ?? '') . ' ' . (string)($user['last_name'] ?? ''));
        if ($displayName === '') {
            $displayName = (string)($user['username'] ?? 'EcoTrack user');
        }
        $safeName = htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8');
        $mail->Body = '<p>Hello ' . $safeName . ',</p><p>Your EcoTrack verification code for this ' . htmlspecialchars($actionLabel, ENT_QUOTES, 'UTF-8') . ' is:</p><p style="font-size:28px;font-weight:700;letter-spacing:6px;">' . $code . '</p><p>This code expires in 5 minutes and authorizes one ' . htmlspecialchars($actionLabel, ENT_QUOTES, 'UTF-8') . '. If you did not request it, you can ignore this email.</p>';
        $mail->AltBody = 'Your EcoTrack verification code for this ' . $actionLabel . ' is ' . $code . '. It expires in 5 minutes and authorizes one action.';
        $mail->send();
        return true;
    } catch (\PHPMailer\PHPMailer\Exception $e) {
        error_log('Data-export OTP email error: ' . $e->getMessage());
        return false;
    }
}

/** Send a purpose-scoped code and invalidate only older codes for that purpose. */
function issueActionOtp($conn, array $user, $purpose)
{
    $userId = (int)($user['id'] ?? 0);
    // Settings enables export by issuing its first code before the preference
    // is persisted, so policy enforcement belongs to request/verification.
    if (!$conn || $userId <= 0 || empty($user['email']) || !isSupportedActionOtpPurpose($purpose)) {
        return false;
    }

    ensureDataExportOtpsTable($conn);
    $code = sprintf('%06d', random_int(0, 999999));

    try {
        $clearPending = $conn->prepare('DELETE FROM data_export_otps WHERE user_id = ? AND purpose = ? AND used = 0');
        $clearPending->execute([$userId, $purpose]);
        $insert = $conn->prepare('INSERT INTO data_export_otps (user_id, purpose, code_hash, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 5 MINUTE))');
        $insert->execute([$userId, $purpose, password_hash($code, PASSWORD_DEFAULT)]);
        $otpId = (int)$conn->lastInsertId();

        if (sendActionOtpEmail($user, $code, $purpose)) {
            return true;
        }

        $delete = $conn->prepare('DELETE FROM data_export_otps WHERE id = ?');
        $delete->execute([$otpId]);
    } catch (PDOException $e) {
        error_log('Failed to issue data-export OTP: ' . $e->getMessage());
    }

    return false;
}

/** Avoid sending another purpose-scoped code while one remains valid. */
function ensurePendingActionOtp($conn, array $user, $purpose, &$wasAlreadyPending = false)
{
    $wasAlreadyPending = false;
    $userId = (int)($user['id'] ?? 0);
    if (!$conn || $userId <= 0 || !canUseActionOtp($conn, $userId, $purpose)) {
        return false;
    }

    ensureDataExportOtpsTable($conn);
    try {
        $pending = $conn->prepare('SELECT id FROM data_export_otps WHERE user_id = ? AND purpose = ? AND used = 0 AND expires_at > NOW() ORDER BY created_at DESC LIMIT 1');
        $pending->execute([$userId, $purpose]);
        if ($pending->fetchColumn()) {
            $wasAlreadyPending = true;
            return true;
        }
    } catch (PDOException $e) {
        error_log('Failed to inspect data-export OTP: ' . $e->getMessage());
        return false;
    }

    return issueActionOtp($conn, $user, $purpose);
}

/** Validate and consume a purpose-scoped code in the same request. */
function verifyAndConsumeActionOtp($conn, array $user, $purpose, $code)
{
    $userId = (int)($user['id'] ?? 0);
    $code = trim((string)$code);
    if (!$conn || $userId <= 0 || !preg_match('/^\d{6}$/', $code) || !canUseActionOtp($conn, $userId, $purpose)) {
        return false;
    }

    ensureDataExportOtpsTable($conn);
    try {
        $lookup = $conn->prepare('SELECT id, code_hash FROM data_export_otps WHERE user_id = ? AND purpose = ? AND used = 0 AND expires_at > NOW() ORDER BY created_at DESC LIMIT 1');
        $lookup->execute([$userId, $purpose]);
        $otp = $lookup->fetch();
        if (!$otp || !password_verify($code, (string)$otp['code_hash'])) {
            return false;
        }

        $consume = $conn->prepare('UPDATE data_export_otps SET used = 1, used_at = NOW() WHERE id = ? AND user_id = ? AND purpose = ? AND used = 0 AND expires_at > NOW()');
        $consume->execute([(int)$otp['id'], $userId, $purpose]);
        return $consume->rowCount() === 1;
    } catch (PDOException $e) {
        error_log('Failed to verify data-export OTP: ' . $e->getMessage());
        return false;
    }
}

/** Backwards-compatible export helpers used by the Waste Data page. */
function sendDataExportOtpEmail(array $user, $code)
{
    return sendActionOtpEmail($user, $code, 'export');
}

function issueDataExportOtp($conn, array $user)
{
    return issueActionOtp($conn, $user, 'export');
}

function ensurePendingDataExportOtp($conn, array $user, &$wasAlreadyPending = false)
{
    return ensurePendingActionOtp($conn, $user, 'export', $wasAlreadyPending);
}

function verifyAndConsumeDataExportOtp($conn, array $user, $code)
{
    return verifyAndConsumeActionOtp($conn, $user, 'export', $code);
}

/** Keep staff assignments from one posted task addressable as a single batch. */
function ensureDailyTaskBatching($conn)
{
    static $checked = false;
    if (!$conn || $checked) {
        return;
    }

    try {
        $taskTable = $conn->query("SHOW TABLES LIKE 'daily_tasks'");
        if (!$taskTable || $taskTable->rowCount() === 0) {
            return;
        }

        $columns = [];
        foreach ($conn->query('SHOW COLUMNS FROM daily_tasks')->fetchAll() as $column) {
            $columns[$column['Field']] = true;
        }
        if (!isset($columns['task_batch_id'])) {
            $conn->exec('ALTER TABLE daily_tasks ADD COLUMN task_batch_id CHAR(32) NULL AFTER id');
        }

        $batchIndex = $conn->query("SHOW INDEX FROM daily_tasks WHERE Key_name = 'idx_daily_tasks_batch'");
        if (!$batchIndex || $batchIndex->rowCount() === 0) {
            $conn->exec('ALTER TABLE daily_tasks ADD INDEX idx_daily_tasks_batch (task_batch_id)');
        }

        // Older task posts created one row per staff member without a shared ID.
        // Copies created by the same post have identical author, timestamp, date, and content.
        $legacyBatches = $conn->query("SELECT created_by, created_at, task_date, title, description, priority
            FROM daily_tasks
            WHERE task_batch_id IS NULL
            GROUP BY created_by, created_at, task_date, title, description, priority");
        $assignBatch = $conn->prepare('UPDATE daily_tasks SET task_batch_id = ?
            WHERE task_batch_id IS NULL
              AND created_by = ?
              AND created_at = ?
              AND task_date = ?
              AND title = ?
              AND description = ?
              AND priority = ?');
        foreach ($legacyBatches->fetchAll() as $legacyBatch) {
            $assignBatch->execute([
                bin2hex(random_bytes(16)),
                (int)$legacyBatch['created_by'],
                $legacyBatch['created_at'],
                $legacyBatch['task_date'],
                $legacyBatch['title'],
                $legacyBatch['description'],
                $legacyBatch['priority'],
            ]);
        }

        $checked = true;
    } catch (Throwable $e) {
        error_log('Failed to prepare daily task batches: ' . $e->getMessage());
    }
}

// Create per-recipient notification read state on demand.
function ensureNotificationsTable($conn)
{
    static $checked = false;
    if (!$conn || $checked) {
        return;
    }

    try {
        $conn->exec("CREATE TABLE IF NOT EXISTS notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            recipient_user_id INT NOT NULL,
            actor_user_id INT NULL,
            notification_type VARCHAR(50) NOT NULL DEFAULT 'general',
            title VARCHAR(160) NOT NULL,
            message TEXT NOT NULL,
            destination_url VARCHAR(255) NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            read_at TIMESTAMP NULL DEFAULT NULL,
            INDEX idx_notifications_recipient_read_created (recipient_user_id, is_read, created_at),
            INDEX idx_notifications_created_at (created_at)
        )");
        $checked = true;
    } catch (PDOException $e) {
        error_log('Failed to create notifications table: ' . $e->getMessage());
    }
}

function createUserNotification($conn, $recipientUserId, $title, $message, $destinationUrl = '', $notificationType = 'general', $actorUserId = null)
{
    $recipientUserId = (int)$recipientUserId;
    if (!$conn || $recipientUserId <= 0) {
        return false;
    }

    // MySQL DDL commits implicitly; initialize this table before a transaction.
    if (!$conn->inTransaction()) {
        ensureNotificationsTable($conn);
    }

    try {
        $stmt = $conn->prepare('INSERT INTO notifications (recipient_user_id, actor_user_id, notification_type, title, message, destination_url) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $recipientUserId,
            $actorUserId ? (int)$actorUserId : null,
            trim((string)$notificationType) ?: 'general',
            trim((string)$title),
            trim((string)$message),
            trim((string)$destinationUrl) ?: null,
        ]);
        return true;
    } catch (PDOException $e) {
        error_log('Failed to create notification: ' . $e->getMessage());
        return false;
    }
}

function ensureDailyReportReminder($conn, $user, array $settings)
{
    if (($user['user_type'] ?? '') !== 'staff' || empty($user['id']) || empty($settings['reports_reminder'])) {
        return;
    }

    try {
        $stmt = $conn->prepare("SELECT id FROM notifications WHERE recipient_user_id = ? AND notification_type = 'report_reminder' AND DATE(created_at) = CURDATE() LIMIT 1");
        $stmt->execute([(int)$user['id']]);
        if (!$stmt->fetch()) {
            createUserNotification(
                $conn,
                $user['id'],
                'Daily report reminder',
                'Review today\'s work and submit a staff report when you have an update to share.',
                'staff_announcements.php',
                'report_reminder'
            );
        }
    } catch (PDOException $e) {
        error_log('Failed to create daily report reminder: ' . $e->getMessage());
    }
}

// Notification preferences must affect both generation and presentation. This
// keeps unread items created before a preference was disabled out of the bell
// without deleting the user's notification history.
function areNotificationPreferencesEnabled(array $settings)
{
    return !empty($settings['reports_reminder']) || !empty($settings['high_waste_alerts']);
}

function getDisabledNotificationTypes(array $user, array $settings)
{
    $userType = $user['user_type'] ?? '';
    $disabledTypes = [];

    if ($userType === 'staff' && empty($settings['reports_reminder'])) {
        $disabledTypes[] = 'report_reminder';
    }

    if ($userType === 'admin' && empty($settings['high_waste_alerts'])) {
        $disabledTypes[] = 'high_waste_alert';
    }

    return $disabledTypes;
}

function normalizeEcoTrackRoute($route)
{
    $route = trim((string)$route);
    if ($route === '') {
        return '';
    }

    $parts = explode('?', $route, 2);
    $legacyRoutes = [
        'Login.php' => 'login.php',
        'Waste_data.php' => 'waste_records.php',
        'dss.php' => 'admin_collection_schedule.php',
        'dss_responsive_preview.php' => 'admin_collection_schedule_preview.php',
        'staff_dashboard.php' => 'staff_home.php',
        'upload_csv.php' => 'admin_waste_import.php',
        'heatmap.php' => 'waste_heatmap.php',
        'report.php' => 'admin_operations_reports.php',
        'manage_users.php' => 'admin_user_management.php',
        'settings.php' => 'system_settings.php',
        'create_user.php' => 'admin_user_creation.php',
        'daily_task.php' => 'staff_daily_tasks.php',
        'daily_tasks.php' => 'staff_daily_tasks.php',
        'announcement.php' => 'staff_announcements.php',
        'announcements.php' => 'staff_announcements.php',
        'forgot_password.php' => 'password_reset_request.php',
        'verify_code.php' => 'password_reset_verification.php',
        'reset_password.php' => 'password_reset.php',
        'two_factor_verify.php' => 'two_factor_verification.php',
        'unauthorized.php' => 'access_denied.php',
        'legacy_heatmap_redirect.php' => 'route_planning_redirect.php',
        'legacy_role_redirect.php' => 'role_landing_redirect.php',
    ];

    $normalized = $legacyRoutes[$parts[0]] ?? $parts[0];
    return $normalized . (isset($parts[1]) ? '?' . $parts[1] : '');
}

function getNotificationItems($conn, $user)
{
    $items = [];
    if (!$conn || empty($user['id'])) {
        return $items;
    }

    try {
        ensureNotificationsTable($conn);
        $settings = getUserSettings($conn, $user['id']);
        ensureDailyReportReminder($conn, $user, $settings);
        // With both notification controls off, no notification category is
        // enabled. Suppress every unread item, including task and report
        // notifications that predate the preference change.
        if (!areNotificationPreferencesEnabled($settings)) {
            return $items;
        }
        $disabledTypes = getDisabledNotificationTypes($user, $settings);
        $query = 'SELECT id, title, message, destination_url, created_at
            FROM notifications
            WHERE recipient_user_id = ? AND is_read = 0';
        $params = [(int)$user['id']];

        if (!empty($disabledTypes)) {
            $query .= ' AND notification_type NOT IN (' . implode(', ', array_fill(0, count($disabledTypes), '?')) . ')';
            $params = array_merge($params, $disabledTypes);
        }

        $query .= ' ORDER BY created_at DESC, id DESC LIMIT 10';
        $stmt = $conn->prepare($query);
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $row) {
            $createdAt = !empty($row['created_at']) ? strtotime($row['created_at']) : false;
            $items[] = [
                'id' => (int)$row['id'],
                'url' => normalizeEcoTrackRoute($row['destination_url'] ?? '') ?: 'admin_dashboard.php',
                'title' => $row['title'],
                'text' => $row['message'],
                'time' => $createdAt ? date('M j, Y g:i A', $createdAt) : '',
            ];
        }
    } catch (PDOException $e) {
        error_log('Failed to load notifications: ' . $e->getMessage());
    }

    return $items;
}

function isHttpsRequest()
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
}

function persistentLoginCookieOptions($expires)
{
    return [
        'expires' => (int)$expires,
        'path' => '/',
        'secure' => isHttpsRequest(),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function clearPersistentLoginCookie()
{
    setcookie(PERSISTENT_LOGIN_COOKIE, '', persistentLoginCookieOptions(time() - 42000));
    unset($_COOKIE[PERSISTENT_LOGIN_COOKIE]);
}

function setPersistentLoginCookie($selector, $validator)
{
    $value = $selector . ':' . $validator;
    setcookie(PERSISTENT_LOGIN_COOKIE, $value, persistentLoginCookieOptions(time() + PERSISTENT_LOGIN_LIFETIME));
    $_COOKIE[PERSISTENT_LOGIN_COOKIE] = $value;
}

function persistentLoginCookieParts()
{
    $value = $_COOKIE[PERSISTENT_LOGIN_COOKIE] ?? '';
    if (!is_string($value) || !preg_match('/^([a-f0-9]{24}):([a-f0-9]{64})$/D', $value, $matches)) {
        return null;
    }

    return [
        'selector' => $matches[1],
        'validator' => $matches[2],
    ];
}

function ensurePersistentLoginTokensTable($conn)
{
    static $checked = false;
    if (!$conn) {
        return false;
    }
    if ($checked) {
        return true;
    }

    try {
        $conn->exec("CREATE TABLE IF NOT EXISTS persistent_login_tokens (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            selector CHAR(24) NOT NULL,
            token_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_used_at DATETIME NULL DEFAULT NULL,
            UNIQUE KEY unique_persistent_login_selector (selector),
            INDEX idx_persistent_login_user (user_id),
            INDEX idx_persistent_login_expiry (expires_at),
            CONSTRAINT fk_persistent_login_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $checked = true;
        return true;
    } catch (PDOException $e) {
        error_log('Failed to create persistent login token table: ' . $e->getMessage());
        return false;
    }
}

function deleteExpiredPersistentLoginTokens($conn)
{
    if (!ensurePersistentLoginTokensTable($conn)) {
        return false;
    }

    try {
        $stmt = $conn->prepare('DELETE FROM persistent_login_tokens WHERE expires_at <= NOW()');
        return $stmt->execute();
    } catch (PDOException $e) {
        error_log('Failed to remove expired persistent login tokens: ' . $e->getMessage());
        return false;
    }
}

function revokePersistentLoginBySelector($conn, $selector)
{
    if (!$conn || !preg_match('/^[a-f0-9]{24}$/D', (string)$selector) || !ensurePersistentLoginTokensTable($conn)) {
        return false;
    }

    try {
        $stmt = $conn->prepare('DELETE FROM persistent_login_tokens WHERE selector = ?');
        return $stmt->execute([$selector]);
    } catch (PDOException $e) {
        error_log('Failed to revoke persistent login token: ' . $e->getMessage());
        return false;
    }
}

function revokeCurrentPersistentLogin($conn = null)
{
    $parts = persistentLoginCookieParts();
    if ($parts && $conn) {
        revokePersistentLoginBySelector($conn, $parts['selector']);
    }
    clearPersistentLoginCookie();
}

function revokePersistentLoginsForUser($conn, $userId)
{
    if (!$conn || (int)$userId <= 0 || !ensurePersistentLoginTokensTable($conn)) {
        return false;
    }

    try {
        $stmt = $conn->prepare('DELETE FROM persistent_login_tokens WHERE user_id = ?');
        return $stmt->execute([(int)$userId]);
    } catch (PDOException $e) {
        error_log('Failed to revoke persistent logins for user: ' . $e->getMessage());
        return false;
    }
}

function issuePersistentLoginToken($conn, $userId)
{
    if (!$conn || (int)$userId <= 0 || !ensurePersistentLoginTokensTable($conn)) {
        return false;
    }

    try {
        deleteExpiredPersistentLoginTokens($conn);
        $currentToken = persistentLoginCookieParts();
        if ($currentToken) {
            revokePersistentLoginBySelector($conn, $currentToken['selector']);
        }

        $selector = bin2hex(random_bytes(12));
        $validator = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + PERSISTENT_LOGIN_LIFETIME);
        $stmt = $conn->prepare('INSERT INTO persistent_login_tokens (user_id, selector, token_hash, expires_at) VALUES (?, ?, ?, ?)');
        $stmt->execute([(int)$userId, $selector, hash('sha256', $validator), $expiresAt]);
        setPersistentLoginCookie($selector, $validator);
        return true;
    } catch (Throwable $e) {
        error_log('Failed to issue persistent login token: ' . $e->getMessage());
        return false;
    }
}

function getPersistentLoginUser($conn)
{
    $parts = persistentLoginCookieParts();
    if (!$parts) {
        if (isset($_COOKIE[PERSISTENT_LOGIN_COOKIE])) {
            clearPersistentLoginCookie();
        }
        return null;
    }
    if (!$conn || !ensurePersistentLoginTokensTable($conn)) {
        return null;
    }

    try {
        deleteExpiredPersistentLoginTokens($conn);
        $stmt = $conn->prepare('SELECT t.id AS persistent_token_id, t.token_hash, u.*
            FROM persistent_login_tokens t
            INNER JOIN users u ON u.id = t.user_id
            WHERE t.selector = ? AND t.expires_at > NOW() AND u.is_active = 1
            LIMIT 1');
        $stmt->execute([$parts['selector']]);
        $record = $stmt->fetch();

        if (!$record || !hash_equals((string)$record['token_hash'], hash('sha256', $parts['validator']))) {
            revokePersistentLoginBySelector($conn, $parts['selector']);
            clearPersistentLoginCookie();
            return null;
        }

        $usedStmt = $conn->prepare('UPDATE persistent_login_tokens SET last_used_at = NOW() WHERE id = ?');
        $usedStmt->execute([(int)$record['persistent_token_id']]);
        unset($record['persistent_token_id'], $record['token_hash']);
        return $record;
    } catch (PDOException $e) {
        error_log('Failed to validate persistent login token: ' . $e->getMessage());
        clearPersistentLoginCookie();
        return null;
    }
}

// Initialize session
function initSession()
{
    if (session_status() == PHP_SESSION_NONE) {
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => isHttpsRequest(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function sendNoCacheHeaders()
{
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
    header('Pragma: no-cache');
    header('Expires: 0');
}

// Check if user is logged in
function isLoggedIn()
{
    initSession();
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

// Get current user data
function getCurrentUser()
{
    if (isLoggedIn()) {
        return $_SESSION['user'];
    }
    return null;
}

// Redirect if not logged in
function requireLogin()
{
    if (!isLoggedIn()) {
        sendNoCacheHeaders();
        header("Location: login.php");
        exit();
    }

    if (isSessionTimeout()) {
        logoutUser();
        sendNoCacheHeaders();
        header("Location: login.php?timeout=1");
        exit();
    }

    // Revalidate account status and role for every protected request.
    $conn = getDBConnection();
    if (!$conn) {
        logoutUser();
        sendNoCacheHeaders();
        header('Location: login.php?auth=unavailable');
        exit();
    }
    try {
        $stmt = $conn->prepare('SELECT id, username, first_name, last_name, email, user_type, is_active FROM users WHERE id = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([(int)$_SESSION['user_id']]);
        $liveUser = $stmt->fetch();
    } catch (PDOException $e) {
        $liveUser = false;
        error_log('Session validation failed: ' . $e->getMessage());
    }
    if (!$liveUser) {
        logoutUser();
        sendNoCacheHeaders();
        header('Location: login.php?auth=expired');
        exit();
    }
    $_SESSION['user'] = array_merge((array)($_SESSION['user'] ?? []), $liveUser);
    $_SESSION['user_id'] = (int)$liveUser['id'];
    sendNoCacheHeaders();
    updateLastActivity();
}

// Check user type
function requireUserType($requiredType)
{
    requireLogin();
    $user = getCurrentUser();
    if (!$user || !isset($user['user_type']) || !hash_equals((string)$requiredType, (string)$user['user_type'])) {
        sendNoCacheHeaders();
        header("Location: access_denied.php");
        exit();
    }
}

// Log login attempt
function logLoginAttempt($username, $ip, $success)
{
    $conn = getDBConnection();
    if ($conn) {
        try {
            $stmt = $conn->prepare("INSERT INTO login_attempts (username, ip_address, success) VALUES (?, ?, ?)");
            $stmt->execute([$username, $ip, $success]);
        } catch (PDOException $e) {
            error_log("Failed to log login attempt: " . $e->getMessage());
        }
    }
}

// Check if account is locked due to too many attempts
function isAccountLocked($username)
{
    $username = trim((string)$username);
    if ($username === '') {
        return false;
    }
    $conn = getDBConnection();
    if (!$conn) {
        // Do not grant a login attempt when the lockout state cannot be read.
        return true;
    }
    try {
        $stmt = $conn->prepare('SELECT COUNT(*) FROM login_attempts WHERE username = ? AND success = 0 AND attempt_time >= DATE_SUB(NOW(), INTERVAL ' . (int)LOGIN_ATTEMPT_WINDOW . ' MINUTE)');
        $stmt->execute([$username]);
        return (int)$stmt->fetchColumn() >= MAX_LOGIN_ATTEMPTS;
    } catch (PDOException $e) {
        error_log('Could not check account lock status: ' . $e->getMessage());
        return true;
    }
}

// Check if session has timed out
function isSessionTimeout()
{
    initSession();
    if (isset($_SESSION['last_activity'])) {
        $inactive = time() - $_SESSION['last_activity'];
        $timeoutSeconds = (int)($_SESSION['session_timeout_seconds'] ?? SESSION_TIMEOUT);
        if ($timeoutSeconds <= 0) {
            $timeoutSeconds = SESSION_TIMEOUT;
        }
        if ($inactive >= $timeoutSeconds) {
            return true;
        }
    }
    return false;
}

// Update last activity timestamp
function updateLastActivity()
{
    initSession();
    $_SESSION['last_activity'] = time();
}

// Logout user and clear session
function logoutUser($revokePersistentLogin = true)
{
    initSession();
    if ($revokePersistentLogin) {
        $conn = getDBConnection();
        revokeCurrentPersistentLogin($conn);
    }
    session_unset();
    session_destroy();
    $sessionCookie = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $sessionCookie['path'] ?? '/', $sessionCookie['domain'] ?? '', (bool)($sessionCookie['secure'] ?? false), (bool)($sessionCookie['httponly'] ?? true));
    sendNoCacheHeaders();
}

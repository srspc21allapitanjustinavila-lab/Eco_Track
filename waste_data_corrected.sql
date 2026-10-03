-- ============================================
-- ECOTRACK WASTE DATA - SAFE INITIAL TABLE SETUP
-- ============================================
-- This file does not delete existing waste records or add sample data.

USE ecotrack_db;

CREATE TABLE IF NOT EXISTS waste_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    date VARCHAR(50) NOT NULL COMMENT 'Import date or collection period label',
    collection_date DATE DEFAULT NULL COMMENT 'Actual collection date used for monthly analytics',
    is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = active, 0 = archived from weekly trend views',
    phase_number VARCHAR(50) NOT NULL,
    phase_date_label VARCHAR(255) DEFAULT NULL,
    collection_group VARCHAR(255) DEFAULT NULL COMMENT 'Phase, establishments, or other reporting group',
    collection_group_type ENUM('phase', 'establishment') DEFAULT NULL,
    record_fingerprint CHAR(64) DEFAULT NULL,
    reporting_period VARCHAR(255) DEFAULT NULL COMMENT 'Exact uploaded date or reporting period text',
    street VARCHAR(100) NOT NULL,
    households VARCHAR(100) DEFAULT NULL,
    name_of_bioman VARCHAR(255) DEFAULT NULL,
    garbage_collector VARCHAR(100) DEFAULT NULL,
    kilogram_of_waste DECIMAL(10,2) DEFAULT 0,
    recyclable_kg DECIMAL(10,2) DEFAULT 0,
    residual_kg DECIMAL(10,2) DEFAULT 0,
    hazardous_kg DECIMAL(10,2) DEFAULT 0,
    tuesday_factory_returnable_kg DECIMAL(10,2) DEFAULT 0,
    comply_tue VARCHAR(50) DEFAULT NULL,
    cd_processing VARCHAR(100) DEFAULT NULL,
    wednesday_biowaste_kg DECIMAL(10,2) DEFAULT 0,
    comply_wed VARCHAR(50) DEFAULT NULL,
    thursday_factory_returnable_kg DECIMAL(10,2) DEFAULT 0,
    friday_biowaste_kg DECIMAL(10,2) DEFAULT 0,
    comply_fri VARCHAR(50) DEFAULT NULL,
    saturday_hazard_waste_kg DECIMAL(10,2) DEFAULT 0,
    residual_waste_kg DECIMAL(10,2) DEFAULT 0,
    unclassified_waste_kg DECIMAL(10,2) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by VARCHAR(100) DEFAULT NULL,
    INDEX idx_waste_records_collection_date (collection_date),
    INDEX idx_waste_records_active_collection_date (is_active, collection_date),
    INDEX idx_waste_records_date_group (collection_date, collection_group, id),
    UNIQUE KEY unique_waste_records_fingerprint (record_fingerprint),
    INDEX idx_waste_records_fingerprint (record_fingerprint)
);

-- Daily splits support time-filtered heatmap and DSS analytics.
CREATE TABLE IF NOT EXISTS waste_daily_allocations (
    waste_record_id INT NOT NULL,
    allocation_date DATE NOT NULL,
    tuesday_factory_returnable_kg DECIMAL(10,2) NOT NULL DEFAULT 0,
    wednesday_biowaste_kg DECIMAL(10,2) NOT NULL DEFAULT 0,
    thursday_factory_returnable_kg DECIMAL(10,2) NOT NULL DEFAULT 0,
    friday_biowaste_kg DECIMAL(10,2) NOT NULL DEFAULT 0,
    saturday_hazard_waste_kg DECIMAL(10,2) NOT NULL DEFAULT 0,
    residual_waste_kg DECIMAL(10,2) NOT NULL DEFAULT 0,
    unclassified_waste_kg DECIMAL(10,2) NOT NULL DEFAULT 0,
    kilogram_of_waste DECIMAL(10,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (waste_record_id, allocation_date),
    INDEX idx_waste_daily_allocations_date (allocation_date)
);

CREATE TABLE IF NOT EXISTS waste_import_batches (
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
);

CREATE TABLE IF NOT EXISTS waste_import_batch_files (
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
);

CREATE TABLE IF NOT EXISTS waste_import_staging_rows (
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
);

CREATE TABLE IF NOT EXISTS waste_import_manifests (
    file_sha256 CHAR(64) NOT NULL PRIMARY KEY,
    dataset_sha256 CHAR(64) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    row_count INT NOT NULL,
    imported_by INT NULL,
    imported_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_waste_import_dataset (dataset_sha256)
);

CREATE TABLE IF NOT EXISTS waste_record_fingerprints (
    record_fingerprint CHAR(64) NOT NULL PRIMARY KEY,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS data_retention_policy (
    policy_id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    retention_enabled TINYINT(1) NOT NULL DEFAULT 1,
    retention_months SMALLINT UNSIGNED NOT NULL DEFAULT 60,
    updated_by INT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT IGNORE INTO data_retention_policy (policy_id, retention_enabled, retention_months)
VALUES (1, 1, 60);

CREATE TABLE IF NOT EXISTS waste_collection_group_rules (
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
);

CREATE TABLE IF NOT EXISTS waste_data_state (
    state_key VARCHAR(50) NOT NULL PRIMARY KEY,
    version BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT IGNORE INTO waste_data_state (state_key, version) VALUES ('waste_records', 0);

CREATE TABLE IF NOT EXISTS waste_import_backfill_state (
    state_key VARCHAR(100) NOT NULL PRIMARY KEY,
    last_record_id INT NOT NULL DEFAULT 0,
    completed_at DATETIME NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT IGNORE INTO waste_import_backfill_state (state_key) VALUES ('record_fingerprints');

-- Verify the table without changing any data.
SELECT COUNT(*) AS total_waste_records FROM waste_records;

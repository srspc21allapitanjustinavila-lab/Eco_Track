-- EcoTrack Database Setup
-- Create database if it doesn't exist
CREATE DATABASE IF NOT EXISTS ecotrack_db;
USE ecotrack_db;

-- Create users table with password hashing
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    user_type ENUM('admin', 'staff', 'waste_collector') NOT NULL,
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL
);

-- Bio Collector identities are shared by staff and administrators. Existing
-- staff-account attendance remains separate and retains its original identity.
CREATE TABLE IF NOT EXISTS bio_collectors (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    name_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    user_id INT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_bio_collector_name_key (name_key),
    UNIQUE KEY unique_bio_collector_user (user_id),
    CONSTRAINT fk_bio_collector_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS collector_attendance (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    collector_user_id INT NULL,
    bio_collector_id BIGINT UNSIGNED NULL,
    attendance_date DATE NOT NULL,
    status ENUM('present', 'absent', 'late', 'on_leave') NOT NULL,
    remarks VARCHAR(500) NOT NULL DEFAULT '',
    recorded_by INT NULL,
    updated_by INT NULL,
    deleted_at DATETIME NULL,
    deleted_by INT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_collector_attendance_day (collector_user_id, attendance_date),
    UNIQUE KEY unique_bio_collector_day (bio_collector_id, attendance_date),
    INDEX idx_collector_attendance_date_status (attendance_date, status),
    INDEX idx_collector_attendance_collector_date (collector_user_id, attendance_date),
    CONSTRAINT fk_collector_attendance_collector FOREIGN KEY (collector_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_attendance_bio_collector FOREIGN KEY (bio_collector_id) REFERENCES bio_collectors(id) ON DELETE RESTRICT,
    CONSTRAINT fk_collector_attendance_recorded_by FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bio_attendance_audit (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    attendance_id BIGINT UNSIGNED NOT NULL,
    action VARCHAR(12) NOT NULL,
    actor_id INT NULL,
    before_json TEXT NULL,
    after_json TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_bio_attendance_audit_record (attendance_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Per-browser persistent login credentials. The browser receives only the
-- selector and raw validator; the validator is stored here as a SHA-256 hash.
CREATE TABLE IF NOT EXISTS persistent_login_tokens (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Create password_resets table for email-based password reset
CREATE TABLE IF NOT EXISTS password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(255) UNIQUE NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- This schema deliberately creates no default accounts. Migrate an existing
-- installation or create the first administrator with the deployment tooling.

-- Create login_attempts table for security
CREATE TABLE IF NOT EXISTS login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    attempt_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    success BOOLEAN NOT NULL
);

-- Append-only audit trail for the admin Activity Logs page.
CREATE TABLE IF NOT EXISTS activity_logs (
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
);

-- Deleted Waste Data is permanently stored as a recoverable archive snapshot.
CREATE TABLE IF NOT EXISTS waste_records_archive (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_record_id INT NOT NULL,
    archived_by INT NULL,
    archived_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    record_payload LONGTEXT NOT NULL,
    UNIQUE KEY unique_archived_source_record (source_record_id),
    INDEX idx_waste_records_archive_archived_at (archived_at)
);

-- Create verification_codes table for 6-digit password reset codes
CREATE TABLE IF NOT EXISTS verification_codes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    code VARCHAR(6) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    used BOOLEAN DEFAULT FALSE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Create indexes for verification codes
CREATE INDEX idx_verification_codes_code ON verification_codes(code);
CREATE INDEX idx_verification_codes_user ON verification_codes(user_id);

-- Add photo column to users table (for user profile photos)
ALTER TABLE users ADD COLUMN photo VARCHAR(255) DEFAULT NULL;

-- Add address and gender columns to users table
ALTER TABLE users ADD COLUMN address TEXT DEFAULT NULL;
ALTER TABLE users ADD COLUMN gender ENUM('Male', 'Female', 'Other') DEFAULT NULL;

-- Create announcements table
CREATE TABLE IF NOT EXISTS announcements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    content TEXT NOT NULL,
    priority ENUM('low', 'normal', 'high') DEFAULT 'normal',
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
);

-- Create daily_tasks table
CREATE TABLE IF NOT EXISTS daily_tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task_batch_id CHAR(32) NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    assigned_to INT DEFAULT NULL,
    task_date DATE NOT NULL,
    priority ENUM('low', 'normal', 'high') DEFAULT 'normal',
    status ENUM('pending', 'completed', 'cancelled') DEFAULT 'pending',
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    completed_by INT DEFAULT NULL,
    INDEX idx_daily_tasks_batch (task_batch_id),
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (completed_by) REFERENCES users(id) ON DELETE SET NULL
);

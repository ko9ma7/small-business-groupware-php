SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS site_settings (
    setting_key VARCHAR(50) PRIMARY KEY,
    setting_value VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES
    ('portal_name', 'GROUPWARE'),
    ('portal_company_label', ''),
    ('weekly_meeting_weekday', '1'),
    ('weekly_period_basis', 'previous_current'),
    ('registration_enabled', '1'),
    ('approval_timeout', '0'),
    ('bareun_api_key', ''),
    ('gemini_api_key', ''),
    ('gemini_model', 'gemini-2.5-flash');

CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(80) NOT NULL,
    ip_hash CHAR(64) NOT NULL,
    was_success TINYINT(1) NOT NULL DEFAULT 0,
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_login_username_time (username, attempted_at),
    KEY idx_login_ip_time (ip_hash, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_user_id INT NULL,
    action VARCHAR(50) NOT NULL,
    target_type VARCHAR(50) NOT NULL,
    target_id INT NULL,
    summary VARCHAR(500) NOT NULL,
    ip_hash CHAR(64) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_audit_created (created_at),
    KEY idx_audit_actor (actor_user_id, created_at),
    KEY idx_audit_target (target_type, target_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS companies (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(20) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    color CHAR(7) NOT NULL DEFAULT '#2563eb',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS departments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    parent_id INT UNSIGNED NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_department (company_id, name),
    KEY idx_department_company (company_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_org_assignments (
    user_id INT NOT NULL,
    company_id INT UNSIGNED NOT NULL,
    department_id INT UNSIGNED NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, company_id),
    KEY idx_org_company_department (company_id, department_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS report_user_preferences (
    user_id INT PRIMARY KEY,
    input_mode ENUM('daily','weekly','monthly') NOT NULL DEFAULT 'daily',
    last_entry_mode ENUM('self','team') NOT NULL DEFAULT 'self',
    last_target_id INT NULL,
    last_company VARCHAR(100) NOT NULL DEFAULT '',
    last_category VARCHAR(50) NOT NULL DEFAULT '일반업무',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS report_task_meta (
    task_id INT PRIMARY KEY,
    created_by INT NOT NULL,
    input_mode ENUM('classic','daily','weekly','monthly') NOT NULL DEFAULT 'classic',
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_meta_creator (created_by, created_at),
    KEY idx_meta_period (period_start, period_end)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS report_entry_presets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    preset_name VARCHAR(80) NOT NULL,
    payload LONGTEXT NOT NULL,
    deleted_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_report_preset_user (user_id, deleted_at, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

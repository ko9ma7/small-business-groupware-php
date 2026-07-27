<?php
require_once __DIR__ . '/report_periods.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    require __DIR__ . '/db_conn.php';
}

function smw_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function smw_csrf_token(): string
{
    if (empty($_SESSION['smw_csrf'])) {
        $_SESSION['smw_csrf'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['smw_csrf'];
}

function smw_verify_csrf(): void
{
    if (!hash_equals(smw_csrf_token(), (string)($_POST['smw_csrf'] ?? ''))) {
        http_response_code(419);
        exit('요청이 만료되었습니다. 새로고침 후 다시 시도해 주세요.');
    }
}

function smw_ensure_extension_schema(mysqli $conn): bool
{
    $queries = [
        "CREATE TABLE IF NOT EXISTS companies (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(20) NOT NULL UNIQUE,
            name VARCHAR(100) NOT NULL,
            color CHAR(7) NOT NULL DEFAULT '#2563eb',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS departments (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            company_id INT UNSIGNED NOT NULL,
            name VARCHAR(100) NOT NULL,
            parent_id INT UNSIGNED NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_department (company_id, name),
            KEY idx_department_company (company_id, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS user_org_assignments (
            user_id INT NOT NULL,
            company_id INT UNSIGNED NOT NULL,
            department_id INT UNSIGNED NULL,
            is_primary TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, company_id),
            KEY idx_org_company_department (company_id, department_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS report_user_preferences (
            user_id INT PRIMARY KEY,
            input_mode ENUM('daily','weekly','monthly') NOT NULL DEFAULT 'daily',
            last_target_id INT NULL,
            last_company VARCHAR(100) NOT NULL DEFAULT '',
            last_category VARCHAR(50) NOT NULL DEFAULT '일반업무',
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS report_task_meta (
            task_id INT PRIMARY KEY,
            created_by INT NOT NULL,
            input_mode ENUM('classic','daily','weekly','monthly') NOT NULL DEFAULT 'classic',
            period_start DATE NOT NULL,
            period_end DATE NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_meta_creator (created_by, created_at),
            KEY idx_meta_period (period_start, period_end)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS site_settings (
            setting_key VARCHAR(50) PRIMARY KEY,
            setting_value VARCHAR(255) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];

    foreach ($queries as $query) {
        if (!$conn->query($query)) {
            return false;
        }
    }

    $settingsSeed = "INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES
        ('portal_name', 'GROUPWARE'),
        ('portal_company_label', ''),
        ('weekly_meeting_weekday', '1'),
        ('weekly_period_basis', 'previous_current'),
        ('registration_enabled', '1'),
        ('approval_timeout', '0'),
        ('bareun_api_key', ''),
        ('gemini_api_key', ''),
        ('gemini_model', 'gemini-2.5-flash')";
    return (bool)$conn->query($settingsSeed);
}

$smw_extension_ready = smw_ensure_extension_schema($conn);

function smw_current_user(mysqli $conn): ?array
{
    if (empty($_SESSION['uid'])) {
        return null;
    }
    $uid = (int)$_SESSION['uid'];
    $result = $conn->query("SELECT * FROM users WHERE id=$uid");
    return $result && $result->num_rows ? $result->fetch_assoc() : null;
}

function smw_subordinate_ids(mysqli $conn, array $user): array
{
    $uid = (int)$user['id'];
    if ((int)$user['is_admin'] === 1) {
        $result = $conn->query("SELECT id FROM users WHERE id<>$uid ORDER BY nickname");
        return $result ? array_map('intval', array_column($result->fetch_all(MYSQLI_ASSOC), 'id')) : [];
    }

    $ids = [];
    $result = $conn->query("SELECT target_id FROM user_relations WHERE viewer_id=$uid");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $ids[] = (int)$row['target_id'];
        }
    }
    return array_values(array_unique($ids));
}

function smw_selectable_users(mysqli $conn, array $user): array
{
    $ids = smw_subordinate_ids($conn, $user);
    array_unshift($ids, (int)$user['id']);
    $ids = array_values(array_unique($ids));
    $csv = implode(',', array_map('intval', $ids));
    if ($csv === '') {
        return [];
    }

    $result = $conn->query(
        "SELECT u.id, u.username, u.nickname, u.position,
                GROUP_CONCAT(DISTINCT c.name ORDER BY oa.is_primary DESC, c.sort_order SEPARATOR ', ') AS company_names,
                GROUP_CONCAT(DISTINCT d.name ORDER BY d.sort_order SEPARATOR ', ') AS department_names
         FROM users u
         LEFT JOIN user_org_assignments oa ON oa.user_id=u.id
         LEFT JOIN companies c ON c.id=oa.company_id
         LEFT JOIN departments d ON d.id=oa.department_id
         WHERE u.id IN ($csv)
         GROUP BY u.id
         ORDER BY CASE u.position
             WHEN '회장' THEN 1 WHEN '대표' THEN 2 WHEN '사장' THEN 3
             WHEN '부회장' THEN 4 WHEN '부사장' THEN 5 WHEN '전무' THEN 6
             WHEN '상무' THEN 7 WHEN '이사' THEN 8 WHEN '본부장' THEN 9
             WHEN '실장' THEN 10 WHEN '부장' THEN 11 WHEN '차장' THEN 12
             WHEN '과장' THEN 13 WHEN '대리' THEN 14 WHEN '주임' THEN 15
             WHEN '사원' THEN 16 WHEN '인턴' THEN 17 ELSE 99 END,
             u.nickname"
    );
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function smw_user_preference(mysqli $conn, int $userId): array
{
    $result = $conn->query("SELECT * FROM report_user_preferences WHERE user_id=$userId");
    if ($result && $result->num_rows) {
        return $result->fetch_assoc();
    }
    return [
        'user_id' => $userId,
        'input_mode' => 'daily',
        'last_target_id' => $userId,
        'last_company' => '',
        'last_category' => '일반업무',
    ];
}

function smw_company_options(mysqli $conn): array
{
    $options = [];
    $company_result = $conn->query("SELECT name FROM companies WHERE is_active=1 ORDER BY sort_order, name");
    if ($company_result) {
        while ($row = $company_result->fetch_assoc()) {
            $options[] = trim($row['name']);
        }
    }
    $report_result = $conn->query("SELECT DISTINCT company_name FROM report_tasks WHERE TRIM(IFNULL(company_name,''))<>'' ORDER BY company_name");
    if ($report_result) {
        while ($row = $report_result->fetch_assoc()) {
            $options[] = trim($row['company_name']);
        }
    }
    return array_values(array_unique(array_filter($options)));
}

function smw_site_settings(mysqli $conn): array
{
    static $cache = [];
    $cacheKey = spl_object_id($conn);
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $settings = [
        'portal_name' => 'GROUPWARE',
        'portal_company_label' => '',
        'weekly_meeting_weekday' => '1',
        'weekly_period_basis' => 'previous_current',
        'registration_enabled' => '1',
    ];
    $result = $conn->query(
        "SELECT setting_key, setting_value
         FROM site_settings
         WHERE setting_key IN ('portal_name','portal_company_label','weekly_meeting_weekday','weekly_period_basis','registration_enabled')"
    );
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $settings[$row['setting_key']] = (string)$row['setting_value'];
        }
    }
    $cache[$cacheKey] = $settings;
    return $settings;
}

function smw_portal_identity(mysqli $conn): array
{
    $settings = smw_site_settings($conn);
    $companyLabel = trim($settings['portal_company_label']);

    return [
        'name' => trim($settings['portal_name']) !== '' ? trim($settings['portal_name']) : 'GROUPWARE',
        'companies' => $companyLabel !== '' ? $companyLabel : '업무 포털',
    ];
}

function smw_default_report_date(mysqli $conn, ?string $today = null): string
{
    $settings = smw_site_settings($conn);
    $meetingWeekday = max(1, min(7, (int)$settings['weekly_meeting_weekday']));
    return smw_next_weekday_date($today ?: date('Y-m-d'), $meetingWeekday);
}

function smw_weekly_periods(mysqli $conn, string $referenceDate): array
{
    $settings = smw_site_settings($conn);
    $periods = smw_calculate_weekly_periods($referenceDate, $settings['weekly_period_basis']);
    $periods['meeting_weekday'] = max(1, min(7, (int)$settings['weekly_meeting_weekday']));
    return $periods;
}

function smw_category_options(mysqli $conn): array
{
    $options = ['일반업무', '영업진행', '특이요청', '현장업무', '관리업무', '기타사항'];
    $column = $conn->query("SHOW COLUMNS FROM report_tasks LIKE 'task_category'");
    if (!$column || $column->num_rows === 0) {
        $conn->query("ALTER TABLE report_tasks ADD COLUMN task_category VARCHAR(50) DEFAULT '일반업무'");
        return $options;
    }
    $result = $conn->query("SELECT DISTINCT task_category FROM report_tasks WHERE TRIM(IFNULL(task_category,''))<>'' ORDER BY task_category");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $options[] = trim($row['task_category']);
        }
    }
    return array_values(array_unique(array_filter($options)));
}

function smw_period(string $mode, string $value): array
{
    if ($mode === 'weekly') {
        $timestamp = strtotime($value ?: date('Y-m-d'));
        $start = date('Y-m-d', strtotime('monday this week', $timestamp ?: time()));
        return [$start, date('Y-m-d', strtotime($start . ' +6 days'))];
    }
    if ($mode === 'monthly') {
        $month = preg_match('/^\d{4}-\d{2}$/', $value) ? $value : date('Y-m');
        $start = $month . '-01';
        return [$start, date('Y-m-t', strtotime($start))];
    }
    $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : date('Y-m-d');
    return [$date, $date];
}

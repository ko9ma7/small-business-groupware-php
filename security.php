<?php

const SMW_LOGIN_MAX_FAILURES = 5;
const SMW_LOGIN_WINDOW_SECONDS = 900;
const SMW_LOGIN_LOCK_SECONDS = 900;
const SMW_IMAGE_MAX_BYTES = 5242880;
const SMW_ATTACHMENT_MAX_BYTES = 10485760;

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

function smw_client_ip_hash(): string
{
    return hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
}

function smw_password_error(string $password): string
{
    if (strlen($password) < 8) {
        return '비밀번호는 8자 이상으로 입력해 주세요.';
    }
    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
        return '비밀번호에는 영문과 숫자를 각각 하나 이상 포함해 주세요.';
    }
    return '';
}

function smw_login_retry_seconds(array $failureTimestamps, int $now): int
{
    $recent = array_values(array_filter(
        array_map('intval', $failureTimestamps),
        static fn(int $timestamp): bool => $timestamp > $now - SMW_LOGIN_WINDOW_SECONDS
    ));
    if (count($recent) < SMW_LOGIN_MAX_FAILURES) {
        return 0;
    }
    $lastFailure = max($recent);
    return max(0, ($lastFailure + SMW_LOGIN_LOCK_SECONDS) - $now);
}

function smw_login_throttle(mysqli $conn, string $username, string $ipHash): array
{
    $stmt = $conn->prepare(
        "SELECT UNIX_TIMESTAMP(attempted_at) AS attempted_at
         FROM login_attempts
         WHERE was_success=0
           AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
           AND (username=? OR ip_hash=?)
         ORDER BY attempted_at DESC"
    );
    if (!$stmt) {
        return ['locked' => false, 'retry_after' => 0];
    }
    $stmt->bind_param('ss', $username, $ipHash);
    $stmt->execute();
    $result = $stmt->get_result();
    $timestamps = $result ? array_column($result->fetch_all(MYSQLI_ASSOC), 'attempted_at') : [];
    $stmt->close();
    $retryAfter = smw_login_retry_seconds($timestamps, time());
    return ['locked' => $retryAfter > 0, 'retry_after' => $retryAfter];
}

function smw_record_login_attempt(mysqli $conn, string $username, string $ipHash, bool $success): void
{
    $stmt = $conn->prepare(
        'INSERT INTO login_attempts (username, ip_hash, was_success) VALUES (?, ?, ?)'
    );
    if ($stmt) {
        $successValue = $success ? 1 : 0;
        $stmt->bind_param('ssi', $username, $ipHash, $successValue);
        $stmt->execute();
        $stmt->close();
    }
    if ($success) {
        $cleanup = $conn->prepare(
            'DELETE FROM login_attempts WHERE was_success=0 AND (username=? OR ip_hash=?)'
        );
        if ($cleanup) {
            $cleanup->bind_param('ss', $username, $ipHash);
            $cleanup->execute();
            $cleanup->close();
        }
    } elseif (random_int(1, 20) === 1) {
        $conn->query("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    }
}

function smw_audit_log(
    mysqli $conn,
    string $action,
    string $targetType,
    ?int $targetId,
    string $summary,
    ?int $actorUserId = null
): void {
    $actorUserId ??= isset($_SESSION['uid']) ? (int)$_SESSION['uid'] : null;
    $ipHash = smw_client_ip_hash();
    $safeSummary = mb_substr(trim($summary), 0, 500, 'UTF-8');
    $stmt = $conn->prepare(
        'INSERT INTO audit_logs (actor_user_id, action, target_type, target_id, summary, ip_hash)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    if ($stmt) {
        $stmt->bind_param('ississ', $actorUserId, $action, $targetType, $targetId, $safeSummary, $ipHash);
        $stmt->execute();
        $stmt->close();
    }
}

function smw_upload_rules(string $kind): array
{
    if ($kind === 'image') {
        return [
            'max_bytes' => SMW_IMAGE_MAX_BYTES,
            'extensions' => [
                'jpg' => ['image/jpeg'],
                'jpeg' => ['image/jpeg'],
                'png' => ['image/png'],
                'gif' => ['image/gif'],
                'webp' => ['image/webp'],
            ],
        ];
    }
    if ($kind === 'json') {
        return [
            'max_bytes' => SMW_IMAGE_MAX_BYTES,
            'extensions' => [
                'json' => ['application/json', 'text/plain'],
            ],
        ];
    }
    return [
        'max_bytes' => SMW_ATTACHMENT_MAX_BYTES,
        'extensions' => [
            'pdf' => ['application/pdf'],
            'doc' => ['application/msword', 'application/CDFV2'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
            'xls' => ['application/vnd.ms-excel', 'application/CDFV2'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
            'ppt' => ['application/vnd.ms-powerpoint', 'application/CDFV2'],
            'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
            'txt' => ['text/plain'],
            'csv' => ['text/plain', 'text/csv', 'application/vnd.ms-excel'],
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'webp' => ['image/webp'],
            'zip' => ['application/zip'],
        ],
    ];
}

function smw_validate_upload(array $file, string $kind = 'attachment'): array
{
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        return [false, '파일 업로드가 완료되지 않았습니다.', '', ''];
    }
    $size = (int)($file['size'] ?? 0);
    $rules = smw_upload_rules($kind);
    if ($size < 1 || $size > $rules['max_bytes']) {
        $limitMb = (int)($rules['max_bytes'] / 1048576);
        return [false, "{$limitMb}MB 이하 파일만 업로드할 수 있습니다.", '', ''];
    }
    $tmpName = (string)($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        return [false, '정상적인 업로드 파일이 아닙니다.', '', ''];
    }
    $extension = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
    if (!isset($rules['extensions'][$extension])) {
        return [false, '허용되지 않는 파일 형식입니다.', '', ''];
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($tmpName);
    if (!in_array($mime, $rules['extensions'][$extension], true)) {
        return [false, '파일 내용과 확장자가 일치하지 않습니다.', '', $mime];
    }
    return [true, '', $extension, $mime];
}

function smw_safe_upload_name(string $extension): string
{
    return bin2hex(random_bytes(16)) . '.' . strtolower($extension);
}

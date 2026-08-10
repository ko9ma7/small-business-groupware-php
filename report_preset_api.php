<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once 'db_conn.php';
require_once 'smw_extensions.php';
require_once 'report_helpers.php';

if (!isset($_SESSION['uid'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '로그인이 필요합니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => '지원하지 않는 요청입니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

smw_verify_csrf();
$userId = (int)$_SESSION['uid'];
$action = (string)($_POST['action'] ?? 'list');

if ($action === 'list') {
    $includeDeleted = !empty($_POST['include_deleted']);
    $deletedClause = $includeDeleted ? 'IS NOT NULL' : 'IS NULL';
    $stmt = $conn->prepare("SELECT id, preset_name, payload, deleted_at, created_at, updated_at FROM report_entry_presets WHERE user_id=? AND deleted_at $deletedClause ORDER BY updated_at DESC, id DESC LIMIT 100");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $rows = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $decoded = json_decode((string)$row['payload'], true);
        $row['payload'] = is_array($decoded) ? $decoded : [];
        $rows[] = $row;
    }
    echo json_encode(['success' => true, 'items' => $rows], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'save') {
    $presetId = (int)($_POST['preset_id'] ?? 0);
    $name = mb_substr(trim((string)($_POST['preset_name'] ?? '')), 0, 80, 'UTF-8');
    $decoded = json_decode((string)($_POST['payload'] ?? ''), true);
    if ($name === '' || !is_array($decoded)) {
        echo json_encode(['success' => false, 'message' => '묶음 이름과 저장할 내용을 확인해 주세요.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $payload = json_encode(smw_normalize_preset_payload($decoded), JSON_UNESCAPED_UNICODE);
    if ($presetId > 0) {
        $ownerStmt = $conn->prepare("SELECT id FROM report_entry_presets WHERE id=? AND user_id=? AND deleted_at IS NULL LIMIT 1");
        $ownerStmt->bind_param('ii', $presetId, $userId);
        $ownerStmt->execute();
        if ($ownerStmt->get_result()->num_rows === 0) {
            echo json_encode(['success' => false, 'message' => '수정할 업무 묶음을 찾지 못했습니다.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $stmt = $conn->prepare("UPDATE report_entry_presets SET preset_name=?, payload=? WHERE id=? AND user_id=? AND deleted_at IS NULL");
        $stmt->bind_param('ssii', $name, $payload, $presetId, $userId);
        $stmt->execute();
        smw_audit_log($conn, 'report_preset.update', 'report_preset', $presetId, '사용자가 업무 묶음을 수정했습니다.');
    } else {
        $stmt = $conn->prepare("INSERT INTO report_entry_presets (user_id, preset_name, payload) VALUES (?, ?, ?)");
        $stmt->bind_param('iss', $userId, $name, $payload);
        $stmt->execute();
        $presetId = (int)$stmt->insert_id;
        smw_audit_log($conn, 'report_preset.create', 'report_preset', $presetId, '사용자가 업무 묶음을 저장했습니다.');
    }
    echo json_encode(['success' => true, 'message' => '업무 묶음을 보관함에 저장했습니다.', 'id' => $presetId], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'delete' || $action === 'restore') {
    $presetId = (int)($_POST['preset_id'] ?? 0);
    $sql = $action === 'delete'
        ? "UPDATE report_entry_presets SET deleted_at=NOW() WHERE id=? AND user_id=? AND deleted_at IS NULL"
        : "UPDATE report_entry_presets SET deleted_at=NULL WHERE id=? AND user_id=? AND deleted_at IS NOT NULL";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $presetId, $userId);
    $stmt->execute();
    if ($stmt->affected_rows === 0) {
        echo json_encode(['success' => false, 'message' => '대상 업무 묶음을 찾지 못했습니다.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $isRestore = $action === 'restore';
    smw_audit_log($conn, $isRestore ? 'report_preset.restore' : 'report_preset.delete', 'report_preset', $presetId, $isRestore ? '사용자가 업무 묶음을 복원했습니다.' : '사용자가 업무 묶음을 휴지통으로 이동했습니다.');
    echo json_encode(['success' => true, 'message' => $isRestore ? '업무 묶음을 복원했습니다.' : '휴지통으로 이동했습니다. 언제든 복원할 수 있습니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'message' => '알 수 없는 요청입니다.'], JSON_UNESCAPED_UNICODE);

<?php
// 파일명: /smw/daily.php
session_start();
error_reporting(0); ini_set('display_errors', 0);
include 'db_conn.php';

if (!isset($_SESSION['uid'])) { header("Location: login.php"); exit; }
$user_id = (int)$_SESSION['uid'];
require_once 'smw_extensions.php';
require_once 'groupware_shell.php';
require_once 'report_helpers.php';

$current_user = smw_current_user($conn);
if (!$current_user) { header("Location: logout.php"); exit; }
$selectable_users = smw_selectable_users($conn, $current_user);
$selectable_ids = array_map('intval', array_column($selectable_users, 'id'));
$managed_users = array_values(array_filter($selectable_users, static function($person) use ($user_id) {
    return (int)$person['id'] !== $user_id;
}));
$field_workers = $selectable_users;
$user_report_preference = smw_user_preference($conn, $user_id);
$saved_entry_mode = ($user_report_preference['last_entry_mode'] ?? 'self') === 'team' ? 'team' : 'self';

try {
    @$conn->query("ALTER TABLE report_tasks ADD COLUMN task_category VARCHAR(50) DEFAULT '일반업무'");
    @$conn->query("CREATE TABLE IF NOT EXISTS task_comments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        task_id INT NOT NULL,
        user_id INT NOT NULL,
        content LONGTEXT NOT NULL,
        read_by VARCHAR(500) DEFAULT '',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
} catch (Exception $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    smw_verify_csrf();
    $action = $_POST['action'];

    if ($action === 'save_entry_preference') {
        $preferred_mode = ($_POST['entry_mode'] ?? 'self') === 'team' ? 'team' : 'self';
        $preference_stmt = $conn->prepare(
            "INSERT INTO report_user_preferences (user_id, last_entry_mode) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE last_entry_mode=VALUES(last_entry_mode)"
        );
        $preference_stmt->bind_param('is', $user_id, $preferred_mode);
        $saved = $preference_stmt->execute();
        echo json_encode(['success' => $saved]);
        exit;
    }

    if ($action === 'delete' || $action === 'bulk_delete') {
        $requested_ids = $action === 'bulk_delete' ? ($_POST['task_ids'] ?? []) : [$_POST['task_id'] ?? 0];
        $requested_ids = array_values(array_unique(array_filter(array_map('intval', (array)$requested_ids))));
        if (empty($requested_ids)) {
            echo json_encode(['success' => false, 'msg' => '삭제할 업무를 선택해 주세요.']);
            exit;
        }

        $id_csv = implode(',', $requested_ids);
        $allowed_ids = [];
        $allowed_res = $conn->query(
            "SELECT DISTINCT t.id
             FROM report_tasks t
             LEFT JOIN report_task_meta m ON m.task_id=t.id
             WHERE t.id IN ($id_csv) AND (t.user_id=$user_id OR m.created_by=$user_id)"
        );
        if ($allowed_res) {
            while ($row = $allowed_res->fetch_assoc()) $allowed_ids[] = (int)$row['id'];
        }
        if (empty($allowed_ids)) {
            echo json_encode(['success' => false, 'msg' => '삭제 권한이 있는 업무가 없습니다.']);
            exit;
        }

        $conn->begin_transaction();
        try {
            $allowed_csv = implode(',', $allowed_ids);
            $att_res = $conn->query("SELECT file_path FROM attachments WHERE reference_type='task' AND reference_id IN ($allowed_csv)");
            $paths = [];
            if ($att_res) while ($att = $att_res->fetch_assoc()) $paths[] = $att['file_path'];
            $conn->query("DELETE FROM attachments WHERE reference_type='task' AND reference_id IN ($allowed_csv)");
            $conn->query("DELETE FROM task_comments WHERE task_id IN ($allowed_csv)");
            $conn->query("DELETE FROM report_task_meta WHERE task_id IN ($allowed_csv)");
            $conn->query("DELETE FROM report_tasks WHERE id IN ($allowed_csv)");
            $conn->commit();
            foreach (array_unique($paths) as $path) {
                $remaining = $conn->query("SELECT id FROM attachments WHERE file_path='" . $conn->real_escape_string($path) . "' LIMIT 1");
                if (!$remaining || $remaining->num_rows === 0) @unlink($path);
            }
            echo json_encode(['success' => true, 'msg' => count($allowed_ids) . '개 업무를 삭제했습니다.']);
        } catch (Throwable $error) {
            $conn->rollback();
            echo json_encode(['success' => false, 'msg' => '삭제 중 오류가 발생했습니다.']);
        }
        exit;
    }

    if ($action === 'save') {
        $task_id = !empty($_POST['task_id']) ? (int)$_POST['task_id'] : 0;
        $source_task_id = !empty($_POST['source_task_id']) ? (int)$_POST['source_task_id'] : 0;
        $entry_mode = ($_POST['entry_mode'] ?? 'self') === 'team' ? 'team' : 'self';
        $worker_user_ids = $entry_mode === 'team' ? array_map('intval', (array)($_POST['target_user_ids'] ?? [])) : [];
        $worker_user_ids = array_values(array_unique(array_diff(array_intersect($worker_user_ids, $selectable_ids), [$user_id])));
        $target_date = trim((string)($_POST['target_date'] ?? ''));
        $company_name = trim((string)($_POST['company_name'] ?? ''));
        $task_category = trim((string)($_POST['task_category'] ?? '일반업무'));
        
        $plan_content = trim($_POST['plan_content']); 
        $result_content = trim($_POST['result_content']); 
        $weekday_mode = $entry_mode === 'team' && !empty($_POST['weekday_mode']);
        $weekday_results = [];
        $weekday_summaries = [];
        foreach ((array)($_POST['weekday_result'] ?? []) as $weekday => $weekday_text) {
            $weekday = (int)$weekday;
            if ($weekday >= 1 && $weekday <= 7) $weekday_results[$weekday] = trim((string)$weekday_text);
        }
        foreach ((array)($_POST['weekday_summary'] ?? []) as $weekday => $weekday_summary) {
            $weekday = (int)$weekday;
            if ($weekday >= 1 && $weekday <= 7) $weekday_summaries[$weekday] = trim((string)$weekday_summary);
        }
        if ($entry_mode === 'team' && !$weekday_mode && empty($worker_user_ids)) {
            echo json_encode(['success' => false, 'msg' => '작업자를 선택하거나 요일별 입력을 사용해 주세요.']);
            exit;
        }
        
        $result_content = preg_replace('/^(<p>(<br>|&nbsp;|\s)*<\/p>\s*)+/i', '', $result_content);
        $result_content = preg_replace('/(<p>(<br>|&nbsp;|\s)*<\/p>\s*)+$/i', '', $result_content);

        if ($task_id === 0 && $entry_mode === 'team' && !$weekday_mode && !empty($worker_user_ids)) {
            $worker_names = [];
            foreach ($managed_users as $person) {
                if (in_array((int)$person['id'], $worker_user_ids, true)) $worker_names[] = trim((string)$person['nickname']);
            }
            $plain_result = html_entity_decode(strip_tags($result_content), ENT_QUOTES, 'UTF-8');
            $worker_lines = '';
            foreach ($worker_names as $worker_name) {
                if ($worker_name !== '' && mb_strpos($plain_result, $worker_name . ':', 0, 'UTF-8') === false) {
                    $worker_lines .= '<p><strong>' . smw_h($worker_name) . ':</strong>&nbsp;</p>';
                }
            }
            if ($worker_lines !== '') $result_content = $worker_lines . $result_content;
        }
        
        $task_type = ($_POST['task_type'] ?? 'actual') === 'plan' ? 'plan' : 'actual';
        
        $end_date = !empty($_POST['end_date']) ? trim((string)$_POST['end_date']) : $target_date;
        $include_saturday = !empty($_POST['include_saturday']);
        $include_sunday = !empty($_POST['include_sunday']);
        $excluded_dates = array_values(array_unique(array_filter(array_map('strval', (array)($_POST['excluded_dates'] ?? [])))));
        $valid_date = static function(string $value): bool {
            $parsed = DateTime::createFromFormat('Y-m-d', $value);
            return $parsed && $parsed->format('Y-m-d') === $value;
        };
        $excluded_dates = array_values(array_filter($excluded_dates, $valid_date));
        if (!$valid_date($target_date) || !$valid_date($end_date) || (!$weekday_mode && ($company_name === '' || $plan_content === ''))) {
            echo json_encode(['success' => false, 'msg' => '일자, 프로젝트/업체명, 업무 요약을 정확히 입력해 주세요.']);
            exit;
        }
        $start = new DateTime($target_date);
        $end = new DateTime($end_date);
        if($end < $start) $end = clone $start;

        $inserted_ids = array();
        $skipped_count = 0;

        if ($source_task_id > 0) {
            $source_stmt = $conn->prepare(
                "SELECT t.id FROM report_tasks t LEFT JOIN report_task_meta m ON m.task_id=t.id
                 WHERE t.id=? AND (t.user_id=? OR m.created_by=?) LIMIT 1"
            );
            $source_stmt->bind_param('iii', $source_task_id, $user_id, $user_id);
            $source_stmt->execute();
            if ($source_stmt->get_result()->num_rows === 0) {
                echo json_encode(['success' => false, 'msg' => '연장할 업무를 확인할 수 없습니다.']);
                exit;
            }
        }

        if ($task_id > 0) {
            $auth_res = $conn->query(
                "SELECT t.id FROM report_tasks t LEFT JOIN report_task_meta m ON m.task_id=t.id
                 WHERE t.id=$task_id AND (t.user_id=$user_id OR m.created_by=$user_id) LIMIT 1"
            );
            if (!$auth_res || $auth_res->num_rows === 0) {
                echo json_encode(['success' => false, 'msg' => '수정 권한이 없는 업무입니다.']);
                exit;
            }
            $stmt = $conn->prepare("UPDATE report_tasks SET target_date=?, company_name=?, task_category=?, plan_content=?, result_content=?, task_type=? WHERE id=?");
            $stmt->bind_param("ssssssi", $target_date, $company_name, $task_category, $plan_content, $result_content, $task_type, $task_id);
            $stmt->execute();
            $inserted_ids[] = $task_id;
            $meta_stmt = $conn->prepare(
                "INSERT INTO report_task_meta (task_id, created_by, input_mode, period_start, period_end)
                 VALUES (?, ?, 'daily', ?, ?)
                 ON DUPLICATE KEY UPDATE period_start=VALUES(period_start), period_end=VALUES(period_end)"
            );
            $meta_stmt->bind_param('iiss', $task_id, $user_id, $target_date, $target_date);
            $meta_stmt->execute();
            $msg_text = "업무가 수정되었습니다.";
        } else {
            $registration_dates = smw_registration_dates($start->format('Y-m-d'), $end->format('Y-m-d'), $include_saturday, $include_sunday);
            $skipped_count += count(array_intersect($registration_dates, $excluded_dates));
            foreach (smw_without_dates($registration_dates, $excluded_dates) as $curr_date) {
                $date_result_content = $result_content;
                $date_company_name = $company_name;
                $date_plan_content = $plan_content;
                if ($weekday_mode) {
                    $weekday = (int)date('N', strtotime($curr_date));
                    $weekday_text = $weekday_results[$weekday] ?? '';
                    if ($weekday_text === '') { $skipped_count++; continue; }
                    $date_result_content = smw_weekday_result_html($weekday_text);
                    $field_metadata = smw_field_day_metadata($weekday, $weekday_summaries);
                    $date_company_name = $field_metadata['company_name'];
                    $date_plan_content = $field_metadata['plan_content'];
                }
                if ($source_task_id > 0) {
                    $duplicate_stmt = $conn->prepare(
                        "SELECT id FROM report_tasks WHERE user_id=? AND target_date=? AND company_name=? AND task_category=? AND plan_content=? LIMIT 1"
                    );
                    $duplicate_stmt->bind_param('issss', $user_id, $curr_date, $date_company_name, $task_category, $date_plan_content);
                    $duplicate_stmt->execute();
                    if ($duplicate_stmt->get_result()->num_rows > 0) { $skipped_count++; continue; }
                }
                $stmt = $conn->prepare("INSERT INTO report_tasks (user_id, target_date, company_name, task_category, plan_content, result_content, task_type) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("issssss", $user_id, $curr_date, $date_company_name, $task_category, $date_plan_content, $date_result_content, $task_type);
                if($stmt->execute()) {
                    $new_id = (int)$stmt->insert_id;
                    $inserted_ids[] = $new_id;
                    $meta_stmt = $conn->prepare("INSERT INTO report_task_meta (task_id, created_by, input_mode, period_start, period_end) VALUES (?, ?, 'daily', ?, ?)");
                    $meta_stmt->bind_param('iiss', $new_id, $user_id, $curr_date, $curr_date);
                    $meta_stmt->execute();
                }
            }
            $msg_text = $source_task_id > 0
                ? "기존 상세 내용을 포함해 업무 기간을 " . count($inserted_ids) . "일 연장했습니다."
                : ($entry_mode === 'team'
                ? "요일별 업무 " . count($inserted_ids) . "건을 등록했습니다."
                : "내 업무 " . count($inserted_ids) . "건을 등록했습니다.");
            if ($skipped_count > 0) $msg_text .= " 선택 제외·중복·내용 없는 날짜 {$skipped_count}일은 건너뛰었습니다.";
            if (empty($inserted_ids)) {
                if ($source_task_id > 0 && $skipped_count > 0) {
                    echo json_encode(['success' => true, 'msg' => '선택한 기간은 이미 등록되어 있어 중복 추가하지 않았습니다.']);
                } elseif ($weekday_mode) {
                    echo json_encode(['success' => false, 'msg' => '선택 기간의 요일에 입력된 작업 내용이 없습니다.']);
                } else {
                    echo json_encode(['success' => false, 'msg' => '선택한 기간에 등록할 평일이 없습니다. 토요일 또는 일요일 포함을 선택해 주세요.']);
                }
                exit;
            }
        }

        $preference_stmt = $conn->prepare(
            "INSERT INTO report_user_preferences (user_id, last_entry_mode) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE last_entry_mode=VALUES(last_entry_mode)"
        );
        $preference_stmt->bind_param('is', $user_id, $entry_mode);
        $preference_stmt->execute();

        if (!empty($inserted_ids) && !empty($_FILES['attachments']['name'][0])) {
            $upload_dir = 'uploads/tasks/';
            if (!is_dir($upload_dir)) @mkdir($upload_dir, 0777, true);
            $file_count = count($_FILES['attachments']['name']);
            for ($i = 0; $i < $file_count; $i++) {
                $org_name = $_FILES['attachments']['name'][$i];
                $file_size = $_FILES['attachments']['size'][$i];
                $file = [
                    'name' => $org_name,
                    'tmp_name' => $_FILES['attachments']['tmp_name'][$i],
                    'size' => $file_size,
                    'error' => $_FILES['attachments']['error'][$i],
                ];
                [$valid, $validationMessage, $ext] = smw_validate_upload($file);
                if (!$valid) {
                    $msg_text .= " 첨부파일 1개는 제외되었습니다: {$validationMessage}";
                    continue;
                }
                $new_name = smw_safe_upload_name($ext);
                $dest_path = $upload_dir . $new_name;

                if (move_uploaded_file($file['tmp_name'], $dest_path)) {
                    foreach($inserted_ids as $tid) {
                        $file_stmt = $conn->prepare("INSERT INTO attachments (reference_type, reference_id, original_name, file_path, file_size) VALUES ('task', ?, ?, ?, ?)");
                        $file_stmt->bind_param("issi", $tid, $org_name, $dest_path, $file_size);
                        $file_stmt->execute();
                    }
                }
            }
        }
        echo json_encode(array('success' => true, 'msg' => $msg_text));
        exit;
    }
}

$two_weeks_ago = date('Y-m-d', strtotime('-14 days'));
$my_tasks_query = "SELECT t.*, u.nickname AS target_name, u.position AS target_position,
                          m.created_by, (SELECT COUNT(*) FROM task_comments WHERE task_id = t.id) as comment_count
                   FROM report_tasks t
                   JOIN users u ON u.id=t.user_id
                   LEFT JOIN report_task_meta m ON m.task_id=t.id
                   WHERE t.user_id=$user_id
                     AND t.target_date >= '$two_weeks_ago'
                   ORDER BY t.target_date DESC, t.id DESC";
$my_tasks_res = @$conn->query($my_tasks_query);
$my_tasks = array();
if($my_tasks_res) { while($row = $my_tasks_res->fetch_assoc()) { $my_tasks[] = $row; } }
$company_options = smw_company_options($conn);
$company_example = $company_options[0] ?? '프로젝트명';

// ★ [핵심] 일일 업무에서도 내용이 같으면 그룹으로 묶어서 코멘트 개수를 합산 (어느 요일을 눌러도 연동되도록 처리)
$group_counts = [];
$group_ids_map = [];
foreach($my_tasks as $t) {
    $sig = md5($t['user_id'] . '_' . $t['company_name'] . '_' . $t['task_category'] . '_' . $t['plan_content']);
    if(!isset($group_counts[$sig])) { $group_counts[$sig] = 0; $group_ids_map[$sig] = []; }
    $group_counts[$sig] += (int)$t['comment_count'];
    $group_ids_map[$sig][] = $t['id'];
}
foreach($my_tasks as &$t) {
    $sig = md5($t['user_id'] . '_' . $t['company_name'] . '_' . $t['task_category'] . '_' . $t['plan_content']);
    $t['group_comment_count'] = $group_counts[$sig];
    $t['group_task_ids'] = implode(',', $group_ids_map[$sig]);
}
unset($t);

$attachments_map = array();
$att_res = @$conn->query("SELECT * FROM attachments WHERE reference_type = 'task'");
if($att_res) { while($att = $att_res->fetch_assoc()) { $attachments_map[$att['reference_id']][] = $att; } }
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>일일 업무 관리</title>
<script src="https://cdn.tailwindcss.com"></script><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="https://uicdn.toast.com/editor/latest/toastui-editor.min.css" />
<link rel="stylesheet" href="assets/daily-work.css?v=6">
<link rel="stylesheet" href="assets/groupware-shell.css?v=3">
<style>
    #toast { transition: opacity 0.3s ease-in-out, transform 0.3s ease-in-out; }
    .toast-show { opacity: 1; transform: translateY(0); }
    .toast-hide { opacity: 0; transform: translateY(-20px); pointer-events: none; }
</style>
</head>
<body class="gw-body min-h-screen pb-10">
    <a href="#main-content" class="gw-skip-link">본문으로 바로가기</a>
    <div id="toast" class="fixed top-5 right-5 bg-slate-800 text-white px-5 py-3 rounded shadow-2xl toast-hide z-[9999] flex items-center font-bold"><i class="fa-solid fa-circle-check text-emerald-400 mr-2"></i> <span id="toast-msg">메시지</span></div>
    <?php smw_render_shell_header('daily', '일일 업무 관리', (int)$current_user['is_admin'] === 1, (string)$current_user['nickname']); ?>

    <main id="main-content" class="max-w-7xl mx-auto mt-6 px-4 flex flex-col lg:flex-row gap-6" tabindex="-1">
        <div class="w-full lg:w-1/2">
            <div class="daily-entry-card bg-white p-6 rounded-xl shadow-lg border-t-4 border-blue-500 sticky top-20">
                <div class="flex justify-between items-center mb-4 border-b pb-2 border-gray-200">
                    <h2 id="form-title" class="text-xl font-bold text-gray-800">✨ 신규 업무 등록</h2>
                    <div class="daily-form-tools">
                        <button type="button" onclick="openPresetLibrary()" class="daily-preset-button"><img src="assets/icons/work-bundle.svg" alt="">업무 묶음</button>
                        <label class="flex items-center gap-2 cursor-pointer text-sm font-bold text-indigo-600 bg-indigo-50 px-2 py-1 rounded hover:bg-indigo-100 transition">
                            <input type="checkbox" id="multi_day_check" onchange="toggleEndDate()" class="w-4 h-4 text-indigo-600"> 연속 일자 등록
                        </label>
                    </div>
                </div>
                
                <form id="taskForm" onsubmit="submitForm(event)" enctype="multipart/form-data" class="space-y-4">
                    <input type="hidden" name="action" value="save"><input type="hidden" name="smw_csrf" value="<?= smw_h(smw_csrf_token()) ?>"><input type="hidden" id="task_id" name="task_id" value=""><input type="hidden" id="source_task_id" name="source_task_id" value=""><input type="hidden" id="result_content" name="result_content" value="">

                    <fieldset>
                        <legend class="block font-bold text-gray-800 mb-2 text-sm">작성 방식</legend>
                        <div class="daily-mode-grid" role="radiogroup" aria-label="업무 작성 방식">
                            <label class="daily-mode-card">
                                <input type="radio" name="entry_mode" value="self" <?= $saved_entry_mode === 'self' ? 'checked' : '' ?> onchange="setEntryMode('self')">
                                <span class="daily-mode-icon"><i class="fa-solid fa-user-pen"></i></span>
                                <span><strong>1타입 · 내 업무 작성</strong><small>기존 방식 그대로 본인 업무를 등록합니다.</small></span>
                            </label>
                            <label class="daily-mode-card">
                                <input type="radio" name="entry_mode" value="team" <?= $saved_entry_mode === 'team' ? 'checked' : '' ?> onchange="setEntryMode('team')">
                                <span class="daily-mode-icon"><i class="fa-solid fa-calendar-days"></i></span>
                                <span><strong>2타입 · 현장 일지 간편 작성</strong><small>날짜별 출장·미팅·작업과 업체·작업자 내용을 한 번에 적습니다.</small></span>
                            </label>
                        </div>
                    </fieldset>

                    <div id="employeePickerModal" class="hidden daily-overlay" role="dialog" aria-modal="true" aria-labelledby="employeePickerTitle">
                        <div class="daily-overlay-backdrop" onclick="closeEmployeePicker()"></div>
                        <section class="daily-picker-dialog">
                            <header><div><h3 id="employeePickerTitle"><i class="fa-solid fa-users"></i> 본문에 넣을 작업자 선택</h3><p>선택한 이름은 내 보고서에 ‘이름: 작업 내용’ 형식으로 들어갑니다.</p></div><button type="button" onclick="closeEmployeePicker()" aria-label="닫기"><i class="fa-solid fa-xmark"></i></button></header>
                            <div class="daily-picker-body">
                            <?php if(empty($field_workers)): ?>
                                <div class="daily-empty-team">관리자 설정에서 본문에 넣을 작업자를 먼저 연결해 주세요.</div>
                            <?php else: ?>
                                <div class="daily-employee-grid">
                                    <?php foreach($field_workers as $person): ?>
                                        <label class="daily-employee-chip">
                                            <input type="checkbox" name="target_user_ids[]" value="<?= (int)$person['id'] ?>" data-name="<?= smw_h($person['nickname']) ?>" onchange="updateEmployeeSelection()">
                                            <span><b><?= smw_h($person['nickname']) ?><?= (int)$person['id'] === $user_id ? ' (본인)' : '' ?></b><small><?= smw_h($person['position']) ?><?= !empty($person['department_names'])?' · '.smw_h($person['department_names']):'' ?></small></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            </div>
                            <footer><button type="button" onclick="closeEmployeePicker()" class="daily-secondary-button">취소</button><button type="button" onclick="applySelectedWorkers()" class="daily-apply-button"><i class="fa-solid fa-check mr-1"></i>선택 적용</button></footer>
                        </section>
                    </div>

                    <div class="flex gap-4">
                        <div id="startDateField" class="w-1/2"><label class="block font-bold text-blue-700 mb-1 text-sm">시작 일자</label><input type="date" id="target_date" name="target_date" value="<?= date('Y-m-d') ?>" onchange="refreshExcludedDates()" class="w-full p-2 border border-blue-300 bg-blue-50 rounded" required></div>
                        <div id="standardCompanyField" class="w-1/2"><label class="block font-bold text-gray-700 mb-1 text-sm">프로젝트/업체명</label><input type="text" id="company_name" name="company_name" placeholder="예: <?= smw_h($company_example) ?>" class="w-full p-2 border rounded" required></div>
                    </div>
                    
                    <div id="end_date_wrapper" class="hidden bg-indigo-50 p-3 rounded border border-indigo-200">
                        <label class="block font-bold text-indigo-800 mb-1 text-sm"><i class="fa-solid fa-calendar-week mr-1"></i>종료 일자 (자동 일괄 등록)</label>
                        <input type="date" id="end_date" name="end_date" onchange="refreshExcludedDates()" class="w-full p-2 border border-indigo-300 rounded font-bold text-indigo-700">
                        <fieldset class="mt-3">
                            <legend class="text-xs font-bold text-indigo-900">주말 포함</legend>
                            <div class="flex flex-wrap gap-4 mt-2">
                                <label class="inline-flex items-center gap-2 text-sm font-bold text-slate-700 cursor-pointer"><input type="checkbox" name="include_saturday" value="1" onchange="refreshExcludedDates()" class="w-4 h-4 text-indigo-600"> 토요일 포함</label>
                                <label class="inline-flex items-center gap-2 text-sm font-bold text-slate-700 cursor-pointer"><input type="checkbox" name="include_sunday" value="1" onchange="refreshExcludedDates()" class="w-4 h-4 text-indigo-600"> 일요일 포함</label>
                            </div>
                            <p class="mt-2 text-xs text-indigo-700">기본은 토·일 제외입니다. 필요한 요일만 선택하세요.</p>
                        </fieldset>
                        <fieldset class="daily-date-exclusions">
                            <legend>등록에서 뺄 날짜</legend>
                            <div id="excludedDateOptions" class="daily-date-option-list"><span>시작일과 종료일을 선택하면 날짜가 표시됩니다.</span></div>
                            <p>예: 수요일만 쉬는 경우 해당 날짜의 ‘제외’를 선택하세요.</p>
                        </fieldset>
                    </div>

                    <section id="weekdayEntrySection" class="hidden daily-weekday-section" aria-labelledby="weekdayEntryTitle">
                        <input type="checkbox" id="weekday_mode" name="weekday_mode" value="1" checked hidden>
                        <div class="daily-weekday-heading">
                            <div><h3 id="weekdayEntryTitle">현장 일지 간편 입력</h3><p>선택한 기간의 날짜별로 업무 구분과 업체별 작업 내용을 한 번에 적습니다.</p></div>
                            <span id="visibleFieldDaySummary" class="daily-weekday-summary" aria-live="polite"></span>
                        </div>
                        <div id="weekdayFields" class="daily-weekday-grid">
                            <?php foreach([1=>'월',2=>'화',3=>'수',4=>'목',5=>'금',6=>'토',7=>'일'] as $weekdayNumber=>$weekdayLabel): ?>
                                <article class="daily-field-day hidden" data-weekday-card="<?= $weekdayNumber ?>">
                                    <header><strong><?= $weekdayLabel ?>요일</strong><span><small data-weekday-date></small><button type="button" data-copy-previous onclick="copyPreviousFieldDay(<?= $weekdayNumber ?>)" class="daily-day-copy-button hidden"><i class="fa-solid fa-copy"></i> 앞 날짜 가져오기</button><button type="button" onclick="openEmployeePicker(<?= $weekdayNumber ?>)" class="daily-day-worker-button"><i class="fa-solid fa-user-plus"></i> 이름 넣기</button></span></header>
                                    <label><span>업무 구분</span><input type="text" name="weekday_summary[<?= $weekdayNumber ?>]" data-weekday-summary="<?= $weekdayNumber ?>" list="fieldWorkTypes" placeholder="예: 현장 작업, 출장, 현장 미팅"></label>
                                    <label><span>업체·작업자별 상세 내용</span><textarea name="weekday_result[<?= $weekdayNumber ?>]" data-weekday="<?= $weekdayNumber ?>" rows="4" placeholder="예:\nA업체: 배관 용접\n홍길동: 프레임 가공"></textarea></label>
                                </article>
                            <?php endforeach; ?>
                        </div>
                        <datalist id="fieldWorkTypes"><option value="현장 작업"><option value="출장"><option value="현장 미팅"><option value="외주 작업"><option value="설치·시운전"></datalist>
                        <p class="daily-weekday-help">표시된 날짜만 각각 저장됩니다. 각 요일의 ‘이름 넣기’를 누르면 본인과 하위 작업자 구분 줄을 본문 다음 줄에 추가합니다.</p>
                    </section>

                    <div class="flex gap-4">
                        <div id="categoryField" class="w-1/3">
                            <label class="block font-bold text-purple-700 mb-1 text-sm">분류</label>
                            <select id="task_category" name="task_category" class="w-full p-2 border border-purple-300 bg-purple-50 rounded font-bold text-purple-800">
                                <option value="일반업무">일반 업무</option>
                                <option value="영업진행">영업 진행</option>
                                <option value="특이요청">특이/요청사항</option>
                                <option value="기타사항">기타 사항</option>
                            </select>
                        </div>
                        <div id="standardSummaryField" class="w-2/3"><label class="block font-bold text-gray-700 mb-1 text-sm">업무 요약</label><input type="text" id="plan_content" name="plan_content" class="w-full p-2 border rounded text-blue-800 font-bold" required></div>
                    </div>

                    <div id="standardDetailSection">
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 mb-2">
                            <label class="block font-bold text-emerald-700 text-sm">상세 내용 및 결과 (에디터)</label>
                            <div class="daily-editor-actions"><button type="button" id="employeePickerToolbar" onclick="openEmployeePicker()" class="hidden daily-secondary-button"><i class="fa-solid fa-users mr-1"></i>작업자 선택 <span id="employeePickerCount" class="daily-count-badge">0</span></button><button type="button" id="spellcheckBtn" onclick="checkSpelling()" class="daily-spell-button"><i class="fa-solid fa-spell-check mr-1"></i> 맞춤법·띄어쓰기 검사</button></div>
                        </div>
                        <div id="editor"></div>
                        <p class="mt-2 text-xs text-slate-500"><i class="fa-solid fa-circle-info mr-1"></i>검사 결과는 저장 전에 확인하고 선택적으로 적용할 수 있습니다.</p>
                        <section id="spellcheckPanel" class="hidden daily-overlay" aria-live="polite" role="dialog" aria-modal="true" aria-labelledby="spellcheckTitle">
                            <div class="daily-overlay-backdrop" onclick="closeSpellcheck()"></div>
                            <div class="daily-spell-panel"><div class="flex items-center justify-between gap-3"><h3 id="spellcheckTitle" class="font-bold text-slate-800">맞춤법·띄어쓰기 검사 결과</h3><span id="spellcheckProvider" class="daily-provider-badge"></span></div>
                            <p id="spellcheckNotice" class="daily-spell-notice"></p><div id="spellcheckIssues" class="daily-spell-issues"></div>
                            <div class="flex justify-end gap-2"><button type="button" onclick="closeSpellcheck()" class="daily-secondary-button">닫기</button><button type="button" id="applySpellcheckBtn" onclick="applySpellcheck()" class="daily-apply-button">선택 항목 적용</button></div></div>
                        </section>
                    </div>
                    <div><label class="block font-bold text-gray-700 mb-1 text-sm">증빙 자료 첨부</label><input type="file" name="attachments[]" multiple class="w-full p-1 border rounded bg-gray-50 text-xs"></div>
                    <div class="flex gap-2 pt-2"><button type="button" onclick="resetForm()" class="w-1/3 bg-gray-200 font-bold py-2 rounded">초기화</button><button type="submit" id="submitBtn" class="w-2/3 bg-blue-600 text-white font-bold py-2 rounded shadow">등록 / 저장</button></div>
                </form>
            </div>
        </div>

        <div class="w-full lg:w-1/2">
            <div class="bg-white rounded-xl shadow-lg overflow-hidden border border-gray-200">
                <div class="px-5 py-4 bg-gray-50 border-b flex flex-wrap justify-between items-center gap-3"><div><h2 class="font-bold text-gray-800"><i class="fa-solid fa-list-check mr-2"></i>최근 업무 목록 (2주)</h2><p class="text-xs text-gray-500 mt-1">중간 날짜를 빼려면 해당 행의 체크박스를 선택한 뒤 삭제하세요.</p></div><div class="flex gap-2"><button type="button" id="bulkDeleteBtn" onclick="bulkDeleteTasks()" disabled class="text-xs bg-red-600 text-white px-3 py-2 rounded font-bold disabled:opacity-40 disabled:cursor-not-allowed"><i class="fa-solid fa-calendar-xmark mr-1"></i>선택 날짜 <span id="bulkDeleteCount">0</span>개 삭제</button><a href="task_history.php" class="text-xs bg-slate-800 text-white px-3 py-2 rounded">전체 기록 보기 &rarr;</a></div></div>
                <div class="overflow-x-auto max-h-[750px] overflow-y-auto">
                    <table class="w-full text-sm text-left text-gray-600">
                        <thead class="text-xs text-gray-700 uppercase bg-gray-100 sticky top-0 z-10 shadow-sm"><tr><th class="px-3 py-2 w-10 text-center"><input type="checkbox" id="select_all_tasks" onchange="toggleAllTasks(this.checked)" aria-label="전체 업무 선택"></th><th class="px-4 py-2 w-20">일자</th><th class="px-4 py-2">직원·프로젝트·요약</th><th class="px-4 py-2 text-center w-40">액션</th></tr></thead>
                        <tbody>
                            <?php if(empty($my_tasks)): ?><tr><td colspan="4" class="text-center py-10 text-gray-400">등록된 내역이 없습니다.</td></tr><?php endif; ?>
                            <?php 
                            $prev_date = '';
                            foreach($my_tasks as $t): 
                                $current_date = substr($t['target_date'], 5); 
                                $date_day = array('Sun'=>'일','Mon'=>'월','Tue'=>'화','Wed'=>'수','Thu'=>'목','Fri'=>'금','Sat'=>'토')[date('D', strtotime($t['target_date']))];
                                if($prev_date !== $current_date) { echo "<tr class='bg-slate-50 border-y border-slate-200'><td colspan='4' class='px-4 py-1.5 font-bold text-slate-700 text-xs'><i class='fa-regular fa-calendar mr-1'></i> {$current_date} ({$date_day})</td></tr>"; $prev_date = $current_date; }
                                
                                $cat_color = 'bg-gray-200 text-gray-700';
                                if($t['task_category']=='영업진행') $cat_color = 'bg-blue-100 text-blue-700';
                                elseif($t['task_category']=='특이요청') $cat_color = 'bg-red-100 text-red-700';
                                elseif($t['task_category']=='기타사항') $cat_color = 'bg-purple-100 text-purple-700';
                            ?>
                            <tr class="border-b hover:bg-blue-50 transition">
                                <td class="px-3 py-3 text-center"><input type="checkbox" class="task-select" value="<?= (int)$t['id'] ?>" onchange="updateBulkSelection()" aria-label="<?= smw_h($t['plan_content']) ?> 선택"></td>
                                <td class="px-4 py-3 font-bold text-gray-500"><?= $current_date ?></td>
                                <td class="px-4 py-3">
                                    <div class="mb-1"><span class="daily-target-badge"><i class="fa-solid fa-user mr-1"></i><?= smw_h($t['target_name']) ?> · <?= smw_h($t['target_position']) ?></span></div>
                                    <div class="flex items-center gap-1 mb-1">
                                        <span class="px-1.5 py-0.5 rounded text-[10px] font-bold <?= $cat_color ?>"><?= smw_h($t['task_category']) ?></span>
                                        <span class="font-bold text-blue-800">[<?= htmlspecialchars($t['company_name']) ?>]</span>
                                    </div>
                                    <div class="font-medium text-gray-800"><?= htmlspecialchars($t['plan_content']) ?></div>
                                </td>
                                <td class="px-3 py-3 text-center">
                                    <div class="daily-action-panel">
                                        <button type="button" onclick="openCommentModal('<?= $t['group_task_ids'] ?>', this.getAttribute('data-title'), this)" data-title="<?= htmlspecialchars($t['plan_content'], ENT_QUOTES) ?>" class="daily-action-comment"><i class="fa-regular fa-comments"></i><span>코멘트</span><?= $t['group_comment_count'] > 0 ? "<b>{$t['group_comment_count']}</b>" : "" ?></button>
                                        <div class="daily-action-grid">
                                            <button type="button" onclick="editTask(this)" data-id="<?= $t['id'] ?>" data-date="<?= $t['target_date'] ?>" data-company="<?= htmlspecialchars($t['company_name'], ENT_QUOTES) ?>" data-cat="<?= htmlspecialchars($t['task_category'], ENT_QUOTES) ?>" data-plan="<?= htmlspecialchars($t['plan_content'], ENT_QUOTES) ?>" class="is-edit"><i class="fa-solid fa-pen"></i><span>수정</span></button>
                                            <button type="button" onclick="copyTask(this)" data-id="<?= $t['id'] ?>" data-company="<?= htmlspecialchars($t['company_name'], ENT_QUOTES) ?>" data-cat="<?= htmlspecialchars($t['task_category'], ENT_QUOTES) ?>" data-plan="<?= htmlspecialchars($t['plan_content'], ENT_QUOTES) ?>" class="is-copy"><i class="fa-solid fa-copy"></i><span>복사</span></button>
                                            <button type="button" onclick="extendTask(this)" data-id="<?= $t['id'] ?>" data-date="<?= $t['target_date'] ?>" data-company="<?= htmlspecialchars($t['company_name'], ENT_QUOTES) ?>" data-cat="<?= htmlspecialchars($t['task_category'], ENT_QUOTES) ?>" data-plan="<?= htmlspecialchars($t['plan_content'], ENT_QUOTES) ?>" class="is-extend"><i class="fa-solid fa-calendar-plus"></i><span>연장</span></button>
                                            <button type="button" onclick="deleteTask(<?= $t['id'] ?>)" class="is-delete"><i class="fa-solid fa-trash"></i><span>삭제</span></button>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <textarea id="res_raw_<?= $t['id'] ?>" style="display:none;"><?= htmlspecialchars($t['result_content']) ?></textarea>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        </main>

    <section id="presetLibraryModal" class="hidden daily-overlay" role="dialog" aria-modal="true" aria-labelledby="presetLibraryTitle">
        <div class="daily-overlay-backdrop" onclick="closePresetLibrary()"></div>
        <div class="daily-preset-dialog">
            <header><div class="daily-preset-title"><img src="assets/icons/work-bundle.svg" alt=""><div><h3 id="presetLibraryTitle">업무 묶음 보관함</h3><p>작업자·업체·업무 내용 구성을 저장하고 다시 불러옵니다.</p></div></div><button type="button" onclick="closePresetLibrary()" aria-label="업무 묶음 보관함 닫기"><i class="fa-solid fa-xmark"></i></button></header>
            <div class="daily-preset-save">
                <label for="presetName">묶음 이름</label>
                <div><input type="text" id="presetName" maxlength="80" placeholder="예: 월간 탱크 도면 작업"><button type="button" id="savePresetBtn" onclick="saveCurrentPreset()" class="daily-apply-button"><i class="fa-solid fa-box-archive mr-1"></i>현재 입력 저장</button></div>
                <button type="button" id="newPresetBtn" onclick="startNewPreset()" class="hidden daily-link-button">새 묶음으로 저장하기</button>
            </div>
            <nav class="daily-preset-tabs" aria-label="업무 묶음 목록 구분"><button type="button" id="presetActiveTab" class="is-active" onclick="loadPresetList(false)">보관 목록</button><button type="button" id="presetTrashTab" onclick="loadPresetList(true)"><i class="fa-solid fa-trash-can mr-1"></i>휴지통</button></nav>
            <div id="presetList" class="daily-preset-list" aria-live="polite"></div>
            <footer><p><i class="fa-solid fa-shield-halved mr-1"></i>삭제해도 휴지통에 보관되며 복원할 수 있습니다.</p><button type="button" onclick="closePresetLibrary()" class="daily-secondary-button">닫기</button></footer>
        </div>
    </section>

    <div id="commentModal" class="fixed inset-0 bg-black bg-opacity-70 hidden flex justify-center items-center z-50 p-4">
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-3xl flex flex-col h-[85vh] overflow-hidden">
            <div class="p-4 border-b bg-teal-600 text-white flex justify-between items-center"><h3 class="font-bold text-lg"><i class="fa-solid fa-comments mr-2"></i>업무 코멘트: <span id="commentTaskTitle" class="text-teal-200 text-sm"></span></h3><button onclick="closeCommentModal()" class="text-white hover:text-gray-200 text-xl"><i class="fa-solid fa-xmark"></i></button></div>
            <div id="commentList" class="flex-grow overflow-y-auto p-6 bg-gray-50 space-y-4"></div>
            <div class="p-4 border-t bg-white">
                <input type="hidden" id="commentTaskId"><div id="commentEditor" class="mb-2 w-full bg-white" style="height:200px;"></div>
                <div class="flex justify-end"><button onclick="submitComment()" class="bg-teal-600 text-white px-6 py-2 rounded font-bold shadow-md">코멘트 남기기</button></div>
            </div>
        </div>
    </div>

    <script src="https://uicdn.toast.com/editor/latest/toastui-editor-all.min.js"></script>
    <script>
        const formTitle = document.getElementById('form-title');
        const submitBtn = document.getElementById('submitBtn');
        const csrfToken = '<?= smw_h(smw_csrf_token()) ?>';
        const entryStorageKey = 'smw_daily_entry_<?= $user_id ?>';
        let preferredEntryMode = <?= json_encode($saved_entry_mode, JSON_UNESCAPED_UNICODE) ?>;
        let presetItems = [];
        let currentPresetId = 0;
        let presetReturnFocus = null;

        const editor = new toastui.Editor({ el: document.querySelector('#editor'), height: '260px', initialEditType: 'wysiwyg', previewStyle: 'vertical', hooks: { addImageBlobHook: async (blob, callback) => { const fd = new FormData(); fd.append('file', blob); fd.append('smw_csrf', <?= json_encode(smw_csrf_token()) ?>); try { const res = await fetch('upload_image.php', {method:'POST', body:fd}).then(r=>r.json()); if(res.success) callback(res.url, 'Image'); } catch(e) {} } } });

        setTimeout(() => {
            document.querySelectorAll('#editor [contenteditable="true"], #editor textarea').forEach(el => {
                el.setAttribute('spellcheck', 'true');
                el.setAttribute('lang', 'ko');
            });
            restoreEntryPreference();
        }, 120);

        function setEntryMode(mode, persist = true) {
            const isTeam = mode === 'team';
            document.getElementById('weekdayEntrySection').classList.toggle('hidden', !isTeam);
            document.getElementById('standardCompanyField').classList.toggle('hidden', isTeam);
            document.getElementById('standardSummaryField').classList.toggle('hidden', isTeam);
            document.getElementById('standardDetailSection').classList.toggle('hidden', isTeam);
            document.getElementById('company_name').required = !isTeam;
            document.getElementById('plan_content').required = !isTeam;
            document.getElementById('startDateField').classList.toggle('w-1/2', !isTeam);
            document.getElementById('startDateField').classList.toggle('w-full', isTeam);
            document.getElementById('categoryField').classList.toggle('w-1/3', !isTeam);
            document.getElementById('categoryField').classList.toggle('w-full', isTeam);
            document.getElementById('weekday_mode').checked = isTeam;
            toggleWeekdayEntry();
            if (!isTeam) closeEmployeePicker();
            saveEntryPreference();
            preferredEntryMode = isTeam ? 'team' : 'self';
            if (persist) persistEntryMode(preferredEntryMode);
        }

        function persistEntryMode(mode) {
            const formData = new FormData();
            formData.append('action', 'save_entry_preference');
            formData.append('entry_mode', mode);
            formData.append('smw_csrf', csrfToken);
            fetch('daily.php', { method: 'POST', body: formData }).catch(() => {});
        }

        function toggleWeekdayEntry() {
            const enabled = document.getElementById('weekday_mode').checked;
            document.getElementById('weekdayFields').classList.toggle('hidden', !enabled);
            refreshFieldDayVisibility();
        }

        let activeWeekdayTarget = null;

        function insertWorkersIntoWeekday(weekday) {
            const names = selectedEmployees().map(item => item.dataset.name);
            const field = document.querySelector(`textarea[data-weekday="${weekday}"]`);
            if (!field || !names.length) { showToast('넣을 작업자를 선택해 주세요.'); return; }
            const current = field.value.trimEnd();
            const lines = names.filter(name => !new RegExp(`(^|\\n)\\s*${escapeRegExp(name)}\\s*:`, 'm').test(current)).map(name => name + ': ');
            if (!lines.length) { showToast('선택한 작업자 줄이 이 요일에 이미 있습니다.'); return; }
            field.value = (current ? current + '\n' : '') + lines.join('\n');
            field.focus();
            field.setSelectionRange(field.value.length, field.value.length);
            showToast(lines.length + '명의 작업자 구분 줄을 추가했습니다.');
        }

        function escapeRegExp(value) { return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }

        function currentPresetPayload() {
            const weekdayResults = {};
            const weekdaySummaries = {};
            document.querySelectorAll('textarea[name^="weekday_result"]').forEach(field => { weekdayResults[field.dataset.weekday] = field.value; });
            document.querySelectorAll('input[name^="weekday_summary"]').forEach(field => { weekdaySummaries[field.dataset.weekdaySummary] = field.value; });
            return {
                entry_mode: document.querySelector('input[name="entry_mode"]:checked')?.value || 'self',
                worker_ids: selectedEmployees().map(item => Number(item.value)),
                weekday_mode: document.getElementById('weekday_mode').checked,
                weekday_results: weekdayResults,
                weekday_summaries: weekdaySummaries,
                company_name: document.getElementById('company_name').value,
                task_category: document.getElementById('task_category').value,
                plan_content: document.getElementById('plan_content').value,
                result_content: editor.getHTML()
            };
        }

        async function presetRequest(action, values = {}) {
            const formData = new FormData(); formData.append('action', action); formData.append('smw_csrf', csrfToken);
            Object.entries(values).forEach(([key, value]) => formData.append(key, value));
            return fetch('report_preset_api.php', { method: 'POST', body: formData }).then(response => response.json());
        }

        function openPresetLibrary() {
            presetReturnFocus = document.activeElement;
            document.getElementById('presetLibraryModal').classList.remove('hidden');
            document.body.classList.add('daily-modal-open');
            loadPresetList(false);
            setTimeout(() => document.getElementById('presetName').focus(), 50);
        }

        function closePresetLibrary() {
            const modal = document.getElementById('presetLibraryModal');
            if (modal.classList.contains('hidden')) return;
            modal.classList.add('hidden');
            if (document.getElementById('employeePickerModal').classList.contains('hidden') && document.getElementById('spellcheckPanel').classList.contains('hidden')) document.body.classList.remove('daily-modal-open');
            if (presetReturnFocus instanceof HTMLElement) presetReturnFocus.focus();
        }

        function startNewPreset() {
            currentPresetId = 0;
            document.getElementById('presetName').value = '';
            document.getElementById('savePresetBtn').innerHTML = '<i class="fa-solid fa-box-archive mr-1"></i>현재 입력 저장';
            document.getElementById('newPresetBtn').classList.add('hidden');
            document.getElementById('presetName').focus();
        }

        async function loadPresetList(includeDeleted) {
            document.getElementById('presetActiveTab').classList.toggle('is-active', !includeDeleted);
            document.getElementById('presetTrashTab').classList.toggle('is-active', includeDeleted);
            const list = document.getElementById('presetList');
            list.innerHTML = '<div class="daily-preset-empty"><i class="fa-solid fa-spinner fa-spin"></i> 불러오는 중</div>';
            try {
                const result = await presetRequest('list', { include_deleted: includeDeleted ? '1' : '' });
                if (!result.success) throw new Error(result.message || '목록 오류');
                presetItems = result.items || [];
                if (!presetItems.length) {
                    list.innerHTML = `<div class="daily-preset-empty"><i class="fa-solid ${includeDeleted ? 'fa-trash-arrow-up' : 'fa-layer-group'}"></i><strong>${includeDeleted ? '휴지통이 비어 있습니다.' : '저장된 업무 묶음이 없습니다.'}</strong><span>${includeDeleted ? '삭제한 묶음은 여기에 보관됩니다.' : '현재 입력 내용을 이름을 붙여 저장해 보세요.'}</span></div>`;
                    return;
                }
                list.innerHTML = presetItems.map(item => {
                    const payload = item.payload || {};
                    const workerCount = Array.isArray(payload.worker_ids) ? payload.worker_ids.length : 0;
                    return `<article class="daily-preset-item"><div class="daily-preset-item-icon"><img src="assets/icons/work-bundle.svg" alt=""></div><div class="daily-preset-item-body"><strong>${escapeHtml(item.preset_name)}</strong><p>${escapeHtml(payload.plan_content || '업무 요약 없음')}</p><div><span>${escapeHtml(payload.company_name || '업체 미지정')}</span><span>${workerCount ? `작업자 ${workerCount}명` : '내 업무'}</span>${payload.weekday_mode ? '<span>요일별</span>' : ''}</div></div><div class="daily-preset-actions">${includeDeleted ? `<button type="button" onclick="restorePreset(${Number(item.id)})" class="is-restore"><i class="fa-solid fa-trash-arrow-up"></i>복원</button>` : `<button type="button" onclick="applyPreset(${Number(item.id)})" class="is-load"><i class="fa-solid fa-arrow-down"></i>불러오기</button><button type="button" onclick="movePresetToTrash(${Number(item.id)})" class="is-delete" aria-label="${escapeHtml(item.preset_name)} 휴지통으로 이동"><i class="fa-solid fa-trash-can"></i></button>`}</div></article>`;
                }).join('');
            } catch (error) {
                list.innerHTML = '<div class="daily-preset-empty is-error"><i class="fa-solid fa-circle-exclamation"></i>업무 묶음 목록을 불러오지 못했습니다.</div>';
            }
        }

        async function saveCurrentPreset() {
            const name = document.getElementById('presetName').value.trim();
            if (!name) { showToast('업무 묶음 이름을 입력해 주세요.'); document.getElementById('presetName').focus(); return; }
            try {
                const result = await presetRequest('save', { preset_id: String(currentPresetId || ''), preset_name: name, payload: JSON.stringify(currentPresetPayload()) });
                showToast(result.message || '저장 결과를 확인하지 못했습니다.');
                if (result.success) { currentPresetId = Number(result.id); document.getElementById('savePresetBtn').innerHTML = '<i class="fa-solid fa-rotate mr-1"></i>현재 내용으로 갱신'; document.getElementById('newPresetBtn').classList.remove('hidden'); loadPresetList(false); }
            } catch (error) { showToast('업무 묶음 저장 서버에 연결하지 못했습니다.'); }
        }

        function applyPreset(id) {
            const item = presetItems.find(entry => Number(entry.id) === Number(id));
            if (!item) return;
            const payload = item.payload || {};
            resetForm();
            const modeInput = document.querySelector(`input[name="entry_mode"][value="${payload.entry_mode === 'team' ? 'team' : 'self'}"]`);
            if (modeInput && !modeInput.disabled) modeInput.checked = true;
            document.querySelectorAll('input[name="target_user_ids[]"]').forEach(input => { input.checked = (payload.worker_ids || []).map(Number).includes(Number(input.value)); });
            document.getElementById('weekday_mode').checked = Boolean(payload.weekday_mode);
            document.querySelectorAll('textarea[name^="weekday_result"]').forEach(field => { field.value = payload.weekday_results?.[field.dataset.weekday] || ''; });
            document.querySelectorAll('input[name^="weekday_summary"]').forEach(field => { field.value = payload.weekday_summaries?.[field.dataset.weekdaySummary] || ''; });
            document.getElementById('company_name').value = payload.company_name || '';
            document.getElementById('task_category').value = payload.task_category || '일반업무';
            document.getElementById('plan_content').value = payload.plan_content || '';
            editor.setHTML(payload.result_content || '');
            setEntryMode(modeInput?.value || 'self'); toggleWeekdayEntry(); updateEmployeeSelection();
            currentPresetId = Number(item.id); document.getElementById('presetName').value = item.preset_name; document.getElementById('savePresetBtn').innerHTML = '<i class="fa-solid fa-rotate mr-1"></i>현재 내용으로 갱신'; document.getElementById('newPresetBtn').classList.remove('hidden');
            closePresetLibrary(); showToast('업무 묶음을 불러왔습니다. 날짜를 확인한 뒤 등록하세요.');
        }

        async function movePresetToTrash(id) {
            if (!confirm('이 업무 묶음을 휴지통으로 이동하시겠습니까? 나중에 복원할 수 있습니다.')) return;
            try { const result = await presetRequest('delete', { preset_id: String(id) }); showToast(result.message || '처리 결과를 확인하지 못했습니다.'); if (result.success) { if (currentPresetId === Number(id)) startNewPreset(); loadPresetList(false); } }
            catch (error) { showToast('휴지통 처리 서버에 연결하지 못했습니다.'); }
        }

        async function restorePreset(id) {
            try { const result = await presetRequest('restore', { preset_id: String(id) }); showToast(result.message || '처리 결과를 확인하지 못했습니다.'); if (result.success) loadPresetList(true); }
            catch (error) { showToast('복원 서버에 연결하지 못했습니다.'); }
        }

        function openEmployeePicker(weekday = null) {
            activeWeekdayTarget = weekday === null ? null : Number(weekday);
            const title = document.getElementById('employeePickerTitle');
            const weekdayLabels = {1:'월',2:'화',3:'수',4:'목',5:'금',6:'토',7:'일'};
            title.innerHTML = `<i class="fa-solid fa-users"></i> ${activeWeekdayTarget ? weekdayLabels[activeWeekdayTarget] + '요일에 넣을' : '본문에 넣을'} 작업자 선택`;
            document.getElementById('employeePickerModal').classList.remove('hidden');
            document.body.classList.add('daily-modal-open');
        }
        function closeEmployeePicker() { document.getElementById('employeePickerModal').classList.add('hidden'); activeWeekdayTarget = null; if (document.getElementById('spellcheckPanel').classList.contains('hidden')) document.body.classList.remove('daily-modal-open'); }

        function selectedEmployees() {
            return Array.from(document.querySelectorAll('input[name="target_user_ids[]"]:checked'));
        }

        function updateEmployeeSelection() {
            const selected = selectedEmployees();
            document.getElementById('employeePickerCount').textContent = selected.length;
            saveEntryPreference();
        }

        function insertSelectedNames() {
            const names = selectedEmployees().map(item => item.dataset.name);
            if (!names.length) { showToast('먼저 작업자를 선택해 주세요.'); return; }
            const current = editor.getMarkdown().trim();
            const lines = names.filter(name => !current.includes(name + ':')).map(name => name + ': ');
            if (!lines.length) { showToast('선택한 작업자 줄이 이미 본문에 있습니다.'); return; }
            editor.setMarkdown((current ? current + '\n\n' : '') + lines.join('\n\n'));
            editor.focus();
            showToast(lines.length + '명의 작업자 구분 줄을 추가했습니다.');
        }

        function applySelectedWorkers() {
            const mode = document.querySelector('input[name="entry_mode"]:checked')?.value || 'self';
            if (mode === 'team' && activeWeekdayTarget) insertWorkersIntoWeekday(activeWeekdayTarget);
            else if (mode === 'team') showToast('이름을 넣을 요일을 먼저 선택해 주세요.');
            else insertSelectedNames();
            closeEmployeePicker();
        }

        function saveEntryPreference() {
            const mode = document.querySelector('input[name="entry_mode"]:checked')?.value || 'self';
            const targets = selectedEmployees().map(item => item.value);
            try { localStorage.setItem(entryStorageKey, JSON.stringify({ mode, targets })); } catch (error) {}
        }

        function restoreEntryPreference() {
            try {
                const saved = JSON.parse(localStorage.getItem(entryStorageKey) || '{}');
                const requestedMode = preferredEntryMode;
                const modeInput = document.querySelector(`input[name="entry_mode"][value="${requestedMode}"]`);
                if (modeInput && !modeInput.disabled) modeInput.checked = true;
                (saved.targets || []).forEach(id => {
                    const input = document.querySelector(`input[name="target_user_ids[]"][value="${id}"]`);
                    if (input) input.checked = true;
                });
            } catch (error) {}
            const mode = document.querySelector('input[name="entry_mode"]:checked')?.value || 'self';
            setEntryMode(mode, false);
            updateEmployeeSelection();
        }
        
        function toggleEndDate() {
            const isChecked = document.getElementById('multi_day_check').checked;
            const endWrap = document.getElementById('end_date_wrapper');
            const endDateInput = document.getElementById('end_date');
            if(isChecked) { endWrap.classList.remove('hidden'); endDateInput.required = true; if (!endDateInput.value) endDateInput.value = document.getElementById('target_date').value; }
            else { endWrap.classList.add('hidden'); endDateInput.required = false; endDateInput.value = ''; }
            refreshExcludedDates();
        }

        function localDateValue(date) {
            const pad = value => String(value).padStart(2, '0');
            return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
        }

        function refreshExcludedDates() {
            const optionBox = document.getElementById('excludedDateOptions');
            if (!optionBox) return;
            const selected = new Set(Array.from(optionBox.querySelectorAll('input:checked')).map(input => input.value));
            if (!document.getElementById('multi_day_check').checked) { optionBox.innerHTML = '<span>연속 일자 등록을 선택하면 날짜가 표시됩니다.</span>'; refreshFieldDayVisibility(); return; }
            const startValue = document.getElementById('target_date').value;
            const endValue = document.getElementById('end_date').value;
            if (!startValue || !endValue) { optionBox.innerHTML = '<span>시작일과 종료일을 선택해 주세요.</span>'; refreshFieldDayVisibility(); return; }
            const start = new Date(startValue + 'T00:00:00');
            const end = new Date(endValue + 'T00:00:00');
            if (end < start) { optionBox.innerHTML = '<span>종료일은 시작일 이후로 선택해 주세요.</span>'; refreshFieldDayVisibility(); return; }
            const includeSaturday = document.querySelector('input[name="include_saturday"]').checked;
            const includeSunday = document.querySelector('input[name="include_sunday"]').checked;
            const dayNames = ['일', '월', '화', '수', '목', '금', '토'];
            const options = [];
            const cursor = new Date(start);
            while (cursor <= end && options.length < 370) {
                const day = cursor.getDay();
                if ((day !== 6 && day !== 0) || (day === 6 && includeSaturday) || (day === 0 && includeSunday)) {
                    const value = localDateValue(cursor);
                    options.push(`<label><input type="checkbox" name="excluded_dates[]" value="${value}" ${selected.has(value) ? 'checked' : ''}><span>${value.slice(5)}(${dayNames[day]}) 제외</span></label>`);
                }
                cursor.setDate(cursor.getDate() + 1);
            }
            optionBox.innerHTML = options.length ? options.join('') : '<span>현재 조건에서 등록되는 날짜가 없습니다.</span>';
            optionBox.querySelectorAll('input').forEach(input => input.addEventListener('change', refreshFieldDayVisibility));
            refreshFieldDayVisibility();
        }

        function refreshFieldDayVisibility() {
            const cards = Array.from(document.querySelectorAll('[data-weekday-card]'));
            if (!cards.length) return;
            const startValue = document.getElementById('target_date').value;
            const multi = document.getElementById('multi_day_check').checked;
            const endValue = multi ? document.getElementById('end_date').value : startValue;
            const activeDates = new Map();
            if (startValue && endValue) {
                const start = new Date(startValue + 'T00:00:00');
                const end = new Date(endValue + 'T00:00:00');
                const excluded = new Set(Array.from(document.querySelectorAll('input[name="excluded_dates[]"]:checked')).map(input => input.value));
                const includeSaturday = document.querySelector('input[name="include_saturday"]').checked;
                const includeSunday = document.querySelector('input[name="include_sunday"]').checked;
                const cursor = new Date(start);
                while (cursor <= end && cursor.getTime() - start.getTime() <= 370 * 86400000) {
                    const day = cursor.getDay();
                    const value = localDateValue(cursor);
                    const included = (day !== 6 && day !== 0) || (day === 6 && includeSaturday) || (day === 0 && includeSunday);
                    if (included && !excluded.has(value)) {
                        const weekday = day === 0 ? 7 : day;
                        if (!activeDates.has(weekday)) activeDates.set(weekday, []);
                        activeDates.get(weekday).push(value);
                    }
                    cursor.setDate(cursor.getDate() + 1);
                }
            }
            cards.forEach(card => {
                const dates = activeDates.get(Number(card.dataset.weekdayCard)) || [];
                card.classList.toggle('hidden', dates.length === 0);
                const dateLabel = card.querySelector('[data-weekday-date]');
                if (dateLabel) dateLabel.textContent = dates.map(date => date.slice(5).replace('-', '.')).join(', ');
                card.dataset.firstDate = dates[0] || '';
                card.querySelectorAll('input, textarea').forEach(field => { field.disabled = dates.length === 0; });
            });
            const visibleCards = cards.filter(card => !card.classList.contains('hidden')).sort((a, b) => a.dataset.firstDate.localeCompare(b.dataset.firstDate));
            const grid = document.getElementById('weekdayFields');
            grid.classList.toggle('is-single-day', visibleCards.length === 1);
            visibleCards.forEach((card, index) => card.querySelector('[data-copy-previous]')?.classList.toggle('hidden', index === 0));
            const summary = document.getElementById('visibleFieldDaySummary');
            if (summary) summary.textContent = visibleCards.length ? `${visibleCards.length}개 요일 입력` : '저장할 날짜 없음';
        }

        function copyPreviousFieldDay(weekday) {
            const visibleCards = Array.from(document.querySelectorAll('[data-weekday-card]:not(.hidden)')).sort((a, b) => a.dataset.firstDate.localeCompare(b.dataset.firstDate));
            const targetIndex = visibleCards.findIndex(card => Number(card.dataset.weekdayCard) === Number(weekday));
            if (targetIndex < 1) { showToast('가져올 앞 날짜가 없습니다.'); return; }
            const source = visibleCards[targetIndex - 1];
            const target = visibleCards[targetIndex];
            const sourceSummary = source.querySelector('input[name^="weekday_summary"]');
            const sourceResult = source.querySelector('textarea[name^="weekday_result"]');
            const targetSummary = target.querySelector('input[name^="weekday_summary"]');
            const targetResult = target.querySelector('textarea[name^="weekday_result"]');
            if (!sourceSummary.value.trim() && !sourceResult.value.trim()) { showToast('앞 날짜에 가져올 내용이 없습니다.'); return; }
            if ((targetSummary.value.trim() || targetResult.value.trim()) && !confirm('현재 요일의 내용을 앞 날짜 내용으로 바꾸시겠습니까?')) return;
            targetSummary.value = sourceSummary.value;
            targetResult.value = sourceResult.value;
            targetResult.focus();
            targetResult.setSelectionRange(targetResult.value.length, targetResult.value.length);
            showToast('앞 날짜의 업무 구분과 상세 내용을 가져왔습니다.');
        }

        function showToast(msg) { const toast = document.getElementById('toast'); document.getElementById('toast-msg').innerText = msg; toast.classList.replace('toast-hide', 'toast-show'); setTimeout(() => { toast.classList.replace('toast-show', 'toast-hide'); }, 2000); }
        async function submitForm(e) {
            e.preventDefault();
            const mode = document.querySelector('input[name="entry_mode"]:checked')?.value || 'self';
            if (mode === 'team' && document.getElementById('weekday_mode').checked) {
                const hasWeekdayContent = Array.from(document.querySelectorAll('textarea[name^="weekday_result"]:not(:disabled)')).some(field => field.value.trim());
                if (!hasWeekdayContent) { showToast('표시된 날짜 중 하나 이상의 상세 작업 내용을 입력해 주세요.'); return; }
            }
            document.getElementById('result_content').value = editor.getHTML();
            submitBtn.disabled = true;
            try {
                const res = await fetch('daily.php', { method: 'POST', body: new FormData(e.target) }).then(r => r.json());
                showToast(res.msg);
                if(res.success) { saveEntryPreference(); setTimeout(() => location.reload(), 800); }
            } catch (error) { showToast('저장 응답을 확인하지 못했습니다.'); }
            finally { submitBtn.disabled = false; }
        }
        
        function getRawHtml(id) { const ta = document.getElementById('res_raw_' + id); return ta ? ta.value : ''; }

        function editTask(btn) { 
            const selfMode = document.querySelector('input[name="entry_mode"][value="self"]'); if (selfMode) { selfMode.checked = true; setEntryMode('self', false); }
            document.getElementById('task_id').value = btn.getAttribute('data-id'); 
            document.getElementById('source_task_id').value = '';
            document.getElementById('target_date').value = btn.getAttribute('data-date'); 
            document.getElementById('company_name').value = btn.getAttribute('data-company'); 
            document.getElementById('task_category').value = btn.getAttribute('data-cat') || '일반업무'; 
            document.getElementById('plan_content').value = btn.getAttribute('data-plan'); 
            document.getElementById('multi_day_check').checked = false; document.getElementById('multi_day_check').disabled = true; toggleEndDate();
            editor.setHTML(getRawHtml(btn.getAttribute('data-id'))); 
            formTitle.innerHTML = '✏️ 단일 업무 수정 모드'; submitBtn.innerHTML = '수정 내용 저장'; submitBtn.classList.replace('bg-blue-600', 'bg-emerald-600'); window.scrollTo({ top: 0, behavior: 'smooth' }); 
        }

        function copyTask(btn) {
            const selfMode = document.querySelector('input[name="entry_mode"][value="self"]'); if (selfMode) { selfMode.checked = true; setEntryMode('self', false); }
            document.getElementById('task_id').value = '';
            document.getElementById('source_task_id').value = '';
            document.getElementById('company_name').value = btn.getAttribute('data-company');
            document.getElementById('task_category').value = btn.getAttribute('data-cat') || '일반업무';
            document.getElementById('plan_content').value = btn.getAttribute('data-plan');
            document.getElementById('target_date').value = localDateValue(new Date());
            document.getElementById('multi_day_check').disabled = false;
            document.getElementById('multi_day_check').checked = false;
            toggleEndDate();
            editor.setHTML(getRawHtml(btn.getAttribute('data-id')));
            formTitle.innerHTML = '📋 기존 글 복사'; submitBtn.innerHTML = '복사본 등록'; submitBtn.classList.replace('bg-emerald-600', 'bg-blue-600'); window.scrollTo({ top: 0, behavior: 'smooth' }); showToast('상세 내용까지 복사했습니다. 날짜와 일부 내용을 수정해 등록하세요.');
        }
        
        function extendTask(btn) {
            const selfMode = document.querySelector('input[name="entry_mode"][value="self"]'); if (selfMode) { selfMode.checked = true; setEntryMode('self', false); }
            document.getElementById('task_id').value = ''; 
            document.getElementById('source_task_id').value = btn.getAttribute('data-id');
            document.getElementById('company_name').value = btn.getAttribute('data-company'); 
            document.getElementById('task_category').value = btn.getAttribute('data-cat') || '일반업무'; 
            document.getElementById('plan_content').value = btn.getAttribute('data-plan'); 
            document.getElementById('multi_day_check').disabled = false; 
            document.getElementById('multi_day_check').checked = true;
            const nextDate = new Date(btn.getAttribute('data-date') + 'T00:00:00'); nextDate.setDate(nextDate.getDate() + 1);
            const nextDateValue = localDateValue(nextDate);
            document.getElementById('target_date').value = nextDateValue;
            toggleEndDate(); document.getElementById('end_date').value = nextDateValue;
            refreshExcludedDates();
            editor.setHTML(getRawHtml(btn.getAttribute('data-id')));
            formTitle.innerHTML = '📅 미완료 업무 기간 연장'; submitBtn.innerHTML = '비어 있는 날짜만 연장'; submitBtn.classList.replace('bg-emerald-600', 'bg-blue-600'); window.scrollTo({ top: 0, behavior: 'smooth' }); showToast('기존 상세 내용이 포함됩니다. 새 종료일과 제외 날짜를 선택하세요.');
        }
        
        function resetForm() { 
            const currentDate = document.getElementById('target_date').value; document.getElementById('taskForm').reset(); document.getElementById('task_id').value = ''; document.getElementById('source_task_id').value = ''; document.getElementById('target_date').value = currentDate; document.getElementById('task_category').value = '일반업무'; document.getElementById('multi_day_check').disabled = false; toggleEndDate(); toggleWeekdayEntry();
            editor.setHTML(''); formTitle.innerHTML = '✨ 신규 업무 등록'; submitBtn.innerHTML = '등록 / 저장'; submitBtn.classList.replace('bg-emerald-600', 'bg-blue-600'); closeSpellcheck(); startNewPreset(); restoreEntryPreference();
        }
        
        async function deleteTask(id) { if(!confirm('삭제하시겠습니까?')) return; const fd = new FormData(); fd.append('action', 'delete'); fd.append('task_id', id); fd.append('smw_csrf', csrfToken); const res = await fetch('daily.php', { method: 'POST', body: fd }).then(r => r.json()); showToast(res.msg); if(res.success) setTimeout(() => { location.reload(); }, 700); }

        function selectedTaskIds() { return Array.from(document.querySelectorAll('.task-select:checked')).map(item => item.value); }

        function updateBulkSelection() {
            const count = selectedTaskIds().length;
            const total = document.querySelectorAll('.task-select').length;
            const selectAll = document.getElementById('select_all_tasks');
            selectAll.checked = total > 0 && count === total;
            selectAll.indeterminate = count > 0 && count < total;
            document.getElementById('bulkDeleteCount').textContent = count;
            document.getElementById('bulkDeleteBtn').disabled = count === 0;
        }

        function toggleAllTasks(checked) {
            document.querySelectorAll('.task-select').forEach(item => item.checked = checked);
            updateBulkSelection();
        }

        async function bulkDeleteTasks() {
            const ids = selectedTaskIds();
            if (!ids.length || !confirm(`선택한 ${ids.length}개 업무를 삭제하시겠습니까?`)) return;
            const fd = new FormData();
            fd.append('action', 'bulk_delete');
            fd.append('smw_csrf', csrfToken);
            ids.forEach(id => fd.append('task_ids[]', id));
            const res = await fetch('daily.php', { method: 'POST', body: fd }).then(r => r.json());
            showToast(res.msg);
            if (res.success) setTimeout(() => location.reload(), 700);
        }

        async function checkSpelling() {
            const text = editor.getMarkdown().trim();
            if (!text) { showToast('검사할 상세 내용을 입력해 주세요.'); return; }
            const button = document.getElementById('spellcheckBtn');
            button.disabled = true;
            button.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1"></i> 검사 중';
            try {
                const fd = new FormData(); fd.append('text', text); fd.append('smw_csrf', csrfToken);
                const result = await fetch('spellcheck_api.php', { method: 'POST', body: fd }).then(r => r.json());
                if (!result.success) { showToast(result.message || '맞춤법 검사에 실패했습니다.'); return; }
                document.getElementById('spellcheckProvider').textContent = result.provider_label;
                document.getElementById('spellcheckNotice').textContent = result.notice || '';
                const issueBox = document.getElementById('spellcheckIssues');
                issueBox.innerHTML = result.issues.length
                    ? result.issues.map((issue, index) => `<label class="daily-spell-issue daily-spell-choice${issue.safe ? '' : ' is-protected'}"><input type="checkbox" class="spellcheck-choice" data-index="${index}" ${issue.safe ? 'checked' : ''}><span><del>${escapeHtml(issue.original)}</del> → <ins>${escapeHtml(issue.revised)}</ins>${issue.help ? `<div class="mt-1 text-slate-500">${escapeHtml(issue.help)}</div>` : ''}${issue.warning ? `<div class="daily-spell-warning">${escapeHtml(issue.warning)}</div>` : ''}</span></label>`).join('')
                    : `<div class="daily-spell-issue" style="border-left-color:${result.is_fallback ? '#d97706' : '#059669'}">${result.is_fallback ? '기본 규칙에서 교정 항목을 찾지 못했습니다. 정밀 검사 결과가 아니므로 문장을 한 번 더 확인해 주세요.' : '발견된 교정 항목이 없습니다. 그대로 저장해도 좋습니다.'}</div>`;
                issueBox.dataset.issues = JSON.stringify(result.issues || []);
                document.getElementById('applySpellcheckBtn').classList.toggle('hidden', !result.issues.length);
                document.getElementById('spellcheckPanel').classList.remove('hidden');
                document.body.classList.add('daily-modal-open');
            } catch (error) { showToast('맞춤법 검사 서버에 연결하지 못했습니다.'); }
            finally { button.disabled = false; button.innerHTML = '<i class="fa-solid fa-spell-check mr-1"></i> 맞춤법·띄어쓰기 검사'; }
        }

        function applySpellcheck() {
            const issueBox = document.getElementById('spellcheckIssues');
            const issues = JSON.parse(issueBox.dataset.issues || '[]');
            const selected = Array.from(issueBox.querySelectorAll('.spellcheck-choice:checked')).map(input => issues[Number(input.dataset.index)]).filter(Boolean);
            if (!selected.length) { showToast('적용할 교정 항목을 선택해 주세요.'); return; }
            const wrapper = document.createElement('div');
            wrapper.innerHTML = editor.getHTML();
            const walker = document.createTreeWalker(wrapper, NodeFilter.SHOW_TEXT);
            const textNodes = []; while (walker.nextNode()) textNodes.push(walker.currentNode);
            let replacementCount = 0;
            selected.forEach(issue => {
                textNodes.forEach(node => {
                    if (!node.nodeValue.includes(issue.original)) return;
                    replacementCount += node.nodeValue.split(issue.original).length - 1;
                    node.nodeValue = node.nodeValue.split(issue.original).join(issue.revised);
                });
            });
            if (replacementCount === 0) { showToast('현재 편집 내용에서 선택 문구를 찾지 못했습니다.'); return; }
            editor.setHTML(wrapper.innerHTML);
            closeSpellcheck();
            showToast(replacementCount + '개 교정을 적용했습니다. 문단과 목록 형식은 유지했습니다.');
        }

        function closeSpellcheck() { document.getElementById('spellcheckPanel').classList.add('hidden'); if (document.getElementById('employeePickerModal').classList.contains('hidden')) document.body.classList.remove('daily-modal-open'); }
        function escapeHtml(value) { const div = document.createElement('div'); div.textContent = value || ''; return div.innerHTML; }
        document.addEventListener('keydown', event => {
            const presetModal = document.getElementById('presetLibraryModal');
            if (event.key === 'Escape') { closeEmployeePicker(); closeSpellcheck(); closePresetLibrary(); return; }
            if (event.key !== 'Tab' || presetModal.classList.contains('hidden')) return;
            const focusable = Array.from(presetModal.querySelectorAll('button:not([disabled]), input:not([disabled])')).filter(element => element.offsetParent !== null);
            if (!focusable.length) return;
            const first = focusable[0], last = focusable[focusable.length - 1];
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
            else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
        });

        let currentTaskId = ''; let currentCommentBtn = null;
        
        // ★ [핵심] 모달이 열릴 때 에디터를 그립니다. 숨겨져 있을 때 그리면 뻗어버리는 버그 원천 차단.
        function openCommentModal(taskId, title, btn) { 
            currentTaskId = taskId.toString(); 
            currentCommentBtn = btn; 
            document.getElementById('commentTaskId').value = currentTaskId; 
            document.getElementById('commentTaskTitle').innerText = title; 
            document.getElementById('commentModal').classList.remove('hidden'); 
            
            if (!window.cEditor) {
                window.cEditor = new toastui.Editor({
                    el: document.querySelector('#commentEditor'),
                    height: '200px',
                    initialEditType: 'wysiwyg',
                    toolbarItems: [['bold', 'italic', 'strike'], ['image', 'link']],
                    hooks: { addImageBlobHook: async (blob, callback) => { const fd = new FormData(); fd.append('file', blob); fd.append('smw_csrf', <?= json_encode(smw_csrf_token()) ?>); try { const res = await fetch('upload_image.php', {method:'POST', body:fd}).then(r=>r.json()); if(res.success) callback(res.url, 'Image'); } catch(e) {} } }
                });
            }
            window.cEditor.setHTML('');
            loadComments(); 
        }
        
        function closeCommentModal() { 
            document.getElementById('commentModal').classList.add('hidden'); 
            if(window.cEditor) window.cEditor.setHTML(''); 
        }
        
        async function loadComments() {
            const fd = new FormData(); fd.append('action', 'load'); fd.append('task_id', currentTaskId);
            const res = await fetch('task_comment_api.php', { method: 'POST', body: fd }).then(r => r.json());
            if (currentCommentBtn) { let count = res.data.length; if (count > 0) currentCommentBtn.innerHTML = `<i class="fa-regular fa-comments"></i> <span class='text-red-500 font-bold ml-1'>${count}</span>`; else currentCommentBtn.innerHTML = `<i class="fa-regular fa-comments"></i>`; }
            const listDiv = document.getElementById('commentList'); listDiv.innerHTML = '';
            if (res.data.length === 0) { listDiv.innerHTML = '<div class="text-center text-gray-400 py-10">첫 번째 코멘트를 남겨보세요!</div>'; return; }
            res.data.forEach(c => {
                const isMe = (parseInt(c.user_id) === parseInt(res.current_uid));
                const alignClass = isMe ? 'justify-end' : 'justify-start';
                const bgClass = isMe ? 'bg-teal-50 border-teal-200' : 'bg-white border-gray-200';
                const delBtn = isMe ? `<button onclick="deleteComment(${c.id})" class="text-xs text-red-400 hover:text-red-600 ml-2">삭제</button>` : '';
                listDiv.innerHTML += `<div class="flex ${alignClass}"><div class="max-w-[80%]"><div class="text-xs text-gray-500 mb-1 px-1 ${isMe ? 'text-right' : ''}"><span class="font-bold text-gray-700">[${c.position}] ${c.nickname}</span><span class="ml-2">${c.created_at.substring(0,16)}</span>${delBtn}</div><div class="p-3 rounded-xl border shadow-sm ${bgClass} toastui-editor-contents text-sm text-left">${c.content}</div></div></div>`;
            });
            listDiv.scrollTop = listDiv.scrollHeight;
        }
        
        async function submitComment() { 
            const content = window.cEditor.getHTML(); 
            if(!content || content === '<p><br></p>') { alert('내용을 입력하세요.'); return; } 
            const fd = new FormData(); fd.append('action', 'add'); fd.append('task_id', currentTaskId); fd.append('content', content); 
            const res = await fetch('task_comment_api.php', { method: 'POST', body: fd }).then(r => r.json()); 
            if(res.success) { window.cEditor.setHTML(''); loadComments(); } 
        }
        
        async function deleteComment(cid) { if(!confirm('삭제하시겠습니까?')) return; const fd = new FormData(); fd.append('action', 'delete'); fd.append('comment_id', cid); await fetch('task_comment_api.php', { method: 'POST', body: fd }); loadComments(); }
    </script>
</body>
</html>

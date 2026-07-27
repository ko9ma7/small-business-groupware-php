<?php
// 파일명: /smw/task_json_manager.php
session_start();
error_reporting(0); ini_set('display_errors', 0);
include 'db_conn.php';
require_once 'smw_extensions.php';

if (!isset($_SESSION['uid'])) { header("Location: login.php"); exit; }
$my_uid = (int)$_SESSION['uid'];
$is_admin = (int)$_SESSION['admin'];

// --- [ACTION 1] JSON 내보내기 (Export) ---
if (isset($_GET['action']) && $_GET['action'] === 'export') {
    $target_user = isset($_GET['user_id']) ? (int)$_GET['user_id'] : $my_uid;
    $s_date = $_GET['s_date'];
    $e_date = $_GET['e_date'];

    if ($is_admin !== 1) $target_user = $my_uid;

    $query = "SELECT id, target_date, company_name, task_category, plan_content, result_content, task_type 
              FROM report_tasks 
              WHERE user_id = $target_user AND target_date BETWEEN '$s_date' AND '$e_date'
              ORDER BY target_date ASC";
    $res = $conn->query($query);
    $data = $res->fetch_all(MYSQLI_ASSOC);

    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="tasks_export_'.date('Ymd').'.json"');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// --- [ACTION 2] JSON 불러오기 및 반영 (Import) ---
$import_msg = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'import') {
    smw_verify_csrf();
    if (!empty($_FILES['json_file']['tmp_name'])) {
        [$validJsonUpload, $jsonUploadMessage] = smw_validate_upload($_FILES['json_file'], 'json');
        if (!$validJsonUpload) {
            $import_msg = $jsonUploadMessage;
        } else {
        $json_data = file_get_contents($_FILES['json_file']['tmp_name']);
        $tasks = json_decode($json_data, true);
        $target_user = (int)$_POST['target_user_id'];

        if ($is_admin !== 1) $target_user = $my_uid;

        if (is_array($tasks)) {
            $success_count = 0;
            foreach ($tasks as $task) {
                $t_id = isset($task['id']) ? (int)$task['id'] : 0;
                $t_date = $task['target_date'];
                $c_name = $task['company_name'];
                $cat = $task['task_category'] ?? '일반업무';
                $plan = $task['plan_content'];
                $result = $task['result_content'];
                $type = $task['task_type'] ?? 'actual';

                // [핵심 로직 개선] 
                // 해당 ID가 실제로 이 사용자(target_user)의 것인지 먼저 확인합니다.
                $check = $conn->query("SELECT id FROM report_tasks WHERE id = $t_id AND user_id = $target_user");
                
                if ($t_id > 0 && $check && $check->num_rows > 0) {
                    // 내 데이터가 맞으면 -> 업데이트(수정)
                    $stmt = $conn->prepare("UPDATE report_tasks SET target_date=?, company_name=?, task_category=?, plan_content=?, result_content=?, task_type=? WHERE id=? AND user_id=?");
                    $stmt->bind_param("ssssssii", $t_date, $c_name, $cat, $plan, $result, $type, $t_id, $target_user);
                } else {
                    // ID가 없거나, 다른 사람의 ID라면 -> 신규 등록(복사)
                    $stmt = $conn->prepare("INSERT INTO report_tasks (user_id, target_date, company_name, task_category, plan_content, result_content, task_type) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param("issssss", $target_user, $t_date, $c_name, $cat, $plan, $result, $type);
                }
                
                if ($stmt->execute()) $success_count++;
            }
            $import_msg = "✅ 총 {$success_count}건의 업무 데이터가 반영되었습니다. (기존 데이터 수정 및 신규 복사 포함)";
        }
        }
    }
}

$user_list = [];
if($is_admin == 1) {
    $u_res = $conn->query("SELECT id, nickname, position FROM users ORDER BY nickname ASC");
    $user_list = $u_res->fetch_all(MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8"><title>업무 데이터 일괄 관리 (JSON)</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-slate-100 min-h-screen">
    <nav class="bg-slate-900 text-white p-4 shadow-lg">
        <div class="max-w-4xl mx-auto flex justify-between items-center">
            <h1 class="font-bold text-lg"><i class="fa-solid fa-robot mr-2"></i>AI 업무 교정 및 데이터 관리</h1>
            <a href="index.php" class="text-sm bg-slate-700 px-3 py-1 rounded hover:bg-slate-600">대시보드 돌아가기</a>
        </div>
    </nav>

    <main class="max-w-4xl mx-auto py-10 px-4 space-y-8">
        <?php if($import_msg): ?>
        <div class="bg-emerald-100 border-l-4 border-emerald-500 p-4 text-emerald-800 font-bold shadow-md rounded">
            <?= $import_msg ?>
        </div>
        <?php endif; ?>

        <section class="bg-white p-6 rounded-xl shadow-md border-t-4 border-blue-500">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-xl font-bold text-gray-800"><i class="fa-solid fa-file-export text-blue-600 mr-2"></i>Step 1. 원본 데이터 추출 (Export)</h2>
                <span class="bg-blue-100 text-blue-700 text-xs px-2 py-1 rounded-full font-bold">대상 지정 가능</span>
            </div>
            <p class="text-sm text-gray-500 mb-6">오타가 있거나 수정이 필요한 직원의 업무를 선택하여 JSON으로 내려받으세요.</p>
            
            <form action="task_json_manager.php" method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
                <input type="hidden" name="action" value="export">
                <?php if($is_admin == 1): ?>
                <div>
                    <label class="block text-xs font-bold text-gray-600 mb-1">추출 대상</label>
                    <select name="user_id" class="w-full p-2 border rounded text-sm bg-gray-50 font-bold">
                        <?php foreach($user_list as $u): ?>
                        <option value="<?= $u['id'] ?>" <?= $u['id'] == $my_uid ? 'selected' : '' ?>><?= $u['nickname'] ?> (<?= $u['position'] ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div>
                    <label class="block text-xs font-bold text-gray-600 mb-1">시작일</label>
                    <input type="date" name="s_date" value="<?= date('Y-m-d', strtotime('-7 days')) ?>" class="w-full p-2 border rounded text-sm" required>
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-600 mb-1">종료일</label>
                    <input type="date" name="e_date" value="<?= date('Y-m-d') ?>" class="w-full p-2 border rounded text-sm" required>
                </div>
                <button type="submit" class="bg-blue-600 text-white font-bold py-2 rounded hover:bg-blue-700 shadow-md transition">
                    <i class="fa-solid fa-download mr-1"></i> 파일 추출
                </button>
            </form>
        </section>

        <section class="bg-white p-6 rounded-xl shadow-md border-t-4 border-emerald-500">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-xl font-bold text-gray-800"><i class="fa-solid fa-wand-magic-sparkles text-emerald-600 mr-2"></i>Step 2. 교정된 데이터 반영 (Import)</h2>
                <span class="bg-emerald-100 text-emerald-700 text-xs px-2 py-1 rounded-full font-bold">자동 분류 등록</span>
            </div>
            <p class="text-sm text-gray-500 mb-6">Gemini를 통해 교정된 파일을 업로드하세요. **다른 사람의 데이터라도 선택한 사용자에게 새롭게 등록**됩니다.</p>
            
            <form action="task_json_manager.php" method="POST" enctype="multipart/form-data" class="space-y-4">
                <input type="hidden" name="action" value="import">
                <input type="hidden" name="smw_csrf" value="<?= smw_h(smw_csrf_token()) ?>">
                <div class="flex gap-4 items-center mb-2">
                    <div class="w-1/3">
                        <label class="block text-xs font-bold text-gray-600 mb-1">데이터를 넣어줄 계정 (반영 대상)</label>
                        <select name="target_user_id" class="w-full p-2 border border-emerald-300 rounded text-sm bg-emerald-50 font-bold text-emerald-800">
                            <?php foreach($user_list as $u): ?>
                            <option value="<?= $u['id'] ?>" <?= $u['id'] == $my_uid ? 'selected' : '' ?>><?= $u['nickname'] ?> (<?= $u['position'] ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="flex-grow pt-5">
                        <p class="text-xs text-amber-600 font-bold"><i class="fa-solid fa-circle-exclamation mr-1"></i> 팁: 테스트 계정을 선택하면 안전하게 미리 확인해볼 수 있습니다.</p>
                    </div>
                </div>
                <div class="border-2 border-dashed border-gray-200 rounded-lg p-10 text-center hover:border-emerald-400 transition bg-gray-50 group">
                    <input type="file" name="json_file" id="json_file" class="hidden" accept=".json" required onchange="updateFileName(this)">
                    <label for="json_file" class="cursor-pointer">
                        <i class="fa-solid fa-file-circle-check text-5xl text-gray-300 mb-4 block group-hover:text-emerald-500 transition"></i>
                        <span id="file_name_display" class="text-gray-500 font-bold">교정 완료된 JSON 파일을 여기에 넣어주세요.</span>
                    </label>
                </div>
                <div class="flex justify-end">
                    <button type="submit" class="bg-emerald-600 text-white font-bold px-10 py-4 rounded-xl shadow-xl hover:bg-emerald-700 transition transform hover:scale-105">
                        <i class="fa-solid fa-upload mr-1"></i> 데이터 일괄 반영하기
                    </button>
                </div>
            </form>
        </section>
    </main>

    <script>
        function updateFileName(input) {
            const fileName = input.files[0] ? input.files[0].name : "파일을 선택하세요.";
            document.getElementById('file_name_display').innerText = fileName;
            document.getElementById('file_name_display').classList.add('text-emerald-600');
        }
    </script>
</body>
</html>

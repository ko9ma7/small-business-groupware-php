<?php
// 파일명: /smw/schedule.php
session_start();
include 'db_conn.php';
if (!isset($_SESSION['uid'])) { header("Location: login.php"); exit; }
require_once 'groupware_shell.php';

$uid = (int)$_SESSION['uid'];
$u_info = $conn->query("SELECT position, is_admin FROM users WHERE id = $uid")->fetch_assoc();
$my_position = $u_info['position'] ?? '사원';
$is_admin = $u_info['is_admin'] == 1 || $my_position === '사장';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    smw_verify_csrf();

    if (isset($_POST['delete_schedule'])) {
        $del_id = (int)($_POST['schedule_id'] ?? 0);
        $auth_check = $is_admin ? "" : "AND author_id=$uid";
        $conn->query("DELETE FROM schedules WHERE id=$del_id $auth_check");
        echo "<script>alert('일정이 삭제되었습니다.'); location.href='schedule.php';</script>"; exit;
    }

    $title = $conn->real_escape_string($_POST['title'] ?? '');
    $start_date = $conn->real_escape_string($_POST['start_date'] ?? '');
    $end_date = !empty($_POST['end_date']) ? $conn->real_escape_string($_POST['end_date']) : $start_date;
    
    // 수정 로직
    if (isset($_POST['edit_schedule']) && !empty($_POST['schedule_id'])) {
        $sid = (int)$_POST['schedule_id'];
        $auth_check = $is_admin ? "" : "AND author_id=$uid";
        $conn->query("UPDATE schedules SET title='$title', start_date='$start_date', end_date='$end_date' WHERE id=$sid $auth_check");
        echo "<script>alert('일정이 수정되었습니다.'); location.href='schedule.php';</script>"; exit;
    } 
    // 등록 로직
    elseif (isset($_POST['add_schedule'])) {
        $conn->query("INSERT INTO schedules (title, start_date, end_date, author_id) VALUES ('$title', '$start_date', '$end_date', $uid)");
        echo "<script>alert('사내 일정이 등록되었습니다.'); location.href='schedule.php';</script>"; exit;
    }
}

$current_date = date('Y-m-d');
$schedules = $conn->query("SELECT s.*, u.nickname FROM schedules s JOIN users u ON s.author_id = u.id ORDER BY s.start_date ASC")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>사내 일정 관리</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="assets/groupware-shell.css?v=2">
<style>body { font-family: 'Malgun Gothic', sans-serif; }</style>
</head>
<body class="gw-body min-h-screen pb-10">

    <?php smw_render_shell_header('schedule', '사내 전체 일정 관리', $is_admin); ?>

    <div class="max-w-6xl mx-auto mt-8 px-4 flex flex-col md:flex-row gap-6">
        <div class="w-full md:w-1/3">
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 sticky top-24">
                <h2 class="font-bold text-lg mb-4 border-b pb-2 text-blue-800">📌 새 일정 등록</h2>
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="smw_csrf" value="<?= smw_h(smw_csrf_token()) ?>">
                    <input type="hidden" name="add_schedule" value="1">
                    <div><label class="block text-sm font-bold mb-1">일정 내용</label><input type="text" name="title" required class="w-full p-2 border rounded"></div>
                    <div><label class="block text-sm font-bold mb-1">시작일</label><input type="date" name="start_date" value="<?= date('Y-m-d') ?>" required class="w-full p-2 border rounded"></div>
                    <div><label class="block text-sm font-bold mb-1">종료일</label><input type="date" name="end_date" class="w-full p-2 border rounded"></div>
                    <button type="submit" class="w-full bg-blue-600 text-white font-bold py-2 rounded">일정 추가하기</button>
                </form>
            </div>
        </div>

        <div class="w-full md:w-2/3">
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                <div class="px-6 py-4 bg-gray-50 border-b flex justify-between items-center"><h2 class="font-bold text-gray-800">등록된 전체 일정 리스트</h2></div>
                <table class="w-full text-sm text-left text-gray-600">
                    <thead class="text-xs text-gray-700 uppercase bg-gray-100 border-b"><tr><th class="px-4 py-3 w-40">기간</th><th class="px-4 py-3">일정 내용</th><th class="px-4 py-3 w-24">등록자</th><th class="px-4 py-3 w-24 text-center">관리</th></tr></thead>
                    <tbody>
                        <?php foreach($schedules as $s): 
                            $is_past = ($s['end_date'] < $current_date);
                            $date_str = ($s['start_date'] == $s['end_date']) ? $s['start_date'] : $s['start_date'] . ' ~ ' . $s['end_date'];
                        ?>
                        <tr class="border-b <?= $is_past ? 'bg-gray-50 opacity-60' : 'hover:bg-blue-50' ?>">
                            <td class="px-4 py-3 font-bold <?= $is_past ? 'text-gray-400' : 'text-blue-700' ?>"><?= $date_str ?></td>
                            <td class="px-4 py-3 font-bold text-gray-800"><?= htmlspecialchars($s['title']) ?> <?php if($is_past) echo "<span class='text-xs font-normal text-red-500'>(종료)</span>"; ?></td>
                            <td class="px-4 py-3"><?= htmlspecialchars($s['nickname']) ?></td>
                            <td class="px-4 py-3 text-center">
                                <?php if($s['author_id'] == $uid || $is_admin): ?>
                                <button onclick='openEdit(<?= json_encode($s) ?>)' class="text-white bg-indigo-500 px-2 py-1 rounded text-xs mb-1">수정</button>
                                <form method="POST" class="inline" onsubmit="return confirm('일정을 삭제하시겠습니까?')">
                                    <input type="hidden" name="smw_csrf" value="<?= smw_h(smw_csrf_token()) ?>">
                                    <input type="hidden" name="delete_schedule" value="1">
                                    <input type="hidden" name="schedule_id" value="<?= $s['id'] ?>">
                                    <button type="submit" class="text-white bg-red-500 px-2 py-1 rounded text-xs">삭제</button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div id="editModal" class="fixed inset-0 bg-black bg-opacity-50 hidden flex items-center justify-center z-50 p-4">
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-sm p-6">
            <h3 class="text-lg font-bold mb-4 border-b pb-2">✏️ 일정 수정</h3>
            <form method="POST" class="space-y-4">
                <input type="hidden" name="smw_csrf" value="<?= smw_h(smw_csrf_token()) ?>">
                <input type="hidden" name="edit_schedule" value="1">
                <input type="hidden" name="schedule_id" id="edit_id">
                <div><label class="block text-sm font-bold mb-1">일정 내용</label><input type="text" id="edit_title" name="title" required class="w-full p-2 border rounded"></div>
                <div><label class="block text-sm font-bold mb-1">시작일</label><input type="date" id="edit_start" name="start_date" required class="w-full p-2 border rounded"></div>
                <div><label class="block text-sm font-bold mb-1">종료일</label><input type="date" id="edit_end" name="end_date" class="w-full p-2 border rounded"></div>
                <div class="flex gap-2 pt-2">
                    <button type="button" onclick="document.getElementById('editModal').classList.add('hidden')" class="w-1/3 bg-gray-200 font-bold py-2 rounded">취소</button>
                    <button type="submit" class="w-2/3 bg-indigo-600 text-white font-bold py-2 rounded">수정 완료</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openEdit(s) {
            document.getElementById('edit_id').value = s.id;
            document.getElementById('edit_title').value = s.title;
            document.getElementById('edit_start').value = s.start_date;
            document.getElementById('edit_end').value = s.end_date;
            document.getElementById('editModal').classList.remove('hidden');
        }
    </script>
</body>
</html>

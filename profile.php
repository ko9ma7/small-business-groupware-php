<?php
// 파일명: /smw/profile.php
session_start();
error_reporting(0); ini_set('display_errors', 0);
include 'db_conn.php';

if (!isset($_SESSION['uid'])) { header("Location: login.php"); exit; }
require_once 'groupware_shell.php';
$uid = (int)$_SESSION['uid'];

// 정보 수정 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $phone = $conn->real_escape_string($_POST['phone']);
    $email = $conn->real_escape_string($_POST['email']);
    $birth_type = $conn->real_escape_string($_POST['birth_type']);
    $birth_date = !empty($_POST['birth_date']) ? "'" . $conn->real_escape_string($_POST['birth_date']) . "'" : "NULL";

    // 비밀번호 변경이 입력된 경우에만 쿼리 추가
    $pw_query = "";
    if (!empty($_POST['new_password'])) {
        $hashed_pw = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
        $pw_query = ", password = '$hashed_pw'";
    }

    $sql = "UPDATE users SET phone='$phone', email='$email', birth_type='$birth_type', birth_date=$birth_date $pw_query WHERE id=$uid";
    
    if ($conn->query($sql)) {
        echo "<script>alert('내 정보가 성공적으로 수정되었습니다.'); location.href='index.php';</script>";
        exit;
    } else {
        $message = "저장 중 오류가 발생했습니다: " . $conn->error;
    }
}

// 내 정보 불러오기
$u_info = $conn->query("SELECT * FROM users WHERE id = $uid")->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>내 정보 수정</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="assets/groupware-shell.css?v=2">
<style>body { font-family: 'Malgun Gothic', sans-serif; }</style>
</head>
<body class="gw-body min-h-screen pb-10">

    <?php smw_render_shell_header('profile', '내 정보 수정', (int)($u_info['is_admin'] ?? 0) === 1, (string)$u_info['nickname']); ?>

    <div class="max-w-2xl mx-auto mt-8 px-4">
        <div class="bg-white p-8 rounded-xl shadow-lg border-t-4 border-teal-500">
            
            <?php if(isset($message)) echo "<div class='bg-red-100 text-red-700 p-3 rounded mb-4 font-bold'>$message</div>"; ?>

            <div class="flex items-center gap-4 mb-8 bg-gray-50 p-4 rounded-lg border border-gray-200">
                <div class="w-16 h-16 bg-teal-100 text-teal-600 rounded-full flex items-center justify-center text-2xl font-bold border-2 border-white shadow-sm">
                    <?= mb_substr($u_info['nickname'], 0, 1, 'utf-8') ?>
                </div>
                <div>
                    <h2 class="text-xl font-bold text-gray-800"><?= htmlspecialchars($u_info['nickname']) ?> <span class="text-sm font-normal text-gray-500 ml-1">(<?= htmlspecialchars($u_info['username']) ?>)</span></h2>
                    <div class="text-teal-700 font-bold text-sm mt-1">사내 직급: [<?= htmlspecialchars($u_info['position']) ?>]</div>
                    <p class="text-xs text-gray-400 mt-1">* 이름과 직급 변경은 시스템 관리자만 가능합니다.</p>
                </div>
            </div>

            <form method="POST" class="space-y-5">
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">휴대전화 (주소록 연동)</label>
                    <input type="text" name="phone" value="<?= htmlspecialchars($u_info['phone'] ?? '') ?>" placeholder="010-0000-0000" class="w-full p-3 border border-gray-300 rounded focus:ring-2 focus:ring-teal-500 transition">
                </div>
                
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">이메일 주소 (주소록 연동)</label>
                    <input type="email" name="email" value="<?= htmlspecialchars($u_info['email'] ?? '') ?>" placeholder="example@company.com" class="w-full p-3 border border-gray-300 rounded focus:ring-2 focus:ring-teal-500 transition">
                </div>
                
                <div class="flex gap-2">
                    <div class="w-1/3">
                        <label class="block text-sm font-bold text-gray-700 mb-1">생일 구분</label>
                        <select name="birth_type" class="w-full p-3 border border-gray-300 rounded focus:ring-2 focus:ring-teal-500">
                            <option value="solar" <?= ($u_info['birth_type']=='solar')?'selected':'' ?>>양력</option>
                            <option value="lunar" <?= ($u_info['birth_type']=='lunar')?'selected':'' ?>>음력</option>
                        </select>
                    </div>
                    <div class="w-2/3">
                        <label class="block text-sm font-bold text-gray-700 mb-1">생년월일 (캘린더 연동)</label>
                        <input type="date" name="birth_date" value="<?= htmlspecialchars($u_info['birth_date'] ?? '') ?>" class="w-full p-3 border border-gray-300 rounded focus:ring-2 focus:ring-teal-500 transition">
                    </div>
                </div>

                <div class="pt-4 border-t border-gray-200 mt-6">
                    <label class="block text-sm font-bold text-red-600 mb-1">새 비밀번호 (변경 시에만 입력)</label>
                    <input type="password" name="new_password" placeholder="비워두시면 기존 비밀번호가 유지됩니다." class="w-full p-3 border border-gray-300 rounded focus:ring-2 focus:ring-red-300 transition bg-red-50">
                </div>
                
                <button type="submit" class="w-full bg-teal-600 hover:bg-teal-700 text-white font-bold py-3 rounded-lg shadow-md transition text-lg mt-6">
                    내 정보 저장하기
                </button>
            </form>
        </div>
    </div>
</body>
</html>

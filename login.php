<?php
// 파일명: /smw/login.php
session_start();
error_reporting(0); ini_set('display_errors', 0);
include 'db_conn.php';
require_once 'smw_extensions.php';

if (isset($_SESSION['uid'])) { header("Location: index.php"); exit; }

$mode = isset($_GET['mode']) ? $_GET['mode'] : 'login';
$msg = '';
$site_settings = smw_site_settings($conn);
$user_count_result = $conn->query("SELECT COUNT(*) AS total FROM users");
$user_count = $user_count_result ? (int)$user_count_result->fetch_assoc()['total'] : 0;
$registration_enabled = $user_count === 0 || $site_settings['registration_enabled'] === '1';
if ($mode === 'register' && !$registration_enabled) {
    $mode = 'login';
    $msg = '현재 신규 회원가입이 중지되어 있습니다. 관리자에게 계정 등록을 요청해 주세요.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'];
    $id = $conn->real_escape_string($_POST['username']);
    $pw = $_POST['password'];

    if ($action === 'login') {
        $res = $conn->query("SELECT * FROM users WHERE username='$id'");
        if ($res && $res->num_rows > 0) {
            $user = $res->fetch_assoc();
            if (password_verify($pw, $user['password'])) {
                $_SESSION['uid'] = $user['id'];
                $_SESSION['admin'] = $user['is_admin'];
                header("Location: index.php"); exit;
            } else { $msg = "비밀번호가 일치하지 않습니다."; }
        } else { $msg = "존재하지 않는 아이디입니다."; }
    } elseif ($action === 'register') {
        if (!$registration_enabled) {
            $msg = '현재 신규 회원가입이 중지되어 있습니다.';
            $mode = 'login';
        } else {
        $nickname = $conn->real_escape_string($_POST['nickname']);
        $phone = $conn->real_escape_string($_POST['phone']);
        $email = $conn->real_escape_string($_POST['email']);
        $birth_type = $conn->real_escape_string($_POST['birth_type']);
        $birth_date = !empty($_POST['birth_date']) ? "'" . $conn->real_escape_string($_POST['birth_date']) . "'" : "NULL";

        $check = $conn->query("SELECT id FROM users WHERE username='$id'");
        if ($check && $check->num_rows > 0) {
            $msg = "이미 존재하는 아이디입니다."; $mode = 'register';
        } else {
            $hashed_pw = password_hash($pw, PASSWORD_DEFAULT);
            $default_position = $user_count === 0 ? '사장' : '사원';
            $default_admin = $user_count === 0 ? 1 : 0;
            $sql = "INSERT INTO users (username, password, nickname, position, phone, email, birth_type, birth_date, is_admin) 
                    VALUES ('$id', '$hashed_pw', '$nickname', '$default_position', '$phone', '$email', '$birth_type', $birth_date, $default_admin)";
            
            if ($conn->query($sql)) {
                $msg = $default_admin === 1
                    ? "첫 관리자 계정이 생성되었습니다. 로그인 후 회사와 그룹웨어 설정을 완료해 주세요."
                    : "회원가입이 완료되었습니다. 바로 로그인할 수 있습니다.";
                $mode = 'login';
            } else {
                $msg = "회원가입 실패 (DB오류): " . $conn->error; $mode = 'register';
            }
        }
        }
    }
}
$portal_identity = smw_portal_identity($conn);
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= smw_h($portal_identity['name']) ?> - <?= $mode === 'login' ? '로그인' : '회원가입' ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>body { font-family: 'Malgun Gothic', sans-serif; }</style>
</head>
<body class="bg-slate-100 min-h-screen flex items-center justify-center p-4 py-10">

    <div class="bg-white rounded-2xl shadow-xl w-full max-w-md p-8 border-t-4 border-blue-600 my-auto">
        <div class="text-center mb-6">
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-blue-100 text-blue-600 mb-4"><i class="fa-solid fa-building text-3xl"></i></div>
            <h1 class="text-2xl font-bold text-gray-800"><?= smw_h($portal_identity['name']) ?></h1>
            <p class="mt-1 text-sm text-slate-500"><?= smw_h($portal_identity['companies']) ?></p>
        </div>

        <?php if($msg): ?><div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4 text-sm text-center font-bold" role="status"><?= smw_h($msg) ?></div><?php endif; ?>

        <?php if($mode === 'login'): ?>
            <form method="POST" class="space-y-4">
                <input type="hidden" name="action" value="login">
                <div><label class="block text-sm font-bold text-gray-700 mb-1">아이디</label><input type="text" name="username" required class="w-full px-4 py-3 rounded border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200 outline-none transition"></div>
                <div><label class="block text-sm font-bold text-gray-700 mb-1">비밀번호</label><input type="password" name="password" required class="w-full px-4 py-3 rounded border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200 outline-none transition"></div>
                <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 px-4 rounded transition mt-4">로그인</button>
            </form>
            <?php if($registration_enabled): ?><div class="text-center mt-6 text-sm text-gray-600">계정이 없으신가요? <a href="?mode=register" class="text-blue-600 font-bold hover:underline">회원가입하기</a></div><?php else: ?><div class="text-center mt-6 text-sm text-gray-500">신규 가입은 관리자에게 요청해 주세요.</div><?php endif; ?>

        <?php else: ?>
            <form method="POST" class="space-y-3">
                <input type="hidden" name="action" value="register">
                <div class="grid grid-cols-2 gap-3">
                    <div><label class="block text-sm font-bold text-gray-700 mb-1">아이디</label><input type="text" name="username" required class="w-full px-3 py-2 border rounded"></div>
                    <div><label class="block text-sm font-bold text-gray-700 mb-1">비밀번호</label><input type="password" name="password" required class="w-full px-3 py-2 border rounded"></div>
                </div>
                <div><label class="block text-sm font-bold text-gray-700 mb-1">이름 (본명)</label><input type="text" name="nickname" required class="w-full px-3 py-2 border rounded"></div>
                
                <div class="grid grid-cols-2 gap-3">
                    <div><label class="block text-sm font-bold text-gray-700 mb-1">휴대전화</label><input type="text" name="phone" placeholder="010-0000-0000" class="w-full px-3 py-2 border rounded"></div>
                    <div><label class="block text-sm font-bold text-gray-700 mb-1">이메일</label><input type="email" name="email" placeholder="example@email.com" class="w-full px-3 py-2 border rounded"></div>
                </div>

                <div class="flex gap-2">
                    <div class="w-1/3"><label class="block text-sm font-bold text-gray-700 mb-1">생일 구분</label><select name="birth_type" class="w-full px-3 py-2 border rounded"><option value="solar">양력</option><option value="lunar">음력</option></select></div>
                    <div class="w-2/3"><label class="block text-sm font-bold text-gray-700 mb-1">생년월일</label><input type="date" name="birth_date" class="w-full px-3 py-2 border rounded"></div>
                </div>
                <div class="text-xs text-gray-500 mb-2">* 직급 및 시스템 권한은 가입 후 관리자가 부여합니다.</div>
                
                <button type="submit" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-3 px-4 rounded transition mt-4">가입 완료하기</button>
            </form>
            <div class="text-center mt-6 text-sm text-gray-600">이미 계정이 있으신가요? <a href="?mode=login" class="text-blue-600 font-bold hover:underline">로그인하기</a></div>
        <?php endif; ?>
    </div>
</body>
</html>

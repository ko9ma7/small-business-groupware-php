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
    smw_verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    $id = mb_substr(trim((string)($_POST['username'] ?? '')), 0, 80, 'UTF-8');
    $pw = (string)($_POST['password'] ?? '');

    if ($action === 'login') {
        $ipHash = smw_client_ip_hash();
        $limit = smw_login_throttle($conn, $id, $ipHash);
        if ($limit['locked']) {
            $minutes = max(1, (int)ceil($limit['retry_after'] / 60));
            $msg = "로그인 시도가 많아 잠시 보호 중입니다. 약 {$minutes}분 후 다시 시도해 주세요.";
        } else {
            $stmt = $conn->prepare("SELECT * FROM users WHERE username=? LIMIT 1");
            $stmt->bind_param('s', $id);
            $stmt->execute();
            $res = $stmt->get_result();
            $user = $res && $res->num_rows > 0 ? $res->fetch_assoc() : null;
            $validPassword = $user
                ? password_verify($pw, $user['password'])
                : password_verify($pw, '$2y$10$CwTycUXWue0Thq9StjUM0uJ8LmjiI61unXHSzKItSLvCAEhX7WwYu');
            $stmt->close();
            if ($user && $validPassword) {
                smw_record_login_attempt($conn, $id, $ipHash, true);
                session_regenerate_id(true);
                $_SESSION['uid'] = $user['id'];
                $_SESSION['admin'] = $user['is_admin'];
                smw_audit_log($conn, 'login.success', 'user', (int)$user['id'], '사용자가 로그인했습니다.', (int)$user['id']);
                header("Location: index.php"); exit;
            }
            smw_record_login_attempt($conn, $id, $ipHash, false);
            $msg = "아이디 또는 비밀번호를 확인해 주세요.";
        }
    } elseif ($action === 'register') {
        if (!$registration_enabled) {
            $msg = '현재 신규 회원가입이 중지되어 있습니다.';
            $mode = 'login';
        } else {
        $nickname = mb_substr(trim((string)($_POST['nickname'] ?? '')), 0, 100, 'UTF-8');
        $phone = mb_substr(trim((string)($_POST['phone'] ?? '')), 0, 30, 'UTF-8');
        $email = mb_substr(trim((string)($_POST['email'] ?? '')), 0, 190, 'UTF-8');
        $birth_type = in_array($_POST['birth_type'] ?? '', ['solar', 'lunar'], true) ? (string)$_POST['birth_type'] : 'solar';
        $birth_date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['birth_date'] ?? ''))
            ? (string)$_POST['birth_date']
            : null;
        $passwordError = smw_password_error($pw);

        if ($id === '' || $nickname === '') {
            $msg = "아이디와 이름을 입력해 주세요."; $mode = 'register';
        } elseif ($passwordError !== '') {
            $msg = $passwordError; $mode = 'register';
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $msg = "이메일 주소 형식을 확인해 주세요."; $mode = 'register';
        } else {
            $checkStmt = $conn->prepare("SELECT id FROM users WHERE username=?");
            $checkStmt->bind_param('s', $id);
            $checkStmt->execute();
            $check = $checkStmt->get_result();
            $alreadyExists = $check && $check->num_rows > 0;
            $checkStmt->close();
        if ($alreadyExists) {
            $msg = "이미 존재하는 아이디입니다."; $mode = 'register';
        } else {
            $hashed_pw = password_hash($pw, PASSWORD_DEFAULT);
            $default_position = $user_count === 0 ? '사장' : '사원';
            $default_admin = $user_count === 0 ? 1 : 0;
            $insertStmt = $conn->prepare(
                "INSERT INTO users (username, password, nickname, position, phone, email, birth_type, birth_date, is_admin)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $insertStmt->bind_param(
                'ssssssssi',
                $id,
                $hashed_pw,
                $nickname,
                $default_position,
                $phone,
                $email,
                $birth_type,
                $birth_date,
                $default_admin
            );
            if ($insertStmt->execute()) {
                $newUserId = (int)$insertStmt->insert_id;
                smw_audit_log($conn, 'user.register', 'user', $newUserId, '새 사용자 계정이 생성되었습니다.', $newUserId);
                $msg = $default_admin === 1
                    ? "첫 관리자 계정이 생성되었습니다. 로그인 후 회사와 그룹웨어 설정을 완료해 주세요."
                    : "회원가입이 완료되었습니다. 바로 로그인할 수 있습니다.";
                $mode = 'login';
            } else {
                $msg = "회원가입 처리 중 오류가 발생했습니다."; $mode = 'register';
            }
            $insertStmt->close();
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
                <input type="hidden" name="smw_csrf" value="<?= smw_h(smw_csrf_token()) ?>">
                <div><label for="login_username" class="block text-sm font-bold text-gray-700 mb-1">아이디</label><input id="login_username" type="text" name="username" autocomplete="username" maxlength="80" required class="w-full px-4 py-3 rounded border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200 outline-none transition"></div>
                <div><label for="login_password" class="block text-sm font-bold text-gray-700 mb-1">비밀번호</label><input id="login_password" type="password" name="password" autocomplete="current-password" required class="w-full px-4 py-3 rounded border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200 outline-none transition"></div>
                <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 px-4 rounded transition mt-4">로그인</button>
            </form>
            <?php if($registration_enabled): ?><div class="text-center mt-6 text-sm text-gray-600">계정이 없으신가요? <a href="?mode=register" class="text-blue-600 font-bold hover:underline">회원가입하기</a></div><?php else: ?><div class="text-center mt-6 text-sm text-gray-500">신규 가입은 관리자에게 요청해 주세요.</div><?php endif; ?>

        <?php else: ?>
            <form method="POST" class="space-y-3">
                <input type="hidden" name="action" value="register">
                <input type="hidden" name="smw_csrf" value="<?= smw_h(smw_csrf_token()) ?>">
                <div class="grid grid-cols-2 gap-3">
                    <div><label for="register_username" class="block text-sm font-bold text-gray-700 mb-1">아이디</label><input id="register_username" type="text" name="username" autocomplete="username" maxlength="80" required class="w-full px-3 py-2 border rounded"></div>
                    <div><label for="register_password" class="block text-sm font-bold text-gray-700 mb-1">비밀번호</label><input id="register_password" type="password" name="password" autocomplete="new-password" minlength="8" aria-describedby="password_help" required class="w-full px-3 py-2 border rounded"></div>
                </div>
                <p id="password_help" class="text-xs text-slate-500">8자 이상, 영문과 숫자를 각각 하나 이상 포함하세요.</p>
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

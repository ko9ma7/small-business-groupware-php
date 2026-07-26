<?php
// 파일명: /smw/address_book.php
session_start();
include 'db_conn.php';
if (!isset($_SESSION['uid'])) { header("Location: login.php"); exit; }
require_once 'smw_extensions.php';
require_once 'groupware_shell.php';

// 직급 순서대로 정렬하고 회사/사업부 소속을 함께 표시
$query = "SELECT
              u.id,
              u.username,
              u.nickname,
              u.position,
              u.phone,
              u.email,
              GROUP_CONCAT(
                  DISTINCT CONCAT(
                      c.name,
                      IF(d.name IS NULL OR d.name = '', '', CONCAT(' / ', d.name))
                  )
                  ORDER BY oa.is_primary DESC, c.sort_order ASC, c.name ASC
                  SEPARATOR ', '
              ) AS org_label
          FROM users u
          LEFT JOIN user_org_assignments oa ON oa.user_id = u.id
          LEFT JOIN companies c ON c.id = oa.company_id AND c.is_active = 1
          LEFT JOIN departments d ON d.id = oa.department_id AND d.is_active = 1
          GROUP BY u.id, u.username, u.nickname, u.position, u.phone, u.email
          ORDER BY 
          FIELD(u.position, '사장', '전무', '상무', '이사', '부장', '차장', '과장', '대리', '주임', '사원'), 
          u.nickname ASC";
$users = $conn->query($query)->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>임직원 주소록</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="assets/groupware-shell.css?v=2">
<style>body { font-family: 'Malgun Gothic', sans-serif; }</style>
</head>
<body class="gw-body min-h-screen pb-10">

    <?php smw_render_shell_header('people', '사내 임직원 주소록'); ?>

    <div class="max-w-6xl mx-auto mt-8 px-4">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
            <?php foreach($users as $u): ?>
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden hover:shadow-md transition">
                <div class="h-16 bg-slate-800"></div>
                <div class="px-5 pb-5 relative">
                    <div class="w-16 h-16 bg-teal-100 text-teal-600 rounded-full flex items-center justify-center text-2xl font-bold border-4 border-white absolute -top-8 left-5 shadow-sm">
                        <?= mb_substr($u['nickname'], 0, 1, 'utf-8') ?>
                    </div>
                    <div class="pt-10">
                        <div class="flex items-baseline justify-between mb-1">
                            <h2 class="text-lg font-bold text-gray-900"><?= htmlspecialchars($u['nickname']) ?></h2>
                            <span class="text-xs font-bold bg-slate-100 text-slate-600 px-2 py-1 rounded border border-slate-200"><?= htmlspecialchars($u['position']) ?></span>
                        </div>
                        <p class="text-sm text-gray-500 mb-2">ID: <?= htmlspecialchars($u['username']) ?></p>
                        <?php if (!empty($u['org_label'])): ?>
                            <p class="text-xs text-teal-700 bg-teal-50 border border-teal-100 rounded px-2 py-1 mb-4">
                                <i class="fa-solid fa-building mr-1"></i><?= htmlspecialchars($u['org_label']) ?>
                            </p>
                        <?php else: ?>
                            <p class="text-xs text-gray-400 mb-4">소속 미지정</p>
                        <?php endif; ?>
                        
                        <div class="space-y-2 text-sm text-gray-700">
                            <div class="flex items-center gap-2">
                                <i class="fa-solid fa-phone text-gray-400 w-4"></i>
                                <span><?= !empty($u['phone']) ? htmlspecialchars($u['phone']) : '<span class="text-gray-300">미등록</span>' ?></span>
                            </div>
                            <div class="flex items-center gap-2">
                                <i class="fa-solid fa-envelope text-gray-400 w-4"></i>
                                <span><?= !empty($u['email']) ? htmlspecialchars($u['email']) : '<span class="text-gray-300">미등록</span>' ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</body>
</html>

<?php
session_start();
error_reporting(0);
ini_set('display_errors', 0);
include 'db_conn.php';
require_once 'groupware_shell.php';

$user = smw_current_user($conn);
if (!$user) {
    header('Location: login.php');
    exit;
}
if ((int)$user['is_admin'] !== 1) {
    http_response_code(403);
    exit('시스템 관리자 권한이 필요합니다.');
}

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;
$totalResult = $conn->query('SELECT COUNT(*) AS total FROM audit_logs');
$total = $totalResult ? (int)$totalResult->fetch_assoc()['total'] : 0;
$logs = [];
$result = $conn->query(
    "SELECT l.*, u.nickname AS actor_name, u.username AS actor_username
     FROM audit_logs l
     LEFT JOIN users u ON u.id=l.actor_user_id
     ORDER BY l.created_at DESC, l.id DESC
     LIMIT {$perPage} OFFSET {$offset}"
);
if ($result) {
    $logs = $result->fetch_all(MYSQLI_ASSOC);
}
$totalPages = max(1, (int)ceil($total / $perPage));
$portalIdentity = smw_portal_identity($conn);

$actionLabels = [
    'login.success' => '로그인',
    'profile.update' => '내 정보 변경',
    'user.register' => '계정 생성',
    'user.update' => '계정 변경',
    'user.delete' => '계정 삭제',
    'settings.update' => '설정 변경',
    'api_settings.update' => 'API 설정',
    'company.save' => '회사 변경',
    'department.save' => '사업부 변경',
    'organization.assign' => '소속 지정',
    'organization.unassign' => '소속 해제',
    'relation.create' => '업무 관계 연결',
    'relation.delete' => '업무 관계 해제',
    'approval_template.create' => '결재선 생성',
    'approval_template.delete' => '결재선 삭제',
];
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>감사 로그 - <?= smw_h($portalIdentity['name']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/groupware-shell.css?v=3">
    <style>
        @media (max-width: 640px) {
            .audit-table { width: 100% !important; min-width: 0 !important; }
            .audit-table thead { display: none; }
            .audit-table tbody { display: grid; gap: 12px; padding: 12px; background: #f8fafc; }
            .audit-table tr { display: block; border: 1px solid #e2e8f0; border-radius: 12px; background: #fff; overflow: hidden; }
            .audit-table td { display: grid; grid-template-columns: 76px minmax(0, 1fr); gap: 10px; padding: 10px 14px; white-space: normal; border-bottom: 1px solid #f1f5f9; }
            .audit-table td:last-child { border-bottom: 0; }
            .audit-table td::before { content: attr(data-label); color: #64748b; font-size: 12px; font-weight: 700; }
        }
    </style>
</head>
<body class="gw-body min-h-screen pb-10">
    <?php smw_render_shell_header('audit', '관리 변경 이력', true, (string)$user['nickname']); ?>
    <main class="max-w-7xl mx-auto px-4 py-8">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between mb-6">
            <div>
                <p class="text-sm font-bold text-blue-700">보안과 운영 투명성</p>
                <h1 class="mt-1 text-2xl font-black text-slate-900">감사 로그</h1>
                <p class="mt-2 text-sm text-slate-500">로그인과 관리자 설정 변경을 최신 순으로 확인합니다. 비밀번호와 API 키 값은 기록하지 않습니다.</p>
            </div>
            <span class="inline-flex items-center rounded-full bg-blue-50 px-3 py-1 text-sm font-bold text-blue-700">총 <?= number_format($total) ?>건</span>
        </div>

        <section class="gw-surface overflow-hidden rounded-xl bg-white" aria-label="관리 변경 이력">
            <?php if (!$logs): ?>
                <div class="px-6 py-16 text-center">
                    <i class="fa-solid fa-shield-halved text-4xl text-slate-300" aria-hidden="true"></i>
                    <h2 class="mt-4 font-bold text-slate-800">아직 기록된 변경이 없습니다</h2>
                    <p class="mt-1 text-sm text-slate-500">관리 설정을 변경하거나 사용자가 로그인하면 여기에 기록됩니다.</p>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="audit-table w-full min-w-[760px] text-left text-sm">
                        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                            <tr><th class="px-5 py-3">시각</th><th class="px-5 py-3">작업</th><th class="px-5 py-3">실행자</th><th class="px-5 py-3">내용</th><th class="px-5 py-3">대상</th></tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php foreach ($logs as $log): ?>
                                <tr class="hover:bg-slate-50">
                                    <td data-label="시각" class="whitespace-nowrap px-5 py-4 text-slate-500"><?= smw_h(date('Y-m-d H:i', strtotime($log['created_at']))) ?></td>
                                    <td data-label="작업" class="px-5 py-4"><span class="rounded-full bg-blue-50 px-2.5 py-1 text-xs font-bold text-blue-700"><?= smw_h($actionLabels[$log['action']] ?? $log['action']) ?></span></td>
                                    <td data-label="실행자" class="px-5 py-4"><span><strong class="block text-slate-800"><?= smw_h($log['actor_name'] ?: '시스템') ?></strong><small class="text-slate-400"><?= smw_h($log['actor_username'] ?: '-') ?></small></span></td>
                                    <td data-label="내용" class="px-5 py-4 text-slate-700"><?= smw_h($log['summary']) ?></td>
                                    <td data-label="대상" class="whitespace-nowrap px-5 py-4 text-slate-500"><?= smw_h($log['target_type']) ?><?= $log['target_id'] !== null ? ' #' . (int)$log['target_id'] : '' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <?php if ($totalPages > 1): ?>
            <nav class="mt-6 flex items-center justify-center gap-3" aria-label="감사 로그 페이지">
                <?php if ($page > 1): ?><a class="min-h-11 rounded-lg border border-slate-300 bg-white px-4 py-2.5 font-bold text-slate-700 hover:bg-slate-50" href="?page=<?= $page - 1 ?>">이전</a><?php endif; ?>
                <span class="text-sm text-slate-500"><?= $page ?> / <?= $totalPages ?></span>
                <?php if ($page < $totalPages): ?><a class="min-h-11 rounded-lg border border-slate-300 bg-white px-4 py-2.5 font-bold text-slate-700 hover:bg-slate-50" href="?page=<?= $page + 1 ?>">다음</a><?php endif; ?>
            </nav>
        <?php endif; ?>
    </main>
</body>
</html>

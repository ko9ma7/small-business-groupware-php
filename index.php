<?php
// 파일명: /smw/index.php
session_start();
error_reporting(0); ini_set('display_errors', 0);
include 'db_conn.php'; 

// ★ [500 에러 원천 차단] DB 패치 중 오류가 나더라도 서버가 뻗지 않게 try-catch로 방어합니다.
try {
    $chk1 = $conn->query("SHOW COLUMNS FROM users LIKE 'birth_type'");
    if ($chk1 && $chk1->num_rows == 0) $conn->query("ALTER TABLE users ADD COLUMN birth_type VARCHAR(10) DEFAULT 'solar'");

    $chk2 = $conn->query("SHOW COLUMNS FROM e_approvals LIKE 'assigned_at'");
    if ($chk2 && $chk2->num_rows == 0) $conn->query("ALTER TABLE e_approvals ADD COLUMN assigned_at TIMESTAMP NULL DEFAULT NULL");

    $conn->query("CREATE TABLE IF NOT EXISTS site_settings (setting_key VARCHAR(50) PRIMARY KEY, setting_value VARCHAR(255) NOT NULL)");
    $conn->query("INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES ('approval_timeout', '0')");

    // ★ 500 에러의 주범이었던 TEXT DEFAULT '' 문법을 호환성 높은 VARCHAR(500)으로 변경
    $conn->query("CREATE TABLE IF NOT EXISTS task_comments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        task_id INT NOT NULL,
        user_id INT NOT NULL,
        content LONGTEXT NOT NULL,
        read_by VARCHAR(500) DEFAULT '',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $chk3 = $conn->query("SHOW COLUMNS FROM task_comments LIKE 'read_by'");
    if ($chk3 && $chk3->num_rows == 0) $conn->query("ALTER TABLE task_comments ADD COLUMN read_by VARCHAR(500) DEFAULT ''");
} catch (Throwable $e) {
    // DB 세팅 중 충돌이 나도 무시하고 페이지를 정상 출력시킵니다.
}

$timeout_days = 0;
$t_res = $conn->query("SELECT setting_value FROM site_settings WHERE setting_key='approval_timeout'");
if ($t_res && $t_res->num_rows > 0) $timeout_days = (int)$t_res->fetch_assoc()['setting_value'];

if ($timeout_days > 0) {
    $expired = $conn->query("SELECT id, document_id FROM e_approvals WHERE status = 'pending' AND assigned_at IS NOT NULL AND assigned_at < DATE_SUB(NOW(), INTERVAL $timeout_days DAY)");
    if ($expired && $expired->num_rows > 0) {
        while($e_app = $expired->fetch_assoc()) {
            $aid = $e_app['id']; $did = $e_app['document_id'];
            $conn->query("UPDATE e_approvals SET status='rejected', comment='⏱️ 결재 대기 기한 초과로 인한 시스템 자동 반려', processed_at=NOW() WHERE id=$aid");
            $conn->query("UPDATE e_documents SET status='rejected' WHERE id=$did");
        }
    }
}

if (!isset($_SESSION['uid'])) { header("Location: login.php"); exit; }
$uid = (int)$_SESSION['uid'];

$u_res = $conn->query("SELECT * FROM users WHERE id = $uid");
if($u_res && $u_res->num_rows > 0) { $u_info = $u_res->fetch_assoc(); } else { header("Location: logout.php"); exit; }

require_once 'smw_extensions.php';
require_once 'groupware_shell.php';
$portal_identity = smw_portal_identity($conn);
$_SESSION['admin'] = $u_info['is_admin'];
$is_admin = (int)$u_info['is_admin']; 
$position = htmlspecialchars($u_info['position'] ?? '사원');

$pending_approvals = [];
$pending_query = "SELECT d.id, d.title, u.nickname as author_name, a.step_order, d.created_at FROM e_approvals a JOIN e_documents d ON a.document_id = d.id JOIN users u ON d.author_id = u.id WHERE a.approver_id = $uid AND a.status = 'pending' AND d.status = 'pending' ORDER BY a.id ASC";
$p_res = $conn->query($pending_query);
if ($p_res) $pending_approvals = $p_res->fetch_all(MYSQLI_ASSOC);

$recent_comments = [];
$comment_noti_query = "
    SELECT t.id as task_id, t.plan_content, t.company_name, MAX(c.created_at) as last_comment_time, COUNT(c.id) as unread_count
    FROM task_comments c
    JOIN report_tasks t ON c.task_id = t.id
    WHERE c.user_id != $uid 
      AND IFNULL(c.read_by,'') NOT LIKE '%,$uid,%'
      AND (
          t.user_id = $uid 
          OR t.id IN (SELECT DISTINCT task_id FROM task_comments WHERE user_id = $uid)
      )
    GROUP BY t.id, t.plan_content, t.company_name
    ORDER BY last_comment_time DESC LIMIT 5
";
$c_res = $conn->query($comment_noti_query);
if ($c_res) $recent_comments = $c_res->fetch_all(MYSQLI_ASSOC);

$birthdays = [];
$schedules = [];
$current_month = date('m');

$b_res = $conn->query("SELECT nickname, position, birth_date, birth_type FROM users WHERE MONTH(birth_date) = '$current_month' ORDER BY DAY(birth_date) ASC");
if ($b_res) $birthdays = $b_res->fetch_all(MYSQLI_ASSOC);

$s_res = $conn->query("SELECT title, start_date, end_date FROM schedules WHERE MONTH(start_date) = '$current_month' OR MONTH(end_date) = '$current_month' ORDER BY start_date ASC");
if ($s_res) $schedules = $s_res->fetch_all(MYSQLI_ASSOC);

$dashboard_tasks = [];
$dashboard_task_query = "SELECT t.id, t.target_date, t.company_name, t.plan_content, t.task_category,
                                u.nickname AS target_name, m.created_by
                         FROM report_tasks t
                         JOIN users u ON u.id=t.user_id
                         LEFT JOIN report_task_meta m ON m.task_id=t.id
                         WHERE t.user_id=$uid
                         ORDER BY t.id DESC LIMIT 6";
$dashboard_task_res = $conn->query($dashboard_task_query);
if ($dashboard_task_res) $dashboard_tasks = $dashboard_task_res->fetch_all(MYSQLI_ASSOC);

$today_tasks = [];
$today_task_query = "SELECT t.id, t.target_date, t.company_name, t.plan_content, t.task_category,
                            u.nickname AS target_name, m.created_by
                     FROM report_tasks t
                     JOIN users u ON u.id=t.user_id
                     LEFT JOIN report_task_meta m ON m.task_id=t.id
                     WHERE t.user_id=$uid AND t.target_date=CURDATE()
                     ORDER BY t.id";
$today_task_res = $conn->query($today_task_query);
if ($today_task_res) $today_tasks = $today_task_res->fetch_all(MYSQLI_ASSOC);

$week_start = date('Y-m-d', strtotime('monday this week'));
$week_end = date('Y-m-d', strtotime($week_start . ' +6 days'));
$week_task_counts = [];
$week_count_res = $conn->query(
    "SELECT t.target_date, COUNT(DISTINCT t.id) AS total FROM report_tasks t
     LEFT JOIN report_task_meta m ON m.task_id=t.id
     WHERE t.user_id=$uid AND t.target_date BETWEEN '$week_start' AND '$week_end'
     GROUP BY t.target_date"
);
if ($week_count_res) while ($row = $week_count_res->fetch_assoc()) $week_task_counts[$row['target_date']] = (int)$row['total'];

$today_label = date('Y년 n월 j일');
$weekday_labels = ['일요일','월요일','화요일','수요일','목요일','금요일','토요일'];
$today_weekday = $weekday_labels[(int)date('w')];
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8"><title><?= smw_h($portal_identity['name']) ?> 대시보드</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://uicdn.toast.com/editor/latest/toastui-editor.min.css" />
    <link rel="stylesheet" href="assets/dashboard.css?v=4">
    <link rel="stylesheet" href="assets/groupware-shell.css?v=2">
    <style>
        body { font-family: 'Malgun Gothic', sans-serif; }
        .toast-content p { display: inline; }
        .toast-content img { display: none; }
    </style>
</head>
<body class="bg-slate-100 min-h-screen dashboard-body">
    <?php smw_render_shell_header('dashboard', $portal_identity['companies'], $is_admin === 1, (string)$u_info['nickname']); ?>

    <main class="max-w-7xl mx-auto px-4 py-7">
        <section class="dash-hero">
            <div><p class="dash-eyebrow"><?= $today_label ?> <?= $today_weekday ?></p><h1><?= htmlspecialchars($u_info['nickname']) ?>님, 오늘 업무를 확인하세요.</h1><p>세 회사의 업무 보고, 결재, 일정과 조직 정보를 한 곳에서 관리합니다.</p></div>
            <a href="daily.php" class="dash-primary-action"><i class="fa-solid fa-pen-to-square"></i><span><b>일일 업무 작성</b><small>내 업무 또는 작업자 구분 기록</small></span></a>
        </section>

        <section class="dash-stats" aria-label="업무 현황 요약">
            <button type="button" onclick="toggleTodayTracker(true)" class="dash-stat text-left"><span class="dash-stat-icon blue"><i class="fa-solid fa-list-check"></i></span><span><small>오늘 업무</small><b><?= count($today_tasks) ?>건</b></span></button>
            <a href="approval_list.php" class="dash-stat"><span class="dash-stat-icon amber"><i class="fa-solid fa-file-circle-check"></i></span><span><small>내 결재 대기</small><b><?= count($pending_approvals) ?>건</b></span></a>
            <a href="#unreadCommentPanel" class="dash-stat"><span class="dash-stat-icon teal"><i class="fa-solid fa-comments"></i></span><span><small>읽지 않은 코멘트</small><b><?= array_sum(array_column($recent_comments, 'unread_count')) ?>건</b></span></a>
            <a href="schedule.php" class="dash-stat"><span class="dash-stat-icon violet"><i class="fa-solid fa-calendar-days"></i></span><span><small>이번 달 일정</small><b><?= count($schedules) ?>건</b></span></a>
        </section>

        <section class="dash-panel dash-services-compact mb-6">
            <div class="dash-panel-head"><div><span class="dash-section-kicker">WORKSPACE</span><h2>업무 서비스</h2></div><span class="text-xs text-slate-500">상단 메뉴에서도 바로 이동할 수 있습니다.</span></div>
            <div class="dash-service-grid">
                <a href="daily.php" class="dash-service"><span class="dash-service-icon blue"><i class="fa-solid fa-pen-to-square"></i></span><span><b>일일 업무 관리</b><small>내 업무·작업자 구분 작성</small></span><i class="fa-solid fa-chevron-right"></i></a>
                <a href="weekly_presentation.php" class="dash-service"><span class="dash-service-icon green"><i class="fa-solid fa-chart-column"></i></span><span><b>주간 회의 보드</b><small>회사·사업부 주간 현황</small></span><i class="fa-solid fa-chevron-right"></i></a>
                <a href="approval_draft.php" class="dash-service"><span class="dash-service-icon amber"><i class="fa-solid fa-file-signature"></i></span><span><b>전자결재 작성</b><small>품의·기안·연차 상신</small></span><i class="fa-solid fa-chevron-right"></i></a>
                <a href="approval_list.php" class="dash-service"><span class="dash-service-icon orange"><i class="fa-solid fa-box-archive"></i></span><span><b>결재 진행·보관함</b><small>처리 상태와 완료 문서</small></span><i class="fa-solid fa-chevron-right"></i></a>
                <a href="board.php" class="dash-service"><span class="dash-service-icon violet"><i class="fa-solid fa-clipboard-list"></i></span><span><b>사내 게시판</b><small>공지와 업무 공유</small></span><i class="fa-solid fa-chevron-right"></i></a>
                <a href="address_book.php" class="dash-service"><span class="dash-service-icon teal"><i class="fa-solid fa-address-book"></i></span><span><b>임직원 주소록</b><small>회사·사업부별 연락처</small></span><i class="fa-solid fa-chevron-right"></i></a>
                <a href="schedule.php" class="dash-service"><span class="dash-service-icon blue"><i class="fa-solid fa-calendar-days"></i></span><span><b>사내 일정</b><small>월간 일정 등록·확인</small></span><i class="fa-solid fa-chevron-right"></i></a>
                <a href="task_history.php" class="dash-service"><span class="dash-service-icon green"><i class="fa-solid fa-clock-rotate-left"></i></span><span><b>업무 기록 조회</b><small>기간별 작성 업무 확인</small></span><i class="fa-solid fa-chevron-right"></i></a>
                <?php if($is_admin == 1): ?><a href="organization_admin.php" class="dash-service"><span class="dash-service-icon slate"><i class="fa-solid fa-sitemap"></i></span><span><b>회사·사업부 관리</b><small>인원·보고관계·결재선</small></span><i class="fa-solid fa-chevron-right"></i></a><?php endif; ?>
            </div>
        </section>

        <div class="flex flex-col lg:flex-row gap-6">
        <div class="w-full lg:w-3/4">
            <?php if(count($pending_approvals) > 0): ?>
            <div class="mb-6 bg-amber-50 border-l-4 border-amber-500 p-4 rounded shadow-sm">
                <h3 class="text-amber-800 font-bold text-lg mb-3"><i class="fa-solid fa-bell animate-bounce mr-2"></i>처리 대기중인 결재 문서 (<?= count($pending_approvals) ?>건)</h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <?php foreach($pending_approvals as $doc): ?>
                    <a href="approval_process.php?id=<?= $doc['id'] ?>" class="block bg-white p-3 rounded border hover:shadow-md transition">
                        <div class="text-xs text-gray-500 mb-1"><?= substr($doc['created_at'], 0, 10) ?> | 기안: <?= htmlspecialchars($doc['author_name']) ?></div>
                        <div class="font-bold text-gray-800 truncate"><?= htmlspecialchars($doc['title']) ?></div>
                        <div class="text-sm text-amber-600 mt-2 font-bold">내 결재 차례 (<?= $doc['step_order'] ?>차) 바로가기 &rarr;</div>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if(count($recent_comments) > 0): ?>
            <div class="mb-6 bg-teal-50 border-l-4 border-teal-500 p-4 rounded shadow-sm" id="unreadCommentPanel">
                <h3 class="text-teal-800 font-bold text-lg mb-3"><i class="fa-solid fa-comment-dots animate-pulse mr-2"></i>읽지 않은 새 코멘트가 있습니다!</h3>
                <div class="space-y-2">
                    <?php foreach($recent_comments as $c): ?>
                    <button onclick="openDashboardComment(<?= $c['task_id'] ?>, '[<?= htmlspecialchars($c['company_name']) ?>] <?= addslashes(htmlspecialchars($c['plan_content'])) ?>')" 
                            class="w-full text-left bg-white p-3 rounded border border-teal-200 hover:border-teal-400 hover:shadow-md transition flex flex-col sm:flex-row justify-between items-center group">
                        <div class="flex-grow pr-4">
                            <div class="font-bold text-gray-800 truncate max-w-full group-hover:text-teal-700 transition">
                                [<?= htmlspecialchars($c['company_name']) ?>] <?= htmlspecialchars($c['plan_content']) ?>
                            </div>
                        </div>
                        <div class="text-sm font-bold text-red-500 mt-2 sm:mt-0 whitespace-nowrap bg-red-50 px-3 py-1 rounded-full border border-red-100">
                            <i class="fa-solid fa-bell"></i> <?= $c['unread_count'] ?>개의 새 코멘트 확인 &rarr;
                        </div>
                    </button>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <section class="dash-panel">
                <div class="dash-panel-head"><div><span class="dash-section-kicker">RECENT</span><h2>최근 작성 업무</h2></div><a href="daily.php" class="dash-text-link">전체 보기 <i class="fa-solid fa-arrow-right"></i></a></div>
                <div class="dash-recent-list">
                    <?php if(empty($dashboard_tasks)): ?><div class="dash-empty">최근 작성한 업무가 없습니다.</div><?php else: foreach($dashboard_tasks as $task): ?>
                        <a href="daily.php" class="dash-recent-item"><span class="dash-date"><?= date('m.d', strtotime($task['target_date'])) ?></span><span class="min-w-0 flex-1"><b><?= htmlspecialchars($task['plan_content']) ?></b><small><?= htmlspecialchars($task['target_name']) ?> · <?= htmlspecialchars($task['company_name']) ?> · <?= htmlspecialchars($task['task_category']) ?></small></span><i class="fa-solid fa-chevron-right"></i></a>
                    <?php endforeach; endif; ?>
                </div>
            </section>
        </div>

        <div class="w-full lg:w-1/4 space-y-6">
            <div class="bg-white rounded-xl shadow-sm border overflow-hidden">
                <div class="bg-pink-50 border-b px-4 py-3 font-bold text-pink-800"><span><i class="fa-solid fa-cake-candles mr-2"></i> <?= $current_month ?>월 생일자</span></div>
                <ul class="p-4 space-y-3">
                    <?php if(empty($birthdays)): ?><li class="text-sm text-gray-400 text-center">생일자가 없습니다.</li>
                    <?php else: foreach($birthdays as $b): ?>
                        <li class="flex items-center gap-3"><div class="bg-pink-100 text-pink-600 w-10 h-10 rounded-full flex items-center justify-center font-bold text-sm"><?= date('d', strtotime($b['birth_date'])) ?>일</div><div><div class="font-bold text-sm"><?= htmlspecialchars($b['nickname']) ?> <span class="text-xs text-pink-500 font-bold ml-1"><?= $b['birth_type'] == 'lunar' ? '(음)' : '(양)' ?></span></div><div class="text-xs text-gray-400"><?= htmlspecialchars($b['position']) ?></div></div></li>
                    <?php endforeach; endif; ?>
                </ul>
            </div>
            <div class="bg-white rounded-xl shadow-sm border overflow-hidden">
                <div class="bg-blue-50 border-b px-4 py-3 font-bold text-blue-800 flex justify-between"><span><i class="fa-solid fa-calendar-days mr-2"></i> <?= $current_month ?>월 사내 일정</span><a href="schedule.php" class="text-xs hover:underline">관리</a></div>
                <ul class="p-4 space-y-3">
                    <?php if(empty($schedules)): ?><li class="text-sm text-gray-400 text-center">일정이 없습니다.</li>
                    <?php else: foreach($schedules as $s): ?><li class="text-sm"><div class="font-bold text-blue-700"><?= date('m.d', strtotime($s['start_date'])) ?> ~ <?= date('m.d', strtotime($s['end_date'])) ?></div><div class="text-gray-700 mt-1"><?= htmlspecialchars($s['title']) ?></div></li><?php endforeach; endif; ?>
                </ul>
            </div>
            <section class="dash-panel dash-side-shortcuts">
                <div class="dash-panel-head"><div><span class="dash-section-kicker">QUICK</span><h2>빠른 이동</h2></div></div>
                <div><a href="daily.php"><i class="fa-solid fa-pen-to-square blue"></i><span><b>일일 업무 작성</b><small>작업자별 기록 포함</small></span></a><a href="approval_list.php"><i class="fa-solid fa-file-signature amber"></i><span><b>전자결재</b><small>진행·보관함</small></span></a><a href="board.php"><i class="fa-solid fa-clipboard-list violet"></i><span><b>사내 게시판</b><small>공지·업무 공유</small></span></a><a href="address_book.php"><i class="fa-solid fa-address-book teal"></i><span><b>임직원 주소록</b><small>연락처 확인</small></span></a></div>
            </section>
            <section class="dash-panel dash-week-panel">
                <div class="dash-panel-head"><div><span class="dash-section-kicker">THIS WEEK</span><h2>이번 주 업무 분포</h2></div><span class="text-xs text-slate-500"><?= array_sum($week_task_counts) ?>건</span></div>
                <div class="dash-week-days">
                    <?php $short_days=['월','화','수','목','금','토','일']; for($i=0;$i<7;$i++): $day=date('Y-m-d',strtotime($week_start." +$i days")); $count=$week_task_counts[$day]??0; ?>
                    <a href="daily.php" class="dash-week-day <?= $day===date('Y-m-d')?'today':'' ?>"><span><b><?= $short_days[$i] ?></b><small><?= date('m.d',strtotime($day)) ?></small></span><strong><?= $count ?></strong></a>
                    <?php endfor; ?>
                </div>
                <div class="dash-week-actions"><a href="daily.php"><i class="fa-solid fa-pen"></i> 업무 작성</a><a href="weekly_presentation.php"><i class="fa-solid fa-chart-column"></i> 주간 보고</a></div>
            </section>
        </div>
        </div>
    </main>

    <aside id="todayTracker" class="dash-today-tracker" aria-label="오늘 업무 추적 바">
        <button type="button" class="dash-tracker-toggle" onclick="toggleTodayTracker()" aria-expanded="false" aria-controls="todayTrackerBody"><span class="dash-tracker-icon"><i class="fa-solid fa-list-check"></i></span><span class="dash-tracker-copy"><b>오늘 업무 <?= count($today_tasks) ?>건</b><small><?= empty($today_tasks)?'오늘 등록된 업무가 없습니다.':htmlspecialchars($today_tasks[0]['plan_content']) ?></small></span><i class="fa-solid fa-chevron-up dash-tracker-chevron"></i></button>
        <div id="todayTrackerBody" class="dash-tracker-body" hidden>
            <div class="dash-tracker-head"><strong>오늘 하기로 한 업무</strong><a href="daily.php">업무 작성</a></div>
            <?php if(empty($today_tasks)): ?><div class="dash-tracker-empty">등록된 업무가 없습니다.</div><?php else: ?>
            <div class="dash-tracker-list"><?php foreach($today_tasks as $task): ?><a href="daily.php"><span><i class="fa-solid fa-check"></i></span><div><b><?= htmlspecialchars($task['plan_content']) ?></b><small><?= htmlspecialchars($task['company_name']) ?> · <?= htmlspecialchars($task['task_category']) ?></small></div></a><?php endforeach; ?></div>
            <?php endif; ?>
        </div>
    </aside>

    <div id="commentModal" class="fixed inset-0 bg-black bg-opacity-70 hidden flex justify-center items-center z-[9999] p-4">
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-3xl flex flex-col h-[85vh] overflow-hidden">
            <div class="p-4 border-b bg-teal-600 text-white flex justify-between items-center">
                <h3 class="font-bold text-lg"><i class="fa-solid fa-comments mr-2"></i>업무 코멘트: <span id="commentTaskTitle" class="text-teal-200 text-sm"></span></h3>
                <button onclick="closeCommentModal()" class="text-white hover:text-gray-200 text-xl"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div id="commentList" class="flex-grow overflow-y-auto p-6 bg-gray-50 space-y-4"></div>
            <div class="p-4 border-t bg-white">
                <input type="hidden" id="commentTaskId">
                <div id="commentEditor" class="mb-2" style="height:200px;"></div>
                <div class="flex justify-end"><button onclick="submitComment()" class="bg-teal-600 text-white px-6 py-2 rounded font-bold shadow-md hover:bg-teal-700">코멘트 남기기</button></div>
            </div>
        </div>
    </div>

    <script src="https://uicdn.toast.com/editor/latest/toastui-editor-all.min.js"></script>
    <script>
        function toggleTodayTracker(forceOpen = false) {
            const tracker = document.getElementById('todayTracker');
            const body = document.getElementById('todayTrackerBody');
            const shouldOpen = forceOpen || !tracker.classList.contains('open');
            tracker.classList.toggle('open', shouldOpen);
            body.hidden = !shouldOpen;
            tracker.querySelector('.dash-tracker-toggle').setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');
        }

        const cEditor = new toastui.Editor({
            el: document.querySelector('#commentEditor'), height: '200px', initialEditType: 'wysiwyg', toolbarItems: [['bold', 'italic', 'strike'], ['image', 'link']],
            hooks: { addImageBlobHook: async (blob, callback) => { const fd = new FormData(); fd.append('file', blob); try { const res = await fetch('upload_image.php', {method:'POST', body:fd}).then(r=>r.json()); if(res.success) callback(res.url, 'Image'); } catch(e) {} } }
        });

        let currentTaskId = 0;
        let needsRefresh = false;

        function openDashboardComment(taskId, title) { 
            currentTaskId = taskId; 
            needsRefresh = true; 
            document.getElementById('commentTaskId').value = taskId; 
            document.getElementById('commentTaskTitle').innerText = title; 
            document.getElementById('commentModal').classList.remove('hidden'); 
            loadComments(); 
        }

        function closeCommentModal() { 
            document.getElementById('commentModal').classList.add('hidden'); 
            cEditor.setHTML(''); 
            if(needsRefresh) location.reload(); 
        }

        async function loadComments() {
            const fd = new FormData(); fd.append('action', 'load'); fd.append('task_id', currentTaskId);
            const res = await fetch('task_comment_api.php', { method: 'POST', body: fd }).then(r => r.json());
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
            const content = cEditor.getHTML(); if(!content || content === '<p><br></p>') { alert('내용을 입력하세요.'); return; }
            const fd = new FormData(); fd.append('action', 'add'); fd.append('task_id', currentTaskId); fd.append('content', content);
            const res = await fetch('task_comment_api.php', { method: 'POST', body: fd }).then(r => r.json());
            if(res.success) { cEditor.setHTML(''); loadComments(); }
        }

        async function deleteComment(cid) { if(!confirm('삭제?')) return; const fd = new FormData(); fd.append('action', 'delete'); fd.append('comment_id', cid); await fetch('task_comment_api.php', { method: 'POST', body: fd }); loadComments(); }
    </script>
</body>
</html>

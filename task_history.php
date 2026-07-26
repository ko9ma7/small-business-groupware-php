<?php
// 파일명: /smw/task_history.php
session_start();
include 'db_conn.php';
if (!isset($_SESSION['uid'])) { header("Location: login.php"); exit; }
require_once 'smw_extensions.php';
require_once 'groupware_shell.php';

$uid = (int)$_SESSION['uid'];

// 검색 필터링 (기본값: 이번 달)
$search_month = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['month'] ?? ''))
    ? (string)$_GET['month']
    : date('Y-m');
$search_keyword = isset($_GET['keyword']) ? $conn->real_escape_string($_GET['keyword']) : '';
$month_start = $search_month . '-01';
$month_end = date('Y-m-t', strtotime($month_start));

$where_clause = "r.user_id = $uid
    AND (
        DATE_FORMAT(r.target_date, '%Y-%m') = '$search_month'
        OR (m.period_start <= '$month_end' AND m.period_end >= '$month_start')
    )";
if (!empty($search_keyword)) {
    $where_clause .= " AND (r.company_name LIKE '%$search_keyword%' OR r.plan_content LIKE '%$search_keyword%' OR r.result_content LIKE '%$search_keyword%')";
}

$history_query = "SELECT r.*, m.input_mode, m.period_start, m.period_end
                  FROM report_tasks r
                  LEFT JOIN report_task_meta m ON m.task_id = r.id
                  WHERE $where_clause
                  ORDER BY r.target_date DESC, r.id DESC";
$history_data = $conn->query($history_query)->fetch_all(MYSQLI_ASSOC);

// 첨부파일 매핑
$attachments_map = [];
$att_res = $conn->query("SELECT * FROM attachments WHERE reference_type = 'task'");
while($att = $att_res->fetch_assoc()) {
    $attachments_map[$att['reference_id']][] = $att;
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>과거 업무 이력 보관함</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="assets/groupware-shell.css?v=2">
<style>body { font-family: 'Malgun Gothic', sans-serif; }</style>
</head>
<body class="gw-body min-h-screen pb-10">

    <?php smw_render_shell_header('daily', '과거 업무 이력'); ?>

    <div class="max-w-6xl mx-auto mt-8 px-4">
        
        <div class="bg-white p-5 rounded-xl shadow-sm border border-gray-200 mb-6 flex flex-col sm:flex-row gap-4 items-end">
            <form method="GET" class="w-full flex flex-col sm:flex-row gap-4 items-end">
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">조회 월 선택</label>
                    <input type="month" name="month" value="<?= $search_month ?>" class="p-2 border rounded focus:ring-2 focus:ring-blue-500">
                </div>
                <div class="flex-grow">
                    <label class="block text-sm font-bold text-gray-700 mb-1">키워드 검색 (프로젝트명, 내용)</label>
                    <input type="text" name="keyword" value="<?= htmlspecialchars($search_keyword) ?>" placeholder="검색어 입력..." class="w-full p-2 border rounded focus:ring-2 focus:ring-blue-500">
                </div>
                <button type="submit" class="bg-slate-800 text-white font-bold px-6 py-2 rounded shadow hover:bg-slate-900 h-[42px]"><i class="fa-solid fa-magnifying-glass mr-1"></i> 조회</button>
            </form>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
            <table class="w-full text-sm text-left text-gray-600">
                <thead class="text-xs text-gray-700 uppercase bg-gray-100 border-b">
                    <tr><th class="px-4 py-3 w-28">일자</th><th class="px-4 py-3 w-20 text-center">구분</th><th class="px-4 py-3 w-40">프로젝트명</th><th class="px-4 py-3">업무 요약 및 상세 내용</th></tr>
                </thead>
                <tbody>
                    <?php if(empty($history_data)): ?>
                        <tr><td colspan="4" class="text-center py-12 text-gray-400 font-bold"><i class="fa-regular fa-folder-open text-2xl mb-2 block"></i>해당 월에 등록된 데이터가 없습니다.</td></tr>
                    <?php else: ?>
                        <?php foreach($history_data as $row): ?>
                        <tr class="border-b hover:bg-gray-50 transition">
                            <td class="px-4 py-3 font-bold text-gray-500">
                                <?php if(in_array($row['input_mode'] ?? '', ['weekly', 'monthly'], true)): ?>
                                    <?= htmlspecialchars($row['period_start']) ?><br>
                                    <span class="text-[11px] font-normal text-gray-400">~ <?= htmlspecialchars($row['period_end']) ?> · <?= $row['input_mode']==='weekly'?'주간':'월간' ?></span>
                                <?php else: ?>
                                    <?= htmlspecialchars($row['target_date']) ?>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <?= $row['task_type'] === 'plan' ? '<span class="text-amber-600 bg-amber-100 px-2 py-0.5 rounded text-[11px] font-bold">계획</span>' : '<span class="text-emerald-600 bg-emerald-100 px-2 py-0.5 rounded text-[11px] font-bold">실적</span>' ?>
                            </td>
                            <td class="px-4 py-3 font-bold text-blue-800">[<?= htmlspecialchars($row['company_name']) ?>]</td>
                            <td class="px-4 py-4">
                                <div class="font-bold text-gray-800 text-base mb-1"><?= htmlspecialchars($row['plan_content']) ?></div>
                                <?php if(!empty($row['result_content'])): ?>
                                    <div class="text-sm text-gray-600 bg-gray-50 p-3 rounded border border-gray-100 mt-2 leading-relaxed">
                                        <?= $row['result_content'] ?> </div>
                                <?php endif; ?>
                                
                                <?php if(!empty($attachments_map[$row['id']])): ?>
                                    <div class="mt-3 flex flex-wrap gap-2">
                                        <?php foreach($attachments_map[$row['id']] as $att): ?>
                                            <a href="<?= $att['file_path'] ?>" target="_blank" class="bg-indigo-50 text-indigo-600 px-2 py-1 rounded text-xs font-bold border border-indigo-200 hover:bg-indigo-100"><i class="fa-solid fa-download mr-1"></i> <?= htmlspecialchars($att['original_name']) ?></a>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </div>
</body>
</html>

<?php
// 파일명: /smw/export_excel.php
session_start();
include 'db_conn.php';
if (!isset($_SESSION['uid'])) { header("Location: login.php"); exit; }
require_once 'smw_extensions.php';

$uid = (int)$_SESSION['uid'];
$u_info = $conn->query("SELECT position FROM users WHERE id = $uid")->fetch_assoc();
$my_position = $u_info['position'] ?? '사원';

// ★ 프레젠테이션 보드와 100% 동일한 날짜 기준 (GET으로 넘어온 날짜 기준)
$default_report_date = smw_default_report_date($conn);
$ref_date_str = isset($_GET['date']) ? $_GET['date'] : $default_report_date;
if (!preg_match("/^[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[1-2][0-9]|3[0-1])$/", $ref_date_str)) $ref_date_str = $default_report_date;
$weekly_periods = smw_weekly_periods($conn, $ref_date_str);
$tw_start = $weekly_periods['actual_start'];
$tw_end = $weekly_periods['actual_end'];
$nw_start = $weekly_periods['plan_start'];
$nw_end = $weekly_periods['plan_end'];

$target_users = [$uid]; 
if ($my_position === '사장') {
    $u_res = $conn->query("SELECT id FROM users");
    if($u_res) { while($row = $u_res->fetch_assoc()) $target_users[] = $row['id']; }
} else {
    $rel_res = $conn->query("SELECT target_id FROM user_relations WHERE viewer_id = $uid");
    if($rel_res) { while($row = $rel_res->fetch_assoc()) $target_users[] = $row['target_id']; }
}
$target_users_csv = implode(',', array_unique($target_users));

$query = "SELECT r.*, u.nickname as user_name, u.position 
          FROM report_tasks r 
          JOIN users u ON r.user_id = u.id 
          WHERE r.user_id IN ($target_users_csv) 
          AND r.target_date BETWEEN '$tw_start' AND '$nw_end'
          ORDER BY r.user_id ASC, r.target_date ASC";
$result = $conn->query($query);

$report_data = []; 
if($result) {
    while($row = $result->fetch_assoc()) {
        $u_name = "[{$row['position']}] " . $row['user_name'];
        $period = ($row['target_date'] <= $tw_end) ? $weekly_periods['actual_short'] : $weekly_periods['plan_short'];
        $company = trim($row['company_name']);
        
        $plan_text = trim($row['plan_content']);
        $result_text = strip_tags(str_replace(['<br>', '<p>'], ["\n", "\n"], trim($row['result_content']))); // 에디터 태그 엑셀 맞춤 치환
        
        if(empty($plan_text)) continue;

        if(!isset($report_data[$u_name])) { $report_data[$u_name] = []; }
        $report_data[$u_name][] = [
            'period' => $period,
            'date' => $row['target_date'],
            'company' => $company,
            'plan' => $plan_text,
            'result' => $result_text
        ];
    }
}

// 엑셀 다운로드 헤더 설정
$filename = "주간업무보고_" . $tw_start . "_to_" . $nw_end . ".xls";
header("Content-Type: application/vnd.ms-excel; charset=utf-8");
header("Content-Disposition: attachment; filename=$filename");
header("Expires: 0");
header("Cache-Control: must-revalidate, post-check=0, pre-check=0");

echo "\xEF\xBB\xBF"; // UTF-8 BOM
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
    table { border-collapse: collapse; width: 100%; font-family: "Malgun Gothic", sans-serif; font-size: 10pt; }
    th { background-color: #dbeafe; color: #1e3a8a; border: 1px solid #94a3b8; padding: 5px; text-align: center; }
    td { border: 1px solid #94a3b8; padding: 5px; vertical-align: top; mso-number-format:"\@"; }
    .title { font-size: 16pt; font-weight: bold; text-align: center; padding: 15px; }
    .user-header { background-color: #f1f5f9; font-weight: bold; color: #0f172a; }
</style>
</head>
<body>
    <table>
        <tr>
            <td colspan="5" class="title">주간 업무 보고서 (<?= smw_h($weekly_periods['actual_label']) ?> <?= $tw_start ?>~<?= $tw_end ?> / <?= smw_h($weekly_periods['plan_label']) ?> <?= $nw_start ?>~<?= $nw_end ?>)</td>
        </tr>
        <tr>
            <th>구분</th>
            <th>일자</th>
            <th>프로젝트명</th>
            <th>업무 요약 (계획/진행)</th>
            <th>상세 결과</th>
        </tr>
        
        <?php foreach($report_data as $user => $tasks): ?>
            <tr>
                <td colspan="5" class="user-header">🧑‍💻 <?= $user ?></td>
            </tr>
            <?php foreach($tasks as $t): ?>
            <tr>
                <td style="text-align:center;"><?= $t['period'] ?></td>
                <td style="text-align:center;"><?= $t['date'] ?></td>
                <td><?= htmlspecialchars($t['company']) ?></td>
                <td><?= htmlspecialchars($t['plan']) ?></td>
                <td><?= nl2br(htmlspecialchars($t['result'])) ?></td>
            </tr>
            <?php endforeach; ?>
        <?php endforeach; ?>
        
        <?php if(empty($report_data)): ?>
            <tr>
                <td colspan="5" style="text-align:center; padding: 20px;">표시할 데이터가 없습니다.</td>
            </tr>
        <?php endif; ?>
    </table>
</body>
</html>

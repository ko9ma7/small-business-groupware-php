<?php
session_start();
error_reporting(0); ini_set('display_errors', 0);
include 'db_conn.php';
if (!isset($_SESSION['uid'])) { header("Location: login.php"); exit; }
require_once 'smw_extensions.php';
require_once 'groupware_shell.php';
require_once 'report_helpers.php';

$uid = (int)$_SESSION['uid'];
$u_info_res = @$conn->query("SELECT nickname, position, is_admin FROM users WHERE id = $uid");
$u_info = $u_info_res ? $u_info_res->fetch_assoc() : array();
$my_position = isset($u_info['position']) ? $u_info['position'] : '사원';

$default_report_date = smw_default_report_date($conn);
$ref_date_str = isset($_GET['date']) ? $_GET['date'] : $default_report_date;
if (!preg_match("/^[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[1-2][0-9]|3[0-1])$/", $ref_date_str)) $ref_date_str = $default_report_date;
$ref_date = new DateTime($ref_date_str);
$weekly_periods = smw_weekly_periods($conn, $ref_date_str);
$tw_start = $weekly_periods['actual_start'];
$tw_end = $weekly_periods['actual_end'];
$nw_start = $weekly_periods['plan_start'];
$nw_end = $weekly_periods['plan_end'];
$actual_label = $weekly_periods['actual_label'];
$plan_label = $weekly_periods['plan_label'];
$portal_identity = smw_portal_identity($conn);

$prev_week_date = (clone $ref_date)->modify('-7 days')->format('Y-m-d');
$next_week_date = (clone $ref_date)->modify('+7 days')->format('Y-m-d');

$target_users = array($uid); 
if ($my_position === '사장') {
    $u_res = @$conn->query("SELECT id FROM users");
    if($u_res) { while($row = $u_res->fetch_assoc()) $target_users[] = $row['id']; }
} else {
    $rel_res = @$conn->query("SELECT target_id FROM user_relations WHERE viewer_id = $uid");
    if($rel_res) { while($row = $rel_res->fetch_assoc()) $target_users[] = $row['target_id']; }
}
$target_users_csv = implode(',', array_unique($target_users));

$query = "SELECT r.*, u.nickname as user_name, u.position,
          (SELECT COUNT(*) FROM task_comments WHERE task_id = r.id) as c_count
          FROM report_tasks r 
          JOIN users u ON r.user_id = u.id 
          WHERE r.user_id IN ($target_users_csv) 
          AND r.target_date BETWEEN '$tw_start' AND '$nw_end'
          ORDER BY CASE u.position
              WHEN '회장' THEN 1 WHEN '대표' THEN 2 WHEN '사장' THEN 3
              WHEN '부회장' THEN 4 WHEN '부사장' THEN 5 WHEN '전무' THEN 6
              WHEN '상무' THEN 7 WHEN '이사' THEN 8 WHEN '본부장' THEN 9
              WHEN '실장' THEN 10 WHEN '부장' THEN 11 WHEN '차장' THEN 12
              WHEN '과장' THEN 13 WHEN '대리' THEN 14 WHEN '주임' THEN 15
              WHEN '사원' THEN 16 WHEN '인턴' THEN 17 ELSE 99 END,
              u.nickname ASC, r.target_date ASC, r.id ASC";
$result = @$conn->query($query);

$attachments_map = array();
$att_res = @$conn->query("SELECT * FROM attachments WHERE reference_type = 'task'");
if($att_res) { while($att = $att_res->fetch_assoc()) { $attachments_map[$att['reference_id']][] = $att['file_path']; } }

$report_data = array(); 
function getKorDay($date) { $map = array('Sun'=>'일', 'Mon'=>'월', 'Tue'=>'화', 'Wed'=>'수', 'Thu'=>'목', 'Fri'=>'금', 'Sat'=>'토'); return $map[date('D', strtotime($date))]; }
function formatDates($dates_arr) {
    if(empty($dates_arr)) return '';
    $labels = [];
    foreach (smw_date_runs($dates_arr) as [$start, $end]) {
        $startLabel = date('m.d', strtotime($start)) . '(' . getKorDay($start) . ')';
        $labels[] = $start === $end
            ? $startLabel
            : $startLabel . ' ~ ' . date('m.d', strtotime($end)) . '(' . getKorDay($end) . ')';
    }
    return implode(', ', $labels);
}
function renderResultItems(array $items): string {
    $html = '<div class="weekly-result-list">';
    foreach ($items as $item) {
        $dates = formatDates($item['dates'] ?? []);
        $html .= '<div class="weekly-result-item"><span>- ' . htmlspecialchars((string)$item['text'], ENT_QUOTES, 'UTF-8') . '</span>';
        if ($dates !== '') $html .= '<small>' . htmlspecialchars($dates, ENT_QUOTES, 'UTF-8') . '</small>';
        $html .= '</div>';
    }
    return $html . '</div>';
}

if($result) {
    while($row = $result->fetch_assoc()) {
        $u_name = "[{$row['position']}] " . $row['user_name'];
        $company = trim($row['company_name']);
        if (in_array($company, ['월', '화', '수', '목', '금', '토', '일'], true)) $company = '현장 일지';
        $cat = isset($row['task_category']) ? $row['task_category'] : '일반업무';
        $period = ($row['target_date'] <= $tw_end) ? 'actual' : 'plan';
        
        if(!isset($report_data[$u_name])) { 
            $report_data[$u_name] = array(
                'actual' => array('일반업무'=>array(), '영업진행'=>array(), '특이요청'=>array(), '기타사항'=>array()), 
                'plan'   => array('일반업무'=>array(), '영업진행'=>array(), '특이요청'=>array(), '기타사항'=>array()), 
                'images' => array()
            ); 
        }
        
        if(!empty($attachments_map[$row['id']])) {
            foreach($attachments_map[$row['id']] as $img_path) {
                if(preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $img_path)) {
                    $report_data[$u_name]['images'][] = array(
                        'company' => $company,
                        'plan' => trim($row['plan_content']),
                        'path' => $img_path
                    );
                }
            }
        }

        $plan_text = trim($row['plan_content']);
        if(empty($plan_text)) continue;

        if(!isset($report_data[$u_name][$period][$cat][$company][$plan_text])) {
            $report_data[$u_name][$period][$cat][$company][$plan_text] = array(
                'dates' => array(), 'result_items' => array(), 'task_ids' => array(), 'comment_count' => 0
            ); 
        }
        $report_data[$u_name][$period][$cat][$company][$plan_text]['dates'][] = $row['target_date'];
        $report_data[$u_name][$period][$cat][$company][$plan_text]['task_ids'][] = $row['id'];
        $report_data[$u_name][$period][$cat][$company][$plan_text]['comment_count'] += (int)$row['c_count'];
        
        $result_text = trim($row['result_content']);
        $result_text = preg_replace('/^(<p>(<br>|&nbsp;|\s)*<\/p>\s*)+/i', '', $result_text);
        $result_text = preg_replace('/(<p>(<br>|&nbsp;|\s)*<\/p>\s*)+$/i', '', $result_text);
        
        smw_add_result_items($report_data[$u_name][$period][$cat][$company][$plan_text]['result_items'], $result_text, $row['target_date']);
    }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>주간 업무 보고 - <?= smw_h($portal_identity['name']) ?></title>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/FileSaver.js/2.0.5/FileSaver.min.js"></script>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="https://uicdn.toast.com/editor/latest/toastui-editor.min.css" />
<link rel="stylesheet" href="assets/groupware-shell.css?v=3">
<style>
    * { box-sizing: border-box; }
    body { background-color: #334155; margin: 0; font-family: 'Malgun Gothic', sans-serif; overflow: hidden; }
    
    .controls { position: fixed; top: 64px; left: 0; width: 100%; height: 60px; background-color: #0f172a; padding: 0 20px; display: flex; justify-content: space-between; align-items: center; z-index: 45; box-shadow: 0 4px 10px rgba(0,0,0,0.3); }
    .btn { background-color: #3b82f6; color: white; border: none; padding: 8px 16px; font-size: 13px; font-weight: bold; border-radius: 6px; cursor: pointer; transition: 0.2s; display: flex; align-items: center; gap: 6px; }
    .btn:hover { background-color: #2563eb; }
    .btn-success { background-color: #10b981; } .btn-success:hover { background-color: #059669; }
    .btn-warning { background-color: #f59e0b; color: #fff; } .btn-warning:hover { background-color: #d97706; }
    .btn-excel { background-color: #10b981; color: #fff; } .btn-excel:hover { background-color: #059669; }
    .btn-dark { background-color: #475569; color: #fff; } .btn-dark:hover { background-color: #334155; }
    
    .date-nav { display: flex; align-items: center; gap: 10px; background-color: #1e293b; padding: 6px 12px; border-radius: 8px; }
    .date-nav input[type="date"] { padding: 4px 8px; border-radius: 4px; border: none; outline: none; font-weight: bold; font-family: 'Malgun Gothic'; font-size: 13px; cursor: pointer; }
    .weekly-menu-wrap{position:relative}.weekly-quick-menu{position:absolute;top:43px;left:0;display:grid;min-width:190px;padding:7px;border:1px solid #334155;border-radius:9px;background:#172033;box-shadow:0 14px 30px rgba(0,0,0,.35)}.weekly-quick-menu.hidden{display:none}.weekly-quick-menu a{display:flex;align-items:center;gap:8px;padding:9px 10px;border-radius:7px;color:#dbe5f3;font-size:12px;font-weight:700}.weekly-quick-menu a:hover{background:#263650;color:#fff}.weekly-quick-menu i{width:16px;color:#75a7ff;text-align:center}
    
    #slider-viewport { width: 100vw; height: calc(100vh - 124px); margin-top: 60px; display: flex; justify-content: center; align-items: center; overflow: hidden; position: relative; }
    #slider-wrapper { position: relative; width: 1600px; height: 900px; transform-origin: center center; background-color: transparent; }
    
    .slide-container { position: absolute; top: 0; left: 0; width: 1600px; height: 100%; background-color: white; box-shadow: 0 10px 30px rgba(0,0,0,0.5); padding: 25px 35px; display: none; flex-direction: column; border-radius: 12px; overflow: hidden; }
    .slide-container.active { display: flex; }
    
    .header { border-bottom: 3px solid #2563eb; padding-bottom: 12px; margin-bottom: 15px; display: flex; justify-content: space-between; align-items: flex-end; flex-shrink: 0; width: 100%; }
    .header h1 { margin: 0; color: #0f172a; font-size: 26px; font-weight: 900; line-height: 1.3; max-width: 80%; white-space: normal; word-break: keep-all; }
    .header .date { font-size: 16px; color: #475569; font-weight: bold; flex-shrink: 0; }
    
    .content-wrap { display: flex; gap: 20px; height: 100%; min-height: 0; width: 100%; }
    .column { flex: 1; display: flex; flex-direction: column; gap: 10px; background: #f8fafc; border: 2px solid #cbd5e1; border-radius: 8px; padding: 15px; overflow-y: hidden; overflow-x: hidden; height: 100%; }
    
    .section-title { color: white; padding: 8px 12px; font-size: 16px; border-radius: 6px; margin: 0 0 5px 0; font-weight: bold; text-align: center; flex-shrink: 0; box-shadow: 0 2px 4px rgba(0,0,0,0.1); position: sticky; top: -15px; z-index: 10; }
    .bg-blue-600 { background-color: #2563eb; } .bg-amber-600 { background-color: #d97706; } .bg-emerald-600 { background-color: #059669; } .bg-slate-700 { background-color: #475569; }
    
    .project-group { background: #ffffff; border: 1px solid #e2e8f0; border-left: 6px solid; border-radius: 6px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); flex-shrink: 0; display: flex; flex-direction: column; margin-bottom: 8px; }
    .project-name { padding: 6px 10px; font-weight: 900; color: #1e3a8a; font-size: 15px; background-color: #f1f5f9; border-bottom: 1px solid #e2e8f0; border-top-right-radius: 4px; }
    
    .project-details { padding: 8px 10px; font-size: 14px; color: #334155; line-height: 1.5; border-bottom: 1px dashed #e2e8f0; word-break: break-all; } 
    .project-details:last-child { border-bottom: none; }
    
    .data-row { display: flex; align-items: flex-start; gap: 8px; margin-bottom: 4px; width: 100%; } 
    .data-row:last-child { margin-bottom: 0; }
    .data-row > div { flex: 1 1 0; min-width: 0; word-break: break-all; } 
    
    .badge-gray { padding: 3px 6px; border-radius: 4px; font-size: 12px; font-weight: bold; white-space: nowrap; margin-top: 2px; flex-shrink: 0; }
    .badge-green { background-color: #dcfce7; color: #166534; padding: 3px 6px; border-radius: 4px; font-size: 12px; font-weight: bold; white-space: nowrap; margin-top: 2px; flex-shrink: 0; }
    
    .html-content { width: 100%; overflow-wrap: break-word; }
    .weekly-result-list { display: grid; gap: 3px; }
    .weekly-result-item { display: flex; align-items: baseline; justify-content: space-between; gap: 10px; }
    .weekly-result-item > span { min-width: 0; }
    .weekly-result-item > small { flex: 0 0 auto; color: #64748b; font-size: 10px; font-weight: 700; white-space: nowrap; }
    .html-content table { border-collapse: collapse; width: 100%; margin-top: 6px; margin-bottom: 6px; } 
    .html-content th, .html-content td { border: 1px solid #cbd5e1; padding: 6px; font-size: 13px; word-break: break-all; } 
    .html-content img { max-width: 100%; height: auto; border-radius: 4px; }
    
    .image-slide { display: flex; flex-direction: column; padding: 25px 35px; background-color: #f1f5f9; }
    .img-wrapper { flex-grow: 1; width: 100%; display: flex; justify-content: center; align-items: center; background: white; border-radius: 12px; box-shadow: inset 0 0 15px rgba(0,0,0,0.05); padding: 5px; overflow: hidden; margin-bottom: 25px;}
    .img-wrapper img { width: 100%; height: 100%; object-fit: contain; }
    
    .slide-indicator { position: absolute; bottom: 15px; right: 30px; color: #64748b; font-size: 16px; font-weight: 900; }
    
    body.presentation-active { background-color: #000; } 
    body.presentation-active .gw-topbar,
    body.presentation-active .controls { display: none !important; } 
    body.presentation-active #slider-viewport { margin-top: 0; height: 100vh; } 
    
    #loadingOverlay { position: fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(0,0,0,0.9); color:white; display:none; flex-direction:column; justify-content:center; align-items:center; z-index:99999; backdrop-filter: blur(5px); }

    @media (max-width: 1024px) {
        body { overflow-y: auto; background-color: #f1f5f9; }
        .gw-topbar { position: relative; }
        #slider-viewport { height: auto; overflow: visible; display: block; margin-top: 0; padding: 12px; }
        #slider-wrapper { width: 100% !important; height: auto !important; transform: none !important; }
        
        .slide-container { position: relative !important; display: flex !important; width: 100% !important; height: auto !important; padding: 16px !important; margin-bottom: 18px; box-shadow: 0 4px 15px rgba(0,0,0,0.08); border-radius: 8px; }
        
        .content-wrap { flex-direction: column; height: auto !important; }
        .column { width: 100%; height: auto !important; overflow: visible !important; margin-bottom: 15px; }
        .column:empty { display: none; }
        
        .controls { position: sticky; top: 0; flex-wrap: nowrap; height: 58px; padding: 8px 10px; gap: 8px; overflow-x: auto; overscroll-behavior-inline: contain; scrollbar-width: none; }
        .controls::-webkit-scrollbar { display: none; }
        .controls > div { flex: 0 0 auto; }
        .date-nav { width: auto; justify-content: center; margin: 0; }
        .date-nav input[type="date"] { width: 132px; }
        
        .controls .btn-dark, .controls .btn-success { display: none; }
        .weekly-menu-wrap .btn-dark { display: flex; }
        .slide-indicator { display: none; }
        .header { align-items: flex-start; gap: 8px; }
        .header h1 { max-width: 100%; font-size: clamp(18px, 5vw, 24px); }
        .header .date { font-size: 12px; text-align: right; }
        .project-details { font-size: 14px; }
    }

    @media (max-width: 560px) {
        .controls .btn { min-height: 40px; padding: 8px 11px; white-space: nowrap; }
        .slide-container { padding: 12px !important; }
        .header { flex-direction: column; }
        .header .date { text-align: left; }
        .column { padding: 10px; border-width: 1px; }
        .project-name { font-size: 14px; }
        .data-row { gap: 6px; }
    }

    @media print {
        .gw-topbar, .controls, #loadingOverlay { display: none !important; }
        body { background: #fff; overflow: visible; }
        #slider-viewport { width: 100%; height: auto; margin: 0; overflow: visible; }
        #slider-wrapper { width: 100% !important; height: auto !important; transform: none !important; }
        .slide-container { position: relative !important; display: flex !important; width: 100% !important; height: 100vh !important; break-after: page; box-shadow: none; border-radius: 0; }
    }
</style>
</head>
<body>
<?php smw_render_shell_header('weekly', '주간 업무 보고', (int)($u_info['is_admin'] ?? 0) === 1, (string)($u_info['nickname'] ?? '')); ?>

<div id="loadingOverlay">
    <i class="fa-solid fa-spinner fa-spin text-6xl mb-6 text-amber-400"></i>
    <h2 class="text-3xl font-bold">고해상도 캡처를 진행 중입니다...</h2>
    <p class="mt-3 text-gray-300 text-lg">밀림 방지를 위해 독립된 영역에서 촬영 중입니다 (<span id="captureProgress">0</span> 장 완료)</p>
</div>

<div class="controls">
    <div class="flex gap-2">
        <button class="btn btn-gray" onclick="location.href='index.php'"><i class="fa-solid fa-arrow-left"></i> 대시보드</button>
        <div class="weekly-menu-wrap"><button type="button" class="btn btn-dark" onclick="document.getElementById('weeklyQuickMenu').classList.toggle('hidden')"><i class="fa-solid fa-bars"></i> 전체 메뉴</button><nav id="weeklyQuickMenu" class="weekly-quick-menu hidden" aria-label="업무 메뉴"><a href="daily.php"><i class="fa-solid fa-pen-to-square"></i>일일 업무</a><a href="approval_list.php"><i class="fa-solid fa-file-signature"></i>전자결재</a><a href="board.php"><i class="fa-solid fa-clipboard-list"></i>게시판</a><a href="address_book.php"><i class="fa-solid fa-address-book"></i>주소록</a></nav></div>
        <button class="btn bg-blue-600" onclick="location.href='daily.php'"><i class="fa-solid fa-pen-to-square"></i> 일일 업무 작성</button>
        <button class="btn btn-excel" onclick="location.href='export_excel.php?date=<?= $ref_date_str ?>'"><i class="fa-solid fa-file-excel"></i> 엑셀 다운로드</button>
    </div>
    <div class="date-nav">
        <button class="btn btn-dark" onclick="location.href='?date=<?= $prev_week_date ?>'"><i class="fa-solid fa-backward-step"></i> 지난주</button>
        <input type="date" value="<?= $ref_date_str ?>" onchange="location.href='?date='+this.value">
        <?php if($ref_date_str !== $default_report_date): ?>
            <button class="btn bg-blue-600" onclick="location.href='?date=<?= $default_report_date ?>'">회의주 복귀</button>
        <?php endif; ?>
        <button class="btn btn-dark" onclick="location.href='?date=<?= $next_week_date ?>'">다음주 <i class="fa-solid fa-forward-step"></i></button>
    </div>
    <div class="flex gap-2">
        <button class="btn btn-warning" onclick="saveAllAsImages()" id="saveImgBtn"><i class="fa-solid fa-camera-retro"></i> 전체 슬라이드 ZIP 저장</button>
        <button class="btn btn-dark" onclick="prevSlide()"><i class="fa-solid fa-caret-left"></i> 이전</button>
        <button class="btn btn-dark" onclick="nextSlide()">다음 <i class="fa-solid fa-caret-right"></i></button>
        <button class="btn btn-success" onclick="togglePresentation()"><i class="fa-solid fa-expand"></i> F11 전체화면</button>
    </div>
</div>

<div id="slider-viewport">
    <div id="slider-wrapper">
        <?php foreach($report_data as $user_name => $tasks): ?>
            <div class="raw-user-container" data-user="<?= htmlspecialchars($user_name, ENT_QUOTES) ?>" style="display:none;">
                <div class="raw-data-chunk">
                    <?php if(!empty($tasks['actual']['일반업무'])): ?>
                        <div class="section-title bg-blue-600" data-new-col="false"><?= smw_h($actual_label) ?> <small>(<?= date('m.d', strtotime($tw_start)) ?>~<?= date('m.d', strtotime($tw_end)) ?>)</small></div>
                        <?php foreach($tasks['actual']['일반업무'] as $company => $plans): ?>
                            <div class="project-group" style="border-left-color: #3b82f6;">
                                <div class="project-name">[<?= htmlspecialchars($company) ?>]</div>
                                <?php foreach($plans as $plan_desc => $details): ?>
                                    <div class="project-details">
                                        <div class="data-row">
                                            <span class="badge-gray" style="background:#e2e8f0; color:#475569;">진행</span>
                                            <div>
                                                <span class="font-bold text-gray-800"><?= htmlspecialchars($plan_desc) ?></span> 
                                                <strong style="color:#3b82f6;">(<?= formatDates($details['dates']) ?>)</strong>
                                                <button onclick="openCommentModal('<?= implode(',', $details['task_ids']) ?>', this.getAttribute('data-title'))" data-title="<?= htmlspecialchars($plan_desc, ENT_QUOTES) ?>" class="ml-2 text-teal-600 border px-1.5 py-0.5 rounded bg-teal-50" data-html2canvas-ignore="true" style="font-size:12px;"><i class="fa-regular fa-comments"></i> <?= $details['comment_count']>0?"<span class='text-red-500 font-bold ml-1'>{$details['comment_count']}</span>":"" ?></button>
                                            </div>
                                        </div>
                                        <?php if(!empty($details['result_items'])): ?>
                                            <div class="data-row mt-1"><span class="badge-green">결과</span><div class="html-content"><?= renderResultItems($details['result_items']) ?></div></div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    
                    <?php if(!empty($tasks['plan']['일반업무'])): ?>
                        <div class="section-title bg-amber-600" data-new-col="true"><?= smw_h($plan_label) ?> <small>(<?= date('m.d', strtotime($nw_start)) ?>~<?= date('m.d', strtotime($nw_end)) ?>)</small></div>
                        <?php foreach($tasks['plan']['일반업무'] as $company => $plans): ?>
                            <div class="project-group" style="border-left-color: #d97706;">
                                <div class="project-name">[<?= htmlspecialchars($company) ?>]</div>
                                <?php foreach($plans as $plan_desc => $details): ?>
                                    <div class="project-details">
                                        <div class="data-row">
                                            <span class="badge-gray" style="background:#fef3c7; color:#b45309;">예정</span>
                                            <div>
                                                <span class="font-bold text-gray-800"><?= htmlspecialchars($plan_desc) ?></span> 
                                                <strong style="color:#d97706;">(<?= formatDates($details['dates']) ?>)</strong>
                                                <button onclick="openCommentModal('<?= implode(',', $details['task_ids']) ?>', this.getAttribute('data-title'))" data-title="<?= htmlspecialchars($plan_desc, ENT_QUOTES) ?>" class="ml-2 text-teal-600 border px-1.5 py-0.5 rounded bg-teal-50" data-html2canvas-ignore="true" style="font-size:12px;"><i class="fa-regular fa-comments"></i> <?= $details['comment_count']>0?"<span class='text-red-500 font-bold ml-1'>{$details['comment_count']}</span>":"" ?></button>
                                            </div>
                                        </div>
                                        <?php if(!empty($details['result_items'])): ?>
                                            <div class="data-row mt-1"><span class="badge-green">결과</span><div class="html-content"><?= renderResultItems($details['result_items']) ?></div></div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <?php 
                    $has_sales = !empty($tasks['actual']['영업진행']) || !empty($tasks['plan']['영업진행']);
                    if($has_sales): 
                    ?>
                        <div class="section-title bg-emerald-600" data-new-col="true">영업 진행 및 계획</div>
                        <?php foreach(['actual', 'plan'] as $period): 
                            if(empty($tasks[$period]['영업진행'])) continue;
                            $bLbl = $period === 'actual' ? '영업' : '예정';
                            foreach($tasks[$period]['영업진행'] as $company => $plans): 
                        ?>
                            <div class="project-group" style="border-left-color: #059669;">
                                <div class="project-name text-emerald-900 bg-emerald-50">[<?= htmlspecialchars($company) ?>]</div>
                                <?php foreach($plans as $plan_desc => $details): ?>
                                    <div class="project-details">
                                        <div class="data-row">
                                            <span class="badge-gray" style="background:#d1fae5; color:#065f46;"><?= $bLbl ?></span>
                                            <div>
                                                <span class="font-bold text-gray-800"><?= htmlspecialchars($plan_desc) ?></span> 
                                                <strong style="color:#059669;">(<?= formatDates($details['dates']) ?>)</strong>
                                                <button onclick="openCommentModal('<?= implode(',', $details['task_ids']) ?>', this.getAttribute('data-title'))" data-title="<?= htmlspecialchars($plan_desc, ENT_QUOTES) ?>" class="ml-2 text-teal-600 border px-1.5 py-0.5 rounded bg-teal-50" data-html2canvas-ignore="true" style="font-size:12px;"><i class="fa-regular fa-comments"></i> <?= $details['comment_count']>0?"<span class='text-red-500 font-bold ml-1'>{$details['comment_count']}</span>":"" ?></button>
                                            </div>
                                        </div>
                                        <?php if(!empty($details['result_items'])): ?>
                                            <div class="data-row mt-1"><span class="badge-green">결과</span><div class="html-content"><?= renderResultItems($details['result_items']) ?></div></div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; endforeach; ?>
                    <?php endif; ?>

                    <?php 
                    $has_special = !empty($tasks['actual']['특이요청']) || !empty($tasks['plan']['특이요청']) || !empty($tasks['actual']['기타사항']) || !empty($tasks['plan']['기타사항']);
                    if($has_special): 
                    ?>
                        <div class="section-title bg-slate-700" data-new-col="false">특이사항 및 협조요청 (기타)</div>
                        <?php foreach(['actual', 'plan'] as $period): 
                            foreach(['특이요청', '기타사항'] as $cat):
                                if(empty($tasks[$period][$cat])) continue;
                                $bBg = $cat === '특이요청' ? '#fee2e2' : '#f3e8ff';
                                $bTxt = $cat === '특이요청' ? '#b91c1c' : '#7e22ce';
                                $bLbl = $cat === '특이요청' ? '공지' : '기타';
                                foreach($tasks[$period][$cat] as $company => $plans): 
                        ?>
                            <div class="project-group" style="border-left-color: #475569;">
                                <div class="project-name text-slate-800 bg-slate-200">[<?= htmlspecialchars($company) ?>]</div>
                                <?php foreach($plans as $plan_desc => $details): ?>
                                    <div class="project-details">
                                        <div class="data-row">
                                            <span class="badge-gray" style="background:<?= $bBg ?>; color:<?= $bTxt ?>;"><?= $bLbl ?></span>
                                            <div>
                                                <span class="font-bold text-gray-800"><?= htmlspecialchars($plan_desc) ?></span> 
                                                <strong style="color:#475569;">(<?= formatDates($details['dates']) ?>)</strong>
                                                <button onclick="openCommentModal('<?= implode(',', $details['task_ids']) ?>', this.getAttribute('data-title'))" data-title="<?= htmlspecialchars($plan_desc, ENT_QUOTES) ?>" class="ml-2 text-teal-600 border px-1.5 py-0.5 rounded bg-teal-50" data-html2canvas-ignore="true" style="font-size:12px;"><i class="fa-regular fa-comments"></i> <?= $details['comment_count']>0?"<span class='text-red-500 font-bold ml-1'>{$details['comment_count']}</span>":"" ?></button>
                                            </div>
                                        </div>
                                        <?php if(!empty($details['result_items'])): ?>
                                            <div class="data-row mt-1"><span class="badge-green">결과</span><div class="html-content"><?= renderResultItems($details['result_items']) ?></div></div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; endforeach; endforeach; ?>
                    <?php endif; ?>
                </div>
                
                <div class="user-images-chunk">
                    <?php foreach($tasks['images'] as $img): ?>
                        <div class="image-data" data-company="<?= htmlspecialchars($img['company'], ENT_QUOTES) ?>" data-plan="<?= htmlspecialchars($img['plan'], ENT_QUOTES) ?>" data-path="<?= htmlspecialchars($img['path'], ENT_QUOTES) ?>"></div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div id="commentModal" class="fixed inset-0 bg-black bg-opacity-70 hidden flex justify-center items-center z-[9999] p-4" data-html2canvas-ignore="true">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-3xl flex flex-col h-[85vh] overflow-hidden">
        <div class="p-4 border-b bg-teal-600 text-white flex justify-between items-center"><h3 class="font-bold text-lg"><i class="fa-solid fa-comments mr-2"></i>업무 코멘트: <span id="commentTaskTitle" class="text-teal-200 text-sm"></span></h3><button onclick="closeCommentModal()" class="text-white hover:text-gray-200 text-xl"><i class="fa-solid fa-xmark"></i></button></div>
        <div id="commentList" class="flex-grow overflow-y-auto p-6 bg-gray-50 space-y-4"></div>
        <div class="p-4 border-t bg-white">
            <input type="hidden" id="commentTaskId"><div id="commentEditor" class="mb-2" style="height:200px;"></div>
            <div class="flex justify-end"><button onclick="submitComment()" class="bg-teal-600 text-white px-6 py-2 rounded font-bold shadow-md hover:bg-teal-700">코멘트 남기기</button></div>
        </div>
    </div>
</div>

<script>
    let slides = [];
    let currentSlide = 0;

    document.addEventListener("DOMContentLoaded", () => {
        const wrapper = document.getElementById('slider-wrapper');
        const rawUsers = document.querySelectorAll('.raw-user-container');
        
        wrapper.style.width = '1600px';
        wrapper.style.height = '900px';
        wrapper.style.display = 'block';
        wrapper.style.transform = 'none'; 
        
        rawUsers.forEach(userEl => {
            const userName = userEl.getAttribute('data-user');
            const blocks = Array.from(userEl.querySelector('.raw-data-chunk').children);
            const images = Array.from(userEl.querySelectorAll('.image-data'));
            
            if (blocks.length > 0) {
                let currentSlideEl = createNewSlide(userName);
                wrapper.appendChild(currentSlideEl);
                currentSlideEl.style.display = 'flex';
                
                let leftCol = currentSlideEl.querySelector('.column-left');
                let rightCol = currentSlideEl.querySelector('.column-right');
                let currentTargetCol = leftCol;
                
                blocks.forEach(block => {
                    if (block.classList.contains('section-title') && block.getAttribute('data-new-col') === 'true') {
                        if (currentTargetCol.children.length > 0) {
                            if (currentTargetCol === leftCol) {
                                currentTargetCol = rightCol;
                            } else {
                                currentSlideEl.style.display = 'none';
                                currentSlideEl = createNewSlide(userName);
                                wrapper.appendChild(currentSlideEl);
                                currentSlideEl.style.display = 'flex';
                                leftCol = currentSlideEl.querySelector('.column-left');
                                rightCol = currentSlideEl.querySelector('.column-right');
                                currentTargetCol = leftCol;
                            }
                        }
                    }
                    
                    currentTargetCol.appendChild(block);
                    
                    if (currentTargetCol.scrollHeight > currentTargetCol.clientHeight) {
                        if (currentTargetCol.children.length > 1) { 
                            currentTargetCol.removeChild(block);
                            
                            let titleToMove = null;
                            if (currentTargetCol.lastElementChild && currentTargetCol.lastElementChild.classList.contains('section-title')) {
                                titleToMove = currentTargetCol.lastElementChild;
                                currentTargetCol.removeChild(titleToMove);
                            }
                            
                            if (currentTargetCol === leftCol) {
                                currentTargetCol = rightCol;
                            } else {
                                currentSlideEl.style.display = 'none';
                                currentSlideEl = createNewSlide(userName);
                                wrapper.appendChild(currentSlideEl);
                                currentSlideEl.style.display = 'flex';
                                leftCol = currentSlideEl.querySelector('.column-left');
                                rightCol = currentSlideEl.querySelector('.column-right');
                                currentTargetCol = leftCol;
                            }
                            
                            if (titleToMove) currentTargetCol.appendChild(titleToMove);
                            currentTargetCol.appendChild(block);
                        }
                    }
                });
                currentSlideEl.style.display = 'none';
            }
            
            images.forEach(img => {
                const company = img.getAttribute('data-company');
                const path = img.getAttribute('data-path');
                const plan = img.getAttribute('data-plan');
                
                const imgSlide = document.createElement('div');
                imgSlide.className = 'slide-container image-slide built-slide';
                imgSlide.innerHTML = `
                    <div class="header">
                        <h1>📎 [${company}] ${plan}</h1>
                        <div class="date">작성자: ${userName}</div>
                    </div>
                    <div class="img-wrapper"><img src="${path}" alt="첨부자료"></div>
                    <div class="slide-indicator" data-html2canvas-ignore="true"></div>
                `;
                wrapper.appendChild(imgSlide);
            });
            
            userEl.remove(); 
        });
        
        wrapper.style.width = '';
        wrapper.style.height = '';
        
        const allBuiltSlides = wrapper.querySelectorAll('.built-slide');
        if(allBuiltSlides.length === 0) {
            const emptySlide = document.createElement('div');
            emptySlide.className = 'slide-container active built-slide slide-item';
            emptySlide.innerHTML = `<h2 style="color: #64748b; text-align: center; font-size: 24px;"><i class="fa-regular fa-calendar-xmark text-6xl mb-6 block"></i>해당 주차에 표시할 데이터가 없습니다.</h2>`;
            emptySlide.style.justifyContent = 'center'; emptySlide.style.alignItems = 'center'; emptySlide.style.display = 'flex';
            wrapper.appendChild(emptySlide);
            slides = [emptySlide];
        } else {
            allBuiltSlides.forEach((slide, idx) => {
                const indicator = slide.querySelector('.slide-indicator');
                if(indicator) indicator.innerHTML = `${idx + 1} / ${allBuiltSlides.length}`;
                slide.classList.add('slide-item');
            });
            slides = document.querySelectorAll('.slide-item');
            currentSlide = 0;
            showSlide(currentSlide);
        }
        
        resizeSlides();
    });

    function createNewSlide(userName) {
        const slide = document.createElement('div');
        slide.className = 'slide-container built-slide';
        slide.innerHTML = `
            <div class="header">
                <h1>🧑‍💻 ${userName} 주간 보고</h1>
                <div class="date"><?= smw_h($actual_label) ?> <?= $tw_start ?>~<?= $tw_end ?><br><?= smw_h($plan_label) ?> <?= $nw_start ?>~<?= $nw_end ?></div>
            </div>
            <div class="content-wrap">
                <div class="column column-left"></div>
                <div class="column column-right"></div>
            </div>
            <div class="slide-indicator" data-html2canvas-ignore="true"></div>
        `;
        return slide;
    }

    function resizeSlides() {
        if(window.innerWidth > 1024) {
            const viewport = document.getElementById('slider-viewport');
            const wrapper = document.getElementById('slider-wrapper');
            const scale = Math.min(viewport.clientWidth / 1600, viewport.clientHeight / 900) * 0.95;
            wrapper.style.transform = `scale(${scale})`;
        } else {
            document.getElementById('slider-wrapper').style.transform = 'none';
        }
    }
    window.addEventListener('resize', resizeSlides);

    function showSlide(index) { 
        if(slides.length===0) return; 
        slides.forEach(s=> { s.classList.remove('active'); s.style.display = 'none'; }); 
        if(index>=slides.length) currentSlide=0; 
        if(index<0) currentSlide=slides.length-1; 
        slides[currentSlide].classList.add('active'); 
        slides[currentSlide].style.display = 'flex'; 
    }
    
    function nextSlide() { currentSlide++; showSlide(currentSlide); }
    function prevSlide() { currentSlide--; showSlide(currentSlide); }
    
    document.addEventListener('keydown', (e) => { 
        if (['INPUT', 'TEXTAREA', 'SELECT'].includes(e.target.tagName) || e.target.closest('.toastui-editor-defaultUI')) return; 
        const nextKeys = ['ArrowRight', 'ArrowDown', 'PageDown', ' ', 'Enter', 'n', 'N', 'b', 'B'];
        const prevKeys = ['ArrowLeft', 'ArrowUp', 'PageUp', 'Backspace', 'p', 'P'];
        if(nextKeys.includes(e.key)) { e.preventDefault(); nextSlide(); } 
        if(prevKeys.includes(e.key)) { e.preventDefault(); prevSlide(); } 
    });
    
    function togglePresentation() { const elem = document.documentElement; if (!document.fullscreenElement) { if (elem.requestFullscreen) elem.requestFullscreen(); else if (elem.webkitRequestFullscreen) elem.webkitRequestFullscreen(); } else { if (document.exitFullscreen) document.exitFullscreen(); } }
    document.addEventListener('fullscreenchange', () => { document.body.classList.toggle('presentation-active', document.fullscreenElement); setTimeout(resizeSlides, 100); });

    async function saveAllAsImages() {
        if(slides.length === 0) { alert('캡처할 슬라이드가 없습니다.'); return; }
        const overlay = document.getElementById('loadingOverlay');
        const progress = document.getElementById('captureProgress');
        overlay.style.display = 'flex';
        
        const rememberedSlide = currentSlide;
        const zip = new JSZip();
        
        const exportWrapper = document.createElement('div');
        exportWrapper.style.position = 'absolute';
        exportWrapper.style.top = '0';
        exportWrapper.style.left = '0';
        exportWrapper.style.width = '1600px';
        exportWrapper.style.height = '900px';
        exportWrapper.style.zIndex = '99990'; 
        exportWrapper.style.backgroundColor = '#ffffff';
        exportWrapper.style.transform = 'none'; 
        exportWrapper.style.margin = '0';
        exportWrapper.style.padding = '0';
        document.body.appendChild(exportWrapper);
        
        window.scrollTo(0, 0);
        
        for(let i = 0; i < slides.length; i++) {
            exportWrapper.innerHTML = '';
            
            const clone = slides[i].cloneNode(true);
            clone.classList.add('active');
            clone.style.display = 'flex';
            clone.style.position = 'relative'; 
            clone.style.width = '100%';
            clone.style.height = '100%';
            clone.style.transform = 'none';
            clone.style.boxShadow = 'none'; 
            clone.style.margin = '0';
            clone.style.padding = '25px 35px';
            
            exportWrapper.appendChild(clone);
            
            await new Promise(r => setTimeout(r, 300));
            
            const canvas = await html2canvas(clone, { 
                scale: 2, 
                width: 1600, 
                height: 900, 
                x: 0, 
                y: 0, 
                scrollX: 0,
                scrollY: 0,
                backgroundColor: "#ffffff", 
                useCORS: true,
                logging: false,
                onclone: (doc) => {
                    const cols = doc.querySelectorAll('.column');
                    cols.forEach(c => c.style.overflow = 'hidden');
                }
            });
            
            const imgData = canvas.toDataURL('image/jpeg', 0.92).split(',')[1]; 
            const fileNum = String(i + 1).padStart(2, '0');
            zip.file(`주간보고_슬라이드_${fileNum}.jpg`, imgData, {base64: true});
            
            progress.innerText = i + 1;
        }
        
        document.body.removeChild(exportWrapper);
        
        zip.generateAsync({type:"blob"}).then(function(content) {
            saveAs(content, "주간보고_전체슬라이드.zip");
            overlay.style.display = 'none';
            currentSlide = rememberedSlide;
            showSlide(currentSlide);
        });
    }
</script>

<script src="https://uicdn.toast.com/editor/latest/toastui-editor-all.min.js"></script>
<script>
    const cEditor = new toastui.Editor({ el: document.querySelector('#commentEditor'), height: '200px', initialEditType: 'wysiwyg', toolbarItems: [['bold', 'italic', 'strike'], ['image', 'link']] });
    let currentTaskId = ''; 
    
    // [수정] 여러 개의 ID가 넘어와도 문자열로 받도록 처리
    function openCommentModal(taskId, title) { 
        currentTaskId = taskId.toString(); 
        document.getElementById('commentTaskId').value = currentTaskId; 
        document.getElementById('commentTaskTitle').innerText = title; 
        document.getElementById('commentModal').classList.remove('hidden'); 
        loadComments(); 
    }
    
    function closeCommentModal() { document.getElementById('commentModal').classList.add('hidden'); cEditor.setHTML(''); }
    
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
    async function submitComment() { const content = cEditor.getHTML(); if(!content || content === '<p><br></p>') { alert('내용을 입력하세요.'); return; } const fd = new FormData(); fd.append('action', 'add'); fd.append('task_id', currentTaskId); fd.append('content', content); const res = await fetch('task_comment_api.php', { method: 'POST', body: fd }).then(r => r.json()); if(res.success) { cEditor.setHTML(''); loadComments(); } }
    async function deleteComment(cid) { if(!confirm('삭제하시겠습니까?')) return; const fd = new FormData(); fd.append('action', 'delete'); fd.append('comment_id', cid); await fetch('task_comment_api.php', { method: 'POST', body: fd }); loadComments(); }
</script>
</body>
</html>

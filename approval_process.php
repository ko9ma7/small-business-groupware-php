<?php
// 파일명: /smw/approval_process.php
session_start();
error_reporting(0); ini_set('display_errors', 0);
include 'db_conn.php';

if (!isset($_SESSION['uid'])) { header("Location: login.php"); exit; }
require_once 'groupware_shell.php';
$current_user_id = (int)$_SESSION['uid'];
$doc_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($doc_id === 0) die("<script>alert('잘못된 접근입니다.'); location.href='index.php';</script>");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action']; 
    $comment = $conn->real_escape_string($_POST['comment']);
    $approval_id = intval($_POST['approval_id']);
    $status_update = ($action === 'approve') ? 'approved' : 'rejected';
    
    $stmt = $conn->prepare("UPDATE e_approvals SET status = ?, comment = ?, processed_at = NOW() WHERE id = ? AND approver_id = ?");
    $stmt->bind_param("ssii", $status_update, $comment, $approval_id, $current_user_id);
    
    if ($stmt->execute()) {
        if ($action === 'reject') {
            $conn->query("UPDATE e_documents SET status = 'rejected' WHERE id = $doc_id");
            $message = "문서를 반려 처리했습니다.";
        } else {
            $check_next = $conn->query("SELECT id FROM e_approvals WHERE document_id = $doc_id AND status = 'pending' ORDER BY step_order ASC LIMIT 1");
            if ($check_next && $check_next->num_rows > 0) {
                $next_app_id = $check_next->fetch_assoc()['id'];
                $conn->query("UPDATE e_approvals SET assigned_at = NOW() WHERE id = $next_app_id");
                $message = "승인 완료! 다음 결재자에게 이관되었습니다.";
            } else {
                $conn->query("UPDATE e_documents SET status = 'approved' WHERE id = $doc_id");
                $message = "최종 승인 처리되었습니다.";
            }
        }
        echo "<script>alert('$message'); location.href='approval_list.php';</script>"; exit;
    }
}

$doc_stmt = $conn->prepare("SELECT d.*, u.nickname as author_name, u.position as author_pos FROM e_documents d JOIN users u ON d.author_id = u.id WHERE d.id = ?");
$doc_stmt->bind_param("i", $doc_id); $doc_stmt->execute();
$document = $doc_stmt->get_result()->fetch_assoc();
if(!$document) die("<script>alert('문서가 없습니다.'); location.href='index.php';</script>");

$file_stmt = $conn->prepare("SELECT * FROM attachments WHERE reference_type = 'document' AND reference_id = ?");
$file_stmt->bind_param("i", $doc_id); $file_stmt->execute();
$attachments = $file_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$line_stmt = $conn->prepare("SELECT a.*, u.nickname as approver_name, u.position FROM e_approvals a JOIN users u ON a.approver_id = u.id WHERE a.document_id = ? ORDER BY a.step_order ASC");
$line_stmt->bind_param("i", $doc_id); $line_stmt->execute();
$approvals = $line_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$my_turn = false; $my_approval_id = 0; $deadline_str = "";
$timeout_val = $conn->query("SELECT setting_value FROM site_settings WHERE setting_key='approval_timeout'")->fetch_assoc()['setting_value'] ?? 0;
$timeout_days = (int)$timeout_val;

foreach ($approvals as $app) {
    if ($app['status'] === 'pending') {
        if ($app['approver_id'] == $current_user_id) {
            $my_turn = true; $my_approval_id = $app['id'];
            if ($timeout_days > 0 && !empty($app['assigned_at'])) {
                $deadline_time = strtotime($app['assigned_at'] . " + $timeout_days days");
                $deadline_str = "<div class='text-red-600 font-bold mt-2 bg-red-50 p-2 rounded border border-red-200'><i class='fa-solid fa-clock mr-1'></i> 마감 기한: " . date('Y-m-d H:i', $deadline_time) . " 까지</div>";
            }
        }
        break; 
    }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>전자결재 문서</title>
<script src="https://cdn.tailwindcss.com"></script><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"><link rel="stylesheet" href="assets/groupware-shell.css?v=2">
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<style>
    body { font-family: 'Malgun Gothic', sans-serif; }
    .toastui-editor-contents table { border-collapse: collapse; width: 100%; margin-top: 10px; margin-bottom: 10px; }
    .toastui-editor-contents th, .toastui-editor-contents td { border: 1px solid #cbd5e1; padding: 8px; }
    .toastui-editor-contents img { max-width: 100%; height: auto; }
    @media print {
        @page { size: A4; margin: 15mm; } body { background: white; }
        .no-print { display: none !important; }
        .print-box { box-shadow: none !important; border: 1px solid #000 !important; }
        .print-border { border-color: #000 !important; }
    }
</style>
</head>
<body class="gw-body min-h-screen pb-10">
    <div class="no-print"><?php smw_render_shell_header('approval', '결재 문서 열람'); ?></div>
    <div class="no-print max-w-4xl mx-auto mt-5 px-4 flex flex-wrap justify-end gap-2">
            <?php if($document['status'] === 'rejected' && $document['author_id'] == $current_user_id): ?>
                <a href="approval_draft.php?redraft_id=<?= $document['id'] ?>" class="bg-red-500 text-white px-3 py-2 rounded text-sm hover:bg-red-600 font-bold"><i class="fa-solid fa-rotate-left mr-1"></i>수정하여 재기안</a>
            <?php endif; ?>
            <button onclick="saveAsImage()" id="saveImgBtn" class="bg-indigo-600 text-white px-3 py-2 rounded text-sm hover:bg-indigo-700 font-bold"><i class="fa-solid fa-camera mr-1"></i>이미지 저장</button>
            <button onclick="window.print()" class="bg-slate-800 text-white px-3 py-2 rounded text-sm hover:bg-slate-900 font-bold"><i class="fa-solid fa-print mr-1"></i>서류 출력</button>
            <a href="approval_list.php" class="bg-blue-600 text-white px-3 py-2 rounded text-sm hover:bg-blue-700 font-bold">진행함으로</a>
    </div>

    <div class="max-w-4xl mx-auto mt-6 px-4">
        <div class="print-box bg-white p-8 rounded-xl shadow-lg border-t-4 border-amber-500 print-border" id="captureArea">
            <h2 class="text-2xl font-bold text-gray-800 mb-6 border-b-2 border-amber-100 print-border pb-3 text-center print:text-3xl"><?= htmlspecialchars($document['title']) ?></h2>
            <div class="flex gap-2 mb-6 justify-end">
                <div class="w-24 border border-gray-300 print-border rounded p-2 text-center bg-gray-50">
                    <div class="text-xs text-gray-500 mb-1 border-b print-border pb-1">기안자</div><div class="font-bold text-gray-800 text-sm mt-1"><?= htmlspecialchars($document['author_name']) ?></div><div class="text-[10px] text-gray-400"><?= htmlspecialchars($document['author_pos']) ?></div>
                </div>
                <?php foreach($approvals as $app): ?>
                <div class="w-24 border border-gray-300 print-border rounded p-2 text-center bg-white">
                    <div class="text-xs text-gray-500 mb-1 border-b print-border pb-1">결재</div><div class="font-bold text-gray-800 text-sm mt-1"><?= htmlspecialchars($app['approver_name']) ?></div>
                    <div class="text-[10px] font-bold mt-1 <?= $app['status'] === 'approved' ? 'text-emerald-600' : ($app['status'] === 'rejected' ? 'text-red-600' : 'text-amber-500') ?>"><?= $app['status'] === 'approved' ? '(승인)' : ($app['status'] === 'rejected' ? '(반려)' : '대기') ?></div>
                </div>
                <?php endforeach; ?>
            </div>

            <table class="w-full text-sm border-collapse border border-gray-300 print-border mb-6">
                <tbody><tr><td class="border border-gray-300 print-border bg-gray-100 font-bold p-2 w-24 text-center">문서번호</td><td class="border border-gray-300 print-border p-2">DOC-<?= $document['id'] ?></td><td class="border border-gray-300 print-border bg-gray-100 font-bold p-2 w-24 text-center">문서종류</td><td class="border border-gray-300 print-border p-2"><?= strtoupper($document['doc_type']) ?></td></tr><tr><td class="border border-gray-300 print-border bg-gray-100 font-bold p-2 text-center">작성일자</td><td class="border border-gray-300 print-border p-2"><?= $document['created_at'] ?></td><td class="border border-gray-300 print-border bg-gray-100 font-bold p-2 text-center">최종상태</td><td class="border border-gray-300 print-border p-2 font-bold <?= $document['status'] === 'approved' ? 'text-emerald-600' : ($document['status'] === 'rejected' ? 'text-red-600' : 'text-blue-600') ?>"><?= strtoupper($document['status']) ?></td></tr></tbody>
            </table>

            <?php 
            $has_comment = false;
            $comment_html = "<div class='bg-red-50 border border-red-200 p-4 rounded mb-6'><h3 class='font-bold text-red-800 mb-2'><i class='fa-solid fa-triangle-exclamation'></i> 결재 코멘트 / 반려 사유</h3><ul class='space-y-2'>";
            foreach($approvals as $app) {
                if(!empty($app['comment'])) {
                    $has_comment = true;
                    $status_mark = $app['status'] === 'rejected' ? "<span class='bg-red-500 text-white px-1 rounded text-xs'>반려</span>" : "<span class='bg-emerald-500 text-white px-1 rounded text-xs'>승인</span>";
                    $comment_html .= "<li class='text-sm text-gray-800'><strong>[{$app['approver_name']}]</strong> $status_mark : {$app['comment']}</li>";
                }
            }
            $comment_html .= "</ul></div>";
            if($has_comment) echo $comment_html;
            ?>

            <div class="min-h-[300px] border border-gray-300 print-border p-6 rounded mb-6 toastui-editor-contents text-base leading-relaxed"><?= $document['content'] ?></div>

            <?php if(!empty($attachments)): ?>
            <div class="mb-6 p-4 bg-blue-50 border border-blue-100 rounded print-border"><strong class="text-blue-800 block mb-2"><i class="fa-solid fa-paperclip mr-1"></i> 첨부파일</strong><ul class="space-y-1">
                <?php foreach($attachments as $file): ?><li><a href="<?= $file['file_path'] ?>" download="<?= $file['original_name'] ?>" class="text-blue-600 hover:underline text-sm font-medium"><?= htmlspecialchars($file['original_name']) ?> <span class="text-gray-400 text-xs ml-1">(<?= round($file['file_size']/1024) ?>KB)</span></a></li><?php endforeach; ?>
            </ul></div>
            <?php endif; ?>

            <?php if($my_turn): ?>
            <div class="no-print bg-amber-50 border-2 border-amber-300 p-5 rounded mt-8">
                <h3 class="font-bold text-amber-800 mb-3"><i class="fa-solid fa-pen mr-1"></i> 나의 결재 처리</h3>
                <form method="POST">
                    <input type="hidden" name="approval_id" value="<?= $my_approval_id ?>">
                    <textarea name="comment" placeholder="승인 또는 반려 사유를 남겨주세요." required class="w-full p-3 border border-amber-300 rounded focus:ring-2 focus:ring-amber-500 mb-3 text-sm resize-none h-20 bg-white"></textarea>
                    <div class="flex flex-col sm:flex-row justify-between items-center gap-3">
                        <div class="w-full sm:w-auto"><?= $deadline_str ?></div>
                        <div class="w-full sm:w-auto flex gap-2"><button type="submit" name="action" value="reject" class="w-full sm:w-auto bg-red-500 hover:bg-red-600 text-white px-6 py-2.5 rounded font-bold shadow-sm">✖ 반려하기</button><button type="submit" name="action" value="approve" class="w-full sm:w-auto bg-emerald-600 hover:bg-emerald-700 text-white px-6 py-2.5 rounded font-bold shadow-sm">✔ 승인하기</button></div>
                    </div>
                </form>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        async function saveAsImage() {
            const captureArea = document.getElementById('captureArea');
            const btn = document.getElementById('saveImgBtn'); 
            const originText = btn.innerHTML; 
            btn.innerHTML = "⏳ 캡처 중..."; btn.disabled = true;
            try {
                const canvas = await html2canvas(captureArea, { scale: 2, backgroundColor: "#ffffff", useCORS: true });
                const link = document.createElement('a'); link.download = '결재서류_' + Date.now() + '.png'; link.href = canvas.toDataURL('image/png'); link.click();
            } catch(e) { alert('이미지 저장에 실패했습니다.'); } finally { btn.innerHTML = originText; btn.disabled = false; }
        }
    </script>
</body>
</html>

<?php
// 파일명: /smw/approval_list.php
session_start();
error_reporting(0); ini_set('display_errors', 0);
include 'db_conn.php';
if (!isset($_SESSION['uid'])) { header("Location: login.php"); exit; }
require_once 'groupware_shell.php';

$uid = (int)$_SESSION['uid'];
$tab = $_GET['tab'] ?? 'pending_me'; // 기본 탭: 내가 결재할 문서

// 탭에 따른 쿼리 조건 설정
if ($tab === 'pending_me') {
    // 내가 결재해야 할 문서 (대기 중)
    $title = "내가 결재할 문서";
    $query = "SELECT d.*, u.nickname as author_name, a.step_order, a.assigned_at 
              FROM e_approvals a 
              JOIN e_documents d ON a.document_id = d.id 
              JOIN users u ON d.author_id = u.id 
              WHERE a.approver_id = $uid AND a.status = 'pending' AND d.status = 'pending' 
              ORDER BY d.created_at DESC";
} elseif ($tab === 'my_drafts') {
    // 내가 상신한 문서 (진행 중)
    $title = "내가 기안한 문서 (진행중)";
    $query = "SELECT d.*, u.nickname as author_name 
              FROM e_documents d 
              JOIN users u ON d.author_id = u.id 
              WHERE d.author_id = $uid AND d.status = 'pending' 
              ORDER BY d.created_at DESC";
} elseif ($tab === 'completed') {
    // 결재 완료된 문서 (내가 기안했거나, 내가 결재자로 참여한 문서)
    $title = "결재 완료 보관함";
    $query = "SELECT DISTINCT d.*, u.nickname as author_name 
              FROM e_documents d 
              JOIN users u ON d.author_id = u.id 
              LEFT JOIN e_approvals a ON d.id = a.document_id 
              WHERE (d.author_id = $uid OR a.approver_id = $uid) AND d.status = 'approved' 
              ORDER BY d.created_at DESC";
} elseif ($tab === 'rejected') {
    // 반려된 문서 (사유 확인용)
    $title = "반려 문서 보관함";
    $query = "SELECT DISTINCT d.*, u.nickname as author_name 
              FROM e_documents d 
              JOIN users u ON d.author_id = u.id 
              LEFT JOIN e_approvals a ON d.id = a.document_id 
              WHERE (d.author_id = $uid OR a.approver_id = $uid) AND d.status = 'rejected' 
              ORDER BY d.created_at DESC";
}

$docs = $conn->query($query)->fetch_all(MYSQLI_ASSOC);

function getStatusBadge($status) {
    if($status == 'pending') return '<span class="bg-blue-100 text-blue-700 px-2 py-1 rounded text-xs font-bold">진행중</span>';
    if($status == 'approved') return '<span class="bg-emerald-100 text-emerald-700 px-2 py-1 rounded text-xs font-bold">결재완료</span>';
    if($status == 'rejected') return '<span class="bg-red-100 text-red-700 px-2 py-1 rounded text-xs font-bold">반려됨</span>';
    return '';
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>전자결재 보관함</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="assets/groupware-shell.css?v=2">
<style>body { font-family: Pretendard, 'Noto Sans KR', 'Malgun Gothic', sans-serif; }</style>
</head>
<body class="gw-body min-h-screen pb-10">

    <?php smw_render_shell_header('approval', '전자결재 진행 및 보관함'); ?>

    <div class="max-w-6xl mx-auto mt-8 px-4">
        
        <div class="flex flex-wrap gap-2 mb-6 border-b border-gray-300 pb-3">
            <a href="?tab=pending_me" class="px-4 py-2 font-bold rounded-t-lg <?= $tab==='pending_me' ? 'bg-blue-600 text-white' : 'bg-white text-gray-600 hover:bg-gray-100' ?>">
                <i class="fa-solid fa-stamp mr-1"></i> 내가 결재할 문서
            </a>
            <a href="?tab=my_drafts" class="px-4 py-2 font-bold rounded-t-lg <?= $tab==='my_drafts' ? 'bg-blue-600 text-white' : 'bg-white text-gray-600 hover:bg-gray-100' ?>">
                <i class="fa-solid fa-file-export mr-1"></i> 내가 상신한 문서
            </a>
            <a href="?tab=completed" class="px-4 py-2 font-bold rounded-t-lg <?= $tab==='completed' ? 'bg-blue-600 text-white' : 'bg-white text-gray-600 hover:bg-gray-100' ?>">
                <i class="fa-solid fa-check-double mr-1"></i> 결재 완료 (보관함)
            </a>
            <a href="?tab=rejected" class="px-4 py-2 font-bold rounded-t-lg <?= $tab==='rejected' ? 'bg-blue-600 text-white' : 'bg-white text-gray-600 hover:bg-gray-100' ?>">
                <i class="fa-solid fa-triangle-exclamation mr-1"></i> 반려 문서
            </a>
        </div>

        <div class="gw-surface bg-white rounded-xl overflow-hidden">
            <div class="px-6 py-4 bg-gray-50 border-b flex justify-between items-center">
                <h2 class="font-bold text-gray-800 text-lg"><?= $title ?> <span class="text-blue-600">(<?= count($docs) ?>건)</span></h2>
                <?php if($tab==='my_drafts' || $tab==='pending_me'): ?>
                    <a href="approval_draft.php" class="bg-blue-600 text-white px-3 py-1.5 rounded text-sm font-bold shadow hover:bg-blue-700">새 결재 기안하기</a>
                <?php endif; ?>
            </div>

            <table class="w-full text-sm text-center text-gray-600">
                <thead class="bg-gray-100 text-gray-700">
                    <tr><th class="py-3 w-20">번호</th><th class="w-24">상태</th><th>제목</th><th class="w-28">기안자</th><th class="w-32">기안일자</th></tr>
                </thead>
                <tbody>
                    <?php if(empty($docs)): ?>
                        <tr><td colspan="5" class="py-16 text-gray-400 font-bold"><i class="fa-regular fa-folder-open text-3xl mb-2 block"></i>해당함에 문서가 없습니다.</td></tr>
                    <?php else: ?>
                        <?php foreach($docs as $d): ?>
                        <tr class="border-b hover:bg-blue-50 cursor-pointer transition" onclick="location.href='approval_process.php?id=<?= $d['id'] ?>'">
                            <td class="py-4">DOC-<?= $d['id'] ?></td>
                            <td class="py-4"><?= getStatusBadge($d['status']) ?></td>
                            <td class="py-4 text-left font-bold text-gray-800 px-4 truncate max-w-[300px]">
                                <?= htmlspecialchars($d['title']) ?>
                                <?php if($tab==='pending_me' && isset($d['step_order'])): ?>
                                    <span class="ml-2 text-xs text-blue-600 font-bold">[나의 차례: <?= $d['step_order'] ?>차]</span>
                                <?php endif; ?>
                            </td>
                            <td class="py-4 font-medium"><?= htmlspecialchars($d['author_name']) ?></td>
                            <td class="py-4 text-xs text-gray-500"><?= substr($d['created_at'], 0, 10) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </div>
</body>
</html>

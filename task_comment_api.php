<?php
// 파일명: /smw/task_comment_api.php
session_start();
error_reporting(0); ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');
include 'db_conn.php';

// [자동 패치] 읽음 확인용 컬럼
$chk_col = $conn->query("SHOW COLUMNS FROM task_comments LIKE 'read_by'");
if ($chk_col && $chk_col->num_rows == 0) {
    $conn->query("ALTER TABLE task_comments ADD COLUMN read_by TEXT DEFAULT ''");
}

if (!isset($_SESSION['uid'])) { echo json_encode(['success'=>false, 'msg'=>'권한없음']); exit; }
$uid = (int)$_SESSION['uid'];
$action = $_POST['action'] ?? '';

// ★ [핵심] 여러 날짜(월~금)의 ID가 한꺼번에 넘어올 때 모두 인식하도록 처리
if ($action === 'load' || $action === 'add') {
    $ids_str = $_POST['task_id'];
    $ids_arr = explode(',', $ids_str);
    $safe_ids = [];
    foreach($ids_arr as $id) {
        $id = (int)trim($id);
        if($id > 0) $safe_ids[] = $id;
    }
    if(empty($safe_ids)) { echo json_encode(['success'=>false, 'msg'=>'업무 ID 오류']); exit; }
    
    $in_clause = implode(',', $safe_ids);
    $first_id = $safe_ids[0]; // 댓글을 작성할 때 기준이 될 첫 번째 요일 ID
}

if ($action === 'load') {
    // 묶여있는 모든 요일의 댓글을 읽음 처리
    $read_sql = "UPDATE task_comments 
                 SET read_by = CONCAT(IFNULL(read_by,''), '$uid,') 
                 WHERE task_id IN ($in_clause) 
                   AND user_id != $uid 
                   AND IFNULL(read_by,'') NOT LIKE '%,$uid,%'";
    $conn->query($read_sql);

    // 묶여있는 모든 요일에 달린 댓글을 전부 불러와서 하나로 보여줌
    $sql = "SELECT c.*, u.nickname, u.position 
            FROM task_comments c 
            JOIN users u ON c.user_id = u.id 
            WHERE c.task_id IN ($in_clause) 
            ORDER BY c.created_at ASC";
    $res = $conn->query($sql);
    $comments = [];
    if($res) { while($row = $res->fetch_assoc()) $comments[] = $row; }
    
    echo json_encode(['success'=>true, 'data'=>$comments, 'current_uid'=>$uid]); exit;
}

if ($action === 'add') {
    $content = $conn->real_escape_string($_POST['content']);
    // 댓글은 중복 생성 방지를 위해 그룹의 첫 번째 요일에만 등록 (불러올 땐 공유됨)
    $conn->query("INSERT INTO task_comments (task_id, user_id, content) VALUES ($first_id, $uid, '$content')");
    echo json_encode(['success'=>true]); exit;
}

if ($action === 'delete') {
    $comment_id = (int)$_POST['comment_id'];
    $conn->query("DELETE FROM task_comments WHERE id=$comment_id AND user_id=$uid");
    echo json_encode(['success'=>true]); exit;
}
?>
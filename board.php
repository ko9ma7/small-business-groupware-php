<?php
// 파일명: /smw/board.php
session_start();
error_reporting(0); ini_set('display_errors', 0);
include 'db_conn.php';

if (!isset($_SESSION['uid'])) { header("Location: login.php"); exit; }
require_once 'groupware_shell.php';
$portal_identity = smw_portal_identity($conn);

$uid = (int)$_SESSION['uid'];
$my_position = '사원';
$is_admin = false;

$u_res = $conn->query("SELECT position, is_admin FROM users WHERE id = $uid");
if ($u_res && $u_res->num_rows > 0) {
    $u_info = $u_res->fetch_assoc();
    $my_position = $u_info['position'] ?? '사원';
    $is_admin = ($u_info['is_admin'] == 1 || $my_position === '사장');
}

$action = isset($_GET['action']) ? $_GET['action'] : 'list';
$category = isset($_GET['category']) ? $_GET['category'] : 'all';

// 글 작성 및 수정 (HTML 코드로 통째로 저장하여 양식 완벽 보존)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_post'])) {
    $board_type = $conn->real_escape_string($_POST['board_type']);
    $title = $conn->real_escape_string($_POST['title']);
    $content = $conn->real_escape_string($_POST['content']); // 에디터의 HTML 데이터
    $post_id = !empty($_POST['post_id']) ? (int)$_POST['post_id'] : 0;

    if ($post_id > 0) {
        // 관리자이거나 본인이면 수정 통과
        $auth_check = $is_admin ? "" : "AND author_id=$uid";
        $conn->query("UPDATE boards SET board_type='$board_type', title='$title', content='$content' WHERE id=$post_id $auth_check");
        echo "<script>alert('게시글이 성공적으로 수정되었습니다.'); location.href='board.php?action=view&id=$post_id';</script>"; exit;
    } else {
        $conn->query("INSERT INTO boards (board_type, title, content, author_id) VALUES ('$board_type', '$title', '$content', $uid)");
        echo "<script>alert('새 게시글이 등록되었습니다.'); location.href='board.php';</script>"; exit;
    }
}

if ($action === 'delete' && isset($_GET['id'])) {
    $del_id = (int)$_GET['id'];
    if ($is_admin) {
        $conn->query("DELETE FROM boards WHERE id=$del_id");
    } else {
        $conn->query("DELETE FROM boards WHERE id=$del_id AND author_id=$uid");
    }
    echo "<script>alert('삭제되었습니다.'); location.href='board.php';</script>"; exit;
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>사내 게시판 - <?= smw_h($portal_identity['name']) ?></title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="https://uicdn.toast.com/editor/latest/toastui-editor.min.css" />
<link rel="stylesheet" href="assets/groupware-shell.css?v=2">
<style>
    body { font-family: Pretendard, 'Noto Sans KR', 'Malgun Gothic', sans-serif; }
    /* 게시글 본문(HTML) 렌더링 스타일 보정 */
    .toastui-editor-contents { font-size: 15px; line-height: 1.6; color: #1f2937; }
</style>
</head>
<body class="gw-body min-h-screen pb-10">

    <?php smw_render_shell_header('board', '사내 통합 게시판', $is_admin); ?>

    <div class="max-w-6xl mx-auto mt-8 px-4">
        
        <?php if($action === 'list'): ?>
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
            <div class="p-4 border-b flex justify-between items-center bg-gray-50">
                <div class="flex gap-2">
                    <a href="?category=all" class="px-3 py-1 rounded text-sm font-bold <?= $category==='all' ? 'bg-indigo-600 text-white' : 'bg-gray-200 text-gray-700' ?>">전체</a>
                    <a href="?category=자료공유" class="px-3 py-1 rounded text-sm font-bold <?= $category==='자료공유' ? 'bg-indigo-600 text-white' : 'bg-gray-200 text-gray-700' ?>">자료공유</a>
                    <a href="?category=양식공유" class="px-3 py-1 rounded text-sm font-bold <?= $category==='양식공유' ? 'bg-indigo-600 text-white' : 'bg-gray-200 text-gray-700' ?>">양식공유</a>
                    <a href="?category=질문답변" class="px-3 py-1 rounded text-sm font-bold <?= $category==='질문답변' ? 'bg-indigo-600 text-white' : 'bg-gray-200 text-gray-700' ?>">질문답변</a>
                </div>
                <a href="?action=write" class="bg-indigo-600 text-white font-bold px-4 py-2 rounded text-sm"><i class="fa-solid fa-pen mr-1"></i> 글쓰기</a>
            </div>
            <table class="w-full text-sm text-center text-gray-600">
                <thead class="bg-gray-100 text-gray-700"><tr><th class="w-16 py-3">번호</th><th class="w-24">분류</th><th>제목</th><th class="w-32">작성자</th><th class="w-32">작성일</th></tr></thead>
                <tbody>
                    <?php 
                    $cat_sql = $category === 'all' ? "" : "WHERE b.board_type = '$category'";
                    $list_res = $conn->query("SELECT b.*, u.nickname, u.position FROM boards b JOIN users u ON b.author_id = u.id $cat_sql ORDER BY b.id DESC");
                    if(!$list_res || $list_res->num_rows == 0): ?>
                        <tr><td colspan="5" class="py-10 text-gray-400">등록된 게시글이 없습니다.</td></tr>
                    <?php else: while($row = $list_res->fetch_assoc()): ?>
                        <tr class="border-b hover:bg-indigo-50 transition cursor-pointer" onclick="location.href='?action=view&id=<?= $row['id'] ?>'">
                            <td class="py-3"><?= $row['id'] ?></td>
                            <td class="py-3"><span class="bg-gray-200 text-gray-700 px-2 py-1 rounded text-xs font-bold"><?= htmlspecialchars($row['board_type']) ?></span></td>
                            <td class="py-3 text-left font-bold text-gray-800 px-2 truncate max-w-[300px]"><?= htmlspecialchars($row['title']) ?></td>
                            <td class="py-3"><?= htmlspecialchars($row['nickname']) ?> <span class="text-xs text-gray-400"><?= htmlspecialchars($row['position']) ?></span></td>
                            <td class="py-3 text-xs text-gray-500"><?= substr($row['created_at'], 0, 10) ?></td>
                        </tr>
                    <?php endwhile; endif; ?>
                </tbody>
            </table>
        </div>

        <?php elseif($action === 'view' && isset($_GET['id'])): 
            $view_id = (int)$_GET['id'];
            $post_res = $conn->query("SELECT b.*, u.nickname, u.position FROM boards b JOIN users u ON b.author_id = u.id WHERE b.id = $view_id");
            if(!$post_res || $post_res->num_rows == 0) die("<script>alert('삭제되었거나 없는 글입니다.'); location.href='board.php';</script>");
            $post = $post_res->fetch_assoc();
            $is_author = ($post['author_id'] == $uid || $is_admin);
        ?>
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-8">
            <div class="mb-4">
                <span class="bg-indigo-100 text-indigo-800 px-2 py-1 rounded text-sm font-bold mr-2"><?= htmlspecialchars($post['board_type']) ?></span>
                <h2 class="text-2xl font-bold text-gray-900 inline align-middle"><?= htmlspecialchars($post['title']) ?></h2>
            </div>
            
            <div class="mt-6 border-t border-b py-6 min-h-[300px] toastui-editor-contents">
                <?= $post['content'] ?>
            </div>
            
            <div class="mt-6 flex justify-between">
                <a href="board.php" class="bg-gray-200 text-gray-800 font-bold px-4 py-2 rounded">목록으로</a>
                <?php if($is_author): ?>
                <div class="flex gap-2">
                    <a href="?action=write&id=<?= $post['id'] ?>" class="bg-emerald-600 text-white font-bold px-4 py-2 rounded">수정</a>
                    <a href="?action=delete&id=<?= $post['id'] ?>" onclick="return confirm('정말로 이 글을 삭제하시겠습니까?')" class="bg-red-600 text-white font-bold px-4 py-2 rounded">삭제</a>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php elseif($action === 'write'): 
            $edit_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
            $edit_data = ['board_type'=>'자료공유', 'title'=>'', 'content'=>''];
            if($edit_id > 0) {
                // 관리자면 본인 글 아니어도 수정 정보를 불러옴
                $auth_check = $is_admin ? "" : "AND author_id = $uid";
                $e_res = $conn->query("SELECT * FROM boards WHERE id = $edit_id $auth_check");
                if($e_res && $e_res->num_rows > 0) $edit_data = $e_res->fetch_assoc();
            }
        ?>
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <h2 class="font-bold text-xl mb-4 border-b pb-2"><?= $edit_id > 0 ? '게시글 수정' : '새 게시글 작성' ?></h2>
            <form method="POST" id="postForm">
                <input type="hidden" name="submit_post" value="1">
                <input type="hidden" name="post_id" value="<?= $edit_id ?>">
                
                <input type="hidden" name="content" id="real_content">
                
                <div class="flex gap-4 mb-4">
                    <div class="w-1/4">
                        <label class="block font-bold mb-1 text-gray-700">분류</label>
                        <select name="board_type" class="w-full p-2 border rounded focus:ring-2 focus:ring-indigo-500">
                            <option value="자료공유" <?= $edit_data['board_type']=='자료공유'?'selected':'' ?>>자료공유</option>
                            <option value="양식공유" <?= $edit_data['board_type']=='양식공유'?'selected':'' ?>>양식공유</option>
                            <option value="질문답변" <?= $edit_data['board_type']=='질문답변'?'selected':'' ?>>질문답변</option>
                        </select>
                    </div>
                    <div class="w-3/4">
                        <label class="block font-bold mb-1 text-gray-700">제목</label>
                        <input type="text" name="title" value="<?= htmlspecialchars($edit_data['title']) ?>" class="w-full p-2 border rounded focus:ring-2 focus:ring-indigo-500" required>
                    </div>
                </div>
                
                <div class="mb-4">
                    <label class="block font-bold mb-1 text-indigo-600">내용 (이미지를 바로 복사+붙여넣기 하세요! 자동 저장됩니다)</label>
                    <div id="editor"></div>
                </div>
                
                <textarea id="hidden_content_data" style="display:none;"><?= htmlspecialchars($edit_data['content'] ?? '') ?></textarea>
                
                <div class="flex justify-end gap-2">
                    <button type="button" onclick="history.back()" class="bg-gray-200 text-gray-800 font-bold px-6 py-2 rounded">취소</button>
                    <button type="submit" class="bg-indigo-600 text-white font-bold px-6 py-2 rounded hover:bg-indigo-700">작성 완료 (저장)</button>
                </div>
            </form>
        </div>
        
        <script src="https://uicdn.toast.com/editor/latest/toastui-editor-all.min.js"></script>
        <script>
            // 1. 기존 저장된 내용 가져오기 (오류 방지)
            const initVal = document.getElementById('hidden_content_data').value;
            
            // 2. 에디터 렌더링
            const editor = new toastui.Editor({
                el: document.querySelector('#editor'),
                height: '500px',
                initialEditType: 'wysiwyg', // ★ 기본 모드를 '일반 글쓰기(WYSIWYG)'로 고정
                previewStyle: 'vertical',
                hooks: {
                    // ★ 이미지 복사+붙여넣기 자동 WebP 서버 저장 로직
                    addImageBlobHook: async (blob, callback) => {
                        const fd = new FormData();
                        fd.append('file', blob);
                        try {
                            const res = await fetch('upload_image.php', {method:'POST', body:fd}).then(r=>r.json());
                            if(res.success) {
                                callback(res.url, 'Image'); // 에디터 창에 서버 이미지 주소 반환
                            } else {
                                alert(res.message);
                            }
                        } catch(e) { alert('이미지 업로드 서버 통신 실패'); }
                    }
                }
            });
            
            // 3. 에디터에 기존 내용 붓기 (HTML 형태 그대로 보존)
            editor.setHTML(initVal);

            // 4. 작성 완료 버튼을 누를 때, 에디터 내용을 Hidden Input에 담아서 서버로 전송
            document.getElementById('postForm').addEventListener('submit', function() {
                // 표, 글자색 등 완벽한 양식 보존을 위해 getHTML() 사용
                document.getElementById('real_content').value = editor.getHTML();
            });
        </script>
        <?php endif; ?>
    </div>
</body>
</html>

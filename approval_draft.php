<?php
// 파일명: /smw/approval_draft.php
session_start();
error_reporting(0); ini_set('display_errors', 0);
include 'db_conn.php';
if (!isset($_SESSION['uid'])) { header("Location: login.php"); exit; }
require_once 'groupware_shell.php';

$author_id = (int)$_SESSION['uid'];

$templates = $conn->query("SELECT * FROM approval_templates")->fetch_all(MYSQLI_ASSOC);

// ★ [신규 기능] 반려 문서 재기안 처리
$redraft_id = isset($_GET['redraft_id']) ? (int)$_GET['redraft_id'] : 0;
$edit_data = ['doc_type'=>'draft', 'title'=>'', 'content'=>''];

if ($redraft_id > 0) {
    // 내가 작성한 문서 중에 해당 문서 정보 불러오기
    $rd_res = $conn->query("SELECT * FROM e_documents WHERE id = $redraft_id AND author_id = $author_id");
    if ($rd_res && $rd_res->num_rows > 0) {
        $edit_data = $rd_res->fetch_assoc();
        // 제목 앞에 [재기안] 말머리 붙여주기
        $edit_data['title'] = "[재기안] " . $edit_data['title'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    smw_verify_csrf();
    $doc_type = $conn->real_escape_string($_POST['doc_type']);
    $title = $conn->real_escape_string($_POST['title']);
    $content = $conn->real_escape_string($_POST['content']); 
    $template_id = (int)$_POST['template_id'];

    $tpl_res = $conn->query("SELECT * FROM approval_templates WHERE id = $template_id");
    if($tpl_res && $tpl_res->num_rows > 0) {
        $tpl = $tpl_res->fetch_assoc();
        
        $stmt = $conn->prepare("INSERT INTO e_documents (author_id, doc_type, title, content, status) VALUES (?, ?, ?, ?, 'pending')");
        $stmt->bind_param("isss", $author_id, $doc_type, $title, $content);
        
        if ($stmt->execute()) {
            $doc_id = $stmt->insert_id;
            
            $step = 1;
            if (!empty($tpl['step1_id'])) { 
                $conn->query("INSERT INTO e_approvals (document_id, approver_id, step_order, assigned_at) VALUES ($doc_id, {$tpl['step1_id']}, $step, NOW())"); $step++; 
            }
            if (!empty($tpl['step2_id'])) { 
                $conn->query("INSERT INTO e_approvals (document_id, approver_id, step_order, assigned_at) VALUES ($doc_id, {$tpl['step2_id']}, $step, NULL)"); $step++; 
            }
            if (!empty($tpl['step3_id'])) { 
                $conn->query("INSERT INTO e_approvals (document_id, approver_id, step_order, assigned_at) VALUES ($doc_id, {$tpl['step3_id']}, $step, NULL)"); 
            }

            if (!empty($_FILES['attachments']['name'][0])) {
                $upload_dir = 'uploads/documents/';
                if (!is_dir($upload_dir)) @mkdir($upload_dir, 0777, true);
                $skipped_files = 0;

                $file_count = count($_FILES['attachments']['name']);
                for ($i = 0; $i < $file_count; $i++) {
                    $org_name = $_FILES['attachments']['name'][$i];
                    $file_size = $_FILES['attachments']['size'][$i];
                    $file = [
                        'name' => $org_name,
                        'tmp_name' => $_FILES['attachments']['tmp_name'][$i],
                        'size' => $file_size,
                        'error' => $_FILES['attachments']['error'][$i],
                    ];
                    [$valid, , $ext] = smw_validate_upload($file);
                    if (!$valid) {
                        $skipped_files++;
                        continue;
                    }
                    $new_name = smw_safe_upload_name($ext);
                    $dest_path = $upload_dir . $new_name;

                    if (move_uploaded_file($file['tmp_name'], $dest_path)) {
                        $file_stmt = $conn->prepare("INSERT INTO attachments (reference_type, reference_id, original_name, file_path, file_size) VALUES ('document', ?, ?, ?, ?)");
                        $file_stmt->bind_param("issi", $doc_id, $org_name, $dest_path, $file_size);
                        $file_stmt->execute();
                    }
                }
            }
            $upload_notice = !empty($skipped_files) ? " 허용되지 않거나 용량을 초과한 첨부파일 {$skipped_files}개는 제외했습니다." : '';
            echo "<script>alert(" . json_encode('결재 문서가 상신되었습니다.' . $upload_notice, JSON_UNESCAPED_UNICODE) . "); location.href='approval_list.php';</script>";
            exit;
        }
    } else { echo "<script>alert('유효하지 않은 결재선 템플릿입니다.');</script>"; }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>전자결재 상신</title>
<script src="https://cdn.tailwindcss.com"></script><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"><link rel="stylesheet" href="assets/groupware-shell.css?v=2">
<link rel="stylesheet" href="https://uicdn.toast.com/editor/latest/toastui-editor.min.css" />
<style>body { font-family: Pretendard, 'Noto Sans KR', 'Malgun Gothic', sans-serif; }</style>
</head>
<body class="gw-body min-h-screen pb-10">
    <?php smw_render_shell_header('approval', '결재 기안서 작성'); ?>

    <div class="max-w-4xl mx-auto mt-6 px-4">
        <div class="gw-surface gw-accent-border bg-white p-8 rounded-xl border-t-4">
            <?php if($redraft_id > 0): ?>
                <div class="mb-4 bg-red-50 text-red-700 border border-red-200 p-3 rounded font-bold text-sm"><i class="fa-solid fa-rotate-left mr-1"></i> 기존에 반려된 문서를 복구하여 수정 중입니다. 내용을 수정한 뒤 다시 상신하세요.</div>
            <?php endif; ?>

            <form method="POST" id="draftForm" enctype="multipart/form-data" class="space-y-5">
                <input type="hidden" name="smw_csrf" value="<?= smw_h(smw_csrf_token()) ?>">
                <input type="hidden" name="content" id="real_content">
                <div class="flex gap-4">
                    <div class="w-1/3">
                        <label class="block font-bold text-gray-700 mb-1">문서 종류</label>
                        <select name="doc_type" class="w-full p-2 border border-gray-300 rounded" required>
                            <option value="draft" <?= $edit_data['doc_type']=='draft'?'selected':'' ?>>기안서 (일반)</option>
                            <option value="proposal" <?= $edit_data['doc_type']=='proposal'?'selected':'' ?>>품의서 (비용/지출)</option>
                            <option value="leave" <?= $edit_data['doc_type']=='leave'?'selected':'' ?>>연차/휴가 신청서</option>
                        </select>
                    </div>
                    <div class="w-2/3">
                        <label class="block font-bold text-gray-700 mb-1">결재선 지정 (템플릿 재선택 필요)</label>
                        <select name="template_id" class="w-full p-2 border border-blue-300 bg-blue-50 rounded font-bold text-blue-800" required>
                            <option value="">-- 관리자가 지정한 결재 경로를 선택하세요 --</option>
                            <?php foreach($templates as $tpl): ?><option value="<?= $tpl['id'] ?>"><?= htmlspecialchars($tpl['name']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div>
                    <label class="block font-bold text-gray-700 mb-1">문서 제목</label>
                    <input type="text" name="title" value="<?= htmlspecialchars($edit_data['title']) ?>" required placeholder="예: [품의] OOO 구매 건" class="w-full p-3 border border-gray-300 rounded focus:ring-2 focus:ring-blue-500 text-lg font-bold">
                </div>
                
                <div>
                    <label class="block font-bold text-blue-700 mb-1">상세 내용 및 사유 (표/이미지 삽입 가능)</label>
                    <div id="editor"></div>
                    <textarea id="hidden_init_val" style="display:none;"><?= htmlspecialchars($edit_data['content']) ?></textarea>
                </div>
                
                <div>
                    <label class="block font-bold text-gray-700 mb-1">별도 증빙 파일 첨부</label>
                    <input type="file" name="attachments[]" multiple class="w-full p-2 border border-gray-300 rounded bg-gray-50 text-sm">
                </div>
                
                <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 rounded-lg shadow-md text-lg mt-4">문서 상신하기</button>
            </form>
        </div>
    </div>

    <script src="https://uicdn.toast.com/editor/latest/toastui-editor-all.min.js"></script>
    <script>
        const initVal = document.getElementById('hidden_init_val').value;
        const editor = new toastui.Editor({
            el: document.querySelector('#editor'), height: '400px', initialEditType: 'wysiwyg', previewStyle: 'vertical',
            hooks: { addImageBlobHook: async (blob, callback) => { const fd = new FormData(); fd.append('file', blob); fd.append('smw_csrf', <?= json_encode(smw_csrf_token()) ?>); try { const res = await fetch('upload_image.php', {method:'POST', body:fd}).then(r=>r.json()); if(res.success) callback(res.url, 'Image'); } catch(e) {} } }
        });
        
        // 재기안 시 에디터에 기존 HTML을 붓습니다
        if(initVal) { editor.setHTML(initVal); }

        document.getElementById('draftForm').addEventListener('submit', function() {
            document.getElementById('real_content').value = editor.getHTML();
        });
    </script>
</body>
</html>

<?php
// 파일명: /smw/upload_image.php
session_start();
error_reporting(0);
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/security.php';

if (!isset($_SESSION['uid'])) { 
    echo json_encode(["success" => false, "message" => "권한이 없습니다."]);
    exit; 
}
smw_verify_csrf();

$upload_dir = 'uploads/boards/';
if (!is_dir($upload_dir)) {
    @mkdir($upload_dir, 0777, true);
}

if (!isset($_FILES['file'])) {
    echo json_encode(["success" => false, "message" => "파일이 정상적으로 수신되지 않았습니다."]);
    exit;
}

$file = $_FILES['file'];
[$valid, $validationMessage, $ext] = smw_validate_upload($file, 'image');
if (!$valid) {
    echo json_encode(["success" => false, "message" => $validationMessage]);
    exit;
}

$file_tmp = $file['tmp_name'];
$dest_path = '';
$img = null;
if ($ext === 'jpg' || $ext === 'jpeg') {
    $img = @imagecreatefromjpeg($file_tmp);
} elseif ($ext === 'png') {
    $img = @imagecreatefrompng($file_tmp);
    if ($img) {
        @imagepalettetotruecolor($img);
        @imagealphablending($img, true);
        @imagesavealpha($img, true);
    }
} elseif ($ext === 'webp') {
    $img = @imagecreatefromwebp($file_tmp);
}

if ($img && function_exists('imagewebp')) {
    $dest_path = $upload_dir . smw_safe_upload_name('webp');
    $saved = @imagewebp($img, $dest_path, 80);
    @imagedestroy($img);
} else {
    $dest_path = $upload_dir . smw_safe_upload_name($ext);
    $saved = move_uploaded_file($file_tmp, $dest_path);
}

if ($saved && is_file($dest_path)) {
    echo json_encode(["success" => true, "url" => $dest_path]);
    exit;
}
echo json_encode(["success" => false, "message" => "이미지 변환 및 저장에 실패했습니다."]);

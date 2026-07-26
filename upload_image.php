<?php
// 파일명: /smw/upload_image.php
session_start();
// ★ 에러 출력 억제: 닷홈 서버에서 이미지 처리 중 경고가 발생해도 500 에러로 뻗지 않게 방어
error_reporting(0); 
ini_set('display_errors', 0);

if (!isset($_SESSION['uid'])) { 
    echo json_encode(["success" => false, "message" => "권한이 없습니다."]);
    exit; 
}

// 업로드 폴더 지정
$upload_dir = 'uploads/boards/';
if (!is_dir($upload_dir)) {
    @mkdir($upload_dir, 0777, true);
}

if (isset($_FILES['file']['name'])) {
    $file_tmp = $_FILES['file']['tmp_name'];
    $file_name = $_FILES['file']['name'];
    $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

    $allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    
    if (in_array($ext, $allowed_ext)) {
        $dest_path = '';

        // 1. GIF는 움짤 보존을 위해 변환 없이 원본 그대로 이동 및 저장
        if ($ext === 'gif') {
            $new_name = time() . '_' . mt_rand(1000, 9999) . '.gif';
            $dest_path = $upload_dir . $new_name;
            move_uploaded_file($file_tmp, $dest_path);
        } 
        // 2. JPG, PNG 등은 WebP로 압축 변환 (원본 파일은 서버에 남기지 않음)
        else {
            $img = null;
            if ($ext === 'jpg' || $ext === 'jpeg') {
                $img = @imagecreatefromjpeg($file_tmp);
            } elseif ($ext === 'png') { 
                $img = @imagecreatefrompng($file_tmp); 
                @imagepalettetotruecolor($img);
                @imagealphablending($img, true);
                @imagesavealpha($img, true);
            } elseif ($ext === 'webp') {
                $img = @imagecreatefromwebp($file_tmp);
            }

            // 서버에 WebP 변환 함수가 정상적으로 존재하고, 이미지 객체가 만들어졌다면
            if ($img && function_exists('imagewebp')) {
                $new_name = time() . '_' . mt_rand(1000, 9999) . '.webp';
                $dest_path = $upload_dir . $new_name;
                
                // 임시 파일(메모리)에서 바로 WebP 파일을 생성 (품질 80%)
                @imagewebp($img, $dest_path, 80); 
                @imagedestroy($img);
                
                // 원본 파일($file_tmp)은 PHP 종료 시 자동으로 메모리에서 소멸되므로 쓰레기 데이터가 남지 않음.
            } else {
                // 만약 서버 환경 오류로 이미지 객체화에 실패했다면 최후의 보루로 원본 저장
                $new_name = time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
                $dest_path = $upload_dir . $new_name;
                move_uploaded_file($file_tmp, $dest_path);
            }
        }

        // 최종적으로 파일이 잘 생성되었는지 확인 후 에디터로 경로 반환
        if (file_exists($dest_path)) {
            echo json_encode(["success" => true, "url" => $dest_path]);
            exit;
        } else {
            echo json_encode(["success" => false, "message" => "이미지 변환 및 저장에 실패했습니다."]);
            exit;
        }
    } else {
        echo json_encode(["success" => false, "message" => "허용되지 않는 이미지 확장자입니다."]);
        exit;
    }
}

echo json_encode(["success" => false, "message" => "파일이 정상적으로 수신되지 않았습니다."]);
?>
<?php
error_reporting(0);
ini_set('display_errors', 0);

$host = 'localhost';
$db_name = 'YOUR_DATABASE_NAME';
$db_user = 'YOUR_DATABASE_USER';
$db_pass = 'YOUR_DATABASE_PASSWORD';

$conn = new mysqli($host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    http_response_code(500);
    exit('데이터베이스 연결에 실패했습니다.');
}
$conn->set_charset('utf8mb4');

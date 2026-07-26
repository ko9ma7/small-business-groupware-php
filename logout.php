<?php
// 파일명: /smw/logout.php
session_start();
session_unset();    // 모든 세션 변수 해제
session_destroy();  // 세션 파기
header("Location: login.php"); // SMW 전용 로그인 페이지로 이동
exit;
?>
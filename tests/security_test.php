<?php
require_once __DIR__ . '/../security.php';

function assert_same($actual, $expected, string $label): void
{
    if ($actual !== $expected) {
        fwrite(STDERR, "$label: expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . "\n");
        exit(1);
    }
}

assert_same(smw_password_error('short1'), '비밀번호는 8자 이상으로 입력해 주세요.', '비밀번호 길이');
assert_same(smw_password_error('abcdefgh'), '비밀번호에는 영문과 숫자를 각각 하나 이상 포함해 주세요.', '비밀번호 구성');
assert_same(smw_password_error('safePass9'), '', '유효한 비밀번호');

$_SESSION = [];
$csrfToken = smw_csrf_token();
assert_same(strlen($csrfToken), 48, 'CSRF 토큰 길이');
$_POST['smw_csrf'] = $csrfToken;
smw_verify_csrf();

$now = 1_700_000_000;
assert_same(smw_login_retry_seconds([$now - 30, $now - 20], $now), 0, '실패 허용 범위');
assert_same(
    smw_login_retry_seconds([$now - 50, $now - 40, $now - 30, $now - 20, $now - 10], $now),
    890,
    '다섯 번째 실패 잠금'
);
assert_same(
    smw_login_retry_seconds([$now - 1000, $now - 900, $now - 800, $now - 700, $now - 600], $now),
    0,
    '오래된 실패 제외'
);

$imageRules = smw_upload_rules('image');
assert_same(isset($imageRules['extensions']['svg']), false, '실행형 SVG 차단');
assert_same($imageRules['max_bytes'], SMW_IMAGE_MAX_BYTES, '이미지 용량 제한');

$schemaFiles = ['install.sql', 'extension_schema.sql', 'smw_extensions.php'];
foreach ($schemaFiles as $schemaFile) {
    $schema = file_get_contents(__DIR__ . '/../' . $schemaFile);
    assert_same(strpos($schema, 'login_attempts') !== false, true, "$schemaFile 로그인 시도 테이블");
    assert_same(strpos($schema, 'audit_logs') !== false, true, "$schemaFile 감사 로그 테이블");
    assert_same(strpos($schema, 'report_entry_presets') !== false, true, "$schemaFile 업무 묶음 보관함 테이블");
}

$csrfPages = ['approval_draft.php', 'approval_process.php', 'board.php', 'schedule.php'];
foreach ($csrfPages as $csrfPage) {
    $source = file_get_contents(__DIR__ . '/../' . $csrfPage);
    assert_same(strpos($source, 'smw_verify_csrf();') !== false, true, "$csrfPage CSRF 검증");
    assert_same(strpos($source, 'name="smw_csrf"') !== false, true, "$csrfPage CSRF 토큰");
}
assert_same(strpos(file_get_contents(__DIR__ . '/../board.php'), 'action=delete') !== false, false, '게시글 GET 삭제 차단');
assert_same(strpos(file_get_contents(__DIR__ . '/../schedule.php'), '$_GET[\'del_id\']') !== false, false, '일정 GET 삭제 차단');
assert_same(strpos(file_get_contents(__DIR__ . '/../upload_image.php'), 'smw_verify_csrf();') !== false, true, '이미지 업로드 CSRF 검증');
$presetApi = file_get_contents(__DIR__ . '/../report_preset_api.php');
assert_same(strpos($presetApi, 'smw_verify_csrf();') !== false, true, '업무 묶음 API CSRF 검증');
assert_same(substr_count($presetApi, 'user_id=?') >= 3, true, '업무 묶음 사용자 소유권 제한');
assert_same(strpos($presetApi, 'DELETE FROM report_entry_presets') !== false, false, '업무 묶음 영구 삭제 차단');
assert_same(strpos($presetApi, 'deleted_at=NOW()') !== false, true, '업무 묶음 휴지통 이동');
foreach (['approval_draft.php', 'board.php', 'daily.php', 'index.php'] as $uploadCaller) {
    $source = file_get_contents(__DIR__ . '/../' . $uploadCaller);
    assert_same(
        substr_count($source, "fetch('upload_image.php'")
            === preg_match_all("/fd\\.append\\('file', blob\\);\\s*fd\\.append\\('smw_csrf'/", $source),
        true,
        "$uploadCaller 이미지 업로드 CSRF 토큰"
    );
}

echo "security checks passed\n";

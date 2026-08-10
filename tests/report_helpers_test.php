<?php
require_once __DIR__ . '/../report_helpers.php';

$first = smw_result_lines('<p>공정 도면 작성 및 외주품 진행.</p><ul><li>탱크 도면</li><li>프레임 도면, 프레임cover</li><li>액추에이터</li></ul>');
$second = smw_result_lines('<p>공정 도면 작성 및 외주품 진행.</p><ul><li>탱크 도면</li><li>프레임 도면, 프레임cover</li><li>액추에이터</li><li>추가작업</li></ul>');
$merged = array_values(array_unique(array_merge($first, $second)));

if (count($merged) !== 5 || !in_array('추가작업', $merged, true)) {
    fwrite(STDERR, "result line merge failed\n");
    exit(1);
}
$datedItems = [];
smw_add_result_items($datedItems, '<p>탱크 도면</p><p>액추에이터</p>', '2026-08-10');
smw_add_result_items($datedItems, '<p>탱크 도면</p><p>액추에이터</p><p>추가작업</p>', '2026-08-11');
$addedKey = mb_strtolower('추가작업', 'UTF-8');
$tankKey = mb_strtolower('탱크 도면', 'UTF-8');
if (($datedItems[$addedKey]['dates'] ?? []) !== ['2026-08-11'] || count($datedItems[$tankKey]['dates'] ?? []) !== 2) {
    fwrite(STDERR, "result item date merge failed\n");
    exit(1);
}
if (substr_count(smw_weekday_result_html("김성근: 탱크 도면\n진택: 프레임 작업"), '<p>') !== 2) {
    fwrite(STDERR, "weekday HTML conversion failed\n");
    exit(1);
}
$technical = smw_spellcheck_issue('프레임cover', '프레임 cover', '제안');
$knownTypo = smw_spellcheck_issue('날자', '날짜', '기본 규칙', true);
if ($technical['safe'] !== false || $knownTypo['safe'] !== true) {
    fwrite(STDERR, "spellcheck safety classification failed\n");
    exit(1);
}
$preset = smw_normalize_preset_payload([
    'entry_mode' => 'team', 'worker_ids' => ['7', 7, 0, 11], 'weekday_mode' => 1,
    'weekday_results' => ['1' => '김성근: 탱크 도면', '9' => '제외'], 'company_name' => '  프로젝트 A  '
]);
if ($preset['worker_ids'] !== [7, 11] || array_keys($preset['weekday_results']) !== [1] || $preset['company_name'] !== '프로젝트 A') {
    fwrite(STDERR, "preset payload normalization failed\n");
    exit(1);
}

echo "report helper checks passed\n";

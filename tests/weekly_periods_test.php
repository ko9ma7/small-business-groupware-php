<?php
require_once __DIR__ . '/../report_periods.php';

$cases = [
    [smw_next_weekday_date('2026-07-26', 1), '2026-07-27', '일요일 다음 월요일'],
    [smw_next_weekday_date('2026-07-28', 1), '2026-08-03', '화요일 다음 월요일'],
    [smw_calculate_weekly_periods('2026-07-27', 'previous_current')['actual_start'], '2026-07-20', '전주 실적 시작'],
    [smw_calculate_weekly_periods('2026-07-27', 'previous_current')['plan_end'], '2026-08-02', '금주 계획 종료'],
    [smw_calculate_weekly_periods('2026-07-27', 'current_next')['actual_start'], '2026-07-27', '금주 실적 시작'],
    [smw_calculate_weekly_periods('2026-07-27', 'current_next')['plan_end'], '2026-08-09', '차주 계획 종료'],
    [implode(',', smw_registration_dates('2026-07-31', '2026-08-03', false, false)), '2026-07-31,2026-08-03', '주말 기본 제외'],
    [implode(',', smw_registration_dates('2026-07-31', '2026-08-03', true, false)), '2026-07-31,2026-08-01,2026-08-03', '토요일 선택 포함'],
    [implode(',', smw_registration_dates('2026-07-31', '2026-08-03', false, true)), '2026-07-31,2026-08-02,2026-08-03', '일요일 선택 포함'],
];

foreach ($cases as [$actual, $expected, $label]) {
    if ($actual !== $expected) {
        fwrite(STDERR, "$label: expected $expected, got $actual\n");
        exit(1);
    }
}

echo "weekly period checks passed\n";

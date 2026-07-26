<?php

function smw_weekday_label(int $weekday): string
{
    return [1=>'월요일', 2=>'화요일', 3=>'수요일', 4=>'목요일', 5=>'금요일', 6=>'토요일', 7=>'일요일'][$weekday] ?? '월요일';
}

function smw_next_weekday_date(string $date, int $weekday): string
{
    $weekday = max(1, min(7, $weekday));
    $value = DateTime::createFromFormat('!Y-m-d', $date) ?: new DateTime();
    $delta = ($weekday - (int)$value->format('N') + 7) % 7;
    if ($delta > 0) $value->modify("+$delta days");
    return $value->format('Y-m-d');
}

function smw_calculate_weekly_periods(string $referenceDate, string $basis): array
{
    $reference = DateTime::createFromFormat('!Y-m-d', $referenceDate) ?: new DateTime();
    $weekStart = clone $reference;
    $weekStart->modify('-' . ((int)$weekStart->format('N') - 1) . ' days');

    $previousCurrent = $basis !== 'current_next';
    $actualStart = clone $weekStart;
    if ($previousCurrent) $actualStart->modify('-7 days');
    $actualEnd = (clone $actualStart)->modify('+6 days');
    $planStart = (clone $actualStart)->modify('+7 days');
    $planEnd = (clone $planStart)->modify('+6 days');

    return [
        'reference_date' => $reference->format('Y-m-d'),
        'basis' => $previousCurrent ? 'previous_current' : 'current_next',
        'actual_start' => $actualStart->format('Y-m-d'),
        'actual_end' => $actualEnd->format('Y-m-d'),
        'plan_start' => $planStart->format('Y-m-d'),
        'plan_end' => $planEnd->format('Y-m-d'),
        'actual_label' => $previousCurrent ? '전주 진행 실적' : '금주 진행 실적',
        'actual_short' => $previousCurrent ? '전주' : '금주',
        'plan_label' => $previousCurrent ? '금주 예정 계획' : '차주 예정 계획',
        'plan_short' => $previousCurrent ? '금주' : '차주',
    ];
}

function smw_registration_dates(string $startDate, string $endDate, bool $includeSaturday, bool $includeSunday): array
{
    $start = DateTime::createFromFormat('!Y-m-d', $startDate);
    $end = DateTime::createFromFormat('!Y-m-d', $endDate);
    if (!$start || !$end) return [];
    if ($end < $start) $end = clone $start;

    $dates = [];
    for ($date = clone $start; $date <= $end; $date->modify('+1 day')) {
        $weekday = (int)$date->format('N');
        if (($weekday === 6 && !$includeSaturday) || ($weekday === 7 && !$includeSunday)) continue;
        $dates[] = $date->format('Y-m-d');
    }
    return $dates;
}

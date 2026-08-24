<?php

function smw_result_lines(string $html): array
{
    $text = preg_replace('/<\s*br\s*\/?>/i', "\n", $html);
    $text = preg_replace('/<\/(p|div|li|tr|h[1-6])>/i', "\n", (string)$text);
    $text = html_entity_decode(strip_tags((string)$text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $lines = preg_split('/\R+/u', $text) ?: [];
    $result = [];
    foreach ($lines as $line) {
        $line = preg_replace('/^[\s\-•·]+/u', '', trim($line));
        $line = preg_replace('/\s+/u', ' ', (string)$line);
        if ($line === '') continue;
        $key = mb_strtolower($line, 'UTF-8');
        if (!isset($result[$key])) $result[$key] = $line;
    }
    return array_values($result);
}

function smw_weekday_result_html(string $text): string
{
    $lines = preg_split('/\R+/u', trim($text)) ?: [];
    $html = '';
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $html .= '<p>' . htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . '</p>';
    }
    return $html;
}

function smw_field_day_metadata(int $weekday, array $summaries): array
{
    $weekday = max(1, min(7, $weekday));
    $labels = [1=>'월',2=>'화',3=>'수',4=>'목',5=>'금',6=>'토',7=>'일'];
    $summary = trim((string)($summaries[$weekday] ?? ''));
    return ['company_name' => $labels[$weekday], 'plan_content' => $summary !== '' ? $summary : '현장 작업'];
}

function smw_field_item_result_html(array $workerNames, string $text): string
{
    $workerNames = array_values(array_unique(array_filter(array_map('trim', $workerNames))));
    $prefix = $workerNames ? implode(', ', $workerNames) . ': ' : '';
    $lines = preg_split('/\R+/u', trim($text)) ?: [];
    $html = '';
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $html .= '<p>' . htmlspecialchars($prefix . $line, ENT_QUOTES, 'UTF-8') . '</p>';
    }
    return $html;
}

function smw_normalize_field_items(array $items): array
{
    $normalized = [];
    foreach (array_slice($items, 0, 50) as $item) {
        if (!is_array($item)) continue;
        $workerIds = array_values(array_unique(array_filter(array_map('intval', (array)($item['workers'] ?? [])))));
        $normalized[] = [
            'company' => mb_substr(trim((string)($item['company'] ?? '')), 0, 100, 'UTF-8'),
            'summary' => mb_substr(trim((string)($item['summary'] ?? '')), 0, 200, 'UTF-8'),
            'result' => mb_substr(trim((string)($item['result'] ?? '')), 0, 10000, 'UTF-8'),
            'start_date' => trim((string)($item['start_date'] ?? '')),
            'end_date' => trim((string)($item['end_date'] ?? '')),
            'workers' => array_slice($workerIds, 0, 200),
            'include_saturday' => !empty($item['include_saturday']),
            'include_sunday' => !empty($item['include_sunday']),
        ];
    }
    return $normalized;
}

function smw_add_result_items(array &$items, string $html, string $date): void
{
    foreach (smw_result_lines($html) as $resultLine) {
        $key = mb_strtolower($resultLine, 'UTF-8');
        if (!isset($items[$key])) $items[$key] = ['text' => $resultLine, 'dates' => []];
        if (!in_array($date, $items[$key]['dates'], true)) $items[$key]['dates'][] = $date;
    }
}

function smw_date_runs(array $dates): array
{
    $dates = array_values(array_unique(array_filter(array_map('strval', $dates))));
    sort($dates);
    $runs = [];
    foreach ($dates as $date) {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) continue;
        $lastIndex = count($runs) - 1;
        if ($lastIndex >= 0 && (new DateTimeImmutable($runs[$lastIndex][1]))->modify('+1 day')->format('Y-m-d') === $date) {
            $runs[$lastIndex][1] = $date;
        } else {
            $runs[] = [$date, $date];
        }
    }
    return $runs;
}

function smw_without_dates(array $dates, array $excludedDates): array
{
    $excluded = array_fill_keys(array_map('strval', $excludedDates), true);
    return array_values(array_filter($dates, static fn($date) => !isset($excluded[(string)$date])));
}

function smw_spellcheck_issue(string $original, string $revised, string $help, bool $trustedRule = false): array
{
    $hasTechnicalToken = preg_match('/[A-Za-z0-9_\/\\-]/u', $original . $revised) === 1;
    $changesStructure = preg_match('/[\r\n]/u', $original . $revised) === 1;
    $safe = $trustedRule || (!$hasTechnicalToken && !$changesStructure && mb_strlen($original, 'UTF-8') <= 30 && mb_strlen($revised, 'UTF-8') <= 30);
    return [
        'original' => $original,
        'revised' => $revised,
        'help' => $help,
        'safe' => $safe,
        'warning' => $safe ? '' : '영문·숫자·도면명 등 전문용어 가능성이 있어 자동 선택하지 않았습니다.',
    ];
}

function smw_normalize_preset_payload(array $payload): array
{
    $workerIds = array_values(array_unique(array_filter(array_map('intval', (array)($payload['worker_ids'] ?? [])))));
    $weekdayResults = [];
    $weekdaySummaries = [];
    foreach ((array)($payload['weekday_results'] ?? []) as $day => $value) {
        $day = (int)$day;
        if ($day >= 1 && $day <= 7) $weekdayResults[(string)$day] = mb_substr((string)$value, 0, 5000, 'UTF-8');
    }
    foreach ((array)($payload['weekday_summaries'] ?? []) as $day => $value) {
        $day = (int)$day;
        $value = trim((string)$value);
        if ($day >= 1 && $day <= 7 && $value !== '') $weekdaySummaries[$day] = mb_substr($value, 0, 120, 'UTF-8');
    }
    return [
        'entry_mode' => ($payload['entry_mode'] ?? 'self') === 'team' ? 'team' : 'self',
        'worker_ids' => array_slice($workerIds, 0, 200),
        'weekday_mode' => !empty($payload['weekday_mode']),
        'weekday_results' => $weekdayResults,
        'weekday_summaries' => $weekdaySummaries,
        'field_items' => smw_normalize_field_items((array)($payload['field_items'] ?? [])),
        'company_name' => mb_substr(trim((string)($payload['company_name'] ?? '')), 0, 100, 'UTF-8'),
        'task_category' => mb_substr(trim((string)($payload['task_category'] ?? '일반업무')), 0, 50, 'UTF-8'),
        'plan_content' => mb_substr(trim((string)($payload['plan_content'] ?? '')), 0, 2000, 'UTF-8'),
        'result_content' => mb_substr((string)($payload['result_content'] ?? ''), 0, 50000, 'UTF-8'),
    ];
}

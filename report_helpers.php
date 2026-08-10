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

function smw_add_result_items(array &$items, string $html, string $date): void
{
    foreach (smw_result_lines($html) as $resultLine) {
        $key = mb_strtolower($resultLine, 'UTF-8');
        if (!isset($items[$key])) $items[$key] = ['text' => $resultLine, 'dates' => []];
        if (!in_array($date, $items[$key]['dates'], true)) $items[$key]['dates'][] = $date;
    }
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

<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once 'db_conn.php';
require_once 'smw_extensions.php';

if (!isset($_SESSION['uid'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '로그인이 필요합니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => '지원하지 않는 요청입니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

smw_verify_csrf();
$text = trim((string)($_POST['text'] ?? ''));
if ($text === '') {
    echo json_encode(['success' => false, 'message' => '검사할 문장을 입력해 주세요.'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (mb_strlen($text, 'UTF-8') > 5000) {
    echo json_encode(['success' => false, 'message' => '한 번에 5,000자까지 검사할 수 있습니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

function smw_local_spellcheck(string $text): array
{
    $rules = [
        ['/(최슨)/u', '최근', '자주 발생하는 자판 입력 오류를 확인했습니다.'],
        ['/(날자)/u', '날짜', '날짜를 뜻할 때는 ‘날짜’로 씁니다.'],
        ['/(되서)/u', '돼서', '‘되어서’의 준말은 ‘돼서’입니다.'],
        ['/(됬)/u', '됐', '‘되었’의 준말은 ‘됐’입니다.'],
        ['/(안되요)/u', '안 돼요', '부정 표현과 ‘되어요’의 준말을 함께 바로잡았습니다.'],
        ['/(되요)/u', '돼요', '‘되어요’의 준말은 ‘돼요’입니다.'],
        ['/(안됩니다)/u', '안 됩니다', '부정 부사 ‘안’을 띄어 씁니다.'],
        ['/(됨니다)/u', '됩니다', '받침 뒤 어미 표기를 확인했습니다.'],
        ['/(합나다)/u', '합니다', '자주 발생하는 자판 입력 오류를 확인했습니다.'],
        ['/(햇)(?=[가-힣])/u', '했', '과거형 표기를 확인했습니다.'],
        ['/(하겟)/u', '하겠', '어미 ‘-겠-’ 표기를 확인했습니다.'],
        ['/(있읍니다)/u', '있습니다', '현재 표준어는 ‘있습니다’입니다.'],
        ['/(없읍니다)/u', '없습니다', '현재 표준어는 ‘없습니다’입니다.'],
        ['/(하였읍니다)/u', '하였습니다', '현재 표준어는 ‘하였습니다’입니다.'],
        ['/(몇일)/u', '며칠', '날짜를 셀 때는 ‘며칠’로 씁니다.'],
        ['/(몇가지)/u', '몇 가지', '의존 명사 ‘가지’를 띄어 씁니다.'],
        ['/(한가지)/u', '한 가지', '의존 명사 ‘가지’를 띄어 씁니다.'],
        ['/(교채)/u', '교체', '현장에서 자주 발생하는 입력 오류입니다.'],
        ['/(업무요약)/u', '업무 요약', '명사 결합의 가독성을 위해 띄어 씁니다.'],
        ['/(상세내용)/u', '상세 내용', '명사 결합의 가독성을 위해 띄어 씁니다.'],
        ['/(오타부분)/u', '오타 부분', '명사 사이를 띄어 씁니다.'],
        ['/(이부분)/u', '이 부분', '지시 관형사와 명사를 띄어 씁니다.'],
        ['/(그부분)/u', '그 부분', '지시 관형사와 명사를 띄어 씁니다.'],
        ['/(저만그런가요)/u', '저만 그런가요', '문장 성분 사이를 띄어 씁니다.'],
        ['/(되어있)/u', '되어 있', '보조 용언 구성을 띄어 씁니다.'],
        ['/(할일)/u', '할 일', '관형어와 명사를 띄어 씁니다.'],
        ['/(할수있)/u', '할 수 있', '의존 명사 ‘수’를 띄어 씁니다.'],
        ['/(할수)/u', '할 수', '의존 명사 ‘수’를 띄어 씁니다.'],
        ['/(볼수)/u', '볼 수', '의존 명사 ‘수’를 띄어 씁니다.'],
        ['/(쓸수)/u', '쓸 수', '의존 명사 ‘수’를 띄어 씁니다.'],
        ['/(될수)/u', '될 수', '의존 명사 ‘수’를 띄어 씁니다.'],
        ['/(알수)/u', '알 수', '의존 명사 ‘수’를 띄어 씁니다.'],
        ['/(입력시)/u', '입력 시', '때를 뜻하는 ‘시’를 띄어 씁니다.'],
        ['/(기입시)/u', '기입 시', '때를 뜻하는 ‘시’를 띄어 씁니다.'],
        ['/(작성시)/u', '작성 시', '때를 뜻하는 ‘시’를 띄어 씁니다.'],
        ['/(선택시)/u', '선택 시', '때를 뜻하는 ‘시’를 띄어 씁니다.'],
        ['/(검사시)/u', '검사 시', '때를 뜻하는 ‘시’를 띄어 씁니다.'],
        ['/(저장시)/u', '저장 시', '때를 뜻하는 ‘시’를 띄어 씁니다.'],
        ['/(등록시)/u', '등록 시', '때를 뜻하는 ‘시’를 띄어 씁니다.'],
        ['/(확인시)/u', '확인 시', '때를 뜻하는 ‘시’를 띄어 씁니다.'],
        ['/(금일중)/u', '금일 중', '의존 명사 앞을 띄어 씁니다.'],
        ['/(작업중)/u', '작업 중', '의존 명사 ‘중’을 띄어 씁니다.'],
        ['/(점검중)/u', '점검 중', '의존 명사 ‘중’을 띄어 씁니다.'],
        ['/(확인후)/u', '확인 후', '명사 ‘후’를 띄어 씁니다.'],
        ['/(작업후)/u', '작업 후', '명사 ‘후’를 띄어 씁니다.'],
        ['/(용접후)/u', '용접 후', '명사 ‘후’를 띄어 씁니다.'],
        ['/(가공후)/u', '가공 후', '명사 ‘후’를 띄어 씁니다.'],
        ['/(이상없음)/u', '이상 없음', '명사 사이를 띄어 씁니다.'],
        ['/(못먹)/u', '못 먹', '부정 부사 ‘못’을 띄어 씁니다.'],
        ['/[ ]{2,}/u', ' ', '연속된 공백을 하나로 정리했습니다.'],
    ];

    $revised = $text;
    $issues = [];
    foreach ($rules as [$pattern, $replacement, $help]) {
        if (preg_match_all($pattern, $revised, $matches)) {
            foreach (array_unique($matches[0]) as $matched) {
                $issues[] = ['original' => $matched, 'revised' => $replacement, 'help' => $help];
            }
            $revised = preg_replace($pattern, $replacement, $revised);
        }
    }
    return ['revised' => $revised, 'issues' => $issues];
}

function smw_bareun_issues(array $response): array
{
    $helps = $response['helps'] ?? [];
    $issues = [];
    $walk = function(array $blocks) use (&$walk, &$issues, $helps): void {
        foreach ($blocks as $block) {
            if (!empty($block['nested'])) {
                $walk($block['nested']);
                continue;
            }
            $origin = (string)($block['origin']['content'] ?? '');
            $revised = (string)($block['revised'] ?? '');
            if ($origin === '' || $revised === '' || $origin === $revised) continue;
            $help = '';
            $helpId = (string)($block['revisions'][0]['help_id'] ?? '');
            if ($helpId !== '' && isset($helps[$helpId]['comment'])) $help = (string)$helps[$helpId]['comment'];
            $issues[] = ['original' => $origin, 'revised' => $revised, 'help' => $help];
        }
    };
    $walk((array)($response['revised_blocks'] ?? []));
    return array_slice($issues, 0, 30);
}

$apiKey = trim((string)getenv('BAREUN_API_KEY'));
$apiKeySource = $apiKey !== '' ? 'environment' : '';
if ($apiKey === '') {
    try {
        $settingResult = $conn->query("SELECT setting_value FROM site_settings WHERE setting_key='bareun_api_key' LIMIT 1");
        if ($settingResult && $settingResult->num_rows > 0) {
            $apiKey = trim((string)$settingResult->fetch_assoc()['setting_value']);
            if ($apiKey !== '') $apiKeySource = 'admin';
        }
    } catch (Throwable $e) {
        $apiKey = '';
    }
}
$configPath = __DIR__ . '/spellcheck_config.php';
if ($apiKey === '' && is_file($configPath)) {
    $config = require $configPath;
    if (is_array($config)) {
        $apiKey = trim((string)($config['bareun_api_key'] ?? ''));
        if ($apiKey !== '') $apiKeySource = 'file';
    }
}

$apiError = '';
if ($apiKey !== '' && function_exists('curl_init')) {
    $payload = json_encode([
        'document' => ['content' => $text, 'language' => 'ko-KR'],
        'encoding_type' => 'UTF8',
        'config' => ['enable_cleanup_whitespace' => true],
    ], JSON_UNESCAPED_UNICODE);
    $curl = curl_init('https://api.bareun.ai:443/bareun.RevisionService/CorrectError');
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['api-key: ' . $apiKey, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $payload,
    ]);
    $body = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    $response = is_string($body) ? json_decode($body, true) : null;
    if ($status >= 200 && $status < 300 && is_array($response) && isset($response['revised'])) {
        echo json_encode([
            'success' => true,
            'provider' => 'bareun',
            'provider_label' => '바른AI 정밀 검사',
            'is_fallback' => false,
            'notice' => ($apiKeySource === 'admin' ? '관리자 API 설정에 저장된 바른AI로 ' : '바른AI로 ') . '맞춤법·띄어쓰기·표준어를 문맥 기반으로 검사했습니다.',
            'revised' => (string)$response['revised'],
            'issues' => smw_bareun_issues($response),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $apiError = $status > 0 ? '바른AI 응답 오류(' . $status . ')' : '바른AI 연결 실패';
} elseif ($apiKey !== '' && !function_exists('curl_init')) {
    $apiError = '서버의 cURL 확장이 비활성화되어 바른AI에 연결하지 못했습니다.';
}

$local = smw_local_spellcheck($text);
$notice = $apiKey === ''
    ? '현재 바른AI API 키가 없어 제한된 기본 규칙만 검사했습니다. 모든 오타를 찾는 정밀 검사 결과가 아닙니다.'
    : $apiError . '로 제한된 기본 규칙만 검사했습니다.';
echo json_encode([
    'success' => true,
    'provider' => 'local',
    'provider_label' => '기본 검사 · 정밀 API 미연결',
    'is_fallback' => true,
    'notice' => $notice,
    'revised' => $local['revised'],
    'issues' => $local['issues'],
], JSON_UNESCAPED_UNICODE);

<?php
// 파일명: /smw/admin.php
session_start();
error_reporting(0); ini_set('display_errors', 0);
include 'db_conn.php';

if (!isset($_SESSION['uid'])) { header("Location: login.php"); exit; }
$uid = (int)$_SESSION['uid'];
$is_admin = isset($_SESSION['admin']) ? (int)$_SESSION['admin'] : 0;
if ($is_admin != 1) die("<script>alert('시스템 관리자 권한이 없습니다.'); history.back();</script>");
require_once 'groupware_shell.php';
require_once 'smw_extensions.php';

$conn->query("CREATE TABLE IF NOT EXISTS site_settings (setting_key VARCHAR(50) PRIMARY KEY, setting_value VARCHAR(255) NOT NULL)");
$conn->query("INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES
    ('portal_name', 'GROUPWARE'), ('portal_company_label', ''),
    ('weekly_meeting_weekday', '1'), ('weekly_period_basis', 'previous_current'),
    ('registration_enabled', '1'),
    ('approval_timeout', '0'), ('bareun_api_key', ''), ('gemini_api_key', ''), ('gemini_model', 'gemini-2.5-flash')");
$admin_csrf = smw_csrf_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'];
    smw_verify_csrf();

    if ($action === 'delete_user') {
        $tid = (int)$_POST['target_uid'];
        if($tid === $uid) die(json_encode(['success'=>false, 'msg'=>'본인은 삭제 불가']));
        $conn->query("DELETE FROM users WHERE id = $tid");
        echo json_encode(['success'=>true, 'msg'=>'삭제되었습니다.']); exit;
    }
    if ($action === 'update_user') {
        $tid = (int)$_POST['target_uid'];
        $set_admin = (int)$_POST['is_admin']; 
        $nickname = $conn->real_escape_string($_POST['nickname']);
        $position = $conn->real_escape_string($_POST['position']); 
        $phone = $conn->real_escape_string($_POST['phone']); 
        $email = $conn->real_escape_string($_POST['email']); 
        $b_type = $conn->real_escape_string($_POST['birth_type']); 
        $b_date = !empty($_POST['birth_date']) ? "'" . $conn->real_escape_string($_POST['birth_date']) . "'" : "NULL";
        
        $pw_query = "";
        if (!empty($_POST['new_password'])) {
            $hashed_pw = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
            $pw_query = ", password = '$hashed_pw'";
        }
        $conn->query("UPDATE users SET is_admin=$set_admin, nickname='$nickname', position='$position', phone='$phone', email='$email', birth_type='$b_type', birth_date=$b_date $pw_query WHERE id=$tid");
        echo json_encode(['success'=>true, 'msg'=>'업데이트 완료']); exit;
    }
    if ($action === 'add_relation') {
        $v = (int)$_POST['viewer_id']; 
        $targets = $_POST['target_ids'] ?? [];
        if(empty($targets)) die(json_encode(['success'=>false, 'msg'=>'하급자를 선택하세요.']));
        foreach($targets as $t) {
            $t = (int)$t;
            if($v !== $t) $conn->query("INSERT IGNORE INTO user_relations (viewer_id, target_id) VALUES ($v, $t)");
        }
        echo json_encode(['success'=>true, 'msg'=>'일괄 연결 완료']); exit;
    }
    if ($action === 'del_relation') {
        $v = (int)$_POST['viewer_id']; $t = (int)$_POST['target_id'];
        $conn->query("DELETE FROM user_relations WHERE viewer_id=$v AND target_id=$t");
        echo json_encode(['success'=>true, 'msg'=>'해제 완료']); exit;
    }
    if ($action === 'add_template') {
        $name = $conn->real_escape_string($_POST['name']);
        $s1 = !empty($_POST['step1']) ? (int)$_POST['step1'] : "NULL";
        $s2 = !empty($_POST['step2']) ? (int)$_POST['step2'] : "NULL";
        $s3 = !empty($_POST['step3']) ? (int)$_POST['step3'] : "NULL";
        $conn->query("INSERT INTO approval_templates (name, step1_id, step2_id, step3_id) VALUES ('$name', $s1, $s2, $s3)");
        echo json_encode(['success'=>true, 'msg'=>'템플릿 추가 완료']); exit;
    }
    if ($action === 'del_template') {
        $tid = (int)$_POST['template_id'];
        $conn->query("DELETE FROM approval_templates WHERE id=$tid");
        echo json_encode(['success'=>true, 'msg'=>'삭제 완료']); exit;
    }
    if ($action === 'save_global_settings') {
        $timeout = (int)$_POST['approval_timeout'];
        $conn->query("UPDATE site_settings SET setting_value='$timeout' WHERE setting_key='approval_timeout'");
        echo json_encode(['success'=>true, 'msg'=>'설정이 저장되었습니다.']); exit;
    }
    if ($action === 'save_portal_settings') {
        $portalName = mb_substr(trim((string)($_POST['portal_name'] ?? '')), 0, 80, 'UTF-8');
        $companyLabel = mb_substr(trim((string)($_POST['portal_company_label'] ?? '')), 0, 160, 'UTF-8');
        $meetingWeekday = max(1, min(7, (int)($_POST['weekly_meeting_weekday'] ?? 1)));
        $periodBasis = (string)($_POST['weekly_period_basis'] ?? 'previous_current');
        $registrationEnabled = !empty($_POST['registration_enabled']) ? '1' : '0';
        if (!in_array($periodBasis, ['previous_current', 'current_next'], true)) {
            $periodBasis = 'previous_current';
        }
        if ($portalName === '') {
            echo json_encode(['success'=>false, 'msg'=>'그룹웨어 이름을 입력해 주세요.']); exit;
        }

        $updates = [
            'portal_name' => $portalName,
            'portal_company_label' => $companyLabel,
            'weekly_meeting_weekday' => (string)$meetingWeekday,
            'weekly_period_basis' => $periodBasis,
            'registration_enabled' => $registrationEnabled,
        ];
        $stmt = $conn->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
        if (!$stmt) {
            echo json_encode(['success'=>false, 'msg'=>'포털 설정 저장 준비에 실패했습니다.']); exit;
        }
        foreach ($updates as $settingKey => $settingValue) {
            $stmt->bind_param('ss', $settingKey, $settingValue);
            if (!$stmt->execute()) {
                $stmt->close();
                echo json_encode(['success'=>false, 'msg'=>'포털 설정 저장 중 오류가 발생했습니다.']); exit;
            }
        }
        $stmt->close();
        echo json_encode(['success'=>true, 'msg'=>'그룹웨어 및 주간회의 기준을 저장했습니다.']); exit;
    }
    if ($action === 'save_api_settings') {
        $updates = [];
        foreach (['bareun_api_key', 'gemini_api_key'] as $keyName) {
            if (!empty($_POST['clear_' . $keyName])) {
                $updates[$keyName] = '';
                continue;
            }
            $entered = trim((string)($_POST[$keyName] ?? ''));
            if ($entered !== '') $updates[$keyName] = $entered;
        }
        $model = trim((string)($_POST['gemini_model'] ?? 'gemini-2.5-flash'));
        $updates['gemini_model'] = $model !== '' ? mb_substr($model, 0, 100, 'UTF-8') : 'gemini-2.5-flash';

        $stmt = $conn->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
        if (!$stmt) {
            echo json_encode(['success'=>false, 'msg'=>'API 설정 저장 준비에 실패했습니다.']); exit;
        }
        foreach ($updates as $settingKey => $settingValue) {
            $stmt->bind_param('ss', $settingKey, $settingValue);
            if (!$stmt->execute()) {
                $stmt->close();
                echo json_encode(['success'=>false, 'msg'=>'API 설정 저장 중 오류가 발생했습니다.']); exit;
            }
        }
        $stmt->close();
        echo json_encode(['success'=>true, 'msg'=>'API 설정이 안전하게 저장되었습니다.']); exit;
    }
}

$users = $conn->query("SELECT * FROM users ORDER BY CASE position
    WHEN '회장' THEN 1 WHEN '대표' THEN 2 WHEN '사장' THEN 3
    WHEN '부회장' THEN 4 WHEN '부사장' THEN 5 WHEN '전무' THEN 6
    WHEN '상무' THEN 7 WHEN '이사' THEN 8 WHEN '본부장' THEN 9
    WHEN '실장' THEN 10 WHEN '부장' THEN 11 WHEN '차장' THEN 12
    WHEN '과장' THEN 13 WHEN '대리' THEN 14 WHEN '주임' THEN 15
    WHEN '사원' THEN 16 WHEN '인턴' THEN 17 ELSE 99 END, nickname")->fetch_all(MYSQLI_ASSOC);
$relations = $conn->query("SELECT r.*, v.nickname as viewer_name, v.position as v_pos, t.nickname as target_name, t.position as t_pos FROM user_relations r JOIN users v ON r.viewer_id = v.id JOIN users t ON r.target_id = t.id")->fetch_all(MYSQLI_ASSOC);
$templates = $conn->query("SELECT a.*, u1.nickname as s1_name, u2.nickname as s2_name, u3.nickname as s3_name FROM approval_templates a LEFT JOIN users u1 ON a.step1_id = u1.id LEFT JOIN users u2 ON a.step2_id = u2.id LEFT JOIN users u3 ON a.step3_id = u3.id")->fetch_all(MYSQLI_ASSOC);
$timeout_val = $conn->query("SELECT setting_value FROM site_settings WHERE setting_key='approval_timeout'")->fetch_assoc()['setting_value'] ?? 0;
$api_settings = ['bareun_api_key'=>'', 'gemini_api_key'=>'', 'gemini_model'=>'gemini-2.5-flash'];
$api_result = $conn->query("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ('bareun_api_key','gemini_api_key','gemini_model')");
if ($api_result) while ($setting = $api_result->fetch_assoc()) $api_settings[$setting['setting_key']] = (string)$setting['setting_value'];
$bareun_configured = $api_settings['bareun_api_key'] !== '';
$gemini_configured = $api_settings['gemini_api_key'] !== '';
$portal_settings = smw_site_settings($conn);
$portal_identity = smw_portal_identity($conn);
$active_company_count = (int)($conn->query("SELECT COUNT(*) AS total FROM companies WHERE is_active=1")->fetch_assoc()['total'] ?? 0);
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>시스템 관리 - <?= smw_h($portal_identity['name']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"><link rel="stylesheet" href="assets/groupware-shell.css?v=2">
    <style>body { font-family: Pretendard, 'Noto Sans KR', 'Malgun Gothic', sans-serif; }</style>
    <script>
        function switchTab(tabId) {
            document.querySelectorAll('.tab-content').forEach(el => el.classList.add('hidden'));
            document.querySelectorAll('.tab-btn').forEach(el => { el.classList.remove('border-blue-600', 'text-blue-600'); el.classList.add('border-transparent', 'text-gray-500'); el.setAttribute('aria-selected', 'false'); });
            const panel = document.getElementById(tabId);
            const button = document.getElementById('btn-' + tabId);
            if (!panel || !button) return;
            panel.classList.remove('hidden');
            button.classList.remove('border-transparent', 'text-gray-500');
            button.classList.add('border-blue-600', 'text-blue-600');
            button.setAttribute('aria-selected', 'true');
            history.replaceState(null, '', '#' + tabId);
        }
        document.addEventListener('DOMContentLoaded', () => { const requested = location.hash.slice(1); if (requested && document.getElementById(requested)) switchTab(requested); });
    </script>
</head>
<body class="gw-body min-h-screen pb-10">
    <?php smw_render_shell_header('admin', '시스템 관리', true); ?>
    <main class="max-w-7xl mx-auto px-4 py-8">
        <div class="mb-6">
            <h1 class="text-2xl font-black text-slate-900">시스템 관리</h1>
            <p class="mt-1 text-sm text-slate-500">포털 표시, 보고 기준, 계정, 결재와 외부 연결을 한 곳에서 관리합니다.</p>
        </div>
        <div class="text-sm font-medium text-center text-gray-500 border-b border-gray-200 mb-6">
            <ul class="flex flex-wrap -mb-px" role="tablist" aria-label="시스템 관리 항목">
                <li class="mr-2"><button id="btn-tab-portal" role="tab" aria-selected="true" aria-controls="tab-portal" onclick="switchTab('tab-portal')" class="tab-btn inline-block p-4 border-b-2 border-blue-600 text-blue-600">그룹웨어 설정</button></li>
                <li class="mr-2"><button id="btn-tab-users" role="tab" aria-selected="false" aria-controls="tab-users" onclick="switchTab('tab-users')" class="tab-btn inline-block p-4 border-b-2 border-transparent">계정/주소록</button></li>
                <li class="mr-2"><button id="btn-tab-relations" role="tab" aria-selected="false" aria-controls="tab-relations" onclick="switchTab('tab-relations')" class="tab-btn inline-block p-4 border-b-2 border-transparent">작업자 선택 범위</button></li>
                <li class="mr-2"><button id="btn-tab-templates" role="tab" aria-selected="false" aria-controls="tab-templates" onclick="switchTab('tab-templates')" class="tab-btn inline-block p-4 border-b-2 border-transparent">결재 환경설정</button></li>
                <li class="mr-2"><button id="btn-tab-api" role="tab" aria-selected="false" aria-controls="tab-api" onclick="switchTab('tab-api')" class="tab-btn inline-block p-4 border-b-2 border-transparent">API 설정</button></li>
            </ul>
        </div>

        <div id="tab-portal" class="tab-content" role="tabpanel" aria-labelledby="btn-tab-portal">
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <form onsubmit="submitPortalSettings(event)" class="lg:col-span-2 bg-white rounded-xl shadow-lg overflow-hidden">
                    <input type="hidden" name="action" value="save_portal_settings">
                    <div class="px-6 py-5 border-b bg-slate-50">
                        <h2 class="text-lg font-black text-slate-900">그룹웨어 표시와 주간회의 기준</h2>
                        <p class="mt-1 text-sm text-slate-500">저장 즉시 로그인, 상단 메뉴, 대시보드, 주간 보고와 엑셀에 같은 기준이 적용됩니다.</p>
                    </div>
                    <div class="p-6 space-y-7">
                        <section>
                            <h3 class="font-black text-slate-900 mb-4">포털 이름</h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div><label class="block text-sm font-bold mb-1" for="portal_name">그룹웨어 이름</label><input id="portal_name" name="portal_name" maxlength="80" required value="<?= smw_h($portal_settings['portal_name']) ?>" class="w-full border border-slate-300 p-3 rounded-lg" placeholder="예: 우리회사 GROUPWARE"><p class="mt-1 text-xs text-slate-500">모든 화면의 대표 이름으로 표시됩니다.</p></div>
                                <div><label class="block text-sm font-bold mb-1" for="portal_company_label">회사 묶음 표기</label><input id="portal_company_label" name="portal_company_label" maxlength="160" value="<?= smw_h($portal_settings['portal_company_label']) ?>" class="w-full border border-slate-300 p-3 rounded-lg" placeholder="비우면 등록 회사 자동 표시"><p class="mt-1 text-xs text-slate-500">비워 두면 <?= smw_h($portal_identity['companies']) ?>처럼 자동 구성됩니다.</p></div>
                            </div>
                        </section>
                        <section class="border-t pt-6">
                            <h3 class="font-black text-slate-900 mb-1">주간회의 보고 기간</h3>
                            <p class="text-sm text-slate-500 mb-4">회의일에 보고서를 열었을 때 왼쪽 실적과 오른쪽 계획에 어느 주를 넣을지 정합니다.</p>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div><label class="block text-sm font-bold mb-1" for="weekly_meeting_weekday">정기 회의 요일</label><select id="weekly_meeting_weekday" name="weekly_meeting_weekday" class="w-full border border-slate-300 p-3 rounded-lg"><?php for($day=1;$day<=7;$day++): ?><option value="<?= $day ?>" <?= (int)$portal_settings['weekly_meeting_weekday']===$day?'selected':'' ?>><?= smw_weekday_label($day) ?></option><?php endfor; ?></select></div>
                                <div><label class="block text-sm font-bold mb-1" for="weekly_period_basis">회의 자료 기준</label><select id="weekly_period_basis" name="weekly_period_basis" class="w-full border border-slate-300 p-3 rounded-lg"><option value="previous_current" <?= $portal_settings['weekly_period_basis']==='previous_current'?'selected':'' ?>>전주 실적 + 금주 계획</option><option value="current_next" <?= $portal_settings['weekly_period_basis']==='current_next'?'selected':'' ?>>금주 실적 + 차주 계획</option></select></div>
                            </div>
                            <div class="mt-4 rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900"><strong class="block mb-1">월요일 회의 추천</strong>월요일에 지난주 결과를 보고 이번 주 계획을 논의한다면 <b>전주 실적 + 금주 계획</b>을 선택하세요. 각 영역에는 실제 날짜도 함께 표시되어 혼동을 줄입니다.</div>
                        </section>
                        <section class="border-t pt-6">
                            <h3 class="font-black text-slate-900 mb-3">가입 운영</h3>
                            <label class="flex items-start gap-3 rounded-lg border border-slate-200 p-4 cursor-pointer hover:bg-slate-50"><input type="checkbox" name="registration_enabled" value="1" class="mt-1 w-4 h-4 text-blue-600" <?= $portal_settings['registration_enabled']==='1'?'checked':'' ?>><span><strong class="block text-sm text-slate-900">로그인 화면에서 신규 회원가입 허용</strong><small class="block mt-1 text-slate-500">사내 구성원 등록 기간에만 켜고, 등록이 끝나면 끄는 운영을 권장합니다. 첫 설치의 첫 계정은 항상 관리자 계정으로 생성됩니다.</small></span></label>
                        </section>
                        <div class="flex justify-end border-t pt-5"><button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-bold px-8 py-3 rounded-lg">그룹웨어 설정 저장</button></div>
                    </div>
                </form>
                <aside class="space-y-4">
                    <div class="rounded-xl bg-slate-900 text-white p-5 shadow-lg">
                        <span class="text-xs font-bold text-blue-300">미리보기</span>
                        <div class="mt-4 flex items-center gap-3"><span class="grid place-items-center w-11 h-11 rounded-xl bg-blue-600"><i class="fa-solid fa-layer-group" aria-hidden="true"></i></span><div class="min-w-0"><strong class="block truncate"><?= smw_h($portal_identity['name']) ?></strong><small class="block mt-1 text-slate-400 truncate"><?= smw_h($portal_identity['companies']) ?></small></div></div>
                    </div>
                    <div class="bg-white rounded-xl shadow-lg p-5">
                        <h3 class="font-black text-slate-900">운영 준비 상태</h3>
                        <ul class="mt-4 space-y-3 text-sm">
                            <li class="flex items-center justify-between"><span>등록 회사</span><strong class="text-blue-700"><?= $active_company_count ?>개</strong></li>
                            <li class="flex items-center justify-between"><span>정기 회의</span><strong><?= smw_weekday_label((int)$portal_settings['weekly_meeting_weekday']) ?></strong></li>
                            <li class="flex items-center justify-between"><span>정밀 맞춤법</span><strong class="<?= $bareun_configured?'text-emerald-700':'text-amber-700' ?>"><?= $bareun_configured?'연결됨':'기본 검사' ?></strong></li>
                            <li class="flex items-center justify-between"><span>데이터 보존</span><strong class="text-emerald-700">기존 DB 유지</strong></li>
                        </ul>
                    </div>
                    <a href="organization_admin.php" class="flex items-center justify-between bg-white rounded-xl shadow-lg p-5 hover:bg-slate-50"><span><strong class="block text-slate-900">회사·사업부 관리</strong><small class="text-slate-500">회사명과 소속 인원 설정</small></span><i class="fa-solid fa-chevron-right text-slate-400" aria-hidden="true"></i></a>
                </aside>
            </div>
        </div>

        <div id="tab-users" class="tab-content hidden bg-white rounded-xl shadow-lg overflow-x-auto" role="tabpanel" aria-labelledby="btn-tab-users">
            <table class="w-full text-sm text-left text-gray-500"><thead class="bg-gray-100 text-gray-700"><tr><th>이름(ID)</th><th>직급</th><th>연락처</th><th>생일</th><th>관리</th></tr></thead>
                <tbody>
                    <?php foreach($users as $u): ?>
                    <tr class="border-b hover:bg-gray-50">
                        <td class="px-4 py-3 font-bold"><?= htmlspecialchars($u['nickname']) ?><br><span class="text-xs font-normal"><?= htmlspecialchars($u['username']) ?></span></td>
                        <td class="px-4 py-3"><span class="bg-blue-100 text-blue-800 px-2 py-1 rounded text-xs font-bold"><?= htmlspecialchars($u['position']) ?></span></td>
                        <td class="px-4 py-3"><?= htmlspecialchars($u['phone']) ?></td>
                        <td class="px-4 py-3"><?= $u['birth_date'] ? date('m.d', strtotime($u['birth_date'])) . ($u['birth_type']=='lunar'?'(음)':'(양)') : '미등록' ?></td>
                        <td class="px-4 py-3"><button onclick='openEditModal(<?= json_encode($u) ?>)' class="text-white bg-blue-600 px-2 py-1 rounded text-xs mr-1">수정</button></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div id="tab-relations" class="tab-content hidden flex flex-col lg:flex-row gap-6" role="tabpanel" aria-labelledby="btn-tab-relations">
            <div class="w-full lg:w-1/3 bg-white p-6 rounded-xl shadow-lg h-fit">
                <div class="bg-blue-50 border border-blue-200 rounded-lg p-3 mb-4 text-sm text-blue-900"><strong class="block mb-1">본문에 넣을 작업자 선택 범위</strong>작성자와 작업자를 연결하면 일일 업무 2타입에서 이름을 선택해 본인 보고서 본문에 넣을 수 있습니다. 작업자 계정의 보고서는 생성되지 않습니다.</div>
                <form onsubmit="submitRelation(event)">
                    <input type="hidden" name="action" value="add_relation">
                    <label class="block text-sm font-bold mb-1">상급자</label>
                    <select name="viewer_id" class="w-full border p-2 mb-4 rounded bg-blue-50" required><option value="">- 선택 -</option><?php foreach($users as $u) echo "<option value='{$u['id']}'>[{$u['position']}] {$u['nickname']}</option>"; ?></select>
                    <label class="block text-sm font-bold mb-1">하급자 (체크박스)</label>
                    <div class="w-full border p-3 mb-4 rounded max-h-48 overflow-y-auto bg-gray-50 space-y-2">
                        <?php foreach($users as $u): ?>
                        <label class="flex items-center gap-2 cursor-pointer hover:bg-gray-200 p-1 rounded">
                            <input type="checkbox" name="target_ids[]" value="<?= $u['id'] ?>" class="w-4 h-4">
                            <span class="text-sm font-bold text-gray-700">[<?= $u['position'] ?>] <?= htmlspecialchars($u['nickname']) ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <button type="submit" class="w-full bg-emerald-600 text-white font-bold py-2 rounded">일괄 연결하기</button>
                </form>
            </div>
            <div class="w-full lg:w-2/3 bg-white rounded-xl shadow-lg p-6 max-h-[600px] overflow-y-auto">
                <?php foreach($relations as $r) echo "<div class='border-b py-2 flex justify-between'><span>[{$r['v_pos']}]{$r['viewer_name']} <i class='fa-solid fa-arrow-right mx-2'></i> [{$r['t_pos']}]{$r['target_name']}</span><button onclick='deleteRelation({$r['viewer_id']}, {$r['target_id']})' class='text-red-500 text-xs font-bold bg-red-50 px-2 rounded'>해제</button></div>"; ?>
            </div>
        </div>

        <div id="tab-templates" class="tab-content hidden space-y-6" role="tabpanel" aria-labelledby="btn-tab-templates">
            <div class="bg-amber-50 border border-amber-200 p-6 rounded-xl shadow-sm">
                <form onsubmit="submitGlobalSettings(event)" class="flex items-end gap-4"><input type="hidden" name="action" value="save_global_settings"><div class="flex-1"><label class="block text-sm font-bold mb-1">결재 대기 만료 기간</label><select name="approval_timeout" class="w-full border p-2 rounded"><option value="0" <?= $timeout_val==0?'selected':'' ?>>사용 안 함</option><option value="1" <?= $timeout_val==1?'selected':'' ?>>1일 후</option><option value="2" <?= $timeout_val==2?'selected':'' ?>>2일 후</option><option value="7" <?= $timeout_val==7?'selected':'' ?>>7일 후</option></select></div><button type="submit" class="bg-amber-600 text-white font-bold px-6 py-2 rounded h-[42px]">저장</button></form>
            </div>
            <div class="flex flex-col lg:flex-row gap-6">
                <div class="w-full lg:w-1/3 bg-white p-6 rounded-xl shadow-lg h-fit">
                    <form onsubmit="submitTemplate(event)"><input type="hidden" name="action" value="add_template"><input type="text" name="name" placeholder="템플릿명" class="w-full border p-2 mb-3" required><select name="step1" class="w-full border p-2 mb-2" required><option value="">1차</option><?php foreach($users as $u) echo "<option value='{$u['id']}'>{$u['nickname']}</option>"; ?></select><select name="step2" class="w-full border p-2 mb-2"><option value="">2차</option><?php foreach($users as $u) echo "<option value='{$u['id']}'>{$u['nickname']}</option>"; ?></select><select name="step3" class="w-full border p-2 mb-4"><option value="">3차</option><?php foreach($users as $u) echo "<option value='{$u['id']}'>{$u['nickname']}</option>"; ?></select><button type="submit" class="w-full bg-slate-800 text-white font-bold py-2 rounded">저장</button></form>
                </div>
                <div class="w-full lg:w-2/3 bg-white rounded-xl shadow-lg p-6">
                    <?php foreach($templates as $t) echo "<div class='border-b py-2 flex justify-between items-center'><div><span class='font-bold text-blue-700'>{$t['name']}</span></div><button onclick='deleteTemplate({$t['id']})' class='text-red-500 text-xs'>삭제</button></div>"; ?>
                </div>
            </div>
        </div>

        <div id="tab-api" class="tab-content hidden" role="tabpanel" aria-labelledby="btn-tab-api">
            <div class="bg-white rounded-xl shadow-lg overflow-hidden">
                <div class="px-6 py-5 border-b bg-slate-50">
                    <h2 class="text-lg font-black text-slate-900">외부 API 연결 설정</h2>
                    <p class="mt-1 text-sm text-slate-500">키는 저장 후 화면에 다시 표시하지 않습니다. 기존 키를 유지하려면 입력칸을 비워 두세요.</p>
                </div>
                <form onsubmit="submitApiSettings(event)" class="p-6 space-y-6" autocomplete="off">
                    <input type="hidden" name="action" value="save_api_settings">
                    <section class="border border-emerald-200 rounded-xl p-5 bg-emerald-50/40">
                        <div class="flex flex-wrap items-center justify-between gap-2 mb-4">
                            <div><h3 class="font-black text-slate-900">바른AI 맞춤법 검사</h3><p class="text-xs text-slate-500 mt-1">일일 업무 에디터의 맞춤법·띄어쓰기 정밀 검사에 즉시 사용됩니다.</p></div>
                            <span class="px-3 py-1 rounded-full text-xs font-bold <?= $bareun_configured ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-600' ?>"><?= $bareun_configured ? '연결 키 저장됨' : '미설정' ?></span>
                        </div>
                        <label class="block text-sm font-bold mb-1" for="bareun_api_key">바른 API 키</label>
                        <div class="flex gap-2"><input id="bareun_api_key" type="password" name="bareun_api_key" class="flex-1 min-w-0 border border-slate-300 p-3 rounded-lg bg-white" placeholder="<?= $bareun_configured ? '새 키로 변경할 때만 입력' : 'API 키 입력' ?>" autocomplete="new-password"><button type="button" onclick="toggleSecret('bareun_api_key', this)" class="px-4 border border-slate-300 bg-white rounded-lg text-sm font-bold">보기</button></div>
                        <?php if($bareun_configured): ?><label class="inline-flex items-center gap-2 mt-3 text-sm text-red-600"><input type="checkbox" name="clear_bareun_api_key" value="1"> 저장된 바른 API 키 삭제</label><?php endif; ?>
                    </section>
                    <section class="border border-blue-200 rounded-xl p-5 bg-blue-50/40">
                        <div class="flex flex-wrap items-center justify-between gap-2 mb-4">
                            <div><h3 class="font-black text-slate-900">Google Gemini</h3><p class="text-xs text-slate-500 mt-1">향후 AI 요약·문장 정리 기능에서 사용할 연결 정보를 미리 관리합니다.</p></div>
                            <span class="px-3 py-1 rounded-full text-xs font-bold <?= $gemini_configured ? 'bg-blue-100 text-blue-700' : 'bg-slate-200 text-slate-600' ?>"><?= $gemini_configured ? '연결 키 저장됨' : '미설정' ?></span>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div class="md:col-span-2"><label class="block text-sm font-bold mb-1" for="gemini_api_key">Gemini API 키</label><div class="flex gap-2"><input id="gemini_api_key" type="password" name="gemini_api_key" class="flex-1 min-w-0 border border-slate-300 p-3 rounded-lg bg-white" placeholder="<?= $gemini_configured ? '새 키로 변경할 때만 입력' : 'API 키 입력' ?>" autocomplete="new-password"><button type="button" onclick="toggleSecret('gemini_api_key', this)" class="px-4 border border-slate-300 bg-white rounded-lg text-sm font-bold">보기</button></div></div>
                            <div><label class="block text-sm font-bold mb-1" for="gemini_model">모델명</label><input id="gemini_model" type="text" name="gemini_model" value="<?= htmlspecialchars($api_settings['gemini_model'], ENT_QUOTES, 'UTF-8') ?>" class="w-full border border-slate-300 p-3 rounded-lg bg-white" maxlength="100"></div>
                        </div>
                        <?php if($gemini_configured): ?><label class="inline-flex items-center gap-2 mt-3 text-sm text-red-600"><input type="checkbox" name="clear_gemini_api_key" value="1"> 저장된 Gemini API 키 삭제</label><?php endif; ?>
                    </section>
                    <div class="flex items-center justify-between gap-4 border-t pt-5"><p class="text-xs text-slate-500"><i class="fa-solid fa-shield-halved mr-1"></i>API 키 값은 관리자에게도 재표시되지 않습니다.</p><button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-bold px-7 py-3 rounded-lg">API 설정 저장</button></div>
                </form>
            </div>
        </div>
    </main>

    <div id="editModal" class="fixed inset-0 bg-black bg-opacity-50 hidden flex items-center justify-center z-50 p-4">
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-md p-6 max-h-[90vh] overflow-y-auto">
            <h3 class="text-xl font-bold mb-4 border-b pb-2">회원 정보 수정</h3>
            <form id="editForm" onsubmit="submitEdit(event)">
                <input type="hidden" id="edit_uid" name="target_uid"><input type="hidden" name="action" value="update_user">
                <div class="grid grid-cols-2 gap-4 mb-4">
                    <div><label class="block text-sm font-bold">이름</label><input type="text" id="edit_nickname" name="nickname" class="w-full border p-2 rounded" required></div>
                    <div><label class="block text-sm font-bold text-blue-700">직급</label><select id="edit_position" name="position" class="w-full border p-2 rounded font-bold"><option value="사장">사장</option><option value="전무">전무</option><option value="상무">상무</option><option value="이사">이사</option><option value="부장">부장</option><option value="차장">차장</option><option value="과장">과장</option><option value="대리">대리</option><option value="주임">주임</option><option value="사원">사원</option></select></div>
                </div>
                <div class="mb-4"><label class="block text-sm font-bold">휴대전화</label><input type="text" id="edit_phone" name="phone" class="w-full border p-2 rounded"></div>
                <div class="mb-4"><label class="block text-sm font-bold">이메일</label><input type="email" id="edit_email" name="email" class="w-full border p-2 rounded"></div>
                <div class="flex gap-2 mb-4">
                    <div class="w-1/3"><label class="block text-sm font-bold">구분</label><select id="edit_birth_type" name="birth_type" class="w-full border p-2 rounded"><option value="solar">양력</option><option value="lunar">음력</option></select></div>
                    <div class="w-2/3"><label class="block text-sm font-bold">생년월일</label><input type="date" id="edit_birth" name="birth_date" class="w-full border p-2 rounded"></div>
                </div>
                <div class="mb-4 border-t pt-4"><label class="block text-sm font-bold text-red-600">시스템 권한</label><select id="edit_is_admin" name="is_admin" class="w-full border p-2 rounded bg-red-50 font-bold"><option value="0">일반</option><option value="1">시스템 관리자</option></select></div>
                <div class="mb-6"><label class="block text-sm font-bold">비번 변경</label><input type="password" name="new_password" class="w-full border p-2 rounded"></div>
                <div class="flex gap-2"><button type="button" onclick="closeEditModal()" class="px-4 py-2 bg-gray-200 rounded font-bold w-1/3">취소</button><button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded font-bold w-2/3">저장</button></div>
            </form>
        </div>
    </div>

    <script>
        const adminCsrf = <?= json_encode($admin_csrf, JSON_UNESCAPED_UNICODE) ?>;
        async function fetchAPI(fd) {
            if (!fd.has('smw_csrf')) fd.append('smw_csrf', adminCsrf);
            const res = await fetch('admin.php', { method: 'POST', body: fd }).then(r => r.json()); 
            alert(res.msg); 
            if(res.success) location.reload(); 
        }
        function deleteUser(uid) { if(confirm('삭제?')) { const fd = new FormData(); fd.append('action', 'delete_user'); fd.append('target_uid', uid); fetchAPI(fd); } }
        function openEditModal(u) { 
            document.getElementById('edit_uid').value = u.id; document.getElementById('edit_nickname').value = u.nickname; 
            document.getElementById('edit_position').value = u.position; document.getElementById('edit_phone').value = u.phone || ''; 
            document.getElementById('edit_email').value = u.email || ''; document.getElementById('edit_birth').value = u.birth_date || ''; 
            document.getElementById('edit_birth_type').value = u.birth_type || 'solar'; document.getElementById('edit_is_admin').value = u.is_admin; 
            document.getElementById('editModal').classList.remove('hidden'); 
        }
        function closeEditModal() { document.getElementById('editModal').classList.add('hidden'); document.getElementById('editForm').reset(); }
        function submitEdit(e) { e.preventDefault(); fetchAPI(new FormData(e.target)); }
        function submitRelation(e) { e.preventDefault(); fetchAPI(new FormData(e.target)); }
        function deleteRelation(v, t) { if(confirm('해제?')) { const fd = new FormData(); fd.append('action', 'del_relation'); fd.append('viewer_id', v); fd.append('target_id', t); fetchAPI(fd); } }
        function submitTemplate(e) { e.preventDefault(); fetchAPI(new FormData(e.target)); }
        function deleteTemplate(tid) { if(confirm('삭제?')) { const fd = new FormData(); fd.append('action', 'del_template'); fd.append('template_id', tid); fetchAPI(fd); } }
        function submitGlobalSettings(e) { e.preventDefault(); fetchAPI(new FormData(e.target)); }
        function submitPortalSettings(e) { e.preventDefault(); fetchAPI(new FormData(e.target)); }
        function submitApiSettings(e) { e.preventDefault(); fetchAPI(new FormData(e.target)); }
        function toggleSecret(id, button) { const input = document.getElementById(id); const reveal = input.type === 'password'; input.type = reveal ? 'text' : 'password'; button.textContent = reveal ? '숨김' : '보기'; }
    </script>
</body>
</html>

<?php
require_once 'smw_extensions.php';

$user = smw_current_user($conn);
if (!$user) {
    header('Location: login.php');
    exit;
}
$portal_identity = smw_portal_identity($conn);

header('Location: daily.php');
exit;

$uid = (int)$user['id'];
$selectable_users = smw_selectable_users($conn, $user);
$selectable_ids = array_map('intval', array_column($selectable_users, 'id'));
$preference = smw_user_preference($conn, $uid);
$valid_modes = ['daily', 'weekly', 'monthly'];
$mode = in_array($_GET['mode'] ?? '', $valid_modes, true) ? (string)$_GET['mode'] : $preference['input_mode'];
$companies = smw_company_options($conn);
$categories = smw_category_options($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    smw_verify_csrf();
    $target_id = (int)($_POST['target_user_id'] ?? $uid);
    $input_mode = in_array($_POST['input_mode'] ?? '', $valid_modes, true) ? (string)$_POST['input_mode'] : 'daily';
    $work_name = trim((string)($_POST['work_name'] ?? ''));
    $note = trim((string)($_POST['note'] ?? ''));
    $company_name = trim((string)($_POST['company_name'] ?? '공통업무'));
    $task_category = trim((string)($_POST['task_category'] ?? '일반업무'));
    $task_type = ($_POST['task_type'] ?? 'actual') === 'plan' ? 'plan' : 'actual';
    $period_value = $input_mode === 'monthly'
        ? (string)($_POST['month_value'] ?? date('Y-m'))
        : (string)($_POST[$input_mode === 'weekly' ? 'week_value' : 'date_value'] ?? date('Y-m-d'));
    [$period_start, $period_end] = smw_period($input_mode, $period_value);

    if (!in_array($target_id, $selectable_ids, true)) {
        $_SESSION['quick_message'] = ['error', '선택한 직원은 현재 보고 대상에 포함되어 있지 않습니다.'];
    } elseif ($work_name === '') {
        $_SESSION['quick_message'] = ['error', '작업명을 입력해 주세요.'];
    } else {
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare(
                "INSERT INTO report_tasks
                 (user_id, target_date, company_name, task_category, plan_content, result_content, task_type)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param('issssss', $target_id, $period_start, $company_name, $task_category, $work_name, $note, $task_type);
            $stmt->execute();
            $task_id = (int)$stmt->insert_id;

            $meta_stmt = $conn->prepare(
                "INSERT INTO report_task_meta (task_id, created_by, input_mode, period_start, period_end)
                 VALUES (?, ?, ?, ?, ?)"
            );
            $meta_stmt->bind_param('iisss', $task_id, $uid, $input_mode, $period_start, $period_end);
            $meta_stmt->execute();

            $pref_stmt = $conn->prepare(
                "INSERT INTO report_user_preferences
                 (user_id, input_mode, last_target_id, last_company, last_category)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                 input_mode=VALUES(input_mode),
                 last_target_id=VALUES(last_target_id),
                 last_company=VALUES(last_company),
                 last_category=VALUES(last_category)"
            );
            $pref_stmt->bind_param('isiss', $uid, $input_mode, $target_id, $company_name, $task_category);
            $pref_stmt->execute();

            $conn->commit();
            $_SESSION['quick_message'] = ['success', '업무를 저장했습니다. 선택한 직원과 입력 방식은 다음 작성 때 그대로 유지됩니다.'];
            header('Location: quick_report.php');
            exit;
        } catch (Throwable $error) {
            $conn->rollback();
            $_SESSION['quick_message'] = ['error', '저장 중 오류가 발생했습니다. 기존 데이터는 변경되지 않았습니다.'];
        }
    }
}

$message = $_SESSION['quick_message'] ?? null;
unset($_SESSION['quick_message']);
$preference = smw_user_preference($conn, $uid);
$mode = in_array($_GET['mode'] ?? '', $valid_modes, true) ? (string)$_GET['mode'] : $preference['input_mode'];
$selected_target_id = in_array((int)$preference['last_target_id'], $selectable_ids, true)
    ? (int)$preference['last_target_id']
    : $uid;
$selected_company = $preference['last_company'] !== '' ? $preference['last_company'] : ($companies[0] ?? '공통업무');
$selected_category = $preference['last_category'] ?: '일반업무';

$recent = [];
$recent_res = $conn->query(
    "SELECT t.*, m.input_mode, m.period_start, m.period_end,
            u.nickname AS target_name, u.position AS target_position
     FROM report_task_meta m
     JOIN report_tasks t ON t.id=m.task_id
     JOIN users u ON u.id=t.user_id
     WHERE m.created_by=$uid
     ORDER BY m.created_at DESC
     LIMIT 10"
);
if ($recent_res) $recent = $recent_res->fetch_all(MYSQLI_ASSOC);
$mode_labels = ['daily' => '일별', 'weekly' => '주간', 'monthly' => '월간'];
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>간편 업무 입력 - <?= smw_h($portal_identity['name']) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/smw-extension.css?v=1">
    <script src="assets/quick-report.js?v=1" defer></script>
</head>
<body>
<header class="ex-header">
    <div class="ex-shell ex-header-inner">
        <a class="ex-brand" href="index.php"><span class="ex-brand-mark"><i class="fa-solid fa-building"></i></span><span><?= smw_h($portal_identity['name']) ?></span></a>
        <nav class="ex-nav" aria-label="주 메뉴"><a href="index.php">메인</a><a class="active" href="quick_report.php">간편 입력</a><a href="daily.php">기존 일일업무</a><a href="weekly_presentation.php">주간 회의</a><a href="task_history.php">전체 기록</a><?php if((int)$user['is_admin']===1): ?><a href="organization_admin.php">조직 관리</a><?php endif; ?></nav>
        <a class="ex-button" href="daily.php"><i class="fa-solid fa-pen-to-square"></i> 기존 입력</a>
    </div>
</header>

<main class="ex-shell ex-page">
    <section class="ex-heading">
        <div><h1>간편 업무 입력</h1><p>직원 한 명을 선택하고 작업명만 입력하면 됩니다. 기존 입력 화면은 그대로 사용할 수 있습니다.</p></div>
        <span class="ex-badge"><?= smw_h($user['nickname']) ?> · <?= smw_h($user['position']) ?></span>
    </section>

    <?php if($message): ?><div class="ex-message <?= smw_h($message[0]) ?>" role="status"><?= smw_h($message[1]) ?></div><?php endif; ?>

    <div class="ex-layout">
        <section class="ex-card">
            <div class="ex-card-head"><div><h2>직원 업무 기록</h2><p>마지막으로 선택한 직원과 방식이 자동으로 유지됩니다.</p></div></div>
            <form class="ex-card-body ex-steps" method="post" id="quickReportForm">
                <input type="hidden" name="smw_csrf" value="<?= smw_h(smw_csrf_token()) ?>">

                <div class="ex-step">
                    <span class="ex-step-number">1</span>
                    <div>
                        <h3>직원 선택</h3>
                        <div class="ex-field">
                            <label for="target_user_id">업무 대상</label>
                            <div class="ex-target">
                                <select id="target_user_id" name="target_user_id" required>
                                    <?php foreach($selectable_users as $person): ?>
                                        <option value="<?= (int)$person['id'] ?>" <?= $selected_target_id===(int)$person['id']?'selected':'' ?>>
                                            <?= (int)$person['id']===$uid?'나 · ':'' ?><?= smw_h($person['nickname']) ?> · <?= smw_h($person['position']) ?><?= $person['department_names']?' · '.smw_h($person['department_names']):'' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <span class="ex-help">하위 직원은 관리자 화면의 보고 관계 설정에 따라 표시됩니다.</span>
                        </div>
                    </div>
                </div>

                <div class="ex-step">
                    <span class="ex-step-number">2</span>
                    <div>
                        <h3>관리 범위 선택</h3>
                        <div class="ex-mode-tabs">
                            <label class="ex-mode"><input type="radio" name="input_mode" value="daily" <?= $mode==='daily'?'checked':'' ?>><span><i class="fa-regular fa-calendar-day"></i><br>일별</span></label>
                            <label class="ex-mode"><input type="radio" name="input_mode" value="weekly" <?= $mode==='weekly'?'checked':'' ?>><span><i class="fa-regular fa-calendar-week"></i><br>주간</span></label>
                            <label class="ex-mode"><input type="radio" name="input_mode" value="monthly" <?= $mode==='monthly'?'checked':'' ?>><span><i class="fa-regular fa-calendar"></i><br>월간</span></label>
                        </div>
                        <div class="ex-period-fields" data-mode="<?= smw_h($mode) ?>" style="margin-top:10px">
                            <div class="ex-field period-daily"><label for="date_value">업무 일자</label><input id="date_value" type="date" name="date_value" value="<?= date('Y-m-d') ?>"></div>
                            <div class="ex-field period-weekly"><label for="week_value">해당 주 기준일</label><input id="week_value" type="date" name="week_value" value="<?= date('Y-m-d') ?>"><span class="ex-help">선택일이 포함된 월요일부터 일요일까지로 저장됩니다.</span></div>
                            <div class="ex-field period-monthly"><label for="month_value">해당 월</label><input id="month_value" type="month" name="month_value" value="<?= date('Y-m') ?>"></div>
                        </div>
                    </div>
                </div>

                <div class="ex-step">
                    <span class="ex-step-number">3</span>
                    <div class="ex-stack">
                        <div class="ex-field">
                            <label for="work_name">무엇을 했나요?</label>
                            <input class="ex-main-input" id="work_name" name="work_name" required maxlength="255" placeholder="예: 2층 배관 설치 및 점검">
                        </div>
                        <div class="ex-field">
                            <label for="note">메모 <span class="ex-help">(선택)</span></label>
                            <textarea id="note" name="note" placeholder="수량, 진행률, 특이사항이 있으면 적습니다."></textarea>
                        </div>
                    </div>
                </div>

                <details class="ex-details">
                    <summary>회사·분류·계획/실적 변경</summary>
                    <div class="ex-details-content">
                        <div class="ex-field"><label for="company_name">회사·사업부</label><select id="company_name" name="company_name"><?php if(!in_array('공통업무',$companies,true)): ?><option value="공통업무" <?= $selected_company==='공통업무'?'selected':'' ?>>공통업무</option><?php endif; ?><?php foreach($companies as $company): ?><option value="<?= smw_h($company) ?>" <?= $selected_company===$company?'selected':'' ?>><?= smw_h($company) ?></option><?php endforeach; ?></select></div>
                        <div class="ex-field"><label for="task_category">업무 분류</label><select id="task_category" name="task_category"><?php foreach($categories as $category): ?><option value="<?= smw_h($category) ?>" <?= $selected_category===$category?'selected':'' ?>><?= smw_h($category) ?></option><?php endforeach; ?></select></div>
                        <div class="ex-field"><label for="task_type">기록 구분</label><select id="task_type" name="task_type"><option value="actual">한 일·실적</option><option value="plan">할 일·계획</option></select></div>
                    </div>
                </details>

                <button class="ex-button primary ex-submit" type="submit"><i class="fa-solid fa-check"></i> 저장하고 같은 방식으로 계속 입력</button>
            </form>
        </section>

        <aside class="ex-card">
            <div class="ex-card-head"><div><h3>최근 내가 입력한 업무</h3><p>하위 직원 대신 입력한 내용 포함</p></div><a class="ex-button" href="task_history.php">전체</a></div>
            <?php if(empty($recent)): ?><div class="ex-empty">아직 간편 입력 내역이 없습니다.</div><?php else: ?><div class="ex-recent"><?php foreach($recent as $item): ?>
                <div class="ex-recent-item">
                    <div style="display:flex;justify-content:space-between;gap:8px"><strong><?= smw_h($item['plan_content']) ?></strong><span class="ex-badge"><?= smw_h($mode_labels[$item['input_mode']]) ?></span></div>
                    <small><?= smw_h($item['target_name']) ?> · <?= smw_h($item['company_name']) ?> · <?= date('m.d',strtotime($item['period_start'])) ?><?= $item['period_end']!==$item['period_start']?' ~ '.date('m.d',strtotime($item['period_end'])):'' ?></small>
                </div>
            <?php endforeach; ?></div><?php endif; ?>
        </aside>
    </div>
</main>
</body>
</html>

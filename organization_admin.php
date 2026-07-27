<?php
require_once 'smw_extensions.php';

$user = smw_current_user($conn);
if (!$user) {
    header('Location: login.php');
    exit;
}
if ((int)$user['is_admin'] !== 1) {
    http_response_code(403);
    exit('시스템 관리자 권한이 필요합니다.');
}
require_once 'groupware_shell.php';
$portal_identity = smw_portal_identity($conn);

$uid = (int)$user['id'];
$allowed_tabs = ['companies', 'departments', 'people', 'relations', 'approvals'];
$tab = in_array($_GET['tab'] ?? '', $allowed_tabs, true) ? (string)$_GET['tab'] : 'companies';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    smw_verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save_company') {
        $code = strtoupper(trim((string)($_POST['code'] ?? '')));
        $name = trim((string)($_POST['name'] ?? ''));
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($_POST['color'] ?? '')) ? (string)$_POST['color'] : '#2563eb';
        if ($code === '' || $name === '') {
            $_SESSION['org_message'] = ['error', '회사 코드와 회사명을 입력해 주세요.'];
        } else {
            $stmt = $conn->prepare(
                "INSERT INTO companies (code, name, color, sort_order)
                 VALUES (?, ?, ?, 99)
                 ON DUPLICATE KEY UPDATE name=VALUES(name), color=VALUES(color), is_active=1"
            );
            $stmt->bind_param('sss', $code, $name, $color);
            $stmt->execute();
            smw_audit_log($conn, 'company.save', 'company', (int)$stmt->insert_id ?: null, "회사 설정을 저장했습니다: {$name}");
            $_SESSION['org_message'] = ['success', '회사를 저장했습니다. 회사 수는 계속 추가할 수 있습니다.'];
        }
        header('Location: organization_admin.php?tab=companies');
        exit;
    }

    if ($action === 'save_department') {
        $company_id = (int)($_POST['company_id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $parent_id = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
        if ($company_id < 1 || $name === '') {
            $_SESSION['org_message'] = ['error', '회사와 사업부명을 입력해 주세요.'];
        } else {
            $stmt = $conn->prepare(
                "INSERT INTO departments (company_id, name, parent_id, sort_order)
                 VALUES (?, ?, ?, 99)
                 ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), is_active=1"
            );
            $stmt->bind_param('isi', $company_id, $name, $parent_id);
            $stmt->execute();
            smw_audit_log($conn, 'department.save', 'department', (int)$stmt->insert_id ?: null, "사업부 설정을 저장했습니다: {$name}");
            $_SESSION['org_message'] = ['success', '사업부를 저장했습니다. 같은 회사에 사업부를 계속 추가할 수 있습니다.'];
        }
        header('Location: organization_admin.php?tab=departments');
        exit;
    }

    if ($action === 'assign_user') {
        $target_user_id = (int)($_POST['user_id'] ?? 0);
        $company_id = (int)($_POST['company_id'] ?? 0);
        $department_id = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;
        $is_primary = isset($_POST['is_primary']) ? 1 : 0;
        $department_valid = true;
        if ($department_id !== null) {
            $department_check = $conn->query("SELECT id FROM departments WHERE id=$department_id AND company_id=$company_id");
            $department_valid = $department_check && $department_check->num_rows > 0;
        }
        if ($target_user_id < 1 || $company_id < 1) {
            $_SESSION['org_message'] = ['error', '직원과 회사를 선택해 주세요.'];
        } elseif (!$department_valid) {
            $_SESSION['org_message'] = ['error', '선택한 회사에 속한 사업부를 선택해 주세요.'];
        } else {
            if ($is_primary) {
                $conn->query("UPDATE user_org_assignments SET is_primary=0 WHERE user_id=$target_user_id");
            }
            $stmt = $conn->prepare(
                "INSERT INTO user_org_assignments (user_id, company_id, department_id, is_primary)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE department_id=VALUES(department_id), is_primary=VALUES(is_primary)"
            );
            $stmt->bind_param('iiii', $target_user_id, $company_id, $department_id, $is_primary);
            $stmt->execute();
            smw_audit_log($conn, 'organization.assign', 'user', $target_user_id, '직원의 회사·사업부 소속을 저장했습니다.');
            $_SESSION['org_message'] = ['success', '직원의 회사·사업부 소속을 저장했습니다.'];
        }
        header('Location: organization_admin.php?tab=people');
        exit;
    }

    if ($action === 'remove_assignment') {
        $target_user_id = (int)($_POST['user_id'] ?? 0);
        $company_id = (int)($_POST['company_id'] ?? 0);
        $stmt = $conn->prepare("DELETE FROM user_org_assignments WHERE user_id=? AND company_id=?");
        $stmt->bind_param('ii', $target_user_id, $company_id);
        $stmt->execute();
        smw_audit_log($conn, 'organization.unassign', 'user', $target_user_id, '직원의 회사 소속을 해제했습니다.');
        $_SESSION['org_message'] = ['success', '소속만 해제했습니다. 계정과 기존 보고 데이터는 유지됩니다.'];
        header('Location: organization_admin.php?tab=people');
        exit;
    }

    if ($action === 'add_relation') {
        $manager_id = (int)($_POST['manager_id'] ?? 0);
        $target_id = (int)($_POST['target_id'] ?? 0);
        if ($manager_id < 1 || $target_id < 1 || $manager_id === $target_id) {
            $_SESSION['org_message'] = ['error', '작성자와 작업자를 다르게 선택해 주세요.'];
        } else {
            $stmt = $conn->prepare("INSERT IGNORE INTO user_relations (viewer_id, target_id) VALUES (?, ?)");
            $stmt->bind_param('ii', $manager_id, $target_id);
            $stmt->execute();
            smw_audit_log($conn, 'relation.create', 'user', $manager_id, '작업자 선택 관계를 연결했습니다.');
            $_SESSION['org_message'] = ['success', '작성자가 본문에서 선택할 작업자를 연결했습니다.'];
        }
        header('Location: organization_admin.php?tab=relations');
        exit;
    }

    if ($action === 'remove_relation') {
        $manager_id = (int)($_POST['manager_id'] ?? 0);
        $target_id = (int)($_POST['target_id'] ?? 0);
        $stmt = $conn->prepare("DELETE FROM user_relations WHERE viewer_id=? AND target_id=?");
        $stmt->bind_param('ii', $manager_id, $target_id);
        $stmt->execute();
        smw_audit_log($conn, 'relation.delete', 'user', $manager_id, '작업자 선택 관계를 해제했습니다.');
        $_SESSION['org_message'] = ['success', '작업자 선택 연결을 해제했습니다. 기존 보고 데이터는 유지됩니다.'];
        header('Location: organization_admin.php?tab=relations');
        exit;
    }

    if ($action === 'save_approval') {
        $name = trim((string)($_POST['name'] ?? ''));
        $step1 = (int)($_POST['step1'] ?? 0);
        $step2 = !empty($_POST['step2']) ? (int)$_POST['step2'] : null;
        $step3 = !empty($_POST['step3']) ? (int)$_POST['step3'] : null;
        if ($name === '' || $step1 < 1) {
            $_SESSION['org_message'] = ['error', '결재선 이름과 1차 결재자를 선택해 주세요.'];
        } else {
            $stmt = $conn->prepare("INSERT INTO approval_templates (name, step1_id, step2_id, step3_id) VALUES (?, ?, ?, ?)");
            $stmt->bind_param('siii', $name, $step1, $step2, $step3);
            $stmt->execute();
            smw_audit_log($conn, 'approval_template.create', 'approval_template', (int)$stmt->insert_id, "결재선을 저장했습니다: {$name}");
            $_SESSION['org_message'] = ['success', '교차 회사 결재선을 저장했습니다.'];
        }
        header('Location: organization_admin.php?tab=approvals');
        exit;
    }
}

$message = $_SESSION['org_message'] ?? null;
unset($_SESSION['org_message']);
$companies = [];
$company_res = $conn->query("SELECT * FROM companies WHERE is_active=1 ORDER BY sort_order, name");
if ($company_res) $companies = $company_res->fetch_all(MYSQLI_ASSOC);
$departments = [];
$department_res = $conn->query(
    "SELECT d.*, c.name AS company_name, p.name AS parent_name
     FROM departments d
     JOIN companies c ON c.id=d.company_id
     LEFT JOIN departments p ON p.id=d.parent_id
     WHERE d.is_active=1
     ORDER BY c.sort_order, d.sort_order, d.name"
);
if ($department_res) $departments = $department_res->fetch_all(MYSQLI_ASSOC);
$users = [];
$user_res = $conn->query(
    "SELECT u.id, u.username, u.nickname, u.position,
            GROUP_CONCAT(DISTINCT CONCAT(c.name, IF(d.name IS NULL,'',CONCAT(' / ',d.name))) ORDER BY oa.is_primary DESC, c.sort_order SEPARATOR ', ') AS org_label
     FROM users u
     LEFT JOIN user_org_assignments oa ON oa.user_id=u.id
     LEFT JOIN companies c ON c.id=oa.company_id
     LEFT JOIN departments d ON d.id=oa.department_id
     GROUP BY u.id
     ORDER BY CASE u.position
         WHEN '회장' THEN 1 WHEN '대표' THEN 2 WHEN '사장' THEN 3
         WHEN '부회장' THEN 4 WHEN '부사장' THEN 5 WHEN '전무' THEN 6
         WHEN '상무' THEN 7 WHEN '이사' THEN 8 WHEN '본부장' THEN 9
         WHEN '실장' THEN 10 WHEN '부장' THEN 11 WHEN '차장' THEN 12
         WHEN '과장' THEN 13 WHEN '대리' THEN 14 WHEN '주임' THEN 15
         WHEN '사원' THEN 16 WHEN '인턴' THEN 17 ELSE 99 END,
         u.nickname"
);
if ($user_res) $users = $user_res->fetch_all(MYSQLI_ASSOC);
$assignments = [];
$assignment_res = $conn->query(
    "SELECT oa.*, u.nickname, u.position, u.username, c.name AS company_name, d.name AS department_name
     FROM user_org_assignments oa
     JOIN users u ON u.id=oa.user_id
     JOIN companies c ON c.id=oa.company_id
     LEFT JOIN departments d ON d.id=oa.department_id
     ORDER BY c.sort_order, d.sort_order, u.nickname"
);
if ($assignment_res) $assignments = $assignment_res->fetch_all(MYSQLI_ASSOC);
$relations = [];
$relation_res = $conn->query(
    "SELECT r.viewer_id, r.target_id,
            v.nickname AS manager_name, v.position AS manager_position,
            t.nickname AS target_name, t.position AS target_position
     FROM user_relations r
     JOIN users v ON v.id=r.viewer_id
     JOIN users t ON t.id=r.target_id
     ORDER BY v.nickname, t.nickname"
);
if ($relation_res) $relations = $relation_res->fetch_all(MYSQLI_ASSOC);
$approvals = [];
$approval_res = $conn->query(
    "SELECT a.*, u1.nickname AS step1_name, u2.nickname AS step2_name, u3.nickname AS step3_name
     FROM approval_templates a
     LEFT JOIN users u1 ON u1.id=a.step1_id
     LEFT JOIN users u2 ON u2.id=a.step2_id
     LEFT JOIN users u3 ON u3.id=a.step3_id
     ORDER BY a.id DESC"
);
if ($approval_res) $approvals = $approval_res->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>회사·사업부·결재선 관리 - <?= smw_h($portal_identity['name']) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/smw-extension.css?v=1">
    <link rel="stylesheet" href="assets/groupware-shell.css?v=2">
    <script src="assets/organization-admin.js?v=1" defer></script>
</head>
<body class="gw-body">
<?php smw_render_shell_header('organization', '회사·사업부 관리', true, (string)$user['nickname']); ?>

<main class="ex-shell ex-page">
    <section class="ex-heading">
        <div><h1>회사·사업부·인원·결재선</h1><p>회사와 사업부는 계속 추가할 수 있고, 결재자는 회사가 달라도 자유롭게 연결할 수 있습니다.</p></div>
    </section>

    <?php if($message): ?><div class="ex-message <?= smw_h($message[0]) ?>" role="status"><?= smw_h($message[1]) ?></div><?php endif; ?>

    <nav class="ex-tabs" aria-label="조직 관리 탭">
        <a class="<?= $tab==='companies'?'active':'' ?>" href="?tab=companies">회사</a>
        <a class="<?= $tab==='departments'?'active':'' ?>" href="?tab=departments">사업부</a>
        <a class="<?= $tab==='people'?'active':'' ?>" href="?tab=people">직원 소속</a>
        <a class="<?= $tab==='relations'?'active':'' ?>" href="?tab=relations">보고 관계</a>
        <a class="<?= $tab==='approvals'?'active':'' ?>" href="?tab=approvals">교차회사 결재선</a>
    </nav>

    <?php if($tab==='companies'): ?>
    <div class="ex-admin-grid">
        <section class="ex-card">
            <div class="ex-card-head"><div><h2>등록 회사</h2><p>회사 수 제한 없이 추가 가능</p></div></div>
            <div class="ex-table-wrap"><table class="ex-table"><thead><tr><th>코드</th><th>회사명</th><th>색상</th></tr></thead><tbody><?php foreach($companies as $company): ?><tr><td data-label="코드"><span class="ex-badge"><?= smw_h($company['code']) ?></span></td><td data-label="회사명"><strong><?= smw_h($company['name']) ?></strong></td><td data-label="색상"><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:<?= smw_h($company['color']) ?>"></span> <?= smw_h($company['color']) ?></td></tr><?php endforeach; ?></tbody></table></div>
        </section>
        <section class="ex-card">
            <div class="ex-card-head"><div><h2>회사 추가·수정</h2><p>같은 코드는 회사명과 색상을 수정합니다.</p></div></div>
            <form class="ex-card-body ex-stack" method="post"><input type="hidden" name="smw_csrf" value="<?= smw_h(smw_csrf_token()) ?>"><input type="hidden" name="action" value="save_company"><div class="ex-field"><label for="code">회사 코드</label><input id="code" name="code" maxlength="20" required placeholder="예: COMPANY_A"></div><div class="ex-field"><label for="name">회사명</label><input id="name" name="name" required placeholder="예: 우리회사"></div><div class="ex-field"><label for="color">구분 색상</label><input id="color" type="color" name="color" value="#2563eb"></div><button class="ex-button primary" type="submit">회사 저장</button></form>
        </section>
    </div>

    <?php elseif($tab==='departments'): ?>
    <div class="ex-admin-grid">
        <section class="ex-card">
            <div class="ex-card-head"><div><h2>사업부 목록</h2><p>회사별 관리부서·현장팀 구성</p></div></div>
            <?php if(empty($departments)): ?><div class="ex-empty">등록된 사업부가 없습니다.</div><?php else: ?><div class="ex-table-wrap"><table class="ex-table"><thead><tr><th>회사</th><th>사업부</th><th>상위 부서</th></tr></thead><tbody><?php foreach($departments as $department): ?><tr><td data-label="회사"><?= smw_h($department['company_name']) ?></td><td data-label="사업부"><strong><?= smw_h($department['name']) ?></strong></td><td data-label="상위 부서"><?= smw_h($department['parent_name'] ?: '-') ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
        </section>
        <section class="ex-card">
            <div class="ex-card-head"><div><h2>사업부 추가</h2><p>같은 회사에 필요한 만큼 추가합니다.</p></div></div>
            <form class="ex-card-body ex-stack" method="post"><input type="hidden" name="smw_csrf" value="<?= smw_h(smw_csrf_token()) ?>"><input type="hidden" name="action" value="save_department"><div class="ex-field"><label for="department_company">회사</label><select id="department_company" name="company_id" required><?php foreach($companies as $company): ?><option value="<?= (int)$company['id'] ?>"><?= smw_h($company['name']) ?></option><?php endforeach; ?></select></div><div class="ex-field"><label for="department_name">사업부·부서명</label><input id="department_name" name="name" required placeholder="예: 관리부, A현장팀"></div><div class="ex-field"><label for="parent_id">상위 부서 <span class="ex-help">(선택)</span></label><select id="parent_id" name="parent_id"><option value="">없음</option><?php foreach($departments as $department): ?><option value="<?= (int)$department['id'] ?>">[<?= smw_h($department['company_name']) ?>] <?= smw_h($department['name']) ?></option><?php endforeach; ?></select></div><button class="ex-button primary" type="submit">사업부 저장</button></form>
        </section>
    </div>

    <?php elseif($tab==='people'): ?>
    <div class="ex-admin-grid">
        <section class="ex-card">
            <div class="ex-card-head"><div><h2>회사별 직원</h2><p>한 직원은 여러 회사에도 소속될 수 있습니다.</p></div></div>
            <?php if(empty($assignments)): ?><div class="ex-empty">지정된 직원 소속이 없습니다.</div><?php else: ?><div class="ex-table-wrap"><table class="ex-table"><thead><tr><th>직원</th><th>회사</th><th>사업부</th><th>주 소속</th><th>관리</th></tr></thead><tbody><?php foreach($assignments as $assignment): ?><tr><td data-label="직원"><strong><?= smw_h($assignment['nickname']) ?></strong><br><small><?= smw_h($assignment['position']) ?></small></td><td data-label="회사"><?= smw_h($assignment['company_name']) ?></td><td data-label="사업부"><?= smw_h($assignment['department_name'] ?: '-') ?></td><td data-label="주 소속"><?= $assignment['is_primary']?'예':'-' ?></td><td data-label="관리"><form method="post" onsubmit="return confirm('소속만 해제하며 계정과 보고 데이터는 유지됩니다. 계속할까요?')"><input type="hidden" name="smw_csrf" value="<?= smw_h(smw_csrf_token()) ?>"><input type="hidden" name="action" value="remove_assignment"><input type="hidden" name="user_id" value="<?= (int)$assignment['user_id'] ?>"><input type="hidden" name="company_id" value="<?= (int)$assignment['company_id'] ?>"><button class="ex-button danger" type="submit">해제</button></form></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
        </section>
        <section class="ex-card">
            <div class="ex-card-head"><div><h2>직원 소속 지정</h2><p>실제 가입 계정을 회사·사업부에 연결</p></div></div>
            <form class="ex-card-body ex-stack" method="post"><input type="hidden" name="smw_csrf" value="<?= smw_h(smw_csrf_token()) ?>"><input type="hidden" name="action" value="assign_user"><div class="ex-field"><label for="org_user">직원</label><select id="org_user" name="user_id" required><?php foreach($users as $person): ?><option value="<?= (int)$person['id'] ?>"><?= smw_h($person['nickname']) ?> · <?= smw_h($person['position']) ?><?= $person['org_label']?' · '.smw_h($person['org_label']):'' ?></option><?php endforeach; ?></select></div><div class="ex-field"><label for="org_company">회사</label><select id="org_company" name="company_id" required><?php foreach($companies as $company): ?><option value="<?= (int)$company['id'] ?>"><?= smw_h($company['name']) ?></option><?php endforeach; ?></select></div><div class="ex-field"><label for="org_department">사업부</label><select id="org_department" name="department_id"><option value="">미지정</option><?php foreach($departments as $department): ?><option value="<?= (int)$department['id'] ?>" data-company="<?= (int)$department['company_id'] ?>">[<?= smw_h($department['company_name']) ?>] <?= smw_h($department['name']) ?></option><?php endforeach; ?></select></div><label><input type="checkbox" name="is_primary" value="1"> 주 소속으로 지정</label><button class="ex-button primary" type="submit">소속 저장</button></form>
        </section>
    </div>

    <?php elseif($tab==='relations'): ?>
    <div class="ex-admin-grid">
        <section class="ex-card">
            <div class="ex-card-head"><div><h2>작성자 → 선택 가능 작업자</h2><p>본인 일일보고 본문에 작업자별 기록 줄을 넣기 위한 관계</p></div></div>
            <?php if(empty($relations)): ?><div class="ex-empty">연결된 작업자 선택 관계가 없습니다.</div><?php else: ?><div class="ex-table-wrap"><table class="ex-table"><thead><tr><th>보고서 작성자</th><th>선택 가능 작업자</th><th>관리</th></tr></thead><tbody><?php foreach($relations as $relation): ?><tr><td data-label="보고서 작성자"><strong><?= smw_h($relation['manager_name']) ?></strong> · <?= smw_h($relation['manager_position']) ?></td><td data-label="선택 가능 작업자"><strong><?= smw_h($relation['target_name']) ?></strong> · <?= smw_h($relation['target_position']) ?></td><td data-label="관리"><form method="post" onsubmit="return confirm('작업자 선택 관계만 해제합니다. 계속할까요?')"><input type="hidden" name="smw_csrf" value="<?= smw_h(smw_csrf_token()) ?>"><input type="hidden" name="action" value="remove_relation"><input type="hidden" name="manager_id" value="<?= (int)$relation['viewer_id'] ?>"><input type="hidden" name="target_id" value="<?= (int)$relation['target_id'] ?>"><button class="ex-button danger" type="submit">해제</button></form></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
        </section>
        <section class="ex-card">
            <div class="ex-card-head"><div><h2>작업자 선택 관계 연결</h2><p>작성자가 본인 보고서에 넣을 작업자 이름을 선택할 수 있게 합니다.</p></div></div>
            <form class="ex-card-body ex-stack" method="post"><input type="hidden" name="smw_csrf" value="<?= smw_h(smw_csrf_token()) ?>"><input type="hidden" name="action" value="add_relation"><div class="ex-field"><label for="manager_id">보고서 작성자</label><select id="manager_id" name="manager_id" required><?php foreach($users as $person): ?><option value="<?= (int)$person['id'] ?>"><?= smw_h($person['nickname']) ?> · <?= smw_h($person['position']) ?><?= $person['org_label']?' · '.smw_h($person['org_label']):'' ?></option><?php endforeach; ?></select></div><div class="ex-field"><label for="target_id">선택 가능 작업자</label><select id="target_id" name="target_id" required><?php foreach($users as $person): ?><option value="<?= (int)$person['id'] ?>"><?= smw_h($person['nickname']) ?> · <?= smw_h($person['position']) ?><?= $person['org_label']?' · '.smw_h($person['org_label']):'' ?></option><?php endforeach; ?></select></div><button class="ex-button primary" type="submit">작업자 선택 관계 연결</button></form>
        </section>
    </div>

    <?php else: ?>
    <div class="ex-admin-grid">
        <section class="ex-card">
            <div class="ex-card-head"><div><h2>등록 결재선</h2><p>기존 approval_templates와 완전히 호환</p></div></div>
            <?php if(empty($approvals)): ?><div class="ex-empty">등록된 결재선이 없습니다.</div><?php else: ?><div class="ex-table-wrap"><table class="ex-table"><thead><tr><th>결재선</th><th>1차</th><th>2차</th><th>3차</th></tr></thead><tbody><?php foreach($approvals as $approval): ?><tr><td data-label="결재선"><strong><?= smw_h($approval['name']) ?></strong></td><td data-label="1차"><?= smw_h($approval['step1_name'] ?: '-') ?></td><td data-label="2차"><?= smw_h($approval['step2_name'] ?: '-') ?></td><td data-label="3차"><?= smw_h($approval['step3_name'] ?: '-') ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
        </section>
        <section class="ex-card">
            <div class="ex-card-head"><div><h2>교차 회사 결재선</h2><p>회사·사업부와 무관하게 결재자를 선택할 수 있습니다.</p></div></div>
            <form class="ex-card-body ex-stack" method="post"><input type="hidden" name="smw_csrf" value="<?= smw_h(smw_csrf_token()) ?>"><input type="hidden" name="action" value="save_approval"><div class="ex-field"><label for="approval_name">결재선 이름</label><input id="approval_name" name="name" required placeholder="예: 현장 구매 결재"></div><?php foreach([1,2,3] as $step): ?><div class="ex-field"><label for="step<?= $step ?>"><?= $step ?>차 결재자<?= $step>1?' (선택)':'' ?></label><select id="step<?= $step ?>" name="step<?= $step ?>" <?= $step===1?'required':'' ?>><option value="">선택 안 함</option><?php foreach($users as $person): ?><option value="<?= (int)$person['id'] ?>"><?= smw_h($person['nickname']) ?> · <?= smw_h($person['position']) ?><?= $person['org_label']?' · '.smw_h($person['org_label']):'' ?></option><?php endforeach; ?></select></div><?php endforeach; ?><button class="ex-button primary" type="submit">결재선 저장</button></form>
        </section>
    </div>
    <?php endif; ?>
</main>
</body>
</html>

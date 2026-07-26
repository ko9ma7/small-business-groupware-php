<?php
require_once __DIR__ . '/smw_extensions.php';
if (!function_exists('smw_render_shell_header')) {
    function smw_render_shell_header(string $active = '', string $subtitle = '', ?bool $isAdmin = null, string $nickname = ''): void
    {
        $page = basename((string)($_SERVER['PHP_SELF'] ?? ''));
        if ($active === '') {
            $map = [
                'index.php' => 'dashboard', 'daily.php' => 'daily', 'task_history.php' => 'daily',
                'weekly_presentation.php' => 'weekly', 'approval_draft.php' => 'approval',
                'approval_list.php' => 'approval', 'approval_process.php' => 'approval',
                'board.php' => 'board', 'address_book.php' => 'people', 'profile.php' => 'profile',
                'admin.php' => 'admin', 'organization_admin.php' => 'organization', 'schedule.php' => 'schedule',
            ];
            $active = $map[$page] ?? '';
        }
        if ($isAdmin === null) $isAdmin = (int)($_SESSION['admin'] ?? 0) === 1;
        $labels = [
            'dashboard' => '통합 대시보드', 'daily' => '일일 업무 관리', 'weekly' => '주간 업무 보고',
            'approval' => '전자결재', 'board' => '사내 게시판', 'people' => '임직원 주소록',
            'profile' => '내 정보', 'admin' => '시스템 관리', 'organization' => '회사·사업부 관리', 'schedule' => '사내 일정',
        ];
        global $conn;
        $identity = isset($conn) && $conn instanceof mysqli
            ? smw_portal_identity($conn)
            : ['name'=>'GROUPWARE', 'companies'=>'업무 포털'];
        if ($subtitle === '') $subtitle = $labels[$active] ?? $identity['companies'];
        $items = [
            ['dashboard', 'index.php', 'fa-house', '대시보드'],
            ['daily', 'daily.php', 'fa-pen-to-square', '일일 업무'],
            ['weekly', 'weekly_presentation.php', 'fa-chart-column', '주간 보고'],
            ['approval', 'approval_list.php', 'fa-file-signature', '전자결재'],
            ['board', 'board.php', 'fa-clipboard-list', '게시판'],
            ['people', 'address_book.php', 'fa-address-book', '주소록'],
        ];
        if ($isAdmin) $items[] = ['organization', 'organization_admin.php', 'fa-sitemap', '조직 관리'];
        ?>
        <header class="gw-topbar">
            <div class="gw-topbar-inner">
                <a class="gw-brand" href="index.php" aria-label="<?= htmlspecialchars($identity['name'], ENT_QUOTES, 'UTF-8') ?> 대시보드"><span class="gw-brand-mark"><i class="fa-solid fa-layer-group" aria-hidden="true"></i></span><span class="gw-brand-copy"><strong><?= htmlspecialchars($identity['name'], ENT_QUOTES, 'UTF-8') ?></strong><small><?= htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8') ?></small></span></a>
                <nav class="gw-nav gw-global-nav" aria-label="주요 메뉴">
                    <?php foreach ($items as [$key, $href, $icon, $label]): ?>
                        <a class="<?= $active === $key ? 'active' : '' ?>" href="<?= $href ?>"<?= $active === $key ? ' aria-current="page"' : '' ?>><i class="fa-solid <?= $icon ?>" aria-hidden="true"></i><span><?= $label ?></span></a>
                    <?php endforeach; ?>
                </nav>
                <nav class="gw-nav gw-account-nav" aria-label="개인 및 관리 메뉴">
                    <a class="<?= $active === 'profile' ? 'active' : '' ?>" href="profile.php" title="내 정보"><i class="fa-solid fa-user-gear"></i><span><?= $nickname !== '' ? htmlspecialchars($nickname, ENT_QUOTES, 'UTF-8') : '내 정보' ?></span></a>
                    <?php if ($isAdmin): ?><a class="<?= $active === 'admin' ? 'active' : '' ?>" href="admin.php" title="관리"><i class="fa-solid fa-gear"></i><span>관리</span></a><?php endif; ?>
                    <a href="logout.php" title="로그아웃"><i class="fa-solid fa-arrow-right-from-bracket"></i><span class="gw-sr-only">로그아웃</span></a>
                </nav>
            </div>
        </header>
        <?php
    }
}

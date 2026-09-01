<?php
$navIsHelpdeskShell = (function_exists('currentPageIsHelpdeskShell') && currentPageIsHelpdeskShell())
    || (function_exists('currentUserIsHelpdeskAdmin') && currentUserIsHelpdeskAdmin())
    || (function_exists('currentUserIsOperator') && currentUserIsOperator() && !(function_exists('currentUserIsAdmin') && currentUserIsAdmin()));
$navIsSuperAdmin = function_exists('currentUserIsAdmin') && currentUserIsAdmin();
$navIsHelpdeskAdmin = function_exists('currentUserIsHelpdeskAdmin') && currentUserIsHelpdeskAdmin();
$navBrandHref = function_exists('currentUserHomeUrl') ? currentUserHomeUrl() : (function_exists('adminUrl') ? adminUrl('index.php') : 'index.php');
// Super admin di halaman helpdesk → branding Helpdesk; di E-Recepsionis → E-Recepsionis
if ($navIsSuperAdmin) {
    $navIsHelpdeskShell = function_exists('currentPageIsHelpdeskShell') && currentPageIsHelpdeskShell();
}
$navBrandLabel = $navIsHelpdeskShell || $navIsHelpdeskAdmin ? 'Helpdesk IT' : 'E-Recepsionis';
$navBrandIcon = $navIsHelpdeskShell || $navIsHelpdeskAdmin ? 'bi-headset' : 'bi-reception-4';
?>
<nav class="navbar navbar-expand-lg">
    <div class="container-fluid">
        <div class="d-flex align-items-center gap-2">
            <button type="button"
                    class="btn btn-admin-menu d-lg-none"
                    id="adminMenuToggle"
                    aria-label="Buka menu navigasi"
                    aria-expanded="false"
                    aria-controls="adminSidebar">
                <i class="bi bi-list"></i>
            </button>
            <a class="navbar-brand" href="<?= htmlspecialchars($navBrandHref) ?>">
                <span class="brand-mark"><i class="bi <?= htmlspecialchars($navBrandIcon) ?>"></i></span>
                <span class="brand-text"><?= htmlspecialchars($navBrandLabel) ?></span>
            </a>
        </div>
        <div class="navbar-nav ms-auto flex-row align-items-center gap-2">
            <?php if ($navIsSuperAdmin): ?>
                <?php if ($navIsHelpdeskShell): ?>
                    <a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars(adminUrl('index.php')) ?>" title="Ke E-Recepsionis">
                        <i class="bi bi-reception-4"></i>
                        <span class="d-none d-md-inline">E-Recepsionis</span>
                    </a>
                <?php else: ?>
                    <a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars(adminUrl('helpdesk_dashboard.php')) ?>" title="Ke Dashboard Helpdesk">
                        <i class="bi bi-headset"></i>
                        <span class="d-none d-md-inline">Dashboard Helpdesk</span>
                    </a>
                <?php endif; ?>
            <?php endif; ?>
            <span class="navbar-user-pill">
                <i class="bi bi-person-circle text-secondary"></i>
                <span class="user-name"><?= htmlspecialchars($_SESSION['nama_lengkap'] ?? $_SESSION['username'] ?? 'Administrator') ?></span>
                <span class="role-tag"><?= htmlspecialchars(function_exists('currentUserRoleLabel') ? currentUserRoleLabel() : ucfirst((string) ($_SESSION['role'] ?? 'user'))) ?></span>
            </span>
            <a class="btn btn-logout btn-sm" href="<?= htmlspecialchars(function_exists('adminUrl') ? adminUrl('logout.php') : 'logout.php') ?>" title="Logout">
                <i class="bi bi-box-arrow-right"></i>
                <span class="logout-label">Logout</span>
            </a>
        </div>
    </div>
</nav>
<div class="admin-sidebar-backdrop" id="adminSidebarBackdrop" aria-hidden="true"></div>

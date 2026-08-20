<div class="col-md-2 col-lg-2 sidebar p-0" id="adminSidebar">
    <?php
    if (!function_exists('recepsionis_user_is_helpdesk_pic')) {
        require_once dirname(__DIR__) . '/staff_call_routing.php';
    }
    $showHelpdeskQrMenu = function_exists('currentUserIsAdmin') && currentUserIsAdmin();
    $sidebarUserId = (int) ($_SESSION['user_id'] ?? 0);
    $sidebarIsAdmin = function_exists('currentUserIsAdmin') && currentUserIsAdmin();
    $sidebarActionCounts = recepsionis_get_helpdesk_action_counts(
        $koneksi,
        $sidebarUserId,
        $sidebarIsAdmin,
        $sidebarIsAdmin ? 'all' : 'mine',
        (string) ($_SESSION['role'] ?? '')
    );
    ?>
    <nav class="nav flex-column">
        <?php if (function_exists('currentUserIsAdmin') && currentUserIsAdmin()): ?>
            <div class="nav-section-label">Utama</div>
            <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : '' ?>" href="<?= htmlspecialchars(function_exists('adminUrl') ? adminUrl('index.php') : 'index.php') ?>">
                <i class="bi bi-speedometer2"></i> Dashboard
            </a>
            <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'visitors.php' ? 'active' : '' ?>" href="<?= htmlspecialchars(function_exists('adminUrl') ? adminUrl('visitors.php') : 'visitors.php') ?>">
                <i class="bi bi-people"></i> Data Tamu
            </a>

            <div class="nav-section-label">Operasional</div>
        <?php else: ?>
            <div class="nav-section-label">Tugas</div>
            <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'operator_dashboard.php' ? 'active' : '' ?>" href="<?= htmlspecialchars(function_exists('adminUrl') ? adminUrl('operator_dashboard.php') : 'operator_dashboard.php') ?>">
                <i class="bi bi-speedometer2"></i> Dashboard
            </a>
        <?php endif; ?>

        <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'staff_calls.php' ? 'active' : '' ?>" href="<?= htmlspecialchars(function_exists('adminUrl') ? adminUrl('staff_calls.php') : 'staff_calls.php') ?>" data-helpdesk-nav="sidebar">
            <i class="bi bi-headset"></i> Helpdesk
            <?php if ($sidebarActionCounts['total'] > 0): ?>
                <span class="badge bg-danger rounded-pill notification-badge helpdesk-action-badge" data-helpdesk-badge="total"><?= htmlspecialchars(recepsionis_format_action_count($sidebarActionCounts['total'])) ?></span>
            <?php endif; ?>
        </a>
        <?php if (!(function_exists('currentUserIsAdmin') && currentUserIsAdmin())): ?>
            <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'settings.php' ? 'active' : '' ?>" href="<?= htmlspecialchars(function_exists('adminUrl') ? adminUrl('settings.php') : 'settings.php') ?>">
                <i class="bi bi-bell-slash"></i> Preferensi Notifikasi
            </a>
        <?php endif; ?>

        <?php if ($showHelpdeskQrMenu): ?>
            <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'helpdesk_it.php' ? 'active' : '' ?>" href="<?= htmlspecialchars(function_exists('adminUrl') ? adminUrl('helpdesk_it.php') : 'helpdesk_it.php') ?>">
                <i class="bi bi-qr-code"></i> QR Tiket Kelas
            </a>
        <?php endif; ?>

        <?php if (function_exists('currentUserIsAdmin') && currentUserIsAdmin()): ?>
            <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'rooms.php' ? 'active' : '' ?>" href="<?= htmlspecialchars(function_exists('adminUrl') ? adminUrl('rooms.php') : 'rooms.php') ?>">
                <i class="bi bi-door-open"></i> Ruangan
            </a>
            <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'floor_plans.php' ? 'active' : '' ?>" href="<?= htmlspecialchars(function_exists('adminUrl') ? adminUrl('floor_plans.php') : 'floor_plans.php') ?>">
                <i class="bi bi-map"></i> Denah Ruangan
            </a>
            <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'prodi.php' ? 'active' : '' ?>" href="<?= htmlspecialchars(function_exists('adminUrl') ? adminUrl('prodi.php') : 'prodi.php') ?>">
                <i class="bi bi-mortarboard"></i> Program Studi
            </a>

            <div class="nav-section-label">Administrasi</div>
            <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'laporan.php' ? 'active' : '' ?>" href="<?= htmlspecialchars(function_exists('adminUrl') ? adminUrl('laporan.php') : 'laporan.php') ?>">
                <i class="bi bi-file-earmark-bar-graph"></i> Laporan
            </a>
            <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'users.php' ? 'active' : '' ?>" href="<?= htmlspecialchars(function_exists('adminUrl') ? adminUrl('users.php') : 'users.php') ?>">
                <i class="bi bi-person-gear"></i> Kelola User
            </a>
            <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'complaint_categories.php' ? 'active' : '' ?>" href="<?= htmlspecialchars(function_exists('adminUrl') ? adminUrl('complaint_categories.php') : 'complaint_categories.php') ?>">
                <i class="bi bi-tags"></i> Kategori Pengaduan
            </a>
            <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'notifications.php' ? 'active' : '' ?>" href="<?= htmlspecialchars(function_exists('adminUrl') ? adminUrl('notifications.php') : 'notifications.php') ?>">
                <i class="bi bi-bell"></i> Notifikasi
                <?php
                $unread_count = $koneksi->query("SELECT COUNT(*) as count FROM notifications WHERE status = 'unread'")->fetch_assoc()['count'];
                if ($unread_count > 0):
                ?>
                    <span class="badge bg-danger rounded-pill notification-badge">
                        <?= $unread_count > 99 ? '99+' : $unread_count ?>
                    </span>
                <?php endif; ?>
            </a>
            <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'settings.php' ? 'active' : '' ?>" href="<?= htmlspecialchars(function_exists('adminUrl') ? adminUrl('settings.php') : 'settings.php') ?>">
                <i class="bi bi-gear"></i> Settings
            </a>
        <?php endif; ?>
    </nav>
</div>

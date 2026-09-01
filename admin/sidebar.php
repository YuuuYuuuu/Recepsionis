<div class="col-md-2 col-lg-2 sidebar p-0" id="adminSidebar">
    <?php
    // Sidebar E-Recepsionis saja (tanpa menu Helpdesk)
    ?>
    <nav class="nav flex-column">
        <div class="nav-section-label">Utama</div>
        <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : '' ?>" href="<?= htmlspecialchars(function_exists('adminUrl') ? adminUrl('index.php') : 'index.php') ?>">
            <i class="bi bi-speedometer2"></i> Dashboard
        </a>
        <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'visitors.php' ? 'active' : '' ?>" href="<?= htmlspecialchars(function_exists('adminUrl') ? adminUrl('visitors.php') : 'visitors.php') ?>">
            <i class="bi bi-people"></i> Data Tamu
        </a>
        <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'staff_calls.php' ? 'active' : '' ?>" href="<?= htmlspecialchars(function_exists('adminUrl') ? adminUrl('staff_calls.php') : 'staff_calls.php') ?>">
            <i class="bi bi-telephone"></i> Daftar Panggilan
        </a>

        <div class="nav-section-label">Operasional</div>
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
        <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'visitor_branding.php' ? 'active' : '' ?>" href="<?= htmlspecialchars(function_exists('adminUrl') ? adminUrl('visitor_branding.php') : 'visitor_branding.php') ?>">
            <i class="bi bi-palette"></i> Branding Pengunjung
        </a>
        <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'settings.php' ? 'active' : '' ?>" href="<?= htmlspecialchars(function_exists('adminUrl') ? adminUrl('settings.php') : 'settings.php') ?>">
            <i class="bi bi-gear"></i> Settings
        </a>
    </nav>
</div>

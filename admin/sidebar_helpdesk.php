<?php
/**
 * Sidebar klasik Helpdesk — hanya fallback; navigasi utama di helpdesk_dashboard.php.
 */
require_once __DIR__ . '/helpdesk_hub.php';

if (!function_exists('recepsionis_user_is_helpdesk_pic')) {
    require_once dirname(__DIR__) . '/staff_call_routing.php';
}

$hdPage = basename($_SERVER['PHP_SELF'] ?? '');
$hdSection = helpdeskSection();
$hdUserId = (int) ($_SESSION['user_id'] ?? 0);
$hdIsAdmin = function_exists('currentUserIsAdmin') && currentUserIsAdmin();
$hdCanManage = function_exists('currentUserCanManageHelpdesk') && currentUserCanManageHelpdesk();
$hdActionCounts = recepsionis_get_helpdesk_action_counts(
    $koneksi,
    $hdUserId,
    $hdIsAdmin || (function_exists('currentUserIsHelpdeskAdmin') && currentUserIsHelpdeskAdmin()),
    ($hdIsAdmin || (function_exists('currentUserIsHelpdeskAdmin') && currentUserIsHelpdeskAdmin())) ? 'all' : 'mine',
    (string) ($_SESSION['role'] ?? '')
);
$ticketGroupOpen = true;
$assetGroupOpen = $hdSection === 'qr' || in_array($hdPage, ['helpdesk_it.php', 'helpdesk_it_print.php'], true);
?>
<div class="col-md-2 col-lg-2 sidebar p-0" id="adminSidebar">
    <nav class="nav flex-column">
        <div class="nav-section-label">Helpdesk IT</div>

        <a class="nav-link" href="<?= htmlspecialchars(helpdeskUrl('dashboard')) ?>">
            <i class="bi bi-grid-1x2"></i> Dashboard
        </a>

        <?php if (function_exists('currentUserIsOperator') && currentUserIsOperator() && !$hdCanManage): ?>
            <a class="nav-link" href="<?= htmlspecialchars(helpdeskUrl('tasks')) ?>">
                <i class="bi bi-speedometer2"></i> Tugas Saya
            </a>
        <?php endif; ?>

        <div class="nav-group <?= $ticketGroupOpen ? 'is-open' : '' ?>" data-nav-accordion>
            <button type="button"
                    class="nav-link nav-group-toggle"
                    aria-expanded="<?= $ticketGroupOpen ? 'true' : 'false' ?>">
                <span class="nav-group-toggle-label">
                    <i class="bi bi-ticket-detailed"></i> Tiket QR
                    <?php if (($hdActionCounts['tickets'] ?? 0) > 0): ?>
                        <span class="badge bg-danger rounded-pill notification-badge helpdesk-action-badge" data-helpdesk-badge="tickets"><?= htmlspecialchars(recepsionis_format_action_count($hdActionCounts['tickets'])) ?></span>
                    <?php endif; ?>
                </span>
                <i class="bi bi-chevron-down nav-group-chevron" aria-hidden="true"></i>
            </button>
            <div class="nav-sub">
                <a class="nav-link" href="<?= htmlspecialchars(helpdeskUrl('tickets', ['status' => 'pending'])) ?>" data-helpdesk-nav="sidebar">
                    <i class="bi bi-journal-text"></i> Daftar Laporan
                    <?php if (($hdActionCounts['tickets'] ?? 0) > 0): ?>
                        <span class="badge bg-danger rounded-pill notification-badge helpdesk-action-badge" data-helpdesk-badge="tickets"><?= htmlspecialchars(recepsionis_format_action_count($hdActionCounts['tickets'])) ?></span>
                    <?php endif; ?>
                </a>
            </div>
        </div>

        <?php if ($hdCanManage): ?>
            <div class="nav-group <?= $assetGroupOpen ? 'is-open' : '' ?>" data-nav-accordion>
                <button type="button"
                        class="nav-link nav-group-toggle"
                        aria-expanded="<?= $assetGroupOpen ? 'true' : 'false' ?>">
                    <span class="nav-group-toggle-label">
                        <i class="bi bi-qr-code"></i> QR &amp; Akses
                    </span>
                    <i class="bi bi-chevron-down nav-group-chevron" aria-hidden="true"></i>
                </button>
                <div class="nav-sub">
                    <a class="nav-link" href="<?= htmlspecialchars(helpdeskUrl('qr')) ?>">
                        <i class="bi bi-qr-code-scan"></i> QR Tiket Kelas
                    </a>
                </div>
            </div>

            <a class="nav-link" href="<?= htmlspecialchars(helpdeskUrl('users')) ?>">
                <i class="bi bi-person-gear"></i> Kelola User
            </a>
        <?php endif; ?>

        <div class="nav-section-label">Akun</div>
        <a class="nav-link" href="<?= htmlspecialchars(helpdeskUrl('prefs')) ?>">
            <i class="bi bi-bell"></i> Preferensi Notifikasi
        </a>

        <?php if ($hdIsAdmin): ?>
            <div class="nav-section-label">Sistem</div>
            <a class="nav-link" href="<?= htmlspecialchars(adminUrl('index.php')) ?>">
                <i class="bi bi-arrow-left-circle"></i> Ke E-Recepsionis
            </a>
        <?php endif; ?>
    </nav>
</div>

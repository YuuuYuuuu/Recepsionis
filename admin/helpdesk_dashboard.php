<?php
/**
 * Hub tunggal Helpdesk & IT Support.
 * Semua fitur helpdesk lewat file ini (?section=…); E-Recepsionis tetap di /admin/*.
 */
require_once 'auth.php';
require_once '../staff_call_routing.php';
require_once 'helpdesk_hub.php';

requireHelpdeskAccess();

define('HELPDESK_HUB', true);

$hdSection = helpdeskSection();
$hdCanManage = function_exists('currentUserCanManageHelpdesk') && currentUserCanManageHelpdesk();
$hdIsAdmin = function_exists('currentUserIsAdmin') && currentUserIsAdmin();
$hdIsOperatorOnly = function_exists('currentUserIsOperator') && currentUserIsOperator() && !$hdCanManage;

// Operator non-manager: default ke tugas saya (bukan overview admin)
if ($hdSection === 'dashboard' && $hdIsOperatorOnly && !isset($_GET['section'])) {
    $hdSection = 'tasks';
}

if ($hdSection === 'qr' && !$hdCanManage) {
    header('Location: ' . helpdeskUrl('dashboard'));
    exit;
}

if ($hdSection === 'users' && !$hdCanManage) {
    header('Location: ' . helpdeskUrl('dashboard'));
    exit;
}

$userName = (string) ($_SESSION['nama_lengkap'] ?? $_SESSION['username'] ?? 'Operator');
$userRole = function_exists('currentUserRoleLabel')
    ? currentUserRoleLabel()
    : ucfirst((string) ($_SESSION['role'] ?? 'user'));
$userInitials = '';
foreach (preg_split('/\s+/', trim($userName)) as $part) {
    if ($part !== '') {
        $userInitials .= mb_strtoupper(mb_substr($part, 0, 1));
    }
    if (mb_strlen($userInitials) >= 2) {
        break;
    }
}
if ($userInitials === '') {
    $userInitials = 'HD';
}

$hdActionCounts = recepsionis_get_helpdesk_action_counts(
    $koneksi,
    (int) ($_SESSION['user_id'] ?? 0),
    $hdIsAdmin || (function_exists('currentUserIsHelpdeskAdmin') && currentUserIsHelpdeskAdmin()),
    ($hdIsAdmin || (function_exists('currentUserIsHelpdeskAdmin') && currentUserIsHelpdeskAdmin())) ? 'all' : 'mine',
    (string) ($_SESSION['role'] ?? '')
);
// Badge Helpdesk hanya tiket QR (bukan panggilan tamu)
$hdTicketBadge = (int) ($hdActionCounts['tickets'] ?? 0);

$sectionTitles = [
    'dashboard' => ['Overview', 'Main Dashboard'],
    'tickets' => ['Tiket QR', 'Daftar Laporan'],
    'qr' => ['QR & Akses', 'QR Tiket Kelas'],
    'users' => ['Tim Helpdesk', 'Kelola User Helpdesk'],
    'prefs' => ['Akun', 'Preferensi Notifikasi'],
    'tasks' => ['Tugas Saya', 'Antrian PIC'],
];
$crumb = $sectionTitles[$hdSection][0] ?? 'Helpdesk';
$pageHeading = $sectionTitles[$hdSection][1] ?? 'Helpdesk';

$needsBootstrap = in_array($hdSection, ['tickets', 'qr', 'prefs', 'tasks', 'users'], true);
$overviewApiUrl = function_exists('apiUrl')
    ? apiUrl('get_helpdesk_dashboard_overview.php')
    : '../api/get_helpdesk_dashboard_overview.php';
$logoutUrl = htmlspecialchars(adminUrl('logout.php'));
$cssPath = dirname(__DIR__) . '/assets/css/helpdesk-dashboard.css';
$cssVer = is_file($cssPath) ? (int) filemtime($cssPath) : time();

// —— Data overview (section dashboard saja) ——
$metrics = ['open' => 0, 'new' => 0, 'in_progress' => 0, 'resolved_today' => 0, 'sla_breach' => 0];
$recentTickets = [];
$usingLiveData = false;

if ($hdSection === 'dashboard' && recepsionis_table_exists($koneksi, 'helpdesk_it_tickets')) {
    $overview = recepsionis_helpdesk_dashboard_overview($koneksi, 10);
    $metrics = $overview['metrics'];
    $recentTickets = $overview['recent_tickets'];
    $usingLiveData = $recentTickets !== [];
    // Samakan badge sidebar dengan antrian aktif (sama filter Pending Daftar Laporan)
    $hdTicketBadge = (int) ($metrics['open'] ?? $hdTicketBadge);
}

$ticketsUrl = htmlspecialchars(helpdeskUrl('tickets'));
$ticketsPendingUrl = htmlspecialchars(helpdeskUrl('tickets', ['status' => 'pending']));
$ticketsDoneUrl = htmlspecialchars(helpdeskUrl('tickets', ['status' => 'answered']));
$qrUrl = htmlspecialchars(helpdeskUrl('qr'));
$usersUrl = htmlspecialchars(helpdeskUrl('users'));
$prefsUrl = htmlspecialchars(helpdeskUrl('prefs'));
$tasksUrl = htmlspecialchars(helpdeskUrl('tasks'));
$dashUrl = htmlspecialchars(helpdeskUrl('dashboard'));
$daftarLaporanUrl = $ticketsPendingUrl;
$alertLottieUrl = rtrim(BASE_URL, '/') . '/assets/images/alert_1.lottie';
$hdAlertsCssPath = dirname(__DIR__) . '/assets/css/helpdesk-dashboard-alerts.css';
$hdAlertsCssVer = is_file($hdAlertsCssPath) ? (int) filemtime($hdAlertsCssPath) : time();
$hdAlertsJsPath = dirname(__DIR__) . '/assets/js/helpdesk-dashboard-alerts.js';
$hdAlertsJsVer = is_file($hdAlertsJsPath) ? (int) filemtime($hdAlertsJsPath) : time();

$ticketGroupOpen = in_array($hdSection, ['tickets', 'dashboard', 'tasks'], true);
$assetGroupOpen = $hdSection === 'qr';
?>
<!DOCTYPE html>
<html lang="id" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageHeading) ?> — Helpdesk IT</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <?php if ($needsBootstrap): ?>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="../assets/css/style.css" rel="stylesheet">
        <link href="../assets/css/toast.css" rel="stylesheet">
        <?php
        $hdStatusNotifyCss = dirname(__DIR__) . '/assets/css/helpdesk-status-notify.css';
        $hdStatusNotifyCssVer = is_file($hdStatusNotifyCss) ? (int) filemtime($hdStatusNotifyCss) : time();
        ?>
        <link href="../assets/css/helpdesk-status-notify.css?v=<?= $hdStatusNotifyCssVer ?>" rel="stylesheet">
        <?php include 'include_admin_head.php'; ?>
        <?php if ($hdSection === 'qr'): ?>
            <link href="../assets/css/qr-with-logo.css" rel="stylesheet">
        <?php endif; ?>
        <?php include 'include_staff_call_head.php'; ?>
    <?php endif; ?>
    <?php if ($hdSection === 'dashboard'): ?>
        <?php include 'include_staff_call_head.php'; ?>
        <link href="../assets/css/helpdesk-dashboard-alerts.css?v=<?= $hdAlertsCssVer ?>" rel="stylesheet">
        <script type="module" src="https://unpkg.com/@lottiefiles/dotlottie-wc@0.4.2/dist/dotlottie-wc.js"></script>
    <?php endif; ?>
    <link href="../assets/css/helpdesk-dashboard.css?v=<?= $cssVer ?>" rel="stylesheet">
    <style>
        .hd-content-embed { max-width: 1200px; }
        .hd-content-embed .content-area,
        .hd-content-embed .col-md-10 { max-width: none; width: 100%; padding: 0; }
        .hd-nav-section {
            margin: 1.1rem 1rem 0.35rem;
            font-size: 0.68rem;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: rgba(148, 163, 184, 0.7);
        }
    </style>
</head>
<body class="hd-body">
    <div class="hd-app">
        <aside class="hd-sidebar" id="hdSidebar" aria-label="Navigasi Helpdesk">
            <div class="hd-sidebar-brand">
                <span class="hd-brand-mark" aria-hidden="true"><i class="bi bi-headset"></i></span>
                <div class="hd-brand-text">
                    <strong>Helpdesk IT</strong>
                    <span>Modul terpisah</span>
                </div>
                <button type="button" class="hd-sidebar-close d-lg-none" id="hdSidebarClose" aria-label="Tutup menu">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>

            <nav class="hd-nav">
                <?php if (!$hdIsOperatorOnly): ?>
                <a class="hd-nav-link <?= $hdSection === 'dashboard' ? 'is-active' : '' ?>" href="<?= $dashUrl ?>">
                    <i class="bi bi-grid-1x2"></i>
                    <span>Dashboard</span>
                </a>
                <?php endif; ?>

                <?php if ($hdIsOperatorOnly): ?>
                <a class="hd-nav-link <?= $hdSection === 'tasks' ? 'is-active' : '' ?>" href="<?= $tasksUrl ?>">
                    <i class="bi bi-speedometer2"></i>
                    <span>Tugas Saya</span>
                    <?php if ($hdTicketBadge > 0): ?>
                        <span class="badge bg-danger rounded-pill ms-auto"><?= htmlspecialchars(recepsionis_format_action_count($hdTicketBadge)) ?></span>
                    <?php endif; ?>
                </a>
                <?php endif; ?>

                <div class="hd-nav-group <?= $ticketGroupOpen ? 'is-open' : '' ?>" data-accordion data-hd-ticket-nav>
                    <button type="button" class="hd-nav-group-toggle" aria-expanded="<?= $ticketGroupOpen ? 'true' : 'false' ?>">
                        <span class="hd-nav-group-label">
                            <i class="bi bi-ticket-detailed"></i>
                            Tiket QR
                            <?php if ($hdTicketBadge > 0): ?>
                                <span class="badge bg-danger rounded-pill" data-hd-ticket-badge><?= htmlspecialchars(recepsionis_format_action_count($hdTicketBadge)) ?></span>
                            <?php endif; ?>
                        </span>
                        <i class="bi bi-chevron-down hd-chevron"></i>
                    </button>
                    <div class="hd-nav-sub">
                        <a class="<?= $hdSection === 'tickets' ? 'is-active' : '' ?>"
                           href="<?= $daftarLaporanUrl ?>">Daftar Laporan</a>
                    </div>
                </div>

                <?php if ($hdCanManage): ?>
                <div class="hd-nav-group <?= $assetGroupOpen ? 'is-open' : '' ?>" data-accordion>
                    <button type="button" class="hd-nav-group-toggle" aria-expanded="<?= $assetGroupOpen ? 'true' : 'false' ?>">
                        <span class="hd-nav-group-label">
                            <i class="bi bi-qr-code"></i>
                            QR &amp; Akses
                        </span>
                        <i class="bi bi-chevron-down hd-chevron"></i>
                    </button>
                    <div class="hd-nav-sub">
                        <a class="<?= $hdSection === 'qr' ? 'is-active' : '' ?>" href="<?= $qrUrl ?>">QR Tiket Kelas</a>
                    </div>
                </div>

                <a class="hd-nav-link <?= $hdSection === 'users' ? 'is-active' : '' ?>" href="<?= $usersUrl ?>">
                    <i class="bi bi-person-gear"></i>
                    <span>Kelola User</span>
                </a>
                <?php endif; ?>

                <div class="hd-nav-section">Akun</div>
                <a class="hd-nav-link <?= $hdSection === 'prefs' ? 'is-active' : '' ?>" href="<?= $prefsUrl ?>">
                    <i class="bi bi-bell"></i>
                    <span>Preferensi Notifikasi</span>
                </a>

                <?php if ($hdIsAdmin): ?>
                <div class="hd-nav-section">Sistem</div>
                <a class="hd-nav-link" href="<?= htmlspecialchars(adminUrl('index.php')) ?>">
                    <i class="bi bi-arrow-left-circle"></i>
                    <span>Ke E-Recepsionis</span>
                </a>
                <?php endif; ?>
            </nav>

            <div class="hd-sidebar-foot">
                <a href="<?= $logoutUrl ?>" class="hd-nav-link">
                    <i class="bi bi-box-arrow-right"></i>
                    <span>Logout</span>
                </a>
            </div>
        </aside>
        <div class="hd-sidebar-backdrop" id="hdSidebarBackdrop" hidden></div>

        <div class="hd-main">
            <header class="hd-topbar">
                <div class="hd-topbar-left">
                    <button type="button" class="hd-icon-btn d-lg-none" id="hdMenuToggle" aria-label="Buka menu">
                        <i class="bi bi-list"></i>
                    </button>
                    <nav class="hd-breadcrumb" aria-label="Breadcrumb">
                        <a href="<?= $dashUrl ?>">Helpdesk</a>
                        <span aria-hidden="true">/</span>
                        <span class="is-current"><?= htmlspecialchars($crumb) ?></span>
                    </nav>
                </div>

                <div class="hd-topbar-right">
                    <button type="button" class="hd-icon-btn" id="hdThemeToggle" aria-label="Mode gelap / terang" title="Toggle tema">
                        <i class="bi bi-moon-stars" id="hdThemeIcon"></i>
                    </button>
                    <div class="hd-profile">
                        <span class="hd-avatar" aria-hidden="true"><?= htmlspecialchars($userInitials) ?></span>
                        <div class="hd-profile-meta">
                            <strong><?= htmlspecialchars($userName) ?></strong>
                            <span><?= htmlspecialchars($userRole) ?></span>
                        </div>
                        <a class="hd-icon-btn" href="<?= $logoutUrl ?>" title="Logout" aria-label="Logout">
                            <i class="bi bi-box-arrow-right"></i>
                        </a>
                    </div>
                </div>
            </header>

            <main class="hd-content <?= $needsBootstrap ? 'hd-content-embed' : '' ?>">
                <?php if ($hdSection === 'dashboard'): ?>
                    <div class="hd-page-head">
                        <div>
                            <h1>Main Dashboard</h1>
                            <p>Ringkasan operasional Helpdesk &amp; IT Support<?= $usingLiveData ? ' · data live' : '' ?>.</p>
                        </div>
                        <a class="hd-btn-primary" href="<?= $daftarLaporanUrl ?>">
                            <i class="bi bi-list-ul"></i> Buka Daftar Laporan
                        </a>
                    </div>

                    <section class="hd-metrics" aria-label="Metrik utama" id="hdMetrics">
                        <a class="hd-metric-card accent-blue" href="<?= $ticketsPendingUrl ?>" title="Buka filter Pending di Daftar Laporan">
                            <div class="hd-metric-body">
                                <p class="hd-metric-label">Antrian Aktif</p>
                                <p class="hd-metric-value" data-hd-metric="open"><?= (int) ($metrics['open'] ?? ((int) $metrics['new'] + (int) $metrics['in_progress'])) ?></p>
                                <p class="hd-metric-hint"><?= (int) $metrics['new'] ?> pending · <?= (int) $metrics['in_progress'] ?> diproses</p>
                            </div>
                            <span class="hd-metric-icon" aria-hidden="true"><i class="bi bi-inbox"></i></span>
                        </a>
                        <a class="hd-metric-card accent-orange" href="<?= $ticketsPendingUrl ?>">
                            <div class="hd-metric-body">
                                <p class="hd-metric-label">Sedang Diproses</p>
                                <p class="hd-metric-value" data-hd-metric="in_progress"><?= (int) $metrics['in_progress'] ?></p>
                                <p class="hd-metric-hint">Sedang ditindaklanjuti</p>
                            </div>
                            <span class="hd-metric-icon" aria-hidden="true"><i class="bi bi-hourglass-split"></i></span>
                        </a>
                        <a class="hd-metric-card accent-green" href="<?= $ticketsDoneUrl ?>">
                            <div class="hd-metric-body">
                                <p class="hd-metric-label">Selesai Hari Ini</p>
                                <p class="hd-metric-value" data-hd-metric="resolved_today"><?= (int) $metrics['resolved_today'] ?></p>
                                <p class="hd-metric-hint">Terselesaikan hari ini</p>
                            </div>
                            <span class="hd-metric-icon" aria-hidden="true"><i class="bi bi-check2-circle"></i></span>
                        </a>
                        <a class="hd-metric-card accent-red" href="<?= htmlspecialchars(helpdeskUrl('tickets', ['status' => 'answered'])) ?>">
                            <div class="hd-metric-body">
                                <p class="hd-metric-label">Pelanggaran SLA / Eskalasi</p>
                                <p class="hd-metric-value" data-hd-metric="sla_breach"><?= (int) $metrics['sla_breach'] ?></p>
                                <p class="hd-metric-hint">Perlu perhatian segera</p>
                            </div>
                            <span class="hd-metric-icon" aria-hidden="true"><i class="bi bi-exclamation-triangle"></i></span>
                        </a>
                    </section>

                    <section class="hd-panel hd-alert-panel" aria-label="Laporan masuk">
                        <div class="hd-panel-head">
                            <div>
                                <h2>Laporan Masuk</h2>
                                <p>Tiket baru dari form QR — data yang disubmit pelapor</p>
                            </div>
                            <a class="hd-link" href="<?= $daftarLaporanUrl ?>">Kelola di Daftar Laporan <i class="bi bi-arrow-right"></i></a>
                        </div>
                        <div class="hd-alert-list" id="hdAlertList">
                            <div class="hd-alert-empty">Memuat laporan masuk...</div>
                        </div>
                    </section>

                    <section class="hd-panel">
                        <div class="hd-panel-head">
                            <div>
                                <h2>Tiket Terbaru</h2>
                                <p>Antrian aktif (Pending) — kolom dan data sama dengan Daftar Laporan</p>
                            </div>
                            <a class="hd-link" href="<?= $daftarLaporanUrl ?>">Daftar Laporan <i class="bi bi-arrow-right"></i></a>
                        </div>
                        <div class="hd-table-wrap">
                            <table class="hd-table" id="hdTicketsTable">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Waktu</th>
                                        <th>Tipe</th>
                                        <th>Lokasi</th>
                                        <th>Kategori</th>
                                        <th>Ditindak Oleh</th>
                                        <th>Catatan</th>
                                        <th>Status</th>
                                        <th>Waktu Respon</th>
                                    </tr>
                                </thead>
                                <tbody id="hdTicketsTableBody">
                                    <?php if ($recentTickets === []): ?>
                                        <tr><td colspan="9" style="text-align:center;color:var(--hd-muted);padding:2rem;">Belum ada tiket.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($recentTickets as $ticket): ?>
                                            <tr class="hd-ticket-row" data-ticket-id="<?= (int) ($ticket['raw_id'] ?? 0) ?>" tabindex="0" role="link" data-href="<?= $daftarLaporanUrl ?>">
                                                <td data-label="ID"><span class="hd-ticket-id"><?= htmlspecialchars($ticket['id']) ?></span></td>
                                                <td data-label="Waktu"><?= htmlspecialchars($ticket['time']) ?></td>
                                                <td data-label="Tipe"><span class="hd-chip"><?= htmlspecialchars($ticket['type']) ?></span></td>
                                                <td data-label="Lokasi"><?= htmlspecialchars($ticket['location']) ?></td>
                                                <td data-label="Kategori"><span class="hd-chip"><?= htmlspecialchars($ticket['category']) ?></span></td>
                                                <td data-label="Ditindak Oleh"><?= htmlspecialchars($ticket['handler']) ?></td>
                                                <td data-label="Catatan" class="hd-notes-cell"><?= nl2br(htmlspecialchars($ticket['notes'])) ?></td>
                                                <td data-label="Status">
                                                    <span class="hd-status <?= htmlspecialchars($ticket['status_class']) ?>">
                                                        <?= htmlspecialchars($ticket['status']) ?>
                                                    </span>
                                                </td>
                                                <td data-label="Waktu Respon">
                                                    <?php if (($ticket['response_label'] ?? '—') === '—'): ?>
                                                        <span class="text-muted">—</span>
                                                    <?php else: ?>
                                                        <span class="fw-semibold hd-response-tone hd-response-tone--<?= htmlspecialchars($ticket['response_tone']) ?>"><?= htmlspecialchars($ticket['response_label']) ?></span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>

                <?php elseif ($hdSection === 'tickets'): ?>
                    <?php require __DIR__ . '/staff_calls.php'; ?>

                <?php elseif ($hdSection === 'qr'): ?>
                    <?php require __DIR__ . '/helpdesk_it.php'; ?>

                <?php elseif ($hdSection === 'users'): ?>
                    <?php require __DIR__ . '/users.php'; ?>

                <?php elseif ($hdSection === 'tasks'): ?>
                    <?php require __DIR__ . '/operator_dashboard.php'; ?>

                <?php elseif ($hdSection === 'prefs'): ?>
                    <?php
                    $userId = (int) ($_SESSION['user_id'] ?? 0);
                    $prefs = recepsionis_get_notification_preferences($koneksi, $userId);
                    $categoryIds = recepsionis_get_admin_category_ids($koneksi, $userId);
                    $displayName = trim((string) ($_SESSION['nama_lengkap'] ?? $_SESSION['username'] ?? 'Operator'));
                    $apiUrl = function_exists('apiUrl') ? apiUrl('admin_notification_preferences.php') : '../api/admin_notification_preferences.php';
                    ?>
                    <div class="hd-page-head mb-3">
                        <div>
                            <h1>Preferensi Notifikasi</h1>
                            <p>Pengaturan popup &amp; dering untuk akun Helpdesk Anda.</p>
                        </div>
                    </div>
                    <?php include 'include_notification_preferences_section.php'; ?>
                    <script>
                    (function () {
                        const apiUrl = <?= json_encode($apiUrl, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
                        const alertEl = document.getElementById('prefAlert');
                        const notifEl = document.getElementById('prefNotificationsEnabled');
                        const soundEl = document.getElementById('prefSoundEnabled');
                        const saveBtn = document.getElementById('prefSaveBtn');
                        const testBtn = document.getElementById('prefTestSoundBtn');
                        if (!notifEl || !soundEl || !saveBtn) return;
                        function showAlert(type, msg) {
                            if (!alertEl) return;
                            alertEl.className = 'alert alert-' + type;
                            alertEl.textContent = msg;
                            alertEl.classList.remove('d-none');
                        }
                        function syncToRuntime() {
                            if (window.recepsionisStaffCallNotify) {
                                window.recepsionisStaffCallNotify.applyPreferences(notifEl.checked, soundEl.checked, false);
                            }
                        }
                        saveBtn.addEventListener('click', async function () {
                            saveBtn.disabled = true;
                            try {
                                const res = await fetch(apiUrl, {
                                    method: 'POST',
                                    credentials: 'same-origin',
                                    headers: { 'Content-Type': 'application/json' },
                                    body: JSON.stringify({
                                        notifications_enabled: notifEl.checked,
                                        sound_enabled: soundEl.checked,
                                    }),
                                });
                                const data = await res.json();
                                if (!data.success) throw new Error(data.message || 'Gagal menyimpan');
                                syncToRuntime();
                                showAlert('success', 'Preferensi notifikasi disimpan.');
                            } catch (e) {
                                showAlert('danger', e.message || 'Gagal menyimpan preferensi.');
                            } finally {
                                saveBtn.disabled = false;
                            }
                        });
                        if (testBtn) {
                            testBtn.addEventListener('click', function () {
                                if (window.recepsionisStaffCallNotify) {
                                    window.recepsionisStaffCallNotify.unlockAudio();
                                    window.recepsionisStaffCallNotify.testSound();
                                    showAlert('info', 'Jika tidak terdengar, pastikan suara aktif dan volume perangkat tidak mute.');
                                }
                            });
                        }
                        notifEl.addEventListener('change', function () {
                            soundEl.disabled = !notifEl.checked;
                        });
                        soundEl.disabled = !notifEl.checked;
                    })();
                    </script>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <?php if ($needsBootstrap): ?>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
        <script src="../assets/js/toast.js"></script>
        <?php
        $hdStatusNotifyJs = dirname(__DIR__) . '/assets/js/helpdesk-status-notify.js';
        $hdStatusNotifyJsVer = is_file($hdStatusNotifyJs) ? (int) filemtime($hdStatusNotifyJs) : time();
        ?>
        <script src="../assets/js/helpdesk-status-notify.js?v=<?= $hdStatusNotifyJsVer ?>"></script>
        <script src="../assets/js/notification-badge.js"></script>
        <?php include 'include_staff_call_footer.php'; ?>
    <?php endif; ?>
    <?php if ($hdSection === 'dashboard'): ?>
        <?php include 'include_staff_call_footer.php'; ?>
        <script src="../assets/js/helpdesk-dashboard-alerts.js?v=<?= $hdAlertsJsVer ?>"></script>
        <script>
        (function () {
            if (!window.recepsionisHelpdeskDashboardAlerts) return;
            window.recepsionisHelpdeskDashboardAlerts.init({
                alertLottieUrl: <?= json_encode($alertLottieUrl, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
                ticketsUrl: <?= json_encode(htmlspecialchars_decode($daftarLaporanUrl), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
                statusApiUrl: <?= json_encode(function_exists('apiUrl') ? apiUrl('helpdesk_it_update_status.php') : (rtrim(BASE_URL, '/') . '/api/helpdesk_it_update_status.php'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
            });
        })();
        </script>
    <?php endif; ?>

    <script>
    (function () {
        var root = document.documentElement;
        var stored = localStorage.getItem('hd-theme');
        if (stored === 'dark' || stored === 'light') {
            root.setAttribute('data-theme', stored);
        }
        syncThemeIcon();

        document.getElementById('hdThemeToggle')?.addEventListener('click', function () {
            var next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
            root.setAttribute('data-theme', next);
            localStorage.setItem('hd-theme', next);
            syncThemeIcon();
        });

        function syncThemeIcon() {
            var icon = document.getElementById('hdThemeIcon');
            if (!icon) return;
            icon.className = root.getAttribute('data-theme') === 'dark' ? 'bi bi-sun' : 'bi bi-moon-stars';
        }

        document.querySelectorAll('[data-accordion]').forEach(function (group) {
            var btn = group.querySelector('.hd-nav-group-toggle');
            if (!btn) return;
            btn.addEventListener('click', function () {
                var open = group.classList.toggle('is-open');
                btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
        });

        var sidebar = document.getElementById('hdSidebar');
        var backdrop = document.getElementById('hdSidebarBackdrop');
        function openSidebar() {
            sidebar?.classList.add('is-open');
            if (backdrop) backdrop.hidden = false;
        }
        function closeSidebar() {
            sidebar?.classList.remove('is-open');
            if (backdrop) backdrop.hidden = true;
        }
        document.getElementById('hdMenuToggle')?.addEventListener('click', openSidebar);
        document.getElementById('hdSidebarClose')?.addEventListener('click', closeSidebar);
        backdrop?.addEventListener('click', closeSidebar);

        <?php if ($hdSection === 'dashboard'): ?>
        (function pollDashboardOverview() {
            var apiUrl = <?= json_encode($overviewApiUrl, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            var daftarUrl = <?= json_encode(htmlspecialchars_decode($daftarLaporanUrl), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            var lastFingerprint = '';

            function esc(s) {
                return String(s == null ? '' : s)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;');
            }

            function formatBadge(n) {
                n = parseInt(n, 10) || 0;
                return n > 99 ? '99+' : String(n);
            }

            function updateBadge(count) {
                var label = document.querySelector('[data-hd-ticket-nav] .hd-nav-group-label');
                if (!label) return;
                var badge = label.querySelector('[data-hd-ticket-badge]');
                if (count <= 0) {
                    if (badge) badge.remove();
                    return;
                }
                if (!badge) {
                    badge = document.createElement('span');
                    badge.className = 'badge bg-danger rounded-pill';
                    badge.setAttribute('data-hd-ticket-badge', '');
                    label.appendChild(badge);
                }
                badge.textContent = formatBadge(count);
            }

            function bindTicketRows(root) {
                (root || document).querySelectorAll('.hd-ticket-row[data-href]').forEach(function (row) {
                    if (row.dataset.bound === '1') return;
                    row.dataset.bound = '1';
                    row.addEventListener('click', function () {
                        window.location.href = row.getAttribute('data-href');
                    });
                    row.addEventListener('keydown', function (e) {
                        if (e.key === 'Enter' || e.key === ' ') {
                            e.preventDefault();
                            window.location.href = row.getAttribute('data-href');
                        }
                    });
                });
            }

            function renderTickets(tickets) {
                var tbody = document.getElementById('hdTicketsTableBody');
                if (!tbody) return;
                if (!tickets || !tickets.length) {
                    tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;color:var(--hd-muted);padding:2rem;">Belum ada tiket.</td></tr>';
                    return;
                }
                tbody.innerHTML = tickets.map(function (t) {
                    var responseHtml = t.response_label && t.response_label !== '—'
                        ? '<span class="fw-semibold hd-response-tone hd-response-tone--' + esc(t.response_tone || 'muted') + '">' + esc(t.response_label) + '</span>'
                        : '<span class="text-muted">—</span>';
                    return '<tr class="hd-ticket-row" data-ticket-id="' + esc(t.raw_id) + '" tabindex="0" role="link" data-href="' + esc(daftarUrl) + '">'
                        + '<td data-label="ID"><span class="hd-ticket-id">' + esc(t.id) + '</span></td>'
                        + '<td data-label="Waktu">' + esc(t.time) + '</td>'
                        + '<td data-label="Tipe"><span class="hd-chip">' + esc(t.type) + '</span></td>'
                        + '<td data-label="Lokasi">' + esc(t.location) + '</td>'
                        + '<td data-label="Kategori"><span class="hd-chip">' + esc(t.category) + '</span></td>'
                        + '<td data-label="Ditindak Oleh">' + esc(t.handler) + '</td>'
                        + '<td data-label="Catatan" class="hd-notes-cell">' + esc(t.notes).replace(/\n/g, '<br>') + '</td>'
                        + '<td data-label="Status"><span class="hd-status ' + esc(t.status_class) + '">' + esc(t.status) + '</span></td>'
                        + '<td data-label="Waktu Respon">' + responseHtml + '</td>'
                        + '</tr>';
                }).join('');
                bindTicketRows(tbody);
            }

            function apply(data) {
                var m = data.metrics || {};
                if (typeof m.open === 'undefined' && (typeof m.new !== 'undefined' || typeof m.in_progress !== 'undefined')) {
                    m.open = (parseInt(m.new, 10) || 0) + (parseInt(m.in_progress, 10) || 0);
                }
                ['open', 'new', 'in_progress', 'resolved_today', 'sla_breach'].forEach(function (key) {
                    var el = document.querySelector('[data-hd-metric="' + key + '"]');
                    if (el && typeof m[key] !== 'undefined') {
                        el.textContent = String(m[key]);
                    }
                });
                var openCard = document.querySelector('[data-hd-metric="open"]');
                if (openCard) {
                    var hint = openCard.parentElement && openCard.parentElement.querySelector('.hd-metric-hint');
                    if (hint && typeof m.new !== 'undefined' && typeof m.in_progress !== 'undefined') {
                        hint.textContent = m.new + ' pending · ' + m.in_progress + ' diproses';
                    }
                }
                renderTickets(data.recent_tickets || []);
                // Badge = antrian aktif (open), fallback ticket_badge API
                var badgeCount = typeof m.open !== 'undefined' ? m.open : (data.ticket_badge || 0);
                updateBadge(badgeCount);
            }

            function fingerprint(data) {
                var ids = (data.recent_tickets || []).map(function (t) {
                    return t.raw_id + ':' + t.status_raw + ':' + t.handler + ':' + t.response_label;
                });
                return JSON.stringify([data.metrics, data.ticket_badge, ids]);
            }

            function tick() {
                fetch(apiUrl, { credentials: 'same-origin', cache: 'no-store' })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (!data || !data.success) return;
                        var fp = fingerprint(data);
                        if (fp !== lastFingerprint) {
                            lastFingerprint = fp;
                            apply(data);
                        }
                    })
                    .catch(function () { /* diam jika offline */ });
            }

            bindTicketRows(document);
            tick();
            setInterval(tick, 3000);
            document.addEventListener('visibilitychange', function () {
                if (!document.hidden) tick();
            });
            window.addEventListener('focus', tick);
        })();
        <?php endif; ?>
    })();
    </script>
</body>
</html>

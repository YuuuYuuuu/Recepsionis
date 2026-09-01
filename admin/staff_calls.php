<?php
require_once 'auth.php';
require_once '../staff_call_routing.php';
require_once 'helpdesk_hub.php';

$isHelpdeskTicketsMode = defined('HELPDESK_HUB');

if ($isHelpdeskTicketsMode) {
    requireHelpdeskAccess();
} else {
    // Daftar Panggilan = modul E-Recepsionis (bukan Helpdesk)
    requireRecepsionisPage();
}

function staffCallEventLabel(string $eventType): string
{
    $labels = [
        'created' => 'Dibuat',
        'notified' => 'Dirutekan',
        'accepted' => 'Diterima',
        'answered' => 'Terjawab',
        'cancelled' => 'Dibatalkan',
        'assigned' => 'Ditugaskan',
        'reassigned' => 'Dipindahkan',
        'rejected' => 'Ditolak',
        'ended' => 'Sesi diakhiri',
        'wa_confirmed' => 'Dikonfirmasi via WA',
        'wa_queued' => 'Antrian via WA',
    ];
    return $labels[$eventType] ?? ucfirst(str_replace('_', ' ', $eventType));
}

$currentUserId = (int) ($_SESSION['user_id'] ?? 0);
$currentUserRole = (string) ($_SESSION['role'] ?? '');
$isAdminUser = currentUserIsAdmin() || currentUserIsHelpdeskAdmin();
$isHelpdeskTicketAdmin = $isAdminUser;
$assignableUsers = recepsionis_get_active_backoffice_users($koneksi);

// Handle actions
if (isset($_GET['answer'])) {
    $id = intval($_GET['answer']);

    $stmt = $koneksi->prepare("SELECT id, category_id, assigned_user_id, status FROM staff_calls WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $call_res = $stmt->get_result();
    $call = $call_res ? $call_res->fetch_assoc() : null;
    $stmt->close();

    if (
        $call
        && $call['status'] === 'pending'
        && recepsionis_user_can_receive_staff_call(
            $koneksi,
            $currentUserId,
            (int) ($call['category_id'] ?? 0),
            (int) ($call['assigned_user_id'] ?? 0),
            $currentUserRole
        )
    ) {
        $stmt = $koneksi->prepare("UPDATE staff_calls SET status = 'answered', answered_by = ?, answered_at = NOW() WHERE id = ? AND status = 'pending'");
        $stmt->bind_param("ii", $currentUserId, $id);
        $stmt->execute();
        $updated = $stmt->affected_rows > 0;
        $stmt->close();

        if ($updated) {
            recepsionis_log_staff_call_event(
                $koneksi,
                $id,
                'answered',
                $currentUserId,
                null,
                (int) ($call['category_id'] ?? 0),
                'Panggilan ditandai terjawab dari halaman rekap.',
                ['source' => 'staff_calls_page']
            );
            recepsionis_update_visitor_pic_from_staff_call($koneksi, $id, $currentUserId);
            header('Location: ' . ($isHelpdeskTicketsMode
                ? helpdeskUrl('tickets', ['success' => 'answered'])
                : adminUrl('staff_calls.php?success=answered')));
            exit;
        }
    }

    header('Location: ' . ($isHelpdeskTicketsMode
        ? helpdeskUrl('tickets', ['error' => 'unauthorized'])
        : adminUrl('staff_calls.php?error=unauthorized')));
    exit;
}

if (isset($_GET['cancel'])) {
    $id = intval($_GET['cancel']);

    $stmt = $koneksi->prepare("SELECT id, category_id, assigned_user_id, status FROM staff_calls WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $call_res = $stmt->get_result();
    $call = $call_res ? $call_res->fetch_assoc() : null;
    $stmt->close();

    if (
        $call
        && $call['status'] === 'pending'
        && recepsionis_user_can_receive_staff_call(
            $koneksi,
            $currentUserId,
            (int) ($call['category_id'] ?? 0),
            (int) ($call['assigned_user_id'] ?? 0),
            $currentUserRole
        )
    ) {
        $stmt = $koneksi->prepare("UPDATE staff_calls SET status = 'cancelled' WHERE id = ? AND status = 'pending'");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $updated = $stmt->affected_rows > 0;
        $stmt->close();

        if ($updated) {
            recepsionis_log_staff_call_event(
                $koneksi,
                $id,
                'cancelled',
                $currentUserId,
                null,
                (int) ($call['category_id'] ?? 0),
                'Panggilan dibatalkan dari halaman rekap.',
                ['source' => 'staff_calls_page']
            );
            header('Location: ' . ($isHelpdeskTicketsMode
                ? helpdeskUrl('tickets', ['success' => 'cancelled'])
                : adminUrl('staff_calls.php?success=cancelled')));
            exit;
        }
    }

    header('Location: ' . ($isHelpdeskTicketsMode
        ? helpdeskUrl('tickets', ['error' => 'unauthorized'])
        : adminUrl('staff_calls.php?error=unauthorized')));
    exit;
}

if ($isAdminUser && isset($_POST['save_assignment'])) {
    $callId = (int) ($_POST['call_id'] ?? 0);
    $assignedUserId = (int) ($_POST['assigned_user_id'] ?? 0);

    $stmt = $koneksi->prepare("SELECT id, category_id, assigned_user_id, status FROM staff_calls WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $callId);
    $stmt->execute();
    $call_res = $stmt->get_result();
    $call = $call_res ? $call_res->fetch_assoc() : null;
    $stmt->close();

    if (!$call || $call['status'] !== 'pending' || $assignedUserId <= 0) {
        header('Location: ' . ($isHelpdeskTicketsMode
            ? helpdeskUrl('tickets', ['error' => 'assignment_invalid'])
            : adminUrl('staff_calls.php?error=assignment_invalid')));
        exit;
    }

    $ok = recepsionis_assign_staff_call(
        $koneksi,
        $callId,
        $assignedUserId,
        $currentUserId,
        (int) ($call['category_id'] ?? 0),
        'PIC pengaduan diperbarui dari halaman rekap.',
        ['source' => 'staff_calls_page']
    );

    if (!$ok) {
        header('Location: ' . ($isHelpdeskTicketsMode
            ? helpdeskUrl('tickets', ['error' => 'assignment_invalid'])
            : adminUrl('staff_calls.php?error=assignment_invalid')));
        exit;
    }

    $successCode = (int) ($call['assigned_user_id'] ?? 0) > 0 ? 'reassigned' : 'assigned';
    header('Location: ' . ($isHelpdeskTicketsMode
        ? helpdeskUrl('tickets', ['success' => $successCode])
        : adminUrl('staff_calls.php?success=' . $successCode)));
    exit;
}

// Get staff calls
$status_filter = $_GET['status'] ?? 'pending';
$view_filter = $isAdminUser ? ($_GET['view'] ?? 'all') : 'mine';
if (!in_array($view_filter, ['all', 'mine'], true)) {
    $view_filter = $isAdminUser ? 'all' : 'mine';
}

$helpdeskCategoryId = recepsionis_get_helpdesk_category_id($koneksi);
$canManageHelpdeskTickets = $isHelpdeskTicketAdmin
    || recepsionis_user_is_helpdesk_category_member($koneksi, $currentUserId);

// Pisah ranah: Helpdesk = tiket QR saja; E-Recepsionis = daftar panggilan saja
if ($isHelpdeskTicketsMode) {
    $channel_filter = 'tickets';
    $showCallsTable = false;
    $showTicketsTable = true;
} else {
    $channel_filter = 'calls';
    $showCallsTable = true;
    $showTicketsTable = false;
}
$helpdeskStatusApiUrl = function_exists('apiUrl') ? apiUrl('helpdesk_it_update_status.php') : '../api/helpdesk_it_update_status.php';
$actionCounts = recepsionis_get_helpdesk_action_counts(
    $koneksi,
    $currentUserId,
    $isAdminUser,
    $view_filter,
    $currentUserRole
);

$call_rows = [];
$call_ids = [];
$totalRows = 0;
$allowedPerPage = [15, 25, 50, 100];
$perPage = (int) ($_GET['per_page'] ?? 15);
if (!in_array($perPage, $allowedPerPage, true)) {
    $perPage = 15;
}
$totalPages = 1;
$page = 1;
$offset = 0;
$call_rows_page = [];
$call_ids_page = [];
$logs_index = [];
$rangeStart = 0;
$rangeEnd = 0;

if ($showCallsTable) {
$query = "SELECT sc.*, h.nama as host_nama, u.nama_lengkap as answered_by_name,
                 u.username as answered_by_username,
                 au.nama_lengkap as assigned_user_name, au.username as assigned_username,
                 ab.nama_lengkap as assigned_by_name, ab.username as assigned_by_username,
                 cc.nama_kategori as category_name
          FROM staff_calls sc 
          LEFT JOIN hosts h ON sc.host_id = h.id 
          LEFT JOIN users u ON sc.answered_by = u.id
          LEFT JOIN users au ON au.id = sc.assigned_user_id
          LEFT JOIN users ab ON ab.id = sc.assigned_by
          LEFT JOIN complaint_categories cc ON sc.category_id = cc.id";

if ($status_filter != 'all') {
    $status_filter_esc = esc($status_filter);
    $query .= " WHERE sc.status = '$status_filter_esc'";
}

$query .= " ORDER BY sc.created_at DESC";
$calls = $koneksi->query($query);

if ($calls) {
    while ($row = $calls->fetch_assoc()) {
        $canSee = $isAdminUser && $view_filter === 'all';
        if (!$canSee) {
            $canSee = recepsionis_user_can_receive_staff_call(
                $koneksi,
                $currentUserId,
                (int) ($row['category_id'] ?? 0),
                (int) ($row['assigned_user_id'] ?? 0),
                $isAdminUser && $view_filter === 'mine' ? 'operator' : $currentUserRole
            ) || (int) ($row['answered_by'] ?? 0) === $currentUserId;
        }
        if (!$canSee) {
            continue;
        }
        $call_rows[] = $row;
        $call_ids[] = (int) $row['id'];
    }
}
$totalRows = count($call_rows);
$totalPages = max(1, (int) ceil($totalRows / $perPage));
$page = max(1, (int) ($_GET['page'] ?? 1));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;
$call_rows_page = array_slice($call_rows, $offset, $perPage);
$call_ids_page = array_map(static function ($row) {
    return (int) $row['id'];
}, $call_rows_page);
$logs_index = recepsionis_get_staff_call_logs_index($koneksi, $call_ids_page);

$rangeStart = $totalRows > 0 ? $offset + 1 : 0;
$rangeEnd = $totalRows > 0 ? min($offset + $perPage, $totalRows) : 0;
}

$ticket_rows = [];
if ($showTicketsTable && recepsionis_table_exists($koneksi, 'helpdesk_it_tickets')) {
    recepsionis_expire_stale_helpdesk_tickets($koneksi);
    $hasAssignJoin = recepsionis_column_exists($koneksi, 'helpdesk_it_tickets', 'assigned_user_id');
    $assignJoin = $hasAssignJoin
        ? ' LEFT JOIN users u ON u.id = t.assigned_user_id'
        : '';
    $assignSelect = $hasAssignJoin
        ? ', u.nama_lengkap AS assigned_user_name, u.username AS assigned_username'
        : ", NULL AS assigned_user_name, NULL AS assigned_username";
    $ticketQuery = "SELECT t.*{$assignSelect} FROM helpdesk_it_tickets t{$assignJoin} WHERE 1=1";
    if ($status_filter === 'pending') {
        $ticketQuery .= " AND status IN ('pending', 'in_progress')";
    } elseif ($status_filter === 'answered') {
        $ticketQuery .= " AND status IN ('resolved', 'expired')";
    } elseif ($status_filter === 'cancelled') {
        $ticketQuery .= " AND status = 'expired'";
    }
    $ticketQuery .= ' ORDER BY created_at DESC LIMIT 200';
    $ticketResult = $koneksi->query($ticketQuery);
    if ($ticketResult) {
        while ($row = $ticketResult->fetch_assoc()) {
            if ($isHelpdeskTicketAdmin) {
                $ticket_rows[] = $row;
                continue;
            }
            $assignedUserId = isset($row['assigned_user_id']) ? (int) $row['assigned_user_id'] : null;
            if ($assignedUserId !== null && $assignedUserId <= 0) {
                $assignedUserId = null;
            }
            $canSee = recepsionis_user_can_manage_helpdesk_it_ticket(
                $koneksi,
                $currentUserId,
                $assignedUserId,
                recepsionis_resolve_helpdesk_it_ticket_category_id($koneksi, $row),
                $currentUserRole
            );
            if (!$canSee) {
                continue;
            }
            if ($status_filter === 'all' && ($row['status'] ?? '') === 'resolved' && (int) ($row['assigned_user_id'] ?? 0) !== $currentUserId && !$isHelpdeskTicketAdmin) {
                continue;
            }
            $ticket_rows[] = $row;
        }
    }
}

function staff_calls_page_url(int $page, string $statusFilter, string $viewFilter, int $perPage, bool $isAdminUser, string $channelFilter = 'all'): string
{
    $params = [
        'status' => $statusFilter,
        'page' => $page,
        'per_page' => $perPage,
        'channel' => $channelFilter,
    ];
    if ($isAdminUser) {
        $params['view'] = $viewFilter;
    }
    if (defined('HELPDESK_HUB')) {
        return helpdeskUrl('tickets', $params);
    }
    $base = function_exists('adminUrl') ? adminUrl('staff_calls.php') : 'staff_calls.php';
    return $base . '?' . http_build_query($params);
}

$pageTitle = $isHelpdeskTicketsMode ? 'Daftar Laporan — Helpdesk IT' : 'Daftar Panggilan - E-Recepsionis';
$embedTicketsInPanel = $isHelpdeskTicketsMode && $showTicketsTable;
?>
<?php if ($isHelpdeskTicketsMode): ?>
<script>window.originalPageTitle = <?= json_encode($pageTitle, JSON_UNESCAPED_UNICODE) ?>;</script>
<div class="hd-section-tickets">
                <?php if (!$canManageHelpdeskTickets): ?>
                <div class="alert alert-warning mb-3">
                    <i class="bi bi-exclamation-triangle"></i>
                    Akun Anda belum ditugaskan ke kategori Helpdesk. Hubungi Admin Helpdesk agar dapat mengelola tiket masuk.
                </div>
                <?php endif; ?>
<?php else: ?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <script>window.originalPageTitle = <?= json_encode($pageTitle, JSON_UNESCAPED_UNICODE) ?>;</script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <link href="../assets/css/toast.css" rel="stylesheet">
    <?php include 'include_admin_head.php'; ?>
    <?php include 'include_staff_call_head.php'; ?>
</head>
<body>
    <?php include 'navbar.php'; ?>
    <div class="container-fluid">
        <div class="row">
            <?php include 'sidebar.php'; ?>
            <div class="col-md-10 content-area">
                <h2 class="mb-1"><i class="bi bi-telephone"></i> Daftar Panggilan</h2>
                <p class="text-muted small mb-4">
                    Panggilan staff dari tamu / visitor. Tiket QR kelas dikelola di modul Helpdesk IT.
                </p>
<?php endif; ?>

                <?php if (isset($_GET['notice']) && $_GET['notice'] === 'live_chat_retired'): ?>
                    <div class="alert alert-info alert-dismissible fade show">
                        <i class="bi bi-info-circle"></i>
                        Fitur Live Chat sudah dinonaktifkan. Gunakan daftar panggilan di halaman ini.
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (isset($_GET['success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="bi bi-check-circle"></i> 
                        <?php
                        if ($_GET['success'] == 'answered') echo 'Panggilan ditandai sebagai terjawab';
                        elseif ($_GET['success'] == 'cancelled') echo 'Panggilan dibatalkan';
                        elseif ($_GET['success'] == 'assigned') echo 'PIC pengaduan berhasil ditetapkan';
                        elseif ($_GET['success'] == 'reassigned') echo 'PIC pengaduan berhasil dipindahkan';
                        ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                <?php if (isset($_GET['error']) && $_GET['error'] === 'unauthorized'): ?>
                    <div class="alert alert-warning alert-dismissible fade show">
                        <i class="bi bi-exclamation-triangle"></i>
                        Anda tidak ditugaskan untuk menangani kategori panggilan tersebut.
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                <?php if (isset($_GET['error']) && $_GET['error'] === 'assignment_invalid'): ?>
                    <div class="alert alert-warning alert-dismissible fade show">
                        <i class="bi bi-exclamation-triangle"></i>
                        Assignment PIC tidak dapat diproses untuk panggilan ini.
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Filter -->
                <?php
                $ticketFilterBase = static function (string $status) use ($isHelpdeskTicketsMode, $isAdminUser, $showCallsTable, $view_filter, $channel_filter, $perPage): string {
                    if ($isHelpdeskTicketsMode && function_exists('helpdeskUrl')) {
                        return helpdeskUrl('tickets', [
                            'status' => $status,
                            'per_page' => $perPage,
                        ]);
                    }
                    $q = [
                        'status' => $status,
                        'channel' => $channel_filter,
                        'per_page' => $perPage,
                    ];
                    if ($isAdminUser && $showCallsTable) {
                        $q['view'] = $view_filter;
                    }
                    return '?' . http_build_query($q);
                };
                $renderStatusFilter = static function () use ($ticketFilterBase, $status_filter, $isHelpdeskTicketsMode, $actionCounts, $isAdminUser, $showCallsTable, $view_filter, $channel_filter, $perPage, $allowedPerPage): void {
                    ?>
                    <div class="adm-filter-toolbar adm-filter-toolbar--helpdesk">
                        <div class="adm-filter-row">
                            <div class="adm-filter-group adm-filter-group--grow">
                                <span class="adm-filter-label"><i class="bi bi-funnel"></i> Status</span>
                                <div class="adm-segment adm-segment-scroll" role="group" aria-label="Filter status">
                                    <a href="<?= htmlspecialchars($ticketFilterBase('pending')) ?>" class="adm-segment-item <?= $status_filter == 'pending' ? 'is-active' : '' ?>">
                                        <i class="bi bi-hourglass-split"></i> Pending
                                        <?php
                                        $pendingBadge = $isHelpdeskTicketsMode ? (int) $actionCounts['tickets'] : (int) $actionCounts['calls'];
                                        if ($pendingBadge > 0):
                                        ?>
                                            <span class="adm-segment-badge"><?= htmlspecialchars(recepsionis_format_action_count($pendingBadge)) ?></span>
                                        <?php endif; ?>
                                    </a>
                                    <a href="<?= htmlspecialchars($ticketFilterBase('answered')) ?>" class="adm-segment-item <?= $status_filter == 'answered' ? 'is-active' : '' ?>">
                                        <i class="bi bi-check-circle"></i> <?= $isHelpdeskTicketsMode ? 'Selesai' : 'Terjawab' ?>
                                    </a>
                                    <?php if (!$isHelpdeskTicketsMode): ?>
                                    <a href="<?= htmlspecialchars($ticketFilterBase('cancelled')) ?>" class="adm-segment-item <?= $status_filter == 'cancelled' ? 'is-active' : '' ?>">
                                        <i class="bi bi-x-circle"></i> Dibatalkan
                                    </a>
                                    <?php endif; ?>
                                    <a href="<?= htmlspecialchars($ticketFilterBase('all')) ?>" class="adm-segment-item <?= $status_filter == 'all' ? 'is-active' : '' ?>">
                                        <i class="bi bi-list-ul"></i> Semua
                                    </a>
                                </div>
                            </div>
                        </div>
                        <?php if ($isAdminUser && $showCallsTable): ?>
                        <div class="adm-filter-row adm-filter-row--meta">
                            <div class="adm-filter-group">
                                <span class="adm-filter-label"><i class="bi bi-eye"></i> Tampilan</span>
                                <div class="adm-segment adm-segment--muted" role="group" aria-label="Filter tampilan panggilan">
                                    <a href="?status=<?= urlencode($status_filter) ?>&view=all&channel=<?= urlencode($channel_filter) ?>&per_page=<?= $perPage ?>" class="adm-segment-item <?= $view_filter === 'all' ? 'is-active' : '' ?>">
                                        Semua Panggilan
                                    </a>
                                    <a href="?status=<?= urlencode($status_filter) ?>&view=mine&channel=<?= urlencode($channel_filter) ?>&per_page=<?= $perPage ?>" class="adm-segment-item <?= $view_filter === 'mine' ? 'is-active' : '' ?>">
                                        Panggilan Saya
                                    </a>
                                </div>
                            </div>
                            <form method="get" class="adm-filter-pagination">
                                <input type="hidden" name="status" value="<?= htmlspecialchars($status_filter) ?>">
                                <input type="hidden" name="channel" value="<?= htmlspecialchars($channel_filter) ?>">
                                <input type="hidden" name="view" value="<?= htmlspecialchars($view_filter) ?>">
                                <label class="adm-filter-label mb-0">Per halaman</label>
                                <select name="per_page" class="form-select form-select-sm adm-filter-select" onchange="this.form.page.value=1; this.form.submit()">
                                    <?php foreach ($allowedPerPage as $size): ?>
                                        <option value="<?= $size ?>" <?= $perPage === $size ? 'selected' : '' ?>><?= $size ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="hidden" name="page" value="1">
                            </form>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php
                };
                ?>
                <?php if (!$embedTicketsInPanel): ?>
                <div class="card mb-3 adm-filter-panel">
                    <div class="card-body">
                        <?php $renderStatusFilter(); ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($showCallsTable): ?>
                <!-- Calls Table -->
                <div class="card<?= $showTicketsTable ? ' mb-4' : '' ?>">
                    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <span><i class="bi bi-list"></i> Daftar Panggilan</span>
                        <span class="small text-muted">
                            Total <strong><?= (int) $totalRows ?></strong> data
                            <?php if ($totalRows > 0): ?>
                                — menampilkan <?= (int) $rangeStart ?>–<?= (int) $rangeEnd ?>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Waktu</th>
                                        <th>Nama</th>
                                        <th>No. Telp</th>
                                        <th>Topik</th>
                                        <th>PIC</th>
                                        <th>Jenis</th>
                                        <th>Keperluan</th>
                                        <th>Status</th>
                                        <th>Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($call_rows_page)): ?>
                                        <?php foreach ($call_rows_page as $call): ?>
                                            <?php
                                            $callLogs = $logs_index[(int) $call['id']] ?? [];
                                            $routedAdmins = [];
                                            $assignedDisplayName = trim((string) ($call['assigned_user_name'] ?: $call['assigned_username'] ?: ''));
                                            $assignedByDisplayName = trim((string) ($call['assigned_by_name'] ?: $call['assigned_by_username'] ?: ''));
                                            foreach ($callLogs as $logRow) {
                                                if (($logRow['event_type'] ?? '') === 'notified' && !empty($logRow['target_user_id'])) {
                                                    $routedAdmins[] = recepsionis_format_user_display_name($logRow);
                                                }
                                            }
                                            $routedAdmins = array_values(array_unique($routedAdmins));
                                            ?>
                                            <tr class="<?= $call['status'] == 'pending' ? 'table-warning' : '' ?>">
                                                <td><?= date('d/m/Y H:i', strtotime($call['created_at'])) ?></td>
                                                <td><strong><?= htmlspecialchars($call['visitor_name']) ?></strong></td>
                                                <td>
                                                    <a href="tel:<?= htmlspecialchars($call['visitor_phone']) ?>" class="text-decoration-none">
                                                        <i class="bi bi-telephone"></i> <?= htmlspecialchars($call['visitor_phone']) ?>
                                                    </a>
                                                </td>
                                                <td>
                                                    <div class="small">
                                                        <strong><?= htmlspecialchars($call['category_name'] ?: 'Tanpa kategori') ?></strong>
                                                        <?php if (!empty($routedAdmins)): ?>
                                                            <br><span class="text-muted">Ke: <?= htmlspecialchars(implode(', ', $routedAdmins)) ?></span>
                                                        <?php else: ?>
                                                            <br><span class="text-muted">Belum ada jejak routing</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="small">
                                                        <?php if ($assignedDisplayName !== ''): ?>
                                                            <strong><i class="bi bi-person-check"></i> <?= htmlspecialchars($assignedDisplayName) ?></strong>
                                                            <?php if (!empty($call['assigned_at'])): ?>
                                                                <br><span class="text-muted"><?= date('d/m/Y H:i', strtotime($call['assigned_at'])) ?></span>
                                                            <?php endif; ?>
                                                            <?php if ($assignedByDisplayName !== ''): ?>
                                                                <br><span class="text-muted">Oleh: <?= htmlspecialchars($assignedByDisplayName) ?></span>
                                                            <?php endif; ?>
                                                        <?php elseif (!empty($routedAdmins)): ?>
                                                            <span class="text-muted">Routing default</span>
                                                            <br><strong><?= htmlspecialchars(implode(', ', $routedAdmins)) ?></strong>
                                                        <?php else: ?>
                                                            <span class="text-muted">Belum ditetapkan</span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <?php if ($isAdminUser && $call['status'] == 'pending' && !empty($assignableUsers)): ?>
                                                        <form method="POST" class="mt-2">
                                                            <input type="hidden" name="call_id" value="<?= (int) $call['id'] ?>">
                                                            <div class="input-group input-group-sm">
                                                                <select name="assigned_user_id" class="form-select">
                                                                    <option value="">Pilih PIC...</option>
                                                                    <?php foreach ($assignableUsers as $assignUser): ?>
                                                                        <option value="<?= (int) $assignUser['id'] ?>" <?= (int) ($call['assigned_user_id'] ?? 0) === (int) $assignUser['id'] ? 'selected' : '' ?>>
                                                                            <?= htmlspecialchars(($assignUser['nama_lengkap'] ?: $assignUser['username']) . ' (' . $assignUser['role'] . ')') ?>
                                                                        </option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                                <button type="submit" name="save_assignment" class="btn btn-outline-primary">
                                                                    <?= $assignedDisplayName !== '' ? 'Pindah' : 'Tetapkan' ?>
                                                                </button>
                                                            </div>
                                                        </form>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if (($call['call_type'] ?? '') === 'live_chat'): ?>
                                                        <span class="badge bg-info">Live Chat</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary"><?= htmlspecialchars($call['call_type'] ?: 'general') ?></span>
                                                    <?php endif; ?>
                                                    <?php if (!empty($call['live_status'])): ?>
                                                        <br><small class="text-muted">Sesi: <?= htmlspecialchars($call['live_status']) ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="text-break" style="max-width: 400px;">
                                                        <?= htmlspecialchars($call['message']) ?>
                                                    </div>
                                                    <?php if (!empty($callLogs)): ?>
                                                        <details class="mt-2">
                                                            <summary class="small text-primary">Lihat log</summary>
                                                            <div class="mt-2 small text-muted">
                                                                <?php foreach ($callLogs as $logRow): ?>
                                                                    <div class="mb-1">
                                                                        <strong><?= htmlspecialchars(staffCallEventLabel((string) $logRow['event_type'])) ?></strong>
                                                                        <span><?= date('d/m/Y H:i', strtotime($logRow['created_at'])) ?></span>
                                                                        <?php if (!empty($logRow['target_user_id'])): ?>
                                                                            <span>- <?= htmlspecialchars(recepsionis_format_user_display_name($logRow)) ?></span>
                                                                        <?php elseif (!empty($logRow['actor_user_id'])): ?>
                                                                            <span>- <?= htmlspecialchars(recepsionis_format_user_display_name($logRow)) ?></span>
                                                                        <?php endif; ?>
                                                                        <?php if (!empty($logRow['notes'])): ?>
                                                                            <div><?= htmlspecialchars($logRow['notes']) ?></div>
                                                                        <?php endif; ?>
                                                                    </div>
                                                                <?php endforeach; ?>
                                                            </div>
                                                        </details>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="badge-status <?= $call['status'] ?>">
                                                        <?= ucfirst($call['status']) ?>
                                                    </span>
                                                    <?php
                                                    $callFollowUp = (string) ($call['follow_up_action'] ?? 'none');
                                                    if ($callFollowUp !== '' && $callFollowUp !== 'none'):
                                                    ?>
                                                        <br><span class="badge <?= $callFollowUp === 'confirm' ? 'bg-success' : 'bg-secondary' ?> mt-1">
                                                            <?= htmlspecialchars(recepsionis_follow_up_action_label($callFollowUp)) ?>
                                                        </span>
                                                    <?php endif; ?>
                                                    <?php if ($call['answered_by_name']): ?>
                                                        <br><small class="text-muted"><i class="bi bi-person-check"></i> <?= htmlspecialchars($call['answered_by_name']) ?></small>
                                                    <?php endif; ?>
                                                    <?php if (!empty($call['answered_at'])): ?>
                                                        <br><small class="text-muted"><?= date('d/m/Y H:i', strtotime($call['answered_at'])) ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="btn-group btn-group-sm">
                                                        <?php if ($call['status'] == 'pending'): ?>
                                                            <a href="tel:<?= htmlspecialchars($call['visitor_phone']) ?>" 
                                                               class="btn btn-primary btn-sm" 
                                                               title="Hubungi">
                                                                <i class="bi bi-telephone"></i>
                                                            </a>
                                                            <a href="?answer=<?= $call['id'] ?>&status=<?= urlencode($status_filter) ?><?= $isAdminUser ? '&view=' . urlencode($view_filter) : '' ?>&page=<?= $page ?>&channel=<?= urlencode($channel_filter) ?>&per_page=<?= $perPage ?>" 
                                                               class="btn btn-success btn-sm"
                                                               title="Tandai Terjawab">
                                                                <i class="bi bi-check-circle"></i> Jawab
                                                            </a>
                                                            <a href="?cancel=<?= $call['id'] ?>&status=<?= urlencode($status_filter) ?><?= $isAdminUser ? '&view=' . urlencode($view_filter) : '' ?>&page=<?= $page ?>&channel=<?= urlencode($channel_filter) ?>&per_page=<?= $perPage ?>" 
                                                               class="btn btn-danger btn-sm"
                                                               onclick="return confirm('Batalkan panggilan ini?')"
                                                               title="Batalkan">
                                                                <i class="bi bi-x-circle"></i>
                                                            </a>
                                                        <?php else: ?>
                                                            <span class="text-muted small">Selesai</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="9" class="text-center text-muted py-4">
                                                <i class="bi bi-inbox" style="font-size: 3rem;"></i><br>
                                                Tidak ada panggilan
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if ($totalPages > 1): ?>
                            <div class="card-footer d-flex flex-wrap justify-content-between align-items-center gap-3">
                                <div class="small text-muted">
                                    Halaman <?= (int) $page ?> dari <?= (int) $totalPages ?>
                                </div>
                                <nav aria-label="Navigasi halaman panggilan">
                                    <ul class="pagination pagination-sm mb-0 flex-wrap">
                                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                            <a class="page-link" href="<?= htmlspecialchars(staff_calls_page_url(max(1, $page - 1), $status_filter, $view_filter, $perPage, $isAdminUser, $channel_filter)) ?>">Sebelumnya</a>
                                        </li>
                                        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                                            <?php
                                            $pStart = (($p - 1) * $perPage) + 1;
                                            $pEnd = min($p * $perPage, $totalRows);
                                            ?>
                                            <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                                                <a class="page-link" href="<?= htmlspecialchars(staff_calls_page_url($p, $status_filter, $view_filter, $perPage, $isAdminUser, $channel_filter)) ?>" title="Data <?= $pStart ?>–<?= $pEnd ?>">
                                                    <?= $p ?> <span class="d-none d-md-inline">(<?= $pStart ?>–<?= $pEnd ?>)</span>
                                                </a>
                                            </li>
                                        <?php endfor; ?>
                                        <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                                            <a class="page-link" href="<?= htmlspecialchars(staff_calls_page_url(min($totalPages, $page + 1), $status_filter, $view_filter, $perPage, $isAdminUser, $channel_filter)) ?>">Berikutnya</a>
                                        </li>
                                    </ul>
                                </nav>
                                <form method="get" class="d-flex align-items-center gap-2">
                                    <input type="hidden" name="status" value="<?= htmlspecialchars($status_filter) ?>">
                                    <input type="hidden" name="channel" value="<?= htmlspecialchars($channel_filter) ?>">
                                    <?php if ($isAdminUser): ?>
                                        <input type="hidden" name="view" value="<?= htmlspecialchars($view_filter) ?>">
                                    <?php endif; ?>
                                    <input type="hidden" name="per_page" value="<?= (int) $perPage ?>">
                                    <label class="small text-muted mb-0">Lompat</label>
                                    <select name="page" class="form-select form-select-sm" style="width:auto; min-width: 9rem;" onchange="this.form.submit()">
                                        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                                            <?php
                                            $pStart = (($p - 1) * $perPage) + 1;
                                            $pEnd = min($p * $perPage, $totalRows);
                                            ?>
                                            <option value="<?= $p ?>" <?= $p === $page ? 'selected' : '' ?>>
                                                Halaman <?= $p ?> (<?= $pStart ?>–<?= $pEnd ?>)
                                            </option>
                                        <?php endfor; ?>
                                    </select>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($showTicketsTable): ?>
                <?php if ($embedTicketsInPanel): ?>
                <section class="hd-panel hd-tickets-panel">
                    <div class="hd-panel-head">
                        <div>
                            <h2><i class="bi bi-journal-text"></i> Daftar Laporan</h2>
                            <p>Total <?= count($ticket_rows) ?> tiket</p>
                        </div>
                    </div>
                    <div class="hd-tickets-filter">
                        <?php $renderStatusFilter(); ?>
                    </div>
                    <div class="hd-table-wrap">
                        <table class="hd-table">
                <?php else: ?>
                <div class="card">
                    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <span><i class="bi bi-journal-text"></i> Daftar Laporan</span>
                        <span class="small opacity-75">Total <strong><?= count($ticket_rows) ?></strong> tiket</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle">
                <?php endif; ?>
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
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($ticket_rows)): ?>
                                    <?php foreach ($ticket_rows as $t): ?>
                                        <?php
                                        $formatted = recepsionis_format_helpdesk_ticket_table_row($t);
                                        $ticketStatus = (string) ($t['status'] ?? 'pending');
                                        $response = recepsionis_helpdesk_ticket_response_display($t);
                                        $isExpiredTicket = $ticketStatus === 'expired';
                                        $assignedForPerm = isset($t['assigned_user_id']) ? (int) $t['assigned_user_id'] : null;
                                        if ($assignedForPerm !== null && $assignedForPerm <= 0) {
                                            $assignedForPerm = null;
                                        }
                                        $canManageThisTicket = recepsionis_user_can_manage_helpdesk_it_ticket(
                                            $koneksi,
                                            $currentUserId,
                                            $assignedForPerm,
                                            recepsionis_resolve_helpdesk_it_ticket_category_id($koneksi, $t),
                                            $currentUserRole
                                        );
                                        ?>
                                        <tr data-ticket-row="<?= (int) $t['id'] ?>">
                                            <td data-label="ID"><span class="hd-ticket-id"><?= htmlspecialchars($formatted['id']) ?></span></td>
                                            <td data-label="Waktu"><?= htmlspecialchars($formatted['time']) ?></td>
                                            <td data-label="Tipe"><span class="hd-chip"><?= htmlspecialchars($formatted['type']) ?></span></td>
                                            <td data-label="Lokasi"><?= htmlspecialchars($formatted['location']) ?></td>
                                            <td data-label="Kategori"><span class="hd-chip"><?= htmlspecialchars($formatted['category']) ?></span></td>
                                            <td data-label="Ditindak Oleh"><?= htmlspecialchars($formatted['handler']) ?></td>
                                            <td data-label="Catatan" class="hd-notes-cell"><?= nl2br(htmlspecialchars($formatted['notes'])) ?></td>
                                            <td data-label="Status">
                                                <?php if ($embedTicketsInPanel): ?>
                                                    <span class="hd-status <?= htmlspecialchars($formatted['status_class']) ?>" data-ticket-badge="<?= (int) $t['id'] ?>"><?= htmlspecialchars($formatted['status']) ?></span>
                                                <?php else: ?>
                                                    <?php
                                                    $badge = 'secondary';
                                                    if ($t['status'] === 'pending') {
                                                        $badge = 'warning text-dark';
                                                    } elseif ($t['status'] === 'in_progress') {
                                                        $badge = 'info text-dark';
                                                    } elseif ($t['status'] === 'resolved') {
                                                        $badge = 'success';
                                                    } elseif ($t['status'] === 'expired') {
                                                        $badge = 'dark';
                                                    }
                                                    ?>
                                                    <span class="badge bg-<?= $badge ?>" data-ticket-badge="<?= (int) $t['id'] ?>"><?= htmlspecialchars($t['status']) ?></span>
                                                <?php endif; ?>
                                                <?php
                                                $ticketFollowUp = (string) ($t['follow_up_action'] ?? 'none');
                                                if ($ticketFollowUp !== '' && $ticketFollowUp !== 'none'):
                                                ?>
                                                    <br><span class="badge <?= $ticketFollowUp === 'confirm' ? 'bg-success' : 'bg-secondary' ?> mt-1">
                                                        <?= htmlspecialchars(recepsionis_follow_up_action_label($ticketFollowUp)) ?>
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td data-label="Waktu Respon" data-ticket-response="<?= (int) $t['id'] ?>">
                                                <?php if ($response['label'] === '—'): ?>
                                                    <span class="text-muted">—</span>
                                                <?php else: ?>
                                                    <span class="fw-semibold hd-response-tone hd-response-tone--<?= htmlspecialchars($response['tone']) ?>"><?= htmlspecialchars($response['label']) ?></span>
                                                    <?php if ($response['is_expired']): ?>
                                                        <div class="text-muted small">expired (&gt;24 jam)</div>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </td>
                                            <td data-label="Aksi">
                                                <?php if ($isExpiredTicket): ?>
                                                    <span class="badge bg-dark">expired</span>
                                                <?php elseif ($canManageThisTicket): ?>
                                                <select class="form-select form-select-sm ticket-status-select" data-ticket-id="<?= (int) $t['id'] ?>" data-prev-status="<?= htmlspecialchars($t['status'], ENT_QUOTES, 'UTF-8') ?>">
                                                    <option value="pending" <?= $t['status'] === 'pending' ? 'selected' : '' ?>>pending</option>
                                                    <option value="in_progress" <?= $t['status'] === 'in_progress' ? 'selected' : '' ?>>in_progress</option>
                                                    <option value="resolved" <?= $t['status'] === 'resolved' ? 'selected' : '' ?>>resolved</option>
                                                </select>
                                                <?php else: ?>
                                                    <span class="text-muted small">—</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="10" class="text-center text-muted py-4">Belum ada tiket.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                <?php if ($embedTicketsInPanel): ?>
                </section>
                <?php else: ?>
                </div>
                <?php endif; ?>
                <?php endif; ?>

<?php if ($isHelpdeskTicketsMode): ?>
</div>
    <div id="toastContainer" class="toast-container"></div>
<?php else: ?>
            </div>
        </div>
    </div>
    <div id="toastContainer" class="toast-container"></div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/toast.js"></script>
    <script src="../assets/js/notification-badge.js"></script>
    <?php include 'include_staff_call_footer.php'; ?>
</body>
</html>
<?php endif; ?>

    <?php if ($showTicketsTable): ?>
    <script>
    (function () {
        const statusApiUrl = <?= json_encode($helpdeskStatusApiUrl, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const badgeClassByStatus = {
            pending: 'hd-status is-new',
            in_progress: 'hd-status is-progress',
            resolved: 'hd-status is-resolved',
            expired: 'hd-status is-breach',
        };
        const badgeLabelByStatus = {
            pending: 'Pending',
            in_progress: 'Diproses',
            resolved: 'Selesai',
            expired: 'Expired',
        };
        const useHdStatus = <?= $embedTicketsInPanel ? 'true' : 'false' ?>;

        function updateTicketBadge(ticketId, status) {
            const badge = document.querySelector('[data-ticket-badge="' + ticketId + '"]');
            if (!badge) return;
            if (useHdStatus) {
                badge.textContent = badgeLabelByStatus[status] || status;
                badge.className = badgeClassByStatus[status] || 'hd-status';
                badge.setAttribute('data-ticket-badge', ticketId);
                return;
            }
            badge.textContent = status;
            badge.className = 'badge ' + ({
                pending: 'bg-warning text-dark',
                in_progress: 'bg-info text-dark',
                resolved: 'bg-success',
                expired: 'bg-dark',
            }[status] || 'bg-secondary');
        }

        function updateTicketResponse(ticketId, data) {
            const cell = document.querySelector('[data-ticket-response="' + ticketId + '"]');
            if (!cell) return;
            if (data && data.response_label && data.response_label !== '—') {
                cell.innerHTML = '<span class="fw-semibold hd-response-tone hd-response-tone--success">' + data.response_label + '</span>';
            }
        }

        document.querySelectorAll('.ticket-status-select').forEach(function (select) {
            select.addEventListener('change', async function () {
                const ticketId = select.dataset.ticketId;
                const prevStatus = select.dataset.prevStatus || select.value;
                const nextStatus = select.value;
                if (!ticketId || nextStatus === prevStatus) return;

                select.disabled = true;
                try {
                    const body = new FormData();
                    body.append('ticket_id', ticketId);
                    body.append('status', nextStatus);
                    const res = await fetch(statusApiUrl, { method: 'POST', body, credentials: 'same-origin' });
                    const data = await res.json();
                    if (!data.success) throw new Error(data.message || 'Gagal memperbarui status.');
                    select.dataset.prevStatus = nextStatus;
                    updateTicketBadge(ticketId, nextStatus);
                    updateTicketResponse(ticketId, data);
                    var statusLabel = typeof helpdeskStatusLabel === 'function'
                        ? helpdeskStatusLabel(nextStatus)
                        : nextStatus;
                    if (typeof showHelpdeskStatusNotify === 'function') {
                        showHelpdeskStatusNotify(
                            'Status tiket diperbarui',
                            'Tiket #' + ticketId + ' → ' + statusLabel
                        );
                    } else if (typeof showSuccess === 'function') {
                        showSuccess('Status tiket', 'Tiket #' + ticketId + ' → ' + statusLabel);
                    }
                } catch (err) {
                    select.value = prevStatus;
                    if (typeof showError === 'function') {
                        showError('Gagal', err.message || 'Status tiket tidak dapat diperbarui.');
                    }
                } finally {
                    select.disabled = false;
                }
            });
        });
    })();
    </script>
    <?php endif; ?>

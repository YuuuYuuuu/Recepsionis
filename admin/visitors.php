<?php
require_once 'auth.php';
requireSuperAdminPage();

// Sinkron panggilan staff → data tamu, lalu auto checkout tamu yang sudah lewat hari / 24 jam
recepsionis_sync_staff_calls_to_visitors($koneksi);
$autoCheckoutCount = recepsionis_run_auto_checkout($koneksi);

// Handle actions
$status_filter = $_GET['status'] ?? 'all';
$allowedPerPage = [15, 25, 50, 100];
$perPage = (int) ($_GET['per_page'] ?? 15);
if (!in_array($perPage, $allowedPerPage, true)) {
    $perPage = 15;
}

function visitors_page_url(int $page, string $statusFilter, int $perPage, array $extra = []): string
{
    $params = array_merge([
        'status' => $statusFilter,
        'page' => $page,
        'per_page' => $perPage,
    ], $extra);

    return 'visitors.php?' . http_build_query($params);
}

function visitors_redirect_url(array $extra = []): string
{
    global $status_filter, $perPage;
    $page = max(1, (int) ($_GET['page'] ?? 1));

    return visitors_page_url($page, $status_filter, $perPage, $extra);
}

if (isset($_GET['checkout'])) {
    $id = intval($_GET['checkout']);
    if ($id > 0) {
        recepsionis_checkout_visitor_by_id($koneksi, $id);
    }
    header('Location: ' . visitors_redirect_url(['success' => 'checkout']));
    exit;
}

if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    if ($id > 0) {
        $stmt = $koneksi->prepare("DELETE FROM visitors WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();
    }
    header('Location: ' . visitors_redirect_url(['success' => 'deleted']));
    exit;
}

$totalRows = recepsionis_count_visitors($koneksi, $status_filter);
$totalPages = max(1, (int) ceil($totalRows / $perPage));
$page = max(1, (int) ($_GET['page'] ?? 1));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;
$visitors = recepsionis_fetch_visitors($koneksi, $status_filter, $perPage, $offset);
$rangeStart = $totalRows > 0 ? $offset + 1 : 0;
$rangeEnd = $totalRows > 0 ? min($offset + $perPage, $totalRows) : 0;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Tamu - E-Recepsionis System</title>
    <script>
        window.originalPageTitle = 'Data Tamu - E-Recepsionis System';
    </script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <?php include 'include_staff_call_head.php'; ?>
    <style>
        :root {
            --primary: #2563eb;
            --secondary: #0369a1;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
        }

        .content-area {
            background: #f8fafc;
            min-height: calc(100vh - 56px);
        }

        .visitors-toolbar-title {
            font-size: 1.35rem;
            font-weight: 700;
            color: var(--adm-text, #15202b);
            margin: 0;
        }

        .visitors-toolbar-title i {
            color: var(--adm-accent, #0f6e56);
        }

        .visitors-table-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            overflow: hidden;
        }

        .visitors-table-card .card-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            padding: 20px 25px;
            border: none;
            font-weight: 600;
            font-size: 1.1rem;
        }

        .visitors-table-card .table {
            margin: 0;
        }

        .visitors-table-card .table thead {
            background: #f8fafc;
        }

        .visitors-table-card .table thead th {
            border-bottom: 2px solid #e2e8f0;
            font-weight: 700;
            color: #475569;
            padding: 15px;
            text-transform: uppercase;
            font-size: 0.85rem;
            letter-spacing: 0.5px;
        }

        .visitors-table-card .table tbody tr {
            transition: all 0.3s;
            border-bottom: 1px solid #f1f5f9;
        }

        .visitors-table-card .table tbody tr:hover {
            background: #f8fafc;
            transform: scale(1.01);
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .visitors-table-card .table tbody td {
            padding: 18px 15px;
            vertical-align: middle;
        }

        .badge-number {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            padding: 6px 14px;
            border-radius: 20px;
            font-weight: 700;
            font-size: 0.9rem;
            display: inline-block;
            box-shadow: 0 2px 8px rgba(102, 126, 234, 0.3);
        }

        .visitor-photo {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--primary);
            margin-left: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .badge-status {
            padding: 6px 14px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.85rem;
            display: inline-block;
        }

        .badge-status.checked-in {
            background: linear-gradient(135deg, var(--success), #059669);
            color: white;
            box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);
        }

        .badge-status.checked-out {
            background: linear-gradient(135deg, var(--info), #2563eb);
            color: white;
            box-shadow: 0 2px 8px rgba(59, 130, 246, 0.3);
        }

        .badge-status.pending {
            background: linear-gradient(135deg, var(--warning), #d97706);
            color: white;
            box-shadow: 0 2px 8px rgba(245, 158, 11, 0.3);
        }

        .action-buttons .btn {
            margin: 0 2px;
            border-radius: 8px;
            padding: 6px 12px;
            font-weight: 600;
            transition: all 0.3s;
        }

        .action-buttons .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }

        .action-buttons .btn-warning {
            background: linear-gradient(135deg, var(--warning), #d97706);
            border: none;
            color: white;
        }

        .action-buttons .btn-info {
            background: linear-gradient(135deg, var(--info), #2563eb);
            border: none;
            color: white;
        }

        .action-buttons .btn-danger {
            background: linear-gradient(135deg, var(--danger), #dc2626);
            border: none;
            color: white;
        }

        .empty-state {
            padding: 60px 20px;
            text-align: center;
        }

        .empty-state i {
            font-size: 4rem;
            color: #cbd5e1;
            margin-bottom: 20px;
        }

        .empty-state h5 {
            color: #64748b;
            font-weight: 600;
        }

        .alert-success {
            background: linear-gradient(135deg, var(--success), #059669);
            color: white;
            border: none;
            border-radius: 10px;
            padding: 15px 20px;
            box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);
        }

        .alert-success .btn-close {
            filter: brightness(0) invert(1);
        }
    </style>
</head>
<body>
    <?php include 'navbar.php'; ?>

    <div class="container-fluid">
        <div class="row">
            <?php include 'sidebar.php'; ?>

            <div class="col-md-10 content-area">
                <!-- Toolbar: judul + filter -->
                <div class="card mb-3 adm-filter-panel">
                    <div class="card-body">
                        <div class="adm-filter-toolbar adm-filter-toolbar--helpdesk">
                            <div class="adm-filter-row">
                                <div class="adm-filter-group">
                                    <h2 class="visitors-toolbar-title"><i class="bi bi-people"></i> Data Tamu</h2>
                                </div>
                                <div class="ms-auto">
                                    <a href="../visitor/index.php" class="btn btn-primary btn-sm" target="_blank">
                                        <i class="bi bi-person-plus"></i> Check-In Baru
                                    </a>
                                </div>
                            </div>
                            <div class="adm-filter-row adm-filter-row--meta">
                                <div class="adm-filter-group adm-filter-group--grow">
                                    <span class="adm-filter-label"><i class="bi bi-funnel"></i> Status</span>
                                    <div class="adm-segment adm-segment-scroll" role="group" aria-label="Filter status tamu">
                                        <a href="?status=all&per_page=<?= (int) $perPage ?>" class="adm-segment-item <?= $status_filter == 'all' ? 'is-active' : '' ?>">
                                            <i class="bi bi-list-ul"></i> Semua
                                        </a>
                                        <a href="?status=checked-in&per_page=<?= (int) $perPage ?>" class="adm-segment-item <?= $status_filter == 'checked-in' ? 'is-active' : '' ?>">
                                            <i class="bi bi-box-arrow-in-right"></i> Check-In
                                        </a>
                                        <a href="?status=checked-out&per_page=<?= (int) $perPage ?>" class="adm-segment-item <?= $status_filter == 'checked-out' ? 'is-active' : '' ?>">
                                            <i class="bi bi-box-arrow-right"></i> Check-Out
                                        </a>
                                        <a href="?status=pending&per_page=<?= (int) $perPage ?>" class="adm-segment-item <?= $status_filter == 'pending' ? 'is-active' : '' ?>">
                                            <i class="bi bi-clock"></i> Pending
                                        </a>
                                    </div>
                                </div>
                                <form method="get" class="adm-filter-pagination">
                                    <input type="hidden" name="status" value="<?= htmlspecialchars($status_filter) ?>">
                                    <input type="hidden" name="page" value="1">
                                    <label class="adm-filter-label mb-0" for="visitors-per-page">Tampilkan</label>
                                    <select id="visitors-per-page" name="per_page" class="form-select form-select-sm adm-filter-select" onchange="this.form.submit()">
                                        <?php foreach ($allowedPerPage as $option): ?>
                                            <option value="<?= $option ?>" <?= $perPage === $option ? 'selected' : '' ?>><?= $option ?> / halaman</option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($autoCheckoutCount > 0): ?>
                    <div class="alert alert-info alert-dismissible fade show">
                        <i class="bi bi-clock-history"></i>
                        <?= (int) $autoCheckoutCount ?> tamu otomatis check-out (lewat hari atau sudah 24 jam).
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (isset($_GET['success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="bi bi-check-circle"></i> 
                        <?php
                        if ($_GET['success'] == 'checkout') echo 'Tamu berhasil check-out';
                        elseif ($_GET['success'] == 'deleted') echo 'Data tamu berhasil dihapus';
                        ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Visitors Table -->
                <div class="visitors-table-card">
                    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <span><i class="bi bi-list-ul"></i> Daftar Tamu</span>
                        <?php if ($totalRows > 0): ?>
                            <span class="small opacity-75">Menampilkan <?= (int) $rangeStart ?>–<?= (int) $rangeEnd ?> dari <?= (int) $totalRows ?> tamu</span>
                        <?php endif; ?>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive" style="max-height: 70vh; overflow-y: auto;">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Badge</th>
                                        <th>Nama</th>
                                        <th>No. Telp</th>
                                        <th>Kategori</th>
                                        <th>Host</th>
                                        <th>Status</th>
                                        <th>Check-In</th>
                                        <th>Check-Out</th>
                                        <th>Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($visitors && $visitors->num_rows > 0): ?>
                                        <?php while ($visitor = $visitors->fetch_assoc()): ?>
                                            <tr>
                                                <td>
                                                    <span class="badge-number">
                                                        <i class="bi bi-tag-fill"></i> <?= htmlspecialchars($visitor['badge_number'] ?? '') ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <span style="font-weight: 600; color: #1e293b;">
                                                            <?= htmlspecialchars($visitor['nama'] ?? '') ?>
                                                        </span>
                                                        <?php if ($visitor['foto']): ?>
                                                            <img src="../uploads/<?= htmlspecialchars($visitor['foto'] ?? '') ?>" 
                                                                 alt="Foto" 
                                                                 class="visitor-photo">
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span style="color: #475569; font-weight: 500;">
                                                        <i class="bi bi-telephone"></i>
                                                        <?= htmlspecialchars($visitor['display_phone'] ?? $visitor['no_telp'] ?? '-') ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if (!empty($visitor['category_name'])): ?>
                                                        <span class="badge bg-primary"><?= htmlspecialchars($visitor['category_name']) ?></span>
                                                    <?php else: ?>
                                                        <span style="color: #94a3b8;">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php
                                                    $hostLabel = trim((string) ($visitor['display_host_nama'] ?? ''));
                                                    if ($hostLabel === '') {
                                                        $hostLabel = '-';
                                                    }
                                                    ?>
                                                    <span style="color: #475569; font-weight: 500;">
                                                        <i class="bi bi-person-badge"></i> <?= htmlspecialchars($hostLabel) ?>
                                                    </span>
                                                    <?php if (!empty($visitor['staff_call_id']) && ($visitor['staff_call_status'] ?? '') !== 'answered'): ?>
                                                        <br><small class="text-muted">Menunggu PIC</small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="badge-status <?= $visitor['status'] ?>">
                                                        <?= ucfirst(str_replace('-', ' ', $visitor['status'])) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span style="color: #64748b; font-size: 0.9rem;">
                                                        <i class="bi bi-calendar-check"></i> 
                                                        <?= $visitor['checkin_time'] ? date('d/m/Y H:i', strtotime($visitor['checkin_time'])) : '-' ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span style="color: #64748b; font-size: 0.9rem;">
                                                        <i class="bi bi-calendar-x"></i> 
                                                        <?= $visitor['checkout_time'] ? date('d/m/Y H:i', strtotime($visitor['checkout_time'])) : '-' ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="action-buttons">
                                                        <?php if ($visitor['status'] == 'checked-in'): ?>
                                                            <a href="<?= htmlspecialchars(visitors_page_url($page, $status_filter, $perPage, ['checkout' => (int) $visitor['id']])) ?>"
                                                               class="btn btn-warning btn-sm"
                                                               onclick="return confirm('Check-out tamu ini?')"
                                                               title="Check-Out">
                                                                <i class="bi bi-box-arrow-right"></i>
                                                            </a>
                                                        <?php endif; ?>
                                                        <a href="badge.php?id=<?= $visitor['id'] ?>" 
                                                           class="btn btn-info btn-sm" 
                                                           target="_blank"
                                                           title="Cetak Badge">
                                                            <i class="bi bi-printer"></i>
                                                        </a>
                                                           <a href="<?= htmlspecialchars(visitors_page_url($page, $status_filter, $perPage, ['delete' => (int) $visitor['id']])) ?>"
                                                           class="btn btn-danger btn-sm"
                                                           onclick="return confirm('Yakin ingin menghapus data tamu <?= htmlspecialchars($visitor['nama'] ?? '') ?>?')"
                                                           title="Hapus">
                                                            <i class="bi bi-trash"></i>
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="9" class="empty-state">
                                                <i class="bi bi-inbox"></i>
                                                <h5>Tidak ada data tamu</h5>
                                                <p class="text-muted">Belum ada tamu yang terdaftar</p>
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
                                <nav aria-label="Navigasi halaman tamu">
                                    <ul class="pagination pagination-sm mb-0 flex-wrap">
                                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                            <a class="page-link" href="<?= htmlspecialchars(visitors_page_url(max(1, $page - 1), $status_filter, $perPage)) ?>">Sebelumnya</a>
                                        </li>
                                        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                                            <?php
                                            $pStart = (($p - 1) * $perPage) + 1;
                                            $pEnd = min($p * $perPage, $totalRows);
                                            ?>
                                            <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                                                <a class="page-link" href="<?= htmlspecialchars(visitors_page_url($p, $status_filter, $perPage)) ?>" title="Data <?= $pStart ?>–<?= $pEnd ?>">
                                                    <?= $p ?> <span class="d-none d-md-inline">(<?= $pStart ?>–<?= $pEnd ?>)</span>
                                                </a>
                                            </li>
                                        <?php endfor; ?>
                                        <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                                            <a class="page-link" href="<?= htmlspecialchars(visitors_page_url(min($totalPages, $page + 1), $status_filter, $perPage)) ?>">Berikutnya</a>
                                        </li>
                                    </ul>
                                </nav>
                                <form method="get" class="d-flex align-items-center gap-2">
                                    <input type="hidden" name="status" value="<?= htmlspecialchars($status_filter) ?>">
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
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/notification-badge.js"></script>
    <?php include 'include_staff_call_footer.php'; ?>
</body>
</html>

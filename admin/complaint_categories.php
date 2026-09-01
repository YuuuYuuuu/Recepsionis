<?php
require_once 'auth.php';
require_once '../staff_call_routing.php';
checkRole('admin');

// Handle actions
if (isset($_POST['tambah_kategori'])) {
    $nama_kategori = trim($_POST['nama_kategori'] ?? '');
    $deskripsi = trim($_POST['deskripsi'] ?? '');
    $icon = trim($_POST['icon'] ?? 'bi-tag');
    $warna = trim($_POST['warna'] ?? '#2563eb');
    $urutan = intval($_POST['urutan'] ?? 0);
    
    if (empty($nama_kategori)) {
        header("Location: complaint_categories.php?error=empty_name");
        exit;
    }
    
    $stmt = $koneksi->prepare("INSERT INTO complaint_categories (nama_kategori, deskripsi, icon, warna, urutan) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("ssssi", $nama_kategori, $deskripsi, $icon, $warna, $urutan);
    $stmt->execute();
    $stmt->close();
    
    header("Location: complaint_categories.php?success=added");
    exit;
}

if (isset($_POST['edit_kategori'])) {
    $id = intval($_POST['id']);
    $nama_kategori = trim($_POST['nama_kategori'] ?? '');
    $deskripsi = trim($_POST['deskripsi'] ?? '');
    $icon = trim($_POST['icon'] ?? 'bi-tag');
    $warna = trim($_POST['warna'] ?? '#2563eb');
    $urutan = intval($_POST['urutan'] ?? 0);
    
    if (empty($nama_kategori) || $id <= 0) {
        header("Location: complaint_categories.php?error=invalid_data");
        exit;
    }
    
    $stmt = $koneksi->prepare("UPDATE complaint_categories SET nama_kategori=?, deskripsi=?, icon=?, warna=?, urutan=? WHERE id=?");
    $stmt->bind_param("ssssii", $nama_kategori, $deskripsi, $icon, $warna, $urutan, $id);
    $stmt->execute();
    $stmt->close();
    
    header("Location: complaint_categories.php?success=updated");
    exit;
}

if (isset($_GET['toggle'])) {
    $id = intval($_GET['toggle']);
    $status = intval($_GET['status']);
    
    if ($id > 0 && ($status == 0 || $status == 1)) {
        $stmt = $koneksi->prepare("UPDATE complaint_categories SET status_aktif=? WHERE id=?");
        $stmt->bind_param("ii", $status, $id);
        $stmt->execute();
        $stmt->close();
    }
    
    header("Location: complaint_categories.php");
    exit;
}

if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    
    if ($id > 0) {
        $stmt = $koneksi->prepare("DELETE FROM complaint_categories WHERE id=?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();
    }
    
    header("Location: complaint_categories.php?success=deleted");
    exit;
}

// Get categories
if (recepsionis_table_exists($koneksi, 'admin_category_routing')) {
    $categories_list = $koneksi->query(
        "SELECT cc.*,
                COUNT(DISTINCT acr.user_id) AS routed_admin_count,
                GROUP_CONCAT(DISTINCT COALESCE(NULLIF(u.nama_lengkap, ''), u.username) ORDER BY COALESCE(NULLIF(u.nama_lengkap, ''), u.username) SEPARATOR ', ') AS routed_admin_names
         FROM complaint_categories cc
         LEFT JOIN admin_category_routing acr ON acr.category_id = cc.id
         LEFT JOIN users u ON u.id = acr.user_id AND u.status_aktif = 1
         GROUP BY cc.id, cc.nama_kategori, cc.deskripsi, cc.icon, cc.warna, cc.urutan, cc.status_aktif, cc.created_at, cc.updated_at
         ORDER BY cc.urutan, cc.nama_kategori"
    );
} else {
    $categories_list = $koneksi->query(
        "SELECT cc.*, 0 AS routed_admin_count, '' AS routed_admin_names
         FROM complaint_categories cc
         ORDER BY cc.urutan, cc.nama_kategori"
    );
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kategori Pengaduan - E-Recepsionis System</title>
    <script>
        window.originalPageTitle = 'Kategori Pengaduan - E-Recepsionis System';
    </script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <?php include 'include_staff_call_head.php'; ?>
    <style>
        .category-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border-radius: 20px;
            color: white;
            font-weight: 600;
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
    <?php include 'navbar.php'; ?>

    <div class="container-fluid">
        <div class="row">
            <?php include 'sidebar.php'; ?>

            <div class="col-md-10 content-area">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2 class="mb-0"><i class="bi bi-tags"></i> Kategori Pengaduan</h2>
                    <div class="d-flex gap-2">
                        <a class="btn btn-outline-primary" href="<?= htmlspecialchars(function_exists('adminUrl') ? adminUrl('users.php') : 'users.php') ?>">
                            <i class="bi bi-person-gear"></i> Kelola User
                        </a>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
                            <i class="bi bi-plus-circle"></i> Tambah Kategori
                        </button>
                    </div>
                </div>

                <?php if (isset($_GET['success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="bi bi-check-circle"></i> 
                        <?php
                        if ($_GET['success'] == 'added') echo 'Kategori berhasil ditambahkan';
                        elseif ($_GET['success'] == 'updated') echo 'Kategori berhasil diupdate';
                        elseif ($_GET['success'] == 'deleted') echo 'Kategori berhasil dihapus';
                        ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Categories Table -->
                <div class="card">
                    <div class="card-header">
                        <i class="bi bi-list"></i> Daftar Kategori Pengaduan
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th width="50">Urutan</th>
                                        <th>Nama Kategori</th>
                                        <th>Deskripsi</th>
                                        <th>Icon</th>
                                        <th>Warna</th>
                                        <th>Admin Penerima</th>
                                        <th>Status</th>
                                        <th width="150">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($categories_list && $categories_list->num_rows > 0): ?>
                                        <?php while ($cat = $categories_list->fetch_assoc()): ?>
                                            <tr>
                                                <td><strong><?= $cat['urutan'] ?></strong></td>
                                                <td>
                                                    <span class="category-badge" style="background-color: <?= htmlspecialchars($cat['warna']) ?>">
                                                        <i class="bi <?= htmlspecialchars($cat['icon']) ?>"></i>
                                                        <?= htmlspecialchars($cat['nama_kategori']) ?>
                                                    </span>
                                                </td>
                                                <td><?= htmlspecialchars($cat['deskripsi'] ?: '-') ?></td>
                                                <td><i class="bi <?= htmlspecialchars($cat['icon']) ?>" style="font-size: 1.5rem;"></i></td>
                                                <td>
                                                    <span class="badge" style="background-color: <?= htmlspecialchars($cat['warna']) ?>; color: white;">
                                                        <?= htmlspecialchars($cat['warna']) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if ((int) ($cat['routed_admin_count'] ?? 0) > 0): ?>
                                                        <div class="small">
                                                            <strong><?= (int) $cat['routed_admin_count'] ?> admin</strong><br>
                                                            <span class="text-muted"><?= htmlspecialchars($cat['routed_admin_names']) ?></span>
                                                        </div>
                                                    <?php else: ?>
                                                        <span class="badge bg-warning text-dark">Belum dipetakan</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($cat['status_aktif']): ?>
                                                        <span class="badge bg-success">Aktif</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary">Nonaktif</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="btn-group btn-group-sm">
                                                        <button class="btn btn-primary" 
                                                                data-bs-toggle="modal" 
                                                                data-bs-target="#editModal<?= $cat['id'] ?>"
                                                                title="Edit">
                                                            <i class="bi bi-pencil"></i>
                                                        </button>
                                                        <a href="?toggle=<?= $cat['id'] ?>&status=<?= $cat['status_aktif'] ? 0 : 1 ?>" 
                                                           class="btn btn-<?= $cat['status_aktif'] ? 'warning' : 'success' ?>"
                                                           title="<?= $cat['status_aktif'] ? 'Nonaktifkan' : 'Aktifkan' ?>">
                                                            <i class="bi bi-<?= $cat['status_aktif'] ? 'x-circle' : 'check-circle' ?>"></i>
                                                        </a>
                                                        <a href="?delete=<?= $cat['id'] ?>" 
                                                           class="btn btn-danger"
                                                           onclick="return confirm('Hapus kategori ini?')"
                                                           title="Hapus">
                                                            <i class="bi bi-trash"></i>
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>

                                            <!-- Edit Modal -->
                                            <div class="modal fade" id="editModal<?= $cat['id'] ?>" tabindex="-1">
                                                <div class="modal-dialog">
                                                    <div class="modal-content">
                                                        <div class="modal-header">
                                                            <h5 class="modal-title">Edit Kategori Pengaduan</h5>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                        </div>
                                                        <form method="POST">
                                                            <input type="hidden" name="id" value="<?= $cat['id'] ?>">
                                                            <div class="modal-body">
                                                                <div class="mb-3">
                                                                    <label class="form-label">Nama Kategori *</label>
                                                                    <input type="text" name="nama_kategori" class="form-control" 
                                                                           value="<?= htmlspecialchars($cat['nama_kategori']) ?>" required>
                                                                </div>
                                                                <div class="mb-3">
                                                                    <label class="form-label">Deskripsi</label>
                                                                    <textarea name="deskripsi" class="form-control" rows="3"><?= htmlspecialchars($cat['deskripsi']) ?></textarea>
                                                                </div>
                                                                <div class="row">
                                                                    <div class="col-md-6 mb-3">
                                                                        <label class="form-label">Icon (Bootstrap Icons)</label>
                                                                        <input type="text" name="icon" class="form-control" 
                                                                               value="<?= htmlspecialchars($cat['icon']) ?>"
                                                                               placeholder="bi-tag">
                                                                        <small class="text-muted">Contoh: bi-tag, bi-mortarboard, bi-headset</small>
                                                                    </div>
                                                                    <div class="col-md-6 mb-3">
                                                                        <label class="form-label">Warna</label>
                                                                        <input type="color" name="warna" class="form-control form-control-color" 
                                                                               value="<?= htmlspecialchars($cat['warna']) ?>">
                                                                    </div>
                                                                </div>
                                                                <div class="mb-3">
                                                                    <label class="form-label">Urutan</label>
                                                                    <input type="number" name="urutan" class="form-control" 
                                                                           value="<?= $cat['urutan'] ?>" min="0">
                                                                    <small class="text-muted">Urutan tampil di dropdown (0 = pertama)</small>
                                                                </div>
                                                                <div class="alert alert-light border mb-0">
                                                                    <i class="bi bi-info-circle"></i>
                                                                    Admin penerima untuk kategori ini diatur dari <a href="<?= htmlspecialchars(function_exists('adminUrl') ? adminUrl('users.php') : 'users.php') ?>" class="alert-link">Kelola User</a>.
                                                                </div>
                                                            </div>
                                                            <div class="modal-footer">
                                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                                                                <button type="submit" name="edit_kategori" class="btn btn-primary">Simpan</button>
                                                            </div>
                                                        </form>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="8" class="text-center text-muted py-4">
                                                <i class="bi bi-inbox" style="font-size: 3rem;"></i><br>
                                                Belum ada kategori pengaduan
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Modal -->
    <div class="modal fade" id="addModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Tambah Kategori Pengaduan</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Nama Kategori *</label>
                            <input type="text" name="nama_kategori" class="form-control" required
                                   placeholder="Contoh: Program, Help Desk, Lainnya">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Deskripsi</label>
                            <textarea name="deskripsi" class="form-control" rows="3"
                                      placeholder="Deskripsi kategori pengaduan..."></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Icon (Bootstrap Icons)</label>
                                <input type="text" name="icon" class="form-control" 
                                       value="bi-tag" placeholder="bi-tag">
                                <small class="text-muted">Contoh: bi-tag, bi-mortarboard, bi-headset, bi-question-circle</small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Warna</label>
                                <input type="color" name="warna" class="form-control form-control-color" 
                                       value="#2563eb">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Urutan</label>
                            <input type="number" name="urutan" class="form-control" value="0" min="0">
                            <small class="text-muted">Urutan tampil di dropdown (0 = pertama)</small>
                        </div>
                        <div class="alert alert-light border mb-0">
                            <i class="bi bi-info-circle"></i>
                            Setelah kategori dibuat, pilih user penerimanya dari <a href="<?= htmlspecialchars(function_exists('adminUrl') ? adminUrl('users.php') : 'users.php') ?>" class="alert-link">Kelola User</a>.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" name="tambah_kategori" class="btn btn-primary">Simpan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/notification-badge.js"></script>
    <?php include 'include_staff_call_footer.php'; ?>
</body>
</html>

<?php
require_once 'auth.php';
requireSuperAdminPage();

// Handle actions
if (isset($_POST['tambah_prodi'])) {
    $nama_prodi = esc($_POST['nama_prodi']);
    $kode_prodi = esc($_POST['kode_prodi'] ?? '');
    $penjelasan = esc($_POST['penjelasan'] ?? '');
    $kontak_person = esc($_POST['kontak_person'] ?? '');
    $email = esc($_POST['email'] ?? '');
    $no_telp = esc($_POST['no_telp'] ?? '');
    $direct_link = esc($_POST['direct_link'] ?? '');
    $fakultas = esc($_POST['fakultas'] ?? '');
    $jenjang = esc($_POST['jenjang'] ?? 'S1');
    
    // Handle file upload
    $foto = '';
    $uploadError = '';
    if (isset($_FILES['foto'])) {
        $upload_dir = '../uploads/prodi/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        if (($_FILES['foto']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $file_ext = strtolower(pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION));
            $allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            if (in_array($file_ext, $allowed_ext, true)) {
                $fotoCandidate = uniqid() . '.' . $file_ext;
                $dest = $upload_dir . $fotoCandidate;
                if (move_uploaded_file($_FILES['foto']['tmp_name'], $dest)) {
                    $foto = $fotoCandidate;
                } else {
                    $uploadError = 'Gagal menyimpan file ke folder uploads/prodi (izin folder).';
                }
            } else {
                $uploadError = 'Format file foto tidak didukung.';
            }
        } else {
            $uploadError = 'Upload foto gagal. Cek ukuran file atau konfigurasi PHP.';
        }
    }

    // Karena input foto di form "Tambah Prodi" wajib, stop jika upload gagal.
    if ($uploadError !== '') {
        header("Location: prodi.php?error=upload_failed&reason=" . urlencode($uploadError));
        exit;
    }
    
    $kode_sql = $kode_prodi ? "'$kode_prodi'" : 'NULL';
    $foto_sql = $foto ? "'$foto'" : 'NULL';
    
    $koneksi->query("INSERT INTO prodi (nama_prodi, kode_prodi, penjelasan, kontak_person, email, no_telp, direct_link, fakultas, jenjang, foto) 
                     VALUES ('$nama_prodi', $kode_sql, '$penjelasan', '$kontak_person', '$email', '$no_telp', '$direct_link', '$fakultas', '$jenjang', $foto_sql)");
    header("Location: prodi.php?success=added");
    exit;
}

if (isset($_POST['edit_prodi'])) {
    $id = intval($_POST['id']);
    $nama_prodi = esc($_POST['nama_prodi']);
    $kode_prodi = esc($_POST['kode_prodi'] ?? '');
    $penjelasan = esc($_POST['penjelasan'] ?? '');
    $kontak_person = esc($_POST['kontak_person'] ?? '');
    $email = esc($_POST['email'] ?? '');
    $no_telp = esc($_POST['no_telp'] ?? '');
    $direct_link = esc($_POST['direct_link'] ?? '');
    $fakultas = esc($_POST['fakultas'] ?? '');
    $jenjang = esc($_POST['jenjang'] ?? 'S1');
    
    // Handle file upload
    $foto_update = '';
    $uploadError = '';
    if (isset($_FILES['foto']) && (($_FILES['foto']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK)) {
        $upload_dir = '../uploads/prodi/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        $file_ext = strtolower(pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION));
        $allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        if (in_array($file_ext, $allowed_ext, true)) {
            $fotoCandidate = uniqid() . '.' . $file_ext;
            $dest = $upload_dir . $fotoCandidate;

            // Jangan hapus foto lama sebelum move_uploaded_file sukses
            if (move_uploaded_file($_FILES['foto']['tmp_name'], $dest)) {
                $stmt = $koneksi->prepare("SELECT foto FROM prodi WHERE id = ?");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $result = $stmt->get_result();
                $old_prodi = $result->fetch_assoc();
                $stmt->close();

                if ($old_prodi && $old_prodi['foto'] && file_exists($upload_dir . $old_prodi['foto'])) {
                    unlink($upload_dir . $old_prodi['foto']);
                }

                $foto_update = $fotoCandidate;
            } else {
                $uploadError = 'Gagal menyimpan file foto (izin folder uploads/prodi).';
            }
        } else {
            $uploadError = 'Format file foto tidak didukung.';
        }
    }
    
    if ($foto_update) {
        $stmt = $koneksi->prepare("UPDATE prodi SET nama_prodi=?, kode_prodi=?, penjelasan=?, kontak_person=?, email=?, no_telp=?, direct_link=?, fakultas=?, jenjang=?, foto=? WHERE id=?");
        $stmt->bind_param("ssssssssssi", $nama_prodi, $kode_prodi, $penjelasan, $kontak_person, $email, $no_telp, $direct_link, $fakultas, $jenjang, $foto_update, $id);
    } else {
        $stmt = $koneksi->prepare("UPDATE prodi SET nama_prodi=?, kode_prodi=?, penjelasan=?, kontak_person=?, email=?, no_telp=?, direct_link=?, fakultas=?, jenjang=? WHERE id=?");
        $stmt->bind_param("sssssssssi", $nama_prodi, $kode_prodi, $penjelasan, $kontak_person, $email, $no_telp, $direct_link, $fakultas, $jenjang, $id);
    }
    $stmt->execute();
    $stmt->close();
    $redirect = "prodi.php?success=updated";
    if ($uploadError !== '') {
        $redirect .= "&upload_error=1&reason=" . urlencode($uploadError);
    }
    header("Location: " . $redirect);
    exit;
}

if (isset($_GET['toggle'])) {
    $id = intval($_GET['toggle']);
    $status = intval($_GET['status']);
    
    if ($id > 0 && ($status == 0 || $status == 1)) {
        $stmt = $koneksi->prepare("UPDATE prodi SET status_aktif=? WHERE id=?");
        $stmt->bind_param("ii", $status, $id);
        $stmt->execute();
        $stmt->close();
    }
    
    header("Location: prodi.php");
    exit;
}

if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    
    if ($id > 0) {
        // Delete photo if exists
        $stmt = $koneksi->prepare("SELECT foto FROM prodi WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $prodi = $result->fetch_assoc();
        $stmt->close();
        
        if ($prodi && $prodi['foto']) {
            $foto_path = '../uploads/prodi/' . $prodi['foto'];
            if (file_exists($foto_path)) {
                unlink($foto_path);
            }
        }
        
        $stmt = $koneksi->prepare("DELETE FROM prodi WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();
    }
    
    header("Location: prodi.php?success=deleted");
    exit;
}

// Get prodi
$prodi_list = $koneksi->query("SELECT * FROM prodi ORDER BY fakultas, nama_prodi");
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Program Studi - E-Recepsionis System</title>
    <script>
        window.originalPageTitle = 'Program Studi - E-Recepsionis System';
    </script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <?php include 'include_staff_call_head.php'; ?>
    <style>
        .table img.img-thumbnail {
            border-radius: 8px;
        }
        .table td {
            vertical-align: middle;
        }
        .modal-body hr {
            margin: 1.5rem 0;
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
                    <h2 class="mb-0"><i class="bi bi-mortarboard"></i> Program Studi</h2>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
                        <i class="bi bi-plus-circle"></i> Tambah Prodi
                    </button>
                </div>

                <?php if (isset($_GET['success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="bi bi-check-circle"></i> 
                        <?php
                        if ($_GET['success'] == 'added') echo 'Program studi berhasil ditambahkan';
                        elseif ($_GET['success'] == 'updated') echo 'Program studi berhasil diupdate';
                        elseif ($_GET['success'] == 'deleted') echo 'Program studi berhasil dihapus';
                        ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (isset($_GET['upload_error']) && (string) $_GET['upload_error'] === '1'): ?>
                    <div class="alert alert-warning alert-dismissible fade show">
                        <i class="bi bi-exclamation-triangle"></i>
                        Upload foto tidak tersimpan. <?php if (!empty($_GET['reason'])): ?>
                            <?= htmlspecialchars((string) $_GET['reason']) ?>
                        <?php else: ?>
                            Periksa izin folder `uploads/prodi`.
                        <?php endif; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (isset($_GET['error']) && (string) $_GET['error'] === 'upload_failed'): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="bi bi-x-circle"></i>
                        <?= !empty($_GET['reason']) ? htmlspecialchars((string) $_GET['reason']) : 'Upload foto gagal.' ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Prodi Table -->
                <div class="card">
                    <div class="card-header">
                        <i class="bi bi-list"></i> Daftar Program Studi
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th width="80">Foto</th>
                                        <th>Kode</th>
                                        <th>Nama Prodi</th>
                                        <th>Fakultas</th>
                                        <th>Jenjang</th>
                                        <th>Kontak</th>
                                        <th>Direct Link</th>
                                        <th>Status</th>
                                        <th width="150">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($prodi_list && $prodi_list->num_rows > 0): ?>
                                        <?php while ($prodi = $prodi_list->fetch_assoc()): ?>
                                            <tr>
                                                <td>
                                                    <?php if ($prodi['foto'] && file_exists('../uploads/prodi/' . $prodi['foto'])): ?>
                                                        <img src="../uploads/prodi/<?= htmlspecialchars($prodi['foto']) ?>" 
                                                             alt="<?= htmlspecialchars($prodi['nama_prodi']) ?>"
                                                             class="img-thumbnail" 
                                                             style="width: 60px; height: 60px; object-fit: cover;">
                                                    <?php else: ?>
                                                        <div class="bg-light d-flex align-items-center justify-content-center" 
                                                             style="width: 60px; height: 60px; border-radius: 4px;">
                                                            <i class="bi bi-mortarboard text-muted"></i>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($prodi['kode_prodi']): ?>
                                                        <span class="badge bg-primary"><?= htmlspecialchars($prodi['kode_prodi']) ?></span>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <strong><?= htmlspecialchars($prodi['nama_prodi']) ?></strong>
                                                    <?php if ($prodi['penjelasan']): ?>
                                                        <br><small class="text-muted"><?= htmlspecialchars(function_exists('mb_substr') ? mb_substr($prodi['penjelasan'], 0, 50) : substr($prodi['penjelasan'], 0, 50)) ?><?= (function_exists('mb_strlen') ? mb_strlen($prodi['penjelasan']) : strlen($prodi['penjelasan'])) > 50 ? '...' : '' ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= htmlspecialchars($prodi['fakultas'] ?: '-') ?></td>
                                                <td>
                                                    <span class="badge bg-info"><?= htmlspecialchars($prodi['jenjang']) ?></span>
                                                </td>
                                                <td>
                                                    <?php if (!empty($prodi['kontak_person']) || !empty($prodi['no_telp']) || !empty($prodi['email'])): ?>
                                                        <?php if (!empty($prodi['kontak_person'])): ?>
                                                            <div><i class="bi bi-person-badge text-muted"></i> <?= htmlspecialchars($prodi['kontak_person']) ?></div>
                                                        <?php endif; ?>
                                                        <?php if (!empty($prodi['no_telp'])): ?>
                                                            <div><i class="bi bi-whatsapp text-muted"></i> <?= htmlspecialchars($prodi['no_telp']) ?></div>
                                                        <?php endif; ?>
                                                        <?php if (!empty($prodi['email'])): ?>
                                                            <div><i class="bi bi-envelope text-muted"></i> <?= htmlspecialchars($prodi['email']) ?></div>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span class="text-muted">Belum diisi</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($prodi['direct_link']): ?>
                                                        <small>
                                                            <a href="<?= htmlspecialchars($prodi['direct_link']) ?>" target="_blank" class="text-decoration-none">
                                                                <i class="bi bi-link-45deg"></i> 
                                                                <?= htmlspecialchars(parse_url($prodi['direct_link'], PHP_URL_HOST) ?: 'Link') ?>
                                                            </a>
                                                        </small>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($prodi['status_aktif']): ?>
                                                        <span class="badge bg-success">Aktif</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary">Nonaktif</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="btn-group btn-group-sm">
                                                        <button class="btn btn-primary" 
                                                                data-bs-toggle="modal" 
                                                                data-bs-target="#editModal<?= $prodi['id'] ?>"
                                                                title="Edit">
                                                            <i class="bi bi-pencil"></i>
                                                        </button>
                                                        <a href="?toggle=<?= $prodi['id'] ?>&status=<?= $prodi['status_aktif'] ? 0 : 1 ?>" 
                                                           class="btn btn-<?= $prodi['status_aktif'] ? 'warning' : 'success' ?>"
                                                           title="<?= $prodi['status_aktif'] ? 'Nonaktifkan' : 'Aktifkan' ?>">
                                                            <i class="bi bi-<?= $prodi['status_aktif'] ? 'x-circle' : 'check-circle' ?>"></i>
                                                        </a>
                                                        <a href="?delete=<?= $prodi['id'] ?>" 
                                                           class="btn btn-danger"
                                                           onclick="return confirm('Hapus program studi ini?')"
                                                           title="Hapus">
                                                            <i class="bi bi-trash"></i>
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>

                                            <!-- Edit Modal -->
                                            <div class="modal fade" id="editModal<?= $prodi['id'] ?>" tabindex="-1">
                                                <div class="modal-dialog">
                                                    <div class="modal-content">
                                                        <div class="modal-header">
                                                            <h5 class="modal-title">Edit Program Studi</h5>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                        </div>
                                                        <form method="POST" enctype="multipart/form-data">
                                                            <input type="hidden" name="id" value="<?= $prodi['id'] ?>">
                                                            <div class="modal-body">
                                                                <div class="mb-3">
                                                                    <label class="form-label">Foto Prodi</label>
                                                                    <?php if ($prodi['foto'] && file_exists('../uploads/prodi/' . $prodi['foto'])): ?>
                                                                        <div class="mb-2">
                                                                            <img src="../uploads/prodi/<?= htmlspecialchars($prodi['foto']) ?>" 
                                                                                 alt="Foto saat ini" 
                                                                                 class="img-thumbnail" 
                                                                                 style="max-width: 200px; max-height: 200px; object-fit: cover;">
                                                                            <br><small class="text-muted">Foto saat ini: <?= htmlspecialchars($prodi['foto']) ?></small>
                                                                        </div>
                                                                    <?php endif; ?>
                                                                    <input type="file" name="foto" class="form-control" accept="image/*">
                                                                    <small class="text-muted">Format: JPG, PNG, GIF, WebP (Maks. 5MB). Kosongkan jika tidak ingin mengubah foto.</small>
                                                                </div>
                                                                <div class="mb-3">
                                                                    <label class="form-label">Nama Prodi *</label>
                                                                    <input type="text" name="nama_prodi" class="form-control" 
                                                                           value="<?= htmlspecialchars($prodi['nama_prodi']) ?>" required>
                                                                    <small class="text-muted">Nama lengkap program studi yang akan ditampilkan di carousel visitor</small>
                                                                </div>
                                                                <div class="row">
                                                                    <div class="col-md-6 mb-3">
                                                                        <label class="form-label">Kode Prodi</label>
                                                                        <input type="text" name="kode_prodi" class="form-control" 
                                                                               value="<?= htmlspecialchars($prodi['kode_prodi']) ?>"
                                                                               placeholder="Contoh: MTI, TI, SI">
                                                                        <small class="text-muted">Akan ditampilkan sebagai badge di carousel</small>
                                                                    </div>
                                                                    <div class="col-md-6 mb-3">
                                                                        <label class="form-label">Jenjang *</label>
                                                                        <select name="jenjang" class="form-select" required>
                                                                            <option value="D3" <?= $prodi['jenjang'] == 'D3' ? 'selected' : '' ?>>D3</option>
                                                                            <option value="S1" <?= $prodi['jenjang'] == 'S1' ? 'selected' : '' ?>>S1</option>
                                                                            <option value="S2" <?= $prodi['jenjang'] == 'S2' ? 'selected' : '' ?>>S2</option>
                                                                            <option value="S3" <?= $prodi['jenjang'] == 'S3' ? 'selected' : '' ?>>S3</option>
                                                                        </select>
                                                                        <small class="text-muted">Akan ditampilkan sebagai badge di carousel</small>
                                                                    </div>
                                                                </div>
                                                                <div class="mb-3">
                                                                    <label class="form-label">Fakultas</label>
                                                                    <input type="text" name="fakultas" class="form-control" 
                                                                           value="<?= htmlspecialchars($prodi['fakultas']) ?>"
                                                                           placeholder="Contoh: Fakultas Teknik">
                                                                    <small class="text-muted">Akan ditampilkan sebagai badge di carousel</small>
                                                                </div>
                                                                <div class="mb-3">
                                                                    <label class="form-label">Penjelasan / Deskripsi</label>
                                                                    <textarea name="penjelasan" class="form-control" rows="5" 
                                                                              placeholder="Deskripsi lengkap program studi..."><?= htmlspecialchars($prodi['penjelasan']) ?></textarea>
                                                                    <small class="text-muted">Deskripsi yang akan ditampilkan di carousel visitor. Gunakan untuk menjelaskan program studi secara detail.</small>
                                                                </div>
                                                                <hr>
                                                                <h6 class="mb-3"><i class="bi bi-person-lines-fill"></i> Kontak untuk Calon Mahasiswa</h6>
                                                                <div class="mb-3">
                                                                    <label class="form-label">Nama Kontak Person</label>
                                                                    <input type="text" name="kontak_person" class="form-control"
                                                                           value="<?= htmlspecialchars($prodi['kontak_person'] ?? '') ?>"
                                                                           placeholder="Contoh: Dewi Sartika, S.Kom., M.Kom.">
                                                                    <small class="text-muted">Nama PIC yang dapat dihubungi calon mahasiswa.</small>
                                                                </div>
                                                                <div class="row">
                                                                    <div class="col-md-6 mb-3">
                                                                        <label class="form-label">No. WhatsApp</label>
                                                                        <input type="text" name="no_telp" class="form-control"
                                                                               value="<?= htmlspecialchars($prodi['no_telp'] ?? '') ?>"
                                                                               placeholder="Contoh: 081234567890">
                                                                        <small class="text-muted">Nomor WhatsApp yang dapat dihubungi calon mahasiswa.</small>
                                                                    </div>
                                                                    <div class="col-md-6 mb-3">
                                                                        <label class="form-label">Email</label>
                                                                        <input type="email" name="email" class="form-control"
                                                                               value="<?= htmlspecialchars($prodi['email'] ?? '') ?>"
                                                                               placeholder="Contoh: prodi@kampus.ac.id">
                                                                    </div>
                                                                </div>
                                                                <hr>
                                                                <h6 class="mb-3"><i class="bi bi-link-45deg"></i> Link Direct (Opsional - untuk QR Code)</h6>
                                                                <div class="mb-3">
                                                                    <label class="form-label">Link Direct</label>
                                                                    <input type="url" name="direct_link" class="form-control" 
                                                                           value="<?= htmlspecialchars($prodi['direct_link']) ?>"
                                                                           placeholder="https://example.com/halaman-informasi">
                                                                    <small class="text-muted">Link yang akan disertakan dalam QR Code. Gunakan format lengkap dengan https://</small>
                                                                </div>
                                                            </div>
                                                            <div class="modal-footer">
                                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                                                                <button type="submit" name="edit_prodi" class="btn btn-primary">Simpan</button>
                                                            </div>
                                                        </form>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="9" class="text-center text-muted py-4">
                                                <i class="bi bi-inbox" style="font-size: 3rem;"></i><br>
                                                Belum ada program studi
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
                    <h5 class="modal-title">Tambah Program Studi</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Foto Prodi *</label>
                            <input type="file" name="foto" class="form-control" accept="image/*" required>
                            <small class="text-muted">Format: JPG, PNG, GIF, WebP (Maks. 5MB). Foto akan ditampilkan di carousel visitor.</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Nama Prodi *</label>
                            <input type="text" name="nama_prodi" class="form-control" required
                                   placeholder="Contoh: Magister Teknik Informatika">
                            <small class="text-muted">Nama lengkap program studi yang akan ditampilkan di carousel visitor</small>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Kode Prodi</label>
                                <input type="text" name="kode_prodi" class="form-control"
                                       placeholder="Contoh: MTI, TI, SI">
                                <small class="text-muted">Akan ditampilkan sebagai badge di carousel</small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Jenjang *</label>
                                <select name="jenjang" class="form-select" required>
                                    <option value="D3">D3</option>
                                    <option value="S1" selected>S1</option>
                                    <option value="S2">S2</option>
                                    <option value="S3">S3</option>
                                </select>
                                <small class="text-muted">Akan ditampilkan sebagai badge di carousel</small>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Fakultas</label>
                            <input type="text" name="fakultas" class="form-control"
                                   placeholder="Contoh: Fakultas Teknik">
                            <small class="text-muted">Akan ditampilkan sebagai badge di carousel</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Penjelasan / Deskripsi *</label>
                            <textarea name="penjelasan" class="form-control" rows="5" required
                                      placeholder="Deskripsi lengkap program studi..."></textarea>
                            <small class="text-muted">Deskripsi yang akan ditampilkan di carousel visitor. Gunakan untuk menjelaskan program studi secara detail.</small>
                        </div>
                        <hr>
                        <h6 class="mb-3"><i class="bi bi-person-lines-fill"></i> Kontak untuk Calon Mahasiswa</h6>
                        <div class="mb-3">
                            <label class="form-label">Nama Kontak Person</label>
                            <input type="text" name="kontak_person" class="form-control"
                                   placeholder="Contoh: Dewi Sartika, S.Kom., M.Kom.">
                            <small class="text-muted">Nama PIC yang dapat dihubungi calon mahasiswa.</small>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">No. WhatsApp</label>
                                <input type="text" name="no_telp" class="form-control"
                                       placeholder="Contoh: 081234567890">
                                <small class="text-muted">Nomor WhatsApp yang dapat dihubungi calon mahasiswa.</small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" name="email" class="form-control"
                                       placeholder="Contoh: prodi@kampus.ac.id">
                            </div>
                        </div>
                        <hr>
                        <h6 class="mb-3"><i class="bi bi-link-45deg"></i> Link Direct (Opsional - untuk QR Code)</h6>
                        <div class="mb-3">
                            <label class="form-label">Link Direct</label>
                            <input type="url" name="direct_link" class="form-control"
                                   placeholder="https://example.com/halaman-informasi">
                            <small class="text-muted">Link yang akan disertakan dalam QR Code. Gunakan format lengkap dengan https://</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" name="tambah_prodi" class="btn btn-primary">Simpan</button>
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

<?php
require_once 'auth.php';
require_once '../staff_call_routing.php';
requireSuperAdminPage();

$upload_dir = __DIR__ . '/../uploads/floor_plans/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

function floor_plan_image_url(string $path): string
{
    if (preg_match('~^(https?://|/)~', $path)) {
        return $path;
    }

    return '../' . ltrim($path, '/');
}

function floor_plan_redirect(string $flag): void
{
    header('Location: floor_plans.php?' . $flag);
    exit;
}

function floor_plan_get_room(mysqli $koneksi, int $roomId): ?array
{
    if ($roomId <= 0) {
        return null;
    }
    $stmt = $koneksi->prepare(
        "SELECT id, nama_ruangan, kode_ruangan, gedung, lantai
         FROM rooms
         WHERE id = ? AND status_aktif = 1
         LIMIT 1"
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $roomId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function floor_plan_validate_upload(array $file, bool $required): ?string
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        return $required ? 'no_image' : null;
    }
    if ($error !== UPLOAD_ERR_OK) {
        return 'upload_failed';
    }

    $imageInfo = @getimagesize($file['tmp_name'] ?? '');
    $mime = strtolower((string) ($imageInfo['mime'] ?? ''));
    $allowed = ['image/jpeg' => true, 'image/png' => true, 'image/gif' => true, 'image/webp' => true];
    if ($imageInfo === false || !isset($allowed[$mime])) {
        return 'invalid_image';
    }
    if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
        return 'file_too_large';
    }

    return null;
}

function floor_plan_store_upload(array $file, string $uploadDir, string $prefix): ?string
{
    $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    if ($ext === '') {
        $ext = 'jpg';
    }
    $safe = $prefix . '_' . time() . '_' . random_int(1000, 9999) . '.' . $ext;
    $dest = rtrim($uploadDir, '/\\') . '/' . $safe;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return null;
    }

    return 'uploads/floor_plans/' . $safe;
}

$hasRoomIdCol = recepsionis_column_exists($koneksi, 'floor_plans', 'room_id');

if (isset($_POST['tambah_denah'])) {
    if (!$hasRoomIdCol) {
        floor_plan_redirect('error=need_migrate');
    }

    $roomId = (int) ($_POST['room_id'] ?? 0);
    $room = floor_plan_get_room($koneksi, $roomId);
    if (!$room) {
        floor_plan_redirect('error=room_required');
    }

    $uploadError = floor_plan_validate_upload($_FILES['gambar'] ?? [], true);
    if ($uploadError !== null) {
        floor_plan_redirect('error=' . $uploadError);
    }

    $gambar = floor_plan_store_upload($_FILES['gambar'], $upload_dir, 'fp_room_' . $roomId);
    if ($gambar === null) {
        floor_plan_redirect('error=upload_failed');
    }

    $gedung = trim((string) ($room['gedung'] ?? '')) !== '' ? trim((string) $room['gedung']) : '-';
    $lantai = trim((string) ($room['lantai'] ?? '')) !== '' ? trim((string) $room['lantai']) : '-';

    $stmt = $koneksi->prepare(
        'INSERT INTO floor_plans (room_id, gedung, lantai, gambar) VALUES (?, ?, ?, ?)'
    );
    $stmt->bind_param('isss', $roomId, $gedung, $lantai, $gambar);
    if (!$stmt->execute()) {
        @unlink(__DIR__ . '/../' . $gambar);
        $stmt->close();
        floor_plan_redirect('error=duplicate_room');
    }
    $stmt->close();
    floor_plan_redirect('success=added');
}

if (isset($_POST['update_denah'])) {
    if (!$hasRoomIdCol) {
        floor_plan_redirect('error=need_migrate');
    }

    $id = (int) ($_POST['id'] ?? 0);
    $roomId = (int) ($_POST['room_id'] ?? 0);
    $room = floor_plan_get_room($koneksi, $roomId);
    if ($id <= 0 || !$room) {
        floor_plan_redirect('error=invalid');
    }

    $res = $koneksi->query('SELECT gambar FROM floor_plans WHERE id = ' . $id . ' LIMIT 1');
    if (!$res || $res->num_rows === 0) {
        floor_plan_redirect('error=not_found');
    }
    $row = $res->fetch_assoc();
    $gambar = (string) $row['gambar'];

    $uploadError = floor_plan_validate_upload($_FILES['gambar'] ?? [], false);
    if ($uploadError !== null) {
        floor_plan_redirect('error=' . $uploadError);
    }
    if (isset($_FILES['gambar']) && (int) ($_FILES['gambar']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $newGambar = floor_plan_store_upload($_FILES['gambar'], $upload_dir, 'fp_' . $id);
        if ($newGambar !== null) {
            $oldPath = __DIR__ . '/../' . $gambar;
            if (is_file($oldPath)) {
                @unlink($oldPath);
            }
            $gambar = $newGambar;
        }
    }

    $gedung = trim((string) ($room['gedung'] ?? '')) !== '' ? trim((string) $room['gedung']) : '-';
    $lantai = trim((string) ($room['lantai'] ?? '')) !== '' ? trim((string) $room['lantai']) : '-';

    $stmt = $koneksi->prepare(
        'UPDATE floor_plans SET room_id = ?, gedung = ?, lantai = ?, gambar = ? WHERE id = ?'
    );
    $stmt->bind_param('isssi', $roomId, $gedung, $lantai, $gambar, $id);
    if (!$stmt->execute()) {
        $stmt->close();
        floor_plan_redirect('error=duplicate_room');
    }
    $stmt->close();
    header('Location: floor_plans.php?edit=' . $id . '&success=updated');
    exit;
}

if (isset($_GET['delete'])) {
    $id = (int) $_GET['delete'];
    if ($id > 0) {
        $res = $koneksi->query('SELECT gambar FROM floor_plans WHERE id = ' . $id . ' LIMIT 1');
        if ($res && $res->num_rows > 0) {
            $row = $res->fetch_assoc();
            $oldPath = __DIR__ . '/../' . $row['gambar'];
            if (is_file($oldPath)) {
                @unlink($oldPath);
            }
        }
        $koneksi->query('DELETE FROM floor_plans WHERE id = ' . $id);
    }
    floor_plan_redirect('success=deleted');
}

$edit_id = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$edit_plan = null;
if ($edit_id > 0) {
    $res = $koneksi->query('SELECT * FROM floor_plans WHERE id = ' . $edit_id . ' LIMIT 1');
    if ($res && $res->num_rows > 0) {
        $edit_plan = $res->fetch_assoc();
    }
}

if ($hasRoomIdCol) {
    $plans_list = $koneksi->query(
        "SELECT fp.*, r.nama_ruangan, r.kode_ruangan
         FROM floor_plans fp
         LEFT JOIN rooms r ON r.id = fp.room_id
         ORDER BY COALESCE(r.nama_ruangan, fp.gedung), COALESCE(r.kode_ruangan, fp.lantai)"
    );
} else {
    $plans_list = $koneksi->query('SELECT * FROM floor_plans ORDER BY gedung, lantai');
}

$roomsForSelect = [];
$assignedRoomIds = [];
if ($hasRoomIdCol) {
    $assignedRes = $koneksi->query(
        'SELECT room_id FROM floor_plans WHERE room_id IS NOT NULL AND room_id > 0'
    );
    if ($assignedRes) {
        while ($a = $assignedRes->fetch_assoc()) {
            $assignedRoomIds[(int) $a['room_id']] = true;
        }
    }

    $editRoomId = $edit_plan ? (int) ($edit_plan['room_id'] ?? 0) : 0;
    $roomsRes = $koneksi->query(
        "SELECT id, nama_ruangan, kode_ruangan, gedung, lantai
         FROM rooms
         WHERE status_aktif = 1
         ORDER BY nama_ruangan, kode_ruangan"
    );
    if ($roomsRes) {
        while ($r = $roomsRes->fetch_assoc()) {
            $rid = (int) $r['id'];
            // Dropdown: ruangan yang belum punya denah, plus ruangan denah yang sedang diedit
            if (!isset($assignedRoomIds[$rid]) || $rid === $editRoomId) {
                $roomsForSelect[] = $r;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Denah Ruangan - E-Recepsionis</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <?php include 'include_staff_call_head.php'; ?>
    <style>
        .fp-preview img {
            display: block;
            width: 100%;
            height: auto;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
        }
        .fp-thumb {
            height: 48px;
            width: auto;
            border-radius: 6px;
            object-fit: cover;
        }
    </style>
</head>
<body>
    <?php include 'navbar.php'; ?>
    <div class="container-fluid">
        <div class="row">
            <?php include 'sidebar.php'; ?>
            <div class="col-md-10 content-area">
                <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
                    <div>
                        <h2 class="mb-1"><i class="bi bi-map"></i> Denah Ruangan</h2>
                        <p class="text-muted small mb-0">1 denah untuk 1 ruangan. Pilih ruangan saat menambah denah.</p>
                    </div>
                    <?php if ($edit_plan): ?>
                        <a href="floor_plans.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Daftar Denah</a>
                    <?php elseif ($hasRoomIdCol): ?>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addFloorPlanModal" <?= empty($roomsForSelect) ? 'disabled' : '' ?>>
                            <i class="bi bi-plus-circle"></i> Tambah Denah
                        </button>
                    <?php endif; ?>
                </div>

                <?php if (!$hasRoomIdCol): ?>
                    <div class="alert alert-warning">
                        <i class="bi bi-database"></i>
                        Database denah belum siap. Jalankan migrasi:
                        <code>php migrations/ensure_latest_schema.php</code>
                    </div>
                <?php endif; ?>

                <?php if (isset($_GET['success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <?php
                        $s = $_GET['success'];
                        if ($s === 'added') echo 'Denah berhasil diunggah.';
                        elseif ($s === 'updated') echo 'Denah berhasil diperbarui.';
                        elseif ($s === 'deleted') echo 'Denah berhasil dihapus.';
                        else echo 'Berhasil disimpan.';
                        ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (isset($_GET['error'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <?php
                        $e = $_GET['error'];
                        if ($e === 'duplicate_room') echo 'Ruangan ini sudah punya denah. Satu ruangan hanya boleh satu denah.';
                        elseif ($e === 'room_required') echo 'Pilih ruangan terlebih dahulu.';
                        elseif ($e === 'no_image') echo 'Gambar denah wajib diunggah.';
                        elseif ($e === 'invalid_image') echo 'Format gambar tidak didukung (JPG, PNG, WebP, GIF).';
                        elseif ($e === 'file_too_large') echo 'Ukuran file maksimal 5 MB.';
                        elseif ($e === 'need_migrate') echo 'Jalankan migrasi database terlebih dahulu.';
                        else echo 'Terjadi kesalahan. Coba lagi.';
                        ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($edit_plan && $hasRoomIdCol): ?>
                    <div class="card shadow-sm mb-4">
                        <div class="card-header text-white" style="background: linear-gradient(135deg, #0d9488, #0369a1);">
                            <i class="bi bi-pencil-square"></i>
                            Edit Denah
                        </div>
                        <div class="card-body">
                            <form method="post" enctype="multipart/form-data" class="row g-3 mb-4">
                                <input type="hidden" name="update_denah" value="1">
                                <input type="hidden" name="id" value="<?= (int) $edit_plan['id'] ?>">
                                <div class="col-md-8">
                                    <label class="form-label">Ruangan *</label>
                                    <select name="room_id" class="form-select" required>
                                        <option value="">— Pilih ruangan —</option>
                                        <?php foreach ($roomsForSelect as $roomOpt): ?>
                                            <?php
                                            $label = $roomOpt['nama_ruangan'] . ' (' . $roomOpt['kode_ruangan'] . ')';
                                            $meta = trim(($roomOpt['gedung'] ?? '') . ' · Lt ' . ($roomOpt['lantai'] ?? ''));
                                            if ($meta !== '· Lt') {
                                                $label .= ' — ' . $meta;
                                            }
                                            ?>
                                            <option value="<?= (int) $roomOpt['id'] ?>"
                                                <?= (int) ($edit_plan['room_id'] ?? 0) === (int) $roomOpt['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($label) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="text-muted">Gedung &amp; lantai mengikuti data ruangan yang dipilih.</small>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Ganti gambar (opsional)</label>
                                    <input type="file" name="gambar" class="form-control" accept="image/jpeg,image/png,image/webp,image/gif">
                                </div>
                                <div class="col-12">
                                    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-save"></i> Simpan</button>
                                </div>
                            </form>
                            <p class="text-muted small">Pratinjau denah:</p>
                            <div class="fp-preview">
                                <img src="<?= htmlspecialchars(floor_plan_image_url($edit_plan['gambar'])) ?>" alt="Denah">
                            </div>
                        </div>
                    </div>
                <?php elseif ($hasRoomIdCol): ?>
                    <?php if (empty($roomsForSelect)): ?>
                        <div class="alert alert-info">
                            Semua ruangan aktif sudah punya denah, atau belum ada ruangan.
                            Tambah/aktifkan ruangan di menu <a href="rooms.php">Ruangan</a>.
                        </div>
                    <?php endif; ?>

                    <div class="card shadow-sm">
                        <div class="card-header text-white" style="background: linear-gradient(135deg, #2563eb, #0369a1);">
                            <i class="bi bi-list"></i> Daftar Denah
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0 align-middle">
                                    <thead>
                                        <tr>
                                            <th>Ruangan</th>
                                            <th>Kode</th>
                                            <th>Gedung / Lantai</th>
                                            <th>Preview</th>
                                            <th>Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if ($plans_list && $plans_list->num_rows > 0): ?>
                                            <?php while ($fp = $plans_list->fetch_assoc()): ?>
                                                <tr>
                                                    <td>
                                                        <strong><?= htmlspecialchars($fp['nama_ruangan'] ?? 'Ruangan dihapus/nonaktif') ?></strong>
                                                    </td>
                                                    <td><?= htmlspecialchars($fp['kode_ruangan'] ?? '-') ?></td>
                                                    <td>
                                                        <?= htmlspecialchars(($fp['gedung'] ?? '-') . ' / Lt ' . ($fp['lantai'] ?? '-')) ?>
                                                    </td>
                                                    <td>
                                                        <img src="<?= htmlspecialchars(floor_plan_image_url($fp['gambar'])) ?>" alt="" class="fp-thumb">
                                                    </td>
                                                    <td>
                                                        <a href="?edit=<?= (int) $fp['id'] ?>" class="btn btn-sm btn-primary"><i class="bi bi-pencil"></i> Edit</a>
                                                        <a href="?delete=<?= (int) $fp['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Hapus denah ini?')"><i class="bi bi-trash"></i></a>
                                                    </td>
                                                </tr>
                                            <?php endwhile; ?>
                                        <?php else: ?>
                                            <tr><td colspan="5" class="text-center text-muted py-4">Belum ada denah. Klik Tambah Denah.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($hasRoomIdCol): ?>
    <div class="modal fade" id="addFloorPlanModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="tambah_denah" value="1">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-upload"></i> Tambah Denah Ruangan</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Ruangan *</label>
                            <select name="room_id" class="form-select" required>
                                <option value="">— Pilih ruangan —</option>
                                <?php foreach ($roomsForSelect as $roomOpt): ?>
                                    <?php
                                    $label = $roomOpt['nama_ruangan'] . ' (' . $roomOpt['kode_ruangan'] . ')';
                                    $g = trim((string) ($roomOpt['gedung'] ?? ''));
                                    $l = trim((string) ($roomOpt['lantai'] ?? ''));
                                    if ($g !== '' || $l !== '') {
                                        $label .= ' — ' . ($g !== '' ? $g : '-') . ' / Lt ' . ($l !== '' ? $l : '-');
                                    }
                                    ?>
                                    <option value="<?= (int) $roomOpt['id'] ?>"><?= htmlspecialchars($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Satu ruangan hanya boleh punya satu denah.</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Gambar denah *</label>
                            <input type="file" name="gambar" class="form-control" accept="image/jpeg,image/png,image/webp,image/gif" required>
                            <small class="text-muted">JPG/PNG/WebP, maks. 5 MB</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-upload"></i> Unggah</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <?php include 'include_admin_footer.php'; ?>
</body>
</html>

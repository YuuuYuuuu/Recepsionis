<?php
require_once 'auth.php';
require_once '../staff_call_routing.php';
requireSuperAdminPage();

$perangkat_options = ['Smartboard', 'Microphone', 'Kamera', 'Proyektor'];
$tvAllowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

function rooms_tv_redirect(string $flag): void
{
    header('Location: rooms.php?' . $flag . '=1');
    exit;
}

if (isset($_POST['upload_tv_info'])) {
    $roomId = (int) ($_POST['room_id'] ?? 0);
    if ($roomId <= 0 || empty($_FILES['tv_image']['tmp_name'])) {
        rooms_tv_redirect('tv_err');
    }

    $check = $koneksi->prepare('SELECT id, tv_info_image FROM rooms WHERE id = ? LIMIT 1');
    $check->bind_param('i', $roomId);
    $check->execute();
    $roomRow = $check->get_result()->fetch_assoc();
    $check->close();
    if (!$roomRow) {
        rooms_tv_redirect('tv_err');
    }

    $file = $_FILES['tv_image'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        rooms_tv_redirect('tv_err');
    }

    $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $tvAllowedExt, true)) {
        rooms_tv_redirect('tv_err');
    }

    // Validasi gambar tanpa ekstensi fileinfo (sering tidak aktif di VPS)
    $imageInfo = @getimagesize($file['tmp_name']);
    if ($imageInfo === false) {
        rooms_tv_redirect('tv_err');
    }
    $mime = strtolower((string) ($imageInfo['mime'] ?? ''));
    $allowedMimes = [
        'image/jpeg' => true,
        'image/png' => true,
        'image/gif' => true,
        'image/webp' => true,
    ];
    if (!isset($allowedMimes[$mime])) {
        rooms_tv_redirect('tv_err');
    }

    $uploadDir = recepsionis_tv_info_upload_dir();
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        rooms_tv_redirect('tv_err');
    }

    $safeName = 'room_' . $roomId . '_' . time() . '_' . random_int(1000, 9999) . '.' . $ext;
    $dest = $uploadDir . '/' . $safeName;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        rooms_tv_redirect('tv_err');
    }

    $relative = 'uploads/tv_info/' . $safeName;
    recepsionis_delete_room_tv_image_file($roomRow['tv_info_image'] ?? null);
    recepsionis_ensure_room_tv_token($koneksi, $roomId);

    $upd = $koneksi->prepare(
        'UPDATE rooms SET tv_info_image = ?, tv_info_updated_at = NOW() WHERE id = ?'
    );
    $upd->bind_param('si', $relative, $roomId);
    $upd->execute();
    $upd->close();

    rooms_tv_redirect('tv_ok');
}

if (isset($_POST['delete_tv_info'])) {
    $roomId = (int) ($_POST['room_id'] ?? 0);
    $stmt = $koneksi->prepare('SELECT tv_info_image FROM rooms WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $roomId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) {
        recepsionis_delete_room_tv_image_file($row['tv_info_image'] ?? null);
        $upd = $koneksi->prepare(
            'UPDATE rooms SET tv_info_image = NULL, tv_info_updated_at = NOW() WHERE id = ?'
        );
        $upd->bind_param('i', $roomId);
        $upd->execute();
        $upd->close();
    }
    rooms_tv_redirect('tv_deleted');
}

if (isset($_POST['regenerate_tv_token'])) {
    $roomId = (int) ($_POST['room_id'] ?? 0);
    if ($roomId > 0) {
        if (!recepsionis_column_exists($koneksi, 'rooms', 'tv_display_token')) {
            rooms_tv_redirect('tv_need_migrate');
        }
        $token = recepsionis_generate_room_tv_token();
        $upd = $koneksi->prepare(
            'UPDATE rooms SET tv_display_token = ?, tv_info_updated_at = NOW() WHERE id = ?'
        );
        $upd->bind_param('si', $token, $roomId);
        $upd->execute();
        $upd->close();
    }
    rooms_tv_redirect('tv_token');
}

if (isset($_POST['prepare_tv_schema'])) {
    $result = recepsionis_ensure_tv_info_schema($koneksi);
    if (!empty($result['ok'])) {
        rooms_tv_redirect('tv_ready');
    }
    rooms_tv_redirect('tv_need_migrate');
}

$tvSchemaReady = recepsionis_column_exists($koneksi, 'rooms', 'tv_display_token')
    && recepsionis_column_exists($koneksi, 'rooms', 'tv_info_image');

// Handle actions
if (isset($_POST['tambah_ruangan'])) {
    $nama = trim((string) ($_POST['nama_ruangan'] ?? ''));
    $kode = trim((string) ($_POST['kode_ruangan'] ?? ''));
    if ($kode === '') {
        $kode = null;
    }
    $lokasi = trim((string) ($_POST['lokasi'] ?? ''));
    $lantai = trim((string) ($_POST['lantai'] ?? ''));
    $gedung = trim((string) ($_POST['gedung'] ?? ''));
    $kapasitas = (int) ($_POST['kapasitas'] ?? 0);
    $deskripsi = trim((string) ($_POST['deskripsi'] ?? ''));
    $perangkat_raw = $_POST['perangkat_list'] ?? [];
    $perangkat_selected = [];
    if (is_array($perangkat_raw)) {
        foreach ($perangkat_raw as $item) {
            $item = trim((string) $item);
            if (in_array($item, $perangkat_options, true)) {
                $perangkat_selected[] = $item;
            }
        }
    }
    $perangkat = implode("\n", array_values(array_unique($perangkat_selected)));
    $mode_ruangan = trim((string) ($_POST['mode_ruangan'] ?? ''));
    $tvToken = recepsionis_generate_room_tv_token();
    $stmt = $koneksi->prepare(
        'INSERT INTO rooms (nama_ruangan, kode_ruangan, lokasi, lantai, gedung, kapasitas, deskripsi, perangkat, mode_ruangan, tv_display_token)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param(
        'sssssissss',
        $nama,
        $kode,
        $lokasi,
        $lantai,
        $gedung,
        $kapasitas,
        $deskripsi,
        $perangkat,
        $mode_ruangan,
        $tvToken
    );
    $stmt->execute();
    $stmt->close();
    header('Location: rooms.php?success=added');
    exit;
}

if (isset($_POST['edit_ruangan'])) {
    $id = intval($_POST['id']);
    $nama = esc($_POST['nama_ruangan']);
    $kode = trim((string) ($_POST['kode_ruangan'] ?? ''));
    $kodeSql = $kode === '' ? 'NULL' : "'" . $koneksi->real_escape_string($kode) . "'";
    $lokasi = esc($_POST['lokasi']);
    $lantai = esc($_POST['lantai'] ?? '');
    $gedung = esc($_POST['gedung'] ?? '');
    $kapasitas = intval($_POST['kapasitas'] ?? 0);
    $deskripsi = esc($_POST['deskripsi'] ?? '');
    $perangkat_raw = $_POST['perangkat_list'] ?? [];
    $perangkat_selected = [];
    if (is_array($perangkat_raw)) {
        foreach ($perangkat_raw as $item) {
            $item = trim((string)$item);
            if (in_array($item, $perangkat_options, true)) {
                $perangkat_selected[] = $item;
            }
        }
    }
    $perangkat = implode("\n", array_values(array_unique($perangkat_selected)));
    $mode_ruangan = esc($_POST['mode_ruangan'] ?? '');
    $koneksi->query("UPDATE rooms SET nama_ruangan='$nama', kode_ruangan=$kodeSql, lokasi='$lokasi',
                     lantai='$lantai', gedung='$gedung', kapasitas=$kapasitas, deskripsi='$deskripsi', 
                     perangkat='" . $koneksi->real_escape_string($perangkat) . "', mode_ruangan='" . $koneksi->real_escape_string($mode_ruangan) . "' 
                     WHERE id=$id");
    header("Location: rooms.php?success=updated");
    exit;
}

if (isset($_GET['toggle'])) {
    $id = intval($_GET['toggle']);
    $status = intval($_GET['status']);
    $koneksi->query("UPDATE rooms SET status_aktif=$status WHERE id=$id");
    header("Location: rooms.php");
    exit;
}

if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $koneksi->query("DELETE FROM rooms WHERE id=$id");
    header("Location: rooms.php?success=deleted");
    exit;
}

// Get rooms
$rooms = $koneksi->query("SELECT * FROM rooms ORDER BY gedung, lantai, nama_ruangan");
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daftar Ruangan - E-Recepsionis System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <link href="../assets/css/qr-with-logo.css" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script src="../assets/js/qr-with-logo.js"></script>
    <?php require_once '../lib/qr_svg.php'; ?>
    <script>
        window.__QR_LOGO_URL__ = <?= json_encode(recepsionis_qr_logo_url(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        window.__QR_LOGO_ASPECT__ = <?= json_encode(recepsionis_qr_logo_aspect_ratio(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    </script>
    <?php include 'include_staff_call_head.php'; ?>
    <style>
        [id^="tvQr"] canvas,
        [id^="tvQr"] img:not(.qr-logo-mark) { max-width: 160px; height: auto !important; }
    </style>
</head>
<body>
    <?php include 'navbar.php'; ?>

    <div class="container-fluid">
        <div class="row">
            <?php include 'sidebar.php'; ?>

            <div class="col-md-10 content-area">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2><i class="bi bi-door-open"></i> Daftar Ruangan</h2>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
                        <i class="bi bi-plus-circle"></i> Tambah Ruangan
                    </button>
                </div>

                <?php if (isset($_GET['success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="bi bi-check-circle"></i> 
                        <?php
                        if ($_GET['success'] == 'added') echo 'Ruangan berhasil ditambahkan';
                        elseif ($_GET['success'] == 'updated') echo 'Ruangan berhasil diupdate';
                        elseif ($_GET['success'] == 'deleted') echo 'Ruangan berhasil dihapus';
                        ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (isset($_GET['tv_ok'])): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="bi bi-tv"></i> Gambar TV info berhasil disimpan.
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php elseif (isset($_GET['tv_deleted'])): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="bi bi-tv"></i> Gambar TV info dihapus.
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php elseif (isset($_GET['tv_token'])): ?>
                    <div class="alert alert-warning alert-dismissible fade show">
                        <i class="bi bi-arrow-repeat"></i> Token URL TV diganti. Bookmark/QR lama tidak berlaku.
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php elseif (isset($_GET['tv_ready'])): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="bi bi-check-circle"></i> Database TV info siap. Buka ikon TV di ruangan untuk salin URL / QR.
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php elseif (isset($_GET['tv_need_migrate']) || isset($_GET['tv_err'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="bi bi-exclamation-triangle"></i>
                        <?php if (isset($_GET['tv_need_migrate'])): ?>
                            Gagal menyiapkan skema TV. Pastikan file <code>lib/tv_info.php</code> ter-upload, lalu coba lagi.
                        <?php else: ?>
                            Gagal menyimpan gambar TV info. Pastikan file JPG/PNG/GIF/WebP dan folder <code>uploads/tv_info/</code> bisa ditulis.
                        <?php endif; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (!$tvSchemaReady): ?>
                    <div class="alert alert-warning d-flex flex-wrap align-items-center justify-content-between gap-2">
                        <div>
                            <i class="bi bi-database"></i>
                            <strong>Database TV Info belum siap.</strong>
                            Klik tombol di samping untuk membuat kolom &amp; token otomatis (sekali saja).
                        </div>
                        <form method="POST" class="m-0">
                            <button type="submit" name="prepare_tv_schema" value="1" class="btn btn-warning btn-sm">
                                <i class="bi bi-magic"></i> Siapkan Database TV Info
                            </button>
                        </form>
                    </div>
                <?php endif; ?>

                <!-- Rooms Table -->
                <div class="card shadow-sm">
                    <div class="card-header" style="background: linear-gradient(135deg, #2563eb, #0369a1); color: white;">
                        <i class="bi bi-list"></i> Daftar Ruangan
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead>
                                    <tr>
                                        <th>Kode</th>
                                        <th>Nama Ruangan</th>
                                        <th>Lokasi</th>
                                        <th>Gedung</th>
                                        <th>Lantai</th>
                                        <th>Kapasitas</th>
                                        <th>Status</th>
                                        <th>Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($rooms && $rooms->num_rows > 0): ?>
                                        <?php while ($room = $rooms->fetch_assoc()): ?>
                                            <?php
                                            $roomId = (int) $room['id'];
                                            $tvToken = trim((string) ($room['tv_display_token'] ?? ''));
                                            if ($tvToken === '') {
                                                $tvToken = (string) (recepsionis_ensure_room_tv_token($koneksi, $roomId) ?? '');
                                            }
                                            $tvUrl = $tvToken !== '' ? recepsionis_build_room_tv_url($tvToken) : '';
                                            $tvImage = trim((string) ($room['tv_info_image'] ?? ''));
                                            $tvImageUrl = $tvImage !== '' ? recepsionis_room_tv_image_url($tvImage) : '';
                                            ?>
                                            <tr>
                                                <td><strong><?= ($kodeDisplay = trim((string) ($room['kode_ruangan'] ?? ''))) !== '' ? htmlspecialchars($kodeDisplay) : '-' ?></strong></td>
                                                <td>
                                                    <?= htmlspecialchars($room['nama_ruangan']) ?>
                                                    <?php if ($tvImage !== ''): ?>
                                                        <span class="badge bg-info ms-1" title="TV info aktif"><i class="bi bi-tv"></i></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= htmlspecialchars($room['lokasi']) ?></td>
                                                <td><?= htmlspecialchars($room['gedung'] ?? '-') ?></td>
                                                <td><?= htmlspecialchars($room['lantai'] ?? '-') ?></td>
                                                <td><?= $room['kapasitas'] > 0 ? $room['kapasitas'] . ' orang' : '-' ?></td>
                                                <td>
                                                    <?php if ($room['status_aktif']): ?>
                                                        <span class="badge bg-success">Aktif</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary">Nonaktif</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="btn-group btn-group-sm" role="group">
                                                        <button type="button"
                                                                class="btn btn-dark btn-sm"
                                                                data-bs-toggle="modal"
                                                                data-bs-target="#tvModal<?= $roomId ?>"
                                                                title="TV Info Kelas">
                                                            <i class="bi bi-tv"></i>
                                                        </button>
                                                        <a href="room_gallery.php?room_id=<?= $roomId ?>" 
                                                           class="btn btn-secondary btn-sm"
                                                           title="Kelola Gambar">
                                                            <i class="bi bi-images"></i>
                                                        </a>
                                                        <a href="?toggle=<?= $roomId ?>&status=<?= $room['status_aktif'] ? 0 : 1 ?>" 
                                                           class="btn btn-<?= $room['status_aktif'] ? 'warning' : 'success' ?> btn-sm"
                                                           title="<?= $room['status_aktif'] ? 'Nonaktifkan' : 'Aktifkan' ?>">
                                                            <i class="bi bi-<?= $room['status_aktif'] ? 'x-circle' : 'check-circle' ?>"></i>
                                                        </a>
                                                        <button class="btn btn-info btn-sm" 
                                                                data-bs-toggle="modal" 
                                                                data-bs-target="#editModal<?= $roomId ?>"
                                                                title="Edit Ruangan">
                                                            <i class="bi bi-pencil"></i>
                                                        </button>
                                                        <a href="?delete=<?= $roomId ?>" 
                                                           class="btn btn-danger btn-sm"
                                                           onclick="return confirm('Yakin ingin menghapus ruangan <?= htmlspecialchars($room['nama_ruangan'], ENT_QUOTES) ?>?')"
                                                           title="Hapus Ruangan">
                                                            <i class="bi bi-trash"></i>
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>

                                            <!-- TV Info Modal -->
                                            <div class="modal fade" id="tvModal<?= $roomId ?>" tabindex="-1">
                                                <div class="modal-dialog modal-lg">
                                                    <div class="modal-content">
                                                        <div class="modal-header bg-dark text-white">
                                                            <h5 class="modal-title"><i class="bi bi-tv"></i> TV Info — <?= htmlspecialchars($room['nama_ruangan']) ?></h5>
                                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <p class="text-muted small">Satu gambar fullscreen untuk TV di kelas. Buka URL di browser TV, lalu tap sekali untuk masuk fullscreen.</p>
                                                            <div class="row g-3">
                                                                <div class="col-md-6">
                                                                    <div class="border rounded bg-light p-3 text-center mb-3" style="min-height:180px;">
                                                                        <?php if ($tvImageUrl !== ''): ?>
                                                                            <img src="<?= htmlspecialchars($tvImageUrl) ?>" alt="TV info" class="img-fluid rounded" style="max-height:220px;object-fit:contain;">
                                                                        <?php else: ?>
                                                                            <div class="text-muted py-5"><i class="bi bi-image" style="font-size:2rem;"></i><br>Belum ada gambar</div>
                                                                        <?php endif; ?>
                                                                    </div>
                                                                    <form method="POST" enctype="multipart/form-data" class="mb-2">
                                                                        <input type="hidden" name="room_id" value="<?= $roomId ?>">
                                                                        <input type="file" name="tv_image" class="form-control form-control-sm mb-2" accept="image/jpeg,image/png,image/gif,image/webp" required>
                                                                        <button type="submit" name="upload_tv_info" value="1" class="btn btn-primary btn-sm w-100">
                                                                            <i class="bi bi-upload"></i> Upload / Ganti Gambar
                                                                        </button>
                                                                    </form>
                                                                    <?php if ($tvImage !== ''): ?>
                                                                        <form method="POST" onsubmit="return confirm('Hapus gambar TV info?');">
                                                                            <input type="hidden" name="room_id" value="<?= $roomId ?>">
                                                                            <button type="submit" name="delete_tv_info" value="1" class="btn btn-outline-danger btn-sm w-100">
                                                                                <i class="bi bi-trash"></i> Hapus Gambar
                                                                            </button>
                                                                        </form>
                                                                    <?php endif; ?>
                                                                </div>
                                                                <div class="col-md-6">
                                                                    <?php if ($tvUrl !== ''): ?>
                                                                        <div class="d-flex flex-column align-items-center mb-3">
                                                                            <div id="tvQr<?= $roomId ?>" class="p-2 border rounded bg-white" data-tv-url="<?= htmlspecialchars($tvUrl) ?>"></div>
                                                                        </div>
                                                                        <label class="form-label small text-muted">URL TV</label>
                                                                        <div class="input-group input-group-sm mb-2">
                                                                            <input type="text" class="form-control" id="tvUrl<?= $roomId ?>" readonly value="<?= htmlspecialchars($tvUrl) ?>">
                                                                            <button type="button" class="btn btn-outline-secondary" onclick="navigator.clipboard.writeText(document.getElementById('tvUrl<?= $roomId ?>').value)">Salin</button>
                                                                        </div>
                                                                        <a href="<?= htmlspecialchars($tvUrl) ?>" target="_blank" class="btn btn-outline-dark btn-sm w-100 mb-2">
                                                                            <i class="bi bi-box-arrow-up-right"></i> Buka di tab baru
                                                                        </a>
                                                                        <form method="POST" onsubmit="return confirm('Ganti token? URL/QR lama tidak akan berfungsi.');">
                                                                            <input type="hidden" name="room_id" value="<?= $roomId ?>">
                                                                            <button type="submit" name="regenerate_tv_token" value="1" class="btn btn-warning btn-sm w-100">
                                                                                <i class="bi bi-arrow-repeat"></i> Regenerate Token
                                                                            </button>
                                                                        </form>
                                                                    <?php else: ?>
                                                                        <div class="alert alert-warning">
                                                                            <strong>Token belum tersedia.</strong><br>
                                                                            Database TV Info belum disiapkan di server ini.
                                                                        </div>
                                                                        <form method="POST">
                                                                            <button type="submit" name="prepare_tv_schema" value="1" class="btn btn-warning btn-sm w-100">
                                                                                <i class="bi bi-magic"></i> Siapkan Database TV Info
                                                                            </button>
                                                                        </form>
                                                                    <?php endif; ?>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Edit Modal -->
                                            <div class="modal fade" id="editModal<?= $roomId ?>" tabindex="-1">
                                                <div class="modal-dialog">
                                                    <div class="modal-content">
                                                        <div class="modal-header" style="background: linear-gradient(135deg, #2563eb, #0369a1); color: white;">
                                                            <h5 class="modal-title"><i class="bi bi-pencil-square"></i> Edit Ruangan</h5>
                                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                        </div>
                                                        <form method="POST">
                                                            <input type="hidden" name="id" value="<?= $room['id'] ?>">
                                                            <div class="modal-body">
                                                                <div class="mb-3">
                                                                    <label class="form-label">Nama Ruangan *</label>
                                                                    <input type="text" name="nama_ruangan" class="form-control" value="<?= htmlspecialchars($room['nama_ruangan']) ?>" required>
                                                                </div>
                                                                <div class="mb-3">
                                                                    <label class="form-label">Kode Ruangan</label>
                                                                    <input type="text" name="kode_ruangan" class="form-control" value="<?= htmlspecialchars($room['kode_ruangan'] ?? '') ?>">
                                                                </div>
                                                                <div class="mb-3">
                                                                    <label class="form-label">Lokasi *</label>
                                                                    <input type="text" name="lokasi" class="form-control" value="<?= htmlspecialchars($room['lokasi']) ?>" required>
                                                                </div>
                                                                <div class="row">
                                                                    <div class="col-md-6 mb-3">
                                                                        <label class="form-label">Gedung</label>
                                                                        <input type="text" name="gedung" class="form-control" value="<?= htmlspecialchars($room['gedung'] ?? '') ?>">
                                                                    </div>
                                                                    <div class="col-md-6 mb-3">
                                                                        <label class="form-label">Lantai</label>
                                                                        <input type="text" name="lantai" class="form-control" value="<?= htmlspecialchars($room['lantai'] ?? '') ?>">
                                                                    </div>
                                                                </div>
                                                                <div class="mb-3">
                                                                    <label class="form-label">Kapasitas</label>
                                                                    <input type="number" name="kapasitas" class="form-control" value="<?= $room['kapasitas'] ?>" min="0">
                                                                </div>
                                                                <div class="mb-3">
                                                                    <label class="form-label">Deskripsi</label>
                                                                    <textarea name="deskripsi" class="form-control" rows="3"><?= htmlspecialchars($room['deskripsi'] ?? '') ?></textarea>
                                                                </div>
                                                                <?php
                                                                    $perangkat_existing = str_replace(["\\r\\n", "\\n", "\r"], "\n", (string)($room['perangkat'] ?? ''));
                                                                    $perangkat_existing = array_filter(array_map('trim', explode("\n", $perangkat_existing)));
                                                                ?>
                                                                <div class="mb-3">
                                                                    <label class="form-label">Perangkat</label>
                                                                    <div class="border rounded p-3 bg-light">
                                                                        <?php foreach ($perangkat_options as $opt): ?>
                                                                            <div class="form-check">
                                                                                <input class="form-check-input" type="checkbox" name="perangkat_list[]" value="<?= $opt ?>" id="perangkat_<?= $room['id'] ?>_<?= strtolower($opt) ?>"
                                                                                    <?= in_array($opt, $perangkat_existing, true) ? 'checked' : '' ?>>
                                                                                <label class="form-check-label" for="perangkat_<?= $room['id'] ?>_<?= strtolower($opt) ?>">
                                                                                    <?= $opt ?>
                                                                                </label>
                                                                            </div>
                                                                        <?php endforeach; ?>
                                                                    </div>
                                                                </div>
                                                                <div class="mb-3">
                                                                    <label class="form-label">Mode Ruangan</label>
                                                                    <input type="text" name="mode_ruangan" class="form-control" value="<?= htmlspecialchars($room['mode_ruangan'] ?? '') ?>">
                                                                </div>
                                                            </div>
                                                            <div class="modal-footer">
                                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                                                    <i class="bi bi-x-circle"></i> Batal
                                                                </button>
                                                                <button type="submit" name="edit_ruangan" class="btn btn-primary">
                                                                    <i class="bi bi-check-circle"></i> Simpan Perubahan
                                                                </button>
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
                                                Tidak ada ruangan
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
                <div class="modal-header" style="background: linear-gradient(135deg, #2563eb, #0369a1); color: white;">
                    <h5 class="modal-title"><i class="bi bi-door-open"></i> Tambah Ruangan</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Nama Ruangan *</label>
                            <input type="text" name="nama_ruangan" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Kode Ruangan</label>
                            <input type="text" name="kode_ruangan" class="form-control">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Lokasi *</label>
                            <input type="text" name="lokasi" class="form-control" required>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Gedung</label>
                                <input type="text" name="gedung" class="form-control">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Lantai</label>
                                <input type="text" name="lantai" class="form-control">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Kapasitas</label>
                            <input type="number" name="kapasitas" class="form-control" min="0">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Deskripsi</label>
                            <textarea name="deskripsi" class="form-control" rows="3"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Perangkat</label>
                            <div class="border rounded p-3 bg-light">
                                <?php foreach ($perangkat_options as $opt): ?>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="perangkat_list[]" value="<?= $opt ?>" id="add_perangkat_<?= strtolower($opt) ?>">
                                        <label class="form-check-label" for="add_perangkat_<?= strtolower($opt) ?>">
                                            <?= $opt ?>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Mode Ruangan</label>
                            <input type="text" name="mode_ruangan" class="form-control">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="bi bi-x-circle"></i> Batal
                        </button>
                        <button type="submit" name="tambah_ruangan" class="btn btn-primary">
                            <i class="bi bi-plus-circle"></i> Tambah Ruangan
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/notification-badge.js"></script>
    <script>
    document.querySelectorAll('[id^="tvQr"]').forEach(function (el) {
        var url = el.getAttribute('data-tv-url');
        if (!url || typeof recepsionisRenderQrWithLogo !== 'function') return;
        recepsionisRenderQrWithLogo(el, url, 148);
    });
    </script>
    <?php include 'include_staff_call_footer.php'; ?>
</body>
</html>

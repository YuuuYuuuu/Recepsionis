<?php
require_once 'auth.php';
require_once '../lib/branding.php';
requireSuperAdminPage();

$branding = recepsionis_get_visitor_branding($koneksi);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['reset_logo'])) {
        $oldLogo = trim(recepsionis_get_setting($koneksi, 'visitor_logo', ''));
        if ($oldLogo !== '') {
            recepsionis_delete_visitor_logo_file($oldLogo);
        }
        recepsionis_save_setting($koneksi, 'visitor_logo', '');
        header('Location: visitor_branding.php?success=logo_reset');
        exit;
    }

    $textFields = [
        'site_name',
        'visitor_logo_alt',
        'visitor_welcome_title',
        'visitor_service_rooms_title',
        'visitor_service_rooms_desc',
        'visitor_service_rooms_cta',
        'visitor_service_prodi_title',
        'visitor_service_prodi_desc',
        'visitor_service_prodi_cta',
        'visitor_service_staff_title',
        'visitor_service_staff_desc',
        'visitor_service_staff_cta',
    ];

    foreach ($textFields as $field) {
        $value = trim((string) ($_POST[$field] ?? ''));
        if ($value === '' && in_array($field, ['site_name', 'visitor_welcome_title'], true)) {
            $errors[] = 'Field wajib tidak boleh kosong: ' . $field;
            continue;
        }
        recepsionis_save_setting($koneksi, $field, $value);
    }

    if (!empty($_FILES['visitor_logo']['name'])) {
        $upload = recepsionis_handle_visitor_logo_upload($_FILES['visitor_logo']);
        if ($upload['ok'] && !empty($upload['path'])) {
            $oldLogo = trim(recepsionis_get_setting($koneksi, 'visitor_logo', ''));
            if ($oldLogo !== '') {
                recepsionis_delete_visitor_logo_file($oldLogo);
            }
            recepsionis_save_setting($koneksi, 'visitor_logo', $upload['path']);
        } elseif (($upload['error'] ?? '') !== 'no_file') {
            $errorMap = [
                'too_large' => 'Logo terlalu besar (maks. 2 MB).',
                'invalid_type' => 'Format logo harus JPG, PNG, WebP, atau SVG.',
                'upload_error' => 'Gagal mengunggah logo.',
                'move_failed' => 'Gagal menyimpan file logo.',
                'mkdir_failed' => 'Folder upload tidak dapat dibuat.',
            ];
            $errors[] = $errorMap[$upload['error'] ?? ''] ?? 'Gagal mengunggah logo.';
        }
    }

    if ($errors) {
        $branding = recepsionis_get_visitor_branding($koneksi);
    } else {
        header('Location: visitor_branding.php?success=saved');
        exit;
    }
}

$branding = recepsionis_get_visitor_branding($koneksi);
$visitorPreviewUrl = rtrim(BASE_URL, '/') . '/visitor/index.php';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Branding Halaman Pengunjung - E-Recepsionis</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <?php include 'include_staff_call_head.php'; ?>
    <style>
        .branding-preview {
            background: linear-gradient(160deg, #0b1220 0%, #111827 55%, #0f172a 100%);
            border-radius: 20px;
            padding: 1.75rem;
            color: #e2e8f0;
            border: 1px solid rgba(148, 163, 184, 0.2);
        }
        .branding-preview-logo {
            max-height: 72px;
            max-width: 100%;
            object-fit: contain;
        }
        .branding-preview-tag {
            font-size: 0.8rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #7dd3fc;
            margin-bottom: 0.35rem;
        }
        .branding-preview-title {
            font-size: 1.75rem;
            font-weight: 700;
            margin: 0;
            color: #f8fafc;
        }
        .branding-preview-card {
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 14px;
            padding: 1rem;
        }
        .branding-preview-card h6 {
            margin: 0 0 0.35rem;
            color: #f8fafc;
            font-weight: 600;
        }
        .branding-preview-card p {
            margin: 0;
            font-size: 0.85rem;
            color: #94a3b8;
        }
        .branding-preview-cta {
            display: inline-block;
            margin-top: 0.65rem;
            font-size: 0.75rem;
            color: #38bdf8;
        }
        .current-logo-box {
            background: #f8fafc;
            border: 1px dashed #cbd5e1;
            border-radius: 12px;
            padding: 1rem;
            text-align: center;
            min-height: 120px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .current-logo-box img {
            max-height: 80px;
            max-width: 100%;
            object-fit: contain;
        }
    </style>
</head>
<body>
    <?php include 'navbar.php'; ?>

    <div class="container-fluid">
        <div class="row">
            <?php include 'sidebar.php'; ?>

            <div class="col-md-10 content-area">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
                    <h2 class="mb-0"><i class="bi bi-palette"></i> Branding Halaman Pengunjung</h2>
                    <a href="<?= htmlspecialchars($visitorPreviewUrl) ?>" target="_blank" rel="noopener" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-box-arrow-up-right"></i> Lihat Halaman Pengunjung
                    </a>
                </div>

                <?php if (isset($_GET['success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="bi bi-check-circle"></i>
                        <?php if ($_GET['success'] === 'logo_reset'): ?>
                            Logo dikembalikan ke default.
                        <?php else: ?>
                            Pengaturan branding berhasil disimpan.
                        <?php endif; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($errors): ?>
                    <div class="alert alert-danger">
                        <ul class="mb-0">
                            <?php foreach ($errors as $err): ?>
                                <li><?= htmlspecialchars($err) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <form method="POST" enctype="multipart/form-data">
                    <div class="row g-4">
                        <div class="col-lg-7">
                            <div class="card shadow-sm mb-4">
                                <div class="card-header">
                                    <i class="bi bi-image"></i> Logo
                                </div>
                                <div class="card-body">
                                    <div class="current-logo-box mb-3">
                                        <img src="<?= htmlspecialchars($branding['logo_url']) ?>" alt="Logo saat ini" id="currentLogoPreview">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label" for="visitor_logo">Unggah Logo Baru</label>
                                        <input type="file" class="form-control" name="visitor_logo" id="visitor_logo" accept="image/jpeg,image/png,image/webp,image/svg+xml">
                                        <small class="text-muted">Format: JPG, PNG, WebP, SVG. Maks. 2 MB. Kosongkan jika tidak ingin mengganti.</small>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label" for="visitor_logo_alt">Teks Alt Logo</label>
                                        <input type="text" class="form-control" name="visitor_logo_alt" id="visitor_logo_alt"
                                               value="<?= htmlspecialchars($branding['logo_alt']) ?>"
                                               placeholder="Contoh: Logo Kampus ABC">
                                    </div>
                                    <button type="submit" name="reset_logo" value="1" class="btn btn-outline-secondary btn-sm"
                                            onclick="return confirm('Kembalikan logo ke default?');">
                                        <i class="bi bi-arrow-counterclockwise"></i> Reset ke Logo Default
                                    </button>
                                </div>
                            </div>

                            <div class="card shadow-sm mb-4">
                                <div class="card-header">
                                    <i class="bi bi-type"></i> Teks Hero (Bagian Atas)
                                </div>
                                <div class="card-body">
                                    <div class="mb-3">
                                        <label class="form-label" for="site_name">Nama Institusi / Sistem</label>
                                        <input type="text" class="form-control preview-input" name="site_name" id="site_name"
                                               data-preview="siteName"
                                               value="<?= htmlspecialchars($branding['site_name']) ?>" required>
                                        <small class="text-muted">Ditampilkan sebagai label kecil di atas judul utama.</small>
                                    </div>
                                    <div class="mb-0">
                                        <label class="form-label" for="visitor_welcome_title">Judul Utama</label>
                                        <input type="text" class="form-control preview-input" name="visitor_welcome_title" id="visitor_welcome_title"
                                               data-preview="welcomeTitle"
                                               value="<?= htmlspecialchars($branding['welcome_title']) ?>" required>
                                        <small class="text-muted">Contoh: Selamat Datang, Halo Pengunjung, dll.</small>
                                    </div>
                                </div>
                            </div>

                            <div class="card shadow-sm mb-4">
                                <div class="card-header">
                                    <i class="bi bi-grid"></i> Kartu Layanan
                                </div>
                                <div class="card-body">
                                    <?php
                                    $cards = [
                                        'rooms' => ['label' => 'Daftar Ruangan', 'prefix' => 'visitor_service_rooms'],
                                        'prodi' => ['label' => 'Program Studi', 'prefix' => 'visitor_service_prodi'],
                                        'staff' => ['label' => 'Panggil Staff', 'prefix' => 'visitor_service_staff'],
                                    ];
                                    foreach ($cards as $key => $card):
                                        $titleKey = $card['prefix'] . '_title';
                                        $descKey = $card['prefix'] . '_desc';
                                        $ctaKey = $card['prefix'] . '_cta';
                                    ?>
                                        <div class="border rounded p-3 mb-3">
                                            <h6 class="text-primary mb-3"><?= htmlspecialchars($card['label']) ?></h6>
                                            <div class="mb-2">
                                                <label class="form-label">Judul</label>
                                                <input type="text" class="form-control preview-input"
                                                       name="<?= $titleKey ?>" data-preview="<?= $key ?>Title"
                                                       value="<?= htmlspecialchars($branding['services'][$key]['title']) ?>">
                                            </div>
                                            <div class="mb-2">
                                                <label class="form-label">Deskripsi</label>
                                                <textarea class="form-control preview-input" rows="2"
                                                          name="<?= $descKey ?>" data-preview="<?= $key ?>Desc"><?= htmlspecialchars($branding['services'][$key]['description']) ?></textarea>
                                            </div>
                                            <div class="mb-0">
                                                <label class="form-label">Tombol CTA</label>
                                                <input type="text" class="form-control preview-input"
                                                       name="<?= $ctaKey ?>" data-preview="<?= $key ?>Cta"
                                                       value="<?= htmlspecialchars($branding['services'][$key]['cta']) ?>">
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-save"></i> Simpan Branding
                            </button>
                        </div>

                        <div class="col-lg-5">
                            <div class="card shadow-sm sticky-top" style="top: 1rem;">
                                <div class="card-header">
                                    <i class="bi bi-eye"></i> Pratinjau
                                </div>
                                <div class="card-body">
                                    <div class="branding-preview">
                                        <div class="text-center mb-3">
                                            <img src="<?= htmlspecialchars($branding['logo_url']) ?>" alt="" class="branding-preview-logo" id="previewLogo">
                                        </div>
                                        <div class="text-center mb-4">
                                            <p class="branding-preview-tag" id="previewSiteName"><?= htmlspecialchars($branding['site_name']) ?></p>
                                            <h3 class="branding-preview-title" id="previewWelcomeTitle"><?= htmlspecialchars($branding['welcome_title']) ?></h3>
                                        </div>
                                        <div class="d-flex flex-column gap-2">
                                            <div class="branding-preview-card">
                                                <h6 id="previewRoomsTitle"><?= htmlspecialchars($branding['services']['rooms']['title']) ?></h6>
                                                <p id="previewRoomsDesc"><?= htmlspecialchars($branding['services']['rooms']['description']) ?></p>
                                                <span class="branding-preview-cta" id="previewRoomsCta"><?= htmlspecialchars($branding['services']['rooms']['cta']) ?> →</span>
                                            </div>
                                            <div class="branding-preview-card">
                                                <h6 id="previewProdiTitle"><?= htmlspecialchars($branding['services']['prodi']['title']) ?></h6>
                                                <p id="previewProdiDesc"><?= htmlspecialchars($branding['services']['prodi']['description']) ?></p>
                                                <span class="branding-preview-cta" id="previewProdiCta"><?= htmlspecialchars($branding['services']['prodi']['cta']) ?> →</span>
                                            </div>
                                            <div class="branding-preview-card">
                                                <h6 id="previewStaffTitle"><?= htmlspecialchars($branding['services']['staff']['title']) ?></h6>
                                                <p id="previewStaffDesc"><?= htmlspecialchars($branding['services']['staff']['description']) ?></p>
                                                <span class="branding-preview-cta" id="previewStaffCta"><?= htmlspecialchars($branding['services']['staff']['cta']) ?> →</span>
                                            </div>
                                        </div>
                                    </div>
                                    <p class="text-muted small mt-3 mb-0">
                                        Perubahan teks terlihat langsung di pratinjau. Simpan untuk menerapkan ke halaman pengunjung.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/notification-badge.js"></script>
    <?php include 'include_staff_call_footer.php'; ?>
    <script>
    (function () {
        const previewMap = {
            siteName: 'previewSiteName',
            welcomeTitle: 'previewWelcomeTitle',
            roomsTitle: 'previewRoomsTitle',
            roomsDesc: 'previewRoomsDesc',
            roomsCta: 'previewRoomsCta',
            prodiTitle: 'previewProdiTitle',
            prodiDesc: 'previewProdiDesc',
            prodiCta: 'previewProdiCta',
            staffTitle: 'previewStaffTitle',
            staffDesc: 'previewStaffDesc',
            staffCta: 'previewStaffCta',
        };

        document.querySelectorAll('.preview-input').forEach(function (input) {
            input.addEventListener('input', function () {
                const key = input.dataset.preview;
                const target = document.getElementById(previewMap[key]);
                if (!target) return;
                let value = input.value;
                if (key.endsWith('Cta')) {
                    value = value.trim() ? value + ' →' : '';
                }
                target.textContent = value;
            });
        });

        const logoInput = document.getElementById('visitor_logo');
        if (logoInput) {
            logoInput.addEventListener('change', function () {
                const file = logoInput.files && logoInput.files[0];
                if (!file) return;
                const reader = new FileReader();
                reader.onload = function (e) {
                    const url = e.target.result;
                    const previewLogo = document.getElementById('previewLogo');
                    const currentLogo = document.getElementById('currentLogoPreview');
                    if (previewLogo) previewLogo.src = url;
                    if (currentLogo) currentLogo.src = url;
                };
                reader.readAsDataURL(file);
            });
        }
    })();
    </script>
</body>
</html>

<?php
require_once '../config.php';

// Get room ID from URL
$room_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($room_id <= 0) {
    header('Location: index.php');
    exit;
}

// Get room data
$room_query = $koneksi->query("SELECT * FROM rooms WHERE id = " . (int)$room_id . " AND status_aktif = 1 LIMIT 1");
if (!$room_query || $room_query->num_rows === 0) {
    header('Location: index.php');
    exit;
}

$room = $room_query->fetch_assoc();

// Parse images - normalize paths
$images = [];
if (!empty($room['images'])) {
    $images = array_filter(array_map('trim', explode(',', $room['images'])));
    // Normalize paths - add ../ prefix for relative paths
    $images = array_map(function($img) {
        if (!preg_match('~^(https?://|/|\.\./)~', $img)) {
            return '../' . $img;
        }
        return $img;
    }, $images);
}
if (empty($images) && !empty($room['foto'])) {
    $foto = (string)$room['foto'];
    if (!preg_match('~^(https?://|/|\.\./)~', $foto)) {
        $foto = '../' . $foto;
    }
    $images = [$foto];
}
if (empty($images)) {
    $images = ['../assets/images/placeholder.png'];
}

// Parse perangkat/features
$features = [];
if (!empty($room['perangkat'])) {
    $raw_features = str_replace(["\\r\\n", "\\n", "\\r"], "\n", (string)$room['perangkat']);
    $parts = preg_split('/\r\n|\r|\n/', $raw_features);
    $features = array_values(array_filter(array_map('trim', $parts)));
}

$main_image = reset($images);

$floor_plan = null;
$room_gedung = trim((string) ($room['gedung'] ?? ''));
$room_lantai = trim((string) ($room['lantai'] ?? ''));
$room_id_for_fp = (int) ($room['id'] ?? 0);

// Denah ketat 1:1 — hanya tampil jika terikat room_id ruangan ini
if ($room_id_for_fp > 0) {
    $fp_by_room = $koneksi->prepare('SELECT * FROM floor_plans WHERE room_id = ? LIMIT 1');
    if ($fp_by_room) {
        $fp_by_room->bind_param('i', $room_id_for_fp);
        $fp_by_room->execute();
        $fp_res = $fp_by_room->get_result();
        if ($fp_res && $fp_res->num_rows > 0) {
            $floor_plan = $fp_res->fetch_assoc();
        }
        $fp_by_room->close();
    }
}

function visitor_asset_url(string $path): string
{
    if (preg_match('~^(https?://|/|\.\./)~', $path)) {
        return $path;
    }
    return '../' . ltrim($path, '/');
}

$has_floor_plan_image = $floor_plan && !empty($floor_plan['gambar']);
$floor_plan_image_url = $has_floor_plan_image ? visitor_asset_url($floor_plan['gambar']) : '';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($room['nama_ruangan']) ?> - Recepsionis</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Sora:wght@500;600;700&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <link href="assets/landing/assets/visitor-landing.css" rel="stylesheet">
    <link href="../assets/css/visitor-unified.css" rel="stylesheet">
    <link href="../assets/css/location-guide.css" rel="stylesheet">
    <style>
        :root {
            --primary: #2563eb;
            --secondary: #0369a1;
            --success: #48bb78;
            --warning: #f6ad55;
            --info: #4299e1;
        }

        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            padding: 20px 0;
        }

        .hero-section {
            position: relative;
            height: 500px;
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            margin-bottom: 40px;
        }

        .main-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .gallery-nav {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            background: linear-gradient(to top, rgba(0,0,0,0.5), transparent);
            padding: 20px;
            display: flex;
            gap: 10px;
            overflow-x: auto;
            align-items: flex-end;
        }

        .gallery-thumb {
            width: 80px;
            height: 80px;
            border-radius: 8px;
            cursor: pointer;
            border: 3px solid transparent;
            overflow: hidden;
            flex-shrink: 0;
            transition: all 0.3s;
            opacity: 0.7;
        }

        .gallery-thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .gallery-thumb:hover, .gallery-thumb.active {
            opacity: 1;
            border-color: white;
            transform: scale(1.05);
        }

        .gallery-controls {
            position: absolute;
            top: 20px;
            right: 20px;
            display: flex;
            gap: 10px;
            z-index: 10;
        }

        .gallery-btn {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background: rgba(255,255,255,0.9);
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            color: var(--primary);
        }

        .gallery-btn:hover {
            background: white;
            transform: scale(1.1);
            box-shadow: 0 6px 16px rgba(0,0,0,0.2);
        }

        .gallery-btn i {
            font-size: 1.1rem;
        }

        .breadcrumb-custom {
            background: transparent;
            padding: 0;
            margin-bottom: 30px;
        }

        .breadcrumb-custom .breadcrumb-item {
            color: #666;
        }

        .breadcrumb-custom .breadcrumb-item.active {
            color: var(--primary);
            font-weight: 600;
        }

        .room-header {
            margin-bottom: 40px;
        }

        .room-title {
            font-size: 2.5rem;
            font-weight: 700;
            color: #1a202c;
            margin-bottom: 10px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .room-code {
            display: inline-block;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 600;
            margin-bottom: 15px;
        }

        .room-description {
            font-size: 1.05rem;
            color: #4a5568;
            line-height: 1.6;
            margin-bottom: 30px;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }

        .info-card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            text-align: center;
            transition: all 0.3s;
            border-top: 4px solid var(--primary);
        }

        .info-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.12);
        }

        .info-icon {
            font-size: 2rem;
            color: var(--primary);
            margin-bottom: 12px;
        }

        .info-label {
            font-size: 0.85rem;
            color: #999;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }

        .info-value {
            font-size: 1.3rem;
            font-weight: 700;
            color: #1a202c;
        }

        .features-section {
            background: white;
            padding: 40px;
            border-radius: 16px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            margin-bottom: 40px;
        }

        .section-title {
            font-size: 1.8rem;
            font-weight: 700;
            color: #1a202c;
            margin-bottom: 30px;
            padding-bottom: 15px;
            border-bottom: 3px solid var(--primary);
            display: inline-block;
        }

        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
        }

        .feature-badge {
            background: linear-gradient(135deg, #ffffff, #f8fbff);
            padding: 16px 18px;
            border-radius: 14px;
            text-align: left;
            border: 1px solid #dbeafe;
            transition: all 0.3s;
            cursor: default;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .feature-badge:hover {
            background: linear-gradient(135deg, #eff6ff, #ffffff);
            transform: translateY(-3px);
            box-shadow: 0 8px 18px rgba(37, 99, 235, 0.16);
            border-color: #93c5fd;
        }

        .feature-icon {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            color: #1d4ed8;
            background: #dbeafe;
            transition: all 0.3s;
            flex-shrink: 0;
        }

        .feature-name {
            font-weight: 600;
            color: #1a202c;
            font-size: 1rem;
            line-height: 1.4;
        }

        .cta-section {
            display: flex;
            gap: 15px;
            margin-top: 40px;
            flex-wrap: wrap;
        }

        .btn-custom {
            padding: 14px 28px;
            font-size: 1rem;
            font-weight: 600;
            border-radius: 10px;
            border: none;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }

        .btn-primary-custom {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
        }

        .btn-primary-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(102, 126, 234, 0.5);
            color: white;
        }

        .btn-secondary-custom {
            background: white;
            color: var(--primary);
            border: 2px solid var(--primary);
        }

        .btn-secondary-custom:hover {
            background: var(--primary);
            color: white;
            transform: translateY(-2px);
        }

        .divider {
            height: 1px;
            background: linear-gradient(to right, transparent, #e2e8f0, transparent);
            margin: 40px 0;
        }

        @media (max-width: 768px) {
            .hero-section {
                height: 300px;
                margin-bottom: 30px;
            }

            .room-title {
                font-size: 1.8rem;
            }

            .gallery-nav {
                padding: 15px;
            }

            .gallery-thumb {
                width: 70px;
                height: 70px;
            }

            .info-grid {
                grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
                gap: 15px;
            }

            .info-card {
                padding: 20px;
            }

            .features-section {
                padding: 25px;
            }

            .features-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        /* Harmonize with shared visitor system */
        body {
            font-family: 'Plus Jakarta Sans', Inter, 'Segoe UI', sans-serif;
        }
        .hero-section,
        .features-section,
        .room-actions {
            border: 1px solid var(--visitor-border);
            box-shadow: var(--visitor-shadow);
        }
        .room-title {
            background: none;
            -webkit-text-fill-color: initial;
            color: var(--visitor-text);
        }
        .features-section .section-title {
            color: #0f172a !important;
            border-bottom-color: #2563eb !important;
        }
        .features-section .section-title i {
            color: #2563eb !important;
        }
        .room-code {
            background: var(--visitor-primary-soft);
            color: var(--visitor-accent);
            border: 1px solid rgba(30, 86, 179, 0.18);
        }
    </style>
</head>
<body class="visitor-page visitor-unified-shell room-detail-page">
    <div class="visitor-shell-content">
    <div class="container mt-4 mb-5">
        <!-- Breadcrumb -->
        <nav class="breadcrumb-custom mb-4">
            <a href="index.php" class="text-decoration-none" style="color: var(--primary);"><i class="bi bi-house"></i> Home</a>
            <span style="margin: 0 10px; color: #ccc;">/</span>
            <span class="breadcrumb-item active"><?= htmlspecialchars($room['nama_ruangan']) ?></span>
        </nav>

        <!-- Hero Section with Image Gallery -->
        <div class="hero-section">
            <img id="mainImage" src="<?= htmlspecialchars($main_image) ?>" 
                 alt="<?= htmlspecialchars($room['nama_ruangan']) ?>"
                 class="main-image"
                 onerror="this.src='../assets/images/placeholder.png'">

            <!-- Gallery Controls -->
            <div class="gallery-controls">
                <button class="gallery-btn" id="prevBtn" title="Gambar Sebelumnya">
                    <i class="bi bi-chevron-left"></i>
                </button>
                <button class="gallery-btn" id="nextBtn" title="Gambar Berikutnya">
                    <i class="bi bi-chevron-right"></i>
                </button>
            </div>

            <!-- Gallery Thumbnails -->
            <?php if (count($images) > 1): ?>
            <div class="gallery-nav">
                <?php foreach ($images as $idx => $img): ?>
                <div class="gallery-thumb <?= $idx === 0 ? 'active' : '' ?>" data-img="<?= htmlspecialchars($img) ?>" data-idx="<?= $idx ?>">
                    <img src="<?= htmlspecialchars($img) ?>" alt="Thumbnail <?= $idx+1 ?>" onerror="this.src='../assets/images/placeholder.png'">
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Room Header -->
        <div class="room-header">
            <div class="room-code"><i class="bi bi-hash"></i> <?= htmlspecialchars($room['kode_ruangan']) ?></div>
            <h1 class="room-title"><?= htmlspecialchars($room['nama_ruangan']) ?></h1>
            <?php if (!empty($room['deskripsi'])): ?>
            <p class="room-description"><?= htmlspecialchars($room['deskripsi']) ?></p>
            <?php endif; ?>
        </div>

        <!-- Info Cards -->
        <div class="info-grid">
            <?php if (!empty($room['lokasi'])): ?>
            <div class="info-card">
                <div class="info-icon"><i class="bi bi-geo-alt"></i></div>
                <div class="info-label">Lokasi</div>
                <div class="info-value" style="font-size: 1rem;"><?= htmlspecialchars($room['lokasi']) ?></div>
            </div>
            <?php endif; ?>

            <?php if (!empty($room['gedung'])): ?>
            <div class="info-card">
                <div class="info-icon"><i class="bi bi-building"></i></div>
                <div class="info-label">Gedung</div>
                <div class="info-value" style="font-size: 1rem;"><?= htmlspecialchars($room['gedung']) ?></div>
            </div>
            <?php endif; ?>

            <?php if (!empty($room['lantai'])): ?>
            <div class="info-card">
                <div class="info-icon"><i class="bi bi-diagram-3"></i></div>
                <div class="info-label">Lantai</div>
                <div class="info-value" style="font-size: 1rem;"><?= htmlspecialchars($room['lantai']) ?></div>
            </div>
            <?php endif; ?>

            <?php if ($room['kapasitas'] > 0): ?>
            <div class="info-card">
                <div class="info-icon"><i class="bi bi-people"></i></div>
                <div class="info-label">Kapasitas</div>
                <div class="info-value"><?= (int)$room['kapasitas'] ?></div>
                <div style="font-size: 0.85rem; color: #999; margin-top: 5px;">orang</div>
            </div>
            <?php endif; ?>
        </div>
        <!-- Features Section -->
        <?php if (!empty($features)): ?>
        <div class="features-section">
            <h2 class="section-title" style="color:#0f172a !important; border-bottom-color:#2563eb !important;">
                <i class="bi bi-sparkles" style="color:#2563eb !important;"></i> Fasilitas & Perangkat
            </h2>
            <div class="features-grid">
                <?php foreach ($features as $idx => $feature): ?>
                <?php
                    $feature_icon = 'bi-check2-circle';
                    $feature_lower = strtolower($feature);
                    if (strpos($feature_lower, 'smartboard') !== false) {
                        $feature_icon = 'bi-easel2';
                    } elseif (strpos($feature_lower, 'microphone') !== false || strpos($feature_lower, 'mic') !== false) {
                        $feature_icon = 'bi-mic-fill';
                    } elseif (strpos($feature_lower, 'kamera') !== false || strpos($feature_lower, 'camera') !== false) {
                        $feature_icon = 'bi-camera-video-fill';
                    } elseif (strpos($feature_lower, 'proyektor') !== false || strpos($feature_lower, 'projector') !== false) {
                        $feature_icon = 'bi-projector-fill';
                    }
                ?>
                <div class="feature-badge">
                    <div class="feature-icon"><i class="bi <?= $feature_icon ?>"></i></div>
                    <div class="feature-name"><?= htmlspecialchars($feature) ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="divider"></div>

        <!-- CTA Section -->
        <div class="cta-section">
            <button type="button" class="btn-custom btn-location-guide" data-bs-toggle="modal" data-bs-target="#locationGuideModal">
                <i class="bi bi-map"></i> Petunjuk Lokasi
            </button>
            <a href="index.php" class="btn-custom btn-secondary-custom">
                <i class="bi bi-arrow-left"></i> Kembali ke Daftar
            </a>
        </div>
    </div>
    </div>

    <!-- Modal Petunjuk Lokasi -->
    <div class="modal fade" id="locationGuideModal" tabindex="-1" aria-labelledby="locationGuideTitle">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title" id="locationGuideTitle"><i class="bi bi-map"></i> Petunjuk Lokasi</h5>
                        <p class="mb-0 mt-1 small opacity-90"><?= htmlspecialchars($room['nama_ruangan']) ?></p>
                        <div class="location-chips mt-2 d-flex flex-wrap gap-2">
                            <?php if ($room_gedung !== ''): ?>
                                <span class="badge bg-light text-dark">Gedung <?= htmlspecialchars($room_gedung) ?></span>
                            <?php endif; ?>
                            <?php if ($room_lantai !== ''): ?>
                                <span class="badge bg-light text-dark">Lantai <?= htmlspecialchars($room_lantai) ?></span>
                            <?php endif; ?>
                            <span class="badge bg-light text-dark"><?= htmlspecialchars($room['kode_ruangan']) ?></span>
                        </div>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>
                <div class="modal-body">
                    <?php if ($has_floor_plan_image): ?>
                        <p class="text-muted small mb-3">
                            <i class="bi bi-info-circle"></i> Gunakan denah di bawah untuk menemukan lokasi ruangan ini.
                        </p>
                        <div class="location-map-controls">
                            <button type="button" class="btn btn-outline-secondary btn-map-tool" id="locationZoomOut" title="Perkecil"><i class="bi bi-dash-lg"></i></button>
                            <button type="button" class="btn btn-outline-secondary btn-map-tool" id="locationZoomIn" title="Perbesar"><i class="bi bi-plus-lg"></i></button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="locationZoomReset"><i class="bi bi-arrow-counterclockwise"></i> Reset</button>
                            <button type="button" class="btn btn-outline-primary btn-sm" id="locationFullscreen"><i class="bi bi-arrows-fullscreen"></i> Layar penuh</button>
                            <span class="text-muted small ms-auto d-none d-md-inline">Geser denah · scroll untuk zoom</span>
                        </div>

                        <div class="location-map-viewport" id="locationMapViewport">
                            <div class="location-map-stage" id="locationMapStage">
                                <div class="location-map-inner" id="locationMapInner">
                                    <img src="<?= htmlspecialchars($floor_plan_image_url) ?>" alt="Denah lokasi" id="locationMapImage">
                                </div>
                            </div>
                        </div>

                        <p class="text-muted small mt-3 mb-0">
                            <strong><?= htmlspecialchars($room['nama_ruangan']) ?></strong>
                            <?php if (!empty($room['lokasi'])): ?>
                                · <?= htmlspecialchars($room['lokasi']) ?>
                            <?php endif; ?>
                        </p>
                    <?php else: ?>
                        <div class="location-map-fallback">
                            <i class="bi bi-map display-4 text-secondary mb-3 d-block"></i>
                            <p class="mb-2"><strong>Denah belum tersedia</strong></p>
                            <p class="small mb-0">
                                Belum ada denah khusus untuk ruangan ini.
                                Admin dapat mengunggah di menu <strong>Denah Ruangan</strong> dan memilih ruangan ini.
                            </p>
                            <?php if (!empty($room['lokasi'])): ?>
                                <p class="mt-3 mb-0"><i class="bi bi-geo-alt"></i> <?= htmlspecialchars($room['lokasi']) ?></p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const images = <?= json_encode($images) ?>;
        let currentIndex = 0;

        const mainImage = document.getElementById('mainImage');
        const prevBtn = document.getElementById('prevBtn');
        const nextBtn = document.getElementById('nextBtn');

        function updateImage(idx) {
            currentIndex = (idx + images.length) % images.length;
            const imgPath = images[currentIndex];
            mainImage.src = imgPath;
            
            // Update active thumbnail
            document.querySelectorAll('.gallery-thumb').forEach((thumb, i) => {
                thumb.classList.toggle('active', i === currentIndex);
            });
        }

        prevBtn.addEventListener('click', () => updateImage(currentIndex - 1));
        nextBtn.addEventListener('click', () => updateImage(currentIndex + 1));

        // Keyboard navigation
        document.addEventListener('keydown', (e) => {
            if (e.key === 'ArrowLeft') updateImage(currentIndex - 1);
            if (e.key === 'ArrowRight') updateImage(currentIndex + 1);
        });

        // Thumbnail click
        document.querySelectorAll('.gallery-thumb').forEach(thumb => {
            thumb.addEventListener('click', () => {
                updateImage(parseInt(thumb.dataset.idx));
            });
        });

        const locationModalEl = document.getElementById('locationGuideModal');
        if (locationModalEl) {
            locationModalEl.addEventListener('shown.bs.modal', function () {
                if (typeof LocationGuideModal !== 'undefined') {
                    window._locationGuide = LocationGuideModal.init();
                }
            });
            locationModalEl.addEventListener('hidden.bs.modal', function () {
                locationModalEl.classList.remove('modal-fullscreen-map');
            });
        }
    </script>
    <script src="../assets/js/location-guide-modal.js"></script>
    <?php require __DIR__ . '/_visitor_react_chrome.php'; ?>
    <script src="../assets/js/idle-redirect.js"></script>
</body>
</html>

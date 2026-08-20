<?php
require_once '../config.php';

// URL absolut api kategori — sama host/port dengan halaman (aman untuk MAMP :8888, subfolder, dll.)
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$http_host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$script_dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/visitor'));
$parent_dir = dirname($script_dir);
$api_base = ($parent_dir === '/' || $parent_dir === '\\' || $parent_dir === '.') ? '' : $parent_dir;
$live_categories_url = $scheme . '://' . $http_host . $api_base . '/api/live_categories.php';
$call_staff_url = $scheme . '://' . $http_host . $api_base . '/api/call_staff.php';
$visitor_base_url = $scheme . '://' . $http_host . $api_base . '/visitor/';
$auto_open = isset($_GET['open']) ? trim((string) $_GET['open']) : '';

$landing_css = 'assets/landing/assets/visitor-landing.css';
$landing_js = 'assets/landing/assets/visitor-landing.js';
$landing_asset_ver = max(
    (int) @filemtime(__DIR__ . '/' . $landing_css),
    (int) @filemtime(__DIR__ . '/' . $landing_js),
    time()
);

// Get rooms
$rooms = $koneksi->query("SELECT * FROM rooms WHERE status_aktif = 1 ORDER BY gedung, lantai, nama_ruangan");

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Selamat Datang - E-Recepsionis System</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <link href="../assets/css/toast.css" rel="stylesheet">
    <link href="<?= htmlspecialchars($landing_css) ?>?v=<?= (int) $landing_asset_ver ?>" rel="stylesheet">
    <link href="../assets/css/visitor-unified.css" rel="stylesheet">
    <script>
        window.__LIVE_SOCKET_URL__ = <?= json_encode(recepsionis_live_socket_url_for_browser(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        window.__LIVE_CATEGORIES_URL__ = <?= json_encode($live_categories_url, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        window.__CALL_STAFF_URL__ = <?= json_encode($call_staff_url, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        window.__VISITOR_BASE_URL__ = <?= json_encode($visitor_base_url, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        window.__AUTO_OPEN_MODAL__ = <?= json_encode($auto_open, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    </script>
    <style>
        /* Room detail modal image sizing (shared visitor pattern) */
        #roomDetailModal .carousel-item img {
            width: 100%;
            max-height: 420px;
            object-fit: cover;
        }
        #roomDetailModal .modal-body { padding: 1.5rem; }
        :root {
            --primary: #2563eb;
            --secondary: #0369a1;
            --success: #10b981;
            --warning: #f59e0b;
            --info: #3b82f6;
        }

        .room-card {
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
            border-radius: 16px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            border: 2px solid transparent;
            position: relative;
            overflow: hidden;
        }

        .room-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--primary), var(--secondary), var(--success));
            transform: scaleX(0);
            transform-origin: left;
            transition: transform 0.4s ease;
        }

        .room-card:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 12px 40px rgba(0,0,0,0.15);
            border-color: var(--primary);
        }

        .room-card:hover::before {
            transform: scaleX(1);
        }

        .room-card-header {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 12px;
            border-bottom: 2px solid #e2e8f0;
        }

        .room-icon-wrapper {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            color: white;
            font-size: 1.5rem;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
            transition: all 0.3s;
        }

        .room-card:hover .room-icon-wrapper {
            transform: rotate(5deg) scale(1.1);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
        }

        .room-title {
            font-size: 1.3rem;
            font-weight: 700;
            color: #1e293b;
            margin: 0;
            flex: 1;
        }

        .room-code-badge {
            display: inline-block;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 12px;
            box-shadow: 0 2px 8px rgba(102, 126, 234, 0.2);
        }

        .room-detail-item {
            display: flex;
            align-items: flex-start;
            margin-bottom: 12px;
            padding: 8px;
            border-radius: 8px;
            background: #f8fafc;
            transition: all 0.3s;
        }

        .room-card:hover .room-detail-item {
            background: #f1f5f9;
            transform: translateX(5px);
        }

        .room-detail-item i {
            font-size: 1.2rem;
            width: 24px;
            margin-right: 12px;
            margin-top: 2px;
            flex-shrink: 0;
        }

        .room-detail-item i.text-success {
            color: var(--success) !important;
        }

        .room-detail-item i.text-info {
            color: var(--info) !important;
        }

        .room-detail-item i.text-warning {
            color: var(--warning) !important;
        }

        .room-detail-item strong {
            color: #475569;
            font-size: 0.9rem;
            margin-right: 5px;
        }

        .room-detail-item span {
            color: #64748b;
            font-size: 0.95rem;
        }

        .room-capacity {
            display: inline-block;
            background: linear-gradient(135deg, var(--warning), #f59e0b);
            color: white;
            padding: 4px 12px;
            border-radius: 15px;
            font-weight: 600;
            font-size: 0.9rem;
            box-shadow: 0 2px 8px rgba(245, 158, 11, 0.3);
        }

        .room-description {
            background: linear-gradient(135deg, #f8fafc, #ffffff);
            padding: 12px 15px;
            border-radius: 10px;
            border-left: 3px solid var(--primary);
            margin-top: 15px;
            color: #64748b;
            font-size: 0.9rem;
            line-height: 1.6;
        }

        .modal-content {
            border-radius: 16px;
            border: none;
        }

        .modal-header {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            border-radius: 16px 16px 0 0;
        }

        #roomsModal .modal-dialog {
            max-width: min(96vw, 1500px);
            margin: 1rem auto;
        }

        #roomsModal .modal-content {
            min-height: 82vh;
        }

        #roomsModal .modal-body {
            max-height: calc(82vh - 88px);
            overflow-y: auto;
            padding: 1.25rem 1.5rem;
            position: relative;
        }

        .rooms-scroll-hint {
            position: absolute;
            left: 0;
            right: 0;
            bottom: 0;
            z-index: 30;
            pointer-events: none;
            display: flex;
            justify-content: center;
            padding: 2.5rem 1rem 1.1rem;
            background: linear-gradient(to top, rgba(255, 255, 255, 0.98) 38%, rgba(255, 255, 255, 0));
            opacity: 0;
            transform: translateY(14px);
            transition: opacity 0.4s ease, transform 0.4s ease;
        }

        .rooms-scroll-hint.is-visible {
            opacity: 1;
            transform: translateY(0);
        }

        .rooms-scroll-hint.is-hiding {
            opacity: 0;
            transform: translateY(10px);
        }

        .rooms-scroll-hint-card {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.35rem;
            padding: 0.85rem 1.15rem 0.7rem;
            border-radius: 18px;
            background: rgba(15, 23, 42, 0.82);
            color: #fff;
            box-shadow: 0 12px 32px rgba(15, 23, 42, 0.28);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
        }

        .rooms-scroll-silhouette {
            width: 42px;
            height: 54px;
            position: relative;
            animation: roomsScrollSilhouetteBob 1.8s ease-in-out infinite;
        }

        .rooms-scroll-silhouette svg {
            width: 100%;
            height: 100%;
            display: block;
            fill: #fff;
        }

        .rooms-scroll-hint-text {
            font-size: 0.78rem;
            font-weight: 700;
            letter-spacing: 0.03em;
            text-transform: uppercase;
            opacity: 0.95;
        }

        .rooms-scroll-arrows {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0;
            margin-top: 0.1rem;
            color: #93c5fd;
            font-size: 1rem;
            line-height: 0.55;
            animation: roomsScrollArrowBounce 1.2s ease-in-out infinite;
        }

        @keyframes roomsScrollSilhouetteBob {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(4px); }
        }

        @keyframes roomsScrollArrowBounce {
            0%, 100% { transform: translateY(0); opacity: 0.55; }
            50% { transform: translateY(6px); opacity: 1; }
        }

        @media (prefers-reduced-motion: reduce) {
            .rooms-scroll-silhouette,
            .rooms-scroll-arrows {
                animation: none;
            }
        }

        #roomsModal .room-thumbnail {
            width: 100%;
            height: 220px;
            border-radius: 12px;
            overflow: hidden;
            margin-bottom: 15px;
            background: #e2e8f0;
        }

        #roomsModal .room-card {
            height: 100%;
        }

        body.visitor-page {
            background: #030712;
            font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: #0f172a;
            min-height: 100vh;
        }

    </style>
</head>
<body class="visitor-page visitor-unified-shell">
    <!-- React landing: rebuild with npm run build in /visitor-app -->
    <div id="visitor-landing-root"></div>

    <!-- Rooms Modal -->
    <div class="modal fade" id="roomsModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-door-open"></i> Daftar Ruangan</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="roomsModalBody">
                    <div class="rooms-scroll-hint" id="roomsScrollHint" aria-hidden="true">
                        <div class="rooms-scroll-hint-card">
                            <div class="rooms-scroll-silhouette" aria-hidden="true">
                                <svg viewBox="0 0 48 64" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="">
                                    <circle cx="24" cy="10" r="8"/>
                                    <path d="M14 24c0-4 4-6 10-6s10 2 10 6v4H14v-4z"/>
                                    <path d="M10 30c2 10 6 16 14 16s12-6 14-16l-6 2c-1 6-4 10-8 10s-7-4-8-10l-6-2z"/>
                                    <path d="M30 34l10 18 4-2-8-14 6-4-3-5-9 7z"/>
                                </svg>
                            </div>
                            <div class="rooms-scroll-hint-text">Gulir ke bawah</div>
                            <div class="rooms-scroll-arrows" aria-hidden="true">
                                <i class="bi bi-chevron-down"></i>
                                <i class="bi bi-chevron-down"></i>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <?php 
                        $rooms->data_seek(0);
                        if ($rooms->num_rows > 0): 
                            while ($room = $rooms->fetch_assoc()): 
                        ?>
                            <div class="col-md-6 mb-3">
                                <div class="room-card">
                                    <?php
                                        // Extract first image from images field and normalize path
                                        $img_list = [];
                                        if (!empty($room['images'])) {
                                            $img_list = array_filter(array_map('trim', explode(',', $room['images'])));
                                        } elseif (!empty($room['foto'])) {
                                            $img_list = [(string)$room['foto']];
                                        }
                                        $first_img = !empty($img_list) ? reset($img_list) : null;

                                        // Normalize relative path for visitor folder: add ../ prefix when needed
                                        if ($first_img) {
                                            $fi = trim((string)$first_img);
                                            if (!preg_match('~^(https?://|/|\.\./)~i', $fi)) {
                                                $fi = '../' . $fi;
                                            }
                                            $first_img = $fi;
                                        }
                                    ?>
                                    <?php if (!empty($first_img)): ?>
                                        <div class="room-thumbnail">
                                            <img src="<?= htmlspecialchars($first_img) ?>" alt="<?= htmlspecialchars($room['nama_ruangan']) ?>" 
                                                 style="width: 100%; height: 100%; object-fit: cover;" onerror="this.style.display='none'">
                                        </div>
                                    <?php endif; ?>
                                    <div class="room-card-header">
                                        <div class="room-icon-wrapper">
                                            <i class="bi bi-door-open-fill"></i>
                                        </div>
                                        <h5 class="room-title"><?= htmlspecialchars($room['nama_ruangan']) ?></h5>
                                    </div>
                                    
                                    <div class="room-code-badge">
                                        <i class="bi bi-tag-fill"></i> <?= htmlspecialchars($room['kode_ruangan']) ?>
                                    </div>
                                    
                                    <div class="room-detail-item">
                                        <i class="bi bi-geo-alt-fill text-success"></i>
                                        <div>
                                            <strong>Lokasi:</strong>
                                            <span><?= htmlspecialchars($room['lokasi']) ?></span>
                                        </div>
                                    </div>
                                    
                                    <?php if ($room['gedung']): ?>
                                        <div class="room-detail-item">
                                            <i class="bi bi-building text-info"></i>
                                            <div>
                                                <strong>Gedung:</strong>
                                                <span>
                                                    <?= htmlspecialchars($room['gedung']) ?>
                                                    <?php if ($room['lantai']): ?>
                                                        - Lantai <?= htmlspecialchars($room['lantai']) ?>
                                                    <?php endif; ?>
                                                </span>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($room['kapasitas'] > 0): ?>
                                        <div class="room-detail-item">
                                            <i class="bi bi-people text-warning"></i>
                                            <div>
                                                <strong>Kapasitas:</strong>
                                                <span class="room-capacity"><?= $room['kapasitas'] ?> orang</span>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="room-actions mt-3">
                                        <a href="room_detail.php?id=<?= (int)$room['id'] ?>" class="btn btn-outline-primary btn-sm w-100">
                                            <i class="bi bi-card-list"></i> Detail Ruangan
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php 
                            endwhile;
                        else: 
                        ?>
                            <div class="col-12 text-center text-muted py-5">
                                <i class="bi bi-inbox" style="font-size: 4rem; opacity: 0.3;"></i><br>
                                <h5 class="mt-3">Tidak ada ruangan tersedia</h5>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Toast Notification Container -->
    <div class="toast-container" id="toastContainer"></div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script type="module" src="<?= htmlspecialchars($landing_js) ?>?v=<?= (int) $landing_asset_ver ?>"></script>
    <script src="../assets/js/toast.js"></script>
    <script src="../assets/js/idle-redirect.js"></script>
    <script>
        (function () {
            const modalEl = document.getElementById('roomsModal');
            const modalBody = document.getElementById('roomsModalBody');
            const scrollHint = document.getElementById('roomsScrollHint');
            let hideTimer = null;
            let scrollListener = null;

            function isScrollable(el) {
                return el && el.scrollHeight > el.clientHeight + 8;
            }

            function hideScrollHint() {
                if (!scrollHint || scrollHint.classList.contains('is-hiding')) {
                    return;
                }
                scrollHint.classList.remove('is-visible');
                scrollHint.classList.add('is-hiding');
                window.setTimeout(function () {
                    scrollHint.classList.remove('is-hiding');
                    scrollHint.setAttribute('aria-hidden', 'true');
                }, 400);
            }

            function showScrollHint() {
                if (!modalBody || !scrollHint || !isScrollable(modalBody)) {
                    return;
                }

                scrollHint.setAttribute('aria-hidden', 'false');
                scrollHint.classList.remove('is-hiding');
                requestAnimationFrame(function () {
                    scrollHint.classList.add('is-visible');
                });

                if (hideTimer) {
                    clearTimeout(hideTimer);
                }
                hideTimer = window.setTimeout(hideScrollHint, 4200);

                if (scrollListener) {
                    modalBody.removeEventListener('scroll', scrollListener);
                }
                scrollListener = function () {
                    if (modalBody.scrollTop > 12) {
                        hideScrollHint();
                        modalBody.removeEventListener('scroll', scrollListener);
                        scrollListener = null;
                    }
                };
                modalBody.addEventListener('scroll', scrollListener, { passive: true });
            }

            function resetScrollHint() {
                if (hideTimer) {
                    clearTimeout(hideTimer);
                    hideTimer = null;
                }
                if (scrollListener && modalBody) {
                    modalBody.removeEventListener('scroll', scrollListener);
                    scrollListener = null;
                }
                if (scrollHint) {
                    scrollHint.classList.remove('is-visible', 'is-hiding');
                    scrollHint.setAttribute('aria-hidden', 'true');
                }
                if (modalBody) {
                    modalBody.scrollTop = 0;
                }
            }

            if (modalEl) {
                modalEl.addEventListener('shown.bs.modal', function () {
                    window.setTimeout(showScrollHint, 350);
                });
                modalEl.addEventListener('hidden.bs.modal', resetScrollHint);
            }

            if (window.__AUTO_OPEN_MODAL__ !== 'rooms') return;
            if (!modalEl || !window.bootstrap?.Modal) return;
            const modal = window.bootstrap.Modal.getOrCreateInstance(modalEl);
            setTimeout(() => modal.show(), 150);
        })();
    </script>
</body>
</html>

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

$branding = recepsionis_get_visitor_branding($koneksi);
$visitor_logo_url = $scheme . '://' . $http_host . $api_base . '/' . ltrim((string) $branding['logo_relative'], '/');
$siteName = (string) $branding['site_name'];
$welcomeTitle = (string) $branding['welcome_title'];
$logoAlt = (string) $branding['logo_alt'];
$visitorServices = $branding['services'];

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#030712">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <title><?= htmlspecialchars($welcomeTitle) ?> - <?= htmlspecialchars($siteName) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Sora:wght@500;600;700&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
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
        window.__SITE_NAME__ = <?= json_encode($siteName, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        window.__VISITOR_LOGO_URL__ = <?= json_encode($visitor_logo_url, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        window.__VISITOR_LOGO_ALT__ = <?= json_encode($logoAlt, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        window.__VISITOR_WELCOME_TITLE__ = <?= json_encode($welcomeTitle, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        window.__VISITOR_SERVICES__ = <?= json_encode($visitorServices, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    </script>
    <style>
        body.visitor-page {
            background: #030712;
            font-family: 'Plus Jakarta Sans', Inter, sans-serif;
            color: #e2e8f0;
            min-height: 100dvh;
            overflow-x: hidden;
            overscroll-behavior: none;
            -webkit-tap-highlight-color: transparent;
        }

        #visitor-landing-root {
            min-height: 100dvh;
        }

        #roomsModal .modal-dialog {
            max-width: min(96vw, 1180px);
            margin: 1rem auto;
        }

        #roomsModal .modal-content {
            min-height: 82vh;
            border: 1px solid rgba(255, 255, 255, 0.78);
            border-radius: 24px;
            background: linear-gradient(145deg, rgba(255, 255, 255, 0.94) 0%, rgba(255, 255, 255, 0.78) 100%);
            color: #0f172a;
            overflow: hidden;
            box-shadow:
                0 24px 60px rgba(15, 23, 42, 0.22),
                inset 0 1px 0 rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(22px) saturate(1.35);
            -webkit-backdrop-filter: blur(22px) saturate(1.35);
        }

        #roomsModal .modal-header {
            background: transparent;
            border-bottom: 1px solid rgba(148, 163, 184, 0.22);
            padding: 1.15rem 1.5rem;
            color: #0f172a;
        }

        #roomsModal .modal-title {
            font-family: Sora, 'Plus Jakarta Sans', sans-serif;
            font-weight: 600;
            font-size: 1.35rem;
            letter-spacing: -0.02em;
            display: flex;
            align-items: center;
            gap: 0.55rem;
            color: #0f172a;
        }

        #roomsModal .modal-title i {
            color: #0284c7;
            font-size: 1.15rem;
        }

        #roomsModal .btn-close {
            opacity: 0.55;
        }

        #roomsModal .btn-close:hover {
            opacity: 0.9;
        }

        #roomsModal .modal-body {
            max-height: calc(82vh - 76px);
            overflow-y: auto;
            padding: 1.5rem 1.65rem 1.9rem;
            position: relative;
        }

        #roomsModal .rooms-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 1.35rem;
        }

        @media (max-width: 767.98px) {
            #roomsModal .rooms-grid {
                grid-template-columns: 1fr;
            }
        }

        #roomsModal .room-card {
            display: flex;
            flex-direction: column;
            height: 100%;
            text-decoration: none;
            color: inherit;
            border-radius: 20px;
            border: 1px solid rgba(255, 255, 255, 0.78);
            background: linear-gradient(145deg, rgba(255, 255, 255, 0.92) 0%, rgba(255, 255, 255, 0.7) 100%);
            box-shadow:
                0 12px 28px rgba(15, 23, 42, 0.1),
                inset 0 1px 0 rgba(255, 255, 255, 0.9);
            overflow: hidden;
            transition: transform 0.25s ease, border-color 0.25s ease, box-shadow 0.25s ease;
        }

        #roomsModal .room-card:hover {
            transform: translateY(-4px);
            border-color: rgba(14, 165, 233, 0.35);
            box-shadow:
                0 18px 36px rgba(15, 23, 42, 0.14),
                inset 0 1px 0 rgba(255, 255, 255, 1);
            color: inherit;
        }

        #roomsModal .room-media {
            position: relative;
            aspect-ratio: 16 / 10;
            background: linear-gradient(145deg, #e0f2fe 0%, #dbeafe 55%, #e2e8f0 100%);
            overflow: hidden;
            flex-shrink: 0;
        }

        #roomsModal .room-media img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        #roomsModal .room-media-fallback {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            color: rgba(14, 165, 233, 0.45);
            font-size: 2.6rem;
        }

        #roomsModal .room-body {
            display: flex;
            flex-direction: column;
            flex: 1;
            padding: 1.1rem 1.2rem 1.25rem;
            min-height: 11.5rem;
        }

        #roomsModal .room-chips {
            display: flex;
            flex-wrap: wrap;
            gap: 0.4rem;
            margin-bottom: 0.65rem;
        }

        #roomsModal .room-chip {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            padding: 0.22rem 0.65rem;
            border-radius: 999px;
            font-size: 0.72rem;
            font-weight: 600;
            letter-spacing: 0.02em;
            border: 1px solid rgba(148, 163, 184, 0.28);
            background: rgba(248, 250, 252, 0.9);
            color: #64748b;
        }

        #roomsModal .room-chip.is-code {
            color: #0369a1;
            border-color: rgba(14, 165, 233, 0.28);
            background: rgba(14, 165, 233, 0.1);
        }

        #roomsModal .room-title {
            font-family: Sora, 'Plus Jakarta Sans', sans-serif;
            font-size: 1.15rem;
            font-weight: 600;
            letter-spacing: -0.02em;
            color: #0f172a;
            margin: 0 0 0.45rem;
            line-height: 1.25;
        }

        #roomsModal .room-meta {
            margin: 0;
            color: #64748b;
            font-size: 0.9rem;
            line-height: 1.45;
        }

        #roomsModal .room-meta + .room-meta {
            margin-top: 0.2rem;
        }

        #roomsModal .room-cta {
            margin-top: auto;
            padding-top: 1rem;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            color: #0284c7;
            font-size: 0.92rem;
            font-weight: 600;
        }

        #roomsModal .room-card:hover .room-cta i {
            transform: translateX(3px);
        }

        #roomsModal .room-cta i {
            transition: transform 0.25s ease;
            font-size: 0.95rem;
        }

        #roomsModal .rooms-empty {
            text-align: center;
            color: #64748b;
            padding: 3.5rem 1rem;
        }

        #roomsModal .rooms-empty i {
            font-size: 3rem;
            opacity: 0.35;
            color: #0284c7;
        }

        #roomsModal .rooms-empty h5 {
            color: #0f172a !important;
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
            background: linear-gradient(to top, rgba(255, 255, 255, 0.96) 35%, rgba(255, 255, 255, 0));
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
            gap: 0.3rem;
            padding: 0.75rem 1.1rem 0.65rem;
            border-radius: 16px;
            border: 1px solid rgba(148, 163, 184, 0.25);
            background: rgba(255, 255, 255, 0.9);
            color: #0f172a;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.1);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
        }

        .rooms-scroll-silhouette {
            width: 36px;
            height: 46px;
            animation: roomsScrollSilhouetteBob 1.8s ease-in-out infinite;
        }

        .rooms-scroll-silhouette svg {
            width: 100%;
            height: 100%;
            display: block;
            fill: #64748b;
        }

        .rooms-scroll-hint-text {
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: #64748b;
        }

        .rooms-scroll-arrows {
            display: flex;
            flex-direction: column;
            align-items: center;
            line-height: 0.55;
            color: #0284c7;
            font-size: 0.95rem;
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
            .rooms-scroll-arrows,
            #roomsModal .room-card {
                animation: none;
                transition: none;
            }
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
                    <div class="rooms-grid">
                        <?php
                        $rooms->data_seek(0);
                        if ($rooms->num_rows > 0):
                            while ($room = $rooms->fetch_assoc()):
                                $img_list = [];
                                if (!empty($room['images'])) {
                                    $img_list = array_filter(array_map('trim', explode(',', $room['images'])));
                                } elseif (!empty($room['foto'])) {
                                    $img_list = [(string) $room['foto']];
                                }
                                $first_img = !empty($img_list) ? reset($img_list) : null;
                                if ($first_img) {
                                    $fi = trim((string) $first_img);
                                    if (!preg_match('~^(https?://|/|\.\./)~i', $fi)) {
                                        $fi = '../' . $fi;
                                    }
                                    $first_img = $fi;
                                }
                                $buildingBits = array_filter([
                                    trim((string) ($room['gedung'] ?? '')),
                                    trim((string) ($room['lantai'] ?? '')) !== ''
                                        ? 'Lt ' . trim((string) $room['lantai'])
                                        : '',
                                ]);
                                $buildingLine = implode(' · ', $buildingBits);
                                $lokasi = trim((string) ($room['lokasi'] ?? ''));
                                $kapasitas = (int) ($room['kapasitas'] ?? 0);
                        ?>
                            <a class="room-card" href="room_detail.php?id=<?= (int) $room['id'] ?>">
                                <div class="room-media">
                                    <?php if (!empty($first_img)): ?>
                                        <img
                                            src="<?= htmlspecialchars($first_img) ?>"
                                            alt="<?= htmlspecialchars($room['nama_ruangan']) ?>"
                                            onerror="this.classList.add('d-none'); var fb=this.nextElementSibling; if(fb) fb.classList.remove('d-none');"
                                        >
                                        <div class="room-media-fallback d-none" aria-hidden="true">
                                            <i class="bi bi-building"></i>
                                        </div>
                                    <?php else: ?>
                                        <div class="room-media-fallback" aria-hidden="true">
                                            <i class="bi bi-building"></i>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="room-body">
                                    <div class="room-chips">
                                        <span class="room-chip is-code"><?= htmlspecialchars($room['kode_ruangan']) ?></span>
                                        <?php if ($kapasitas > 0): ?>
                                            <span class="room-chip"><?= $kapasitas ?> orang</span>
                                        <?php endif; ?>
                                    </div>
                                    <h5 class="room-title"><?= htmlspecialchars($room['nama_ruangan']) ?></h5>
                                    <?php if ($buildingLine !== ''): ?>
                                        <p class="room-meta"><?= htmlspecialchars($buildingLine) ?></p>
                                    <?php endif; ?>
                                    <?php if ($lokasi !== ''): ?>
                                        <p class="room-meta"><?= htmlspecialchars($lokasi) ?></p>
                                    <?php endif; ?>
                                    <span class="room-cta">
                                        Detail ruangan <i class="bi bi-arrow-right"></i>
                                    </span>
                                </div>
                            </a>
                        <?php
                            endwhile;
                        else:
                        ?>
                            <div class="rooms-empty" style="grid-column: 1 / -1;">
                                <i class="bi bi-inbox"></i>
                                <h5 class="mt-3 mb-0">Tidak ada ruangan tersedia</h5>
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

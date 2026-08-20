<?php
require_once '../config.php';
require_once '../staff_call_routing.php';

$token = trim((string) ($_GET['k'] ?? ''));
$room = recepsionis_get_room_by_tv_token($koneksi, $token);

$roomName = $room ? (string) ($room['nama_ruangan'] ?? 'Ruangan') : '';
$imagePath = $room ? trim((string) ($room['tv_info_image'] ?? '')) : '';
$imageUrl = $imagePath !== '' ? recepsionis_room_tv_image_url($imagePath) : '';
$updatedAt = $room ? (string) ($room['tv_info_updated_at'] ?? '') : '';
$apiUrl = function_exists('apiUrl')
    ? apiUrl('tv_info_status.php')
    : (rtrim(BASE_URL, '/') . '/api/tv_info_status.php');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="robots" content="noindex,nofollow">
    <title><?= $room ? htmlspecialchars($roomName) . ' — TV Info' : 'TV Info' ?></title>
    <style>
        html, body {
            margin: 0;
            padding: 0;
            width: 100%;
            height: 100%;
            background: #000;
            overflow: hidden;
            font-family: system-ui, -apple-system, sans-serif;
            user-select: none;
            -webkit-user-select: none;
            touch-action: manipulation;
        }
        body.idle-cursor { cursor: none; }
        #stage {
            position: fixed;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #000;
        }
        #tvImage {
            width: 100%;
            height: 100%;
            object-fit: contain;
            background: #000;
            display: none;
        }
        #tvImage.has-image { display: block; }
        #emptyState, #errorState {
            color: #94a3b8;
            text-align: center;
            padding: 2rem;
            display: none;
        }
        #emptyState.show, #errorState.show { display: block; }
        #emptyState strong, #errorState strong {
            display: block;
            color: #e2e8f0;
            font-size: 1.25rem;
            margin-bottom: 0.5rem;
        }
        #tapHint {
            position: fixed;
            left: 50%;
            bottom: 1.5rem;
            transform: translateX(-50%);
            background: rgba(15, 23, 42, 0.85);
            color: #fff;
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 999px;
            padding: 0.65rem 1.25rem;
            font-size: 0.9rem;
            z-index: 5;
            transition: opacity .3s;
        }
        #tapHint.hidden { opacity: 0; pointer-events: none; }
    </style>
</head>
<body>
    <div id="stage">
        <?php if (!$room): ?>
            <div id="errorState" class="show">
                <strong>Link TV tidak valid</strong>
                Token salah atau ruangan nonaktif. Minta URL baru ke admin.
            </div>
        <?php else: ?>
            <img id="tvImage" alt="<?= htmlspecialchars($roomName) ?>" <?= $imageUrl !== '' ? 'class="has-image" src="' . htmlspecialchars($imageUrl) . '"' : '' ?>>
            <div id="emptyState" class="<?= $imageUrl === '' ? 'show' : '' ?>">
                <strong><?= htmlspecialchars($roomName) ?></strong>
                Belum ada info. Admin belum mengunggah gambar.
            </div>
            <div id="tapHint">Ketuk layar sekali untuk fullscreen</div>
        <?php endif; ?>
    </div>

    <?php if ($room): ?>
    <script>
    (function () {
        const token = <?= json_encode($token, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const apiUrl = <?= json_encode($apiUrl, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        let updatedAt = <?= json_encode($updatedAt, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const img = document.getElementById('tvImage');
        const empty = document.getElementById('emptyState');
        const hint = document.getElementById('tapHint');
        let idleTimer = null;
        let reenterTimer = null;
        let kioskArmed = false;

        function isFullscreen() {
            return !!(document.fullscreenElement || document.webkitFullscreenElement);
        }

        function requestFs() {
            const el = document.documentElement;
            const req = el.requestFullscreen || el.webkitRequestFullscreen;
            if (!req) return Promise.resolve();
            try {
                const result = req.call(el);
                return result && typeof result.then === 'function' ? result : Promise.resolve();
            } catch (e) {
                return Promise.resolve();
            }
        }

        function armKiosk() {
            kioskArmed = true;
            if (hint) hint.classList.add('hidden');
            requestFs();
        }

        function scheduleReenter() {
            if (!kioskArmed) return;
            clearTimeout(reenterTimer);
            reenterTimer = setTimeout(function () {
                if (!isFullscreen()) {
                    requestFs();
                }
            }, 400);
        }

        function resetIdleCursor() {
            document.body.classList.remove('idle-cursor');
            clearTimeout(idleTimer);
            idleTimer = setTimeout(function () {
                document.body.classList.add('idle-cursor');
            }, 2500);
        }

        document.addEventListener('fullscreenchange', scheduleReenter);
        document.addEventListener('webkitfullscreenchange', scheduleReenter);

        document.addEventListener('contextmenu', function (e) { e.preventDefault(); });
        document.addEventListener('dragstart', function (e) { e.preventDefault(); });
        document.addEventListener('selectstart', function (e) { e.preventDefault(); });

        document.addEventListener('keydown', function (e) {
            const blocked = ['F11', 'Escape', 'Esc', 'F5'];
            if (blocked.indexOf(e.key) !== -1 || (e.ctrlKey && ['r', 'R', 'u', 'U', 's', 'S'].indexOf(e.key) !== -1)) {
                e.preventDefault();
                e.stopPropagation();
                if (kioskArmed && !isFullscreen()) {
                    requestFs();
                }
            }
        }, true);

        ['pointerdown', 'click', 'touchstart'].forEach(function (evt) {
            document.addEventListener(evt, function () {
                if (!kioskArmed) {
                    armKiosk();
                } else if (!isFullscreen()) {
                    requestFs();
                }
                resetIdleCursor();
            }, { passive: true });
        });

        document.addEventListener('mousemove', resetIdleCursor, { passive: true });
        resetIdleCursor();

        function applyStatus(data) {
            if (!data || !data.success) return;
            const nextUpdated = data.updated_at || '';
            const nextImage = data.image_url || '';
            if (nextUpdated && nextUpdated !== updatedAt) {
                updatedAt = nextUpdated;
                if (nextImage) {
                    const bust = nextImage + (nextImage.indexOf('?') >= 0 ? '&' : '?') + 'v=' + encodeURIComponent(nextUpdated);
                    img.onload = function () {
                        img.classList.add('has-image');
                        empty.classList.remove('show');
                    };
                    img.src = bust;
                } else {
                    img.removeAttribute('src');
                    img.classList.remove('has-image');
                    empty.classList.add('show');
                }
            }
        }

        async function poll() {
            try {
                const res = await fetch(apiUrl + '?k=' + encodeURIComponent(token), {
                    cache: 'no-store',
                    headers: { 'Accept': 'application/json' }
                });
                const data = await res.json();
                applyStatus(data);
            } catch (e) {
                // ignore transient network errors
            }
        }

        setInterval(poll, 30000);
        setTimeout(poll, 5000);
    })();
    </script>
    <?php endif; ?>
</body>
</html>

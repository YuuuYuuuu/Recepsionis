<?php
require_once '../config.php';
require_once '../staff_call_routing.php';

$token = trim((string) ($_GET['k'] ?? ''));
$access = recepsionis_get_helpdesk_it_access_by_token($koneksi, $token);
$accessValid = $access !== null;
$accessType = $accessValid ? (string) ($access['access_type'] ?? 'event') : 'event';
$isRoomMode = $accessType === 'room';
$roomName = trim((string) ($access['nama_ruangan'] ?? ''));
$roomMeta = trim(implode(' · ', array_filter([
    trim((string) ($access['kode_ruangan'] ?? '')),
    trim((string) ($access['gedung'] ?? '')),
    trim((string) ($access['lantai'] ?? '')) !== '' ? 'Lt ' . trim((string) ($access['lantai'])) : '',
])));
$issueCategories = recepsionis_helpdesk_it_issue_categories();
$submitted = isset($_GET['sent']) && $_GET['sent'] === '1';
$ticketId = max(0, (int) ($_GET['t'] ?? 0));
$loadingLottieUrl = rtrim(BASE_URL, '/') . '/assets/images/loading_2.lottie';
$helpdeskLottieUrl = rtrim(BASE_URL, '/') . '/assets/images/helpdesk.lottie';
$needsLottiePlayer = $accessValid && ($submitted || !$submitted);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $submitted ? 'Laporan Terkirim' : 'Helpdesk IT' ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/visitor-unified.css" rel="stylesheet">
    <?php if ($needsLottiePlayer): ?>
    <script type="module" src="https://unpkg.com/@lottiefiles/dotlottie-wc@0.4.2/dist/dotlottie-wc.js"></script>
    <?php endif; ?>
    <style>
        body {
            min-height: 100vh;
            background: linear-gradient(160deg, #0f172a 0%, #1e3a5f 45%, #0c4a6e 100%);
            font-family: Inter, system-ui, sans-serif;
        }
        .hd-card {
            max-width: 520px;
            margin: 2rem auto;
            border: none;
            border-radius: 18px;
            box-shadow: 0 20px 50px rgba(0,0,0,.25);
            overflow: hidden;
        }
        .hd-header {
            background: linear-gradient(135deg, #2563eb, #0ea5e9);
            color: #fff;
            padding: 1.25rem 1.5rem;
        }
        .hd-header.is-success {
            background: linear-gradient(135deg, #059669, #0ea5e9);
        }
        .hd-badge {
            display: inline-flex;
            align-items: center;
            gap: .35rem;
            background: rgba(255,255,255,.15);
            border-radius: 999px;
            padding: .25rem .75rem;
            font-size: .75rem;
            font-weight: 600;
            letter-spacing: .04em;
            text-transform: uppercase;
        }
        .hd-room-meta {
            font-size: .82rem;
            opacity: .9;
            margin-top: .35rem;
        }
        .hd-cat-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: .75rem;
        }
        .hd-cat-card {
            border: 2px solid #e2e8f0;
            border-radius: 14px;
            background: #fff;
            padding: .95rem .75rem;
            text-align: center;
            cursor: pointer;
            transition: .15s ease;
            user-select: none;
        }
        .hd-cat-card i {
            display: block;
            font-size: 1.35rem;
            color: #2563eb;
            margin-bottom: .35rem;
        }
        .hd-cat-card span {
            display: block;
            font-size: .88rem;
            font-weight: 600;
            color: #0f172a;
        }
        .hd-cat-card:hover {
            border-color: #93c5fd;
            background: #f8fbff;
        }
        .hd-cat-card.is-selected {
            border-color: #2563eb;
            background: #eff6ff;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, .12);
        }
        .hd-cat-card input {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }
        .hd-success {
            text-align: center;
            padding: .25rem 0 .15rem;
        }
        .hd-success-lottie {
            width: 168px;
            height: 168px;
            margin: -.35rem auto .5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            animation: hdFadeUp .55s cubic-bezier(.22, 1, .36, 1) both;
        }
        .hd-success-lottie dotlottie-wc {
            width: 168px !important;
            height: 168px !important;
            display: block;
        }
        .hd-success h2 {
            font-weight: 700;
            color: #0f172a;
            margin-bottom: .45rem;
            animation: hdFadeUp .5s .12s ease both;
        }
        .hd-success .lead-msg {
            color: #64748b;
            font-size: .94rem;
            line-height: 1.6;
            margin-bottom: 1.1rem;
            max-width: 26rem;
            margin-left: auto;
            margin-right: auto;
            animation: hdFadeUp .5s .2s ease both;
        }
        .hd-ticket-chip {
            display: inline-flex;
            align-items: center;
            gap: .45rem;
            background: linear-gradient(135deg, #ecfdf5 0%, #f0fdf4 100%);
            border: 1px solid #86efac;
            color: #047857;
            border-radius: 999px;
            padding: .45rem 1rem;
            font-size: .84rem;
            font-weight: 700;
            letter-spacing: .01em;
            margin-bottom: 1.2rem;
            box-shadow: 0 4px 14px rgba(16, 185, 129, .12);
            animation: hdFadeUp .5s .28s ease both;
        }
        .hd-ticket-chip i {
            font-size: .95rem;
        }
        .hd-next {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 1rem 1.1rem;
            text-align: left;
            margin-bottom: 1.25rem;
            animation: hdFadeUp .5s .35s ease both;
        }
        .hd-next-title {
            font-size: .72rem;
            font-weight: 700;
            letter-spacing: .06em;
            text-transform: uppercase;
            color: #94a3b8;
            margin-bottom: .65rem;
        }
        .hd-next-row {
            display: flex;
            align-items: flex-start;
            gap: .65rem;
            font-size: .88rem;
            color: #334155;
        }
        .hd-next-row + .hd-next-row {
            margin-top: .55rem;
        }
        .hd-next-row i {
            color: #0ea5e9;
            font-size: 1.05rem;
            margin-top: .1rem;
            flex-shrink: 0;
        }
        .hd-success .btn-again {
            animation: hdFadeUp .5s .45s ease both;
            border-radius: 12px;
            font-weight: 600;
            padding: .65rem 1.25rem;
        }
        @keyframes hdFadeUp {
            from { transform: translateY(10px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        @media (prefers-reduced-motion: reduce) {
            .hd-success-lottie,
            .hd-success h2,
            .hd-success .lead-msg,
            .hd-ticket-chip,
            .hd-next,
            .hd-success .btn-again {
                animation: none !important;
            }
        }
        .hd-loading-overlay {
            position: fixed;
            inset: 0;
            z-index: 10060;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            background: rgba(15, 23, 42, 0.55);
            backdrop-filter: blur(4px);
            opacity: 0;
            visibility: hidden;
            transition: opacity .2s ease, visibility .2s ease;
        }
        .hd-loading-overlay.is-visible {
            opacity: 1;
            visibility: visible;
        }
        .hd-loading-panel {
            background: #fff;
            border-radius: 18px;
            padding: 1.75rem 1.5rem 1.35rem;
            text-align: center;
            box-shadow: 0 24px 50px rgba(0, 0, 0, .22);
            min-width: 240px;
        }
        .hd-loading-lottie {
            width: 120px;
            height: 120px;
            margin: 0 auto .35rem;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .hd-loading-lottie dotlottie-wc {
            width: 120px !important;
            height: 120px !important;
            display: block;
        }
        .hd-loading-panel p {
            margin: 0;
            font-size: .92rem;
            font-weight: 600;
            color: #334155;
        }
    </style>
</head>
<body class="visitor-page">
    <div class="container px-3">
        <div class="card hd-card">
            <div class="hd-header<?= $submitted && $accessValid ? ' is-success' : '' ?>">
                <div class="hd-badge mb-2"><i class="bi bi-headset"></i> Helpdesk IT</div>
                <?php if ($submitted && $accessValid): ?>
                    <h1 class="h4 mb-0">Laporan diterima</h1>
                    <p class="mb-0 mt-1 small opacity-90">
                        <?= $isRoomMode && $roomName !== ''
                            ? htmlspecialchars($roomName)
                            : ($isRoomMode ? 'Kendala ruangan' : 'Kendala event') ?>
                    </p>
                <?php elseif ($isRoomMode): ?>
                    <h1 class="h4 mb-0"><?= htmlspecialchars($roomName !== '' ? $roomName : 'Lapor Kendala Ruangan') ?></h1>
                    <?php if ($roomMeta !== ''): ?>
                        <div class="hd-room-meta"><?= htmlspecialchars($roomMeta) ?></div>
                    <?php endif; ?>
                    <p class="mb-0 mt-2 small opacity-90">Pilih kategori kendala, lalu kirim laporan.</p>
                <?php else: ?>
                    <h1 class="h4 mb-0">Lapor Kendala Event</h1>
                    <p class="mb-0 mt-1 small opacity-90">Isi data pelapor dan pilih kategori kendala.</p>
                <?php endif; ?>
            </div>
            <div class="card-body p-4">
                <?php if (!$accessValid): ?>
                    <div class="alert alert-danger mb-0">
                        <i class="bi bi-shield-x"></i>
                        Link tidak valid atau barcode sudah diganti admin. Minta barcode terbaru ke admin IT.
                    </div>
                <?php elseif ($submitted): ?>
                    <div class="hd-success">
                        <div class="hd-success-lottie" aria-hidden="true">
                            <dotlottie-wc
                                id="hdSuccessLottie"
                                src="<?= htmlspecialchars($helpdeskLottieUrl, ENT_QUOTES, 'UTF-8') ?>"
                                backgroundColor="transparent"
                                speed="1"
                                loop
                                autoplay
                            ></dotlottie-wc>
                        </div>
                        <h2 class="h4">Laporan terkirim</h2>
                        <p class="lead-msg">
                            <?= $isRoomMode
                                ? 'Tim Helpdesk IT sudah menerima laporan dan akan segera menindaklanjuti kendala di ruangan ini.'
                                : 'Tim Helpdesk IT sudah menerima laporan dan akan menghubungi Anda sesuai nomor yang diisi.' ?>
                        </p>
                        <?php if ($ticketId > 0): ?>
                            <div class="hd-ticket-chip">
                                <i class="bi bi-ticket-perforated"></i>
                                Tiket #<?= (int) $ticketId ?>
                            </div>
                        <?php endif; ?>
                        <div class="hd-next">
                            <div class="hd-next-title">Langkah berikutnya</div>
                            <div class="hd-next-row">
                                <i class="bi bi-bell"></i>
                                <span>Notifikasi sudah dikirim ke petugas Helpdesk IT.</span>
                            </div>
                            <div class="hd-next-row">
                                <i class="bi bi-hourglass-split"></i>
                                <span><?= $isRoomMode
                                    ? 'Petugas akan datang ke ruangan setelah konfirmasi.'
                                    : 'Anda akan dihubungi via WhatsApp setelah petugas konfirmasi.' ?></span>
                            </div>
                        </div>
                        <a href="helpdesk-it.php?k=<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-primary btn-again">
                            <i class="bi bi-plus-lg"></i> Kirim laporan lain
                        </a>
                    </div>
                <?php else: ?>
                    <form id="helpdeskForm" class="vstack gap-3">
                        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

                        <?php if (!$isRoomMode): ?>
                            <div>
                                <label class="form-label">Nama *</label>
                                <input type="text" name="nama" class="form-control" required placeholder="Nama lengkap">
                            </div>
                            <div>
                                <label class="form-label">Nomor *</label>
                                <input type="tel" name="nomor" class="form-control" required placeholder="08xxxxxxxxxx">
                            </div>
                        <?php endif; ?>

                        <div>
                            <label class="form-label d-block mb-2">Kategori Kendala *</label>
                            <div class="hd-cat-grid" id="hdCatGrid">
                                <?php foreach ($issueCategories as $key => $cat): ?>
                                    <label class="hd-cat-card" data-cat="<?= htmlspecialchars($key) ?>">
                                        <input type="radio" name="issue_category" value="<?= htmlspecialchars($key) ?>" required>
                                        <i class="bi <?= htmlspecialchars($cat['icon']) ?>"></i>
                                        <span><?= htmlspecialchars($cat['label']) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div>
                            <label class="form-label">Catatan tambahan <span class="text-muted">(opsional)</span></label>
                            <textarea name="kendala" class="form-control" rows="3" placeholder="Jelaskan detail kendala jika perlu..."></textarea>
                        </div>

                        <div id="hdError" class="alert alert-danger py-2 small d-none"></div>
                        <button type="submit" class="btn btn-primary w-100 py-2" id="hdSubmitBtn">
                            <i class="bi bi-send"></i> Kirim Laporan
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="hd-loading-overlay" id="hdLoadingOverlay" aria-hidden="true" aria-live="polite">
        <div class="hd-loading-panel">
            <div class="hd-loading-lottie" id="hdLoadingLottie">
                <?php if ($accessValid && !$submitted): ?>
                <dotlottie-wc
                    id="hdLoadingPlayer"
                    src="<?= htmlspecialchars($loadingLottieUrl, ENT_QUOTES, 'UTF-8') ?>"
                    backgroundColor="transparent"
                    speed="1"
                    loop
                ></dotlottie-wc>
                <?php endif; ?>
            </div>
            <p id="hdLoadingText">Mengirim laporan...</p>
        </div>
    </div>
    <?php if ($accessValid && $submitted): ?>
    <script>
    (function () {
        function playSuccessLottie() {
            var player = document.getElementById('hdSuccessLottie');
            if (!player) return;
            var start = function () {
                if (player.dotLottie) {
                    player.dotLottie.play();
                }
            };
            if (player.dotLottie) {
                start();
                return;
            }
            player.addEventListener('ready', start, { once: true });
        }

        if (window.customElements && window.customElements.whenDefined) {
            window.customElements.whenDefined('dotlottie-wc').then(playSuccessLottie).catch(function () {});
        } else {
            document.addEventListener('DOMContentLoaded', playSuccessLottie);
        }
    })();
    </script>
    <?php endif; ?>
    <?php if ($accessValid && !$submitted): ?>
    <script>
    (function () {
        function getLoadingPlayer() {
            return document.getElementById('hdLoadingPlayer');
        }

        var playerReadyPromise = null;

        function ensureLoadingPlayer() {
            if (playerReadyPromise) {
                return playerReadyPromise;
            }
            playerReadyPromise = new Promise(function (resolve) {
                if (window.customElements && window.customElements.whenDefined) {
                    window.customElements.whenDefined('dotlottie-wc').then(resolve).catch(resolve);
                } else {
                    resolve();
                }
            }).then(function () {
                return new Promise(function (resolve) {
                    var player = getLoadingPlayer();
                    if (!player) {
                        resolve();
                        return;
                    }
                    if (player.dotLottie) {
                        resolve();
                        return;
                    }
                    var done = function () {
                        player.removeEventListener('ready', done);
                        resolve();
                    };
                    player.addEventListener('ready', done);
                    setTimeout(resolve, 1500);
                });
            });
            return playerReadyPromise;
        }

        function restartLoadingPlayer() {
            var player = getLoadingPlayer();
            if (!player || !player.dotLottie) return;
            player.dotLottie.stop();
            player.dotLottie.play();
        }

        function showLoading(message) {
            var overlay = document.getElementById('hdLoadingOverlay');
            var textEl = document.getElementById('hdLoadingText');
            if (!overlay) {
                return Promise.resolve();
            }
            if (textEl) {
                textEl.textContent = message || 'Mengirim laporan...';
            }
            overlay.classList.add('is-visible');
            overlay.setAttribute('aria-hidden', 'false');
            return ensureLoadingPlayer().then(function () {
                restartLoadingPlayer();
            });
        }

        function hideLoading() {
            var overlay = document.getElementById('hdLoadingOverlay');
            if (!overlay) return;
            overlay.classList.remove('is-visible');
            overlay.setAttribute('aria-hidden', 'true');
            var player = getLoadingPlayer();
            if (player && player.dotLottie) {
                player.dotLottie.stop();
            }
        }

        document.addEventListener('DOMContentLoaded', function () {
            var grid = document.getElementById('hdCatGrid');
            if (grid) {
                grid.querySelectorAll('.hd-cat-card').forEach(function (card) {
                    card.addEventListener('click', function () {
                        grid.querySelectorAll('.hd-cat-card').forEach(function (c) { c.classList.remove('is-selected'); });
                        card.classList.add('is-selected');
                        var input = card.querySelector('input[type="radio"]');
                        if (input) input.checked = true;
                    });
                });
            }

            document.getElementById('helpdeskForm')?.addEventListener('submit', async function (e) {
                e.preventDefault();
                var btn = document.getElementById('hdSubmitBtn');
                var err = document.getElementById('hdError');
                var selected = document.querySelector('input[name="issue_category"]:checked');
                if (!selected) {
                    err.textContent = 'Pilih kategori kendala terlebih dahulu.';
                    err.classList.remove('d-none');
                    return;
                }
                err.classList.add('d-none');
                btn.disabled = true;
                await showLoading('Mengirim laporan...');
                try {
                    var res = await fetch('../api/helpdesk_it_submit.php', {
                        method: 'POST',
                        body: new FormData(e.target),
                    });
                    var data = await res.json();
                    if (data.success) {
                        await showLoading('Laporan terkirim...');
                        var tid = data.ticket_id ? ('&t=' + encodeURIComponent(data.ticket_id)) : '';
                        window.location.href = 'helpdesk-it.php?k=<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>&sent=1' + tid;
                        return;
                    }
                    hideLoading();
                    err.textContent = data.message || 'Gagal mengirim laporan.';
                    err.classList.remove('d-none');
                } catch {
                    hideLoading();
                    err.textContent = 'Koneksi gagal. Coba lagi.';
                    err.classList.remove('d-none');
                } finally {
                    if (!window.location.href.includes('sent=1')) {
                        btn.disabled = false;
                    }
                }
            });
        });
    })();
    </script>
    <?php endif; ?>
</body>
</html>

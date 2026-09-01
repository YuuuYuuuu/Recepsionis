<?php
require_once 'auth.php';
require_once '../staff_call_routing.php';
require_once '../lib/qr_svg.php';
require_once 'helpdesk_hub.php';

requireHelpdeskManagerPage();

if (!defined('HELPDESK_HUB')) {
    helpdeskRedirectToHub('qr');
}

if (isset($_POST['regenerate_event_token'])) {
    recepsionis_regenerate_helpdesk_it_event_token($koneksi);
    header('Location: ' . helpdeskUrl('qr', ['success' => 'event']));
    exit;
}

if (isset($_POST['sync_room_tokens'])) {
    recepsionis_sync_helpdesk_it_room_tokens($koneksi);
    header('Location: ' . helpdeskUrl('qr', ['success' => 'sync']));
    exit;
}

recepsionis_sync_helpdesk_it_room_tokens($koneksi);

$eventAccess = recepsionis_get_helpdesk_it_event_access($koneksi);
$eventToken = trim((string) ($eventAccess['public_token'] ?? ''));
$roomAccesses = recepsionis_get_helpdesk_it_room_accesses($koneksi);
$visitorPage = recepsionis_helpdesk_it_visitor_page_url();
$eventUrl = $eventToken !== '' ? recepsionis_helpdesk_it_public_url($eventToken) : '';
$eventQrSvg = $eventUrl !== '' ? recepsionis_qr_svg($eventUrl, 180) : '';
?>
<style>
        .qr-page { max-width: 1100px; }
        .qr-hero {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            flex-wrap: wrap;
            margin-bottom: 1.25rem;
        }
        .qr-hero h2 { margin: 0; font-size: 1.45rem; font-weight: 700; }
        .qr-section {
            background: #fff;
            border: 1px solid var(--adm-border, #dde3ec);
            border-radius: 18px;
            box-shadow: 0 1px 2px rgba(21, 32, 43, 0.06), 0 10px 28px rgba(21, 32, 43, 0.05);
            margin-bottom: 1rem;
            overflow: hidden;
        }
        .qr-section-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: .75rem;
            padding: 1rem 1.15rem;
            border-bottom: 1px solid var(--adm-border, #dde3ec);
            background: #f8fafc;
        }
        .qr-section-head h3 {
            margin: 0;
            font-size: 1rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: .45rem;
        }
        .qr-section-body { padding: 1.15rem; }
        .qr-event-wrap {
            display: grid;
            grid-template-columns: auto 1fr;
            gap: 1.25rem;
            align-items: start;
        }
        .qr-frame {
            width: 200px;
            padding: .75rem;
            border-radius: 16px;
            background: linear-gradient(#fff, #fff) padding-box, linear-gradient(145deg, #0f6e56, #0ea5e9) border-box;
            border: 2px solid transparent;
        }
        .qr-frame svg, .qr-frame img { display: block; width: 160px; height: 160px; }
        .qr-url-box {
            display: flex;
            gap: .5rem;
            align-items: center;
            background: #f4f7fa;
            border: 1px solid var(--adm-border, #dde3ec);
            border-radius: 12px;
            padding: .45rem .5rem .45rem .85rem;
            margin-bottom: .75rem;
        }
        .qr-url-box input {
            border: 0;
            background: transparent;
            font-size: .78rem;
            color: #334155;
            width: 100%;
            outline: none;
            min-width: 0;
        }
        .qr-room-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: .85rem;
        }
        .qr-room-card {
            border: 1px solid var(--adm-border, #dde3ec);
            border-radius: 14px;
            padding: .85rem;
            background: #fff;
        }
        .qr-room-card h4 {
            margin: 0 0 .15rem;
            font-size: .92rem;
            font-weight: 700;
        }
        .qr-room-card small {
            display: block;
            color: #64748b;
            margin-bottom: .65rem;
            font-size: .76rem;
        }
        .qr-room-qr {
            display: flex;
            justify-content: center;
            margin-bottom: .65rem;
        }
        .qr-room-qr svg, .qr-room-qr img { width: 120px; height: 120px; }
        .qr-toast {
            position: fixed; right: 1rem; bottom: 1rem; background: #0f6e56; color: #fff;
            padding: .65rem .95rem; border-radius: 999px; font-size: .85rem; font-weight: 600;
            opacity: 0; transform: translateY(8px); transition: .2s ease; z-index: 1080;
        }
        .qr-toast.show { opacity: 1; transform: translateY(0); }
        @media (max-width: 767.98px) {
            .qr-event-wrap { grid-template-columns: 1fr; justify-items: center; text-align: center; }
        }
</style>
<div class="qr-page">
                    <?php if (isset($_GET['success'])): ?>
                        <div class="alert alert-success alert-dismissible fade show">
                            <i class="bi bi-check-circle"></i>
                            <?php
                            if ($_GET['success'] === 'event') {
                                echo 'QR Event berhasil diganti.';
                            } elseif ($_GET['success'] === 'sync') {
                                echo 'Token ruangan berhasil disinkronkan.';
                            } else {
                                echo 'Perubahan berhasil disimpan.';
                            }
                            ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <div class="qr-hero">
                        <h2 class="mb-0"><i class="bi bi-qr-code-scan text-success"></i> QR Tiket Kelas</h2>
                        <a href="<?= htmlspecialchars(helpdeskUrl('tickets')) ?>" class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-headset"></i> Buka Helpdesk
                        </a>
                    </div>

                    <div class="qr-section">
                                <div class="qr-section-head">
                                    <h3><i class="bi bi-calendar-event"></i> QR Event / General</h3>
                                    <span class="badge text-bg-light border">Non-harian</span>
                                </div>
                                <div class="qr-section-body">
                                    <?php if ($eventUrl): ?>
                                        <div class="qr-event-wrap">
                                            <div class="qr-frame">
                                                <?php if ($eventQrSvg !== ''): ?>
                                                    <?= $eventQrSvg ?>
                                                <?php else: ?>
                                                    <?= recepsionis_qr_fallback_img($eventUrl, 160, 'QR Event') ?>
                                                <?php endif; ?>
                                            </div>
                                            <div class="w-100">
                                                <p class="text-muted small mb-2">Untuk seminar, acara, atau kegiatan di luar kelas harian. Pelapor mengisi nama & nomor.</p>
                                                <div class="qr-url-box">
                                                    <input type="text" readonly class="js-copy-url" value="<?= htmlspecialchars($eventUrl) ?>" title="<?= htmlspecialchars($eventUrl) ?>">
                                                    <button type="button" class="btn btn-sm btn-primary js-copy-btn"><i class="bi bi-clipboard"></i></button>
                                                </div>
                                                <div class="d-flex flex-wrap gap-2 qr-no-print">
                                                    <a class="btn btn-outline-primary btn-sm" href="<?= htmlspecialchars($eventUrl) ?>" target="_blank" rel="noopener">
                                                        <i class="bi bi-box-arrow-up-right"></i> Pratinjau
                                                    </a>
                                                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.open('<?= htmlspecialchars(adminUrl('helpdesk_it_print.php?type=event')) ?>', '_blank', 'noopener')">
                                                        <i class="bi bi-printer"></i> Cetak
                                                    </button>
                                                    <form method="post" class="m-0" onsubmit="return confirm('Ganti QR Event? QR lama tidak akan berfungsi.');">
                                                        <input type="hidden" name="regenerate_event_token" value="1">
                                                        <button type="submit" class="btn btn-warning btn-sm">
                                                            <i class="bi bi-arrow-repeat"></i> Regenerasi
                                                        </button>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <div class="alert alert-warning mb-0">Token event belum tersedia. Jalankan migrasi database.</div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="qr-section">
                                <div class="qr-section-head">
                                    <h3><i class="bi bi-door-open"></i> QR Per Ruangan</h3>
                                    <form method="post" class="m-0 qr-no-print">
                                        <input type="hidden" name="sync_room_tokens" value="1">
                                        <button type="submit" class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-arrow-repeat"></i> Sinkronkan
                                        </button>
                                    </form>
                                </div>
                                <div class="qr-section-body">
                                    <?php if (empty($roomAccesses)): ?>
                                        <div class="alert alert-warning mb-0">
                                            Belum ada ruangan aktif. Minta Super Admin menambahkan ruangan di modul E-Recepsionis.
                                        </div>
                                    <?php else: ?>
                                        <p class="text-muted small mb-3">Satu QR per ruangan aktif. Pelapor cukup pilih kategori kendala tanpa mengisi nama.</p>
                                        <div class="qr-room-grid">
                                            <?php foreach ($roomAccesses as $roomAccess): ?>
                                                <?php
                                                $roomToken = trim((string) ($roomAccess['public_token'] ?? ''));
                                                $roomUrl = $roomToken !== '' ? recepsionis_helpdesk_it_public_url($roomToken) : '';
                                                $roomQr = $roomUrl !== '' ? recepsionis_qr_svg($roomUrl, 120) : '';
                                                $roomTitle = trim((string) ($roomAccess['nama_ruangan'] ?? 'Ruangan'));
                                                $roomSub = trim(implode(' · ', array_filter([
                                                    (string) ($roomAccess['kode_ruangan'] ?? ''),
                                                    (string) ($roomAccess['gedung'] ?? ''),
                                                    trim((string) ($roomAccess['lantai'] ?? '')) !== '' ? 'Lt ' . trim((string) $roomAccess['lantai']) : '',
                                                ])));
                                                ?>
                                                <div class="qr-room-card">
                                                    <h4><?= htmlspecialchars($roomTitle) ?></h4>
                                                    <?php if ($roomSub !== ''): ?><small><?= htmlspecialchars($roomSub) ?></small><?php endif; ?>
                                                    <?php if ($roomUrl): ?>
                                                        <div class="qr-room-qr">
                                                            <?php if ($roomQr !== ''): ?>
                                                                <?= $roomQr ?>
                                                            <?php else: ?>
                                                                <?= recepsionis_qr_fallback_img($roomUrl, 120, 'QR ' . $roomTitle) ?>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="qr-url-box">
                                                            <input type="text" readonly class="js-copy-url" value="<?= htmlspecialchars($roomUrl) ?>" title="<?= htmlspecialchars($roomUrl) ?>">
                                                            <button type="button" class="btn btn-sm btn-outline-primary js-copy-btn"><i class="bi bi-clipboard"></i></button>
                                                        </div>
                                                        <div class="d-flex gap-2 qr-no-print">
                                                            <a class="btn btn-sm btn-outline-secondary flex-fill" href="<?= htmlspecialchars($roomUrl) ?>" target="_blank" rel="noopener">Pratinjau</a>
                                                            <button type="button" class="btn btn-sm btn-outline-secondary flex-fill" onclick="window.open('<?= htmlspecialchars(adminUrl('helpdesk_it_print.php?type=room&room_id=' . (int) ($roomAccess['room_id'] ?? 0))) ?>', '_blank', 'noopener')">Cetak</button>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
</div>

    <div class="qr-toast" id="qrToast">URL disalin</div>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const params = new URLSearchParams(window.location.search);
        if (params.get('success')) {
            params.delete('success');
            const next = window.location.pathname + (params.toString() ? '?' + params.toString() : '');
            window.history.replaceState({}, '', next);
        }

        const toast = document.getElementById('qrToast');
        function showToast(msg) {
            if (!toast) return;
            toast.textContent = msg;
            toast.classList.add('show');
            setTimeout(function () { toast.classList.remove('show'); }, 1600);
        }

        document.querySelectorAll('.js-copy-btn').forEach(function (btn) {
            btn.addEventListener('click', async function () {
                const input = btn.closest('.qr-url-box')?.querySelector('.js-copy-url');
                if (!input) return;
                try {
                    await navigator.clipboard.writeText(input.value);
                    showToast('URL disalin');
                } catch (e) {
                    input.select();
                    document.execCommand('copy');
                    showToast('URL disalin');
                }
            });
        });
    });
    </script>

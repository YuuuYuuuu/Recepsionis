<?php
require_once 'auth.php';
require_once '../staff_call_routing.php';

requireSuperAdminPage();

if (isset($_POST['regenerate_token'])) {
    recepsionis_regenerate_helpdesk_it_token($koneksi);
    header('Location: ' . adminUrl('helpdesk_it.php?success=token'));
    exit;
}

$access = recepsionis_get_helpdesk_it_access($koneksi);
$helpdeskCategoryId = recepsionis_get_helpdesk_category_id($koneksi);
$helpdeskCategoryName = '';
$helpdeskPicUsers = [];

if ($helpdeskCategoryId > 0) {
    foreach (recepsionis_get_complaint_categories($koneksi, true) as $category) {
        if ((int) $category['id'] === $helpdeskCategoryId) {
            $helpdeskCategoryName = (string) $category['nama_kategori'];
            break;
        }
    }
    $helpdeskPicUsers = recepsionis_get_active_category_admins($koneksi, $helpdeskCategoryId);
}

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$httpHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/admin'));
$parentDir = dirname($scriptDir);
$visitorBase = ($parentDir === '/' || $parentDir === '\\' || $parentDir === '.') ? '' : $parentDir;
$publicUrl = $access
    ? $scheme . '://' . $httpHost . $visitorBase . '/visitor/helpdesk-it.php?k=' . urlencode((string) $access['public_token'])
    : '';

function helpdesk_initials(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return '?';
    }
    $parts = preg_split('/\s+/', $name) ?: [];
    $letters = '';
    foreach (array_slice($parts, 0, 2) as $part) {
        $letters .= mb_strtoupper(mb_substr($part, 0, 1));
    }

    return $letters !== '' ? $letters : '?';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QR Tiket Kelas - E-Recepsionis</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <?php include 'include_staff_call_head.php'; ?>
    <style>
        .qr-page { max-width: 980px; }
        .qr-hero {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 1rem;
            flex-wrap: wrap;
            margin-bottom: 1.25rem;
        }
        .qr-hero h2 {
            margin: 0;
            font-size: 1.45rem;
            font-weight: 700;
            letter-spacing: -0.02em;
        }
        .qr-hero p {
            margin: 0.35rem 0 0;
            color: var(--adm-muted, #5f6f82);
            font-size: 0.92rem;
            max-width: 36rem;
        }
        .qr-main {
            display: grid;
            grid-template-columns: 1.1fr 0.9fr;
            gap: 1rem;
            align-items: start;
        }
        .qr-card, .qr-side {
            background: #fff;
            border: 1px solid var(--adm-border, #dde3ec);
            border-radius: 18px;
            box-shadow: 0 1px 2px rgba(21, 32, 43, 0.06), 0 10px 28px rgba(21, 32, 43, 0.05);
            overflow: hidden;
        }
        .qr-card-body {
            padding: 1.5rem 1.35rem 1.35rem;
            text-align: center;
        }
        .qr-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            background: var(--adm-accent-soft, #e6f4ef);
            color: var(--adm-accent, #0f6e56);
            border-radius: 999px;
            padding: 0.28rem 0.75rem;
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            margin-bottom: 0.85rem;
        }
        .qr-frame {
            width: min(240px, 70vw);
            aspect-ratio: 1;
            margin: 0 auto 1rem;
            padding: 0.85rem;
            border-radius: 20px;
            background:
                linear-gradient(#fff, #fff) padding-box,
                linear-gradient(145deg, #0f6e56, #0ea5e9) border-box;
            border: 2px solid transparent;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 12px 30px rgba(15, 110, 86, 0.12);
        }
        #helpdeskQr canvas,
        #helpdeskQr img {
            width: 100% !important;
            height: auto !important;
            max-width: 200px;
        }
        .qr-url-box {
            display: flex;
            gap: 0.5rem;
            align-items: center;
            background: #f4f7fa;
            border: 1px solid var(--adm-border, #dde3ec);
            border-radius: 12px;
            padding: 0.45rem 0.5rem 0.45rem 0.85rem;
            text-align: left;
            margin-bottom: 0.85rem;
        }
        .qr-url-box input {
            border: 0;
            background: transparent;
            font-size: 0.78rem;
            color: #334155;
            width: 100%;
            outline: none;
        }
        .qr-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            justify-content: center;
        }
        .qr-actions .btn {
            min-width: 120px;
        }
        .qr-side-body { padding: 1.15rem; }
        .qr-side-title {
            font-size: 0.95rem;
            font-weight: 700;
            margin-bottom: 0.85rem;
            display: flex;
            align-items: center;
            gap: 0.45rem;
        }
        .qr-meta {
            display: grid;
            gap: 0.65rem;
            margin-bottom: 1rem;
        }
        .qr-meta-item {
            display: flex;
            align-items: center;
            gap: 0.7rem;
            padding: 0.75rem 0.85rem;
            border-radius: 12px;
            background: #f7faf9;
            border: 1px solid #e5eee9;
        }
        .qr-meta-item i {
            width: 34px;
            height: 34px;
            border-radius: 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #fff;
            color: var(--adm-accent, #0f6e56);
            border: 1px solid #d7e8df;
            flex: 0 0 auto;
        }
        .qr-meta-item strong {
            display: block;
            font-size: 0.72rem;
            color: #7a8a9a;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .qr-meta-item span {
            display: block;
            font-size: 0.9rem;
            font-weight: 600;
            color: #15202b;
        }
        .qr-pic-list {
            display: flex;
            flex-direction: column;
            gap: 0.55rem;
        }
        .qr-pic {
            display: flex;
            align-items: center;
            gap: 0.7rem;
            padding: 0.65rem 0.75rem;
            border-radius: 12px;
            border: 1px solid var(--adm-border, #dde3ec);
            background: #fff;
        }
        .qr-pic-avatar {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: linear-gradient(145deg, #0f6e56, #155e75);
            color: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.78rem;
            font-weight: 700;
            flex: 0 0 auto;
        }
        .qr-pic strong {
            display: block;
            font-size: 0.9rem;
            color: #15202b;
        }
        .qr-pic small {
            color: #7a8a9a;
            text-transform: capitalize;
        }
        .qr-empty {
            border: 1px dashed #f0c36d;
            background: #fffbeb;
            color: #92400e;
            border-radius: 12px;
            padding: 0.85rem;
            font-size: 0.85rem;
        }
        .qr-toast {
            position: fixed;
            right: 1rem;
            bottom: 1rem;
            background: #0f6e56;
            color: #fff;
            padding: 0.65rem 0.95rem;
            border-radius: 999px;
            font-size: 0.85rem;
            font-weight: 600;
            box-shadow: 0 10px 24px rgba(15, 110, 86, 0.25);
            opacity: 0;
            transform: translateY(8px);
            transition: 0.2s ease;
            z-index: 1080;
            pointer-events: none;
        }
        .qr-toast.show {
            opacity: 1;
            transform: translateY(0);
        }
        @media (max-width: 991.98px) {
            .qr-main { grid-template-columns: 1fr; }
        }
        @media print {
            .sidebar, .navbar, .qr-steps, .qr-side, .qr-actions, .qr-hero a, .qr-url-box { display: none !important; }
            .content-area { padding: 0 !important; }
            .qr-main { grid-template-columns: 1fr; }
            .qr-card { box-shadow: none; border: 0; }
            .qr-frame { box-shadow: none; }
        }
    </style>
</head>
<body>
    <?php include 'navbar.php'; ?>
    <div class="container-fluid">
        <div class="row">
            <?php include 'sidebar.php'; ?>
            <div class="col-md-10 content-area">
                <div class="qr-page">
                    <?php if (isset($_GET['success']) && $_GET['success'] === 'token'): ?>
                        <div class="alert alert-success alert-dismissible fade show">
                            <i class="bi bi-check-circle"></i> Barcode baru berhasil dibuat.
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <div class="qr-hero">
                        <div>
                            <h2><i class="bi bi-qr-code-scan text-success"></i> QR Tiket Kelas</h2>
                            <p>Satu barcode untuk semua kelas. Scan → isi form → masuk antrian Helpdesk.</p>
                        </div>
                        <a href="<?= htmlspecialchars(adminUrl('staff_calls.php')) ?>" class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-headset"></i> Buka Helpdesk
                        </a>
                    </div>

                    <div class="qr-main">
                        <div class="qr-card">
                            <div class="qr-card-body">
                                <div class="qr-badge"><i class="bi bi-upc-scan"></i> Barcode global</div>
                                <?php if ($publicUrl): ?>
                                    <div class="qr-frame">
                                        <div id="helpdeskQr"></div>
                                    </div>
                                    <div class="qr-url-box">
                                        <input type="text" id="helpdeskUrl" readonly value="<?= htmlspecialchars($publicUrl) ?>">
                                        <button type="button" class="btn btn-sm btn-primary" id="copyHelpdeskUrl">
                                            <i class="bi bi-clipboard"></i> Salin
                                        </button>
                                    </div>
                                    <div class="qr-actions">
                                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print()">
                                            <i class="bi bi-printer"></i> Cetak
                                        </button>
                                        <a class="btn btn-outline-primary btn-sm" href="<?= htmlspecialchars($publicUrl) ?>" target="_blank" rel="noopener">
                                            <i class="bi bi-box-arrow-up-right"></i> Pratinjau
                                        </a>
                                        <form method="post" class="m-0" onsubmit="return confirm('Ganti barcode? QR lama tidak akan berfungsi.');">
                                            <input type="hidden" name="regenerate_token" value="1">
                                            <button type="submit" class="btn btn-warning btn-sm">
                                                <i class="bi bi-arrow-repeat"></i> Regenerasi
                                            </button>
                                        </form>
                                    </div>
                                <?php else: ?>
                                    <div class="alert alert-warning mb-0 text-start">
                                        Token belum tersedia. Jalankan migrasi database.
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="qr-side">
                            <div class="qr-side-body">
                                <div class="qr-side-title">
                                    <i class="bi bi-people"></i> Status & PIC
                                </div>
                                <div class="qr-meta">
                                    <div class="qr-meta-item">
                                        <i class="bi bi-tag"></i>
                                        <div>
                                            <strong>Kategori</strong>
                                            <span><?= $helpdeskCategoryName !== '' ? htmlspecialchars($helpdeskCategoryName) : 'Belum dikonfigurasi' ?></span>
                                        </div>
                                    </div>
                                    <div class="qr-meta-item">
                                        <i class="bi bi-diagram-3"></i>
                                        <div>
                                            <strong>Antrian</strong>
                                            <span>Gabung dengan Helpdesk</span>
                                        </div>
                                    </div>
                                    <div class="qr-meta-item">
                                        <i class="bi bi-whatsapp"></i>
                                        <div>
                                            <strong>Notifikasi</strong>
                                            <span>Panel admin + WhatsApp</span>
                                        </div>
                                    </div>
                                </div>

                                <?php if (empty($helpdeskPicUsers)): ?>
                                    <div class="qr-empty">
                                        Belum ada PIC aktif.
                                        Atur di <a href="<?= htmlspecialchars(adminUrl('users.php')) ?>">Kelola User</a>.
                                    </div>
                                <?php else: ?>
                                    <div class="qr-pic-list">
                                        <?php foreach ($helpdeskPicUsers as $picUser): ?>
                                            <?php
                                            $picName = trim((string) ($picUser['nama_lengkap'] ?: $picUser['username']));
                                            $picRole = (string) ($picUser['role'] ?? 'operator');
                                            ?>
                                            <div class="qr-pic">
                                                <div class="qr-pic-avatar"><?= htmlspecialchars(helpdesk_initials($picName)) ?></div>
                                                <div>
                                                    <strong><?= htmlspecialchars($picName) ?></strong>
                                                    <small><?= htmlspecialchars($picRole) ?></small>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="qr-toast" id="qrToast">URL disalin</div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const params = new URLSearchParams(window.location.search);
        if (params.get('success') === 'token') {
            params.delete('success');
            window.history.replaceState({}, '', window.location.pathname + (params.toString() ? '?' + params.toString() : ''));
        }

        const toast = document.getElementById('qrToast');
        function showToast(msg) {
            if (!toast) return;
            toast.textContent = msg;
            toast.classList.add('show');
            setTimeout(function () { toast.classList.remove('show'); }, 1600);
        }

        const copyBtn = document.getElementById('copyHelpdeskUrl');
        const urlInput = document.getElementById('helpdeskUrl');
        if (copyBtn && urlInput) {
            copyBtn.addEventListener('click', async function () {
                try {
                    await navigator.clipboard.writeText(urlInput.value);
                    showToast('URL disalin');
                } catch (e) {
                    urlInput.select();
                    document.execCommand('copy');
                    showToast('URL disalin');
                }
            });
        }

        <?php if ($publicUrl): ?>
        const el = document.getElementById('helpdeskQr');
        if (el && window.QRCode) {
            el.innerHTML = '';
            new QRCode(el, {
                text: <?= json_encode($publicUrl, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
                width: 200,
                height: 200,
                colorDark: '#0f172a',
                colorLight: '#ffffff',
                correctLevel: QRCode.CorrectLevel.M,
            });
        }
        <?php endif; ?>
    });
    </script>
    <?php include 'include_admin_footer.php'; ?>
</body>
</html>

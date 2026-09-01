<?php
require_once 'auth.php';
require_once '../staff_call_routing.php';
require_once '../lib/qr_svg.php';
require_once 'helpdesk_hub.php';

requireHelpdeskManagerPage();

$type = trim((string) ($_GET['type'] ?? ''));
$roomId = (int) ($_GET['room_id'] ?? 0);

$title = '';
$meta = '';
$hint = 'Lapor kendala IT';
$url = '';

if ($type === 'event') {
    $access = recepsionis_get_helpdesk_it_event_access($koneksi);
    $token = trim((string) ($access['public_token'] ?? ''));
    $url = $token !== '' ? recepsionis_helpdesk_it_public_url($token) : '';
    $title = 'Helpdesk IT';
    $meta = 'Event · Seminar';
    $hint = 'Isi data · Kirim';
} elseif ($type === 'room' && $roomId > 0) {
    $stmt = $koneksi->prepare(
        "SELECT a.public_token, r.nama_ruangan, r.kode_ruangan, r.gedung, r.lantai
         FROM helpdesk_it_access a
         INNER JOIN rooms r ON r.id = a.room_id
         WHERE a.status_aktif = 1
           AND a.access_type = 'room'
           AND r.status_aktif = 1
           AND r.id = ?
         LIMIT 1"
    );
    if ($stmt) {
        $stmt->bind_param('i', $roomId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            $token = trim((string) ($row['public_token'] ?? ''));
            $url = $token !== '' ? recepsionis_helpdesk_it_public_url($token) : '';
            $title = trim((string) ($row['nama_ruangan'] ?? 'Ruangan'));
            $meta = trim(implode(' · ', array_filter([
                (string) ($row['kode_ruangan'] ?? ''),
                (string) ($row['gedung'] ?? ''),
                trim((string) ($row['lantai'] ?? '')) !== '' ? 'Lt ' . trim((string) $row['lantai']) : '',
            ])));
            $hint = 'Pilih kategori · Kirim';
        }
    }
}

if ($url === '') {
    http_response_code(404);
    echo 'QR tidak ditemukan.';
    exit;
}

$qrSvg = recepsionis_qr_svg($url, 220);
$autoPrint = !isset($_GET['preview']);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cetak QR — <?= htmlspecialchars($title) ?></title>
    <link href="../assets/css/qr-with-logo.css" rel="stylesheet">
    <style>
        :root {
            --blue: #0b3b8c;
            --blue-light: #e8efff;
            --ink: #0f172a;
            --muted: #94a3b8;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: "Segoe UI", system-ui, sans-serif;
            background: #eef2f6;
            color: var(--ink);
        }

        .print-toolbar {
            position: sticky;
            top: 0;
            z-index: 10;
            display: flex;
            justify-content: center;
            gap: .6rem;
            padding: .75rem 1rem;
            background: #fff;
            border-bottom: 1px solid #e2e8f0;
        }

        .print-toolbar button,
        .print-toolbar a {
            border: 0;
            border-radius: 999px;
            padding: .5rem 1rem;
            font-size: .85rem;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
        }

        .btn-print { background: var(--blue); color: #fff; }
        .btn-back { background: #f1f5f9; color: #475569; }

        .print-stage {
            min-height: calc(100vh - 56px);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.25rem;
        }

        .qr-card {
            width: 100mm;
            background: #fff;
            border: 2pt solid var(--blue);
            padding: 2.5mm;
            position: relative;
            box-shadow: 0 12px 40px rgba(15, 23, 42, .1);
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .qr-card::before {
            content: "";
            position: absolute;
            inset: 2.5mm;
            border: 0.75pt solid #94a3b8;
            pointer-events: none;
        }

        .qr-card-accent {
            height: 3px;
            background: linear-gradient(90deg, var(--blue), #0ea5e9);
            margin: 0 0 5mm;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .qr-card-body {
            position: relative;
            z-index: 1;
            padding: 4mm 7mm 7mm;
            text-align: center;
        }

        .qr-corner {
            position: absolute;
            width: 7mm;
            height: 7mm;
            border: 2pt solid var(--blue);
            z-index: 2;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .qr-corner-tl { top: 1.5mm; left: 1.5mm; border-right: 0; border-bottom: 0; }
        .qr-corner-tr { top: 1.5mm; right: 1.5mm; border-left: 0; border-bottom: 0; }
        .qr-corner-bl { bottom: 1.5mm; left: 1.5mm; border-right: 0; border-top: 0; }
        .qr-corner-br { bottom: 1.5mm; right: 1.5mm; border-left: 0; border-top: 0; }

        .qr-head {
            margin: 0 -4mm 5mm;
            padding: 4mm 4mm 4.5mm;
            background: linear-gradient(180deg, var(--blue-light) 0%, #fff 100%);
            border-bottom: 0.75pt solid #bfdbfe;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .qr-kicker {
            display: inline-flex;
            align-items: center;
            gap: 2mm;
            font-size: 7.5pt;
            font-weight: 700;
            letter-spacing: .14em;
            text-transform: uppercase;
            color: var(--blue);
            margin-bottom: 2.5mm;
        }

        .qr-kicker-icon {
            width: 6mm;
            height: 6mm;
            border-radius: 50%;
            display: inline-grid;
            place-items: center;
            background: var(--blue);
            color: #fff;
            font-size: 9pt;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .qr-title {
            margin: 0;
            font-size: 15pt;
            font-weight: 800;
            line-height: 1.2;
            letter-spacing: -.02em;
        }

        .qr-meta {
            margin: 2mm 0 0;
            font-size: 8.5pt;
            color: #64748b;
            font-weight: 500;
        }

        .qr-frame {
            display: inline-block;
            margin: 0 auto 4mm;
            padding: 3.5mm;
            border-radius: 8px;
            border: 1.5pt solid var(--blue);
            background: #fff;
            box-shadow: 0 0 0 1.5mm var(--blue-light);
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .qr-scan-badge {
            display: inline-flex;
            align-items: center;
            gap: 1.5mm;
            margin-bottom: 3mm;
            padding: 1.5mm 4mm;
            border-radius: 999px;
            background: var(--blue);
            color: #fff;
            font-size: 8pt;
            font-weight: 800;
            letter-spacing: .06em;
            text-transform: uppercase;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .qr-scan-badge i { font-size: 9pt; }

        .qr-frame svg,
        .qr-frame img {
            display: block;
            width: 52mm;
            height: 52mm;
        }

        .qr-hint {
            display: inline-block;
            font-size: 7.5pt;
            font-weight: 700;
            color: var(--blue);
            letter-spacing: .03em;
            padding: 1.5mm 3.5mm;
            border-radius: 6px;
            background: var(--blue-light);
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .qr-foot {
            margin-top: 5mm;
            padding-top: 4mm;
            border-top: 0.75pt solid #cbd5e1;
            font-size: 7pt;
            color: var(--muted);
            font-weight: 600;
        }

        @media print {
            body { background: #fff; }
            .print-toolbar { display: none !important; }
            .print-stage {
                min-height: auto;
                padding: 0;
                display: block;
            }
            .qr-card {
                width: 100mm;
                margin: 0 auto;
                box-shadow: none;
                border: 2pt solid var(--blue);
            }
            .qr-card::before {
                border-color: #64748b;
            }
            @page { size: auto; margin: 10mm; }
        }
    </style>
</head>
<body>
    <div class="print-toolbar">
        <a class="btn-back" href="<?= htmlspecialchars(function_exists('helpdeskUrl') ? helpdeskUrl('qr') : adminUrl('helpdesk_dashboard.php?section=qr')) ?>"><i class="bi bi-arrow-left"></i> Kembali</a>
        <button type="button" class="btn-print" onclick="window.print()"><i class="bi bi-printer"></i> Cetak</button>
    </div>

    <div class="print-stage">
        <article class="qr-card">
            <span class="qr-corner qr-corner-tl" aria-hidden="true"></span>
            <span class="qr-corner qr-corner-tr" aria-hidden="true"></span>
            <span class="qr-corner qr-corner-bl" aria-hidden="true"></span>
            <span class="qr-corner qr-corner-br" aria-hidden="true"></span>
            <div class="qr-card-body">
                <div class="qr-card-accent"></div>
                <header class="qr-head">
                    <div class="qr-kicker">
                        <span class="qr-kicker-icon"><i class="bi bi-headset"></i></span>
                        Helpdesk IT
                    </div>
                    <h1 class="qr-title"><?= htmlspecialchars($title) ?></h1>
                    <?php if ($meta !== ''): ?>
                        <p class="qr-meta"><?= htmlspecialchars($meta) ?></p>
                    <?php endif; ?>
                </header>

                <div class="qr-scan-badge"><i class="bi bi-qr-code-scan"></i> Scan di sini</div>

                <div class="qr-frame">
                    <?php if ($qrSvg !== ''): ?>
                        <?= $qrSvg ?>
                    <?php else: ?>
                        <?= recepsionis_qr_fallback_img($url, 220) ?>
                    <?php endif; ?>
                </div>

                <div class="qr-hint"><?= htmlspecialchars($hint) ?></div>
            </div>
        </article>
    </div>

    <?php if ($autoPrint): ?>
    <script>
    window.addEventListener('load', function () {
        setTimeout(function () { window.print(); }, 300);
    });
    </script>
    <?php endif; ?>
</body>
</html>

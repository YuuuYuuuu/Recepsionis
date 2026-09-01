<?php
require_once '../config.php';

// Get all active prodi
$all_prodi = $koneksi->query("SELECT * FROM prodi 
                               WHERE status_aktif = 1 
                               ORDER BY fakultas, nama_prodi ASC");
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Program Studi - E-Recepsionis System</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Sora:wght@500;600;700&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <link href="assets/landing/assets/visitor-landing.css" rel="stylesheet">
    <link href="../assets/css/visitor-unified.css" rel="stylesheet">
    <link href="../assets/css/qr-with-logo.css" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script src="../assets/js/qr-with-logo.js"></script>
    <?php require_once '../lib/qr_svg.php'; ?>
    <script>
        window.__QR_LOGO_URL__ = <?= json_encode(recepsionis_qr_logo_url(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        window.__QR_LOGO_ASPECT__ = <?= json_encode(recepsionis_qr_logo_aspect_ratio(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    </script>
    <style>
        :root {
            --bg: #f2f4f8;
            --surface: #ffffff;
            --surface-soft: #f8fafc;
            --text-primary: #0f172a;
            --text-secondary: #475569;
            --border: #e2e8f0;
            --primary: #0b3b8c;
            --primary-soft: #e8efff;
            --accent: #1e56b3;
            --shadow: 0 12px 40px rgba(15, 23, 42, 0.10);
            --shadow-soft: 0 8px 20px rgba(15, 23, 42, 0.08);
            --radius-lg: 22px;
            --radius-md: 14px;
            --radius-sm: 10px;
            /* Ruang untuk header + footer fixed (judul halaman & running text) */
            --prodi-header-offset: 80px;
            --prodi-footer-offset: 112px;
        }

        body {
            background: radial-gradient(circle at top right, #f8fbff 0%, var(--bg) 55%);
            min-height: 100vh;
            font-family: 'Plus Jakarta Sans', Inter, 'Segoe UI', sans-serif;
            margin: 0;
            padding: 0;
            color: var(--text-primary);
        }

        .prodi-page-container {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* Header — tetap di atas saat scroll */
        .prodi-page-header {
            background: rgba(255, 255, 255, 0.92);
            color: var(--text-primary);
            padding: 16px 40px;
            text-align: center;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 100;
            border-bottom: 1px solid rgba(15, 23, 42, 0.08);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            box-sizing: border-box;
        }

        .prodi-page-header h1 {
            font-family: Sora, 'Plus Jakarta Sans', sans-serif;
            font-size: 1.15rem;
            font-weight: 600;
            margin: 0;
            color: var(--text-primary);
            letter-spacing: 0.02em;
            text-transform: none;
        }

        .back-button {
            position: absolute;
            top: 50%;
            left: 20px;
            transform: translateY(-50%);
            background: var(--surface);
            border: 1px solid var(--border);
            color: var(--primary);
            padding: 8px 16px;
            border-radius: 999px;
            text-decoration: none;
            transition: all 0.3s;
            font-size: 0.88rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 2px 8px rgba(15, 23, 42, 0.08);
        }

        .back-button:hover {
            background: var(--primary-soft);
            color: var(--primary);
            border-color: rgba(11, 59, 140, 0.35);
            transform: translateY(-50%) translateX(-2px);
        }

        /* Main Content Area — offset supaya tidak di bawah header/footer fixed */
        .prodi-carousel-container {
            flex: 1;
            padding: var(--prodi-header-offset) 24px var(--prodi-footer-offset);
            position: relative;
            min-height: 100vh;
            box-sizing: border-box;
        }

        .prodi-carousel {
            position: relative;
            max-width: 1240px;
            margin: 0 auto;
        }

        .prodi-carousel-item-wrapper {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            overflow: hidden;
            display: flex;
            min-height: 580px;
            box-shadow: var(--shadow);
            transition: transform 0.3s;
        }

        .carousel-item.active .prodi-carousel-item-wrapper {
            animation: fadeSlideIn 0.6s ease;
        }

        /* Left Section - Image */
        .prodi-image-section {
            flex: 1.2;
            background: #dfe7f4;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            min-height: 580px;
            overflow: hidden;
        }

        .prodi-image-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(to top, rgba(8, 18, 40, 0.42), rgba(8, 18, 40, 0.06));
            z-index: 1;
        }

        .prodi-image-wrapper {
            position: relative;
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 2;
        }

        .prodi-image-section img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.7s ease;
            display: block;
        }

        .carousel-item.active .prodi-image-section img {
            transform: scale(1.03);
        }

        .prodi-image-section i {
            font-size: 6rem;
            color: rgba(255, 255, 255, 0.85);
            z-index: 2;
        }

        /* Right Section - Info */
        .prodi-info-section {
            flex: 1;
            background: var(--surface);
            padding: 40px 36px 32px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
        }

        .prodi-info-content {
            flex: 1;
        }

        .prodi-title {
            font-size: clamp(1.65rem, 1.5vw + 1rem, 2.25rem);
            font-weight: 800;
            color: var(--text-primary);
            margin-bottom: 18px;
            line-height: 1.25;
            letter-spacing: -0.02em;
        }

        .prodi-meta {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .prodi-meta-badge {
            background: var(--primary-soft);
            color: var(--accent);
            border: 1px solid rgba(30, 86, 179, 0.15);
            padding: 6px 12px;
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 600;
            letter-spacing: 0.01em;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .prodi-description {
            color: var(--text-secondary);
            line-height: 1.75;
            font-size: 0.98rem;
            margin-bottom: 20px;
            text-align: left;
            max-width: 62ch;
        }

        .prodi-contact-section,
        .prodi-qr-section {
            height: 100%;
            min-height: 260px;
            padding: 18px;
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            background: linear-gradient(180deg, #f8fbff 0%, var(--surface-soft) 100%);
            display: flex;
            flex-direction: column;
        }

        .prodi-contact-section {
            margin-bottom: 0;
        }

        .prodi-contact-title,
        .prodi-qr-title {
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 0 0 14px;
            font-size: 0.92rem;
            font-weight: 700;
            color: var(--text-primary);
            letter-spacing: 0.01em;
            flex-shrink: 0;
        }

        .prodi-contact-title i,
        .prodi-qr-title i {
            color: var(--accent);
        }

        .prodi-contact-grid {
            display: flex;
            flex-direction: column;
            gap: 10px;
            flex: 1;
        }

        .prodi-contact-item {
            display: flex;
            flex-direction: column;
            gap: 4px;
            padding: 12px 14px;
            border-radius: var(--radius-sm);
            background: var(--surface);
            border: 1px solid var(--border);
            text-decoration: none;
            color: inherit;
            transition: border-color 0.2s ease, box-shadow 0.2s ease, transform 0.2s ease;
            width: 100%;
        }

        .prodi-contact-item--action:hover {
            border-color: rgba(11, 59, 140, 0.28);
            box-shadow: var(--shadow-soft);
            transform: translateY(-1px);
            color: inherit;
        }

        .prodi-contact-label {
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: #64748b;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .prodi-contact-value {
            font-size: 0.92rem;
            font-weight: 600;
            color: var(--text-primary);
            line-height: 1.45;
            word-break: break-word;
        }

        .prodi-contact-item--action .prodi-contact-value {
            color: var(--accent);
        }

        .prodi-contact-empty {
            margin: 0;
            font-size: 0.86rem;
            color: #64748b;
            line-height: 1.5;
            flex: 1;
            display: flex;
            align-items: center;
        }

        .prodi-bottom-panels {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
            align-items: stretch;
        }

        /* QR Code Section */
        .prodi-qr-section {
            justify-content: flex-start;
            align-items: center;
            text-align: center;
        }

        .prodi-qr-body {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
        }

        .prodi-qr-wrapper {
            display: inline-block;
            padding: 10px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            box-shadow: var(--shadow-soft);
        }

        .prodi-qr-code {
            display: inline-block;
            width: 120px;
            height: 120px;
        }

        /* Carousel Controls */
        .carousel-control-prev,
        .carousel-control-next {
            width: 56px;
            height: 56px;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(6px);
            border: 1px solid rgba(11, 59, 140, 0.18);
            border-radius: 50%;
            top: 50%;
            transform: translateY(-50%);
            opacity: 1;
            transition: all 0.3s;
            z-index: 10;
            box-shadow: var(--shadow-soft);
        }

        .carousel-control-prev:hover,
        .carousel-control-next:hover {
            background: white;
            border-color: rgba(11, 59, 140, 0.42);
            transform: translateY(-50%) scale(1.06);
            box-shadow: 0 14px 28px rgba(15, 23, 42, 0.16);
        }

        .carousel-control-prev {
            left: -26px;
        }

        .carousel-control-next {
            right: -26px;
        }

        .carousel-control-prev-icon,
        .carousel-control-next-icon {
            filter: brightness(0) saturate(100%) invert(18%) sepia(39%) saturate(2952%) hue-rotate(204deg) brightness(97%) contrast(95%);
            width: 24px;
            height: 24px;
        }

        /* Carousel Indicators */
        .carousel-indicators {
            bottom: 20px;
            position: absolute;
            left: 50%;
            transform: translateX(-50%);
            margin: 0;
            padding: 0;
            list-style: none;
            display: flex;
            gap: 8px;
            z-index: 15;
        }

        .carousel-indicators button {
            background-color: rgba(15, 23, 42, 0.2);
            width: 9px;
            height: 9px;
            border-radius: 50%;
            opacity: 1;
            border: 0;
            padding: 0;
            margin: 0;
            cursor: pointer;
            transition: all 0.25s;
        }

        .carousel-indicators button.active {
            background-color: var(--primary);
            transform: scale(1.1);
            width: 30px;
            border-radius: 999px;
        }

        /* Carousel Item */
        .carousel-item {
            display: block;
            opacity: 0;
            transition: opacity 0.6s ease-in-out;
        }

        .carousel-item.active {
            opacity: 1;
        }

        /* Footer / running text — tetap di bawah layar saat scroll */
        .prodi-footer {
            background: #0f172a;
            padding: 11px clamp(16px, 5vw, 40px);
            padding-right: max(40px, 5.5rem);
            text-align: center;
            color: #cbd5e1;
            font-size: 0.83rem;
            border-top: 0;
            letter-spacing: 0.02em;
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            z-index: 100;
            box-sizing: border-box;
        }

        @keyframes fadeSlideIn {
            from {
                opacity: 0;
                transform: translateY(12px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Responsive */
        @media (max-width: 1200px) {
            .carousel-control-prev {
                left: 12px;
            }

            .carousel-control-next {
                right: 12px;
            }
        }

        @media (max-width: 992px) {
            .prodi-carousel-item-wrapper {
                flex-direction: column;
                min-height: auto;
            }

            .prodi-image-section {
                flex: 1;
                min-height: 300px;
            }

            .prodi-info-section {
                flex: 1;
                padding: 30px 24px;
            }

            .prodi-title {
                font-size: 1.7rem;
            }

            .prodi-description {
                font-size: 0.95rem;
                margin-bottom: 16px;
            }

            .prodi-bottom-panels {
                grid-template-columns: 1fr;
            }

            .prodi-contact-section,
            .prodi-qr-section {
                min-height: auto;
            }

            .prodi-qr-code {
                width: 104px;
                height: 104px;
            }
        }

        @media (max-width: 768px) {
            :root {
                --prodi-header-offset: 92px;
                --prodi-footer-offset: 104px;
            }

            .prodi-page-header {
                padding: 12px 16px;
            }

            .prodi-page-header h1 {
                font-size: 0.9rem;
                padding-left: 80px;
                padding-right: 8px;
                text-align: left;
            }

            .back-button {
                left: 10px;
                padding: 6px 11px;
                font-size: 0.72rem;
            }

            .prodi-carousel-container {
                padding: var(--prodi-header-offset) 12px var(--prodi-footer-offset);
            }

            .prodi-info-section {
                padding: 24px 16px 18px;
            }

            .prodi-title {
                font-size: 1.5rem;
            }

            .prodi-description {
                font-size: 0.9rem;
                margin-bottom: 15px;
            }

            .carousel-control-prev,
            .carousel-control-next {
                width: 42px;
                height: 42px;
            }

            .carousel-control-prev {
                left: 5px;
            }

            .carousel-control-next {
                right: 5px;
            }

            .prodi-qr-code {
                width: 92px;
                height: 92px;
            }

            .prodi-qr-section {
                margin-top: 12px;
                padding: 15px 12px;
            }

            .prodi-meta-badge {
                font-size: 0.73rem;
                padding: 5px 10px;
            }
        }

        @media (max-width: 576px) {
            .prodi-image-section {
                min-height: 220px;
            }

            .prodi-page-header h1 {
                letter-spacing: 0.04em;
            }

            .prodi-description {
                font-size: 0.89rem;
            }
        }
    </style>
</head>
<body class="visitor-page visitor-unified-shell">
    <div class="visitor-shell-content">
    <div class="prodi-page-container">
        <!-- Header -->
        <div class="prodi-page-header">
            <a href="index.php" class="back-button">
                <i class="bi bi-arrow-left"></i> Kembali
            </a>
            <h1>Program Studi ITB KAMPUS JAKARTA</h1>
        </div>

        <!-- Main Content -->
        <div class="prodi-carousel-container">
            <?php if ($all_prodi && $all_prodi->num_rows > 0): ?>
                <div id="prodiCarousel" class="carousel slide prodi-carousel" data-bs-ride="false" data-bs-interval="false">
                    <div class="carousel-inner">
                        <?php 
                        $first = true;
                        while ($prodi = $all_prodi->fetch_assoc()): 
                            // Prepare photo path
                            $foto_url = '';
                            $foto_exists = false;
                            if (!empty($prodi['foto'])) {
                                $foto_filename = htmlspecialchars($prodi['foto']);
                                // Use relative path for URL (from visitor folder)
                                $foto_url = '../uploads/prodi/' . $foto_filename;
                                // Check if file exists using absolute path
                                $foto_absolute_path = BASE_PATH . '/uploads/prodi/' . $foto_filename;
                                $foto_exists = file_exists($foto_absolute_path);
                                
                                // Debug: uncomment to check paths
                                // error_log("Foto URL: " . $foto_url);
                                // error_log("Foto Path: " . $foto_absolute_path);
                                // error_log("File Exists: " . ($foto_exists ? 'YES' : 'NO'));
                            }
                            
                            // Prepare QR code data (use direct link if available, otherwise use prodi info)
                            if (!empty($prodi['direct_link'])) {
                                $qr_data = $prodi['direct_link'];
                            } else {
                                // Fallback to JSON format with prodi info if no direct link
                                $qr_data = json_encode([
                                    'nama_prodi' => $prodi['nama_prodi'],
                                    'kode_prodi' => $prodi['kode_prodi'] ?? '',
                                    'email' => $prodi['email'] ?? '',
                                    'kontak_person' => $prodi['kontak_person'] ?? '',
                                    'fakultas' => $prodi['fakultas'] ?? '',
                                    'jenjang' => $prodi['jenjang'] ?? ''
                                ]);
                            }
                            $qr_id = 'qr-' . $prodi['id'];
                        ?>
                            <div class="carousel-item <?= $first ? 'active' : '' ?>" data-prodi-id="<?= $prodi['id'] ?>">
                                <div class="prodi-carousel-item-wrapper">
                                    <!-- Left: Image Section -->
                                    <div class="prodi-image-section">
                                        <div class="prodi-image-wrapper">
                                            <?php 
                                            // Prepare photo
                                            if (!empty($prodi['foto'])) {
                                                $foto_name = htmlspecialchars($prodi['foto']);
                                                // Build paths - use relative path from visitor folder
                                                $foto_url = '../uploads/prodi/' . $foto_name;
                                                // Use BASE_PATH for file existence check
                                                $foto_path = BASE_PATH . '/uploads/prodi/' . $foto_name;
                                                
                                                // Check if file exists
                                                if (file_exists($foto_path)) {
                                                    // Display image with fallback
                                                    ?>
                                                    <img src="<?= $foto_url ?>" 
                                                         alt="<?= htmlspecialchars($prodi['nama_prodi']) ?>" 
                                                         onerror="this.onerror=null; this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                                    <i class="bi bi-mortarboard-fill" style="display: none;"></i>
                                                    <?php
                                                } else {
                                                    // File doesn't exist, show icon
                                                    ?>
                                                    <i class="bi bi-mortarboard-fill"></i>
                                                    <?php
                                                }
                                            } else {
                                                // No photo, show icon
                                                ?>
                                                <i class="bi bi-mortarboard-fill"></i>
                                                <?php
                                            }
                                            ?>
                                        </div>
                                    </div>

                                    <!-- Right: Info Section -->
                                    <div class="prodi-info-section">
                                        <div class="prodi-info-content">
                                            <h2 class="prodi-title"><?= htmlspecialchars($prodi['nama_prodi']) ?></h2>
                                            
                                            <div class="prodi-meta">
                                                <?php if (!empty($prodi['kode_prodi'])): ?>
                                                    <span class="prodi-meta-badge">
                                                        <i class="bi bi-tag-fill"></i> <?= htmlspecialchars($prodi['kode_prodi']) ?>
                                                    </span>
                                                <?php endif; ?>
                                                <?php if (!empty($prodi['jenjang'])): ?>
                                                    <span class="prodi-meta-badge">
                                                        <i class="bi bi-award-fill"></i> <?= htmlspecialchars($prodi['jenjang']) ?>
                                                    </span>
                                                <?php endif; ?>
                                                <?php if (!empty($prodi['fakultas'])): ?>
                                                    <span class="prodi-meta-badge">
                                                        <i class="bi bi-building"></i> <?= htmlspecialchars($prodi['fakultas']) ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <?php if ($prodi['penjelasan']): ?>
                                                <div class="prodi-description">
                                                    <?= nl2br(htmlspecialchars($prodi['penjelasan'])) ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>

                                        <div class="prodi-bottom-panels">
                                            <?php
                                            $kontakPerson = trim((string) ($prodi['kontak_person'] ?? ''));
                                            $emailKontak = trim((string) ($prodi['email'] ?? ''));
                                            $noTelp = trim((string) ($prodi['no_telp'] ?? ''));
                                            $hasContact = $kontakPerson !== '' || $emailKontak !== '' || $noTelp !== '';
                                            $waDigits = $noTelp !== '' ? preg_replace('/\D/', '', $noTelp) : '';
                                            if ($waDigits !== '' && $waDigits[0] === '0') {
                                                $waDigits = '62' . substr($waDigits, 1);
                                            }
                                            $waHref = $waDigits !== '' ? 'https://wa.me/' . $waDigits : '';
                                            ?>
                                            <div class="prodi-contact-section">
                                                <h3 class="prodi-contact-title">
                                                    <i class="bi bi-person-lines-fill"></i> Hubungi Program Studi
                                                </h3>
                                                <?php if ($hasContact): ?>
                                                    <div class="prodi-contact-grid">
                                                        <?php if ($kontakPerson !== ''): ?>
                                                            <div class="prodi-contact-item">
                                                                <span class="prodi-contact-label"><i class="bi bi-person-badge"></i> Kontak Person</span>
                                                                <span class="prodi-contact-value"><?= htmlspecialchars($kontakPerson) ?></span>
                                                            </div>
                                                        <?php endif; ?>
                                                        <?php if ($noTelp !== ''): ?>
                                                            <a href="<?= htmlspecialchars($waHref) ?>" target="_blank" rel="noopener noreferrer" class="prodi-contact-item prodi-contact-item--action">
                                                                <span class="prodi-contact-label"><i class="bi bi-whatsapp"></i> WhatsApp</span>
                                                                <span class="prodi-contact-value"><?= htmlspecialchars($noTelp) ?></span>
                                                            </a>
                                                        <?php endif; ?>
                                                        <?php if ($emailKontak !== ''): ?>
                                                            <a href="mailto:<?= htmlspecialchars($emailKontak) ?>" class="prodi-contact-item prodi-contact-item--action">
                                                                <span class="prodi-contact-label"><i class="bi bi-envelope-fill"></i> Email</span>
                                                                <span class="prodi-contact-value"><?= htmlspecialchars($emailKontak) ?></span>
                                                            </a>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php else: ?>
                                                    <p class="prodi-contact-empty">
                                                        Informasi kontak belum tersedia. Silakan hubungi resepsionis untuk bantuan lebih lanjut.
                                                    </p>
                                                <?php endif; ?>
                                            </div>

                                        <div class="prodi-qr-section" aria-label="QR Code link program studi">
                                            <h3 class="prodi-qr-title">
                                                <i class="bi bi-qr-code"></i> QR Link
                                            </h3>
                                            <div class="prodi-qr-body">
                                                <div class="prodi-qr-wrapper">
                                                    <div id="<?= $qr_id ?>" class="prodi-qr-code"></div>
                                                </div>
                                            </div>
                                        </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php 
                            $first = false;
                        endwhile; 
                        ?>
                    </div>
                    
                    <!-- Navigation Controls -->
                    <button class="carousel-control-prev" type="button">
                        <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                        <span class="visually-hidden">Previous</span>
                    </button>
                    <button class="carousel-control-next" type="button">
                        <span class="carousel-control-next-icon" aria-hidden="true"></span>
                        <span class="visually-hidden">Next</span>
                    </button>
                    
                    <!-- Indicators -->
                    <?php 
                    $all_prodi->data_seek(0);
                    $total_prodi = $all_prodi->num_rows;
                    ?>
                    <?php if ($total_prodi > 1): ?>
                    <div class="carousel-indicators">
                        <?php 
                        for ($i = 0; $i < $total_prodi; $i++): 
                        ?>
                            <button type="button" data-bs-slide-to="<?= $i ?>" 
                                    <?= $i == 0 ? 'class="active" aria-current="true"' : '' ?> 
                                    aria-label="Slide <?= $i + 1 ?>"></button>
                        <?php 
                        endfor; 
                        ?>
                    </div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="text-center py-5">
                    <i class="bi bi-inbox" style="font-size: 4rem; color: rgba(255,255,255,0.5);"></i><br>
                    <h5 class="mt-3" style="color: white;">Tidak ada program studi tersedia</h5>
                    <a href="index.php" class="btn btn-light mt-3">
                        <i class="bi bi-arrow-left"></i> Kembali
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <!-- Footer -->
        <div class="prodi-footer">
            Running Text untuk Informasi
        </div>
    </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Initialize carousel dengan manual control
        document.addEventListener('DOMContentLoaded', function() {
            const carouselElement = document.getElementById('prodiCarousel');
            if (!carouselElement) return;

            // Initialize carousel
            let carousel = new bootstrap.Carousel(carouselElement, {
                interval: false,
                ride: false,
                wrap: true,
                keyboard: true
            });

            // Generate QR Codes for all prodi
            <?php 
            $all_prodi->data_seek(0);
            while ($prodi = $all_prodi->fetch_assoc()): 
                // Prepare QR code data (use direct link if available, otherwise use prodi info)
                if (!empty($prodi['direct_link'])) {
                    $qr_data = $prodi['direct_link'];
                } else {
                    // Fallback to JSON format with prodi info if no direct link
                    $qr_data = json_encode([
                        'nama_prodi' => $prodi['nama_prodi'],
                        'kode_prodi' => $prodi['kode_prodi'] ?? '',
                        'email' => $prodi['email'] ?? '',
                        'kontak_person' => $prodi['kontak_person'] ?? '',
                        'fakultas' => $prodi['fakultas'] ?? '',
                        'jenjang' => $prodi['jenjang'] ?? ''
                    ]);
                }
                $qr_id = 'qr-' . $prodi['id'];
            ?>
                (function() {
                    const qrElement = document.getElementById("<?= $qr_id ?>");
                    if (qrElement && typeof recepsionisRenderQrWithLogo === 'function') {
                        recepsionisRenderQrWithLogo(qrElement, <?= json_encode($qr_data) ?>, 120);
                    }
                })();
            <?php endwhile; ?>

            // Fix prev button
            const prevButton = carouselElement.querySelector('.carousel-control-prev');
            if (prevButton) {
                prevButton.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    e.stopImmediatePropagation();
                    if (carousel) {
                        carousel.prev();
                    }
                    return false;
                }, true);
            }

            // Fix next button
            const nextButton = carouselElement.querySelector('.carousel-control-next');
            if (nextButton) {
                nextButton.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    e.stopImmediatePropagation();
                    if (carousel) {
                        carousel.next();
                    }
                    return false;
                }, true);
            }

            // Fix indicators
            const indicators = carouselElement.querySelectorAll('.carousel-indicators button');
            indicators.forEach(function(button) {
                const slideIndex = parseInt(button.getAttribute('data-bs-slide-to'));
                button.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    e.stopImmediatePropagation();
                    if (carousel && !isNaN(slideIndex)) {
                        carousel.to(slideIndex);
                    }
                    return false;
                }, true);
            });
        });
    </script>
    <?php require __DIR__ . '/_visitor_react_chrome.php'; ?>
    <script src="../assets/js/idle-redirect.js"></script>
</body>
</html>

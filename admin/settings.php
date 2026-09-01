<?php
require_once 'auth.php';
require_once '../staff_call_routing.php';
require_once 'helpdesk_hub.php';

requireComplaintOperatorPage();

$isAdmin = currentUserIsAdmin();

// Preferensi notifikasi helpdesk → hub Helpdesk (bukan settings E-Recepsionis)
if (!$isAdmin && (currentUserIsHelpdeskAdmin() || currentUserIsOperator())) {
    header('Location: ' . helpdeskUrl('prefs'));
    exit;
}

requireSuperAdminPage();

// Mode pemeliharaan (file flag di root proyek) — admin saja
if ($isAdmin && isset($_POST['save_maintenance'])) {
    $on = isset($_POST['maintenance_enabled']) && $_POST['maintenance_enabled'] === '1';
    if ($on) {
        if (@file_put_contents(RECEPSIONIS_MAINTENANCE_FLAG, gmdate('c') . "\n") === false) {
            header('Location: settings.php?maintenance_err=1');
            exit;
        }
    } elseif (is_file(RECEPSIONIS_MAINTENANCE_FLAG)) {
        @unlink(RECEPSIONIS_MAINTENANCE_FLAG);
    }
    $note = trim((string) ($_POST['maintenance_message'] ?? ''));
    if ($note === '') {
        if (is_file(RECEPSIONIS_MAINTENANCE_MESSAGE_FILE)) {
            @unlink(RECEPSIONIS_MAINTENANCE_MESSAGE_FILE);
        }
    } elseif (@file_put_contents(RECEPSIONIS_MAINTENANCE_MESSAGE_FILE, $note) === false) {
        header('Location: settings.php?maintenance_err=1');
        exit;
    }
    header('Location: settings.php?maintenance_ok=1');
    exit;
}

if ($isAdmin && isset($_POST['test_whatsapp'])) {
    $phones = recepsionis_collect_wa_fallback_phones($koneksi)['phones'] ?? [];
    $testMessage = 'Tes WhatsApp E-Recepsionis (' . date('Y-m-d H:i:s') . '). Jika Anda menerima pesan ini, integrasi WA aktif.';
    $testResult = recepsionis_send_whatsapp_messages($koneksi, $testMessage, $phones);
    $testStatus = !empty($testResult['sent']) ? 'success' : 'failed';
    $testDetail = rawurlencode(json_encode($testResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    header('Location: settings.php?wa_test=' . $testStatus . '&wa_test_detail=' . $testDetail);
    exit;
}

// Handle settings update — admin saja
if ($isAdmin && isset($_POST['update_settings'])) {
    foreach (['wa_enabled', 'email_notification', 'sms_notification'] as $checkboxKey) {
        if (!isset($_POST[$checkboxKey])) {
            $_POST[$checkboxKey] = '0';
        }
    }
    foreach ($_POST as $key => $value) {
        if ($key != 'update_settings') {
            $key_esc = esc($key);
            $value_esc = esc($value);
            $koneksi->query("INSERT INTO settings (setting_key, setting_value) 
                             VALUES ('$key_esc', '$value_esc')
                             ON DUPLICATE KEY UPDATE setting_value='$value_esc'");
        }
    }
    header("Location: settings.php?success=1");
    exit;
}

// Get settings — admin saja
$settings = [];
if ($isAdmin) {
    $result = $koneksi->query("SELECT * FROM settings");
    while ($row = $result->fetch_assoc()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
$prefs = recepsionis_get_notification_preferences($koneksi, $userId);
$categoryIds = recepsionis_get_admin_category_ids($koneksi, $userId);
$displayName = trim((string) ($_SESSION['nama_lengkap'] ?? $_SESSION['username'] ?? 'Operator'));
$apiUrl = function_exists('apiUrl') ? apiUrl('admin_notification_preferences.php') : '../api/admin_notification_preferences.php';
$pageTitle = 'Settings';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - E-Recepsionis System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <?php include 'include_staff_call_head.php'; ?>
</head>
<body>
    <?php include 'navbar.php'; ?>

    <div class="container-fluid">
        <div class="row">
            <?php include 'sidebar.php'; ?>

            <div class="col-md-10 content-area">
                <h2 class="mb-4">
                    <i class="bi bi-gear"></i> <?= htmlspecialchars($pageTitle) ?>
                </h2>

                <?php if ($isAdmin && isset($_GET['success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="bi bi-check-circle"></i> Settings berhasil diupdate
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($isAdmin && isset($_GET['wa_test'])): ?>
                    <?php
                    $waTestOk = ($_GET['wa_test'] ?? '') === 'success';
                    $waTestDetail = json_decode((string) ($_GET['wa_test_detail'] ?? ''), true);
                    $waFirst = is_array($waTestDetail) && !empty($waTestDetail['responses'][0]) && is_array($waTestDetail['responses'][0])
                        ? $waTestDetail['responses'][0]
                        : null;
                    ?>
                    <div class="alert alert-<?= $waTestOk ? 'success' : 'danger' ?> alert-dismissible fade show">
                        <i class="bi bi-<?= $waTestOk ? 'check-circle' : 'exclamation-triangle' ?>"></i>
                        <?php if ($waTestOk): ?>
                            Tes WhatsApp berhasil dikirim ke nomor fallback.
                        <?php else: ?>
                            Tes WhatsApp gagal.
                            <?php if (is_array($waTestDetail)): ?>
                                <div class="small mt-2 mb-0">
                                    <div><strong>Alasan:</strong> <?= htmlspecialchars((string) ($waTestDetail['reason'] ?? 'unknown')) ?></div>
                                    <?php if (!empty($waTestDetail['phone_count'])): ?>
                                        <div>Jumlah nomor: <?= (int) $waTestDetail['phone_count'] ?></div>
                                    <?php endif; ?>
                                    <?php if ($waFirst): ?>
                                        <?php if (isset($waFirst['http_code'])): ?>
                                            <div>HTTP: <?= (int) $waFirst['http_code'] ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($waFirst['error'])): ?>
                                            <div>cURL: <?= htmlspecialchars((string) $waFirst['error']) ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($waFirst['response'])): ?>
                                            <div>Respons API: <?= htmlspecialchars((string) $waFirst['response']) ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($waFirst['phone'])): ?>
                                            <div>Target: <?= htmlspecialchars((string) $waFirst['phone']) ?></div>
                                        <?php endif; ?>
                                    <?php elseif (($waTestDetail['reason'] ?? '') === 'no_phones'): ?>
                                        <div>Isi <strong>Admin Phones</strong> (format 628…) lalu Simpan Settings.</div>
                                    <?php elseif (($waTestDetail['reason'] ?? '') === 'missing_api_key'): ?>
                                        <div>Isi <strong>Cloudify API Key</strong> di Settings atau set <code>CLOUDIFY_WA_API_KEY</code> di server.</div>
                                    <?php elseif (($waTestDetail['reason'] ?? '') === 'missing_session'): ?>
                                        <div>Isi <strong>Cloudify Session ID</strong> (UUID) di Settings atau set <code>CLOUDIFY_WA_SESSION</code> di server.</div>
                                    <?php elseif (($waTestDetail['reason'] ?? '') === 'missing_api_url'): ?>
                                        <div>Isi <strong>Cloudify API URL</strong> lalu Simpan Settings.</div>
                                    <?php elseif (($waTestDetail['reason'] ?? '') === 'disabled'): ?>
                                        <div>Centang <strong>Aktifkan Notifikasi WhatsApp</strong> lalu Simpan Settings.</div>
                                    <?php elseif (($waTestDetail['reason'] ?? '') === 'curl_missing'): ?>
                                        <div>Ekstensi PHP <code>curl</code> belum aktif di VPS. Hubungi admin server.</div>
                                    <?php endif; ?>
                                    <?php if (!empty($waTestDetail['failure_hint'])): ?>
                                        <div class="mt-2"><strong>Saran:</strong> <?= htmlspecialchars((string) $waTestDetail['failure_hint']) ?></div>
                                    <?php elseif (!empty($waTestDetail['api_reason'])): ?>
                                        <div class="mt-2"><strong>Detail API:</strong> <?= htmlspecialchars((string) $waTestDetail['api_reason']) ?></div>
                                    <?php endif; ?>
                                    <div class="mt-2 text-muted">
                                        Cek umum: API Key &amp; Session ID Cloudify valid, session status <strong>ready</strong> di panel Cloudify,
                                        nomor pakai format internasional (<code>628…</code>), dan server boleh akses HTTPS keluar.
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                <?php if ($isAdmin && isset($_GET['maintenance_ok'])): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="bi bi-check-circle"></i> Pengaturan mode pemeliharaan disimpan
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                <?php if ($isAdmin && isset($_GET['maintenance_err'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="bi bi-exclamation-triangle"></i> Gagal menulis file mode pemeliharaan. Periksa izin folder proyek di server.
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php include 'include_notification_preferences_section.php'; ?>

                <?php if ($isAdmin): ?>
                <?php
                $maint_on = recepsionis_maintenance_active();
                $maint_msg = '';
                if (is_file(RECEPSIONIS_MAINTENANCE_MESSAGE_FILE)) {
                    $maint_msg = (string) file_get_contents(RECEPSIONIS_MAINTENANCE_MESSAGE_FILE);
                }
                ?>
                <div class="card border-warning mb-4">
                    <div class="card-header bg-warning bg-opacity-10">
                        <i class="bi bi-tools"></i> Mode pemeliharaan
                    </div>
                    <div class="card-body">
                        <p class="text-muted small mb-3">
                            Saat aktif, pengunjung dan API mendapat halaman / respons pemeliharaan. Panel admin dan skrip migrasi tetap dapat diakses.
                            Anda juga bisa mengaktifkan lewat server: buat file <code>maintenance.flag</code> di folder root proyek (isi boleh kosong); hapus file untuk menonaktifkan.
                        </p>
                        <form method="POST">
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" name="maintenance_enabled" value="1" id="maintenance_enabled" <?= $maint_on ? 'checked' : '' ?>>
                                <label class="form-check-label" for="maintenance_enabled">Aktifkan mode pemeliharaan</label>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="maintenance_message">Pesan tambahan (opsional)</label>
                                <textarea class="form-control" name="maintenance_message" id="maintenance_message" rows="3" placeholder="Contoh: Estimasi selesai pukul 14:00 WIB."><?= htmlspecialchars($maint_msg) ?></textarea>
                            </div>
                            <button type="submit" name="save_maintenance" value="1" class="btn btn-outline-warning">
                                <i class="bi bi-save"></i> Simpan mode pemeliharaan
                            </button>
                        </form>
                    </div>
                </div>

                <form method="POST">
                    <div class="card">
                        <div class="card-header">
                            <i class="bi bi-gear"></i> Pengaturan Umum
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label">Nama Sistem</label>
                                <input type="text" name="site_name" class="form-control" 
                                       value="<?= htmlspecialchars($settings['site_name'] ?? 'E-Recepsionis System') ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Email Sistem</label>
                                <input type="email" name="site_email" class="form-control" 
                                       value="<?= htmlspecialchars($settings['site_email'] ?? '') ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Auto Check-Out (jam)</label>
                                <input type="number" name="auto_checkout_hours" class="form-control" 
                                       value="<?= htmlspecialchars($settings['auto_checkout_hours'] ?? '8') ?>" min="1">
                                <small class="text-muted">Check-in biasa: otomatis check-out setelah X jam. Tamu Panggil Staff: otomatis check-out saat lewat hari atau setelah 24 jam.</small>
                            </div>
                        </div>
                    </div>

                    <div class="card mt-3">
                        <div class="card-header">
                            <i class="bi bi-toggle-on"></i> Fitur
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="queue_enabled" value="1" 
                                           id="queue_enabled" <?= ($settings['queue_enabled'] ?? '1') == '1' ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="queue_enabled">
                                        Aktifkan Sistem Antrian
                                    </label>
                                </div>
                            </div>
                            <div class="mb-3">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="badge_enabled" value="1" 
                                           id="badge_enabled" <?= ($settings['badge_enabled'] ?? '1') == '1' ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="badge_enabled">
                                        Aktifkan Sistem Badge
                                    </label>
                                </div>
                            </div>
                            <div class="mb-3">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="email_notification" value="1" 
                                           id="email_notification" <?= ($settings['email_notification'] ?? '1') == '1' ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="email_notification">
                                        Aktifkan Notifikasi Email
                                    </label>
                                </div>
                            </div>
                            <div class="mb-3">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="sms_notification" value="1" 
                                           id="sms_notification" <?= ($settings['sms_notification'] ?? '0') == '1' ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="sms_notification">
                                        Aktifkan Notifikasi SMS
                                    </label>
                                </div>
                            </div>
                        
                            <hr>
                            <h5>URL Publik (link WhatsApp)</h5>
                            <div class="mb-3">
                                <label class="form-label">Public Base URL</label>
                                <input type="url" name="public_base_url" class="form-control"
                                       value="<?= htmlspecialchars($settings['public_base_url'] ?? '') ?>"
                                       placeholder="http://127.0.0.1:8888/Recepsionis/">
                                <small class="text-muted">
                                    URL yang dipakai di link tindak lanjut WA (approve PIC).
                                    <strong>Jangan pakai https://localhost</strong> — akan error SSL.
                                    Lokal: <code>http://127.0.0.1:8888/Recepsionis/</code>.
                                    HP di jaringan yang sama: pakai IP LAN, contoh <code>http://192.168.x.x:8888/Recepsionis/</code>.
                                    VPS: domain publik dengan http/https yang benar.
                                </small>
                            </div>

                            <hr>
                            <h5>WhatsApp — Cloudify WA</h5>
                            <p class="text-muted small mb-3">
                                Provider: <a href="https://whatsapp.cloudify.id" target="_blank" rel="noopener">Cloudify WA</a>.
                                Kirim via <code>POST /sessions/{sessionId}/messages/send-text</code> dengan header <code>X-API-Key</code>.
                            </p>
                            <div class="mb-3">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="wa_enabled" value="1" 
                                           id="wa_enabled" <?= ($settings['wa_enabled'] ?? '0') == '1' ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="wa_enabled">
                                        Aktifkan Notifikasi WhatsApp
                                    </label>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Cloudify API URL</label>
                                <input type="text" name="wa_api_url" class="form-control" 
                                       value="<?= htmlspecialchars($settings['wa_api_url'] ?? '') ?>" placeholder="https://whatsapp.cloudify.id/api">
                                <small class="text-muted">Base URL API (tanpa path kirim pesan). Kosongkan untuk pakai default Cloudify.</small>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Cloudify API Key</label>
                                <input type="text" name="wa_api_token" class="form-control" 
                                       value="<?= htmlspecialchars($settings['wa_api_token'] ?? '') ?>" placeholder="owa_k1_...">
                                <small class="text-muted">Dari Cloudify → Admin → API Keys. Header: <code>X-API-Key</code>.</small>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Cloudify Session ID</label>
                                <input type="text" name="wa_session_id" class="form-control"
                                       value="<?= htmlspecialchars($settings['wa_session_id'] ?? '') ?>" placeholder="99744581-04a8-41f1-b013-c025323ae56e">
                                <small class="text-muted">UUID session WhatsApp (bukan nama session). Pastikan status session <strong>ready</strong>.</small>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Admin Phones (comma separated)</label>
                                <input type="text" name="wa_admin_phones" class="form-control" 
                                       value="<?= htmlspecialchars($settings['wa_admin_phones'] ?? '') ?>" placeholder="628123...,62819...">
                                <small class="text-muted">Nomor global dipakai sebagai fallback jika user kategori belum punya no_wa. Jika kosong, akan menggunakan nomor pada tabel <code>hosts</code>.</small>
                            </div>
                        </div>
                    </div>

                    <div class="mt-3 d-flex flex-wrap gap-2">
                        <button type="submit" name="update_settings" class="btn btn-primary">
                            <i class="bi bi-save"></i> Simpan Settings
                        </button>
                        <button type="submit" name="test_whatsapp" value="1" class="btn btn-outline-success">
                            <i class="bi bi-whatsapp"></i> Tes Kirim WhatsApp
                        </button>
                    </div>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/notification-badge.js"></script>
    <?php include 'include_staff_call_footer.php'; ?>
    <script>
    (function () {
        const apiUrl = <?= json_encode($apiUrl, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const alertEl = document.getElementById('prefAlert');
        const notifEl = document.getElementById('prefNotificationsEnabled');
        const soundEl = document.getElementById('prefSoundEnabled');
        const saveBtn = document.getElementById('prefSaveBtn');
        const testBtn = document.getElementById('prefTestSoundBtn');

        if (!notifEl || !soundEl || !saveBtn) {
            return;
        }

        function showAlert(type, msg) {
            if (!alertEl) return;
            alertEl.className = 'alert alert-' + type;
            alertEl.textContent = msg;
            alertEl.classList.remove('d-none');
        }

        function syncToRuntime() {
            if (window.recepsionisStaffCallNotify) {
                window.recepsionisStaffCallNotify.applyPreferences(
                    notifEl.checked,
                    soundEl.checked,
                    false
                );
            }
        }

        saveBtn.addEventListener('click', async function () {
            saveBtn.disabled = true;
            try {
                const res = await fetch(apiUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        notifications_enabled: notifEl.checked,
                        sound_enabled: soundEl.checked,
                    }),
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.message || 'Gagal menyimpan');
                syncToRuntime();
                showAlert('success', 'Preferensi notifikasi disimpan.');
            } catch (e) {
                showAlert('danger', e.message || 'Gagal menyimpan preferensi.');
            } finally {
                saveBtn.disabled = false;
            }
        });

        if (testBtn) {
            testBtn.addEventListener('click', function () {
                if (window.recepsionisStaffCallNotify) {
                    window.recepsionisStaffCallNotify.unlockAudio();
                    window.recepsionisStaffCallNotify.testSound();
                    showAlert('info', 'Jika tidak terdengar, pastikan suara aktif dan volume perangkat tidak mute.');
                }
            });
        }

        notifEl.addEventListener('change', function () {
            soundEl.disabled = !notifEl.checked;
        });
        soundEl.disabled = !notifEl.checked;

        if (window.location.hash === '#pref-notifikasi') {
            const target = document.getElementById('pref-notifikasi');
            if (target) {
                setTimeout(function () {
                    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }, 100);
            }
        }
    })();
    </script>
</body>
</html>

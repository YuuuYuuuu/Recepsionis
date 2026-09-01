<?php
/**
 * Salin file ini jadi config.local.php di server (folder yang sama dengan config.php).
 * Jangan commit config.local.php ke Git.
 *
 * Dipakai untuk produksi / VPS / shared hosting.
 */

// --- Database (wajib di hosting) ---
define('DB_HOST', 'localhost');
define('DB_USER', 'recepsion_3q5xh1');
define('DB_PASS', '123itb123');
define('DB_NAME', 'recepsion_3q5xh1');

// --- URL publik (wajib untuk link WhatsApp & QR) ---
// Contoh subfolder: 'https://domain-anda.com/Recepsionis/'
// Contoh document root VPS: 'http://103.107.4.29:89/'
define('PUBLIC_BASE_URL', 'http://103.107.4.29:89/');

// --- Base URL aplikasi (opsional — override deteksi otomatis) ---
// Pakai jika link admin/visitor salah prefix folder. Contoh VPS document root:
// define('BASE_URL', 'http://103.107.4.29:89/');

// --- Cloudify WA (opsional — fallback jika tidak diisi di Settings admin) ---
// define('CLOUDIFY_WA_API_URL', 'https://whatsapp.cloudify.id/api');
// define('CLOUDIFY_WA_API_KEY', 'owa_k1_...');
// define('CLOUDIFY_WA_SESSION', '99744581-04a8-41f1-b013-c025323ae56e');
// Legacy alias API key:
// define('DRIPSENDER_API_KEY', 'owa_k1_...');

// --- Live chat Socket.io (opsional) ---
// define('LIVE_SOCKET_URL', 'https://domain-anda.com');
// define('LIVE_SOCKET_AUTO_HOST', false);

// --- Deploy webhook (opsional — alternatif jika tidak pakai self-hosted runner) ---
// Token rahasia untuk POST /api/deploy_webhook.php (header: X-Deploy-Token)
// define('DEPLOY_WEBHOOK_SECRET', 'ganti-dengan-string-panjang-acak');

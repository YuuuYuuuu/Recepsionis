<?php
/**
 * Salin file ini jadi config.local.php di server (folder yang sama dengan config.php).
 * Jangan commit config.local.php ke Git.
 *
 * Dipakai untuk produksi / VPS / shared hosting.
 */

// --- Database (wajib di hosting) ---
define('DB_HOST', 'localhost');
define('DB_USER', 'username_mysql_hosting');
define('DB_PASS', 'password_mysql_hosting');
define('DB_NAME', 'nama_database');

// --- URL publik (wajib untuk link WhatsApp & QR) ---
// Contoh subfolder: 'https://domain-anda.com/Recepsionis/'
// Contoh document root VPS: 'http://103.107.4.29:89/'
define('PUBLIC_BASE_URL', 'https://domain-anda.com/Recepsionis/');

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

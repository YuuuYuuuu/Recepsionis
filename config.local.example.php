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
// Contoh: 'https://domain-anda.com/Recepsionis/' atau 'https://domain-anda.com/'
define('PUBLIC_BASE_URL', 'https://domain-anda.com/Recepsionis/');

// --- Live chat Socket.io (opsional) ---
// define('LIVE_SOCKET_URL', 'https://domain-anda.com');
// define('LIVE_SOCKET_AUTO_HOST', false);

<?php
// Konfigurasi E-Recepsionis System

if (!function_exists('recepsionis_detect_base_url')) {
    function recepsionis_detect_base_url(): string
    {
        if (PHP_SAPI === 'cli') {
            return 'http://127.0.0.1:8000/' . basename(__DIR__) . '/';
        }

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
        if ($host === '') {
            $host = '127.0.0.1:8000';
        }

        $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $projectName = basename(__DIR__);
        $projectSegment = '/' . $projectName . '/';
        $position = strpos($scriptName, $projectSegment);

        if ($position !== false) {
            $basePath = substr($scriptName, 0, $position + strlen($projectSegment));
        } else {
            $basePath = '/' . $projectName . '/';
        }

        return $scheme . '://' . $host . $basePath;
    }
}

// Base URL
if (!defined('BASE_URL')) {
    define('BASE_URL', recepsionis_detect_base_url());
}

// Path
define('BASE_PATH', dirname(__FILE__));
/** File ini ada = mode pemeliharaan aktif (hapus file untuk menonaktifkan). */
define('RECEPSIONIS_MAINTENANCE_FLAG', BASE_PATH . '/maintenance.flag');
/** Pesan opsional untuk halaman maintenance (teks biasa). */
define('RECEPSIONIS_MAINTENANCE_MESSAGE_FILE', BASE_PATH . '/maintenance_message.txt');
define('UPLOAD_PATH', BASE_PATH . '/uploads/');
if (!defined('UPLOAD_URL')) {
    define('UPLOAD_URL', rtrim(BASE_URL, '/') . '/uploads/');
}

// Database (sudah di koneksi.php)
require_once 'koneksi.php';

// Override produksi / VPS (tidak di-commit): salin config.local.example.php → config.local.php
// koneksi.php mungkin sudah memuat file ini lebih awal (untuk DB_HOST, dll.)
if (is_file(BASE_PATH . '/config.local.php') && !defined('RECEPSIONIS_CONFIG_LOCAL_LOADED')) {
    require_once BASE_PATH . '/config.local.php';
    define('RECEPSIONIS_CONFIG_LOCAL_LOADED', true);
}

/**
 * URL publik untuk link di WhatsApp / email (harus bisa diakses dari HP admin).
 * Prioritas: PUBLIC_BASE_URL (config.local) → settings.public_base_url → BASE_URL.
 * Host lokal selalu pakai http agar tidak kena ERR_SSL_PROTOCOL_ERROR.
 */
if (!function_exists('recepsionis_get_public_base_url')) {
    function recepsionis_get_public_base_url(): string
    {
        $url = '';
        if (defined('PUBLIC_BASE_URL') && trim((string) PUBLIC_BASE_URL) !== '') {
            $url = trim((string) PUBLIC_BASE_URL);
        } elseif (isset($GLOBALS['koneksi']) && $GLOBALS['koneksi'] instanceof mysqli) {
            $koneksi = $GLOBALS['koneksi'];
            if (
                function_exists('recepsionis_table_exists')
                && recepsionis_table_exists($koneksi, 'settings')
            ) {
                $res = $koneksi->query("SELECT setting_value FROM settings WHERE setting_key = 'public_base_url' LIMIT 1");
                if ($res && $res->num_rows > 0) {
                    $url = trim((string) $res->fetch_assoc()['setting_value']);
                }
            }
        }

        if ($url === '') {
            $url = defined('BASE_URL') ? (string) BASE_URL : recepsionis_detect_base_url();
        }

        $url = rtrim($url, '/') . '/';
        $parsed = parse_url($url);
        if (!is_array($parsed)) {
            return $url;
        }

        $host = strtolower((string) ($parsed['host'] ?? ''));
        $isLocal = in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            || str_starts_with($host, '192.168.')
            || str_starts_with($host, '10.');

        if ($isLocal) {
            $scheme = 'http';
            $port = isset($parsed['port']) ? ':' . (int) $parsed['port'] : '';
            $path = (string) ($parsed['path'] ?? '/');
            if ($path === '') {
                $path = '/';
            }

            return $scheme . '://' . ($parsed['host'] ?? 'localhost') . $port . $path;
        }

        return $url;
    }
}

// URL server Socket.io (Node realtime-server). Sesuaikan di VPS / produksi.
if (!defined('LIVE_SOCKET_URL')) {
    define('LIVE_SOCKET_URL', 'http://127.0.0.1:3001');
}

// Jika true: saat tamu/admin buka dari host non-localhost (mis. IP LAN), ganti host URL socket
// agar browser tidak menghubungi 127.0.0.1 di perangkat tamu. Nonaktifkan jika pakai reverse proxy khusus.
if (!defined('LIVE_SOCKET_AUTO_HOST')) {
    define('LIVE_SOCKET_AUTO_HOST', true);
}

/**
 * URL Socket.io yang dipakai di browser: cocokkan host dengan halaman saat akses HTTP dari LAN.
 * Untuk halaman HTTPS, tetap pakai LIVE_SOCKET_URL (set ke wss / domain proxy).
 */
function recepsionis_live_socket_url_for_browser(): string {
    $configured = LIVE_SOCKET_URL;
    if (!LIVE_SOCKET_AUTO_HOST || php_sapi_name() === 'cli' || empty($_SERVER['HTTP_HOST'])) {
        return $configured;
    }
    $pageScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    if ($pageScheme === 'https') {
        return $configured;
    }
    $p = parse_url($configured);
    if ($p === false || empty($p['host'])) {
        return $configured;
    }
    $port = isset($p['port']) ? (int) $p['port'] : 3001;
    $cfgHost = strtolower($p['host']);
    $isLoopbackCfg = in_array($cfgHost, ['127.0.0.1', 'localhost'], true);
    $pageHost = strtolower(explode(':', (string) $_SERVER['HTTP_HOST'], 2)[0]);
    $pageIsLoopback = in_array($pageHost, ['127.0.0.1', 'localhost'], true);
    if ($isLoopbackCfg && !$pageIsLoopback) {
        return 'http://' . $pageHost . ':' . $port;
    }
    return $configured;
}

// Session settings (hanya boleh sebelum session aktif)
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_only_cookies', '1');
}
// Only start session if not already started and not in API context
if (session_status() === PHP_SESSION_NONE && !defined('API_CONTEXT')) {
    session_start();
}

// Timezone
date_default_timezone_set('Asia/Jakarta');

/**
 * Mode pemeliharaan: tamu & API diblokir; folder admin/ dan migrations/ tetap bisa diakses.
 * Nonaktifkan pengecekan: define('SKIP_MAINTENANCE_CHECK', true); sebelum require config.
 */
function recepsionis_maintenance_active(): bool {
    return is_file(RECEPSIONIS_MAINTENANCE_FLAG);
}

function recepsionis_maintenance_enforce(): void {
    if (PHP_SAPI === 'cli') {
        return;
    }
    if (defined('SKIP_MAINTENANCE_CHECK') && SKIP_MAINTENANCE_CHECK) {
        return;
    }
    if (!recepsionis_maintenance_active()) {
        return;
    }
    $script = isset($_SERVER['SCRIPT_NAME']) ? str_replace('\\', '/', (string) $_SERVER['SCRIPT_NAME']) : '';
    if ($script !== '' && (strpos($script, '/admin/') !== false || strpos($script, '/migrations/') !== false)) {
        return;
    }
    if (strpos($script, '/api/') !== false) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('HTTP/1.1 503 Service Unavailable');
            header('Retry-After: 3600');
        }
        echo json_encode([
            'success' => false,
            'error' => 'maintenance',
            'message' => 'Layanan sedang dalam pemeliharaan. Silakan coba lagi nanti.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!headers_sent()) {
        header('HTTP/1.1 503 Service Unavailable');
        header('Retry-After: 3600');
    }
    $maint = BASE_PATH . '/maintenance.php';
    if (is_file($maint)) {
        require $maint;
        exit;
    }
    echo '503 Service Unavailable';
    exit;
}

recepsionis_maintenance_enforce();

// Settings
define('SESSION_TIMEOUT', 7200); // 2 jam
define('MAX_UPLOAD_SIZE', 5 * 1024 * 1024); // 5MB
define('ALLOWED_IMAGE_TYPES', ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);
define('ALLOWED_IMAGE_EXT', ['jpg', 'jpeg', 'png', 'gif', 'webp']);

// Badge settings
define('BADGE_WIDTH', 85); // mm
define('BADGE_HEIGHT', 54); // mm (standard ID card size)

// Queue settings
define('QUEUE_PREFIX', 'A'); // Prefix untuk nomor antrian (A001, A002, etc)

// Email settings (untuk notifikasi)
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USER', ''); // Isi jika ingin menggunakan email
define('SMTP_PASS', ''); // Isi jika ingin menggunakan email
define('SMTP_FROM_EMAIL', 'noreply@recepsionis.local');
define('SMTP_FROM_NAME', 'E-Recepsionis System');

// Helper function untuk redirect
function redirect($url) {
    header("Location: " . $url);
    exit();
}

// Helper function untuk check login
function requireLogin() {
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
        redirect('admin/index.php');
    }
    
    // Check session timeout
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT)) {
        session_destroy();
        redirect('admin/index.php?timeout=1');
    }
    
    $_SESSION['last_activity'] = time();
}

// Helper function untuk check admin role
function requireAdmin() {
    requireLogin();
    if (!isset($_SESSION['role']) || $_SESSION['role'] != 'admin') {
        redirect('admin/index.php?error=unauthorized');
    }
}

// Helper function untuk sanitize input
function sanitize($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

// Helper function untuk generate badge number
function generateBadgeNumber() {
    global $koneksi;
    
    // Check if database connection exists
    if (!$koneksi || $koneksi->connect_error) {
        // Fallback to simple timestamp if DB fails
        return 'TMU' . date('Ymd') . '0001';
    }
    
    $date = date('Ymd');
    $query = "SELECT COUNT(*) as count FROM visitors WHERE DATE(created_at) = CURDATE()";
    $result = $koneksi->query($query);
    
    if (!$result) {
        // Fallback if query fails
        return 'TMU' . $date . '0001';
    }
    
    $row = $result->fetch_assoc();
    $count = $row['count'] + 1;
    return 'TMU' . $date . str_pad($count, 4, '0', STR_PAD_LEFT);
}

// Helper function untuk generate queue number
function generateQueueNumber($host_id) {
    global $koneksi;
    $date = date('Ymd');
    $query = "SELECT COUNT(*) as count FROM queue WHERE host_id = " . (int)$host_id . " AND DATE(created_at) = CURDATE()";
    $result = $koneksi->query($query);
    $row = $result->fetch_assoc();
    $count = $row['count'] + 1;
    return QUEUE_PREFIX . str_pad($count, 3, '0', STR_PAD_LEFT);
}

require_once BASE_PATH . '/lib/visitor_sync.php';
require_once BASE_PATH . '/lib/tv_info.php';

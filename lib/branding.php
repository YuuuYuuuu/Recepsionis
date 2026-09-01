<?php
declare(strict_types=1);

if (!function_exists('recepsionis_get_setting')) {
    require_once __DIR__ . '/visitor_sync.php';
}

const RECEPSIONIS_DEFAULT_LOGO = 'assets/images/official-logo-demk.png';

function recepsionis_get_visitor_logo_relative_path(?mysqli $koneksi = null): string
{
    $db = $koneksi ?? ($GLOBALS['koneksi'] ?? null);
    if (!$db instanceof mysqli) {
        return RECEPSIONIS_DEFAULT_LOGO;
    }

    $custom = trim(recepsionis_get_setting($db, 'visitor_logo', ''));
    if ($custom !== '' && is_file(BASE_PATH . '/' . ltrim($custom, '/'))) {
        return ltrim($custom, '/');
    }

    return RECEPSIONIS_DEFAULT_LOGO;
}

function recepsionis_get_visitor_logo_url(?mysqli $koneksi = null): string
{
    $rel = recepsionis_get_visitor_logo_relative_path($koneksi);
    $base = defined('BASE_URL') ? rtrim((string) BASE_URL, '/') : '';

    return $base . '/' . ltrim($rel, '/');
}

/**
 * @return array<string, mixed>
 */
function recepsionis_get_visitor_branding(?mysqli $koneksi = null): array
{
    $db = $koneksi ?? ($GLOBALS['koneksi'] ?? null);
    $defaults = [
        'site_name' => 'E-Recepsionis',
        'logo_alt' => 'Logo',
        'welcome_title' => 'Selamat Datang',
        'service_rooms_title' => 'Daftar Ruangan',
        'service_rooms_desc' => 'Cari ruangan, gedung, dan lokasi di kampus.',
        'service_rooms_cta' => 'Lihat ruangan',
        'service_prodi_title' => 'Program Studi',
        'service_prodi_desc' => 'Jelajahi program studi yang ada di kampus.',
        'service_prodi_cta' => 'Lihat prodi',
        'service_staff_title' => 'Panggil Staff',
        'service_staff_desc' => 'Hubungi operator, notifikasi langsung ke tim.',
        'service_staff_cta' => 'Panggil sekarang',
    ];

    if (!$db instanceof mysqli) {
        $defaults['logo_url'] = recepsionis_get_visitor_logo_url();
        $defaults['logo_path'] = BASE_PATH . '/' . RECEPSIONIS_DEFAULT_LOGO;
        $defaults['logo_relative'] = RECEPSIONIS_DEFAULT_LOGO;
        $defaults['services'] = [
            'rooms' => [
                'title' => $defaults['service_rooms_title'],
                'description' => $defaults['service_rooms_desc'],
                'cta' => $defaults['service_rooms_cta'],
            ],
            'prodi' => [
                'title' => $defaults['service_prodi_title'],
                'description' => $defaults['service_prodi_desc'],
                'cta' => $defaults['service_prodi_cta'],
            ],
            'staff' => [
                'title' => $defaults['service_staff_title'],
                'description' => $defaults['service_staff_desc'],
                'cta' => $defaults['service_staff_cta'],
            ],
        ];
        return $defaults;
    }

    $siteName = recepsionis_get_setting($db, 'site_name', $defaults['site_name']);
    $logoRel = recepsionis_get_visitor_logo_relative_path($db);

    return [
        'site_name' => $siteName,
        'logo_relative' => $logoRel,
        'logo_path' => BASE_PATH . '/' . $logoRel,
        'logo_url' => recepsionis_get_visitor_logo_url($db),
        'logo_alt' => recepsionis_get_setting($db, 'visitor_logo_alt', $siteName),
        'welcome_title' => recepsionis_get_setting($db, 'visitor_welcome_title', $defaults['welcome_title']),
        'service_rooms_title' => recepsionis_get_setting($db, 'visitor_service_rooms_title', $defaults['service_rooms_title']),
        'service_rooms_desc' => recepsionis_get_setting($db, 'visitor_service_rooms_desc', $defaults['service_rooms_desc']),
        'service_rooms_cta' => recepsionis_get_setting($db, 'visitor_service_rooms_cta', $defaults['service_rooms_cta']),
        'service_prodi_title' => recepsionis_get_setting($db, 'visitor_service_prodi_title', $defaults['service_prodi_title']),
        'service_prodi_desc' => recepsionis_get_setting($db, 'visitor_service_prodi_desc', $defaults['service_prodi_desc']),
        'service_prodi_cta' => recepsionis_get_setting($db, 'visitor_service_prodi_cta', $defaults['service_prodi_cta']),
        'service_staff_title' => recepsionis_get_setting($db, 'visitor_service_staff_title', $defaults['service_staff_title']),
        'service_staff_desc' => recepsionis_get_setting($db, 'visitor_service_staff_desc', $defaults['service_staff_desc']),
        'service_staff_cta' => recepsionis_get_setting($db, 'visitor_service_staff_cta', $defaults['service_staff_cta']),
        'services' => [
            'rooms' => [
                'title' => recepsionis_get_setting($db, 'visitor_service_rooms_title', $defaults['service_rooms_title']),
                'description' => recepsionis_get_setting($db, 'visitor_service_rooms_desc', $defaults['service_rooms_desc']),
                'cta' => recepsionis_get_setting($db, 'visitor_service_rooms_cta', $defaults['service_rooms_cta']),
            ],
            'prodi' => [
                'title' => recepsionis_get_setting($db, 'visitor_service_prodi_title', $defaults['service_prodi_title']),
                'description' => recepsionis_get_setting($db, 'visitor_service_prodi_desc', $defaults['service_prodi_desc']),
                'cta' => recepsionis_get_setting($db, 'visitor_service_prodi_cta', $defaults['service_prodi_cta']),
            ],
            'staff' => [
                'title' => recepsionis_get_setting($db, 'visitor_service_staff_title', $defaults['service_staff_title']),
                'description' => recepsionis_get_setting($db, 'visitor_service_staff_desc', $defaults['service_staff_desc']),
                'cta' => recepsionis_get_setting($db, 'visitor_service_staff_cta', $defaults['service_staff_cta']),
            ],
        ],
    ];
}

function recepsionis_save_setting(mysqli $koneksi, string $key, string $value): void
{
    $stmt = $koneksi->prepare(
        'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('ss', $key, $value);
    $stmt->execute();
    $stmt->close();
}

function recepsionis_visitor_branding_upload_dir(): string
{
    return BASE_PATH . '/uploads/branding/';
}

function recepsionis_visitor_branding_upload_url(): string
{
    return rtrim(defined('UPLOAD_URL') ? (string) UPLOAD_URL : (BASE_URL . 'uploads/'), '/') . '/branding/';
}

/**
 * @return array{ok:bool,path?:string,error?:string}
 */
function recepsionis_handle_visitor_logo_upload(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'error' => 'no_file'];
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'upload_error'];
    }

    $maxBytes = 2 * 1024 * 1024;
    if (($file['size'] ?? 0) > $maxBytes) {
        return ['ok' => false, 'error' => 'too_large'];
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'error' => 'invalid_file'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmp) ?: '';
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/svg+xml' => 'svg',
    ];
    if (!isset($allowed[$mime])) {
        return ['ok' => false, 'error' => 'invalid_type'];
    }

    $dir = recepsionis_visitor_branding_upload_dir();
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        return ['ok' => false, 'error' => 'mkdir_failed'];
    }

    $filename = 'logo_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
    $dest = $dir . $filename;
    if (!move_uploaded_file($tmp, $dest)) {
        return ['ok' => false, 'error' => 'move_failed'];
    }

    return ['ok' => true, 'path' => 'uploads/branding/' . $filename];
}

function recepsionis_delete_visitor_logo_file(string $relativePath): void
{
    $relativePath = ltrim($relativePath, '/');
    if ($relativePath === '' || !str_starts_with($relativePath, 'uploads/branding/')) {
        return;
    }
    $full = BASE_PATH . '/' . $relativePath;
    if (is_file($full)) {
        @unlink($full);
    }
}

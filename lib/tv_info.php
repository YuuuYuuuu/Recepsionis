<?php
declare(strict_types=1);

/**
 * Helpers untuk tampilan TV info per ruangan.
 */

function recepsionis_tv_info_upload_dir(): string
{
    return rtrim(BASE_PATH, '/\\') . '/uploads/tv_info';
}

function recepsionis_generate_room_tv_token(): string
{
    return bin2hex(random_bytes(16));
}

function recepsionis_ensure_room_tv_token(mysqli $koneksi, int $roomId): ?string
{
    if ($roomId <= 0 || !recepsionis_table_exists($koneksi, 'rooms')) {
        return null;
    }
    if (!recepsionis_column_exists($koneksi, 'rooms', 'tv_display_token')) {
        return null;
    }

    $stmt = $koneksi->prepare('SELECT tv_display_token FROM rooms WHERE id = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $roomId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!$row) {
        return null;
    }

    $token = trim((string) ($row['tv_display_token'] ?? ''));
    if ($token !== '') {
        return $token;
    }

    $token = recepsionis_generate_room_tv_token();
    $upd = $koneksi->prepare('UPDATE rooms SET tv_display_token = ? WHERE id = ?');
    if (!$upd) {
        return null;
    }
    $upd->bind_param('si', $token, $roomId);
    $ok = $upd->execute();
    $upd->close();

    return $ok ? $token : null;
}

function recepsionis_build_room_tv_url(string $token): string
{
    $base = function_exists('recepsionis_get_public_base_url')
        ? recepsionis_get_public_base_url()
        : (defined('BASE_URL') ? BASE_URL : '/');

    return rtrim($base, '/') . '/visitor/tv.php?k=' . rawurlencode($token);
}

function recepsionis_get_room_by_tv_token(mysqli $koneksi, string $token): ?array
{
    $token = trim($token);
    if (
        $token === ''
        || !recepsionis_table_exists($koneksi, 'rooms')
        || !recepsionis_column_exists($koneksi, 'rooms', 'tv_display_token')
    ) {
        return null;
    }

    $stmt = $koneksi->prepare(
        'SELECT * FROM rooms WHERE tv_display_token = ? AND status_aktif = 1 LIMIT 1'
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    return $row ?: null;
}

function recepsionis_room_tv_image_url(?string $relativePath): string
{
    $relativePath = trim((string) $relativePath);
    if ($relativePath === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $relativePath)) {
        return $relativePath;
    }

    return rtrim(BASE_URL, '/') . '/' . ltrim($relativePath, '/');
}

function recepsionis_delete_room_tv_image_file(?string $relativePath): void
{
    $relativePath = trim((string) $relativePath);
    if ($relativePath === '' || strpos($relativePath, '..') !== false) {
        return;
    }
    if (strpos($relativePath, 'uploads/tv_info/') !== 0) {
        return;
    }
    $full = rtrim(BASE_PATH, '/\\') . '/' . $relativePath;
    if (is_file($full)) {
        @unlink($full);
    }
}

/**
 * Pastikan kolom TV info ada + seed token untuk semua ruangan.
 * Aman dijalankan berulang (idempotent).
 *
 * @return array{ok:bool,message:string,seeded:int}
 */
function recepsionis_ensure_tv_info_schema(mysqli $koneksi): array
{
    if (!recepsionis_table_exists($koneksi, 'rooms')) {
        return ['ok' => false, 'message' => 'Tabel rooms tidak ditemukan.', 'seeded' => 0];
    }

    $alters = [
        'tv_info_image' => 'ALTER TABLE `rooms` ADD COLUMN `tv_info_image` VARCHAR(255) NULL DEFAULT NULL',
        'tv_display_token' => 'ALTER TABLE `rooms` ADD COLUMN `tv_display_token` VARCHAR(64) NULL DEFAULT NULL',
        'tv_info_updated_at' => 'ALTER TABLE `rooms` ADD COLUMN `tv_info_updated_at` TIMESTAMP NULL DEFAULT NULL',
    ];

    foreach ($alters as $col => $sql) {
        if (!recepsionis_column_exists($koneksi, 'rooms', $col)) {
            try {
                if (!$koneksi->query($sql)) {
                    if ((int) $koneksi->errno !== 1060) {
                        return [
                            'ok' => false,
                            'message' => 'Gagal menambah kolom ' . $col . ': ' . $koneksi->error,
                            'seeded' => 0,
                        ];
                    }
                }
            } catch (Throwable $e) {
                if (strpos($e->getMessage(), 'Duplicate') === false) {
                    return [
                        'ok' => false,
                        'message' => 'Gagal menambah kolom ' . $col . ': ' . $e->getMessage(),
                        'seeded' => 0,
                    ];
                }
            }
        }
    }

    // Unique index (abaikan jika sudah ada)
    try {
        $koneksi->query('ALTER TABLE `rooms` ADD UNIQUE KEY `uq_rooms_tv_display_token` (`tv_display_token`)');
    } catch (Throwable $e) {
        // 1061 duplicate key name — aman diabaikan
    }

    $seeded = 0;
    $missing = $koneksi->query(
        "SELECT id FROM rooms WHERE tv_display_token IS NULL OR tv_display_token = ''"
    );
    if ($missing) {
        while ($row = $missing->fetch_assoc()) {
            $id = (int) $row['id'];
            $token = recepsionis_generate_room_tv_token();
            $stmt = $koneksi->prepare('UPDATE rooms SET tv_display_token = ? WHERE id = ?');
            if ($stmt) {
                $stmt->bind_param('si', $token, $id);
                if ($stmt->execute()) {
                    $seeded++;
                }
                $stmt->close();
            }
        }
    }

    $uploadDir = recepsionis_tv_info_upload_dir();
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0755, true);
    }

    return [
        'ok' => true,
        'message' => 'Skema TV info siap. Token dibuat untuk ' . $seeded . ' ruangan.',
        'seeded' => $seeded,
    ];
}

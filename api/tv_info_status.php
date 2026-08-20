<?php
declare(strict_types=1);

define('API_CONTEXT', true);

ob_start();
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/staff_call_routing.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$token = trim((string) ($_GET['k'] ?? $_POST['k'] ?? ''));
$room = recepsionis_get_room_by_tv_token($koneksi, $token);

if (!$room) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'message' => 'Token tidak valid.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$imagePath = trim((string) ($room['tv_info_image'] ?? ''));
$imageUrl = $imagePath !== '' ? recepsionis_room_tv_image_url($imagePath) : '';

echo json_encode([
    'success' => true,
    'room_id' => (int) ($room['id'] ?? 0),
    'room_name' => (string) ($room['nama_ruangan'] ?? ''),
    'image_url' => $imageUrl,
    'updated_at' => (string) ($room['tv_info_updated_at'] ?? ''),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

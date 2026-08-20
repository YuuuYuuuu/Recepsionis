<?php
declare(strict_types=1);

define('API_CONTEXT', true);

ob_start();
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/staff_call_routing.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$rawToken = trim((string) ($_POST['token'] ?? ''));
$action = trim((string) ($_POST['action'] ?? ''));

if ($rawToken === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Token tidak ditemukan.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$validation = recepsionis_validate_helpdesk_wa_action_token($koneksi, $rawToken);
if (!$validation['ok']) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => (string) ($validation['error'] ?? 'Link tidak valid.')], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!empty($validation['already_used'])) {
    echo json_encode([
        'success' => true,
        'message' => 'Aksi ini sudah diproses sebelumnya.',
        'action' => (string) ($validation['action_taken'] ?? ''),
        'already_used' => true,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$result = recepsionis_apply_helpdesk_wa_action($koneksi, $validation['token'], $action);

if (empty($result['success'])) {
    http_response_code(400);
}

echo json_encode($result, JSON_UNESCAPED_UNICODE);

<?php
define('API_CONTEXT', true);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

ob_start();
session_start();

require_once '../config.php';
require_once '../staff_call_routing.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

$overview = recepsionis_helpdesk_dashboard_overview($koneksi, 10);

$userId = (int) $_SESSION['user_id'];
$userRole = (string) ($_SESSION['role'] ?? '');
$isAdminUser = $userRole === 'admin'
    || (function_exists('currentUserIsHelpdeskAdmin') && currentUserIsHelpdeskAdmin());
$counts = recepsionis_get_helpdesk_action_counts(
    $koneksi,
    $userId,
    $isAdminUser,
    $isAdminUser ? 'all' : 'mine',
    $userRole
);

// Badge & metrik antrian harus sama angka (pending + in_progress)
$ticketBadge = (int) ($counts['tickets'] ?? 0);
$metrics = $overview['metrics'];
if ($isAdminUser) {
    // Admin melihat semua: pakai metrik open langsung
    $ticketBadge = (int) ($metrics['open'] ?? $ticketBadge);
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

echo json_encode(
    [
        'success' => true,
        'metrics' => $metrics,
        'recent_tickets' => $overview['recent_tickets'],
        'ticket_badge' => $ticketBadge,
        'synced_at' => date('c'),
    ],
    JSON_UNESCAPED_UNICODE
);
exit;

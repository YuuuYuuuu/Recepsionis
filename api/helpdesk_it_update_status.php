<?php
declare(strict_types=1);

define('API_CONTEXT', true);

header('Content-Type: application/json; charset=utf-8');

session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/staff_call_routing.php';

$userId = (int) ($_SESSION['user_id'] ?? 0);
$isAdmin = (string) ($_SESSION['role'] ?? '') === 'admin';

$ticketId = (int) ($_POST['ticket_id'] ?? 0);
$status = trim((string) ($_POST['status'] ?? ''));
$allowed = ['pending', 'in_progress', 'resolved'];

if ($ticketId <= 0 || !in_array($status, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Data tiket tidak valid.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!recepsionis_table_exists($koneksi, 'helpdesk_it_tickets')) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Tabel tiket belum tersedia.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$stmt = $koneksi->prepare('SELECT * FROM helpdesk_it_tickets WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $ticketId);
$stmt->execute();
$res = $stmt->get_result();
$ticket = $res ? $res->fetch_assoc() : null;
$stmt->close();

if (!$ticket) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Tiket tidak ditemukan.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$categoryId = recepsionis_resolve_helpdesk_it_ticket_category_id($koneksi, $ticket);
$assignedUserId = isset($ticket['assigned_user_id']) ? (int) $ticket['assigned_user_id'] : null;
if ($assignedUserId !== null && $assignedUserId <= 0) {
    $assignedUserId = null;
}

if (!$isAdmin && !recepsionis_user_can_receive_helpdesk_it_ticket($koneksi, $userId, $assignedUserId, $categoryId)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Anda tidak ditugaskan untuk tiket ini.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$shouldAssign = in_array($status, ['in_progress', 'resolved'], true)
    && ($assignedUserId === null || $assignedUserId <= 0)
    && $userId > 0
    && recepsionis_column_exists($koneksi, 'helpdesk_it_tickets', 'assigned_user_id');

if ($shouldAssign) {
    $stmt = $koneksi->prepare('UPDATE helpdesk_it_tickets SET status = ?, assigned_user_id = ?, updated_at = NOW() WHERE id = ?');
    $stmt->bind_param('sii', $status, $userId, $ticketId);
} else {
    $stmt = $koneksi->prepare('UPDATE helpdesk_it_tickets SET status = ?, updated_at = NOW() WHERE id = ?');
    $stmt->bind_param('si', $status, $ticketId);
}

$ok = $stmt->execute();
$affected = $stmt->affected_rows;
$stmt->close();

if (!$ok || $affected < 1) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Tiket tidak ditemukan atau status sama.'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'success' => true,
    'message' => 'Status tiket diperbarui.',
    'ticket_id' => $ticketId,
    'status' => $status,
    'assigned_user_id' => $shouldAssign ? $userId : $assignedUserId,
], JSON_UNESCAPED_UNICODE);

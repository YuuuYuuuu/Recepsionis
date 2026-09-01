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
    echo json_encode(['success' => false, 'message' => 'Unauthorized', 'tickets' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$userId = (int) $_SESSION['user_id'];
$prefs = recepsionis_get_notification_preferences($koneksi, $userId);
$notificationsEnabled = (bool) ($prefs['notifications_enabled'] ?? true);

recepsionis_expire_stale_helpdesk_tickets($koneksi);

if (!$notificationsEnabled || !recepsionis_user_is_helpdesk_pic($koneksi, $userId)) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    echo json_encode(
        [
            'success' => true,
            'tickets' => [],
            'count' => 0,
            'notifications_enabled' => $notificationsEnabled,
            'sound_enabled' => (bool) ($prefs['sound_enabled'] ?? true),
        ],
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

$tickets = [];

if (recepsionis_table_exists($koneksi, 'helpdesk_it_tickets')) {
    $hasCategoryColumn = recepsionis_column_exists($koneksi, 'helpdesk_it_tickets', 'category_id');
    $hasFollowUpColumn = recepsionis_column_exists($koneksi, 'helpdesk_it_tickets', 'follow_up_action');
    $followUpSelect = $hasFollowUpColumn ? ', follow_up_action' : ", 'none' AS follow_up_action";
    $selectSql = $hasCategoryColumn
        ? "SELECT id, nama, nomor, kelas, kendala, status, assigned_user_id, category_id, created_at{$followUpSelect}
           FROM helpdesk_it_tickets
           WHERE status = 'pending'
           ORDER BY created_at DESC
           LIMIT 50"
        : "SELECT id, nama, nomor, kelas, kendala, status, assigned_user_id, created_at{$followUpSelect}
           FROM helpdesk_it_tickets
           WHERE status = 'pending'
           ORDER BY created_at DESC
           LIMIT 50";

    $result = $koneksi->query($selectSql);

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $assignedUserId = isset($row['assigned_user_id']) ? (int) $row['assigned_user_id'] : null;
            if ($assignedUserId !== null && $assignedUserId <= 0) {
                $assignedUserId = null;
            }

            $categoryId = recepsionis_resolve_helpdesk_it_ticket_category_id($koneksi, $row);

            if (!recepsionis_user_can_receive_helpdesk_it_ticket($koneksi, $userId, $assignedUserId, $categoryId)) {
                continue;
            }

            $tickets[] = [
                'id' => (int) $row['id'],
                'type' => 'helpdesk_it',
                'nama' => (string) ($row['nama'] ?? ''),
                'nomor' => (string) ($row['nomor'] ?? ''),
                'kelas' => (string) ($row['kelas'] ?? ''),
                'kendala' => (string) ($row['kendala'] ?? ''),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'time_ago' => helpdesk_it_time_ago($row['created_at'] ?? ''),
                'assigned_user_id' => $assignedUserId,
                'follow_up_action' => (string) ($row['follow_up_action'] ?? 'none'),
            ];
        }
    }
}

function helpdesk_it_time_ago($datetime): string
{
    $timestamp = strtotime((string) $datetime);
    if ($timestamp === false) {
        return '';
    }
    $diff = time() - $timestamp;

    if ($diff < 60) {
        return 'Baru saja';
    }
    if ($diff < 3600) {
        return floor($diff / 60) . ' menit lalu';
    }
    if ($diff < 86400) {
        return floor($diff / 3600) . ' jam lalu';
    }

    return floor($diff / 86400) . ' hari lalu';
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

echo json_encode(
    [
        'success' => true,
        'tickets' => $tickets,
        'count' => count($tickets),
        'notifications_enabled' => true,
        'sound_enabled' => (bool) ($prefs['sound_enabled'] ?? true),
    ],
    JSON_UNESCAPED_UNICODE
);

exit;

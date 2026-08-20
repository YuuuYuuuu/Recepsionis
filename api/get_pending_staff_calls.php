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
    echo json_encode(['success' => false, 'message' => 'Unauthorized', 'calls' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$userId = (int) $_SESSION['user_id'];
$prefs = recepsionis_get_notification_preferences($koneksi, $userId);
$notificationsEnabled = (bool) ($prefs['notifications_enabled'] ?? true);

if (!$notificationsEnabled) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    echo json_encode(
        [
            'success' => true,
            'calls' => [],
            'count' => 0,
            'notifications_enabled' => false,
            'sound_enabled' => (bool) ($prefs['sound_enabled'] ?? true),
        ],
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

$hasAssignedUserColumn = recepsionis_column_exists($koneksi, 'staff_calls', 'assigned_user_id');
$hasFollowUpColumn = recepsionis_column_exists($koneksi, 'staff_calls', 'follow_up_action');
$followUpSelect = $hasFollowUpColumn ? ', sc.follow_up_action' : ", 'none' AS follow_up_action";

$sql = "SELECT sc.id, sc.visitor_name, sc.visitor_phone, sc.message, sc.created_at,
               sc.live_session_id, sc.category_id, sc.call_type,
               " . ($hasAssignedUserColumn ? 'sc.assigned_user_id' : 'NULL AS assigned_user_id') . "
               {$followUpSelect},
               cc.nama_kategori AS category_name
        FROM staff_calls sc
        LEFT JOIN complaint_categories cc ON cc.id = sc.category_id
        WHERE sc.status = 'pending'
        ORDER BY sc.created_at DESC
        LIMIT 50";

$result = $koneksi->query($sql);
$calls = [];

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $categoryId = isset($row['category_id']) ? (int) $row['category_id'] : 0;
        $assignedUserId = isset($row['assigned_user_id']) ? (int) $row['assigned_user_id'] : null;
        if ($assignedUserId !== null && $assignedUserId <= 0) {
            $assignedUserId = null;
        }

        if (!recepsionis_user_can_receive_staff_call($koneksi, $userId, $categoryId, $assignedUserId)) {
            continue;
        }

        $calls[] = [
            'id' => (int) $row['id'],
            'visitor_name' => $row['visitor_name'],
            'visitor_phone' => $row['visitor_phone'],
            'message' => $row['message'],
            'created_at' => $row['created_at'],
            'time_ago' => getTimeAgo($row['created_at']),
            'live_session_id' => $row['live_session_id'] ?? null,
            'category_id' => $categoryId > 0 ? $categoryId : null,
            'category_name' => $row['category_name'] ?: 'Tanpa kategori',
            'call_type' => $row['call_type'] ?: 'general',
            'assigned_user_id' => $assignedUserId,
            'follow_up_action' => (string) ($row['follow_up_action'] ?? 'none'),
        ];
    }
}

function getTimeAgo($datetime)
{
    $timestamp = strtotime((string) $datetime);
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
        'calls' => $calls,
        'count' => count($calls),
        'notifications_enabled' => true,
        'sound_enabled' => (bool) ($prefs['sound_enabled'] ?? true),
    ],
    JSON_UNESCAPED_UNICODE
);

exit;

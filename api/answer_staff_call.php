<?php
// API untuk menjawab panggilan staff
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

ob_start();

require_once '../config.php';
require_once '../staff_call_routing.php';

header('Content-Type: application/json; charset=utf-8');

// Check request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    ob_end_flush();
    exit;
}

// Get call ID
$call_id = intval($_POST['call_id'] ?? 0);

if ($call_id <= 0) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Invalid call ID'], JSON_UNESCAPED_UNICODE);
    ob_end_flush();
    exit;
}

$chk = $koneksi->query("SELECT id, live_session_id, category_id, assigned_user_id, status FROM staff_calls WHERE id = " . (int)$call_id . " LIMIT 1");
if (!$chk || !$chk->num_rows) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Panggilan tidak ditemukan'], JSON_UNESCAPED_UNICODE);
    ob_end_flush();
    exit;
}
$rowChk = $chk->fetch_assoc();
$categoryId = isset($rowChk['category_id']) ? (int) $rowChk['category_id'] : 0;
$assignedUserId = isset($rowChk['assigned_user_id']) ? (int) $rowChk['assigned_user_id'] : 0;

// Get user_id from session (if available, otherwise NULL)
$user_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;
$user_id_sql = $user_id > 0 ? $user_id : 'NULL';

if ($user_id > 0 && !recepsionis_user_can_receive_staff_call($koneksi, $user_id, $categoryId, $assignedUserId, $_SESSION['role'] ?? null)) {
    ob_clean();
    echo json_encode([
        'success' => false,
        'message' => 'Anda tidak ditugaskan untuk kategori panggilan ini.',
    ], JSON_UNESCAPED_UNICODE);
    ob_end_flush();
    exit;
}

// Update staff call status to answered (non-live)
$result = $koneksi->query("UPDATE staff_calls 
                          SET status = 'answered', 
                              answered_by = $user_id_sql,
                              answered_at = NOW() 
                          WHERE id = $call_id AND status = 'pending'");

if (!$result) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Gagal update status: ' . $koneksi->error], JSON_UNESCAPED_UNICODE);
    ob_end_flush();
    exit;
}

if ($koneksi->affected_rows > 0) {
    recepsionis_log_staff_call_event(
        $koneksi,
        $call_id,
        'answered',
        $user_id > 0 ? $user_id : null,
        null,
        $categoryId > 0 ? $categoryId : null,
        'Panggilan non-live ditandai sebagai terjawab.',
        ['source' => 'answer_staff_call_api']
    );
    if ($user_id > 0) {
        recepsionis_update_visitor_pic_from_staff_call($koneksi, $call_id, $user_id);
    }
}

// Clear output buffer
while (ob_get_level() > 0) {
    ob_end_clean();
}

// Return JSON
echo json_encode([
    'success' => true,
    'message' => 'Panggilan ditandai sebagai terjawab'
], JSON_UNESCAPED_UNICODE);

exit;

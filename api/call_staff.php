<?php
// Disable error display for clean JSON output
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Start output buffering to catch any unexpected output
ob_start();

// Define API context to prevent session start in config
define('API_CONTEXT', true);

// Suppress any warnings/notices
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    // Log errors but don't output them
    error_log("PHP Error: $errstr in $errfile on line $errline");
    return true;
}, E_WARNING | E_NOTICE | E_DEPRECATED);

// Require config but suppress any output
ob_start();
require_once '../config.php';
require_once '../staff_call_routing.php';
$config_output = ob_get_clean();
if (!empty($config_output)) {
    error_log("Config output detected: " . substr($config_output, 0, 200));
}

// Set JSON header first
header('Content-Type: application/json; charset=utf-8');

// Check request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    ob_end_flush();
    exit;
}

// Get and validate input
// Support both old API field names and new form field names
$visitor_name = trim(esc($_POST['visitor_name'] ?? $_POST['nama'] ?? ''));
$visitor_phone = trim(esc($_POST['visitor_phone'] ?? $_POST['telepon'] ?? ''));
$message = trim(esc($_POST['message'] ?? $_POST['pesan'] ?? ''));
$visitor_company = trim(esc($_POST['perusahaan'] ?? ''));
$room_id = isset($_POST['room_id']) ? (int)$_POST['room_id'] : 0;
$room_name = '';
$category_id = isset($_POST['category_id']) ? (int)$_POST['category_id'] : 0;
$category_name = ''; // Initialize category name variable
$target_admins = [];

if (empty($visitor_name) || empty($visitor_phone) || empty($message)) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Data tidak lengkap. Pastikan semua field terisi.'], JSON_UNESCAPED_UNICODE);
    ob_end_flush();
    exit;
}

// Validate category_id is required
if ($category_id <= 0) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Silakan pilih kategori pengaduan.'], JSON_UNESCAPED_UNICODE);
    ob_end_flush();
    exit;
}

// Validate category_id if provided
if ($category_id > 0) {
    $stmt = $koneksi->prepare("SELECT id, nama_kategori FROM complaint_categories WHERE id = ? AND status_aktif = 1 LIMIT 1");
    $stmt->bind_param("i", $category_id);
    $stmt->execute();
    $cat_check = $stmt->get_result();
    if (!$cat_check || $cat_check->num_rows == 0) {
        $category_id = 0; // Invalid category, reset to 0
    } else {
        $cat_row = $cat_check->fetch_assoc();
        $category_name = (string) ($cat_row['nama_kategori'] ?? '');
    }
    $stmt->close();
}

if ($category_id <= 0 || $category_name === '') {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Kategori tidak valid.'], JSON_UNESCAPED_UNICODE);
    ob_end_flush();
    exit;
}

$target_admins = recepsionis_get_active_category_admins($koneksi, $category_id);
if (empty($target_admins)) {
    ob_clean();
    echo json_encode([
        'success' => false,
        'code' => 'no_target_admin',
        'message' => 'Belum ada admin aktif untuk kategori ini. Silakan hubungi admin.',
    ], JSON_UNESCAPED_UNICODE);
    ob_end_flush();
    exit;
}

$assigned_user_id = null;
$effective_targets = $target_admins;

// Prepare insert - include room columns if present in DB
$has_room_cols = false;
$col_check = $koneksi->query("SHOW COLUMNS FROM staff_calls LIKE 'room_id'");
if ($col_check && $col_check->num_rows > 0) {
    $has_room_cols = true;
}

// Check if category_id column exists
$has_category_col = false;
$cat_col_check = $koneksi->query("SHOW COLUMNS FROM staff_calls LIKE 'category_id'");
if ($cat_col_check && $cat_col_check->num_rows > 0) {
    $has_category_col = true;
}

// If room selected, try to fetch room name
if ($room_id > 0) {
    $stmt = $koneksi->prepare("SELECT nama_ruangan FROM rooms WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $room_id);
    $stmt->execute();
    $rres = $stmt->get_result();
    if ($rres && $rres->num_rows > 0) {
        $room_name = $rres->fetch_assoc()['nama_ruangan'];
    }
    $stmt->close();
}

// Build INSERT query with prepared statement
$cols = ['visitor_name','visitor_phone','host_id','call_type','message','status'];
$placeholders = ['?','?','?','?','?','?'];
$types = 'ssisss';
$call_type = 'general';
$status_pending = 'pending';
$host_id_null = null; // Variable for NULL host_id

if ($has_room_cols) {
    $cols[] = 'room_id';
    $cols[] = 'room_name';
    $placeholders[] = '?';
    $placeholders[] = '?';
    $types .= 'is';
}

if ($has_category_col && $category_id > 0) {
    $cols[] = 'category_id';
    $placeholders[] = '?';
    $types .= 'i';
}

$insert_sql = "INSERT INTO staff_calls (" . implode(',', $cols) . ") VALUES (" . implode(',', $placeholders) . ")";
$stmt = $koneksi->prepare($insert_sql);

// Bind parameters dynamically
if ($stmt) {
    if ($has_room_cols && $has_category_col && $category_id > 0) {
        $stmt->bind_param($types, $visitor_name, $visitor_phone, $host_id_null, $call_type, $message, $status_pending, $room_id, $room_name, $category_id);
    } elseif ($has_room_cols) {
        $stmt->bind_param($types, $visitor_name, $visitor_phone, $host_id_null, $call_type, $message, $status_pending, $room_id, $room_name);
    } elseif ($has_category_col && $category_id > 0) {
        $stmt->bind_param($types, $visitor_name, $visitor_phone, $host_id_null, $call_type, $message, $status_pending, $category_id);
    } else {
        $stmt->bind_param($types, $visitor_name, $visitor_phone, $host_id_null, $call_type, $message, $status_pending);
    }
    
    if (!$stmt->execute()) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Gagal menyimpan data: ' . $stmt->error], JSON_UNESCAPED_UNICODE);
        ob_end_flush();
        $stmt->close();
        exit;
    }
    
    $call_id = $stmt->insert_id;
    $stmt->close();
} else {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Gagal menyiapkan query: ' . $koneksi->error], JSON_UNESCAPED_UNICODE);
    ob_end_flush();
    exit;
}

recepsionis_log_staff_call_event(
    $koneksi,
    (int) $call_id,
    'created',
    null,
    null,
    $category_id,
    'Panggilan staff dibuat dari form visitor.',
    [
        'call_type' => $call_type,
        'category_name' => $category_name,
        'visitor_name' => $visitor_name,
        'visitor_phone' => $visitor_phone,
    ]
);
if (count($target_admins) === 1) {
    $autoAssignedUserId = (int) ($target_admins[0]['id'] ?? 0);
    if (
        $autoAssignedUserId > 0
        && recepsionis_assign_staff_call(
            $koneksi,
            (int) $call_id,
            $autoAssignedUserId,
            null,
            $category_id,
            'Pengaduan otomatis ditugaskan karena kategori hanya memiliki satu admin aktif.',
            [
                'source' => 'call_staff_api',
                'auto_assigned' => true,
            ]
        )
    ) {
        $assigned_user_id = $autoAssignedUserId;
        $effective_targets = recepsionis_get_effective_staff_call_targets($koneksi, $assigned_user_id, $category_id);
    }
}

foreach ($effective_targets as $target_admin) {
    recepsionis_log_staff_call_event(
        $koneksi,
        (int) $call_id,
        'notified',
        null,
        (int) $target_admin['id'],
        $category_id,
        'Kategori dirutekan ke admin aktif.',
        [
            'call_type' => $call_type,
            'category_name' => $category_name,
            'assigned_user_id' => $assigned_user_id,
        ]
    );
}

// ========== INSERT INTO VISITORS TABLE (sync with Data Tamu) ==========
// Also insert visitor data into visitors table so they appear in Data Tamu
try {
    // Generate badge number for staff call visitors
    $badge_number = generateBadgeNumber();
    $status_checked_in = 'checked-in';
    
    $stmt = $koneksi->prepare("INSERT INTO visitors (nama, no_telp, perusahaan, tujuan, status, checkin_time, badge_number) VALUES (?, ?, ?, ?, ?, NOW(), ?)");
    $stmt->bind_param("ssssss", $visitor_name, $visitor_phone, $visitor_company, $message, $status_checked_in, $badge_number);
    
    if ($stmt->execute()) {
        $visitor_id = $stmt->insert_id;
        $stmt->close();
        
        // Update staff_calls with visitor_id for reference
        $stmt = $koneksi->prepare("UPDATE staff_calls SET visitor_id = ? WHERE id = ?");
        $stmt->bind_param("ii", $visitor_id, $call_id);
        $stmt->execute();
        $stmt->close();
    } else {
        $stmt->close();
    }
} catch (Exception $e) {
    // If visitor insert fails, still continue (staff_calls is saved)
    error_log("Visitor insert error: " . $e->getMessage());
}

// Create notification untuk admin (host_id = NULL)
// Try to create notification, but don't fail if it doesn't work
$notification_title = "Panggilan dari: " . $visitor_name;
$notification_message = "Panggilan dari $visitor_name ($visitor_phone)";
if (!empty($category_name)) {
    $notification_message .= "\nKategori: $category_name";
}
$notification_message .= "\nKeperluan: $message";
if (!empty($room_name)) {
    $notification_message .= "\nRuangan: " . $room_name;
}
try {
    $notif_title_q = $koneksi->real_escape_string($notification_title);
    $notif_msg_q = $koneksi->real_escape_string($notification_message);
    $notif_result = $koneksi->query("INSERT INTO notifications (host_id, type, title, message) 
                     VALUES (NULL, 'system', '$notif_title_q', '$notif_msg_q')");
} catch (Exception $e) {
    // Notification is optional, log but continue
    error_log("Notification insert error: " . $e->getMessage());
}

// Send email notification to admin if enabled (optional, don't fail if it doesn't work)
// Jangan require notify.php karena bisa output JSON
try {
    $email_setting = $koneksi->query("SELECT setting_value FROM settings WHERE setting_key = 'email_notification'");
    if ($email_setting && $email_setting->num_rows > 0) {
        $setting = $email_setting->fetch_assoc();
        if ($setting && $setting['setting_value'] == '1') {
            $email_body = "<h2>Panggilan Staff</h2>";
            $email_body .= "<p><strong>Nama:</strong> $visitor_name</p>";
            $email_body .= "<p><strong>No. Telepon:</strong> $visitor_phone</p>";
            if (!empty($category_name)) {
                $email_body .= "<p><strong>Kategori:</strong> $category_name</p>";
            }
            $email_body .= "<p><strong>Keperluan:</strong> $message</p>";
            $email_body .= "<p>Silakan hubungi visitor segera.</p>";

            $headers = "From: " . SMTP_FROM_NAME . " <" . SMTP_FROM_EMAIL . ">\r\n";
            $headers .= "Reply-To: " . SMTP_FROM_EMAIL . "\r\n";
            $headers .= "Content-Type: text/html; charset=UTF-8\r\n";

            foreach ($effective_targets as $target_admin) {
                if (!empty($target_admin['email'])) {
                    @mail($target_admin['email'], $notification_title, $email_body, $headers);
                }
            }
        }
    }
} catch (Exception $e) {
    // Email notification is optional, continue even if it fails
    error_log("Email notification error: " . $e->getMessage());
}

// ========== WhatsApp Notification (optional) ==========
$wa_responses = [];
$wa_sent_any = false;
$wa_invalid_numbers = [];
$wa_target_source = '';

try {
    $wa_message = "Panggilan Helpdesk #{$call_id}\nDari: $visitor_name\nNo: $visitor_phone";
    if (!empty($category_name)) {
        $wa_message .= "\nKategori: $category_name";
    }
    $wa_message .= "\nKeperluan: $message";
    if (!empty($room_name)) {
        $wa_message .= "\nRuangan: " . $room_name;
    }

    $helpdeskCategoryId = recepsionis_get_helpdesk_category_id($koneksi);
    if ($helpdeskCategoryId > 0 && $category_id === $helpdeskCategoryId) {
        $waHelpdeskResult = recepsionis_send_helpdesk_wa_with_action_links(
            $koneksi,
            $wa_message,
            'call',
            (int) $call_id,
            $effective_targets
        );
        $wa_responses = $waHelpdeskResult['responses'] ?? [];
        $wa_sent_any = !empty($waHelpdeskResult['sent']);
        $wa_target_source = (string) ($waHelpdeskResult['mode'] ?? 'helpdesk_action_links');
    } else {
        $waTargets = recepsionis_resolve_wa_targets_for_admins($koneksi, $effective_targets);
        $wa_target_source = (string) ($waTargets['source'] ?? '');
        $wa_invalid_numbers = $waTargets['invalid'] ?? [];
        $waResult = recepsionis_send_whatsapp_messages($koneksi, $wa_message, $waTargets['phones'] ?? []);
        $wa_responses = $waResult['responses'] ?? [];
        $wa_sent_any = !empty($waResult['sent']);
        if (!empty($waResult['invalid'])) {
            $wa_invalid_numbers = array_merge($wa_invalid_numbers, $waResult['invalid']);
        }
    }

    if (!empty($wa_responses)) {
        $first_success_code = 0;
        foreach ($wa_responses as $r) {
            if (($r['http_code'] ?? 0) >= 200 && ($r['http_code'] ?? 0) < 300) {
                $first_success_code = (int) $r['http_code'];
                break;
            }
        }
        if ($first_success_code === 0) {
            $last = end($wa_responses);
            $first_success_code = (int) ($last['http_code'] ?? 0);
        }
        $resp_json = $koneksi->real_escape_string(json_encode($wa_responses, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        try {
            $koneksi->query("UPDATE staff_calls SET wa_http_code = " . (int) $first_success_code . ", wa_response = '" . $resp_json . "' WHERE id = " . (int) $call_id);
        } catch (Exception $e) {
            error_log('Failed to update wa_response columns: ' . $e->getMessage());
        }
    }

    if ($wa_sent_any) {
        try {
            $koneksi->query("UPDATE staff_calls SET whatsapp_sent = 1 WHERE id = " . (int) $call_id);
        } catch (Exception $e) {
            error_log('Failed to update whatsapp_sent: ' . $e->getMessage());
        }
    }
} catch (Exception $e) {
    error_log('WhatsApp notification error: ' . $e->getMessage());
}

// Clear ALL output buffers - data sudah masuk ke database, jadi kita pasti success
while (ob_get_level() > 0) {
    ob_end_clean();
}

// Pastikan header sudah di-set
if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
}

// Return success response - data sudah masuk ke database
$response = [
    'success' => true,
    'message' => 'Panggilan berhasil dikirim',
    'data' => [
        'call_id' => (int)$call_id,
        'visitor_name' => $visitor_name,
        'visitor_phone' => $visitor_phone,
        'assigned_user_id' => $assigned_user_id,
        'target_admin_count' => count($effective_targets),
    ]
];
// If there were invalid admin phone numbers, include them for visibility
if (!empty($wa_invalid_numbers)) {
    $response['wa_invalid_numbers'] = array_values(array_unique($wa_invalid_numbers));
}
// Include WA send summary and responses for debugging
$response['wa_sent'] = !empty($wa_sent_any);
$response['wa_responses'] = !empty($wa_responses) ? $wa_responses : [];
if ($wa_target_source !== '') {
    $response['wa_target_source'] = $wa_target_source;
}

// Output JSON - pastikan tidak ada output lain
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;

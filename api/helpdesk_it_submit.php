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

$token = trim((string) ($_POST['token'] ?? $_POST['k'] ?? ''));
$issueCategory = strtolower(trim((string) ($_POST['issue_category'] ?? '')));
$detail = trim((string) ($_POST['kendala'] ?? $_POST['detail'] ?? ''));
$nama = trim((string) ($_POST['nama'] ?? ''));
$nomor = trim((string) ($_POST['nomor'] ?? ''));

$access = recepsionis_get_helpdesk_it_access_by_token($koneksi, $token);
if (!$access) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Link Helpdesk IT tidak valid atau sudah diganti.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!recepsionis_issue_category_is_valid($issueCategory)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Pilih kategori kendala terlebih dahulu.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$accessType = (string) ($access['access_type'] ?? 'event');
$roomId = isset($access['room_id']) ? (int) $access['room_id'] : 0;
$roomName = trim((string) ($access['nama_ruangan'] ?? ''));
$kelas = $accessType === 'room'
    ? ($roomName !== '' ? $roomName : 'Ruangan')
    : 'Event / Umum';

if ($accessType === 'event') {
    if ($nama === '' || $nomor === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Lengkapi Nama dan Nomor untuk laporan event.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
} else {
    $nama = '';
    $nomor = '';
    if ($roomId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'QR ruangan tidak valid.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$categoryLabel = recepsionis_issue_category_label($issueCategory);
$kendala = $detail !== '' ? $detail : $categoryLabel;

if (!recepsionis_table_exists($koneksi, 'helpdesk_it_tickets')) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Tabel tiket belum tersedia. Jalankan migrasi.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$categoryId = recepsionis_get_helpdesk_category_id($koneksi);
$targets = $categoryId > 0 ? recepsionis_get_active_category_admins($koneksi, $categoryId) : [];

if (empty($targets)) {
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'code' => 'no_target_admin',
        'message' => 'Belum ada PIC Help Desk aktif. Silakan hubungi admin.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$status = 'pending';
$hasCategoryColumn = recepsionis_column_exists($koneksi, 'helpdesk_it_tickets', 'category_id');
$hasModeColumns = recepsionis_column_exists($koneksi, 'helpdesk_it_tickets', 'access_type')
    && recepsionis_column_exists($koneksi, 'helpdesk_it_tickets', 'issue_category');

if ($hasModeColumns) {
    $roomIdDb = $accessType === 'room' ? $roomId : null;
    if ($hasCategoryColumn) {
        $stmt = $koneksi->prepare(
            'INSERT INTO helpdesk_it_tickets (nama, nomor, kelas, kendala, access_type, room_id, issue_category, status, category_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('sssssissi', $nama, $nomor, $kelas, $kendala, $accessType, $roomIdDb, $issueCategory, $status, $categoryId);
    } else {
        $stmt = $koneksi->prepare(
            'INSERT INTO helpdesk_it_tickets (nama, nomor, kelas, kendala, access_type, room_id, issue_category, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('sssssiss', $nama, $nomor, $kelas, $kendala, $accessType, $roomIdDb, $issueCategory, $status);
    }
} elseif ($hasCategoryColumn) {
    $stmt = $koneksi->prepare(
        'INSERT INTO helpdesk_it_tickets (nama, nomor, kelas, kendala, status, category_id) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('sssssi', $nama, $nomor, $kelas, $kendala, $status, $categoryId);
} else {
    $stmt = $koneksi->prepare(
        'INSERT INTO helpdesk_it_tickets (nama, nomor, kelas, kendala, status) VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('sssss', $nama, $nomor, $kelas, $kendala, $status);
}

if (!$stmt || !$stmt->execute()) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Gagal menyimpan tiket.'], JSON_UNESCAPED_UNICODE);
    if ($stmt) {
        $stmt->close();
    }
    exit;
}

$ticketId = (int) $stmt->insert_id;
$stmt->close();

$assignedUserId = null;
$effectiveTargets = $targets;

if (count($targets) === 1) {
    $autoAssignedUserId = (int) ($targets[0]['id'] ?? 0);
    if ($autoAssignedUserId > 0 && recepsionis_assign_helpdesk_it_ticket($koneksi, $ticketId, $autoAssignedUserId)) {
        $assignedUserId = $autoAssignedUserId;
        $effectiveTargets = recepsionis_get_effective_helpdesk_it_targets($koneksi, $assignedUserId, $categoryId);
    }
}

$notif = recepsionis_format_helpdesk_it_ticket_message(
    $ticketId,
    $accessType,
    $kelas,
    $issueCategory,
    $detail,
    $nama,
    $nomor
);

recepsionis_notify_helpdesk_it_targets(
    $koneksi,
    $effectiveTargets,
    $notif['title'],
    $notif['message'],
    $notif['wa_message'],
    'ticket',
    $ticketId
);

echo json_encode([
    'success' => true,
    'message' => $accessType === 'room'
        ? 'Laporan kendala berhasil dikirim. Tim IT akan segera menindaklanjuti.'
        : 'Tiket Helpdesk IT berhasil dikirim. Tim IT akan menghubungi Anda.',
    'ticket_id' => $ticketId,
    'assigned_user_id' => $assignedUserId,
    'target_admin_count' => count($effectiveTargets),
], JSON_UNESCAPED_UNICODE);

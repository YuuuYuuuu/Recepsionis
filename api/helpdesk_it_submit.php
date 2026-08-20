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
$nama = trim((string) ($_POST['nama'] ?? ''));
$nomor = trim((string) ($_POST['nomor'] ?? ''));
$kelas = trim((string) ($_POST['kelas'] ?? ''));
$kendala = trim((string) ($_POST['kendala'] ?? ''));

if (!recepsionis_validate_helpdesk_it_token($koneksi, $token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Link Helpdesk IT tidak valid atau sudah diganti.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($nama === '' || $nomor === '' || $kelas === '' || $kendala === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Lengkapi Nama, Nomor, Kelas, dan Kendala.'], JSON_UNESCAPED_UNICODE);
    exit;
}

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

$hasCategoryColumn = recepsionis_column_exists($koneksi, 'helpdesk_it_tickets', 'category_id');
$status = 'pending';

if ($hasCategoryColumn) {
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

if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Gagal menyimpan tiket.'], JSON_UNESCAPED_UNICODE);
    $stmt->close();
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

$notifTitle = 'Helpdesk IT: ' . $nama;
$notifMessage = "Tiket #{$ticketId}\nNama: {$nama}\nNo: {$nomor}\nKelas: {$kelas}\nKendala: {$kendala}";
$waMessage = "Tiket Helpdesk IT #{$ticketId}\nNama: {$nama}\nNo: {$nomor}\nKelas: {$kelas}\nKendala: {$kendala}";

recepsionis_notify_helpdesk_it_targets(
    $koneksi,
    $effectiveTargets,
    $notifTitle,
    $notifMessage,
    $waMessage,
    'ticket',
    $ticketId
);

echo json_encode([
    'success' => true,
    'message' => 'Tiket Helpdesk IT berhasil dikirim. Tim IT akan menghubungi Anda.',
    'ticket_id' => $ticketId,
    'assigned_user_id' => $assignedUserId,
    'target_admin_count' => count($effectiveTargets),
], JSON_UNESCAPED_UNICODE);

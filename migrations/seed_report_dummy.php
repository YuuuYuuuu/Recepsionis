<?php
/**
 * Dummy data 30 hari terakhir untuk halaman Laporan.
 * Idempotent: baris ditandai notes/message "[DUMMY]" dan tidak di-insert ulang.
 *
 *   php migrations/seed_report_dummy.php
 */

require_once dirname(__DIR__) . '/config.php';

$marker = '[DUMMY]';
$now = new DateTimeImmutable('now');

function out(string $msg): void
{
    echo $msg . PHP_EOL;
}

function alreadySeeded(mysqli $koneksi, string $table, string $column, string $marker): bool
{
    $col = $koneksi->real_escape_string($column);
    $tbl = $koneksi->real_escape_string($table);
    $like = $koneksi->real_escape_string('%' . $marker . '%');
    $res = $koneksi->query("SELECT COUNT(*) AS c FROM `{$tbl}` WHERE `{$col}` LIKE '{$like}'");
    $row = $res ? $res->fetch_assoc() : null;
    return (int) ($row['c'] ?? 0) > 0;
}

$users = [];
$ures = $koneksi->query("SELECT id, role FROM users WHERE status_aktif = 1 AND role IN ('admin','operator') ORDER BY id ASC");
while ($ures && ($u = $ures->fetch_assoc())) {
    $users[] = (int) $u['id'];
}
if (!$users) {
    fwrite(STDERR, "Tidak ada user aktif. Seed dibatalkan.\n");
    exit(1);
}
$operators = $users;

$hosts = [];
$hres = $koneksi->query("SELECT id FROM hosts WHERE status_aktif = 1");
while ($hres && ($h = $hres->fetch_assoc())) {
    $hosts[] = (int) $h['id'];
}

$rooms = [];
$rres = $koneksi->query("SELECT id, nama_ruangan FROM rooms WHERE status_aktif = 1");
while ($rres && ($r = $rres->fetch_assoc())) {
    $rooms[] = $r;
}

$categories = [];
$cres = $koneksi->query("SELECT id FROM complaint_categories WHERE status_aktif = 1");
while ($cres && ($c = $cres->fetch_assoc())) {
    $categories[] = (int) $c['id'];
}
if (!$categories) {
    $categories = [null];
}

$names = [
    ['Andi Pratama', '081234500001', 'PT Nusantara'],
    ['Sari Dewi', '081234500002', 'Universitas Indonesia'],
    ['Budi Hartono', '081234500003', 'PT Medco'],
    ['Lina Kusuma', '081234500004', 'Yayasan Pendidikan'],
    ['Rizky Fadillah', '081234500005', 'PT Telkom'],
    ['Maya Putri', '081234500006', 'Bank Mandiri'],
    ['Fajar Nugroho', '081234500007', 'Pemda DKI'],
    ['Dewi Lestari', '081234500008', 'PT Pertamina'],
    ['Agus Salim', '081234500009', 'Kampus Binus'],
    ['Nina Rahma', '081234500010', 'PT Astra'],
    ['Hendra Wijaya', '081234500011', 'Kementerian ESDM'],
    ['Putri Ayu', '081234500012', 'PT Unilever'],
    ['Yoga Prasetyo', '081234500013', 'Startup Lokal'],
    ['Intan Sari', '081234500014', 'PT Indofood'],
    ['Dimas Ardi', '081234500015', 'Freelance'],
];

$tujuanList = [
    'Rapat koordinasi program',
    'Kunjungan industri',
    'Presentasi proposal',
    'Survey fasilitas',
    'Meeting dengan dosen',
    'Pengambilan dokumen',
    'Workshop mahasiswa',
    'Audit internal',
];

$callMessages = [
    'Proyektor tidak menyala',
    'Mic tidak berfungsi',
    'AC terlalu dingin',
    'Layar presentasi berkedip',
    'Kabel HDMI tidak ketemu',
    'Speaker berisik',
    'Lampu ruang redup',
    'Wifi ruang tidak connect',
    'Remote AC hilang',
    'Kursi kurang untuk peserta',
];

$ticketIssues = [
    'Mic kedut saat presentasi',
    'Layar projector buram',
    'Audio tidak keluar ke speaker',
    'Komputer kelas lambat',
    'Pointer laser habis baterai',
    'Kabel power terputus',
    'AC bocor di sudut ruang',
    'Papan tulis digital error',
];

$kelasList = ['12A', '12B', '12C', 'Medco', 'Kirana 1', 'Henk Uno'];

$koneksi->begin_transaction();
try {
    $visitorInserted = 0;
    $callInserted = 0;
    $ticketInserted = 0;

    if (!alreadySeeded($koneksi, 'visitors', 'notes', $marker)) {
        $stmt = $koneksi->prepare(
            "INSERT INTO visitors (nama, email, no_telp, perusahaan, tujuan, host_id, status, checkin_time, checkout_time, badge_number, notes, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        for ($i = 0; $i < 42; $i++) {
            $person = $names[$i % count($names)];
            $dayOffset = $i % 28;
            $hour = 8 + ($i % 8);
            $checkin = $now->modify("-{$dayOffset} days")->setTime($hour, ($i * 7) % 60, 0);
            $created = $checkin->format('Y-m-d H:i:s');
            $hostId = $hosts ? $hosts[$i % count($hosts)] : null;
            $tujuan = $tujuanList[$i % count($tujuanList)];
            $badge = 'DUM' . $checkin->format('Ymd') . str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT);
            $email = strtolower(str_replace(' ', '.', $person[0])) . ($i + 1) . '@dummy.local';

            $roll = $i % 10;
            if ($roll === 0 && $dayOffset <= 1) {
                $status = 'checked-in';
                $checkout = null;
            } elseif ($roll === 1) {
                $status = 'pending';
                $checkout = null;
            } else {
                $status = 'checked-out';
                $dur = 45 + (($i * 13) % 180);
                $checkout = $checkin->modify("+{$dur} minutes")->format('Y-m-d H:i:s');
            }

            $notes = $marker . ' seed laporan';
            $hostBind = $hostId ?: 0;
            $checkoutBind = $checkout ?? $created;
            $stmt->bind_param(
                'sssssisssssss',
                $person[0],
                $email,
                $person[1],
                $person[2],
                $tujuan,
                $hostBind,
                $status,
                $created,
                $checkoutBind,
                $badge,
                $notes,
                $created,
                $created
            );
            $stmt->execute();
            $vid = (int) $koneksi->insert_id;
            if ($hostId === null || $status !== 'checked-out') {
                $sets = [];
                if ($hostId === null) {
                    $sets[] = 'host_id = NULL';
                }
                if ($checkout === null) {
                    $sets[] = 'checkout_time = NULL';
                }
                if ($sets && $vid > 0) {
                    $koneksi->query('UPDATE visitors SET ' . implode(', ', $sets) . ' WHERE id = ' . $vid);
                }
            }
            $visitorInserted++;
        }
        $stmt->close();
    } else {
        out('[SKIP] visitors dummy sudah ada');
    }

    if (!alreadySeeded($koneksi, 'staff_calls', 'message', $marker)) {
        $stmt = $koneksi->prepare(
            "INSERT INTO staff_calls
                (visitor_name, visitor_phone, host_id, call_type, message, status, answered_by, answered_at,
                 whatsapp_sent, created_at, updated_at, room_id, category_id, room_name, assigned_user_id, assigned_by, assigned_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        for ($i = 0; $i < 36; $i++) {
            $person = $names[$i % count($names)];
            $dayOffset = $i % 27;
            $hour = 9 + ($i % 7);
            $createdDt = $now->modify("-{$dayOffset} days")->setTime($hour, ($i * 11) % 60, 0);
            $created = $createdDt->format('Y-m-d H:i:s');
            $room = $rooms ? $rooms[$i % count($rooms)] : null;
            $roomId = $room ? (int) $room['id'] : null;
            $roomName = $room ? (string) $room['nama_ruangan'] : '';
            $catId = $categories[$i % count($categories)];
            $assigned = $operators[$i % count($operators)];
            $message = $marker . ' ' . $callMessages[$i % count($callMessages)];
            $callType = ($i % 8 === 0) ? 'live_chat' : 'general';
            $wa = ($i % 4 === 0) ? 0 : 1;
            $hostId = $hosts ? $hosts[$i % count($hosts)] : null;

            $roll = $i % 9;
            if ($roll === 0 && $dayOffset <= 2) {
                $status = 'pending';
                $answeredBy = null;
                $answeredAt = null;
            } elseif ($roll === 1) {
                $status = 'cancelled';
                $answeredBy = null;
                $answeredAt = null;
            } else {
                $status = 'answered';
                $answeredBy = $assigned;
                $respMin = [2, 4, 6, 8, 12, 18, 25, 3, 1][$i % 9];
                $answeredAt = $createdDt->modify("+{$respMin} minutes")->format('Y-m-d H:i:s');
            }

            $hostBind = $hostId ?: 0;
            $answeredByBind = $answeredBy ?: 0;
            $answeredAtBind = $answeredAt ?? $created;
            $roomIdBind = $roomId ?: 0;
            $catIdBind = $catId ?: 0;
            $stmt->bind_param(
                'ssisssisissiisiis',
                $person[0],
                $person[1],
                $hostBind,
                $callType,
                $message,
                $status,
                $answeredByBind,
                $answeredAtBind,
                $wa,
                $created,
                $created,
                $roomIdBind,
                $catIdBind,
                $roomName,
                $assigned,
                $assigned,
                $created
            );
            $stmt->execute();
            $cid = (int) $koneksi->insert_id;
            $nulls = [];
            if ($hostId === null) {
                $nulls[] = 'host_id = NULL';
            }
            if ($answeredBy === null) {
                $nulls[] = 'answered_by = NULL';
                $nulls[] = 'answered_at = NULL';
            }
            if ($roomId === null) {
                $nulls[] = 'room_id = NULL';
            }
            if (!$catId) {
                $nulls[] = 'category_id = NULL';
            }
            if ($nulls && $cid > 0) {
                $koneksi->query('UPDATE staff_calls SET ' . implode(', ', $nulls) . ' WHERE id = ' . $cid);
            }
            $callInserted++;
        }
        $stmt->close();
    } else {
        out('[SKIP] staff_calls dummy sudah ada');
    }

    if (!alreadySeeded($koneksi, 'helpdesk_it_tickets', 'kendala', $marker)) {
        $stmt = $koneksi->prepare(
            "INSERT INTO helpdesk_it_tickets
                (nama, nomor, kelas, kendala, status, assigned_user_id, category_id, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $helpdeskCat = 2;
        if (!in_array(2, $categories, true) && $categories[0]) {
            $helpdeskCat = (int) $categories[0];
        }
        for ($i = 0; $i < 22; $i++) {
            $person = $names[($i + 3) % count($names)];
            $dayOffset = $i % 26;
            $hour = 8 + ($i % 9);
            $createdDt = $now->modify("-{$dayOffset} days")->setTime($hour, ($i * 5) % 60, 0);
            $created = $createdDt->format('Y-m-d H:i:s');
            $kendala = $marker . ' ' . $ticketIssues[$i % count($ticketIssues)];
            $kelas = $kelasList[$i % count($kelasList)];
            $assigned = $operators[$i % count($operators)];
            $roll = $i % 7;
            if ($roll === 0 && $dayOffset <= 2) {
                $status = 'pending';
                $updated = $created;
            } elseif ($roll === 1) {
                $status = 'in_progress';
                $updated = $createdDt->modify('+' . (20 + $i) . ' minutes')->format('Y-m-d H:i:s');
            } else {
                $status = 'resolved';
                $respMin = [8, 15, 22, 35, 50, 6, 12][$i % 7];
                $updated = $createdDt->modify("+{$respMin} minutes")->format('Y-m-d H:i:s');
            }
            $stmt->bind_param(
                'sssssiiss',
                $person[0],
                $person[1],
                $kelas,
                $kendala,
                $status,
                $assigned,
                $helpdeskCat,
                $created,
                $updated
            );
            $stmt->execute();
            $ticketInserted++;
        }
        $stmt->close();
    } else {
        out('[SKIP] helpdesk_it_tickets dummy sudah ada');
    }

    $koneksi->commit();
    out("[OK] Dummy visitors: {$visitorInserted}");
    out("[OK] Dummy staff_calls: {$callInserted}");
    out("[OK] Dummy helpdesk tickets: {$ticketInserted}");
    out('Selesai. Buka admin/laporan.php dengan rentang 30 hari terakhir.');
} catch (Throwable $e) {
    $koneksi->rollback();
    fwrite(STDERR, 'Gagal seed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

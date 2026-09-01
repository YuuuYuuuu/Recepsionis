<?php
require_once 'auth.php';
require_once '../staff_call_routing.php';
requireSuperAdminPage();

/* ── Helpers ─────────────────────────────────────────────── */

function laporan_settings(mysqli $koneksi): array
{
    $out = ['site_name' => 'E-Recepsionis System'];
    $res = $koneksi->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('site_name','site_email')");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $out[$row['setting_key']] = (string) ($row['setting_value'] ?? '');
        }
    }
    return $out;
}

function laporan_status_label(string $status): string
{
    $map = [
        'pending' => 'Menunggu',
        'answered' => 'Terjawab',
        'cancelled' => 'Dibatalkan',
        'in_progress' => 'Diproses',
        'resolved' => 'Selesai',
        'expired' => 'Expired',
        'checked-in' => 'Check-In',
        'checked-out' => 'Check-Out',
    ];
    return $map[$status] ?? ucfirst(str_replace('_', ' ', $status));
}

function laporan_sumber_label(string $sumber): string
{
    $map = [
        'call' => 'Panggilan Staff',
        'ticket' => 'Tiket Kelas',
    ];
    return $map[$sumber] ?? ucfirst(str_replace('_', ' ', $sumber));
}

function laporan_ticket_access_label(?string $accessType): string
{
    return ($accessType ?? '') === 'room' ? 'Ruangan' : 'Event';
}

function laporan_ticket_issue_label(?string $issueCategory): string
{
    return recepsionis_issue_category_label((string) ($issueCategory ?? 'other'));
}

function laporan_format_duration(?int $minutes): string
{
    if ($minutes === null || $minutes < 0) {
        return '—';
    }
    if ($minutes < 60) {
        return $minutes . ' mnt';
    }
    $h = intdiv($minutes, 60);
    $m = $minutes % 60;
    return $m > 0 ? "{$h} jam {$m} mnt" : "{$h} jam";
}

function laporan_median(array $values): ?float
{
    $n = count($values);
    if ($n === 0) {
        return null;
    }
    sort($values, SORT_NUMERIC);
    $mid = intdiv($n, 2);
    return $n % 2 === 1
        ? (float) $values[$mid]
        : (((float) $values[$mid - 1] + (float) $values[$mid]) / 2);
}

function laporan_daily_series(string $dateFrom, string $dateTo, array $counts): array
{
    $labels = [];
    $values = [];
    try {
        $start = new DateTimeImmutable($dateFrom);
        $end = new DateTimeImmutable($dateTo);
    } catch (Exception $e) {
        return ['labels' => [], 'values' => []];
    }
    if ($start > $end) {
        [$start, $end] = [$end, $start];
    }
    for ($d = $start; $d <= $end; $d = $d->modify('+1 day')) {
        $key = $d->format('Y-m-d');
        $labels[] = $d->format('d/m');
        $values[] = (int) ($counts[$key] ?? 0);
    }
    return ['labels' => $labels, 'values' => $values];
}

/** @param resource $handle */
function laporan_fputcsv($handle, array $fields): void
{
    fputcsv($handle, $fields, ',', '"', '\\');
}

function laporan_export_csv(string $filename, array $metaLines, array $headers, array $dataRows, array $summary = []): void
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    foreach ($metaLines as $line) {
        laporan_fputcsv($out, is_array($line) ? $line : [$line]);
    }
    if ($metaLines) {
        laporan_fputcsv($out, []);
    }
    laporan_fputcsv($out, $headers);
    foreach ($dataRows as $row) {
        laporan_fputcsv($out, $row);
    }
    if ($summary) {
        laporan_fputcsv($out, []);
        laporan_fputcsv($out, ['RINGKASAN']);
        foreach ($summary as $pair) {
            laporan_fputcsv($out, $pair);
        }
    }
    fclose($out);
    exit;
}

function laporan_query_scalar(mysqli $koneksi, string $sql, string $types = '', ...$params)
{
    if ($types === '') {
        $res = $koneksi->query($sql);
        if (!$res) {
            return 0;
        }
        $row = $res->fetch_assoc();
        return $row ? reset($row) : 0;
    }
    $stmt = $koneksi->prepare($sql);
    if (!$stmt) {
        return 0;
    }
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return $row ? reset($row) : 0;
}

/* ── Config & filters ────────────────────────────────────── */

$settings = laporan_settings($koneksi);
$siteName = trim($settings['site_name'] ?? '') !== '' ? $settings['site_name'] : 'E-Recepsionis System';

$reportTypes = [
    'overview' => [
        'label' => 'Ringkasan Eksekutif',
        'short' => 'Ringkasan',
        'icon' => 'bi-speedometer2',
        'desc' => 'Gambaran lintas modul: tamu, helpdesk, ruangan, dan operator.',
    ],
    'visitors' => [
        'label' => 'Laporan Tamu',
        'short' => 'Tamu',
        'icon' => 'bi-people',
        'desc' => 'Volume check-in/out, durasi kunjungan, dan distribusi host.',
    ],
    'helpdesk' => [
        'label' => 'Laporan Helpdesk',
        'short' => 'Helpdesk',
        'icon' => 'bi-headset',
        'desc' => 'Panggilan staff & tiket kelas, SLA, dan penyelesaian kasus.',
    ],
    'rooms' => [
        'label' => 'Laporan Ruangan',
        'short' => 'Ruangan',
        'icon' => 'bi-door-open',
        'desc' => 'Inventaris ruangan, kelengkapan aset, dan aktivitas terkait.',
    ],
    'operators' => [
        'label' => 'Kinerja Operator',
        'short' => 'Operator',
        'icon' => 'bi-person-badge',
        'desc' => 'Beban kerja PIC, kasus ditangani, dan kecepatan respons.',
    ],
];

$type = (string) ($_GET['type'] ?? 'overview');
if (!isset($reportTypes[$type])) {
    $type = 'overview';
}

$today = date('Y-m-d');
$defaultFrom = date('Y-m-d', strtotime('-29 days'));
$dateFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['from'] ?? '')) ? $_GET['from'] : $defaultFrom;
$dateTo = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['to'] ?? '')) ? $_GET['to'] : $today;
if ($dateFrom > $dateTo) {
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}

$preset = (string) ($_GET['preset'] ?? '');
if ($preset === 'today') {
    $dateFrom = $today;
    $dateTo = $today;
} elseif ($preset === '7d') {
    $dateFrom = date('Y-m-d', strtotime('-6 days'));
    $dateTo = $today;
} elseif ($preset === '30d') {
    $dateFrom = date('Y-m-d', strtotime('-29 days'));
    $dateTo = $today;
} elseif ($preset === 'month') {
    $dateFrom = date('Y-m-01');
    $dateTo = $today;
}

$fromDt = $dateFrom . ' 00:00:00';
$toDt = $dateTo . ' 23:59:59';

$channel = (string) ($_GET['channel'] ?? 'all');
if (!in_array($channel, ['all', 'call', 'ticket'], true)) {
    $channel = 'all';
}
$statusFilter = (string) ($_GET['status'] ?? 'all');
$visitorStatus = (string) ($_GET['vstatus'] ?? 'all');
if (!in_array($visitorStatus, ['all', 'checked-in', 'checked-out', 'pending'], true)) {
    $visitorStatus = 'all';
}
$categoryId = (int) ($_GET['category_id'] ?? 0);

$categories = [];
$catRes = $koneksi->query('SELECT id, nama_kategori FROM complaint_categories WHERE status_aktif = 1 ORDER BY urutan ASC, nama_kategori ASC');
if ($catRes) {
    while ($c = $catRes->fetch_assoc()) {
        $categories[] = $c;
    }
}

$periodLabel = date('d/m/Y', strtotime($dateFrom)) . ' – ' . date('d/m/Y', strtotime($dateTo));
$generatedAt = date('d/m/Y H:i');
$reporterName = (string) ($_SESSION['nama_lengkap'] ?? $_SESSION['username'] ?? 'Admin');
$export = isset($_GET['export']) && $_GET['export'] === 'csv';

$queryBase = array_filter([
    'type' => $type,
    'from' => $dateFrom,
    'to' => $dateTo,
    'channel' => $type === 'helpdesk' ? $channel : null,
    'status' => $type === 'helpdesk' ? $statusFilter : null,
    'vstatus' => $type === 'visitors' ? $visitorStatus : null,
    'category_id' => ($type === 'helpdesk' && $categoryId > 0) ? $categoryId : null,
], static fn($v) => $v !== null && $v !== '' && $v !== 'all' && $v !== 0);

/* ── Data loaders ────────────────────────────────────────── */

$helpdeskRows = [];
$visitorRows = [];
$roomRows = [];
$operatorRows = [];
$overview = [];

// Helpdesk rows (used by helpdesk + overview + operators)
function laporan_load_helpdesk(mysqli $koneksi, string $fromDt, string $toDt, string $channel, string $statusFilter, int $categoryId): array
{
    $rows = [];
    if ($channel === 'all' || $channel === 'call') {
        $sql = "SELECT 'call' AS sumber, sc.id, sc.created_at, sc.visitor_name AS nama, sc.visitor_phone AS kontak,
                       COALESCE(NULLIF(sc.room_name,''), r.nama_ruangan, '') AS lokasi,
                       cc.nama_kategori AS kategori, sc.category_id, sc.call_type AS tipe, sc.status,
                       ua.nama_lengkap AS pic, ub.nama_lengkap AS penanggung, sc.answered_at AS waktu_selesai,
                       CASE WHEN sc.answered_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, sc.created_at, sc.answered_at) ELSE NULL END AS response_menit,
                       sc.follow_up_action, sc.whatsapp_sent, sc.message AS deskripsi,
                       sc.room_id, sc.answered_by, sc.assigned_user_id
                FROM staff_calls sc
                LEFT JOIN rooms r ON r.id = sc.room_id
                LEFT JOIN complaint_categories cc ON cc.id = sc.category_id
                LEFT JOIN users ua ON ua.id = sc.assigned_user_id
                LEFT JOIN users ub ON ub.id = sc.answered_by
                WHERE sc.created_at BETWEEN ? AND ?";
        $types = 'ss';
        $params = [$fromDt, $toDt];
        if ($statusFilter !== 'all' && in_array($statusFilter, ['pending', 'answered', 'cancelled'], true)) {
            $sql .= ' AND sc.status = ?';
            $types .= 's';
            $params[] = $statusFilter;
        }
        if ($categoryId > 0) {
            $sql .= ' AND sc.category_id = ?';
            $types .= 'i';
            $params[] = $categoryId;
        }
        $stmt = $koneksi->prepare($sql);
        if ($stmt) {
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($res && ($row = $res->fetch_assoc())) {
                $rows[] = $row;
            }
            $stmt->close();
        }
    }

    if ($channel === 'all' || $channel === 'ticket') {
        $ticketStatus = $statusFilter;
        if ($statusFilter === 'answered') {
            $ticketStatus = 'resolved';
        } elseif ($statusFilter === 'cancelled') {
            $ticketStatus = 'expired';
        }
        if ($ticketStatus !== '__skip__') {
            recepsionis_expire_stale_helpdesk_tickets($koneksi);
            $hasRespondedAt = recepsionis_column_exists($koneksi, 'helpdesk_it_tickets', 'responded_at');
            $responseExpr = $hasRespondedAt
                ? "CASE
                       WHEN t.responded_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, t.created_at, t.responded_at)
                       WHEN t.status = 'expired' THEN TIMESTAMPDIFF(MINUTE, t.created_at, t.updated_at)
                       WHEN t.status IN ('in_progress', 'resolved') THEN TIMESTAMPDIFF(MINUTE, t.created_at, t.updated_at)
                       ELSE NULL
                   END"
                : "CASE WHEN t.status IN ('in_progress', 'resolved', 'expired') THEN TIMESTAMPDIFF(MINUTE, t.created_at, t.updated_at) ELSE NULL END";
            $respondedSelect = $hasRespondedAt ? 't.responded_at' : 'NULL AS responded_at';
            $sql = "SELECT 'ticket' AS sumber, t.id, t.created_at, t.nama, t.nomor AS kontak,
                           COALESCE(r.nama_ruangan, t.kelas) AS lokasi,
                           cc.nama_kategori AS kategori, t.category_id, 'tiket_kelas' AS tipe, t.status,
                           ua.nama_lengkap AS pic, NULL AS penanggung,
                           CASE WHEN t.status = 'resolved' THEN t.updated_at ELSE NULL END AS waktu_selesai,
                           {$responseExpr} AS response_menit,
                           t.follow_up_action, NULL AS whatsapp_sent, t.kendala AS deskripsi,
                           t.room_id, t.access_type, t.issue_category, {$respondedSelect},
                           NULL AS answered_by, t.assigned_user_id
                    FROM helpdesk_it_tickets t
                    LEFT JOIN rooms r ON r.id = t.room_id
                    LEFT JOIN complaint_categories cc ON cc.id = t.category_id
                    LEFT JOIN users ua ON ua.id = t.assigned_user_id
                    WHERE t.created_at BETWEEN ? AND ?";
            $types = 'ss';
            $params = [$fromDt, $toDt];
            if ($ticketStatus !== 'all') {
                $sql .= ' AND t.status = ?';
                $types .= 's';
                $params[] = $ticketStatus;
            }
            if ($categoryId > 0) {
                $sql .= ' AND t.category_id = ?';
                $types .= 'i';
                $params[] = $categoryId;
            }
            $stmt = $koneksi->prepare($sql);
            if ($stmt) {
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($res && ($row = $res->fetch_assoc())) {
                    $rows[] = $row;
                }
                $stmt->close();
            }
        }
    }

    usort($rows, static fn($a, $b) => strcmp((string) $b['created_at'], (string) $a['created_at']));
    return $rows;
}

function laporan_helpdesk_stats(array $rows): array
{
    $total = count($rows);
    $byStatus = [];
    $bySumber = ['call' => 0, 'ticket' => 0];
    $byKategori = [];
    $byDay = [];
    $byDayCall = [];
    $byDayTicket = [];
    $responseTimes = [];
    $byIssueCategory = [];
    $byAccessType = ['event' => 0, 'room' => 0];
    $waSent = 0;
    $waTotal = 0;
    $done = 0;
    $pending = 0;
    foreach ($rows as $row) {
        $st = (string) $row['status'];
        $byStatus[$st] = ($byStatus[$st] ?? 0) + 1;
        $bySumber[$row['sumber']] = ($bySumber[$row['sumber']] ?? 0) + 1;
        $kat = trim((string) ($row['kategori'] ?? '')) !== '' ? (string) $row['kategori'] : 'Tanpa kategori';
        $byKategori[$kat] = ($byKategori[$kat] ?? 0) + 1;
        $day = substr((string) $row['created_at'], 0, 10);
        $byDay[$day] = ($byDay[$day] ?? 0) + 1;
        $sumberKey = (string) ($row['sumber'] ?? 'call');
        if ($sumberKey === 'ticket') {
            $byDayTicket[$day] = ($byDayTicket[$day] ?? 0) + 1;
            $accessKey = (string) ($row['access_type'] ?? 'event');
            if (!isset($byAccessType[$accessKey])) {
                $byAccessType[$accessKey] = 0;
            }
            $byAccessType[$accessKey]++;
            $issueKey = laporan_ticket_issue_label((string) ($row['issue_category'] ?? 'other'));
            $byIssueCategory[$issueKey] = ($byIssueCategory[$issueKey] ?? 0) + 1;
        } else {
            $byDayCall[$day] = ($byDayCall[$day] ?? 0) + 1;
        }
        if ($row['response_menit'] !== null && $row['response_menit'] !== '') {
            $responseTimes[] = (int) $row['response_menit'];
        }
        if ($row['sumber'] === 'call') {
            $waTotal++;
            if ((int) ($row['whatsapp_sent'] ?? 0) === 1) {
                $waSent++;
            }
        }
        if (in_array($st, ['answered', 'resolved'], true)) {
            $done++;
        }
        if (in_array($st, ['pending', 'in_progress'], true)) {
            $pending++;
        }
    }
    ksort($byDay);
    arsort($byKategori);
    $avg = $responseTimes ? array_sum($responseTimes) / count($responseTimes) : null;
    $median = laporan_median($responseTimes);
    $fast = 0;
    foreach ($responseTimes as $m) {
        if ($m <= 5) {
            $fast++;
        }
    }
    return [
        'total' => $total,
        'done' => $done,
        'pending' => $pending,
        'pct_closed' => $total ? round(($done / $total) * 100, 1) : 0.0,
        'avg' => $avg,
        'median' => $median,
        'pct_fast' => $responseTimes ? round(($fast / count($responseTimes)) * 100, 1) : null,
        'wa_sent' => $waSent,
        'wa_total' => $waTotal,
        'pct_wa' => $waTotal ? round(($waSent / $waTotal) * 100, 1) : null,
        'by_status' => $byStatus,
        'by_sumber' => $bySumber,
        'by_kategori' => $byKategori,
        'by_issue_category' => $byIssueCategory,
        'by_access_type' => $byAccessType,
        'by_day' => $byDay,
        'by_day_call' => $byDayCall,
        'by_day_ticket' => $byDayTicket,
    ];
}

if (in_array($type, ['overview', 'helpdesk', 'operators', 'rooms'], true)) {
    $hdChannel = $type === 'helpdesk' ? $channel : 'all';
    $hdStatus = $type === 'helpdesk' ? $statusFilter : 'all';
    $hdCat = $type === 'helpdesk' ? $categoryId : 0;
    $helpdeskRows = laporan_load_helpdesk($koneksi, $fromDt, $toDt, $hdChannel, $hdStatus, $hdCat);
}
$hdStats = laporan_helpdesk_stats($helpdeskRows);

// Visitors
if (in_array($type, ['overview', 'visitors'], true)) {
    $sql = "SELECT v.*, h.nama AS host_nama, h.departemen AS host_dept,
                   CASE
                     WHEN v.checkin_time IS NOT NULL AND v.checkout_time IS NOT NULL
                       THEN TIMESTAMPDIFF(MINUTE, v.checkin_time, v.checkout_time)
                     ELSE NULL
                   END AS durasi_menit
            FROM visitors v
            LEFT JOIN hosts h ON h.id = v.host_id
            WHERE v.created_at BETWEEN ? AND ?";
    $types = 'ss';
    $params = [$fromDt, $toDt];
    if ($type === 'visitors' && $visitorStatus !== 'all') {
        $sql .= ' AND v.status = ?';
        $types .= 's';
        $params[] = $visitorStatus;
    }
    $sql .= ' ORDER BY v.created_at DESC';
    $stmt = $koneksi->prepare($sql);
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && ($row = $res->fetch_assoc())) {
            $visitorRows[] = $row;
        }
        $stmt->close();
    }
}

$visitorTotal = count($visitorRows);
$visitorByStatus = [];
$visitorByHost = [];
$visitorByDay = [];
$visitorDurations = [];
foreach ($visitorRows as $v) {
    $st = (string) ($v['status'] ?? 'pending');
    $visitorByStatus[$st] = ($visitorByStatus[$st] ?? 0) + 1;
    $host = trim((string) ($v['host_nama'] ?? '')) !== '' ? (string) $v['host_nama'] : 'Tanpa host';
    $visitorByHost[$host] = ($visitorByHost[$host] ?? 0) + 1;
    $day = substr((string) $v['created_at'], 0, 10);
    $visitorByDay[$day] = ($visitorByDay[$day] ?? 0) + 1;
    if ($v['durasi_menit'] !== null && $v['durasi_menit'] !== '') {
        $visitorDurations[] = (int) $v['durasi_menit'];
    }
}
ksort($visitorByDay);
arsort($visitorByHost);
$visitorAvgDur = $visitorDurations ? array_sum($visitorDurations) / count($visitorDurations) : null;

// Rooms
if (in_array($type, ['overview', 'rooms'], true)) {
    $hasFloorRoom = false;
    $colCheck = $koneksi->query("SHOW COLUMNS FROM floor_plans LIKE 'room_id'");
    $hasFloorRoom = $colCheck && $colCheck->num_rows > 0;

    $sql = "SELECT r.*,
                   " . ($hasFloorRoom ? "(SELECT COUNT(*) FROM floor_plans fp WHERE fp.room_id = r.id)" : "0") . " AS has_denah
            FROM rooms r
            ORDER BY r.gedung ASC, r.lantai ASC, r.nama_ruangan ASC";
    $res = $koneksi->query($sql);
    $roomCallCounts = [];
    foreach ($helpdeskRows as $hr) {
        if (($hr['sumber'] ?? '') !== 'call') {
            continue;
        }
        $rid = (int) ($hr['room_id'] ?? 0);
        if ($rid > 0) {
            $roomCallCounts[$rid] = ($roomCallCounts[$rid] ?? 0) + 1;
        }
    }
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $rid = (int) $row['id'];
            $row['calls_period'] = $roomCallCounts[$rid] ?? 0;
            $imgs = trim((string) ($row['images'] ?? ''));
            $row['foto_count'] = $imgs !== '' ? count(array_filter(array_map('trim', explode(',', $imgs)))) : 0;
            $row['has_tv'] = trim((string) ($row['tv_info_image'] ?? '')) !== '' ? 1 : 0;
            $roomRows[] = $row;
        }
    }
}
$roomsActive = 0;
$roomsWithTv = 0;
$roomsWithDenah = 0;
$roomsWithCalls = 0;
foreach ($roomRows as $r) {
    if ((int) ($r['status_aktif'] ?? 0) === 1) {
        $roomsActive++;
    }
    if ((int) ($r['has_tv'] ?? 0) === 1) {
        $roomsWithTv++;
    }
    if ((int) ($r['has_denah'] ?? 0) > 0) {
        $roomsWithDenah++;
    }
    if ((int) ($r['calls_period'] ?? 0) > 0) {
        $roomsWithCalls++;
    }
}

// Operators
if (in_array($type, ['overview', 'operators'], true)) {
    $users = [];
    $ures = $koneksi->query("SELECT id, nama_lengkap, username, role, status_aktif, last_login FROM users WHERE role IN ('admin','operator') ORDER BY nama_lengkap ASC");
    if ($ures) {
        while ($u = $ures->fetch_assoc()) {
            $uid = (int) $u['id'];
            $users[$uid] = [
                'id' => $uid,
                'nama' => (string) ($u['nama_lengkap'] ?: $u['username']),
                'role' => (string) $u['role'],
                'aktif' => (int) $u['status_aktif'],
                'last_login' => $u['last_login'],
                'assigned' => 0,
                'answered' => 0,
                'tickets' => 0,
                'responses' => [],
            ];
        }
    }
    foreach ($helpdeskRows as $hr) {
        $assigned = (int) ($hr['assigned_user_id'] ?? 0);
        $answered = (int) ($hr['answered_by'] ?? 0);
        if ($assigned > 0 && isset($users[$assigned])) {
            if ($hr['sumber'] === 'ticket') {
                $users[$assigned]['tickets']++;
            } else {
                $users[$assigned]['assigned']++;
            }
        }
        if ($answered > 0 && isset($users[$answered])) {
            $users[$answered]['answered']++;
            if ($hr['response_menit'] !== null && $hr['response_menit'] !== '') {
                $users[$answered]['responses'][] = (int) $hr['response_menit'];
            }
        } elseif ($hr['sumber'] === 'ticket' && $assigned > 0 && isset($users[$assigned]) && $hr['status'] === 'resolved'
            && $hr['response_menit'] !== null && $hr['response_menit'] !== '') {
            $users[$assigned]['responses'][] = (int) $hr['response_menit'];
        }
    }
    foreach ($users as $uid => $u) {
        $handled = $u['answered'] + $u['tickets'];
        $avg = $u['responses'] ? array_sum($u['responses']) / count($u['responses']) : null;
        $operatorRows[] = [
            'id' => $uid,
            'nama' => $u['nama'],
            'role' => $u['role'],
            'aktif' => $u['aktif'],
            'last_login' => $u['last_login'],
            'assigned' => $u['assigned'],
            'answered' => $u['answered'],
            'tickets' => $u['tickets'],
            'handled' => $handled,
            'avg_response' => $avg,
        ];
    }
    usort($operatorRows, static fn($a, $b) => $b['handled'] <=> $a['handled']);
}

// Overview cards
if ($type === 'overview') {
    $overview = [
        'visitors' => $visitorTotal,
        'visitors_out' => $visitorByStatus['checked-out'] ?? 0,
        'visitors_in' => (int) laporan_query_scalar($koneksi, "SELECT COUNT(*) FROM visitors WHERE status = 'checked-in'"),
        'visitor_avg_dur' => $visitorAvgDur,
        'helpdesk_total' => $hdStats['total'],
        'helpdesk_done' => $hdStats['done'],
        'helpdesk_pending' => $hdStats['pending'],
        'helpdesk_pct' => $hdStats['pct_closed'],
        'helpdesk_avg' => $hdStats['avg'],
        'rooms_active' => $roomsActive,
        'rooms_total' => count($roomRows),
        'rooms_tv' => $roomsWithTv,
        'rooms_denah' => $roomsWithDenah,
        'rooms_calls' => $roomsWithCalls,
        'ops_active' => count(array_filter($operatorRows, static fn($o) => (int) $o['aktif'] === 1)),
        'ops_handled' => array_sum(array_column($operatorRows, 'handled')),
        'hosts' => (int) laporan_query_scalar($koneksi, "SELECT COUNT(*) FROM hosts WHERE status_aktif = 1"),
    ];
}

/* ── CSV export ──────────────────────────────────────────── */

if ($export) {
    $meta = [
        [$reportTypes[$type]['label'] . ' — ' . $siteName],
        ['Periode', $periodLabel],
        ['Dicetak', $generatedAt],
        ['Oleh', $reporterName],
    ];

    if ($type === 'visitors') {
        $csvRows = [];
        $i = 1;
        foreach ($visitorRows as $v) {
            $csvRows[] = [
                $i++,
                $v['created_at'],
                $v['nama'],
                $v['no_telp'],
                $v['perusahaan'],
                $v['tujuan'],
                $v['host_nama'] ?: '—',
                laporan_status_label((string) $v['status']),
                $v['checkin_time'] ?: '—',
                $v['checkout_time'] ?: '—',
                $v['durasi_menit'] !== null ? (int) $v['durasi_menit'] : '',
                $v['badge_number'] ?: '—',
            ];
        }
        laporan_export_csv(
            'laporan-tamu_' . $dateFrom . '_' . $dateTo . '.csv',
            $meta,
            ['No', 'Waktu Daftar', 'Nama', 'Telepon', 'Perusahaan', 'Tujuan', 'Host', 'Status', 'Check-In', 'Check-Out', 'Durasi (mnt)', 'Badge'],
            $csvRows,
            [
                ['Total tamu', $visitorTotal],
                ['Check-out', $visitorByStatus['checked-out'] ?? 0],
                ['Check-in', $visitorByStatus['checked-in'] ?? 0],
                ['Rata-rata durasi (mnt)', $visitorAvgDur !== null ? round($visitorAvgDur, 1) : ''],
            ]
        );
    }

    if ($type === 'helpdesk') {
        $csvRows = [];
        $i = 1;
        foreach ($helpdeskRows as $row) {
            $csvRows[] = [
                $i++,
                laporan_sumber_label((string) $row['sumber']),
                $row['id'],
                $row['created_at'],
                $row['nama'] ?: '—',
                $row['kontak'] ?: '—',
                $row['lokasi'],
                laporan_ticket_access_label((string) ($row['access_type'] ?? 'event')),
                laporan_ticket_issue_label((string) ($row['issue_category'] ?? 'other')),
                $row['kategori'] ?: '—',
                $row['tipe'],
                laporan_status_label((string) $row['status']),
                $row['pic'] ?: '—',
                $row['penanggung'] ?: '—',
                $row['waktu_selesai'] ?: '—',
                $row['response_menit'] !== null && $row['response_menit'] !== '' ? (int) $row['response_menit'] : '',
                $row['sumber'] === 'call' ? ((int) ($row['whatsapp_sent'] ?? 0) === 1 ? 'Ya' : 'Tidak') : '—',
                preg_replace("/\s+/u", ' ', trim((string) ($row['deskripsi'] ?? ''))),
            ];
        }
        laporan_export_csv(
            'laporan-helpdesk_' . $dateFrom . '_' . $dateTo . '.csv',
            $meta,
            ['No', 'Sumber', 'ID', 'Waktu', 'Nama', 'Kontak', 'Lokasi', 'Tipe Tiket', 'Kategori Kendala', 'Kategori PIC', 'Tipe', 'Status', 'PIC', 'Ditangani', 'Selesai', 'Response (mnt)', 'WA', 'Deskripsi'],
            $csvRows,
            [
                ['Total kasus', $hdStats['total']],
                ['Selesai', $hdStats['done']],
                ['Aktif', $hdStats['pending']],
                ['Penyelesaian (%)', $hdStats['pct_closed']],
                ['Rata-rata response (mnt)', $hdStats['avg'] !== null ? round($hdStats['avg'], 1) : ''],
            ]
        );
    }

    if ($type === 'rooms') {
        $csvRows = [];
        $i = 1;
        foreach ($roomRows as $r) {
            $csvRows[] = [
                $i++,
                $r['kode_ruangan'],
                $r['nama_ruangan'],
                $r['gedung'],
                $r['lantai'],
                $r['lokasi'],
                $r['kapasitas'],
                (int) $r['status_aktif'] === 1 ? 'Aktif' : 'Nonaktif',
                (int) $r['foto_count'],
                (int) $r['has_denah'] > 0 ? 'Ya' : 'Tidak',
                (int) $r['has_tv'] === 1 ? 'Ya' : 'Tidak',
                (int) $r['calls_period'],
            ];
        }
        laporan_export_csv(
            'laporan-ruangan_' . $dateFrom . '_' . $dateTo . '.csv',
            $meta,
            ['No', 'Kode', 'Nama', 'Gedung', 'Lantai', 'Lokasi', 'Kapasitas', 'Status', 'Foto', 'Denah', 'TV Info', 'Panggilan Periode'],
            $csvRows,
            [
                ['Total ruangan', count($roomRows)],
                ['Aktif', $roomsActive],
                ['Punya denah', $roomsWithDenah],
                ['Punya TV Info', $roomsWithTv],
                ['Ada panggilan di periode', $roomsWithCalls],
            ]
        );
    }

    if ($type === 'operators') {
        $csvRows = [];
        $i = 1;
        foreach ($operatorRows as $o) {
            $csvRows[] = [
                $i++,
                $o['nama'],
                $o['role'],
                (int) $o['aktif'] === 1 ? 'Aktif' : 'Nonaktif',
                $o['assigned'],
                $o['answered'],
                $o['tickets'],
                $o['handled'],
                $o['avg_response'] !== null ? round($o['avg_response'], 1) : '',
                $o['last_login'] ?: '—',
            ];
        }
        laporan_export_csv(
            'laporan-operator_' . $dateFrom . '_' . $dateTo . '.csv',
            $meta,
            ['No', 'Nama', 'Role', 'Status', 'Ditugaskan (Call)', 'Terjawab (Call)', 'Tiket', 'Total Ditangani', 'Avg Response (mnt)', 'Last Login'],
            $csvRows,
            [
                ['Total user', count($operatorRows)],
                ['Total kasus ditangani', array_sum(array_column($operatorRows, 'handled'))],
            ]
        );
    }

    if ($type === 'overview') {
        laporan_export_csv(
            'laporan-ringkasan_' . $dateFrom . '_' . $dateTo . '.csv',
            $meta,
            ['Metrik', 'Nilai'],
            [
                ['Tamu (periode)', $overview['visitors']],
                ['Tamu sedang check-in', $overview['visitors_in']],
                ['Rata-rata durasi kunjungan (mnt)', $overview['visitor_avg_dur'] !== null ? round($overview['visitor_avg_dur'], 1) : ''],
                ['Kasus helpdesk', $overview['helpdesk_total']],
                ['Helpdesk selesai', $overview['helpdesk_done']],
                ['Helpdesk aktif', $overview['helpdesk_pending']],
                ['Tingkat penyelesaian helpdesk (%)', $overview['helpdesk_pct']],
                ['Rata-rata response helpdesk (mnt)', $overview['helpdesk_avg'] !== null ? round($overview['helpdesk_avg'], 1) : ''],
                ['Ruangan aktif', $overview['rooms_active'] . ' / ' . $overview['rooms_total']],
                ['Ruangan dengan denah', $overview['rooms_denah']],
                ['Ruangan dengan TV Info', $overview['rooms_tv']],
                ['Ruangan ada panggilan (periode)', $overview['rooms_calls']],
                ['Operator/admin aktif', $overview['ops_active']],
                ['Kasus ditangani operator', $overview['ops_handled']],
                ['Host aktif', $overview['hosts']],
            ]
        );
    }
}

$channelLabel = match ($channel) {
    'call' => 'Panggilan Staff',
    'ticket' => 'Tiket Kelas',
    default => 'Semua sumber',
};
$maxDayHd = $hdStats['by_day'] ? max($hdStats['by_day']) : 1;
$maxKatHd = $hdStats['by_kategori'] ? max($hdStats['by_kategori']) : 1;
$maxIssueHd = !empty($hdStats['by_issue_category']) ? max($hdStats['by_issue_category']) : 1;
$maxHost = $visitorByHost ? max($visitorByHost) : 1;
$maxDayV = $visitorByDay ? max($visitorByDay) : 1;
$maxOps = $operatorRows ? max(array_column($operatorRows, 'handled') ?: [1]) : 1;

$chartDaily = [
    'labels' => [],
    'datasets' => [],
];
if ($type === 'overview' || $type === 'visitors' || $type === 'helpdesk') {
    $visitorSeries = laporan_daily_series($dateFrom, $dateTo, $visitorByDay);
    $callSeries = laporan_daily_series($dateFrom, $dateTo, $hdStats['by_day_call'] ?? []);
    $ticketSeries = laporan_daily_series($dateFrom, $dateTo, $hdStats['by_day_ticket'] ?? []);
    $chartDaily['labels'] = $visitorSeries['labels'] ?: $callSeries['labels'];
    if ($type === 'overview') {
        $chartDaily['datasets'] = [
            ['label' => 'Tamu', 'data' => $visitorSeries['values'], 'color' => '#0f6e56'],
            ['label' => 'Panggilan', 'data' => $callSeries['values'], 'color' => '#0284c7'],
            ['label' => 'Tiket kelas', 'data' => $ticketSeries['values'], 'color' => '#d97706'],
        ];
    } elseif ($type === 'visitors') {
        $chartDaily['datasets'] = [
            ['label' => 'Tamu', 'data' => $visitorSeries['values'], 'color' => '#0f6e56'],
        ];
    } else {
        $chartDaily['datasets'] = [
            ['label' => 'Panggilan staff', 'data' => $callSeries['values'], 'color' => '#0284c7'],
            ['label' => 'Tiket kelas', 'data' => $ticketSeries['values'], 'color' => '#d97706'],
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($reportTypes[$type]['label']) ?> - <?= htmlspecialchars($siteName) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <?php include 'include_admin_head.php'; ?>
    <?php include 'include_staff_call_head.php'; ?>
    <style>
        .rpt-cover {
            background: linear-gradient(135deg, #0f6e56 0%, #155e75 55%, #0b4f63 100%);
            color: #fff;
            border-radius: 14px;
            padding: 0.9rem 1.15rem;
            margin-bottom: 1rem;
            box-shadow: 0 10px 28px rgba(15, 110, 86, 0.22);
        }
        .rpt-cover h1 {
            font-size: 1.2rem;
            font-weight: 700;
            letter-spacing: -0.02em;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.45rem;
        }
        .rpt-cover h1 i { font-size: 1.15rem; opacity: 0.95; }
        .rpt-print-only { display: none; }
        .rpt-cover .rpt-meta { opacity: .92; font-size: .82rem; margin-top: 0.35rem; }
        .rpt-cover .rpt-actions .btn {
            width: 2.35rem;
            height: 2.35rem;
            padding: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
        }
        .rpt-cover .rpt-actions .btn i { font-size: 1.05rem; line-height: 1; }
        .rpt-cover .rpt-actions .btn { border-color: rgba(255,255,255,.45); color: #fff; }
        .rpt-cover .rpt-actions .btn-light { color: #0f6e56; border: none; }
        .rpt-cover .rpt-actions .btn-outline-light:hover { background: rgba(255,255,255,.12); color: #fff; }

        .rpt-filter { margin-bottom: 1rem; }
        .rpt-filter-bar {
            display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between;
            gap: .7rem 1rem; padding: .65rem 1rem; background: #fff;
            border: 1px solid var(--adm-border, #dde3ec); border-radius: 12px;
        }
        .rpt-filter.is-open .rpt-filter-bar { border-radius: 12px 12px 0 0; border-bottom-color: transparent; }
        .rpt-filter-summary { display: flex; flex-wrap: wrap; align-items: center; gap: .45rem .55rem; flex: 1; min-width: 0; }
        .rpt-filter-title { font-weight: 650; font-size: .88rem; display: inline-flex; align-items: center; gap: .35rem; margin-right: .15rem; }
        .rpt-type-select {
            width: auto;
            min-width: 168px;
            max-width: 220px;
            font-size: .84rem !important;
            font-weight: 600;
            border-radius: 8px !important;
            border-color: #c8d2df;
            padding-top: .35rem;
            padding-bottom: .35rem;
            color: #0f6e56;
            background-color: #eef8f4;
        }
        .rpt-type-select:focus {
            border-color: #0f6e56;
            box-shadow: 0 0 0 .2rem rgba(15, 110, 86, .12);
        }
        .rpt-kpi {
            background: #fff;
            border: 1px solid var(--adm-border, #dde3ec);
            border-radius: 12px;
            padding: 1rem 1.05rem;
            height: 100%;
        }
        .rpt-kpi .label {
            font-size: .72rem; text-transform: uppercase; letter-spacing: .04em;
            color: #5f6f82; font-weight: 650; margin-bottom: .3rem;
        }
        .rpt-kpi .value {
            font-size: 1.55rem; font-weight: 700; letter-spacing: -.03em; color: #15202b; line-height: 1.1;
        }
        .rpt-kpi .hint { font-size: .78rem; color: #5f6f82; margin-top: .25rem; }

        .rpt-panel {
            background: #fff;
            border: 1px solid var(--adm-border, #dde3ec);
            border-radius: 12px;
            margin-bottom: 1.1rem;
            overflow: hidden;
        }
        .rpt-panel-h {
            padding: .8rem 1.1rem;
            border-bottom: 1px solid var(--adm-border, #dde3ec);
            font-weight: 650;
            display: flex; align-items: center; gap: .5rem;
            background: #f8fafc;
        }
        .rpt-panel-b { padding: 1.05rem 1.1rem; }

        .rpt-bar { display: flex; align-items: center; gap: .7rem; margin-bottom: .5rem; font-size: .85rem; }
        .rpt-bar:last-child { margin-bottom: 0; }
        .rpt-bar .name { width: 130px; flex-shrink: 0; color: #334155; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .rpt-bar .track { flex: 1; height: 8px; background: #eef2f7; border-radius: 99px; overflow: hidden; }
        .rpt-bar .fill { height: 100%; background: linear-gradient(90deg, #0f6e56, #1a8f6f); border-radius: 99px; }
        .rpt-bar .count { width: 36px; text-align: right; font-weight: 650; }

        .rpt-filter { margin-bottom: 1rem; }
        .rpt-filter-bar {
            display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between;
            gap: .7rem 1rem; padding: .65rem 1rem; background: #fff;
            border: 1px solid var(--adm-border, #dde3ec); border-radius: 12px;
        }
        .rpt-filter.is-open .rpt-filter-bar { border-radius: 12px 12px 0 0; border-bottom-color: transparent; }
        .rpt-filter-summary { display: flex; flex-wrap: wrap; align-items: center; gap: .4rem .5rem; }
        .rpt-filter-title { font-weight: 650; font-size: .88rem; display: inline-flex; align-items: center; gap: .35rem; margin-right: .2rem; }
        .rpt-chip {
            display: inline-flex; align-items: center; gap: .3rem; padding: .2rem .5rem;
            border-radius: 7px; background: #f1f5f9; color: #334155; font-size: .76rem; font-weight: 550;
        }
        .rpt-filter-toggle {
            display: inline-flex; align-items: center; gap: .35rem; border: 1px solid #c8d2df;
            background: #fff; color: #15202b; font-weight: 600; font-size: .84rem;
            padding: .38rem .7rem; border-radius: 8px;
        }
        .rpt-filter-toggle:hover { border-color: #0f6e56; color: #0f6e56; }
        .rpt-filter-toggle .rpt-chevron { transition: transform .28s ease; }
        .rpt-filter.is-open .rpt-filter-toggle .rpt-chevron { transform: rotate(180deg); }
        .rpt-filter-panel {
            display: grid; grid-template-rows: 0fr; background: #fff;
            border: 1px solid transparent; border-top: none; border-radius: 0 0 12px 12px;
            overflow: hidden; opacity: 0;
            transition: grid-template-rows .32s ease, opacity .22s ease, border-color .22s ease;
        }
        .rpt-filter.is-open .rpt-filter-panel {
            grid-template-rows: 1fr; opacity: 1; border-color: var(--adm-border, #dde3ec);
        }
        .rpt-filter-panel-inner { min-height: 0; overflow: hidden; }
        .rpt-filter-form {
            padding: 0 1rem 1rem; display: grid;
            grid-template-columns: repeat(6, minmax(0, 1fr)); gap: .7rem; align-items: end;
        }
        @media (max-width: 991.98px) { .rpt-filter-form { grid-template-columns: repeat(3, 1fr); } }
        @media (max-width: 575.98px) { .rpt-filter-form { grid-template-columns: 1fr 1fr; } }
        .rpt-filter .form-label {
            font-size: .7rem; font-weight: 650; color: #64748b; margin-bottom: .25rem;
            text-transform: uppercase; letter-spacing: .03em;
        }
        .rpt-filter .form-select, .rpt-filter .form-control { font-size: .86rem; border-radius: 8px; }
        .rpt-filter-actions { display: flex; gap: .5rem; grid-column: 1 / -1; max-width: 200px; }
        .rpt-filter-actions .btn { flex: 1; border-radius: 8px; font-weight: 600; font-size: .86rem; }

        .rpt-table thead th {
            font-size: .72rem; text-transform: uppercase; letter-spacing: .03em;
            color: #5f6f82; font-weight: 650; background: #f8fafc; white-space: nowrap;
        }
        .rpt-table td { font-size: .86rem; vertical-align: middle; }
        .rpt-badge {
            display: inline-block; padding: .18rem .5rem; border-radius: 6px;
            font-size: .7rem; font-weight: 650;
        }
        .rpt-badge-call { background: #e0f2fe; color: #0369a1; }
        .rpt-badge-ticket { background: #fef3c7; color: #b45309; }
        .rpt-badge-pending { background: #fff7ed; color: #c2410c; }
        .rpt-badge-answered, .rpt-badge-resolved, .rpt-badge-checked-out { background: #dcfce7; color: #15803d; }
        .rpt-badge-expired { background: #e2e8f0; color: #334155; }
        .rpt-badge-cancelled { background: #f1f5f9; color: #475569; }
        .rpt-badge-in_progress, .rpt-badge-checked-in { background: #e0e7ff; color: #4338ca; }
        .rpt-badge-admin { background: #fce7f3; color: #be185d; }
        .rpt-badge-operator { background: #e0f2fe; color: #0369a1; }
        .rpt-desc { max-width: 200px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: #64748b; }
        .rpt-footnote { font-size: .78rem; color: #64748b; }
        .rpt-chart-wrap {
            position: relative;
            height: 280px;
            width: 100%;
        }
        @media print {
            .rpt-chart-wrap { height: 220px; }
        }
        .rpt-module-card {
            border: 1px solid var(--adm-border, #dde3ec); border-radius: 12px; padding: 1rem 1.1rem;
            background: #fff; height: 100%;
        }
        .rpt-module-card h3 { font-size: 1rem; font-weight: 700; margin: 0 0 .35rem; }
        .rpt-module-card p { font-size: .82rem; color: #64748b; margin-bottom: .75rem; }
        .rpt-stat-line { display: flex; justify-content: space-between; font-size: .86rem; padding: .28rem 0; border-bottom: 1px dashed #e8eef5; }
        .rpt-stat-line:last-child { border-bottom: 0; }
        .rpt-stat-line strong { font-weight: 700; color: #15202b; }

        @media print {
            @page { size: A4 landscape; margin: 12mm; }
            body { background: #fff !important; }
            .navbar, .sidebar, .admin-sidebar-backdrop, .rpt-no-print, .notification-badge { display: none !important; }
            body:has(.sidebar) > .container-fluid > .row { display: block !important; }
            .content-area, .col-md-10 { width: 100% !important; max-width: 100% !important; flex: none !important; padding: 0 !important; }
            .rpt-cover { background: #fff !important; color: #15202b !important; box-shadow: none !important; border: 2px solid #0f6e56; border-radius: 0; }
            .rpt-cover .rpt-actions { display: none !important; }
            .rpt-print-only { display: block !important; }
            .rpt-panel, .rpt-kpi, .rpt-module-card { box-shadow: none !important; break-inside: avoid; }
            a { text-decoration: none !important; color: inherit !important; }
        }
    </style>
</head>
<body>
<?php include 'navbar.php'; ?>
<div class="container-fluid">
    <div class="row">
        <?php include 'sidebar.php'; ?>
        <div class="col-md-10 content-area">

            <div class="rpt-cover">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div>
                        <div class="rpt-print-only small text-uppercase fw-semibold mb-1" style="letter-spacing:.08em;color:#0f6e56!important;">Dokumen Internal</div>
                        <h1><i class="bi <?= htmlspecialchars($reportTypes[$type]['icon']) ?>"></i> <?= htmlspecialchars($reportTypes[$type]['label']) ?></h1>
                        <div class="rpt-print-only rpt-meta">
                            <?= htmlspecialchars($siteName) ?>
                            · Periode <?= htmlspecialchars($periodLabel) ?>
                            · Dicetak <?= htmlspecialchars($generatedAt) ?>
                            · Oleh <?= htmlspecialchars($reporterName) ?>
                        </div>
                    </div>
                    <div class="rpt-actions d-flex gap-2">
                        <a class="btn btn-light btn-sm"
                           href="laporan.php?<?= htmlspecialchars(http_build_query(array_merge($queryBase, ['type' => $type, 'from' => $dateFrom, 'to' => $dateTo, 'export' => 'csv']))) ?>"
                           title="Export CSV" aria-label="Export CSV">
                            <i class="bi bi-filetype-csv"></i>
                        </a>
                        <button type="button" class="btn btn-outline-light btn-sm" onclick="window.print()"
                                title="Cetak / PDF" aria-label="Cetak / PDF">
                            <i class="bi bi-printer"></i>
                        </button>
                    </div>
                </div>
            </div>

            <div class="rpt-filter rpt-no-print" id="rptFilter">
                <div class="rpt-filter-bar">
                    <div class="rpt-filter-summary">
                        <span class="rpt-filter-title"><i class="bi bi-funnel"></i> Filter</span>
                        <select class="form-select form-select-sm rpt-type-select" id="rptTypeQuick" aria-label="Jenis laporan">
                            <?php foreach ($reportTypes as $key => $mod): ?>
                                <option value="<?= htmlspecialchars($key) ?>" <?= $type === $key ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($mod['short']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="rpt-chip"><i class="bi bi-calendar3"></i> <?= htmlspecialchars($periodLabel) ?></span>
                        <?php if ($type === 'helpdesk'): ?>
                            <span class="rpt-chip"><?= htmlspecialchars($channelLabel) ?></span>
                            <span class="rpt-chip"><?= htmlspecialchars($statusFilter === 'all' ? 'Semua status' : laporan_status_label($statusFilter)) ?></span>
                        <?php elseif ($type === 'visitors'): ?>
                            <span class="rpt-chip"><?= htmlspecialchars($visitorStatus === 'all' ? 'Semua status' : laporan_status_label($visitorStatus)) ?></span>
                        <?php endif; ?>
                    </div>
                    <button type="button" class="rpt-filter-toggle" id="rptFilterToggle" aria-expanded="false">
                        <span class="rpt-toggle-label">Atur filter</span>
                        <i class="bi bi-chevron-down rpt-chevron"></i>
                    </button>
                </div>
                <div class="rpt-filter-panel" id="rptFilterPanel">
                    <div class="rpt-filter-panel-inner">
                        <form method="get" class="rpt-filter-form" id="rptFilterForm">
                            <div>
                                <label class="form-label" for="rptType">Jenis laporan</label>
                                <select class="form-select" id="rptType" name="type">
                                    <?php foreach ($reportTypes as $key => $mod): ?>
                                        <option value="<?= htmlspecialchars($key) ?>" <?= $type === $key ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($mod['label']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="form-label" for="rptFrom">Dari</label>
                                <input type="date" name="from" id="rptFrom" class="form-control" value="<?= htmlspecialchars($dateFrom) ?>" required>
                            </div>
                            <div>
                                <label class="form-label" for="rptTo">Sampai</label>
                                <input type="date" name="to" id="rptTo" class="form-control" value="<?= htmlspecialchars($dateTo) ?>" required>
                            </div>
                            <div data-rpt-type="helpdesk" <?= $type !== 'helpdesk' ? 'hidden' : '' ?>>
                                <label class="form-label" for="rptChannel">Sumber</label>
                                <select name="channel" id="rptChannel" class="form-select" <?= $type !== 'helpdesk' ? 'disabled' : '' ?>>
                                    <option value="all" <?= $channel === 'all' ? 'selected' : '' ?>>Semua sumber</option>
                                    <option value="call" <?= $channel === 'call' ? 'selected' : '' ?>>Panggilan Staff</option>
                                    <option value="ticket" <?= $channel === 'ticket' ? 'selected' : '' ?>>Tiket Kelas</option>
                                </select>
                            </div>
                            <div data-rpt-type="helpdesk" <?= $type !== 'helpdesk' ? 'hidden' : '' ?>>
                                <label class="form-label" for="rptStatus">Status</label>
                                <select name="status" id="rptStatus" class="form-select" <?= $type !== 'helpdesk' ? 'disabled' : '' ?>>
                                    <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>Semua status</option>
                                    <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Menunggu</option>
                                    <option value="in_progress" <?= $statusFilter === 'in_progress' ? 'selected' : '' ?>>Diproses</option>
                                    <option value="answered" <?= $statusFilter === 'answered' ? 'selected' : '' ?>>Terjawab</option>
                                    <option value="resolved" <?= $statusFilter === 'resolved' ? 'selected' : '' ?>>Selesai</option>
                                    <option value="expired" <?= $statusFilter === 'expired' ? 'selected' : '' ?>>Expired</option>
                                    <option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : '' ?>>Dibatalkan / Expired</option>
                                </select>
                            </div>
                            <div data-rpt-type="helpdesk" <?= $type !== 'helpdesk' ? 'hidden' : '' ?>>
                                <label class="form-label" for="rptCategory">Kategori</label>
                                <select name="category_id" id="rptCategory" class="form-select" <?= $type !== 'helpdesk' ? 'disabled' : '' ?>>
                                    <option value="0">Semua kategori</option>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?= (int) $cat['id'] ?>" <?= $categoryId === (int) $cat['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($cat['nama_kategori']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div data-rpt-type="visitors" <?= $type !== 'visitors' ? 'hidden' : '' ?>>
                                <label class="form-label" for="rptVStatus">Status tamu</label>
                                <select name="vstatus" id="rptVStatus" class="form-select" <?= $type !== 'visitors' ? 'disabled' : '' ?>>
                                    <option value="all" <?= $visitorStatus === 'all' ? 'selected' : '' ?>>Semua status</option>
                                    <option value="checked-in" <?= $visitorStatus === 'checked-in' ? 'selected' : '' ?>>Check-In</option>
                                    <option value="checked-out" <?= $visitorStatus === 'checked-out' ? 'selected' : '' ?>>Check-Out</option>
                                    <option value="pending" <?= $visitorStatus === 'pending' ? 'selected' : '' ?>>Pending</option>
                                </select>
                            </div>
                            <div class="rpt-filter-actions">
                                <a href="laporan.php?type=<?= urlencode($type) ?>" class="btn btn-outline-secondary">Reset filter</a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <?php if ($type === 'overview'): ?>
                <div class="row g-3 mb-3">
                    <div class="col-6 col-lg-3">
                        <div class="rpt-kpi">
                            <div class="label">Tamu Periode</div>
                            <div class="value"><?= number_format($overview['visitors']) ?></div>
                            <div class="hint"><?= number_format($overview['visitors_in']) ?> sedang check-in</div>
                        </div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="rpt-kpi">
                            <div class="label">Kasus Helpdesk</div>
                            <div class="value"><?= number_format($overview['helpdesk_total']) ?></div>
                            <div class="hint"><?= number_format($overview['helpdesk_pct'], 1) ?>% selesai · <?= number_format($overview['helpdesk_pending']) ?> aktif</div>
                        </div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="rpt-kpi">
                            <div class="label">Ruangan Aktif</div>
                            <div class="value"><?= number_format($overview['rooms_active']) ?></div>
                            <div class="hint"><?= number_format($overview['rooms_calls']) ?> ada panggilan di periode</div>
                        </div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="rpt-kpi">
                            <div class="label">Kinerja Operator</div>
                            <div class="value"><?= number_format($overview['ops_handled']) ?></div>
                            <div class="hint"><?= number_format($overview['ops_active']) ?> user aktif · <?= number_format($overview['hosts']) ?> host</div>
                        </div>
                    </div>
                </div>

                <div class="rpt-panel mb-3">
                    <div class="rpt-panel-h"><i class="bi bi-graph-up"></i> Tren harian</div>
                    <div class="rpt-panel-b">
                        <div class="rpt-chart-wrap">
                            <canvas id="rptDailyChart"></canvas>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-6 col-xl-3">
                        <div class="rpt-module-card">
                            <h3><i class="bi bi-people text-success"></i> Tamu</h3>
                            <p>Aktivitas kunjungan pada periode terpilih.</p>
                            <div class="rpt-stat-line"><span>Total kunjungan</span><strong><?= number_format($overview['visitors']) ?></strong></div>
                            <div class="rpt-stat-line"><span>Rata-rata durasi</span><strong><?= $overview['visitor_avg_dur'] !== null ? htmlspecialchars(laporan_format_duration((int) round($overview['visitor_avg_dur']))) : '—' ?></strong></div>
                            <div class="rpt-stat-line"><span>Check-out</span><strong><?= number_format($overview['visitors_out']) ?></strong></div>
                            <a class="btn btn-sm btn-outline-primary mt-3" href="laporan.php?type=visitors&from=<?= urlencode($dateFrom) ?>&to=<?= urlencode($dateTo) ?>">Buka laporan</a>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <div class="rpt-module-card">
                            <h3><i class="bi bi-headset text-success"></i> Helpdesk</h3>
                            <p>Panggilan staff dan tiket kelas.</p>
                            <div class="rpt-stat-line"><span>Panggilan</span><strong><?= number_format($hdStats['by_sumber']['call'] ?? 0) ?></strong></div>
                            <div class="rpt-stat-line"><span>Tiket</span><strong><?= number_format($hdStats['by_sumber']['ticket'] ?? 0) ?></strong></div>
                            <div class="rpt-stat-line"><span>Avg response</span><strong><?= $overview['helpdesk_avg'] !== null ? htmlspecialchars(laporan_format_duration((int) round($overview['helpdesk_avg']))) : '—' ?></strong></div>
                            <a class="btn btn-sm btn-outline-primary mt-3" href="laporan.php?type=helpdesk&from=<?= urlencode($dateFrom) ?>&to=<?= urlencode($dateTo) ?>">Buka laporan</a>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <div class="rpt-module-card">
                            <h3><i class="bi bi-door-open text-success"></i> Ruangan</h3>
                            <p>Kelengkapan aset & aktivitas ruang.</p>
                            <div class="rpt-stat-line"><span>Dengan denah</span><strong><?= number_format($overview['rooms_denah']) ?></strong></div>
                            <div class="rpt-stat-line"><span>Dengan TV Info</span><strong><?= number_format($overview['rooms_tv']) ?></strong></div>
                            <div class="rpt-stat-line"><span>Total inventaris</span><strong><?= number_format($overview['rooms_total']) ?></strong></div>
                            <a class="btn btn-sm btn-outline-primary mt-3" href="laporan.php?type=rooms&from=<?= urlencode($dateFrom) ?>&to=<?= urlencode($dateTo) ?>">Buka laporan</a>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <div class="rpt-module-card">
                            <h3><i class="bi bi-person-badge text-success"></i> Operator</h3>
                            <p>Beban kerja PIC pada periode ini.</p>
                            <div class="rpt-stat-line"><span>Kasus ditangani</span><strong><?= number_format($overview['ops_handled']) ?></strong></div>
                            <div class="rpt-stat-line"><span>User aktif</span><strong><?= number_format($overview['ops_active']) ?></strong></div>
                            <div class="rpt-stat-line"><span>Host aktif</span><strong><?= number_format($overview['hosts']) ?></strong></div>
                            <a class="btn btn-sm btn-outline-primary mt-3" href="laporan.php?type=operators&from=<?= urlencode($dateFrom) ?>&to=<?= urlencode($dateTo) ?>">Buka laporan</a>
                        </div>
                    </div>
                </div>

            <?php elseif ($type === 'visitors'): ?>
                <div class="row g-3 mb-3">
                    <div class="col-6 col-lg-3"><div class="rpt-kpi"><div class="label">Total Tamu</div><div class="value"><?= number_format($visitorTotal) ?></div><div class="hint">Pada periode terpilih</div></div></div>
                    <div class="col-6 col-lg-3"><div class="rpt-kpi"><div class="label">Check-Out</div><div class="value"><?= number_format($visitorByStatus['checked-out'] ?? 0) ?></div><div class="hint"><?= number_format($visitorByStatus['checked-in'] ?? 0) ?> masih check-in</div></div></div>
                    <div class="col-6 col-lg-3"><div class="rpt-kpi"><div class="label">Rata-rata Durasi</div><div class="value"><?= $visitorAvgDur !== null ? htmlspecialchars(laporan_format_duration((int) round($visitorAvgDur))) : '—' ?></div><div class="hint">Dari data yang sudah check-out</div></div></div>
                    <div class="col-6 col-lg-3"><div class="rpt-kpi"><div class="label">Host Terlibat</div><div class="value"><?= number_format(count($visitorByHost)) ?></div><div class="hint">Distribusi kunjungan per host</div></div></div>
                </div>
                <div class="rpt-panel mb-3">
                    <div class="rpt-panel-h"><i class="bi bi-graph-up"></i> Tren kunjungan harian</div>
                    <div class="rpt-panel-b">
                        <div class="rpt-chart-wrap">
                            <canvas id="rptDailyChart"></canvas>
                        </div>
                    </div>
                </div>
                <div class="row g-3">
                    <div class="col-12">
                        <div class="rpt-panel h-100">
                            <div class="rpt-panel-h"><i class="bi bi-person-vcard"></i> Volume per Host</div>
                            <div class="rpt-panel-b">
                                <?php if (!$visitorByHost): ?>
                                    <p class="text-muted small mb-0">Tidak ada data.</p>
                                <?php else: foreach (array_slice($visitorByHost, 0, 10, true) as $name => $count): ?>
                                    <div class="rpt-bar">
                                        <div class="name" title="<?= htmlspecialchars($name) ?>"><?= htmlspecialchars($name) ?></div>
                                        <div class="track"><div class="fill" style="width:<?= round(($count / $maxHost) * 100) ?>%"></div></div>
                                        <div class="count"><?= (int) $count ?></div>
                                    </div>
                                <?php endforeach; endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="rpt-panel mt-3">
                    <div class="rpt-panel-h"><i class="bi bi-table"></i> Rincian Tamu <span class="ms-auto small fw-normal text-muted"><?= number_format($visitorTotal) ?> baris</span></div>
                    <div class="rpt-panel-b p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0 rpt-table">
                                <thead><tr><th>#</th><th>Waktu</th><th>Nama</th><th>Kontak</th><th>Perusahaan</th><th>Tujuan</th><th>Host</th><th>Status</th><th>Durasi</th></tr></thead>
                                <tbody>
                                <?php if (!$visitorRows): ?>
                                    <tr><td colspan="9" class="text-center text-muted py-4">Tidak ada data tamu pada filter ini.</td></tr>
                                <?php else: $n = 1; foreach ($visitorRows as $v): ?>
                                    <tr>
                                        <td class="text-muted"><?= $n++ ?></td>
                                        <td class="text-nowrap"><?= htmlspecialchars(date('d/m/Y H:i', strtotime((string) $v['created_at']))) ?></td>
                                        <td class="fw-semibold"><?= htmlspecialchars((string) $v['nama']) ?></td>
                                        <td><?= htmlspecialchars((string) ($v['no_telp'] ?: '—')) ?></td>
                                        <td><?= htmlspecialchars((string) ($v['perusahaan'] ?: '—')) ?></td>
                                        <td><div class="rpt-desc" title="<?= htmlspecialchars((string) ($v['tujuan'] ?? '')) ?>"><?= htmlspecialchars((string) ($v['tujuan'] ?: '—')) ?></div></td>
                                        <td><?= htmlspecialchars((string) ($v['host_nama'] ?: '—')) ?></td>
                                        <td><span class="rpt-badge rpt-badge-<?= htmlspecialchars((string) $v['status']) ?>"><?= htmlspecialchars(laporan_status_label((string) $v['status'])) ?></span></td>
                                        <td><?= htmlspecialchars(laporan_format_duration($v['durasi_menit'] !== null ? (int) $v['durasi_menit'] : null)) ?></td>
                                    </tr>
                                <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            <?php elseif ($type === 'helpdesk'): ?>
                <div class="row g-3 mb-3">
                    <div class="col-6 col-lg-3"><div class="rpt-kpi"><div class="label">Total Kasus</div><div class="value"><?= number_format($hdStats['total']) ?></div><div class="hint"><?= (int) ($hdStats['by_sumber']['call'] ?? 0) ?> panggilan · <?= (int) ($hdStats['by_sumber']['ticket'] ?? 0) ?> tiket</div></div></div>
                    <div class="col-6 col-lg-3"><div class="rpt-kpi"><div class="label">Penyelesaian</div><div class="value"><?= number_format($hdStats['pct_closed'], 1) ?>%</div><div class="hint"><?= number_format($hdStats['done']) ?> selesai · <?= number_format($hdStats['pending']) ?> aktif</div></div></div>
                    <div class="col-6 col-lg-3"><div class="rpt-kpi"><div class="label">Rata-rata Response</div><div class="value"><?= $hdStats['avg'] !== null ? htmlspecialchars(laporan_format_duration((int) round($hdStats['avg']))) : '—' ?></div><div class="hint">Median <?= $hdStats['median'] !== null ? htmlspecialchars(laporan_format_duration((int) round($hdStats['median']))) : '—' ?></div></div></div>
                    <div class="col-6 col-lg-3"><div class="rpt-kpi"><div class="label">SLA ≤ 5 Menit</div><div class="value"><?= $hdStats['pct_fast'] !== null ? number_format($hdStats['pct_fast'], 1) . '%' : '—' ?></div><div class="hint">WA <?= $hdStats['pct_wa'] !== null ? number_format($hdStats['pct_wa'], 1) . '%' : '—' ?> (panggilan)</div></div></div>
                </div>
                <div class="rpt-panel mb-3">
                    <div class="rpt-panel-h"><i class="bi bi-graph-up"></i> Tren kasus harian</div>
                    <div class="rpt-panel-b">
                        <div class="rpt-chart-wrap">
                            <canvas id="rptDailyChart"></canvas>
                        </div>
                    </div>
                </div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="rpt-panel h-100">
                            <div class="rpt-panel-h"><i class="bi bi-tags"></i> Per Kategori PIC</div>
                            <div class="rpt-panel-b">
                                <?php if (!$hdStats['by_kategori']): ?><p class="text-muted small mb-0">Tidak ada data.</p>
                                <?php else: foreach ($hdStats['by_kategori'] as $name => $count): ?>
                                    <div class="rpt-bar"><div class="name"><?= htmlspecialchars($name) ?></div><div class="track"><div class="fill" style="width:<?= round(($count / $maxKatHd) * 100) ?>%"></div></div><div class="count"><?= (int) $count ?></div></div>
                                <?php endforeach; endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="rpt-panel h-100">
                            <div class="rpt-panel-h"><i class="bi bi-ui-checks-grid"></i> Per Kategori Kendala</div>
                            <div class="rpt-panel-b">
                                <?php if (empty($hdStats['by_issue_category'])): ?><p class="text-muted small mb-0">Tidak ada data tiket.</p>
                                <?php else: foreach ($hdStats['by_issue_category'] as $name => $count): ?>
                                    <div class="rpt-bar"><div class="name"><?= htmlspecialchars($name) ?></div><div class="track"><div class="fill" style="width:<?= round(($count / $maxIssueHd) * 100) ?>%"></div></div><div class="count"><?= (int) $count ?></div></div>
                                <?php endforeach; endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="rpt-panel mt-3">
                    <div class="rpt-panel-h"><i class="bi bi-table"></i> Rincian Kasus <span class="ms-auto small fw-normal text-muted"><?= number_format($hdStats['total']) ?> baris</span></div>
                    <div class="rpt-panel-b p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0 rpt-table">
                                <thead><tr><th>#</th><th>Waktu</th><th>Sumber</th><th>Nama</th><th>Lokasi</th><th>Tipe</th><th>Kategori Kendala</th><th>Status</th><th>PIC</th><th>Response</th><th>Catatan</th></tr></thead>
                                <tbody>
                                <?php if (!$helpdeskRows): ?>
                                    <tr><td colspan="11" class="text-center text-muted py-4">Tidak ada data kasus.</td></tr>
                                <?php else: $n = 1; foreach ($helpdeskRows as $row):
                                    $st = (string) $row['status']; $sumber = (string) $row['sumber'];
                                    $resp = ($row['response_menit'] !== null && $row['response_menit'] !== '') ? (int) $row['response_menit'] : null;
                                    $ticketType = $sumber === 'ticket' ? laporan_ticket_access_label((string) ($row['access_type'] ?? 'event')) : '—';
                                    $issueLabel = $sumber === 'ticket' ? laporan_ticket_issue_label((string) ($row['issue_category'] ?? 'other')) : '—';
                                ?>
                                    <tr>
                                        <td class="text-muted"><?= $n++ ?></td>
                                        <td class="text-nowrap"><?= htmlspecialchars(date('d/m/Y H:i', strtotime((string) $row['created_at']))) ?></td>
                                        <td><span class="rpt-badge <?= $sumber === 'ticket' ? 'rpt-badge-ticket' : 'rpt-badge-call' ?>"><?= htmlspecialchars(laporan_sumber_label($sumber)) ?></span><div class="small text-muted">#<?= (int) $row['id'] ?></div></td>
                                        <td><div class="fw-semibold"><?= htmlspecialchars((string) ($row['nama'] ?: '—')) ?></div><div class="small text-muted"><?= htmlspecialchars((string) ($row['kontak'] ?: '—')) ?></div></td>
                                        <td><?= htmlspecialchars((string) ($row['lokasi'] ?: '—')) ?></td>
                                        <td><?= htmlspecialchars($ticketType) ?></td>
                                        <td><?= htmlspecialchars($issueLabel) ?></td>
                                        <td><span class="rpt-badge rpt-badge-<?= htmlspecialchars($st) ?>"><?= htmlspecialchars(laporan_status_label($st)) ?></span></td>
                                        <td><?= htmlspecialchars((string) ($row['pic'] ?: ($row['penanggung'] ?: '—'))) ?></td>
                                        <td><?= htmlspecialchars(laporan_format_duration($resp)) ?></td>
                                        <td><div class="rpt-desc" title="<?= htmlspecialchars((string) ($row['deskripsi'] ?? '')) ?>"><?= htmlspecialchars((string) ($row['deskripsi'] ?: '—')) ?></div></td>
                                    </tr>
                                <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            <?php elseif ($type === 'rooms'): ?>
                <div class="row g-3 mb-3">
                    <div class="col-6 col-lg-3"><div class="rpt-kpi"><div class="label">Total Ruangan</div><div class="value"><?= number_format(count($roomRows)) ?></div><div class="hint"><?= number_format($roomsActive) ?> aktif</div></div></div>
                    <div class="col-6 col-lg-3"><div class="rpt-kpi"><div class="label">Punya Denah</div><div class="value"><?= number_format($roomsWithDenah) ?></div><div class="hint"><?= count($roomRows) ? round(($roomsWithDenah / max(count($roomRows), 1)) * 100, 1) : 0 ?>% kelengkapan</div></div></div>
                    <div class="col-6 col-lg-3"><div class="rpt-kpi"><div class="label">TV Info</div><div class="value"><?= number_format($roomsWithTv) ?></div><div class="hint">Konten display terpasang</div></div></div>
                    <div class="col-6 col-lg-3"><div class="rpt-kpi"><div class="label">Ada Aktivitas</div><div class="value"><?= number_format($roomsWithCalls) ?></div><div class="hint">Ruangan dengan panggilan di periode</div></div></div>
                </div>
                <div class="rpt-panel">
                    <div class="rpt-panel-h"><i class="bi bi-building"></i> Inventaris & Aktivitas Ruangan</div>
                    <div class="rpt-panel-b p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0 rpt-table">
                                <thead><tr><th>#</th><th>Kode</th><th>Nama</th><th>Gedung / Lantai</th><th>Kapasitas</th><th>Status</th><th>Foto</th><th>Denah</th><th>TV</th><th>Panggilan</th></tr></thead>
                                <tbody>
                                <?php if (!$roomRows): ?>
                                    <tr><td colspan="10" class="text-center text-muted py-4">Belum ada data ruangan.</td></tr>
                                <?php else: $n = 1; foreach ($roomRows as $r): ?>
                                    <tr>
                                        <td class="text-muted"><?= $n++ ?></td>
                                        <td class="fw-semibold"><?= htmlspecialchars((string) $r['kode_ruangan']) ?></td>
                                        <td><?= htmlspecialchars((string) $r['nama_ruangan']) ?></td>
                                        <td><?= htmlspecialchars(trim(($r['gedung'] ?? '') . ' · Lt ' . ($r['lantai'] ?? '—'), ' ·')) ?></td>
                                        <td><?= htmlspecialchars((string) ($r['kapasitas'] ?: '—')) ?></td>
                                        <td><?= (int) $r['status_aktif'] === 1 ? '<span class="rpt-badge rpt-badge-resolved">Aktif</span>' : '<span class="rpt-badge rpt-badge-cancelled">Nonaktif</span>' ?></td>
                                        <td><?= (int) $r['foto_count'] ?></td>
                                        <td><?= (int) $r['has_denah'] > 0 ? 'Ya' : '—' ?></td>
                                        <td><?= (int) $r['has_tv'] === 1 ? 'Ya' : '—' ?></td>
                                        <td class="fw-semibold"><?= (int) $r['calls_period'] ?></td>
                                    </tr>
                                <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            <?php elseif ($type === 'operators'): ?>
                <div class="row g-3 mb-3">
                    <div class="col-6 col-lg-3"><div class="rpt-kpi"><div class="label">User Backoffice</div><div class="value"><?= number_format(count($operatorRows)) ?></div><div class="hint"><?= number_format(count(array_filter($operatorRows, static fn($o) => (int) $o['aktif'] === 1))) ?> aktif</div></div></div>
                    <div class="col-6 col-lg-3"><div class="rpt-kpi"><div class="label">Kasus Ditangani</div><div class="value"><?= number_format(array_sum(array_column($operatorRows, 'handled'))) ?></div><div class="hint">Call terjawab + tiket</div></div></div>
                    <div class="col-6 col-lg-3"><div class="rpt-kpi"><div class="label">Call Terjawab</div><div class="value"><?= number_format(array_sum(array_column($operatorRows, 'answered'))) ?></div><div class="hint"><?= number_format(array_sum(array_column($operatorRows, 'assigned'))) ?> ditugaskan</div></div></div>
                    <div class="col-6 col-lg-3"><div class="rpt-kpi"><div class="label">Tiket Kelas</div><div class="value"><?= number_format(array_sum(array_column($operatorRows, 'tickets'))) ?></div><div class="hint">Assigned ke PIC</div></div></div>
                </div>
                <div class="row g-3">
                    <div class="col-lg-5">
                        <div class="rpt-panel h-100">
                            <div class="rpt-panel-h"><i class="bi bi-bar-chart"></i> Beban Kerja</div>
                            <div class="rpt-panel-b">
                                <?php
                                $shown = 0;
                                foreach ($operatorRows as $o):
                                    if ((int) $o['handled'] <= 0 && $shown >= 5) {
                                        continue;
                                    }
                                    if ($shown++ >= 10) {
                                        break;
                                    }
                                ?>
                                    <div class="rpt-bar">
                                        <div class="name" title="<?= htmlspecialchars($o['nama']) ?>"><?= htmlspecialchars($o['nama']) ?></div>
                                        <div class="track"><div class="fill" style="width:<?= $maxOps ? round(($o['handled'] / $maxOps) * 100) : 0 ?>%;background:linear-gradient(90deg,#155e75,#0ea5e9)"></div></div>
                                        <div class="count"><?= (int) $o['handled'] ?></div>
                                    </div>
                                <?php endforeach; ?>
                                <?php if ($shown === 0): ?><p class="text-muted small mb-0">Belum ada aktivitas operator di periode ini.</p><?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-7">
                        <div class="rpt-panel h-100">
                            <div class="rpt-panel-h"><i class="bi bi-table"></i> Detail Kinerja</div>
                            <div class="rpt-panel-b p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0 rpt-table">
                                        <thead><tr><th>#</th><th>Nama</th><th>Role</th><th>Ditugaskan</th><th>Terjawab</th><th>Tiket</th><th>Total</th><th>Avg Response</th><th>Last Login</th></tr></thead>
                                        <tbody>
                                        <?php if (!$operatorRows): ?>
                                            <tr><td colspan="9" class="text-center text-muted py-4">Tidak ada user.</td></tr>
                                        <?php else: $n = 1; foreach ($operatorRows as $o): ?>
                                            <tr>
                                                <td class="text-muted"><?= $n++ ?></td>
                                                <td>
                                                    <div class="fw-semibold"><?= htmlspecialchars($o['nama']) ?></div>
                                                    <?= (int) $o['aktif'] === 1 ? '<span class="small text-success">Aktif</span>' : '<span class="small text-muted">Nonaktif</span>' ?>
                                                </td>
                                                <td><span class="rpt-badge rpt-badge-<?= htmlspecialchars($o['role']) ?>"><?= htmlspecialchars($o['role'] === 'admin' ? 'Admin' : 'Operator') ?></span></td>
                                                <td><?= (int) $o['assigned'] ?></td>
                                                <td><?= (int) $o['answered'] ?></td>
                                                <td><?= (int) $o['tickets'] ?></td>
                                                <td class="fw-semibold"><?= (int) $o['handled'] ?></td>
                                                <td><?= htmlspecialchars(laporan_format_duration($o['avg_response'] !== null ? (int) round($o['avg_response']) : null)) ?></td>
                                                <td class="text-nowrap small"><?= $o['last_login'] ? htmlspecialchars(date('d/m/Y H:i', strtotime((string) $o['last_login']))) : '—' ?></td>
                                            </tr>
                                        <?php endforeach; endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<?php if (!empty($chartDaily['labels'])): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
    var canvas = document.getElementById('rptDailyChart');
    if (!canvas || typeof Chart === 'undefined') return;
    var payload = <?= json_encode($chartDaily, JSON_UNESCAPED_UNICODE) ?>;
    var stacked = (payload.datasets || []).length > 1;
    var datasets = (payload.datasets || []).map(function (ds) {
        return {
            label: ds.label,
            data: ds.data,
            backgroundColor: ds.color,
            borderColor: ds.color,
            borderWidth: 0,
            borderRadius: 4,
            maxBarThickness: stacked ? 18 : 22,
            stack: stacked ? 'volume' : undefined
        };
    });
    new Chart(canvas, {
        type: 'bar',
        data: { labels: payload.labels, datasets: datasets },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: {
                    display: datasets.length > 1,
                    position: 'bottom',
                    labels: { boxWidth: 10, usePointStyle: true, padding: 16 }
                },
                tooltip: {
                    callbacks: {
                        title: function (items) { return items.length ? 'Tanggal ' + items[0].label : ''; }
                    }
                }
            },
            scales: {
                x: {
                    stacked: stacked,
                    grid: { display: false },
                    ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 12, color: '#64748b', font: { size: 11 } }
                },
                y: {
                    stacked: stacked,
                    beginAtZero: true,
                    ticks: { precision: 0, color: '#64748b' },
                    grid: { color: '#eef2f7' },
                    border: { display: false }
                }
            }
        }
    });
})();
</script>
<?php endif; ?>
<script>
(function () {
    var root = document.getElementById('rptFilter');
    var toggle = document.getElementById('rptFilterToggle');
    var label = toggle ? toggle.querySelector('.rpt-toggle-label') : null;
    var from = document.getElementById('rptFrom');
    var to = document.getElementById('rptTo');
    var typeQuick = document.getElementById('rptTypeQuick');
    var typeForm = document.getElementById('rptType');
    var storageKey = 'recepsionis_laporan_filter_open';
    var forceOpenKey = 'recepsionis_laporan_force_filter_open';

    function setOpen(open) {
        if (!root || !toggle) return;
        root.classList.toggle('is-open', open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (label) label.textContent = open ? 'Sembunyikan' : 'Atur filter';
        try { localStorage.setItem(storageKey, open ? '1' : '0'); } catch (e) {}
    }

    function syncTypeFilters(type) {
        document.querySelectorAll('[data-rpt-type]').forEach(function (el) {
            var match = el.getAttribute('data-rpt-type') === type;
            el.hidden = !match;
            el.querySelectorAll('select, input').forEach(function (inp) {
                inp.disabled = !match;
            });
        });
        if (typeQuick && typeQuick.value !== type) typeQuick.value = type;
        if (typeForm && typeForm.value !== type) typeForm.value = type;
    }

    function switchReportType(type) {
        syncTypeFilters(type);
        setOpen(true);
        try { sessionStorage.setItem(forceOpenKey, '1'); } catch (e) {}
        try { localStorage.setItem(storageKey, '1'); } catch (e) {}

        var params = new URLSearchParams(window.location.search);
        params.set('type', type);
        if (from && from.value) params.set('from', from.value);
        if (to && to.value) params.set('to', to.value);
        params.delete('preset');
        params.delete('channel');
        params.delete('status');
        params.delete('vstatus');
        params.delete('category_id');
        params.delete('export');
        window.location.search = params.toString();
    }

    if (toggle) {
        var forceOpen = false;
        try {
            forceOpen = sessionStorage.getItem(forceOpenKey) === '1';
            if (forceOpen) sessionStorage.removeItem(forceOpenKey);
        } catch (e) {}
        var saved = null;
        try { saved = localStorage.getItem(storageKey); } catch (e) {}
        setOpen(forceOpen || saved === '1');
        toggle.addEventListener('click', function () {
            setOpen(!root.classList.contains('is-open'));
        });
    }

    syncTypeFilters(<?= json_encode($type, JSON_UNESCAPED_UNICODE) ?>);

    function autoApplyFilters() {
        var form = document.getElementById('rptFilterForm');
        if (form) form.requestSubmit ? form.requestSubmit() : form.submit();
    }

    if (from) from.addEventListener('change', autoApplyFilters);
    if (to) to.addEventListener('change', autoApplyFilters);

    ['rptChannel', 'rptStatus', 'rptCategory', 'rptVStatus'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) el.addEventListener('change', autoApplyFilters);
    });

    if (typeQuick) {
        typeQuick.addEventListener('change', function () {
            switchReportType(typeQuick.value);
        });
    }
    if (typeForm) {
        typeForm.addEventListener('change', function () {
            switchReportType(typeForm.value);
        });
    }
})();
</script>
</body>
</html>

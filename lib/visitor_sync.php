<?php
/**
 * Sinkronisasi panggilan staff → data tamu, checkout, dan auto checkout.
 */

if (!function_exists('recepsionis_get_setting')) {
    function recepsionis_get_setting(mysqli $koneksi, string $key, string $default = ''): string
    {
        static $cache = [];
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        $stmt = $koneksi->prepare('SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1');
        if (!$stmt) {
            return $default;
        }
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        $cache[$key] = $row ? (string) ($row['setting_value'] ?? $default) : $default;
        return $cache[$key];
    }
}

if (!function_exists('recepsionis_checkout_visitor_by_id')) {
    function recepsionis_checkout_visitor_by_id(mysqli $koneksi, int $visitorId, bool $withNotification = false): bool
    {
        if ($visitorId <= 0) {
            return false;
        }

        $stmt = $koneksi->prepare("SELECT id, host_id, nama, status FROM visitors WHERE id = ? AND status = 'checked-in' LIMIT 1");
        $stmt->bind_param('i', $visitorId);
        $stmt->execute();
        $visitor = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$visitor) {
            return false;
        }

        $statusCheckedOut = 'checked-out';
        $stmt = $koneksi->prepare("UPDATE visitors SET status = ?, checkout_time = NOW() WHERE id = ?");
        $stmt->bind_param('si', $statusCheckedOut, $visitorId);
        $stmt->execute();
        $stmt->close();

        $statusCompleted = 'completed';
        $statusWaiting = 'waiting';
        $statusInProgress = 'in-progress';
        $stmt = $koneksi->prepare("UPDATE queue SET status = ?, waktu_selesai = NOW() WHERE visitor_id = ? AND status IN (?, ?)");
        $stmt->bind_param('siss', $statusCompleted, $visitorId, $statusWaiting, $statusInProgress);
        $stmt->execute();
        $stmt->close();

        if ($withNotification) {
            $hostId = $visitor['host_id'] !== null ? (int) $visitor['host_id'] : null;
            $typeCheckout = 'checkout';
            $title = 'Check-Out: ' . $visitor['nama'];
            $message = $visitor['nama'] . ' telah check-out';
            $stmt = $koneksi->prepare('INSERT INTO notifications (host_id, visitor_id, type, title, message) VALUES (?, ?, ?, ?, ?)');
            $stmt->bind_param('iisss', $hostId, $visitorId, $typeCheckout, $title, $message);
            $stmt->execute();
            $stmt->close();
        }

        return true;
    }
}

if (!function_exists('recepsionis_update_visitor_pic_from_staff_call')) {
    /**
     * Isi catatan tamu dengan nama PIC setelah panggilan staff diterima.
     */
    function recepsionis_update_visitor_pic_from_staff_call(mysqli $koneksi, int $callId, int $picUserId): void
    {
        if ($callId <= 0 || $picUserId <= 0) {
            return;
        }

        $stmt = $koneksi->prepare('SELECT visitor_id FROM staff_calls WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $callId);
        $stmt->execute();
        $callRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $visitorId = (int) ($callRow['visitor_id'] ?? 0);
        if ($visitorId <= 0) {
            return;
        }

        $stmt = $koneksi->prepare('SELECT nama_lengkap, username FROM users WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $picUserId);
        $stmt->execute();
        $userRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$userRow) {
            return;
        }

        $picName = trim((string) ($userRow['nama_lengkap'] ?? ''));
        if ($picName === '') {
            $picName = trim((string) ($userRow['username'] ?? ''));
        }
        if ($picName === '') {
            return;
        }

        $notes = 'PIC: ' . $picName;
        $stmt = $koneksi->prepare('UPDATE visitors SET notes = ? WHERE id = ?');
        $stmt->bind_param('si', $notes, $visitorId);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('recepsionis_sync_staff_calls_to_visitors')) {
    /**
     * Buat record tamu untuk panggilan staff lama yang belum punya visitor_id.
     */
    function recepsionis_sync_staff_calls_to_visitors(mysqli $koneksi): int
    {
        $synced = 0;
        $result = $koneksi->query(
            "SELECT id, visitor_name, visitor_phone, message, created_at
             FROM staff_calls
             WHERE visitor_id IS NULL
             ORDER BY id ASC"
        );
        if (!$result) {
            return 0;
        }

        while ($row = $result->fetch_assoc()) {
            $callId = (int) $row['id'];
            $name = (string) ($row['visitor_name'] ?? '');
            $phone = (string) ($row['visitor_phone'] ?? '');
            $message = (string) ($row['message'] ?? '');
            $createdAt = (string) ($row['created_at'] ?? date('Y-m-d H:i:s'));

            if ($name === '' || $phone === '') {
                continue;
            }

            // Coba tautkan ke tamu yang sudah ada (nama + telp + tanggal sama)
            $linkStmt = $koneksi->prepare(
                "SELECT v.id
                 FROM visitors v
                 WHERE v.nama = ?
                   AND v.no_telp = ?
                   AND DATE(v.checkin_time) = DATE(?)
                 ORDER BY v.id DESC
                 LIMIT 1"
            );
            $linkStmt->bind_param('sss', $name, $phone, $createdAt);
            $linkStmt->execute();
            $existing = $linkStmt->get_result()->fetch_assoc();
            $linkStmt->close();

            if ($existing) {
                $visitorId = (int) $existing['id'];
                $stmt = $koneksi->prepare('UPDATE staff_calls SET visitor_id = ? WHERE id = ?');
                $stmt->bind_param('ii', $visitorId, $callId);
                $stmt->execute();
                $stmt->close();
                $synced++;
                continue;
            }

            $badgeNumber = 'TMU' . date('Ymd', strtotime($createdAt)) . str_pad($callId, 4, '0', STR_PAD_LEFT);
            $statusCheckedIn = 'checked-in';
            $company = '';

            $stmt = $koneksi->prepare(
                "INSERT INTO visitors (nama, no_telp, perusahaan, tujuan, status, checkin_time, badge_number, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param('ssssssss', $name, $phone, $company, $message, $statusCheckedIn, $createdAt, $badgeNumber, $createdAt);

            if (!$stmt->execute()) {
                $stmt->close();
                continue;
            }

            $visitorId = (int) $stmt->insert_id;
            $stmt->close();

            $stmt = $koneksi->prepare('UPDATE staff_calls SET visitor_id = ? WHERE id = ?');
            $stmt->bind_param('ii', $visitorId, $callId);
            $stmt->execute();
            $stmt->close();

            $synced++;
        }

        // Isi nomor telp tamu dari staff_calls jika masih kosong
        $koneksi->query(
            "UPDATE visitors v
             INNER JOIN staff_calls sc ON sc.visitor_id = v.id
             SET v.no_telp = sc.visitor_phone
             WHERE (v.no_telp IS NULL OR TRIM(v.no_telp) = '')
               AND sc.visitor_phone IS NOT NULL
               AND TRIM(sc.visitor_phone) <> ''"
        );

        // Backfill catatan PIC untuk panggilan staff yang sudah diterima
        $answered = $koneksi->query(
            "SELECT sc.id, sc.answered_by
             FROM staff_calls sc
             WHERE sc.status = 'answered'
               AND sc.visitor_id IS NOT NULL
               AND sc.answered_by IS NOT NULL"
        );
        if ($answered) {
            while ($row = $answered->fetch_assoc()) {
                recepsionis_update_visitor_pic_from_staff_call(
                    $koneksi,
                    (int) $row['id'],
                    (int) $row['answered_by']
                );
            }
        }

        return $synced;
    }
}

if (!function_exists('recepsionis_run_auto_checkout')) {
    /**
     * Tamu panggilan staff: auto checkout jika sudah lewat hari atau >= 24 jam.
     * Tamu check-in biasa: pakai setting auto_checkout_hours.
     */
    function recepsionis_run_auto_checkout(mysqli $koneksi): int
    {
        $checkedOut = 0;
        $hoursSetting = max(1, (int) recepsionis_get_setting($koneksi, 'auto_checkout_hours', '8'));

        // Panggilan staff: lewat hari atau >= 24 jam
        $staffCallQuery = "
            SELECT DISTINCT v.id
            FROM visitors v
            INNER JOIN staff_calls sc ON sc.visitor_id = v.id
            WHERE v.status = 'checked-in'
              AND (
                    DATE(v.checkin_time) < CURDATE()
                    OR TIMESTAMPDIFF(HOUR, v.checkin_time, NOW()) >= 24
                  )
        ";
        $staffResult = $koneksi->query($staffCallQuery);
        if ($staffResult) {
            while ($row = $staffResult->fetch_assoc()) {
                if (recepsionis_checkout_visitor_by_id($koneksi, (int) $row['id'])) {
                    $checkedOut++;
                }
            }
        }

        // Check-in biasa (bukan dari panggilan staff)
        $regularQuery = "
            SELECT v.id
            FROM visitors v
            LEFT JOIN staff_calls sc ON sc.visitor_id = v.id
            WHERE v.status = 'checked-in'
              AND sc.id IS NULL
              AND TIMESTAMPDIFF(HOUR, v.checkin_time, NOW()) >= ?
        ";
        $stmt = $koneksi->prepare($regularQuery);
        if ($stmt) {
            $stmt->bind_param('i', $hoursSetting);
            $stmt->execute();
            $regularResult = $stmt->get_result();
            while ($row = $regularResult->fetch_assoc()) {
                if (recepsionis_checkout_visitor_by_id($koneksi, (int) $row['id'])) {
                    $checkedOut++;
                }
            }
            $stmt->close();
        }

        return $checkedOut;
    }
}

if (!function_exists('recepsionis_visitors_status_clause')) {
    /**
     * @return array{sql:string, types:string, params:array<int, string>}
     */
    function recepsionis_visitors_status_clause(string $statusFilter): array
    {
        $allowed = ['checked-in', 'checked-out', 'pending'];
        if ($statusFilter !== 'all' && in_array($statusFilter, $allowed, true)) {
            return [
                'sql' => ' WHERE v.status = ?',
                'types' => 's',
                'params' => [$statusFilter],
            ];
        }

        return ['sql' => '', 'types' => '', 'params' => []];
    }
}

if (!function_exists('recepsionis_count_visitors')) {
    function recepsionis_count_visitors(mysqli $koneksi, string $statusFilter = 'all'): int
    {
        $clause = recepsionis_visitors_status_clause($statusFilter);
        $sql = 'SELECT COUNT(*) AS total FROM visitors v' . $clause['sql'];

        if ($clause['types'] !== '') {
            $stmt = $koneksi->prepare($sql);
            if (!$stmt) {
                return 0;
            }
            $stmt->bind_param($clause['types'], ...$clause['params']);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            return (int) ($row['total'] ?? 0);
        }

        $result = $koneksi->query($sql);
        if (!$result) {
            return 0;
        }
        $row = $result->fetch_assoc();

        return (int) ($row['total'] ?? 0);
    }
}

if (!function_exists('recepsionis_fetch_visitors')) {
    function recepsionis_fetch_visitors(
        mysqli $koneksi,
        string $statusFilter = 'all',
        ?int $limit = null,
        ?int $offset = null
    ): mysqli_result|false {
        $sql = "
            SELECT v.*,
                   h.nama AS host_nama,
                   COALESCE(NULLIF(TRIM(v.no_telp), ''), sc.visitor_phone) AS display_phone,
                   cc.nama_kategori AS category_name,
                   sc.call_type AS staff_call_type,
                   sc.id AS staff_call_id,
                   sc.status AS staff_call_status,
                   CASE
                       WHEN sc.id IS NOT NULL THEN
                           CASE
                               WHEN sc.status = 'answered' THEN COALESCE(NULLIF(TRIM(pic.nama_lengkap), ''), pic.username)
                               ELSE NULL
                           END
                       ELSE h.nama
                   END AS display_host_nama
            FROM visitors v
            LEFT JOIN hosts h ON v.host_id = h.id
            LEFT JOIN (
                SELECT sc1.*
                FROM staff_calls sc1
                INNER JOIN (
                    SELECT visitor_id, MAX(id) AS max_id
                    FROM staff_calls
                    WHERE visitor_id IS NOT NULL
                    GROUP BY visitor_id
                ) sc_latest ON sc1.id = sc_latest.max_id
            ) sc ON sc.visitor_id = v.id
            LEFT JOIN complaint_categories cc ON cc.id = sc.category_id
            LEFT JOIN users pic ON pic.id = sc.answered_by
        ";

        $clause = recepsionis_visitors_status_clause($statusFilter);
        $sql .= $clause['sql'] . ' ORDER BY v.created_at DESC';

        $types = $clause['types'];
        $params = $clause['params'];

        if ($limit !== null && $limit > 0) {
            $sql .= ' LIMIT ?';
            $types .= 'i';
            $params[] = $limit;

            if ($offset !== null && $offset > 0) {
                $sql .= ' OFFSET ?';
                $types .= 'i';
                $params[] = $offset;
            }
        }

        if ($types === '') {
            return $koneksi->query($sql);
        }

        $stmt = $koneksi->prepare($sql);
        if (!$stmt) {
            return false;
        }

        $bindValues = $params;
        $bindRefs = [$types];
        $typeChars = str_split($types);
        foreach ($bindValues as $key => $value) {
            if (($typeChars[$key] ?? '') === 'i') {
                $bindValues[$key] = (int) $value;
            }
            $bindRefs[] = &$bindValues[$key];
        }
        call_user_func_array([$stmt, 'bind_param'], $bindRefs);
        $stmt->execute();

        return $stmt->get_result();
    }
}

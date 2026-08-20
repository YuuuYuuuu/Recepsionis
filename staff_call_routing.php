<?php

/**
 * Helper routing / log panggilan staff berbasis kategori.
 */

function recepsionis_table_exists(mysqli $koneksi, string $table): bool
{
    static $cache = [];
    $key = 'table:' . $table;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $safeTable = $koneksi->real_escape_string($table);
    $result = $koneksi->query("SHOW TABLES LIKE '{$safeTable}'");
    $cache[$key] = (bool) ($result && $result->num_rows > 0);

    return $cache[$key];
}

function recepsionis_column_exists(mysqli $koneksi, string $table, string $column): bool
{
    static $cache = [];
    $key = 'column:' . $table . ':' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $safeTable = $koneksi->real_escape_string($table);
    $safeColumn = $koneksi->real_escape_string($column);
    $result = $koneksi->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
    $cache[$key] = (bool) ($result && $result->num_rows > 0);

    return $cache[$key];
}

function recepsionis_get_active_backoffice_users(mysqli $koneksi): array
{
    if (!recepsionis_table_exists($koneksi, 'users')) {
        return [];
    }

    $result = $koneksi->query(
        "SELECT id, username, nama_lengkap, email, role, status_aktif, last_login
         FROM users
         WHERE status_aktif = 1
         ORDER BY FIELD(role, 'admin', 'operator') ASC,
                  COALESCE(NULLIF(nama_lengkap, ''), username) ASC,
                  id ASC"
    );
    if (!$result) {
        return [];
    }

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = [
            'id' => (int) $row['id'],
            'username' => (string) ($row['username'] ?? ''),
            'nama_lengkap' => (string) ($row['nama_lengkap'] ?? ''),
            'email' => (string) ($row['email'] ?? ''),
            'role' => (string) ($row['role'] ?? ''),
            'status_aktif' => (int) ($row['status_aktif'] ?? 0),
            'last_login' => $row['last_login'] ?? null,
        ];
    }

    return $rows;
}

function recepsionis_get_active_user_by_id(mysqli $koneksi, int $userId): ?array
{
    if ($userId <= 0 || !recepsionis_table_exists($koneksi, 'users')) {
        return null;
    }

    $noWaSelect = recepsionis_column_exists($koneksi, 'users', 'no_wa') ? ', no_wa' : '';
    $stmt = $koneksi->prepare(
        "SELECT id, username, nama_lengkap, email, role, status_aktif{$noWaSelect}
         FROM users
         WHERE id = ? AND status_aktif = 1
         LIMIT 1"
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!$row) {
        return null;
    }

    $entry = [
        'id' => (int) $row['id'],
        'username' => (string) ($row['username'] ?? ''),
        'nama_lengkap' => (string) ($row['nama_lengkap'] ?? ''),
        'email' => (string) ($row['email'] ?? ''),
        'role' => (string) ($row['role'] ?? ''),
        'status_aktif' => (int) ($row['status_aktif'] ?? 0),
    ];
    if (array_key_exists('no_wa', $row)) {
        $entry['no_wa'] = (string) ($row['no_wa'] ?? '');
    }

    return $entry;
}

function recepsionis_get_complaint_categories(mysqli $koneksi, bool $onlyActive = false): array
{
    if (!recepsionis_table_exists($koneksi, 'complaint_categories')) {
        return [];
    }

    $sql = "SELECT id, nama_kategori, deskripsi, icon, warna, urutan, status_aktif
            FROM complaint_categories";
    if ($onlyActive) {
        $sql .= " WHERE status_aktif = 1";
    }
    $sql .= " ORDER BY urutan ASC, nama_kategori ASC";

    $result = $koneksi->query($sql);
    if (!$result) {
        return [];
    }

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = [
            'id' => (int) $row['id'],
            'nama_kategori' => (string) ($row['nama_kategori'] ?? ''),
            'deskripsi' => (string) ($row['deskripsi'] ?? ''),
            'icon' => (string) ($row['icon'] ?? 'bi-tag'),
            'warna' => (string) ($row['warna'] ?? '#2563eb'),
            'urutan' => (int) ($row['urutan'] ?? 0),
            'status_aktif' => (int) ($row['status_aktif'] ?? 0),
        ];
    }

    return $rows;
}

function recepsionis_get_active_category_admins(mysqli $koneksi, int $categoryId): array
{
    if ($categoryId <= 0 || !recepsionis_table_exists($koneksi, 'admin_category_routing')) {
        return [];
    }

    $noWaSelect = recepsionis_column_exists($koneksi, 'users', 'no_wa') ? ', u.no_wa' : '';
    $stmt = $koneksi->prepare(
        "SELECT u.id, u.username, u.nama_lengkap, u.email, u.role{$noWaSelect}
         FROM admin_category_routing acr
         INNER JOIN users u ON u.id = acr.user_id
         WHERE acr.category_id = ? AND u.status_aktif = 1
         ORDER BY COALESCE(NULLIF(u.nama_lengkap, ''), u.username) ASC, u.id ASC"
    );
    $stmt->bind_param('i', $categoryId);
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $entry = [
            'id' => (int) $row['id'],
            'username' => (string) ($row['username'] ?? ''),
            'nama_lengkap' => (string) ($row['nama_lengkap'] ?? ''),
            'email' => (string) ($row['email'] ?? ''),
            'role' => (string) ($row['role'] ?? ''),
        ];
        if (array_key_exists('no_wa', $row)) {
            $entry['no_wa'] = (string) ($row['no_wa'] ?? '');
        }
        $rows[] = $entry;
    }
    $stmt->close();

    return $rows;
}

function recepsionis_get_admin_category_ids(mysqli $koneksi, int $userId): array
{
    if ($userId <= 0 || !recepsionis_table_exists($koneksi, 'admin_category_routing')) {
        return [];
    }

    $stmt = $koneksi->prepare(
        "SELECT category_id
         FROM admin_category_routing
         WHERE user_id = ?
         ORDER BY category_id ASC"
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();

    $ids = [];
    while ($row = $res->fetch_assoc()) {
        $ids[] = (int) $row['category_id'];
    }
    $stmt->close();

    return $ids;
}

function recepsionis_get_user_category_index(mysqli $koneksi, array $userIds = []): array
{
    $index = [];
    if (!recepsionis_table_exists($koneksi, 'admin_category_routing')) {
        return $index;
    }

    $cleanUserIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static function ($id) {
        return $id > 0;
    })));

    $sql = "SELECT acr.user_id, acr.category_id, cc.nama_kategori
            FROM admin_category_routing acr
            INNER JOIN complaint_categories cc ON cc.id = acr.category_id";
    if (!empty($cleanUserIds)) {
        $sql .= " WHERE acr.user_id IN (" . implode(',', $cleanUserIds) . ")";
    }
    $sql .= " ORDER BY acr.user_id ASC, cc.urutan ASC, cc.nama_kategori ASC";

    $result = $koneksi->query($sql);
    if (!$result) {
        return $index;
    }

    while ($row = $result->fetch_assoc()) {
        $userId = (int) $row['user_id'];
        if (!isset($index[$userId])) {
            $index[$userId] = [
                'ids' => [],
                'names' => [],
            ];
        }
        $index[$userId]['ids'][] = (int) $row['category_id'];
        $index[$userId]['names'][] = (string) ($row['nama_kategori'] ?? '');
    }

    return $index;
}

function recepsionis_save_user_category_ids(mysqli $koneksi, int $userId, array $categoryIds): void
{
    if ($userId <= 0 || !recepsionis_table_exists($koneksi, 'admin_category_routing')) {
        return;
    }

    $cleanIds = array_values(array_unique(array_filter(array_map('intval', $categoryIds), static function ($id) {
        return $id > 0;
    })));

    $stmtDelete = $koneksi->prepare("DELETE FROM admin_category_routing WHERE user_id = ?");
    $stmtDelete->bind_param('i', $userId);
    $stmtDelete->execute();
    $stmtDelete->close();

    if (empty($cleanIds)) {
        return;
    }

    $stmtInsert = $koneksi->prepare("INSERT INTO admin_category_routing (user_id, category_id) VALUES (?, ?)");
    foreach ($cleanIds as $categoryId) {
        $stmtInsert->bind_param('ii', $userId, $categoryId);
        $stmtInsert->execute();
    }
    $stmtInsert->close();
}

function recepsionis_category_has_routing(mysqli $koneksi, int $categoryId): bool
{
    if ($categoryId <= 0 || !recepsionis_table_exists($koneksi, 'admin_category_routing')) {
        return false;
    }

    $stmt = $koneksi->prepare(
        "SELECT 1
         FROM admin_category_routing
         WHERE category_id = ?
         LIMIT 1"
    );
    $stmt->bind_param('i', $categoryId);
    $stmt->execute();
    $res = $stmt->get_result();
    $exists = (bool) ($res && $res->num_rows > 0);
    $stmt->close();

    return $exists;
}

function recepsionis_user_can_receive_category(mysqli $koneksi, int $userId, int $categoryId): bool
{
    if ($userId <= 0 || $categoryId <= 0) {
        return false;
    }

    foreach (recepsionis_get_active_category_admins($koneksi, $categoryId) as $target) {
        if ((int) ($target['id'] ?? 0) === $userId) {
            return true;
        }
    }

    return false;
}

function recepsionis_get_notification_preferences(mysqli $koneksi, int $userId): array
{
    $defaults = [
        'user_id' => $userId,
        'notifications_enabled' => true,
        'sound_enabled' => true,
    ];

    if ($userId <= 0 || !recepsionis_table_exists($koneksi, 'admin_notification_preferences')) {
        return $defaults;
    }

    $hasNotificationsColumn = recepsionis_column_exists($koneksi, 'admin_notification_preferences', 'notifications_enabled');
    $selectSql = $hasNotificationsColumn
        ? 'SELECT notifications_enabled, sound_enabled FROM admin_notification_preferences WHERE user_id = ? LIMIT 1'
        : 'SELECT sound_enabled FROM admin_notification_preferences WHERE user_id = ? LIMIT 1';

    $stmt = $koneksi->prepare($selectSql);
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!$row) {
        return $defaults;
    }

    if ($hasNotificationsColumn) {
        $defaults['notifications_enabled'] = (int) ($row['notifications_enabled'] ?? 1) === 1;
    }
    $defaults['sound_enabled'] = (int) ($row['sound_enabled'] ?? 1) === 1;

    return $defaults;
}

function recepsionis_set_notification_preferences(
    mysqli $koneksi,
    int $userId,
    ?bool $notificationsEnabled = null,
    ?bool $soundEnabled = null
): bool {
    if ($userId <= 0 || !recepsionis_table_exists($koneksi, 'admin_notification_preferences')) {
        return false;
    }

    $current = recepsionis_get_notification_preferences($koneksi, $userId);
    $notifications = $notificationsEnabled ?? (bool) $current['notifications_enabled'];
    $sound = $soundEnabled ?? (bool) $current['sound_enabled'];
    $notificationsInt = $notifications ? 1 : 0;
    $soundInt = $sound ? 1 : 0;

    if (recepsionis_column_exists($koneksi, 'admin_notification_preferences', 'notifications_enabled')) {
        $stmt = $koneksi->prepare(
            'INSERT INTO admin_notification_preferences (user_id, notifications_enabled, sound_enabled)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE
                notifications_enabled = VALUES(notifications_enabled),
                sound_enabled = VALUES(sound_enabled),
                updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->bind_param('iii', $userId, $notificationsInt, $soundInt);
    } else {
        $stmt = $koneksi->prepare(
            'INSERT INTO admin_notification_preferences (user_id, sound_enabled)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE sound_enabled = VALUES(sound_enabled), updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->bind_param('ii', $userId, $soundInt);
    }

    $ok = $stmt->execute();
    $stmt->close();

    return (bool) $ok;
}

function recepsionis_set_notification_sound_enabled(mysqli $koneksi, int $userId, bool $enabled): bool
{
    return recepsionis_set_notification_preferences($koneksi, $userId, null, $enabled);
}

function recepsionis_set_notifications_enabled(mysqli $koneksi, int $userId, bool $enabled): bool
{
    return recepsionis_set_notification_preferences($koneksi, $userId, $enabled, null);
}

function recepsionis_get_effective_staff_call_targets(mysqli $koneksi, ?int $assignedUserId, int $categoryId): array
{
    $activeAssignee = recepsionis_get_active_user_by_id($koneksi, (int) $assignedUserId);
    if ($activeAssignee) {
        return [$activeAssignee];
    }

    return recepsionis_get_active_category_admins($koneksi, $categoryId);
}

function recepsionis_user_can_receive_staff_call(
    mysqli $koneksi,
    int $userId,
    int $categoryId = 0,
    ?int $assignedUserId = null,
    ?string $role = null
): bool {
    unset($role);

    if ($userId <= 0) {
        return false;
    }

    $activeAssignee = recepsionis_get_active_user_by_id($koneksi, (int) $assignedUserId);
    if ($activeAssignee) {
        return (int) ($activeAssignee['id'] ?? 0) === $userId;
    }

    return recepsionis_user_can_receive_category($koneksi, $userId, $categoryId);
}

function recepsionis_user_is_helpdesk_it_pic(mysqli $koneksi, int $userId): bool
{
    if ($userId <= 0) {
        return false;
    }

    $categoryId = recepsionis_get_helpdesk_category_id($koneksi);

    return $categoryId > 0 && recepsionis_user_can_receive_category($koneksi, $userId, $categoryId);
}

function recepsionis_get_effective_helpdesk_it_targets(mysqli $koneksi, ?int $assignedUserId, ?int $categoryId = null): array
{
    if ($categoryId === null || $categoryId <= 0) {
        $categoryId = recepsionis_get_helpdesk_category_id($koneksi);
    }

    $activeAssignee = recepsionis_get_active_user_by_id($koneksi, (int) $assignedUserId);
    if ($activeAssignee) {
        return [$activeAssignee];
    }

    return $categoryId > 0 ? recepsionis_get_active_category_admins($koneksi, $categoryId) : [];
}

function recepsionis_user_can_receive_helpdesk_it_ticket(
    mysqli $koneksi,
    int $userId,
    ?int $assignedUserId = null,
    ?int $categoryId = null
): bool {
    if ($userId <= 0) {
        return false;
    }

    if ($categoryId === null || $categoryId <= 0) {
        $categoryId = recepsionis_get_helpdesk_category_id($koneksi);
    }

    $activeAssignee = recepsionis_get_active_user_by_id($koneksi, (int) $assignedUserId);
    if ($activeAssignee) {
        return (int) ($activeAssignee['id'] ?? 0) === $userId;
    }

    return recepsionis_user_can_receive_category($koneksi, $userId, $categoryId);
}

function recepsionis_resolve_helpdesk_it_ticket_category_id(mysqli $koneksi, array $ticketRow): int
{
    if (recepsionis_column_exists($koneksi, 'helpdesk_it_tickets', 'category_id')) {
        $categoryId = (int) ($ticketRow['category_id'] ?? 0);
        if ($categoryId > 0) {
            return $categoryId;
        }
    }

    return recepsionis_get_helpdesk_category_id($koneksi);
}

function recepsionis_assign_helpdesk_it_ticket(mysqli $koneksi, int $ticketId, int $assignedUserId): bool
{
    if ($ticketId <= 0 || $assignedUserId <= 0) {
        return false;
    }

    if (!recepsionis_column_exists($koneksi, 'helpdesk_it_tickets', 'assigned_user_id')) {
        return false;
    }

    $assignee = recepsionis_get_active_user_by_id($koneksi, $assignedUserId);
    if (!$assignee) {
        return false;
    }

    $stmt = $koneksi->prepare('UPDATE helpdesk_it_tickets SET assigned_user_id = ? WHERE id = ?');
    $stmt->bind_param('ii', $assignedUserId, $ticketId);
    $stmt->execute();
    $updated = $stmt->affected_rows > 0;
    $stmt->close();

    return $updated;
}

function recepsionis_notify_helpdesk_it_targets(
    mysqli $koneksi,
    array $effectiveTargets,
    string $title,
    string $message,
    string $waMessage,
    ?string $waEntityType = null,
    ?int $waEntityId = null
): void {
    recepsionis_create_in_app_notification($koneksi, $title, $message);

    try {
        $emailSetting = $koneksi->query("SELECT setting_value FROM settings WHERE setting_key = 'email_notification'");
        if ($emailSetting && $emailSetting->num_rows > 0) {
            $setting = $emailSetting->fetch_assoc();
            if ($setting && ($setting['setting_value'] ?? '') === '1') {
                $emailBody = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));
                $headers = "From: " . SMTP_FROM_NAME . " <" . SMTP_FROM_EMAIL . ">\r\n";
                $headers .= "Reply-To: " . SMTP_FROM_EMAIL . "\r\n";
                $headers .= "Content-Type: text/html; charset=UTF-8\r\n";

                foreach ($effectiveTargets as $targetAdmin) {
                    if (!empty($targetAdmin['email'])) {
                        @mail($targetAdmin['email'], $title, $emailBody, $headers);
                    }
                }
            }
        }
    } catch (Throwable $e) {
        error_log('Helpdesk IT email notification error: ' . $e->getMessage());
    }

    try {
        if ($waEntityType !== null && $waEntityId > 0) {
            recepsionis_send_helpdesk_wa_with_action_links(
                $koneksi,
                $waMessage,
                $waEntityType,
                $waEntityId,
                $effectiveTargets
            );
        } else {
            $waTargets = recepsionis_collect_helpdesk_wa_delivery_phones($koneksi, $effectiveTargets);
            recepsionis_send_whatsapp_messages($koneksi, $waMessage, $waTargets['phones'] ?? []);
        }
    } catch (Throwable $e) {
        error_log('Helpdesk IT WhatsApp notification error: ' . $e->getMessage());
    }
}

function recepsionis_assign_staff_call(
    mysqli $koneksi,
    int $staffCallId,
    int $assignedUserId,
    ?int $actorUserId = null,
    ?int $categoryId = null,
    ?string $notes = null,
    array $metadata = []
): bool {
    if (
        $staffCallId <= 0
        || $assignedUserId <= 0
        || !recepsionis_column_exists($koneksi, 'staff_calls', 'assigned_user_id')
    ) {
        return false;
    }

    $assignee = recepsionis_get_active_user_by_id($koneksi, $assignedUserId);
    if (!$assignee) {
        return false;
    }

    $selectColumns = ['category_id'];
    if (recepsionis_column_exists($koneksi, 'staff_calls', 'assigned_user_id')) {
        $selectColumns[] = 'assigned_user_id';
    }

    $stmt = $koneksi->prepare(
        "SELECT " . implode(', ', $selectColumns) . "
         FROM staff_calls
         WHERE id = ?
         LIMIT 1"
    );
    $stmt->bind_param('i', $staffCallId);
    $stmt->execute();
    $res = $stmt->get_result();
    $current = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!$current) {
        return false;
    }

    $previousAssignedUserId = (int) ($current['assigned_user_id'] ?? 0);
    if ($previousAssignedUserId === $assignedUserId) {
        return true;
    }

    $effectiveCategoryId = $categoryId ?? (int) ($current['category_id'] ?? 0);

    $updateSql = "UPDATE staff_calls
                  SET assigned_user_id = ?";

    $hasAssignedBy = recepsionis_column_exists($koneksi, 'staff_calls', 'assigned_by');
    if ($hasAssignedBy) {
        if ($actorUserId !== null && $actorUserId > 0) {
            $updateSql .= ", assigned_by = ?";
        } else {
            $updateSql .= ", assigned_by = NULL";
        }
    }
    if (recepsionis_column_exists($koneksi, 'staff_calls', 'assigned_at')) {
        $updateSql .= ", assigned_at = NOW()";
    }

    $updateSql .= " WHERE id = ?";
    $stmtUpdate = $koneksi->prepare($updateSql);
    if ($hasAssignedBy && $actorUserId !== null && $actorUserId > 0) {
        $stmtUpdate->bind_param('iii', $assignedUserId, $actorUserId, $staffCallId);
    } else {
        $stmtUpdate->bind_param('ii', $assignedUserId, $staffCallId);
    }
    $ok = $stmtUpdate->execute();
    $affected = $stmtUpdate->affected_rows;
    $stmtUpdate->close();

    if (!$ok || $affected <= 0) {
        return false;
    }

    $eventType = $previousAssignedUserId > 0 ? 'reassigned' : 'assigned';
    $defaultNotes = $previousAssignedUserId > 0
        ? 'PIC pengaduan dipindahkan ke admin lain.'
        : 'PIC pengaduan ditetapkan.';

    recepsionis_log_staff_call_event(
        $koneksi,
        $staffCallId,
        $eventType,
        $actorUserId,
        $assignedUserId,
        $effectiveCategoryId > 0 ? $effectiveCategoryId : null,
        $notes ?? $defaultNotes,
        array_merge(
            [
                'previous_assigned_user_id' => $previousAssignedUserId > 0 ? $previousAssignedUserId : null,
                'assigned_user_id' => $assignedUserId,
            ],
            $metadata
        )
    );

    return true;
}

function recepsionis_log_staff_call_event(
    mysqli $koneksi,
    int $staffCallId,
    string $eventType,
    ?int $actorUserId = null,
    ?int $targetUserId = null,
    ?int $categoryId = null,
    ?string $notes = null,
    array $metadata = []
): void {
    if (
        $staffCallId <= 0
        || trim($eventType) === ''
        || !recepsionis_table_exists($koneksi, 'staff_call_logs')
    ) {
        return;
    }

    $eventType = substr(trim($eventType), 0, 50);
    $notes = $notes !== null ? substr($notes, 0, 4000) : null;
    $metadataJson = !empty($metadata)
        ? json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        : null;
    if ($metadataJson !== null && $metadataJson === false) {
        $metadataJson = null;
    }

    $stmt = $koneksi->prepare(
        "INSERT INTO staff_call_logs
            (staff_call_id, event_type, actor_user_id, target_user_id, category_id, notes, metadata_json)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param(
        'isiiiss',
        $staffCallId,
        $eventType,
        $actorUserId,
        $targetUserId,
        $categoryId,
        $notes,
        $metadataJson
    );
    $stmt->execute();
    $stmt->close();
}

function recepsionis_get_staff_call_logs_index(mysqli $koneksi, array $staffCallIds): array
{
    $index = [];
    if (empty($staffCallIds) || !recepsionis_table_exists($koneksi, 'staff_call_logs')) {
        return $index;
    }

    $ids = array_values(array_unique(array_map('intval', $staffCallIds)));
    $ids = array_filter($ids, static function ($id) {
        return $id > 0;
    });
    if (empty($ids)) {
        return $index;
    }

    $in = implode(',', $ids);
    $sql = "SELECT scl.*, actor.nama_lengkap AS actor_name, actor.username AS actor_username,
                   target.nama_lengkap AS target_name, target.username AS target_username,
                   cc.nama_kategori AS category_name
            FROM staff_call_logs scl
            LEFT JOIN users actor ON actor.id = scl.actor_user_id
            LEFT JOIN users target ON target.id = scl.target_user_id
            LEFT JOIN complaint_categories cc ON cc.id = scl.category_id
            WHERE scl.staff_call_id IN ($in)
            ORDER BY scl.created_at ASC, scl.id ASC";
    $res = $koneksi->query($sql);
    if (!$res) {
        return $index;
    }

    while ($row = $res->fetch_assoc()) {
        $staffCallId = (int) $row['staff_call_id'];
        if (!isset($index[$staffCallId])) {
            $index[$staffCallId] = [];
        }
        $index[$staffCallId][] = $row;
    }

    return $index;
}

function recepsionis_format_user_display_name(array $row): string
{
    $name = trim((string) ($row['nama_lengkap'] ?? $row['actor_name'] ?? $row['target_name'] ?? ''));
    if ($name !== '') {
        return $name;
    }

    $username = trim((string) ($row['username'] ?? $row['actor_username'] ?? $row['target_username'] ?? ''));
    return $username !== '' ? $username : 'User';
}

function recepsionis_normalize_phone_for_provider($phone)
{
    $p = trim((string) $phone);
    if ($p === '') {
        return false;
    }
    $p = preg_replace('/[^0-9+]/', '', $p);
    if (strpos($p, '+') === 0) {
        $p = substr($p, 1);
    }
    if (preg_match('/^0+/', $p)) {
        $p = '62' . preg_replace('/^0+/', '', $p);
    }
    if (!preg_match('/^[0-9]{8,}$/', $p)) {
        return false;
    }

    return $p;
}

function recepsionis_get_wa_settings(mysqli $koneksi): array
{
    $settings = [
        'wa_enabled' => false,
        'wa_api_url' => '',
        'wa_api_token' => '',
        'wa_admin_phones' => '',
    ];
    if (!recepsionis_table_exists($koneksi, 'settings')) {
        return $settings;
    }
    $rs = $koneksi->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'wa_%'");
    if ($rs) {
        while ($r = $rs->fetch_assoc()) {
            $settings[$r['setting_key']] = (string) ($r['setting_value'] ?? '');
        }
    }
    $settings['wa_enabled'] = ($settings['wa_enabled'] ?? '0') === '1';

    return $settings;
}

function recepsionis_collect_wa_phones_from_users(array $users): array
{
    $phones = [];
    $invalid = [];
    foreach ($users as $user) {
        $raw = trim((string) ($user['no_wa'] ?? ''));
        if ($raw === '') {
            continue;
        }
        $norm = recepsionis_normalize_phone_for_provider($raw);
        if ($norm === false) {
            $invalid[] = $raw;
        } else {
            $phones[$norm] = true;
        }
    }

    return [
        'phones' => array_keys($phones),
        'invalid' => $invalid,
    ];
}

function recepsionis_collect_wa_fallback_phones(mysqli $koneksi): array
{
    $phones = [];
    $invalid = [];
    $wa = recepsionis_get_wa_settings($koneksi);
    $adminPhones = trim((string) ($wa['wa_admin_phones'] ?? ''));
    if ($adminPhones !== '') {
        foreach (array_map('trim', explode(',', $adminPhones)) as $p) {
            if ($p === '') {
                continue;
            }
            $norm = recepsionis_normalize_phone_for_provider($p);
            if ($norm === false) {
                $invalid[] = $p;
            } else {
                $phones[$norm] = true;
            }
        }
    }
    if (empty($phones) && recepsionis_table_exists($koneksi, 'hosts')) {
        $hres = $koneksi->query("SELECT no_telp FROM hosts WHERE status_aktif = 1 AND no_telp IS NOT NULL AND no_telp != ''");
        if ($hres) {
            while ($hr = $hres->fetch_assoc()) {
                $norm = recepsionis_normalize_phone_for_provider($hr['no_telp'] ?? '');
                if ($norm === false) {
                    $invalid[] = (string) ($hr['no_telp'] ?? '');
                } else {
                    $phones[$norm] = true;
                }
            }
        }
    }

    return [
        'phones' => array_keys($phones),
        'invalid' => $invalid,
    ];
}

function recepsionis_resolve_wa_targets_for_admins(mysqli $koneksi, array $adminUsers): array
{
    $fromUsers = recepsionis_collect_wa_phones_from_users($adminUsers);
    if (!empty($fromUsers['phones'])) {
        return [
            'phones' => $fromUsers['phones'],
            'invalid' => $fromUsers['invalid'],
            'source' => 'category_users',
        ];
    }
    $fallback = recepsionis_collect_wa_fallback_phones($koneksi);

    return [
        'phones' => $fallback['phones'],
        'invalid' => array_merge($fromUsers['invalid'], $fallback['invalid']),
        'source' => 'fallback',
    ];
}

function recepsionis_whatsapp_response_is_success(?string $responseBody, int $httpCode): bool
{
    if ($httpCode < 200 || $httpCode >= 300) {
        return false;
    }

    $decoded = json_decode((string) $responseBody, true);
    if (!is_array($decoded)) {
        return true;
    }

    if (array_key_exists('status', $decoded)) {
        $status = $decoded['status'];
        if ($status === true || $status === 1 || $status === 'true' || $status === 'success') {
            return true;
        }
        if ($status === false || $status === 0 || $status === 'false') {
            return false;
        }
    }

    $detail = strtolower(trim((string) ($decoded['detail'] ?? $decoded['message'] ?? '')));
    if ($detail !== '' && (strpos($detail, 'success') !== false || strpos($detail, 'queue') !== false)) {
        return true;
    }

    return !array_key_exists('status', $decoded);
}

function recepsionis_send_whatsapp_messages(mysqli $koneksi, string $message, array $phones): array
{
    $wa = recepsionis_get_wa_settings($koneksi);
    $responses = [];
    $sentAny = false;
    $invalid = [];

    if (!$wa['wa_enabled'] || trim((string) $wa['wa_api_url']) === '' || empty($phones)) {
        return [
            'sent' => false,
            'responses' => [],
            'invalid' => $invalid,
            'enabled' => $wa['wa_enabled'],
            'reason' => !$wa['wa_enabled'] ? 'disabled' : (trim((string) $wa['wa_api_url']) === '' ? 'missing_api_url' : 'no_phones'),
        ];
    }

    if (!function_exists('curl_init')) {
        error_log('WhatsApp send failed: PHP cURL extension is not available.');

        return [
            'sent' => false,
            'responses' => [],
            'invalid' => $invalid,
            'enabled' => true,
            'reason' => 'curl_missing',
        ];
    }

    foreach ($phones as $phone) {
        $phone_sanitized = recepsionis_normalize_phone_for_provider($phone);
        if ($phone_sanitized === false || $phone_sanitized === '') {
            $invalid[] = (string) $phone;
            continue;
        }
        $payload_arr = [
            'target' => $phone_sanitized,
            'message' => $message,
        ];
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, trim((string) $wa['wa_api_url']));
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload_arr);
        $headers = [];
        if (!empty($wa['wa_api_token'])) {
            $headers[] = 'Authorization: ' . $wa['wa_api_token'];
        }
        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        $resp = curl_exec($ch);
        $httpcode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $entry = [
            'phone' => $phone_sanitized,
            'http_code' => $httpcode,
            'response' => $resp === false ? null : (string) $resp,
            'error' => curl_errno($ch) ? curl_error($ch) : null,
        ];
        if (!curl_errno($ch) && recepsionis_whatsapp_response_is_success($entry['response'], $httpcode)) {
            $sentAny = true;
        } elseif (!curl_errno($ch)) {
            error_log('WhatsApp send rejected for ' . $phone_sanitized . ': HTTP ' . $httpcode . ' body=' . (string) $entry['response']);
        } else {
            error_log('WhatsApp send curl error for ' . $phone_sanitized . ': ' . (string) $entry['error']);
        }
        curl_close($ch);
        $responses[] = $entry;
    }

    return [
        'sent' => $sentAny,
        'responses' => $responses,
        'invalid' => $invalid,
        'enabled' => true,
        'reason' => $sentAny ? 'ok' : 'send_failed',
    ];
}

function recepsionis_get_helpdesk_it_category_id(mysqli $koneksi): int
{
    if (recepsionis_table_exists($koneksi, 'settings')) {
        $res = $koneksi->query("SELECT setting_value FROM settings WHERE setting_key = 'helpdesk_it_category_id' LIMIT 1");
        if ($res && $res->num_rows > 0) {
            $id = (int) $res->fetch_assoc()['setting_value'];
            if ($id > 0) {
                return $id;
            }
        }
    }
    if (!recepsionis_table_exists($koneksi, 'complaint_categories')) {
        return 0;
    }
    $res = $koneksi->query(
        "SELECT id FROM complaint_categories
         WHERE status_aktif = 1 AND (LOWER(nama_kategori) LIKE '%help%' OR LOWER(nama_kategori) LIKE '%helpdesk%')
         ORDER BY urutan ASC, id ASC LIMIT 1"
    );
    if ($res && $res->num_rows > 0) {
        return (int) $res->fetch_assoc()['id'];
    }

    return 0;
}

/** Kategori Helpdesk (satu sumber: Panggilan Staff + Tiket QR). */
function recepsionis_get_helpdesk_category_id(mysqli $koneksi): int
{
    return recepsionis_get_helpdesk_it_category_id($koneksi);
}

function recepsionis_user_is_helpdesk_pic(mysqli $koneksi, int $userId): bool
{
    return recepsionis_user_is_helpdesk_it_pic($koneksi, $userId);
}

function recepsionis_user_can_receive_helpdesk_ticket(
    mysqli $koneksi,
    int $userId,
    ?int $assignedUserId = null,
    ?int $categoryId = null
): bool {
    return recepsionis_user_can_receive_helpdesk_it_ticket($koneksi, $userId, $assignedUserId, $categoryId);
}

function recepsionis_format_action_count(int $count): string
{
    return $count > 99 ? '99+' : (string) $count;
}

function recepsionis_count_actionable_pending_staff_calls(
    mysqli $koneksi,
    int $userId,
    bool $isAdminUser = false,
    string $viewFilter = 'mine',
    ?string $userRole = null
): int {
    if ($userId <= 0) {
        return 0;
    }

    $result = $koneksi->query("SELECT id, category_id, assigned_user_id FROM staff_calls WHERE status = 'pending'");
    if (!$result) {
        return 0;
    }

    $countAll = $isAdminUser && $viewFilter === 'all';
    $count = 0;
    while ($row = $result->fetch_assoc()) {
        if ($countAll) {
            $count++;
            continue;
        }

        if (recepsionis_user_can_receive_staff_call(
            $koneksi,
            $userId,
            (int) ($row['category_id'] ?? 0),
            (int) ($row['assigned_user_id'] ?? 0),
            $isAdminUser && $viewFilter === 'mine' ? 'operator' : (string) $userRole
        )) {
            $count++;
        }
    }

    return $count;
}

function recepsionis_count_actionable_pending_helpdesk_tickets(
    mysqli $koneksi,
    int $userId,
    bool $isAdminUser = false,
    string $viewFilter = 'mine'
): int {
    if ($userId <= 0 || !recepsionis_table_exists($koneksi, 'helpdesk_it_tickets')) {
        return 0;
    }

    if (!$isAdminUser && !recepsionis_user_is_helpdesk_pic($koneksi, $userId)) {
        return 0;
    }

    $result = $koneksi->query(
        "SELECT * FROM helpdesk_it_tickets WHERE status IN ('pending', 'in_progress') ORDER BY created_at DESC LIMIT 500"
    );
    if (!$result) {
        return 0;
    }

    $countAll = $isAdminUser && $viewFilter === 'all';
    $count = 0;
    while ($row = $result->fetch_assoc()) {
        if ($countAll) {
            $count++;
            continue;
        }

        $assignedUserId = isset($row['assigned_user_id']) ? (int) $row['assigned_user_id'] : null;
        if ($assignedUserId !== null && $assignedUserId <= 0) {
            $assignedUserId = null;
        }

        if (recepsionis_user_can_receive_helpdesk_ticket(
            $koneksi,
            $userId,
            $assignedUserId,
            recepsionis_resolve_helpdesk_it_ticket_category_id($koneksi, $row)
        ) || (int) ($row['assigned_user_id'] ?? 0) === $userId) {
            $count++;
        }
    }

    return $count;
}

function recepsionis_get_helpdesk_action_counts(
    mysqli $koneksi,
    int $userId,
    bool $isAdminUser = false,
    string $viewFilter = 'mine',
    ?string $userRole = null
): array {
    if (!in_array($viewFilter, ['all', 'mine'], true)) {
        $viewFilter = $isAdminUser ? 'all' : 'mine';
    }

    $calls = recepsionis_count_actionable_pending_staff_calls(
        $koneksi,
        $userId,
        $isAdminUser,
        $viewFilter,
        $userRole
    );
    $tickets = recepsionis_count_actionable_pending_helpdesk_tickets(
        $koneksi,
        $userId,
        $isAdminUser,
        $viewFilter
    );

    return [
        'calls' => $calls,
        'tickets' => $tickets,
        'total' => $calls + $tickets,
    ];
}

function recepsionis_get_helpdesk_it_access(mysqli $koneksi): ?array
{
    if (!recepsionis_table_exists($koneksi, 'helpdesk_it_access')) {
        return null;
    }
    $res = $koneksi->query('SELECT * FROM helpdesk_it_access WHERE status_aktif = 1 ORDER BY id ASC LIMIT 1');
    if (!$res || $res->num_rows === 0) {
        return null;
    }

    return $res->fetch_assoc();
}

function recepsionis_validate_helpdesk_it_token(mysqli $koneksi, string $token): bool
{
    $token = trim($token);
    if ($token === '') {
        return false;
    }
    $access = recepsionis_get_helpdesk_it_access($koneksi);
    if (!$access) {
        return false;
    }

    return hash_equals((string) ($access['public_token'] ?? ''), $token);
}

function recepsionis_regenerate_helpdesk_it_token(mysqli $koneksi): ?string
{
    if (!recepsionis_table_exists($koneksi, 'helpdesk_it_access')) {
        return null;
    }
    $token = bin2hex(random_bytes(16));
    $cnt = $koneksi->query('SELECT COUNT(*) AS c FROM helpdesk_it_access');
    $n = $cnt ? (int) $cnt->fetch_assoc()['c'] : 0;
    if ($n === 0) {
        $stmt = $koneksi->prepare('INSERT INTO helpdesk_it_access (public_token, status_aktif) VALUES (?, 1)');
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $stmt->close();
    } else {
        $koneksi->query('UPDATE helpdesk_it_access SET public_token = ' . "'" . $koneksi->real_escape_string($token) . "', status_aktif = 1, updated_at = CURRENT_TIMESTAMP ORDER BY id ASC LIMIT 1");
    }

    return $token;
}

function recepsionis_create_in_app_notification(mysqli $koneksi, string $title, string $message): void
{
    if (!recepsionis_table_exists($koneksi, 'notifications')) {
        return;
    }
    try {
        $stmt = $koneksi->prepare('INSERT INTO notifications (host_id, type, title, message) VALUES (NULL, ?, ?, ?)');
        $type = 'system';
        $stmt->bind_param('sss', $type, $title, $message);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        error_log('Notification insert error: ' . $e->getMessage());
    }
}

function recepsionis_follow_up_action_label(string $action): string
{
    if ($action === 'confirm') {
        return 'Ditindaklanjuti';
    }
    if ($action === 'wait') {
        return 'Dalam antrian';
    }

    return '';
}

function recepsionis_build_helpdesk_action_url(string $linkKey): string
{
    return rtrim(recepsionis_get_public_base_url(), '/') . '/visitor/h.php?c=' . rawurlencode($linkKey);
}

function recepsionis_hash_helpdesk_wa_action_token(string $rawToken): string
{
    return hash('sha256', $rawToken);
}

function recepsionis_generate_helpdesk_short_code(): string
{
    $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
    $max = strlen($alphabet) - 1;
    $code = '';
    for ($i = 0; $i < 8; $i++) {
        $code .= $alphabet[random_int(0, $max)];
    }

    return $code;
}

function recepsionis_generate_unique_helpdesk_short_code(mysqli $koneksi): ?string
{
    if (!recepsionis_column_exists($koneksi, 'helpdesk_wa_action_tokens', 'short_code')) {
        return null;
    }

    for ($attempt = 0; $attempt < 8; $attempt++) {
        $code = recepsionis_generate_helpdesk_short_code();
        $stmt = $koneksi->prepare('SELECT id FROM helpdesk_wa_action_tokens WHERE short_code = ? LIMIT 1');
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('s', $code);
        $stmt->execute();
        $res = $stmt->get_result();
        $exists = $res && $res->num_rows > 0;
        $stmt->close();
        if (!$exists) {
            return $code;
        }
    }

    return null;
}

function recepsionis_lookup_helpdesk_wa_token_row(mysqli $koneksi, string $linkKey): ?array
{
    $linkKey = trim($linkKey);
    if ($linkKey === '' || !recepsionis_table_exists($koneksi, 'helpdesk_wa_action_tokens')) {
        return null;
    }

    if (
        recepsionis_column_exists($koneksi, 'helpdesk_wa_action_tokens', 'short_code')
        && strlen($linkKey) <= 16
        && preg_match('/^[A-Za-z0-9]+$/', $linkKey) === 1
    ) {
        $stmt = $koneksi->prepare('SELECT * FROM helpdesk_wa_action_tokens WHERE short_code = ? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('s', $linkKey);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            $stmt->close();
            if ($row) {
                return $row;
            }
        }
    }

    if (strlen($linkKey) < 32) {
        return null;
    }

    $tokenHash = recepsionis_hash_helpdesk_wa_action_token($linkKey);
    $stmt = $koneksi->prepare('SELECT * FROM helpdesk_wa_action_tokens WHERE token_hash = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('s', $tokenHash);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    return $row ?: null;
}

function recepsionis_create_helpdesk_wa_action_token(
    mysqli $koneksi,
    string $entityType,
    int $entityId,
    int $adminUserId,
    int $ttlHours = 72
): ?array {
    if (
        !recepsionis_table_exists($koneksi, 'helpdesk_wa_action_tokens')
        || !in_array($entityType, ['call', 'ticket'], true)
        || $entityId <= 0
        || $adminUserId <= 0
    ) {
        return null;
    }

    $rawToken = bin2hex(random_bytes(32));
    $tokenHash = recepsionis_hash_helpdesk_wa_action_token($rawToken);
    $shortCode = recepsionis_generate_unique_helpdesk_short_code($koneksi);
    $expiresAt = date('Y-m-d H:i:s', time() + max(1, $ttlHours) * 3600);

    if ($shortCode !== null) {
        $stmt = $koneksi->prepare(
            'INSERT INTO helpdesk_wa_action_tokens (token_hash, short_code, entity_type, entity_id, admin_user_id, expires_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        if (!$stmt) {
            error_log('Helpdesk WA token prepare failed: ' . $koneksi->error);

            return null;
        }
        $stmt->bind_param('sssiis', $tokenHash, $shortCode, $entityType, $entityId, $adminUserId, $expiresAt);
    } else {
        $stmt = $koneksi->prepare(
            'INSERT INTO helpdesk_wa_action_tokens (token_hash, entity_type, entity_id, admin_user_id, expires_at)
             VALUES (?, ?, ?, ?, ?)'
        );
        if (!$stmt) {
            error_log('Helpdesk WA token prepare failed: ' . $koneksi->error);

            return null;
        }
        $stmt->bind_param('ssiis', $tokenHash, $entityType, $entityId, $adminUserId, $expiresAt);
    }

    $ok = $stmt->execute();
    if (!$ok) {
        error_log('Helpdesk WA token insert failed: ' . $stmt->error);
    }
    $stmt->close();

    if (!$ok) {
        return null;
    }

    return [
        'raw' => $rawToken,
        'short_code' => $shortCode ?? $rawToken,
        'link_key' => $shortCode ?? $rawToken,
    ];
}

function recepsionis_collect_helpdesk_wa_delivery_phones(mysqli $koneksi, array $adminUsers): array
{
    $fromUsers = recepsionis_collect_wa_phones_from_users($adminUsers);
    $fallback = recepsionis_collect_wa_fallback_phones($koneksi);
    $phones = [];

    foreach (array_merge($fromUsers['phones'], $fallback['phones']) as $phone) {
        $phones[(string) $phone] = true;
    }

    return [
        'phones' => array_keys($phones),
        'invalid' => array_merge($fromUsers['invalid'], $fallback['invalid']),
    ];
}

function recepsionis_send_helpdesk_wa_with_action_links(
    mysqli $koneksi,
    string $baseMessage,
    string $entityType,
    int $entityId,
    array $effectiveTargets
): array {
    $result = [
        'sent' => false,
        'mode' => 'none',
        'responses' => [],
        'errors' => [],
    ];

    if (!in_array($entityType, ['call', 'ticket'], true) || $entityId <= 0) {
        $result['errors'][] = 'invalid_entity';

        return $result;
    }

    if (empty($effectiveTargets)) {
        $result['errors'][] = 'no_targets';

        return $result;
    }

    $sentPhones = [];
    foreach ($effectiveTargets as $targetAdmin) {
        $adminUserId = (int) ($targetAdmin['id'] ?? 0);
        if ($adminUserId <= 0) {
            continue;
        }

        $tokenBundle = recepsionis_create_helpdesk_wa_action_token($koneksi, $entityType, $entityId, $adminUserId);
        if ($tokenBundle === null) {
            $result['errors'][] = 'token_failed:' . $adminUserId;
            continue;
        }

        $actionUrl = recepsionis_build_helpdesk_action_url((string) $tokenBundle['link_key']);
        $message = rtrim($baseMessage) . "\n\nTindak lanjut:\n" . $actionUrl;

        $waTargets = recepsionis_collect_helpdesk_wa_delivery_phones($koneksi, [$targetAdmin]);
        $phones = $waTargets['phones'] ?? [];
        $phones = array_values(array_diff($phones, $sentPhones));
        if (empty($phones)) {
            $result['errors'][] = 'no_phone:' . $adminUserId;
            continue;
        }

        $sendResult = recepsionis_send_whatsapp_messages($koneksi, $message, $phones);
        $result['responses'] = array_merge($result['responses'], $sendResult['responses'] ?? []);
        if (!empty($sendResult['sent'])) {
            $result['sent'] = true;
            $result['mode'] = 'per_admin';
            $sentPhones = array_merge($sentPhones, $phones);
        }
    }

    if ($result['sent']) {
        return $result;
    }

    $allTargets = recepsionis_collect_helpdesk_wa_delivery_phones($koneksi, $effectiveTargets);
    $phones = $allTargets['phones'] ?? [];
    if (empty($phones)) {
        error_log(
            'Helpdesk WA not sent for ' . $entityType . '#' . $entityId
            . ': no valid phone numbers. errors=' . json_encode($result['errors'], JSON_UNESCAPED_UNICODE)
        );

        return $result;
    }

    $message = rtrim($baseMessage);
    $firstAdminId = (int) ($effectiveTargets[0]['id'] ?? 0);
    if ($firstAdminId > 0) {
        $tokenBundle = recepsionis_create_helpdesk_wa_action_token($koneksi, $entityType, $entityId, $firstAdminId);
        if ($tokenBundle !== null) {
            $message .= "\n\nTindak lanjut:\n" . recepsionis_build_helpdesk_action_url((string) $tokenBundle['link_key']);
        } else {
            $result['errors'][] = 'fallback_token_failed';
        }
    }

    $sendResult = recepsionis_send_whatsapp_messages($koneksi, $message, $phones);
    $result['responses'] = array_merge($result['responses'], $sendResult['responses'] ?? []);
    $result['sent'] = !empty($sendResult['sent']);
    $result['mode'] = $result['sent'] ? 'fallback' : 'failed';

    if (!$result['sent']) {
        error_log(
            'Helpdesk WA fallback failed for ' . $entityType . '#' . $entityId
            . ': ' . json_encode($sendResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    return $result;
}

function recepsionis_send_helpdesk_reporter_whatsapp(mysqli $koneksi, string $phone, string $message): bool
{
    $phone = trim($phone);
    if ($phone === '') {
        return false;
    }

    $result = recepsionis_send_whatsapp_messages($koneksi, $message, [$phone]);

    return !empty($result['sent']);
}

function recepsionis_get_helpdesk_entity_row(mysqli $koneksi, string $entityType, int $entityId): ?array
{
    if ($entityId <= 0) {
        return null;
    }

    if ($entityType === 'ticket' && recepsionis_table_exists($koneksi, 'helpdesk_it_tickets')) {
        $stmt = $koneksi->prepare('SELECT * FROM helpdesk_it_tickets WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $entityId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        return $row ?: null;
    }

    if ($entityType === 'call' && recepsionis_table_exists($koneksi, 'staff_calls')) {
        $stmt = $koneksi->prepare('SELECT * FROM staff_calls WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $entityId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        return $row ?: null;
    }

    return null;
}

function recepsionis_helpdesk_entity_is_actionable(array $entity, string $entityType): bool
{
    $status = (string) ($entity['status'] ?? '');
    if ($entityType === 'ticket') {
        return in_array($status, ['pending', 'in_progress'], true);
    }

    return $status === 'pending';
}

function recepsionis_validate_helpdesk_wa_action_token(mysqli $koneksi, string $linkKey): array
{
    $linkKey = trim($linkKey);
    if ($linkKey === '' || !recepsionis_table_exists($koneksi, 'helpdesk_wa_action_tokens')) {
        return ['ok' => false, 'error' => 'Link tidak valid.', 'token' => null, 'entity' => null, 'entity_type' => null];
    }

    $tokenRow = recepsionis_lookup_helpdesk_wa_token_row($koneksi, $linkKey);
    if (!$tokenRow) {
        return ['ok' => false, 'error' => 'Link tidak valid atau sudah kedaluwarsa.', 'token' => null, 'entity' => null, 'entity_type' => null];
    }

    $entityType = (string) ($tokenRow['entity_type'] ?? '');
    $entityId = (int) ($tokenRow['entity_id'] ?? 0);
    $entity = recepsionis_get_helpdesk_entity_row($koneksi, $entityType, $entityId);

    if (!$entity) {
        return ['ok' => false, 'error' => 'Pengaduan tidak ditemukan.', 'token' => $tokenRow, 'entity' => null, 'entity_type' => $entityType];
    }

    if (!empty($tokenRow['used_at']) && !empty($tokenRow['action_taken'])) {
        return [
            'ok' => true,
            'already_used' => true,
            'error' => '',
            'token' => $tokenRow,
            'entity' => $entity,
            'entity_type' => $entityType,
            'action_taken' => (string) $tokenRow['action_taken'],
        ];
    }

    if (strtotime((string) ($tokenRow['expires_at'] ?? '')) < time()) {
        return ['ok' => false, 'error' => 'Link sudah kedaluwarsa.', 'token' => $tokenRow, 'entity' => $entity, 'entity_type' => $entityType];
    }

    if (!recepsionis_helpdesk_entity_is_actionable($entity, $entityType)) {
        return ['ok' => false, 'error' => 'Pengaduan ini sudah tidak dapat diproses.', 'token' => $tokenRow, 'entity' => $entity, 'entity_type' => $entityType];
    }

    return [
        'ok' => true,
        'already_used' => false,
        'error' => '',
        'token' => $tokenRow,
        'entity' => $entity,
        'entity_type' => $entityType,
        'action_taken' => null,
    ];
}

function recepsionis_build_helpdesk_reporter_message(
    string $action,
    string $reporterName,
    string $entityType,
    int $entityId
): string {
    $ref = $entityType === 'ticket'
        ? 'Tiket #' . $entityId
        : 'Panggilan #' . $entityId;
    $greeting = 'Halo ' . $reporterName . ',';

    if ($action === 'confirm') {
        return $greeting . ' pengaduan Helpdesk ' . $ref . ' sedang ditindaklanjuti oleh tim kami. Terima kasih.';
    }

    return $greeting . ' pengaduan Helpdesk ' . $ref . ' sudah kami terima dan masih dalam antrian. Kami akan segera menghubungi Anda.';
}

function recepsionis_apply_helpdesk_wa_action(mysqli $koneksi, array $tokenRow, string $action): array
{
    if (!in_array($action, ['confirm', 'wait'], true)) {
        return ['success' => false, 'message' => 'Aksi tidak valid.'];
    }

    $entityType = (string) ($tokenRow['entity_type'] ?? '');
    $entityId = (int) ($tokenRow['entity_id'] ?? 0);
    $adminUserId = (int) ($tokenRow['admin_user_id'] ?? 0);
    $tokenId = (int) ($tokenRow['id'] ?? 0);

    if ($tokenId <= 0 || $entityId <= 0 || $adminUserId <= 0) {
        return ['success' => false, 'message' => 'Token tidak valid.'];
    }

    if (!empty($tokenRow['used_at']) && !empty($tokenRow['action_taken'])) {
        return [
            'success' => true,
            'message' => 'Aksi ini sudah diproses sebelumnya.',
            'action' => (string) $tokenRow['action_taken'],
            'already_used' => true,
        ];
    }

    $entity = recepsionis_get_helpdesk_entity_row($koneksi, $entityType, $entityId);
    if (!$entity || !recepsionis_helpdesk_entity_is_actionable($entity, $entityType)) {
        return ['success' => false, 'message' => 'Pengaduan sudah tidak dapat diproses.'];
    }

    $reporterName = $entityType === 'ticket'
        ? trim((string) ($entity['nama'] ?? 'Pelapor'))
        : trim((string) ($entity['visitor_name'] ?? 'Pelapor'));
    $reporterPhone = $entityType === 'ticket'
        ? trim((string) ($entity['nomor'] ?? ''))
        : trim((string) ($entity['visitor_phone'] ?? ''));

    if ($entityType === 'ticket') {
        $hasFollowUp = recepsionis_column_exists($koneksi, 'helpdesk_it_tickets', 'follow_up_action');
        $hasAssign = recepsionis_column_exists($koneksi, 'helpdesk_it_tickets', 'assigned_user_id');
        $assignedUserId = isset($entity['assigned_user_id']) ? (int) $entity['assigned_user_id'] : 0;

        if ($action === 'confirm') {
            if ($hasAssign && ($assignedUserId <= 0)) {
                recepsionis_assign_helpdesk_it_ticket($koneksi, $entityId, $adminUserId);
            }
            if ($hasFollowUp) {
                $stmt = $koneksi->prepare(
                    "UPDATE helpdesk_it_tickets
                     SET status = 'in_progress', follow_up_action = 'confirm', follow_up_at = NOW(), follow_up_by = ?
                     WHERE id = ? AND status IN ('pending', 'in_progress')"
                );
                $stmt->bind_param('ii', $adminUserId, $entityId);
            } else {
                $stmt = $koneksi->prepare(
                    "UPDATE helpdesk_it_tickets SET status = 'in_progress' WHERE id = ? AND status IN ('pending', 'in_progress')"
                );
                $stmt->bind_param('i', $entityId);
            }
        } else {
            if ($hasFollowUp) {
                $stmt = $koneksi->prepare(
                    "UPDATE helpdesk_it_tickets
                     SET status = 'pending', follow_up_action = 'wait', follow_up_at = NOW(), follow_up_by = ?
                     WHERE id = ? AND status IN ('pending', 'in_progress')"
                );
                $stmt->bind_param('ii', $adminUserId, $entityId);
            } else {
                $stmt = $koneksi->prepare(
                    "UPDATE helpdesk_it_tickets SET status = 'pending' WHERE id = ? AND status IN ('pending', 'in_progress')"
                );
                $stmt->bind_param('i', $entityId);
            }
        }
        $stmt->execute();
        $updated = $stmt->affected_rows > 0;
        $stmt->close();

        if (!$updated) {
            return ['success' => false, 'message' => 'Gagal memperbarui tiket.'];
        }
    } else {
        $hasFollowUp = recepsionis_column_exists($koneksi, 'staff_calls', 'follow_up_action');
        $categoryId = (int) ($entity['category_id'] ?? 0);
        $assignedUserId = (int) ($entity['assigned_user_id'] ?? 0);

        if ($action === 'confirm' && $assignedUserId <= 0) {
            recepsionis_assign_staff_call(
                $koneksi,
                $entityId,
                $adminUserId,
                $adminUserId,
                $categoryId,
                'PIC ditugaskan via konfirmasi WhatsApp.',
                ['source' => 'helpdesk_wa_action']
            );
        }

        if ($hasFollowUp) {
            $followUp = $action === 'confirm' ? 'confirm' : 'wait';
            $stmt = $koneksi->prepare(
                "UPDATE staff_calls
                 SET follow_up_action = ?, follow_up_at = NOW(), follow_up_by = ?
                 WHERE id = ? AND status = 'pending'"
            );
            $stmt->bind_param('sii', $followUp, $adminUserId, $entityId);
            $stmt->execute();
            $updated = $stmt->affected_rows > 0;
            $stmt->close();
        } else {
            $updated = true;
        }

        if (!$updated) {
            return ['success' => false, 'message' => 'Gagal memperbarui panggilan.'];
        }

        recepsionis_log_staff_call_event(
            $koneksi,
            $entityId,
            $action === 'confirm' ? 'wa_confirmed' : 'wa_queued',
            $adminUserId,
            $adminUserId,
            $categoryId > 0 ? $categoryId : null,
            $action === 'confirm'
                ? 'PIC mengonfirmasi penanganan via link WhatsApp.'
                : 'PIC menandai pengaduan dalam antrian via link WhatsApp.',
            ['source' => 'helpdesk_wa_action', 'action' => $action]
        );
    }

    $stmt = $koneksi->prepare(
        'UPDATE helpdesk_wa_action_tokens SET used_at = NOW(), action_taken = ? WHERE id = ? AND used_at IS NULL'
    );
    $stmt->bind_param('si', $action, $tokenId);
    $stmt->execute();
    $stmt->close();

    $reporterMessage = recepsionis_build_helpdesk_reporter_message($action, $reporterName, $entityType, $entityId);
    $reporterNotified = recepsionis_send_helpdesk_reporter_whatsapp($koneksi, $reporterPhone, $reporterMessage);

    return [
        'success' => true,
        'message' => $reporterNotified
            ? 'Terima kasih. Pelapor telah diberitahu via WhatsApp.'
            : 'Status diperbarui. Notifikasi WhatsApp ke pelapor gagal atau nomor tidak valid.',
        'action' => $action,
        'reporter_notified' => $reporterNotified,
        'already_used' => false,
    ];
}

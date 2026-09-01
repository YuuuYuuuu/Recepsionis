<?php
/**
 * Pastikan skema database selaras dengan fitur terbaru (live chat, kategori, staff_calls).
 * Aman dijalankan berulang: melewati kolom/tabel yang sudah ada.
 *
 * CLI:  php migrations/ensure_latest_schema.php
 * Web:  http://localhost/.../Recepsionis/migrations/ensure_latest_schema.php (localhost saja)
 */
declare(strict_types=1);

$isCli = php_sapi_name() === 'cli';
$GLOBALS['isCli'] = $isCli;
if (!$isCli) {
    $allowed = ['127.0.0.1', '::1'];
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!in_array($remote, $allowed, true)) {
        header('HTTP/1.1 403 Forbidden');
        echo 'Forbidden. Jalankan dari CLI atau localhost saja.';
        exit(1);
    }
    header('Content-Type: text/plain; charset=utf-8');
}

require_once dirname(__DIR__) . '/config.php';

/** @var mysqli $koneksi */

function out(string $msg): void
{
    echo $msg . ($GLOBALS['isCli'] ? PHP_EOL : "<br>\n");
}

function dbName(mysqli $db): string
{
    $r = $db->query('SELECT DATABASE() AS d');
    if (!$r) {
        throw new RuntimeException('Tidak bisa baca nama database.');
    }
    $row = $r->fetch_assoc();
    $name = $row['d'] ?? '';
    if ($name === '') {
        throw new RuntimeException('Pilih database dulu (koneksi.php / USE database).');
    }
    return $name;
}

function tableExists(mysqli $db, string $schema, string $table): bool
{
    $s = $db->real_escape_string($schema);
    $t = $db->real_escape_string($table);
    $r = $db->query("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '{$s}' AND TABLE_NAME = '{$t}' LIMIT 1");
    return $r && $r->num_rows > 0;
}

function columnExists(mysqli $db, string $schema, string $table, string $column): bool
{
    $s = $db->real_escape_string($schema);
    $t = $db->real_escape_string($table);
    $c = $db->real_escape_string($column);
    $r = $db->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = '{$s}' AND TABLE_NAME = '{$t}' AND COLUMN_NAME = '{$c}' LIMIT 1");
    return $r && $r->num_rows > 0;
}

function indexExists(mysqli $db, string $schema, string $table, string $indexName): bool
{
    $s = $db->real_escape_string($schema);
    $t = $db->real_escape_string($table);
    $i = $db->real_escape_string($indexName);
    $r = $db->query("SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = '{$s}' AND TABLE_NAME = '{$t}' AND INDEX_NAME = '{$i}' LIMIT 1");
    return $r && $r->num_rows > 0;
}

function columnType(mysqli $db, string $schema, string $table, string $column): ?string
{
    $s = $db->real_escape_string($schema);
    $t = $db->real_escape_string($table);
    $c = $db->real_escape_string($column);
    $r = $db->query("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = '{$s}' AND TABLE_NAME = '{$t}' AND COLUMN_NAME = '{$c}' LIMIT 1");
    if (!$r || !$r->num_rows) {
        return null;
    }
    $row = $r->fetch_assoc();
    return $row['COLUMN_TYPE'] ?? null;
}

function runAlter(mysqli $db, string $sql, array $ignoreErrnos = [1060, 1061, 1091]): bool
{
    if ($db->query($sql)) {
        return true;
    }
    if (in_array($db->errno, $ignoreErrnos, true)) {
        return false;
    }
    throw new RuntimeException("SQL error ({$db->errno}): {$db->error}\nSQL: {$sql}");
}

try {
    $schema = dbName($koneksi);
    out("Database aktif: {$schema}");
    out('---');

    // --- complaint_categories ---
    $sqlCc = <<<'SQL'
CREATE TABLE IF NOT EXISTS `complaint_categories` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `nama_kategori` VARCHAR(100) NOT NULL,
    `deskripsi` TEXT,
    `icon` VARCHAR(50) DEFAULT 'bi-tag',
    `warna` VARCHAR(20) DEFAULT '#667eea',
    `status_aktif` TINYINT(1) DEFAULT 1,
    `urutan` INT DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
    if (!$koneksi->query($sqlCc)) {
        throw new RuntimeException('complaint_categories: ' . $koneksi->error);
    }
    out('[OK] Tabel complaint_categories (create if not exists)');

    $cnt = $koneksi->query('SELECT COUNT(*) AS c FROM complaint_categories');
    $n = $cnt ? (int) $cnt->fetch_assoc()['c'] : 0;
    if ($n === 0) {
        $koneksi->query("INSERT INTO complaint_categories (nama_kategori, deskripsi, icon, warna, urutan) VALUES
            ('Program', 'Pengaduan terkait program studi, pendaftaran, atau informasi akademik', 'bi-mortarboard', '#667eea', 1),
            ('Help Desk', 'Bantuan teknis, informasi umum, atau pertanyaan lainnya', 'bi-headset', '#10b981', 2),
            ('Lainnya', 'Pengaduan atau pertanyaan lainnya', 'bi-question-circle', '#f59e0b', 3)");
        out('[OK] Seed default complaint_categories (3 baris)');
    } else {
        out("[OK] complaint_categories sudah berisi ({$n} baris), skip seed");
    }

    if (!tableExists($koneksi, $schema, 'staff_calls')) {
        throw new RuntimeException(
            'Tabel staff_calls tidak ada. Impor dulu database dasar (database.sql / recepsionis_full_vps.sql) lalu jalankan skrip ini lagi.'
        );
    }

    // call_type: live_chat membutuhkan VARCHAR (bukan ENUM lama)
    $ct = columnType($koneksi, $schema, 'staff_calls', 'call_type');
    if ($ct !== null && stripos($ct, 'enum') !== false) {
        runAlter($koneksi, 'ALTER TABLE `staff_calls` MODIFY COLUMN `call_type` VARCHAR(50) DEFAULT \'general\'');
        out('[OK] staff_calls.call_type → VARCHAR(50) (supaya nilai live_chat valid)');
    }

    $staffCols = [
        'visitor_id' => 'ADD COLUMN `visitor_id` INT NULL',
        'room_id' => 'ADD COLUMN `room_id` INT NULL',
        'room_name' => 'ADD COLUMN `room_name` VARCHAR(200) NULL',
        'category_id' => 'ADD COLUMN `category_id` INT NULL',
        'assigned_user_id' => 'ADD COLUMN `assigned_user_id` INT NULL',
        'assigned_by' => 'ADD COLUMN `assigned_by` INT NULL',
        'assigned_at' => 'ADD COLUMN `assigned_at` TIMESTAMP NULL DEFAULT NULL',
        'whatsapp_sent' => 'ADD COLUMN `whatsapp_sent` TINYINT(1) DEFAULT 0',
        'wa_http_code' => 'ADD COLUMN `wa_http_code` INT NULL',
        'wa_response' => 'ADD COLUMN `wa_response` MEDIUMTEXT NULL',
        'live_session_id' => 'ADD COLUMN `live_session_id` VARCHAR(64) NULL',
        'live_status' => "ADD COLUMN `live_status` ENUM('waiting','active','ended') NULL DEFAULT NULL",
    ];

    foreach ($staffCols as $col => $fragment) {
        if (!columnExists($koneksi, $schema, 'staff_calls', $col)) {
            runAlter($koneksi, 'ALTER TABLE `staff_calls` ' . $fragment);
            out("[OK] staff_calls.{$col} ditambahkan");
        } else {
            out("[OK] staff_calls.{$col} sudah ada");
        }
    }

    if (!indexExists($koneksi, $schema, 'staff_calls', 'idx_staff_calls_live_session')
        && columnExists($koneksi, $schema, 'staff_calls', 'live_session_id')) {
        runAlter($koneksi, 'ALTER TABLE `staff_calls` ADD INDEX `idx_staff_calls_live_session` (`live_session_id`)');
        out('[OK] Index idx_staff_calls_live_session');
    } else {
        out('[OK] Index live_session (sudah ada atau tidak perlu)');
    }

    if (!indexExists($koneksi, $schema, 'staff_calls', 'idx_staff_calls_assigned_user')
        && columnExists($koneksi, $schema, 'staff_calls', 'assigned_user_id')) {
        runAlter($koneksi, 'ALTER TABLE `staff_calls` ADD INDEX `idx_staff_calls_assigned_user` (`assigned_user_id`)');
        out('[OK] Index idx_staff_calls_assigned_user');
    } else {
        out('[OK] Index assigned_user_id (sudah ada atau tidak perlu)');
    }

    if (!indexExists($koneksi, $schema, 'staff_calls', 'idx_staff_calls_assigned_by')
        && columnExists($koneksi, $schema, 'staff_calls', 'assigned_by')) {
        runAlter($koneksi, 'ALTER TABLE `staff_calls` ADD INDEX `idx_staff_calls_assigned_by` (`assigned_by`)');
        out('[OK] Index idx_staff_calls_assigned_by');
    } else {
        out('[OK] Index assigned_by (sudah ada atau tidak perlu)');
    }

    // --- live_chat_messages ---
    $sqlLcm = <<<'SQL'
CREATE TABLE IF NOT EXISTS `live_chat_messages` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `live_session_id` VARCHAR(64) NOT NULL,
    `staff_call_id` INT NULL,
    `sender` ENUM('guest','admin') NOT NULL,
    `admin_user_id` INT NULL,
    `body` TEXT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_session` (`live_session_id`),
    INDEX `idx_staff_call` (`staff_call_id`),
    CONSTRAINT `fk_lcm_staff_call` FOREIGN KEY (`staff_call_id`) REFERENCES `staff_calls`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_lcm_admin_user` FOREIGN KEY (`admin_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
    if (!$koneksi->query($sqlLcm)) {
        if ($koneksi->errno !== 1005 && $koneksi->errno !== 1824) {
            throw new RuntimeException('live_chat_messages: ' . $koneksi->error);
        }
        out('[OK] live_chat_messages (sudah ada / FK dicek manual jika error)');
    } else {
        out('[OK] Tabel live_chat_messages');
    }

    // --- admin_category_routing ---
    $sqlAcr = <<<'SQL'
CREATE TABLE IF NOT EXISTS `admin_category_routing` (
    `user_id` INT NOT NULL,
    `category_id` INT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`, `category_id`),
    CONSTRAINT `fk_acr_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_acr_cat` FOREIGN KEY (`category_id`) REFERENCES `complaint_categories`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
    if (!$koneksi->query($sqlAcr)) {
        if ($koneksi->errno !== 1005) {
            throw new RuntimeException('admin_category_routing: ' . $koneksi->error);
        }
        out('[OK] admin_category_routing (sudah ada)');
    } else {
        out('[OK] Tabel admin_category_routing');
    }

    // --- admin_notification_preferences ---
    $sqlAnp = <<<'SQL'
CREATE TABLE IF NOT EXISTS `admin_notification_preferences` (
    `user_id` INT NOT NULL,
    `sound_enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`),
    CONSTRAINT `fk_anp_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
    if (!$koneksi->query($sqlAnp)) {
        if ($koneksi->errno !== 1005) {
            throw new RuntimeException('admin_notification_preferences: ' . $koneksi->error);
        }
        out('[OK] admin_notification_preferences (sudah ada)');
    } else {
        out('[OK] Tabel admin_notification_preferences');
    }
    if (!columnExists($koneksi, $schema, 'admin_notification_preferences', 'notifications_enabled')) {
        runAlter($koneksi, 'ALTER TABLE `admin_notification_preferences` ADD COLUMN `notifications_enabled` TINYINT(1) NOT NULL DEFAULT 1 AFTER `user_id`');
        out('[OK] admin_notification_preferences.notifications_enabled ditambahkan');
    } else {
        out('[OK] admin_notification_preferences.notifications_enabled sudah ada');
    }

    // --- staff_call_logs ---
    $sqlScl = <<<'SQL'
CREATE TABLE IF NOT EXISTS `staff_call_logs` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `staff_call_id` INT NOT NULL,
    `event_type` VARCHAR(50) NOT NULL,
    `actor_user_id` INT NULL,
    `target_user_id` INT NULL,
    `category_id` INT NULL,
    `notes` TEXT NULL,
    `metadata_json` LONGTEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_scl_staff_call` (`staff_call_id`),
    INDEX `idx_scl_event_type` (`event_type`),
    INDEX `idx_scl_actor` (`actor_user_id`),
    INDEX `idx_scl_target` (`target_user_id`),
    CONSTRAINT `fk_scl_staff_call` FOREIGN KEY (`staff_call_id`) REFERENCES `staff_calls`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_scl_actor_user` FOREIGN KEY (`actor_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_scl_target_user` FOREIGN KEY (`target_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_scl_category` FOREIGN KEY (`category_id`) REFERENCES `complaint_categories`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
    if (!$koneksi->query($sqlScl)) {
        if ($koneksi->errno !== 1005 && $koneksi->errno !== 1824) {
            throw new RuntimeException('staff_call_logs: ' . $koneksi->error);
        }
        out('[OK] staff_call_logs (sudah ada / FK dicek manual jika error)');
    } else {
        out('[OK] Tabel staff_call_logs');
    }

    // --- live_chat_admin_state (unread + deleted per admin) ---
    $sqlLcas = <<<'SQL'
CREATE TABLE IF NOT EXISTS `live_chat_admin_state` (
    `live_session_id` VARCHAR(64) NOT NULL,
    `admin_user_id` INT NOT NULL,
    `last_read_message_id` INT NOT NULL DEFAULT 0,
    `deleted_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`live_session_id`, `admin_user_id`),
    INDEX `idx_lcas_admin` (`admin_user_id`),
    CONSTRAINT `fk_lcas_admin_user` FOREIGN KEY (`admin_user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
    if (!$koneksi->query($sqlLcas)) {
        if ($koneksi->errno !== 1005) {
            throw new RuntimeException('live_chat_admin_state: ' . $koneksi->error);
        }
        out('[OK] live_chat_admin_state (sudah ada)');
    } else {
        out('[OK] Tabel live_chat_admin_state');
    }

    // --- floor_plans (denah 1:1 dengan ruangan) ---
    $sqlFp = <<<'SQL'
CREATE TABLE IF NOT EXISTS `floor_plans` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `room_id` INT NULL DEFAULT NULL,
    `gedung` VARCHAR(100) NOT NULL,
    `lantai` VARCHAR(50) NOT NULL,
    `gambar` VARCHAR(255) NOT NULL,
    `resepsionis_x` DECIMAL(5,2) NULL DEFAULT NULL,
    `resepsionis_y` DECIMAL(5,2) NULL DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_floor_plans_room_id` (`room_id`),
    INDEX `idx_floor_plans_gedung_lantai` (`gedung`, `lantai`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
    if (!$koneksi->query($sqlFp)) {
        throw new RuntimeException('floor_plans: ' . $koneksi->error);
    }
    out('[OK] Tabel floor_plans (create if not exists)');

    if (tableExists($koneksi, $schema, 'floor_plans')) {
        runAlter($koneksi, 'ALTER TABLE `floor_plans` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        out('[OK] floor_plans collation diselaraskan ke utf8mb4_unicode_ci');

        if (!columnExists($koneksi, $schema, 'floor_plans', 'room_id')) {
            runAlter($koneksi, 'ALTER TABLE `floor_plans` ADD COLUMN `room_id` INT NULL DEFAULT NULL AFTER `id`');
            out('[OK] floor_plans.room_id ditambahkan');
        } else {
            out('[OK] floor_plans.room_id sudah ada');
        }

        // Unique per ruangan (1 denah = 1 ruangan)
        if (!indexExists($koneksi, $schema, 'floor_plans', 'uq_floor_plans_room_id')) {
            runAlter($koneksi, 'ALTER TABLE `floor_plans` ADD UNIQUE KEY `uq_floor_plans_room_id` (`room_id`)');
        }
        // Unique gedung+lantai lama diganti indeks biasa agar banyak denah per lantai boleh
        if (indexExists($koneksi, $schema, 'floor_plans', 'uq_floor_plans_gedung_lantai')) {
            runAlter($koneksi, 'ALTER TABLE `floor_plans` DROP INDEX `uq_floor_plans_gedung_lantai`');
        }
        if (!indexExists($koneksi, $schema, 'floor_plans', 'idx_floor_plans_gedung_lantai')) {
            runAlter($koneksi, 'ALTER TABLE `floor_plans` ADD INDEX `idx_floor_plans_gedung_lantai` (`gedung`, `lantai`)');
        }
        out('[OK] floor_plans indeks room_id / gedung+lantai diselaraskan');
    }

    if (tableExists($koneksi, $schema, 'rooms')) {
        $roomDenahCols = [
            'denah_pin_x' => 'ADD COLUMN `denah_pin_x` DECIMAL(5,2) NULL DEFAULT NULL',
            'denah_pin_y' => 'ADD COLUMN `denah_pin_y` DECIMAL(5,2) NULL DEFAULT NULL',
        ];
        foreach ($roomDenahCols as $col => $fragment) {
            if (!columnExists($koneksi, $schema, 'rooms', $col)) {
                runAlter($koneksi, 'ALTER TABLE `rooms` ' . $fragment);
                out("[OK] rooms.{$col} ditambahkan");
            } else {
                out("[OK] rooms.{$col} sudah ada");
            }
        }

        $roomTvCols = [
            'tv_info_image' => 'ADD COLUMN `tv_info_image` VARCHAR(255) NULL DEFAULT NULL',
            'tv_display_token' => 'ADD COLUMN `tv_display_token` VARCHAR(64) NULL DEFAULT NULL',
            'tv_info_updated_at' => 'ADD COLUMN `tv_info_updated_at` TIMESTAMP NULL DEFAULT NULL',
        ];
        foreach ($roomTvCols as $col => $fragment) {
            if (!columnExists($koneksi, $schema, 'rooms', $col)) {
                runAlter($koneksi, 'ALTER TABLE `rooms` ' . $fragment);
                out("[OK] rooms.{$col} ditambahkan");
            } else {
                out("[OK] rooms.{$col} sudah ada");
            }
        }

        if (columnExists($koneksi, $schema, 'rooms', 'tv_display_token')
            && !indexExists($koneksi, $schema, 'rooms', 'uq_rooms_tv_display_token')) {
            runAlter($koneksi, 'ALTER TABLE `rooms` ADD UNIQUE KEY `uq_rooms_tv_display_token` (`tv_display_token`)');
            $missing = $koneksi->query(
                "SELECT id FROM rooms WHERE tv_display_token IS NULL OR tv_display_token = ''"
            );
            $seeded = 0;
            if ($missing) {
                while ($row = $missing->fetch_assoc()) {
                    $token = bin2hex(random_bytes(16));
                    $id = (int) $row['id'];
                    $stmt = $koneksi->prepare('UPDATE rooms SET tv_display_token = ? WHERE id = ?');
                    if ($stmt) {
                        $stmt->bind_param('si', $token, $id);
                        if ($stmt->execute()) {
                            $seeded++;
                        }
                        $stmt->close();
                    }
                }
            }
            out("[OK] rooms.tv_display_token di-seed untuk {$seeded} ruangan");
        }

        if (columnExists($koneksi, $schema, 'rooms', 'kode_ruangan')) {
            runAlter(
                $koneksi,
                'ALTER TABLE `rooms` MODIFY COLUMN `kode_ruangan` VARCHAR(100) NULL DEFAULT NULL'
            );
            $koneksi->query("UPDATE `rooms` SET `kode_ruangan` = NULL WHERE `kode_ruangan` = ''");
            out('[OK] rooms.kode_ruangan boleh dikosongkan (NULL)');
        }
    } else {
        out('[SKIP] Tabel rooms tidak ada, lewati kolom denah_pin / TV info');
    }

    // --- users.no_wa (WhatsApp per operator) ---
    if (tableExists($koneksi, $schema, 'users')) {
        if (!columnExists($koneksi, $schema, 'users', 'no_wa')) {
            runAlter($koneksi, 'ALTER TABLE `users` ADD COLUMN `no_wa` VARCHAR(20) NULL DEFAULT NULL AFTER `email`');
            out('[OK] users.no_wa ditambahkan');
        } else {
            out('[OK] users.no_wa sudah ada');
        }

        // Role helpdesk_admin untuk pemisahan modul Helpdesk
        runAlter(
            $koneksi,
            "ALTER TABLE `users` MODIFY COLUMN `role` ENUM('admin','helpdesk_admin','operator') NOT NULL DEFAULT 'operator'"
        );
        out('[OK] users.role mendukung helpdesk_admin');

        // Status tugas (on duty) — terima tiket/panggilan tanpa menonaktifkan akun
        if (!columnExists($koneksi, $schema, 'users', 'status_tugas')) {
            runAlter(
                $koneksi,
                'ALTER TABLE `users` ADD COLUMN `status_tugas` TINYINT(1) NOT NULL DEFAULT 1 AFTER `status_aktif`'
            );
            out('[OK] users.status_tugas ditambahkan');
        } else {
            out('[OK] users.status_tugas sudah ada');
        }
    }

    // --- helpdesk_it_tickets ---
    $sqlHit = <<<'SQL'
CREATE TABLE IF NOT EXISTS `helpdesk_it_tickets` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `nama` VARCHAR(150) NOT NULL,
    `nomor` VARCHAR(50) NOT NULL,
    `kelas` VARCHAR(150) NOT NULL,
    `kendala` TEXT NOT NULL,
    `status` ENUM('pending','in_progress','resolved','expired') NOT NULL DEFAULT 'pending',
    `assigned_user_id` INT NULL DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_helpdesk_it_status` (`status`),
    INDEX `idx_helpdesk_it_assigned` (`assigned_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
    if (!$koneksi->query($sqlHit)) {
        throw new RuntimeException('helpdesk_it_tickets: ' . $koneksi->error);
    }
    out('[OK] Tabel helpdesk_it_tickets (create if not exists)');

    if (tableExists($koneksi, $schema, 'helpdesk_it_tickets')) {
        if (!columnExists($koneksi, $schema, 'helpdesk_it_tickets', 'category_id')) {
            runAlter($koneksi, 'ALTER TABLE `helpdesk_it_tickets` ADD COLUMN `category_id` INT NULL DEFAULT NULL AFTER `assigned_user_id`');
            out('[OK] helpdesk_it_tickets.category_id ditambahkan');
        } else {
            out('[OK] helpdesk_it_tickets.category_id sudah ada');
        }

        $ticketFollowUpCols = [
            'follow_up_action' => "ADD COLUMN `follow_up_action` ENUM('none','wait','confirm') NOT NULL DEFAULT 'none' AFTER `status`",
            'follow_up_at' => 'ADD COLUMN `follow_up_at` TIMESTAMP NULL DEFAULT NULL AFTER `follow_up_action`',
            'follow_up_by' => 'ADD COLUMN `follow_up_by` INT NULL DEFAULT NULL AFTER `follow_up_at`',
            'responded_at' => 'ADD COLUMN `responded_at` TIMESTAMP NULL DEFAULT NULL AFTER `follow_up_by`',
        ];
        foreach ($ticketFollowUpCols as $col => $fragment) {
            if (!columnExists($koneksi, $schema, 'helpdesk_it_tickets', $col)) {
                runAlter($koneksi, 'ALTER TABLE `helpdesk_it_tickets` ' . $fragment);
                out("[OK] helpdesk_it_tickets.{$col} ditambahkan");
            } else {
                out("[OK] helpdesk_it_tickets.{$col} sudah ada");
            }
        }

        if (columnExists($koneksi, $schema, 'helpdesk_it_tickets', 'responded_at')) {
            $koneksi->query(
                "UPDATE helpdesk_it_tickets
                 SET responded_at = COALESCE(follow_up_at, updated_at)
                 WHERE responded_at IS NULL
                   AND status IN ('in_progress', 'resolved')"
            );
            out('[OK] helpdesk_it_tickets.responded_at backfill dari data lama');
        }

        $ticketModeCols = [
            'access_type' => "ADD COLUMN `access_type` ENUM('event','room') NOT NULL DEFAULT 'event' AFTER `kendala`",
            'room_id' => 'ADD COLUMN `room_id` INT NULL DEFAULT NULL AFTER `access_type`',
            'issue_category' => "ADD COLUMN `issue_category` ENUM('audio','video','device','other') NOT NULL DEFAULT 'other' AFTER `room_id`",
        ];
        foreach ($ticketModeCols as $col => $fragment) {
            if (!columnExists($koneksi, $schema, 'helpdesk_it_tickets', $col)) {
                runAlter($koneksi, 'ALTER TABLE `helpdesk_it_tickets` ' . $fragment);
                out("[OK] helpdesk_it_tickets.{$col} ditambahkan");
            } else {
                out("[OK] helpdesk_it_tickets.{$col} sudah ada");
            }
        }

        if (columnExists($koneksi, $schema, 'helpdesk_it_tickets', 'access_type')) {
            $koneksi->query("UPDATE helpdesk_it_tickets SET access_type = 'event' WHERE access_type IS NULL OR access_type = ''");
            $koneksi->query("UPDATE helpdesk_it_tickets SET issue_category = 'other' WHERE issue_category IS NULL OR issue_category = ''");
            out('[OK] helpdesk_it_tickets data lama di-backfill');
        }

        // Pastikan ENUM status mencakup expired
        $statusCol = $koneksi->query(
            "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = '" . $koneksi->real_escape_string($schema) . "'
               AND TABLE_NAME = 'helpdesk_it_tickets'
               AND COLUMN_NAME = 'status'
             LIMIT 1"
        );
        if ($statusCol && ($statusRow = $statusCol->fetch_assoc())) {
            $colType = strtolower((string) ($statusRow['COLUMN_TYPE'] ?? ''));
            if (strpos($colType, "'expired'") === false) {
                runAlter(
                    $koneksi,
                    "ALTER TABLE `helpdesk_it_tickets`
                     MODIFY COLUMN `status` ENUM('pending','in_progress','resolved','expired') NOT NULL DEFAULT 'pending'"
                );
                out('[OK] helpdesk_it_tickets.status ENUM +expired');
            } else {
                out('[OK] helpdesk_it_tickets.status sudah termasuk expired');
            }
        }

        // Expire tiket pending > 24 jam yang belum ditanggapi
        if (function_exists('recepsionis_expire_stale_helpdesk_tickets')) {
            // no-op di migrasi CLI tanpa staff_call_routing; lakukan SQL langsung
        }
        $expireSql = "UPDATE helpdesk_it_tickets
                      SET status = 'expired', updated_at = NOW()
                      WHERE status = 'pending'
                        AND created_at < (NOW() - INTERVAL 24 HOUR)";
        if (columnExists($koneksi, $schema, 'helpdesk_it_tickets', 'responded_at')) {
            $expireSql .= ' AND responded_at IS NULL';
        }
        if ($koneksi->query($expireSql)) {
            out('[OK] helpdesk_it_tickets expired stale (>24 jam): ' . (int) $koneksi->affected_rows);
        }
    }

    if (tableExists($koneksi, $schema, 'staff_calls')) {
        $callFollowUpCols = [
            'follow_up_action' => "ADD COLUMN `follow_up_action` ENUM('none','wait','confirm') NOT NULL DEFAULT 'none' AFTER `status`",
            'follow_up_at' => 'ADD COLUMN `follow_up_at` TIMESTAMP NULL DEFAULT NULL AFTER `follow_up_action`',
            'follow_up_by' => 'ADD COLUMN `follow_up_by` INT NULL DEFAULT NULL AFTER `follow_up_at`',
        ];
        foreach ($callFollowUpCols as $col => $fragment) {
            if (!columnExists($koneksi, $schema, 'staff_calls', $col)) {
                runAlter($koneksi, 'ALTER TABLE `staff_calls` ' . $fragment);
                out("[OK] staff_calls.{$col} ditambahkan");
            } else {
                out("[OK] staff_calls.{$col} sudah ada");
            }
        }
    }

    $sqlHwat = <<<'SQL'
CREATE TABLE IF NOT EXISTS `helpdesk_wa_action_tokens` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `token_hash` CHAR(64) NOT NULL,
    `entity_type` ENUM('call','ticket') NOT NULL,
    `entity_id` INT NOT NULL,
    `admin_user_id` INT NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `used_at` DATETIME NULL DEFAULT NULL,
    `action_taken` ENUM('confirm','wait') NULL DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_helpdesk_wa_token_hash` (`token_hash`),
    INDEX `idx_helpdesk_wa_entity` (`entity_type`, `entity_id`),
    INDEX `idx_helpdesk_wa_admin` (`admin_user_id`),
    INDEX `idx_helpdesk_wa_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
    if (!$koneksi->query($sqlHwat)) {
        throw new RuntimeException('helpdesk_wa_action_tokens: ' . $koneksi->error);
    }
    out('[OK] Tabel helpdesk_wa_action_tokens (create if not exists)');

    if (tableExists($koneksi, $schema, 'helpdesk_wa_action_tokens')) {
        if (!columnExists($koneksi, $schema, 'helpdesk_wa_action_tokens', 'short_code')) {
            runAlter($koneksi, 'ALTER TABLE `helpdesk_wa_action_tokens` ADD COLUMN `short_code` VARCHAR(12) NULL DEFAULT NULL AFTER `token_hash`');
            runAlter($koneksi, 'ALTER TABLE `helpdesk_wa_action_tokens` ADD UNIQUE KEY `uq_helpdesk_wa_short_code` (`short_code`)');
            out('[OK] helpdesk_wa_action_tokens.short_code ditambahkan');
        } else {
            out('[OK] helpdesk_wa_action_tokens.short_code sudah ada');
        }
    }

    // --- helpdesk_it_access (barcode global) ---
    $sqlHia = <<<'SQL'
CREATE TABLE IF NOT EXISTS `helpdesk_it_access` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `public_token` VARCHAR(64) NOT NULL,
    `status_aktif` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_helpdesk_it_token` (`public_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
    if (!$koneksi->query($sqlHia)) {
        throw new RuntimeException('helpdesk_it_access: ' . $koneksi->error);
    }
    out('[OK] Tabel helpdesk_it_access (create if not exists)');

    if (tableExists($koneksi, $schema, 'helpdesk_it_access')) {
        $accessCols = [
            'access_type' => "ADD COLUMN `access_type` ENUM('event','room') NOT NULL DEFAULT 'event' AFTER `public_token`",
            'room_id' => 'ADD COLUMN `room_id` INT NULL DEFAULT NULL AFTER `access_type`',
        ];
        foreach ($accessCols as $col => $fragment) {
            if (!columnExists($koneksi, $schema, 'helpdesk_it_access', $col)) {
                runAlter($koneksi, 'ALTER TABLE `helpdesk_it_access` ' . $fragment);
                out("[OK] helpdesk_it_access.{$col} ditambahkan");
            } else {
                out("[OK] helpdesk_it_access.{$col} sudah ada");
            }
        }

        if (columnExists($koneksi, $schema, 'helpdesk_it_access', 'access_type')) {
            $koneksi->query("UPDATE helpdesk_it_access SET access_type = 'event' WHERE access_type IS NULL OR access_type = '' OR (room_id IS NULL AND access_type = 'room')");
            out('[OK] helpdesk_it_access access_type di-backfill');
        }
    }

    $cntAccess = $koneksi->query('SELECT COUNT(*) AS c FROM helpdesk_it_access');
    $accessCount = $cntAccess ? (int) $cntAccess->fetch_assoc()['c'] : 0;
    $hasEventAccess = false;
    if (tableExists($koneksi, $schema, 'helpdesk_it_access') && columnExists($koneksi, $schema, 'helpdesk_it_access', 'access_type')) {
        $eventCheck = $koneksi->query("SELECT id FROM helpdesk_it_access WHERE access_type = 'event' AND status_aktif = 1 LIMIT 1");
        $hasEventAccess = $eventCheck && $eventCheck->num_rows > 0;
    }

    if ($accessCount === 0 || !$hasEventAccess) {
        $token = bin2hex(random_bytes(16));
        if (columnExists($koneksi, $schema, 'helpdesk_it_access', 'access_type')) {
            $koneksi->query("UPDATE helpdesk_it_access SET status_aktif = 0 WHERE access_type = 'event'");
            $koneksi->query("INSERT INTO helpdesk_it_access (public_token, access_type, room_id, status_aktif) VALUES ('" . $koneksi->real_escape_string($token) . "', 'event', NULL, 1)");
        } else {
            $koneksi->query("INSERT INTO helpdesk_it_access (public_token, status_aktif) VALUES ('" . $koneksi->real_escape_string($token) . "', 1)");
        }
        out('[OK] Seed helpdesk_it_access token event');
    } else {
        $emptyTok = $koneksi->query("SELECT id FROM helpdesk_it_access WHERE access_type = 'event' AND status_aktif = 1 AND (public_token IS NULL OR public_token = '') LIMIT 1");
        if ($emptyTok && $emptyTok->num_rows > 0) {
            $token = bin2hex(random_bytes(16));
            $koneksi->query("UPDATE helpdesk_it_access SET public_token = '" . $koneksi->real_escape_string($token) . "', updated_at = CURRENT_TIMESTAMP WHERE access_type = 'event' AND status_aktif = 1 AND (public_token IS NULL OR public_token = '')");
            out('[OK] helpdesk_it_access token event kosong diisi ulang');
        } else {
            out('[OK] helpdesk_it_access token event sudah ada');
        }
    }

    if (tableExists($koneksi, $schema, 'rooms') && tableExists($koneksi, $schema, 'helpdesk_it_access') && columnExists($koneksi, $schema, 'helpdesk_it_access', 'room_id')) {
        $rooms = $koneksi->query('SELECT id FROM rooms WHERE status_aktif = 1');
        $activeRoomIds = [];
        if ($rooms) {
            while ($room = $rooms->fetch_assoc()) {
                $roomId = (int) ($room['id'] ?? 0);
                if ($roomId <= 0) {
                    continue;
                }
                $activeRoomIds[] = $roomId;
                $existing = $koneksi->query('SELECT id FROM helpdesk_it_access WHERE access_type = \'room\' AND room_id = ' . $roomId . ' AND status_aktif = 1 LIMIT 1');
                if ($existing && $existing->num_rows > 0) {
                    continue;
                }
                $koneksi->query('UPDATE helpdesk_it_access SET status_aktif = 0 WHERE access_type = \'room\' AND room_id = ' . $roomId);
                $roomToken = bin2hex(random_bytes(16));
                $koneksi->query("INSERT INTO helpdesk_it_access (public_token, access_type, room_id, status_aktif) VALUES ('" . $koneksi->real_escape_string($roomToken) . "', 'room', " . $roomId . ', 1)');
            }
        }
        if (!empty($activeRoomIds)) {
            $idList = implode(',', array_map('intval', $activeRoomIds));
            $koneksi->query("UPDATE helpdesk_it_access SET status_aktif = 0 WHERE access_type = 'room' AND room_id IS NOT NULL AND room_id NOT IN ({$idList})");
        } else {
            $koneksi->query("UPDATE helpdesk_it_access SET status_aktif = 0 WHERE access_type = 'room'");
        }
        out('[OK] helpdesk_it_access token per ruangan disinkronkan');
    }

    if (tableExists($koneksi, $schema, 'settings')) {
        $hdCat = $koneksi->query("SELECT id FROM complaint_categories WHERE status_aktif = 1 AND (nama_kategori LIKE '%Help%' OR nama_kategori LIKE '%helpdesk%') ORDER BY urutan ASC, id ASC LIMIT 1");
        if ($hdCat && $hdCat->num_rows > 0) {
            $catId = (int) $hdCat->fetch_assoc()['id'];
            $koneksi->query("INSERT INTO settings (setting_key, setting_value) VALUES ('helpdesk_it_category_id', '" . $catId . "') ON DUPLICATE KEY UPDATE setting_value = IF(setting_value = '' OR setting_value IS NULL, VALUES(setting_value), setting_value)");
            out('[OK] settings.helpdesk_it_category_id');
        }

        $waDefaults = [
            'wa_session_id' => '',
        ];
        foreach ($waDefaults as $waKey => $waValue) {
            $koneksi->query("INSERT INTO settings (setting_key, setting_value) VALUES ('" . $koneksi->real_escape_string($waKey) . "', '" . $koneksi->real_escape_string($waValue) . "') ON DUPLICATE KEY UPDATE setting_key = setting_key");
            out('[OK] settings.' . $waKey);
        }

        $brandingDefaults = [
            'visitor_logo' => '',
            'visitor_logo_alt' => '',
            'visitor_welcome_title' => 'Selamat Datang',
            'visitor_service_rooms_title' => 'Daftar Ruangan',
            'visitor_service_rooms_desc' => 'Cari ruangan, gedung, dan lokasi di kampus.',
            'visitor_service_rooms_cta' => 'Lihat ruangan',
            'visitor_service_prodi_title' => 'Program Studi',
            'visitor_service_prodi_desc' => 'Jelajahi program studi yang ada di kampus.',
            'visitor_service_prodi_cta' => 'Lihat prodi',
            'visitor_service_staff_title' => 'Panggil Staff',
            'visitor_service_staff_desc' => 'Hubungi operator, notifikasi langsung ke tim.',
            'visitor_service_staff_cta' => 'Panggil sekarang',
        ];
        foreach ($brandingDefaults as $brandKey => $brandValue) {
            $koneksi->query("INSERT INTO settings (setting_key, setting_value) VALUES ('" . $koneksi->real_escape_string($brandKey) . "', '" . $koneksi->real_escape_string($brandValue) . "') ON DUPLICATE KEY UPDATE setting_key = setting_key");
            out('[OK] settings.' . $brandKey);
        }
    }

    out('---');
    out('Selesai. Skema selaras dengan live chat + kategori + denah + helpdesk IT + WA konfirmasi.');
} catch (Throwable $e) {
    out('GAGAL: ' . $e->getMessage());
    exit(1);
}

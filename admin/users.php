<?php
require_once 'auth.php';
require_once '../staff_call_routing.php';
require_once 'helpdesk_hub.php';

$isHelpdeskUsersMode = defined('HELPDESK_HUB') && HELPDESK_HUB;

if ($isHelpdeskUsersMode) {
    if (!function_exists('currentUserCanManageHelpdesk') || !currentUserCanManageHelpdesk()) {
        helpdeskRedirectToHub('dashboard');
    }
} else {
    checkRole('admin');
}

$currentUserId = (int) ($_SESSION['user_id'] ?? 0);
$hasStatusTugas = recepsionis_users_have_status_tugas($koneksi);
$helpdeskCategoryId = recepsionis_get_helpdesk_category_id($koneksi);
$helpdeskCategoryName = 'Helpdesk';
if ($helpdeskCategoryId > 0) {
    foreach (recepsionis_get_complaint_categories($koneksi, false) as $cat) {
        if ((int) ($cat['id'] ?? 0) === $helpdeskCategoryId) {
            $helpdeskCategoryName = (string) ($cat['nama_kategori'] ?? 'Helpdesk');
            break;
        }
    }
}

function usersRedirect(array $params = []): void
{
    global $isHelpdeskUsersMode;
    if (!empty($isHelpdeskUsersMode) && function_exists('helpdeskUrl')) {
        header('Location: ' . helpdeskUrl('users', $params));
        exit;
    }
    $query = http_build_query($params);
    $target = function_exists('adminUrl') ? adminUrl('users.php') : 'users.php';
    header('Location: ' . $target . ($query !== '' ? '?' . $query : ''));
    exit;
}

function normalizeUserRole(string $role, bool $helpdeskOnly = false): string
{
    if ($helpdeskOnly) {
        return in_array($role, ['helpdesk_admin', 'operator'], true) ? $role : 'operator';
    }
    return in_array($role, ['admin', 'helpdesk_admin', 'operator'], true) ? $role : 'operator';
}

function normalizeUserWhatsApp(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    $norm = recepsionis_normalize_phone_for_provider($raw);
    return $norm === false ? '' : (string) $norm;
}

function selectedCategoryIdsFromRequest(): array
{
    $raw = $_POST['category_ids'] ?? [];
    if (!is_array($raw)) {
        return [];
    }

    return array_values(array_unique(array_filter(array_map('intval', $raw), static function ($id) {
        return $id > 0;
    })));
}

function usernameExists(mysqli $koneksi, string $username, int $excludeId = 0): bool
{
    $sql = 'SELECT id FROM users WHERE username = ?';
    if ($excludeId > 0) {
        $sql .= ' AND id <> ?';
    }
    $sql .= ' LIMIT 1';

    $stmt = $koneksi->prepare($sql);
    if ($excludeId > 0) {
        $stmt->bind_param('si', $username, $excludeId);
    } else {
        $stmt->bind_param('s', $username);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $exists = (bool) ($res && $res->num_rows > 0);
    $stmt->close();

    return $exists;
}

function usersPillLines(string $text): array
{
    $text = trim($text);
    if ($text === '') {
        return ['-', ''];
    }
    if (preg_match('/\s+/u', $text)) {
        $parts = preg_split('/\s+/u', $text, 2);

        return [$parts[0], $parts[1] ?? ''];
    }
    if (preg_match('/^(help)(desk)$/i', $text, $matches)) {
        return [ucfirst(strtolower($matches[1])), ucfirst(strtolower($matches[2]))];
    }

    return [$text, ''];
}

function usersRolePillLines(string $role): array
{
    if ($role === 'helpdesk_admin') {
        return ['Admin', 'Helpdesk'];
    }
    if ($role === 'operator') {
        return ['Operator', 'Helpdesk'];
    }
    if ($role === 'admin') {
        return ['Super', 'Admin'];
    }

    return usersPillLines($role);
}

function usersToggleUrl(int $userId, int $nextStatus, string $kind = 'aktif'): string
{
    global $isHelpdeskUsersMode;
    $params = $kind === 'tugas'
        ? ['toggle_tugas' => $userId, 'status' => $nextStatus]
        : ['toggle' => $userId, 'status' => $nextStatus];
    if (!empty($isHelpdeskUsersMode) && function_exists('helpdeskUrl')) {
        return helpdeskUrl('users', $params);
    }
    $base = function_exists('adminUrl') ? adminUrl('users.php') : 'users.php';
    return $base . '?' . http_build_query($params);
}

if (isset($_POST['tambah_user'])) {
    $username = trim((string) ($_POST['username'] ?? ''));
    $namaLengkap = trim((string) ($_POST['nama_lengkap'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $role = normalizeUserRole((string) ($_POST['role'] ?? 'operator'), $isHelpdeskUsersMode);
    $password = (string) ($_POST['password'] ?? '');
    $statusAktif = isset($_POST['status_aktif']) ? 1 : 0;
    $statusTugas = isset($_POST['status_tugas']) ? 1 : ($hasStatusTugas ? 0 : 1);
    $noWa = normalizeUserWhatsApp((string) ($_POST['no_wa'] ?? ''));
    $categoryIds = selectedCategoryIdsFromRequest();

    if ($isHelpdeskUsersMode) {
        $categoryIds = $helpdeskCategoryId > 0 ? [$helpdeskCategoryId] : [];
    }

    if ($username === '' || $namaLengkap === '' || $password === '') {
        usersRedirect(['error' => 'required']);
    }
    if (strlen($password) < 6) {
        usersRedirect(['error' => 'password_short']);
    }
    if (usernameExists($koneksi, $username)) {
        usersRedirect(['error' => 'duplicate_username']);
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    if ($hasStatusTugas) {
        $stmt = $koneksi->prepare(
            'INSERT INTO users (username, password, nama_lengkap, email, no_wa, role, status_aktif, status_tugas)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('ssssssii', $username, $passwordHash, $namaLengkap, $email, $noWa, $role, $statusAktif, $statusTugas);
    } else {
        $stmt = $koneksi->prepare(
            'INSERT INTO users (username, password, nama_lengkap, email, no_wa, role, status_aktif)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('ssssssi', $username, $passwordHash, $namaLengkap, $email, $noWa, $role, $statusAktif);
    }
    $ok = $stmt->execute();
    $userId = (int) $stmt->insert_id;
    $stmt->close();

    if (!$ok || $userId <= 0) {
        usersRedirect(['error' => 'save_failed']);
    }

    if ($isHelpdeskUsersMode && $helpdeskCategoryId > 0) {
        recepsionis_ensure_user_category($koneksi, $userId, $helpdeskCategoryId);
    } else {
        recepsionis_save_user_category_ids($koneksi, $userId, $categoryIds);
    }
    usersRedirect(['success' => 'added']);
}

if (isset($_POST['edit_user'])) {
    $userId = (int) ($_POST['id'] ?? 0);
    $username = trim((string) ($_POST['username'] ?? ''));
    $namaLengkap = trim((string) ($_POST['nama_lengkap'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $role = normalizeUserRole((string) ($_POST['role'] ?? 'operator'), $isHelpdeskUsersMode);
    $password = (string) ($_POST['password'] ?? '');
    $statusAktif = isset($_POST['status_aktif']) ? 1 : 0;
    $statusTugas = isset($_POST['status_tugas']) ? 1 : 0;
    $noWa = normalizeUserWhatsApp((string) ($_POST['no_wa'] ?? ''));
    $categoryIds = selectedCategoryIdsFromRequest();

    if ($userId <= 0 || $username === '' || $namaLengkap === '') {
        usersRedirect(['error' => 'required']);
    }
    if ($currentUserId === $userId && $statusAktif !== 1) {
        usersRedirect(['error' => 'self_deactivate']);
    }
    if (!$isHelpdeskUsersMode && $currentUserId === $userId && $role !== 'admin') {
        usersRedirect(['error' => 'self_downgrade']);
    }
    if ($isHelpdeskUsersMode) {
        $existingRoleStmt = $koneksi->prepare('SELECT role FROM users WHERE id = ? LIMIT 1');
        $existingRoleStmt->bind_param('i', $userId);
        $existingRoleStmt->execute();
        $existingRole = (string) (($existingRoleStmt->get_result()->fetch_assoc()['role'] ?? ''));
        $existingRoleStmt->close();
        if ($existingRole === 'admin') {
            usersRedirect(['error' => 'helpdesk_cannot_edit_admin']);
        }
    }
    if ($password !== '' && strlen($password) < 6) {
        usersRedirect(['error' => 'password_short']);
    }
    if (usernameExists($koneksi, $username, $userId)) {
        usersRedirect(['error' => 'duplicate_username']);
    }

    if ($hasStatusTugas) {
        if ($password !== '') {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $koneksi->prepare(
                'UPDATE users
                 SET username = ?, password = ?, nama_lengkap = ?, email = ?, no_wa = ?, role = ?, status_aktif = ?, status_tugas = ?
                 WHERE id = ?'
            );
            $stmt->bind_param('ssssssiii', $username, $passwordHash, $namaLengkap, $email, $noWa, $role, $statusAktif, $statusTugas, $userId);
        } else {
            $stmt = $koneksi->prepare(
                'UPDATE users
                 SET username = ?, nama_lengkap = ?, email = ?, no_wa = ?, role = ?, status_aktif = ?, status_tugas = ?
                 WHERE id = ?'
            );
            $stmt->bind_param('sssssiii', $username, $namaLengkap, $email, $noWa, $role, $statusAktif, $statusTugas, $userId);
        }
    } elseif ($password !== '') {
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $koneksi->prepare(
            'UPDATE users
             SET username = ?, password = ?, nama_lengkap = ?, email = ?, no_wa = ?, role = ?, status_aktif = ?
             WHERE id = ?'
        );
        $stmt->bind_param('ssssssii', $username, $passwordHash, $namaLengkap, $email, $noWa, $role, $statusAktif, $userId);
    } else {
        $stmt = $koneksi->prepare(
            'UPDATE users
             SET username = ?, nama_lengkap = ?, email = ?, no_wa = ?, role = ?, status_aktif = ?
             WHERE id = ?'
        );
        $stmt->bind_param('sssssii', $username, $namaLengkap, $email, $noWa, $role, $statusAktif, $userId);
    }

    $ok = $stmt->execute();
    $stmt->close();

    if (!$ok) {
        usersRedirect(['error' => 'save_failed']);
    }

    if ($isHelpdeskUsersMode) {
        if ($helpdeskCategoryId > 0) {
            recepsionis_ensure_user_category($koneksi, $userId, $helpdeskCategoryId);
        }
    } else {
        recepsionis_save_user_category_ids($koneksi, $userId, $categoryIds);
    }
    usersRedirect(['success' => 'updated']);
}

if (isset($_GET['toggle'])) {
    $userId = (int) ($_GET['toggle'] ?? 0);
    $status = (int) ($_GET['status'] ?? 0);

    if ($userId <= 0 || !in_array($status, [0, 1], true)) {
        usersRedirect(['error' => 'invalid']);
    }
    if ($currentUserId === $userId && $status !== 1) {
        usersRedirect(['error' => 'self_deactivate']);
    }
    if ($isHelpdeskUsersMode) {
        $roleCheck = $koneksi->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
        $roleCheck->bind_param('i', $userId);
        $roleCheck->execute();
        $roleVal = (string) (($roleCheck->get_result()->fetch_assoc()['role'] ?? ''));
        $roleCheck->close();
        if ($roleVal === 'admin' || !in_array($roleVal, ['helpdesk_admin', 'operator'], true)) {
            usersRedirect(['error' => 'helpdesk_cannot_edit_admin']);
        }
    }

    $stmt = $koneksi->prepare('UPDATE users SET status_aktif = ? WHERE id = ?');
    $stmt->bind_param('ii', $status, $userId);
    $stmt->execute();
    $stmt->close();

    usersRedirect(['success' => 'status_updated']);
}

if (isset($_GET['toggle_tugas']) && $hasStatusTugas) {
    $userId = (int) ($_GET['toggle_tugas'] ?? 0);
    $status = (int) ($_GET['status'] ?? 0);

    if ($userId <= 0 || !in_array($status, [0, 1], true)) {
        usersRedirect(['error' => 'invalid']);
    }
    if ($isHelpdeskUsersMode) {
        $roleCheck = $koneksi->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
        $roleCheck->bind_param('i', $userId);
        $roleCheck->execute();
        $roleVal = (string) (($roleCheck->get_result()->fetch_assoc()['role'] ?? ''));
        $roleCheck->close();
        if ($roleVal === 'admin' || !in_array($roleVal, ['helpdesk_admin', 'operator'], true)) {
            usersRedirect(['error' => 'helpdesk_cannot_edit_admin']);
        }
    }

    if (!recepsionis_set_user_status_tugas($koneksi, $userId, $status)) {
        usersRedirect(['error' => 'save_failed']);
    }
    usersRedirect(['success' => 'tugas_updated']);
}

$roleFilter = '';
if ($isHelpdeskUsersMode) {
    $roleFilter = isset($_GET['role']) && in_array((string) $_GET['role'], ['helpdesk_admin', 'operator'], true)
        ? (string) $_GET['role']
        : '';
} else {
    $roleFilter = isset($_GET['role']) && in_array((string) $_GET['role'], ['admin', 'helpdesk_admin', 'operator'], true)
        ? (string) $_GET['role']
        : '';
}
$statusFilter = isset($_GET['status']) && in_array((string) $_GET['status'], ['0', '1'], true)
    ? (string) $_GET['status']
    : '';
$tugasFilter = isset($_GET['tugas']) && in_array((string) $_GET['tugas'], ['0', '1'], true)
    ? (string) $_GET['tugas']
    : '';

$usersSql = "SELECT u.*
             FROM users u
             WHERE 1 = 1";
$usersTypes = '';
$usersParams = [];

if ($isHelpdeskUsersMode) {
    $usersSql .= " AND u.role IN ('helpdesk_admin', 'operator')";
}

if ($roleFilter !== '') {
    $usersSql .= ' AND u.role = ?';
    $usersTypes .= 's';
    $usersParams[] = $roleFilter;
}
if ($statusFilter !== '') {
    $usersSql .= ' AND u.status_aktif = ?';
    $usersTypes .= 'i';
    $usersParams[] = (int) $statusFilter;
}
if ($hasStatusTugas && $tugasFilter !== '') {
    $usersSql .= ' AND u.status_tugas = ?';
    $usersTypes .= 'i';
    $usersParams[] = (int) $tugasFilter;
}

$usersSql .= " ORDER BY FIELD(u.role, 'admin', 'helpdesk_admin', 'operator') ASC,
                      COALESCE(NULLIF(u.nama_lengkap, ''), u.username) ASC,
                      u.id ASC";

$stmtUsers = $koneksi->prepare($usersSql);
if ($usersTypes === 's') {
    $stmtUsers->bind_param('s', $usersParams[0]);
} elseif ($usersTypes === 'i') {
    $stmtUsers->bind_param('i', $usersParams[0]);
} elseif ($usersTypes === 'si') {
    $stmtUsers->bind_param('si', $usersParams[0], $usersParams[1]);
} elseif ($usersTypes === 'ii') {
    $stmtUsers->bind_param('ii', $usersParams[0], $usersParams[1]);
} elseif ($usersTypes === 'sii') {
    $stmtUsers->bind_param('sii', $usersParams[0], $usersParams[1], $usersParams[2]);
} elseif ($usersTypes === 'ssi') {
    $stmtUsers->bind_param('ssi', $usersParams[0], $usersParams[1], $usersParams[2]);
}
$stmtUsers->execute();
$usersResult = $stmtUsers->get_result();

$users = [];
$userIds = [];
while ($row = $usersResult->fetch_assoc()) {
    $users[] = $row;
    $userIds[] = (int) $row['id'];
}
$stmtUsers->close();

$categories = recepsionis_get_complaint_categories($koneksi, false);
$userCategoryIndex = recepsionis_get_user_category_index($koneksi, $userIds);
$summaryCounts = [
    'total' => count($users),
    'active' => count(array_filter($users, static function ($user) {
        return (int) ($user['status_aktif'] ?? 0) === 1;
    })),
    'on_duty' => count(array_filter($users, static function ($user) use ($hasStatusTugas) {
        if (!$hasStatusTugas) {
            return (int) ($user['status_aktif'] ?? 0) === 1;
        }
        return (int) ($user['status_aktif'] ?? 0) === 1 && (int) ($user['status_tugas'] ?? 0) === 1;
    })),
    'admin' => count(array_filter($users, static function ($user) {
        return (string) ($user['role'] ?? '') === 'admin';
    })),
];

$pageTitle = $isHelpdeskUsersMode
    ? 'Kelola User Helpdesk — Helpdesk IT'
    : 'Kelola User - E-Recepsionis System';
$resetUrl = $isHelpdeskUsersMode
    ? htmlspecialchars(helpdeskUrl('users'))
    : htmlspecialchars(function_exists('adminUrl') ? adminUrl('users.php') : 'users.php');
$formAction = $isHelpdeskUsersMode
    ? htmlspecialchars(helpdeskUrl('users'))
    : htmlspecialchars(function_exists('adminUrl') ? adminUrl('users.php') : 'users.php');
?>
<?php if ($isHelpdeskUsersMode): ?>
<script>window.originalPageTitle = <?= json_encode($pageTitle, JSON_UNESCAPED_UNICODE) ?>;</script>
<div class="hd-section-users">
                <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
                    <div>
                        <h2 class="mb-1"><i class="bi bi-person-gear"></i> Kelola User Helpdesk</h2>
                        <p class="text-muted small mb-0">
                            Hanya role Helpdesk (Admin Helpdesk / Operator) dengan kategori
                            <strong><?= htmlspecialchars($helpdeskCategoryName) ?></strong>.
                            Perubahan sinkron otomatis ke E-Recepsionis.
                        </p>
                    </div>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
                        <i class="bi bi-person-plus"></i> Tambah User Helpdesk
                    </button>
                </div>
<?php else: ?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <script>
        window.originalPageTitle = <?= json_encode($pageTitle, JSON_UNESCAPED_UNICODE) ?>;
    </script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <?php include 'include_staff_call_head.php'; ?>
    <style>
        .category-pill {
            display: inline-flex;
            align-items: center;
            padding: 4px 10px;
            border-radius: 999px;
            background: #eff6ff;
            color: #1d4ed8;
            font-size: 0.8rem;
            font-weight: 600;
            margin: 0 6px 6px 0;
        }
        .duty-switch .form-check-input {
            width: 2.6em;
            height: 1.35em;
            cursor: pointer;
        }
        .hd-users-actions {
            display: inline-flex;
            flex-direction: row;
            flex-wrap: nowrap;
            align-items: center;
            justify-content: flex-start;
            gap: 0.35rem;
        }
        body:has(.sidebar) .hd-users-actions .btn,
        .hd-users-actions .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 2rem;
            height: 2rem;
            min-width: 2rem;
            min-height: 2rem;
            padding: 0;
            line-height: 1;
            border-radius: 8px;
        }
        .hd-users-actions .btn i {
            font-size: 0.9rem;
            line-height: 1;
        }
    </style>
</head>
<body>
    <?php include 'navbar.php'; ?>

    <div class="container-fluid">
        <div class="row">
            <?php include 'sidebar.php'; ?>

            <div class="col-md-10 content-area">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2 class="mb-0"><i class="bi bi-person-gear"></i> Kelola User</h2>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
                        <i class="bi bi-person-plus"></i> Tambah User
                    </button>
                </div>
<?php endif; ?>

                <?php if (isset($_GET['success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="bi bi-check-circle"></i>
                        <?php
                        if ($_GET['success'] === 'added') echo 'User berhasil ditambahkan.';
                        elseif ($_GET['success'] === 'updated') echo 'User berhasil diperbarui.';
                        elseif ($_GET['success'] === 'status_updated') echo 'Status akun berhasil diperbarui.';
                        elseif ($_GET['success'] === 'tugas_updated') echo 'Status tugas berhasil diperbarui.';
                        ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (isset($_GET['error'])): ?>
                    <div class="alert alert-warning alert-dismissible fade show">
                        <i class="bi bi-exclamation-triangle"></i>
                        <?php
                        if ($_GET['error'] === 'required') echo 'Mohon lengkapi field yang wajib diisi.';
                        elseif ($_GET['error'] === 'duplicate_username') echo 'Username sudah digunakan user lain.';
                        elseif ($_GET['error'] === 'password_short') echo 'Password minimal 6 karakter.';
                        elseif ($_GET['error'] === 'self_deactivate') echo 'Akun Anda sendiri tidak boleh dinonaktifkan.';
                        elseif ($_GET['error'] === 'self_downgrade') echo 'Akun Anda sendiri harus tetap ber-role admin.';
                        elseif ($_GET['error'] === 'helpdesk_cannot_edit_admin') echo 'User Super Admin hanya bisa dikelola dari E-Recepsionis.';
                        elseif ($_GET['error'] === 'save_failed') echo 'Data user gagal disimpan.';
                        else echo 'Permintaan tidak dapat diproses.';
                        ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <div class="card shadow-sm h-100">
                            <div class="card-body">
                                <div class="text-muted small">Total user</div>
                                <div class="display-6 fw-bold"><?= $summaryCounts['total'] ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card shadow-sm h-100">
                            <div class="card-body">
                                <div class="text-muted small">Akun aktif</div>
                                <div class="display-6 fw-bold text-success"><?= $summaryCounts['active'] ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card shadow-sm h-100">
                            <div class="card-body">
                                <div class="text-muted small"><?= $hasStatusTugas ? 'Status tugas aktif' : ($isHelpdeskUsersMode ? 'Admin Helpdesk' : 'Super Admin') ?></div>
                                <div class="display-6 fw-bold text-primary"><?= $hasStatusTugas ? $summaryCounts['on_duty'] : $summaryCounts['admin'] ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($isHelpdeskUsersMode): ?>
                <section class="hd-panel hd-users-panel">
                    <div class="hd-panel-head">
                        <div>
                            <h2><i class="bi bi-people"></i> Daftar User Helpdesk</h2>
                            <p>Total <?= count($users) ?> user</p>
                        </div>
                    </div>
                    <div class="hd-users-filter">
                        <form class="hd-users-filter-form" method="GET">
                            <input type="hidden" name="section" value="users">
                            <div class="hd-users-filter-grid">
                                <div>
                                    <label class="adm-filter-label">Filter role</label>
                                    <select name="role" class="form-select form-select-sm">
                                        <option value="">Semua role</option>
                                        <option value="helpdesk_admin" <?= $roleFilter === 'helpdesk_admin' ? 'selected' : '' ?>>Admin Helpdesk</option>
                                        <option value="operator" <?= $roleFilter === 'operator' ? 'selected' : '' ?>>Operator Helpdesk</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="adm-filter-label">Filter akun</label>
                                    <select name="status" class="form-select form-select-sm">
                                        <option value="">Semua status</option>
                                        <option value="1" <?= $statusFilter === '1' ? 'selected' : '' ?>>Aktif</option>
                                        <option value="0" <?= $statusFilter === '0' ? 'selected' : '' ?>>Nonaktif</option>
                                    </select>
                                </div>
                                <?php if ($hasStatusTugas): ?>
                                <div>
                                    <label class="adm-filter-label">Filter status tugas</label>
                                    <select name="tugas" class="form-select form-select-sm">
                                        <option value="">Semua</option>
                                        <option value="1" <?= $tugasFilter === '1' ? 'selected' : '' ?>>Aktif</option>
                                        <option value="0" <?= $tugasFilter === '0' ? 'selected' : '' ?>>Nonaktif</option>
                                    </select>
                                </div>
                                <?php endif; ?>
                                <div class="hd-users-filter-actions">
                                    <button type="submit" class="btn btn-primary btn-sm">
                                        <i class="bi bi-funnel"></i> Terapkan
                                    </button>
                                    <a href="<?= $resetUrl ?>" class="btn btn-outline-secondary btn-sm">Reset</a>
                                </div>
                            </div>
                        </form>
                    </div>
                    <div class="hd-table-wrap">
                        <table class="hd-table hd-users-table">
                <?php else: ?>
                <div class="card mb-3">
                    <div class="card-body">
                        <form class="row g-3 align-items-end" method="GET">
                            <div class="col-md-3">
                                <label class="form-label">Filter role</label>
                                <select name="role" class="form-select">
                                    <option value="">Semua role</option>
                                    <option value="admin" <?= $roleFilter === 'admin' ? 'selected' : '' ?>>Super Admin</option>
                                    <option value="helpdesk_admin" <?= $roleFilter === 'helpdesk_admin' ? 'selected' : '' ?>>Admin Helpdesk</option>
                                    <option value="operator" <?= $roleFilter === 'operator' ? 'selected' : '' ?>>Operator Helpdesk</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Filter akun</label>
                                <select name="status" class="form-select">
                                    <option value="">Semua status</option>
                                    <option value="1" <?= $statusFilter === '1' ? 'selected' : '' ?>>Aktif</option>
                                    <option value="0" <?= $statusFilter === '0' ? 'selected' : '' ?>>Nonaktif</option>
                                </select>
                            </div>
                            <?php if ($hasStatusTugas): ?>
                            <div class="col-md-3">
                                <label class="form-label">Filter status tugas</label>
                                <select name="tugas" class="form-select">
                                    <option value="">Semua</option>
                                    <option value="1" <?= $tugasFilter === '1' ? 'selected' : '' ?>>Aktif</option>
                                    <option value="0" <?= $tugasFilter === '0' ? 'selected' : '' ?>>Nonaktif</option>
                                </select>
                            </div>
                            <?php endif; ?>
                            <div class="col-md-3 d-flex gap-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-funnel"></i> Terapkan
                                </button>
                                <a href="<?= $resetUrl ?>" class="btn btn-outline-secondary">Reset</a>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <i class="bi bi-people"></i> Daftar User
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                <?php endif; ?>
                                <thead>
                                    <tr>
                                        <th>User</th>
                                        <th>Kontak</th>
                                        <th>Role</th>
                                        <th>Kategori</th>
                                        <th>Akun</th>
                                        <?php if ($hasStatusTugas): ?>
                                            <th>Status Tugas</th>
                                        <?php endif; ?>
                                        <th>Login Terakhir</th>
                                        <th>Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($users)): ?>
                                        <?php foreach ($users as $user): ?>
                                            <?php
                                            $userId = (int) $user['id'];
                                            $selectedCategories = $userCategoryIndex[$userId]['ids'] ?? [];
                                            $categoryNames = $userCategoryIndex[$userId]['names'] ?? [];
                                            $displayName = trim((string) ($user['nama_lengkap'] ?? '')) !== ''
                                                ? (string) $user['nama_lengkap']
                                                : (string) $user['username'];
                                            $isOnDuty = $hasStatusTugas ? (int) ($user['status_tugas'] ?? 1) === 1 : true;
                                            $roleLabel = function_exists('currentUserRoleLabel')
                                                ? currentUserRoleLabel((string) $user['role'])
                                                : ucfirst((string) $user['role']);
                                            $roleClass = $user['role'] === 'admin'
                                                ? 'is-new'
                                                : ($user['role'] === 'helpdesk_admin' ? 'is-progress' : 'is-resolved');
                                            [$rolePillLine1, $rolePillLine2] = usersRolePillLines((string) $user['role']);
                                            [$categoryPillLine1, $categoryPillLine2] = usersPillLines($helpdeskCategoryName);
                                            ?>
                                            <tr>
                                                <td data-label="User">
                                                    <div class="hd-user-cell">
                                                        <strong><?= htmlspecialchars($displayName) ?></strong>
                                                        <?php if ($currentUserId === $userId): ?>
                                                            <span class="hd-chip hd-chip-you">Anda</span>
                                                        <?php endif; ?>
                                                        <span>@<?= htmlspecialchars($user['username']) ?></span>
                                                    </div>
                                                </td>
                                                <td data-label="Kontak">
                                                    <div class="hd-user-cell">
                                                        <strong><?= htmlspecialchars($user['email'] ?: '-') ?></strong>
                                                        <?php if (!empty($user['no_wa'])): ?>
                                                            <span><i class="bi bi-whatsapp"></i> <?= htmlspecialchars($user['no_wa']) ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td data-label="Role">
                                                    <?php if ($isHelpdeskUsersMode): ?>
                                                        <span class="hd-table-pill hd-table-pill--role <?= $roleClass ?>">
                                                            <span><?= htmlspecialchars($rolePillLine1) ?></span>
                                                            <?php if ($rolePillLine2 !== ''): ?>
                                                                <span><?= htmlspecialchars($rolePillLine2) ?></span>
                                                            <?php endif; ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="badge <?= $user['role'] === 'admin' ? 'bg-primary' : ($user['role'] === 'helpdesk_admin' ? 'bg-info text-dark' : 'bg-secondary') ?>">
                                                            <?= htmlspecialchars($roleLabel) ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td data-label="Kategori">
                                                    <?php if ($isHelpdeskUsersMode): ?>
                                                        <span class="hd-table-pill hd-table-pill--category">
                                                            <span><?= htmlspecialchars($categoryPillLine1) ?></span>
                                                            <?php if ($categoryPillLine2 !== ''): ?>
                                                                <span><?= htmlspecialchars($categoryPillLine2) ?></span>
                                                            <?php endif; ?>
                                                        </span>
                                                    <?php elseif (!empty($categoryNames)): ?>
                                                        <?php foreach ($categoryNames as $categoryName): ?>
                                                            <span class="badge rounded-pill text-bg-primary-subtle text-primary border mb-1"><?= htmlspecialchars($categoryName) ?></span>
                                                        <?php endforeach; ?>
                                                    <?php else: ?>
                                                        <span class="text-muted small">Belum ada kategori</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td data-label="Akun">
                                                    <?php if ((int) $user['status_aktif'] === 1): ?>
                                                        <?php if ($isHelpdeskUsersMode): ?>
                                                            <span class="hd-status is-resolved">Aktif</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-success">Aktif</span>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <?php if ($isHelpdeskUsersMode): ?>
                                                            <span class="hd-status is-breach">Nonaktif</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-secondary">Nonaktif</span>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                </td>
                                                <?php if ($hasStatusTugas): ?>
                                                <td data-label="Status Tugas">
                                                    <div class="form-check form-switch duty-switch mb-0">
                                                        <input class="form-check-input"
                                                               type="checkbox"
                                                               role="switch"
                                                               id="duty_<?= $userId ?>"
                                                               aria-label="Status tugas user <?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?>"
                                                               <?= $isOnDuty ? 'checked' : '' ?>
                                                               <?= (int) $user['status_aktif'] !== 1 ? 'disabled' : '' ?>
                                                               onchange="window.location.href=this.checked ? <?= json_encode(usersToggleUrl($userId, 1, 'tugas')) ?> : <?= json_encode(usersToggleUrl($userId, 0, 'tugas')) ?>">
                                                    </div>
                                                </td>
                                                <?php endif; ?>
                                                <td data-label="Login Terakhir">
                                                    <?php if (!empty($user['last_login'])): ?>
                                                        <?= date('d/m/Y H:i', strtotime((string) $user['last_login'])) ?>
                                                    <?php else: ?>
                                                        <span class="text-muted small">Belum pernah login</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td data-label="Aksi">
                                                    <div class="hd-users-actions">
                                                        <a href="<?= htmlspecialchars(usersToggleUrl($userId, (int) $user['status_aktif'] === 1 ? 0 : 1, 'aktif')) ?>"
                                                           class="btn btn-sm btn-<?= (int) $user['status_aktif'] === 1 ? 'warning' : 'success' ?>"
                                                           title="<?= (int) $user['status_aktif'] === 1 ? 'Nonaktifkan akun' : 'Aktifkan akun' ?>"
                                                           onclick="return confirm('<?= (int) $user['status_aktif'] === 1 ? 'Nonaktifkan' : 'Aktifkan' ?> user ini?')">
                                                            <i class="bi bi-<?= (int) $user['status_aktif'] === 1 ? 'pause-circle' : 'play-circle' ?>"></i>
                                                        </a>
                                                        <button class="btn btn-sm btn-primary"
                                                                data-bs-toggle="modal"
                                                                data-bs-target="#editModal<?= $userId ?>">
                                                            <i class="bi bi-pencil"></i>
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="<?= $hasStatusTugas ? 8 : 7 ?>" class="text-center text-muted py-4">
                                                <i class="bi bi-inbox" style="font-size: 3rem;"></i><br>
                                                Belum ada user yang sesuai dengan filter.
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                    <?php if ($isHelpdeskUsersMode): ?>
                    </div>
                </section>
                    <?php else: ?>
                        </div>
                    </div>
                </div>
                    <?php endif; ?>
<?php if (!$isHelpdeskUsersMode): ?>
            </div>
        </div>
    </div>
<?php endif; ?>

    <?php foreach ($users as $user): ?>
        <?php
        $userId = (int) $user['id'];
        $selectedCategories = $userCategoryIndex[$userId]['ids'] ?? [];
        $isOnDuty = $hasStatusTugas ? (int) ($user['status_tugas'] ?? 1) === 1 : true;
        ?>
        <div class="modal fade" id="editModal<?= $userId ?>" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-person-lines-fill"></i> Edit User<?= $isHelpdeskUsersMode ? ' Helpdesk' : '' ?></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form method="POST" action="<?= $formAction ?>">
                        <input type="hidden" name="id" value="<?= $userId ?>">
                        <?php if ($isHelpdeskUsersMode): ?>
                            <input type="hidden" name="section" value="users">
                        <?php endif; ?>
                        <div class="modal-body">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Username *</label>
                                    <input type="text" name="username" class="form-control" value="<?= htmlspecialchars($user['username']) ?>" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Nama Lengkap *</label>
                                    <input type="text" name="nama_lengkap" class="form-control" value="<?= htmlspecialchars($user['nama_lengkap']) ?>" required>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Email</label>
                                    <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($user['email'] ?? '') ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Nomor WhatsApp</label>
                                    <input type="tel" name="no_wa" class="form-control" value="<?= htmlspecialchars($user['no_wa'] ?? '') ?>" placeholder="08xxxxxxxxxx atau 628xxxxxxxxxx">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Role</label>
                                    <select name="role" class="form-select">
                                        <?php if (!$isHelpdeskUsersMode): ?>
                                            <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>Super Admin (E-Recepsionis + Helpdesk)</option>
                                        <?php endif; ?>
                                        <option value="helpdesk_admin" <?= $user['role'] === 'helpdesk_admin' ? 'selected' : '' ?>>Admin Helpdesk (Helpdesk saja)</option>
                                        <option value="operator" <?= $user['role'] === 'operator' ? 'selected' : '' ?>>Operator Helpdesk</option>
                                    </select>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Password Baru</label>
                                <input type="password" name="password" class="form-control" minlength="6" placeholder="Kosongkan jika tidak ingin mengubah password">
                            </div>
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" name="status_aktif" id="status_aktif_<?= $userId ?>" <?= (int) $user['status_aktif'] === 1 ? 'checked' : '' ?>>
                                <label class="form-check-label" for="status_aktif_<?= $userId ?>">Akun aktif (bisa login)</label>
                            </div>
                            <?php if ($hasStatusTugas): ?>
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" name="status_tugas" id="status_tugas_<?= $userId ?>" <?= $isOnDuty ? 'checked' : '' ?>>
                                <label class="form-check-label" for="status_tugas_<?= $userId ?>">Status tugas (terima tiket / panggilan)</label>
                            </div>
                            <?php endif; ?>
                            <?php if ($isHelpdeskUsersMode): ?>
                                <div class="alert alert-light border mb-0">
                                    <strong>Kategori:</strong> <?= htmlspecialchars($helpdeskCategoryName) ?>
                                    <div class="small text-muted">Ditetapkan otomatis untuk user Helpdesk. Sinkron dengan E-Recepsionis.</div>
                                </div>
                            <?php else: ?>
                            <div class="mb-3">
                                <label class="form-label">Kategori yang Ditangani</label>
                                <div class="border rounded p-3 bg-light" style="max-height: 260px; overflow-y: auto;">
                                    <?php if (!empty($categories)): ?>
                                        <?php foreach ($categories as $category): ?>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="category_ids[]" value="<?= (int) $category['id'] ?>" id="user_<?= $userId ?>_cat_<?= (int) $category['id'] ?>" <?= in_array((int) $category['id'], $selectedCategories, true) ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="user_<?= $userId ?>_cat_<?= (int) $category['id'] ?>">
                                                    <?= htmlspecialchars($category['nama_kategori']) ?>
                                                    <?php if ((int) ($category['status_aktif'] ?? 0) !== 1): ?>
                                                        <small class="text-muted">(nonaktif)</small>
                                                    <?php endif; ?>
                                                </label>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <span class="text-muted small">Belum ada kategori. Tambahkan kategori dulu.</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                            <button type="submit" name="edit_user" class="btn btn-primary">Simpan Perubahan</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <div class="modal fade" id="addModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-person-plus"></i> <?= $isHelpdeskUsersMode ? 'Tambah User Helpdesk' : 'Tambah User' ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="<?= $formAction ?>">
                    <?php if ($isHelpdeskUsersMode): ?>
                        <input type="hidden" name="section" value="users">
                    <?php endif; ?>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Username *</label>
                                <input type="text" name="username" class="form-control" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Nama Lengkap *</label>
                                <input type="text" name="nama_lengkap" class="form-control" required>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" name="email" class="form-control">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Nomor WhatsApp</label>
                                <input type="tel" name="no_wa" class="form-control" placeholder="08xxxxxxxxxx atau 628xxxxxxxxxx">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Role</label>
                                <select name="role" class="form-select">
                                    <?php if (!$isHelpdeskUsersMode): ?>
                                        <option value="admin">Super Admin (E-Recepsionis + Helpdesk)</option>
                                    <?php endif; ?>
                                    <option value="helpdesk_admin">Admin Helpdesk (Helpdesk saja)</option>
                                    <option value="operator" selected>Operator Helpdesk</option>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Password *</label>
                            <input type="password" name="password" class="form-control" minlength="6" required>
                        </div>
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" name="status_aktif" id="status_aktif_new" checked>
                            <label class="form-check-label" for="status_aktif_new">Akun aktif (bisa login)</label>
                        </div>
                        <?php if ($hasStatusTugas): ?>
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" name="status_tugas" id="status_tugas_new" checked>
                            <label class="form-check-label" for="status_tugas_new">Status tugas aktif</label>
                        </div>
                        <?php endif; ?>
                        <?php if ($isHelpdeskUsersMode): ?>
                            <div class="alert alert-info mb-0">
                                Kategori <strong><?= htmlspecialchars($helpdeskCategoryName) ?></strong> akan dipasang otomatis.
                                User ini juga muncul di Kelola User E-Recepsionis.
                            </div>
                        <?php else: ?>
                        <div class="mb-0">
                            <label class="form-label">Kategori yang Ditangani</label>
                            <div class="border rounded p-3 bg-light" style="max-height: 260px; overflow-y: auto;">
                                <?php if (!empty($categories)): ?>
                                    <?php foreach ($categories as $category): ?>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="category_ids[]" value="<?= (int) $category['id'] ?>" id="new_cat_<?= (int) $category['id'] ?>" <?= $helpdeskCategoryId > 0 && (int) $category['id'] === $helpdeskCategoryId ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="new_cat_<?= (int) $category['id'] ?>">
                                                <?= htmlspecialchars($category['nama_kategori']) ?>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <span class="text-muted small">Belum ada kategori.</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" name="tambah_user" class="btn btn-primary">Simpan User</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

<?php if ($isHelpdeskUsersMode): ?>
</div>
<style>
.duty-switch .form-check-input { width: 2.6em; height: 1.35em; cursor: pointer; }
.hd-section-users .badge.rounded-pill { margin: 0 4px 4px 0; }
</style>
<?php else: ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/notification-badge.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('.modal-backdrop').forEach(function (backdrop) {
                backdrop.remove();
            });
            document.body.classList.remove('modal-open');
            document.body.style.removeProperty('padding-right');
            document.body.style.removeProperty('overflow');
        });
    </script>
    <?php include 'include_staff_call_footer.php'; ?>
</body>
</html>
<?php endif; ?>

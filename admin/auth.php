<?php
// Authentication middleware
// Include file ini di setiap halaman admin yang perlu proteksi

require_once '../config.php';

if (!function_exists('recepsionis_sync_staff_calls_to_visitors')) {
    require_once dirname(__DIR__) . '/lib/visitor_sync.php';
}

// Cek apakah user sudah login
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . rtrim(BASE_URL, '/') . '/admin/login.php');
    exit;
}

function currentUserRole(): string
{
    return isset($_SESSION['role']) ? (string) $_SESSION['role'] : '';
}

/** Super Admin — akses penuh E-Recepsionis + Helpdesk */
function currentUserIsAdmin(): bool
{
    return currentUserRole() === 'admin';
}

/** Alias eksplisit Super Admin */
function currentUserIsSuperAdmin(): bool
{
    return currentUserIsAdmin();
}

/** Admin Helpdesk — hanya modul Helpdesk */
function currentUserIsHelpdeskAdmin(): bool
{
    return currentUserRole() === 'helpdesk_admin';
}

/** Operator kategori / PIC (modul Helpdesk) */
function currentUserIsOperator(): bool
{
    return currentUserRole() === 'operator';
}

/** Boleh masuk area Helpdesk */
function currentUserCanAccessHelpdesk(): bool
{
    return currentUserIsAdmin() || currentUserIsHelpdeskAdmin() || currentUserIsOperator();
}

/** Kelola QR tiket kelas & pengaturan helpdesk penuh */
function currentUserCanManageHelpdesk(): bool
{
    return currentUserIsAdmin() || currentUserIsHelpdeskAdmin();
}

function currentUserCanHandleComplaints(): bool
{
    return currentUserCanAccessHelpdesk();
}

/** User khusus E-Recepsionis (bukan helpdesk-only) */
function currentUserCanAccessRecepsionis(): bool
{
    return currentUserIsAdmin();
}

function currentUserRoleLabel(?string $role = null): string
{
    $role = $role ?? currentUserRole();
    if ($role === 'admin') {
        return 'Super Admin';
    }
    if ($role === 'helpdesk_admin') {
        return 'Admin Helpdesk';
    }
    if ($role === 'operator') {
        return 'Operator Helpdesk';
    }
    return 'User';
}

function adminUrl(string $path = ''): string
{
    return rtrim(BASE_URL, '/') . '/admin/' . ltrim($path, '/');
}

function apiUrl(string $path = ''): string
{
    return rtrim(BASE_URL, '/') . '/api/' . ltrim($path, '/');
}

function visitorUrl(string $path = ''): string
{
    return rtrim(BASE_URL, '/') . '/visitor/' . ltrim($path, '/');
}

function currentUserHomePath(): string
{
    if (currentUserIsAdmin()) {
        return 'index.php';
    }
    if (currentUserIsHelpdeskAdmin() || currentUserIsOperator()) {
        return 'helpdesk_dashboard.php';
    }
    return 'login.php';
}

function currentUserHomeUrl(): string
{
    return adminUrl(currentUserHomePath());
}

function redirectToCurrentUserHome(string $error = ''): void
{
    $glue = strpos(currentUserHomeUrl(), '?') === false ? '?' : '&';
    header('Location: ' . currentUserHomeUrl() . ($error !== '' ? $glue . 'error=' . urlencode($error) : ''));
    exit;
}

function checkRole($required_role): void
{
    if (currentUserRole() !== $required_role) {
        redirectToCurrentUserHome();
    }
}

function requireSuperAdminPage(): void
{
    if (!currentUserIsAdmin()) {
        redirectToCurrentUserHome();
    }
}

function requireRecepsionisPage(): void
{
    requireSuperAdminPage();
}

function requireComplaintOperatorPage(): void
{
    if (!currentUserCanHandleComplaints()) {
        redirectToCurrentUserHome();
    }
}

function requireHelpdeskAccess(): void
{
    if (!currentUserCanAccessHelpdesk()) {
        redirectToCurrentUserHome();
    }
}

function requireHelpdeskManagerPage(): void
{
    if (!currentUserCanManageHelpdesk()) {
        redirectToCurrentUserHome();
    }
}

/** Halaman yang memakai shell Helpdesk (bukan sidebar E-Recepsionis) */
function currentPageIsHelpdeskShell(): bool
{
    $page = basename((string) ($_SERVER['PHP_SELF'] ?? ''));
    return in_array($page, [
        'helpdesk_dashboard.php',
        'helpdesk_it.php',
        'helpdesk_it_print.php',
        'operator_dashboard.php',
        'live_chat.php',
    ], true);
}

// Auto logout setelah 2 jam tidak aktif
$timeout_duration = SESSION_TIMEOUT;

if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout_duration) {
    session_unset();
    session_destroy();
    header('Location: ' . rtrim(BASE_URL, '/') . '/admin/login.php?timeout=1');
    exit;
}

$_SESSION['last_activity'] = time();
?>

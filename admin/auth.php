<?php
// Authentication middleware
// Include file ini di setiap halaman admin yang perlu proteksi

require_once '../config.php';

if (!function_exists('recepsionis_sync_staff_calls_to_visitors')) {
    require_once dirname(__DIR__) . '/lib/visitor_sync.php';
}

// Cek apakah user sudah login
if (!isset($_SESSION['user_id'])) {
    // Jika belum login, redirect ke halaman login
    header("Location: " . rtrim(BASE_URL, '/') . '/admin/login.php');
    exit;
}

function currentUserRole(): string
{
    return isset($_SESSION['role']) ? (string) $_SESSION['role'] : '';
}

function currentUserIsAdmin(): bool
{
    return currentUserRole() === 'admin';
}

function currentUserIsOperator(): bool
{
    return currentUserRole() === 'operator';
}

function currentUserCanHandleComplaints(): bool
{
    return currentUserIsAdmin() || currentUserIsOperator();
}

function currentUserRoleLabel(?string $role = null): string
{
    $role = $role ?? currentUserRole();
    if ($role === 'admin') {
        return 'Super Admin';
    }
    if ($role === 'operator') {
        return 'Operator';
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
    return currentUserIsAdmin() ? 'index.php' : 'operator_dashboard.php';
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

// Fungsi untuk cek role
function checkRole($required_role) {
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

function requireComplaintOperatorPage(): void
{
    if (!currentUserCanHandleComplaints()) {
        redirectToCurrentUserHome();
    }
}

// Auto logout setelah 2 jam tidak aktif
$timeout_duration = SESSION_TIMEOUT;

if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout_duration) {
    session_unset();
    session_destroy();
    header("Location: " . rtrim(BASE_URL, '/') . '/admin/login.php?timeout=1');
    exit;
}

$_SESSION['last_activity'] = time();
?>

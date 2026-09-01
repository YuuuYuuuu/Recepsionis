<?php
/**
 * Helper URL & section untuk hub Helpdesk (helpdesk_dashboard.php).
 * E-Recepsionis tetap di /admin/*; Helpdesk hanya lewat dashboard ini.
 */

if (!function_exists('helpdeskSection')) {
    function helpdeskAllowedSections(): array
    {
        return ['dashboard', 'tickets', 'qr', 'prefs', 'tasks', 'users'];
    }

    function helpdeskSection(?string $section = null): string
    {
        $explicit = $section !== null ? $section : (string) ($_GET['section'] ?? '');
        $explicit = strtolower(trim($explicit));

        if ($explicit === '' || $explicit === 'overview' || $explicit === 'home') {
            // Infer dari query lama agar link relatif (?status=…) tetap di modul tickets
            $success = (string) ($_GET['success'] ?? '');
            $error = (string) ($_GET['error'] ?? '');
            if (
                isset($_GET['status'])
                || isset($_GET['answer'])
                || isset($_GET['cancel'])
                || isset($_GET['channel'])
                || isset($_GET['view'])
                || isset($_GET['per_page'])
                || in_array($success, ['answered', 'cancelled', 'assigned', 'reassigned'], true)
                || in_array($error, ['unauthorized', 'assignment_invalid'], true)
            ) {
                $explicit = 'tickets';
            } elseif (in_array($success, ['event', 'sync'], true)) {
                $explicit = 'qr';
            } elseif (in_array($success, ['added', 'updated', 'status_updated', 'tugas_updated'], true)
                || in_array($error, ['required', 'duplicate_username', 'password_short', 'self_deactivate', 'self_downgrade', 'helpdesk_cannot_edit_admin', 'save_failed', 'invalid'], true)
                || isset($_GET['toggle'])
                || isset($_GET['toggle_tugas'])
            ) {
                $explicit = 'users';
            } else {
                $explicit = 'dashboard';
            }
        }

        if (!in_array($explicit, helpdeskAllowedSections(), true)) {
            $explicit = 'dashboard';
        }
        return $explicit;
    }

    /**
     * @param array<string, scalar|null> $params
     */
    function helpdeskUrl(string $section = 'dashboard', array $params = []): string
    {
        $section = helpdeskSection($section);
        $query = $params;
        if ($section !== 'dashboard') {
            $query = array_merge(['section' => $section], $query);
        } else {
            unset($query['section']);
        }
        // Buang nilai kosong agar URL bersih
        foreach ($query as $key => $value) {
            if ($value === null || $value === '') {
                unset($query[$key]);
            }
        }
        $qs = http_build_query($query);
        $path = 'helpdesk_dashboard.php' . ($qs !== '' ? '?' . $qs : '');
        return function_exists('adminUrl') ? adminUrl($path) : $path;
    }

    /**
     * Redirect entry point lama (staff_calls.php, dll.) ke hub.
     *
     * @param array<string, scalar|null>|null $extra
     */
    function helpdeskRedirectToHub(string $section, ?array $extra = null): void
    {
        $params = $extra ?? $_GET;
        unset($params['section']);
        header('Location: ' . helpdeskUrl($section, $params));
        exit;
    }
}

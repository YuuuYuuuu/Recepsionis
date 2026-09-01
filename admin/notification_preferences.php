<?php
/**
 * Redirect lama → hub Helpdesk (preferensi notifikasi).
 */
require_once 'auth.php';
require_once 'helpdesk_hub.php';

header('Location: ' . helpdeskUrl('prefs'));
exit;

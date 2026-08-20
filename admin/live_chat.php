<?php
/**
 * Live Chat sudah dinonaktifkan — alihkan ke antrian Helpdesk.
 */
require_once 'auth.php';
requireComplaintOperatorPage();

$target = function_exists('adminUrl') ? adminUrl('staff_calls.php') : 'staff_calls.php';
header('Location: ' . $target . (strpos($target, '?') === false ? '?' : '&') . 'notice=live_chat_retired');
exit;

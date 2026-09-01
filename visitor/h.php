<?php
/**
 * Link pendek tindak lanjut Helpdesk (dari WhatsApp).
 * Contoh: /visitor/h.php?c=Ab3x9K2m
 *
 * Jika browser memaksa HTTPS ke localhost (ERR_SSL_PROTOCOL_ERROR),
 * alihkan ke http://127.0.0.1 dengan path yang sama.
 */
$host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
$hostOnly = preg_replace('/:\d+$/', '', $host) ?: $host;
$httpsOn = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443);

if ($httpsOn && in_array($hostOnly, ['localhost', '127.0.0.1', '::1'], true)) {
    $port = (int) ($_SERVER['SERVER_PORT'] ?? 0);
    // MAMP HTTP biasanya 8888; jangan pakai 443.
    $httpPort = ($port > 0 && $port !== 443) ? $port : 8888;
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    $target = 'http://127.0.0.1:' . $httpPort . $uri;
    header('Location: ' . $target, true, 302);
    exit;
}

require __DIR__ . '/helpdesk-action.php';

<?php
declare(strict_types=1);

function recepsionis_qr_logo_path(): string
{
    if (!function_exists('recepsionis_get_visitor_logo_relative_path')) {
        require_once __DIR__ . '/branding.php';
    }

    return BASE_PATH . '/' . recepsionis_get_visitor_logo_relative_path();
}

function recepsionis_qr_logo_url(): string
{
    if (!function_exists('recepsionis_get_visitor_logo_url')) {
        require_once __DIR__ . '/branding.php';
    }

    return recepsionis_get_visitor_logo_url();
}

/**
 * @return array{width:int,height:int,ratio:float}
 */
function recepsionis_qr_logo_metrics(): array
{
    static $metrics = null;
    if ($metrics !== null) {
        return $metrics;
    }

    $path = recepsionis_qr_logo_path();
    $info = is_readable($path) ? @getimagesize($path) : false;
    if ($info === false) {
        $metrics = ['width' => 1041, 'height' => 271, 'ratio' => 3.84];
        return $metrics;
    }

    $width = max(1, (int) $info[0]);
    $height = max(1, (int) $info[1]);
    $metrics = [
        'width' => $width,
        'height' => $height,
        'ratio' => $width / $height,
    ];

    return $metrics;
}

function recepsionis_qr_logo_data_uri(): string
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $path = recepsionis_qr_logo_path();
    if (!is_readable($path)) {
        $cached = '';
        return $cached;
    }

    $cached = 'data:image/png;base64,' . base64_encode((string) file_get_contents($path));
    return $cached;
}

/**
 * @return array{width:int,height:int,offsetX:int,offsetY:int,padX:int,padY:int,plateW:int,plateH:int,plateX:int,plateY:int,radius:int}
 */
function recepsionis_qr_logo_box_for_modules(int $modules, float $widthRatio = 0.3, float $maxHeightRatio = 0.13): array
{
    $ratio = recepsionis_qr_logo_metrics()['ratio'];

    $logoW = max(7, (int) round($modules * $widthRatio));
    $logoH = max(2, (int) round($logoW / $ratio));

    $maxH = max(2, (int) round($modules * $maxHeightRatio));
    if ($logoH > $maxH) {
        $logoH = $maxH;
        $logoW = max(7, (int) round($logoH * $ratio));
    }

    $padX = max(1, (int) round($logoW * 0.08));
    $padY = max(1, (int) round($logoH * 0.12));
    $plateW = $logoW + ($padX * 2);
    $plateH = $logoH + ($padY * 2);

    return [
        'width' => $logoW,
        'height' => $logoH,
        'offsetX' => (int) floor(($modules - $logoW) / 2),
        'offsetY' => (int) floor(($modules - $logoH) / 2),
        'padX' => $padX,
        'padY' => $padY,
        'plateW' => $plateW,
        'plateH' => $plateH,
        'plateX' => (int) floor(($modules - $plateW) / 2),
        'plateY' => (int) floor(($modules - $plateH) / 2),
        'radius' => max(1, (int) round($plateH * 0.24)),
    ];
}

/**
 * @return array{width:int,height:int,padX:int,padY:int,plateW:int,plateH:int,radius:int}
 */
function recepsionis_qr_logo_plate_pixels(int $qrSize): array
{
    $ratio = recepsionis_qr_logo_metrics()['ratio'];
    $width = max(28, (int) round($qrSize * 0.3));
    $height = max(10, (int) round($width / $ratio));
    $padX = max(4, (int) round($width * 0.08));
    $padY = max(3, (int) round($height * 0.12));

    return [
        'width' => $width,
        'height' => $height,
        'padX' => $padX,
        'padY' => $padY,
        'plateW' => $width + ($padX * 2),
        'plateH' => $height + ($padY * 2),
        'radius' => max(4, (int) round(($height + ($padY * 2)) * 0.24)),
    ];
}

function recepsionis_qr_embed_logo_in_svg(string $svg): string
{
    $logoData = recepsionis_qr_logo_data_uri();
    if ($logoData === '' || strpos($svg, '</svg>') === false) {
        return $svg;
    }

    if (!preg_match('/viewBox="0 0 (\d+) (\d+)"/', $svg, $matches)) {
        return $svg;
    }

    $modules = (int) $matches[1];
    if ($modules <= 0) {
        return $svg;
    }

    $box = recepsionis_qr_logo_box_for_modules($modules);

    $overlay = sprintf(
        '<rect x="%d" y="%d" width="%d" height="%d" rx="%d" fill="#ffffff"/>'
        . '<image href="%s" x="%d" y="%d" width="%d" height="%d" preserveAspectRatio="xMidYMid meet"/>',
        $box['plateX'],
        $box['plateY'],
        $box['plateW'],
        $box['plateH'],
        $box['radius'],
        htmlspecialchars($logoData, ENT_QUOTES, 'UTF-8'),
        $box['offsetX'],
        $box['offsetY'],
        $box['width'],
        $box['height']
    );

    return str_replace('</svg>', $overlay . '</svg>', $svg);
}

function recepsionis_qr_svg(string $text, int $size = 200): string
{
    $text = trim($text);
    if ($text === '') {
        return '';
    }

    require_once __DIR__ . '/QRCode.php';

    try {
        $svg = \splitbrain\phpQRCode\QRCode::svg($text, ['s' => 'qrh']);
    } catch (Throwable $e) {
        return '';
    }

    if ($svg === '' || strpos($svg, '<svg') === false) {
        return '';
    }

    $size = max(80, $size);
    $svg = preg_replace(
        '/<svg /',
        '<svg width="' . $size . '" height="' . $size . '" role="img" aria-label="QR Code" ',
        $svg,
        1
    );
    $svg = str_replace('<rect ', '<rect fill="#0f172a" ', $svg);
    $svg = recepsionis_qr_embed_logo_in_svg($svg);

    return $svg;
}

function recepsionis_qr_logo_overlay_html(int $size = 160): string
{
    $logoUrl = recepsionis_qr_logo_url();
    if ($logoUrl === '') {
        return '';
    }

    $plate = recepsionis_qr_logo_plate_pixels($size);

    return sprintf(
        '<span class="qr-logo-plate" style="width:%dpx;height:%dpx;border-radius:%dpx;">'
        . '<img class="qr-logo-mark" src="%s" alt="" width="%d" height="%d" style="width:%dpx;height:%dpx;object-fit:contain;" aria-hidden="true">'
        . '</span>',
        $plate['plateW'],
        $plate['plateH'],
        $plate['radius'],
        htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8'),
        $plate['width'],
        $plate['height'],
        $plate['width'],
        $plate['height']
    );
}

function recepsionis_qr_fallback_img(string $url, int $size, string $alt = 'QR Code'): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }

    $qrSrc = 'https://api.qrserver.com/v1/create-qr-code/?size=' . $size . 'x' . $size
        . '&ecc=H&data=' . rawurlencode($url);

    return '<div class="qr-with-logo" style="width:' . $size . 'px;height:' . $size . 'px;">'
        . '<img class="qr-with-logo__img" src="' . htmlspecialchars($qrSrc, ENT_QUOTES, 'UTF-8') . '" alt="'
        . htmlspecialchars($alt, ENT_QUOTES, 'UTF-8') . '" width="' . $size . '" height="' . $size . '">'
        . recepsionis_qr_logo_overlay_html($size)
        . '</div>';
}

function recepsionis_qr_logo_aspect_ratio(): float
{
    return recepsionis_qr_logo_metrics()['ratio'];
}

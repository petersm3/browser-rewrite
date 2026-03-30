<?php
/**
 * Lightweight placeholder image generator using PHP GD.
 * Replaces dependency on external dummyimage.com service.
 *
 * URL format: /img/WIDTHxHEIGHT/BGCOLOR/FGCOLOR.FORMAT?text=TEXT
 * Example:    /img/320x240/000/fff.png?text=Hello
 *
 * Based on concepts from https://github.com/kingkool68/dummyimage
 */

// Parse the request path: everything after /img/
$request = isset($_GET['x']) ? $_GET['x'] : '';
$text = isset($_GET['text']) ? $_GET['text'] : '';

// Split path segments: dimensions/bgcolor/fgcolor.format
$segments = explode('/', $request);

// Parse dimensions (first segment)
$dimensions = isset($segments[0]) ? $segments[0] : '320x240';

// Strip format extension from last segment
$format = 'png';
$lastSeg = end($segments);
if (preg_match('/\.(png|gif|jpe?g)$/i', $lastSeg, $fmtMatch)) {
    $format = strtolower($fmtMatch[1]);
    if ($format === 'jpeg') $format = 'jpg';
    // Remove extension from the segment it was found on
    $key = key(array_slice($segments, -1, 1, true));
    $segments[$key] = preg_replace('/\.(png|gif|jpe?g)$/i', '', $segments[$key]);
}

// Parse dimensions
$width = 320;
$height = 240;
if (preg_match('/^(\d+)x(\d+)$/i', $dimensions, $m)) {
    $width = intval($m[1]);
    $height = intval($m[2]);
} elseif (preg_match('/^(\d+)$/', $dimensions, $m)) {
    $width = $height = intval($m[1]);
}

// Clamp dimensions
$width = max(1, min($width, 2000));
$height = max(1, min($height, 2000));

// Parse colors (hex without #)
$bgHex = isset($segments[1]) && $segments[1] !== '' ? $segments[1] : 'cccccc';
$fgHex = isset($segments[2]) && $segments[2] !== '' ? $segments[2] : '000000';

// Expand short hex
function expandHex($hex) {
    $hex = ltrim($hex, '#');
    $len = strlen($hex);
    if ($len === 1) return str_repeat($hex, 6);
    if ($len === 2) return str_repeat($hex, 3);
    if ($len === 3) return $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    return str_pad($hex, 6, '0');
}

function hexToRgb($hex) {
    $hex = expandHex($hex);
    return array(
        hexdec(substr($hex, 0, 2)),
        hexdec(substr($hex, 2, 2)),
        hexdec(substr($hex, 4, 2))
    );
}

$bgRgb = hexToRgb($bgHex);
$fgRgb = hexToRgb($fgHex);

// Create image
$img = imagecreatetruecolor($width, $height);
$bgColor = imagecolorallocate($img, $bgRgb[0], $bgRgb[1], $bgRgb[2]);
$fgColor = imagecolorallocate($img, $fgRgb[0], $fgRgb[1], $fgRgb[2]);
imagefilledrectangle($img, 0, 0, $width - 1, $height - 1, $bgColor);

// Render text if provided
if ($text !== '') {
    $fontPath = __DIR__ . '/mplus-1c-medium.ttf';
    if (file_exists($fontPath)) {
        // Calculate font size to fit within image
        $fontSize = max(min($width / max(strlen($text), 1) * 1.15, $height * 0.5), 5);
        $fontSize = min($fontSize, 60);

        // Get text bounding box to center it
        $bbox = imagettfbbox($fontSize, 0, $fontPath, $text);
        $textWidth = abs($bbox[4] - $bbox[0]);
        $textHeight = abs($bbox[5] - $bbox[1]);
        $x = ($width - $textWidth) / 2;
        $y = ($height + $textHeight) / 2;

        imagettftext($img, $fontSize, 0, intval($x), intval($y), $fgColor, $fontPath, $text);
    }
}

// Set caching headers (90 days)
header('Cache-Control: public, max-age=7776000');
header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 7776000) . ' GMT');

// Output image
switch ($format) {
    case 'gif':
        header('Content-Type: image/gif');
        imagegif($img);
        break;
    case 'jpg':
        header('Content-Type: image/jpeg');
        imagejpeg($img, null, 90);
        break;
    default:
        header('Content-Type: image/png');
        imagepng($img);
        break;
}

imagedestroy($img);

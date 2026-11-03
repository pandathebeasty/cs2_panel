<?php
/**
 * Generates .webp siblings for PNG/JPG images under assets/ so .htaccess can
 * serve them to browsers that accept WebP. Originals are never modified.
 *
 * Usage: php tools/convert-webp.php [--quality=82] [--force] [dir ...]
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (!function_exists('imagewebp')) { fwrite(STDERR, "GD with WebP support is required.\n"); exit(1); }

$root = dirname(__DIR__);
$quality = 82;
$force = false;
$dirs = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--quality=(\d{1,3})$/', $arg, $m)) { $quality = max(1, min(100, (int) $m[1])); }
    elseif ($arg === '--force') { $force = true; }
    else { $dirs[] = $arg; }
}
if (!$dirs) { $dirs = [$root . '/assets/img']; }

$done = $skipped = $failed = 0;
$before = $after = 0;
foreach ($dirs as $dir) {
    if (!is_dir($dir)) { fwrite(STDERR, "Not a directory: $dir\n"); continue; }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        $path = $file->getPathname();
        if (!preg_match('/\.(png|jpe?g)$/i', $path, $m)) { continue; }
        $target = substr($path, 0, -strlen($m[0])) . '.webp';
        if (!$force && is_file($target) && filemtime($target) >= filemtime($path)) { $skipped++; continue; }

        $img = strtolower($m[1]) === 'png' ? @imagecreatefrompng($path) : @imagecreatefromjpeg($path);
        if (!$img) { $failed++; continue; }
        if (!imageistruecolor($img)) { imagepalettetotruecolor($img); }
        imagealphablending($img, false);
        imagesavealpha($img, true);
        $ok = imagewebp($img, $target, $quality);
        imagedestroy($img);
        if (!$ok || !is_file($target)) { $failed++; continue; }

        $src = filesize($path);
        $dst = filesize($target);
        // A larger WebP is pointless; drop it so the original keeps being served.
        if ($dst >= $src) { unlink($target); $skipped++; continue; }
        $before += $src;
        $after += $dst;
        $done++;
    }
}

printf("Converted %d, skipped %d, failed %d. %.1f MB -> %.1f MB\n", $done, $skipped, $failed, $before / 1048576, $after / 1048576);

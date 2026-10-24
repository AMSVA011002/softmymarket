<?php
declare(strict_types=1);

/**
 * Safe file uploads.
 *
 * - the extension is derived from the sniffed MIME type, never the file name
 * - names are random, so one user can never overwrite another user's file
 * - files land outside the document root and are served through a checked PHP
 *   endpoint (see payment/proof.php and image.php)
 * - images are re-encoded with GD when available, which strips embedded
 *   payloads and EXIF metadata (including GPS coordinates)
 */
final class Uploads
{
    /** @var array<string,string> */
    public const IMAGE_MIME = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    /** @var array<string,string> */
    public const DOCUMENT_MIME = [
        'application/pdf' => 'pdf',
    ];

    /**
     * Store an uploaded file.
     *
     * @param array<string,mixed> $file one entry of $_FILES
     * @param string $folder sub-directory inside storage/uploads
     * @param array<string,string> $allowed map of mime => extension
     * @return array{ok:bool,file?:string,path?:string,mime?:string,size?:int,error?:string}
     */
    public static function store(array $file, string $folder, array $allowed, ?int $maxBytes = null, bool $reencodeImages = true): array
    {
        $maxBytes = $maxBytes ?? (int) Config::get('uploads.max_bytes', 5242880);
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            return ['ok' => false, 'error' => 'No file was uploaded.'];
        }
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            return ['ok' => false, 'error' => 'The file is larger than the server allows.'];
        }
        if ($error !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => 'The upload failed (code ' . $error . ').'];
        }
        if (!is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            return ['ok' => false, 'error' => 'That upload could not be verified.'];
        }
        if ((int) $file['size'] <= 0 || (int) $file['size'] > $maxBytes) {
            return ['ok' => false, 'error' => 'Files must be smaller than ' . round($maxBytes / 1048576, 1) . 'MB.'];
        }

        $tmp = (string) $file['tmp_name'];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($tmp);
        if (!isset($allowed[$mime])) {
            return ['ok' => false, 'error' => 'That file type is not allowed.'];
        }

        $folder = trim(preg_replace('/[^a-z0-9_\-\/]/i', '', $folder) ?? '', '/');
        $directory = STORAGE_PATH . '/uploads/' . ($folder !== '' ? $folder : 'misc');
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            return ['ok' => false, 'error' => 'The upload folder is not writable.'];
        }
        self::protect($directory);

        $extension = $allowed[$mime];
        $name = date('Ymd') . '-' . bin2hex(random_bytes(8)) . '.' . $extension;
        $target = $directory . '/' . $name;

        if ($reencodeImages && isset(self::IMAGE_MIME[$mime]) && $mime !== 'image/gif') {
            if (self::reencode($tmp, $target, $mime)) {
                return ['ok' => true, 'file' => $name, 'path' => $target, 'mime' => $mime, 'size' => (int) filesize($target)];
            }
        }

        if (!move_uploaded_file($tmp, $target)) {
            return ['ok' => false, 'error' => 'We could not save the file.'];
        }
        @chmod($target, 0644);
        return ['ok' => true, 'file' => $name, 'path' => $target, 'mime' => $mime, 'size' => (int) $file['size']];
    }

    /** Re-encode an image, dropping metadata and any embedded payload. */
    private static function reencode(string $source, string $target, string $mime): bool
    {
        if (!function_exists('imagecreatefromstring')) {
            return false;
        }
        $info = @getimagesize($source);
        if (!is_array($info)) {
            return false;
        }
        if ((int) $info[0] > 6000 || (int) $info[1] > 6000) {
            return false;
        }
        $data = @file_get_contents($source);
        if ($data === false) {
            return false;
        }
        $image = @imagecreatefromstring($data);
        if ($image === false) {
            return false;
        }
        $ok = match ($mime) {
            'image/jpeg' => imagejpeg($image, $target, 88),
            'image/png' => imagepng($image, $target, 6),
            'image/webp' => function_exists('imagewebp') ? imagewebp($image, $target, 88) : false,
            default => false,
        };
        imagedestroy($image);
        if ($ok) {
            @chmod($target, 0644);
        }
        return (bool) $ok;
    }

    /** Drop an Apache/PHP deny file into a storage folder. */
    private static function protect(string $directory): void
    {
        $htaccess = $directory . '/.htaccess';
        if (!is_file($htaccess)) {
            @file_put_contents($htaccess, "Options -Indexes\n<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order deny,allow\n  Deny from all\n</IfModule>\n");
        }
    }

    /** Remove a previously stored upload (path is confined to storage/uploads). */
    public static function delete(?string $storedPath): bool
    {
        if ($storedPath === null || $storedPath === '') {
            return false;
        }
        $real = realpath($storedPath);
        $root = realpath(STORAGE_PATH . '/uploads');
        if ($real === false || $root === false || !str_starts_with($real, $root)) {
            return false;
        }
        return is_file($real) && @unlink($real);
    }

    /** Byte size as a short human string. */
    public static function humanSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024) . ' KB';
        }
        return $bytes . ' B';
    }
}

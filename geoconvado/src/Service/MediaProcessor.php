<?php
/**
 * GEO.Convado - AI visibility (llms.txt) for PrestaShop 9
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to a commercial license from Convado AG.
 * Use, copy, modification or distribution is only permitted with a valid
 * license agreement.
 *
 * @author    Convado AG <info@convado.ch>
 * @copyright 2026 Convado AG
 * @license   Commercial license, see LICENSE.md
 */

declare(strict_types=1);

namespace GeoConvado\Service;

if (!defined('_PS_VERSION_')) {
    exit;
}

/** Fehler bei der Medienverarbeitung. Der Code ist ein Text-Schlüssel aus ReviewText (m_*). */
final class MediaException extends \RuntimeException
{
}

/**
 * Bilder: prüfen, neu kodieren (entfernt EXIF/GPS und eingebetteten Code), verkleinern, Vorschaubild.
 * Das Original wird nie gespeichert oder ausgeliefert.
 */
final class MediaProcessor
{
    public const MAX_PX = 6000;
    public const MAX_PIXELS = 24000000; // 24 MP (z. B. 6000 × 4000), schützt den Arbeitsspeicher
    public const OUT_MAX = 1600;
    public const THUMB = 400;
    public const IMG_MAX_BYTES = 8388608; // 8 MB je Bild

    public static function root(): string
    {
        return _PS_ROOT_DIR_ . '/var/geoconvado-media';
    }

    /** Pfad einer gespeicherten Datei (nur Namen aus 32 Hex + Endung; schützt vor Pfad-Tricks). */
    public static function path(string $file): string
    {
        if (!preg_match('/^[a-f0-9]{32}(-t|-p|-src)?\.(webp|jpg|mp4|webm|bin)$/', $file)) {
            throw new \InvalidArgumentException('Ungültiger Dateiname');
        }

        return self::root() . '/' . substr($file, 0, 2) . '/' . $file;
    }

    public static function remove(string $file): void
    {
        try {
            $p = self::path($file);
        } catch (\InvalidArgumentException $e) {
            return;
        }
        if (is_file($p)) {
            @unlink($p);
        }
    }

    /** Ordner anlegen und gegen direkten Aufruf sperren (Auslieferung nur über den Modul-Controller). */
    public static function ensureRoot(): bool
    {
        $r = self::root();
        if (!is_dir($r) && !@mkdir($r, 0755, true) && !is_dir($r)) {
            return false;
        }
        @file_put_contents($r . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n");
        @file_put_contents($r . '/index.php', "<?php\nheader('HTTP/1.1 403 Forbidden');\nexit;\n");

        return is_writable($r);
    }

    public static function newName(string $suffix, string $ext): string
    {
        return bin2hex(random_bytes(16)) . $suffix . '.' . $ext;
    }

    /** Fähigkeiten dieses Servers (Anzeige im Backoffice, Entscheidung zur Laufzeit). */
    public static function caps(): array
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        $proc = function_exists('proc_open') && !in_array('proc_open', $disabled, true);
        $ffmpeg = $proc ? VideoProcessor::findBin('ffmpeg') : '';
        $ffprobe = $proc ? VideoProcessor::findBin('ffprobe') : '';

        return [
            'gd' => extension_loaded('gd'),
            'webp' => function_exists('imagewebp') && function_exists('imagecreatefromwebp'),
            'jpeg' => function_exists('imagecreatefromjpeg'),
            'png' => function_exists('imagecreatefrompng'),
            'exif' => function_exists('exif_read_data'),
            'fileinfo' => class_exists('finfo'),
            'proc' => $proc,
            'ffmpeg' => $ffmpeg,
            'ffprobe' => $ffprobe,
            'upload_max' => self::bytes((string) ini_get('upload_max_filesize')),
            'post_max' => self::bytes((string) ini_get('post_max_size')),
            'memory' => self::bytes((string) ini_get('memory_limit')),
            'max_file_uploads' => (int) ini_get('max_file_uploads'),
            'file_uploads' => (bool) ini_get('file_uploads'),
            'time' => (int) ini_get('max_execution_time'),
            'root_writable' => self::ensureRoot(),
            'free' => is_dir(self::root()) ? (int) @disk_free_space(self::root()) : 0,
        ];
    }

    public static function bytes(string $v): int
    {
        $v = trim($v);
        if ($v === '' || $v === '-1') {
            return -1;
        }
        $n = (int) $v;
        switch (strtolower(substr($v, -1))) {
            case 'g':
                return $n * 1073741824;
            case 'm':
                return $n * 1048576;
            case 'k':
                return $n * 1024;
        }

        return $n;
    }

    public static function imagesPossible(): bool
    {
        $c = self::caps();

        return $c['gd'] && $c['jpeg'] && $c['png'] && $c['fileinfo'] && $c['file_uploads'] && $c['root_writable'];
    }

    /**
     * Ein hochgeladenes Bild verarbeiten.
     *
     * @return array{file: string, thumb: string, mime: string, w: int, h: int, bytes: int}
     *
     * @throws MediaException
     */
    public static function image(string $tmp, int $size, int $maxBytes = self::IMG_MAX_BYTES, bool $isUpload = true): array
    {
        if (!is_file($tmp) || ($isUpload && !is_uploaded_file($tmp))) {
            throw new MediaException('m_upload');
        }
        if ($size <= 0 || $size > $maxBytes) {
            throw new MediaException('m_size');
        }
        $f = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $f->file($tmp);
        $allowed = ['image/jpeg' => IMAGETYPE_JPEG, 'image/png' => IMAGETYPE_PNG, 'image/webp' => IMAGETYPE_WEBP];
        if (!isset($allowed[$mime])) {
            throw new MediaException('m_type');
        }
        $info = @getimagesize($tmp);
        if ($info === false || $info[2] !== $allowed[$mime]) {
            throw new MediaException('m_type');
        }
        [$w, $h] = [(int) $info[0], (int) $info[1]];
        if ($w < 1 || $h < 1 || $w > self::MAX_PX || $h > self::MAX_PX || $w * $h > self::MAX_PIXELS) {
            throw new MediaException('m_dim');
        }
        $caps = self::caps();
        if ($mime === 'image/webp' && !$caps['webp']) {
            throw new MediaException('m_type');
        }
        // Speicherbedarf grob: Bild + Kopie beim Verkleinern. Nicht riskieren, wenn das Limit knapp ist.
        $need = (int) ($w * $h * 4 * 2.2) + 8388608;
        $limit = $caps['memory'];
        if ($limit > 0 && $need > $limit - memory_get_usage(true)) {
            @ini_set('memory_limit', (string) max($limit, $need + 33554432));
            $limit = self::bytes((string) ini_get('memory_limit'));
            if ($limit > 0 && $need > $limit - memory_get_usage(true)) {
                throw new MediaException('m_dim');
            }
        }
        if (!self::ensureRoot()) {
            throw new MediaException('m_fail');
        }

        $src = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($tmp),
            'image/png' => @imagecreatefrompng($tmp),
            default => @imagecreatefromwebp($tmp),
        };
        if ($src === false) {
            throw new MediaException('m_fail');
        }
        try {
            if (function_exists('imagepalettetotruecolor')) {
                @imagepalettetotruecolor($src);
            }
            $src = self::orient($src, $tmp, $mime);
            $full = self::scale($src, self::OUT_MAX);
            $thumb = self::scale($src, self::THUMB);
            $useWebp = $caps['webp'];
            $ext = $useWebp ? 'webp' : 'jpg';
            $name = self::newName('', $ext);
            $tname = self::newName('-t', $ext);
            if (!self::save($full, $name, $useWebp) || !self::save($thumb, $tname, $useWebp)) {
                self::remove($name);
                self::remove($tname);
                throw new MediaException('m_fail');
            }
            $out = @getimagesize(self::path($name));

            return [
                'file' => $name, 'thumb' => $tname, 'mime' => $useWebp ? 'image/webp' : 'image/jpeg',
                'w' => (int) ($out[0] ?? imagesx($full)), 'h' => (int) ($out[1] ?? imagesy($full)), 'bytes' => (int) filesize(self::path($name)),
            ];
        } finally {
            // imagedestroy() ist seit PHP 8.0 wirkungslos und ab 8.5 veraltet: Speicher wird mit dem Objekt freigegeben.
            unset($src, $full, $thumb);
        }
    }

    /** EXIF-Drehung anwenden (Handyfotos), bevor die Metadaten verworfen werden. */
    private static function orient(\GdImage $img, string $tmp, string $mime): \GdImage
    {
        if ($mime !== 'image/jpeg' || !function_exists('exif_read_data') || !function_exists('imagerotate')) {
            return $img;
        }
        $o = (int) (@exif_read_data($tmp)['Orientation'] ?? 1);
        $deg = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
        if ($deg === 0) {
            return $img;
        }
        $r = @imagerotate($img, $deg, 0);

        return $r instanceof \GdImage ? $r : $img;
    }

    private static function scale(\GdImage $src, int $max): \GdImage
    {
        $w = imagesx($src);
        $h = imagesy($src);
        $ratio = min(1.0, $max / max($w, $h));
        $nw = max(1, (int) round($w * $ratio));
        $nh = max(1, (int) round($h * $ratio));
        $dst = imagecreatetruecolor($nw, $nh);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, (int) imagecolorallocatealpha($dst, 255, 255, 255, 127));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

        return $dst;
    }

    private static function save(\GdImage $img, string $name, bool $webp): bool
    {
        $path = self::path($name);
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }
        if ($webp) {
            imagealphablending($img, false);
            imagesavealpha($img, true);

            return @imagewebp($img, $path, 82);
        }
        // JPEG kennt keine Transparenz: auf Weiss legen.
        $flat = imagecreatetruecolor(imagesx($img), imagesy($img));
        imagefill($flat, 0, 0, (int) imagecolorallocate($flat, 255, 255, 255));
        imagealphablending($flat, true);
        imagecopy($flat, $img, 0, 0, 0, 0, imagesx($img), imagesy($img));
        $ok = @imagejpeg($flat, $path, 84);
        unset($flat);

        return $ok;
    }
}

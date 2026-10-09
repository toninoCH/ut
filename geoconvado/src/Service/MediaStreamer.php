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

/** Liefert gespeicherte Bewertungs-Medien aus (feste Content-Types, nosniff, Range für Video). */
final class MediaStreamer
{
    private const TYPES = ['webp' => 'image/webp', 'jpg' => 'image/jpeg', 'mp4' => 'video/mp4'];

    /** Content-Type aus der Dateiendung (nie aus Benutzerangaben). */
    public static function mime(string $file): string
    {
        return self::TYPES[strtolower((string) pathinfo($file, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
    }

    /**
     * Welche Datei gehört zu welcher Variante? f = Original-Ausgabe, t = Vorschau/Poster.
     *
     * @return string Dateiname oder '' (nicht vorhanden)
     */
    public static function pick(array $media, string $variant): string
    {
        if (($media['state'] ?? '') !== 'ready') {
            return '';
        }

        return (string) ($variant === 't' ? ($media['thumb'] !== '' ? $media['thumb'] : ($media['kind'] === 'image' ? $media['file'] : '')) : $media['file']);
    }

    /**
     * HTTP-Range auswerten. status 200 = ganze Datei, 206 = Teil, 416 = ungültig.
     *
     * @return array{status: int, start: int, end: int}
     */
    public static function plan(string $range, int $size): array
    {
        if ($size <= 0) {
            return ['status' => 416, 'start' => 0, 'end' => 0];
        }
        if ($range === '' || !preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $m) || ($m[1] === '' && $m[2] === '')) {
            return ['status' => 200, 'start' => 0, 'end' => $size - 1];
        }
        if ($m[1] === '') {
            $len = (int) $m[2];
            if ($len <= 0) {
                return ['status' => 416, 'start' => 0, 'end' => 0];
            }
            $start = max(0, $size - $len);
            $end = $size - 1;
        } else {
            $start = (int) $m[1];
            $end = $m[2] === '' ? $size - 1 : min((int) $m[2], $size - 1);
        }
        if ($start > $end || $start >= $size) {
            return ['status' => 416, 'start' => 0, 'end' => 0];
        }

        return ['status' => 206, 'start' => $start, 'end' => $end];
    }

    /** Datei senden und beenden. $public = freigegeben (öffentlich cachebar). */
    public static function send(string $file, bool $public, string $range = ''): void
    {
        try {
            $path = MediaProcessor::path($file);
        } catch (\InvalidArgumentException $e) {
            self::notFound();
        }
        $ext = strtolower((string) pathinfo($file, PATHINFO_EXTENSION));
        if (!isset(self::TYPES[$ext]) || !is_file($path)) {
            self::notFound();
        }
        $size = (int) filesize($path);
        $p = self::plan($range, $size);
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        header('Content-Type: ' . self::TYPES[$ext]);
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; sandbox");
        header('Accept-Ranges: bytes');
        header('Cache-Control: ' . ($public ? 'public, max-age=86400' : 'private, no-store'));
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', (int) filemtime($path)) . ' GMT');
        if ($p['status'] === 416) {
            http_response_code(416);
            header('Content-Range: bytes */' . $size);
            exit;
        }
        if ($p['status'] === 206) {
            http_response_code(206);
            header('Content-Range: bytes ' . $p['start'] . '-' . $p['end'] . '/' . $size);
        }
        $len = $p['end'] - $p['start'] + 1;
        header('Content-Length: ' . $len);
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            self::notFound();
        }
        fseek($fh, $p['start']);
        while ($len > 0 && !feof($fh)) {
            $chunk = (string) fread($fh, min(65536, $len));
            if ($chunk === '') {
                break;
            }
            echo $chunk;
            $len -= strlen($chunk);
            flush();
        }
        fclose($fh);
        exit;
    }

    private static function notFound(): never
    {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Not found');
    }
}

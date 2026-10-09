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

/**
 * Videos: Container prüfen (Magic Bytes), Dauer bestimmen, bei vorhandenem ffmpeg im Hintergrund (Cron) nach
 * H.264/AAC MP4 umwandeln. Ohne ffmpeg werden nur MP4-Dateien mit geprüfter Dauer unverändert freigegeben.
 * Die Dateien werden nie ausgeführt und nur über den Modul-Controller mit festem Content-Type ausgeliefert.
 */
final class VideoProcessor
{
    private const MP4_BRANDS = ['isom', 'iso2', 'iso4', 'iso5', 'iso6', 'mp41', 'mp42', 'avc1', 'M4V ', '3gp4', '3gp5', '3gp6'];

    /** Programm suchen (Konfiguration, Standardpfade, PATH). Leer = nicht vorhanden. */
    public static function findBin(string $name): string
    {
        $cfg = trim((string) \Configuration::get('GEOCONVADO_RV_' . strtoupper($name)));
        $paths = $cfg !== '' ? [$cfg] : [];
        foreach (['/usr/bin', '/usr/local/bin', '/opt/homebrew/bin', '/bin'] as $d) {
            $paths[] = $d . '/' . $name;
        }
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $d) {
            if ($d !== '') {
                $paths[] = rtrim($d, '/') . '/' . $name;
            }
        }
        foreach ($paths as $p) {
            if (@is_file($p) && @is_executable($p)) {
                return $p;
            }
        }

        return '';
    }

    /**
     * Programm ohne Shell starten (Argumentliste), mit Zeitlimit.
     *
     * @param string[] $cmd
     *
     * @return array{code: int, out: string, err: string, timeout: bool}
     */
    public static function run(array $cmd, int $timeout): array
    {
        $proc = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            return ['code' => -1, 'out' => '', 'err' => 'proc_open failed', 'timeout' => false];
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = '';
        $err = '';
        $start = microtime(true);
        $timedOut = false;
        while (true) {
            $st = proc_get_status($proc);
            $r = [$pipes[1], $pipes[2]];
            $w = $e = null;
            if (@stream_select($r, $w, $e, 0, 200000) > 0) {
                foreach ($r as $s) {
                    $chunk = (string) fread($s, 65536);
                    if ($s === $pipes[1]) {
                        $out .= strlen($out) < 1048576 ? $chunk : '';
                    } else {
                        $err .= strlen($err) < 8192 ? $chunk : '';
                    }
                }
            }
            if (!$st['running']) {
                break;
            }
            if (microtime(true) - $start > $timeout) {
                $timedOut = true;
                proc_terminate($proc, 9);
                break;
            }
        }
        $out .= (string) stream_get_contents($pipes[1]);
        $err .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        return ['code' => $timedOut ? -1 : ((int) $st['exitcode'] >= 0 ? (int) $st['exitcode'] : (int) $code), 'out' => $out, 'err' => substr($err, -2000), 'timeout' => $timedOut];
    }

    /** Container anhand der ersten Bytes. @return 'mp4'|'mov'|'webm'|null */
    public static function sniff(string $path): ?string
    {
        $h = (string) @file_get_contents($path, false, null, 0, 16);
        if (strlen($h) < 12) {
            return null;
        }
        if (substr($h, 4, 4) === 'ftyp') {
            $brand = substr($h, 8, 4);
            if ($brand === 'qt  ') {
                return 'mov';
            }

            return in_array($brand, self::MP4_BRANDS, true) ? 'mp4' : null;
        }
        if (substr($h, 0, 4) === "\x1A\x45\xDF\xA3") {
            return 'webm';
        }

        return null;
    }

    /** Dauer aus dem MP4-Header (moov/mvhd) ohne externe Programme. */
    public static function mp4Duration(string $path): ?float
    {
        $size = (int) @filesize($path);
        $fh = @fopen($path, 'rb');
        if (!$fh || $size < 16) {
            return null;
        }
        try {
            $pos = 0;
            $guard = 0;
            while ($pos + 8 <= $size && ++$guard < 512) {
                fseek($fh, $pos);
                $h = (string) fread($fh, 16);
                if (strlen($h) < 8) {
                    return null;
                }
                $len = (int) unpack('N', substr($h, 0, 4))[1];
                $type = substr($h, 4, 4);
                $hdr = 8;
                if ($len === 1 && strlen($h) >= 16) {
                    $len = (int) unpack('J', substr($h, 8, 8))[1];
                    $hdr = 16;
                } elseif ($len === 0) {
                    $len = $size - $pos;
                }
                if ($len < $hdr) {
                    return null;
                }
                if ($type === 'moov') {
                    return self::mvhd($fh, $pos + $hdr, min($size, $pos + $len));
                }
                $pos += $len;
            }
        } finally {
            fclose($fh);
        }

        return null;
    }

    /** @param resource $fh */
    private static function mvhd($fh, int $start, int $end): ?float
    {
        $pos = $start;
        $guard = 0;
        while ($pos + 8 <= $end && ++$guard < 256) {
            fseek($fh, $pos);
            $h = (string) fread($fh, 8);
            if (strlen($h) < 8) {
                return null;
            }
            $len = (int) unpack('N', substr($h, 0, 4))[1];
            if ($len < 8) {
                return null;
            }
            if (substr($h, 4, 4) === 'mvhd') {
                $b = (string) fread($fh, 32);
                $v = ord($b[0] ?? "\0");
                if ($v === 1 && strlen($b) >= 32) {
                    $ts = (int) unpack('N', substr($b, 20, 4))[1];
                    $dur = (int) unpack('J', substr($b, 24, 8))[1];
                } elseif ($v === 0 && strlen($b) >= 20) {
                    $ts = (int) unpack('N', substr($b, 12, 4))[1];
                    $dur = (int) unpack('N', substr($b, 16, 4))[1];
                } else {
                    return null;
                }

                return $ts > 0 ? $dur / $ts : null;
            }
            $pos += $len;
        }

        return null;
    }

    /** Dauer und Masse über ffprobe. @return array{duration: float, w: int, h: int, codec: string}|null */
    public static function probe(string $path, string $container = ''): ?array
    {
        $bin = self::findBin('ffprobe');
        if ($bin === '') {
            return null;
        }
        $cmd = [$bin, '-v', 'error', '-protocol_whitelist', 'file'];
        if ($container !== '') {
            $cmd[] = '-f';
            $cmd[] = $container === 'webm' ? 'matroska,webm' : 'mov,mp4,m4a,3gp,3g2,mj2';
        }
        array_push($cmd, '-select_streams', 'v:0', '-show_entries', 'stream=codec_name,width,height:format=duration', '-of', 'json', $path);
        $r = self::run($cmd, 15);
        if ($r['code'] !== 0) {
            return null;
        }
        $j = json_decode($r['out'], true);
        $s = $j['streams'][0] ?? null;
        $d = $j['format']['duration'] ?? null;
        if (!is_array($s) || !is_numeric($d)) {
            return null;
        }

        return ['duration' => (float) $d, 'w' => (int) ($s['width'] ?? 0), 'h' => (int) ($s['height'] ?? 0), 'codec' => (string) ($s['codec_name'] ?? '')];
    }

    /** Wird auf diesem Server umgewandelt? */
    public static function transcodes(): bool
    {
        return MediaProcessor::caps()['ffmpeg'] !== '';
    }

    /**
     * Upload annehmen: prüfen und ablegen.
     *
     * @return array{src: string, file: string, mime: string, bytes: int, duration: int, state: string}
     *
     * @throws MediaException
     */
    public static function accept(string $tmp, int $size, int $maxBytes, int $maxSec, bool $isUpload = true): array
    {
        if (!is_file($tmp) || ($isUpload && !is_uploaded_file($tmp))) {
            throw new MediaException('m_upload');
        }
        if ($size <= 0 || $size > $maxBytes) {
            throw new MediaException('m_size');
        }
        $container = self::sniff($tmp);
        if ($container === null) {
            throw new MediaException('m_type');
        }
        $caps = MediaProcessor::caps();
        $transcode = $caps['ffmpeg'] !== '';
        if (!$transcode && $container !== 'mp4') {
            throw new MediaException('m_off');
        }
        $dur = null;
        if ($caps['ffprobe'] !== '') {
            $p = self::probe($tmp, $container);
            $dur = $p['duration'] ?? null;
            if ($p === null) {
                throw new MediaException('m_type');
            }
        } elseif ($container !== 'webm') {
            $dur = self::mp4Duration($tmp);
        }
        if ($dur === null) {
            throw new MediaException($container === 'webm' ? 'm_off' : 'm_fail');
        }
        if ($dur > $maxSec + 1) {
            throw new MediaException('m_long');
        }
        if (!MediaProcessor::ensureRoot()) {
            throw new MediaException('m_fail');
        }
        $name = MediaProcessor::newName($transcode ? '-src' : '', $transcode ? 'bin' : 'mp4');
        $dst = MediaProcessor::path($name);
        $dir = dirname($dst);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new MediaException('m_fail');
        }
        $moved = $isUpload ? @move_uploaded_file($tmp, $dst) : @copy($tmp, $dst);
        if (!$moved) {
            throw new MediaException('m_fail');
        }
        @chmod($dst, 0644);

        return [
            'src' => $transcode ? $name : '', 'file' => $transcode ? '' : $name, 'mime' => 'video/mp4', 'bytes' => $size,
            'duration' => (int) ceil($dur), 'state' => $transcode ? 'processing' : 'ready', 'container' => $container,
        ];
    }

    /**
     * Wartende Videos umwandeln (vom Cron aufgerufen). Pro Aufruf höchstens $max Dateien.
     *
     * @return int Anzahl bearbeiteter Videos
     */
    public static function processQueue(int $max = 1, int $maxSec = 30): int
    {
        $ffmpeg = MediaProcessor::caps()['ffmpeg'];
        $db = \Db::getInstance();
        $t = '`' . _DB_PREFIX_ . 'geoconvado_review_media`';
        $done = 0;
        foreach ($db->executeS("SELECT * FROM $t WHERE state = 'processing' ORDER BY id_media LIMIT " . max(1, $max)) ?: [] as $m) {
            $id = (int) $m['id_media'];
            // Anspruch sichern, falls zwei Cron-Läufe gleichzeitig kommen.
            $db->execute("UPDATE $t SET state = 'working' WHERE id_media = $id AND state = 'processing'");
            if ((int) $db->Affected_Rows() !== 1) {
                continue;
            }
            $ok = false;
            $res = [];
            try {
                $res = $ffmpeg !== '' ? self::transcode((string) $m['src'], $ffmpeg, $maxSec) : [];
                $ok = $res !== [];
            } catch (\Throwable $e) {
                $ok = false;
            }
            if ($ok) {
                $db->execute("UPDATE $t SET state = 'ready', file = '" . pSQL($res['file']) . "', thumb = '" . pSQL($res['thumb']) . "', src = '', mime = 'video/mp4', bytes = "
                    . (int) $res['bytes'] . ', w = ' . (int) $res['w'] . ', h = ' . (int) $res['h'] . ', duration = ' . (int) $res['duration'] . " WHERE id_media = $id");
            } else {
                $db->execute("UPDATE $t SET state = 'failed', src = '' WHERE id_media = $id");
            }
            MediaProcessor::remove((string) $m['src']);
            ++$done;
        }
        // Hängende Aufträge (Absturz mitten in der Umwandlung) nach 30 Min. wieder freigeben.
        $db->execute("UPDATE $t SET state = 'processing' WHERE state = 'working' AND created_at < '" . date('Y-m-d H:i:s', time() - 1800) . "' AND src <> ''");

        return $done;
    }

    /** @return array{file: string, thumb: string, bytes: int, w: int, h: int, duration: int}|array{} */
    private static function transcode(string $srcName, string $ffmpeg, int $maxSec): array
    {
        $src = MediaProcessor::path($srcName);
        if (!is_file($src)) {
            return [];
        }
        $container = self::sniff($src);
        if ($container === null) {
            return [];
        }
        $name = MediaProcessor::newName('', 'mp4');
        $poster = MediaProcessor::newName('-p', 'jpg');
        $out = MediaProcessor::path($name);
        $pOut = MediaProcessor::path($poster);
        foreach ([$out, $pOut] as $dest) {
            $dir = dirname($dest);
            if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                return [];
            }
        }
        $fmt = $container === 'webm' ? 'matroska,webm' : 'mov,mp4,m4a,3gp,3g2,mj2';

        $r = self::run([
            $ffmpeg, '-nostdin', '-hide_banner', '-loglevel', 'error', '-y', '-protocol_whitelist', 'file', '-f', $fmt, '-i', $src,
            '-t', (string) $maxSec, '-map', '0:v:0', '-map', '0:a:0?', '-vf', "scale='min(1280,iw)':-2,format=yuv420p",
            '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '26', '-c:a', 'aac', '-b:a', '96k', '-map_metadata', '-1',
            '-movflags', '+faststart', '-threads', '2', '-f', 'mp4', $out,
        ], 150);
        if ($r['code'] !== 0 || !is_file($out)) {
            @unlink($out);

            return [];
        }
        $p = self::probe($out, 'mp4');
        if ($p === null || $p['codec'] !== 'h264' || $p['duration'] > $maxSec + 2) {
            @unlink($out);

            return [];
        }
        $seek = $p['duration'] > 2 ? '1' : '0';
        $pr = self::run([$ffmpeg, '-nostdin', '-hide_banner', '-loglevel', 'error', '-y', '-ss', $seek, '-i', $out, '-frames:v', '1', '-vf', 'scale=640:-2', '-q:v', '4', $pOut], 30);
        if ($pr['code'] !== 0 || !is_file($pOut)) {
            $poster = '';
            @unlink($pOut);
        }
        @chmod($out, 0644);

        return ['file' => $name, 'thumb' => $poster, 'bytes' => (int) filesize($out), 'w' => $p['w'], 'h' => $p['h'], 'duration' => (int) ceil($p['duration'])];
    }
}

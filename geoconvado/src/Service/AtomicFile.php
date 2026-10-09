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

final class AtomicFile
{
    public static function write(string $path, string $content): bool
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $content, LOCK_EX) === false) {
            return false;
        }
        @chmod($tmp, 0644);

        if (@rename($tmp, $path)) {
            return true;
        }
        @unlink($tmp);

        return false;
    }

    /** Verschiebt eine fertige Datei atomar an ihren Zielort. */
    public static function publish(string $tmp, string $path): bool
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }
        @chmod($tmp, 0644);

        return @rename($tmp, $path);
    }
}

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

if (!defined('_PS_VERSION_') && !defined('GEOCONVADO_TEST')) {
    exit;
}

/** Ratenbegrenzung nach festem Zeitfenster (je Client und gesamt). */
final class RateLimit
{
    /**
     * @return array{allowed: bool, count: int, retry_after: int}
     */
    public static function hit(KvStore $store, string $key, int $limit, int $window, ?int $now = null): array
    {
        $now ??= time();
        $limit = max(1, $limit);
        $window = max(1, $window);
        $slot = intdiv($now, $window);
        $count = $store->incr('rl:' . $key . ':' . $slot, $window + 5);

        return ['allowed' => $count <= $limit, 'count' => $count, 'retry_after' => max(1, ($slot + 1) * $window - $now)];
    }

    /** Client-Schlüssel ohne Klartext-IP (wird nicht gespeichert, nur gehasht). */
    public static function clientKey(string $ip, string $salt): string
    {
        return substr(hash('sha256', $salt . '|' . $ip), 0, 32);
    }
}

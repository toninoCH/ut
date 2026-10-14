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

/** Kleiner Schlüssel-Wert-Speicher mit Ablaufzeit (Cache und Ratenbegrenzung des Agenten-Zugangs). */
interface KvStore
{
    public function get(string $key): ?string;

    public function set(string $key, string $value, int $ttl): void;

    /** Zähler erhöhen und neuen Stand liefern; der Zähler verfällt nach $ttl Sekunden ab dem ersten Aufruf. */
    public function incr(string $key, int $ttl): int;
}

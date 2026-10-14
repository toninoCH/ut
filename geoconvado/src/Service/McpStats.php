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

/** Tageswerte des MCP-Endpunkts je Werkzeug: Aufrufe, Fehler, Laufzeit; dazu begrenzte Anfragen («_rate_limited»). */
final class McpStats
{
    public const LIMITED = '_rate_limited';

    public static function install(): bool
    {
        return (bool) \Db::getInstance()->execute('CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'geoconvado_mcp_stats` (
            `day` DATE NOT NULL,
            `id_shop` INT UNSIGNED NOT NULL,
            `tool` VARCHAR(32) NOT NULL,
            `calls` INT UNSIGNED NOT NULL DEFAULT 0,
            `errors` INT UNSIGNED NOT NULL DEFAULT 0,
            `ms_total` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `ms_max` INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`day`, `id_shop`, `tool`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4');
    }

    public static function record(int $idShop, string $tool, int $ms, bool $error): void
    {
        try {
            $ms = max(0, $ms);
            \Db::getInstance()->execute('INSERT INTO `' . _DB_PREFIX_ . 'geoconvado_mcp_stats` (`day`, `id_shop`, `tool`, `calls`, `errors`, `ms_total`, `ms_max`)
                VALUES (CURDATE(), ' . max(1, $idShop) . ", '" . pSQL(substr($tool, 0, 32)) . "', 1, " . ($error ? 1 : 0) . ", {$ms}, {$ms})
                ON DUPLICATE KEY UPDATE `calls` = `calls` + 1, `errors` = `errors` + VALUES(`errors`), `ms_total` = `ms_total` + VALUES(`ms_total`), `ms_max` = GREATEST(`ms_max`, VALUES(`ms_max`))");
        } catch (\Throwable) {
            // Messung darf den Endpunkt nie stören
        }
    }

    /** Letzte 30 Tage je Werkzeug. @return list<array<string, mixed>> */
    public static function summary(int $idShop): array
    {
        $rows = \Db::getInstance()->executeS('SELECT `tool`, SUM(`calls`) AS calls, SUM(`errors`) AS errors, SUM(`ms_total`) AS ms_total, MAX(`ms_max`) AS ms_max
            FROM `' . _DB_PREFIX_ . 'geoconvado_mcp_stats` WHERE `id_shop` = ' . max(1, $idShop) . ' AND `day` > DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            GROUP BY `tool` ORDER BY calls DESC') ?: [];

        return array_map(static fn (array $r): array => [
            'tool' => (string) $r['tool'],
            'calls' => (int) $r['calls'],
            'errors' => (int) $r['errors'],
            'avg_ms' => (int) $r['calls'] > 0 ? (int) round((int) $r['ms_total'] / (int) $r['calls']) : 0,
            'max_ms' => (int) $r['ms_max'],
        ], $rows);
    }
}

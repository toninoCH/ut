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
 * KI-Umsatzzuordnung: Warenkörbe aus KI-Besuchen (Referrer) und aus Agenten-Kaufen-Links bis zur Bestellung und
 * zur Zahlung (Bestellung «gültig»). Es werden nur Warenkorb- und Bestellnummern gespeichert, keine Personendaten.
 */
final class Attribution
{
    public const CHANNEL_REFERRAL = 'referral';
    public const CHANNEL_AGENT = 'agent_link';

    public static function install(): bool
    {
        return (bool) \Db::getInstance()->execute('CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'geoconvado_attribution` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_shop` INT UNSIGNED NOT NULL,
            `id_cart` INT UNSIGNED NOT NULL,
            `id_order` INT UNSIGNED NOT NULL DEFAULT 0,
            `source` VARCHAR(32) NOT NULL,
            `channel` VARCHAR(16) NOT NULL,
            `total` DECIMAL(20,6) NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL,
            `ordered_at` DATETIME NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `cart_order` (`id_cart`, `id_order`),
            KEY `shop_created` (`id_shop`, `created_at`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4');
    }

    /** Warenkorb einer Quelle zuordnen. Ein Agenten-Kaufen-Link setzt den Kanal, die erste Quelle bleibt erhalten. */
    public static function markCart(int $idShop, int $idCart, string $source, string $channel): void
    {
        if ($idCart < 1) {
            return;
        }
        try {
            \Db::getInstance()->execute('INSERT INTO `' . _DB_PREFIX_ . 'geoconvado_attribution` (`id_shop`, `id_cart`, `id_order`, `source`, `channel`, `created_at`)
                VALUES (' . max(1, $idShop) . ', ' . $idCart . ", 0, '" . pSQL(substr($source, 0, 32)) . "', '" . pSQL($channel) . "', NOW())
                ON DUPLICATE KEY UPDATE `channel` = IF(VALUES(`channel`) = '" . self::CHANNEL_AGENT . "', VALUES(`channel`), `channel`)");
        } catch (\Throwable) {
            // Zuordnung darf den Einkauf nie stören
        }
    }

    /** Bei der Bestellung: zugeordneten Warenkorb mit Bestellung und Betrag (Shop-Standardwährung) verknüpfen. */
    public static function onOrder(\Order $order): void
    {
        $idCart = (int) $order->id_cart;
        if ($idCart < 1 || (int) $order->id < 1) {
            return;
        }
        try {
            $db = \Db::getInstance();
            $p = _DB_PREFIX_;
            $rate = (float) $order->conversion_rate > 0 ? (float) $order->conversion_rate : 1.0;
            $total = round((float) $order->total_paid_tax_incl / $rate, 6);
            $row = $db->getRow("SELECT `id_shop`, `source`, `channel` FROM `{$p}geoconvado_attribution` WHERE `id_cart` = {$idCart} ORDER BY `id_order` ASC");
            if (!$row) {
                return;
            }
            $db->execute("UPDATE `{$p}geoconvado_attribution` SET `id_order` = " . (int) $order->id . ", `total` = {$total}, `ordered_at` = NOW()
                WHERE `id_cart` = {$idCart} AND `id_order` = 0");
            if ((int) $db->Affected_Rows() === 0) {
                // Ein Warenkorb, mehrere Bestellungen (z. B. getrennte Lieferungen): je Bestellung eine Zeile.
                $db->execute("INSERT IGNORE INTO `{$p}geoconvado_attribution` (`id_shop`, `id_cart`, `id_order`, `source`, `channel`, `total`, `created_at`, `ordered_at`)
                    VALUES (" . (int) $row['id_shop'] . ", {$idCart}, " . (int) $order->id . ", '" . pSQL((string) $row['source']) . "', '" . pSQL((string) $row['channel']) . "', {$total}, NOW(), NOW())");
            }
        } catch (\Throwable) {
            // Zuordnung darf eine Bestellung nie stören
        }
    }

    /**
     * Letzte 30 Tage je Quelle: KI-Besuche, Warenkörbe, Bestellungen, bezahlte Bestellungen, Umsatz.
     *
     * @return array{rows: list<array<string, mixed>>, totals: array<string, mixed>, currency: string}
     */
    public static function summary(int $idShop): array
    {
        $p = _DB_PREFIX_;
        $db = \Db::getInstance();
        $idShop = max(1, $idShop);
        $visits = $db->executeS("SELECT `bot` AS source, SUM(`hits`) AS n FROM `{$p}geoconvado_stats_daily`
            WHERE `id_shop` = {$idShop} AND `kind` = 'ref' AND `day` > DATE_SUB(CURDATE(), INTERVAL 30 DAY) GROUP BY `bot`") ?: [];
        $carts = $db->executeS("SELECT a.`source`, a.`channel`, COUNT(DISTINCT a.`id_cart`) AS carts,
                COUNT(DISTINCT IF(a.`id_order` > 0, a.`id_order`, NULL)) AS orders,
                COUNT(DISTINCT IF(o.`valid` = 1, a.`id_order`, NULL)) AS paid,
                COALESCE(SUM(IF(o.`valid` = 1, a.`total`, 0)), 0) AS revenue
            FROM `{$p}geoconvado_attribution` a LEFT JOIN `{$p}orders` o ON o.`id_order` = a.`id_order` AND a.`id_order` > 0
            WHERE a.`id_shop` = {$idShop} AND a.`created_at` > DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY a.`source`, a.`channel`") ?: [];

        return self::combine($visits, $carts, (string) (new \Currency((int) \Configuration::get('PS_CURRENCY_DEFAULT')))->iso_code);
    }

    /**
     * Besuche und Warenkörbe je Quelle zusammenführen (ohne Datenbank, testbar).
     *
     * @param list<array<string, mixed>> $visits
     * @param list<array<string, mixed>> $carts
     *
     * @return array{rows: list<array<string, mixed>>, totals: array<string, mixed>, currency: string}
     */
    public static function combine(array $visits, array $carts, string $currency): array
    {
        $by = [];
        $blank = ['visits' => 0, 'carts' => 0, 'agent_carts' => 0, 'orders' => 0, 'paid' => 0, 'revenue' => 0.0];
        foreach ($visits as $v) {
            $s = (string) $v['source'];
            $by[$s] = ($by[$s] ?? $blank);
            $by[$s]['visits'] += (int) $v['n'];
        }
        foreach ($carts as $c) {
            $s = (string) $c['source'];
            $by[$s] = ($by[$s] ?? $blank);
            $by[$s]['carts'] += (int) $c['carts'];
            $by[$s]['agent_carts'] += $c['channel'] === self::CHANNEL_AGENT ? (int) $c['carts'] : 0;
            $by[$s]['orders'] += (int) $c['orders'];
            $by[$s]['paid'] += (int) $c['paid'];
            $by[$s]['revenue'] += (float) $c['revenue'];
        }
        $rows = [];
        $t = $blank;
        foreach ($by as $s => $r) {
            $r['revenue'] = round($r['revenue'], 2);
            $r['conversion'] = $r['visits'] > 0 ? round($r['paid'] / $r['visits'] * 100, 1) : null;
            $rows[] = ['source' => $s] + $r;
            foreach (array_keys($blank) as $k) {
                $t[$k] += $r[$k];
            }
        }
        usort($rows, static fn (array $a, array $b): int => [$b['revenue'], $b['visits']] <=> [$a['revenue'], $a['visits']]);
        $t['revenue'] = round($t['revenue'], 2);
        $t['conversion'] = $t['visits'] > 0 ? round($t['paid'] / $t['visits'] * 100, 1) : null;

        return ['rows' => $rows, 'totals' => $t, 'currency' => $currency];
    }
}

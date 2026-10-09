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

/** Wer darf welchen Artikel bewerten? Nur eingeloggte Kunden mit versendeter, nicht zurückgegebener Bestellung. */
final class ReviewEligibility
{
    public const OK = 'ok';

    /**
     * Prüft, ob der Kunde den Artikel bewerten darf.
     * Gründe (reason): ok, login, no_order, too_early, returned, already.
     *
     * @return array{ok: bool, reason: string, id_order: int, days: int}
     */
    public static function check(int $idCustomer, int $idProduct, int $idShop): array
    {
        $days = max(0, (int) \Configuration::get('GEOCONVADO_RV_DAYS'));
        $fail = static fn (string $r): array => ['ok' => false, 'reason' => $r, 'id_order' => 0, 'days' => $days];
        if ($idCustomer <= 0) {
            return $fail('login');
        }
        $db = \Db::getInstance();
        $p = _DB_PREFIX_;
        if ((int) $db->getValue("SELECT COUNT(*) FROM `{$p}geoconvado_review` WHERE id_shop = $idShop AND id_customer = $idCustomer AND id_product = $idProduct") > 0) {
            return $fail('already');
        }
        $states = self::states();
        if ($states === []) {
            return $fail('no_order');
        }
        $in = implode(',', $states);
        // Bestellungen mit diesem Artikel, die mindestens einmal einen «versendet/geliefert»-Status hatten.
        $rows = $db->executeS("SELECT o.id_order, MIN(oh.date_add) AS shipped_at,
                (od.product_quantity - od.product_quantity_refunded - od.product_quantity_return) AS kept
            FROM `{$p}orders` o
            JOIN `{$p}order_detail` od ON od.id_order = o.id_order AND od.product_id = $idProduct
            JOIN `{$p}order_history` oh ON oh.id_order = o.id_order AND oh.id_order_state IN ($in)
            WHERE o.id_customer = $idCustomer AND o.id_shop = $idShop AND o.valid = 1
            GROUP BY o.id_order, od.product_quantity, od.product_quantity_refunded, od.product_quantity_return
            ORDER BY o.id_order DESC LIMIT 10") ?: [];
        if ($rows === []) {
            return $fail('no_order');
        }
        $limit = time() - $days * 86400;
        $early = false;
        $returned = false;
        foreach ($rows as $r) {
            if ((int) $r['kept'] <= 0) {
                $returned = true;
                continue;
            }
            if ((int) strtotime((string) $r['shipped_at']) > $limit) {
                $early = true;
                continue;
            }

            return ['ok' => true, 'reason' => self::OK, 'id_order' => (int) $r['id_order'], 'days' => $days];
        }

        return $fail($early ? 'too_early' : ($returned ? 'returned' : 'no_order'));
    }

    /** Status, die als «Artikel unterwegs oder angekommen» gelten (einstellbar, Standard: versendet und geliefert). */
    public static function states(): array
    {
        $cfg = json_decode((string) \Configuration::get('GEOCONVADO_RV_STATES'), true);
        if (!is_array($cfg) || $cfg === []) {
            $cfg = [(int) \Configuration::get('PS_OS_SHIPPING'), (int) \Configuration::get('PS_OS_DELIVERED')];
        }

        return array_values(array_unique(array_filter(array_map('intval', $cfg))));
    }
}

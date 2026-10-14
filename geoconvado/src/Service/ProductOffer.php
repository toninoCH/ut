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

/** Varianten eines Produkts mit Preis, Lager und Bestellbarkeit, so wie sie der Kunde im Shop sieht. */
final class ProductOffer
{
    /**
     * @return list<array{combination: int, options: list<string>, price: float, quantity: int, orderable: bool, backorder: bool, minimal_quantity: int, reference: string, ean13: string, default: bool}>
     */
    public static function variants(\Product $p, int $idLang, int $idShop): array
    {
        $id = (int) $p->id;
        $backorder = (bool) \Product::isAvailableWhenOutOfStock((int) \StockAvailable::outOfStock($id, $idShop));
        $forOrder = (bool) $p->available_for_order;
        $byIpa = [];
        // Eine Zeile je Variante und Attributgruppe (nur so kommen alle Optionen wie «Switch» und «Layout» mit).
        foreach ((array) $p->getAttributeCombinations($idLang, true) as $row) {
            $ipa = (int) $row['id_product_attribute'];
            if (!isset($byIpa[$ipa])) {
                $byIpa[$ipa] = ['options' => [], 'minimal_quantity' => (int) ($row['minimal_quantity'] ?? 1), 'reference' => (string) ($row['reference'] ?? ''),
                    'ean13' => (string) ($row['ean13'] ?? ''), 'default' => (int) ($row['default_on'] ?? 0) === 1];
            }
            $byIpa[$ipa]['options'][] = (string) $row['group_name'] . ': ' . (string) $row['attribute_name'];
        }
        if ($byIpa === []) {
            $qty = (int) \StockAvailable::getQuantityAvailableByProduct($id, 0, $idShop);

            return [[
                'combination' => 0, 'options' => [], 'price' => round((float) \Product::getPriceStatic($id, true, null, 2), 2),
                'quantity' => $qty, 'orderable' => $forOrder && ($qty > 0 || $backorder), 'backorder' => $backorder,
                'minimal_quantity' => max(1, (int) $p->minimal_quantity), 'reference' => (string) $p->reference, 'ean13' => (string) $p->ean13, 'default' => true,
            ]];
        }
        $out = [];
        foreach ($byIpa as $ipa => $v) {
            $qty = (int) \StockAvailable::getQuantityAvailableByProduct($id, $ipa, $idShop);
            $out[] = [
                'combination' => $ipa, 'options' => $v['options'], 'price' => round((float) \Product::getPriceStatic($id, true, $ipa, 2), 2),
                'quantity' => $qty, 'orderable' => $forOrder && ($qty > 0 || $backorder), 'backorder' => $backorder,
                'minimal_quantity' => max(1, $v['minimal_quantity']), 'reference' => $v['reference'] !== '' ? $v['reference'] : (string) $p->reference,
                'ean13' => $v['ean13'], 'default' => $v['default'],
            ];
        }

        return $out;
    }

    /** Produkt laden, wenn ein Kunde es im Shop sehen und bestellen dürfte (Preis sichtbar, kein Katalogmodus). */
    public static function load(int $id, int $idLang, int $idShop): ?\Product
    {
        if ($id <= 0) {
            return null;
        }
        $p = new \Product($id, true, $idLang, $idShop);
        if (!\Validate::isLoadedObject($p) || !$p->active || !$p->available_for_order || !$p->show_price || $p->visibility === 'none'
            || (int) \Configuration::get('PS_CATALOG_MODE') || !\Group::getCurrent()->show_prices) {
            return null;
        }

        return $p;
    }
}

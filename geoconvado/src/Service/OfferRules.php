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

/**
 * Regeln für den Kauf genau einer Variante (ohne Datenbank, testbar). Eine ungültige oder fehlende Variante führt
 * nie zu einer Ersatzvariante, sondern zu einem Fehler mit der Liste der gültigen Varianten.
 *
 * Variante: combination (0 = Produkt ohne Varianten), options, price, quantity, orderable, backorder, minimal_quantity.
 */
final class OfferRules
{
    /**
     * @param list<array<string, mixed>> $variants
     *
     * @return array{ok: bool, code?: string, error?: string, variant?: array<string, mixed>, variants?: list<array<string, mixed>>}
     */
    public static function choose(array $variants, ?int $combination, int $qty): array
    {
        $hasVariants = false;
        foreach ($variants as $v) {
            if ((int) $v['combination'] > 0) {
                $hasVariants = true;
                break;
            }
        }
        $fail = static fn (string $code, string $msg): array => ['ok' => false, 'code' => $code, 'error' => $msg, 'variants' => self::brief($variants)];
        if ($variants === []) {
            return $fail('not_orderable', 'Produkt nicht bestellbar');
        }
        if ($hasVariants && !$combination) {
            return $fail('combination_required', 'Dieses Produkt hat Varianten: combination angeben (siehe variants)');
        }
        $chosen = null;
        foreach ($variants as $v) {
            if ((int) $v['combination'] === (int) ($combination ?? 0)) {
                $chosen = $v;
                break;
            }
        }
        if ($chosen === null) {
            return $fail('combination_invalid', $hasVariants ? 'Variante ' . (int) $combination . ' gehört nicht zu diesem Produkt' : 'Dieses Produkt hat keine Varianten: combination weglassen');
        }
        if (empty($chosen['orderable'])) {
            return $fail('not_orderable', 'Variante nicht bestellbar (ausverkauft)');
        }
        $min = max(1, (int) ($chosen['minimal_quantity'] ?? 1));
        if ($qty < $min) {
            return $fail('below_minimum', 'Mindestmenge ist ' . $min);
        }
        if (empty($chosen['backorder']) && $qty > (int) $chosen['quantity']) {
            return $fail('insufficient_stock', 'Nur ' . max(0, (int) $chosen['quantity']) . ' an Lager');
        }

        return ['ok' => true, 'variant' => $chosen];
    }

    /** Kurzfassung für Fehlermeldungen. @param list<array<string, mixed>> $variants @return list<array<string, mixed>> */
    public static function brief(array $variants): array
    {
        return array_map(static fn (array $v): array => [
            'combination' => (int) $v['combination'],
            'options' => array_values((array) ($v['options'] ?? [])),
            'price' => (float) $v['price'],
            'orderable' => (bool) ($v['orderable'] ?? false),
        ], $variants);
    }

    /**
     * Zusammenfassung über alle Varianten: Standardpreis, Preisspanne, Verfügbarkeit.
     *
     * @param list<array<string, mixed>> $variants
     *
     * @return array{price: float, price_min: float, price_max: float, orderable: bool, in_stock: bool, quantity: int, has_variants: bool, variants_count: int, default_combination: int}
     */
    public static function summarize(array $variants): array
    {
        $prices = array_map(static fn (array $v): float => (float) $v['price'], $variants) ?: [0.0];
        $default = $variants[0] ?? ['price' => 0.0, 'combination' => 0];
        foreach ($variants as $v) {
            if (!empty($v['default'])) {
                $default = $v;
                break;
            }
        }
        $orderablePrices = array_map(static fn (array $v): float => (float) $v['price'], array_filter($variants, static fn (array $v): bool => !empty($v['orderable'])));

        return [
            'price' => (float) $default['price'],
            'price_min' => $orderablePrices ? min($orderablePrices) : min($prices),
            'price_max' => $orderablePrices ? max($orderablePrices) : max($prices),
            'orderable' => $orderablePrices !== [],
            'in_stock' => array_filter($variants, static fn (array $v): bool => (int) $v['quantity'] > 0) !== [],
            'quantity' => array_sum(array_map(static fn (array $v): int => max(0, (int) $v['quantity']), $variants)),
            'has_variants' => (int) $default['combination'] > 0,
            'variants_count' => (int) $default['combination'] > 0 ? count($variants) : 0,
            'default_combination' => (int) $default['combination'],
        ];
    }

    /** Einzelner Begriff, der eine EAN/GTIN, Artikelnummer oder MPN sein kann (exakte Suche, günstig über Index). */
    public static function looksLikeCode(string $q): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9._\-\/]{3,64}$/', $q);
    }

    /** Filter auf eine Zusammenfassung anwenden. @param array<string, mixed> $s @param array<string, mixed> $f */
    public static function matches(array $s, array $f): bool
    {
        if (empty($s['orderable'])) {
            return false;
        }
        if (!empty($f['in_stock']) && empty($s['in_stock'])) {
            return false;
        }
        if (isset($f['brand']) && mb_strtolower(trim((string) $f['brand'])) !== mb_strtolower(trim((string) ($s['brand'] ?? '')))) {
            return false;
        }
        if (isset($f['min_price']) && (float) $s['price_max'] < (float) $f['min_price']) {
            return false;
        }
        if (isset($f['max_price']) && (float) $s['price_min'] > (float) $f['max_price']) {
            return false;
        }

        return true;
    }
}

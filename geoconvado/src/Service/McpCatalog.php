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
 * Datenquelle der MCP-Werkzeuge (PrestaShop-Umsetzung: AgentCatalog). Alle Preise inkl. MwSt. in der Shopwährung,
 * alle Angaben variantengenau: Ein Produkt mit Varianten wird nur über eine gültige Variante gekauft.
 */
interface McpCatalog
{
    /**
     * @param array{brand?: string, min_price?: float, max_price?: float, in_stock?: bool} $filters
     *
     * @return list<array<string, mixed>>
     */
    public function search(string $query, int $limit, string $iso, array $filters = []): array;

    /** @return array<string, mixed>|null */
    public function product(int $id, string $iso): ?array;

    /**
     * Kaufen-Link für genau eine Variante und Menge. Ungültige Angaben ergeben einen Fehler, nie eine Ersatzvariante.
     *
     * @return array{ok: bool, error?: string, buy_url?: string, combination?: int, options?: list<string>, quantity?: int, unit_price?: float, total?: float, currency?: string, variants?: list<array<string, mixed>>}
     */
    public function buyLink(int $id, ?int $combination, int $quantity, string $iso): array;

    /** @return array<string, mixed> */
    public function shopInfo(string $iso): array;

    /** Gültige Sprachcodes des Shops. @return list<string> */
    public function languages(): array;
}

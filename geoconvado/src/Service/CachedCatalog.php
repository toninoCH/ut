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
 * Zwischenspeicher vor dem Katalog: Suche, Produkt und Shop-Angaben für kurze Zeit wiederverwenden.
 * Kaufen-Links werden nie zwischengespeichert (Lager und Preis müssen aktuell sein).
 */
final class CachedCatalog implements McpCatalog
{
    /** @param string $scope trennt Shop, Währung und Kundengruppe (andere Preise) */
    public function __construct(private readonly McpCatalog $inner, private readonly KvStore $store, private readonly int $ttl, private readonly string $scope)
    {
    }

    public function search(string $query, int $limit, string $iso, array $filters = []): array
    {
        ksort($filters);

        return $this->remember(['s', mb_strtolower($query), $limit, $iso, $filters], fn (): array => $this->inner->search($query, $limit, $iso, $filters));
    }

    public function product(int $id, string $iso): ?array
    {
        $r = $this->remember(['p', $id, $iso], fn (): array => ['v' => $this->inner->product($id, $iso)]);

        return $r['v'] ?? null;
    }

    public function buyLink(int $id, ?int $combination, int $quantity, string $iso): array
    {
        return $this->inner->buyLink($id, $combination, $quantity, $iso);
    }

    public function shopInfo(string $iso): array
    {
        return $this->remember(['i', $iso], fn (): array => $this->inner->shopInfo($iso));
    }

    public function languages(): array
    {
        return $this->inner->languages();
    }

    /** @param list<mixed> $key @param callable(): array<mixed> $load @return array<mixed> */
    private function remember(array $key, callable $load): array
    {
        if ($this->ttl < 1) {
            return $load();
        }
        $k = 'mc:' . hash('sha256', $this->scope . '|' . json_encode($key));
        $hit = $this->store->get($k);
        if ($hit !== null) {
            $v = json_decode($hit, true);
            if (is_array($v)) {
                return $v;
            }
        }
        $v = $load();
        $this->store->set($k, (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $this->ttl);

        return $v;
    }
}

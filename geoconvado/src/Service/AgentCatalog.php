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

/** Katalogzugriff der MCP-Werkzeuge auf PrestaShop. Nur Produkte, die ein Kunde im Shop auch bestellen könnte. */
final class AgentCatalog implements McpCatalog
{
    /** Höchstens so viele Produkte je Suche laden (Datenbanklast begrenzen). */
    private const MAX_LOAD = 40;
    private const CANDIDATES = 60;

    public function __construct(private readonly \Context $context)
    {
    }

    public function languages(): array
    {
        return array_values(array_map(static fn (array $l): string => strtolower((string) $l['iso_code']), \Language::getLanguages(true, $this->idShop())));
    }

    public function search(string $query, int $limit, string $iso, array $filters = []): array
    {
        $idLang = $this->idLang($iso);
        $out = [];
        $loaded = 0;
        foreach ($this->candidates($query, $idLang) as $id) {
            if ($loaded >= self::MAX_LOAD || count($out) >= $limit) {
                break;
            }
            ++$loaded;
            $p = ProductOffer::load($id, $idLang, $this->idShop());
            if (!$p || !in_array((string) $p->visibility, ['both', 'search'], true)) {
                continue;
            }
            if (!empty($filters['features']) && is_array($filters['features']) && !OfferRules::matchFeatures($this->featureList($p, $idLang), $filters['features'])) {
                continue;
            }
            $s = $this->summary($p, $idLang, ProductOffer::variants($p, $idLang, $this->idShop()));
            if (OfferRules::matches($s, $filters)) {
                $out[] = $s;
            }
        }

        return $out;
    }

    public function product(int $id, string $iso): ?array
    {
        $idLang = $this->idLang($iso);
        $p = ProductOffer::load($id, $idLang, $this->idShop());
        if (!$p) {
            return null;
        }
        $variants = ProductOffer::variants($p, $idLang, $this->idShop());

        return $this->summary($p, $idLang, $variants) + [
            'description_short' => $this->text((string) $p->description_short, 600),
            'description' => $this->text((string) $p->description, 2000),
            'reference' => (string) $p->reference,
            'ean13' => (string) $p->ean13,
            'mpn' => (string) $p->mpn,
            'features' => $this->featureList($p, $idLang),
            'variants' => $variants,
        ];
    }

    /**
     * @return array{ok: bool, code?: string, error?: string, buy_url?: string, product?: string, combination?: int, options?: array<int, string>, quantity?: int, unit_price?: float, total?: float, currency?: string, variant?: array<string, mixed>, variants?: list<array<string, mixed>>}
     */
    public function buyLink(int $id, ?int $combination, int $quantity, string $iso): array
    {
        $idLang = $this->idLang($iso);
        $p = ProductOffer::load($id, $idLang, $this->idShop());
        if (!$p) {
            return ['ok' => false, 'error' => 'Produkt nicht gefunden oder nicht bestellbar'];
        }
        $r = OfferRules::choose(ProductOffer::variants($p, $idLang, $this->idShop()), $combination, $quantity);
        if (!$r['ok']) {
            return $r;
        }
        $v = $r['variant'];
        $ipa = (int) $v['combination'];
        // Stückpreis für genau diese Menge (Mengenrabatte berücksichtigt).
        $unit = round((float) \Product::getPriceStatic($id, true, $ipa ?: null, 2, null, false, true, $quantity), 2);

        return [
            'ok' => true,
            'buy_url' => $this->context->link->getModuleLink('geoconvado', 'buy', ['id' => $id, 'ipa' => $ipa, 'qty' => $quantity], true, $idLang),
            'product' => (string) $p->name,
            'combination' => $ipa,
            'options' => $v['options'],
            'quantity' => $quantity,
            'unit_price' => $unit,
            'total' => round($unit * $quantity, 2),
            'currency' => (string) $this->context->currency->iso_code,
        ];
    }

    public function shopInfo(string $iso): array
    {
        $idShop = $this->idShop();
        $idLang = $this->idLang($iso);
        $base = rtrim($this->context->link->getBaseLink($idShop, true), '/');
        $currency = (string) $this->context->currency->iso_code;

        return [
            'name' => (string) \Configuration::get('PS_SHOP_NAME'),
            'currency' => $currency,
            'languages' => $this->languages(),
            'prices' => 'inkl. MwSt., wie im Shop angezeigt',
            'shipping' => Generator::shippingDetails($idShop, $currency),
            'facts' => Generator::facts($idShop),
            'contact_url' => $this->context->link->getPageLink('contact', true, $idLang),
            'catalog_urls' => ['llms_txt' => $base . '/llms.txt', 'llms_json' => $base . '/llms.json'],
            'searchable_features' => $this->searchableFeatures($idShop, $idLang),
            'checkout' => 'Kein Agenten-Checkout: Der Kunde bestellt und bezahlt selbst im Shop (get_buy_link).',
        ];
    }

    /** @return list<array{name: string, value: string}> */
    private function featureList(\Product $p, int $idLang): array
    {
        $out = [];
        foreach ((array) $p->getFrontFeatures($idLang) as $f) {
            $out[] = ['name' => (string) $f['name'], 'value' => (string) $f['value']];
        }

        return $out;
    }

    /**
     * Die häufigsten Produktmerkmale mit ihren häufigsten Werten (damit ein Agent gültige Filter kennt).
     *
     * @return list<array{name: string, values: list<string>}>
     */
    private function searchableFeatures(int $idShop, int $idLang): array
    {
        $p = _DB_PREFIX_;
        $rows = \Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS("SELECT fl.name AS feature, fvl.value AS value, COUNT(*) AS n
            FROM `{$p}feature_product` fp
            INNER JOIN `{$p}product_shop` ps ON ps.id_product = fp.id_product AND ps.id_shop = {$idShop} AND ps.active = 1 AND ps.visibility IN ('both', 'search')
            INNER JOIN `{$p}feature_lang` fl ON fl.id_feature = fp.id_feature AND fl.id_lang = {$idLang}
            INNER JOIN `{$p}feature_value_lang` fvl ON fvl.id_feature_value = fp.id_feature_value AND fvl.id_lang = {$idLang}
            GROUP BY fp.id_feature, fp.id_feature_value, fl.name, fvl.value
            ORDER BY n DESC
            LIMIT 600") ?: [];
        $values = [];
        $total = [];
        foreach ($rows as $r) {
            $name = trim((string) $r['feature']);
            $value = trim((string) $r['value']);
            if ($name === '' || $value === '') {
                continue;
            }
            $values[$name][] = $value;
            $total[$name] = ($total[$name] ?? 0) + (int) $r['n'];
        }
        arsort($total);
        $out = [];
        foreach (array_slice(array_keys($total), 0, 8) as $name) {
            $out[] = ['name' => (string) $name, 'values' => array_slice(array_values(array_unique($values[$name])), 0, 15)];
        }

        return $out;
    }

    /**
     * Kandidaten in Relevanzreihenfolge: exakte Artikelnummer/EAN, dann PrestaShop-Suche, dann Namens-/Markensuche.
     *
     * @return list<int>
     */
    private function candidates(string $q, int $idLang): array
    {
        $ids = [];
        $p = _DB_PREFIX_;
        $idShop = $this->idShop();
        $db = \Db::getInstance(_PS_USE_SQL_SLAVE_);
        if (OfferRules::looksLikeCode($q)) {
            $c = pSQL($q);
            $rows = $db->executeS("SELECT DISTINCT p.id_product FROM `{$p}product` p
                INNER JOIN `{$p}product_shop` ps ON ps.id_product = p.id_product AND ps.id_shop = {$idShop} AND ps.active = 1
                LEFT JOIN `{$p}product_attribute` pa ON pa.id_product = p.id_product
                WHERE p.ean13 = '{$c}' OR p.reference = '{$c}' OR p.upc = '{$c}' OR p.mpn = '{$c}'
                   OR pa.ean13 = '{$c}' OR pa.reference = '{$c}' OR pa.upc = '{$c}' OR pa.mpn = '{$c}'
                LIMIT 20") ?: [];
            $ids = array_map('intval', array_column($rows, 'id_product'));
        }
        $found = \Search::find($idLang, $q, 1, self::CANDIDATES, 'position', 'desc', false, false, $this->context);
        $rows = is_array($found) ? ($found['result'] ?? $found) : [];
        foreach (is_array($rows) ? $rows : [] as $r) {
            if (is_array($r) && isset($r['id_product'])) {
                $ids[] = (int) $r['id_product'];
            }
        }
        if (count(array_unique($ids)) < 10) {
            // Rückfall, falls der Suchindex unvollständig ist: jedes Wort muss in Name oder Marke vorkommen.
            $words = array_slice(array_filter(preg_split('/\s+/u', $q) ?: [], static fn (string $w): bool => mb_strlen($w) >= 2), 0, 5);
            if ($words) {
                $conds = array_map(static function (string $w): string {
                    $l = pSQL(addcslashes($w, '%_\\'));

                    return "(pl.name LIKE '%{$l}%' OR m.name LIKE '%{$l}%')";
                }, $words);
                $rows = $db->executeS("SELECT ps.id_product FROM `{$p}product_shop` ps
                    INNER JOIN `{$p}product_lang` pl ON pl.id_product = ps.id_product AND pl.id_lang = {$idLang} AND pl.id_shop = {$idShop}
                    INNER JOIN `{$p}product` pr ON pr.id_product = ps.id_product
                    LEFT JOIN `{$p}manufacturer` m ON m.id_manufacturer = pr.id_manufacturer
                    WHERE ps.id_shop = {$idShop} AND ps.active = 1 AND ps.visibility IN ('both', 'search') AND " . implode(' AND ', $conds) . '
                    ORDER BY ps.date_upd DESC LIMIT ' . self::CANDIDATES) ?: [];
                $ids = array_merge($ids, array_map('intval', array_column($rows, 'id_product')));
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * @param list<array<string, mixed>> $variants
     *
     * @return array<string, mixed>
     */
    private function summary(\Product $p, int $idLang, array $variants): array
    {
        $id = (int) $p->id;
        $cover = \Product::getCover($id);

        return [
            'id' => $id,
            'name' => (string) $p->name,
            'brand' => (string) $p->manufacturer_name,
        ] + OfferRules::summarize($variants) + [
            'currency' => (string) $this->context->currency->iso_code,
            'url' => $this->context->link->getProductLink($p, null, null, null, $idLang),
            'image' => !empty($cover['id_image']) ? $this->context->link->getImageLink((string) $p->link_rewrite, $id . '-' . (int) $cover['id_image'], \ImageType::getFormattedName('home')) : '',
        ];
    }

    private function idShop(): int
    {
        return (int) $this->context->shop->id;
    }

    private function idLang(string $iso): int
    {
        $id = (int) \Language::getIdByIso($iso);

        return $id > 0 ? $id : (int) $this->context->language->id;
    }

    private function text(string $html, int $max): string
    {
        $t = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        return mb_strlen($t) > $max ? rtrim(mb_substr($t, 0, $max - 1)) . '…' : $t;
    }
}

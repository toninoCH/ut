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

final class Generator
{
    /** @var \Context Shop-Kontext des Aufrufers (Cron-Controller oder Back-Office) */
    private $context;

    /**
     * @param \Context $context
     */
    /** @var array<int, array<int, array>>|null */
    private ?array $rvStats = null;

    public function __construct($context)
    {
        $this->context = $context;
    }

    private const BATCH = 200;
    private const FULL_MAX_BYTES = 1572864; // 1.5 MB – darüber nur Index
    private const LOW_STOCK = 5;
    private const LOCK = 'geoconvado_build';

    private float $deadline = 0.0;

    /** Einstellung lesen mit Standardwert (fehlender Schlüssel = Verhalten wie vor 1.4.0). */
    private static function opt(string $key, int $default = 1): int
    {
        $v = \Configuration::get($key);

        return $v === false || $v === '' ? $default : (int) $v;
    }

    /** @return int[] */
    private static function ids(string $key): array
    {
        return array_values(array_filter(array_map('intval', (array) json_decode((string) \Configuration::get($key), true))));
    }
    private ?array $aio = null;

    /**
     * Führt Arbeit bis zum Zeitbudget aus.
     *
     * @return array{status: string, done: int, total: int, message: string}
     */
    public function run(int $budgetSeconds = 20, bool $force = false): array
    {
        $this->deadline = microtime(true) + max(5, $budgetSeconds);
        $db = \Db::getInstance();

        $lock = self::LOCK . '_' . substr(md5(_DB_NAME_ . _DB_PREFIX_), 0, 8);
        if ((int) $db->getValue("SELECT GET_LOCK('" . $lock . "', 0)") !== 1) {
            return ['status' => 'locked', 'done' => 0, 'total' => 0, 'message' => 'Ein anderer Lauf ist aktiv.'];
        }

        try {
            $targets = $this->targets();
            $dirty = (int) \Configuration::getGlobalValue('GEOCONVADO_DIRTY') === 1;
            $dirtyAt = (int) \Configuration::getGlobalValue('GEOCONVADO_DIRTY_AT');
            if ($dirty && $dirtyAt === 0) {
                // Altbestand (DIRTY ohne Zeitstempel): als «jetzt geändert» behandeln, sonst bliebe die Änderung unbemerkt.
                $dirtyAt = time();
                \Configuration::updateGlobalValue('GEOCONVADO_DIRTY_AT', $dirtyAt);
            }
            $runStart = time();
            $finished = 0;
            $deferred = 0;

            foreach ($targets as [$idShop, $idLang]) {
                $state = $this->state($idShop, $idLang);
                $stale = !$state['finished_at'] || (time() - strtotime((string) $state['finished_at'])) > 86400;

                $age = $state['finished_at'] ? time() - strtotime((string) $state['finished_at']) : PHP_INT_MAX;
                $startedTs = $state['started_at'] ? (int) strtotime((string) $state['started_at']) : 0;
                // Dieses Ziel ist veraltet, wenn nach seinem letzten Start etwas geändert wurde (oder es nie fertig wurde).
                $changed = $dirty && $dirtyAt >= $startedTs;
                // Selbstheilung: Arbeitsordner fehlt (z. B. nach «Cache leeren») oder Lauf hängt seit über 1 h.
                if ($state['status'] === 'running' && (!is_dir($this->buildDir($idShop, $idLang))
                    || time() - strtotime((string) ($state['started_at'] ?? 'now')) > 3600)) {
                    $state['status'] = 'idle';
                }
                // Nach einem Fehler 15 Min. warten statt bei jedem Aufruf neu bauen.
                if (!$force && $state['status'] === 'error' && $age < 900) {
                    continue;
                }
                if ($state['status'] !== 'running') {
                    $needs = $force || $stale || $changed || in_array($state['status'], ['error', 'idle'], true);
                    if (!$needs) {
                        ++$finished;
                        continue;
                    }
                    // Mindestabstand 30 Min. nur für reine Änderungs-Neuaufbauten. Das Ziel zählt dann NICHT als fertig,
                    // damit die Änderungsmarke erhalten bleibt (Fehler N1 in 1.6.0).
                    if (!$force && !$stale && $state['status'] === 'done' && $age < 1800) {
                        ++$deferred;
                        continue;
                    }
                    $state = $this->start($idShop, $idLang);
                }

                while ($state['status'] === 'running' && microtime(true) < $this->deadline) {
                    $state = $this->batch($state);
                }

                if ($state['status'] === 'done') {
                    ++$finished;
                }
                if (microtime(true) >= $this->deadline) {
                    break;
                }
            }

            if ($finished === count($targets)) {
                // Änderungen während des Laufs nicht verschlucken.
                if ((int) \Configuration::getGlobalValue('GEOCONVADO_DIRTY_AT') < $runStart) {
                    \Configuration::updateGlobalValue('GEOCONVADO_DIRTY', 0);
                }
                if ((int) \Configuration::get('GEOCONVADO_ROBOTS')) {
                    (new RobotsWriter())->ensure(\Tools::getShopDomainSsl(true) . __PS_BASE_URI__);
                } else {
                    (new RobotsWriter())->remove();
                }

                return ['status' => 'done', 'done' => $finished, 'total' => count($targets), 'message' => 'Alle Dateien aktuell.'];
            }

            if ($deferred > 0 && $finished + $deferred === count($targets)) {
                return ['status' => 'waiting', 'done' => $finished, 'total' => count($targets), 'message' => 'Änderungen vorgemerkt, Neuaufbau nach höchstens 30 Min.'];
            }

            return ['status' => 'running', 'done' => $finished, 'total' => count($targets), 'message' => 'Läuft weiter beim nächsten Aufruf.'];
        } finally {
            $db->getValue("SELECT RELEASE_LOCK('" . $lock . "')");
        }
    }

    /** Alle aktiven Shop × Sprache-Kombinationen. */
    public function targets(): array
    {
        $out = [];
        foreach (\Shop::getShops(true, null, true) as $idShop) {
            foreach (\Language::getLanguages(true, (int) $idShop, true) as $idLang) {
                $out[] = [(int) $idShop, (int) $idLang];
            }
        }

        return $out;
    }

    /* ------------------------------------------------------------------ */

    private function start(int $idShop, int $idLang): array
    {
        $dir = $this->buildDir($idShop, $idLang);
        $this->rmdir($dir);
        @mkdir($dir, 0755, true);

        $prev = $this->state($idShop, $idLang)['finished_at'];
        \Db::getInstance()->execute('REPLACE INTO `' . _DB_PREFIX_ . 'geoconvado_state`
            (id_shop, id_lang, status, cursor_id, meta, product_count, bytes, started_at, finished_at, last_error) VALUES ('
            . $idShop . ', ' . $idLang . ", 'running', 0, '{}', 0, 0, '" . date('Y-m-d H:i:s') . "', "
            . ($prev ? "'" . pSQL((string) $prev) . "'" : 'NULL') . ', NULL)');

        return $this->state($idShop, $idLang);
    }

    private function batch(array $state): array
    {
        $idShop = (int) $state['id_shop'];
        $idLang = (int) $state['id_lang'];
        $this->bootContext($idShop, $idLang);
        $meta = json_decode((string) $state['meta'], true) ?: [];
        $meta['cats'] ??= [];

        $ids = \Db::getInstance()->executeS('SELECT p.id_product FROM `' . _DB_PREFIX_ . 'product_shop` p
            WHERE p.id_shop = ' . $idShop . " AND p.active = 1 AND p.visibility IN ('both','catalog','search')
            AND p.id_product > " . (int) $state['cursor_id'] . ' ORDER BY p.id_product ASC LIMIT ' . self::BATCH) ?: [];

        $max = self::opt('GEOCONVADO_MAX_PRODUCTS', 0);
        if ($ids === [] || ($max > 0 && (int) $state['product_count'] >= $max)) {
            return $this->finalize($state, $meta);
        }

        $dir = $this->buildDir($idShop, $idLang);
        $handles = [];
        $count = (int) $state['product_count'];
        $cursor = (int) $state['cursor_id'];

        foreach ($ids as $row) {
            $idProduct = (int) $row['id_product'];
            $cursor = $idProduct;
            try {
                [$slug, $title, $md] = $this->renderProduct($idProduct, $idShop, $idLang);
            } catch (\Throwable $e) {
                continue; // Einzelnes defektes Produkt stoppt den Lauf nie
            }
            if ($md === '' || ($max > 0 && $count >= $max)) {
                continue;
            }
            $meta['cats'][$slug] ??= ['title' => $title, 'count' => 0];
            ++$meta['cats'][$slug]['count'];
            $handles[$slug] ??= @fopen($dir . '/' . $slug . '.md', 'ab');
            if ($handles[$slug] === false) {
                throw new \RuntimeException('Arbeitsordner nicht beschreibbar: ' . $dir);
            }
            fwrite($handles[$slug], $md);
            ++$count;
        }
        foreach ($handles as $h) {
            fclose($h);
        }

        \Db::getInstance()->update('geoconvado_state', [
            'cursor_id' => $cursor,
            'product_count' => $count,
            'meta' => pSQL(json_encode($meta, JSON_UNESCAPED_UNICODE), true),
        ], 'id_shop=' . $idShop . ' AND id_lang=' . $idLang);

        return $this->state($idShop, $idLang);
    }

    private function finalize(array $state, array $meta): array
    {
        $idShop = (int) $state['id_shop'];
        $idLang = (int) $state['id_lang'];
        $iso = (string) \Language::getIsoById($idLang);
        $link = $this->context->link;
        $base = rtrim($link->getBaseLink($idShop), '/');
        $prefix = $this->publicPrefix($idShop);
        $dir = $this->buildDir($idShop, $idLang);
        $aio = $this->aio($idShop);

        $header = $this->header($idShop, $idLang, $aio);
        $cats = $meta['cats'] ?? [];
        uasort($cats, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        // 1) Kategorie-Dateien veröffentlichen
        $catLinks = [];
        $fullBody = '';
        $bytes = 0;
        foreach ($cats as $slug => $c) {
            $src = $dir . '/' . $slug . '.md';
            $body = is_file($src) ? (string) file_get_contents($src) : '';
            $fullBody .= '## ' . $c['title'] . "\n\n" . $body;
            $file = $header . '## ' . $c['title'] . "\n\n" . $body;
            AtomicFile::write($this->outRoot() . $prefix . 'llms/' . $iso . '/' . $slug . '.txt', $file);
            $bytes += strlen($file);
            $catLinks[] = '- [' . $c['title'] . ' (' . $c['count'] . ' Produkte)](' . $base . '/' . $prefix . 'llms/' . $iso . '/' . $slug . '.txt)';
        }

        // 2) Sprach-Index
        $index = $header
            . "## Produktkataloge\n\n" . ($catLinks ? implode("\n", $catLinks) : '- (keine Produkte)') . "\n\n"
            . '- [Vollständiger Katalog](' . $base . '/' . $prefix . 'llms-full-' . $iso . ".txt)\n\n"
            . $this->categoriesSection($idShop, $idLang)
            . $this->brandsSection($idLang)
            . $this->cmsSection($idShop, $idLang)
            . $this->faqSection($aio, $iso)
            . $this->customSection($idLang);

        // 3) Vollkatalog (Grösse begrenzt – grosse Kataloge nur als Index)
        $full = $header . $this->faqSection($aio, $iso) . (strlen($fullBody) <= self::FULL_MAX_BYTES
            ? $fullBody
            : "## Hinweis\n\nDer Katalog ist zu gross für eine Datei. Bitte die Kategorie-Dateien verwenden:\n\n" . implode("\n", $catLinks) . "\n");

        $ok = AtomicFile::write($this->outRoot() . $prefix . 'llms-' . $iso . '.txt', $index)
            && AtomicFile::write($this->outRoot() . $prefix . 'llms-full-' . $iso . '.txt', $full);

        // 4) Root-Index (Standardsprache des Standardshops verlinkt alle Sprachen)
        if ($ok && $idShop === (int) \Configuration::get('PS_SHOP_DEFAULT')) {
            $langs = [];
            foreach (\Language::getLanguages(true, $idShop) as $l) {
                $langs[] = '- [' . $l['name'] . '](' . $base . '/llms-' . $l['iso_code'] . '.txt) · [Vollkatalog](' . $base . '/llms-full-' . $l['iso_code'] . '.txt)';
            }
            if ($idLang === (int) \Configuration::get('PS_LANG_DEFAULT', null, null, $idShop)) {
                AtomicFile::write($this->outRoot() . 'llms.txt', $index . "## Sprachen\n\n" . implode("\n", $langs) . "\n");
                AtomicFile::write($this->outRoot() . 'llms-full.txt', $full);
                AtomicFile::write($this->outRoot() . 'llms.json', $this->json($idShop, $idLang, $base));
            }
        }

        $this->rmdir($dir);
        \Db::getInstance()->update('geoconvado_state', [
            'status' => $ok ? 'done' : 'error',
            'bytes' => $bytes + strlen($index) + strlen($full),
            'finished_at' => date('Y-m-d H:i:s'),
            'last_error' => $ok ? null : pSQL('Schreibrechte im Shop-Verzeichnis fehlen'),
        ], 'id_shop=' . $idShop . ' AND id_lang=' . $idLang, 0, true);

        return $this->state($idShop, $idLang);
    }

    /* --------------------------------------------------------- Rendering */

    /** @return array{0: string, 1: string, 2: string} [slug, Kategorietitel, Markdown] */
    private function renderProduct(int $idProduct, int $idShop, int $idLang): array
    {
        $p = new \Product($idProduct, true, $idLang, $idShop);
        if (!$p->id || !$p->active) {
            return ['other', 'Weitere', ''];
        }
        $link = $this->context->link;
        [$slug, $catTitle, $topId] = $this->topCategory((int) $p->id_category_default, $idLang);
        $cats = self::ids('GEOCONVADO_CAT_IDS');
        if ($cats && !in_array($topId, $cats, true)) {
            return [$slug, $catTitle, ''];
        }

        $price = \Product::getPriceStatic($idProduct, true, null, 2);
        $regular = \Product::getPriceStatic($idProduct, true, null, 2, null, false, false);
        $qty = (int) \StockAvailable::getQuantityAvailableByProduct($idProduct, 0, $idShop);
        $currency = $this->context->currency->iso_code ?? 'CHF';
        $aio = $this->aio($idShop)['products'][$p->reference] ?? null;
        $orderableOos = \Product::isAvailableWhenOutOfStock((int) $p->out_of_stock);
        if (self::opt('GEOCONVADO_EXCL_OOS', 0) && $qty <= 0 && !$orderableOos) {
            return [$slug, $catTitle, ''];
        }

        $lines = ['### ' . $this->esc((string) $p->name), ''];
        $lines[] = '- URL: ' . $link->getProductLink($p, null, null, null, $idLang, $idShop);
        if ($p->available_for_order && ($qty > 0 || \Product::isAvailableWhenOutOfStock((int) $p->out_of_stock))) {
            $ipa = (int) \Product::getDefaultAttribute($idProduct);
            $lines[] = '- Direkt kaufen (KI-Agent): ' . $link->getModuleLink('geoconvado', 'buy', ['id' => $idProduct, 'qty' => 1] + ($ipa ? ['ipa' => $ipa] : []), true, $idLang, $idShop);
        }
        if (self::opt('GEOCONVADO_UPSELL') && self::pricesPublic()) {
            $ups = array_map(fn (array $u): string => $this->esc($u['name']) . ' (' . ($u['show_price'] ? number_format((float) $u['price'], 2, '.', '') . ' ' . $currency . ', ' : '') . $link->getProductLink($u['p'], null, null, null, $idLang, $idShop) . ')', \GeoConvado::upsell($idProduct, $idLang, $idShop, 3));
            if ($ups) {
                $lines[] = '- Passt dazu: ' . implode('; ', $ups);
            }
        }
        if ($p->reference) {
            $lines[] = '- Artikelnummer: ' . $this->esc((string) $p->reference);
        }
        if ($p->manufacturer_name) {
            $lines[] = '- Marke: ' . $this->esc((string) $p->manufacturer_name);
        }
        if (self::opt('GEOCONVADO_F_IMAGE')) {
            $cover = \Product::getCover($idProduct);
            if (!empty($cover['id_image'])) {
                $lines[] = '- Bild: ' . $link->getImageLink((string) $p->link_rewrite, $idProduct . '-' . (int) $cover['id_image'], \ImageType::getFormattedName('large'));
            }
        }
        $showPrice = self::opt('GEOCONVADO_F_PRICE') && self::pricesPublic() && (bool) $p->show_price;
        if ($showPrice) {
            $lines[] = '- Preis: ' . $currency . ' ' . number_format($price, 2, '.', '') . ' (inkl. MwSt.)';
        }
        if ($showPrice && $regular > $price + 0.009) {
            $lines[] = '- Originalpreis: ' . $currency . ' ' . number_format($regular, 2, '.', '') . ' (Aktion −' . round((1 - $price / $regular) * 100) . ' %)';
        }
        if ((int) \Configuration::get('GEOCONVADO_RV_ON')) {
            $this->rvStats ??= [];
            $this->rvStats[$idShop] ??= ReviewRepository::statsAll($idShop);
            $rs = $this->rvStats[$idShop][$idProduct] ?? null;
            if ($rs && $rs['count'] > 0) {
                $lines[] = '- Bewertungen verifizierter Käufer: ' . number_format((float) $rs['avg'], 1, '.', '') . '/5 aus ' . (int) $rs['count']
                    . ' Bewertungen' . ($rs['inc'] > 0 ? ' (davon ' . (int) $rs['inc'] . ' gegen Gutschein eingereicht)' : '') . ' (Quelle: eigener Shop, alle Noten, ungefiltert)';
            }
        }
        if (self::opt('GEOCONVADO_F_STOCK')) {
            $lines[] = '- Verfügbarkeit: ' . $this->availability($qty, (bool) $p->available_for_order, $idLang);
        }
        if ($aio && !empty($aio['rating_avg'])) {
            $lines[] = '- Bewertung: ' . $aio['rating_avg'] . '/5 aus ' . (int) $aio['rating_count'] . ' Kundenbewertungen';
            foreach (array_slice($aio['highlights'] ?? [], 0, 2) as $h) {
                $lines[] = '  - «' . $this->esc((string) $h) . '»';
            }
        }
        if ($aio && !empty($aio['use_cases'])) {
            $lines[] = '- Geeignet für: ' . $this->esc(implode(', ', $aio['use_cases']));
        }
        $meta = trim((string) ($p->meta_description ?: strip_tags((string) $p->description_short)));
        if ($meta !== '' && self::opt('GEOCONVADO_F_META')) {
            $lines[] = '- Beschreibung: ' . $this->esc($this->clip($meta, 400));
        }
        foreach (self::opt('GEOCONVADO_F_FEATURES') ? \Product::getFrontFeaturesStatic($idLang, $idProduct) : [] as $f) {
            $lines[] = '- ' . $this->esc((string) $f['name']) . ': ' . $this->esc((string) $f['value']);
        }
        $variants = [];
        foreach (self::opt('GEOCONVADO_F_VARIANTS') ? (array) $p->getAttributesGroups($idLang) : [] as $a) {
            $variants[(string) $a['group_name']][(string) $a['attribute_name']] = true;
        }
        foreach ($variants as $group => $vals) {
            $lines[] = '- Varianten ' . $this->esc($group) . ': ' . $this->esc(implode(', ', array_keys($vals)));
        }
        if ($variants && $p->available_for_order) {
            $combos = [];
            foreach ((array) $p->getAttributesGroups($idLang) as $a) {
                $combos[(int) $a['id_product_attribute']][] = (string) $a['attribute_name'];
            }
            foreach (array_slice($combos, 0, 20, true) as $ipaId => $names) {
                $vq = (int) \StockAvailable::getQuantityAvailableByProduct($idProduct, $ipaId, $idShop);
                $lines[] = '  - Variante ' . $this->esc(implode(' / ', $names)) . ' (ipa=' . $ipaId . ')'
                    . ($showPrice ? ': ' . $currency . ' ' . number_format(\Product::getPriceStatic($idProduct, true, $ipaId, 2), 2, '.', '') : '')
                    . ', ' . ($vq > 0 ? 'lieferbar' : 'nicht an Lager')
                    . ($vq > 0 ? ', kaufen: ' . $link->getModuleLink('geoconvado', 'buy', ['id' => $idProduct, 'ipa' => $ipaId, 'qty' => 1], true, $idLang, $idShop) : '');
            }
        }
        $sum = $aio['summary'][\Language::getIsoById($idLang)] ?? null;
        if ($sum) {
            $lines[] = '- Neutrale Einschätzung: ' . $this->esc((string) $sum['summary']);
            if (!empty($sum['pros'])) {
                $lines[] = '- Vorteile: ' . $this->esc(implode('; ', $sum['pros']));
            }
            if (!empty($sum['cons'])) {
                $lines[] = '- Nachteile: ' . $this->esc(implode('; ', $sum['cons']));
            }
            if (!empty($sum['best_for'])) {
                $lines[] = '- Ideal für: ' . $this->esc((string) $sum['best_for']);
            }
            if (!empty($sum['not_for'])) {
                $lines[] = '- Weniger geeignet für: ' . $this->esc((string) $sum['not_for']);
            }
        }
        // Produktgenaue FAQs (aus Postfach-Antworten und FAQ-Library, per Artikelnummer verknüpft).
        $faqBlock = '';
        if ((int) \Configuration::get('GEOCONVADO_FAQS') && $aio) {
            $iso = \Language::getIsoById($idLang);
            $pf = array_values(array_filter($aio['faqs'] ?? [], static fn (array $f): bool => ($f['lang'] ?? 'de') === $iso));
            if ($pf) {
                $faqBlock = "\n#### Kundenfragen zu " . $this->esc((string) $p->name) . "\n\n";
                foreach (array_slice($pf, 0, 8) as $faq) {
                    $faqBlock .= '**F: ' . $this->esc(self::scrub((string) $faq['q'])) . "**\n"
                        . 'A: ' . $this->esc($this->clip(self::scrub((string) $faq['a']), 600)) . "\n\n";
                }
            }
        }

        return [$slug, $catTitle, implode("\n", $lines) . "\n" . $faqBlock . "\n"];
    }

    /** Verfügbarkeit mit Schweiz-Vorteil und Knappheit. */
    private function availability(int $qty, bool $orderable, int $idLang): string
    {
        $iso = \Language::getIsoById($idLang);
        $t = match ($iso) {
            'fr' => ['En stock', 'Stock faible (plus que %d)', 'Sur commande', 'Indisponible'],
            'it' => ['Disponibile', 'Scorte limitate (solo %d)', 'Su ordinazione', 'Non disponibile'],
            'en' => ['In stock', 'Low stock (only %d left)', 'Available on order', 'Out of stock'],
            default => ['Auf Lager', 'Nur noch %d Stück', 'Auf Bestellung lieferbar', 'Nicht verfügbar'],
        };
        // Eigener Lagertext (z. B. «Lieferung am nächsten Werktag») nur, wenn der Händler ihn einträgt.
        $own = trim((string) \Configuration::get('GEOCONVADO_STOCK_TEXT', $idLang));
        if ($own !== '') {
            $t[0] .= ' – ' . $own;
        }
        if ($qty > self::LOW_STOCK) {
            return $t[0];
        }
        if ($qty > 0) {
            return $t[0] . ' – ' . sprintf($t[1], $qty);
        }

        return $orderable ? $t[2] : $t[3];
    }

    private function header(int $idShop, int $idLang, array $aio): string
    {
        $name = (string) \Configuration::get('PS_SHOP_NAME', null, null, $idShop);
        $brand = trim((string) \Configuration::get('GEOCONVADO_BRAND', $idLang, null, $idShop));
        if ($brand === '') {
            $brand = trim((string) \Configuration::get('GEOCONVADO_BRAND', (int) \Configuration::get('PS_LANG_DEFAULT'), null, $idShop));
        }
        $out = '# ' . $name . "\n\n";
        $out .= '> ' . ($brand !== '' ? str_replace("\n", "\n> ", $brand) : $name . ' – Online-Shop. Preise inkl. MwSt.') . "\n\n";
        $shop = $aio['shop'] ?? [];
        if ((int) \Configuration::get('GEOCONVADO_REVIEWS') && !empty($shop['rating_avg'])) {
            $out .= '- Shop-Bewertung: ' . $shop['rating_avg'] . '/5 aus ' . (int) $shop['rating_count'] . " Bewertungen\n";
            if (!empty($shop['google_rating_avg'])) {
                $out .= '- Google-Bewertung: ' . $shop['google_rating_avg'] . '/5 aus ' . (int) $shop['google_rating_count'] . ' Bewertungen (Quelle: Google Business Profile, alle Noten, ungefiltert'
                    . (!empty($shop['google_reviews_url']) ? ', prüfbar: ' . $shop['google_reviews_url'] : '') . ")\n";
            }
        }
        $out .= '- Stand: ' . date('Y-m-d H:i') . ' (wird automatisch aktualisiert)' . "\n\n";

        return $out . $this->commerceSection($idShop, $idLang);
    }

    /** Einkaufsfakten + Anleitung für KI-Agenten (maschinenlesbar, Schlüssel: Wert). */
    private function commerceSection(int $idShop, int $idLang): string
    {
        $get = static fn (string $k): string => self::fact($k, $idShop);
        $currency = $this->context->currency->iso_code ?? 'CHF';
        $link = $this->context->link;
        $vat = $get('GEOCONVADO_VAT');
        $rows = array_filter([
            'Accepted-Payment' => $get('GEOCONVADO_PAYMENTS'),
            'Shipping-Zone' => $get('GEOCONVADO_SHIP_ZONE'),
            'Shipping-Cost' => $get('GEOCONVADO_SHIP_COST'),
            'Currency' => $currency,
            'VAT-Included' => $vat !== '' ? $vat . ' %' : '',
            'Prices' => 'Alle Preise inkl. MwSt. in ' . $currency,
            'Checkout' => $link->getPageLink('order', true, $idLang, null, false, $idShop),
            'Cart' => $link->getPageLink('cart', true, $idLang, ['action' => 'show'], false, $idShop),
        ]);
        $out = "## Einkaufen & Bestellen\n\n";
        foreach ($rows as $k => $v) {
            $out .= '- ' . $k . ': ' . $this->esc($v) . "\n";
        }
        $out .= "\n### Anleitung für KI-Agenten\n\n"
            . "1. Produkt wählen und den Link «Direkt kaufen (KI-Agent)» öffnen. Er legt den Artikel in den Warenkorb und öffnet die Kasse.\n"
            . "2. Menge über den Parameter `qty` (1–20), Variante über `ipa` (Kombinations-ID). Mehrere Artikel: Links nacheinander öffnen, der Warenkorb bleibt erhalten.\n"
            . "3. Die Bestellung schliesst immer der Kunde selbst ab (Adresse, Zahlung). Bitte keine Zahlungsdaten erfragen.\n"
            . "4. Lieferung nur innerhalb der Lieferzone. Preise und Lager sind tagesaktuell, massgebend ist die Kasse.\n\n";

        return $out;
    }

    private function customSection(int $idLang): string
    {
        $txt = trim((string) \Configuration::get('GEOCONVADO_CUSTOM', $idLang));
        if ($txt === '') {
            $txt = trim((string) \Configuration::get('GEOCONVADO_CUSTOM', (int) \Configuration::get('PS_LANG_DEFAULT')));
        }

        return $txt === '' ? '' : strip_tags($txt) . "\n\n";
    }

    /** Maschinenlesbare Zusammenfassung für Agenten (Shop, Sprachen, Dateien, Kaufregeln, Aktionen). */
    private function json(int $idShop, int $idLang, string $base): string
    {
        $get = static fn (string $k): string => self::fact($k, $idShop);
        $langs = [];
        foreach (\Language::getLanguages(true, $idShop) as $l) {
            $langs[] = ['iso' => $l['iso_code'], 'name' => $l['name'], 'index' => $base . '/llms-' . $l['iso_code'] . '.txt', 'full' => $base . '/llms-full-' . $l['iso_code'] . '.txt'];
        }
        $link = $this->context->link;
        $data = [
            'schema' => 'https://convado.ch/geo/llms.json/v1',
            'name' => (string) \Configuration::get('PS_SHOP_NAME', null, null, $idShop),
            'url' => $base . '/',
            'updated' => date('c'),
            'llms_txt' => $base . '/llms.txt',
            'languages' => $langs,
            'commerce' => [
                'currency' => $this->context->currency->iso_code ?? 'CHF',
                'prices_include_vat' => true,
                'vat_percent' => $get('GEOCONVADO_VAT') !== '' ? (float) $get('GEOCONVADO_VAT') : null,
                'payment_methods' => array_values(array_filter(array_map('trim', explode(',', $get('GEOCONVADO_PAYMENTS'))))),
                'shipping_zone' => $get('GEOCONVADO_SHIP_ZONE'),
                'shipping' => $get('GEOCONVADO_SHIP_COST'),
            ],
            'actions' => [
                'buy' => [
                    'description' => 'Adds a product to the cart and opens checkout. The customer always completes address and payment.',
                    'url' => $link->getModuleLink('geoconvado', 'buy', [], true, $idLang, $idShop),
                    'params' => ['id' => 'product id (required)', 'qty' => '1-20', 'ipa' => 'combination id (optional)'],
                ],
                'checkout' => $link->getPageLink('order', true, $idLang, null, false, $idShop),
                'cart' => $link->getPageLink('cart', true, $idLang, ['action' => 'show'], false, $idShop),
            ],
            'policy' => 'Never ask the user for payment data. Prices and stock are refreshed automatically; checkout is authoritative.',
        ];

        return (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function categoriesSection(int $idShop, int $idLang): string
    {
        if (!self::opt('GEOCONVADO_INC_CATS')) {
            return '';
        }
        $sel = self::ids('GEOCONVADO_CAT_IDS');
        $rows = \Db::getInstance()->executeS('SELECT c.id_category, cl.name FROM `' . _DB_PREFIX_ . 'category` c
            JOIN `' . _DB_PREFIX_ . 'category_shop` cs ON cs.id_category = c.id_category AND cs.id_shop = ' . $idShop . '
            JOIN `' . _DB_PREFIX_ . 'category_lang` cl ON cl.id_category = c.id_category AND cl.id_lang = ' . $idLang . ' AND cl.id_shop = ' . $idShop . '
            WHERE c.active = 1 AND c.level_depth >= 2 ORDER BY c.level_depth, c.nleft LIMIT 300') ?: [];
        if (!$rows) {
            return '';
        }
        $link = $this->context->link;
        $out = "## Kategorien\n\n";
        foreach ($rows as $r) {
            if ($sel && !in_array((int) $r['id_category'], $sel, true) && !$this->underTop((int) $r['id_category'], $sel)) {
                continue;
            }
            $out .= '- [' . $this->esc((string) $r['name']) . '](' . $link->getCategoryLink((int) $r['id_category'], null, $idLang, null, $idShop) . ")\n";
        }

        return $out . "\n";
    }

    private function brandsSection(int $idLang): string
    {
        $sel = self::ids('GEOCONVADO_BRAND_IDS');
        $brands = self::opt('GEOCONVADO_INC_BRANDS') ? \Manufacturer::getManufacturers(false, $idLang, true) : [];
        if ($sel) {
            $brands = array_filter($brands, static fn (array $b): bool => in_array((int) $b['id_manufacturer'], $sel, true));
        }
        if (!$brands) {
            return '';
        }
        $link = $this->context->link;
        $out = "## Marken\n\n";
        foreach ($brands as $b) {
            $out .= '- [' . $this->esc((string) $b['name']) . '](' . $link->getManufacturerLink((int) $b['id_manufacturer'], null, $idLang) . ")\n";
        }

        return $out . "\n";
    }

    private function cmsSection(int $idShop, int $idLang): string
    {
        $sel = self::ids('GEOCONVADO_CMS_IDS');
        $pages = self::opt('GEOCONVADO_INC_CMS') ? \CMS::getCMSPages($idLang, null, true, $idShop) : [];
        if ($sel) {
            $pages = array_filter($pages, static fn (array $c): bool => in_array((int) $c['id_cms'], $sel, true));
        }
        if (!$pages) {
            return '';
        }
        $link = $this->context->link;
        $out = "## Service & Rechtliches\n\n";
        foreach ($pages as $c) {
            $out .= '- [' . $this->esc((string) $c['meta_title']) . '](' . $link->getCMSLink((int) $c['id_cms'], null, null, $idLang, $idShop) . ')';
            $txt = $this->clip(html_entity_decode(strip_tags((string) ($c['content'] ?? '')), ENT_QUOTES, 'UTF-8'), 300);
            $out .= ($txt !== '' ? ': ' . $this->esc($txt) : '') . "\n";
        }

        return $out . "\n";
    }

    private function faqSection(array $aio, string $iso): string
    {
        if (!(int) \Configuration::get('GEOCONVADO_FAQS')) {
            return '';
        }
        $faqs = array_values(array_filter($aio['faqs'] ?? [], static fn (array $f): bool => ($f['lang'] ?? 'de') === $iso));
        if (!$faqs) {
            return '';
        }
        $out = "## Häufige Fragen\n\n";
        foreach (array_slice($faqs, 0, 40) as $f) {
            $out .= '### ' . $this->esc(self::scrub((string) $f['q'])) . "\n\n" . $this->esc($this->clip(self::scrub((string) $f['a']), 800)) . "\n\n";
        }

        return $out;
    }

    /* ----------------------------------------------------------- Helfer */

    /** Hauptkategorie (Ebene 2) als Datei-Slug. */
    private function topCategory(int $idCategory, int $idLang): array
    {
        static $cache = [];
        $key = $idCategory . '-' . $idLang;
        if (isset($cache[$key])) {
            return $cache[$key];
        }
        $cat = new \Category($idCategory, $idLang);
        $top = $cat;
        foreach ((array) $cat->getParentsCategories($idLang) as $parent) {
            if ((int) $parent['level_depth'] === 2) {
                $top = new \Category((int) $parent['id_category'], $idLang);
                break;
            }
        }
        $title = (string) ($top->name ?: 'Weitere');
        $slug = \Tools::str2url($title) ?: 'weitere';

        return $cache[$key] = [$slug, $title, (int) $top->id];
    }

    /** Liegt die Kategorie unter einer der gewählten Hauptkategorien? */
    private function underTop(int $idCategory, array $tops): bool
    {
        $c = new \Category($idCategory);
        foreach ((array) $c->getParentsCategories() as $parent) {
            if (in_array((int) $parent['id_category'], $tops, true)) {
                return true;
            }
        }

        return false;
    }

    private function aio(int $idShop): array
    {
        if ($this->aio === null) {
            $this->aio = (int) \Configuration::get('GEOCONVADO_REVIEWS') || (int) \Configuration::get('GEOCONVADO_FAQS')
                ? (new AioClient())->fetch($idShop)
                : ['shop' => [], 'products' => [], 'faqs' => [], 'source' => 'off'];
        }

        return $this->aio;
    }

    private function bootContext(int $idShop, int $idLang): void
    {
        $ctx = $this->context;
        if ((int) ($ctx->shop->id ?? 0) !== $idShop) {
            $ctx->shop = new \Shop($idShop);
            \Shop::setContext(\Shop::CONTEXT_SHOP, $idShop);
        }
        if ((int) ($ctx->language->id ?? 0) !== $idLang) {
            $ctx->language = new \Language($idLang);
        }
        if (!$ctx->link) {
            $ctx->link = new \Link();
        }
        if (!$ctx->currency) {
            $ctx->currency = \Currency::getDefaultCurrency();
        }
    }

    public function state(int $idShop, int $idLang): array
    {
        $row = \Db::getInstance()->getRow('SELECT * FROM `' . _DB_PREFIX_ . 'geoconvado_state` WHERE id_shop = ' . $idShop . ' AND id_lang = ' . $idLang);

        return $row ?: ['id_shop' => $idShop, 'id_lang' => $idLang, 'status' => 'idle', 'cursor_id' => 0, 'meta' => '{}', 'product_count' => 0, 'bytes' => 0, 'finished_at' => null, 'last_error' => null];
    }

    private function publicPrefix(int $idShop): string
    {
        return count(\Shop::getShops(true, null, true)) > 1 && $idShop !== (int) \Configuration::get('PS_SHOP_DEFAULT')
            ? 'llms/shop' . $idShop . '/'
            : '';
    }

    /** Ziel: Shop-Hauptordner (statisch) oder /llms-data/ (über Modul ausgeliefert und gezählt). */
    private function outRoot(): string
    {
        return (int) \Configuration::get('GEOCONVADO_TRACK_LLMS') ? _PS_ROOT_DIR_ . '/llms-data/' : _PS_ROOT_DIR_ . '/';
    }

    private function buildDir(int $idShop, int $idLang): string
    {
        // Ausserhalb des Caches, damit «Cache leeren» einen Lauf nie zerstört.
        return _PS_ROOT_DIR_ . '/var/geoconvado/build-' . $idShop . '-' . $idLang;
    }

    private function rmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach ((array) glob($dir . '/*') as $f) {
            is_dir((string) $f) ? $this->rmdir((string) $f) : @unlink((string) $f);
        }
        @rmdir($dir);
    }

    /**
     * Konfigurationswert für Einkaufsinfos. Stammen die Werte noch aus den Vorgaben alter Versionen (Prüfmarke gesetzt),
     * werden sie nicht veröffentlicht, bis der Händler sie im Backoffice bestätigt hat.
     */
    private static function fact(string $key, int $idShop): string
    {
        if (in_array($key, ['GEOCONVADO_PAYMENTS', 'GEOCONVADO_SHIP_ZONE', 'GEOCONVADO_SHIP_COST', 'GEOCONVADO_VAT'], true)
            && (int) \Configuration::get('GEOCONVADO_FACTS_REVIEW') === 1) {
            return '';
        }

        return trim((string) \Configuration::get($key, null, null, $idShop));
    }

    /** Preise nur, wenn der Shop sie öffentlich zeigt (kein Katalogmodus, Besuchergruppe sieht Preise). */
    public static function pricesPublic(): bool
    {
        if ((int) \Configuration::get('PS_CATALOG_MODE')) {
            return false;
        }
        $g = new \Group((int) \Configuration::get('PS_UNIDENTIFIED_GROUP'));

        return !\Validate::isLoadedObject($g) || (bool) $g->show_prices;
    }

    /** Entfernt E-Mail-Adressen, Telefonnummern und Steuerzeichen aus Freitext. */
    public static function scrub(string $s): string
    {
        $s = (string) preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[E-Mail entfernt]', $s);
        $s = (string) preg_replace('/(\+|00)?\d[\d \/.-]{8,}\d/', '[Nummer entfernt]', $s);

        return trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', strip_tags($s)));
    }

    /** Löscht alle erzeugten llms-Dateien (Deinstallation). */
    public function removeOutput(): void
    {
        foreach ([_PS_ROOT_DIR_ . '/', _PS_ROOT_DIR_ . '/llms-data/'] as $root) {
            foreach (array_merge(glob($root . 'llms*.txt') ?: [], glob($root . 'llms.json') ?: []) as $f) {
                @unlink($f);
            }
        }
        foreach ([_PS_ROOT_DIR_ . '/llms', _PS_ROOT_DIR_ . '/llms-data', _PS_ROOT_DIR_ . '/var/geoconvado'] as $d) {
            if (is_dir($d)) {
                $this->rmdir($d);
            }
        }
    }

    private function esc(string $s): string
    {
        return trim(str_replace(["\r", "\n", '[', ']'], [' ', ' ', '(', ')'], strip_tags($s)));
    }

    private function clip(string $s, int $n): string
    {
        $s = trim((string) preg_replace('/\s+/u', ' ', $s));

        return mb_strlen($s) > $n ? rtrim(mb_substr($s, 0, $n - 1)) . '…' : $s;
    }
}

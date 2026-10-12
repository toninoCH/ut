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
    private const SPLIT_KB = 150; // Hauptkategorien darüber werden nach Unterkategorie aufgeteilt (0 = nie)
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
     * Beschriftungen je Sprache (Standard: Deutsch, wortgleich zu den früheren Versionen).
     *
     * @return string Beschriftung in der Sprache $iso, sonst Deutsch
     */
    private static function L(string $key, string $iso): string
    {
        static $t = [
            'buy' => ['de' => 'Direkt kaufen (KI-Agent)', 'en' => 'Buy directly (AI agent)', 'fr' => 'Acheter directement (agent IA)'],
            'related' => ['de' => 'Passt dazu', 'en' => 'Goes well with', 'fr' => 'Se marie avec'],
            'profile' => ['de' => 'Kurzprofil', 'en' => 'Key facts', 'fr' => 'Profil'],
            'sku' => ['de' => 'Artikelnummer', 'en' => 'SKU', 'fr' => 'Référence'],
            'brand' => ['de' => 'Marke', 'en' => 'Brand', 'fr' => 'Marque'],
            'image' => ['de' => 'Bild', 'en' => 'Image', 'fr' => 'Image'],
            'price' => ['de' => 'Preis', 'en' => 'Price', 'fr' => 'Prix'],
            'vat_incl' => ['de' => 'inkl. MwSt.', 'en' => 'incl. VAT', 'fr' => 'TVA incl.'],
            'regular' => ['de' => 'Originalpreis', 'en' => 'Original price', 'fr' => 'Prix d’origine'],
            'sale' => ['de' => 'Aktion', 'en' => 'sale', 'fr' => 'promotion'],
            'avail' => ['de' => 'Verfügbarkeit', 'en' => 'Availability', 'fr' => 'Disponibilité'],
            'part_of' => ['de' => 'Teil %d von %d', 'en' => 'Part %d of %d', 'fr' => 'Partie %d sur %d'],
            'desc' => ['de' => 'Beschreibung', 'en' => 'Description', 'fr' => 'Description'],
            'variants' => ['de' => 'Varianten', 'en' => 'Variants', 'fr' => 'Variantes'],
            'variant' => ['de' => 'Variante', 'en' => 'Variant', 'fr' => 'Variante'],
            'in_stock' => ['de' => 'lieferbar', 'en' => 'in stock', 'fr' => 'disponible'],
            'no_stock' => ['de' => 'nicht an Lager', 'en' => 'out of stock', 'fr' => 'non disponible'],
            'buy_short' => ['de' => 'kaufen', 'en' => 'buy', 'fr' => 'acheter'],
            'use_cases' => ['de' => 'Geeignet für', 'en' => 'Suitable for', 'fr' => 'Convient pour'],
            'summary' => ['de' => 'Neutrale Einschätzung', 'en' => 'Neutral assessment', 'fr' => 'Évaluation neutre'],
            'pros' => ['de' => 'Vorteile', 'en' => 'Pros', 'fr' => 'Avantages'],
            'cons' => ['de' => 'Nachteile', 'en' => 'Cons', 'fr' => 'Inconvénients'],
            'best_for' => ['de' => 'Ideal für', 'en' => 'Ideal for', 'fr' => 'Idéal pour'],
            'not_for' => ['de' => 'Weniger geeignet für', 'en' => 'Less suitable for', 'fr' => 'Moins adapté pour'],
            'rating' => ['de' => 'Bewertung', 'en' => 'Rating', 'fr' => 'Note'],
            'rv_title' => ['de' => 'Bewertungen verifizierter Käufer', 'en' => 'Verified-buyer reviews', 'fr' => 'Avis d’acheteurs vérifiés'],
            'q' => ['de' => 'F', 'en' => 'Q', 'fr' => 'Q'],
            'tagline' => ['de' => ' – Online-Shop. Preise inkl. MwSt.', 'en' => ' – online shop. Prices incl. VAT.', 'fr' => ' – boutique en ligne. Prix TVA incluse.'],
            'g_source' => ['de' => 'Quelle: Google Business Profile, alle Noten, ungefiltert', 'en' => 'Source: Google Business Profile, all ratings, unfiltered', 'fr' => 'Source : profil Google Business, toutes les notes, sans filtre'],
            'verifiable' => ['de' => 'prüfbar', 'en' => 'verifiable', 'fr' => 'vérifiable'],
            'prices_note' => ['de' => 'Alle Preise inkl. MwSt. in %s', 'en' => 'All prices incl. VAT in %s', 'fr' => 'Tous les prix TVA incluse en %s'],
            'too_big' => ['de' => "Hinweis\n\nDer Katalog ist zu gross für eine Datei. Bitte die Kategorie-Dateien verwenden:", 'en' => "Note\n\nThe catalogue is too large for one file. Please use the category files:", 'fr' => "Remarque\n\nLe catalogue est trop volumineux pour un seul fichier. Veuillez utiliser les fichiers par catégorie :"],
            'rv_voucher' => ['de' => 'davon %d gegen Gutschein eingereicht', 'en' => '%d of them submitted in exchange for a voucher', 'fr' => 'dont %d soumis contre un bon d’achat'],
            'rv_source' => ['de' => 'Quelle: eigener Shop, alle Noten, ungefiltert', 'en' => 'Source: own shop, all ratings, unfiltered', 'fr' => 'Source : boutique, toutes les notes, sans filtre'],
            'of' => ['de' => 'aus', 'en' => 'from', 'fr' => 'sur'],
            'reviews' => ['de' => 'Bewertungen', 'en' => 'reviews', 'fr' => 'avis'],
            'customer_reviews' => ['de' => 'Kundenbewertungen', 'en' => 'customer reviews', 'fr' => 'avis clients'],
            'shop_rating' => ['de' => 'Shop-Bewertung', 'en' => 'Shop rating', 'fr' => 'Note de la boutique'],
            'google_rating' => ['de' => 'Google-Bewertung', 'en' => 'Google rating', 'fr' => 'Note Google'],
            'updated' => ['de' => 'Stand', 'en' => 'Updated', 'fr' => 'Mise à jour'],
            'auto_updated' => ['de' => 'wird automatisch aktualisiert', 'en' => 'updated automatically', 'fr' => 'mis à jour automatiquement'],
            'commerce' => ['de' => 'Einkaufen & Bestellen', 'en' => 'Shopping & ordering', 'fr' => 'Achat et commande'],
            'guide' => ['de' => 'Anleitung für KI-Agenten', 'en' => 'Instructions for AI agents', 'fr' => 'Instructions pour agents IA'],
            'catalogs' => ['de' => 'Produktkataloge', 'en' => 'Product catalogues', 'fr' => 'Catalogues de produits'],
            'products' => ['de' => 'Produkte', 'en' => 'products', 'fr' => 'produits'],
            'none_products' => ['de' => '(keine Produkte)', 'en' => '(no products)', 'fr' => '(aucun produit)'],
            'full_catalog' => ['de' => 'Vollständiger Katalog', 'en' => 'Full catalogue', 'fr' => 'Catalogue complet'],
            'categories' => ['de' => 'Kategorien', 'en' => 'Categories', 'fr' => 'Catégories'],
            'brands' => ['de' => 'Marken', 'en' => 'Brands', 'fr' => 'Marques'],
            'service' => ['de' => 'Service & Rechtliches', 'en' => 'Service & legal', 'fr' => 'Service et mentions légales'],
            'faq' => ['de' => 'Häufige Fragen', 'en' => 'Frequently asked questions', 'fr' => 'Questions fréquentes'],
            'customer_questions' => ['de' => 'Kundenfragen zu', 'en' => 'Customer questions about', 'fr' => 'Questions clients sur'],
            'languages' => ['de' => 'Sprachen', 'en' => 'Languages', 'fr' => 'Langues'],
            'full_short' => ['de' => 'Vollkatalog', 'en' => 'Full catalogue', 'fr' => 'Catalogue complet'],
            'guide1' => [
                'de' => 'Produkt wählen und den Link «Direkt kaufen (KI-Agent)» öffnen. Es öffnet sich eine Bestätigungsseite; nach dem Klick auf «In den Warenkorb und zur Kasse» liegt der Artikel im Warenkorb und die Kasse öffnet sich.',
                'en' => 'Choose a product and open its “Buy directly (AI agent)” link. A confirmation page opens; after the customer clicks “Add to cart and checkout”, the item is in the cart and checkout opens.',
                'fr' => 'Choisir un produit et ouvrir le lien « Acheter directement (agent IA) ». Une page de confirmation s’ouvre ; après le clic sur « Ajouter au panier et commander », l’article est dans le panier et la caisse s’ouvre.',
            ],
            'guide2' => [
                'de' => 'Menge über den Parameter `qty` (1–20), Variante über `ipa` (Kombinations-ID). Mehrere Artikel: Links nacheinander öffnen, der Warenkorb bleibt erhalten.',
                'en' => 'Quantity via the `qty` parameter (1–20), variant via `ipa` (combination id). Several items: open the links one after another; the cart is kept.',
                'fr' => 'Quantité via le paramètre `qty` (1–20), variante via `ipa` (id de combinaison). Plusieurs articles : ouvrir les liens l’un après l’autre, le panier est conservé.',
            ],
            'guide3' => [
                'de' => 'Die Bestellung schliesst immer der Kunde selbst ab (Adresse, Zahlung). Bitte keine Zahlungsdaten erfragen.',
                'en' => 'The customer always completes the order themselves (address, payment). Please do not ask for payment data.',
                'fr' => 'Le client finalise toujours la commande lui-même (adresse, paiement). Ne demandez aucune donnée de paiement.',
            ],
            'guide4' => [
                'de' => 'Lieferung nur innerhalb der Lieferzone. Preise und Lager sind tagesaktuell, massgebend ist die Kasse.',
                'en' => 'Delivery only within the delivery zone. Prices and stock are updated daily; the checkout is authoritative.',
                'fr' => 'Livraison uniquement dans la zone de livraison. Prix et stock sont actualisés chaque jour ; la caisse fait foi.',
            ],
        ];

        return $t[$key][$iso] ?? $t[$key]['de'] ?? $key;
    }

    /** Beste Produktbeschreibung: Kurzbeschreibung, Meta-Beschreibung, Langtext. Reine Wiederholung des Namens zählt nicht. */
    private function bestDescription(\Product $p): string
    {
        $name = mb_strtolower(trim((string) $p->name));
        foreach ([(string) $p->description_short, (string) $p->meta_description, (string) $p->description] as $raw) {
            $raw = (string) preg_replace('#</(p|li|div|h[1-6]|tr)>|<br\s*/?>#i', ' ', $raw);
            $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($raw), ENT_QUOTES, 'UTF-8')));
            if (mb_strlen($text) < 40) {
                continue;
            }
            $lower = mb_strtolower($text);
            if ($lower === $name || (mb_strpos($lower, $name) === 0 && mb_strlen($lower) < mb_strlen($name) + 25)) {
                continue;
            }
            // Fast gleich wie der Name (z. B. «… (ANSI)» statt «… (US)») bringt keine Information.
            similar_text(mb_substr($lower, 0, 200), mb_substr($name, 0, 200), $pct);
            if ($pct >= 80 && mb_strlen($lower) < mb_strlen($name) + 40) {
                continue;
            }

            return $text;
        }

        return '';
    }

    /**
     * Kurzprofil aus den Produktmerkmalen: konfigurierte Merkmale (GEOCONVADO_PROFILE_FEATURES, Komma-getrennt),
     * sonst die ersten sechs. Ja/Nein-Werte werden mit dem Merkmalsnamen beschriftet.
     *
     * Einträge sind Merkmals-IDs (sprachunabhängig, empfohlen) oder Merkmalsnamen.
     *
     * @param array<string, array<string, bool>> $feat Merkmale: Name => [Wert => true]
     * @param array<string, int> $featId Merkmalsname => Merkmals-ID
     */
    private static function profileParts(array $feat, array $featId): array
    {
        $want = array_values(array_filter(array_map('trim', explode(',', (string) \Configuration::get('GEOCONVADO_PROFILE_FEATURES')))));
        $pick = [];
        if ($want) {
            $byName = [];
            $byId = [];
            foreach ($feat as $name => $vals) {
                $byName[mb_strtolower((string) $name)] = [(string) $name, $vals];
                if (!empty($featId[$name])) {
                    $byId[(int) $featId[$name]] = [(string) $name, $vals];
                }
            }
            foreach ($want as $w) {
                $hit = ctype_digit($w) ? ($byId[(int) $w] ?? null) : ($byName[mb_strtolower($w)] ?? null);
                if ($hit) {
                    $pick[] = $hit;
                }
            }
        }
        if (!$pick) {
            foreach (array_slice($feat, 0, 6, true) as $name => $vals) {
                $pick[] = [(string) $name, $vals];
            }
        }
        $yesNo = ['ja', 'nein', 'yes', 'no', 'oui', 'non'];
        $out = [];
        foreach ($pick as [$name, $vals]) {
            $v = implode('/', array_map('strval', array_keys($vals)));
            $out[] = in_array(mb_strtolower($v), $yesNo, true) ? $name . ': ' . $v : $v;
        }

        return $out;
    }

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
                $startedTs = !empty($state['started_at']) ? (int) strtotime((string) $state['started_at']) : 0;
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

        $failed = 0;
        $firstError = '';
        foreach ($ids as $row) {
            $idProduct = (int) $row['id_product'];
            $cursor = $idProduct;
            try {
                [$slug, $title, $md] = $this->renderProduct($idProduct, $idShop, $idLang);
            } catch (\Throwable $e) {
                // Einzelnes defektes Produkt stoppt den Lauf nie – scheitern aber ALLE Produkte eines Blocks,
                // liegt ein Umgebungsfehler vor (z. B. fehlender Kontext) und der Lauf darf nicht «fertig» melden.
                ++$failed;
                $firstError = $firstError !== '' ? $firstError : $e->getMessage();
                continue;
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
            // Zusätzlich nach Unterkategorie (Ebene 3): finalize() teilt grosse Hauptkategorien damit auf.
            [$subSlug, $subTitle] = $this->subCategory((int) \Db::getInstance()->getValue('SELECT id_category_default FROM `' . _DB_PREFIX_ . 'product_shop` WHERE id_product = ' . $idProduct . ' AND id_shop = ' . $idShop), $idLang);
            $meta['subs'][$slug][$subSlug] ??= ['title' => $subTitle, 'count' => 0];
            ++$meta['subs'][$slug][$subSlug]['count'];
            $subKey = $slug . '--' . $subSlug;
            $handles[$subKey] ??= @fopen($dir . '/' . $subKey . '.md', 'ab');
            if ($handles[$subKey] === false) {
                throw new \RuntimeException('Arbeitsordner nicht beschreibbar: ' . $dir);
            }
            fwrite($handles[$subKey], $md);
            ++$count;
        }
        foreach ($handles as $h) {
            fclose($h);
        }
        if ($failed > 0 && $failed === count($ids)) {
            $msg = sprintf('Alle %d Produkte eines Blocks sind fehlgeschlagen: %s', $failed, $firstError);
            \PrestaShopLogger::addLog('GEO.Convado: ' . $msg, 3, null, 'geoconvado');
            \Db::getInstance()->update('geoconvado_state', [
                'status' => 'error',
                'last_error' => pSQL(substr($msg, 0, 250)),
            ], 'id_shop=' . $idShop . ' AND id_lang=' . $idLang);

            return $this->state($idShop, $idLang);
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
            $subs = $meta['subs'][$slug] ?? [];
            $limit = self::opt('GEOCONVADO_SPLIT_KB', self::SPLIT_KB) * 1024;
            $name = 'llms/' . $iso . '/' . $slug;
            if ($limit > 0 && count($subs) > 1 && strlen($body) > $limit) {
                // Grosse Hauptkategorie: eine Datei je Unterkategorie, die Hauptkategorie-Datei wird zur Übersicht.
                $subLinks = [];
                foreach ($subs as $subSlug => $sub) {
                    $subSrc = $dir . '/' . $slug . '--' . $subSlug . '.md';
                    $subBody = is_file($subSrc) ? (string) file_get_contents($subSrc) : '';
                    foreach ($this->publishBody($header, $c['title'] . ' › ' . $sub['title'], $subBody, (int) $sub['count'], $name . '--' . $subSlug, $iso, $base, $prefix, $limit, $bytes) as $line) {
                        $subLinks[] = $line;
                        $catLinks[] = $line;
                    }
                }
                $file = $header . '## ' . $c['title'] . "\n\n" . implode("\n", $subLinks) . "\n";
                AtomicFile::write($this->outRoot() . $prefix . $name . '.txt', $file);
                $bytes += strlen($file);
                continue;
            }
            foreach ($this->publishBody($header, $c['title'], $body, (int) $c['count'], $name, $iso, $base, $prefix, $limit, $bytes) as $line) {
                $catLinks[] = $line;
            }
        }

        // 2) Sprach-Index
        $index = $header
            . '## ' . self::L('catalogs', $iso) . "\n\n" . ($catLinks ? implode("\n", $catLinks) : '- ' . self::L('none_products', $iso)) . "\n\n"
            . '- [' . self::L('full_catalog', $iso) . '](' . $base . '/' . $prefix . 'llms-full-' . $iso . ".txt)\n\n"
            . $this->categoriesSection($idShop, $idLang)
            . $this->brandsSection($idLang)
            . $this->cmsSection($idShop, $idLang)
            . $this->faqSection($aio, $iso)
            . $this->customSection($idLang);

        // 3) Vollkatalog (Grösse begrenzt – grosse Kataloge nur als Index)
        $full = $header . $this->faqSection($aio, $iso) . (strlen($fullBody) <= self::FULL_MAX_BYTES
            ? $fullBody
            : '## ' . self::L('too_big', $iso) . "\n\n" . implode("\n", $catLinks) . "\n");

        $ok = AtomicFile::write($this->outRoot() . $prefix . 'llms-' . $iso . '.txt', $index)
            && AtomicFile::write($this->outRoot() . $prefix . 'llms-full-' . $iso . '.txt', $full);

        // 4) Root-Index (Standardsprache des Standardshops verlinkt alle Sprachen)
        if ($ok && $idShop === (int) \Configuration::get('PS_SHOP_DEFAULT')) {
            $langs = [];
            foreach (\Language::getLanguages(true, $idShop) as $l) {
                $langs[] = '- [' . $l['name'] . '](' . $base . '/llms-' . $l['iso_code'] . '.txt) · [' . self::L('full_short', (string) $l['iso_code']) . '](' . $base . '/llms-full-' . $l['iso_code'] . '.txt)';
            }
            if ($idLang === (int) \Configuration::get('PS_LANG_DEFAULT', null, null, $idShop)) {
                AtomicFile::write($this->outRoot() . 'llms.txt', $index . '## ' . self::L('languages', $iso) . "\n\n" . implode("\n", $langs) . "\n");
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

        $iso = (string) (\Language::getIsoById($idLang) ?: 'de');
        $showPrice = self::opt('GEOCONVADO_F_PRICE') && self::pricesPublic() && (bool) $p->show_price;

        // Merkmale einmal laden und gleichnamige zusammenfassen (z. B. mehrere Tastenkappenfarben in einer Zeile).
        $feat = [];
        $featId = [];
        foreach (self::opt('GEOCONVADO_F_FEATURES') ? \Product::getFrontFeaturesStatic($idLang, $idProduct) : [] as $f) {
            $fn = trim((string) $f['name']);
            $fv = trim((string) $f['value']);
            if ($fn !== '' && $fv !== '') {
                $feat[$fn][$fv] = true;
                $featId[$fn] = (int) ($f['id_feature'] ?? 0);
            }
        }

        $lines = ['### ' . $this->esc((string) $p->name), ''];
        // Kurzprofil: die wichtigsten Fakten in einer Zeile – zuerst, damit sie auch bei gekürztem Lesen ankommen.
        $profile = self::profileParts($feat, $featId);
        if ($showPrice) {
            $profile[] = $currency . ' ' . number_format($price, 2, '.', '');
        }
        if (self::opt('GEOCONVADO_F_STOCK')) {
            $profile[] = $this->availability($qty, $p, $idLang);
        }
        if ($profile) {
            $lines[] = '- ' . self::L('profile', $iso) . ': ' . $this->esc(implode(' · ', $profile));
        }
        $lines[] = '- URL: ' . $link->getProductLink($p, null, null, null, $idLang, $idShop);
        if ($p->available_for_order && ($qty > 0 || \Product::isAvailableWhenOutOfStock((int) $p->out_of_stock))) {
            $ipa = (int) \Product::getDefaultAttribute($idProduct);
            $lines[] = '- ' . self::L('buy', $iso) . ': ' . $link->getModuleLink('geoconvado', 'buy', ['id' => $idProduct, 'qty' => 1] + ($ipa ? ['ipa' => $ipa] : []), true, $idLang, $idShop);
        }
        if (self::opt('GEOCONVADO_UPSELL') && self::pricesPublic()) {
            $ups = array_map(fn (array $u): string => $this->esc($u['name']) . ' (' . ($u['show_price'] ? number_format((float) $u['price'], 2, '.', '') . ' ' . $currency . ', ' : '') . $link->getProductLink($u['p'], null, null, null, $idLang, $idShop) . ')', \GeoConvado::upsell($idProduct, $idLang, $idShop, 3));
            if ($ups) {
                $lines[] = '- ' . self::L('related', $iso) . ': ' . implode('; ', $ups);
            }
        }
        if ($p->reference) {
            $lines[] = '- ' . self::L('sku', $iso) . ': ' . $this->esc((string) $p->reference);
        }
        if ($p->manufacturer_name) {
            $lines[] = '- ' . self::L('brand', $iso) . ': ' . $this->esc((string) $p->manufacturer_name);
        }
        // Eindeutige Kennungen (GTIN/EAN, MPN): Grundlage, damit Shopping-Agenten das Produkt zuordnen können.
        $gtin = (string) preg_replace('/\D/', '', (string) $p->ean13);
        if (in_array(strlen($gtin), [8, 12, 13, 14], true) && trim($gtin, '0') !== '') {
            $lines[] = '- GTIN/EAN: ' . $gtin;
        }
        if (trim((string) $p->mpn) !== '') {
            $lines[] = '- MPN: ' . $this->esc(trim((string) $p->mpn));
        }
        if (self::opt('GEOCONVADO_F_IMAGE')) {
            $cover = \Product::getCover($idProduct);
            if (!empty($cover['id_image'])) {
                $lines[] = '- ' . self::L('image', $iso) . ': ' . $link->getImageLink((string) $p->link_rewrite, $idProduct . '-' . (int) $cover['id_image'], \ImageType::getFormattedName('large'));
            }
        }
        if ($showPrice) {
            $lines[] = '- ' . self::L('price', $iso) . ': ' . $currency . ' ' . number_format($price, 2, '.', '') . ' (' . self::L('vat_incl', $iso) . ')';
        }
        if ($showPrice && $regular > $price + 0.009) {
            $lines[] = '- ' . self::L('regular', $iso) . ': ' . $currency . ' ' . number_format($regular, 2, '.', '') . ' (' . self::L('sale', $iso) . ' −' . round((1 - $price / $regular) * 100) . ' %)';
        }
        if ((int) \Configuration::get('GEOCONVADO_RV_ON')) {
            $this->rvStats ??= [];
            $this->rvStats[$idShop] ??= ReviewRepository::statsAll($idShop);
            $rs = $this->rvStats[$idShop][$idProduct] ?? null;
            if ($rs && $rs['count'] > 0) {
                $lines[] = '- ' . self::L('rv_title', $iso) . ': ' . number_format((float) $rs['avg'], 1, '.', '') . '/5 ' . self::L('of', $iso) . ' ' . (int) $rs['count']
                    . ' ' . self::L('reviews', $iso)
                    . ($rs['inc'] > 0 ? ' (' . sprintf(self::L('rv_voucher', $iso), (int) $rs['inc']) . ')' : '')
                    . ' (' . self::L('rv_source', $iso) . ')';
            }
        }
        if (self::opt('GEOCONVADO_F_STOCK')) {
            $lines[] = '- ' . self::L('avail', $iso) . ': ' . $this->availability($qty, $p, $idLang);
        }
        if ($aio && !empty($aio['rating_avg'])) {
            $lines[] = '- ' . self::L('rating', $iso) . ': ' . $aio['rating_avg'] . '/5 ' . self::L('of', $iso) . ' ' . (int) $aio['rating_count'] . ' ' . self::L('customer_reviews', $iso);
            foreach (array_slice($aio['highlights'] ?? [], 0, 2) as $h) {
                $lines[] = '  - «' . $this->esc((string) $h) . '»';
            }
        }
        // «Geeignet für» nur, wenn es echte Einsatzzwecke sind: Besteht die Liste überwiegend aus Merkmalswerten
        // dieses Produkts (z. B. «ABS, Schwarz, MX, Nein, ISO, 88»), ist sie ein Merkmals-Dump und wird nicht ausgegeben.
        $useCases = $aio ? array_values(array_filter(array_map(static fn ($u): string => trim((string) $u), (array) ($aio['use_cases'] ?? [])), static fn (string $u): bool => $u !== '')) : [];
        if ($useCases) {
            $known = [];
            foreach ($feat as $vals) {
                foreach (array_keys($vals) as $val) {
                    $known[mb_strtolower((string) $val)] = true;
                }
            }
            $hits = 0;
            foreach ($useCases as $u) {
                if (isset($known[mb_strtolower($u)]) || preg_match('/^\d+$/', $u)) {
                    ++$hits;
                }
            }
            if ($hits * 2 < count($useCases)) {
                $lines[] = '- ' . self::L('use_cases', $iso) . ': ' . $this->esc(implode(', ', $useCases));
            }
        }
        $desc = self::opt('GEOCONVADO_F_META') ? $this->bestDescription($p) : '';
        if ($desc !== '') {
            $lines[] = '- ' . self::L('desc', $iso) . ': ' . $this->esc($this->clip($desc, 500));
        }
        foreach ($feat as $fn => $vals) {
            $lines[] = '- ' . $this->esc((string) $fn) . ': ' . $this->esc(implode(', ', array_map('strval', array_keys($vals))));
        }
        $variants = [];
        foreach (self::opt('GEOCONVADO_F_VARIANTS') ? (array) $p->getAttributesGroups($idLang) : [] as $a) {
            $variants[(string) $a['group_name']][(string) $a['attribute_name']] = true;
        }
        foreach ($variants as $group => $vals) {
            $lines[] = '- ' . self::L('variants', $iso) . ' ' . $this->esc($group) . ': ' . $this->esc(implode(', ', array_map('strval', array_keys($vals))));
        }
        if ($variants && $p->available_for_order) {
            $combos = [];
            foreach ((array) $p->getAttributesGroups($idLang) as $a) {
                $combos[(int) $a['id_product_attribute']][] = (string) $a['attribute_name'];
            }
            foreach (array_slice($combos, 0, 20, true) as $ipaId => $names) {
                $vq = (int) \StockAvailable::getQuantityAvailableByProduct($idProduct, $ipaId, $idShop);
                $lines[] = '  - ' . self::L('variant', $iso) . ' ' . $this->esc(implode(' / ', $names)) . ' (ipa=' . $ipaId . ')'
                    . ($showPrice ? ': ' . $currency . ' ' . number_format(\Product::getPriceStatic($idProduct, true, $ipaId, 2), 2, '.', '') : '')
                    . ', ' . ($vq > 0 ? self::L('in_stock', $iso) : self::L('no_stock', $iso))
                    . ($vq > 0 ? ', ' . self::L('buy_short', $iso) . ': ' . $link->getModuleLink('geoconvado', 'buy', ['id' => $idProduct, 'ipa' => $ipaId, 'qty' => 1], true, $idLang, $idShop) : '');
            }
        }
        $sum = $aio['summary'][\Language::getIsoById($idLang)] ?? null;
        if ($sum) {
            $lines[] = '- ' . self::L('summary', $iso) . ': ' . $this->esc((string) $sum['summary']);
            if (!empty($sum['pros'])) {
                $lines[] = '- ' . self::L('pros', $iso) . ': ' . $this->esc(implode('; ', $sum['pros']));
            }
            if (!empty($sum['cons'])) {
                $lines[] = '- ' . self::L('cons', $iso) . ': ' . $this->esc(implode('; ', $sum['cons']));
            }
            if (!empty($sum['best_for'])) {
                $lines[] = '- ' . self::L('best_for', $iso) . ': ' . $this->esc((string) $sum['best_for']);
            }
            if (!empty($sum['not_for'])) {
                $lines[] = '- ' . self::L('not_for', $iso) . ': ' . $this->esc((string) $sum['not_for']);
            }
        }
        // Produktgenaue FAQs (aus Postfach-Antworten und FAQ-Library, per Artikelnummer verknüpft).
        $faqBlock = '';
        if ((int) \Configuration::get('GEOCONVADO_FAQS') && $aio) {
            $pf = array_values(array_filter($aio['faqs'] ?? [], static fn (array $f): bool => ($f['lang'] ?? 'de') === $iso));
            if ($pf) {
                $faqBlock = "\n#### " . self::L('customer_questions', $iso) . ' ' . $this->esc((string) $p->name) . "\n\n";
                foreach (array_slice($pf, 0, 8) as $faq) {
                    $faqBlock .= '**' . self::L('q', $iso) . ': ' . $this->esc(self::scrub((string) $faq['q'])) . "**\n"
                        . 'A: ' . $this->esc($this->clip(self::scrub((string) $faq['a']), 600)) . "\n\n";
                }
            }
        }

        return [$slug, $catTitle, implode("\n", $lines) . "\n" . $faqBlock . "\n"];
    }

    /** Text eines Produktfelds in der Sprache (je nach Ladeart String oder Sprach-Array). */
    private static function langText($value, int $idLang): string
    {
        if (is_array($value)) {
            $value = $value[$idLang] ?? '';
        }

        return trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $value)));
    }

    /**
     * Verfügbarkeit mit Lieferzeit des Produkts (Reiter «Stocks»: «Angezeigter Text wenn auf Lager» / «Label when out of stock»),
     * Schweiz-Vorteil und Knappheit. Nicht bestellbar (Bestellungen ablehnen) = «Nicht verfügbar», ohne Datum.
     */
    private function availability(int $qty, $p, int $idLang): string
    {
        $iso = \Language::getIsoById($idLang);
        $t = match ($iso) {
            'fr' => ['En stock', 'Stock faible (plus que %d)', 'Sur commande', 'Indisponible', 'dès le %s'],
            'it' => ['Disponibile', 'Scorte limitate (solo %d)', 'Su ordinazione', 'Non disponibile', 'dal %s'],
            'en' => ['In stock', 'Low stock (only %d left)', 'Available on order', 'Out of stock', 'from %s'],
            default => ['Auf Lager', 'Nur noch %d Stück', 'Auf Bestellung lieferbar', 'Nicht verfügbar', 'ab %s'],
        };
        $orderable = (bool) $p->available_for_order && (\Product::isAvailableWhenOutOfStock((int) $p->out_of_stock) || $qty > 0);

        // Lieferzeit des Produkts; fehlt sie, gilt der globale Lagertext (nur wenn der Händler ihn einträgt).
        $now = self::langText($p->available_now, $idLang);
        $own = $now !== '' ? $now : trim((string) \Configuration::get('GEOCONVADO_STOCK_TEXT', $idLang));
        if ($qty > 0) {
            $out = $own !== '' ? $t[0] . ' – ' . $own : $t[0];

            return $qty > self::LOW_STOCK ? $out : $out . ' – ' . sprintf($t[1], $qty);
        }
        if (!$orderable) {
            return $t[3];
        }
        $out = $t[2];
        $later = self::langText($p->available_later, $idLang);
        $date = (string) $p->available_date;
        if ($later !== '') {
            $out .= ' – ' . rtrim($later, ' :');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && $date > date('Y-m-d')) {
            $out .= ' (' . sprintf($t[4], date('d.m.Y', (int) strtotime($date))) . ')';
        }

        return $out;
    }

    private function header(int $idShop, int $idLang, array $aio): string
    {
        $name = (string) \Configuration::get('PS_SHOP_NAME', null, null, $idShop);
        $brand = trim((string) \Configuration::get('GEOCONVADO_BRAND', $idLang, null, $idShop));
        if ($brand === '') {
            $brand = trim((string) \Configuration::get('GEOCONVADO_BRAND', (int) \Configuration::get('PS_LANG_DEFAULT'), null, $idShop));
        }
        $iso = (string) (\Language::getIsoById($idLang) ?: 'de');
        $out = '# ' . $name . "\n\n";
        $out .= '> ' . ($brand !== '' ? str_replace("\n", "\n> ", $brand) : $name . self::L('tagline', $iso)) . "\n\n";
        $shop = $aio['shop'] ?? [];
        if ((int) \Configuration::get('GEOCONVADO_REVIEWS') && !empty($shop['rating_avg'])) {
            $out .= '- ' . self::L('shop_rating', $iso) . ': ' . $shop['rating_avg'] . '/5 ' . self::L('of', $iso) . ' ' . (int) $shop['rating_count'] . ' ' . self::L('reviews', $iso) . "\n";
            if (!empty($shop['google_rating_avg'])) {
                $out .= '- ' . self::L('google_rating', $iso) . ': ' . $shop['google_rating_avg'] . '/5 ' . self::L('of', $iso) . ' ' . (int) $shop['google_rating_count'] . ' ' . self::L('reviews', $iso)
                    . ' (' . self::L('g_source', $iso)
                    . (!empty($shop['google_reviews_url']) ? ', ' . self::L('verifiable', $iso) . ': ' . $shop['google_reviews_url'] : '') . ")\n";
            }
        }
        $out .= '- ' . self::L('updated', $iso) . ': ' . date('Y-m-d H:i') . ' (' . self::L('auto_updated', $iso) . ')' . "\n\n";

        return $out . $this->commerceSection($idShop, $idLang);
    }

    /** Einkaufsfakten + Anleitung für KI-Agenten (maschinenlesbar, Schlüssel: Wert). */
    private function commerceSection(int $idShop, int $idLang): string
    {
        $get = static fn (string $k): string => self::fact($k, $idShop);
        $iso = (string) (\Language::getIsoById($idLang) ?: 'de');
        $currency = $this->context->currency->iso_code ?? 'CHF';
        $link = $this->context->link;
        $vat = $get('GEOCONVADO_VAT');
        $rows = array_filter([
            'Accepted-Payment' => $get('GEOCONVADO_PAYMENTS'),
            'Shipping-Zone' => $get('GEOCONVADO_SHIP_ZONE'),
            'Shipping-Cost' => $get('GEOCONVADO_SHIP_COST'),
            'Currency' => $currency,
            'VAT-Included' => $vat !== '' ? $vat . ' %' : '',
            'Prices' => sprintf(self::L('prices_note', $iso), $currency),
            'Checkout' => $link->getPageLink('order', true, $idLang, null, false, $idShop),
            'Cart' => $link->getPageLink('cart', true, $idLang, ['action' => 'show'], false, $idShop),
        ]);
        $out = '## ' . self::L('commerce', $iso) . "\n\n";
        foreach ($rows as $k => $v) {
            $out .= '- ' . $k . ': ' . $this->esc($v) . "\n";
        }
        $out .= "\n### " . self::L('guide', $iso) . "\n\n"
            . '1. ' . self::L('guide1', $iso) . "\n"
            . '2. ' . self::L('guide2', $iso) . "\n"
            . '3. ' . self::L('guide3', $iso) . "\n"
            . '4. ' . self::L('guide4', $iso) . "\n\n";

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
                    'description' => 'Opens a confirmation page for the product; once the customer confirms, it adds the product to the cart and opens checkout. The customer always completes address and payment.',
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
        $iso = (string) (\Language::getIsoById($idLang) ?: 'de');
        $sel = self::ids('GEOCONVADO_CAT_IDS');
        // Mit Elternkategorie (gleichnamige Unterkategorien wie «MCHOSE» bleiben unterscheidbar) und Produktanzahl
        // inkl. Unterkategorien; Kategorien ohne sichtbare Produkte entfallen.
        $rows = \Db::getInstance()->executeS('SELECT c.id_category, c.level_depth, cl.name, pl.name AS parent_name,
            (SELECT COUNT(DISTINCT cp.id_product) FROM `' . _DB_PREFIX_ . 'category` c2
                JOIN `' . _DB_PREFIX_ . 'category_product` cp ON cp.id_category = c2.id_category
                JOIN `' . _DB_PREFIX_ . 'product_shop` ps ON ps.id_product = cp.id_product AND ps.id_shop = ' . $idShop . " AND ps.active = 1 AND ps.visibility IN ('both','catalog','search')
                WHERE c2.nleft >= c.nleft AND c2.nright <= c.nright AND c2.active = 1) AS n
            FROM `" . _DB_PREFIX_ . 'category` c
            JOIN `' . _DB_PREFIX_ . 'category_shop` cs ON cs.id_category = c.id_category AND cs.id_shop = ' . $idShop . '
            JOIN `' . _DB_PREFIX_ . 'category_lang` cl ON cl.id_category = c.id_category AND cl.id_lang = ' . $idLang . ' AND cl.id_shop = ' . $idShop . '
            LEFT JOIN `' . _DB_PREFIX_ . 'category_lang` pl ON pl.id_category = c.id_parent AND pl.id_lang = ' . $idLang . ' AND pl.id_shop = ' . $idShop . '
            WHERE c.active = 1 AND c.level_depth >= 2 ORDER BY c.level_depth, c.nleft LIMIT 300') ?: [];
        if (!$rows) {
            return '';
        }
        $link = $this->context->link;
        $out = '## ' . self::L('categories', $iso) . "\n\n";
        foreach ($rows as $r) {
            if ((int) $r['n'] < 1) {
                continue;
            }
            if ($sel && !in_array((int) $r['id_category'], $sel, true) && !$this->underTop((int) $r['id_category'], $sel)) {
                continue;
            }
            $label = (int) $r['level_depth'] >= 3 && (string) $r['parent_name'] !== '' ? $r['parent_name'] . ' › ' . $r['name'] : $r['name'];
            $out .= '- [' . $this->esc((string) $label) . '](' . $link->getCategoryLink((int) $r['id_category'], null, $idLang, null, $idShop) . ') (' . (int) $r['n'] . ' ' . self::L('products', $iso) . ")\n";
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
        $out = '## ' . self::L('brands', (string) (\Language::getIsoById($idLang) ?: 'de')) . "\n\n";
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
        $out = '## ' . self::L('service', (string) (\Language::getIsoById($idLang) ?: 'de')) . "\n\n";
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
        $out = '## ' . self::L('faq', $iso) . "\n\n";
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

    /**
     * Veröffentlicht einen Textkörper als eine Datei. Liegt er über dem Limit, wird er an Produktgrenzen in Teile
     * «<name>-teil-N.txt» zerlegt und <name>.txt zur Übersicht mit Links auf die Teile.
     *
     * @param string $base  Basis-Adresse des Shops
     * @param string $prefix Pfadpräfix der Ausgabe
     *
     * @return string[] Link-Zeilen für den Index
     */
    private function publishBody(string $header, string $title, string $body, int $count, string $name, string $iso, string $base, string $prefix, int $limit, int &$bytes): array
    {
        $products = self::L('products', $iso);
        $parts = [];
        if ($limit > 0 && strlen($body) > $limit) {
            $cur = '';
            foreach (preg_split('/(?=^### )/m', $body, -1, PREG_SPLIT_NO_EMPTY) ?: [$body] as $block) {
                if ($cur !== '' && strlen($cur) + strlen($block) > $limit) {
                    $parts[] = $cur;
                    $cur = '';
                }
                $cur .= $block;
            }
            if ($cur !== '') {
                $parts[] = $cur;
            }
        }
        if (count($parts) < 2) {
            $file = $header . '## ' . $title . "\n\n" . $body;
            AtomicFile::write($this->outRoot() . $prefix . $name . '.txt', $file);
            $bytes += strlen($file);

            return ['- [' . $title . ' (' . $count . ' ' . $products . ')](' . $base . '/' . $prefix . $name . '.txt)'];
        }
        $links = [];
        $total = count($parts);
        foreach ($parts as $i => $part) {
            $label = sprintf(self::L('part_of', $iso), $i + 1, $total);
            $partName = $name . '-teil-' . ($i + 1);
            $file = $header . '## ' . $title . ' – ' . $label . "\n\n" . $part;
            AtomicFile::write($this->outRoot() . $prefix . $partName . '.txt', $file);
            $bytes += strlen($file);
            $links[] = '- [' . $title . ' – ' . $label . ' (' . preg_match_all('/^### /m', $part) . ' ' . $products . ')](' . $base . '/' . $prefix . $partName . '.txt)';
        }
        $overview = $header . '## ' . $title . "\n\n" . implode("\n", $links) . "\n";
        AtomicFile::write($this->outRoot() . $prefix . $name . '.txt', $overview);
        $bytes += strlen($overview);

        return $links;
    }

    /** Unterkategorie (Ebene 3) der Kategorie; ohne Unterkategorie «weitere». @return array{0: string, 1: string} */
    private function subCategory(int $idCategory, int $idLang): array
    {
        static $cache = [];
        $key = $idCategory . '-' . $idLang;
        if (isset($cache[$key])) {
            return $cache[$key];
        }
        $sub = null;
        foreach ((array) (new \Category($idCategory, $idLang))->getParentsCategories($idLang) as $parent) {
            if ((int) $parent['level_depth'] === 3) {
                $sub = $parent;
                break;
            }
        }
        $title = $sub ? (string) $sub['name'] : '';
        $slug = $title !== '' ? (\Tools::str2url($title) ?: 'weitere') : 'weitere';

        return $cache[$key] = [$slug, $title !== '' ? $title : 'Weitere'];
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

        return $row ?: ['id_shop' => $idShop, 'id_lang' => $idLang, 'status' => 'idle', 'cursor_id' => 0, 'meta' => '{}', 'product_count' => 0, 'bytes' => 0, 'started_at' => null, 'finished_at' => null, 'last_error' => null];
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

    /**
     * Ergänzt den Shop-Kontext wie bei einem anonymen Seitenaufruf für den CLI-Cron (Preise, Steuern, Links stimmen mit dem Frontend überein).
     *
     * @param \Context $ctx
     *
     * @return \Context
     */
    public static function prepareCliContext($ctx)
    {
        if (!\Validate::isLoadedObject($ctx->shop)) {
            $ctx->shop = new \Shop((int) \Configuration::get('PS_SHOP_DEFAULT'));
            \Shop::setContext(\Shop::CONTEXT_SHOP, (int) $ctx->shop->id);
        }
        if (!\Validate::isLoadedObject($ctx->language)) {
            $ctx->language = new \Language((int) \Configuration::get('PS_LANG_DEFAULT'));
        }
        if (!\Validate::isLoadedObject($ctx->country)) {
            $ctx->country = new \Country((int) \Configuration::get('PS_COUNTRY_DEFAULT'));
        }
        if (!\Validate::isLoadedObject($ctx->currency)) {
            $ctx->currency = \Currency::getDefaultCurrency();
        }
        if (!$ctx->customer) {
            $ctx->customer = new \Customer();
        }
        // Links wie im Frontend: ohne HTTPS-Serverumgebung würden Bild-Links sonst mit http:// erzeugt.
        $proto = (int) \Configuration::get('PS_SSL_ENABLED') ? 'https://' : 'http://';
        $ctx->link = new \Link($proto, $proto);
        // Anonymer Besucher: PrestaShop legt bei jedem Seitenaufruf einen (noch nicht gespeicherten) Warenkorb an.
        // Ohne ihn verweigert die Preisberechnung (Product::getPriceStatic) die Arbeit.
        if (!is_object($ctx->cart)) {
            $cart = new \Cart();
            $cart->id_lang = (int) $ctx->language->id;
            $cart->id_currency = (int) $ctx->currency->id;
            $cart->id_shop = (int) $ctx->shop->id;
            $cart->id_shop_group = (int) $ctx->shop->id_shop_group;
            $ctx->cart = $cart;
        }

        return $ctx;
    }
}

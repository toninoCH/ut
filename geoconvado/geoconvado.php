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

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';

use GeoConvado\Service\AioClient;
use GeoConvado\Service\AtomicFile;
use GeoConvado\Service\BotDetector;
use GeoConvado\Service\Generator;
use GeoConvado\Service\MediaProcessor;
use GeoConvado\Service\ReviewEligibility;
use GeoConvado\Service\ReviewGuard;
use GeoConvado\Service\ReviewRepository;
use GeoConvado\Service\ReviewText;
use GeoConvado\Service\RobotsWriter;
use GeoConvado\Service\Tracker;
use GeoConvado\Service\VoucherService;

class GeoConvado extends Module
{
    /** Konfigurationsschlüssel (alle mit Präfix, damit sauber deinstallierbar). */
    public const CFG_KEY = 'GEOCONVADO_AIO_KEY';
    public const CFG_BRAND = 'GEOCONVADO_BRAND';
    public const CFG_REVIEWS = 'GEOCONVADO_REVIEWS';
    public const CFG_FAQS = 'GEOCONVADO_FAQS';
    public const CFG_ROBOTS = 'GEOCONVADO_ROBOTS';
    public const CFG_TOKEN = 'GEOCONVADO_CRON_TOKEN';
    public const CFG_DIRTY = 'GEOCONVADO_DIRTY';
    public const CFG_PAYMENTS = 'GEOCONVADO_PAYMENTS';
    public const CFG_SHIP_ZONE = 'GEOCONVADO_SHIP_ZONE';
    public const CFG_SHIP_COST = 'GEOCONVADO_SHIP_COST';
    public const CFG_VAT = 'GEOCONVADO_VAT';
    public const CFG_W_SUMMARY = 'GEOCONVADO_W_SUMMARY';
    public const CFG_W_REVIEWS = 'GEOCONVADO_W_REVIEWS';
    public const CFG_TRACK_LLMS = 'GEOCONVADO_TRACK_LLMS';

    /** Alle Konfigurationsschlüssel (für Deinstallation). */
    public const ALL_KEYS = ['GEOCONVADO_AIO_KEY', 'GEOCONVADO_BRAND', 'GEOCONVADO_REVIEWS', 'GEOCONVADO_FAQS', 'GEOCONVADO_ROBOTS',
        'GEOCONVADO_CRON_TOKEN', 'GEOCONVADO_DIRTY', 'GEOCONVADO_PAYMENTS', 'GEOCONVADO_SHIP_ZONE', 'GEOCONVADO_SHIP_COST',
        'GEOCONVADO_VAT', 'GEOCONVADO_W_SUMMARY', 'GEOCONVADO_W_REVIEWS', 'GEOCONVADO_TRACK_LLMS', 'GEOCONVADO_LAST_CRON',
        'GEOCONVADO_CUSTOM', 'GEOCONVADO_INC_CMS', 'GEOCONVADO_CMS_IDS', 'GEOCONVADO_INC_BRANDS', 'GEOCONVADO_BRAND_IDS',
        'GEOCONVADO_INC_CATS', 'GEOCONVADO_CAT_IDS', 'GEOCONVADO_EXCL_OOS', 'GEOCONVADO_MAX_PRODUCTS', 'GEOCONVADO_F_PRICE',
        'GEOCONVADO_F_STOCK', 'GEOCONVADO_F_IMAGE', 'GEOCONVADO_F_META', 'GEOCONVADO_F_FEATURES', 'GEOCONVADO_F_VARIANTS',
        'GEOCONVADO_BOTS', 'GEOCONVADO_CRON_ON', 'GEOCONVADO_UPSELL', 'GEOCONVADO_DIRTY_AT', 'GEOCONVADO_AIO_PAUSE', 'GEOCONVADO_STOCK_TEXT', 'GEOCONVADO_FACTS_REVIEW',
        'GEOCONVADO_RV_ON', 'GEOCONVADO_RV_DAYS', 'GEOCONVADO_RV_MIN_TEXT', 'GEOCONVADO_RV_MIN_CHARS', 'GEOCONVADO_RV_PHOTO_REQ', 'GEOCONVADO_RV_VOUCHER_ON',
        'GEOCONVADO_RV_VOUCHER_AMOUNT', 'GEOCONVADO_RV_VOUCHER_DAYS', 'GEOCONVADO_RV_VOUCHER_MIN', 'GEOCONVADO_RV_VOUCHER_YEAR_MAX', 'GEOCONVADO_RV_IMG_MAX',
        'GEOCONVADO_RV_VIDEO_ON', 'GEOCONVADO_RV_VIDEO_MB', 'GEOCONVADO_RV_VIDEO_SEC', 'GEOCONVADO_RV_AUTO', 'GEOCONVADO_RV_LD', 'GEOCONVADO_RV_LD_INC',
        'GEOCONVADO_RV_BLOCK', 'GEOCONVADO_RV_STATES', 'GEOCONVADO_RV_PURGE', 'GEOCONVADO_RV_FFMPEG', 'GEOCONVADO_RV_FFPROBE', 'GEOCONVADO_PROFILE_FEATURES', 'GEOCONVADO_SPLIT_KB'];

    /** Standardwerte des Bewertungsprogramms (ab 1.7.0). Das Programm ist bis zur Aktivierung ausgeschaltet. */
    public const RV_DEFAULTS = ['GEOCONVADO_RV_ON' => 0, 'GEOCONVADO_RV_DAYS' => 5, 'GEOCONVADO_RV_MIN_TEXT' => 30, 'GEOCONVADO_RV_MIN_CHARS' => 150,
        'GEOCONVADO_RV_PHOTO_REQ' => 1, 'GEOCONVADO_RV_VOUCHER_ON' => 1, 'GEOCONVADO_RV_VOUCHER_AMOUNT' => 10, 'GEOCONVADO_RV_VOUCHER_DAYS' => 90,
        'GEOCONVADO_RV_VOUCHER_MIN' => 50, 'GEOCONVADO_RV_VOUCHER_YEAR_MAX' => 5, 'GEOCONVADO_RV_IMG_MAX' => 3, 'GEOCONVADO_RV_VIDEO_ON' => 0,
        'GEOCONVADO_RV_VIDEO_MB' => 40, 'GEOCONVADO_RV_VIDEO_SEC' => 30, 'GEOCONVADO_RV_AUTO' => 0, 'GEOCONVADO_RV_LD' => 0, 'GEOCONVADO_RV_LD_INC' => 0,
        'GEOCONVADO_RV_PURGE' => 0, 'GEOCONVADO_RV_BLOCK' => '', 'GEOCONVADO_RV_STATES' => '', 'GEOCONVADO_RV_FFMPEG' => '', 'GEOCONVADO_RV_FFPROBE' => ''];

    /** Schalter ab 1.4.0 mit Standardwert (Standard = bisheriges Verhalten). */
    public const SWITCH_DEFAULTS = ['GEOCONVADO_INC_CMS' => 1, 'GEOCONVADO_INC_BRANDS' => 1, 'GEOCONVADO_INC_CATS' => 1,
        'GEOCONVADO_EXCL_OOS' => 0, 'GEOCONVADO_F_PRICE' => 1, 'GEOCONVADO_F_STOCK' => 1, 'GEOCONVADO_F_IMAGE' => 1,
        'GEOCONVADO_F_META' => 1, 'GEOCONVADO_F_FEATURES' => 1, 'GEOCONVADO_F_VARIANTS' => 1, 'GEOCONVADO_CRON_ON' => 1];

    /** Änderungen, die eine Aktualisierung auslösen (seit 1.6.0). */
    public const CHANGE_HOOKS = ['actionObjectSpecificPriceAddAfter', 'actionObjectSpecificPriceUpdateAfter', 'actionObjectSpecificPriceDeleteAfter',
        'actionObjectCategoryUpdateAfter', 'actionObjectCmsUpdateAfter', 'actionObjectManufacturerUpdateAfter'];

    /** Front-Hooks seit 1.1.0. */
    public const FRONT_HOOKS = ['displayHeader', 'displayProductAdditionalInfo', 'displayFooterProduct', 'displayFooterBefore', 'moduleRoutes'];

    /**
     * Shop-Kontext für den CLI-Cron (cli/cron.php); der Zugriff auf das geschützte Property bleibt in der Modulklasse.
     *
     * @return Context
     */
    public function getShopContext()
    {
        return $this->context;
    }

    public function __construct()
    {
        $this->name = 'geoconvado';
        $this->tab = 'seo';
        $this->version = '1.10.2';
        $this->author = 'Convado AG';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = ['min' => '9.0.0', 'max' => '9.99.99'];

        parent::__construct();

        $this->displayName = $this->trans('GEO.Convado', [], 'Modules.Geoconvado.Admin');
        $this->description = $this->trans('Makes your shop the first choice of ChatGPT, Claude, Perplexity and Gemini: full catalog as llms.txt in all languages with reviews, product FAQs, shipping and payment info and agent checkout links. Shows a neutral AI assessment (pros and cons) and your Google reviews on the product page.', [], 'Modules.Geoconvado.Admin');
        $this->confirmUninstall = $this->trans('Die llms-Dateien und die Einstellungen werden gelöscht. Käuferbewertungen und hochgeladene Medien bleiben erhalten, ausser Sie haben im Reiter «Bewertungen» das Löschen gewählt. Fortfahren?', [], 'Modules.Geoconvado.Admin');
    }

    public function isUsingNewTranslationSystem(): bool
    {
        return true;
    }

    public function install(): bool
    {
        return parent::install()
            && $this->installDb()
            && Configuration::updateValue(self::CFG_TOKEN, bin2hex(random_bytes(24)))
            && Configuration::updateValue(self::CFG_REVIEWS, 1)
            && Configuration::updateValue(self::CFG_FAQS, 1)
            && Configuration::updateValue(self::CFG_ROBOTS, 1)
            && Configuration::updateGlobalValue(self::CFG_DIRTY, 1)
            && $this->installDefaults()
            && $this->registerHook(self::FRONT_HOOKS)
            && $this->registerHook('actionFrontControllerSetMedia')
            && $this->registerHook('actionAdminMetaBeforeWriteRobotsFile')
            && $this->registerHook('actionObjectProductUpdateAfter')
            && $this->registerHook('actionObjectProductAddAfter')
            && $this->registerHook('actionObjectProductDeleteAfter')
            && $this->registerHook('actionUpdateQuantity')
            && $this->registerHook(self::CHANGE_HOOKS)
            && $this->registerHook(['actionDeleteGDPRCustomer', 'actionExportGDPRData']);
    }

    public function uninstall(): bool
    {
        // Käuferbewertungen und Medien sind Kundendaten: sie bleiben erhalten, ausser der Händler hat das Löschen gewählt.
        $purge = (int) Configuration::get('GEOCONVADO_RV_PURGE') === 1;
        foreach (self::ALL_KEYS as $k) {
            Configuration::deleteByName($k);
        }
        (new RobotsWriter())->remove();
        (new Generator($this->context))->removeOutput();
        if ($purge) {
            $this->purgeReviews();
        }
        Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'geoconvado_state`, `' . _DB_PREFIX_ . 'geoconvado_bot_log`, `' . _DB_PREFIX_ . 'geoconvado_review_cache`, `' . _DB_PREFIX_ . 'geoconvado_stats_daily`, `' . _DB_PREFIX_ . 'geoconvado_front`');

        return parent::uninstall();
    }

    /** Alle Bewertungen samt Mediendateien endgültig löschen. */
    private function purgeReviews(): void
    {
        foreach (Db::getInstance()->executeS('SELECT file, thumb, src FROM `' . _DB_PREFIX_ . 'geoconvado_review_media`') ?: [] as $m) {
            foreach (['file', 'thumb', 'src'] as $k) {
                if ((string) $m[$k] !== '') {
                    MediaProcessor::remove((string) $m[$k]);
                }
            }
        }
        ReviewRepository::dropTables();
    }

    /** Standardwerte für Einkaufsinfos und Widgets (im Backoffice änderbar). */
    public function installDefaults(): bool
    {
        // Bewusst leer: Einkaufsinfos erscheinen erst, wenn der Händler sie selbst eingetragen hat.
        return Configuration::updateValue(self::CFG_PAYMENTS, '')
            && Configuration::updateValue(self::CFG_SHIP_ZONE, '')
            && Configuration::updateValue(self::CFG_SHIP_COST, '')
            && Configuration::updateValue(self::CFG_VAT, '')
            && Configuration::updateValue(self::CFG_W_SUMMARY, 1)
            && Configuration::updateValue(self::CFG_W_REVIEWS, 1)
            && Configuration::updateValue('GEOCONVADO_UPSELL', 1)
            && $this->installReviewDefaults()
            && $this->installSwitches();
    }

    /** Bewertungs-Einstellungen nur setzen, wenn sie noch fehlen (Upgrade-sicher). */
    public function installReviewDefaults(): bool
    {
        foreach (self::RV_DEFAULTS as $k => $v) {
            if (Configuration::get($k) === false) {
                Configuration::updateValue($k, $v);
            }
        }

        return true;
    }

    /** Neue Schalter nur setzen, wenn sie noch fehlen (Upgrade-sicher). */
    public function installSwitches(): bool
    {
        foreach (self::SWITCH_DEFAULTS as $k => $v) {
            if (Configuration::get($k) === false) {
                Configuration::updateValue($k, $v);
            }
        }
        if (Configuration::get('GEOCONVADO_MAX_PRODUCTS') === false) {
            Configuration::updateValue('GEOCONVADO_MAX_PRODUCTS', 0);
        }

        return true;
    }

    private function installDb(): bool
    {
        $p = _DB_PREFIX_;
        $e = _MYSQL_ENGINE_;
        $sql = [
            "CREATE TABLE IF NOT EXISTS `{$p}geoconvado_front` (
                `id_shop` INT UNSIGNED NOT NULL,
                `ref` VARCHAR(64) NOT NULL,
                `payload` MEDIUMTEXT NOT NULL,
                PRIMARY KEY (`id_shop`, `ref`)
            ) ENGINE={$e} DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `{$p}geoconvado_state` (
                `id_shop` INT UNSIGNED NOT NULL,
                `id_lang` INT UNSIGNED NOT NULL,
                `status` VARCHAR(16) NOT NULL DEFAULT 'idle',
                `cursor_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `meta` LONGTEXT NULL,
                `product_count` INT UNSIGNED NOT NULL DEFAULT 0,
                `bytes` INT UNSIGNED NOT NULL DEFAULT 0,
                `started_at` DATETIME NULL,
                `finished_at` DATETIME NULL,
                `last_error` VARCHAR(255) NULL,
                PRIMARY KEY (`id_shop`, `id_lang`)
            ) ENGINE={$e} DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `{$p}geoconvado_bot_log` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_shop` INT UNSIGNED NOT NULL DEFAULT 1,
                `bot` VARCHAR(32) NOT NULL,
                `kind` VARCHAR(8) NOT NULL DEFAULT 'page',
                `ip` VARCHAR(45) NOT NULL,
                `user_agent` VARCHAR(255) NULL,
                `path` VARCHAR(255) NOT NULL,
                `id_product` INT UNSIGNED NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`), KEY `bot_date` (`bot`, `created_at`), KEY `created` (`created_at`), KEY `kind_date` (`kind`, `created_at`)
            ) ENGINE={$e} DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `{$p}geoconvado_review_cache` (
                `id_shop` INT UNSIGNED NOT NULL,
                `payload` LONGTEXT NOT NULL,
                `fetched_at` DATETIME NOT NULL,
                PRIMARY KEY (`id_shop`)
            ) ENGINE={$e} DEFAULT CHARSET=utf8mb4",
        ];
        foreach ($sql as $q) {
            if (!Db::getInstance()->execute($q)) {
                return false;
            }
        }

        return $this->installStatsTable() && ReviewRepository::install();
    }

    /** Tageswerte (unbegrenzt aufbewahrt, klein). */
    public function installStatsTable(): bool
    {
        return Db::getInstance()->execute('CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'geoconvado_stats_daily` (
            `day` DATE NOT NULL,
            `id_shop` INT UNSIGNED NOT NULL DEFAULT 1,
            `bot` VARCHAR(32) NOT NULL,
            `kind` VARCHAR(8) NOT NULL,
            `hits` INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`day`, `id_shop`, `bot`, `kind`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4');
    }

    /** llms-Adressen über das Modul ausliefern (nur bei aktiver Zählung). */
    public function hookModuleRoutes(): array
    {
        if (!(int) Configuration::get(self::CFG_TRACK_LLMS)) {
            return [];
        }

        return ['module-geoconvado-llms' => [
            'controller' => 'serve',
            'rule' => 'llms{path}.txt',
            'keywords' => ['path' => ['regexp' => '[a-z0-9/\\-]*', 'param' => 'path']],
            'params' => ['fc' => 'module', 'module' => $this->name],
        ], 'module-geoconvado-llmsjson' => [
            'controller' => 'serve',
            'rule' => 'llms.json',
            'keywords' => [],
            'params' => ['fc' => 'module', 'module' => $this->name, 'format' => 'json'],
        ]];
    }

    /** Backoffice: Weiterleitung auf die Symfony-Konfigurationsseite. */
    public function getContent(): void
    {
        $router = $this->get('router');
        Tools::redirectAdmin($router->generate('geoconvado_config'));
    }

    /** Leichtgewichtiges Bot-Tracking (nur bekannte KI-Crawler, keine Kunden). */
    public function hookActionFrontControllerSetMedia(): void
    {
        $bot = BotDetector::detect((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        if ($bot === null) {
            return;
        }
        $c = $this->context->controller;
        $idProduct = ($c->php_self ?? '') === 'product' ? (int) Tools::getValue('id_product') : 0;
        Tracker::log((int) $this->context->shop->id, $bot, 'page', (string) ($_SERVER['REQUEST_URI'] ?? '/'), $idProduct);
    }

    /** Wird PrestaShop's robots.txt neu geschrieben, fügt der nächste Cron den GEO-Block wieder an. */
    public function hookActionAdminMetaBeforeWriteRobotsFile(array $params): void
    {
        self::markDirty();
    }

    /** Markiert «Änderung vorhanden» nur einmal (kein Schreibzugriff pro Bestellung) und merkt sich den Zeitpunkt. */
    public static function markDirty(): void
    {
        if ((int) Configuration::getGlobalValue(self::CFG_DIRTY) !== 1) {
            Configuration::updateGlobalValue(self::CFG_DIRTY, 1);
        }
        Configuration::updateGlobalValue('GEOCONVADO_DIRTY_AT', time());
    }

    public function hookActionObjectSpecificPriceAddAfter(): void
    {
        self::markDirty();
    }

    public function hookActionObjectSpecificPriceUpdateAfter(): void
    {
        self::markDirty();
    }

    public function hookActionObjectSpecificPriceDeleteAfter(): void
    {
        self::markDirty();
    }

    public function hookActionObjectCategoryUpdateAfter(): void
    {
        self::markDirty();
    }

    public function hookActionObjectCmsUpdateAfter(): void
    {
        self::markDirty();
    }

    public function hookActionObjectManufacturerUpdateAfter(): void
    {
        self::markDirty();
    }

    /** Jede Katalogänderung markiert die Dateien als veraltet. */
    public function hookActionObjectProductUpdateAfter(): void
    {
        self::markDirty();
    }

    public function hookActionObjectProductAddAfter(): void
    {
        self::markDirty();
    }

    public function hookActionObjectProductDeleteAfter(): void
    {
        self::markDirty();
    }

    public function hookActionUpdateQuantity(): void
    {
        self::markDirty();
    }

    /* ------------------------------------------------ Front-Widgets */

    /**
     * «Passt dazu»: zuerst gepflegtes PrestaShop-Zubehör, sonst Bestseller der Standardkategorie.
     * Nur aktive, lieferbare Artikel; deterministisch, kein KI-Aufruf.
     * Die Kategorie-Bestsellerliste wird 6 Stunden zwischengespeichert (eine Abfrage statt einer pro Seitenaufruf).
     */
    public static function upsell(int $idProduct, int $idLang, int $idShop, int $limit = 4): array
    {
        static $cand = [];
        $idShop = max(1, $idShop);
        $db = Db::getInstance();
        $ids = array_map('intval', array_column($db->executeS('SELECT a.`id_product_2` FROM `' . _DB_PREFIX_ . 'accessory` a
            JOIN `' . _DB_PREFIX_ . 'product_shop` ps ON ps.`id_product` = a.`id_product_2` AND ps.`id_shop` = ' . $idShop . '
            WHERE a.`id_product_1` = ' . $idProduct . ' AND ps.`active` = 1 AND ps.`available_for_order` = 1
            ORDER BY a.`id_product_2` LIMIT 12') ?: [], 'id_product_2'));
        if (count($ids) < $limit) {
            $cat = (int) $db->getValue('SELECT `id_category_default` FROM `' . _DB_PREFIX_ . 'product_shop`
                WHERE `id_product` = ' . $idProduct . ' AND `id_shop` = ' . $idShop);
            if ($cat > 0) {
                $ids = array_merge($ids, self::topSellers($cat, $idShop));
            }
        }
        $out = [];
        foreach (array_unique($ids) as $id) {
            if ($id === $idProduct || count($out) >= $limit) {
                continue;
            }
            $k = $idShop . '-' . $id . '-' . $idLang;
            if (!isset($cand[$k])) {
                $p = new Product($id, false, $idLang, $idShop);
                $ok = Validate::isLoadedObject($p) && $p->active && $p->available_for_order
                    && ((int) StockAvailable::getQuantityAvailableByProduct($id, 0, $idShop) > 0 || Product::isAvailableWhenOutOfStock((int) $p->out_of_stock));
                $cand[$k] = $ok ? ['id' => $id, 'name' => (string) $p->name, 'price' => (float) Product::getPriceStatic($id, true, null, 2), 'show_price' => (bool) $p->show_price, 'p' => $p] : false;
            }
            if ($cand[$k] !== false) {
                $out[] = $cand[$k];
            }
        }

        return $out;
    }

    /** Meistverkaufte, sichtbare Artikel einer Kategorie (6 h Dateicache, IDs only). */
    private static function topSellers(int $idCategory, int $idShop): array
    {
        static $memo = [];
        $k = $idShop . '-' . $idCategory;
        if (isset($memo[$k])) {
            return $memo[$k];
        }
        $file = _PS_ROOT_DIR_ . '/var/geoconvado/ups-' . $k . '.json';
        if (is_file($file) && (int) filemtime($file) > time() - 21600) {
            $d = json_decode((string) @file_get_contents($file), true);
            if (is_array($d)) {
                return $memo[$k] = array_map('intval', $d);
            }
        }
        $ids = array_map('intval', array_column(Db::getInstance()->executeS('SELECT cp.`id_product` FROM `' . _DB_PREFIX_ . 'category_product` cp
            JOIN `' . _DB_PREFIX_ . 'product_shop` ps ON ps.`id_product` = cp.`id_product` AND ps.`id_shop` = ' . $idShop . '
            LEFT JOIN `' . _DB_PREFIX_ . 'product_sale` s ON s.`id_product` = cp.`id_product`
            WHERE cp.`id_category` = ' . $idCategory . " AND ps.`active` = 1 AND ps.`available_for_order` = 1
            AND ps.`visibility` IN ('both','catalog')
            ORDER BY COALESCE(s.`quantity`, 0) DESC, cp.`id_product` ASC LIMIT 12") ?: [], 'id_product'));
        AtomicFile::write($file, (string) json_encode($ids));

        return $memo[$k] = $ids;
    }

    /** Darf der aktuelle Besucher Preise sehen? (Katalogmodus, Kundengruppe) */
    public static function visitorSeesPrices(): bool
    {
        if ((int) Configuration::get('PS_CATALOG_MODE')) {
            return false;
        }
        $g = Group::getCurrent();

        return !Validate::isLoadedObject($g) || (bool) $g->show_prices;
    }

    /** Nur http(s)-Adressen zulassen (verhindert javascript: o. Ä. aus Fremddaten). */
    public static function safeUrl(string $u): string
    {
        return preg_match('#^https://[^\s"<>]+$#i', $u) === 1 ? $u : '';
    }

    /** 0–5 Sterne als Text; ausserhalb des Bereichs wird begrenzt (kein Fehler bei str_repeat). */
    public static function stars(float $avg): string
    {
        $full = max(0, min(5, (int) round($avg)));

        return str_repeat('★', $full) . str_repeat('☆', 5 - $full);
    }

    /** Shop-Daten für Templates: Review-URL nur als https-Adresse. */
    private function safeShop(array $shop): array
    {
        $shop['google_reviews_url'] = self::safeUrl((string) ($shop['google_reviews_url'] ?? ''));

        return $shop;
    }

    /** Gecachte AIO-Daten (nie ein Live-Abruf im Frontend, null ms Wartezeit). */
    private function aioCache(): array
    {
        static $data = null;

        return $data ??= ['shop' => (new AioClient())->cachedShop((int) $this->context->shop->id)];
    }

    /** Kopf jeder Shopseite: Verweis auf llms.txt / llms.json (Auffindbarkeit für KI). */
    public function hookDisplayHeader(): string
    {
        $this->addFrontCss();
        $base = rtrim($this->context->link->getBaseLink(), '/');
        $this->context->smarty->assign(['geo_llms_txt' => $base . '/llms.txt', 'geo_llms_json' => $base . '/llms.json']);

        return $this->fetch('module:geoconvado/views/templates/hook/head.tpl');
    }

    private function addFrontCss(): void
    {
        if ($this->context->controller->php_self === 'product' || (int) Configuration::get(self::CFG_W_REVIEWS)) {
            $this->context->controller->registerStylesheet('geoconvado-front', 'modules/' . $this->name . '/views/css/front.css', ['media' => 'all', 'priority' => 150]);
        }
    }

    /** Kompaktes Google-Badge unter dem Preis. */
    public function hookDisplayProductAdditionalInfo(): string
    {
        $shop = $this->aioCache()['shop'] ?? [];
        if (!(int) Configuration::get(self::CFG_W_REVIEWS) || empty($shop['google_rating_avg'])) {
            return '';
        }
        $iso = (string) $this->context->language->iso_code;
        // Zitate: zuerst die lokal synchronisierten Rezensionen von «AF Google Reviews» (falls installiert), sonst Convado AIO.
        $quotes = self::pickQuotes(self::localGoogleRows(), $iso, 5);
        if (!$quotes) {
            $quotes = self::pickQuotes(array_map(static fn (array $r): array => ['author_name' => (string) ($r['author'] ?? ''), 'text' => (string) ($r['text'] ?? ''), 'rating' => (int) ($r['rating'] ?? 0), 'language' => ''], (array) ($shop['google_reviews'] ?? [])), $iso, 5);
        }
        $avg = (float) $shop['google_rating_avg'];
        $label = match ($iso) {
            'fr' => 'avis Google', 'it' => 'recensioni Google', 'en' => 'Google reviews', default => 'Google-Bewertungen',
        };
        $scope = match ($iso) {
            'fr' => 'Avis sur la boutique', 'it' => 'Valutazione del negozio', 'en' => 'Shop rating', default => 'Shop-Bewertung',
        };
        $this->context->smarty->assign(['geo_g_scope' => $scope, 'geo_stars' => self::stars($avg), 'geo_g_label' => $label, 'geo_g' => $this->safeShop($shop), 'geo_quotes' => $quotes, 'geo_rot' => count($quotes) > 1 ? ' geo-proof--rot geo-n' . count($quotes) : '']);

        return $this->fetch('module:geoconvado/views/templates/hook/reviews_badge.tpl');
    }

    /**
     * Aktive 5-Sterne-Rezensionen mit Text aus der Tabelle des Moduls «AF Google Reviews» (leer, wenn es fehlt).
     *
     * @return array<int, array<string, mixed>>
     */
    private static function localGoogleRows(): array
    {
        static $rows = null;
        if ($rows !== null) {
            return $rows;
        }
        $rows = [];
        try {
            $table = _DB_PREFIX_ . 'af_googlereviews';
            $db = Db::getInstance();
            if ($db->getValue("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . pSQL($table) . "'")) {
                $rows = $db->executeS('SELECT author_name, text, rating, language FROM `' . bqSQL($table) . '`
                    WHERE active = 1 AND rating >= 5 AND CHAR_LENGTH(text) >= 40 ORDER BY position ASC, time DESC LIMIT 60') ?: [];
            }
        } catch (Throwable $e) {
            $rows = [];
        }

        return $rows;
    }

    /**
     * Wählt bis zu $max Zitate: erst Rezensionen in der Sprache der Seite, dann solche ohne Sprachangabe («Default»).
     *
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<int, array{text: string, author: string}>
     */
    public static function pickQuotes(array $rows, string $iso, int $max): array
    {
        $own = [];
        $other = [];
        foreach ($rows as $r) {
            $text = trim((string) ($r['text'] ?? ''));
            if ((int) ($r['rating'] ?? 0) < 5 || mb_strlen($text) < 40) {
                continue;
            }
            $q = ['text' => mb_strimwidth($text, 0, 140, '…'), 'author' => trim((string) ($r['author_name'] ?? ''))];
            $lang = strtolower(trim((string) ($r['language'] ?? '')));
            if ($lang === strtolower($iso)) {
                $own[] = $q;
            } elseif ($lang === '' || $lang === 'default') {
                $other[] = $q;
            }
        }

        return array_slice(array_merge($own, $other), 0, $max);
    }

    /** Unter der Produktbeschreibung: links KI-Zusammenfassung (deklariert), rechts echte Käuferbewertungen, darunter FAQ und «Passt dazu». */
    public function hookDisplayFooterProduct(array $params): string
    {
        $product = $params['product'] ?? null;
        $get = static fn (string $k): mixed => is_array($product) || $product instanceof ArrayAccess ? ($product[$k] ?? null) : ($product->$k ?? null);
        $ref = (string) ($get('reference') ?? '');
        $iso = (string) $this->context->language->iso_code;
        $idProduct = (int) ($get('id_product') ?? $get('id') ?? 0);
        $prod = $ref !== '' ? (new AioClient())->cachedProduct((int) $this->context->shop->id, $ref) : [];
        $s = (int) Configuration::get(self::CFG_W_SUMMARY) ? ($prod['summary'][$iso] ?? null) : null;
        $faqs = (int) Configuration::get(self::CFG_FAQS)
            ? array_values(array_filter($prod['faqs'] ?? [], static fn (array $f): bool => ($f['lang'] ?? 'de') === $iso))
            : [];
        $faqs = array_map(static fn (array $f): array => ['q' => Generator::scrub((string) $f['q']), 'a' => Generator::scrub((string) $f['a'])] + $f, $faqs);
        $ups = [];
        if ($idProduct && (int) Configuration::get('GEOCONVADO_UPSELL', 1)) {
            $seesPrices = self::visitorSeesPrices();
            $thumb = ImageType::getFormattedName('home');
            foreach (self::upsell($idProduct, (int) $this->context->language->id, (int) $this->context->shop->id) as $u) {
                $cover = Product::getCover($u['id']);
                $ups[] = [
                    'name' => $u['name'],
                    'url' => $this->context->link->getProductLink($u['p']),
                    'price' => $seesPrices && $u['show_price'] ? Tools::getContextLocale($this->context)->formatPrice($u['price'], $this->context->currency->iso_code) : '',
                    'img' => !empty($cover['id_image']) ? $this->context->link->getImageLink($u['p']->link_rewrite, $u['id'] . '-' . $cover['id_image'], $thumb) : '',
                ];
            }
        }
        $rv = $idProduct ? $this->reviewsContext($idProduct, (string) ($get('name') ?? ''), $iso) : null;
        if (!$s && !$faqs && !$ups && !$rv) {
            return '';
        }
        $labels = match ($iso) {
            'fr' => ['Évaluation neutre', 'Points forts', 'Points faibles', 'Idéal pour', 'Moins adapté pour', ReviewText::get('fr')['ai_declared']],
            'it' => ['Valutazione neutrale', 'Punti di forza', 'Punti deboli', 'Ideale per', 'Meno adatto per', ReviewText::get('it')['ai_declared']],
            'en' => ['Neutral assessment', 'Pros', 'Cons', 'Best for', 'Less suited for', ReviewText::get('en')['ai_declared']],
            default => ['Neutrale Einschätzung', 'Vorteile', 'Nachteile', 'Ideal für', 'Weniger geeignet für', ReviewText::get('de')['ai_declared']],
        };
        $faqTitle = match ($iso) {
            'fr' => 'Questions fréquentes', 'it' => 'Domande frequenti', 'en' => 'Frequently asked questions', default => 'Häufige Fragen zu diesem Produkt',
        };
        $ldFaq = array_map(static fn (array $f): array => ['q' => self::ldStr((string) $f['q']), 'a' => self::ldStr((string) $f['a'])], array_slice($faqs, 0, 8));
        $upTitle = match ($iso) {
            'fr' => 'Complète bien', 'it' => 'Si abbina bene', 'en' => 'Goes well with', default => 'Passt dazu',
        };
        $this->context->smarty->assign([
            'geo_ups' => $ups, 'geo_up_title' => $upTitle, 'geo_s' => $s, 'geo_l' => $labels, 'geo_ai_title' => ReviewText::get($iso)['h_ai'],
            'geo_faqs' => array_slice($faqs, 0, 8), 'geo_faq_title' => $faqTitle,
            'geo_rv' => $rv, 'geo_ld_faq' => $ldFaq, 'geo_ld_rv' => $rv['ld'] ?? null,
        ]);

        return $this->fetch('module:geoconvado/views/templates/hook/summary.tpl');
    }

    /**
     * Text als Inhalt eines JSON-Strings (ohne Anführungszeichen), für die strukturierten Daten im Template.
     * Alle für HTML kritischen Zeichen (Ampersand, spitze Klammern, Anführungszeichen, Apostroph) werden als \uXXXX kodiert.
     * Dadurch bleibt der Wert nach dem Smarty-Escaping unverändert gültiges JSON und kann den Block nicht verlassen.
     */
    public static function ldStr(string $s): string
    {
        $json = json_encode($s, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        return $json === false ? '' : substr($json, 1, -1);
    }

    /**
     * Daten für die rechte Spalte (echte Käuferbewertungen). null = Programm aus oder nichts anzuzeigen.
     *
     * @return array<string, mixed>|null
     */
    private function reviewsContext(int $idProduct, string $name, string $iso): ?array
    {
        if (!(int) Configuration::get('GEOCONVADO_RV_ON')) {
            return null;
        }
        $t = ReviewText::get($iso);
        $idShop = (int) $this->context->shop->id;
        $link = $this->context->link;
        $cust = $this->context->customer;
        $logged = $cust instanceof Customer && $cust->isLogged();
        $stats = ReviewRepository::stats($idProduct, $idShop);
        $all = (int) Tools::getValue('geo_rvp') === 1;
        $rows = $stats['count'] > 0 ? ReviewRepository::visible($idProduct, $idShop, $all ? 30 : 5) : [];

        $items = [];
        foreach ($rows as $r) {
            $media = [];
            foreach ($r['media'] as $m) {
                $media[] = [
                    'kind' => (string) $m['kind'],
                    'url' => $link->getModuleLink($this->name, 'media', ['id' => (int) $m['id_media']], true),
                    'thumb' => (string) $m['thumb'] !== '' || $m['kind'] === 'image' ? $link->getModuleLink($this->name, 'media', ['id' => (int) $m['id_media'], 'v' => 't'], true) : '',
                ];
            }
            $items[] = [
                'id' => (int) $r['id_review'], 'rating' => (int) $r['rating'], 'stars' => self::stars((float) $r['rating']), 'title' => (string) $r['title'],
                'body' => (string) $r['body'], 'name' => (string) $r['display_name'], 'date' => Tools::displayDate((string) $r['created_at']),
                'incentivised' => (bool) $r['incentivised'], 'helpful' => (int) $r['helpful'], 'blog_url' => ReviewGuard::httpsUrl((string) $r['blog_url']),
                'mine' => $logged && (int) $r['id_customer'] === (int) $cust->id, 'media' => $media,
            ];
        }

        $productUrl = $link->getProductLink($idProduct);
        $can = ['state' => 'none', 'text' => '', 'url' => ''];
        if (!$logged) {
            $can = ['state' => 'login', 'text' => '', 'url' => $link->getPageLink('authentication', true, null, ['back' => $link->getModuleLink($this->name, 'review', ['id_product' => $idProduct], true)])];
        } else {
            $el = ReviewEligibility::check((int) $cust->id, $idProduct, $idShop);
            $can = $el['ok']
                ? ['state' => 'ok', 'text' => '', 'url' => $link->getModuleLink($this->name, 'review', ['id_product' => $idProduct], true)]
                : ['state' => 'no', 'url' => '', 'text' => match ($el['reason']) {
                    'already' => $t['already'], 'too_early' => sprintf($t['too_early'], $el['days']), 'returned' => $t['returned'], default => $t['only_buyers'],
                }];
        }
        if ($stats['count'] === 0 && $can['state'] === 'no' && $can['text'] === $t['only_buyers']) {
            return null; // nichts zu zeigen und der Besucher kann nicht bewerten: keine leere Hülle auf jeder Produktseite
        }

        $flash = match ((string) Tools::getValue('geo_rv')) {
            'ok' => $t['flash_ok'], 'okv' => $t['flash_ok'] . ' ' . $t['flash_voucher'], 'deleted' => $t['flash_deleted'], 'reported' => $t['flash_reported'], default => '',
        };
        $shop = $this->aioCache()['shop'] ?? [];
        $google = (int) Configuration::get(self::CFG_W_REVIEWS) && !empty($shop['google_rating_avg'])
            ? ['avg' => (string) $shop['google_rating_avg'], 'count' => (int) ($shop['google_rating_count'] ?? 0), 'url' => self::safeUrl((string) ($shop['google_reviews_url'] ?? ''))]
            : null;
        $shown = count($items);

        return [
            't' => $t, 'stats' => $stats, 'items' => $items, 'can' => $can, 'flash' => $flash, 'google' => $google,
            'voucher' => VoucherService::enabled(), 'id_product' => $idProduct, 'token' => Tools::getToken(false),
            'action' => $link->getModuleLink($this->name, 'review', [], true),
            'stars' => self::stars((float) $stats['avg']),
            'avg_text' => sprintf($t['avg'], number_format((float) $stats['avg'], 1, '.', ''), $stats['count']),
            'of_text' => sprintf($t['of_which'], $stats['inc']),
            'more' => !$all && $stats['count'] > $shown ? sprintf($t['all'], $stats['count']) : '',
            'more_url' => $productUrl . (str_contains($productUrl, '?') ? '&' : '?') . 'geo_rvp=1#geo-reviews',
            'ld' => $this->reviewsLd($stats, $name, $productUrl),
        ];
    }

    /** Strukturierte Daten nur auf ausdrücklichen Wunsch und nur aus Bewertungen, die ohne Gegenleistung entstanden sind. */
    private function reviewsLd(array $stats, string $name, string $url): ?array
    {
        if (!(int) Configuration::get('GEOCONVADO_RV_LD')) {
            return null;
        }
        $inc = (int) Configuration::get('GEOCONVADO_RV_LD_INC') === 1;
        $n = $inc ? $stats['count'] : $stats['organic'];
        $avg = $inc ? $stats['avg'] : $stats['organic_avg'];
        if ($n < 3 || $name === '') {
            return null;
        }

        // Werte JSON-sicher vorkodiert; das Template setzt die Struktur (Product + AggregateRating) zusammen.
        return ['id' => self::ldStr($url . '#product'), 'name' => self::ldStr($name), 'url' => self::ldStr($url), 'avg' => round((float) $avg, 1), 'count' => (int) $n];
    }

    /* ------------------------------------------------- Datenschutz (nDSG/DSGVO) */

    /** Löscht alle Bewertungen und Medien eines Kunden (Löschwunsch). */
    public function hookActionDeleteGDPRCustomer(array $customer): string
    {
        $id = (int) ($customer['id'] ?? 0);
        if ($id <= 0 && !empty($customer['email'])) {
            $c = Customer::getCustomersByEmail((string) $customer['email']);
            $id = (int) ($c[0]['id_customer'] ?? 0);
        }
        if ($id <= 0) {
            return (string) json_encode(true);
        }
        try {
            foreach (ReviewRepository::idsOfCustomer($id) as $rid) {
                ReviewRepository::delete($rid);
            }
        } catch (Throwable $e) {
            return (string) json_encode($this->trans('GEO.Convado: Bewertungen konnten nicht gelöscht werden.', [], 'Modules.Geoconvado.Admin'));
        }

        return (string) json_encode(true);
    }

    /** Auskunft: alle Bewertungen eines Kunden. */
    public function hookActionExportGDPRData(array $customer): string
    {
        $id = (int) ($customer['id'] ?? 0);
        if ($id <= 0 && !empty($customer['email'])) {
            $c = Customer::getCustomersByEmail((string) $customer['email']);
            $id = (int) ($c[0]['id_customer'] ?? 0);
        }

        return (string) json_encode($id > 0 ? ReviewRepository::export($id) : []);
    }

    /** Google-Bewertungsleiste über dem Footer (alle Seiten). */
    public function hookDisplayFooterBefore(): string
    {
        $shop = $this->aioCache()['shop'] ?? [];
        if (!(int) Configuration::get(self::CFG_W_REVIEWS) || empty($shop['google_rating_avg'])) {
            return '';
        }
        $label = match ((string) $this->context->language->iso_code) {
            'fr' => 'avis Google', 'it' => 'recensioni Google', 'en' => 'Google reviews', default => 'Google-Bewertungen',
        };
        $this->context->smarty->assign(['geo_stars' => self::stars((float) $shop['google_rating_avg']), 'geo_g_label' => $label, 'geo_g' => $this->safeShop($shop), 'geo_reviews' => array_map(static fn (array $r): array => ['text' => (string) ($r['text'] ?? ''), 'author' => (string) ($r['author'] ?? ''), 'rating' => max(0, min(5, (int) ($r['rating'] ?? 0)))], array_slice(array_values(array_filter((array) ($shop['google_reviews'] ?? []), 'is_array')), 0, 3))]);

        return $this->fetch('module:geoconvado/views/templates/hook/reviews_footer.tpl');
    }

    /** Direktkauf-Link für KI-Agenten (legt in den Warenkorb, öffnet die Kasse). */
    public function getBuyUrl(int $idProduct, int $idLang, int $idShop, int $ipa = 0): string
    {
        $q = ['id' => $idProduct, 'qty' => 1];
        if ($ipa > 0) {
            $q['ipa'] = $ipa;
        }

        return $this->context->link->getModuleLink($this->name, 'buy', $q, true, $idLang, $idShop);
    }

    /** Öffentliche Cron-URL inkl. Token. */
    public function getCronUrl(): string
    {
        return $this->context->link->getModuleLink($this->name, 'cron', ['token' => Configuration::get(self::CFG_TOKEN)]);
    }
}

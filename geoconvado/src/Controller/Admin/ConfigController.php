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

namespace GeoConvado\Controller\Admin;

if (!defined('_PS_VERSION_')) {
    exit;
}

use GeoConvado\Service\AioClient;
use GeoConvado\Service\BotDetector;
use GeoConvado\Service\Generator;
use GeoConvado\Service\MediaProcessor;
use GeoConvado\Service\ReviewRepository;
use GeoConvado\Service\RobotsWriter;
use GeoConvado\Service\Tracker;
use PrestaShop\PrestaShop\Adapter\LegacyContext;
use PrestaShopBundle\Controller\Admin\PrestaShopAdminController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ConfigController extends PrestaShopAdminController
{
    /** Rechteprüfung auf das Modul-Recht (AdminModules). Bei jedem Fehler wird verweigert. */
    private function allowed(string $mode): bool
    {
        try {
            return (bool) $this->isGranted($mode, 'AdminModules');
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function denied(): JsonResponse
    {
        return new JsonResponse(['success' => false, 'error' => 'Keine Berechtigung'], 403);
    }

    public function indexAction(Request $request, LegacyContext $legacyContext): Response
    {
        if (!$this->allowed($request->isMethod('POST') ? 'update' : 'read')) {
            return new Response('Keine Berechtigung', 403);
        }
        \Module::getInstanceByName('geoconvado'); // lädt die Modulklasse (GeoConvado::markDirty, Konstanten)
        $languages = \Language::getLanguages(true);
        $testResult = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('geoconvado', (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Sitzung abgelaufen, bitte erneut speichern.');

                return $this->redirectToRoute('geoconvado_config');
            }
            $key = trim((string) $request->request->get('aio_key'));
            \Configuration::updateValue('GEOCONVADO_AIO_KEY', $key);
            $brand = [];
            foreach ($languages as $l) {
                $brand[(int) $l['id_lang']] = (string) $request->request->get('brand_' . $l['id_lang'], '');
            }
            \Configuration::updateValue('GEOCONVADO_BRAND', $brand, true);
            $custom = [];
            foreach ($languages as $l) {
                $custom[(int) $l['id_lang']] = strip_tags((string) $request->request->get('custom_' . $l['id_lang'], ''));
            }
            \Configuration::updateValue('GEOCONVADO_CUSTOM', $custom);
            foreach (array_keys(\GeoConvado::SWITCH_DEFAULTS) as $k) {
                \Configuration::updateValue($k, $request->request->get(strtolower(substr($k, 11))) ? 1 : 0);
            }
            \Configuration::updateValue('GEOCONVADO_MAX_PRODUCTS', max(0, (int) $request->request->get('max_products', 0)));
            foreach (['cms_ids' => 'GEOCONVADO_CMS_IDS', 'brand_ids' => 'GEOCONVADO_BRAND_IDS', 'cat_ids' => 'GEOCONVADO_CAT_IDS'] as $f => $k) {
                \Configuration::updateValue($k, (string) json_encode(array_values(array_map('intval', (array) $request->request->all($f)))));
            }
            $bots = array_values(array_intersect(array_keys(BotDetector::PROVIDERS), (array) $request->request->all('bots')));
            \Configuration::updateValue('GEOCONVADO_BOTS', (string) json_encode($bots));
            \Configuration::updateValue('GEOCONVADO_REVIEWS', $request->request->get('reviews') ? 1 : 0);
            \Configuration::updateValue('GEOCONVADO_FAQS', $request->request->get('faqs') ? 1 : 0);
            \Configuration::updateValue('GEOCONVADO_ROBOTS', $request->request->get('robots') ? 1 : 0);
            // Sofort schreiben, nicht erst beim nächsten Generierungslauf (sonst fehlt der Block bis zum Cron).
            $robotsWriter = new RobotsWriter();
            if ($request->request->get('robots')) {
                if (!$robotsWriter->ensure(\Tools::getShopDomainSsl(true) . __PS_BASE_URI__)) {
                    $this->addFlash('warning', 'robots.txt ist nicht beschreibbar. Block aus dem Reiter «KI-Zugriff» manuell einfügen.');
                }
            } else {
                $robotsWriter->remove();
            }
            foreach (['payments' => 'GEOCONVADO_PAYMENTS', 'ship_zone' => 'GEOCONVADO_SHIP_ZONE', 'ship_cost' => 'GEOCONVADO_SHIP_COST', 'vat' => 'GEOCONVADO_VAT'] as $f => $k) {
                \Configuration::updateValue($k, trim(strip_tags((string) $request->request->get($f, ''))));
            }
            \Configuration::updateValue('GEOCONVADO_FACTS_REVIEW', 0); // Händler hat die Einkaufsinfos gesehen und bestätigt
            \Configuration::updateValue('GEOCONVADO_W_SUMMARY', $request->request->get('w_summary') ? 1 : 0);
            \Configuration::updateValue('GEOCONVADO_UPSELL', $request->request->get('upsell') ? 1 : 0);
            \Configuration::updateValue('GEOCONVADO_W_REVIEWS', $request->request->get('w_reviews') ? 1 : 0);
            $track = $request->request->get('track_llms') ? 1 : 0;
            if ($track !== (int) \Configuration::get('GEOCONVADO_TRACK_LLMS')) {
                \Configuration::updateValue('GEOCONVADO_TRACK_LLMS', $track);
                // Physische Dateien am alten Ort entfernen, sonst überdecken sie die Auslieferung
                $this->cleanup($track ? _PS_ROOT_DIR_ . '/' : _PS_ROOT_DIR_ . '/llms-data/');
                \Tools::clearSf2Cache();
            }
            $stock = [];
            foreach ($languages as $l) {
                $stock[(int) $l['id_lang']] = trim(strip_tags((string) $request->request->get('stock_text_' . $l['id_lang'], '')));
            }
            \Configuration::updateValue('GEOCONVADO_STOCK_TEXT', $stock);
            $this->saveReviewSettings($request);
            \GeoConvado::markDirty();

            if ($request->request->get('test') && $key !== '') {
                $testResult = (new AioClient())->test($key);
            } else {
                $this->addFlash('success', 'Gespeichert. Die Dateien werden beim nächsten Cron-Lauf aktualisiert.');

                return $this->redirectToRoute('geoconvado_config', ['tab' => (string) $request->request->get('tab', 'dashboard')]);
            }
        }

        $context = $legacyContext->getContext();
        $generator = new Generator($context);
        $states = [];
        foreach ($generator->targets() as [$idShop, $idLang]) {
            $s = $generator->state($idShop, $idLang);
            $s['iso'] = \Language::getIsoById($idLang);
            $s['shop'] = \Shop::getShop($idShop)['name'] ?? (string) $idShop;
            $states[] = $s;
        }

        $bots = \Db::getInstance()->executeS('SELECT bot, COUNT(*) AS hits, MAX(created_at) AS last_seen FROM `' . _DB_PREFIX_ . 'geoconvado_bot_log`
            WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY bot ORDER BY hits DESC') ?: [];
        // 90 Tage Aufbewahrung
        \Db::getInstance()->execute('DELETE FROM `' . _DB_PREFIX_ . 'geoconvado_bot_log` WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)');

        $module = \Module::getInstanceByName('geoconvado');
        if (!$module instanceof \GeoConvado) {
            throw new \RuntimeException('GEO.Convado ist nicht installiert.');
        }
        $brandValues = [];
        foreach ($languages as $l) {
            $brandValues[(int) $l['id_lang']] = (string) \Configuration::get('GEOCONVADO_BRAND', (int) $l['id_lang']);
        }

        $dash = Tracker::dashboard();
        $idLang = (int) $context->language->id;
        $idShop = (int) $context->shop->id;
        $base = rtrim($context->link->getBaseLink(), '/');
        $customValues = [];
        $stockValues = [];
        foreach ($languages as $l) {
            $customValues[(int) $l['id_lang']] = (string) \Configuration::get('GEOCONVADO_CUSTOM', (int) $l['id_lang']);
            $stockValues[(int) $l['id_lang']] = (string) \Configuration::get('GEOCONVADO_STOCK_TEXT', (int) $l['id_lang']);
        }
        $switches = [];
        foreach (\GeoConvado::SWITCH_DEFAULTS as $k => $def) {
            $v = \Configuration::get($k);
            $switches[strtolower(substr($k, 11))] = $v === false ? (bool) $def : (bool) (int) $v;
        }
        $ids = static fn (string $k): array => array_map('intval', (array) json_decode((string) \Configuration::get($k), true));
        $cmsPages = \CMS::getCMSPages($idLang, null, true, $idShop) ?: [];
        $brandsAll = \Manufacturer::getManufacturers(false, $idLang, true) ?: [];
        $topCats = \Db::getInstance()->executeS('SELECT c.id_category, cl.name FROM `' . _DB_PREFIX_ . 'category` c
            JOIN `' . _DB_PREFIX_ . 'category_lang` cl ON cl.id_category = c.id_category AND cl.id_lang = ' . $idLang . ' AND cl.id_shop = ' . $idShop . '
            WHERE c.active = 1 AND c.level_depth = 2 ORDER BY c.nleft') ?: [];
        $totalProducts = (int) \Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'product_shop` WHERE id_shop = ' . $idShop . " AND active = 1 AND visibility IN ('both','catalog','search')");
        $totalCats = (int) \Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'category` WHERE active = 1 AND level_depth >= 2');
        $selCms = $ids('GEOCONVADO_CMS_IDS');
        $selBrands = $ids('GEOCONVADO_BRAND_IDS');
        $selCats = $ids('GEOCONVADO_CAT_IDS');
        $stats = [
            ['label' => 'Produkte', 'in' => (int) (array_sum(array_column($states, 'product_count')) / max(1, count($states))), 'all' => $totalProducts],
            ['label' => 'Hauptkategorien', 'in' => $switches['inc_cats'] ? ($selCats ? count($selCats) : count($topCats)) : 0, 'all' => count($topCats)],
            ['label' => 'CMS-Seiten', 'in' => $switches['inc_cms'] ? ($selCms ? count($selCms) : count($cmsPages)) : 0, 'all' => count($cmsPages)],
            ['label' => 'Marken', 'in' => $switches['inc_brands'] ? ($selBrands ? count($selBrands) : count($brandsAll)) : 0, 'all' => count($brandsAll)],
        ];
        $cronUrl = $module->getCronUrl();
        $cronFull = $cronUrl . (str_contains($cronUrl, '?') ? '&' : '?') . 'force=1';
        $cli = 'php ' . _PS_ROOT_DIR_ . '/modules/geoconvado/cli/cron.php';
        $cronCli = '*/10 * * * * ' . $cli . ' >/dev/null 2>&1' . "\n" . '45 3 * * * ' . $cli . ' --force >/dev/null 2>&1';
        $cronCurl = '*/10 * * * * curl -s -m 120 "' . $cronUrl . '" >/dev/null 2>&1' . "\n" . '45 3 * * * curl -s -m 300 "' . $cronFull . '" >/dev/null 2>&1';
        $providers = [];
        $allowed = BotDetector::allowedProviders();
        foreach (BotDetector::PROVIDERS as $k => [$label, $agents]) {
            $providers[] = ['key' => $k, 'label' => $label, 'agents' => implode(', ', $agents), 'on' => in_array($k, $allowed, true)];
        }
        $lastCron = (string) \Configuration::getGlobalValue('GEOCONVADO_LAST_CRON');
        $factsReview = (int) \Configuration::get('GEOCONVADO_FACTS_REVIEW') === 1;
        $steps = [
            ['label' => 'Verbindung', 'ok' => (string) \Configuration::get('GEOCONVADO_AIO_KEY') !== ''],
            ['label' => 'Inhalte', 'ok' => array_filter($brandValues) !== []],
            ['label' => 'Einkaufen', 'ok' => !$factsReview && (string) \Configuration::get('GEOCONVADO_PAYMENTS') !== '' && (string) \Configuration::get('GEOCONVADO_SHIP_COST') !== ''],
            ['label' => 'Cron', 'ok' => $lastCron !== '' && strtotime($lastCron) > time() - 3600],
        ];

        return $this->render('@Modules/geoconvado/views/templates/admin/configure.html.twig', [
            'version' => $module->version,
            'aio_key' => (string) \Configuration::get('GEOCONVADO_AIO_KEY'),
            'reviews' => (bool) \Configuration::get('GEOCONVADO_REVIEWS'),
            'faqs' => (bool) \Configuration::get('GEOCONVADO_FAQS'),
            'robots' => (bool) \Configuration::get('GEOCONVADO_ROBOTS'),
            'payments' => (string) \Configuration::get('GEOCONVADO_PAYMENTS'),
            'ship_zone' => (string) \Configuration::get('GEOCONVADO_SHIP_ZONE'),
            'ship_cost' => (string) \Configuration::get('GEOCONVADO_SHIP_COST'),
            'vat' => (string) \Configuration::get('GEOCONVADO_VAT'),
            'w_summary' => (bool) \Configuration::get('GEOCONVADO_W_SUMMARY'),
            'upsell' => (bool) \Configuration::get('GEOCONVADO_UPSELL'),
            'w_reviews' => (bool) \Configuration::get('GEOCONVADO_W_REVIEWS'),
            'track_llms' => (bool) \Configuration::get('GEOCONVADO_TRACK_LLMS'),
            'dash' => $dash,
            'steps' => $steps,
            'steps_done' => count(array_filter(array_column($steps, 'ok'))),
            'last_cron' => $lastCron,
            'css_url' => __PS_BASE_URI__ . 'modules/geoconvado/views/css/admin.css?v=' . $module->version,
            'total_products' => array_sum(array_column($states, 'product_count')) / max(1, count($states)),
            'languages' => $languages,
            'brand' => $brandValues,
            'states' => $states,
            'bots' => $bots,
            'cron_url' => $cronUrl,
            'cron_full_url' => $cronUrl . (str_contains($cronUrl, '?') ? '&' : '?') . 'force=1',
            'cron_cli_lines' => $cronCli,
            'cron_curl_lines' => $cronCurl,
            'tab' => (string) $request->query->get('tab', 'dashboard'),
            'custom' => $customValues,
            'stock_text' => $stockValues,
            'facts_review' => $factsReview,
            'rv' => $this->reviewContext($idLang),
            'reviews_url' => $this->generateUrl('geoconvado_reviews'),
            'sw' => $switches,
            'max_products' => (int) \Configuration::get('GEOCONVADO_MAX_PRODUCTS'),
            'cms_pages' => $cmsPages,
            'brands_all' => $brandsAll,
            'top_cats' => $topCats,
            'sel_cms' => $selCms,
            'sel_brands' => $selBrands,
            'sel_cats' => $selCats,
            'stats' => $stats,
            'files' => $this->files($base),
            'providers' => $providers,
            'robots_preview' => (new RobotsWriter())->block($base . '/'),
            'htaccess' => "# GEO.Convado: llms-Dateien über das Modul ausliefern (nur nötig, wenn Ihr Server das nicht automatisch tut)\nRewriteEngine On\nRewriteCond %{REQUEST_FILENAME} !-f\nRewriteRule ^(llms[a-z0-9/\\-]*\\.txt|llms\\.json)$ index.php [L,QSA]",
            'nginx' => "location ~ ^/llms[a-z0-9/\\-]*\\.(txt|json)$ {\n    try_files \$uri /index.php?\$args;\n}",
            'default_iso' => \Language::getIsoById((int) \Configuration::get('PS_LANG_DEFAULT')),
            'llms_url' => rtrim($context->link->getBaseLink(), '/') . '/llms.txt',
            'test' => $testResult,
            'dirty' => (bool) \Configuration::getGlobalValue('GEOCONVADO_DIRTY'),
        ]);
    }

    /** Einstellungen des Bewertungsprogramms speichern (alle Werte werden auf sinnvolle Grenzen gekürzt). */
    private function saveReviewSettings(Request $request): void
    {
        // Nur speichern, wenn der Reiter «Bewertungen» im Formular war (sonst würden Schalter versehentlich zurückgesetzt).
        if (!$request->request->has('rv_present')) {
            return;
        }
        foreach (['RV_ON', 'RV_VOUCHER_ON', 'RV_PHOTO_REQ', 'RV_VIDEO_ON', 'RV_AUTO', 'RV_LD', 'RV_LD_INC', 'RV_PURGE'] as $k) {
            \Configuration::updateValue('GEOCONVADO_' . $k, $request->request->get(strtolower($k)) ? 1 : 0);
        }
        $ints = ['RV_DAYS' => [0, 60], 'RV_MIN_TEXT' => [10, 1000], 'RV_MIN_CHARS' => [0, 2000], 'RV_VOUCHER_DAYS' => [1, 365], 'RV_VOUCHER_YEAR_MAX' => [1, 50],
            'RV_IMG_MAX' => [0, 6], 'RV_VIDEO_MB' => [1, 200], 'RV_VIDEO_SEC' => [5, 120]];
        foreach ($ints as $k => [$lo, $hi]) {
            \Configuration::updateValue('GEOCONVADO_' . $k, max($lo, min($hi, (int) $request->request->get(strtolower($k), \GeoConvado::RV_DEFAULTS['GEOCONVADO_' . $k]))));
        }
        foreach (['RV_VOUCHER_AMOUNT' => 500, 'RV_VOUCHER_MIN' => 10000] as $k => $hi) {
            $v = (float) str_replace(',', '.', (string) $request->request->get(strtolower($k), '0'));
            \Configuration::updateValue('GEOCONVADO_' . $k, max(0, min($hi, round($v, 2))));
        }
        $lines = array_slice(array_filter(array_map('trim', explode("\n", strip_tags((string) $request->request->get('rv_block', ''))))), 0, 200);
        \Configuration::updateValue('GEOCONVADO_RV_BLOCK', implode("\n", $lines));
        \Configuration::updateValue('GEOCONVADO_RV_STATES', (string) json_encode(array_values(array_filter(array_map('intval', (array) $request->request->all('rv_states'))))));
        foreach (['RV_FFMPEG' => 'rv_ffmpeg', 'RV_FFPROBE' => 'rv_ffprobe'] as $k => $f) {
            $path = trim((string) $request->request->get($f, ''));
            \Configuration::updateValue('GEOCONVADO_' . $k, $path !== '' && preg_match('#^/[A-Za-z0-9_./-]+$#', $path) && is_file($path) && is_executable($path) ? $path : '');
        }
    }

    /** Daten für den Reiter «Bewertungen». */
    private function reviewContext(int $idLang): array
    {
        $caps = MediaProcessor::caps();
        $cfg = static fn (string $k): string => (string) \Configuration::get('GEOCONVADO_' . $k);
        $states = [];
        $selected = \GeoConvado\Service\ReviewEligibility::states();
        foreach (\OrderState::getOrderStates($idLang) as $st) {
            $states[] = ['id' => (int) $st['id_order_state'], 'name' => (string) $st['name'], 'on' => in_array((int) $st['id_order_state'], $selected, true)];
        }
        $int = static fn (int $v): string => $v < 0 ? 'unbegrenzt' : number_format($v / 1048576, 0) . ' MB';

        return [
            'on' => (bool) (int) $cfg('RV_ON'), 'voucher_on' => (bool) (int) $cfg('RV_VOUCHER_ON'), 'photo_req' => (bool) (int) $cfg('RV_PHOTO_REQ'),
            'video_on' => (bool) (int) $cfg('RV_VIDEO_ON'), 'auto' => (bool) (int) $cfg('RV_AUTO'), 'ld' => (bool) (int) $cfg('RV_LD'),
            'ld_inc' => (bool) (int) $cfg('RV_LD_INC'), 'purge' => (bool) (int) $cfg('RV_PURGE'),
            'days' => (int) $cfg('RV_DAYS'), 'min_text' => (int) $cfg('RV_MIN_TEXT'), 'min_chars' => (int) $cfg('RV_MIN_CHARS'),
            'voucher_amount' => $cfg('RV_VOUCHER_AMOUNT'), 'voucher_min' => $cfg('RV_VOUCHER_MIN'), 'voucher_days' => (int) $cfg('RV_VOUCHER_DAYS'),
            'year_max' => (int) $cfg('RV_VOUCHER_YEAR_MAX'), 'img_max' => (int) $cfg('RV_IMG_MAX'), 'video_mb' => (int) $cfg('RV_VIDEO_MB'),
            'video_sec' => (int) $cfg('RV_VIDEO_SEC'), 'block' => $cfg('RV_BLOCK'), 'ffmpeg' => $cfg('RV_FFMPEG'), 'ffprobe' => $cfg('RV_FFPROBE'),
            'states' => $states, 'counts' => ReviewRepository::counts(), 'currency' => (new \Currency((int) \Configuration::get('PS_CURRENCY_DEFAULT')))->iso_code,
            'caps' => [
                ['label' => 'PHP-Bildbibliothek GD (JPG, PNG)', 'ok' => $caps['gd'] && $caps['jpeg'] && $caps['png'], 'detail' => $caps['gd'] ? 'vorhanden' : 'fehlt – ohne GD keine Foto-Uploads'],
                ['label' => 'WebP-Ausgabe', 'ok' => $caps['webp'], 'detail' => $caps['webp'] ? 'kleine Dateien (WebP)' : 'nicht verfügbar, es wird JPG gespeichert'],
                ['label' => 'EXIF-Drehung (Handyfotos)', 'ok' => $caps['exif'], 'detail' => $caps['exif'] ? 'vorhanden' : 'fehlt, Fotos können gedreht erscheinen'],
                ['label' => 'Dateityp-Prüfung (fileinfo)', 'ok' => $caps['fileinfo'], 'detail' => $caps['fileinfo'] ? 'vorhanden' : 'fehlt – Uploads nicht möglich'],
                ['label' => 'Uploads erlaubt', 'ok' => $caps['file_uploads'], 'detail' => 'upload_max_filesize ' . $int($caps['upload_max']) . ', post_max_size ' . $int($caps['post_max']) . ', max. ' . $caps['max_file_uploads'] . ' Dateien'],
                ['label' => 'Speicherordner beschreibbar', 'ok' => $caps['root_writable'], 'detail' => 'var/geoconvado-media, frei: ' . ($caps['free'] > 0 ? $int($caps['free']) : 'unbekannt')],
                ['label' => 'PHP-Speicherlimit', 'ok' => $caps['memory'] < 0 || $caps['memory'] >= 134217728, 'detail' => $int($caps['memory']) . ($caps['memory'] >= 0 && $caps['memory'] < 134217728 ? ' – grosse Fotos werden abgelehnt' : '')],
                ['label' => 'Programme starten erlaubt (proc_open)', 'ok' => $caps['proc'], 'detail' => $caps['proc'] ? 'ja' : 'gesperrt – Videos werden nicht umgewandelt'],
                ['label' => 'ffmpeg', 'ok' => $caps['ffmpeg'] !== '', 'detail' => $caps['ffmpeg'] !== '' ? $caps['ffmpeg'] . ' – Videos werden nach MP4 umgewandelt (Cron)' : 'nicht gefunden – nur MP4-Videos, ungeprüft umgewandelt'],
                ['label' => 'ffprobe', 'ok' => $caps['ffprobe'] !== '', 'detail' => $caps['ffprobe'] !== '' ? $caps['ffprobe'] : 'nicht gefunden – Dauer wird aus der MP4-Datei gelesen'],
            ],
        ];
    }

    /** Erzeugte Dateien (Shop-Hauptordner bzw. llms-data) mit Grösse und Datum. */
    private function files(string $base): array
    {
        $root = (int) \Configuration::get('GEOCONVADO_TRACK_LLMS') ? _PS_ROOT_DIR_ . '/llms-data/' : _PS_ROOT_DIR_ . '/';
        $out = [];
        foreach (array_merge((array) glob($root . 'llms*.txt'), (array) glob($root . 'llms.json')) as $f) {
            $f = (string) $f;
            if ($f === '' || !is_file($f)) {
                continue;
            }
            $name = basename($f);
            $out[] = ['name' => $name, 'url' => $base . '/' . $name, 'size' => (int) filesize($f), 'mtime' => date('Y-m-d H:i', (int) filemtime($f))];
        }
        usort($out, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $out;
    }

    /** Selbsttest: Dateien, robots.txt, AIO und Cloudflare-/Firewall-Sperren pro KI-Anbieter. */
    public function checkAction(Request $request, LegacyContext $legacyContext): JsonResponse
    {
        if (!$this->allowed('update')) {
            return $this->denied();
        }
        if (!$this->isCsrfTokenValid('geoconvado', (string) $request->request->get('_token'))) {
            return new JsonResponse(['success' => false, 'error' => 'Ungültiges Token'], 403);
        }
        @set_time_limit(60);
        $context = $legacyContext->getContext();
        $base = rtrim($context->link->getBaseLink(), '/');
        $idProduct = (int) \Db::getInstance()->getValue('SELECT id_product FROM `' . _DB_PREFIX_ . 'product_shop` WHERE active = 1 AND id_shop = ' . (int) $context->shop->id . ' ORDER BY id_product LIMIT 1');
        $productUrl = $idProduct ? $context->link->getProductLink($idProduct) : $base . '/';
        $rows = [];

        $browser = $this->probe($base . '/llms.txt', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36');
        $rows[] = ['label' => 'llms.txt erreichbar (normaler Besucher)', 'ok' => $browser['code'] === 200, 'detail' => $browser['detail']];
        $robots = $this->probe($base . '/robots.txt', 'Mozilla/5.0');
        $rows[] = ['label' => 'robots.txt enthält GEO.Convado-Block', 'ok' => str_contains($robots['body'], '# GEO.Convado start'), 'detail' => $robots['code'] === 200 ? 'gefunden' : $robots['detail']];
        $cloudflare = $browser['cf'] || $robots['cf'];

        foreach (BotDetector::TEST_UA as $p => $ua) {
            $label = BotDetector::PROVIDERS[$p][0];
            foreach (['llms.txt' => $base . '/llms.txt', 'Produktseite' => $productUrl] as $what => $url) {
                $r = $this->probe($url, $ua);
                $cloudflare = $cloudflare || $r['cf'];
                $blocked = in_array($r['code'], [401, 403, 429, 503], true) || $r['challenge'];
                $rows[] = [
                    'label' => $label . ' – ' . $what,
                    'ok' => $r['code'] === 200 && !$r['challenge'],
                    'detail' => $blocked && $r['cf']
                        ? 'Von Cloudflare blockiert (' . $r['code'] . ($r['mitigated'] ? ', ' . $r['mitigated'] : '') . ')'
                        : $r['detail'],
                ];
            }
        }
        $key = (string) \Configuration::get('GEOCONVADO_AIO_KEY');
        $aio = $key !== '' ? (new AioClient())->test($key) : ['ok' => false, 'error' => 'Kein Schlüssel'];
        $rows[] = ['label' => 'Convado AIO (Bewertungen, FAQs)', 'ok' => $aio['ok'], 'detail' => $aio['ok'] ? $aio['products'] . ' Produkte, ' . $aio['faqs'] . ' FAQs' : (string) $aio['error']];

        return new JsonResponse(['success' => true, 'data' => ['rows' => $rows, 'cloudflare' => $cloudflare]]);
    }

    /** @return array{code: int, cf: bool, challenge: bool, mitigated: string, body: string, detail: string} */
    private function probe(string $url, string $ua): array
    {
        $headers = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_USERAGENT => $ua,
            CURLOPT_HEADERFUNCTION => static function ($c, string $h) use (&$headers): int {
                $parts = explode(':', $h, 2);
                if (count($parts) === 2) {
                    $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($h);
            },
        ]);
        $body = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        unset($ch); // curl_close() ist seit PHP 8.0 wirkungslos und ab 8.5 veraltet
        $cf = isset($headers['cf-ray']) || stripos($headers['server'] ?? '', 'cloudflare') !== false;
        $challenge = $cf && (isset($headers['cf-mitigated']) || stripos($body, 'challenge-platform') !== false);

        return [
            'code' => $code,
            'cf' => $cf,
            'challenge' => $challenge,
            'mitigated' => (string) ($headers['cf-mitigated'] ?? ''),
            'body' => substr($body, 0, 20000),
            'detail' => $code === 0 ? 'Keine Verbindung: ' . $err : 'HTTP ' . $code . ($cf ? ' · über Cloudflare' : ''),
        ];
    }

    /** Übersetzt Markentext oder eigene Abschnitte aus der Standardsprache (Ergebnis wird erst beim Speichern übernommen). */
    public function translateAction(Request $request): JsonResponse
    {
        if (!$this->allowed('update')) {
            return $this->denied();
        }
        if (!$this->isCsrfTokenValid('geoconvado', (string) $request->request->get('_token'))) {
            return new JsonResponse(['success' => false, 'error' => 'Ungültiges Token'], 403);
        }
        $key = (string) \Configuration::get('GEOCONVADO_AIO_KEY');
        $text = trim((string) $request->request->get('text'));
        if ($key === '' || $text === '') {
            return new JsonResponse(['success' => false, 'error' => $key === '' ? 'Zuerst den AIO-Schlüssel speichern.' : 'Text in der Standardsprache fehlt.']);
        }
        $source = (string) $request->request->get('source', 'de');
        $targets = [];
        foreach (\Language::getLanguages(true) as $l) {
            if ($l['iso_code'] !== $source) {
                $targets[(string) $l['iso_code']] = (int) $l['id_lang'];
            }
        }
        $r = (new AioClient())->translate($key, $text, $source, array_keys($targets));
        if (!$r['ok']) {
            return new JsonResponse(['success' => false, 'error' => $r['error']]);
        }
        $out = [];
        foreach ($r['translations'] as $iso => $t) {
            if (isset($targets[$iso])) {
                $out[$targets[$iso]] = (string) $t;
            }
        }

        return new JsonResponse(['success' => true, 'data' => $out]);
    }

    /** Entfernt nur GEO.Convado-Dateien (llms*.txt und den Ordner llms/). */
    private function cleanup(string $root): void
    {
        foreach (array_merge((array) glob($root . 'llms*.txt'), (array) glob($root . 'llms.json')) as $f) {
            @unlink((string) $f);
        }
        $dir = $root . 'llms';
        if (is_dir($dir)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : (str_ends_with($f->getFilename(), '.txt') ? @unlink($f->getPathname()) : null);
            }
            @rmdir($dir);
        }
    }

    /** «Jetzt neu erzeugen»: ein Lauf mit 25 s Budget, Rest erledigt der Cron. */
    public function runAction(Request $request, LegacyContext $legacyContext): JsonResponse
    {
        if (!$this->allowed('update')) {
            return $this->denied();
        }
        if (!$this->isCsrfTokenValid('geoconvado', (string) $request->request->get('_token'))) {
            return new JsonResponse(['success' => false, 'error' => 'Ungültiges Token'], 403);
        }
        @set_time_limit(60);

        return new JsonResponse(['success' => true, 'data' => (new Generator($legacyContext->getContext()))->run(25, true)]);
    }
}

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
 *
 * Cron per Kommandozeile (empfohlen): kein HTTP, kein Token in der URL, unabhängig von Wartungsmodus,
 * Cloudflare und Firewall. Aufruf, z. B. alle 10 Minuten:
 *
 *   php /pfad/zum/shop/modules/geoconvado/cli/cron.php
 *
 * Optionen: --force (Neuaufbau erzwingen), --budget=SEKUNDEN (Standard 120)
 * Ausgabe: eine JSON-Zeile. Exit-Code 0 = Erfolg, 1 = Fehler.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

$root = dirname(__DIR__, 3);
if (!is_file($root . '/config/config.inc.php')) {
    fwrite(STDERR, "GEO.Convado: PrestaShop-Konfiguration nicht gefunden unter {$root}\n");
    exit(1);
}

require_once $root . '/config/config.inc.php';

if (!defined('_PS_VERSION_')) {
    exit;
}
require_once _PS_MODULE_DIR_ . 'geoconvado/geoconvado.php';

use GeoConvado\Service\Generator;
use GeoConvado\Service\ReviewRepository;
use GeoConvado\Service\Tracker;
use GeoConvado\Service\VideoProcessor;

$opts = getopt('', ['force', 'budget::']);
$force = isset($opts['force']);
$budget = isset($opts['budget']) ? max(5, (int) $opts['budget']) : 120;

// Kontext wie bei einem anonymen Seitenaufruf (Preise, Steuern, Links stimmen dann mit dem Frontend überein).
$ctx = (new GeoConvado())->context;
if (!Validate::isLoadedObject($ctx->shop)) {
    $ctx->shop = new Shop((int) Configuration::get('PS_SHOP_DEFAULT'));
    Shop::setContext(Shop::CONTEXT_SHOP, (int) $ctx->shop->id);
}
if (!Validate::isLoadedObject($ctx->language)) {
    $ctx->language = new Language((int) Configuration::get('PS_LANG_DEFAULT'));
}
if (!Validate::isLoadedObject($ctx->country)) {
    $ctx->country = new Country((int) Configuration::get('PS_COUNTRY_DEFAULT'));
}
if (!Validate::isLoadedObject($ctx->currency)) {
    $ctx->currency = Currency::getDefaultCurrency();
}
if (!$ctx->customer) {
    $ctx->customer = new Customer();
}
// Links wie im Frontend: ohne HTTPS-Serverumgebung würden Bild-Links sonst mit http:// erzeugt.
$proto = (int) Configuration::get('PS_SSL_ENABLED') ? 'https://' : 'http://';
$ctx->link = new Link($proto, $proto);
// Anonymer Besucher: PrestaShop legt bei jedem Seitenaufruf einen (noch nicht gespeicherten) Warenkorb an.
// Ohne ihn verweigert die Preisberechnung (Product::getPriceStatic) die Arbeit.
if (!is_object($ctx->cart)) {
    $cart = new Cart();
    $cart->id_lang = (int) $ctx->language->id;
    $cart->id_currency = (int) $ctx->currency->id;
    $cart->id_shop = (int) $ctx->shop->id;
    $cart->id_shop_group = (int) $ctx->shop->id_shop_group;
    $ctx->cart = $cart;
}

$start = microtime(true);
try {
    if ((string) Configuration::get('GEOCONVADO_CRON_ON') === '0') {
        echo json_encode(['success' => false, 'error' => 'Cron im Modul ausgeschaltet'], JSON_UNESCAPED_UNICODE) . "\n";
        exit(0);
    }
    $result = (new Generator($ctx))->run($budget, $force);
    Tracker::aggregate();
    if ((int) Configuration::get('GEOCONVADO_RV_ON')) {
        $result['reviews'] = [
            'videos' => VideoProcessor::processQueue(1, max(10, (int) Configuration::get('GEOCONVADO_RV_VIDEO_SEC'))),
        ];
        ReviewRepository::purge();
    }
    Configuration::updateGlobalValue('GEOCONVADO_LAST_CRON', date('Y-m-d H:i:s'));
    echo json_encode(['success' => true, 'sec' => round(microtime(true) - $start, 1), 'data' => $result], JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
} catch (Throwable $e) {
    PrestaShopLogger::addLog('GEO.Convado Cron (CLI): ' . $e->getMessage(), 3, null, 'geoconvado');
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE) . "\n";
    exit(1);
}

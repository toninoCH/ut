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

use GeoConvado\Service\AgentStore;
use GeoConvado\Service\Generator;
use GeoConvado\Service\ReviewRepository;
use GeoConvado\Service\Tracker;
use GeoConvado\Service\VideoProcessor;

$opts = getopt('', ['force', 'budget::']);
$force = isset($opts['force']);
$budget = isset($opts['budget']) ? max(5, (int) $opts['budget']) : 120;

// Kontext wie bei einem anonymen Seitenaufruf (Preise, Steuern, Links stimmen dann mit dem Frontend überein).
$ctx = Generator::prepareCliContext((new GeoConvado())->getShopContext());

$start = microtime(true);
try {
    if ((string) Configuration::get('GEOCONVADO_CRON_ON') === '0') {
        echo json_encode(['success' => false, 'error' => 'Cron im Modul ausgeschaltet'], JSON_UNESCAPED_UNICODE) . "\n";
        exit(0);
    }
    $result = (new Generator($ctx))->run($budget, $force);
    Tracker::aggregate();
    AgentStore::purgeExpired();
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

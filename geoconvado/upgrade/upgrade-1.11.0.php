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

use GeoConvado\Service\Generator;

/**
 * 1.11.0 (fasst die Entwicklungsschritte der Funktionen «KI-Zugang» zusammen; jeder Teil ist wiederholbar):
 * Content Signals und Markdown für Agenten, FAQ auf der CMS-Seite, robots.txt-Prüfung, strukturierte Versandangaben,
 * Agenten-Zugang (MCP, Server-Card, API-Katalog, OpenAPI, Skills-Index, WebMCP).
 *
 * @param GeoConvado $module
 */
function upgrade_module_1_11_0($module)
{
    // Schalter mit Standardwerten (setzt nur, was noch fehlt, also auch GEOCONVADO_MD_NEGOTIATION und GEOCONVADO_AGENT).
    $ok = $module->installSwitches()
        && $module->installSignalDefault()
        && $module->installFaqTable()
        && $module->registerHook(['actionOutputHTMLBefore', 'filterCmsContent', 'moduleRoutes'])
        && $module->installFaqCmsDefault();

    // Strukturierte Versandfelder aus dem bisherigen Freitext vorbelegen (nur wo noch nichts steht).
    $parsed = Generator::parseLegacyShipping((string) Configuration::get('GEOCONVADO_SHIP_COST'));
    foreach (['GEOCONVADO_SHIP_FREE_FROM' => 'free_from', 'GEOCONVADO_SHIP_FEE' => 'fee', 'GEOCONVADO_SHIP_CUTOFF' => 'cutoff', 'GEOCONVADO_SHIP_DAYS' => null] as $k => $f) {
        if (Configuration::get($k) === false) {
            Configuration::updateValue($k, $f ? $parsed[$f] : '');
        }
    }

    // Die robots.txt wird beim nächsten Cron-Lauf geprüft und bereinigt.
    return $ok && Configuration::updateGlobalValue('GEOCONVADO_DIRTY', 1);
}

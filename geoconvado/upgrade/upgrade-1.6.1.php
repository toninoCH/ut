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

/**
 * 1.6.1: Änderungsmarke mit Zeitstempel, Einkaufsinfos aus alten Vorgaben zur Prüfung markieren, neue Schalter.
 *
 * @param GeoConvado $module
 */
function upgrade_module_1_6_1($module)
{
    // Vorgaben bis 1.5.x (frei erfunden, nicht vom Händler eingetragen).
    $old = [
        'GEOCONVADO_PAYMENTS' => 'TWINT, Visa, Mastercard, Rechnung, PayPal',
        'GEOCONVADO_SHIP_ZONE' => 'Schweiz und Liechtenstein',
        'GEOCONVADO_SHIP_COST' => 'Kostenlos ab CHF 100.–, sonst CHF 9.00. Bis 14 Uhr bestellt, am nächsten Werktag geliefert.',
        'GEOCONVADO_VAT' => '8.1',
    ];
    $review = 0;
    foreach ($old as $k => $v) {
        if (trim((string) Configuration::get($k)) === $v) {
            $review = 1;
        }
    }
    Configuration::updateValue('GEOCONVADO_FACTS_REVIEW', $review);
    if (Configuration::get('GEOCONVADO_UPSELL') === false) {
        Configuration::updateValue('GEOCONVADO_UPSELL', 1);
    }

    // Alle Dateien einmal neu erzeugen: DIRTY mit Zeitstempel, sonst bleibt die Änderung unbemerkt.
    GeoConvado::markDirty();

    // Neue Ablage für Bewertungsmedien liegt ausserhalb von var/geoconvado (wird bei Deinstallation geleert).
    return true;
}

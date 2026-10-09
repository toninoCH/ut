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
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * 1.3.0: produktgenaue Kundenfragen in llms-Dateien und auf der Produktseite.
 * Erzwingt einen Neuaufbau, damit die FAQs sofort in allen Sprachen erscheinen.
 */
function upgrade_module_1_3_0($module)
{
    return Configuration::updateGlobalValue('GEOCONVADO_DIRTY', 1);
}

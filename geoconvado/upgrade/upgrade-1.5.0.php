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
 * 1.5.0: Upsell-Empfehlungen («Passt dazu») auf Produktseite und in llms-Dateien.
 */
function upgrade_module_1_5_0($module)
{
    return Configuration::updateValue('GEOCONVADO_UPSELL', 1) && Configuration::updateGlobalValue('GEOCONVADO_DIRTY', 1);
}

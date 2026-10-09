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

function upgrade_module_1_1_0($module): bool
{
    return $module->installDefaults()
        && $module->registerHook(GeoConvado::FRONT_HOOKS)
        && Configuration::updateGlobalValue(GeoConvado::CFG_DIRTY, 1);
}

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
 * 1.13.2: Front-Stylesheet wird zusätzlich im Hook actionFrontControllerSetMedia angemeldet. Keine Datenbankänderung.
 *
 * @param GeoConvado $module
 */
function upgrade_module_1_13_2($module)
{
    return true;
}

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
 * 1.6.0: Frontend-Datentabelle, Änderungs-Hooks (Aktionen, Kategorien, CMS, Marken),
 * Arbeitsordner ausserhalb des Caches, sichere robots.txt-Regeln. Bestehende Einkaufsinfos bleiben erhalten.
 */
function upgrade_module_1_6_0($module)
{
    $ok = Db::getInstance()->execute('CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'geoconvado_front` (
        `id_shop` INT UNSIGNED NOT NULL,
        `ref` VARCHAR(64) NOT NULL,
        `payload` MEDIUMTEXT NOT NULL,
        PRIMARY KEY (`id_shop`, `ref`)
    ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4')
        && $module->registerHook(GeoConvado::CHANGE_HOOKS);
    Db::getInstance()->execute('DELETE FROM `' . _DB_PREFIX_ . 'geoconvado_review_cache`');

    return $ok && Configuration::updateGlobalValue('GEOCONVADO_DIRTY', 1);
}

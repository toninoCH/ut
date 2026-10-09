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

function upgrade_module_1_2_0($module): bool
{
    $db = Db::getInstance();
    $t = _DB_PREFIX_ . 'geoconvado_bot_log';
    $cols = array_column($db->executeS('SHOW COLUMNS FROM `' . $t . '`') ?: [], 'Field');
    $add = [
        'id_shop' => 'INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`',
        'kind' => "VARCHAR(8) NOT NULL DEFAULT 'page' AFTER `bot`",
        'user_agent' => 'VARCHAR(255) NULL AFTER `ip`',
        'id_product' => 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER `path`',
    ];
    foreach ($add as $c => $def) {
        if (!in_array($c, $cols, true) && !$db->execute('ALTER TABLE `' . $t . '` ADD COLUMN `' . $c . '` ' . $def)) {
            return false;
        }
    }
    $idx = array_column($db->executeS('SHOW INDEX FROM `' . $t . '`') ?: [], 'Key_name');
    foreach (['created' => '(`created_at`)', 'kind_date' => '(`kind`, `created_at`)'] as $k => $def) {
        if (!in_array($k, $idx, true)) {
            $db->execute('ALTER TABLE `' . $t . '` ADD INDEX `' . $k . '` ' . $def);
        }
    }
    // Alte Direktkauf-Einträge aus 1.1.0 umschlüsseln
    $db->execute("UPDATE `$t` SET kind = 'buy' WHERE bot LIKE 'agent-buy%'");
    // IPs rückwirkend kürzen
    $db->execute("UPDATE `$t` SET ip = CONCAT(SUBSTRING_INDEX(ip, '.', 3), '.0') WHERE ip LIKE '%.%.%.%'");

    return $module->installStatsTable()
        && Configuration::updateValue(GeoConvado::CFG_TRACK_LLMS, 0)
        && $module->registerHook('moduleRoutes')
        && Configuration::updateGlobalValue(GeoConvado::CFG_DIRTY, 1);
}

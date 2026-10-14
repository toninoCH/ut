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

namespace GeoConvado\Service;

if (!defined('_PS_VERSION_')) {
    exit;
}

/** Speicher für Cache und Ratenbegrenzung: APCu, wenn verfügbar, sonst eine kleine Datenbanktabelle. */
final class AgentStore implements KvStore
{
    private readonly bool $apcu;

    public function __construct(?bool $useApcu = null)
    {
        $this->apcu = $useApcu ?? (function_exists('apcu_enabled') && apcu_enabled());
    }

    public static function install(): bool
    {
        return (bool) \Db::getInstance()->execute('CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'geoconvado_kv` (
            `k` CHAR(64) NOT NULL,
            `v` MEDIUMTEXT NOT NULL,
            `expires` INT UNSIGNED NOT NULL,
            PRIMARY KEY (`k`),
            KEY `expires` (`expires`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4');
    }

    public function get(string $key): ?string
    {
        if ($this->apcu) {
            $ok = false;
            $v = apcu_fetch($this->k($key), $ok);

            return $ok && is_string($v) ? $v : null;
        }
        $v = \Db::getInstance()->getValue('SELECT `v` FROM `' . _DB_PREFIX_ . "geoconvado_kv` WHERE `k` = '" . pSQL($this->k($key)) . "' AND `expires` >= " . time());

        return $v === false || $v === null ? null : (string) $v;
    }

    public function set(string $key, string $value, int $ttl): void
    {
        if ($this->apcu) {
            apcu_store($this->k($key), $value, max(1, $ttl));

            return;
        }
        if (strlen($value) > 4000000) {
            return;
        }
        \Db::getInstance()->execute('REPLACE INTO `' . _DB_PREFIX_ . "geoconvado_kv` (`k`, `v`, `expires`) VALUES ('" . pSQL($this->k($key)) . "', '"
            . pSQL($value, true) . "', " . (time() + max(1, $ttl)) . ')');
        $this->cleanup();
    }

    public function incr(string $key, int $ttl): int
    {
        $k = $this->k($key);
        if ($this->apcu) {
            apcu_add($k, 0, max(1, $ttl));
            $ok = false;
            $n = apcu_inc($k, 1, $ok);

            return $ok ? (int) $n : 1;
        }
        $now = time();
        $db = \Db::getInstance();
        $kq = pSQL($k);
        $db->execute('INSERT INTO `' . _DB_PREFIX_ . "geoconvado_kv` (`k`, `v`, `expires`) VALUES ('{$kq}', '1', " . ($now + max(1, $ttl)) . ")
            ON DUPLICATE KEY UPDATE `v` = IF(`expires` < {$now}, '1', CAST(`v` AS UNSIGNED) + 1), `expires` = IF(`expires` < {$now}, " . ($now + max(1, $ttl)) . ', `expires`)');
        $this->cleanup();

        return (int) $db->getValue('SELECT `v` FROM `' . _DB_PREFIX_ . "geoconvado_kv` WHERE `k` = '{$kq}'");
    }

    private function k(string $key): string
    {
        return strlen($key) <= 64 && !$this->apcu ? $key : ($this->apcu ? 'geoconvado:' . _DB_PREFIX_ . $key : hash('sha256', $key));
    }

    private function cleanup(): void
    {
        if (random_int(1, 200) === 1) {
            \Db::getInstance()->execute('DELETE FROM `' . _DB_PREFIX_ . 'geoconvado_kv` WHERE `expires` < ' . time() . ' LIMIT 1000');
        }
    }
}

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

/** Datenzugriff für Käuferbewertungen (Tabellen geoconvado_review, _media, _flag). */
final class ReviewRepository
{
    public const STATUSES = ['pending', 'approved', 'rejected'];
    public const REASONS = ['spam', 'abuse', 'pii', 'offtopic', 'other'];

    private static function t(string $n): string
    {
        return '`' . _DB_PREFIX_ . 'geoconvado_' . $n . '`';
    }

    private static function q(string $s): string
    {
        return "'" . pSQL($s, true) . "'";
    }

    /** Tabellen anlegen (idempotent, auch von der Aktualisierung verwendet). */
    public static function install(): bool
    {
        $e = _MYSQL_ENGINE_;
        $sql = [
            'CREATE TABLE IF NOT EXISTS ' . self::t('review') . " (
                `id_review` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_shop` INT UNSIGNED NOT NULL DEFAULT 1,
                `id_product` INT UNSIGNED NOT NULL,
                `id_customer` INT UNSIGNED NOT NULL,
                `id_order` INT UNSIGNED NOT NULL DEFAULT 0,
                `id_lang` INT UNSIGNED NOT NULL DEFAULT 1,
                `rating` TINYINT UNSIGNED NOT NULL,
                `title` VARCHAR(120) NOT NULL DEFAULT '',
                `body` TEXT NOT NULL,
                `display_name` VARCHAR(60) NOT NULL DEFAULT '',
                `status` VARCHAR(12) NOT NULL DEFAULT 'pending',
                `reject_reason` VARCHAR(16) NOT NULL DEFAULT '',
                `verified` TINYINT UNSIGNED NOT NULL DEFAULT 1,
                `incentivised` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `consent_quote` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `voucher_id` INT NOT NULL DEFAULT 0,
                `blog_url` VARCHAR(255) NOT NULL DEFAULT '',
                `helpful` INT UNSIGNED NOT NULL DEFAULT 0,
                `reports` INT UNSIGNED NOT NULL DEFAULT 0,
                `body_hash` CHAR(40) NOT NULL DEFAULT '',
                `ip_hash` CHAR(32) NOT NULL DEFAULT '',
                `created_at` DATETIME NOT NULL,
                `moderated_at` DATETIME NULL,
                PRIMARY KEY (`id_review`),
                UNIQUE KEY `cust_prod` (`id_shop`, `id_customer`, `id_product`),
                KEY `prod_status` (`id_shop`, `id_product`, `status`, `created_at`),
                KEY `status_date` (`status`, `created_at`),
                KEY `ip_date` (`ip_hash`, `created_at`),
                KEY `body_hash` (`body_hash`)
            ) ENGINE={$e} DEFAULT CHARSET=utf8mb4",
            'CREATE TABLE IF NOT EXISTS ' . self::t('review_media') . " (
                `id_media` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_review` INT UNSIGNED NOT NULL,
                `kind` VARCHAR(5) NOT NULL,
                `file` VARCHAR(64) NOT NULL,
                `thumb` VARCHAR(64) NOT NULL DEFAULT '',
                `src` VARCHAR(64) NOT NULL DEFAULT '',
                `mime` VARCHAR(24) NOT NULL,
                `bytes` INT UNSIGNED NOT NULL DEFAULT 0,
                `w` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                `h` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                `duration` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                `state` VARCHAR(10) NOT NULL DEFAULT 'ready',
                `created_at` DATETIME NOT NULL,
                PRIMARY KEY (`id_media`),
                KEY `review` (`id_review`),
                KEY `state` (`state`)
            ) ENGINE={$e} DEFAULT CHARSET=utf8mb4",
            'CREATE TABLE IF NOT EXISTS ' . self::t('review_flag') . " (
                `id_review` INT UNSIGNED NOT NULL,
                `voter` VARCHAR(40) NOT NULL,
                `type` VARCHAR(8) NOT NULL,
                `created_at` DATETIME NOT NULL,
                PRIMARY KEY (`id_review`, `voter`, `type`)
            ) ENGINE={$e} DEFAULT CHARSET=utf8mb4",
        ];
        foreach ($sql as $q) {
            if (!\Db::getInstance()->execute($q)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $d @return int neue ID oder 0 */
    public static function create(array $d): int
    {
        $db = \Db::getInstance();
        $ok = $db->execute('INSERT INTO ' . self::t('review') . ' (id_shop, id_product, id_customer, id_order, id_lang, rating, title, body, display_name,
            status, verified, incentivised, consent_quote, body_hash, ip_hash, created_at) VALUES ('
            . (int) $d['id_shop'] . ', ' . (int) $d['id_product'] . ', ' . (int) $d['id_customer'] . ', ' . (int) $d['id_order'] . ', ' . (int) $d['id_lang'] . ', '
            . max(1, min(5, (int) $d['rating'])) . ', ' . self::q(mb_substr((string) $d['title'], 0, 120)) . ', ' . self::q((string) $d['body']) . ', '
            . self::q(mb_substr((string) $d['display_name'], 0, 60)) . ", '" . pSQL((string) ($d['status'] ?? 'pending')) . "', 1, "
            . (int) !empty($d['incentivised']) . ', ' . (int) !empty($d['consent_quote']) . ", '" . pSQL((string) $d['body_hash']) . "', '" . pSQL((string) $d['ip_hash']) . "', '"
            . date('Y-m-d H:i:s') . "')");

        return $ok ? (int) $db->Insert_ID() : 0;
    }

    public static function addMedia(int $idReview, array $m): int
    {
        $db = \Db::getInstance();
        $ok = $db->execute('INSERT INTO ' . self::t('review_media') . ' (id_review, kind, file, thumb, src, mime, bytes, w, h, duration, state, created_at) VALUES ('
            . $idReview . ", '" . pSQL((string) $m['kind']) . "', '" . pSQL((string) $m['file']) . "', '" . pSQL((string) ($m['thumb'] ?? '')) . "', '"
            . pSQL((string) ($m['src'] ?? '')) . "', '" . pSQL((string) $m['mime']) . "', " . (int) ($m['bytes'] ?? 0) . ', ' . (int) ($m['w'] ?? 0) . ', '
            . (int) ($m['h'] ?? 0) . ', ' . (int) ($m['duration'] ?? 0) . ", '" . pSQL((string) ($m['state'] ?? 'ready')) . "', '" . date('Y-m-d H:i:s') . "')");

        return $ok ? (int) $db->Insert_ID() : 0;
    }

    /** Wie viele Bewertungen hat dieser Kunde / diese IP in den letzten $hours Stunden abgegeben? */
    public static function recent(int $idCustomer, string $ipHash, int $hours): array
    {
        $db = \Db::getInstance();
        $since = date('Y-m-d H:i:s', time() - $hours * 3600);

        return [
            'customer' => (int) $db->getValue('SELECT COUNT(*) FROM ' . self::t('review') . ' WHERE id_customer = ' . $idCustomer . " AND created_at > '" . $since . "'"),
            'ip' => $ipHash === '' ? 0 : (int) $db->getValue('SELECT COUNT(*) FROM ' . self::t('review') . " WHERE ip_hash = '" . pSQL($ipHash) . "' AND created_at > '" . $since . "'"),
        ];
    }

    public static function duplicate(string $bodyHash, int $days = 30): bool
    {
        return (int) \Db::getInstance()->getValue('SELECT COUNT(*) FROM ' . self::t('review') . " WHERE body_hash = '" . pSQL($bodyHash) . "' AND created_at > '"
            . date('Y-m-d H:i:s', time() - $days * 86400) . "'") > 0;
    }

    /** @return array{count: int, avg: float, inc: int, organic: int, organic_avg: float, hist: array<int, int>} */
    public static function stats(int $idProduct, int $idShop): array
    {
        $rows = \Db::getInstance()->executeS('SELECT rating, incentivised, COUNT(*) AS n FROM ' . self::t('review')
            . " WHERE id_shop = $idShop AND id_product = $idProduct AND status = 'approved' GROUP BY rating, incentivised") ?: [];

        return self::fold($rows);
    }

    /** Alle Artikel eines Shops auf einmal (für llms.txt, eine Abfrage statt eine pro Artikel). @return array<int, array> */
    public static function statsAll(int $idShop): array
    {
        $rows = \Db::getInstance()->executeS('SELECT id_product, rating, incentivised, COUNT(*) AS n FROM ' . self::t('review')
            . " WHERE id_shop = $idShop AND status = 'approved' GROUP BY id_product, rating, incentivised") ?: [];
        $by = [];
        foreach ($rows as $r) {
            $by[(int) $r['id_product']][] = $r;
        }

        return array_map([self::class, 'fold'], $by);
    }

    private static function fold(array $rows): array
    {
        $s = ['count' => 0, 'avg' => 0.0, 'inc' => 0, 'organic' => 0, 'organic_avg' => 0.0, 'hist' => [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0]];
        $sum = 0;
        $osum = 0;
        foreach ($rows as $r) {
            $n = (int) $r['n'];
            $v = max(1, min(5, (int) $r['rating']));
            $s['count'] += $n;
            $sum += $v * $n;
            $s['hist'][$v] += $n;
            if ((int) $r['incentivised'] === 1) {
                $s['inc'] += $n;
            } else {
                $s['organic'] += $n;
                $osum += $v * $n;
            }
        }
        $s['avg'] = $s['count'] ? round($sum / $s['count'], 1) : 0.0;
        $s['organic_avg'] = $s['organic'] ? round($osum / $s['organic'], 1) : 0.0;

        return $s;
    }

    /** Freigegebene Bewertungen mit Medien. @return array<int, array> */
    public static function visible(int $idProduct, int $idShop, int $limit = 5, int $offset = 0, string $sort = 'new'): array
    {
        $order = $sort === 'helpful' ? 'helpful DESC, created_at DESC' : 'created_at DESC';
        $rows = \Db::getInstance()->executeS('SELECT * FROM ' . self::t('review') . " WHERE id_shop = $idShop AND id_product = $idProduct AND status = 'approved'
            ORDER BY $order, id_review DESC LIMIT " . max(1, $limit) . ' OFFSET ' . max(0, $offset)) ?: [];

        return self::withMedia($rows, true);
    }

    /** @param array<int, array> $rows */
    private static function withMedia(array $rows, bool $readyOnly): array
    {
        if ($rows === []) {
            return [];
        }
        $ids = implode(',', array_map(static fn (array $r): int => (int) $r['id_review'], $rows));
        $m = \Db::getInstance()->executeS('SELECT * FROM ' . self::t('review_media') . " WHERE id_review IN ($ids)" . ($readyOnly ? " AND state = 'ready'" : '') . ' ORDER BY kind DESC, id_media') ?: [];
        $by = [];
        foreach ($m as $x) {
            $by[(int) $x['id_review']][] = $x;
        }
        foreach ($rows as &$r) {
            $r['media'] = $by[(int) $r['id_review']] ?? [];
        }
        unset($r);

        return $rows;
    }

    public static function find(int $id): ?array
    {
        $r = \Db::getInstance()->getRow('SELECT * FROM ' . self::t('review') . ' WHERE id_review = ' . $id);

        return $r ? (self::withMedia([$r], false)[0]) : null;
    }

    public static function findMedia(int $idMedia): ?array
    {
        $m = \Db::getInstance()->getRow('SELECT m.*, r.status AS review_status, r.id_customer FROM ' . self::t('review_media') . ' m
            JOIN ' . self::t('review') . ' r ON r.id_review = m.id_review WHERE m.id_media = ' . $idMedia);

        return $m ?: null;
    }

    /** @return array{rows: array<int, array>, total: int} */
    public static function adminList(string $status, int $page = 1, int $per = 25): array
    {
        $where = in_array($status, self::STATUSES, true) ? "WHERE r.status = '" . pSQL($status) . "'" : '';
        $db = \Db::getInstance();
        $total = (int) $db->getValue('SELECT COUNT(*) FROM ' . self::t('review') . ' r ' . $where);
        $rows = $db->executeS('SELECT r.*, pl.name AS product_name FROM ' . self::t('review') . ' r
            LEFT JOIN `' . _DB_PREFIX_ . 'product_lang` pl ON pl.id_product = r.id_product AND pl.id_lang = r.id_lang AND pl.id_shop = r.id_shop ' . $where
            . ' ORDER BY (r.status = \'pending\') DESC, r.reports DESC, r.created_at DESC LIMIT ' . $per . ' OFFSET ' . (max(1, $page) - 1) * $per) ?: [];

        return ['rows' => self::withMedia($rows, false), 'total' => $total];
    }

    public static function counts(): array
    {
        $out = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'reported' => 0];
        foreach (\Db::getInstance()->executeS('SELECT status, COUNT(*) AS n FROM ' . self::t('review') . ' GROUP BY status') ?: [] as $r) {
            $out[(string) $r['status']] = (int) $r['n'];
        }
        $out['reported'] = (int) \Db::getInstance()->getValue('SELECT COUNT(*) FROM ' . self::t('review') . " WHERE reports > 0 AND status = 'approved'");

        return $out;
    }

    public static function setStatus(int $id, string $status, string $reason = ''): bool
    {
        if (!in_array($status, self::STATUSES, true)) {
            return false;
        }
        $reason = $status === 'rejected' && in_array($reason, self::REASONS, true) ? $reason : '';

        return (bool) \Db::getInstance()->execute('UPDATE ' . self::t('review') . " SET status = '" . pSQL($status) . "', reject_reason = '" . pSQL($reason) . "', moderated_at = '" . date('Y-m-d H:i:s') . "'"
            . ($status === 'approved' ? ', reports = 0' : '') . ' WHERE id_review = ' . $id);
    }

    public static function setBlogUrl(int $id, string $url): bool
    {
        return (bool) \Db::getInstance()->execute('UPDATE ' . self::t('review') . " SET blog_url = '" . pSQL(ReviewGuard::httpsUrl($url)) . "' WHERE id_review = " . $id);
    }

    /** Gutschein-Anspruch atomar sichern: nur ein Aufruf bekommt die Zeile (verhindert doppelte Gutscheine). */
    public static function claimVoucher(int $id): bool
    {
        $db = \Db::getInstance();
        $db->execute('UPDATE ' . self::t('review') . ' SET voucher_id = -1 WHERE id_review = ' . $id . " AND voucher_id = 0 AND incentivised = 1 AND status = 'approved'");

        return (int) $db->Affected_Rows() === 1;
    }

    public static function setVoucher(int $id, int $idCartRule): void
    {
        \Db::getInstance()->execute('UPDATE ' . self::t('review') . ' SET voucher_id = ' . $idCartRule . ' WHERE id_review = ' . $id);
    }

    /** Wie viele Gutscheine hat der Kunde in den letzten 365 Tagen bekommen? */
    public static function vouchersLastYear(int $idCustomer): int
    {
        return (int) \Db::getInstance()->getValue('SELECT COUNT(*) FROM ' . self::t('review') . ' WHERE id_customer = ' . $idCustomer
            . " AND voucher_id > 0 AND moderated_at > '" . date('Y-m-d H:i:s', time() - 365 * 86400) . "'");
    }

    /** Hilfreich- oder Melde-Stimme. @return bool true = neu gezählt */
    public static function flag(int $idReview, string $voter, string $type): bool
    {
        if (!in_array($type, ['helpful', 'report'], true)) {
            return false;
        }
        $db = \Db::getInstance();
        $db->execute('INSERT IGNORE INTO ' . self::t('review_flag') . ' (id_review, voter, type, created_at) VALUES (' . $idReview . ", '" . pSQL(substr($voter, 0, 40)) . "', '" . pSQL($type) . "', '" . date('Y-m-d H:i:s') . "')");
        if ((int) $db->Affected_Rows() !== 1) {
            return false;
        }
        $col = $type === 'helpful' ? 'helpful' : 'reports';
        $db->execute('UPDATE ' . self::t('review') . " SET $col = $col + 1 WHERE id_review = " . $idReview);

        return true;
    }

    /** Eine Bewertung samt Mediendateien und Stimmen löschen. */
    public static function delete(int $id): void
    {
        $db = \Db::getInstance();
        foreach ($db->executeS('SELECT file, thumb, src FROM ' . self::t('review_media') . ' WHERE id_review = ' . $id) ?: [] as $m) {
            foreach (['file', 'thumb', 'src'] as $k) {
                if ($m[$k] !== '') {
                    MediaProcessor::remove((string) $m[$k]);
                }
            }
        }
        $db->execute('DELETE FROM ' . self::t('review_media') . ' WHERE id_review = ' . $id);
        $db->execute('DELETE FROM ' . self::t('review_flag') . ' WHERE id_review = ' . $id);
        $db->execute('DELETE FROM ' . self::t('review') . ' WHERE id_review = ' . $id);
    }

    /** IP-Hashes nach 30 Tagen leeren (Datensparsamkeit). */
    public static function purge(): void
    {
        \Db::getInstance()->execute('UPDATE ' . self::t('review') . " SET ip_hash = '' WHERE ip_hash <> '' AND created_at < '" . date('Y-m-d H:i:s', time() - 30 * 86400) . "'");
        \Db::getInstance()->execute('DELETE FROM ' . self::t('review_flag') . " WHERE type = 'report' AND voter LIKE 'g%' AND created_at < '" . date('Y-m-d H:i:s', time() - 30 * 86400) . "'");
    }

    /** @return int[] Review-IDs eines Kunden */
    public static function idsOfCustomer(int $idCustomer): array
    {
        return array_map('intval', array_column(\Db::getInstance()->executeS('SELECT id_review FROM ' . self::t('review') . ' WHERE id_customer = ' . $idCustomer) ?: [], 'id_review'));
    }

    /** Auskunft nach nDSG/DSGVO: alle eigenen Daten. */
    public static function export(int $idCustomer): array
    {
        $out = [];
        foreach (self::idsOfCustomer($idCustomer) as $id) {
            $r = self::find($id);
            if ($r) {
                $out[] = [
                    'Artikel-ID' => $r['id_product'], 'Datum' => $r['created_at'], 'Note' => $r['rating'], 'Titel' => $r['title'], 'Text' => $r['body'],
                    'Status' => $r['status'], 'Einwilligung Zitat' => $r['consent_quote'] ? 'ja' : 'nein', 'Dateien' => count($r['media']),
                ];
            }
        }

        return $out;
    }

    public static function dropTables(): void
    {
        foreach (['review', 'review_media', 'review_flag'] as $n) {
            \Db::getInstance()->execute('DROP TABLE IF EXISTS ' . self::t($n));
        }
    }
}

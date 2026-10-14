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

final class Tracker
{
    /**
     * page = Seitenaufruf eines KI-Crawlers, llms = Datei- oder MCP-Abruf, buy = in den Warenkorb gelegt,
     * bview = Bestätigungsseite nur angesehen (zählt nicht als Kauf), ref = Besucher aus einem KI-Assistenten (Mensch).
     */
    public const KINDS = ['page', 'llms', 'buy', 'bview', 'ref'];
    /** Arten, die als KI-Zugriff zählen (ohne Bestätigungsseite und ohne menschliche Besucher). */
    public const BOT_KINDS = "'page','llms','buy'";

    public static function log(int $idShop, string $bot, string $kind, string $path, int $idProduct = 0): void
    {
        try {
            \Db::getInstance()->insert('geoconvado_bot_log', [
                'id_shop' => max(1, $idShop),
                'bot' => pSQL(substr($bot, 0, 32)),
                'kind' => pSQL(in_array($kind, self::KINDS, true) ? $kind : 'page'),
                'ip' => pSQL(self::anonymize((string) \Tools::getRemoteAddr())),
                'user_agent' => pSQL(substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255)),
                'path' => pSQL(substr($path, 0, 255)),
                'id_product' => $idProduct,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // Tracking darf nie eine Seite stören
        }
    }

    /** IPv4: letzter Block 0, IPv6: nur die ersten 3 Gruppen (nDSG-konform). */
    public static function anonymize(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return (string) preg_replace('/\.\d+$/', '.0', $ip);
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return implode(':', array_slice(explode(':', $ip), 0, 3)) . '::';
        }

        return '';
    }

    /**
     * Tageswerte verdichten (idempotent) und alte Rohdaten löschen. Verdichtet wird ab dem letzten verdichteten Tag
     * (minus ein Tag für spät eintreffende Zeilen), damit auch nach längeren Cron-Ausfällen kein Tag fehlt.
     */
    public static function aggregate(): void
    {
        $p = _DB_PREFIX_;
        $db = \Db::getInstance();
        $last = (string) \Configuration::getGlobalValue('GEOCONVADO_AGG_DAY');
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $last) ? "DATE_SUB('" . pSQL($last) . "', INTERVAL 1 DAY)" : 'DATE_SUB(CURDATE(), INTERVAL 90 DAY)';
        $db->execute("INSERT INTO `{$p}geoconvado_stats_daily` (`day`, `id_shop`, `bot`, `kind`, `hits`)
            SELECT DATE(created_at), id_shop, bot, kind, COUNT(*) FROM `{$p}geoconvado_bot_log`
            WHERE created_at >= {$from}
            GROUP BY DATE(created_at), id_shop, bot, kind
            ON DUPLICATE KEY UPDATE hits = VALUES(hits)");
        \Configuration::updateGlobalValue('GEOCONVADO_AGG_DAY', date('Y-m-d'));
        $db->execute("DELETE FROM `{$p}geoconvado_bot_log` WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
    }

    /** Kennzahlen, Verlauf, Anbieter und Top-Seiten fürs Dashboard. */
    public static function dashboard(): array
    {
        self::aggregate();
        $p = _DB_PREFIX_;
        $db = \Db::getInstance();
        $sum = static fn (string $where): int => (int) $db->getValue("SELECT COALESCE(SUM(hits),0) FROM `{$p}geoconvado_stats_daily` WHERE $where");
        $cur = 'day > DATE_SUB(CURDATE(), INTERVAL 30 DAY)';
        $prev = 'day <= DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND day > DATE_SUB(CURDATE(), INTERVAL 60 DAY)';

        $kpi = [];
        foreach (['all' => 'kind IN (' . self::BOT_KINDS . ')', 'page' => "kind='page'", 'llms' => "kind='llms'", 'buy' => "kind='buy'"] as $k => $w) {
            $a = $sum("$cur AND $w");
            $b = $sum("$prev AND $w");
            $kpi[$k] = ['value' => $a, 'delta' => $b > 0 ? (int) round(($a - $b) / $b * 100) : null];
        }

        $providers = $db->executeS("SELECT bot, SUM(hits) AS hits FROM `{$p}geoconvado_stats_daily` WHERE $cur AND kind IN ('page','llms')
            GROUP BY bot ORDER BY hits DESC LIMIT 8") ?: [];
        $max = max(1, (int) ($providers[0]['hits'] ?? 1));
        foreach ($providers as &$r) {
            $r['pct'] = (int) round($r['hits'] / $max * 100);
        }
        unset($r);

        // Verlauf 30 Tage als SVG-Balken
        $rows = $db->executeS("SELECT day, SUM(hits) AS hits FROM `{$p}geoconvado_stats_daily` WHERE $cur AND kind IN (" . self::BOT_KINDS . ') GROUP BY day') ?: [];
        $byDay = array_column($rows, 'hits', 'day');
        $series = [];
        for ($i = 29; $i >= 0; --$i) {
            $d = date('Y-m-d', strtotime("-$i day"));
            $series[] = ['day' => $d, 'hits' => (int) ($byDay[$d] ?? 0)];
        }
        $peak = max(1, max(array_column($series, 'hits')));
        foreach ($series as $i => &$s) {
            $s['h'] = (int) round($s['hits'] / $peak * 100);
            $s['x'] = $i * 20;
        }
        unset($s);

        $top = $db->executeS("SELECT path, kind, COUNT(*) AS hits FROM `{$p}geoconvado_bot_log`
            WHERE created_at > DATE_SUB(NOW(), INTERVAL 30 DAY) AND kind IN ('page','llms')
            GROUP BY path, kind ORDER BY hits DESC LIMIT 8") ?: [];

        // Letzte Abrufe (Rohdaten, 90 Tage): Zeit, KI, Art, Datei/Seite, anonymisierte IP, User-Agent.
        $recent = $db->executeS("SELECT created_at, bot, kind, path, ip, user_agent FROM `{$p}geoconvado_bot_log`
            WHERE kind IN ('page','llms','buy','ref') ORDER BY id DESC LIMIT 40") ?: [];

        return [
            'recent' => $recent,
            'kpi' => $kpi,
            'top_bot' => $providers[0]['bot'] ?? null,
            'providers' => $providers,
            'series' => $series,
            'peak' => $peak,
            'top' => $top,
            'has_data' => $kpi['all']['value'] > 0,
        ];
    }
}

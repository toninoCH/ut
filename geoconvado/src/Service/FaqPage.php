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

if (!defined('_PS_VERSION_') && !defined('GEOCONVADO_TEST')) {
    exit;
}

/**
 * Hilfe-/FAQ-Seite im Shop: holt das Fragment (Design, Texte, FAQPage-JSON-LD) aus dem AIO und setzt es in die
 * gewählte CMS-Seite ein. Nie ein Netzwerkaufruf ohne Zeitlimit, bei Fehlern gilt der letzte gute Stand.
 */
final class FaqPage
{
    public const CFG_CMS = 'GEOCONVADO_FAQ_CMS';
    public const TOKEN = '{geoconvado_faq}';
    private const TTL = 600;
    private const RETRY = 300;
    private const MAX_AGE = 2592000;
    private const MAX_BYTES = 2000000;

    public function __construct(private readonly string $endpoint = '')
    {
    }

    private function url(): string
    {
        return $this->endpoint !== '' ? $this->endpoint : AioClient::endpoint('faq-page');
    }

    /**
     * Fragment der Seite für Sprache $iso, oder null (dann bleibt der bisherige CMS-Inhalt stehen).
     *
     * @return array{html: string, title: string, description: string, lastmod: ?string, count: int}|null
     */
    public function get(int $idShop, string $iso, string $canonical): ?array
    {
        $key = (string) \Configuration::get('GEOCONVADO_AIO_KEY', null, null, $idShop);
        if ($key === '' || !preg_match('/^[a-z]{2}$/', $iso)) {
            return null;
        }
        $db = \Db::getInstance();
        $t = _DB_PREFIX_ . 'geoconvado_faqpage';
        $row = $db->getRow('SELECT payload, canonical, fetched_at, retry_at FROM `' . $t . '` WHERE id_shop = ' . $idShop . " AND iso = '" . pSQL($iso) . "'") ?: null;

        $age = $row ? time() - (int) strtotime((string) $row['fetched_at']) : PHP_INT_MAX;
        $fresh = $row && $age < self::TTL && (string) $row['canonical'] === $canonical;
        $paused = $row && (int) strtotime((string) $row['retry_at']) > time();
        if (!$fresh && !$paused) {
            $live = $this->fetchRemote($key, $iso, $canonical);
            if ($live !== null) {
                $db->execute('REPLACE INTO `' . $t . '` (id_shop, iso, payload, canonical, fetched_at, retry_at) VALUES (' . $idShop . ", '" . pSQL($iso) . "', '"
                    . pSQL((string) json_encode($live, JSON_UNESCAPED_UNICODE), true) . "', '" . pSQL($canonical) . "', '" . date('Y-m-d H:i:s') . "', '1970-01-02 00:00:00')");

                return $live;
            }
            if ($row) {
                $db->execute('UPDATE `' . $t . "` SET retry_at = '" . date('Y-m-d H:i:s', time() + self::RETRY) . "' WHERE id_shop = " . $idShop . " AND iso = '" . pSQL($iso) . "'");
            }
        }
        if (!$row || $age > self::MAX_AGE) {
            return null;
        }
        $data = json_decode((string) $row['payload'], true);

        return is_array($data) && isset($data['html']) ? $data : null;
    }

    /** @return array{html: string, title: string, description: string, lastmod: ?string, count: int}|null */
    public function fetchRemote(string $key, string $iso, string $canonical): ?array
    {
        $ch = curl_init($this->url() . '?' . http_build_query(['format' => 'fragment', 'lang' => $iso, 'canonical' => $canonical]));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 4,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_HTTPHEADER => ['X-Geo-Key: ' . $key, 'Accept: application/json'],
            CURLOPT_MAXFILESIZE => self::MAX_BYTES,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);

        return $status === 200 && is_string($body) ? self::parse($body, $iso) : null;
    }

    /** Antwort prüfen: nur Fragmente in der gewünschten Sprache mit Inhalt werden übernommen. */
    public static function parse(string $body, string $iso): ?array
    {
        if (strlen($body) > self::MAX_BYTES) {
            return null;
        }
        $json = json_decode($body, true);
        $d = is_array($json) && !empty($json['success']) && is_array($json['data'] ?? null) ? $json['data'] : null;
        if ($d === null || ($d['lang'] ?? '') !== $iso || (int) ($d['count'] ?? 0) < 1 || !is_string($d['html'] ?? null) || $d['html'] === '') {
            return null;
        }

        return [
            'html' => $d['html'],
            'title' => (string) ($d['title'] ?? ''),
            'description' => (string) ($d['description'] ?? ''),
            'lastmod' => isset($d['lastmod']) ? (string) $d['lastmod'] : null,
            'count' => (int) $d['count'],
        ];
    }

    /** Enthält der CMS-Text die Marke {geoconvado_faq}, wird nur sie ersetzt, sonst der ganze Inhalt. */
    public static function inject(string $original, string $fragment): string
    {
        return str_contains($original, self::TOKEN) ? str_replace(self::TOKEN, $fragment, $original) : $fragment;
    }
}

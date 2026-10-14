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

final class AioClient
{
    public const ENDPOINT = 'https://pklhuswpdxdixkmtgxqf.supabase.co/functions/v1/geo-reviews-export';
    /** Standard-Basisadresse des Convado AIO; überschreibbar mit GEOCONVADO_AIO_URL (z. B. für STAGE oder eigenen Betrieb). */
    public const DEFAULT_BASE = 'https://pklhuswpdxdixkmtgxqf.supabase.co/functions/v1';
    public const CFG_URL = 'GEOCONVADO_AIO_URL';
    private const CACHE_TTL = 3600;
    /** Nach einem Fehler 15 Min. Pause, danach höchstens 30 Tage alte Daten verwenden. */
    private const BACKOFF = 900;
    private const MAX_AGE = 2592000;

    /**
     * @return array{shop: array, products: array, faqs: array, source: string}
     */
    public function fetch(int $idShop, bool $force = false): array
    {
        $empty = ['shop' => [], 'products' => [], 'faqs' => [], 'source' => 'none'];
        $cached = \Db::getInstance()->getRow('SELECT payload, fetched_at FROM `' . _DB_PREFIX_ . 'geoconvado_review_cache` WHERE id_shop = ' . $idShop);

        if (!$force && $cached && (time() - strtotime((string) $cached['fetched_at'])) < self::CACHE_TTL) {
            return $this->decode((string) $cached['payload'], 'cache') ?? $empty;
        }

        $key = (string) \Configuration::get('GEOCONVADO_AIO_KEY', null, null, $idShop);
        $pauseUntil = (int) \Configuration::getGlobalValue('GEOCONVADO_AIO_PAUSE');
        if ($key !== '' && ($force || time() >= $pauseUntil)) {
            $live = $this->request($key);
            if ($live === null) {
                \Configuration::updateGlobalValue('GEOCONVADO_AIO_PAUSE', time() + self::BACKOFF);
            }
            if ($live !== null) {
                $this->storeFront($idShop, $live);
                \Db::getInstance()->execute('REPLACE INTO `' . _DB_PREFIX_ . 'geoconvado_review_cache` (id_shop, payload, fetched_at) VALUES ('
                    . $idShop . ", '" . pSQL(json_encode($live, JSON_UNESCAPED_UNICODE), true) . "', '" . date('Y-m-d H:i:s') . "')");
                $live['source'] = 'live';

                return $live + $empty;
            }
        }

        if (!$cached || (time() - strtotime((string) $cached['fetched_at'])) > self::MAX_AGE) {
            return $empty;
        }

        return $this->decode((string) $cached['payload'], 'cache') ?? $empty;
    }

    /** Kleine Datensätze fürs Frontend: Shopdaten und je Artikelnummer eine Zeile. */
    private function storeFront(int $idShop, array $data): void
    {
        $db = \Db::getInstance();
        $t = _DB_PREFIX_ . 'geoconvado_front';
        // Erst einfügen/aktualisieren, danach Veraltetes löschen: das Frontend sieht nie eine leere Tabelle.
        $old = array_column($db->executeS('SELECT ref FROM `' . $t . '` WHERE id_shop = ' . $idShop) ?: [], 'ref');
        $new = [''];
        $rows = ['(' . $idShop . ", '', '" . pSQL((string) json_encode($data['shop'] ?? [], JSON_UNESCAPED_UNICODE), true) . "')"];
        foreach ((array) ($data['products'] ?? []) as $ref => $p) {
            $ref = mb_substr((string) $ref, 0, 64);
            $new[] = $ref;
            $rows[] = '(' . $idShop . ", '" . pSQL($ref) . "', '" . pSQL((string) json_encode($p, JSON_UNESCAPED_UNICODE), true) . "')";
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            $db->execute('INSERT INTO `' . $t . '` (id_shop, ref, payload) VALUES ' . implode(',', $chunk) . ' ON DUPLICATE KEY UPDATE payload = VALUES(payload)');
        }
        foreach (array_chunk(array_diff($old, $new), 200) as $chunk) {
            $db->execute('DELETE FROM `' . $t . '` WHERE id_shop = ' . $idShop . " AND ref IN ('" . implode("','", array_map('pSQL', $chunk)) . "')");
        }
    }

    public function cachedShop(int $idShop): array
    {
        return $this->front($idShop, '');
    }

    public function cachedProduct(int $idShop, string $ref): array
    {
        return $this->front($idShop, $ref);
    }

    private function front(int $idShop, string $ref): array
    {
        $v = \Db::getInstance()->getValue('SELECT payload FROM `' . _DB_PREFIX_ . 'geoconvado_front` WHERE id_shop = ' . $idShop . " AND ref = '" . pSQL($ref) . "'");
        $d = $v ? json_decode((string) $v, true) : null;

        return is_array($d) ? $d : [];
    }

    /** Nur Cache lesen (Frontend). Nie ein Netzwerkaufruf. */
    public function cachedOnly(int $idShop): array
    {
        $payload = \Db::getInstance()->getValue('SELECT payload FROM `' . _DB_PREFIX_ . 'geoconvado_review_cache` WHERE id_shop = ' . $idShop);

        return ($payload ? $this->decode((string) $payload, 'cache') : null) ?? ['shop' => [], 'products' => [], 'faqs' => [], 'source' => 'none'];
    }

    /** Verbindungstest fürs Backoffice. */
    public function test(string $key): array
    {
        $data = $this->request($key, $error);

        return $data === null
            ? ['ok' => false, 'error' => $error ?: 'Keine Antwort']
            : ['ok' => true, 'shop' => $data['shop']['name'] ?? '', 'products' => count($data['products'] ?? []), 'faqs' => count($data['faqs'] ?? [])];
    }

    /** Adresse einer AIO-Funktion (Basis aus der Einstellung, sonst Standard). */
    public static function endpoint(string $function): string
    {
        return self::base((string) \Configuration::get(self::CFG_URL)) . '/' . $function;
    }

    /** Gültige Basisadresse (nur https, ohne Abfrage und abschliessenden Schrägstrich), sonst der Standard. */
    public static function base(string $configured): string
    {
        $u = rtrim(trim($configured), '/');

        return preg_match('#^https://[a-z0-9.\-]+(:\d{1,5})?(/[A-Za-z0-9._~\-/]*)?$#i', $u) ? $u : self::DEFAULT_BASE;
    }

    /**
     * Übersetzt einen Text in die Zielsprachen (über den AIO, Ergebnis wird im Shop gespeichert).
     *
     * @return array{ok: bool, translations?: array, error?: string}
     */
    public function translate(string $key, string $text, string $source, array $targets): array
    {
        $ch = curl_init(self::endpoint('geo-translate'));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER => ['X-Geo-Key: ' . $key, 'Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_POSTFIELDS => (string) json_encode(['text' => $text, 'source' => $source, 'targets' => array_values($targets)]),
        ]);
        $body = curl_exec($ch);
        unset($ch); // curl_close() ist seit PHP 8.0 wirkungslos und ab 8.5 veraltet
        $json = is_string($body) ? json_decode($body, true) : null;
        if (!is_array($json) || empty($json['success'])) {
            return ['ok' => false, 'error' => (string) ($json['error'] ?? 'Keine Antwort vom AIO')];
        }

        return ['ok' => true, 'translations' => (array) ($json['data']['translations'] ?? [])];
    }

    private function request(string $key, ?string &$error = null): ?array
    {
        $ch = curl_init(self::endpoint('geo-reviews-export'));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_HTTPHEADER => ['X-Geo-Key: ' . $key, 'Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch) ?: null;
        unset($ch); // curl_close() ist seit PHP 8.0 wirkungslos und ab 8.5 veraltet

        if (!is_string($body) || $status !== 200) {
            $decoded = is_string($body) ? json_decode($body, true) : null;
            $error = $decoded['error'] ?? $error ?? ('HTTP ' . $status);

            return null;
        }
        $json = json_decode($body, true);
        if (!is_array($json) || empty($json['success']) || !is_array($json['data'] ?? null)) {
            $error = $json['error'] ?? 'Ungültige Antwort';

            return null;
        }

        return $json['data'];
    }

    private function decode(string $payload, string $source): ?array
    {
        $data = json_decode($payload, true);
        if (!is_array($data)) {
            return null;
        }
        $data['source'] = $source;

        return $data + ['shop' => [], 'products' => [], 'faqs' => []];
    }
}

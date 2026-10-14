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
 * MCP-Server (JSON-RPC 2.0, Streamable HTTP, nur POST mit JSON-Antwort, ohne SSE und ohne Sitzungen).
 * Nur lesende Werkzeuge: kein Warenkorb, keine Bestellung, keine Konten. Spezifikation: modelcontextprotocol.io.
 */
final class McpServer
{
    public const PROTOCOL_VERSION = '2025-11-25';
    /** Unterstützte Versionen, neueste zuerst. */
    public const SUPPORTED = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];
    /** Ohne MCP-Protocol-Version-Header gilt laut Spezifikation 2025-03-26. */
    public const DEFAULT_HEADER_VERSION = '2025-03-26';
    public const MAX_BATCH = 10;
    public const MAX_BODY = 16384;
    private const MAX_QUERY = 100;

    /** @return list<array<string, mixed>> */
    public static function tools(): array
    {
        $lang = ['type' => 'string', 'description' => 'Sprache: de, fr, it oder en (Standard: Shop-Sprache)'];
        $ro = ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false];
        $obj = ['type' => 'object'];

        return [
            ['name' => 'search_products', 'title' => 'Produkte suchen',
                'description' => 'Bestellbare Produkte suchen (Name, Marke, Merkmal, Artikelnummer oder EAN). Höchstens 20 Treffer mit Preisspanne der Varianten und Verfügbarkeit.',
                'inputSchema' => ['type' => 'object', 'required' => ['query'], 'additionalProperties' => false, 'properties' => [
                    'query' => ['type' => 'string', 'minLength' => 2, 'maxLength' => self::MAX_QUERY],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20, 'default' => 5],
                    'brand' => ['type' => 'string', 'maxLength' => 64, 'description' => 'Nur diese Marke'],
                    'min_price' => ['type' => 'number', 'minimum' => 0, 'description' => 'Mindestpreis inkl. MwSt.'],
                    'max_price' => ['type' => 'number', 'minimum' => 0, 'description' => 'Höchstpreis inkl. MwSt.'],
                    'in_stock' => ['type' => 'boolean', 'default' => false, 'description' => 'Nur sofort ab Lager lieferbar'],
                    'features' => ['type' => 'object', 'maxProperties' => 5, 'additionalProperties' => ['type' => 'string', 'maxLength' => 64],
                        'description' => 'Produktmerkmale als Name und Wert, z. B. {"Language layout": "CH QWERTZ", "Switches": "Brown"}. Gültige Namen und Werte stehen in get_shop_info unter searchable_features.'],
                    'lang' => $lang]],
                'outputSchema' => $obj, 'annotations' => $ro],
            ['name' => 'get_product', 'title' => 'Produkt im Detail',
                'description' => 'Ein Produkt mit allen Varianten: Preis, Lager, Bestellbarkeit und Mindestmenge je Variante, Merkmale, EAN, Link.',
                'inputSchema' => ['type' => 'object', 'required' => ['id'], 'additionalProperties' => false, 'properties' => ['id' => ['type' => 'integer', 'minimum' => 1], 'lang' => $lang]],
                'outputSchema' => $obj, 'annotations' => $ro],
            ['name' => 'get_buy_link', 'title' => 'Kaufen-Link',
                'description' => 'Kaufen-Link für genau eine Variante und Menge. Bei Produkten mit Varianten ist «combination» Pflicht (aus get_product). Der Kunde bestätigt auf der Seite; erst sein Klick legt den Artikel in den Warenkorb. Bestellt nichts.',
                'inputSchema' => ['type' => 'object', 'required' => ['id'], 'additionalProperties' => false, 'properties' => [
                    'id' => ['type' => 'integer', 'minimum' => 1],
                    'combination' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Varianten-ID aus get_product (Pflicht bei Produkten mit Varianten)'],
                    'quantity' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20, 'default' => 1], 'lang' => $lang]],
                'outputSchema' => $obj, 'annotations' => $ro],
            ['name' => 'get_shop_info', 'title' => 'Shop-Angaben',
                'description' => 'Shop, Währung, Sprachen, Versand (Gratis ab, Gebühr, Bestellschluss, Lieferzeit), Zahlungsarten, Kontakt und die durchsuchbaren Produktmerkmale (searchable_features) für search_products.',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false, 'properties' => ['lang' => $lang]],
                'outputSchema' => $obj, 'annotations' => $ro],
        ];
    }

    /**
     * Prüft eine HTTP-Anfrage an den MCP-Endpunkt vor der Verarbeitung (Methode, Origin, Content-Type, Accept, Version).
     *
     * @param array<string, string> $headers Header mit kleingeschriebenen Namen
     * @param list<string> $allowedOrigins erlaubte Origins (Schema://Host[:Port]), Vergleich ohne Gross-/Kleinschreibung
     *
     * @return array{status: int, body: string, headers: array<string, string>}|null null = Anfrage zulässig
     */
    public static function httpGate(string $method, array $headers, array $allowedOrigins): ?array
    {
        $origin = trim((string) ($headers['origin'] ?? ''));
        // Schutz vor DNS-Rebinding: Browser-Anfragen nur von erlaubten Origins. Server-zu-Server-Clients senden keinen Origin.
        if ($origin !== '' && !self::originAllowed($origin, $allowedOrigins)) {
            return self::httpError(403, null, -32600, 'Origin nicht erlaubt');
        }
        if ($method === 'OPTIONS') {
            return ['status' => 204, 'body' => '', 'headers' => []];
        }
        if ($method !== 'POST') {
            // Kein SSE-Strom und keine Sitzungen: GET und DELETE laut Spezifikation mit 405 beantworten.
            return self::httpError(405, null, -32600, 'Nur POST', ['Allow' => 'POST, OPTIONS']);
        }
        $type = strtolower(trim(explode(';', (string) ($headers['content-type'] ?? ''))[0]));
        if ($type !== 'application/json') {
            return self::httpError(415, null, -32700, 'Content-Type muss application/json sein');
        }
        $accept = strtolower((string) ($headers['accept'] ?? ''));
        if ($accept !== '' && !str_contains($accept, 'application/json') && !str_contains($accept, '*/*') && !str_contains($accept, 'application/*')) {
            return self::httpError(406, null, -32600, 'Accept muss application/json enthalten');
        }
        $version = trim((string) ($headers['mcp-protocol-version'] ?? ''));
        if ($version !== '' && !in_array($version, self::SUPPORTED, true)) {
            return self::httpError(400, null, -32600, 'Nicht unterstützte MCP-Protokollversion: ' . substr($version, 0, 20) . ' (unterstützt: ' . implode(', ', self::SUPPORTED) . ')');
        }

        return null;
    }

    /** CORS-Header für eine erlaubte Browser-Anfrage (sonst leer). @return array<string, string> */
    public static function corsHeaders(string $origin, array $allowedOrigins): array
    {
        $h = ['Vary' => 'Origin'];
        if ($origin !== '' && self::originAllowed($origin, $allowedOrigins)) {
            $h += ['Access-Control-Allow-Origin' => $origin, 'Access-Control-Allow-Methods' => 'POST, OPTIONS',
                'Access-Control-Allow-Headers' => 'Content-Type, Accept, MCP-Protocol-Version, Mcp-Session-Id',
                'Access-Control-Max-Age' => '600'];
        }

        return $h;
    }

    public static function originAllowed(string $origin, array $allowed): bool
    {
        $o = strtolower(rtrim($origin, '/'));
        if (!preg_match('#^https?://[a-z0-9.\-]+(:\d{1,5})?$#', $o)) {
            return false;
        }
        foreach ($allowed as $a) {
            if ($o === strtolower(rtrim($a, '/'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param callable(string, int, bool): void|null $observe wird je Werkzeugaufruf mit Name, Dauer (ms) und Fehlerstatus aufgerufen
     *
     * @return array{status: int, body: string|null} body = null bei Status 202 (nur Benachrichtigungen oder Antworten)
     */
    public static function handle(string $raw, McpCatalog $cat, string $defaultIso, string $headerVersion = '', ?callable $observe = null): array
    {
        if ($raw === '' || strlen($raw) > self::MAX_BODY) {
            return self::json(400, self::error(null, -32600, 'Leere oder zu grosse Anfrage (max. ' . self::MAX_BODY . ' Bytes)'));
        }
        try {
            $msg = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return self::json(400, self::error(null, -32700, 'Parse error'));
        }
        if (!is_array($msg) || $msg === []) {
            return self::json(400, self::error(null, -32600, 'Ungültige Anfrage'));
        }
        $isBatch = array_is_list($msg);
        if ($isBatch) {
            $version = $headerVersion !== '' ? $headerVersion : self::DEFAULT_HEADER_VERSION;
            // JSON-RPC-Batches gibt es nur bis MCP 2025-03-26 (ab 2025-06-18 entfernt).
            if (strcmp($version, '2025-06-18') >= 0) {
                return self::json(400, self::error(null, -32600, 'Batches werden ab MCP 2025-06-18 nicht unterstützt'));
            }
            if (count($msg) > self::MAX_BATCH) {
                return self::json(400, self::error(null, -32600, 'Zu viele Einträge im Batch (max. ' . self::MAX_BATCH . ')'));
            }
        }
        $out = [];
        foreach ($isBatch ? $msg : [$msg] as $m) {
            $r = is_array($m) ? self::one($m, $cat, $defaultIso, $observe) : self::error(null, -32600, 'Ungültige Anfrage');
            if ($r !== null) {
                $out[] = $r;
            }
        }
        if ($out === []) {
            return ['status' => 202, 'body' => null];
        }

        return self::json(200, $isBatch ? $out : $out[0]);
    }

    /** @param array<string, mixed> $m @return array<string, mixed>|null */
    private static function one(array $m, McpCatalog $cat, string $defaultIso, ?callable $observe): ?array
    {
        $hasId = array_key_exists('id', $m);
        $id = $m['id'] ?? null;
        $validId = is_int($id) || (is_string($id) && $id !== '');
        // Antworten des Clients (result/error ohne method) und Benachrichtigungen: annehmen, nichts zurückgeben.
        if (!isset($m['method']) && (array_key_exists('result', $m) || array_key_exists('error', $m))) {
            return null;
        }
        if (($m['jsonrpc'] ?? '') !== '2.0' || !is_string($m['method'] ?? null) || ($hasId && !$validId)) {
            return self::error($validId ? $id : null, -32600, 'Ungültige Anfrage');
        }
        if (!$hasId) {
            return null;
        }
        $method = $m['method'];
        $params = $m['params'] ?? [];
        if (!is_array($params)) {
            return self::error($id, -32602, 'params muss ein Objekt sein');
        }

        switch ($method) {
            case 'initialize':
                $want = (string) ($params['protocolVersion'] ?? '');

                return self::result($id, [
                    'protocolVersion' => in_array($want, self::SUPPORTED, true) ? $want : self::PROTOCOL_VERSION,
                    'capabilities' => ['tools' => ['listChanged' => false]],
                    'serverInfo' => ['name' => 'geoconvado-catalog', 'title' => 'Shop-Katalog (nur lesend)', 'version' => AgentDocs::SERVER_VERSION],
                    'instructions' => 'Nur lesend. Produkte suchen, mit get_product Varianten prüfen, Kaufen-Link für genau eine Variante holen und dem Kunden geben. Kein Checkout.',
                ]);
            case 'ping':
                return self::result($id, new \stdClass());
            case 'tools/list':
                return self::result($id, ['tools' => self::tools()]);
            case 'tools/call':
                $name = $params['name'] ?? null;
                $args = $params['arguments'] ?? [];
                if (!is_string($name) || !in_array($name, array_column(self::tools(), 'name'), true)) {
                    return self::error($id, -32602, 'Unbekanntes Werkzeug');
                }
                if (!is_array($args) || ($args !== [] && array_is_list($args))) {
                    return self::error($id, -32602, 'arguments muss ein Objekt sein');
                }
                $t0 = hrtime(true);
                $res = self::call($name, $args, $cat, $defaultIso);
                if ($observe) {
                    try {
                        $observe($name, (int) ((hrtime(true) - $t0) / 1e6), (bool) $res['isError']);
                    } catch (\Throwable) {
                        // Messung darf nie eine Antwort verhindern
                    }
                }

                return self::result($id, $res);
        }

        return self::error($id, -32601, 'Method not found');
    }

    /** @param array<string, mixed> $a @return array<string, mixed> */
    private static function call(string $name, array $a, McpCatalog $cat, string $defaultIso): array
    {
        $iso = strtolower(substr((string) ($a['lang'] ?? $defaultIso), 0, 2));
        if (!in_array($iso, $cat->languages(), true)) {
            $iso = $defaultIso;
        }
        $id = isset($a['id']) && is_numeric($a['id']) ? (int) $a['id'] : 0;

        try {
            switch ($name) {
                case 'search_products':
                    $q = trim((string) preg_replace('/\s+/u', ' ', (string) ($a['query'] ?? '')));
                    if (mb_strlen($q) < 2 || mb_strlen($q) > self::MAX_QUERY) {
                        return self::toolError('query: 2 bis ' . self::MAX_QUERY . ' Zeichen');
                    }
                    $limit = max(1, min(20, (int) ($a['limit'] ?? 5)));
                    $filters = [];
                    if (isset($a['brand']) && is_string($a['brand']) && trim($a['brand']) !== '') {
                        $filters['brand'] = mb_substr(trim($a['brand']), 0, 64);
                    }
                    foreach (['min_price', 'max_price'] as $k) {
                        if (isset($a[$k]) && is_numeric($a[$k]) && (float) $a[$k] >= 0) {
                            $filters[$k] = (float) $a[$k];
                        }
                    }
                    if (isset($filters['min_price'], $filters['max_price']) && $filters['min_price'] > $filters['max_price']) {
                        return self::toolError('min_price ist grösser als max_price');
                    }
                    if (!empty($a['in_stock'])) {
                        $filters['in_stock'] = true;
                    }
                    if (isset($a['features']) && is_array($a['features'])) {
                        $features = [];
                        foreach ($a['features'] as $featureName => $featureValue) {
                            $featureName = mb_substr(trim((string) $featureName), 0, 64);
                            if (count($features) < 5 && $featureName !== '' && is_string($featureValue) && trim($featureValue) !== '') {
                                $features[$featureName] = mb_substr(trim($featureValue), 0, 64);
                            }
                        }
                        if ($features) {
                            ksort($features);
                            $filters['features'] = $features;
                        }
                    }
                    $rows = $cat->search($q, $limit, $iso, $filters);

                    return self::toolOk(['query' => $q, 'filters' => (object) $filters, 'count' => count($rows), 'products' => $rows]);
                case 'get_product':
                    $p = $id > 0 ? $cat->product($id, $iso) : null;

                    return $p === null ? self::toolError('Produkt nicht gefunden oder nicht bestellbar') : self::toolOk($p);
                case 'get_buy_link':
                    if ($id < 1) {
                        return self::toolError('id fehlt');
                    }
                    $comb = isset($a['combination']) && is_numeric($a['combination']) ? (int) $a['combination'] : null;
                    $qty = isset($a['quantity']) && is_numeric($a['quantity']) ? (int) $a['quantity'] : 1;
                    if ($qty < 1 || $qty > 20) {
                        return self::toolError('quantity: 1 bis 20');
                    }
                    $r = $cat->buyLink($id, $comb, $qty, $iso);
                    if (empty($r['ok'])) {
                        return self::toolError((string) ($r['error'] ?? 'Produkt nicht bestellbar'), isset($r['variants']) ? ['variants' => $r['variants']] : null);
                    }
                    unset($r['ok']);

                    return self::toolOk($r + ['note' => 'Dem Kunden geben. Er bestätigt auf der Seite und schliesst Adresse und Zahlung im Shop selbst ab.']);
                case 'get_shop_info':
                    return self::toolOk($cat->shopInfo($iso));
            }
        } catch (\Throwable) {
            return self::toolError('Interner Fehler, bitte später erneut versuchen');
        }

        return self::toolError('Unbekanntes Werkzeug');
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private static function toolOk(array $data): array
    {
        return ['content' => [['type' => 'text', 'text' => (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)]], 'structuredContent' => $data, 'isError' => false];
    }

    /** @param array<string, mixed>|null $data @return array<string, mixed> */
    private static function toolError(string $msg, ?array $data = null): array
    {
        $r = ['content' => [['type' => 'text', 'text' => $msg]], 'isError' => true];
        if ($data !== null) {
            $r['structuredContent'] = ['error' => $msg] + $data;
            $r['content'][] = ['type' => 'text', 'text' => (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)];
        }

        return $r;
    }

    /** @return array<string, mixed> */
    private static function result(int|string $id, mixed $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /** @return array<string, mixed> */
    private static function error(int|string|null $id, int $code, string $msg): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $msg]];
    }

    /** @param array<string, string> $headers @return array{status: int, body: string, headers: array<string, string>} */
    public static function httpError(int $status, int|string|null $id, int $code, string $msg, array $headers = []): array
    {
        return ['status' => $status, 'body' => (string) json_encode(self::error($id, $code, $msg), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION), 'headers' => $headers];
    }

    /** @param array<string, mixed>|list<array<string, mixed>> $payload @return array{status: int, body: string} */
    private static function json(int $status, array $payload): array
    {
        return ['status' => $status, 'body' => (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)];
    }
}

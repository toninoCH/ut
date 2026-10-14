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
 * Entdeckungsdokumente für KI-Agenten (alle nur lesend, nichts davon verspricht einen Checkout):
 * MCP-Server-Card, API-Katalog (RFC 9727), OpenAPI, Agent-Skills-Index. Reine Funktionen, ohne Datenbank.
 */
final class AgentDocs
{
    public const MCP_PATH = '/mcp';
    public const SERVER_VERSION = '1.0.0';
    public const SKILLS_SCHEMA = 'https://schemas.agentskills.io/discovery/0.2.0/schema.json';

    /** Dokumente und ihr Content-Type; Schlüssel = Wert des Parameters «doc». */
    public const DOCS = [
        'api-catalog' => 'application/linkset+json',
        'server-card' => 'application/json',
        'skills-index' => 'application/json',
        'openapi' => 'application/json',
        'skill' => 'text/markdown',
    ];

    /** Anzeigenamen der Skills: Schlüssel = Skill-Name im Index. */
    public const SKILLS = ['shop-catalog', 'shop-checkout-handoff'];

    /** @return array<string, mixed> */
    public static function serverCard(string $base, string $shop, string $version): array
    {
        return [
            'serverInfo' => ['name' => self::slug($shop) . '-catalog', 'title' => $shop . ' Katalog', 'version' => self::SERVER_VERSION],
            'description' => 'Lesender Zugriff auf Katalog, Preise, Lager und Versandangaben von ' . $shop . '. Kein Checkout: Kaufen schliesst der Kunde im Shop ab.',
            'protocolVersion' => McpServer::PROTOCOL_VERSION,
            'transport' => ['type' => 'streamable-http', 'endpoint' => self::MCP_PATH],
            'endpoint' => $base . self::MCP_PATH,
            'capabilities' => ['tools' => ['listChanged' => false]],
            'tools' => array_map(static fn (array $t): array => ['name' => $t['name'], 'description' => $t['description']], McpServer::tools()),
            'documentation' => $base . '/llms.txt',
            'provider' => ['name' => $shop, 'url' => $base . '/', 'module' => 'GEO.Convado ' . $version],
        ];
    }

    /** @return array<string, mixed> */
    public static function apiCatalog(string $base, string $shop): array
    {
        return ['linkset' => [[
            'anchor' => $base . self::MCP_PATH,
            'service-desc' => [['href' => $base . '/openapi.json', 'type' => 'application/json']],
            'service-doc' => [['href' => $base . '/llms.txt', 'type' => 'text/plain'], ['href' => $base . '/.well-known/mcp/server-card.json', 'type' => 'application/json']],
            'title' => $shop . ' Katalog (MCP, nur lesend)',
        ]]];
    }

    /** @return array<string, mixed> */
    public static function openApi(string $base, string $shop): array
    {
        $calls = [];
        $schemas = [];
        $examples = [];
        foreach (McpServer::tools() as $t) {
            $name = 'Call_' . $t['name'];
            $calls[] = ['$ref' => '#/components/schemas/' . $name];
            $schemas[$name] = ['type' => 'object', 'title' => $t['title'] ?? $t['name'], 'description' => $t['description'], 'required' => ['jsonrpc', 'id', 'method', 'params'], 'properties' => [
                'jsonrpc' => ['const' => '2.0'], 'id' => ['type' => ['integer', 'string']], 'method' => ['const' => 'tools/call'],
                'params' => ['type' => 'object', 'required' => ['name', 'arguments'], 'properties' => ['name' => ['const' => $t['name']], 'arguments' => $t['inputSchema']]],
            ]];
        }
        $examples['search'] = ['summary' => 'Produkte suchen', 'value' => ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'search_products', 'arguments' => ['query' => 'Tastatur ISO-CH', 'limit' => 5, 'max_price' => 200]]]];
        $examples['buy'] = ['summary' => 'Kaufen-Link für eine Variante', 'value' => ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'get_buy_link', 'arguments' => ['id' => 123, 'combination' => 456, 'quantity' => 1]]]];
        $examples['list'] = ['summary' => 'Werkzeuge auflisten', 'value' => ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/list']];
        $schemas['Initialize'] = ['type' => 'object', 'required' => ['jsonrpc', 'id', 'method'], 'properties' => ['jsonrpc' => ['const' => '2.0'], 'id' => ['type' => ['integer', 'string']], 'method' => ['const' => 'initialize'],
            'params' => ['type' => 'object', 'properties' => ['protocolVersion' => ['type' => 'string', 'enum' => McpServer::SUPPORTED], 'capabilities' => ['type' => 'object'], 'clientInfo' => ['type' => 'object']]]]];
        $schemas['ListTools'] = ['type' => 'object', 'required' => ['jsonrpc', 'id', 'method'], 'properties' => ['jsonrpc' => ['const' => '2.0'], 'id' => ['type' => ['integer', 'string']], 'method' => ['enum' => ['tools/list', 'ping']]]];
        $schemas['Notification'] = ['type' => 'object', 'required' => ['jsonrpc', 'method'], 'not' => ['required' => ['id']], 'properties' => ['jsonrpc' => ['const' => '2.0'], 'method' => ['type' => 'string']]];
        $schemas['Response'] = ['type' => 'object', 'required' => ['jsonrpc', 'id'], 'properties' => ['jsonrpc' => ['const' => '2.0'], 'id' => ['type' => ['integer', 'string', 'null']],
            'result' => ['type' => 'object', 'description' => 'Bei tools/call: content (Text), structuredContent (Daten), isError'],
            'error' => ['type' => 'object', 'required' => ['code', 'message'], 'properties' => ['code' => ['type' => 'integer'], 'message' => ['type' => 'string']]]]];
        $err = static fn (string $d): array => ['description' => $d, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Response']]]];

        return [
            'openapi' => '3.1.0',
            'info' => ['title' => $shop . ' Katalog (MCP)', 'version' => self::SERVER_VERSION,
                'description' => 'Nur lesend. Model Context Protocol (JSON-RPC 2.0, Streamable HTTP, Versionen ' . implode(', ', McpServer::SUPPORTED) . '). '
                    . 'Kein Checkout: get_buy_link liefert eine Bestätigungsseite, der Kunde bestellt und bezahlt selbst im Shop. '
                    . 'Begrenzung: Anfragen pro Minute und Client (HTTP 429 mit Retry-After).'],
            'servers' => [['url' => $base]],
            'paths' => [
                self::MCP_PATH => ['post' => [
                    'operationId' => 'mcp',
                    'summary' => 'MCP-Anfrage: initialize, ping, tools/list, tools/call (' . implode(', ', array_column(McpServer::tools(), 'name')) . ')',
                    'parameters' => [['name' => 'MCP-Protocol-Version', 'in' => 'header', 'required' => false, 'schema' => ['type' => 'string', 'enum' => McpServer::SUPPORTED]]],
                    'requestBody' => ['required' => true, 'content' => ['application/json' => [
                        'schema' => ['oneOf' => array_merge([['$ref' => '#/components/schemas/Initialize'], ['$ref' => '#/components/schemas/ListTools'], ['$ref' => '#/components/schemas/Notification']], $calls)],
                        'examples' => $examples]]],
                    'responses' => [
                        '200' => $err('JSON-RPC-Antwort'),
                        '202' => ['description' => 'Benachrichtigung angenommen (ohne Inhalt)'],
                        '400' => $err('Ungültige Anfrage, nicht unterstützte Protokollversion oder Batch'),
                        '403' => $err('Origin nicht erlaubt'),
                        '405' => ['description' => 'Nur POST (kein SSE-Strom, keine Sitzungen)'],
                        '406' => $err('Accept ohne application/json'),
                        '415' => $err('Content-Type nicht application/json'),
                        '429' => $err('Zu viele Anfragen'),
                    ],
                ]],
                '/llms.json' => ['get' => ['operationId' => 'llmsJson', 'summary' => 'Katalog als JSON', 'responses' => ['200' => ['description' => 'Katalog', 'content' => ['application/json' => ['schema' => ['type' => 'object']]]]]]],
                '/llms.txt' => ['get' => ['operationId' => 'llmsTxt', 'summary' => 'Katalog als Text für KI', 'responses' => ['200' => ['description' => 'Katalog', 'content' => ['text/plain' => ['schema' => ['type' => 'string']]]]]]],
            ],
            'components' => ['schemas' => $schemas],
        ];
    }

    /** @return array<string, mixed> */
    public static function skillsIndex(string $base, string $shop, string $iso): array
    {
        $skills = [];
        foreach (self::SKILLS as $name) {
            $body = self::skillMarkdown($name, $base, $shop, $iso);
            $skills[] = ['name' => $name, 'type' => 'skill-md', 'description' => self::skillDescription($name, $shop),
                'url' => $base . '/.well-known/agent-skills/' . $name . '/SKILL.md', 'digest' => 'sha256:' . hash('sha256', $body)];
        }

        return ['$schema' => self::SKILLS_SCHEMA, 'skills' => $skills];
    }

    public static function skillDescription(string $name, string $shop): string
    {
        return $name === 'shop-catalog'
            ? 'Produkte von ' . $shop . ' finden: Katalog, Preise, Lager, Versandangaben (llms.txt, llms.json, MCP).'
            : 'Einen Kauf bei ' . $shop . ' vorbereiten: Kaufen-Link mit Warenkorb, Bestätigung und Zahlung durch den Kunden.';
    }

    public static function skillMarkdown(string $name, string $base, string $shop, string $iso): string
    {
        $mcp = $base . self::MCP_PATH;
        $d = self::skillDescription($name, $shop);
        if ($name === 'shop-catalog') {
            $body = "# Katalog von {$shop}\n\n"
                . "Nur lesend. Preise in der Shopwährung, inklusive Mehrwertsteuer, wie im Shop angezeigt.\n\n"
                . "## Vorgehen\n\n"
                . "1. Übersicht und Kaufablauf: `{$base}/llms.txt` (Datei je Sprache und Kategorie, verlinkt dort).\n"
                . "2. Maschinenlesbar: `{$base}/llms.json`.\n"
                . "3. Gezielte Abfrage über MCP (Streamable HTTP, JSON-RPC 2.0): `POST {$mcp}`.\n\n"
                . "## MCP-Werkzeuge\n\n";
            foreach (McpServer::tools() as $t) {
                $body .= '- `' . $t['name'] . '`: ' . $t['description'] . "\n";
            }

            return "---\nname: {$name}\ndescription: {$d}\n---\n\n" . $body . "\nSprache: Parameter `lang` (de, fr, it, en), Standard `{$iso}`.\n";
        }
        $body = "# Kauf vorbereiten bei {$shop}\n\n"
            . "Dieser Shop hat keinen Agenten-Checkout. Der Kunde bezahlt selbst im Shop.\n\n"
            . "## Vorgehen\n\n"
            . "1. Produkt wählen (Skill `shop-catalog`), mit `get_product` die Variante prüfen: Preis, Lager, `orderable`, Mindestmenge.\n"
            . "2. Kaufen-Link holen (`get_buy_link` mit `id`, bei Varianten Pflicht `combination`, `quantity`). Ungültige Angaben ergeben einen Fehler mit der Liste der gültigen Varianten, nie eine Ersatzvariante.\n"
            . "3. Den Link dem Kunden geben. Er öffnet eine Bestätigungsseite; erst sein Klick legt den Artikel in den Warenkorb.\n"
            . "4. Adresse und Zahlung schliesst der Kunde im Shop ab. Bestellen, Bezahlen oder Konten anlegen ist für Agenten nicht vorgesehen.\n";

        return "---\nname: {$name}\ndescription: {$d}\n---\n\n" . $body;
    }

    /** Link-Header der Startseite (RFC 8288 / RFC 9727). */
    public static function linkHeader(): string
    {
        return '</.well-known/api-catalog>; rel="api-catalog", </openapi.json>; rel="service-desc"; type="application/json", </llms.txt>; rel="service-doc"; type="text/plain", </.well-known/mcp/server-card.json>; rel="describedby"; type="application/json"';
    }

    public static function slug(string $s): string
    {
        $s = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $s), '-'));

        return $s === '' ? 'shop' : $s;
    }
}

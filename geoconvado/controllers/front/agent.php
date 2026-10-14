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

use GeoConvado\Service\AgentCatalog;
use GeoConvado\Service\AgentDocs;
use GeoConvado\Service\AgentStore;
use GeoConvado\Service\BotDetector;
use GeoConvado\Service\CachedCatalog;
use GeoConvado\Service\McpServer;
use GeoConvado\Service\McpStats;
use GeoConvado\Service\RateLimit;
use GeoConvado\Service\Tracker;

/**
 * Entdeckungsdokumente und MCP-Endpunkt für KI-Agenten (nur lesend). Adressen: /mcp, /openapi.json,
 * /.well-known/{api-catalog, mcp/server-card.json, agent-skills/index.json, agent-skills/<name>/SKILL.md}.
 */
class GeoConvadoAgentModuleFrontController extends ModuleFrontController
{
    /** @var bool */
    public $ajax = true;

    public function initContent(): void
    {
        if (!(int) Configuration::get('GEOCONVADO_AGENT')) {
            $this->send(404, 'text/plain; charset=utf-8', 'Not found');
        }
        $doc = (string) Tools::getValue('doc');
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        header('X-Content-Type-Options: nosniff');
        $base = rtrim($this->context->link->getBaseLink((int) $this->context->shop->id, true), '/');
        $shop = (string) Configuration::get('PS_SHOP_NAME');
        $iso = (string) $this->context->language->iso_code;
        $bot = BotDetector::detect((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));

        if ($doc === 'mcp') {
            $this->mcp($method, $iso, $bot);
        }
        // Entdeckungsdokumente sind öffentlich und ohne Anmeldung: von jeder Herkunft lesbar.
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
        if ($method === 'OPTIONS') {
            $this->send(204, 'text/plain; charset=utf-8', '');
        }
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            header('Allow: GET, HEAD, OPTIONS');
            $this->send(405, 'text/plain; charset=utf-8', 'Method not allowed');
        }

        $version = (string) $this->module->version;
        $body = match ($doc) {
            'api-catalog' => $this->encode(AgentDocs::apiCatalog($base, $shop)),
            'server-card' => $this->encode(AgentDocs::serverCard($base, $shop, $version)),
            'skills-index' => $this->encode(AgentDocs::skillsIndex($base, $shop, $iso)),
            'openapi' => $this->encode(AgentDocs::openApi($base, $shop)),
            'skill' => in_array((string) Tools::getValue('name'), AgentDocs::SKILLS, true)
                ? AgentDocs::skillMarkdown((string) Tools::getValue('name'), $base, $shop, $iso) : null,
            default => null,
        };
        if ($body === null) {
            $this->send(404, 'text/plain; charset=utf-8', 'Not found');
        }
        Tracker::log((int) $this->context->shop->id, $bot ?? 'agent', 'llms', '/' . $doc);
        header('Cache-Control: public, max-age=3600');
        $this->send(200, AgentDocs::DOCS[$doc] . '; charset=utf-8', $body, $method === 'HEAD');
    }

    /** MCP-Endpunkt: Prüfung nach Spezifikation, Ratenbegrenzung, Zwischenspeicher, Messung. */
    private function mcp(string $method, string $iso, ?string $bot): never
    {
        $idShop = (int) $this->context->shop->id;
        $headers = [
            'origin' => (string) ($_SERVER['HTTP_ORIGIN'] ?? ''),
            'content-type' => (string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''),
            'accept' => (string) ($_SERVER['HTTP_ACCEPT'] ?? ''),
            'mcp-protocol-version' => (string) ($_SERVER['HTTP_MCP_PROTOCOL_VERSION'] ?? ''),
        ];
        $origins = self::allowedOrigins();
        foreach (McpServer::corsHeaders($headers['origin'], $origins) as $k => $v) {
            header($k . ': ' . $v);
        }
        header('Cache-Control: no-store');
        $gate = McpServer::httpGate($method, $headers, $origins);
        if ($gate !== null) {
            foreach ($gate['headers'] as $k => $v) {
                header($k . ': ' . $v);
            }
            $this->send($gate['status'], 'application/json; charset=utf-8', $gate['body']);
        }

        $store = new AgentStore();
        $rpm = max(1, (int) (Configuration::get('GEOCONVADO_MCP_RPM') ?: 60));
        foreach ([RateLimit::clientKey((string) Tools::getRemoteAddr(), _COOKIE_KEY_) => $rpm, 'all' => $rpm * 10] as $key => $limit) {
            $rl = RateLimit::hit($store, $key, $limit, 60);
            if (!$rl['allowed']) {
                McpStats::record($idShop, McpStats::LIMITED, 0, true);
                header('Retry-After: ' . $rl['retry_after']);
                $e = McpServer::httpError(429, null, -32000, 'Zu viele Anfragen, bitte in ' . $rl['retry_after'] . ' s erneut versuchen');
                $this->send(429, 'application/json; charset=utf-8', $e['body']);
            }
        }

        Tracker::log($idShop, $bot ?? 'agent', 'llms', '/mcp');
        $ctx = $this->context;
        $scope = $idShop . '|' . (int) $ctx->currency->id . '|' . (int) Group::getCurrent()->id . '|' . (int) ($ctx->country->id ?? 0);
        $ttl = Configuration::get('GEOCONVADO_MCP_CACHE') === false ? 120 : max(0, min(3600, (int) Configuration::get('GEOCONVADO_MCP_CACHE')));
        $catalog = new CachedCatalog(new AgentCatalog($ctx), $store, $ttl, $scope);
        $r = McpServer::handle(
            (string) file_get_contents('php://input', false, null, 0, McpServer::MAX_BODY + 1),
            $catalog,
            $iso,
            $headers['mcp-protocol-version'],
            static function (string $tool, int $ms, bool $error) use ($idShop): void {
                McpStats::record($idShop, $tool, $ms, $error);
            }
        );
        if ($r['body'] === null) {
            $this->send(202, 'application/json; charset=utf-8', '');
        }
        $this->send($r['status'], 'application/json; charset=utf-8', $r['body']);
    }

    /** Erlaubte Browser-Herkünfte: die eigenen Shop-Domains und die im Backoffice eingetragenen. @return list<string> */
    private static function allowedOrigins(): array
    {
        $out = [rtrim(Tools::getShopDomainSsl(true), '/'), rtrim(Tools::getShopDomain(true), '/')];
        foreach (preg_split('/[\s,]+/', (string) Configuration::get('GEOCONVADO_MCP_ORIGINS')) ?: [] as $o) {
            if (preg_match('#^https?://[a-z0-9.\-]+(:\d{1,5})?$#i', $o)) {
                $out[] = $o;
            }
        }

        return array_values(array_unique($out));
    }

    /** @param array<string, mixed> $data */
    private function encode(array $data): string
    {
        return (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
    }

    private function send(int $status, string $type, string $body, bool $headOnly = false): never
    {
        http_response_code($status);
        if ($status !== 204) {
            header('Content-Type: ' . $type);
            header('Content-Length: ' . strlen($body));
        }
        if (!$headOnly && $status !== 204) {
            echo $body;
        }
        exit;
    }
}

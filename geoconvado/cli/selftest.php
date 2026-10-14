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
 *
 * Selbsttest im echten Shop (Release-Gate für STAGE und LIVE): prüft Installation, Tabellen, Hooks, Upgrade-Skripte,
 * Katalog, Varianten und Preise gegen PrestaShop, Suche, Kaufen-Link-Regeln, MCP und Speicher. Mit --url zusätzlich
 * die öffentlichen Adressen über HTTP (MCP nach Spezifikation, Entdeckungsdokumente, Markdown, Link-Header).
 * Schreibt nichts ausser kurzlebigen Zählern im Agenten-Speicher.
 *
 *   php modules/geoconvado/cli/selftest.php [--url=https://stage.example.ch] [--json]
 *
 * Exit-Code 0 = alles bestanden, 1 = mindestens ein Fehler.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

$root = dirname(__DIR__, 3);
if (!is_file($root . '/config/config.inc.php')) {
    fwrite(STDERR, "GEO.Convado: PrestaShop-Konfiguration nicht gefunden unter {$root}\n");
    exit(1);
}

require_once $root . '/config/config.inc.php';

if (!defined('_PS_VERSION_')) {
    exit;
}
require_once _PS_MODULE_DIR_ . 'geoconvado/geoconvado.php';

use GeoConvado\Service\AgentCatalog;
use GeoConvado\Service\AgentStore;
use GeoConvado\Service\Generator;
use GeoConvado\Service\McpServer;
use GeoConvado\Service\OfferRules;
use GeoConvado\Service\RateLimit;
use GeoConvado\Service\RobotsWriter;

$opts = getopt('', ['url::', 'json', 'max-ms::']);
$maxMs = isset($opts['max-ms']) ? max(50, (int) $opts['max-ms']) : 1500;
$results = [];
$check = static function (string $label, bool $ok, string $detail = '') use (&$results): void {
    $results[] = ['ok' => $ok, 'label' => $label, 'detail' => $detail];
    if (!in_array('--json', $GLOBALS['argv'] ?? [], true)) {
        $mark = $ok ? 'OK   ' : 'FAIL ';
        echo $mark . $label . ($detail !== '' ? ' · ' . $detail : '') . "\n";
    }
};

$module = new GeoConvado();
$ctx = Generator::prepareCliContext($module->getShopContext());
$db = Db::getInstance();
$p = _DB_PREFIX_;
$idShop = (int) $ctx->shop->id;
$idLang = (int) $ctx->language->id;
$iso = (string) $ctx->language->iso_code;

// --- 1. Installation
$installed = (string) $db->getValue("SELECT version FROM `{$p}module` WHERE name = 'geoconvado'");
$check('Modul installiert, Version = Dateien', $installed === $module->version, 'DB ' . ($installed ?: '–') . ', Dateien ' . $module->version);
foreach (['geoconvado_state', 'geoconvado_bot_log', 'geoconvado_stats_daily', 'geoconvado_front', 'geoconvado_review_cache', 'geoconvado_faqpage', 'geoconvado_kv', 'geoconvado_mcp_stats', 'geoconvado_attribution'] as $t) {
    $check("Tabelle {$t}", (bool) $db->getValue("SHOW TABLES LIKE '" . pSQL($p . $t) . "'"));
}
$hooks = array_column($db->executeS("SELECT h.name FROM `{$p}hook_module` hm JOIN `{$p}hook` h ON h.id_hook = hm.id_hook JOIN `{$p}module` m ON m.id_module = hm.id_module
    WHERE m.name = 'geoconvado' AND hm.id_shop = {$idShop}") ?: [], 'name');
$hooksLower = array_map('strtolower', $hooks);
foreach (['moduleRoutes', 'displayHeader', 'actionOutputHTMLBefore', 'filterCmsContent', 'actionCartSave', 'actionValidateOrder', 'actionFrontControllerSetMedia'] as $h) {
    $check("Hook {$h}", in_array(strtolower($h), $hooksLower, true));
}
$upgrades = glob(_PS_MODULE_DIR_ . 'geoconvado/upgrade/upgrade-*.php') ?: [];
$okUp = true;
$detail = [];
foreach ($upgrades as $f) {
    $v = substr(basename($f, '.php'), 8);
    $src = (string) file_get_contents($f);
    if (!str_contains($src, 'function upgrade_module_' . str_replace('.', '_', $v) . '(')) {
        $okUp = false;
        $detail[] = $v;
    }
}
$check('Upgrade-Skripte: Funktionsnamen passen zur Datei', $okUp, $detail ? implode(', ', $detail) : count($upgrades) . ' Dateien');
$check('Upgrade-Skript für die aktuelle Version', is_file(_PS_MODULE_DIR_ . 'geoconvado/upgrade/upgrade-' . $module->version . '.php'));

// --- 2. Katalog, Varianten, Preise (gegen PrestaShop selbst)
$catalog = new AgentCatalog($ctx);
$withVar = (int) $db->getValue("SELECT pa.id_product FROM `{$p}product_attribute` pa
    JOIN `{$p}product_shop` ps ON ps.id_product = pa.id_product AND ps.id_shop = {$idShop} AND ps.active = 1 AND ps.available_for_order = 1 AND ps.visibility IN ('both','search')
    JOIN `{$p}stock_available` sa ON sa.id_product = pa.id_product AND sa.id_product_attribute = pa.id_product_attribute AND sa.quantity > 0
    GROUP BY pa.id_product ORDER BY pa.id_product DESC");
$simple = (int) $db->getValue("SELECT ps.id_product FROM `{$p}product_shop` ps
    LEFT JOIN `{$p}product_attribute` pa ON pa.id_product = ps.id_product
    JOIN `{$p}stock_available` sa ON sa.id_product = ps.id_product AND sa.id_product_attribute = 0 AND sa.quantity > 0
    WHERE ps.id_shop = {$idShop} AND ps.active = 1 AND ps.available_for_order = 1 AND ps.visibility IN ('both','search') AND pa.id_product_attribute IS NULL
    ORDER BY ps.id_product DESC");

foreach (array_filter(['Produkt mit Varianten' => $withVar, 'Produkt ohne Varianten' => $simple]) as $label => $id) {
    $prod = $catalog->product($id, $iso);
    $check("{$label} #{$id}: get_product", is_array($prod));
    if (!is_array($prod)) {
        continue;
    }
    $priceOk = true;
    foreach ($prod['variants'] as $v) {
        $expected = round((float) Product::getPriceStatic($id, true, $v['combination'] ?: null, 2), 2);
        if (abs($expected - (float) $v['price']) > 0.005) {
            $priceOk = false;
        }
    }
    $check("{$label} #{$id}: Variantenpreise = PrestaShop-Preis", $priceOk, count($prod['variants']) . ' Variante(n)');
    $combos = (int) $db->getValue("SELECT COUNT(*) FROM `{$p}product_attribute` WHERE id_product = {$id}");
    $check("{$label} #{$id}: Anzahl Varianten", $combos === 0 ? count($prod['variants']) === 1 : count($prod['variants']) === $combos, $combos . ' in der Datenbank');
    $check("{$label} #{$id}: Preisspanne enthält Standardpreis", $prod['price_min'] <= $prod['price'] + 0.005 && $prod['price'] <= $prod['price_max'] + 0.005 || !$prod['orderable'],
        $prod['price_min'] . ' … ' . $prod['price_max'] . ' / ' . $prod['price']);
    $stockSum = 0;
    foreach ($prod['variants'] as $v) {
        $stockSum += max(0, (int) StockAvailable::getQuantityAvailableByProduct($id, (int) $v['combination'] ?: 0, $idShop));
    }
    $check("{$label} #{$id}: Lager = Summe der Varianten", $stockSum === (int) $prod['quantity'], (string) $stockSum);

    $orderable = array_values(array_filter($prod['variants'], static fn (array $v): bool => $v['orderable']));
    if ($prod['has_variants']) {
        $r = $catalog->buyLink($id, null, 1, $iso);
        $check("{$label} #{$id}: Kaufen-Link ohne Variante abgelehnt", !$r['ok'] && ($r['code'] ?? '') === 'combination_required');
        $r = $catalog->buyLink($id, 999999999, 1, $iso);
        $check("{$label} #{$id}: fremde Variante abgelehnt (keine Ersatzvariante)", !$r['ok'] && ($r['code'] ?? '') === 'combination_invalid');
    }
    if ($orderable) {
        $v = $orderable[0];
        $r = $catalog->buyLink($id, $v['combination'] ?: null, 1, $iso);
        $check("{$label} #{$id}: Kaufen-Link gültige Variante", $r['ok'] && (int) $r['combination'] === (int) $v['combination'] && str_contains((string) $r['buy_url'], 'ipa=' . (int) $v['combination']),
            $r['buy_url'] ?? $r['error'] ?? '');
        if (!$v['backorder']) {
            $r = $catalog->buyLink($id, $v['combination'] ?: null, min(20, $v['quantity'] + 1), $iso);
            $check("{$label} #{$id}: Menge über Lager abgelehnt", $v['quantity'] + 1 > 20 || (!$r['ok'] && ($r['code'] ?? '') === 'insufficient_stock'));
        }
    }
    // Suche nach Name und nach Artikelnummer findet das Produkt
    $name = (string) $prod['name'];
    $hits = array_column($catalog->search(mb_substr($name, 0, 60), 20, $iso), 'id');
    $check("{$label} #{$id}: Suche nach Name findet es", in_array($id, $hits, true), $name);
    if (!empty($prod['features'])) {
        $feature = $prod['features'][0];
        $hits = array_column($catalog->search(mb_substr($name, 0, 60), 20, $iso, ['features' => [$feature['name'] => $feature['value']]]), 'id');
        $check("{$label} #{$id}: Suche mit Merkmal «{$feature['name']}» findet es", in_array($id, $hits, true), $feature['value']);
        $hits = array_column($catalog->search(mb_substr($name, 0, 60), 20, $iso, ['features' => [$feature['name'] => 'zzzunmoeglich']]), 'id');
        $check("{$label} #{$id}: falscher Merkmalwert findet es nicht", !in_array($id, $hits, true));
    }
    $code = (string) ($prod['ean13'] ?: $prod['reference']);
    if ($code !== '' && OfferRules::looksLikeCode($code)) {
        $hits = array_column($catalog->search($code, 5, $iso), 'id');
        $check("{$label} #{$id}: Suche nach EAN/Artikelnummer", ($hits[0] ?? 0) === $id, $code);
    }
}
$check('Testprodukte gefunden', $withVar > 0 && $simple > 0, "mit Varianten #{$withVar}, ohne #{$simple}");

// --- 3. MCP im Prozess
$init = json_decode((string) McpServer::handle('{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18"}}', $catalog, $iso)['body'], true);
$check('MCP initialize', ($init['result']['protocolVersion'] ?? '') === '2025-06-18');
$list = json_decode((string) McpServer::handle('{"jsonrpc":"2.0","id":2,"method":"tools/list"}', $catalog, $iso)['body'], true);
$check('MCP tools/list', count($list['result']['tools'] ?? []) === 4);
$info = json_decode((string) McpServer::handle('{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"get_shop_info","arguments":{}}}', $catalog, $iso)['body'], true);
$check('MCP get_shop_info', ($info['result']['isError'] ?? true) === false, (string) ($info['result']['structuredContent']['currency'] ?? ''));

$features = $info['result']['structuredContent']['searchable_features'] ?? null;
$check('MCP get_shop_info nennt durchsuchbare Merkmale', is_array($features), is_array($features) ? count($features) . ' Merkmale' : 'fehlt');
$probeQuery = isset($name) ? mb_substr((string) $name, 0, 40) : 'Tastatur';
$times = [];
for ($i = 0; $i < 15; ++$i) {
    $t0 = microtime(true);
    McpServer::handle((string) json_encode(['jsonrpc' => '2.0', 'id' => 100 + $i, 'method' => 'tools/call', 'params' => ['name' => 'search_products', 'arguments' => ['query' => $probeQuery, 'limit' => 5 + ($i % 3)]]]), $catalog, $iso);
    $times[] = (microtime(true) - $t0) * 1000;
}
sort($times);
$p95 = $times[(int) floor(0.95 * (count($times) - 1))];
$check('MCP-Antwortzeit search_products (15 Aufrufe, P95)', $p95 <= $maxMs, sprintf('%.0f ms (Grenze %d ms, Median %.0f ms)', $p95, $maxMs, $times[(int) floor(0.5 * (count($times) - 1))]));
// --- 4. Speicher und Ratenbegrenzung
$store = new AgentStore();
$k = 'selftest-' . bin2hex(random_bytes(4));
$a = RateLimit::hit($store, $k, 2, 60);
$b = RateLimit::hit($store, $k, 2, 60);
$c = RateLimit::hit($store, $k, 2, 60);
$check('Ratenbegrenzung zählt und begrenzt', $a['allowed'] && $b['allowed'] && !$c['allowed'], function_exists('apcu_enabled') && apcu_enabled() ? 'APCu' : 'Datenbank');
$store->set('selftest-cache', 'x', 30);
$check('Zwischenspeicher liest und schreibt', $store->get('selftest-cache') === 'x');

// --- 5. robots.txt
$rs = (new RobotsWriter())->status();
$check('robots.txt ohne Konflikte', $rs['ok'], implode(' | ', $rs['problems']));

// --- 6. HTTP (optional)
if (!empty($opts['url'])) {
    $base = rtrim((string) $opts['url'], '/');
    $req = static function (string $method, string $path, array $headers = [], ?string $body = null) use ($base): array {
        $h = [];
        $ch = curl_init($base . $path);
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => 'GEO.Convado-Selftest', CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$h): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $h[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            }] + ($body !== null ? [CURLOPT_POSTFIELDS => $body] : []));
        $out = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);

        return ['code' => $code, 'headers' => $h, 'body' => $out];
    };
    $json = ['Content-Type: application/json', 'Accept: application/json, text/event-stream'];
    $r = $req('POST', '/mcp', array_merge($json, ['MCP-Protocol-Version: 2025-06-18']), '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"selftest","version":"1"}}}');
    $check('HTTP /mcp initialize 200', $r['code'] === 200 && str_contains($r['body'], '"protocolVersion"'), 'HTTP ' . $r['code']);
    $r = $req('POST', '/mcp', $json, '{"jsonrpc":"2.0","method":"notifications/initialized"}');
    $check('HTTP /mcp Benachrichtigung 202', $r['code'] === 202, 'HTTP ' . $r['code']);
    $r = $req('GET', '/mcp', ['Accept: text/event-stream']);
    $check('HTTP GET /mcp 405 (kein SSE)', $r['code'] === 405, 'HTTP ' . $r['code']);
    $r = $req('POST', '/mcp', array_merge($json, ['Origin: https://evil.example']), '{"jsonrpc":"2.0","id":1,"method":"ping"}');
    $check('HTTP fremder Origin 403', $r['code'] === 403, 'HTTP ' . $r['code']);
    $r = $req('POST', '/mcp', array_merge($json, ['MCP-Protocol-Version: 1999-01-01']), '{"jsonrpc":"2.0","id":1,"method":"ping"}');
    $check('HTTP falsche Protokollversion 400', $r['code'] === 400, 'HTTP ' . $r['code']);
    $r = $req('POST', '/mcp', array_merge($json, ['MCP-Protocol-Version: 2025-06-18']), '[{"jsonrpc":"2.0","id":1,"method":"ping"}]');
    $check('HTTP Batch ab 2025-06-18 abgelehnt', $r['code'] === 400, 'HTTP ' . $r['code']);
    foreach (['/.well-known/api-catalog' => 'application/linkset+json', '/.well-known/mcp/server-card.json' => 'application/json', '/.well-known/agent-skills/index.json' => 'application/json', '/openapi.json' => 'application/json'] as $path => $type) {
        $r = $req('GET', $path);
        $check("HTTP {$path}", $r['code'] === 200 && str_contains((string) ($r['headers']['content-type'] ?? ''), $type), 'HTTP ' . $r['code'] . ' · ' . ($r['headers']['content-type'] ?? ''));
    }
    $r = $req('GET', '/', ['Accept: text/html']);
    $check('HTTP Startseite Link-Header (api-catalog)', str_contains((string) ($r['headers']['link'] ?? ''), 'api-catalog'));
    $r = $req('GET', '/' . $iso . '/', ['Accept: text/markdown']);
    $check('HTTP Markdown für Agenten', str_contains((string) ($r['headers']['content-type'] ?? ''), 'text/markdown'), 'HTTP ' . $r['code'] . ' · ' . ($r['headers']['content-type'] ?? ''));
    $r = $req('GET', '/robots.txt');
    $check('HTTP robots.txt mit Content-Signal', $r['code'] === 200 && preg_match('/^Content-Signal:/m', $r['body']) === 1);
}

$failed = count(array_filter($results, static fn (array $r): bool => !$r['ok']));
if (in_array('--json', $argv ?? [], true)) {
    echo json_encode(['passed' => count($results) - $failed, 'failed' => $failed, 'checks' => $results], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
} else {
    echo "\n" . (count($results) - $failed) . ' bestanden, ' . $failed . " fehlgeschlagen\n";
}
exit($failed > 0 ? 1 : 0);

/**
 * GEO.Convado - AI visibility (llms.txt) for PrestaShop 9
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to a commercial license from Convado AG.
 * Use, copy, modification or distribution is only permitted with a valid
 * license agreement.
 *
 * WebMCP: macht die lesenden Katalog-Werkzeuge des Shops fuer Browser-Agenten verfuegbar.
 * Tut nichts, wenn der Browser navigator.modelContext nicht kennt.
 *
 * @author    Convado AG <info@convado.ch>
 * @copyright 2026 Convado AG
 * @license   Commercial license, see LICENSE.md
 */
(function () {
  'use strict';
  var mc = window.navigator && window.navigator.modelContext;
  if (!mc || typeof mc.registerTool !== 'function' || typeof window.fetch !== 'function') { return; }
  var base = (window.prestashop && window.prestashop.urls && window.prestashop.urls.base_url) || (window.location.origin + '/');
  var endpoint = base.replace(/\/+$/, '') + '/mcp';

  function rpc(method, params) {
    return window.fetch(endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({ jsonrpc: '2.0', id: 1, method: method, params: params || {} })
    }).then(function (r) { return r.json(); });
  }

  rpc('tools/list').then(function (j) {
    var tools = (j && j.result && j.result.tools) || [];
    var ctl = typeof AbortController === 'function' ? new AbortController() : null;
    tools.forEach(function (t) {
      var def = {
        name: t.name,
        description: t.description,
        inputSchema: t.inputSchema,
        annotations: t.annotations,
        execute: function (args) {
          return rpc('tools/call', { name: t.name, arguments: args || {} }).then(function (r) {
            var res = (r && r.result) || {};
            return { content: res.content || [{ type: 'text', text: 'Fehler' }] };
          });
        }
      };
      try { mc.registerTool(def, ctl ? { signal: ctl.signal } : undefined); } catch (e) { /* bereits registriert */ }
    });
    if (ctl) { window.addEventListener('pagehide', function () { ctl.abort(); }); }
  }).catch(function () { /* Endpunkt nicht erreichbar: nichts registrieren */ });
}());

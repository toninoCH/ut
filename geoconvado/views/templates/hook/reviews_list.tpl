{**
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
 *}
{* Rechte Spalte: echte Käuferbewertungen. Erwartet $geo_rv (siehe GeoConvado::reviewsContext). *}
<div class="geo-rv">
  <h3 class="geo-summary__title">{$geo_rv.t.h_reviews|escape:'html':'UTF-8'}</h3>
  {if $geo_rv.flash}<p class="geo-rv__flash" role="status">{$geo_rv.flash|escape:'html':'UTF-8'}</p>{/if}

  {if $geo_rv.stats.count > 0}
    <p class="geo-rv__avg"><span class="geo-rv__stars" aria-hidden="true">{$geo_rv.stars|escape:'html':'UTF-8'}</span> <strong>{$geo_rv.avg_text|escape:'html':'UTF-8'}</strong>{if $geo_rv.stats.inc > 0}<br><small>{$geo_rv.of_text|escape:'html':'UTF-8'}</small>{/if}</p>
  {else}
    <p class="geo-rv__none">{$geo_rv.t.none|escape:'html':'UTF-8'}</p>
  {/if}

  {foreach $geo_rv.items as $r}
  <article class="geo-rv__item" id="geo-rv-{$r.id|intval}">
    <header>
      <span class="geo-rv__stars" aria-label="{$r.rating|intval}/5">{$r.stars|escape:'html':'UTF-8'}</span>
      {if $r.title}<strong>{$r.title|escape:'html':'UTF-8'}</strong>{/if}
    </header>
    <p class="geo-rv__meta">{$r.name|escape:'html':'UTF-8'} · {$r.date|escape:'html':'UTF-8'}
      <span class="geo-badge geo-badge--ok">{$geo_rv.t.verified|escape:'html':'UTF-8'}</span>
      {if $r.incentivised}<span class="geo-badge geo-badge--inc">{$geo_rv.t.incentivised|escape:'html':'UTF-8'}</span>{/if}
    </p>
    <p class="geo-rv__body">{$r.body|escape:'html':'UTF-8'}</p>
    {if $r.media}
    <div class="geo-rv__media">
      {foreach $r.media as $m}
        {if $m.kind == 'image'}
          <a href="{$m.url|escape:'html':'UTF-8'}" target="_blank" rel="noopener"><img src="{$m.thumb|escape:'html':'UTF-8'}" alt="" loading="lazy" width="96" height="96"></a>
        {else}
          <video controls preload="none" playsinline width="240" {if $m.thumb}poster="{$m.thumb|escape:'html':'UTF-8'}"{/if}><source src="{$m.url|escape:'html':'UTF-8'}" type="video/mp4"></video>
        {/if}
      {/foreach}
    </div>
    {/if}
    {if $r.blog_url}<p class="geo-rv__blog"><a href="{$r.blog_url|escape:'html':'UTF-8'}" rel="noopener">{$geo_rv.t.blog|escape:'html':'UTF-8'} →</a></p>{/if}
    <footer class="geo-rv__actions">
      {if $r.mine}
        <form method="post" action="{$geo_rv.action|escape:'html':'UTF-8'}" data-c="{$geo_rv.t.delete_confirm|escape:'html':'UTF-8'}" onsubmit="return confirm(this.getAttribute('data-c'));">
          <input type="hidden" name="do" value="delete"><input type="hidden" name="id_review" value="{$r.id|intval}"><input type="hidden" name="id_product" value="{$geo_rv.id_product|intval}"><input type="hidden" name="token" value="{$geo_rv.token|escape:'html':'UTF-8'}">
          <button type="submit" class="geo-link">{$geo_rv.t.delete|escape:'html':'UTF-8'}</button>
        </form>
      {else}
        <form method="post" action="{$geo_rv.action|escape:'html':'UTF-8'}">
          <input type="hidden" name="do" value="vote"><input type="hidden" name="id_review" value="{$r.id|intval}"><input type="hidden" name="id_product" value="{$geo_rv.id_product|intval}"><input type="hidden" name="token" value="{$geo_rv.token|escape:'html':'UTF-8'}">
          <button type="submit" class="geo-link">{$geo_rv.t.helpful|escape:'html':'UTF-8'} ({$r.helpful|intval})</button>
        </form>
        <form method="post" action="{$geo_rv.action|escape:'html':'UTF-8'}">
          <input type="hidden" name="do" value="report"><input type="hidden" name="id_review" value="{$r.id|intval}"><input type="hidden" name="id_product" value="{$geo_rv.id_product|intval}"><input type="hidden" name="token" value="{$geo_rv.token|escape:'html':'UTF-8'}">
          <button type="submit" class="geo-link">{$geo_rv.t.report|escape:'html':'UTF-8'}</button>
        </form>
      {/if}
    </footer>
  </article>
  {/foreach}

  {if $geo_rv.more}<p class="geo-rv__more"><a href="{$geo_rv.more_url|escape:'html':'UTF-8'}">{$geo_rv.more|escape:'html':'UTF-8'}</a></p>{/if}
  <div class="geo-rv__cta">
    {if $geo_rv.can.state == 'ok'}
      <a class="btn btn-primary" href="{$geo_rv.can.url|escape:'html':'UTF-8'}">{$geo_rv.t.write|escape:'html':'UTF-8'}</a>
    {elseif $geo_rv.can.state == 'login'}
      <a class="btn btn-secondary" href="{$geo_rv.can.url|escape:'html':'UTF-8'}">{$geo_rv.t.login|escape:'html':'UTF-8'}</a>
    {elseif $geo_rv.can.text}
      <p class="geo-rv__hint">{$geo_rv.can.text|escape:'html':'UTF-8'}</p>
    {/if}
  </div>
  {if $geo_rv.voucher}<p class="geo-summary__note">{$geo_rv.t.incentive_note|escape:'html':'UTF-8'}</p>{/if}
  {if $geo_rv.google}<p class="geo-summary__note">{$geo_rv.t.google_shop|escape:'html':'UTF-8'}: <strong>{$geo_rv.google.avg|escape:'html':'UTF-8'}</strong> ({$geo_rv.google.count|intval}){if $geo_rv.google.url} · <a href="{$geo_rv.google.url|escape:'html':'UTF-8'}" target="_blank" rel="noopener nofollow">Google</a>{/if}</p>{/if}
</div>

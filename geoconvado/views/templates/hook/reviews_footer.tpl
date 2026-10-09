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
<section class="geo-greviews" aria-label="{$geo_g_label|escape:'html':'UTF-8'}">
  <div class="geo-greviews__head">
    <span class="geo-gbadge__g" aria-hidden="true">G</span>
    <strong class="geo-greviews__score">{$geo_g.google_rating_avg|escape:'html':'UTF-8'}</strong>
    <span class="geo-gbadge__stars" aria-hidden="true">{$geo_stars|escape:'html':'UTF-8'}</span>
    <span>{if $geo_g.google_reviews_url}<a href="{$geo_g.google_reviews_url|escape:'html':'UTF-8'}" target="_blank" rel="noopener nofollow">{/if}{$geo_g.google_rating_count|intval} {$geo_g_label|escape:'html':'UTF-8'}{if $geo_g.google_reviews_url}</a>{/if}</span>
  </div>
  {if $geo_reviews}
  <div class="geo-greviews__list">
    {foreach $geo_reviews as $r}
      <blockquote class="geo-greviews__item">
        <p>«{$r.text|escape:'html':'UTF-8'}»</p>
        <footer>{$r.author|escape:'html':'UTF-8'} · {section name=st loop=$r.rating|intval}★{/section}</footer>
      </blockquote>
    {/foreach}
  </div>
  {/if}
</section>

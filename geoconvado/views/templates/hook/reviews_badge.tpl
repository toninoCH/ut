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
<aside class="geo-proof{$geo_rot|escape:'html':'UTF-8'}" aria-label="Google">
  <div class="geo-proof__head">
    <span class="geo-proof__g" aria-hidden="true">G</span>
    <span class="geo-proof__label">{$geo_g_scope|escape:'html':'UTF-8'}</span>
    <strong class="geo-proof__score">{$geo_g.google_rating_avg|escape:'html':'UTF-8'}</strong>
    <span class="geo-proof__stars" aria-hidden="true">{$geo_stars|escape:'html':'UTF-8'}</span>
    {if $geo_g.google_reviews_url}<a class="geo-proof__count" href="{$geo_g.google_reviews_url|escape:'html':'UTF-8'}" target="_blank" rel="noopener nofollow">{$geo_g.google_rating_count|intval} {$geo_g_label|escape:'html':'UTF-8'}</a>
    {else}<span class="geo-proof__count">{$geo_g.google_rating_count|intval} {$geo_g_label|escape:'html':'UTF-8'}</span>{/if}
  </div>
  {if $geo_quotes}<div class="geo-proof__quotes">{foreach $geo_quotes as $q}<p class="geo-proof__quote geo-q{$q@iteration|intval}">«{$q.text|escape:'html':'UTF-8'}» <span>– {$q.author|escape:'html':'UTF-8'}</span></p>{/foreach}</div>{/if}
</aside>

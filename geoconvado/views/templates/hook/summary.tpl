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
{if $geo_ups}
<section class="geo-ups" aria-labelledby="geo-ups-title">
  <h3 id="geo-ups-title" class="geo-summary__title">{$geo_up_title|escape:'html':'UTF-8'}</h3>
  <div class="geo-ups__grid">
    {foreach $geo_ups as $u}
    <a class="geo-ups__card" href="{$u.url|escape:'html':'UTF-8'}">
      {if $u.img}<img src="{$u.img|escape:'html':'UTF-8'}" alt="{$u.name|escape:'html':'UTF-8'}" loading="lazy" width="250" height="250">{/if}
      <span class="geo-ups__name">{$u.name|escape:'html':'UTF-8'}</span>
      {if $u.price}<strong class="geo-ups__price">{$u.price|escape:'html':'UTF-8'}</strong>{/if}
    </a>
    {/foreach}
  </div>
</section>
{/if}
{if $geo_s || $geo_rv}
<div class="geo-duo{if !$geo_s || !$geo_rv} geo-duo--single{/if}" id="geo-reviews">
  {if $geo_s}
  <section class="geo-summary geo-duo__ai" aria-labelledby="geo-ai-title">
    <h3 id="geo-ai-title" class="geo-summary__title"><span class="geo-summary__ai" aria-hidden="true">AI</span> {$geo_ai_title|escape:'html':'UTF-8'}</h3>
    <p class="geo-summary__declared">{$geo_l[5]|escape:'html':'UTF-8'}</p>
    <p class="geo-summary__lead">{$geo_s.summary|escape:'html':'UTF-8'}</p>
    <div class="geo-summary__grid">
      {if $geo_s.pros}
      <div class="geo-summary__col geo-summary__col--pro">
        <h4>{$geo_l[1]|escape:'html':'UTF-8'}</h4>
        <ul>{foreach $geo_s.pros as $p}<li>{$p|escape:'html':'UTF-8'}</li>{/foreach}</ul>
      </div>
      {/if}
      {if $geo_s.cons}
      <div class="geo-summary__col geo-summary__col--con">
        <h4>{$geo_l[2]|escape:'html':'UTF-8'}</h4>
        <ul>{foreach $geo_s.cons as $c}<li>{$c|escape:'html':'UTF-8'}</li>{/foreach}</ul>
      </div>
      {/if}
    </div>
    {if $geo_s.best_for || $geo_s.not_for}
    <dl class="geo-summary__fit">
      {if $geo_s.best_for}<dt>{$geo_l[3]|escape:'html':'UTF-8'}</dt><dd>{$geo_s.best_for|escape:'html':'UTF-8'}</dd>{/if}
      {if $geo_s.not_for}<dt>{$geo_l[4]|escape:'html':'UTF-8'}</dt><dd>{$geo_s.not_for|escape:'html':'UTF-8'}</dd>{/if}
    </dl>
    {/if}
  </section>
  {/if}
  {if $geo_rv}
  <section class="geo-summary geo-duo__rv" aria-label="{$geo_rv.t.h_reviews|escape:'html':'UTF-8'}">
    {include file='module:geoconvado/views/templates/hook/reviews_list.tpl'}
  </section>
  {/if}
</div>
{/if}
{if $geo_faqs}
<section class="geo-summary geo-faq" aria-labelledby="geo-faq-title">
  <h3 id="geo-faq-title" class="geo-summary__title">{$geo_faq_title|escape:'html':'UTF-8'}</h3>
  {foreach $geo_faqs as $f}
  <details class="geo-faq__item">
    <summary>{$f.q|escape:'html':'UTF-8'}</summary>
    <p>{$f.a|escape:'html':'UTF-8'}</p>
  </details>
  {/foreach}
</section>
{/if}
{if $geo_ld_faq}
<script type="application/ld+json">{ldelim}"@context":"https://schema.org","@type":"FAQPage","mainEntity":[{foreach $geo_ld_faq as $f}{ldelim}"@type":"Question","name":"{$f.q|escape:'html':'UTF-8'}","acceptedAnswer":{ldelim}"@type":"Answer","text":"{$f.a|escape:'html':'UTF-8'}"{rdelim}{rdelim}{if !$f@last},{/if}{/foreach}]{rdelim}</script>
{/if}
{if $geo_ld_rv}
<script type="application/ld+json">{ldelim}"@context":"https://schema.org","@type":"Product","@id":"{$geo_ld_rv.id|escape:'html':'UTF-8'}","name":"{$geo_ld_rv.name|escape:'html':'UTF-8'}","url":"{$geo_ld_rv.url|escape:'html':'UTF-8'}","aggregateRating":{ldelim}"@type":"AggregateRating","ratingValue":{$geo_ld_rv.avg|floatval},"reviewCount":{$geo_ld_rv.count|intval},"bestRating":5,"worstRating":1{rdelim}{rdelim}</script>
{/if}

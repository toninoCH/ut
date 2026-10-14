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
{extends file='page.tpl'}
{block name='page_content'}
<section class="geo-buy">
  {if $geo_p.img}<img src="{$geo_p.img|escape:'html':'UTF-8'}" alt="{$geo_p.name|escape:'html':'UTF-8'}" width="160" height="160">{/if}
  <h1 class="h3">{$geo_p.qty|intval} × {$geo_p.name|escape:'html':'UTF-8'}</h1>
  {if $geo_error}<p class="alert alert-warning" role="alert">{$geo_error|escape:'html':'UTF-8'}</p>{/if}
  {if $geo_p.price}<p><strong>{$geo_p.price|escape:'html':'UTF-8'}</strong></p>{/if}
  <form method="post" action="{$geo_action|escape:'html':'UTF-8'}">
    <input type="hidden" name="token" value="{$geo_token|escape:'html':'UTF-8'}">
    {if $geo_variants}
      <label for="geo-ipa">{$geo_t.choose|escape:'html':'UTF-8'}</label>
      <select id="geo-ipa" name="ipa" class="form-control" required>
        {if !$geo_ipa}<option value="" selected disabled>–</option>{/if}
        {foreach $geo_variants as $v}
          <option value="{$v.id|intval}"{if $v.selected} selected{/if}{if !$v.orderable} disabled{/if}>{$v.label|escape:'html':'UTF-8'}{if !$v.orderable} ({$geo_t.unavailable|escape:'html':'UTF-8'}){/if}</option>
        {/foreach}
      </select>
    {else}
      <input type="hidden" name="ipa" value="0">
    {/if}
    <button type="submit" name="geo_confirm" value="1" class="btn btn-primary">{$geo_t.cta|escape:'html':'UTF-8'}</button>
    <a class="btn btn-link" href="{$geo_p.url|escape:'html':'UTF-8'}">{$geo_t.view|escape:'html':'UTF-8'}</a>
  </form>
  <p class="geo-buy__note">{$geo_t.note|escape:'html':'UTF-8'}</p>
</section>
{/block}

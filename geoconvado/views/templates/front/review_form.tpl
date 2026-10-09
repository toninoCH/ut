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
{block name='page_title'}{$geo_heading|escape:'html':'UTF-8'}{/block}
{block name='page_content'}
<section class="geo-rvform">
  {if !$geo_ok}
    <p class="geo-rvform__deny">{$geo_reason|escape:'html':'UTF-8'}</p>
    <p><a class="btn btn-primary" href="{$geo_product.url|escape:'html':'UTF-8'}">{$geo_t.back|escape:'html':'UTF-8'}</a></p>
  {else}
  {if $geo_voucher}
    <div class="geo-rvform__offer" role="note">
      <strong>{$geo_offer|escape:'html':'UTF-8'}</strong>
      <span>{$geo_cond|escape:'html':'UTF-8'}</span>
    </div>
  {/if}
  <form method="post" action="{$geo_form_action|escape:'html':'UTF-8'}" enctype="multipart/form-data" class="geo-rvform__form" novalidate>
    <input type="hidden" name="do" value="submit">
    <input type="hidden" name="id_product" value="{$geo_product.id|intval}">
    <input type="hidden" name="token" value="{$geo_token|escape:'html':'UTF-8'}">
    <input type="hidden" name="ftoken" value="{$geo_ftoken|escape:'html':'UTF-8'}">
    <div class="geo-rvform__hp" aria-hidden="true"><label>Website<input type="text" name="website" value="" tabindex="-1" autocomplete="off"></label></div>

    <fieldset class="geo-rvform__rating">
      <legend>{$geo_t.f_rating|escape:'html':'UTF-8'}</legend>
      <div class="geo-stars-input">
        {foreach $geo_star_vals as $n}
          <input type="radio" id="geo-r{$n|intval}" name="rating" value="{$n|intval}" {if $geo_old.rating == $n}checked{/if} required>
          <label for="geo-r{$n|intval}" title="{$n|intval}">★</label>
        {/foreach}
      </div>
    </fieldset>

    <label class="geo-rvform__field">{$geo_t.f_title|escape:'html':'UTF-8'}
      <input type="text" name="title" maxlength="120" value="{$geo_old.title|escape:'html':'UTF-8'}" class="form-control">
    </label>
    <label class="geo-rvform__field">{$geo_t.f_body|escape:'html':'UTF-8'}
      <textarea name="body" id="geo-body" rows="7" maxlength="3000" required class="form-control">{$geo_old.body|escape:'html':'UTF-8'}</textarea>
      <small class="geo-rvform__count"><span id="geo-count">0</span> {$geo_t.f_chars|escape:'html':'UTF-8'}{if $geo_voucher && $geo_min_chars} · {$geo_cond|escape:'html':'UTF-8'}{/if}</small>
    </label>

    {if $geo_images_on}
    <label class="geo-rvform__field">{$geo_photos_label|escape:'html':'UTF-8'}
      <input type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple class="form-control">
    </label>
    {/if}
    {if $geo_video_on}
    <label class="geo-rvform__field">{$geo_video_label|escape:'html':'UTF-8'}
      <input type="file" name="video" accept="video/mp4,video/quicktime,video/webm" class="form-control">
    </label>
    {/if}

    <label class="geo-rvform__check"><input type="checkbox" name="consent" value="1" {if $geo_old.consent}checked{/if}> <span>{$geo_consent_text|escape:'html':'UTF-8'}</span></label>
    <p class="geo-rvform__note">{$geo_name_note|escape:'html':'UTF-8'}<br>{$geo_t.f_rules|escape:'html':'UTF-8'}</p>
    <button type="submit" class="btn btn-primary">{$geo_t.f_submit|escape:'html':'UTF-8'}</button>
    <a class="btn btn-link" href="{$geo_product.url|escape:'html':'UTF-8'}">{$geo_t.back|escape:'html':'UTF-8'}</a>
  </form>
  {literal}<script>
  (function(){var b=document.getElementById('geo-body'),c=document.getElementById('geo-count');if(b&&c){var u=function(){c.textContent=b.value.length};b.addEventListener('input',u);u();}})();
  </script>{/literal}
  {/if}
</section>
{/block}

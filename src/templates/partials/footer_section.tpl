{* Footer Section - Displays site footer with configurable layout and optional background *}
{if $settings.show_footer}
<footer class="mt-4 border-top position-relative rounded-3" style="overflow: hidden; {if $settings.footer_media_id}padding: 0;{else}padding: 1.5rem 0;{/if}">
  {if $is_admin|default:false}
    <button class="btn btn-sm btn-light btn-edit-section position-absolute top-0 end-0 m-2" style="z-index: 10;" data-bs-toggle="modal" data-bs-target="#editFooterModal" title="Edit Footer">
      <i class="bi bi-pencil"></i> Edit
    </button>
  {/if}

  {* Background Image with Overlay *}
  {if $settings.footer_media_id && $footer_jpg}
    <div class="footer-background-wrapper" style="display: block; height: 0; padding-bottom: {$settings.footer_height|default:30}%; position: relative; overflow: hidden;">
      <picture style="position: absolute; top: 0; left: 0; width: 100%; height: 100%;">
        {if $footer_webp}
          <source type="image/webp" srcset="{$footer_webp}" sizes="100vw" />
        {/if}
        <img srcset="{$footer_jpg}" sizes="100vw" alt="Footer background" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; object-fit: cover; z-index: 0;" />
      </picture>
      {if $settings.footer_overlay_opacity}
        <div class="footer-overlay" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; background-color: {$settings.footer_overlay_color|default:'#000'}; opacity: {$settings.footer_overlay_opacity|default:'0.5'}; z-index: 1;"></div>
      {/if}
      <div class="footer-content-wrapper" style="position: absolute; top: 0; left: 0; right: 0; width: 100%; height: 100%; z-index: 2; display: flex; align-items: center; justify-content: center;">
        <div class="container" style="padding: 2rem 1.5rem;">
          {if $settings.footer_layout == 'single'}
            <div class="row">
              <div class="col-12">
                {$settings.footer_column1_html nofilter}
              </div>
            </div>
          {else}
            <div class="row">
              <div class="col-md-6 mb-3 mb-md-0">
                {$settings.footer_column1_html nofilter}
              </div>
              <div class="col-md-6">
                {$settings.footer_column2_html nofilter}
              </div>
            </div>
          {/if}
        </div>
      </div>
    </div>
  {else}
    {* Footer without background image *}
    <div class="container" style="padding: 1.5rem 1.5rem;">
      {if $settings.footer_layout == 'single'}
        <div class="row">
          <div class="col-12">
            {$settings.footer_column1_html nofilter}
          </div>
        </div>
      {else}
        <div class="row">
          <div class="col-md-6 mb-3 mb-md-0">
            {$settings.footer_column1_html nofilter}
          </div>
          <div class="col-md-6">
            {$settings.footer_column2_html nofilter}
          </div>
        </div>
      {/if}
    </div>
  {/if}
</footer>
{/if}

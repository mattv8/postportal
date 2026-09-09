{* Post card partial - Timeline version *}
{assign var=jpg_srcset value=$post.hero_srcset_jpg}
{assign var=webp_srcset value=$post.hero_srcset_webp}
{assign var=can_see_views value=($show_view_counts|default:0 || $is_authenticated|default:false)}
{assign var=can_see_impressions value=($show_impression_counts|default:0 || $is_authenticated|default:false)}
{assign var=is_draft value=($post.status|default:'published' == 'draft')}

<div class="timeline-item{if $is_draft} timeline-item-draft{/if}" id="post-{$post.id}" data-post-id="{$post.id}" data-status="{$post.status|default:'published'}">
  <div class="timeline-date">
    <div class="timeline-date-content{if $is_draft} timeline-date-draft{/if}">
      {if $is_draft}
        {* Draft posts show date (published_at if set, otherwise current date) *}
        {if $post.published_at}
          <div class="timeline-month">{$post.published_at|date_format:"%b"}</div>
          <div class="timeline-day">{$post.published_at|date_format:"%e"}</div>
          <div class="timeline-year">{$post.published_at|date_format:"%Y"}</div>
        {else}
          <div class="timeline-month">{$smarty.now|date_format:"%b"}</div>
          <div class="timeline-day">{$smarty.now|date_format:"%e"}</div>
          <div class="timeline-year">{$smarty.now|date_format:"%Y"}</div>
        {/if}
      {elseif $post.published_at}
        <div class="timeline-month">{$post.published_at|date_format:"%b"}</div>
        <div class="timeline-day">{$post.published_at|date_format:"%e"}</div>
        <div class="timeline-year">{$post.published_at|date_format:"%Y"}</div>
      {/if}
    </div>
  </div>
  <div class="timeline-content">
    <div class="card post-card{if $is_draft} post-card-draft{/if}">
      {if $is_draft}
        <div class="draft-badge-container">
          <span class="badge bg-secondary draft-badge"><i class="bi bi-pencil-square"></i> Draft</span>
        </div>
      {/if}
      {if $jpg_srcset || $webp_srcset}
        <div class="hero-image-container" style="max-height: 600px; overflow: hidden; position: relative;">
          <picture style="display: block; height: 0; padding-bottom: {$post.hero_image_height|default:100}%; position: relative; overflow: hidden;">
            {if $webp_srcset}
              <source type="image/webp" srcset="{$webp_srcset}" sizes="(max-width: 768px) 100vw, 50vw" />
            {/if}
            {if $jpg_srcset}
              <img class="card-img-top" srcset="{$jpg_srcset}" sizes="(max-width: 768px) 100vw, 50vw" alt="{$post.title|escape}"
                   style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; object-fit: cover; object-position: center; {if $post.hero_title_overlay|default:1}filter: brightness({$post.hero_overlay_opacity|default:0.70});{/if}" />
            {/if}
          </picture>
          {if $post.hero_title_overlay|default:1}
            <div class="hero-title-overlay">
              <h5 class="card-title text-white mb-0">{$post.title|escape}</h5>
            </div>
          {/if}
        </div>
      {/if}
      <div class="card-body">
        {if !$post.hero_title_overlay|default:1 || !($jpg_srcset || $webp_srcset)}
          <h5 class="card-title">{$post.title|escape}</h5>
        {/if}
         {if $post.author_first || $post.author_last}
           <p class="text-muted small mb-2">
             <em>By {if $post.author_first}{$post.author_first|escape:'html'}{/if} {if $post.author_last}{$post.author_last|escape:'html'}{/if}</em>
           </p>
         {/if}
        <div class="card-text post-preview-content">{$post.body_html nofilter}</div>
        <div class="d-flex justify-content-between align-items-center">
          {if !$is_authenticated|default:false}
            <small class="text-muted fst-italic">
              <span class="d-none d-md-inline">Click to read more</span>
              <span class="d-md-none">Tap to read more</span>
            </small>
          {else}
            <span></span>
          {/if}
          <div class="d-flex align-items-center gap-2">
            {if $can_see_views || $can_see_impressions}
              <div class="post-metrics-badges d-flex gap-1 flex-wrap justify-content-end">
                {if $can_see_views}
                  <span class="badge rounded-pill bg-light text-dark border small">{$post.view_count|default:0} views ({$post.unique_view_count|default:0} unique)</span>
                {/if}
                {if $can_see_impressions}
                  <span class="badge rounded-pill bg-light text-dark border small">{$post.impression_count|default:0} impressions ({$post.unique_impression_count|default:0} unique)</span>
                {/if}
              </div>
            {/if}
            {if $is_admin|default:false}
              <div class="btn-group" role="group">
                <button class="btn btn-sm btn-outline-secondary btn-edit-post-home" data-post-id="{$post.id}" title="Edit post">
                  <i class="bi bi-pencil"></i> Edit
                </button>
                <button class="btn btn-sm btn-outline-danger btn-delete-post-home" data-post-id="{$post.id}" title="Delete post">
                  <i class="bi bi-trash"></i> Delete
                </button>
              </div>
            {/if}
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

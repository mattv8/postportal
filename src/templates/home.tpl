<div class="container py-4">
  {if $settings.show_hero && ($settings.hero_media_id || $settings.hero_html)}
  <div class="hero position-relative mb-4" style="max-height: 100vh; overflow: hidden;">
      {if $is_admin|default:false}
        <button class="btn btn-sm btn-light btn-edit-section position-absolute top-0 end-0 m-2" style="z-index: 10;" data-bs-toggle="modal" data-bs-target="#editHeroModal" title="Edit Hero Banner">
          <i class="bi bi-pencil"></i> Edit
        </button>
      {/if}
      {if $settings.hero_media_id}
        <picture style="display: block; height: 0; padding-bottom: {$settings.hero_height|default:100}%; position: relative; overflow: hidden;">
          {if $hero_webp}
            <source type="image/webp" srcset="{$hero_webp}" sizes="100vw" />
          {/if}
          {if $hero_jpg}
            <img class="w-100 rounded" srcset="{$hero_jpg}" sizes="100vw" alt="Hero image" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; object-fit: cover;" />
          {/if}
        </picture>
        {if $settings.hero_overlay_opacity}
          <div class="hero-overlay position-absolute top-0 start-0 w-100 h-100" style="background-color: {$settings.hero_overlay_color|default:'#000'}; opacity: {$settings.hero_overlay_opacity|default:'0.3'}"></div>
        {/if}
      {/if}
      {if $settings.hero_html}
        <div class="hero-content {if $settings.hero_media_id}position-absolute top-50 start-50 translate-middle text-white{else}w-100 text-center mx-auto{/if} p-3">
          {$settings.hero_html nofilter}
        </div>
      {/if}
    </div>
  {/if}

  <div class="row g-4">
    <div class="col-12 col-lg-8">
      {* Newsletter section above timeline when position is 'above_timeline' *}
      {if $settings.show_mailing_list && $settings.newsletter_position == 'above_timeline'}
        {* Show on mobile always when above_timeline, show on desktop only if scope is all_devices *}
        <div class="mb-4 {if $settings.newsletter_position_scope != 'all_devices'}d-lg-none{/if}">
          {include file='templates/partials/mailing_list_section.tpl'}
        </div>
      {/if}
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="mb-0">Updates</h2>
        {if $is_admin|default:false}
          <button class="btn btn-primary" id="btnCreatePost">
            <i class="bi bi-plus-circle me-1"></i>Create Post
          </button>
        {/if}
      </div>
      <div id="posts-list" class="timeline">
        {if $posts|@count == 0}
          <p>No updates yet.</p>
        {/if}
        {foreach $posts as $post}
          {include file='templates/partials/post_card.tpl' post=$post show_view_counts=$settings.show_view_counts show_impression_counts=$settings.show_impression_counts}
        {/foreach}
      </div>
      <div id="posts-load-more" class="text-center mt-3">
        <button class="btn btn-outline-primary btn-load-more-posts" data-offset="{$posts|@count}" data-limit="5">
          Load more
        </button>
        <div class="text-muted small mt-2 d-none" id="posts-loading-indicator">
          Loading more updates…
        </div>
      </div>
    </div>
    <div class="col-12 col-lg-4">
      <div class="sticky-top" style="top: 30px;">
        {if $settings.show_about}
          <div class="card mb-4 position-relative">
            {if $is_admin|default:false}
              <button class="btn btn-sm btn-light btn-edit-section position-absolute top-0 end-0 m-2" style="z-index: 10;" data-bs-toggle="modal" data-bs-target="#editAboutModal" title="Edit About Section">
                <i class="bi bi-pencil"></i> Edit
              </button>
            {/if}
            <div class="card-body">
              <h5 class="card-title">About</h5>
              <div class="card-text">{$settings.site_bio_html nofilter}</div>
            </div>
          </div>
        {/if}
        {if $settings.show_mailing_list}
          {* On mobile: hide if position is above_timeline. On desktop: hide only if above_timeline AND scope is all_devices *}
          <div class="{if $settings.newsletter_position == 'above_timeline'}{if $settings.newsletter_position_scope == 'all_devices'}d-none{else}d-none d-lg-block{/if}{/if}">
            {include file='templates/partials/mailing_list_section.tpl'}
          </div>
        {/if}
        {if $settings.show_donation}
          <div class="position-relative">
            {if $is_admin|default:false}
              <button class="btn btn-sm btn-light btn-edit-section position-absolute top-0 end-0 m-2" style="z-index: 10;" data-bs-toggle="modal" data-bs-target="#editDonationModal" title="Edit Donation Section">
                <i class="bi bi-pencil"></i> Edit
              </button>
            {/if}
            {include file='templates/partials/donate_section.tpl'}
          </div>
        {/if}
      </div>
    </div>
  </div>

  {include file='templates/partials/post_overlay.tpl'}

  {* Footer Section *}
  {include file='templates/partials/footer_section.tpl'}

  {* Donation Modal - shown to all users when donate button is clicked *}
  {include file='templates/modals/donation_modal.tpl'}

  {* Create Post Modal - only shown to administrators *}
  {if $is_admin|default:false}
    {include file='templates/modals/create_post.tpl'}
  {/if}

  {* Editing controls - only shown to administrators *}
  {if $is_admin|default:false}
  <div class="modal fade" id="postEditorModal" tabindex="-1" aria-labelledby="postEditorModalLabel" aria-hidden="true" data-bs-focus="false">
      <div class="modal-dialog modal-xl">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="postEditorModalLabel">Edit Post</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            {include file='templates/partials/post_editor.tpl' mode='edit' editorId='home-edit'}
          </div>
        </div>
      </div>
    </div>

    {* Delete Post Confirmation Modal *}
    <div class="modal fade" id="deletePostModal" tabindex="-1" aria-labelledby="deletePostModalLabel" aria-hidden="true">
      <div class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="deletePostModalLabel">Confirm Delete</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <p>Are you sure you want to delete this post?</p>
            <p class="text-muted small mb-0">This action will archive the post and it will no longer be visible to visitors.</p>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="button" class="btn btn-danger" id="confirmDeletePost">Delete Post</button>
          </div>
        </div>
      </div>
    </div>

    {* Post Not Found Error Modal *}
    <div class="modal fade" id="postNotFoundModal" tabindex="-1" aria-labelledby="postNotFoundModalLabel" aria-hidden="true">
      <div class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="postNotFoundModalLabel">Post Not Found</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <p>Sorry, the post you're looking for could not be found.</p>
            <p class="text-muted small mb-0">It may have been removed or is no longer available.</p>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-primary" data-bs-dismiss="modal">OK</button>
          </div>
        </div>
      </div>
    </div>

    {* Edit Section Modals *}
    {include file='templates/modals/edit_hero.tpl'}
    {include file='templates/modals/edit_about.tpl'}
    {include file='templates/modals/edit_mailing_list.tpl'}
    {include file='templates/modals/edit_donation.tpl'}
    {include file='templates/modals/edit_footer.tpl'}

    {* Publish Confirmation Modal *}
    {include file='templates/partials/publish_confirmation_modal.tpl'}
    {include file='templates/partials/unpublish_confirmation_modal.tpl'}
    {include file='templates/partials/save_draft_confirmation_modal.tpl'}

    <meta name="csrf-token" content="{$csrf_token|default:''}" />

    {* Cropper + crop managers (needed for hero/footer image crop in modals) *}
    <link rel="stylesheet" href="https://unpkg.com/cropperjs@1.6.1/dist/cropper.min.css">
    <script src="https://unpkg.com/cropperjs@1.6.1/dist/cropper.min.js"></script>
    {if $_bundle_mode}
    {* Cropping bundle already loaded in header *}
    {else}
    <script defer src="{asset_js file='image-crop-manager.js'}"></script>
    <script defer src="{asset_js file='admin-crop-init.js'}"></script>
    {/if}

    {* Edit sections JS - only for authenticated users *}
    {if !$_bundle_mode}
    <script defer src="{asset_js file='edit-sections.js'}"></script>
    {/if}
  {/if}

  {* Page-specific JS - loaded for all users *}
  <script>
    window.POST_PORTAL_VIEW_SETTINGS = {
      showViewCounts: {$settings.show_view_counts|default:0},
      showImpressionCounts: {$settings.show_impression_counts|default:0},
      ignoreAdminTracking: {$settings.ignore_admin_tracking|default:1},
      isAuthenticated: {if $is_authenticated|default:false}true{else}false{/if}
    };
  </script>
  {if $_bundle_mode}
  <script defer src="{bundle_url name='home.bundle.js'}"></script>
  {else}
  <script defer src="{asset_js file='newsletter-signup.js'}"></script>
  <script defer src="{asset_js file='notifications.js'}"></script>
  <script defer src="{asset_js file='publish-confirmation.js'}"></script>
  <script defer src="{asset_js file='unpublish-confirmation.js'}"></script>
  <script defer src="{asset_js file='post-draft-handler.js'}"></script>
  <script defer src="{asset_js file='home.js'}"></script>
  {/if}

</div>

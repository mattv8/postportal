{* Overlay modal for full post view *}
<div id="post-overlay" class="post-overlay d-none">
  <div class="post-overlay-backdrop"></div>
  <div class="post-overlay-content card shadow">
    {* Metrics badges - upper left *}
    <div class="overlay-metrics-left d-none" id="overlay-metrics">
      <span class="badge rounded-pill bg-light text-dark border fw-semibold shadow-sm" id="overlay-view-badge"></span>
      <span class="badge rounded-pill bg-light text-dark border fw-semibold shadow-sm" id="overlay-impression-badge"></span>
    </div>
    {* Action buttons stay pinned to the header while content scrolls *}
     <div class="overlay-actions">
       {if $is_admin|default:false}
         <button type="button" class="btn btn-sm btn-success d-none" id="overlay-publish-draft" style="display: none;">
           <i class="bi bi-send me-1"></i> Publish
         </button>
         <button type="button" class="btn btn-sm btn-outline-light" id="overlay-edit" style="display: none;">
           <i class="bi bi-pencil"></i> Edit
         </button>
         <button type="button" class="btn btn-sm btn-outline-light" id="overlay-delete" style="display: none;">
           <i class="bi bi-trash"></i> Delete
         </button>
       {/if}
       <button type="button" class="btn-close btn-close-white" aria-label="Close" id="overlay-close"></button>
     </div>

    <div class="post-overlay-scroll">
      {* Hero image container - full width at top *}
      <div id="overlay-media" class="overlay-hero-container"></div>

      {* Content section with title and body *}
      <div class="card-body">
        <h2 class="mb-3" id="overlay-title"></h2>
        <div id="overlay-body"></div>
      </div>
    </div>
  </div>
</div>

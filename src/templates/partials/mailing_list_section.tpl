{* Mailing List Section - Newsletter signup with editable content *}
{if $settings.show_mailing_list}
  <style>
    #newsletter-signup-another {
      text-decoration: none;
      transition: text-decoration 0.2s ease;
    }
    #newsletter-signup-another:hover {
      text-decoration: underline;
    }
  </style>
  <div class="card mb-4 position-relative">
    {if $is_admin|default:false}
      <button class="btn btn-sm btn-light btn-edit-section position-absolute top-0 end-0 m-2" style="z-index: 10;" data-bs-toggle="modal" data-bs-target="#editMailingListModal" title="Edit Mailing List Section">
        <i class="bi bi-pencil"></i> Edit
      </button>
    {/if}
    <div class="card-body">
      <h5 class="card-title">Stay Updated</h5>
      <div class="card-text mb-3">{$settings.mailing_list_html nofilter}</div>

      {* Email signup form *}
      <div id="newsletter-signup-form">
        <form id="newsletterSignupForm" class="needs-validation" novalidate>
          <div class="mb-3">
            <label for="newsletter-email" class="form-label visually-hidden">Email address</label>
            <div class="input-group">
              <input
                type="email"
                class="form-control"
                id="newsletter-email"
                name="email"
                placeholder="Enter your email"
                required
                pattern="[^\s@]+@[^\s@]+\.[^\s@]+"
                maxlength="255"
              />
              <button class="btn btn-primary" type="submit" id="newsletter-submit-btn">
                <i class="bi bi-envelope"></i> Subscribe
              </button>
            </div>
            <div class="invalid-feedback" id="newsletter-error">
              Please enter a valid email address.
            </div>
          </div>
        </form>
      </div>

      <div id="newsletter-pending-confirmation" class="alert alert-success d-none" role="status">
        <i class="bi bi-check-circle-fill me-2"></i>
        <strong>Check your email to confirm.</strong> Open the verification link to complete your subscription.
      </div>
    </div>
  </div>
{/if}

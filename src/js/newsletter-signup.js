/**
 * Newsletter Signup Handler
 * Manages email subscription form submission and pending-confirmation state
 */
(function() {
  'use strict';

  // Email validation regex (client-side validation)
  // More permissive regex that allows modern TLDs and international domains
  function isValidEmail(email) {
    const regex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return regex.test(email);
  }

  // Show pending confirmation state. Acceptance does not prove subscription ownership.
  function showPendingState() {
    const formDiv = document.getElementById('newsletter-signup-form');
    const pendingDiv = document.getElementById('newsletter-pending-confirmation');

    if (formDiv) formDiv.classList.add('d-none');
    if (pendingDiv) pendingDiv.classList.remove('d-none');
  }

  // Initialize on DOM ready
  document.addEventListener('DOMContentLoaded', function() {
    // Setup form submission
    const form = document.getElementById('newsletterSignupForm');
    if (!form) return;

    const emailInput = document.getElementById('newsletter-email');
    const submitBtn = document.getElementById('newsletter-submit-btn');
    const errorDiv = document.getElementById('newsletter-error');

    form.addEventListener('submit', async function(e) {
      e.preventDefault();
      e.stopPropagation();

      // Reset validation state
      form.classList.remove('was-validated');
      if (errorDiv) errorDiv.textContent = 'Please enter a valid email address.';

      // Get email value
      const email = emailInput.value.trim();

      // Validate email
      if (!email || !isValidEmail(email)) {
        form.classList.add('was-validated');
        emailInput.focus();
        return;
      }

      // Disable button during submission
      const originalBtnText = submitBtn.innerHTML;
      submitBtn.disabled = true;
      submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>Subscribing...';

      try {
        // Submit to API
        const response = await fetch('/api/newsletter-subscribe.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json'
          },
          body: JSON.stringify({ email: email })
        });

        const result = await response.json();

        if (result.success) {
          showPendingState();

          // Clear form
          form.reset();
          form.classList.remove('was-validated');
        } else {
          // Show error
          if (errorDiv) {
            errorDiv.textContent = result.error || 'An error occurred. Please try again.';
          }
          form.classList.add('was-validated');
          emailInput.setCustomValidity('invalid');
          emailInput.focus();
        }
      } catch (error) {
        console.error('Newsletter signup error:', error);
        if (errorDiv) {
          errorDiv.textContent = 'Network error. Please check your connection and try again.';
        }
        form.classList.add('was-validated');
        emailInput.setCustomValidity('invalid');
      } finally {
        // Re-enable button
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalBtnText;
      }
    });

    // Clear custom validity on input
    emailInput.addEventListener('input', function() {
      this.setCustomValidity('');
    });
  });

})();

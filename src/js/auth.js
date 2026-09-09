(function () {
  'use strict';

  // SEC-11: Detect and handle activation mode from URL parameters
  // Contract: /?page=login&activate=1&username=<urlencoded>&token=<urlencoded>
  const urlParams = new URLSearchParams(window.location.search);
  const activationMode = urlParams.get('activate') === '1';
  const activationUsername = urlParams.get('username') || '';
  const activationToken = urlParams.get('token') || '';

  function setBanner(message, type) {
    const banner = document.getElementById('auth-banner');
    const text = document.getElementById('auth-banner-text');
    if (!banner || !text) return;

    banner.classList.remove('alert-success', 'alert-danger', 'alert-warning');
    banner.classList.add(`alert-${type || 'danger'}`);
    text.textContent = message;
    banner.style.display = 'block';
  }

  function setupActivationMode() {
    // Show/hide password confirmation field for activation
    const confirmField = document.getElementById('password-confirm-field');
    const usernameInput = document.getElementById('username');
    const submitBtn = document.getElementById('login-submit-btn');

    if (activationMode && activationUsername && activationToken) {
      // Set username to read-only
      if (usernameInput) {
        usernameInput.value = decodeURIComponent(activationUsername);
        usernameInput.readOnly = true;
      }

      // Show password confirmation field
      if (confirmField) {
        confirmField.style.display = 'block';
      }

      // Change button text
      if (submitBtn) {
        submitBtn.textContent = 'Set Password & Activate Account';
      }
    } else {
      // Login mode: hide password confirmation
      if (confirmField) {
        confirmField.style.display = 'none';
      }
      if (submitBtn) {
        submitBtn.textContent = 'Sign In';
      }
    }
  }

  function getRecaptchaToken() {
    const siteKey = (window.GLOBAL && window.GLOBAL.config && window.GLOBAL.config.recaptcha_key) || '';
    if (!siteKey) {
      return Promise.resolve(null);
    }

    if (typeof grecaptcha === 'undefined' || typeof grecaptcha.ready !== 'function') {
      return Promise.reject(new Error('reCAPTCHA is not available.'));
    }

    return new Promise((resolve, reject) => {
      grecaptcha.ready(() => {
        grecaptcha
          .execute(siteKey, { action: 'login' })
          .then((token) => resolve(token))
          .catch((err) => reject(err));
      });
    });
  }

  async function handleLogin(button) {
    const usernameInput = document.getElementById('username');
    const passwordInput = document.getElementById('password');
    const username = (usernameInput?.value || '').trim();
    const password = passwordInput?.value || '';

    if (!username || !password) {
      setBanner('Username and password are required.', 'warning');
      return;
    }

    if (button) {
      button.disabled = true;
      button.textContent = activationMode ? 'Activating...' : 'Signing in...';
    }

    try {
      // SEC-11: If in activation mode, POST to /api/auth/activate.php
      if (activationMode && activationToken) {
        const passwordConfirmInput = document.getElementById('password-confirm');
        const passwordConfirm = passwordConfirmInput?.value || '';

        if (password !== passwordConfirm) {
          setBanner('Passwords do not match.', 'warning');
          return;
        }

        const response = await fetch('/api/auth/activate.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            username: activationUsername,
            token: activationToken,
            password: password,
            password_confirm: passwordConfirm,
          }),
        });

        const payload = await response.json().catch(() => ({}));
        if (response.ok && payload.success) {
          setBanner('Account activated successfully! Redirecting to login...', 'success');
          setTimeout(() => {
            window.location = '/?page=login';
          }, 2000);
          return;
        }

        setBanner('Activation failed. Please try again or contact support.', 'danger');
        return;
      }

      // Normal login mode
      const recaptchaToken = await getRecaptchaToken().catch((err) => {
        setBanner('Unable to verify reCAPTCHA. Please try again.', 'danger');
        throw err;
      });

      const response = await fetch('/api/login.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ username, password, recaptcha_token: recaptchaToken }),
      });

      const payload = await response.json().catch(() => ({}));
      if (response.ok && payload.success) {
        // Redirect admins to admin page, non-admins to homepage
        window.location = payload.isadmin ? '/?page=admin' : '/';
        return;
      }

      const message = payload.message || 'Login failed. Please check your credentials.';
      setBanner(message, 'danger');
    } catch (error) {
      setBanner('Unable to sign in right now. Please try again.', 'danger');
    } finally {
      if (button) {
        button.disabled = false;
        button.textContent = activationMode ? 'Set Password & Activate Account' : 'Sign In';
      }
    }
  }

  // Initialize activation mode on page load
  function initializePage() {
    setupActivationMode();

    // Setup form submission
    const form = document.querySelector('form');
    if (form) {
      form.addEventListener('submit', (e) => {
        e.preventDefault();
        const btn = form.querySelector('button[type="submit"]');
        handleLogin(btn);
      });
    }

    // Also allow clicking on button directly
    window.handleLogin = handleLogin;
  }

  // Run on DOM ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializePage);
  } else {
    initializePage();
  }

  async function logoff() {
    try {
      // SEC-09: Include CSRF token for logout request
      const csrfToken = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
      await fetch('/api/logout.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': csrfToken,
        },
        credentials: 'same-origin',
      });
    } catch (error) {
      // Ignore network errors; we still redirect to login.
    } finally {
      window.location = '/?page=login';
    }
  }

  /**
   * Open a Bootstrap modal by ID
   * @param {string} modalId - The ID of the modal to open
   * @param {HTMLElement} navButton - The button that triggered the modal (for styling)
   * @param {Function} modalFunction - Optional callback function to run when modal opens
   */
  function openModal(modalId, navButton, modalFunction = null) {
    const modalElement = document.getElementById(modalId);
    if (!modalElement) {
      console.warn('Modal not found:', modalId);
      return;
    }

    // Add event listener for modal close/dismiss to reset button styling
    modalElement.addEventListener('hidden.bs.modal', function handler() {
      if (navButton) {
        navButton.classList.add('btn-secondary');
        navButton.classList.remove('btn-primary');
      }
      modalElement.removeEventListener('hidden.bs.modal', handler);
    });

    // Initialize and show the modal
    const modal = new bootstrap.Modal(modalElement);
    modal.show();

    // Update button styling
    if (navButton) {
      navButton.classList.remove('btn-secondary');
      navButton.classList.add('btn-primary');
    }

    // Call the modalFunction if provided
    if (modalFunction && typeof modalFunction === 'function') {
      modalFunction();
    }
  }

  window.handleLogin = handleLogin;
  window.logoff = logoff;
  window.openModal = openModal;
})();

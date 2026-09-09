/**
 * Shared Notification System
 * Provides toast notifications and email error handling across all pages
 */
(function() {
  'use strict';

  /**
   * Show a Bootstrap toast notification
   * @param {string} message - The message to display
   * @param {string} type - 'success', 'warning', 'error', 'info'
   * @param {Object} action - Optional { label: string, callback: function(toast, toastElement) }
   */
  function showNotification(message, type = 'info', action = null) {
    // Create toast container if it doesn't exist
    let toastContainer = document.querySelector('[data-notification-toast-container]');
    if (!toastContainer) {
      toastContainer = document.createElement('div');
      toastContainer.className = 'toast-container position-fixed top-0 end-0 p-3';
      toastContainer.id = 'notification-toast-container';
      toastContainer.setAttribute('data-notification-toast-container', '');
      toastContainer.style.zIndex = '9999';
      document.body.appendChild(toastContainer);
    }

    // Map type to Bootstrap alert class
    const typeMap = {
      'success': 'success',
      'warning': 'warning',
      'error': 'danger',
      'danger': 'danger',
      'info': 'info'
    };
    const bgClass = 'bg-' + (typeMap[type] || 'info');
    const textClass = (type === 'warning') ? 'text-dark' : 'text-white';

    // Create toast element
    const toastId = 'toast-' + Date.now();
    const toastElement = document.createElement('div');
    toastElement.id = toastId;
    toastElement.className = `toast ${bgClass} ${textClass}`;
    toastElement.setAttribute('data-toast-boundary', '');
    toastElement.setAttribute('role', 'alert');
    toastElement.setAttribute('aria-live', 'assertive');
    toastElement.setAttribute('aria-atomic', 'true');

    const toastHeader = document.createElement('div');
    toastHeader.className = `toast-header ${bgClass} ${textClass}`;
    const title = document.createElement('strong');
    title.className = 'me-auto';
    title.textContent = type === 'success' ? 'Success' : type === 'warning' ? 'Warning' : type === 'error' || type === 'danger' ? 'Error' : 'Info';
    const closeButton = document.createElement('button');
    closeButton.type = 'button';
    closeButton.className = 'btn-close btn-close-white';
    closeButton.setAttribute('data-bs-dismiss', 'toast');
    closeButton.setAttribute('aria-label', 'Close');
    toastHeader.appendChild(title);
    toastHeader.appendChild(closeButton);

    const toastBody = document.createElement('div');
    toastBody.className = 'toast-body';
    const messageElement = document.createElement('div');
    messageElement.setAttribute('data-toast-message', '');
    messageElement.style.whiteSpace = 'pre-line';
    messageElement.textContent = String(message);
    toastBody.appendChild(messageElement);

    if (action && action.label) {
      const actionContainer = document.createElement('div');
      actionContainer.className = 'mt-2 pt-2 border-top border-light border-opacity-25';
      actionContainer.setAttribute('data-toast-action-container', '');
      const actionButton = document.createElement('button');
      actionButton.type = 'button';
      actionButton.className = 'btn btn-sm btn-light toast-action-btn w-100';
      actionButton.setAttribute('data-toast-action', '');
      actionButton.textContent = String(action.label);
      actionContainer.appendChild(actionButton);
      toastBody.appendChild(actionContainer);
    }

    toastElement.appendChild(toastHeader);
    toastElement.appendChild(toastBody);
    toastContainer.appendChild(toastElement);

    // Initialize and show the toast
    const toast = new bootstrap.Toast(toastElement, {
      autohide: type === 'success', // Auto-hide success messages
      delay: 5000
    });
    toast.show();

    // Attach action callback if provided
    if (action && action.callback) {
      const actionBtn = toastElement.querySelector('.toast-action-btn');
      if (actionBtn) {
        actionBtn.addEventListener('click', () => {
          actionBtn.disabled = true;
          const spinner = document.createElement('span');
          spinner.className = 'spinner-border spinner-border-sm';
          spinner.setAttribute('aria-hidden', 'true');
          actionBtn.textContent = '';
          actionBtn.appendChild(spinner);
          action.callback(toast, toastElement);
        });
      }
    }

    // Remove from DOM after hidden
    toastElement.addEventListener('hidden.bs.toast', () => {
      toastElement.remove();
    });
  }

  /**
   * Show an email error with optional action button based on response
   * @param {Object} emailResponse - Response object with error, actionRequired, actionLabel
   * @param {string} customMessage - Custom message to display (overrides emailResponse.error if provided)
   */
  function showEmailError(emailResponse, customMessage = null) {
    const errorMsg = customMessage || emailResponse.error || 'Failed to send emails';

    // Check if the error has an actionable fix
    if (emailResponse.actionRequired === 'enable_notifications') {
      showNotification(errorMsg, 'warning', {
        label: emailResponse.actionLabel || 'Enable',
        callback: (toast, toastElement) => {
          // Call API to enable notifications
          const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
          fetch('/api/admin/settings.php', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify({ notify_subscribers_on_post: 1 })
          })
          .then(r => r.json())
          .then(result => {
            toast.hide();
            if (result.success) {
              showNotification('Notifications enabled! You can now resend emails.', 'success');
            } else {
              showNotification('Failed to enable notifications: ' + (result.error || 'Unknown error'), 'error');
            }
          })
          .catch(err => {
            toast.hide();
            showNotification('Failed to enable notifications: ' + err.message, 'error');
          });
        }
      });
    } else if (emailResponse.actionRequired === 'configure_smtp') {
      showNotification(errorMsg, 'warning', {
        label: emailResponse.actionLabel || 'Configure Email Settings',
        callback: (toast) => {
          toast.hide();
          // Navigate to Newsletter tab > Email Settings subtab
          // Check if we're already on the admin page (either via ?page=admin or direct adminApp element)
          const isAdminPage = window.location.search.includes('page=admin') || document.getElementById('adminApp');
          if (isAdminPage) {
            // Already on admin page - switch to Newsletter tab, then Email Settings subtab
            const newsletterTab = document.getElementById('tab-newsletter');
            if (newsletterTab) {
              newsletterTab.click();
              // Wait for tab switch, then click Email Settings subtab
              setTimeout(() => {
                const emailSettingsSubtab = document.getElementById('subtab-email-settings');
                if (emailSettingsSubtab) {
                  emailSettingsSubtab.click();
                  // Scroll to SMTP section after a brief delay
                  setTimeout(() => {
                    const smtpHost = document.getElementById('smtp_host');
                    if (smtpHost) {
                      smtpHost.scrollIntoView({ behavior: 'smooth', block: 'center' });
                      smtpHost.focus();
                    }
                  }, 300);
                }
              }, 300);
            }
          } else {
            // On homepage or other page - set sessionStorage for admin tab state then redirect
            sessionStorage.setItem('adminActiveTab', '#pane-newsletter');
            sessionStorage.setItem('adminNewsletterSubtab', 'email-settings');
            window.location.href = '/?page=admin';
          }
        }
      });
    } else {
      // No actionable fix, just show the error
      showNotification(errorMsg, 'warning');
    }
  }

  /**
   * Handle a publish result and show appropriate notifications
   * Returns true if publish was successful, false otherwise
   * Throws '__handled__' error if an actionable error was shown
   * @param {Object} publishResult - The API response from publish action
   * @returns {boolean} - True if successful
   * @throws {Error} - Throws '__handled__' if error was shown, or generic error otherwise
   */
  function handlePublishResult(publishResult) {
    if (!publishResult.success) {
      // Check if publish was rolled back due to email failure
      if (publishResult.publishRolledBack) {
        const errorMsg = publishResult.email?.error || publishResult.error || 'Email sending failed';
        showEmailError(
          publishResult.email || { error: errorMsg, actionRequired: 'configure_smtp', actionLabel: 'Configure Email Settings' },
          'Post NOT published: ' + errorMsg
        );
        throw new Error('__handled__'); // Signal that error was already shown
      }

      // Check if there's an actionable email error (config issue prevented publish)
      if (publishResult.email && publishResult.email.actionRequired) {
        showEmailError(publishResult.email, 'Cannot publish: ' + (publishResult.email.error || 'Email configuration required'));
        throw new Error('__handled__'); // Signal that error was already shown
      }
      throw new Error(publishResult.error || 'Unknown error');
    }

    // Show email result
    if (publishResult.email) {
      if (publishResult.email.sent && publishResult.email.count > 0) {
        showNotification(`Post published! Email sent to ${publishResult.email.count} subscriber(s).`, 'success');
      } else if (publishResult.email.sent === false && !publishResult.email.skipped) {
        showNotification('Post published successfully.', 'success');
        // Show actionable error toast for email failure after publish
        showEmailError(publishResult.email, 'Email notification failed');
      } else {
        showNotification('Post published successfully.', 'success');
      }
    } else {
      showNotification('Post published successfully.', 'success');
    }

    return true;
  }

  // Expose functions globally
  window.showNotification = showNotification;
  window.showEmailError = showEmailError;
  window.handlePublishResult = handlePublishResult;

})();

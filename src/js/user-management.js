/**
 * User Management Module
 * Provides CRUD functionality for user administration with inline editing
 */
(function() {
  'use strict';

  // Module state
  let users = [];
  let currentDeleteUsername = null;

  // DOM elements
  const userMgmtModal = document.getElementById('UserMgmtModal');
  const userTableBody = document.getElementById('user-mgmt-tbody');
  const createUserForm = document.getElementById('createUserForm');
  const deleteUserModal = document.getElementById('deleteUserModal');
  const confirmDeleteUserBtn = document.getElementById('confirmDeleteUser');
  const toggleNewPasswordBtn = document.getElementById('toggleNewPassword');
  const generatePasswordBtn = document.getElementById('generatePasswordBtn');
  const createAndEmailBtn = document.getElementById('createAndEmailBtn');

  // Get CSRF token
  function getCsrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content || '';
  }

  // Use shared notification function from notifications.js
  const showNotification = window.showNotification;

  // API call helper
  async function api(url, options = {}) {
    const defaults = {
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': getCsrfToken()
      }
    };
    const config = { ...defaults, ...options };
    if (options.headers) {
      config.headers = { ...defaults.headers, ...options.headers };
    }

    try {
      const response = await fetch(url, config);
      return await response.json();
    } catch (error) {
      console.error('API error:', error);
      return { success: false, error: error.message };
    }
  }

  // Load users from API
  async function loadUsers() {
    userTableBody.innerHTML = `
      <tr>
        <td colspan="8" class="text-center text-muted py-4">
          <i class="bi bi-hourglass-split me-2"></i>Loading users...
        </td>
      </tr>
    `;

    const result = await api('/api/admin/users.php');

    if (result.success && result.data) {
      users = result.data;
      renderUsers();
    } else {
      userTableBody.innerHTML = `
        <tr>
          <td colspan="8" class="text-center text-danger py-4">
            <i class="bi bi-exclamation-circle me-2"></i>Failed to load users: ${result.error || 'Unknown error'}
          </td>
        </tr>
      `;
    }
  }

  // Render users table
  function renderUsers() {
    if (users.length === 0) {
      userTableBody.innerHTML = `
        <tr>
          <td colspan="8" class="text-center text-muted py-4">
            <i class="bi bi-people me-2"></i>No users found
          </td>
        </tr>
      `;
      return;
    }

    userTableBody.innerHTML = users.map((user, index) => {
      const isAdmin = parseInt(user.isadmin) === 1;
      const isActive = parseInt(user.active) === 1;

      return `
        <tr data-username="${escapeHtml(user.username)}">
          <td class="ps-3 align-middle">${index + 1}</td>
          <td class="align-middle">
            <span class="user-field username fw-bold" data-field="username">${escapeHtml(user.username)}</span>
          </td>
          <td class="align-middle">
            <span class="user-field editable" data-field="first" data-type="text">${escapeHtml(user.first || '')}</span>
            <button class="btn btn-link btn-sm p-0 ms-1 edit-field-btn" data-field="first" title="Edit">
              <i class="bi bi-pencil text-muted"></i>
            </button>
          </td>
          <td class="align-middle">
            <span class="user-field editable" data-field="last" data-type="text">${escapeHtml(user.last || '')}</span>
            <button class="btn btn-link btn-sm p-0 ms-1 edit-field-btn" data-field="last" title="Edit">
              <i class="bi bi-pencil text-muted"></i>
            </button>
          </td>
          <td class="align-middle">
            <span class="user-field editable" data-field="email" data-type="email">${escapeHtml(user.email || '')}</span>
            <button class="btn btn-link btn-sm p-0 ms-1 edit-field-btn" data-field="email" title="Edit">
              <i class="bi bi-pencil text-muted"></i>
            </button>
          </td>
          <td class="text-center align-middle">
            <div class="form-check form-switch d-flex justify-content-center m-0">
              <input class="form-check-input toggle-field" type="checkbox" data-field="isadmin" ${isAdmin ? 'checked' : ''} />
            </div>
          </td>
          <td class="text-center align-middle">
            <div class="form-check form-switch d-flex justify-content-center m-0">
              <input class="form-check-input toggle-field" type="checkbox" data-field="active" ${isActive ? 'checked' : ''} />
            </div>
          </td>
          <td class="text-center align-middle">
            <div class="btn-group btn-group-sm">
              <button class="btn btn-outline-secondary change-password-btn" title="Change Password">
                <i class="bi bi-key"></i>
              </button>
              <button class="btn btn-outline-danger delete-user-btn" title="Delete User">
                <i class="bi bi-trash"></i>
              </button>
            </div>
          </td>
        </tr>
      `;
    }).join('');

    attachRowEventListeners();
  }

  // HTML escape helper
  function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }

  // Set up event delegation on table body (called once)
  function setupTableEventDelegation() {
    if (!userTableBody) return;

    // Handle all clicks via delegation
    userTableBody.addEventListener('click', function(e) {
      const target = e.target;

      // Edit field button
      const editBtn = target.closest('.edit-field-btn');
      if (editBtn) {
        e.preventDefault();
        const row = editBtn.closest('tr');
        const field = editBtn.dataset.field;
        const span = row.querySelector(`.user-field[data-field="${field}"]`);
        startInlineEdit(row, span, field);
        return;
      }

      // Change password button
      const pwBtn = target.closest('.change-password-btn');
      if (pwBtn) {
        const row = pwBtn.closest('tr');
        const username = row.dataset.username;
        startPasswordEdit(row, username);
        return;
      }

      // Delete user button
      const delBtn = target.closest('.delete-user-btn');
      if (delBtn) {
        const row = delBtn.closest('tr');
        const username = row.dataset.username;
        showDeleteConfirmation(username);
        return;
      }
    });

    // Handle toggle switches via delegation
    userTableBody.addEventListener('change', async function(e) {
      const input = e.target;
      if (!input.classList.contains('toggle-field')) return;

      const row = input.closest('tr');
      const username = row.dataset.username;
      const field = input.dataset.field;
      const value = input.checked ? 1 : 0;

      input.disabled = true;

      const result = await api('/api/admin/users.php', {
        method: 'PUT',
        body: JSON.stringify({ username, field, value })
      });

      input.disabled = false;

      if (result.success) {
        animateSuccess(row);
        showNotification(`User ${field === 'isadmin' ? 'admin status' : 'active status'} updated`, 'success');
      } else {
        input.checked = !input.checked; // Revert
        showNotification(result.error || 'Failed to update user', 'error');
      }
    });
  }

  // Legacy function - now a no-op since we use event delegation
  function attachRowEventListeners() {
    // Event delegation handles all events now
  }

  // Start inline editing for a field
  function startInlineEdit(row, span, field) {
    const currentValue = span.textContent;
    const type = span.dataset.type || 'text';

    // Create input
    const input = document.createElement('input');
    input.type = type;
    input.className = 'form-control form-control-sm d-inline-block';
    input.value = currentValue;
    input.style.width = 'auto';
    input.style.minWidth = '100px';
    input.style.maxWidth = '200px';

    // Create buttons
    const saveBtn = document.createElement('button');
    saveBtn.className = 'btn btn-link btn-sm p-0 ms-1 text-success';
    saveBtn.innerHTML = '<i class="bi bi-check-lg"></i>';
    saveBtn.title = 'Save';

    const cancelBtn = document.createElement('button');
    cancelBtn.className = 'btn btn-link btn-sm p-0 ms-1 text-danger';
    cancelBtn.innerHTML = '<i class="bi bi-x-lg"></i>';
    cancelBtn.title = 'Cancel';

    // Hide original elements
    span.style.display = 'none';
    const editBtn = row.querySelector(`.edit-field-btn[data-field="${field}"]`);
    if (editBtn) editBtn.style.display = 'none';

    // Insert new elements
    span.parentNode.insertBefore(input, span);
    span.parentNode.insertBefore(saveBtn, span);
    span.parentNode.insertBefore(cancelBtn, span);

    input.focus();
    input.select();

    // Save function
    async function saveEdit() {
      const newValue = input.value.trim();
      const username = row.dataset.username;

      // Validate email if this is the email field
      if (field === 'email' && newValue !== '') {
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailRegex.test(newValue)) {
          showNotification('Please enter a valid email address', 'warning');
          input.classList.add('is-invalid');
          return;
        }
      }

      input.disabled = true;
      saveBtn.disabled = true;
      cancelBtn.disabled = true;

      const result = await api('/api/admin/users.php', {
        method: 'PUT',
        body: JSON.stringify({ username, field, value: newValue })
      });

      if (result.success) {
        span.textContent = newValue;
        cancelEdit();
        animateSuccess(row);
        showNotification(`${field.charAt(0).toUpperCase() + field.slice(1)} updated`, 'success');
      } else {
        input.disabled = false;
        saveBtn.disabled = false;
        cancelBtn.disabled = false;
        showNotification(result.error || 'Failed to update', 'error');
      }
    }

    // Cancel function
    function cancelEdit() {
      input.remove();
      saveBtn.remove();
      cancelBtn.remove();
      span.style.display = '';
      if (editBtn) editBtn.style.display = '';
    }

    // Event listeners
    saveBtn.addEventListener('click', saveEdit);
    cancelBtn.addEventListener('click', cancelEdit);
    input.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        saveEdit();
      } else if (e.key === 'Escape') {
        cancelEdit();
      }
    });
  }

  // Start password change inline edit
  function startPasswordEdit(row, username) {
    const actionsCell = row.querySelector('td:last-child');
    const originalContent = actionsCell.innerHTML;

    actionsCell.innerHTML = `
      <div class="input-group input-group-sm" style="min-width: 240px;">
        <input type="password" class="form-control password-input" placeholder="New password" autocomplete="new-password" />
        <button class="btn btn-outline-secondary generate-pw-btn" type="button" title="Generate random password">
          <i class="bi bi-shuffle"></i>
        </button>
        <button class="btn btn-outline-secondary toggle-pw-btn" type="button" title="Show/hide">
          <i class="bi bi-eye"></i>
        </button>
        <button class="btn btn-success save-pw-btn" type="button" title="Save">
          <i class="bi bi-check"></i>
        </button>
        <button class="btn btn-secondary cancel-pw-btn" type="button" title="Cancel">
          <i class="bi bi-x"></i>
        </button>
      </div>
    `;

    const passwordInput = actionsCell.querySelector('.password-input');
    const generatePwBtn = actionsCell.querySelector('.generate-pw-btn');
    const togglePwBtn = actionsCell.querySelector('.toggle-pw-btn');
    const savePwBtn = actionsCell.querySelector('.save-pw-btn');
    const cancelPwBtn = actionsCell.querySelector('.cancel-pw-btn');

    passwordInput.focus();

    // Generate random password
    generatePwBtn.addEventListener('click', () => {
      const upper = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
      const lower = 'abcdefghijklmnopqrstuvwxyz';
      const numbers = '0123456789';
      const all = upper + lower + numbers;

      let password = '';
      password += upper[Math.floor(Math.random() * upper.length)];
      password += lower[Math.floor(Math.random() * lower.length)];
      password += numbers[Math.floor(Math.random() * numbers.length)];

      for (let i = 0; i < 9; i++) {
        password += all[Math.floor(Math.random() * all.length)];
      }

      password = password.split('').sort(() => Math.random() - 0.5).join('');
      passwordInput.value = password;
      passwordInput.type = 'text'; // Show generated password
      togglePwBtn.innerHTML = '<i class="bi bi-eye-slash"></i>';
      passwordInput.classList.remove('is-invalid');
    });

    // Toggle password visibility
    togglePwBtn.addEventListener('click', () => {
      const isPassword = passwordInput.type === 'password';
      passwordInput.type = isPassword ? 'text' : 'password';
      togglePwBtn.innerHTML = isPassword ? '<i class="bi bi-eye-slash"></i>' : '<i class="bi bi-eye"></i>';
    });

    // Save password
    async function savePassword() {
      const newPassword = passwordInput.value;

      // Validate password strength
      const hasUpper = /[A-Z]/.test(newPassword);
      const hasLower = /[a-z]/.test(newPassword);
      const hasNumber = /[0-9]/.test(newPassword);
      const hasLength = newPassword.length >= 8;

      if (!hasLength || !hasUpper || !hasLower || !hasNumber) {
        showNotification('Password must be 8+ characters with uppercase, lowercase, and number', 'warning');
        passwordInput.classList.add('is-invalid');
        return;
      }

      passwordInput.disabled = true;
      savePwBtn.disabled = true;
      cancelPwBtn.disabled = true;

      const result = await api('/api/admin/users.php', {
        method: 'PUT',
        body: JSON.stringify({ username, field: 'password', value: newPassword })
      });

      if (result.success) {
        actionsCell.innerHTML = originalContent;
        attachRowEventListeners();
        animateSuccess(row);
        showNotification('Password updated', 'success');
      } else {
        passwordInput.disabled = false;
        savePwBtn.disabled = false;
        cancelPwBtn.disabled = false;
        showNotification(result.error || 'Failed to update password', 'error');
      }
    }

    // Cancel
    function cancelPassword() {
      actionsCell.innerHTML = originalContent;
      attachRowEventListeners();
    }

    savePwBtn.addEventListener('click', savePassword);
    cancelPwBtn.addEventListener('click', cancelPassword);
    passwordInput.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        savePassword();
      } else if (e.key === 'Escape') {
        cancelPassword();
      }
    });
  }

  // Show delete confirmation
  function showDeleteConfirmation(username) {
    currentDeleteUsername = username;
    document.getElementById('deleteUserUsername').textContent = username;
    const modal = new bootstrap.Modal(deleteUserModal);
    modal.show();
  }

  // Confirm delete user
  async function confirmDeleteUser() {
    if (!currentDeleteUsername) return;

    const btn = confirmDeleteUserBtn;
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Deleting...';

    const result = await api(`/api/admin/users.php?username=${encodeURIComponent(currentDeleteUsername)}`, {
      method: 'DELETE'
    });

    btn.disabled = false;
    btn.innerHTML = originalText;

    if (result.success) {
      bootstrap.Modal.getInstance(deleteUserModal).hide();
      showNotification('User deleted successfully', 'success');
      loadUsers();
    } else {
      showNotification(result.error || 'Failed to delete user', 'error');
    }

    currentDeleteUsername = null;
  }

  // Create new user
  async function createUser(e) {
    e.preventDefault();

    if (!createUserForm.checkValidity()) {
      createUserForm.classList.add('was-validated');
      return;
    }

    const formData = new FormData(createUserForm);
    const userData = {
      username: formData.get('username'),
      password: formData.get('password'),
      first: formData.get('first') || '',
      last: formData.get('last') || '',
      email: formData.get('email') || '',
      isadmin: formData.get('isadmin') === 'on' ? 1 : 0,
      active: formData.get('active') === 'on' ? 1 : 0
    };

    const submitBtn = createUserForm.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Creating...';

    const result = await api('/api/admin/users.php', {
      method: 'POST',
      body: JSON.stringify(userData)
    });

    submitBtn.disabled = false;
    submitBtn.innerHTML = originalText;

    if (result.success) {
      createUserForm.reset();
      createUserForm.classList.remove('was-validated');
      // Reset active checkbox to checked (default)
      document.getElementById('newActive').checked = true;
      showNotification('User created successfully', 'success');
      loadUsers();
    } else {
      showNotification(result.error || 'Failed to create user', 'error');
    }
  }

  // Create new user and send email with credentials
  async function createUserAndEmail() {
    // The direct-create password is intentionally not part of the activation flow.
    const passwordInput = document.getElementById('newPassword');
    const passwordWasDisabled = passwordInput.disabled;
    passwordInput.disabled = true;
    const isFormValid = createUserForm.checkValidity();
    passwordInput.disabled = passwordWasDisabled;
    if (!isFormValid) {
      createUserForm.classList.add('was-validated');
      return;
    }

    // Check email is provided (required for sending)
    const emailInput = document.getElementById('newEmail');
    const email = emailInput.value.trim();
    if (!email) {
      emailInput.classList.add('is-invalid');
      showNotification('Email is required to send account information', 'warning');
      return;
    }

    // Validate email format
    if (!validateEmail(emailInput)) {
      showNotification('Please enter a valid email address', 'warning');
      return;
    }

    const formData = new FormData(createUserForm);
    const userData = {
      username: formData.get('username'),
      first: formData.get('first') || '',
      last: formData.get('last') || '',
      email: email,
      isadmin: formData.get('isadmin') === 'on' ? 1 : 0,
      active: formData.get('active') === 'on' ? 1 : 0,
      send_email: true // Flag to send welcome email
    };

    const originalText = createAndEmailBtn.innerHTML;
    createAndEmailBtn.disabled = true;
    createAndEmailBtn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Creating & Sending...';

    // Also disable the regular submit button
    const submitBtn = createUserForm.querySelector('button[type="submit"]');
    submitBtn.disabled = true;

    const result = await api('/api/admin/users.php', {
      method: 'POST',
      body: JSON.stringify(userData)
    });

    createAndEmailBtn.disabled = false;
    createAndEmailBtn.innerHTML = originalText;
    submitBtn.disabled = false;

    if (result.success) {
      createUserForm.reset();
      createUserForm.classList.remove('was-validated');
      // Reset active checkbox to checked (default)
      document.getElementById('newActive').checked = true;

      if (result.email_sent) {
        showNotification('User created and welcome email sent successfully', 'success');
      } else {
        showNotification('User created but email could not be sent: ' + (result.email_error || 'Unknown error'), 'warning');
      }
      loadUsers();
    } else {
      showNotification(result.error || 'Failed to create user', 'error');
    }
  }

  // Animate success background
  function animateSuccess(row) {
    row.style.transition = 'background-color 0.5s ease';
    row.style.backgroundColor = '#d1e7dd';
    setTimeout(() => {
      row.style.backgroundColor = '';
      setTimeout(() => {
        row.style.transition = '';
      }, 500);
    }, 1000);
  }

  // Generate a random password (12 chars: upper, lower, numbers)
  function generatePassword() {
    const upper = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    const lower = 'abcdefghijklmnopqrstuvwxyz';
    const numbers = '0123456789';
    const all = upper + lower + numbers;

    // Ensure at least one of each required type
    let password = '';
    password += upper[Math.floor(Math.random() * upper.length)];
    password += lower[Math.floor(Math.random() * lower.length)];
    password += numbers[Math.floor(Math.random() * numbers.length)];

    // Fill remaining 9 characters randomly
    for (let i = 0; i < 9; i++) {
      password += all[Math.floor(Math.random() * all.length)];
    }

    // Shuffle the password
    password = password.split('').sort(() => Math.random() - 0.5).join('');

    const input = document.getElementById('newPassword');
    input.value = password;
    input.type = 'text'; // Show the generated password

    // Update toggle icon
    const icon = toggleNewPasswordBtn.querySelector('i');
    icon.className = 'bi bi-eye-slash';

    // Trigger validation
    validatePasswordStrength(input);
  }

  // Validate password strength
  function validatePasswordStrength(input) {
    const value = input.value;
    const hasUpper = /[A-Z]/.test(value);
    const hasLower = /[a-z]/.test(value);
    const hasNumber = /[0-9]/.test(value);
    const hasLength = value.length >= 8;

    const isValid = hasUpper && hasLower && hasNumber && hasLength;

    if (isValid) {
      input.setCustomValidity('');
      input.classList.remove('is-invalid');
      if (value.length > 0) input.classList.add('is-valid');
    } else {
      input.setCustomValidity('Password must be 8+ characters with uppercase, lowercase, and number');
      input.classList.remove('is-valid');
    }

    return isValid;
  }

  // Validate email format
  function validateEmail(input) {
    const value = input.value.trim();

    // Empty email is valid (not required)
    if (value === '') {
      input.setCustomValidity('');
      input.classList.remove('is-invalid', 'is-valid');
      return true;
    }

    // Basic email regex
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    const isValid = emailRegex.test(value);

    if (isValid) {
      input.setCustomValidity('');
      input.classList.remove('is-invalid');
      input.classList.add('is-valid');
    } else {
      input.setCustomValidity('Please enter a valid email address');
      input.classList.remove('is-valid');
      input.classList.add('is-invalid');
    }

    return isValid;
  }

  // Toggle new user password visibility
  function toggleNewPassword() {
    const input = document.getElementById('newPassword');
    const icon = toggleNewPasswordBtn.querySelector('i');
    const isPassword = input.type === 'password';
    input.type = isPassword ? 'text' : 'password';
    icon.className = isPassword ? 'bi bi-eye-slash' : 'bi bi-eye';
  }

  // Initialize
  function init() {
    if (!userMgmtModal) return;

    // Set up event delegation once
    setupTableEventDelegation();

    // Load users when modal is shown
    userMgmtModal.addEventListener('show.bs.modal', loadUsers);

    // Create user form
    if (createUserForm) {
      createUserForm.addEventListener('submit', createUser);
    }

    // Create and email button
    if (createAndEmailBtn) {
      createAndEmailBtn.addEventListener('click', createUserAndEmail);
    }

    // Confirm delete button
    if (confirmDeleteUserBtn) {
      confirmDeleteUserBtn.addEventListener('click', confirmDeleteUser);
    }

    // Toggle new password visibility
    if (toggleNewPasswordBtn) {
      toggleNewPasswordBtn.addEventListener('click', toggleNewPassword);
    }

    // Generate password button
    if (generatePasswordBtn) {
      generatePasswordBtn.addEventListener('click', generatePassword);
    }

    // Password strength validation
    const newPasswordInput = document.getElementById('newPassword');
    if (newPasswordInput) {
      newPasswordInput.addEventListener('input', function() {
        validatePasswordStrength(this);
      });
      newPasswordInput.addEventListener('blur', function() {
        if (this.value && !validatePasswordStrength(this)) {
          this.classList.add('is-invalid');
        }
      });
    }

    // Email validation
    const newEmailInput = document.getElementById('newEmail');
    if (newEmailInput) {
      newEmailInput.addEventListener('blur', function() {
        validateEmail(this);
      });
      newEmailInput.addEventListener('input', function() {
        if (this.classList.contains('is-invalid')) {
          validateEmail(this);
        }
      });
    }
  }

  // Run initialization on DOM ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  // Expose for global access if needed
  window.userManagement = {
    loadUsers,
    showNotification
  };
})();

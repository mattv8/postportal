(function () {
  const root = document.getElementById('adminApp');
  if (!root) return;
  const CSRF = root.getAttribute('data-csrf') || '';

  // Use shared notification functions from notifications.js
  // These are exposed globally: window.showNotification, window.showEmailError, window.handlePublishResult

  // Quill editor instances
  let heroEditor = null;
  let bioEditor = null;
  let donateEditor = null;
  let donationInstructionsEditor = null;
  let postBodyEditor = null;
  let footerCol1Editor = null;
  let footerCol2Editor = null;

  // Shared hero preview text updater (used by settings page hero preview)
  function updateHeroTextPreview(html) {
    const heroTextPreview = document.querySelector('.hero-preview-content');
    if (!heroTextPreview) {
      console.warn('Hero text preview element not found.');
      return;
    }
    heroTextPreview.innerHTML = html && html.trim() ? html : 'Hero text will appear here...';
  }

  // Use shared payment platform detection from shared-utils.js
  const detectPaymentPlatform = window.detectPaymentPlatform;
  const extractDonationUsername = window.extractDonationUsername;

  // Helper to toggle donation form fields based on method
  function updateDonationMethodVisibility(prefix = '') {
    const methodRadios = document.querySelectorAll(`input[name="${prefix}donation_method"]`);
    let selectedMethod = '';

    methodRadios.forEach(radio => {
      if (radio.checked) {
        selectedMethod = radio.value;
      }
    });

    // Get the containers
    const linkContainer = document.querySelector(`#${prefix}donation_link`)?.closest('.mb-3');
    const qrContainer = document.querySelector(`#${prefix}donation_qr_media_id`)?.closest('.mb-3');

    if (!linkContainer || !qrContainer) return;

    // Show/hide based on selected method
    switch (selectedMethod) {
      case 'link':
        linkContainer.style.display = 'block';
        qrContainer.style.display = 'none';
        break;
      case 'qr':
        linkContainer.style.display = 'none';
        qrContainer.style.display = 'block';
        break;
      case 'both':
        linkContainer.style.display = 'block';
        qrContainer.style.display = 'block';
        break;
      default:
        linkContainer.style.display = 'none';
        qrContainer.style.display = 'none';
    }
  }

  // Update preview for admin donation form (non-prefixed IDs)
  function updateAdminDonationPreview() {
    // Containers
    const instructionsPreview = document.getElementById('preview-instructions');
    const qrPreview = document.getElementById('preview-qr');
    const qrImg = qrPreview ? qrPreview.querySelector('img') : null;
    const linkPreview = document.getElementById('preview-link');
    const emptyPreview = document.getElementById('preview-empty');

    if (!instructionsPreview || !qrPreview || !linkPreview || !emptyPreview) return;

    // Read current values
    let donationMethod = 'link';
    document.querySelectorAll('input[name="donation_method"]').forEach(r => { if (r.checked) donationMethod = r.value; });
    const donationLink = document.getElementById('donation_link')?.value || '';
    const qrMediaId = document.getElementById('donation_qr_media_id')?.value || '';

    // Update instructions HTML
    try {
      const html = donationInstructionsEditor ? window.getQuillHTML(donationInstructionsEditor) : (document.getElementById('donation_instructions_html')?.innerHTML || '');
      instructionsPreview.innerHTML = html && html.trim() ? html : '<p>Thank you for your support!</p>';
    } catch (e) {
      console.warn('Could not update donation instructions preview:', e);
    }

    // Determine what to show based on method - match actual modal logic
    const showQr = (donationMethod === 'qr' || donationMethod === 'both') && qrMediaId;
    const showLink = (donationMethod === 'link' || donationMethod === 'both') && donationLink;

    // Update QR preview - match actual modal structure
    if (qrImg) {
      if (showQr) {
        // Fetch media info to get proper image URL
        fetch(`/api/admin/media.php?id=${qrMediaId}`)
          .then(r => r.json())
          .then(data => {
            if (data.success && data.data) {
              const variants = JSON.parse(data.data.variants_json || '{}');
              const variant400 = variants['400'];
              let previewUrl;
              if (variant400 && variant400.jpg) {
                previewUrl = '/storage/uploads/variants/400/' + variant400.jpg.split('/').pop();
              } else {
                previewUrl = '/storage/uploads/originals/' + data.data.filename;
              }
              qrImg.src = previewUrl;
              qrImg.alt = data.data.alt_text || 'Donation Image';
            }
          })
          .catch(err => console.error('Error loading image preview:', err));
      } else {
        qrImg.removeAttribute('src');
      }
    }
    qrPreview.style.display = showQr ? 'block' : 'none';

    // Update link preview - match actual modal structure
    const linkText = linkPreview.querySelector('.preview-link-text');
    const linkIcon = linkPreview.querySelector('.preview-platform-icon');
    const linkName = linkPreview.querySelector('.preview-platform-name');
    const linkHref = linkPreview.querySelector('a');

    if (showLink && linkText && linkIcon && linkName) {
      const platform = detectPaymentPlatform(donationLink);
      linkIcon.className = `preview-platform-icon bi ${platform.icon} ${platform.color}`;
      linkName.textContent = platform.name;

      // Extract username from URL using shared utility
      const username = extractDonationUsername(donationLink);

      linkText.textContent = username;
    } else if (linkText) {
      linkText.textContent = 'username';
    }

    if (linkHref) {
      linkHref.href = donationLink || '#';
    }

    linkPreview.style.display = showLink ? 'block' : 'none';

    // Empty state - only show if no QR or link is configured
    const hasContent = showQr || showLink;
    emptyPreview.style.display = hasContent ? 'none' : 'block';
  }  // Auto-save intervals
  let heroAutoSave = null;
  let bioAutoSave = null;
  let donateAutoSave = null;
  let donationInstructionsAutoSave = null;
  let postAutoSave = null;
  let footerCol1AutoSave = null;
  let footerCol2AutoSave = null;

  // Helper function to setup auto-save for settings fields
  function setupSettingsAutoSave(editor, fieldName) {

    // Map field names to status element IDs
    const statusMap = {
      'hero_html': 'hero-autosave-status',
      'site_bio_html': 'about-autosave-status',
      'donate_text_html': 'donation-autosave-status',
      'donation_instructions_html': 'donation-instructions-autosave-status',
      'footer_column1_html': 'footer-col1-autosave-status',
      'footer_column2_html': 'footer-col2-autosave-status'
    };

    const statusEl = document.getElementById(statusMap[fieldName]);
    if (statusEl) {
      statusEl.dataset.forceEnabled = '1';
      statusEl.innerHTML = '<span class="text-muted">Auto-save enabled</span>';
      statusEl.className = 'editor-autosave-indicator';
    }

    if (!window.setupAutoSave) {
      console.error('window.setupAutoSave is not defined!');
      return null;
    }

    return window.setupAutoSave(editor, {
      saveUrl: '/api/admin/settings-draft.php',
      method: 'PUT',
      buildPayload: (content) => {
        const payload = {};
        payload[fieldName] = content;
        return payload;
      },
      statusElementId: statusMap[fieldName],
      fieldName: `${fieldName} draft`
    });
  }

  // Helper function to setup auto-save for posts (saves to draft)
  function setupPostAutoSave(editor, postId) {
    if (!window.setupAutoSave) {
      console.error('window.setupAutoSave is not defined!');
      return null;
    }

    // Mark autosave as enabled for existing posts so the UI does not suggest an initial manual save
    const statusEl = document.getElementById('post-autosave-status');
    if (statusEl) {
      statusEl.dataset.forceEnabled = '1';
      statusEl.innerHTML = '<span class="text-muted">Auto-save enabled</span>';
      statusEl.className = 'editor-autosave-indicator';
    }

    return window.setupAutoSave(editor, {
      saveUrl: `/api/admin/posts-draft.php?id=${postId}`,
      method: 'PUT',
      buildPayload: (content) => ({ body_html: content }),
      statusElementId: 'post-autosave-status',
      fieldName: `post ${postId} draft`
    });
  }

  // Simple helper
  const api = (url, opts = {}) => {
    opts.headers = Object.assign({ 'X-CSRF-Token': CSRF }, opts.headers || {});
    return fetch(url, opts).then(r => r.json());
  };

  // Silent cache purge helper - purges cache without user notification
  function purgeCache() {
    api('/api/admin/cache-purge.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' }
    }).catch(() => {}); // Silent failure is ok
  }

  // Expose cache purge globally for other scripts (branding.js, newsletter-admin.js)
  window.purgeSiteCache = purgeCache;

  /**
   * Unpublish a post (revert to draft status)
   * @param {number|string} postId - The ID of the post to unpublish
   * @param {Object} options - Optional callbacks
   * @param {Function} options.onSuccess - Called on success
   * @param {Function} options.onError - Called on error with error message
   * @returns {Promise<boolean>} - True if successful
   */
  async function unpublishPost(postId, options = {}) {
    try {
      const payload = {
        status: 'draft',
        published_at: null
      };

      const result = await api('/api/admin/posts.php?id=' + postId, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });

      if (result.success) {
        purgeCache();
        if (options.onSuccess) options.onSuccess();
        return true;
      } else {
        const errorMsg = result.error || 'Unknown error';
        if (options.onError) options.onError(errorMsg);
        return false;
      }
    } catch (err) {
      console.error('Error unpublishing post:', err);
      if (options.onError) options.onError(err.message || 'An error occurred');
      return false;
    }
  }

  // Expose unpublish function globally for reuse
  window.unpublishPost = unpublishPost;

  // Dashboard
  function loadDashboard() {
    // Check if dashboard elements exist before loading
    const postsTotal = document.getElementById('postsTotal');
    if (!postsTotal) return; // Dashboard not present on this page

    fetch('/api/admin/dashboard.php').then(r => r.json()).then(j => {
      if (!j.success) return;
      postsTotal.innerText = j.data.posts_total;
      document.getElementById('postsPublished').innerText = j.data.posts_published;
      document.getElementById('mediaTotal').innerText = j.data.media_total;
      const ul = document.getElementById('recentPosts');
      if (ul) {
        ul.innerHTML = '';
        j.data.recent_posts.forEach(p => {
          const li = document.createElement('li'); li.className = 'list-group-item';
          li.textContent = `#${p.id} ${p.title || '(untitled)'} — ${p.published_at || p.created_at}`;
          ul.appendChild(li);
        });
      }
    }).catch(err => {
      console.error('Dashboard load error:', err);
    });
  }

  // Settings
  function loadSettings() {
    fetch('/api/admin/settings.php').then(r => r.json()).then(j => {
      if (!j.success || !j.data) return;

      const siteTitleElement = document.getElementById('site_title');
      if (siteTitleElement) {
        siteTitleElement.value = j.data.site_title || '';
      }

      // Load visibility toggles
      const showHeroElement = document.getElementById('show_hero');
      const showAboutElement = document.getElementById('show_about');
      const showDonationElement = document.getElementById('show_donation');
      const showFooterElement = document.getElementById('show_footer');

      if (showHeroElement) showHeroElement.checked = j.data.show_hero == 1;
      if (showAboutElement) showAboutElement.checked = j.data.show_about == 1;
      if (showDonationElement) showDonationElement.checked = j.data.show_donation == 1;
      if (showFooterElement) showFooterElement.checked = j.data.show_footer == 1;

      // Load donate button toggle
      const showDonateButtonCheckbox = document.getElementById('show_donate_button');
      if (showDonateButtonCheckbox) {
        showDonateButtonCheckbox.checked = j.data.show_donate_button == 1;
      }

      // Load hero media ID
      const heroMediaId = j.data.hero_media_id || '';
      const heroMediaSelect = document.getElementById('hero_media_id');
      if (heroMediaSelect) {
        heroMediaSelect.value = heroMediaId;
        // Trigger change to show preview
        if (heroMediaId) {
          heroMediaSelect.dispatchEvent(new Event('change'));
        }
      }

      // Load hero HTML into editor (use draft content)
      const heroHtml = j.data.hero_html_editing || '';
      if (heroEditor) {
        window.setQuillHTML(heroEditor, heroHtml);
        updateHeroTextPreview(heroHtml);
      } else {
        const heroHtmlElement = document.getElementById('hero_html');
        if (heroHtmlElement) {
          heroHtmlElement.value = heroHtml;
        }
        updateHeroTextPreview(heroHtml);
      }

      const ctaTextElement = document.getElementById('cta_text');
      const ctaUrlElement = document.getElementById('cta_url');
      const heroOpacityElement = document.getElementById('hero_overlay_opacity');
      const heroColorElement = document.getElementById('hero_overlay_color');
      const heroColorHexElement = document.getElementById('hero_overlay_color_hex');
      const heroHeightElement = document.getElementById('hero_height');

      if (ctaTextElement) ctaTextElement.value = j.data.cta_text || '';
      if (ctaUrlElement) ctaUrlElement.value = j.data.cta_url || '';

      // Load hero overlay opacity and update preview
      const heroOpacity = j.data.hero_overlay_opacity || 0.5;
      if (heroOpacityElement) {
        heroOpacityElement.value = heroOpacity;
        // Update opacity value display
        const opacityValueEl = document.querySelector('.hero-overlay-opacity-value');
        if (opacityValueEl) opacityValueEl.textContent = parseFloat(heroOpacity).toFixed(2);
        // Update overlay style
        const overlayEl = document.querySelector('.hero-banner-overlay');
        if (overlayEl) overlayEl.style.opacity = heroOpacity;
      }

      // Load hero overlay color and update preview
      const heroColor = j.data.hero_overlay_color || '#000000';
      if (heroColorElement) {
        heroColorElement.value = heroColor;
        if (heroColorHexElement) heroColorHexElement.value = heroColor;
        // Update overlay style
        const overlayEl = document.querySelector('.hero-banner-overlay');
        if (overlayEl) overlayEl.style.backgroundColor = heroColor;
      }

      // Load hero height and update preview
      const heroHeight = j.data.hero_height || 100;
      if (heroHeightElement) {
        heroHeightElement.value = heroHeight;
        // Update height value display
        const heightValueEl = document.querySelector('.hero-banner-height-value');
        if (heightValueEl) heightValueEl.textContent = heroHeight;
        // Update preview aspect ratio
        const previewEl = document.querySelector('.hero-banner-preview');
        if (previewEl) previewEl.style.paddingBottom = heroHeight + '%';
      }

      // Load bio HTML into editor (use draft content)
      const bioHtml = j.data.site_bio_html_editing || '';
      if (bioEditor) {
        window.setQuillHTML(bioEditor, bioHtml);
      } else {
        const bioHtmlElement = document.getElementById('site_bio_html');
        if (bioHtmlElement) {
          bioHtmlElement.value = bioHtml;
        }
      }

      // Load donate HTML into editor (use draft content)
      const donateHtml = j.data.donate_text_html_editing || '';
      if (donateEditor) {
        window.setQuillHTML(donateEditor, donateHtml);
      } else {
        const donateTextElement = document.getElementById('donate_text_html');
        if (donateTextElement) {
          donateTextElement.value = donateHtml;
        }
      }

      // Load donation instructions HTML into editor (use draft content)
      const donationInstructionsHtml = j.data.donation_instructions_html_editing || '';
      if (donationInstructionsEditor) {
        window.setQuillHTML(donationInstructionsEditor, donationInstructionsHtml);
      }

      // Load donation method radio buttons
      const donationMethod = j.data.donation_method || 'link';
      const methodRadio = document.getElementById(`donation_method_${donationMethod}`);
      if (methodRadio) {
        methodRadio.checked = true;
        updateDonationMethodVisibility('');
      }

      // Load donation link
      const donationLinkElement = document.getElementById('donation_link');
      if (donationLinkElement) {
        donationLinkElement.value = j.data.donation_link || '';
      }

      // Load QR media ID and update preview after media options are loaded
      const donationQrMediaElement = document.getElementById('donation_qr_media_id');
      if (donationQrMediaElement) {
        donationQrMediaElement.value = j.data.donation_qr_media_id || '';
        // Wait for media options to be loaded before updating preview
        if (window.donationMediaOptionsLoaded) {
          window.donationMediaOptionsLoaded.then(() => {
            if (typeof updateAdminDonationPreview === 'function') {
              updateAdminDonationPreview();
            }
          });
        }
      }

      // Legacy: Load donation presets if element exists (deprecated)
      try {
        const donationPresetsElement = document.getElementById('donation_presets');
        if (donationPresetsElement) {
          const ds = j.data.donation_settings_json ? JSON.parse(j.data.donation_settings_json) : {};
          donationPresetsElement.value = (ds.preset_amounts || []).join(',');
        }
      } catch (e) {
        console.warn('Could not load donation presets:', e);
      }

      // Load public view count toggle
      const showViewCountsElement = document.getElementById('show_view_counts');
      if (showViewCountsElement) {
        showViewCountsElement.checked = j.data.show_view_counts == 1;
      }

      // Load public impression count toggle
      const showImpressionCountsElement = document.getElementById('show_impression_counts');
      if (showImpressionCountsElement) {
        showImpressionCountsElement.checked = j.data.show_impression_counts == 1;
      }

      // Load ignore admin tracking toggle
      const ignoreAdminTrackingElement = document.getElementById('ignore_admin_tracking');
      if (ignoreAdminTrackingElement) {
        ignoreAdminTrackingElement.checked = j.data.ignore_admin_tracking == 1;
      }

      // Load AI system prompt
      const aiPromptElement = document.getElementById('ai_system_prompt');
      if (aiPromptElement) {
        aiPromptElement.value = j.data.ai_system_prompt || '';
      }

      // Load SMTP settings
      const smtpHostElement = document.getElementById('smtp_host');
      const smtpPortElement = document.getElementById('smtp_port');
      const smtpSecureElement = document.getElementById('smtp_secure');
      const smtpAuthElement = document.getElementById('smtp_auth');
      const smtpUsernameElement = document.getElementById('smtp_username');
      const smtpPasswordElement = document.getElementById('smtp_password');
      const smtpFromEmailElement = document.getElementById('smtp_from_email');
      const smtpFromNameElement = document.getElementById('smtp_from_name');

      if (smtpHostElement) smtpHostElement.value = j.data.smtp_host || '';
      if (smtpPortElement) smtpPortElement.value = j.data.smtp_port || 587;
      if (smtpSecureElement) smtpSecureElement.value = j.data.smtp_secure || 'tls';
      if (smtpAuthElement) smtpAuthElement.checked = j.data.smtp_auth == 1;
      if (smtpUsernameElement) smtpUsernameElement.value = j.data.smtp_username || '';
      if (smtpPasswordElement) smtpPasswordElement.value = j.data.smtp_password || '';
      if (smtpFromEmailElement) smtpFromEmailElement.value = j.data.smtp_from_email || '';
      if (smtpFromNameElement) smtpFromNameElement.value = j.data.smtp_from_name || '';

      // Load footer layout
      const footerLayoutSingle = document.getElementById('footer_layout_single');
      const footerLayoutDouble = document.getElementById('footer_layout_double');
      if (j.data.footer_layout === 'single' && footerLayoutSingle) {
        footerLayoutSingle.checked = true;
      } else if (footerLayoutDouble) {
        footerLayoutDouble.checked = true;
      }
      updateFooterColumn2Visibility();

      // Load footer media ID
      const footerMediaSelect = document.getElementById('footer_media_id');
      if (footerMediaSelect) {
        footerMediaSelect.value = j.data.footer_media_id || '';
        if (j.data.footer_media_id) {
          footerMediaSelect.dispatchEvent(new Event('change'));
        }
      }

      // Load footer height
      const footerHeightSlider = document.getElementById('footer_height');
      const footerHeightValue = document.querySelector('.footer-height-value');
      const footerHeight = j.data.footer_height || 30;
      if (footerHeightSlider) {
        footerHeightSlider.value = footerHeight;
        if (footerHeightValue) footerHeightValue.textContent = footerHeight;
        const footerPreview = document.querySelector('.footer-preview');
        if (footerPreview) footerPreview.style.paddingBottom = footerHeight + '%';
      }

      // Load footer overlay settings
      const footerOpacitySlider = document.getElementById('footer_overlay_opacity');
      const footerOpacityValue = document.querySelector('.footer-overlay-opacity-value');
      const footerOpacity = j.data.footer_overlay_opacity || 0.5;
      if (footerOpacitySlider) {
        footerOpacitySlider.value = footerOpacity;
        if (footerOpacityValue) footerOpacityValue.textContent = parseFloat(footerOpacity).toFixed(2);
        const footerOverlay = document.querySelector('.footer-overlay');
        if (footerOverlay) footerOverlay.style.opacity = footerOpacity;
      }

      const footerColorPicker = document.getElementById('footer_overlay_color');
      const footerColorHex = document.getElementById('footer_overlay_color_hex');
      const footerColor = j.data.footer_overlay_color || '#000000';
      if (footerColorPicker) {
        footerColorPicker.value = footerColor;
        if (footerColorHex) footerColorHex.value = footerColor;
        const footerOverlay = document.querySelector('.footer-overlay');
        if (footerOverlay) footerOverlay.style.backgroundColor = footerColor;
      }

      // Load footer column HTML into editors (use draft content)
      if (footerCol1Editor) {
        window.setQuillHTML(footerCol1Editor, j.data.footer_column1_html_editing || '');
        updateFooterTextPreview();
      }
      if (footerCol2Editor) {
        window.setQuillHTML(footerCol2Editor, j.data.footer_column2_html_editing || '');
        updateFooterTextPreview();
      }
    });
  }

  // Helper to update footer column 2 visibility based on layout
  function updateFooterColumn2Visibility() {
    const layoutSingle = document.getElementById('footer_layout_single');
    const col2Container = document.getElementById('footer_column2_container');
    if (col2Container) {
      col2Container.style.display = layoutSingle?.checked ? 'none' : 'block';
    }
  }

  // Helper to update footer text preview
  function updateFooterTextPreview() {
    const footerPreviewManager = window.adminFooterPreviewManager;
    if (!footerPreviewManager || !footerCol1Editor) return;

    const layoutSingle = document.getElementById('footer_layout_single');
    if (layoutSingle?.checked) {
      footerPreviewManager.setLayout('single');
      const col1HTML = window.getQuillHTML(footerCol1Editor);
      footerPreviewManager.updateTextContent(col1HTML);
    } else {
      footerPreviewManager.setLayout('double');
      const col1HTML = window.getQuillHTML(footerCol1Editor);
      const col2HTML = footerCol2Editor ? window.getQuillHTML(footerCol2Editor) : '';
      footerPreviewManager.updateColumn1(col1HTML);
      footerPreviewManager.updateColumn2(col2HTML);
    }
  }

  // Save hero/settings
  document.getElementById('heroForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    const payload = {
      show_hero: document.getElementById('show_hero')?.checked ? 1 : 0,
      hero_media_id: document.getElementById('hero_media_id')?.value || null,
      hero_html: heroEditor ? window.getQuillHTML(heroEditor) : (document.getElementById('hero_html')?.value || ''),
      hero_overlay_opacity: document.getElementById('hero_overlay_opacity')?.value || 0.5,
      hero_overlay_color: document.getElementById('hero_overlay_color')?.value || '#000000',
      hero_height: document.getElementById('hero_height')?.value || 100,
    };

    // Save to draft first
    const draftResult = await api('/api/admin/settings-draft.php', {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });

    if (!draftResult.success) {
      alert('Error saving draft: ' + draftResult.error);
      return;
    }

    // Publish the draft
    const publishResult = await api('/api/admin/settings.php?action=publish', { method: 'GET' });

    if (!publishResult.success) {
      alert('Error publishing: ' + publishResult.error);
      return;
    }

    // Save non-draft fields
    await api('/api/admin/settings.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        show_hero: payload.show_hero,
        hero_media_id: payload.hero_media_id,
        hero_overlay_opacity: payload.hero_overlay_opacity,
        hero_overlay_color: payload.hero_overlay_color,
        hero_height: payload.hero_height
      })
    });

    purgeCache();
    showNotification('Hero banner saved successfully', 'success');
  });

  document.getElementById('aboutForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    const payload = {
      show_about: document.getElementById('show_about').checked ? 1 : 0,
      site_bio_html: bioEditor ? window.getQuillHTML(bioEditor) : document.getElementById('site_bio_html').value,
    };

    // Save to draft first
    const draftResult = await api('/api/admin/settings-draft.php', {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });

    if (!draftResult.success) {
      alert('Error saving draft: ' + draftResult.error);
      return;
    }

    // Publish the draft
    const publishResult = await api('/api/admin/settings.php?action=publish', { method: 'GET' });

    if (!publishResult.success) {
      alert('Error publishing: ' + publishResult.error);
      return;
    }

    // Save non-draft fields
    await api('/api/admin/settings.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        show_about: payload.show_about
      })
    });

    purgeCache();
    showNotification('About section saved successfully', 'success');
  });

  document.getElementById('donationForm').addEventListener('submit', async function (e) {
    e.preventDefault();

    // Get selected donation method
    let donationMethod = 'link';
    const methodRadios = document.querySelectorAll('input[name="donation_method"]');
    methodRadios.forEach(radio => {
      if (radio.checked) donationMethod = radio.value;
    });

    const payload = {
      show_donation: document.getElementById('show_donation').checked ? 1 : 0,
      show_donate_button: document.getElementById('show_donate_button')?.checked ? 1 : 0,
      donate_text_html: donateEditor ? window.getQuillHTML(donateEditor) : document.getElementById('donate_text_html').value,
      donation_instructions_html: donationInstructionsEditor ? window.getQuillHTML(donationInstructionsEditor) : (document.getElementById('donation_instructions_html')?.innerHTML || ''),
      donation_method: donationMethod,
      donation_link: document.getElementById('donation_link')?.value || '',
      donation_qr_media_id: document.getElementById('donation_qr_media_id')?.value || null
    };

    // Save to draft first
    const draftResult = await api('/api/admin/settings-draft.php', {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });

    if (!draftResult.success) {
      alert('Error saving draft: ' + draftResult.error);
      return;
    }

    // Publish the draft
    const publishResult = await api('/api/admin/settings.php?action=publish', { method: 'GET' });

    if (!publishResult.success) {
      alert('Error publishing: ' + publishResult.error);
      return;
    }

    // Save non-draft fields
    await api('/api/admin/settings.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        show_donation: payload.show_donation,
        show_donate_button: payload.show_donate_button,
        donation_method: payload.donation_method,
        donation_link: payload.donation_link,
        donation_qr_media_id: payload.donation_qr_media_id
      })
    });

    purgeCache();
    showNotification('Donation section saved successfully', 'success');
    showNotification('Donation settings saved successfully', 'success');
  });

  // Save footer settings
  document.getElementById('footerForm')?.addEventListener('submit', async function (e) {
    e.preventDefault();

    const layoutSingle = document.getElementById('footer_layout_single');
    const payload = {
      show_footer: document.getElementById('show_footer')?.checked ? 1 : 0,
      footer_layout: layoutSingle?.checked ? 'single' : 'double',
      footer_media_id: document.getElementById('footer_media_id')?.value || null,
      footer_height: parseInt(document.getElementById('footer_height')?.value) || 30,
      footer_overlay_opacity: parseFloat(document.getElementById('footer_overlay_opacity')?.value || 0.5),
      footer_overlay_color: document.getElementById('footer_overlay_color')?.value || '#000000',
      footer_column1_html: footerCol1Editor ? window.getQuillHTML(footerCol1Editor) : '',
      footer_column2_html: footerCol2Editor ? window.getQuillHTML(footerCol2Editor) : '',
    };

    // Save to draft first
    const draftResult = await api('/api/admin/settings-draft.php', {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });

    if (!draftResult.success) {
      alert('Error saving draft: ' + draftResult.error);
      return;
    }

    // Publish the draft
    const publishResult = await api('/api/admin/settings.php?action=publish', { method: 'GET' });

    if (!publishResult.success) {
      alert('Error publishing: ' + publishResult.error);
      return;
    }

    // Save non-draft fields
    const saveResult = await api('/api/admin/settings.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        show_footer: payload.show_footer,
        footer_layout: payload.footer_layout,
        footer_media_id: payload.footer_media_id,
        footer_height: payload.footer_height,
        footer_overlay_opacity: payload.footer_overlay_opacity,
        footer_overlay_color: payload.footer_overlay_color
      })
    });

    if (saveResult.success) {
      showNotification('Footer settings saved successfully', 'success');
    } else {
      showNotification('Error saving footer: ' + (saveResult.error || 'Unknown error'), 'error');
    }
  });

  document.getElementById('settingsForm').addEventListener('submit', function (e) {
    e.preventDefault();
    const presets = document.getElementById('donation_presets').value.split(',').map(s => parseInt(s.trim(), 10)).filter(n => !isNaN(n));
    const payload = {
      site_title: document.getElementById('site_title').value,
      donation_settings_json: JSON.stringify({ preset_amounts: presets }),
      ai_system_prompt: document.getElementById('ai_system_prompt').value
    };

    const submitBtn = e.target.querySelector('button[type="submit"]');
    const originalText = submitBtn ? submitBtn.textContent : '';
    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.textContent = 'Saving...';
    }

    api('/api/admin/settings.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) }).then(j => {
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.textContent = originalText;
      }
      if (j.success) {
        purgeCache();
        showNotification('Settings saved successfully', 'success');
      } else {
        alert('Error: ' + j.error);
      }
    });
  });

  // Reset AI prompt to default
  const resetAIPromptBtn = document.getElementById('btnResetAIPrompt');
  if (resetAIPromptBtn) {
    resetAIPromptBtn.addEventListener('click', function (e) {
      e.preventDefault();
      const defaultAIPrompt = this.getAttribute('data-default-prompt') || '';
      if (confirm('Reset AI system prompt to default?')) {
        document.getElementById('ai_system_prompt').value = defaultAIPrompt;
      }
    });
  }

  // Purge cache button handler
  const purgeCacheBtn = document.getElementById('btnPurgeCache');
  if (purgeCacheBtn) {
    purgeCacheBtn.addEventListener('click', function () {
      const statusEl = document.getElementById('cacheStatus');
      const originalText = this.innerHTML;

      // Disable button and show loading state
      this.disabled = true;
      this.innerHTML = '<span class="spinner-border spinner-border-sm" role="status"></span> Purging...';

      api('/api/admin/cache-purge.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' }
      }).then(j => {
        if (j.success) {
          showNotification('Cache purged successfully', 'success');
          if (statusEl) {
            // Format the time nicely
            const purgedAt = j.purged_at ? new Date(j.purged_at) : new Date();
            const formattedTime = purgedAt.toLocaleString(undefined, {
              month: 'short',
              day: 'numeric',
              year: 'numeric',
              hour: 'numeric',
              minute: '2-digit',
              hour12: true
            });
            statusEl.innerHTML = 'Last purged: <span id="lastPurgeTime">' + formattedTime + '</span>';
          }
        } else {
          showNotification('Error: ' + (j.error || 'Failed to purge cache'), 'error');
        }
      }).catch(error => {
        console.error('Error purging cache:', error);
        showNotification('Error purging cache', 'error');
      }).finally(() => {
        // Re-enable button
        this.disabled = false;
        this.innerHTML = originalText;
      });
    });
  }

  // Backup button handler
  const createBackupBtn = document.getElementById('btnCreateBackup');
  if (createBackupBtn) {
    createBackupBtn.addEventListener('click', function () {
      const statusEl = document.getElementById('backupStatus');
      const originalText = this.innerHTML;

      // Disable button and show loading state
      this.disabled = true;
      this.innerHTML = '<span class="spinner-border spinner-border-sm" role="status"></span> Creating backup...';
      if (statusEl) statusEl.textContent = '';

      // Create a form and submit it to trigger the download
      const form = document.createElement('form');
      form.method = 'POST';
      form.action = '/api/admin/backup.php';
      form.style.display = 'none';

      // Add CSRF token
      const csrfInput = document.createElement('input');
      csrfInput.type = 'hidden';
      csrfInput.name = 'csrf_token';
      csrfInput.value = CSRF;
      form.appendChild(csrfInput);

      document.body.appendChild(form);

      // Use an iframe to handle the download without navigating away
      const iframe = document.createElement('iframe');
      iframe.name = 'backupDownloadFrame';
      iframe.style.display = 'none';
      document.body.appendChild(iframe);

      form.target = 'backupDownloadFrame';
      form.submit();

      // Re-enable button after a delay (since we can't detect download completion)
      setTimeout(() => {
        this.disabled = false;
        this.innerHTML = originalText;
        if (statusEl) {
          const now = new Date().toLocaleString(undefined, {
            month: 'short', day: 'numeric', year: 'numeric',
            hour: 'numeric', minute: '2-digit', hour12: true
          });
          statusEl.textContent = 'Backup initiated: ' + now;
        }
        // Cleanup
        document.body.removeChild(form);
        document.body.removeChild(iframe);
      }, 3000);
    });
  }

  // Restore file input handler
  const restoreFileInput = document.getElementById('restoreFile');
  const restoreBackupBtn = document.getElementById('btnRestoreBackup');
  if (restoreFileInput && restoreBackupBtn) {
    restoreFileInput.addEventListener('change', function () {
      restoreBackupBtn.disabled = !this.files || this.files.length === 0;
    });
  }

  // Restore backup modal and confirmation handler
  const restoreBackupModalEl = document.getElementById('restoreBackupModal');
  if (restoreBackupModalEl && restoreBackupModalEl.parentElement !== document.body) {
    document.body.appendChild(restoreBackupModalEl);
  }
  const restoreBackupModal = restoreBackupModalEl ? new bootstrap.Modal(restoreBackupModalEl) : null;
  const confirmRestoreBackupBtn = document.getElementById('confirmRestoreBackup');

  // Restore button handler - shows confirmation modal
  if (restoreBackupBtn) {
    restoreBackupBtn.addEventListener('click', function () {
      const fileInput = document.getElementById('restoreFile');

      if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
        showNotification('Please select a backup file', 'warning');
        return;
      }

      // Show filename in modal
      const filenameEl = document.getElementById('restoreBackupFilename');
      if (filenameEl) {
        filenameEl.textContent = fileInput.files[0].name;
      }

      // Show confirmation modal
      if (restoreBackupModal) {
        restoreBackupModal.show();
      }
    });
  }

  // Confirm restore button handler - performs actual restore
  if (confirmRestoreBackupBtn) {
    confirmRestoreBackupBtn.addEventListener('click', function () {
      const fileInput = document.getElementById('restoreFile');
      const statusEl = document.getElementById('restoreStatus');
      const restoreBtn = document.getElementById('btnRestoreBackup');

      if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
        return;
      }

      // Hide modal
      if (restoreBackupModal) {
        restoreBackupModal.hide();
      }

      const originalText = restoreBtn ? restoreBtn.innerHTML : '';

      // Disable button and show loading state
      if (restoreBtn) {
        restoreBtn.disabled = true;
        restoreBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status"></span> Restoring...';
      }
      if (statusEl) statusEl.textContent = '';

      const formData = new FormData();
      formData.append('backup', fileInput.files[0]);

      fetch('/api/admin/restore.php', {
        method: 'POST',
        headers: {
          'X-CSRF-Token': CSRF
        },
        body: formData
      })
      .then(r => r.json())
      .then(j => {
        if (j.success) {
          showNotification('Backup restored successfully! Reloading page...', 'success');
          if (statusEl) {
            statusEl.innerHTML = '<span class="text-success"><i class="bi bi-check-circle"></i> Restored successfully</span>';
          }
          // Reload page after a short delay to show the restored data
          setTimeout(() => {
            window.location.reload();
          }, 2000);
        } else {
          showNotification('Error: ' + (j.error || 'Failed to restore backup'), 'error');
          if (statusEl) {
            statusEl.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle"></i> ' + (j.error || 'Restore failed') + '</span>';
          }
        }
      })
      .catch(error => {
        console.error('Error restoring backup:', error);
        showNotification('Error restoring backup', 'error');
        if (statusEl) {
          statusEl.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle"></i> Error occurred</span>';
        }
      })
      .finally(() => {
        // Re-enable button
        if (restoreBtn) {
          restoreBtn.disabled = false;
          restoreBtn.innerHTML = originalText;
        }
      });
    });
  }

  // Auto-save visibility toggles when changed
  const showHeroCheckbox = document.getElementById('show_hero');
  if (showHeroCheckbox) {
    showHeroCheckbox.addEventListener('change', function () {
      const payload = { show_hero: this.checked ? 1 : 0 };
      api('/api/admin/settings.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      }).then(j => {
        if (j.success) {
          purgeCache();
          showNotification('Setting saved successfully', 'success');
        } else {
          console.error('Error updating hero visibility:', j.error);
          // Revert checkbox on error
          this.checked = !this.checked;
          showNotification('Error: ' + (j.error || 'Failed to update hero visibility'), 'error');
        }
      }).catch(error => {
        console.error('Error updating hero visibility:', error);
        this.checked = !this.checked;
        showNotification('Error updating hero visibility', 'error');
      });
    });
  }

  const showAboutCheckbox = document.getElementById('show_about');
  if (showAboutCheckbox) {
    showAboutCheckbox.addEventListener('change', function () {
      const payload = { show_about: this.checked ? 1 : 0 };
      api('/api/admin/settings.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      }).then(j => {
        if (j.success) {
          purgeCache();
          showNotification('Setting saved successfully', 'success');
        } else {
          console.error('Error updating about visibility:', j.error);
          // Revert checkbox on error
          this.checked = !this.checked;
          showNotification('Error: ' + (j.error || 'Failed to update about visibility'), 'error');
        }
      }).catch(error => {
        console.error('Error updating about visibility:', error);
        this.checked = !this.checked;
        showNotification('Error updating about visibility', 'error');
      });
    });
  }

  const showDonationCheckbox = document.getElementById('show_donation');
  if (showDonationCheckbox) {
    showDonationCheckbox.addEventListener('change', function () {
      const payload = { show_donation: this.checked ? 1 : 0 };
      api('/api/admin/settings.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      }).then(j => {
        if (j.success) {
          purgeCache();
          showNotification('Setting saved successfully', 'success');
        } else {
          console.error('Error updating donation visibility:', j.error);
          // Revert checkbox on error
          this.checked = !this.checked;
          showNotification('Error: ' + (j.error || 'Failed to update donation visibility'), 'error');
        }
      }).catch(error => {
        console.error('Error updating donation visibility:', error);
        this.checked = !this.checked;
        showNotification('Error updating donation visibility', 'error');
      });
    });
  }

  const showDonateButtonCheckbox = document.getElementById('show_donate_button');
  if (showDonateButtonCheckbox) {
    showDonateButtonCheckbox.addEventListener('change', function () {
      const payload = { show_donate_button: this.checked ? 1 : 0 };
      api('/api/admin/settings.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      }).then(j => {
        if (j.success) {
          purgeCache();
          showNotification('Setting saved successfully', 'success');
        } else {
          console.error('Error updating donate button visibility:', j.error);
          // Revert checkbox on error
          this.checked = !this.checked;
          showNotification('Error: ' + (j.error || 'Failed to update donate button visibility'), 'error');
        }
      }).catch(error => {
        console.error('Error updating donate button visibility:', error);
        this.checked = !this.checked;
        showNotification('Error updating donate button visibility', 'error');
      });
    });
  }

  const showViewCountsCheckbox = document.getElementById('show_view_counts');
  if (showViewCountsCheckbox) {
    showViewCountsCheckbox.addEventListener('change', function () {
      const payload = { show_view_counts: this.checked ? 1 : 0 };
      api('/api/admin/settings.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      }).then(j => {
        if (j.success) {
          purgeCache();
          showNotification('Setting saved successfully', 'success');
        } else {
          console.error('Error updating view count visibility:', j.error);
          this.checked = !this.checked;
          showNotification('Error: ' + (j.error || 'Failed to update view count visibility'), 'error');
        }
      }).catch(error => {
        console.error('Error updating view count visibility:', error);
        this.checked = !this.checked;
        showNotification('Error updating view count visibility', 'error');
      });
    });
  }

  const showImpressionCountsCheckbox = document.getElementById('show_impression_counts');
  if (showImpressionCountsCheckbox) {
    showImpressionCountsCheckbox.addEventListener('change', function () {
      const payload = { show_impression_counts: this.checked ? 1 : 0 };
      api('/api/admin/settings.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      }).then(j => {
        if (j.success) {
          purgeCache();
          showNotification('Setting saved successfully', 'success');
        } else {
          console.error('Error updating impression count visibility:', j.error);
          this.checked = !this.checked;
          showNotification('Error: ' + (j.error || 'Failed to update impression count visibility'), 'error');
        }
      }).catch(error => {
        console.error('Error updating impression count visibility:', error);
        this.checked = !this.checked;
        showNotification('Error updating impression count visibility', 'error');
      });
    });
  }

  const ignoreAdminTrackingCheckbox = document.getElementById('ignore_admin_tracking');
  if (ignoreAdminTrackingCheckbox) {
    ignoreAdminTrackingCheckbox.addEventListener('change', function () {
      const payload = { ignore_admin_tracking: this.checked ? 1 : 0 };
      api('/api/admin/settings.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      }).then(j => {
        if (j.success) {
          showNotification('Setting saved successfully', 'success');
        } else {
          console.error('Error updating admin tracking setting:', j.error);
          this.checked = !this.checked;
          showNotification('Error: ' + (j.error || 'Failed to update admin tracking setting'), 'error');
        }
      }).catch(error => {
        console.error('Error updating admin tracking setting:', error);
        this.checked = !this.checked;
        showNotification('Error updating admin tracking setting', 'error');
      });
    });
  }

  const showFooterCheckbox = document.getElementById('show_footer');
  if (showFooterCheckbox) {
    showFooterCheckbox.addEventListener('change', function () {
      const payload = { show_footer: this.checked ? 1 : 0 };
      api('/api/admin/settings.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      }).then(j => {
        if (j.success) {
          purgeCache();
          showNotification('Setting saved successfully', 'success');
        } else {
          console.error('Error updating footer visibility:', j.error);
          this.checked = !this.checked;
          showNotification('Error: ' + (j.error || 'Failed to update footer visibility'), 'error');
        }
      }).catch(error => {
        console.error('Error updating footer visibility:', error);
        this.checked = !this.checked;
        showNotification('Error updating footer visibility', 'error');
      });
    });
  }

  // Move modal to body first (fix z-index stacking issue)
  // Modal must be direct child of body, not nested in framework containers
  const postEditorModal = document.getElementById('postEditorModal');
  if (postEditorModal && postEditorModal.parentElement !== document.body) {
    document.body.appendChild(postEditorModal);
  }

  // Move media delete modal to body as well
  const mediaDeleteModal = document.getElementById('mediaDeleteModal');
  if (mediaDeleteModal && mediaDeleteModal.parentElement !== document.body) {
    document.body.appendChild(mediaDeleteModal);
  }

  // Move post delete modal to body as well
  const deletePostModal = document.getElementById('deletePostModal');
  if (deletePostModal && deletePostModal.parentElement !== document.body) {
    document.body.appendChild(deletePostModal);
  }

  // Move newsletter modals to body as well
  const addSubscriberModal = document.getElementById('addSubscriberModal');
  if (addSubscriberModal && addSubscriberModal.parentElement !== document.body) {
    document.body.appendChild(addSubscriberModal);
  }

  const deleteSubscriberModal = document.getElementById('deleteSubscriberModal');
  if (deleteSubscriberModal && deleteSubscriberModal.parentElement !== document.body) {
    document.body.appendChild(deleteSubscriberModal);
  }

  const reactivateSubscriberModal = document.getElementById('reactivateSubscriberModal');
  if (reactivateSubscriberModal && reactivateSubscriberModal.parentElement !== document.body) {
    document.body.appendChild(reactivateSubscriberModal);
  }

  const resendEmailModalEl = document.getElementById('resendEmailModal');
  if (resendEmailModalEl && resendEmailModalEl.parentElement !== document.body) {
    document.body.appendChild(resendEmailModalEl);
  }
  const resendEmailModal = resendEmailModalEl ? new bootstrap.Modal(resendEmailModalEl) : null;
  const confirmResendEmailBtn = document.getElementById('confirmResendEmail');
  let pendingResendPostId = null;
  let pendingResendButton = null;
  let pendingResendButtonOriginal = '';

  if (resendEmailModalEl) {
    resendEmailModalEl.addEventListener('hidden.bs.modal', () => {
      pendingResendPostId = null;
      pendingResendButton = null;
      pendingResendButtonOriginal = '';
      if (confirmResendEmailBtn) confirmResendEmailBtn.disabled = false;
    });
  }

  // Posts - minimal list/create
  const postsList = document.getElementById('postsList');
  const postEditorContainer = postEditorModal.querySelector('.modal-body');
  const btnNewPost = document.getElementById('btnNewPost');
  let editingId = null;
  let galleryMediaIds = []; // Track gallery image IDs in order

  // Re-enable Publish & Email button if confirmation is cancelled
  if (!postEditorContainer.dataset.publishCancelBound) {
    document.addEventListener('publish-confirmation:cancelled', () => {
      const publishEmailBtn = postEditorContainer.querySelector('.btn-publish-email');
      if (publishEmailBtn) {
        publishEmailBtn.disabled = false;
        publishEmailBtn.innerHTML = '<i class="bi bi-send-fill me-1"></i>Publish & Email';
      }
      // Trigger a refresh of the post editor to show the saved draft
      if (editingId) {
        const refreshEvent = new CustomEvent('post-editor:refresh-needed');
        document.dispatchEvent(refreshEvent);
        // Also refresh the posts list table to show the new draft
        loadPosts();
      }
    });
    postEditorContainer.dataset.publishCancelBound = '1';
  }

  // Helper function to upload gallery images
  async function uploadGalleryImages(files, addToGalleryFn) {
    const uploadedIds = [];

    for (const file of files) {
      // Validate each file
      if (file.size > 20 * 1024 * 1024) {
        console.warn(`${file.name} is too large (max 20MB), skipping`);
        continue;
      }

      const validTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/heic'];
      if (!validTypes.includes(file.type)) {
        console.warn(`${file.name} has invalid type, skipping`);
        continue;
      }

      const formData = new FormData();
      formData.append('file', file);
      formData.append('alt_text', '');

      const response = await fetch('/api/admin/media.php', {
        method: 'POST',
        headers: {
          'X-CSRF-Token': CSRF
        },
        body: formData
      });

      const data = await response.json();

      if (data.success) {
        uploadedIds.push(data.id);
        galleryMediaIds.push(data.id);
        // If addToGalleryFn provided, call it to show preview
        if (addToGalleryFn) {
          addToGalleryFn(data.id, data.data);
        }
      } else {
        console.error(`Failed to upload ${file.name}:`, data.error);
      }
    }

    return uploadedIds;
  }

  function loadPosts() {
    fetch('/api/admin/posts.php').then(r => r.json()).then(j => {
      if (!j.success) return;
      postsList.innerHTML = '';

      // Create responsive table wrapper
      const tableWrapper = document.createElement('div');
      tableWrapper.className = 'table-responsive';

      const table = document.createElement('table');
      table.className = 'table table-striped';
      table.innerHTML = '<thead><tr><th>ID</th><th>Title</th><th>Author</th><th>Published</th><th>Created</th><th>Actions</th></tr></thead>';
      const tbody = document.createElement('tbody');
      (j.data || []).forEach(p => {
        const tr = document.createElement('tr');
        const isPublished = p.status === 'published';
        const publishedDate = p.published_at ? new Date(p.published_at).toLocaleDateString() : '';

        // Build author name
        const authorName = [p.author_first, p.author_last].filter(Boolean).join(' ') || p.author_username || 'Unknown';

        // For draft posts, show draft content; for published posts, show published content
        const displayTitle = isPublished ?
          (p.title || '(untitled)') :
          (p.title_draft || p.title || '(untitled)');

         // Validate post ID before use
         const postId = parseInt(p.id, 10);
         if (isNaN(postId)) {
           console.error('Invalid post ID:', p.id);
           return;
         }

         // Create table cells with safe text content for plain-text fields
         const idCell = document.createElement('td');
         idCell.textContent = postId.toString();
         tr.appendChild(idCell);

         const titleCell = document.createElement('td');
         titleCell.textContent = displayTitle;
         tr.appendChild(titleCell);

         const authorCell = document.createElement('td');
         authorCell.className = 'text-muted small';
         authorCell.textContent = authorName;
         tr.appendChild(authorCell);

         // Create toggle cell with DOM methods (no innerHTML)
         const toggleCell = document.createElement('td');
         const formDiv = document.createElement('div');
         formDiv.className = 'form-check form-switch';

         const toggleInput = document.createElement('input');
         toggleInput.className = 'form-check-input publish-toggle';
         toggleInput.type = 'checkbox';
         toggleInput.setAttribute('data-id', postId.toString());
         if (isPublished) {
           toggleInput.checked = true;
         }
         formDiv.appendChild(toggleInput);

         const toggleLabel = document.createElement('label');
         toggleLabel.className = 'form-check-label small text-muted';
         toggleLabel.textContent = isPublished ? publishedDate : 'Draft';
         formDiv.appendChild(toggleLabel);

         toggleCell.appendChild(formDiv);
         tr.appendChild(toggleCell);

         const createdCell = document.createElement('td');
         createdCell.className = 'text-nowrap';
         createdCell.textContent = p.created_at;
         tr.appendChild(createdCell);

         // Create actions cell with safe DOM methods
         const actionsCell = document.createElement('td');
        actionsCell.className = 'text-nowrap';

        const editBtn = document.createElement('button');
        editBtn.className = 'btn btn-sm btn-outline-primary btn-edit-post';
        editBtn.setAttribute('data-id', postId.toString());
        editBtn.setAttribute('data-bs-toggle', 'modal');
        editBtn.setAttribute('data-bs-target', '#postEditorModal');
        editBtn.textContent = 'Edit';
        actionsCell.appendChild(editBtn);

        if (isPublished) {
          actionsCell.appendChild(document.createTextNode(' '));
          const resendBtn = document.createElement('button');
          resendBtn.className = 'btn btn-sm btn-outline-info btn-resend-email';
          resendBtn.setAttribute('data-id', postId.toString());
          resendBtn.setAttribute('title', 'Resend email notification to all active subscribers (sends duplicates)');
          resendBtn.innerHTML = '<i class="bi bi-envelope"></i> Resend to All';
          actionsCell.appendChild(resendBtn);
        }

        actionsCell.appendChild(document.createTextNode(' '));
        const deleteBtn = document.createElement('button');
        deleteBtn.className = 'btn btn-sm btn-outline-danger';
        deleteBtn.setAttribute('data-del', postId.toString());
        deleteBtn.textContent = 'Delete';
        actionsCell.appendChild(deleteBtn);

        tr.appendChild(actionsCell);
        tbody.appendChild(tr);
      });
      table.appendChild(tbody);
      tableWrapper.appendChild(table);
      postsList.appendChild(tableWrapper);
    });
  }

  postsList.addEventListener('click', function (e) {
    // Prevent toggle switches from triggering edit
    if (e.target.classList.contains('publish-toggle') || e.target.classList.contains('form-check-label')) {
      return;
    }

    const id = e.target.getAttribute('data-id');
    const del = e.target.getAttribute('data-del');

    if (id && e.target.classList.contains('btn-edit-post')) {
      // Edit button clicked - store the ID, data will load when modal opens
      editingId = parseInt(id, 10);
    }

    if (id && (e.target.classList.contains('btn-resend-email') || e.target.closest('.btn-resend-email'))) {
      const postId = parseInt(id, 10);
      const button = e.target.classList.contains('btn-resend-email') ? e.target : e.target.closest('.btn-resend-email');
      pendingResendPostId = postId;
      pendingResendButton = button;
      pendingResendButtonOriginal = button ? button.innerHTML : '';
      if (resendEmailModal) {
        resendEmailModal.show();
      }
      return;
    }

    if (del) {
      // Show delete confirmation modal instead of confirm()
      const deleteModal = new bootstrap.Modal(document.getElementById('deletePostModal'));
      const confirmBtn = document.getElementById('confirmDeletePostAdmin');
      confirmBtn.setAttribute('data-post-id', del);
      deleteModal.show();
    }
  });

  if (confirmResendEmailBtn) {
    confirmResendEmailBtn.addEventListener('click', () => {
      if (!pendingResendPostId || !pendingResendButton) {
        return;
      }

      const postId = pendingResendPostId;
      const button = pendingResendButton;
      const originalText = pendingResendButtonOriginal || button.innerHTML;

      confirmResendEmailBtn.disabled = true;
      if (resendEmailModal) {
        resendEmailModal.hide();
      }

      button.disabled = true;
      button.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Sending...';

      api('/api/admin/posts.php?action=resend-email&id=' + postId, {
        method: 'GET'
      }).then(j => {
        if (j.success) {
          if (j.email && j.email.sent && j.email.count > 0) {
            showNotification(`Email notifications resent to ${j.email.count} subscriber(s).`, 'success');
          } else if (j.email && j.email.sent === false) {
            showEmailError(j.email);
          } else {
            showNotification('Email sending completed.', 'success');
          }
        } else {
          showNotification(`Error: ${j.error || 'Failed to resend emails'}`, 'error');
        }
      }).catch(err => {
        console.error('Error resending emails:', err);
        showNotification('Error resending emails: ' + err.message, 'error');
      }).finally(() => {
        button.disabled = false;
        button.innerHTML = originalText;
        confirmResendEmailBtn.disabled = false;
        pendingResendPostId = null;
        pendingResendButton = null;
        pendingResendButtonOriginal = '';
      });
    });
  }

  // Handle publish toggle switches
  postsList.addEventListener('change', async function (e) {
    if (e.target.classList.contains('publish-toggle')) {
      const postId = e.target.getAttribute('data-id');
      const isPublished = e.target.checked;
      const toggleBtn = e.target;
      const originalChecked = !isPublished; // The state before the change

      // Disable toggle while processing
      toggleBtn.disabled = true;

      // If toggling to published, show email confirmation modal
      if (isPublished) {
        try {
          // Show email confirmation modal for publishing via toggle
          const result = await window.publishConfirmation.confirmAndSendEmail(async () => {
            // Publish WITH email
            const publishResult = await api('/api/admin/posts.php?action=publish&id=' + postId, {
              method: 'GET'
            });
            if (!publishResult.success) {
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
                showEmailError(publishResult.email, 'Email notification failed');
              } else {
                showNotification('Post published successfully.', 'success');
              }
            } else {
              showNotification('Post published successfully.', 'success');
            }

            // Purge cache silently
            api('/api/admin/cache-purge.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' }
            }).catch(() => {}); // Silent failure is ok

            // Reload the posts list to show updated date
            loadPosts();
          }, postId);

          if (result && result.action === 'publish-only') {
            // User chose to publish without email
            try {
              const j = await api('/api/admin/posts.php?action=publish&id=' + postId + '&skip_email=1', {
                method: 'GET'
              });
              if (j.success) {
                showNotification('Post published successfully.', 'success');
                // Purge cache silently
                api('/api/admin/cache-purge.php', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json' }
                }).catch(() => {});
                loadPosts();
              } else {
                throw new Error(j.error || 'Unknown error');
              }
            } catch (err) {
              console.error('Error publishing post:', err);
              alert('Error updating post status: ' + err.message);
              toggleBtn.checked = originalChecked;
              toggleBtn.disabled = false;
            }
          } else if (result && result.proceeded) {
            // User chose to send email - loadPosts() already called in callback
            // Toggle will be refreshed when posts list reloads
          } else {
            // User cancelled - restore toggle to original state
            toggleBtn.checked = originalChecked;
            toggleBtn.disabled = false;
          }
        } catch (err) {
          console.error('Error publishing via toggle:', err);
          // Don't show alert if error was already handled with actionable toast
          if (err.message !== '__handled__') {
            alert('Error updating post status: ' + err.message);
          }
          toggleBtn.checked = originalChecked;
          toggleBtn.disabled = false;
        }
      } else {
        // Toggling to draft - no confirmation needed
        unpublishPost(postId, {
          onSuccess: () => {
            loadPosts();
          },
          onError: (errorMsg) => {
            alert('Error updating post status: ' + errorMsg);
            e.target.checked = true;
            e.target.disabled = false;
          }
        }).then(success => {
          if (!success) {
            e.target.checked = true;
            e.target.disabled = false;
          }
        });
      }
    }
  });

  // Confirm delete post button in modal
  const confirmDeletePostAdmin = document.getElementById('confirmDeletePostAdmin');
  if (confirmDeletePostAdmin) {
    confirmDeletePostAdmin.addEventListener('click', function () {
      const postId = this.getAttribute('data-post-id');
      if (!postId) return;

      const originalText = this.textContent;
      this.disabled = true;
      this.textContent = 'Deleting...';

      api('/api/admin/posts.php?id=' + postId, { method: 'DELETE' })
        .then(j => {
          if (j.success) {
            // Close modal
            const deleteModal = bootstrap.Modal.getInstance(document.getElementById('deletePostModal'));
            if (deleteModal) deleteModal.hide();
            // Reload posts list
            loadPosts();
          } else {
            alert('Error deleting post: ' + (j.error || 'Unknown error'));
          }
        })
        .catch(err => {
          console.error('Error deleting post:', err);
          alert('An error occurred while deleting the post');
        })
        .finally(() => {
          this.disabled = false;
          this.textContent = originalText;
        });
    });
  }

  // When New Post button is clicked (modal opens via data-bs-toggle)
  btnNewPost.addEventListener('click', function () {
    editingId = null;
  });

  // Cancel button in post editor - use Bootstrap's dismiss
  postEditorContainer.querySelector('.btn-cancel-post').setAttribute('data-bs-dismiss', 'modal');

  // Unpublish button in post editor
  const unpublishBtnAdmin = postEditorContainer.querySelector('.btn-unpublish-post');
  if (unpublishBtnAdmin) {
    unpublishBtnAdmin.addEventListener('click', async function () {
      if (!editingId) return;

      const btn = this;
      const originalText = btn.innerHTML;

      // Use the centralized confirmation module
      await window.unpublishConfirmation.confirmAndUnpublish(editingId, {
        onStart: () => {
          btn.disabled = true;
          btn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Unpublishing...';
        },
        onSuccess: () => {
          showNotification('Post unpublished and reverted to draft.', 'info');
          loadPosts();
          // Close the modal
          const modalEl = bootstrap.Modal.getInstance(postEditorModal);
          if (modalEl) modalEl.hide();
        },
        onError: (errorMsg) => {
          alert('Error unpublishing post: ' + errorMsg);
        },
        onComplete: () => {
          btn.disabled = false;
          btn.innerHTML = originalText;
        }
      });
    });
  }

  // Publish Changes button handler - same functionality as Publish, used for already-published posts
  postEditorContainer.querySelector('.btn-publish-changes').addEventListener('click', async function () {
    // Delegate to the Publish button handler (which shows email confirmation modal)
    postEditorContainer.querySelector('.btn-publish-email').click();
  });

  // Publish & Email button handler (Publish and send email notification)
  postEditorContainer.querySelector('.btn-publish-email').addEventListener('click', async function () {
    const saveBtn = this;
    const originalHTML = saveBtn.innerHTML;

    try {
      saveBtn.disabled = true;

      // Check if there are pending gallery uploads
      const galleryUploadInput = postEditorContainer.querySelector('.gallery-upload-input');
      if (galleryUploadInput && galleryUploadInput.files.length > 0) {
        const fileCount = galleryUploadInput.files.length;
        saveBtn.innerHTML = `<i class="bi bi-hourglass-split me-1"></i>Uploading gallery...`;
        try {
          await uploadGalleryImages(Array.from(galleryUploadInput.files));
          galleryUploadInput.value = '';
          const galleryUploadBtn = postEditorContainer.querySelector('.btn-upload-gallery');
          if (galleryUploadBtn) galleryUploadBtn.style.display = 'none';
        } catch (error) {
          alert('Gallery upload failed: ' + error.message);
          saveBtn.disabled = false;
          saveBtn.innerHTML = originalHTML;
          return;
        }
      }

      saveBtn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Saving...';

      const heroSelect = postEditorContainer.querySelector('.post-hero-media');
      const heroMediaId = heroSelect ? heroSelect.value : null;

      const payload = {
        title: postEditorContainer.querySelector('.post-title').value,
        body_html: postBodyEditor ? window.getQuillHTML(postBodyEditor) : postEditorContainer.querySelector('.post-body').value,
        hero_media_id: heroMediaId || null,
        hero_image_height: heroMediaId ? parseInt(postEditorContainer.querySelector('.post-hero-height').value) : null,
        hero_title_overlay: heroMediaId ? (postEditorContainer.querySelector('.post-hero-title-overlay')?.checked ? 1 : 0) : 1,
        hero_overlay_opacity: heroMediaId ? parseFloat(postEditorContainer.querySelector('.post-hero-overlay-opacity')?.value || 0.70) : 0.70,
        gallery_media_ids: galleryMediaIds
      };

      let postIdToPublish = editingId;

      if (editingId) {
        // Editing existing post: save draft first
        const draftSave = await api('/api/admin/posts-draft.php?id=' + editingId, {
          method: 'PUT',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload)
        });

        if (!draftSave.success) {
          alert('Error saving draft: ' + (draftSave.error || 'Unknown error'));
          saveBtn.disabled = false;
          saveBtn.innerHTML = originalHTML;
          return;
        }
      } else {
        // New post: create as draft first
        saveBtn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Creating...';
        const createDraft = await api('/api/admin/posts.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ ...payload, status: 'draft' })
        });
        if (!createDraft.success || !createDraft.id) {
          alert('Error creating draft: ' + (createDraft.error || 'Unknown error'));
          saveBtn.disabled = false;
          saveBtn.innerHTML = originalHTML;
          return;
        }
        postIdToPublish = createDraft.id;
        editingId = postIdToPublish;
      }

      // Show email confirmation modal
      saveBtn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Confirming...';
      const result = await window.publishConfirmation.confirmAndSendEmail(async () => {
        // Publish WITH email (no skip_email flag)
        const publishResult = await api('/api/admin/posts.php?action=publish&id=' + postIdToPublish, { method: 'GET' });
        window.handlePublishResult(publishResult);
      }, postIdToPublish);

      if (result && result.action === 'publish-only') {
        // User chose to publish without email
        const publishResult = await api('/api/admin/posts.php?action=publish&id=' + postIdToPublish + '&skip_email=1', { method: 'GET' });
        if (!publishResult.success) {
          throw new Error(publishResult.error || 'Unknown error');
        }
        showNotification('Post published successfully (email not sent).', 'success');
        loadPosts();
        const modalEl = bootstrap.Modal.getInstance(postEditorModal);
        if (modalEl) modalEl.hide();
      } else if (result && result.proceeded) {
        // User chose to send email
        loadPosts();
        const modalEl = bootstrap.Modal.getInstance(postEditorModal);
        if (modalEl) modalEl.hide();
      } else {
        // User cancelled - keep as draft
        saveBtn.disabled = false;
        saveBtn.innerHTML = originalHTML;
        showNotification('Draft saved (not published).', 'info');
      }
    } catch (error) {
      console.error('Error publishing post with email:', error);
      // Don't show alert if error was already handled with actionable toast
      if (error.message !== '__handled__') {
        alert('An error occurred while publishing the post');
      }
    } finally {
      saveBtn.disabled = false;
      saveBtn.innerHTML = originalHTML;
    }
  });

  // Resend Email button handler (for already-published posts)
  postEditorContainer.querySelector('.btn-resend-email').addEventListener('click', async function () {
    const saveBtn = this;
    const originalHTML = saveBtn.innerHTML;

    try {
      saveBtn.disabled = true;

      if (!editingId) {
        alert('Cannot resend email: no post selected');
        saveBtn.disabled = false;
        return;
      }

      // Show email confirmation modal
      saveBtn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Confirming...';
      const result = await window.publishConfirmation.confirmAndSendEmail(async () => {
        // Resend email notification for already-published post
        const resendResult = await api('/api/admin/posts.php?action=resend-email&id=' + editingId, { method: 'GET' });
        if (!resendResult.success) {
          throw new Error(resendResult.error || 'Unknown error');
        }
        // Show result - handle both queued and immediate responses
        if (resendResult.queued) {
          showNotification(resendResult.message || 'Email notification queued and will be sent shortly.', 'success');
        } else if (resendResult.email) {
          if (resendResult.email.sent && resendResult.email.count > 0) {
            showNotification(`Email sent to ${resendResult.email.count} subscriber(s).`, 'success');
          } else if (resendResult.email.sent === false) {
            showEmailError(resendResult.email);
          } else {
            showNotification('Email notification sent.', 'success');
          }
        } else {
          showNotification('Email notification sent.', 'success');
        }
      }, editingId);

      if (result && (result.action === 'email' || result.action === 'publish-only')) {
        // Close the modal after successful action (for resend, publish-only doesn't apply but we'll handle it gracefully)
        if (result.action === 'email' || result.proceeded) {
          const modalEl = bootstrap.Modal.getInstance(postEditorModal);
          if (modalEl) modalEl.hide();
        }
      } else {
        // User cancelled
        showNotification('Email not sent.', 'info');
      }
    } catch (error) {
      console.error('Error resending email:', error);
      alert('An error occurred while sending the email');
    } finally {
      saveBtn.disabled = false;
      saveBtn.innerHTML = originalHTML;
    }
  });

  // Save Draft button handler (save draft and close without publishing/email)
  // Use shared handler from post-draft-handler.js
  if (typeof window.setupSaveDraftHandler === 'function') {
    window.setupSaveDraftHandler({
      modal: postEditorModal,
      postEditorContainer: postEditorContainer,
      getPostBodyEditor: () => postBodyEditor,
      getEditingId: () => editingId,
      setEditingId: (id) => { editingId = id; },
      getGalleryMediaIds: () => galleryMediaIds,
      refreshPostsList: loadPosts,
      api: api
    });
  }

  // Media
  const uploadForm = document.getElementById('uploadForm');
  const mediaGrid = document.getElementById('mediaGrid');

  // Media crop manager for admin media tab
  (function initAdminMediaCropper() {
    if (typeof window.ImageCropManager === 'undefined') return;

    const fileInput = document.getElementById('mediaFile');
    const altInput = document.getElementById('mediaAlt');
    const cropContainer = document.getElementById('mediaCropContainer');
    const cropImage = document.getElementById('mediaCropImage');
    const autoDetectBtn = document.getElementById('mediaAutoDetect');
    const rotateLeftBtn = document.getElementById('mediaRotateLeft');
    const rotateRightBtn = document.getElementById('mediaRotateRight');
    const uploadBtn = document.getElementById('mediaUploadBtn');
    const cancelBtn = document.getElementById('mediaCancelBtn');
    const submitBtn = document.getElementById('mediaSubmitBtn');

    if (!fileInput || !cropContainer || !cropImage || !uploadBtn || !cancelBtn) return;

    const mediaCropManager = new window.ImageCropManager({
      fileInput: fileInput,
      cropContainer: cropContainer,
      cropImage: cropImage,
      uploadButton: uploadBtn,
      cancelButton: cancelBtn,
      autoDetectButton: autoDetectBtn,
      rotateLeftButton: rotateLeftBtn,
      rotateRightButton: rotateRightBtn,
      onCropInit: () => {
        if (submitBtn) { submitBtn.disabled = true; submitBtn.classList.add('disabled'); }
      },
      onCropCancel: () => {
        if (submitBtn) { submitBtn.disabled = false; submitBtn.classList.remove('disabled'); }
      },
      uploadCallback: async (file, cropData) => {
        const fd = new FormData();
        fd.append('file', file);
        fd.append('alt_text', altInput?.value || '');
        if (cropData) fd.append('crop', JSON.stringify(cropData));

        const resp = await fetch('/api/admin/media.php', {
          method: 'POST',
          headers: { 'X-CSRF-Token': CSRF },
          body: fd
        });
        const j = await resp.json();
        if (!j.success) throw new Error(j.error || 'Upload failed');

        // Reset form and reload media grid
        if (uploadForm) uploadForm.reset();
        if (submitBtn) { submitBtn.disabled = false; submitBtn.classList.remove('disabled'); }
        loadMedia();
        return j;
      }
    });

    // Prevent default submit if crop UI is active; allow fallback direct upload otherwise
    if (uploadForm) {
      uploadForm.addEventListener('submit', function (e) {
        e.preventDefault();
        const f = fileInput.files[0];
        if (!f) return alert('Choose a file');

        // If crop UI is visible, let the dedicated Upload & Apply button handle the upload
        if (cropContainer && cropContainer.style.display !== 'none') {
          return; // No-op; user should click Upload & Apply
        }

        // Fallback: direct upload without cropping
        if (f.size > 20 * 1024 * 1024) return alert('Max 20MB');
        const fd = new FormData();
        fd.append('file', f);
        fd.append('alt_text', altInput?.value || '');
        fetch('/api/admin/media.php', { method: 'POST', headers: { 'X-CSRF-Token': CSRF }, body: fd })
          .then(r => r.json()).then(j => {
            if (j.success) { loadMedia(); uploadForm.reset(); }
            else alert(j.error || 'Upload failed');
          });
      });
    }
  })();

  function loadMedia() {
    fetch('/api/admin/media.php').then(r => r.json()).then(j => {
      mediaGrid.innerHTML = '';
      (j.data || []).forEach(m => {
        const col = document.createElement('div'); col.className = 'col';
        const card = document.createElement('div'); card.className = 'card position-relative media-card';
        const img = document.createElement('img'); img.className = 'card-img-top';
        try {
          const vars = JSON.parse(m.variants_json || '{}');
          const variant400 = vars['400'];
          if (variant400 && variant400.jpg) {
            img.src = '/storage/uploads/variants/400/' + variant400.jpg.split('/').pop();
          } else {
            img.src = '/storage/uploads/originals/' + m.filename;
          }
        } catch (e) {
          img.src = '/storage/uploads/originals/' + m.filename;
        }
        const body = document.createElement('div'); body.className = 'card-body';
        const filenameLabel = document.createElement('small');
        filenameLabel.textContent = m.original_filename;
        body.appendChild(filenameLabel);

        // Add trash icon button (hidden by default, shows on hover)
        const deleteBtn = document.createElement('button');
        deleteBtn.className = 'btn btn-danger btn-sm position-absolute top-0 end-0 m-2 media-delete-btn';
        deleteBtn.innerHTML = '<i class="bi bi-trash"></i>';
        deleteBtn.style.opacity = '0';
        deleteBtn.style.transition = 'opacity 0.2s';
        deleteBtn.dataset.mediaId = m.id;
        deleteBtn.dataset.mediaFilename = m.original_filename;
        deleteBtn.title = 'Delete image';

        // Show/hide delete button on hover
        card.addEventListener('mouseenter', function () {
          deleteBtn.style.opacity = '1';
        });
        card.addEventListener('mouseleave', function () {
          deleteBtn.style.opacity = '0';
        });

        // Handle delete click
        deleteBtn.addEventListener('click', function (e) {
          e.preventDefault();
          e.stopPropagation();
          handleMediaDelete(m.id, m.original_filename);
        });

        card.appendChild(img);
        card.appendChild(deleteBtn);
        card.appendChild(body);
        col.appendChild(card);
        mediaGrid.appendChild(col);
      });

      // Also populate hero media dropdown in post editor
      populateHeroMediaDropdown(j.data || []);

      // Also populate hero banner media dropdown
      populateHeroBannerMediaDropdown(j.data || []);
    });
  }

  function populateHeroBannerMediaDropdown(mediaList) {
    const heroSelect = document.getElementById('hero_media_id');
    if (!heroSelect) return;

    // Clear existing options except the first one (None)
    while (heroSelect.options.length > 1) {
      heroSelect.remove(1);
    }

    // Deduplicate by filename - keep only the largest version of each unique filename
    const uniqueMedia = {};
    mediaList.forEach(m => {
      const filename = m.original_filename;
      if (!uniqueMedia[filename] || (m.width * m.height) > (uniqueMedia[filename].width * uniqueMedia[filename].height)) {
        uniqueMedia[filename] = m;
      }
    });

    // Add media options (sorted by ID descending - newest first)
    Object.values(uniqueMedia)
      .sort((a, b) => b.id - a.id)
      .forEach(m => {
        const option = document.createElement('option');
        option.value = m.id;
        option.textContent = m.original_filename;
        heroSelect.appendChild(option);
      });
  }

  function populateHeroMediaDropdown(mediaList) {
    const heroSelect = postEditorContainer.querySelector('.post-hero-media');
    if (!heroSelect) return;

    // Clear existing options except the first one
    while (heroSelect.options.length > 1) {
      heroSelect.remove(1);
    }

    // Add ALL media options (sorted by ID descending - newest first)
    // Important: Do not deduplicate by filename, as posts may reference older
    // images that share a filename and would otherwise be missing from the list.
    mediaList
      .slice()
      .sort((a, b) => b.id - a.id)
      .forEach(m => {
        const option = document.createElement('option');
        option.value = String(m.id);
        option.textContent = m.original_filename;
        heroSelect.appendChild(option);
      });
  }

  // Handle media deletion with confirmation
  let pendingDeleteMediaId = null;
  let pendingDeleteHasUsage = false;

  async function handleMediaDelete(mediaId, filename) {
    try {
      // First, check which posts use this media
      const response = await fetch(`/api/admin/media.php?check_usage=${mediaId}`);
      const data = await response.json();

      if (!data.success) {
        console.error('Error checking media usage');
        return;
      }

      const affectedPosts = data.data || [];

      // Store the media ID for deletion
      pendingDeleteMediaId = mediaId;

      // Populate modal
      const filenameEl = document.getElementById('mediaDeleteFilename');
      const warningEl = document.getElementById('mediaDeleteWarning');
      const noUsageEl = document.getElementById('mediaDeleteNoUsage');
      const affectedListEl = document.getElementById('mediaDeleteAffectedList');
      const confirmBtn = document.getElementById('confirmMediaDelete');

      filenameEl.textContent = `Delete "${filename}"?`;
      affectedListEl.innerHTML = '';

      pendingDeleteHasUsage = affectedPosts.length > 0;

      if (pendingDeleteHasUsage) {
        warningEl.classList.remove('d-none');
        noUsageEl.classList.add('d-none');

        affectedPosts.forEach(post => {
          const li = document.createElement('li');
          const link = document.createElement('a');
          link.href = '#';
          link.textContent = `${post.title} (${post.usage})`;
          link.classList.add('media-usage-link');
          link.dataset.postId = post.id;
          li.appendChild(link);
          affectedListEl.appendChild(li);
        });
      } else {
        warningEl.classList.add('d-none');
        noUsageEl.classList.remove('d-none');
      }

      if (confirmBtn) {
        confirmBtn.disabled = pendingDeleteHasUsage;
        confirmBtn.textContent = pendingDeleteHasUsage ? 'Cannot delete while in use' : 'Delete Image';
      }

      // Show modal
      const modal = new bootstrap.Modal(document.getElementById('mediaDeleteModal'));
      modal.show();
    } catch (error) {
      console.error('Error checking media usage:', error);
    }
  }

  // Handle confirm delete button in modal
  document.getElementById('confirmMediaDelete').addEventListener('click', async function () {
    if (!pendingDeleteMediaId) return;

    if (pendingDeleteHasUsage) {
      showNotification('This image is still used in posts/settings. Remove those references before deleting.', 'warning');
      return;
    }

    try {
      const deleteResponse = await api(`/api/admin/media.php?id=${pendingDeleteMediaId}`, {
        method: 'DELETE'
      });

      if (deleteResponse.success) {
        bootstrap.Modal.getInstance(document.getElementById('mediaDeleteModal'))?.hide();
        loadMedia();
      } else {
        showNotification(deleteResponse.error || 'Delete failed', 'danger');
      }
    } catch (error) {
      console.error('Error deleting media:', error);
      showNotification('Delete failed. Please check logs.', 'danger');
    } finally {
      pendingDeleteMediaId = null;
      pendingDeleteHasUsage = false;
    }
  });

  // Initialize
  loadDashboard();
  loadSettings();
  loadPosts();
  loadMedia();

  // Allow jumping into post editor from media-usage links
  document.addEventListener('click', function (e) {
    const usageLink = e.target.closest('.media-usage-link');
    if (!usageLink) return;
    e.preventDefault();

    const postId = parseInt(usageLink.dataset.postId || '0', 10);
    if (!postId) return;

    editingId = postId;
    const postModal = document.getElementById('postEditorModal');
    if (postModal) {
      const modalInstance = bootstrap.Modal.getOrCreateInstance(postModal);
      modalInstance.show();
    } else {
      showNotification('Post editor modal is not available.', 'warning');
    }
  });

  // Hide Draft Previews toggle functionality
  const hideDraftPreviewsToggle = document.getElementById('hideDraftPreviews');

  if (hideDraftPreviewsToggle) {
    // Restore saved preference using shared utility
    hideDraftPreviewsToggle.checked = window.shouldHideDrafts();

    // Handle toggle changes
    hideDraftPreviewsToggle.addEventListener('change', function () {
      const hide = this.checked;
      window.setHideDrafts(hide);

      // Update visibility of draft posts on the home page if visible
      document.querySelectorAll('.timeline-item-draft').forEach(el => {
        if (hide) {
          el.classList.add('draft-hidden');
        } else {
          el.classList.remove('draft-hidden');
        }
      });

      showNotification(hide ? 'Draft previews will be hidden in the timeline.' : 'Draft previews will be shown in the timeline.', 'info');
    });
  }

  // Restore last active tab from sessionStorage or default to Posts
  const lastActiveTab = sessionStorage.getItem('adminActiveTab') || '#pane-posts';
  const tabButton = document.querySelector(`button[data-bs-target="${lastActiveTab}"]`);
  if (tabButton) {
    const tab = new bootstrap.Tab(tabButton);
    tab.show();
  }

  // Restore Newsletter subtab if coming from external navigation (e.g., homepage toast button)
  const newsletterSubtab = sessionStorage.getItem('adminNewsletterSubtab');
  if (newsletterSubtab) {
    // Clear the flag so it doesn't persist on subsequent visits
    sessionStorage.removeItem('adminNewsletterSubtab');

    // Wait for main tab to render, then activate subtab
    setTimeout(() => {
      if (newsletterSubtab === 'email-settings') {
        const emailSettingsTab = document.getElementById('subtab-email-settings');
        if (emailSettingsTab) {
          emailSettingsTab.click();
          // Scroll to SMTP host field
          setTimeout(() => {
            const smtpHost = document.getElementById('smtp_host');
            if (smtpHost) {
              smtpHost.scrollIntoView({ behavior: 'smooth', block: 'center' });
              smtpHost.focus();
            }
          }, 300);
        }
      }
    }, 300);
  }

  // Reveal the tab content now that the correct tab is active
  const tabContent = document.getElementById('adminTabContent');
  if (tabContent) {
    tabContent.style.visibility = 'visible';
  }

  // Save active tab to sessionStorage when changed
  const tabButtons = document.querySelectorAll('#adminTabs button[data-bs-toggle="tab"]');
  tabButtons.forEach(button => {
    button.addEventListener('shown.bs.tab', function (e) {
      const target = e.target.getAttribute('data-bs-target');
      sessionStorage.setItem('adminActiveTab', target);
    });
  });

  // Initialize Quill editor instances for hero and bio
  // Wait a bit for the DOM to be ready and tabs to be rendered
  setTimeout(() => {
    // Hero HTML editor
    const heroTextarea = document.getElementById('hero_html');
    if (heroTextarea) {
      heroEditor = window.initQuillEditor(heroTextarea, {
        placeholder: 'Enter hero banner text...',
        toolbar: [
          [{ 'header': [1, 2, 3, false] }],
          ['bold', 'italic', 'underline', 'link'],
          [{ 'list': 'ordered' }, { 'list': 'bullet' }],
          [{ 'align': [] }],
          ['blockquote', 'image'],
          ['clean']
        ]
      });

      // Mirror hero content into the admin preview as the editor changes
      if (heroEditor && typeof heroEditor.on === 'function') {
        heroEditor.on('text-change', () => {
          updateHeroTextPreview(window.getQuillHTML(heroEditor));
        });
      }

      // Set up auto-save
      heroAutoSave = setupSettingsAutoSave(heroEditor, 'hero_html');

      // Reload settings to populate editor
      loadSettings();
    }

    // Site Bio editor
    const bioTextarea = document.getElementById('site_bio_html');
    if (bioTextarea) {
      bioEditor = window.initQuillEditor(bioTextarea, {
        placeholder: 'Enter about section content...',
        toolbar: [
          [{ 'header': [1, 2, 3, false] }],
          ['bold', 'italic', 'underline', 'link'],
          [{ 'list': 'ordered' }, { 'list': 'bullet' }],
          [{ 'align': [] }],
          ['blockquote', 'image'],
          ['clean']
        ]
      });

      // Set up auto-save
      bioAutoSave = setupSettingsAutoSave(bioEditor, 'site_bio_html');

      // Reload settings to populate editor
      loadSettings();
    }

    // Donate editor
    const donateTextarea = document.getElementById('donate_text_html');
    if (donateTextarea) {
      donateEditor = window.initQuillEditor(donateTextarea, {
        placeholder: 'Enter donation section content...',
        toolbar: [
          [{ 'header': [1, 2, 3, false] }],
          ['bold', 'italic', 'underline', 'link'],
          [{ 'list': 'ordered' }, { 'list': 'bullet' }],
          [{ 'align': [] }],
          ['blockquote', 'image'],
          ['clean']
        ]
      });

      // Set up auto-save
      donateAutoSave = setupSettingsAutoSave(donateEditor, 'donate_text_html');

      // Reload settings to populate editor
      loadSettings();
    }

    // Donation Instructions editor (admin page)
    const donationInstructionsContainer = document.getElementById('donation_instructions_html');
    if (donationInstructionsContainer) {
      donationInstructionsEditor = window.initQuillEditor(donationInstructionsContainer, {
        placeholder: 'Add any instructions for donors (e.g., preferred apps, notes, etc.)...',
        toolbar: [
          [{ 'header': [1, 2, 3, false] }],
          ['bold', 'italic', 'underline', 'link'],
          [{ 'list': 'ordered' }, { 'list': 'bullet' }],
          [{ 'align': [] }],
          ['blockquote', 'image'],
          ['clean']
        ]
      });

      // Set up auto-save
      donationInstructionsAutoSave = setupSettingsAutoSave(donationInstructionsEditor, 'donation_instructions_html');

      // Live preview update on instructions change
      donationInstructionsEditor.on('text-change', () => {
        updateAdminDonationPreview();
      });

      // Reload settings to populate editor
      loadSettings();
    }

    // Footer Column 1 editor
    const footerCol1Container = document.getElementById('footer_column1_html');
    if (footerCol1Container) {
      footerCol1Editor = window.initQuillEditor(footerCol1Container, {
        placeholder: 'Enter footer column 1 content...',
        toolbar: [
          [{ 'header': [1, 2, 3, false] }],
          ['bold', 'italic', 'underline', 'link'],
          [{ 'list': 'ordered' }, { 'list': 'bullet' }],
          [{ 'align': [] }],
          ['blockquote', 'image'],
          ['clean']
        ]
      });

      // Set up auto-save
      footerCol1AutoSave = setupSettingsAutoSave(footerCol1Editor, 'footer_column1_html');

      // Update text preview when content changes
      footerCol1Editor.on('text-change', () => {
        updateFooterTextPreview();
      });
    }

    // Footer Column 2 editor
    const footerCol2Container = document.getElementById('footer_column2_html');
    if (footerCol2Container) {
      footerCol2Editor = window.initQuillEditor(footerCol2Container, {
        placeholder: 'Enter footer column 2 content...',
        toolbar: [
          [{ 'header': [1, 2, 3, false] }],
          ['bold', 'italic', 'underline', 'link'],
          [{ 'list': 'ordered' }, { 'list': 'bullet' }],
          [{ 'align': [] }],
          ['blockquote', 'image'],
          ['clean']
        ]
      });

      // Set up auto-save
      footerCol2AutoSave = setupSettingsAutoSave(footerCol2Editor, 'footer_column2_html');

      // Update text preview when content changes
      footerCol2Editor.on('text-change', () => {
        updateFooterTextPreview();
      });
    }

    // Initialize footer BackgroundPreviewManager
    if (window.BackgroundPreviewManager) {
      window.adminFooterPreviewManager = new window.BackgroundPreviewManager('footer');
      window.adminFooterPreviewManager.init();
    }

    // Footer layout radio buttons
    const footerLayoutSingle = document.getElementById('footer_layout_single');
    const footerLayoutDouble = document.getElementById('footer_layout_double');
    if (footerLayoutSingle) {
      footerLayoutSingle.addEventListener('change', () => {
        updateFooterColumn2Visibility();
        updateFooterTextPreview();
      });
    }
    if (footerLayoutDouble) {
      footerLayoutDouble.addEventListener('change', () => {
        updateFooterColumn2Visibility();
        updateFooterTextPreview();
      });
    }

    // Load media options for footer media selector
    const footerMediaSelect = document.getElementById('footer_media_id');
    if (footerMediaSelect && typeof SettingsManager !== 'undefined') {
      SettingsManager.loadMediaOptions(footerMediaSelect);
    }

    // Reload settings to populate footer editors
    if (footerCol1Container || footerCol2Container) {
      loadSettings();
    }
    const qrMediaSelect = document.getElementById('donation_qr_media_id');
    let mediaOptionsLoaded = Promise.resolve(); // Default resolved promise

    if (qrMediaSelect && typeof SettingsManager !== 'undefined') {
      mediaOptionsLoaded = SettingsManager.loadMediaOptions(qrMediaSelect);
      // Update preview when selection changes
      qrMediaSelect.addEventListener('change', () => {
        updateAdminDonationPreview();
      });
    }

    // Make mediaOptionsLoaded available to loadSettings
    window.donationMediaOptionsLoaded = mediaOptionsLoaded;

    // Setup donation method radio listeners for admin page
    const methodRadios = document.querySelectorAll('input[name="donation_method"]');
    methodRadios.forEach(radio => {
      radio.addEventListener('change', () => {
        updateDonationMethodVisibility('');
        updateAdminDonationPreview();
      });
    });

    // Initialize visibility based on current selection
    updateDonationMethodVisibility('');

    // Link input live preview
    const donationLinkInput = document.getElementById('donation_link');
    if (donationLinkInput) {
      donationLinkInput.addEventListener('input', () => {
        updateAdminDonationPreview();
      });
    }

    // Post body editor (initialize early to prevent modal height jumping)
    const postBodyTextarea = postEditorContainer.querySelector('.post-body');
    if (postBodyTextarea) {
      postBodyEditor = window.initQuillEditor(postBodyTextarea, {
        placeholder: 'Write your post content here...',
        toolbar: [
          [{ 'header': [1, 2, 3, false] }],
          ['bold', 'italic', 'underline', 'link'],
          [{ 'list': 'ordered' }, { 'list': 'bullet' }],
          [{ 'align': [] }],
          ['blockquote', 'image'],
          ['clean']
        ]
      });

      // Initialize AI title generator with editor instance
      if (typeof window.initAITitleGenerator === 'function') {
        const statusElement = document.getElementById('post-autosave-status');
        if (statusElement) {
          statusElement.dataset.forceEnabled = '1';
          statusElement.innerHTML = '<span class="text-muted">Auto-save enabled</span>';
          statusElement.className = 'editor-autosave-indicator';
        }
        window.initAITitleGenerator(postEditorContainer, postBodyEditor);
      }
    }

    // Setup modal event handlers
    // Ensure editingId is set from the trigger element before the modal actually shows
    postEditorModal.addEventListener('show.bs.modal', function (e) {
      const trigger = e.relatedTarget;
      if (trigger) {
        // Edit existing post button carries data-id
        const idAttr = trigger.getAttribute('data-id');
        if (idAttr) {
          editingId = parseInt(idAttr, 10) || null;
        } else if (trigger.id === 'btnNewPost') {
          editingId = null;
        }
      }

      // Immediately set button visibility based on whether we're editing or creating
      // For existing posts, hide "Publish" and show "Publish Changes" only when changes are detected
      // For new posts, show "Publish" and hide "Publish Changes"
      const publishEmailBtn = postEditorContainer.querySelector('.btn-publish-email');
      const publishChangesBtn = postEditorContainer.querySelector('.btn-publish-changes');

      if (editingId) {
        // Editing existing post - hide Publish, Publish Changes will be shown when changes are detected
        if (publishEmailBtn) publishEmailBtn.style.display = 'none';
        if (publishChangesBtn) publishChangesBtn.style.display = 'none';
      } else {
        // New post - show Publish, hide Publish Changes
        if (publishEmailBtn) publishEmailBtn.style.display = 'inline-block';
        if (publishChangesBtn) publishChangesBtn.style.display = 'none';
      }
    });

    postEditorModal.addEventListener('shown.bs.modal', function () {
      // Clear gallery preview first (for both new and edit)
      const galleryPreview = postEditorContainer.querySelector('#galleryPreview');
      if (galleryPreview) {
        galleryPreview.innerHTML = '';
        galleryPreview.classList.add('empty');
      }
      galleryMediaIds = [];

      // Reset auto-save indicator immediately for new posts
      // This prevents stale "Draft saved" messages from previous edits
      if (!editingId) {
        const statusElement = document.getElementById('post-autosave-status');
        if (statusElement) {
          statusElement.innerHTML = '<span class="text-muted">Save post to enable auto-save</span>';
          statusElement.className = 'editor-autosave-indicator';
        }
      }

      // Initialize post editor crop manager if not already initialized
      if (typeof window.initPostEditorCrop === 'function' && !postEditorContainer._cropManager) {
        postEditorContainer._cropManager = window.initPostEditorCrop(postEditorContainer, CSRF);
      }

      // Show Save button for both new and existing posts
      // Label changes based on context: "Save Draft" for new, "Save" for existing
      const saveDraftBtn = postEditorContainer.querySelector('.btn-save-draft');
      if (saveDraftBtn) {
        saveDraftBtn.style.display = 'inline-block';
        saveDraftBtn.textContent = editingId ? 'Save' : 'Save Draft';
      }

      // Status control is always hidden; Save Draft / Save and Publish determine intent

      // Setup hero image selection handler BEFORE loading post data
      const heroSelect = postEditorContainer.querySelector('.post-hero-media');
      const heroPreviewContainer = postEditorContainer.querySelector('.hero-preview-container');
      const heroPreview = postEditorContainer.querySelector('.hero-preview');
      const heroPreviewImg = heroPreview ? heroPreview.querySelector('img') : null;

      // Persist hero-related draft fields for existing posts
      const saveHeroDraft = async () => {
        if (!editingId) return; // Only auto-save for existing posts
        const heroHeightSlider = postEditorContainer.querySelector('.post-hero-height');
        const heroTitleOverlayToggle = postEditorContainer.querySelector('.post-hero-title-overlay');
        const heroOverlayOpacitySlider = postEditorContainer.querySelector('.post-hero-overlay-opacity');
        const payload = {
          hero_media_id: heroSelect?.value || null,
          hero_image_height: heroSelect?.value ? parseInt(heroHeightSlider?.value || 100) : null,
          hero_crop_overlay: heroSelect?.value ? (postEditorContainer.querySelector('.post-hero-crop-overlay')?.checked ? 1 : 0) : 0,
          hero_title_overlay: heroSelect?.value ? (heroTitleOverlayToggle?.checked ? 1 : 0) : 1,
          hero_overlay_opacity: heroSelect?.value ? parseFloat(heroOverlayOpacitySlider?.value || 0.70) : 0.70
        };
        try {
          await api('/api/admin/posts-draft.php?id=' + editingId, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
          });
        } catch (e) {
          console.warn('Auto-save hero draft failed:', e);
        }
      };

      if (heroSelect && !heroSelect.dataset.listenerAdded) {
        heroSelect.addEventListener('change', function () {
          const heroUploadControls = postEditorContainer.querySelector('.hero-upload-controls');
          const heroRemoveBtn = postEditorContainer.querySelector('.btn-remove-hero');

          if (this.value && heroPreviewImg) {
            // Load media info to show preview
            fetch(`/api/admin/media.php?id=${this.value}`)
              .then(r => r.json())
              .then(data => {
                if (data.success && data.data) {
                  const variants = JSON.parse(data.data.variants_json || '[]');
                  const variant400 = variants.find(v => v.width === 400);
                  let previewUrl;
                  if (variant400 && variant400.path) {
                    // Extract web-accessible path from full filesystem path
                    // Path format: /var/www/html/storage/uploads/... -> /storage/uploads/...
                    const pathMatch = variant400.path.match(/\/storage\/uploads\/.+$/);
                    previewUrl = pathMatch ? pathMatch[0] : variant400.path;
                  } else {
                    previewUrl = '/storage/uploads/originals/' + data.data.filename;
                  }

                  heroPreviewImg.src = previewUrl;
                  heroPreviewImg.alt = data.data.alt_text || '';
                  if (heroPreviewContainer) heroPreviewContainer.style.display = 'block';

                  // Hide upload controls and show remove button
                  if (heroUploadControls) heroUploadControls.style.display = 'none';
                  if (heroRemoveBtn) heroRemoveBtn.style.opacity = '1';

                  // Auto-save hero selection
                  saveHeroDraft();
                }
              })
              .catch(err => console.error('Error loading hero preview:', err));
          } else if (heroPreviewContainer) {
            heroPreviewContainer.style.display = 'none';
            // Auto-save cleared hero
            saveHeroDraft();
          }
        });
        heroSelect.dataset.listenerAdded = 'true';
      }

      // Setup hero remove button
      const heroRemoveBtn = postEditorContainer.querySelector('.btn-remove-hero');
      if (heroRemoveBtn && !heroRemoveBtn.dataset.listenerAdded) {
        heroRemoveBtn.addEventListener('click', function () {
          const heroUploadControls = postEditorContainer.querySelector('.hero-upload-controls');

          if (heroSelect) heroSelect.value = '';
          if (heroPreviewContainer) heroPreviewContainer.style.display = 'none';
          if (heroPreviewImg) heroPreviewImg.src = '';

          // Show upload controls and hide remove button
          if (heroUploadControls) heroUploadControls.style.display = 'block';
          this.style.opacity = '0';

          // Notify change so preview/save logic runs
          heroSelect?.dispatchEvent(new Event('change'));
        });
        heroRemoveBtn.dataset.listenerAdded = 'true';
      }

      // Setup hero height slider
      const heroHeightSlider = postEditorContainer.querySelector('.post-hero-height');
      const heroHeightValue = postEditorContainer.querySelector('.hero-height-value');
      if (heroHeightSlider && heroHeightValue && !heroHeightSlider.dataset.listenerAdded) {
        heroHeightSlider.addEventListener('input', function () {
          const heightPercent = parseInt(this.value);
          heroHeightValue.textContent = heightPercent;

          // Update preview padding-bottom to match actual display
          const heroPreviewDiv = postEditorContainer.querySelector('.hero-preview');
          if (heroPreviewDiv) {
            heroPreviewDiv.style.paddingBottom = heightPercent + '%';
          }
          // Auto-save hero height
          saveHeroDraft();
        });
        heroHeightSlider.dataset.listenerAdded = 'true';
      }

      // Setup title overlay preview update function
      const heroPreviewTitleOverlay = postEditorContainer.querySelector('.hero-preview-title-overlay');
      const heroTitleOverlayToggle = postEditorContainer.querySelector('.post-hero-title-overlay');
      const postTitleInput = postEditorContainer.querySelector('.post-title');
      const heroOverlayOpacitySlider = postEditorContainer.querySelector('.post-hero-overlay-opacity');
      const overlayOpacityValue = postEditorContainer.querySelector('.overlay-opacity-value');
      const heroOverlayOpacityControl = postEditorContainer.querySelector('.hero-overlay-opacity-control');

      const updateHeroPreview = () => {
        if (!heroPreviewImg || !heroPreviewTitleOverlay) return;

        const showTitleOverlay = heroTitleOverlayToggle?.checked ?? true;
        const opacity = parseFloat(heroOverlayOpacitySlider?.value || 0.70);
        const titleText = postTitleInput?.value || 'Post Title Preview';

        // Show/hide opacity control based on title overlay toggle
        if (heroOverlayOpacityControl) {
          heroOverlayOpacityControl.style.display = showTitleOverlay ? 'block' : 'none';
        }

        // Update image brightness
        if (showTitleOverlay) {
          heroPreviewImg.style.filter = `brightness(${opacity})`;
        } else {
          heroPreviewImg.style.filter = 'none';
        }

        // Update title overlay visibility and text
        if (showTitleOverlay) {
          heroPreviewTitleOverlay.style.display = 'block';
          const titleElement = heroPreviewTitleOverlay.querySelector('h5');
          if (titleElement) {
            titleElement.textContent = titleText || 'Post Title Preview';
          }
        } else {
          heroPreviewTitleOverlay.style.display = 'none';
        }
      };

      // Setup event listeners for title overlay controls
      if (heroTitleOverlayToggle && !heroTitleOverlayToggle.dataset.listenerAdded) {
        heroTitleOverlayToggle.addEventListener('change', function () {
          updateHeroPreview();
          saveHeroDraft();
        });
        heroTitleOverlayToggle.dataset.listenerAdded = 'true';
      }

      if (heroOverlayOpacitySlider && !heroOverlayOpacitySlider.dataset.listenerAdded) {
        heroOverlayOpacitySlider.addEventListener('input', function () {
          const opacity = parseFloat(this.value);
          if (overlayOpacityValue) {
            overlayOpacityValue.textContent = opacity.toFixed(2);
          }
          updateHeroPreview();
          saveHeroDraft();
        });
        heroOverlayOpacitySlider.dataset.listenerAdded = 'true';
      }

      if (postTitleInput && !postTitleInput.dataset.titleListenerAdded) {
        postTitleInput.addEventListener('input', updateHeroPreview);
        postTitleInput.dataset.titleListenerAdded = 'true';
      }

      // Function to load post data into editor
      const loadPostIntoEditor = () => {
        // Load post data if editing (editingId set in show.bs.modal)
        if (editingId) {
          fetch('/api/admin/posts.php?id=' + editingId).then(r => r.json()).then(j => {
            if (j.success && j.data) {
              const post = j.data;


              // Use draft content for editing (falls back to published if no draft)
              postEditorContainer.querySelector('.post-title').value = post.title_editing || '';

              // Set post body in editor (use draft content)
              if (postBodyEditor) {
                window.setQuillHTML(postBodyEditor, post.body_html_editing || '');

                // Set up auto-save for this post (saves to draft)
                if (postAutoSave) {
                  if (typeof postAutoSave.cleanup === 'function') {
                    postAutoSave.cleanup();
                  } else {
                    clearInterval(postAutoSave);
                  }
                  postAutoSave = null;
                }
                postAutoSave = setupPostAutoSave(postBodyEditor, editingId);
              } else {
                postEditorContainer.querySelector('.post-body').value = post.body_html_editing || '';
              }

              // Set hero image if exists (use draft)
              const heroMediaId = post.hero_media_id_editing;
              if (heroMediaId) {
                const heroSelect = postEditorContainer.querySelector('.post-hero-media');
                if (heroSelect) {
                  heroSelect.value = heroMediaId;
                  // Trigger change to show preview
                  heroSelect.dispatchEvent(new Event('change'));
                }
              } else {
                // Clear hero if no image
                const heroSelect = postEditorContainer.querySelector('.post-hero-media');
                const heroPreviewContainer = postEditorContainer.querySelector('.hero-preview-container');
                if (heroSelect) heroSelect.value = '';
                if (heroPreviewContainer) heroPreviewContainer.style.display = 'none';
              }

              // Set hero height if exists (use draft)
              const heroImageHeight = parseInt(post.hero_image_height_editing) || 100;
              const heroHeightSlider = postEditorContainer.querySelector('.post-hero-height');
              const heroHeightValue = postEditorContainer.querySelector('.hero-height-value');
              if (heroHeightSlider) {
                heroHeightSlider.value = heroImageHeight;
                if (heroHeightValue) heroHeightValue.textContent = heroImageHeight;

                // Update preview padding-bottom to match loaded height
                const heroPreviewDiv = postEditorContainer.querySelector('.hero-preview');
                if (heroPreviewDiv) {
                  heroPreviewDiv.style.paddingBottom = heroImageHeight + '%';
                }
              }

              // Set hero title overlay if exists (use draft)
              const heroTitleOverlay = post.hero_title_overlay_editing !== undefined && post.hero_title_overlay_editing !== null
                ? parseInt(post.hero_title_overlay_editing)
                : 1;
              const heroTitleOverlayToggle = postEditorContainer.querySelector('.post-hero-title-overlay');
              if (heroTitleOverlayToggle) {
                heroTitleOverlayToggle.checked = heroTitleOverlay == 1;
              }

              // Set hero overlay opacity if exists (use draft)
              const heroOverlayOpacity = parseFloat(post.hero_overlay_opacity_editing) || 0.70;
              const heroOverlayOpacitySlider = postEditorContainer.querySelector('.post-hero-overlay-opacity');
              const overlayOpacityValue = postEditorContainer.querySelector('.overlay-opacity-value');
              if (heroOverlayOpacitySlider) {
                heroOverlayOpacitySlider.value = heroOverlayOpacity;
                if (overlayOpacityValue) overlayOpacityValue.textContent = heroOverlayOpacity.toFixed(2);
              }

              // Update hero preview with loaded values (wait for image to load first)
              setTimeout(() => {
                const heroPreviewTitleOverlay = postEditorContainer.querySelector('.hero-preview-title-overlay');
                const heroPreviewImg = heroPreview ? heroPreview.querySelector('img') : null;
                const heroOverlayOpacityControl = postEditorContainer.querySelector('.hero-overlay-opacity-control');

                if (heroPreviewImg && heroPreviewTitleOverlay) {
                  const showTitleOverlay = heroTitleOverlayToggle?.checked ?? true;
                  const titleText = postEditorContainer.querySelector('.post-title')?.value || 'Post Title Preview';

                  // Show/hide opacity control based on title overlay toggle
                  if (heroOverlayOpacityControl) {
                    heroOverlayOpacityControl.style.display = showTitleOverlay ? 'block' : 'none';
                  }

                  // Update image brightness
                  if (showTitleOverlay) {
                    heroPreviewImg.style.filter = `brightness(${heroOverlayOpacity})`;
                  } else {
                    heroPreviewImg.style.filter = 'none';
                  }

                  // Update title overlay visibility and text
                  if (showTitleOverlay) {
                    heroPreviewTitleOverlay.style.display = 'block';
                    const titleElement = heroPreviewTitleOverlay.querySelector('h5');
                    if (titleElement) {
                      titleElement.textContent = titleText || 'Post Title Preview';
                    }
                  } else {
                    heroPreviewTitleOverlay.style.display = 'none';
                  }
                }
              }, 100);

              // Load gallery images if exists (prefer draft editing version)
              const galleryData = post.gallery_media_ids_editing || post.gallery_media_ids;
              if (galleryData) {
                try {
                  const galleryIds = JSON.parse(galleryData);
                  if (Array.isArray(galleryIds) && galleryIds.length > 0) {
                    galleryMediaIds = [...galleryIds];
                    // Load each gallery image (use addGalleryItemPreview to avoid duplicates)
                    for (const mediaId of galleryIds) {
                      fetch(`/api/admin/media.php?id=${mediaId}`)
                        .then(r => r.json())
                        .then(data => {
                          if (data.success && data.data) {
                            addGalleryItemPreview(mediaId, data.data);
                          }
                        });
                    }
                  }
                } catch (e) {
                  console.error('Error parsing gallery_media_ids:', e);
                }
              }

              // Update Save button label for existing posts
              const saveDraftBtn = postEditorContainer.querySelector('.btn-save-draft');
              if (saveDraftBtn) {
                saveDraftBtn.textContent = 'Save';
              }

              // Show/hide Unpublish button based on post status
              const unpublishBtn = postEditorContainer.querySelector('.btn-unpublish-post');
              if (unpublishBtn) {
                unpublishBtn.style.display = post.status === 'published' ? 'inline-block' : 'none';
              }

              // For already-published posts, hide "Publish" and show "Publish Changes" only when there are changes
              const publishEmailBtn = postEditorContainer.querySelector('.btn-publish-email');
              const publishChangesBtn = postEditorContainer.querySelector('.btn-publish-changes');
              const isAlreadyPublished = post.status === 'published';

              if (isAlreadyPublished) {
                // Hide "Publish" button for already-published posts
                if (publishEmailBtn) publishEmailBtn.style.display = 'none';
                // Hide "Publish Changes" initially - will show when changes are detected
                if (publishChangesBtn) publishChangesBtn.style.display = 'none';

                // Setup text-change listener to detect changes and show "Publish Changes" button
                if (postBodyEditor) {
                  const updatePublishChangesVisibility = () => {
                    const hasChanges = postAutoSave && typeof postAutoSave.hasChanges === 'function'
                      ? postAutoSave.hasChanges()
                      : false;
                    if (publishChangesBtn) {
                      publishChangesBtn.style.display = hasChanges ? 'inline-block' : 'none';
                    }
                  };

                  // Check for changes on text-change
                  postBodyEditor.on('text-change', updatePublishChangesVisibility);

                  // Also check when title changes
                  const titleInput = postEditorContainer.querySelector('.post-title');
                  if (titleInput && !titleInput.dataset.publishChangesListener) {
                    titleInput.addEventListener('input', updatePublishChangesVisibility);
                    titleInput.dataset.publishChangesListener = 'true';
                  }
                }
              } else {
                // For draft posts, show "Publish" button and hide "Publish Changes"
                if (publishEmailBtn) publishEmailBtn.style.display = 'inline-block';
                if (publishChangesBtn) publishChangesBtn.style.display = 'none';
              }

              // Show Resend Email button for already-published posts
              const resendEmailBtn = postEditorContainer.querySelector('.btn-resend-email');
              if (resendEmailBtn) {
                resendEmailBtn.style.display = post.status === 'published' ? 'inline-block' : 'none';
              }
            }
          });
        } else {
          // New post - clear the form
          postEditorContainer.querySelector('.post-title').value = '';

          // For new posts, show Save Draft button with appropriate label
          const saveDraftBtn = postEditorContainer.querySelector('.btn-save-draft');
          if (saveDraftBtn) {
            saveDraftBtn.style.display = '';
            saveDraftBtn.textContent = 'Save Draft';
          }

          // Hide Unpublish button for new posts
          const unpublishBtn = postEditorContainer.querySelector('.btn-unpublish-post');
          if (unpublishBtn) {
            unpublishBtn.style.display = 'none';
          }

          // Show Publish button for new posts
          const publishEmailBtn = postEditorContainer.querySelector('.btn-publish-email');
          if (publishEmailBtn) {
            publishEmailBtn.style.display = 'inline-block';
          }

          // Hide Publish Changes for new posts
          const publishChangesBtn = postEditorContainer.querySelector('.btn-publish-changes');
          if (publishChangesBtn) publishChangesBtn.style.display = 'none';

          // Hide Resend Email button for new posts
          const resendEmailBtn = postEditorContainer.querySelector('.btn-resend-email');
          if (resendEmailBtn) {
            resendEmailBtn.style.display = 'none';
          }
          if (postBodyEditor) {
            window.clearQuillEditor(postBodyEditor);
          } else {
            postEditorContainer.querySelector('.post-body').value = '';
          }
          postEditorContainer.querySelector('.post-hero-media').value = '';
          const clearHeroPreviewContainer = postEditorContainer.querySelector('.hero-preview-container');
          if (clearHeroPreviewContainer) clearHeroPreviewContainer.style.display = 'none';

          // Reset hero height slider to default
          const clearHeroHeightSlider = postEditorContainer.querySelector('.post-hero-height');
          const clearHeroHeightValue = postEditorContainer.querySelector('.hero-height-value');
          if (clearHeroHeightSlider) clearHeroHeightSlider.value = 100;
          if (clearHeroHeightValue) clearHeroHeightValue.textContent = '100';

          // Reset title overlay controls to defaults
          const clearHeroTitleOverlay = postEditorContainer.querySelector('.post-hero-title-overlay');
          if (clearHeroTitleOverlay) clearHeroTitleOverlay.checked = true;

          const clearHeroOverlayOpacity = postEditorContainer.querySelector('.post-hero-overlay-opacity');
          const clearOverlayOpacityValue = postEditorContainer.querySelector('.overlay-opacity-value');
          if (clearHeroOverlayOpacity) clearHeroOverlayOpacity.value = 0.70;
          if (clearOverlayOpacityValue) clearOverlayOpacityValue.textContent = '0.70';

          // Show upload controls and hide remove button for new posts
          const clearHeroUploadControls = postEditorContainer.querySelector('.hero-upload-controls');
          const clearHeroRemoveBtn = postEditorContainer.querySelector('.btn-remove-hero');
          if (clearHeroUploadControls) clearHeroUploadControls.style.display = 'block';
          if (clearHeroRemoveBtn) clearHeroRemoveBtn.style.opacity = '0';

          // Clear auto-save for new posts (they don't have an ID yet)
          if (postAutoSave) {
            if (typeof postAutoSave.cleanup === 'function') {
              postAutoSave.cleanup();
            } else {
              clearInterval(postAutoSave);
            }
            postAutoSave = null;
          }
          const statusElement = document.getElementById('post-autosave-status');
          if (statusElement) {
            statusElement.innerHTML = '<span class="text-muted">Save post to enable auto-save</span>';
            statusElement.className = 'editor-autosave-indicator';
          }
        }
      };

      // Call initial load
      loadPostIntoEditor();

      // Listen for refresh requests from cancellation handler
      const refreshListener = () => {
        // Check editingId at runtime (not captured in closure)
        if (editingId) {
          loadPostIntoEditor();
        }
      };
      document.addEventListener('post-editor:refresh-needed', refreshListener);

      // Clean up listener when modal closes
      postEditorModal.addEventListener('hidden.bs.modal', function cleanup() {
        document.removeEventListener('post-editor:refresh-needed', refreshListener);
        postEditorModal.removeEventListener('hidden.bs.modal', cleanup);
      }, { once: true });

    });

    // Cleanup editor when modal is hidden
    postEditorModal.addEventListener('hidden.bs.modal', function () {
      // Clean up any leftover backdrops
      document.querySelectorAll('.modal-backdrop').forEach(el => el.remove());
      // Reset body classes that Bootstrap might have added
      document.body.classList.remove('modal-open');
      document.body.style.overflow = '';
      document.body.style.paddingRight = '';

      // Reset editing state
      editingId = null;
      galleryMediaIds = [];

      // Clear post auto-save interval
      if (postAutoSave) {
        if (typeof postAutoSave.cleanup === 'function') {
          postAutoSave.cleanup();
        } else {
          // Fallback for old interval ID format
          clearInterval(postAutoSave);
        }
        postAutoSave = null;
      }

      // Reset autosave indicator for next new post
      const statusElement = document.getElementById('post-autosave-status');
      if (statusElement) {
        statusElement.innerHTML = '<span class="text-muted">Save post to enable auto-save</span>';
        statusElement.className = 'editor-autosave-indicator';
      }
    });

    // Hero image upload handlers - now handled by ImageCropManager
    // The crop manager is initialized in the modal shown event handler
    // Old manual upload handlers removed to avoid conflicts

    // Gallery upload handlers
    const galleryUploadInput = postEditorContainer.querySelector('.gallery-upload-input');
    const galleryUploadBtn = postEditorContainer.querySelector('.btn-upload-gallery');
    const galleryPreview = postEditorContainer.querySelector('#galleryPreview');

    // Show/hide upload button when files selected and update button text
    if (galleryUploadInput && galleryUploadBtn) {
      // Initially hide the button
      galleryUploadBtn.style.display = 'none';

      galleryUploadInput.addEventListener('change', function () {
        const fileCount = this.files.length;
        if (fileCount === 0) {
          galleryUploadBtn.style.display = 'none';
        } else {
          galleryUploadBtn.style.display = 'block';
          galleryUploadBtn.disabled = false;
          if (fileCount === 1) {
            galleryUploadBtn.textContent = 'Upload 1 image';
          } else {
            galleryUploadBtn.textContent = `Upload ${fileCount} images`;
          }
        }
      });
    }

    // Handle gallery upload
    if (galleryUploadBtn) {
      galleryUploadBtn.addEventListener('click', async function () {
        const files = Array.from(galleryUploadInput.files);
        if (!files.length) return;

        try {
          galleryUploadBtn.disabled = true;
          galleryUploadBtn.textContent = `Uploading ${files.length} file(s)...`;

          await uploadGalleryImages(files, addGalleryItem);

          // Clear file input and hide button
          galleryUploadInput.value = '';
          galleryUploadBtn.style.display = 'none';
        } catch (error) {
          alert('Error uploading gallery images: ' + error.message);
          // Re-enable button on error
          galleryUploadBtn.disabled = false;
        }
      });
    }

    // Add gallery item to preview
    function addGalleryItem(mediaId, mediaData) {
      if (!galleryPreview) return;

      galleryMediaIds.push(mediaId);
      galleryPreview.classList.remove('empty');

      const variants = JSON.parse(mediaData?.variants_json || '{}');
      const variant400 = variants['400'];
      const thumbUrl = variant400?.jpg
        ? '/storage/uploads/variants/400/' + variant400.jpg.split('/').pop()
        : '/storage/uploads/originals/' + mediaData?.filename;

      const item = document.createElement('div');
      item.className = 'gallery-item';
      item.draggable = true;
      item.dataset.mediaId = parseInt(mediaId, 10);

      const img = document.createElement('img');
      img.src = thumbUrl;
      img.alt = mediaData?.alt_text || '';
      item.appendChild(img);

      const removeBtn = document.createElement('button');
      removeBtn.type = 'button';
      removeBtn.className = 'btn btn-danger btn-sm btn-remove-gallery-item';
      removeBtn.textContent = '×';
      item.appendChild(removeBtn);

      // Remove handler
      removeBtn.addEventListener('click', function () {
        const idx = galleryMediaIds.indexOf(parseInt(mediaId));
        if (idx > -1) galleryMediaIds.splice(idx, 1);
        item.remove();
        if (!galleryPreview.children.length) {
          galleryPreview.classList.add('empty');
        }
      });

      // Drag and drop handlers
      item.addEventListener('dragstart', handleDragStart);
      item.addEventListener('dragover', handleDragOver);
      item.addEventListener('drop', handleDrop);
      item.addEventListener('dragend', handleDragEnd);

      galleryPreview.appendChild(item);
    }

    // Add gallery item preview (for existing images - doesn't add to array)
    function addGalleryItemPreview(mediaId, mediaData) {
      if (!galleryPreview) return;

      galleryPreview.classList.remove('empty');

      const variants = JSON.parse(mediaData?.variants_json || '{}');
      const variant400 = variants['400'];
      const thumbUrl = variant400?.jpg
        ? '/storage/uploads/variants/400/' + variant400.jpg.split('/').pop()
        : '/storage/uploads/originals/' + mediaData?.filename;

      const item = document.createElement('div');
      item.className = 'gallery-item';
      item.draggable = true;
      item.dataset.mediaId = parseInt(mediaId, 10);

      const img = document.createElement('img');
      img.src = thumbUrl;
      img.alt = mediaData?.alt_text || '';
      item.appendChild(img);

      const removeBtn = document.createElement('button');
      removeBtn.type = 'button';
      removeBtn.className = 'btn btn-danger btn-sm btn-remove-gallery-item';
      removeBtn.textContent = '×';
      item.appendChild(removeBtn);

      // Remove handler
      removeBtn.addEventListener('click', function () {
        const idx = galleryMediaIds.indexOf(parseInt(mediaId));
        if (idx > -1) galleryMediaIds.splice(idx, 1);
        item.remove();
        if (!galleryPreview.children.length) {
          galleryPreview.classList.add('empty');
        }
      });

      // Drag and drop handlers
      item.addEventListener('dragstart', handleDragStart);
      item.addEventListener('dragover', handleDragOver);
      item.addEventListener('drop', handleDrop);
      item.addEventListener('dragend', handleDragEnd);

      galleryPreview.appendChild(item);
    }

    // Drag and drop reordering
    let draggedElement = null;

    function handleDragStart(e) {
      draggedElement = this;
      this.classList.add('dragging');
      e.dataTransfer.effectAllowed = 'move';
    }

    function handleDragOver(e) {
      if (e.preventDefault) e.preventDefault();
      e.dataTransfer.dropEffect = 'move';
      return false;
    }

    function handleDrop(e) {
      if (e.stopPropagation) e.stopPropagation();
      if (draggedElement !== this) {
        // Swap positions
        const allItems = Array.from(galleryPreview.querySelectorAll('.gallery-item'));
        const draggedIdx = allItems.indexOf(draggedElement);
        const targetIdx = allItems.indexOf(this);

        if (draggedIdx < targetIdx) {
          this.parentNode.insertBefore(draggedElement, this.nextSibling);
        } else {
          this.parentNode.insertBefore(draggedElement, this);
        }

        // Update galleryMediaIds order
        const draggedId = parseInt(draggedElement.dataset.mediaId);
        const targetId = parseInt(this.dataset.mediaId);
        const draggedIdIdx = galleryMediaIds.indexOf(draggedId);
        const targetIdIdx = galleryMediaIds.indexOf(targetId);

        galleryMediaIds.splice(draggedIdIdx, 1);
        galleryMediaIds.splice(targetIdIdx, 0, draggedId);
      }
      return false;
    }

    function handleDragEnd(e) {
      this.classList.remove('dragging');
      draggedElement = null;
    }

    // Hero Banner image selection and preview
    const heroBannerSelect = document.getElementById('hero_media_id');
    const heroBannerPreview = document.querySelector('.hero-banner-preview');
    const heroBannerPreviewImg = heroBannerPreview ? heroBannerPreview.querySelector('img') : null;
    const heroBannerOverlay = document.querySelector('.hero-banner-overlay');
    const heroBannerRemoveBtn = document.querySelector('.btn-remove-hero-banner');
    const heroTextPreview = document.querySelector('.hero-preview-content');
    const heroHeightSlider = document.getElementById('hero_height');
    const heroHeightValueEl = document.querySelector('.hero-banner-height-value');
    const heroOpacitySlider = document.getElementById('hero_overlay_opacity');
    const heroOpacityValueEl = document.querySelector('.hero-overlay-opacity-value');
    const heroColorPicker = document.getElementById('hero_overlay_color');
    const heroColorHex = document.getElementById('hero_overlay_color_hex');

    // Keep preview aspect ratio in sync with slider (matches homepage padding-bottom%)
    function updateHeroHeightPreview(val) {
      if (heroHeightValueEl) heroHeightValueEl.textContent = val;
      if (heroBannerPreview) heroBannerPreview.style.paddingBottom = val + '%';
    }

    function updateHeroOverlayOpacity(val) {
      const num = parseFloat(val);
      if (heroOpacityValueEl) heroOpacityValueEl.textContent = num.toFixed(2);
      if (heroBannerOverlay) heroBannerOverlay.style.opacity = num;
    }

    function updateHeroOverlayColor(val) {
      if (heroBannerOverlay) heroBannerOverlay.style.backgroundColor = val;
      if (heroColorHex && heroColorHex !== document.activeElement) heroColorHex.value = val;
      if (heroColorPicker && heroColorPicker !== document.activeElement) heroColorPicker.value = val;
    }

    if (heroHeightSlider) {
      heroHeightSlider.addEventListener('input', function () {
        updateHeroHeightPreview(this.value);
      });
      heroHeightSlider.addEventListener('change', function () {
        updateHeroHeightPreview(this.value);
      });
    }


      function updateHeroTextPreview(html) {
        if (!heroTextPreview) return;
        heroTextPreview.innerHTML = html && html.trim() ? html : 'Hero text will appear here...';
      }
    if (heroOpacitySlider) {
      heroOpacitySlider.addEventListener('input', function () {
        updateHeroOverlayOpacity(this.value);
      });
      heroOpacitySlider.addEventListener('change', function () {
        updateHeroOverlayOpacity(this.value);
      });
    }

    if (heroColorPicker) {
      heroColorPicker.addEventListener('input', function () {
        updateHeroOverlayColor(this.value);
      });
    }

    if (heroColorHex) {
      heroColorHex.addEventListener('input', function () {
        const isValid = /^#[0-9A-Fa-f]{6}$/.test(this.value);
        this.classList.toggle('is-invalid', !isValid && this.value.length > 0);
        if (isValid) {
          updateHeroOverlayColor(this.value);
        }
      });
    }

    if (heroBannerSelect && heroBannerPreview) {
      heroBannerSelect.addEventListener('change', function () {
        if (this.value && heroBannerPreviewImg) {
          // Load media info to show preview
          fetch(`/api/admin/media.php?id=${this.value}`)
            .then(r => r.json())
            .then(data => {
              if (data.success && data.data) {
                const variants = JSON.parse(data.data.variants_json || '{}');
                const variant400 = variants['400'];
                let previewUrl;
                if (variant400 && variant400.jpg) {
                  previewUrl = '/storage/uploads/variants/400/' + variant400.jpg.split('/').pop();
                } else {
                  previewUrl = '/storage/uploads/originals/' + data.data.filename;
                }
                heroBannerPreviewImg.src = previewUrl;
                heroBannerPreviewImg.alt = data.data.alt_text || '';
                heroBannerPreview.style.display = 'block';
              }
            });
        } else if (heroBannerPreview) {
          heroBannerPreview.style.display = 'none';
        }
      });
    }

    // Keep hero text preview in sync with editor (if present)
    if (heroEditor && typeof heroEditor.on === 'function') {
      heroEditor.on('text-change', () => {
        updateHeroTextPreview(window.getQuillHTML(heroEditor));
      });
    }

    if (heroBannerRemoveBtn) {
      heroBannerRemoveBtn.addEventListener('click', function () {
        if (heroBannerSelect) heroBannerSelect.value = '';
        if (heroBannerPreview) heroBannerPreview.style.display = 'none';
        if (heroBannerPreviewImg) heroBannerPreviewImg.src = '';
      });
    }
  }, 500);

  // Clean up auto-save intervals when page unloads
  window.addEventListener('beforeunload', function () {
    if (heroAutoSave) clearInterval(heroAutoSave);
    if (bioAutoSave) clearInterval(bioAutoSave);
    if (donateAutoSave) clearInterval(donateAutoSave);
    if (footerCol1AutoSave) clearInterval(footerCol1AutoSave);
    if (footerCol2AutoSave) clearInterval(footerCol2AutoSave);
  });
})();

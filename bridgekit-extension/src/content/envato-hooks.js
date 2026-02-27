/**
 * BridgeKit — Content Script (Envato Elements Hooks)
 *
 * Injected into elements.envato.com pages.
 * Responsibilities:
 * - Detect template kit / item pages
 * - Inject "Send to WordPress" button next to download buttons
 * - Capture metadata (title, thumbnail, category)
 * - Intercept download link and forward to service worker
 */

(function () {
  'use strict';

  const BRIDGE_BTN_CLASS = 'bk-send-to-wp';
  const BRIDGE_BTN_ID_PREFIX = 'bk-btn-';
  let buttonCounter = 0;

  // ─── Page Detection ───────────────────────────────────────────
  function isItemPage() {
    // Envato Elements item pages contain download/license buttons
    return (
      window.location.hostname === 'elements.envato.com' &&
      (window.location.pathname.includes('/') && document.querySelector('[class*="download"], [class*="Download"], [data-testid*="download"]'))
    );
  }

  // ─── Metadata Extraction ─────────────────────────────────────
  function extractMetadata() {
    const meta = {
      title: '',
      thumbnail_url: '',
      category: '',
      source_url: window.location.href,
    };

    // Title — try multiple selectors (Envato changes DOM frequently)
    const titleSelectors = [
      'h1',
      '[class*="itemTitle"]',
      '[class*="item-header"] h1',
      '[class*="ItemTitle"]',
      '[data-testid="item-title"]',
    ];
    for (const sel of titleSelectors) {
      const el = document.querySelector(sel);
      if (el && el.textContent.trim()) {
        meta.title = el.textContent.trim();
        break;
      }
    }

    // Thumbnail — try multiple selectors
    const thumbSelectors = [
      'meta[property="og:image"]',
      '[class*="preview"] img',
      '[class*="thumbnail"] img',
      '[class*="hero"] img',
      '.item-preview img',
    ];
    for (const sel of thumbSelectors) {
      const el = document.querySelector(sel);
      if (el) {
        meta.thumbnail_url = el.getAttribute('content') || el.getAttribute('src') || '';
        if (meta.thumbnail_url) break;
      }
    }

    // Category — from breadcrumbs or meta
    const catSelectors = [
      '[class*="breadcrumb"] a:last-of-type',
      'meta[property="article:section"]',
      '[class*="category"]',
      '[class*="Breadcrumb"] a',
    ];
    for (const sel of catSelectors) {
      const el = document.querySelector(sel);
      if (el) {
        meta.category = el.getAttribute('content') || el.textContent.trim() || '';
        if (meta.category) break;
      }
    }

    return meta;
  }

  // ─── Button Injection ────────────────────────────────────────
  function createBridgeButton() {
    const btn = document.createElement('button');
    btn.className = BRIDGE_BTN_CLASS;
    btn.id = `${BRIDGE_BTN_ID_PREFIX}${buttonCounter++}`;
    btn.innerHTML = `
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M12 5v14M5 12l7 7 7-7"/>
      </svg>
      <span>Send to WordPress</span>
    `;
    btn.title = 'Import this template kit to your WordPress site via BridgeKit';

    btn.addEventListener('click', handleBridgeClick);

    return btn;
  }

  // ─── Click Handler ────────────────────────────────────────────
  async function handleBridgeClick(event) {
    event.preventDefault();
    event.stopPropagation();

    const btn = event.currentTarget;
    const originalHTML = btn.innerHTML;

    // Set loading state
    btn.disabled = true;
    btn.classList.add('bk-loading');
    btn.innerHTML = `
      <svg class="bk-spinner" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/>
      </svg>
      <span>Sending...</span>
    `;

    try {
      // Extract page metadata
      const metadata = extractMetadata();

      if (!metadata.title) {
        metadata.title = document.title.replace(/ \|.*$/, '').trim() || 'Untitled Template Kit';
      }

      // Try to find the download URL
      // Envato generates download links dynamically — we capture from the DOM
      const downloadUrl = findDownloadUrl();

      const payload = {
        ...metadata,
        download_url: downloadUrl || '',
        timestamp: new Date().toISOString(),
      };

      // Send to service worker → WP
      const response = await chrome.runtime.sendMessage({
        action: 'IMPORT_TO_WP',
        payload,
      });

      if (response && response.success) {
        btn.classList.remove('bk-loading');
        btn.classList.add('bk-success');
        btn.innerHTML = `
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="20 6 9 17 4 12"/>
          </svg>
          <span>Queued!</span>
        `;
        setTimeout(() => resetButton(btn, originalHTML), 3000);
      } else {
        throw new Error(response?.message || 'Failed to send to WordPress');
      }
    } catch (err) {
      console.error('[BridgeKit]', err);
      btn.classList.remove('bk-loading');
      btn.classList.add('bk-error');
      btn.innerHTML = `
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>
        </svg>
        <span>Error</span>
      `;
      setTimeout(() => resetButton(btn, originalHTML), 4000);
    }
  }

  function resetButton(btn, originalHTML) {
    btn.disabled = false;
    btn.classList.remove('bk-loading', 'bk-success', 'bk-error');
    btn.innerHTML = originalHTML;
  }

  // ─── Download URL Detection ───────────────────────────────────
  function findDownloadUrl() {
    // Strategy 1: Look for direct download links
    const downloadLinks = document.querySelectorAll('a[href*="download"], a[href*="api/v"]');
    for (const link of downloadLinks) {
      if (link.href && (link.href.includes('.zip') || link.href.includes('download'))) {
        return link.href;
      }
    }

    // Strategy 2: Check for data attributes on download buttons
    const downloadBtns = document.querySelectorAll('[class*="download"], [data-testid*="download"]');
    for (const btn of downloadBtns) {
      const url = btn.getAttribute('data-url') || btn.getAttribute('data-href');
      if (url) return url;
    }

    // Strategy 3: Will be empty — WP plugin handles the "no URL" case
    // by prompting user to manually upload
    return '';
  }

  // ─── Injection Logic ─────────────────────────────────────────
  function injectButtons() {
    // Don't double-inject
    if (document.querySelector(`.${BRIDGE_BTN_CLASS}`)) return;

    // Find download/license button containers
    const buttonContainers = [
      ...document.querySelectorAll('[class*="download-button"], [class*="DownloadButton"], [class*="license-button"]'),
      ...document.querySelectorAll('[data-testid*="download"]'),
      ...document.querySelectorAll('button[class*="Download"]'),
    ];

    // Also try to find any prominent action button area
    if (buttonContainers.length === 0) {
      const actionAreas = document.querySelectorAll('[class*="action"], [class*="Action"], [class*="cta"], [class*="sidebar"] button');
      buttonContainers.push(...actionAreas);
    }

    if (buttonContainers.length > 0) {
      // Insert after the first download button found
      const target = buttonContainers[0];
      const bridgeBtn = createBridgeButton();

      if (target.parentNode) {
        target.parentNode.insertBefore(bridgeBtn, target.nextSibling);
        console.log('[BridgeKit] ✓ Button injected');
      }
    } else {
      // Fallback: inject as floating button
      injectFloatingButton();
    }
  }

  function injectFloatingButton() {
    const btn = createBridgeButton();
    btn.classList.add('bk-floating');
    document.body.appendChild(btn);
    console.log('[BridgeKit] ✓ Floating button injected');
  }

  // ─── MutationObserver ─────────────────────────────────────────
  // Envato is a SPA — elements render after initial load
  function observeDOM() {
    let injected = false;

    const observer = new MutationObserver((mutations) => {
      if (injected) return;

      // Check if we're on an item page and buttons haven't been injected yet
      if (!document.querySelector(`.${BRIDGE_BTN_CLASS}`)) {
        // Look for signs that the page content has loaded
        const hasContent = document.querySelector('h1') || document.querySelector('[class*="item"]');
        if (hasContent) {
          injected = true;
          // Small delay to let Envato finish rendering
          setTimeout(() => {
            injectButtons();
            injected = false; // allow re-injection on SPA navigation
          }, 500);
        }
      }
    });

    observer.observe(document.body, {
      childList: true,
      subtree: true,
    });

    // Also try immediately
    setTimeout(injectButtons, 1000);
  }

  // ─── SPA Navigation Handler ──────────────────────────────────
  // Detect URL changes (Envato uses client-side routing)
  let lastUrl = location.href;
  function watchNavigation() {
    const urlObserver = new MutationObserver(() => {
      if (location.href !== lastUrl) {
        lastUrl = location.href;
        console.log('[BridgeKit] Navigation detected:', lastUrl);
        // Remove old buttons
        document.querySelectorAll(`.${BRIDGE_BTN_CLASS}`).forEach(el => el.remove());
        // Re-inject after page settles
        setTimeout(injectButtons, 1500);
      }
    });

    urlObserver.observe(document.querySelector('title') || document.head, {
      childList: true,
      subtree: true,
    });
  }

  // ─── Initialize ──────────────────────────────────────────────
  function init() {
    console.log('[BridgeKit] Content script loaded on:', window.location.href);
    observeDOM();
    watchNavigation();
  }

  // Start when DOM is ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();

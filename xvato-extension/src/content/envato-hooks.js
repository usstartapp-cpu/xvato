/**
 * Xvato — Envato Content Script (ISOLATED world)
 *
 * Runs on elements.envato.com and app.envato.com.
 * Responsibilities:
 *  1. Inject "Send to WordPress" button on template kit pages
 *  2. Scrape page metadata (title, thumbnail, category)
 *  3. Listen for intercepted download URLs from the MAIN-world script
 *  4. Communicate with the service worker to trigger imports
 *  5. Update button state based on import progress
 */

(function () {
  'use strict';

  var TAG = '[Xvato:Content]';
  var BUTTON_CLASS = 'bk-send-to-wp';
  var INJECT_INTERVAL = 2000;
  var DEBOUNCE_NAV = 500;

  var lastUrl = location.href;
  var injectedForUrl = '';

  // --- Listen for download URLs from MAIN-world interceptor ---

  window.addEventListener('message', function (event) {
    if (event.source !== window) return;
    if (!event.data || event.data.type !== 'XVATO_DOWNLOAD_URL') return;

    var url = event.data.url;
    var source = event.data.source;
    console.log(TAG, 'Download URL from interceptor (' + source + '):', url);

    chrome.runtime.sendMessage({
      action: 'DOWNLOAD_URL_CAPTURED',
      url: url,
      source: source,
    });
  });

  // --- Listen for import results from the service worker ---

  chrome.runtime.onMessage.addListener(function (message, sender, sendResponse) {
    if (message.action === 'IMPORT_RESULT') {
      console.log(TAG, 'Import result received:', message);
      var btn = document.querySelector('.' + BUTTON_CLASS);
      if (btn) {
        if (message.success) {
          setButtonState(btn, 'success', '\u2713 Sent to WordPress');
          showToast('Template sent to WordPress successfully!', 'success');
          setTimeout(function () { setButtonState(btn, 'idle'); }, 4000);
        } else {
          setButtonState(btn, 'error', '\u2717 ' + (message.message || 'Import failed'));
          showToast(message.message || 'Import failed', 'error');
          setTimeout(function () { setButtonState(btn, 'idle'); }, 5000);
        }
      }
    }
    sendResponse({ ok: true });
  });

  // --- Page Metadata Scraping ---

  function getKitMetadata() {
    var meta = {
      title: '',
      thumbnail_url: '',
      category: 'template-kit',
      source_url: location.href,
    };

    var titleSelectors = [
      'h1',
      '[data-testid="item-title"]',
      '[class*="ItemTitle"]',
      '[class*="item-title"]',
      '[class*="itemTitle"]',
      'main h1',
      '[class*="Header"] h1',
      '[class*="header"] h1',
      'article h1',
    ];

    for (var i = 0; i < titleSelectors.length; i++) {
      var el = document.querySelector(titleSelectors[i]);
      if (el && el.textContent.trim()) {
        meta.title = el.textContent.trim();
        break;
      }
    }

    if (!meta.title) {
      meta.title = document.title
        .replace(/\s*[-|]\s*Envato Elements.*$/i, '')
        .replace(/\s*[-|]\s*Elements.*$/i, '')
        .trim();
    }

    var thumbSelectors = [
      'meta[property="og:image"]',
      'meta[name="twitter:image"]',
      '[data-testid="item-preview"] img',
      '[class*="Preview"] img',
      '[class*="preview"] img',
      '[class*="ItemCover"] img',
      '[class*="item-cover"] img',
      '[class*="Thumbnail"] img',
      'main img[src*="elements"]',
      'picture img',
    ];

    for (var j = 0; j < thumbSelectors.length; j++) {
      var thumbEl = document.querySelector(thumbSelectors[j]);
      if (thumbEl) {
        var thumbUrl = thumbEl.content || thumbEl.src || thumbEl.getAttribute('data-src');
        if (thumbUrl && thumbUrl.startsWith('http')) {
          meta.thumbnail_url = thumbUrl;
          break;
        }
      }
    }

    var categorySelectors = [
      '[data-testid="item-category"]',
      '[class*="Category"]',
      '[class*="category"]',
      'nav [aria-current="page"]',
      'a[href*="/wordpress/"]',
    ];

    for (var k = 0; k < categorySelectors.length; k++) {
      var catEl = document.querySelector(categorySelectors[k]);
      if (catEl && catEl.textContent.trim()) {
        meta.category = catEl.textContent.trim();
        break;
      }
    }

    var urlPath = location.pathname;
    if (urlPath.includes('template-kit')) {
      meta.category = 'Template Kit';
    } else if (urlPath.includes('wordpress')) {
      meta.category = 'WordPress';
    }

    return meta;
  }

  // --- Page Detection ---

  function isTemplateKitPage() {
    var url = location.href;

    var isItemPage =
      /elements\.envato\.com\/[a-z0-9].*-[A-Z0-9]{5,}$/i.test(url) ||
      /app\.envato\.com\/wordpress\/[0-9a-f]{8}-/i.test(url) ||
      /app\.envato\.com\/[^\/]+\/[0-9a-f]{8}-[0-9a-f]{4}-/i.test(url) ||
      /app\.envato\.com\/.*\/items?\//i.test(url) ||
      /app\.envato\.com\/elements\/.*-[A-Z0-9]{5,}/i.test(url);

    if (isItemPage) return true;

    var hasKitIndicator =
      document.querySelector('[class*="template-kit"]') ||
      document.querySelector('[class*="TemplateKit"]') ||
      document.querySelector('[data-item-type="wordpress"]') ||
      document.querySelector('a[href*="template-kit"]') ||
      /template.?kit/i.test(document.title) ||
      /elementor/i.test(document.title) ||
      /woocommerce/i.test(document.title) ||
      /wordpress/i.test(document.title);

    var hasDownload = findDownloadButton() !== null;

    var hasItemDetails = !!(
      document.querySelector('h1') &&
      (document.querySelector('[class*="Detail"]') ||
       document.querySelector('[class*="detail"]') ||
       document.querySelector('[class*="Sidebar"]') ||
       document.querySelector('[class*="sidebar"]') ||
       document.querySelector('[class*="License"]') ||
       document.querySelector('[class*="license"]') ||
       document.querySelector('[class*="Compatible"]') ||
       document.querySelector('[class*="compatible"]'))
    );

    return (hasKitIndicator && hasDownload) || (hasItemDetails && hasDownload);
  }

  function findDownloadButton() {
    var selectors = [
      'button[class*="download" i]',
      'button[class*="Download"]',
      '[data-testid*="download"]',
      'a[download]',
      '[class*="DownloadButton"]',
      '[class*="download-button"]',
      'button[aria-label*="download" i]',
      'button[aria-label*="Download"]',
      'a[aria-label*="download" i]',
      'a[aria-label*="Download"]',
    ];

    for (var i = 0; i < selectors.length; i++) {
      var el = document.querySelector(selectors[i]);
      if (el) return el;
    }

    var allClickable = document.querySelectorAll('button, a, [role="button"]');
    for (var j = 0; j < allClickable.length; j++) {
      var text = allClickable[j].textContent.trim().toLowerCase();
      if (text.indexOf('download') !== -1 && text.length < 30) {
        if (!allClickable[j].classList.contains(BUTTON_CLASS)) {
          return allClickable[j];
        }
      }
    }

    return null;
  }

  // --- Button Creation ---

  function createButton() {
    var btn = document.createElement('button');
    btn.className = BUTTON_CLASS;
    btn.type = 'button';
    btn.innerHTML =
      '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" ' +
      'stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
      '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>' +
      '<polyline points="7 10 12 15 17 10"/>' +
      '<line x1="12" y1="15" x2="12" y2="3"/>' +
      '</svg>' +
      '<span class="bk-btn-text">Send to WordPress</span>';

    btn.addEventListener('click', handleButtonClick);
    return btn;
  }

  // --- Button Injection ---

  function injectButton() {
    if (injectedForUrl === location.href) {
      if (document.querySelector('.' + BUTTON_CLASS)) return;
    }

    var existing = document.querySelectorAll('.' + BUTTON_CLASS);
    for (var i = 0; i < existing.length; i++) {
      existing[i].remove();
    }

    if (!isTemplateKitPage()) return;

    var btn = createButton();
    var injected = false;

    var downloadBtn = findDownloadButton();
    if (downloadBtn) {
      var dlParent = downloadBtn.parentElement;
      if (dlParent) {
        dlParent.insertBefore(btn, downloadBtn.nextSibling);
        injected = true;
      }
    }

    if (!injected) {
      var actionSelectors = [
        '[class*="DownloadButton"]',
        '[class*="download-button"]',
        '[data-testid*="download"]',
        '[class*="ActionBar"]',
        '[class*="action-bar"]',
        '[class*="Actions"]',
        '[class*="ItemActions"]',
        '[class*="item-actions"]',
        '[class*="ButtonGroup"]',
        '[class*="button-group"]',
        '[class*="Sidebar"] button',
        '[class*="sidebar"] button',
      ];

      for (var j = 0; j < actionSelectors.length; j++) {
        var target = document.querySelector(actionSelectors[j]);
        if (target && target.parentElement) {
          target.parentElement.insertBefore(btn, target.nextSibling);
          injected = true;
          break;
        }
      }
    }

    if (!injected) {
      var h1 = document.querySelector('h1');
      if (h1 && h1.parentElement) {
        h1.parentElement.insertBefore(btn, h1.nextSibling);
        injected = true;
      }
    }

    if (!injected) {
      btn.classList.add('bk-floating');
      document.body.appendChild(btn);
      injected = true;
    }

    if (injected) {
      injectedForUrl = location.href;
      console.log(TAG, 'Button injected for:', location.href);
      checkConnectionForButton(btn);
    }
  }

  function checkConnectionForButton(btn) {
    try {
      chrome.runtime.sendMessage({ action: 'GET_CONNECTION_STATUS' }, function (status) {
        if (status && !status.configured) {
          btn.title = 'Xvato: Click the extension icon to configure your WordPress connection first';
          btn.style.opacity = '0.7';
        }
      });
    } catch (e) {}
  }

  // --- Button Click Handler ---

  function handleButtonClick(e) {
    e.preventDefault();
    e.stopPropagation();

    var btn = e.currentTarget;
    if (btn.disabled) return;

    try {
      chrome.runtime.sendMessage({ action: 'GET_CONNECTION_STATUS' }, function (status) {
        if (!status || !status.configured) {
          setButtonState(btn, 'error', '\u2717 Configure WordPress first');
          showToast('Please configure your WordPress connection in the Xvato extension', 'error');
          setTimeout(function () { setButtonState(btn, 'idle'); }, 3000);
          return;
        }

        var meta = getKitMetadata();

        if (!meta.title) {
          setButtonState(btn, 'error', '\u2717 Could not detect kit title');
          setTimeout(function () { setButtonState(btn, 'idle'); }, 3000);
          return;
        }

        console.log(TAG, 'Sending import with metadata:', meta);
        setButtonState(btn, 'loading', 'Sending...');

        chrome.runtime.sendMessage({
          action: 'SEND_IMPORT',
          title: meta.title,
          thumbnail_url: meta.thumbnail_url,
          category: meta.category,
          source_url: meta.source_url,
        }, function (response) {
          if (!response) {
            setButtonState(btn, 'error', '\u2717 No response from extension');
            setTimeout(function () { setButtonState(btn, 'idle'); }, 5000);
            return;
          }

          if (response.success) {
            var isWaiting = response.data && response.data.status === 'waiting_for_download';
            var statusMsg = isWaiting
              ? '\u21bb Waiting for download...'
              : '\u2713 Sent to WordPress';

            setButtonState(btn, isWaiting ? 'loading' : 'success', statusMsg);

            if (!isWaiting) {
              showToast('Template sent to WordPress!', 'success');
              setTimeout(function () { setButtonState(btn, 'idle'); }, 4000);
            }

            if (isWaiting) {
              triggerEnvatoDownload();
            }
          } else {
            setButtonState(btn, 'error', '\u2717 ' + (response.message || 'Failed'));
            setTimeout(function () { setButtonState(btn, 'idle'); }, 5000);
          }
        });
      });
    } catch (err) {
      setButtonState(btn, 'error', '\u2717 Extension error - reload page');
    }
  }

  function triggerEnvatoDownload() {
    var downloadBtn = findDownloadButton();
    if (downloadBtn && !downloadBtn.classList.contains(BUTTON_CLASS)) {
      console.log(TAG, 'Triggering Envato download button');
      downloadBtn.click();
    } else {
      console.log(TAG, 'Could not find Envato download button to trigger.');
    }
  }

  // --- Button State Management ---

  function setButtonState(btn, state, text) {
    btn.disabled = (state === 'loading');
    btn.classList.remove('bk-loading', 'bk-success', 'bk-error');

    var textEl = btn.querySelector('.bk-btn-text');
    var svgEl = btn.querySelector('svg');

    switch (state) {
      case 'loading':
        btn.classList.add('bk-loading');
        if (textEl) textEl.textContent = text || 'Sending...';
        if (svgEl) svgEl.classList.add('bk-spinner');
        break;
      case 'success':
        btn.classList.add('bk-success');
        if (textEl) textEl.textContent = text || '\u2713 Sent to WordPress';
        if (svgEl) svgEl.classList.remove('bk-spinner');
        break;
      case 'error':
        btn.classList.add('bk-error');
        if (textEl) textEl.textContent = text || '\u2717 Error';
        if (svgEl) svgEl.classList.remove('bk-spinner');
        break;
      default:
        if (textEl) textEl.textContent = 'Send to WordPress';
        if (svgEl) svgEl.classList.remove('bk-spinner');
        btn.disabled = false;
        break;
    }
  }

  // --- Toast Notification ---

  function showToast(message, type) {
    // Remove existing toasts
    var existing = document.querySelectorAll('.bk-toast');
    existing.forEach(function (t) { t.remove(); });

    var toast = document.createElement('div');
    toast.className = 'bk-toast bk-toast--' + (type || 'info');
    toast.textContent = message;

    document.body.appendChild(toast);

    // Trigger animation
    requestAnimationFrame(function () {
      requestAnimationFrame(function () {
        toast.classList.add('bk-toast--show');
      });
    });

    // Auto-hide
    setTimeout(function () {
      toast.classList.remove('bk-toast--show');
      setTimeout(function () { toast.remove(); }, 300);
    }, 4000);
  }

  // --- SPA Navigation Detection ---

  var navDebounce = null;

  function onNavChange() {
    if (navDebounce) clearTimeout(navDebounce);
    navDebounce = setTimeout(function () {
      if (location.href !== lastUrl) {
        lastUrl = location.href;
        console.log(TAG, 'SPA navigation detected:', lastUrl);
        injectButton();
      }
    }, DEBOUNCE_NAV);
  }

  var origPushState = history.pushState;
  history.pushState = function () {
    origPushState.apply(this, arguments);
    onNavChange();
  };

  var origReplaceState = history.replaceState;
  history.replaceState = function () {
    origReplaceState.apply(this, arguments);
    onNavChange();
  };

  window.addEventListener('popstate', onNavChange);

  var observer = new MutationObserver(function () {
    onNavChange();
    if (!document.querySelector('.' + BUTTON_CLASS) && injectedForUrl === location.href) {
      injectedForUrl = '';
    }
  });

  observer.observe(document.documentElement, {
    childList: true,
    subtree: true,
  });

  // --- Periodic Re-injection ---

  setInterval(function () {
    if (!document.querySelector('.' + BUTTON_CLASS)) {
      injectedForUrl = '';
      injectButton();
    }
  }, INJECT_INTERVAL);

  // --- Init ---

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      setTimeout(injectButton, 500);
    });
  } else {
    setTimeout(injectButton, 500);
  }

  console.log(TAG, 'Content script loaded for:', location.href);
})();

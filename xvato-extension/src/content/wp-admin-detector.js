/**
 * Xvato — WP Admin Detector (Content Script)
 *
 * Runs on all pages matching *:// * /wp-admin/* and *:// * /wp-login.php*
 * Detects the logged-in WordPress session and sends connection info
 * back to the extension's service worker.
 *
 * How it works:
 * WordPress injects `wpApiSettings` into every wp-admin page, which contains:
 *   - root:  REST API base URL (e.g. "https://mysite.com/wp-json/")
 *   - nonce: A valid WP REST nonce for cookie-based auth
 *
 * We also scrape the current username from the admin bar.
 *
 * This allows the extension to make authenticated REST API calls using
 * the user's existing login session (cookies + nonce) — no Application
 * Password needed.
 */

(function () {
  'use strict';

  const TAG = '[Xvato:WPDetect]';

  /**
   * Wait for wp-admin to be fully loaded, then extract session info.
   */
  function init() {
    // Only proceed if this looks like a real WP admin page
    if (!isWPAdminPage()) {
      return;
    }

    console.log(TAG, 'WP admin page detected, extracting session info...');

    // Try immediately, then retry a few times if wpApiSettings isn't ready yet
    extractAndSend();
    let attempts = 0;
    const retry = setInterval(() => {
      attempts++;
      if (attempts > 10) {
        clearInterval(retry);
        return;
      }
      extractAndSend();
    }, 1000);
  }

  /**
   * Check if this page is a WordPress admin page.
   */
  function isWPAdminPage() {
    const url = location.href;

    // Obvious wp-admin URL
    if (/\/wp-admin\//i.test(url)) return true;

    // Check for WP admin body classes
    if (document.body && document.body.classList.contains('wp-admin')) return true;

    // Check for admin bar
    if (document.getElementById('wpadminbar')) return true;

    // Check for wpApiSettings in a script tag
    if (document.querySelector('script#wp-api-request-js-extra')) return true;

    return false;
  }

  /**
   * Extract the WP session info and send it to the service worker.
   */
  function extractAndSend() {
    const info = extractWPInfo();
    if (!info) return;

    console.log(TAG, 'Sending WP session info:', info.siteUrl, info.username);

    try {
      chrome.runtime.sendMessage({
        action: 'WP_SESSION_DETECTED',
        siteUrl: info.siteUrl,
        restUrl: info.restUrl,
        nonce: info.nonce,
        username: info.username,
        siteName: info.siteName,
        userId: info.userId,
      });
    } catch (e) {
      // Extension context might not be available
      console.warn(TAG, 'Could not send to extension:', e.message);
    }
  }

  /**
   * Extract WordPress connection info from the current admin page.
   */
  function extractWPInfo() {
    let restUrl = '';
    let nonce = '';
    let siteUrl = '';
    let username = '';
    let siteName = '';
    let userId = '';

    // 1. Try to read wpApiSettings (injected by WP into admin pages)
    //    It's in a <script> tag as: var wpApiSettings = {"root":"...","nonce":"..."};
    const apiSettingsScript = document.querySelector('script#wp-api-request-js-extra');
    if (apiSettingsScript) {
      const match = apiSettingsScript.textContent.match(
        /var\s+wpApiSettings\s*=\s*({.*?});/s
      );
      if (match) {
        try {
          const settings = JSON.parse(match[1]);
          restUrl = settings.root || '';
          nonce = settings.nonce || '';
        } catch (e) {
          console.warn(TAG, 'Could not parse wpApiSettings:', e.message);
        }
      }
    }

    // 2. Fallback: try to find the REST URL from <link> tag
    if (!restUrl) {
      const restLink = document.querySelector('link[rel="https://api.w.org/"]');
      if (restLink) {
        restUrl = restLink.href;
      }
    }

    // 3. Fallback: construct from current URL
    if (!restUrl) {
      const urlObj = new URL(location.href);
      const wpAdminIndex = urlObj.pathname.indexOf('/wp-admin');
      const basePath = wpAdminIndex > 0 ? urlObj.pathname.substring(0, wpAdminIndex) : '';
      restUrl = urlObj.origin + basePath + '/wp-json/';
    }

    // 4. Derive siteUrl from restUrl
    if (restUrl) {
      siteUrl = restUrl.replace(/\/wp-json\/?$/, '').replace(/\/+$/, '');
    }

    // 5. Get username from admin bar
    username = getUsername();

    // 6. Get site name from admin bar or title
    const siteNameEl = document.querySelector('#wp-admin-bar-site-name .ab-item');
    if (siteNameEl) {
      siteName = siteNameEl.textContent.trim();
    } else {
      // Fallback to document title
      siteName = document.title
        .replace(/[\u2039\u203A‹›].*$/, '')
        .replace(/\s*[-–—|]\s*WordPress$/, '')
        .trim();
    }

    // 7. Get user ID from body class
    const bodyClass = document.body ? document.body.className : '';
    const userIdMatch = bodyClass.match(/user-id-(\d+)/);
    if (userIdMatch) {
      userId = userIdMatch[1];
    }

    // Must have at least siteUrl to be useful
    if (!siteUrl) return null;

    // Must have nonce for cookie-based auth to work
    // (without nonce, cookie auth won't be accepted by WP REST API)
    if (!nonce) {
      console.warn(TAG, 'No WP REST nonce found — cookie auth won\'t work');
    }

    return { siteUrl, restUrl, nonce, username, siteName, userId };
  }

  /**
   * Extract the current logged-in username.
   */
  function getUsername() {
    // Method 1: Admin bar "Howdy, username" or "Hi, username"
    const greetEl = document.querySelector('#wp-admin-bar-my-account .display-name');
    if (greetEl) return greetEl.textContent.trim();

    // Method 2: Admin bar account link
    const accountEl = document.querySelector('#wp-admin-bar-my-account .ab-item');
    if (accountEl) {
      const text = accountEl.textContent.trim();
      // Strip "Howdy, " or "Hi, " prefix
      const stripped = text.replace(/^(Howdy|Hi),?\s*/i, '').trim();
      if (stripped) return stripped;
    }

    // Method 3: User profile page — look for #user_login
    const userLoginInput = document.querySelector('#user_login');
    if (userLoginInput) return userLoginInput.value;

    // Method 4: Look for the edit profile link to extract username
    const editProfileLink = document.querySelector('#wp-admin-bar-edit-profile a');
    if (editProfileLink) {
      const href = editProfileLink.href;
      const userMatch = href.match(/user_id=(\d+)/);
      // We can't get username from user_id, but at least we tried
    }

    return '';
  }

  // ─── Also listen for requests from the popup ───────────────

  chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
    if (message.action === 'PROBE_WP_SESSION') {
      const info = extractWPInfo();
      sendResponse(info || { error: 'Not a WP admin page or no session found' });
      return true;
    }
  });

  // ─── Init ──────────────────────────────────────────────────

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => setTimeout(init, 300));
  } else {
    setTimeout(init, 300);
  }
})();

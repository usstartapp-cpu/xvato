/**
 * Xvato — Service Worker (Background)
 *
 * Handles:
 *  - Message routing between popup, content scripts, and WordPress
 *  - WP session auto-detection (zero-config cookie+nonce auth)
 *  - Download URL caching from content script interceptor
 *  - webRequest monitoring for Envato download headers
 *  - Chrome downloads API monitoring
 *  - Sending import payloads to WordPress REST API
 */

import { getSettings, testConnection, sendImportRequest, checkImportStatus } from '../lib/api.js';

const TAG = '[Xvato:SW]';

// ─── In-Memory Caches ─────────────────────────────────────────

/** Maps tabId → latest captured download URL info. */
const downloadUrlCache = new Map();

/** Maps tabId → pending import payload (waiting for download URL). */
const pendingImports = new Map();

/** Detected WP sessions from content scripts. Maps siteUrl (lowercase) → session object. */
const detectedSessions = new Map();

/** Timeout (ms) to wait for a download URL after user clicks "Send to WP". */
const DOWNLOAD_URL_TIMEOUT = 30000;

// ─── Message Handler ──────────────────────────────────────────

chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
  const action = message.action;

  switch (action) {
    case 'TEST_CONNECTION':
      handleTestConnection().then(sendResponse);
      return true;

    case 'SEND_IMPORT':
      handleSendImport(message, sender).then(sendResponse);
      return true;

    case 'DOWNLOAD_URL_CAPTURED':
      handleDownloadUrlCaptured(message, sender);
      sendResponse({ ok: true });
      return false;

    case 'GET_CONNECTION_STATUS':
      handleGetConnectionStatus().then(sendResponse);
      return true;

    case 'CHECK_IMPORT_STATUS':
      checkImportStatus(message.jobId).then(sendResponse);
      return true;

    // ─── WP Session Detection (zero-config) ───────────────
    case 'WP_SESSION_DETECTED':
      handleWPSessionDetected(message, sender);
      sendResponse({ ok: true });
      return false;

    case 'DETECT_WP_ACCOUNTS':
      handleDetectWPAccounts().then(sendResponse);
      return true;

    case 'CONNECT_WP_ACCOUNT':
      handleConnectWPAccount(message).then(sendResponse);
      return true;

    case 'DISCONNECT_WP_ACCOUNT':
      handleDisconnect().then(sendResponse);
      return true;

    default:
      console.warn(TAG, 'Unknown action:', action);
      sendResponse({ success: false, message: 'Unknown action' });
      return false;
  }
});

// ═══════════════════════════════════════════════════════════════
// WP SESSION DETECTION — Zero-Config Flow
// ═══════════════════════════════════════════════════════════════

/**
 * Handle a WP session detected by wp-admin-detector.js content script.
 * This fires automatically when the user has a WP admin page open.
 */
function handleWPSessionDetected(message, sender) {
  const siteUrl = (message.siteUrl || '').replace(/\/+$/, '');
  if (!siteUrl) return;

  const key = siteUrl.toLowerCase();
  console.log(TAG, 'WP session detected:', key, message.username || '(unknown user)');

  detectedSessions.set(key, {
    siteUrl,
    restUrl: message.restUrl || '',
    nonce: message.nonce || '',
    username: message.username || '',
    siteName: message.siteName || '',
    userId: message.userId || '',
    source: 'tab',
    tabId: sender.tab ? sender.tab.id : null,
    tabTitle: sender.tab ? sender.tab.title : '',
    timestamp: Date.now(),
  });
}

/**
 * Scan for WordPress accounts.
 * 1. Probe all open wp-admin tabs for fresh nonces
 * 2. Scan cookies for wordpress_logged_in_* to find WP sites
 * 3. Return merged & deduplicated list
 */
async function handleDetectWPAccounts() {
  try {
    // Step 1: Probe open wp-admin tabs
    await probeOpenWPTabs();

    // Step 2: Scan cookies
    await scanWPCookies();

    // Step 3: Build accounts list
    const accounts = [];
    const now = Date.now();
    const MAX_AGE = 12 * 60 * 60 * 1000; // 12 hours

    for (const [, session] of detectedSessions.entries()) {
      accounts.push({
        siteUrl: session.siteUrl,
        restUrl: session.restUrl,
        nonce: session.nonce,
        username: session.username,
        siteName: session.siteName,
        userId: session.userId,
        source: session.source,
        domain: extractDomain(session.siteUrl),
        tabTitle: session.tabTitle || '',
        expired: (now - session.timestamp) > MAX_AGE,
        hasNonce: !!session.nonce,
        timestamp: session.timestamp,
      });
    }

    // Sort: active sessions with nonces first, then by recency
    accounts.sort((a, b) => {
      if (a.expired !== b.expired) return a.expired ? 1 : -1;
      if (a.hasNonce !== b.hasNonce) return a.hasNonce ? -1 : 1;
      return b.timestamp - a.timestamp;
    });

    console.log(TAG, 'Detected WP accounts:', accounts.length);
    return { success: true, accounts };
  } catch (err) {
    console.error(TAG, 'Error detecting WP accounts:', err);
    return { success: false, accounts: [], error: err.message };
  }
}

/**
 * Probe all open wp-admin tabs for fresh session info via content script.
 */
async function probeOpenWPTabs() {
  try {
    const tabs = await chrome.tabs.query({
      url: ['https://*/wp-admin/*', 'http://*/wp-admin/*'],
    });

    const probes = tabs.map((tab) =>
      new Promise((resolve) => {
        const timeout = setTimeout(() => resolve(null), 2000);
        try {
          chrome.tabs.sendMessage(tab.id, { action: 'PROBE_WP_SESSION' }, (response) => {
            clearTimeout(timeout);
            if (chrome.runtime.lastError) {
              resolve(null);
              return;
            }
            if (response && response.siteUrl && !response.error) {
              const key = response.siteUrl.replace(/\/+$/, '').toLowerCase();
              detectedSessions.set(key, {
                siteUrl: response.siteUrl.replace(/\/+$/, ''),
                restUrl: response.restUrl || '',
                nonce: response.nonce || '',
                username: response.username || '',
                siteName: response.siteName || '',
                userId: response.userId || '',
                source: 'tab',
                tabId: tab.id,
                tabTitle: tab.title || '',
                timestamp: Date.now(),
              });
            }
            resolve(response);
          });
        } catch (e) {
          clearTimeout(timeout);
          resolve(null);
        }
      })
    );

    await Promise.all(probes);
  } catch (e) {
    console.warn(TAG, 'Error probing WP tabs:', e);
  }
}

/**
 * Scan browser cookies for wordpress_logged_in_* cookies.
 */
async function scanWPCookies() {
  try {
    const allCookies = await chrome.cookies.getAll({});
    const wpCookies = allCookies.filter((c) =>
      c.name === 'wordpress_logged_in' || c.name.startsWith('wordpress_logged_in_')
    );

    for (const cookie of wpCookies) {
      const protocol = cookie.secure ? 'https' : 'http';
      const domain = cookie.domain.replace(/^\./, '');
      const siteUrl = `${protocol}://${domain}`;
      const key = siteUrl.toLowerCase();

      // Don't overwrite tab-based detection (which has a nonce)
      if (detectedSessions.has(key) && detectedSessions.get(key).nonce) {
        continue;
      }

      // Extract username from cookie value: username|expiration|token|hmac
      let username = '';
      try {
        const parts = decodeURIComponent(cookie.value).split('|');
        if (parts.length >= 1) username = parts[0];
      } catch (e) { /* ignore */ }

      const expired = cookie.expirationDate
        ? cookie.expirationDate * 1000 < Date.now()
        : false;

      if (!expired) {
        detectedSessions.set(key, {
          siteUrl,
          restUrl: `${siteUrl}/wp-json/`,
          nonce: '',
          username,
          siteName: domain,
          userId: '',
          source: 'cookie',
          tabId: null,
          tabTitle: '',
          timestamp: Date.now(),
        });
      }
    }
  } catch (e) {
    console.warn(TAG, 'Error scanning WP cookies:', e);
  }
}

/**
 * One-click connect to a detected WP account (zero-config flow).
 *
 * Accounts with a nonce (from an open wp-admin tab):
 *   → Uses cookie + X-WP-Nonce auth. No password needed.
 *
 * Accounts without a nonce (detected from cookies only):
 *   → Tells the user to open wp-admin in a tab first.
 */
async function handleConnectWPAccount(message) {
  const siteUrl = (message.siteUrl || '').replace(/\/+$/, '');
  const username = message.username || '';

  if (!siteUrl) {
    return { success: false, message: 'No site URL provided.' };
  }

  console.log(TAG, 'Connecting to WP account:', siteUrl, username);

  // Get the cached session
  let session = detectedSessions.get(siteUrl.toLowerCase());
  let nonce = message.nonce || (session ? session.nonce : '');

  // If no nonce, try probing open tabs one more time
  if (!nonce) {
    await probeOpenWPTabs();
    session = detectedSessions.get(siteUrl.toLowerCase());
    nonce = session ? session.nonce : '';
  }

  if (!nonce) {
    return {
      success: false,
      message: 'No active session found. Open your WP admin dashboard in a browser tab first, then try again.',
      needsWpAdmin: true,
    };
  }

  // Test the connection using cookie+nonce
  const restBase = (session && session.restUrl) || `${siteUrl}/wp-json/`;
  const statusUrl = `${restBase.replace(/\/+$/, '')}/xvato/v1/status`;

  try {
    const response = await fetch(statusUrl, {
      method: 'GET',
      headers: {
        'X-WP-Nonce': nonce,
        'Accept': 'application/json',
      },
      credentials: 'include',
    });

    if (response.ok) {
      const statusData = await response.json();

      // Save as active connection in cookie auth mode
      await chrome.storage.local.set({
        bk_wp_url: siteUrl,
        bk_wp_user: username || (session ? session.username : '') || '',
        bk_wp_app_password: '',
        xv_auth_mode: 'cookie',
        xv_active_nonce: nonce,
        xv_active_rest_url: restBase,
        xv_connected: true,
        xv_connected_at: Date.now(),
      });

      console.log(TAG, 'Connected via cookie auth:', siteUrl);
      return {
        success: true,
        message: `Connected to "${statusData.site_name || siteUrl}"`,
        data: statusData,
        authMode: 'cookie',
      };
    } else {
      const errData = await response.json().catch(() => ({}));
      console.warn(TAG, 'Cookie auth failed:', response.status, errData);

      if (response.status === 404) {
        return {
          success: false,
          message: 'Xvato plugin not found on this site. Install and activate the Xvato WordPress plugin first.',
          needsPlugin: true,
        };
      }

      return {
        success: false,
        message: errData.message || `Connection failed (HTTP ${response.status}). Try refreshing your WP admin tab.`,
        needsRefresh: response.status === 401 || response.status === 403,
      };
    }
  } catch (err) {
    console.error(TAG, 'Cookie auth error:', err);
    return {
      success: false,
      message: `Could not reach ${extractDomain(siteUrl)}. Make sure the Xvato plugin is installed and the site is accessible.`,
    };
  }
}

/**
 * Disconnect the current WP account and clear all stored credentials.
 */
async function handleDisconnect() {
  await chrome.storage.local.remove([
    'bk_wp_url',
    'bk_wp_user',
    'bk_wp_app_password',
    'xv_auth_mode',
    'xv_active_nonce',
    'xv_active_rest_url',
    'xv_connected',
    'xv_connected_at',
  ]);
  console.log(TAG, 'Disconnected.');
  return { success: true };
}

// ═══════════════════════════════════════════════════════════════
// EXISTING HANDLERS — Connection Test, Import, Download URL
// ═══════════════════════════════════════════════════════════════

async function handleTestConnection() {
  try {
    const result = await testConnection();
    console.log(TAG, 'Connection test result:', result);

    if (result.success) {
      await chrome.storage.local.set({ xv_connected: true, xv_connected_at: Date.now() });
    }

    return result;
  } catch (err) {
    console.error(TAG, 'Connection test error:', err);
    return { success: false, message: err.message };
  }
}

async function handleGetConnectionStatus() {
  try {
    const settings = await getSettings();
    const data = await chrome.storage.local.get(['xv_connected', 'xv_auth_mode']);

    if (data.xv_connected && settings.wpUrl) {
      return {
        configured: true,
        connected: true,
        wpUrl: settings.wpUrl,
        wpUser: settings.wpUser,
        authMode: data.xv_auth_mode || settings.authMode,
      };
    }

    if (settings.wpUrl && settings.wpUser && settings.wpAppPassword) {
      return { configured: true, connected: false, wpUrl: settings.wpUrl };
    }

    return { configured: false, connected: false };
  } catch (err) {
    return { configured: false, connected: false, error: err.message };
  }
}

async function handleSendImport(message, sender) {
  const tabId = sender.tab ? sender.tab.id : null;
  const payload = {
    title: message.title || 'Untitled Template Kit',
    thumbnail_url: message.thumbnail_url || '',
    category: message.category || 'template-kit',
    source_url: message.source_url || '',
    timestamp: new Date().toISOString(),
  };

  console.log(TAG, 'Import request received:', payload.title, '(tab:', tabId, ')');

  // Check for download URL
  if (message.download_url) {
    payload.download_url = message.download_url;
  } else if (tabId && downloadUrlCache.has(tabId)) {
    const cached = downloadUrlCache.get(tabId);
    if (cached.url && (Date.now() - cached.timestamp) < 60000) {
      payload.download_url = cached.url;
      downloadUrlCache.delete(tabId);
    }
  }

  if (payload.download_url) {
    return await sendToWordPress(payload);
  }

  // No download URL yet — store as pending
  if (tabId) {
    console.log(TAG, 'No download URL yet. Storing as pending import.');
    pendingImports.set(tabId, { payload, timestamp: Date.now() });

    setTimeout(() => {
      const pending = pendingImports.get(tabId);
      if (pending) {
        console.log(TAG, 'Download URL timeout. Sending without it.');
        pendingImports.delete(tabId);
        sendToWordPress(pending.payload).then((result) => notifyTab(tabId, result));
      }
    }, DOWNLOAD_URL_TIMEOUT);

    return {
      success: true,
      message: 'Import queued — waiting for download URL...',
      data: { status: 'waiting_for_download' },
    };
  }

  return await sendToWordPress(payload);
}

function handleDownloadUrlCaptured(message, sender) {
  const tabId = sender.tab ? sender.tab.id : null;
  const url = message.url;
  if (!url || !tabId) return;

  console.log(TAG, `Download URL captured (${message.source}):`, url);
  downloadUrlCache.set(tabId, { url, source: message.source, timestamp: Date.now() });

  const pending = pendingImports.get(tabId);
  if (pending) {
    pending.payload.download_url = url;
    pendingImports.delete(tabId);
    sendToWordPress(pending.payload).then((result) => notifyTab(tabId, result));
  }
}

async function sendToWordPress(payload) {
  try {
    const result = await sendImportRequest(payload);

    if (result.success) {
      console.log(TAG, 'Import sent successfully');
      try {
        chrome.notifications.create('xv-import-' + Date.now(), {
          type: 'basic',
          iconUrl: '../icons/icon-128.png',
          title: payload.download_url ? 'Xvato — Import Started' : 'Xvato — Entry Created',
          message: payload.download_url
            ? `"${payload.title}" is being imported into WordPress.`
            : `"${payload.title}" entry created. Upload the ZIP in the dashboard.`,
          priority: 1,
        });
      } catch (e) { /* ignore */ }
    } else {
      console.error(TAG, 'Import failed:', result.message);
    }

    return result;
  } catch (err) {
    console.error(TAG, 'Error sending import:', err);
    return { success: false, message: err.message };
  }
}

function notifyTab(tabId, result) {
  try {
    chrome.tabs.sendMessage(tabId, { action: 'IMPORT_RESULT', ...result });
  } catch (e) {
    console.warn(TAG, 'Could not notify tab:', e.message);
  }
}

// ═══════════════════════════════════════════════════════════════
// WEBREQUEST & DOWNLOADS MONITORING
// ═══════════════════════════════════════════════════════════════

try {
  chrome.webRequest.onBeforeRedirect.addListener(
    (details) => {
      const { redirectUrl, tabId } = details;
      if (redirectUrl && isLikelyDownload(redirectUrl)) {
        console.log(TAG, 'webRequest redirect to download:', redirectUrl);
        downloadUrlCache.set(tabId, { url: redirectUrl, source: 'webRequest-redirect', timestamp: Date.now() });

        const pending = pendingImports.get(tabId);
        if (pending) {
          pending.payload.download_url = redirectUrl;
          pendingImports.delete(tabId);
          sendToWordPress(pending.payload).then((result) => notifyTab(tabId, result));
        }
      }
    },
    {
      urls: [
        'https://elements.envato.com/*',
        'https://app.envato.com/*',
        'https://*.envato.com/*',
        'https://*.amazonaws.com/*',
        'https://*.cloudfront.net/*',
      ],
    }
  );

  chrome.webRequest.onHeadersReceived.addListener(
    (details) => {
      const { responseHeaders, url, tabId } = details;
      if (!responseHeaders) return;

      const contentDisp = responseHeaders.find((h) => h.name.toLowerCase() === 'content-disposition');
      if (contentDisp && contentDisp.value && contentDisp.value.includes('attachment')) {
        const contentType = responseHeaders.find((h) => h.name.toLowerCase() === 'content-type');
        const isZip = contentType && (contentType.value.includes('zip') || contentType.value.includes('octet-stream'));

        if (isZip || url.includes('.zip')) {
          console.log(TAG, 'webRequest attachment download:', url);
          downloadUrlCache.set(tabId, { url, source: 'webRequest-attachment', timestamp: Date.now() });

          const pending = pendingImports.get(tabId);
          if (pending) {
            pending.payload.download_url = url;
            pendingImports.delete(tabId);
            sendToWordPress(pending.payload).then((result) => notifyTab(tabId, result));
          }
        }
      }
    },
    {
      urls: [
        'https://elements.envato.com/*',
        'https://app.envato.com/*',
        'https://*.envato.com/*',
        'https://*.amazonaws.com/*',
        'https://*.cloudfront.net/*',
      ],
    },
    ['responseHeaders']
  );
} catch (e) {
  console.warn(TAG, 'webRequest listeners not available:', e.message);
}

try {
  chrome.downloads.onCreated.addListener((downloadItem) => {
    const effectiveUrl = downloadItem.finalUrl || downloadItem.url;
    const filename = downloadItem.filename;

    if (isLikelyDownload(effectiveUrl) || (filename && filename.endsWith('.zip'))) {
      console.log(TAG, 'Download detected via downloads API:', effectiveUrl);

      for (const [tabId, pending] of pendingImports.entries()) {
        if (Date.now() - pending.timestamp < DOWNLOAD_URL_TIMEOUT) {
          pending.payload.download_url = effectiveUrl;
          pendingImports.delete(tabId);
          sendToWordPress(pending.payload).then((result) => notifyTab(tabId, result));
          break;
        }
      }
    }
  });
} catch (e) {
  console.warn(TAG, 'Downloads API listener not available:', e.message);
}

// ═══════════════════════════════════════════════════════════════
// CLEANUP & UTILITIES
// ═══════════════════════════════════════════════════════════════

chrome.tabs.onRemoved.addListener((tabId) => {
  downloadUrlCache.delete(tabId);
  pendingImports.delete(tabId);
});

function isLikelyDownload(url) {
  if (!url) return false;
  return (
    /\.zip(\?|#|$)/i.test(url) ||
    /\/download\//i.test(url) ||
    /s3.*amazonaws.*\.zip/i.test(url) ||
    /cloudfront.*\.zip/i.test(url)
  );
}

function extractDomain(url) {
  try {
    return new URL(url).hostname;
  } catch {
    return url;
  }
}

setInterval(() => {
  const now = Date.now();
  for (const [tabId, entry] of downloadUrlCache.entries()) {
    if (now - entry.timestamp > 5 * 60 * 1000) downloadUrlCache.delete(tabId);
  }
  for (const [tabId, entry] of pendingImports.entries()) {
    if (now - entry.timestamp > DOWNLOAD_URL_TIMEOUT * 2) pendingImports.delete(tabId);
  }
}, 60000);

console.log(TAG, 'Service worker initialized.');

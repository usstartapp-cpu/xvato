/**
 * Xvato — Shared API Helper
 * Handles all communication with the WordPress REST API.
 *
 * Supports two auth modes:
 *   1. "cookie" — Uses the user's existing WP login session (cookies + X-WP-Nonce)
 *      No app password needed. Requires the user to be logged into wp-admin.
 *   2. "password" — Uses WordPress Application Passwords (Basic Auth)
 *      Works even when the user isn't logged in.
 */

const STORAGE_KEYS = {
  WP_URL: 'bk_wp_url',
  WP_USER: 'bk_wp_user',
  WP_APP_PASSWORD: 'bk_wp_app_password',
};

/**
 * Retrieve saved connection settings from chrome.storage.
 * @returns {Promise<{wpUrl: string, wpUser: string, wpAppPassword: string, authMode: string, nonce: string, restUrl: string}>}
 */
export async function getSettings() {
  const data = await chrome.storage.local.get([
    STORAGE_KEYS.WP_URL,
    STORAGE_KEYS.WP_USER,
    STORAGE_KEYS.WP_APP_PASSWORD,
    'xv_auth_mode',
    'xv_active_nonce',
    'xv_active_rest_url',
  ]);
  return {
    wpUrl: data[STORAGE_KEYS.WP_URL] || '',
    wpUser: data[STORAGE_KEYS.WP_USER] || '',
    wpAppPassword: data[STORAGE_KEYS.WP_APP_PASSWORD] || '',
    authMode: data.xv_auth_mode || 'password',
    nonce: data.xv_active_nonce || '',
    restUrl: data.xv_active_rest_url || '',
  };
}

/**
 * Save connection settings to chrome.storage.
 * @param {Object} settings
 */
export async function saveSettings({ wpUrl, wpUser, wpAppPassword }) {
  const toStore = {
    [STORAGE_KEYS.WP_URL]: wpUrl.replace(/\/+$/, ''), // strip trailing slashes
    [STORAGE_KEYS.WP_USER]: wpUser,
    [STORAGE_KEYS.WP_APP_PASSWORD]: wpAppPassword,
  };

  // If saving with an app password, switch to password auth mode
  if (wpAppPassword) {
    toStore.xv_auth_mode = 'password';
  }

  await chrome.storage.local.set(toStore);
}

/**
 * Build the full REST API URL for a given endpoint path.
 * @param {string} path - e.g. '/import' or '/library'
 * @returns {Promise<string>}
 */
async function getEndpointUrl(path) {
  const settings = await getSettings();

  // Cookie auth mode: use the stored REST URL
  if (settings.authMode === 'cookie' && settings.restUrl) {
    return `${settings.restUrl.replace(/\/+$/, '')}/xvato/v1${path}`;
  }

  // Password auth mode: derive from wpUrl
  if (!settings.wpUrl) {
    throw new Error('Xvato: WordPress URL not configured.');
  }
  return `${settings.wpUrl}/wp-json/xvato/v1${path}`;
}

/**
 * Build the appropriate auth headers based on the active mode.
 * @returns {Promise<Object>} Headers object
 */
async function getAuthHeaders() {
  const settings = await getSettings();

  if (settings.authMode === 'cookie' && settings.nonce) {
    // Cookie-based auth: send the WP nonce, browser sends cookies automatically
    return {
      'X-WP-Nonce': settings.nonce,
    };
  }

  // Password-based auth: Basic Auth header
  if (!settings.wpUser || !settings.wpAppPassword) {
    throw new Error('Xvato: WordPress credentials not configured.');
  }
  return {
    'Authorization': 'Basic ' + btoa(`${settings.wpUser}:${settings.wpAppPassword}`),
  };
}

/**
 * Determine if we should include credentials (cookies) in the request.
 */
async function getCredentialsMode() {
  const settings = await getSettings();
  return settings.authMode === 'cookie' ? 'include' : 'omit';
}

/**
 * Test the connection to WordPress.
 * @returns {Promise<{success: boolean, message: string, data?: Object}>}
 */
export async function testConnection() {
  try {
    const url = await getEndpointUrl('/status');
    const authHeaders = await getAuthHeaders();
    const credentials = await getCredentialsMode();

    console.log('[Xvato] Testing connection to:', url);

    const response = await fetch(url, {
      method: 'GET',
      headers: {
        ...authHeaders,
        'Accept': 'application/json',
      },
      credentials,
    });

    console.log('[Xvato] Response status:', response.status);

    if (!response.ok) {
      const errorData = await response.json().catch(() => ({}));

      // If cookie auth failed with 401/403, the nonce might be stale
      const settings = await getSettings();
      if (settings.authMode === 'cookie' && (response.status === 401 || response.status === 403)) {
        return {
          success: false,
          message: 'Session expired. Refresh your WordPress admin tab and reconnect.',
          needsRefresh: true,
        };
      }

      return {
        success: false,
        message: errorData.message || `HTTP ${response.status}: ${response.statusText}`,
      };
    }

    const data = await response.json();
    return { success: true, message: 'Connected successfully!', data };
  } catch (err) {
    console.error('[Xvato] Connection error:', err);
    let message = err.message;
    if (message === 'Failed to fetch') {
      message = 'Failed to fetch — check that your Site URL is correct and the site is accessible. Make sure the Xvato plugin is activated.';
    }
    return { success: false, message };
  }
}

/**
 * Send an import request to WordPress.
 * @param {Object} payload
 * @returns {Promise<{success: boolean, message: string, data?: Object}>}
 */
export async function sendImportRequest(payload) {
  try {
    const url = await getEndpointUrl('/import');
    const authHeaders = await getAuthHeaders();
    const credentials = await getCredentialsMode();

    const response = await fetch(url, {
      method: 'POST',
      headers: {
        ...authHeaders,
        'Content-Type': 'application/json',
      },
      credentials,
      body: JSON.stringify(payload),
    });

    const data = await response.json();

    if (!response.ok) {
      return {
        success: false,
        message: data.message || `HTTP ${response.status}`,
      };
    }

    return { success: true, message: 'Import queued!', data };
  } catch (err) {
    return { success: false, message: err.message };
  }
}

/**
 * Check the status of an import job.
 * @param {string|number} jobId
 * @returns {Promise<{success: boolean, status: string, data?: Object}>}
 */
export async function checkImportStatus(jobId) {
  try {
    const url = await getEndpointUrl(`/status/${jobId}`);
    const authHeaders = await getAuthHeaders();
    const credentials = await getCredentialsMode();

    const response = await fetch(url, {
      method: 'GET',
      headers: {
        ...authHeaders,
        'Accept': 'application/json',
      },
      credentials,
    });

    const data = await response.json();
    return { success: response.ok, status: data.status || 'unknown', data };
  } catch (err) {
    return { success: false, status: 'error', message: err.message };
  }
}

export { STORAGE_KEYS };

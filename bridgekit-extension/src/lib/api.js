/**
 * BridgeKit — Shared API Helper
 * Handles all communication with the WordPress REST API.
 */

const STORAGE_KEYS = {
  WP_URL: 'bk_wp_url',
  WP_USER: 'bk_wp_user',
  WP_APP_PASSWORD: 'bk_wp_app_password',
};

/**
 * Retrieve saved connection settings from chrome.storage.
 * @returns {Promise<{wpUrl: string, wpUser: string, wpAppPassword: string}>}
 */
export async function getSettings() {
  const data = await chrome.storage.local.get([
    STORAGE_KEYS.WP_URL,
    STORAGE_KEYS.WP_USER,
    STORAGE_KEYS.WP_APP_PASSWORD,
  ]);
  return {
    wpUrl: data[STORAGE_KEYS.WP_URL] || '',
    wpUser: data[STORAGE_KEYS.WP_USER] || '',
    wpAppPassword: data[STORAGE_KEYS.WP_APP_PASSWORD] || '',
  };
}

/**
 * Save connection settings to chrome.storage.
 * @param {Object} settings
 */
export async function saveSettings({ wpUrl, wpUser, wpAppPassword }) {
  await chrome.storage.local.set({
    [STORAGE_KEYS.WP_URL]: wpUrl.replace(/\/+$/, ''), // strip trailing slashes
    [STORAGE_KEYS.WP_USER]: wpUser,
    [STORAGE_KEYS.WP_APP_PASSWORD]: wpAppPassword,
  });
}

/**
 * Build the Basic Auth header from stored credentials.
 * @returns {Promise<string>}
 */
async function getAuthHeader() {
  const { wpUser, wpAppPassword } = await getSettings();
  if (!wpUser || !wpAppPassword) {
    throw new Error('BridgeKit: WordPress credentials not configured.');
  }
  return 'Basic ' + btoa(`${wpUser}:${wpAppPassword}`);
}

/**
 * Build the full REST API URL for a given endpoint path.
 * @param {string} path - e.g. '/import' or '/library'
 * @returns {Promise<string>}
 */
async function getEndpointUrl(path) {
  const { wpUrl } = await getSettings();
  if (!wpUrl) {
    throw new Error('BridgeKit: WordPress URL not configured.');
  }
  return `${wpUrl}/wp-json/bridgekit/v1${path}`;
}

/**
 * Test the connection to WordPress.
 * @returns {Promise<{success: boolean, message: string, data?: Object}>}
 */
export async function testConnection() {
  try {
    const url = await getEndpointUrl('/status');
    const auth = await getAuthHeader();

    const response = await fetch(url, {
      method: 'GET',
      headers: {
        'Authorization': auth,
        'Content-Type': 'application/json',
      },
    });

    if (!response.ok) {
      const errorData = await response.json().catch(() => ({}));
      return {
        success: false,
        message: errorData.message || `HTTP ${response.status}: ${response.statusText}`,
      };
    }

    const data = await response.json();
    return { success: true, message: 'Connected successfully!', data };
  } catch (err) {
    return { success: false, message: err.message };
  }
}

/**
 * Send an import request to WordPress.
 * @param {Object} payload
 * @param {string} payload.download_url - Envato download URL
 * @param {string} payload.title - Template kit title
 * @param {string} payload.thumbnail_url - Thumbnail image URL
 * @param {string} payload.category - Category/type from Envato
 * @param {string} payload.source_url - Original Envato page URL
 * @returns {Promise<{success: boolean, message: string, data?: Object}>}
 */
export async function sendImportRequest(payload) {
  try {
    const url = await getEndpointUrl('/import');
    const auth = await getAuthHeader();

    const response = await fetch(url, {
      method: 'POST',
      headers: {
        'Authorization': auth,
        'Content-Type': 'application/json',
      },
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
    const auth = await getAuthHeader();

    const response = await fetch(url, {
      method: 'GET',
      headers: {
        'Authorization': auth,
        'Content-Type': 'application/json',
      },
    });

    const data = await response.json();
    return { success: response.ok, status: data.status || 'unknown', data };
  } catch (err) {
    return { success: false, status: 'error', message: err.message };
  }
}

export { STORAGE_KEYS };

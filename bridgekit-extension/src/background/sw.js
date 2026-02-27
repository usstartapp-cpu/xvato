/**
 * BridgeKit — Service Worker (Background)
 * 
 * Responsibilities:
 * - Relay messages between content script and WP REST API
 * - Show notifications for import status
 * - Coordinate the import flow
 */

import { sendImportRequest, testConnection, checkImportStatus, getSettings } from '../lib/api.js';

// ─── Message Handler ──────────────────────────────────────────────
chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
  const { action, payload } = message;

  switch (action) {
    case 'TEST_CONNECTION':
      testConnection().then(sendResponse);
      return true; // keep channel open for async response

    case 'IMPORT_TO_WP':
      handleImport(payload).then(sendResponse);
      return true;

    case 'CHECK_STATUS':
      checkImportStatus(payload.jobId).then(sendResponse);
      return true;

    case 'GET_SETTINGS':
      getSettings().then(sendResponse);
      return true;

    default:
      sendResponse({ success: false, message: `Unknown action: ${action}` });
      return false;
  }
});

// ─── Import Handler ───────────────────────────────────────────────
async function handleImport(payload) {
  try {
    // Validate we have settings
    const settings = await getSettings();
    if (!settings.wpUrl || !settings.wpUser || !settings.wpAppPassword) {
      showNotification(
        'Configuration Required',
        'Please configure your WordPress connection in the BridgeKit popup.'
      );
      return { success: false, message: 'Not configured' };
    }

    // Show "sending" notification
    showNotification('Sending to WordPress...', `Importing: ${payload.title || 'Template Kit'}`);

    // Send to WP
    const result = await sendImportRequest(payload);

    // Show result notification
    if (result.success) {
      showNotification('Import Queued ✓', `"${payload.title}" is being imported to your WordPress site.`);
    } else {
      showNotification('Import Failed ✗', result.message || 'Unknown error occurred.');
    }

    return result;
  } catch (err) {
    showNotification('Import Error', err.message);
    return { success: false, message: err.message };
  }
}

// ─── Notifications ────────────────────────────────────────────────
function showNotification(title, message) {
  chrome.notifications.create({
    type: 'basic',
    iconUrl: chrome.runtime.getURL('icons/icon-128.png'),
    title: `BridgeKit: ${title}`,
    message: message,
  });
}

// ─── Install Handler ──────────────────────────────────────────────
chrome.runtime.onInstalled.addListener((details) => {
  if (details.reason === 'install') {
    showNotification(
      'Welcome to BridgeKit!',
      'Click the extension icon to configure your WordPress connection.'
    );
  }
});

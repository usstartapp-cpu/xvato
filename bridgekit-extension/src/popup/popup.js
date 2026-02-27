/**
 * BridgeKit — Popup Script
 * Handles settings form and connection testing.
 */

import { getSettings, saveSettings, STORAGE_KEYS } from '../lib/api.js';

// ─── DOM Elements ──────────────────────────────────────────────
const form = document.getElementById('bk-settings-form');
const wpUrlInput = document.getElementById('bk-wp-url');
const wpUserInput = document.getElementById('bk-wp-user');
const wpAppPasswordInput = document.getElementById('bk-wp-app-password');
const saveBtn = document.getElementById('bk-save');
const testBtn = document.getElementById('bk-test');
const statusEl = document.getElementById('bk-status');
const statusText = document.getElementById('bk-status-text');
const messageEl = document.getElementById('bk-message');

// ─── Load Saved Settings ──────────────────────────────────────
async function loadSettings() {
  const settings = await getSettings();
  wpUrlInput.value = settings.wpUrl;
  wpUserInput.value = settings.wpUser;
  wpAppPasswordInput.value = settings.wpAppPassword;

  if (settings.wpUrl && settings.wpUser && settings.wpAppPassword) {
    setStatus('saved', 'Settings saved — click Test to verify');
  }
}

// ─── Save Settings ────────────────────────────────────────────
form.addEventListener('submit', async (e) => {
  e.preventDefault();

  const wpUrl = wpUrlInput.value.trim();
  const wpUser = wpUserInput.value.trim();
  const wpAppPassword = wpAppPasswordInput.value.trim();

  if (!wpUrl || !wpUser || !wpAppPassword) {
    showMessage('Please fill in all fields.', 'error');
    return;
  }

  // Validate URL format
  try {
    new URL(wpUrl);
  } catch {
    showMessage('Please enter a valid URL (e.g., https://yoursite.com)', 'error');
    return;
  }

  saveBtn.disabled = true;
  saveBtn.textContent = 'Saving...';

  await saveSettings({ wpUrl, wpUser, wpAppPassword });

  saveBtn.disabled = false;
  saveBtn.textContent = 'Save Settings';

  setStatus('saved', 'Settings saved');
  showMessage('Settings saved successfully!', 'success');
});

// ─── Test Connection ──────────────────────────────────────────
testBtn.addEventListener('click', async () => {
  testBtn.disabled = true;
  testBtn.textContent = 'Testing...';
  setStatus('testing', 'Testing connection...');

  // First save current form values
  await saveSettings({
    wpUrl: wpUrlInput.value.trim(),
    wpUser: wpUserInput.value.trim(),
    wpAppPassword: wpAppPasswordInput.value.trim(),
  });

  // Send test request via service worker
  const response = await chrome.runtime.sendMessage({ action: 'TEST_CONNECTION' });

  testBtn.disabled = false;
  testBtn.textContent = 'Test Connection';

  if (response && response.success) {
    setStatus('connected', 'Connected to WordPress');
    showMessage(`✓ ${response.message}`, 'success');

    // Show extra info if available
    if (response.data) {
      const info = response.data;
      if (info.site_name) {
        showMessage(`✓ Connected to "${info.site_name}" — Elementor: ${info.elementor_version || 'not detected'}`, 'success');
      }
    }
  } else {
    setStatus('error', 'Connection failed');
    showMessage(`✗ ${response?.message || 'Could not connect to WordPress'}`, 'error');
  }
});

// ─── UI Helpers ───────────────────────────────────────────────
function setStatus(state, text) {
  statusEl.className = `bk-status bk-status--${state}`;
  statusText.textContent = text;
}

function showMessage(text, type = 'info') {
  messageEl.textContent = text;
  messageEl.className = `bk-message bk-message--${type}`;
  messageEl.hidden = false;

  // Auto-hide after 5 seconds
  setTimeout(() => {
    messageEl.hidden = true;
  }, 5000);
}

// ─── Init ─────────────────────────────────────────────────────
loadSettings();

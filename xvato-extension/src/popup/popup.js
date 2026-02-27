/**
 * Xvato — Popup Script
 *
 * Manages the extension popup UI with two main views:
 *   1. Connected View — Shows the active WP connection, re-test, disconnect.
 *   2. Setup View     — Auto-detects WP accounts for one-click connect,
 *                        or allows manual connection via Application Password.
 *
 * Zero-Config Flow:
 *   User is logged into WP admin → installs plugin → installs extension →
 *   extension auto-detects the session → user clicks "Connect" → done.
 */

import { getSettings, saveSettings, STORAGE_KEYS } from '../lib/api.js';

// ─── DOM Elements ──────────────────────────────────────────────

// Views
const connectedView = document.getElementById('xv-connected-view');
const setupView = document.getElementById('xv-setup-view');

// Connected view
const connectedSiteEl = document.getElementById('xv-connected-site');
const connectedUserEl = document.getElementById('xv-connected-user');
const connectedModeEl = document.getElementById('xv-connected-mode');
const retestBtn = document.getElementById('xv-retest-btn');
const disconnectBtn = document.getElementById('xv-disconnect-btn');

// Setup view
const statusEl = document.getElementById('xv-status');
const statusText = document.getElementById('xv-status-text');
const accountsSection = document.getElementById('xv-accounts-section');
const accountsList = document.getElementById('xv-accounts-list');
const accountsScanning = document.getElementById('xv-accounts-scanning');
const accountsRefreshBtn = document.getElementById('xv-accounts-refresh');
const noAccountsEl = document.getElementById('xv-no-accounts');
const manualToggle = document.getElementById('xv-manual-toggle');
const manualToggleText = document.getElementById('xv-manual-toggle-text');
const manualForm = document.getElementById('xv-settings-form');

// Manual form fields
const wpUrlInput = document.getElementById('xv-wp-url');
const wpUserInput = document.getElementById('xv-wp-user');
const wpAppPasswordInput = document.getElementById('xv-wp-app-password');
const saveBtn = document.getElementById('xv-save');
const testBtn = document.getElementById('xv-test');

// Shared
const messageEl = document.getElementById('xv-message');
const themeToggle = document.getElementById('xv-theme-toggle');

// ─── State ─────────────────────────────────────────────────────
let detectedAccounts = [];
let manualFormVisible = false;

// ─── Theme Management ─────────────────────────────────────────
async function initTheme() {
  const data = await chrome.storage.local.get('xv_theme');
  const theme = data.xv_theme || 'dark';
  document.documentElement.setAttribute('data-theme', theme);
}

themeToggle.addEventListener('click', async () => {
  const current = document.documentElement.getAttribute('data-theme');
  const next = current === 'dark' ? 'light' : 'dark';
  document.documentElement.setAttribute('data-theme', next);
  await chrome.storage.local.set({ xv_theme: next });
});

// ═══════════════════════════════════════════════════════════════
// VIEW SWITCHING
// ═══════════════════════════════════════════════════════════════

function showConnectedView(info) {
  connectedView.hidden = false;
  setupView.hidden = true;

  connectedSiteEl.textContent = info.wpUrl || info.siteUrl || '—';
  connectedUserEl.textContent = info.wpUser || info.username || '';
  connectedUserEl.hidden = !connectedUserEl.textContent;

  const mode = info.authMode || 'password';
  if (mode === 'cookie') {
    connectedModeEl.textContent = '🔒 Session auth (no password needed)';
  } else {
    connectedModeEl.textContent = '🔑 Application password auth';
  }

  // Show site name if available
  if (info.siteName) {
    connectedSiteEl.textContent = info.siteName;
    connectedSiteEl.title = info.wpUrl || info.siteUrl || '';
  }
}

function showSetupView() {
  connectedView.hidden = true;
  setupView.hidden = false;
}

// ═══════════════════════════════════════════════════════════════
// INITIALIZATION — Check if already connected
// ═══════════════════════════════════════════════════════════════

async function init() {
  await initTheme();

  // Check if we're already connected
  const status = await chrome.runtime.sendMessage({ action: 'GET_CONNECTION_STATUS' });

  if (status && status.connected) {
    showConnectedView(status);
  } else {
    showSetupView();
    // Load any saved manual form values
    await loadManualFormValues();
    // Start scanning for WP accounts
    await detectWPAccounts();
  }
}

async function loadManualFormValues() {
  const settings = await getSettings();
  if (settings.wpUrl) wpUrlInput.value = settings.wpUrl;
  if (settings.wpUser) wpUserInput.value = settings.wpUser;
  if (settings.wpAppPassword) wpAppPasswordInput.value = settings.wpAppPassword;
}

// ═══════════════════════════════════════════════════════════════
// WP ACCOUNT DETECTION (Zero-Config)
// ═══════════════════════════════════════════════════════════════

async function detectWPAccounts(showSpinner = true) {
  if (showSpinner) {
    accountsScanning.hidden = false;
    accountsSection.hidden = true;
    noAccountsEl.hidden = true;
    setStatus('idle', 'Scanning for WordPress sites…');
  }

  try {
    const response = await chrome.runtime.sendMessage({ action: 'DETECT_WP_ACCOUNTS' });

    accountsScanning.hidden = true;

    if (response && response.success && response.accounts.length > 0) {
      detectedAccounts = response.accounts;
      renderAccountsList(detectedAccounts);
      accountsSection.hidden = false;
      noAccountsEl.hidden = true;

      const readyCount = detectedAccounts.filter((a) => a.hasNonce && !a.expired).length;
      if (readyCount > 0) {
        setStatus('saved', `${readyCount} WordPress site${readyCount > 1 ? 's' : ''} ready to connect`);
      } else {
        setStatus('idle', `${detectedAccounts.length} site${detectedAccounts.length > 1 ? 's' : ''} found — open WP admin to connect`);
      }
    } else {
      detectedAccounts = [];
      accountsSection.hidden = true;
      noAccountsEl.hidden = false;
      setStatus('idle', 'No WordPress sessions found');
    }
  } catch (err) {
    console.warn('[Xvato] Detection error:', err);
    accountsScanning.hidden = true;
    accountsSection.hidden = true;
    noAccountsEl.hidden = false;
    setStatus('idle', 'Not connected');
  }
}

/**
 * Render the list of detected WordPress accounts.
 */
function renderAccountsList(accounts) {
  accountsList.innerHTML = '';

  accounts.forEach((account, index) => {
    const card = document.createElement('div');
    card.className = 'xv-account-card';
    if (account.expired) card.classList.add('xv-account--expired');

    const avatarLetter = (account.username || account.domain || '?').charAt(0);
    const displayName = account.username || account.tabTitle || 'Unknown user';
    const displaySite = account.siteName || account.domain || account.siteUrl;

    // Can we one-click connect? Only if we have a nonce from an open tab.
    const canConnect = account.hasNonce && !account.expired;

    // Badge
    let badgeHtml = '';
    if (account.expired) {
      badgeHtml = '<span class="xv-account-badge xv-account-badge--expired">Expired</span>';
    } else if (canConnect) {
      badgeHtml = '<span class="xv-account-badge xv-account-badge--ready">Ready</span>';
    } else if (account.source === 'cookie') {
      badgeHtml = '<span class="xv-account-badge xv-account-badge--cookie">Cookie</span>';
    } else {
      badgeHtml = '<span class="xv-account-badge xv-account-badge--tab">Tab</span>';
    }

    // Button
    let btnHtml = '';
    if (canConnect) {
      btnHtml = `<button type="button" class="xv-account-connect-btn" data-index="${index}">Connect</button>`;
    } else if (account.expired) {
      btnHtml = `<button type="button" class="xv-account-connect-btn xv-account-connect-btn--disabled" disabled title="Session expired">Expired</button>`;
    } else {
      // Cookie-only: no nonce, need to open wp-admin
      btnHtml = `<button type="button" class="xv-account-connect-btn xv-account-connect-btn--secondary" data-index="${index}" title="Open WP admin to get session">Open Admin</button>`;
    }

    card.innerHTML = `
      <div class="xv-account-avatar">${escapeHtml(avatarLetter)}</div>
      <div class="xv-account-info">
        <div class="xv-account-username">${escapeHtml(displayName)}</div>
        <div class="xv-account-domain">${escapeHtml(displaySite)}</div>
      </div>
      ${badgeHtml}
      ${btnHtml}
    `;

    // Button handler
    const btn = card.querySelector('.xv-account-connect-btn');
    if (btn && !btn.disabled) {
      btn.addEventListener('click', (e) => {
        e.stopPropagation();
        if (canConnect) {
          connectAccount(account, btn);
        } else {
          // Open wp-admin in a new tab so the content script can grab the nonce
          openWPAdmin(account);
        }
      });
    }

    accountsList.appendChild(card);
  });
}

/**
 * One-click connect to a detected WP account via cookie+nonce.
 */
async function connectAccount(account, btnEl) {
  btnEl.disabled = true;
  btnEl.textContent = 'Connecting…';
  setStatus('testing', `Connecting to ${account.domain}…`);
  showMessage('', 'info', true); // clear

  try {
    const result = await chrome.runtime.sendMessage({
      action: 'CONNECT_WP_ACCOUNT',
      siteUrl: account.siteUrl,
      restUrl: account.restUrl,
      nonce: account.nonce,
      username: account.username,
    });

    if (result && result.success) {
      setStatus('connected', result.message || 'Connected!');
      showMessage(`✓ ${result.message}`, 'success');

      // Switch to connected view after a brief pause
      setTimeout(() => {
        showConnectedView({
          wpUrl: account.siteUrl,
          wpUser: account.username,
          authMode: result.authMode || 'cookie',
          siteName: result.data ? result.data.site_name : account.siteName,
        });
      }, 800);
    } else {
      btnEl.disabled = false;
      btnEl.textContent = 'Connect';

      if (result && result.needsRefresh) {
        setStatus('error', 'Session expired');
        showMessage('⚠ Session expired. Refresh your WP admin tab, then click Rescan.', 'error');
      } else if (result && result.needsPlugin) {
        setStatus('error', 'Plugin not found');
        showMessage('⚠ ' + result.message, 'error');
      } else if (result && result.needsWpAdmin) {
        setStatus('error', 'No active session');
        showMessage('⚠ ' + result.message, 'error');
      } else {
        setStatus('error', 'Connection failed');
        showMessage(`✗ ${result ? result.message : 'Unknown error'}`, 'error');
      }
    }
  } catch (err) {
    btnEl.disabled = false;
    btnEl.textContent = 'Connect';
    setStatus('error', 'Connection failed');
    showMessage(`✗ ${err.message}`, 'error');
  }
}

/**
 * Open wp-admin for a cookie-only account (no nonce yet).
 * Once the user navigates to wp-admin, the content script will grab the nonce,
 * and they can rescan to see the account as "Ready".
 */
function openWPAdmin(account) {
  const adminUrl = account.siteUrl.replace(/\/+$/, '') + '/wp-admin/';
  chrome.tabs.create({ url: adminUrl });

  showMessage(
    '→ Opening WP admin… Once the page loads, come back here and click Rescan.',
    'info'
  );
}

// ═══════════════════════════════════════════════════════════════
// MANUAL FORM (Application Password)
// ═══════════════════════════════════════════════════════════════

// Toggle the manual form
manualToggle.addEventListener('click', () => {
  manualFormVisible = !manualFormVisible;
  manualForm.hidden = !manualFormVisible;
  manualToggle.classList.toggle('xv-manual-toggle--open', manualFormVisible);
});

// Save & Connect (manual)
manualForm.addEventListener('submit', async (e) => {
  e.preventDefault();

  const wpUrl = wpUrlInput.value.trim();
  const wpUser = wpUserInput.value.trim();
  const wpAppPassword = wpAppPasswordInput.value.trim();

  if (!wpUrl || !wpUser || !wpAppPassword) {
    showMessage('Please fill in all fields.', 'error');
    return;
  }

  try { new URL(wpUrl); } catch {
    showMessage('Please enter a valid URL (e.g., https://yoursite.com)', 'error');
    return;
  }

  saveBtn.disabled = true;
  saveBtn.textContent = 'Connecting…';
  setStatus('testing', 'Connecting…');

  // Save with password auth mode
  await chrome.storage.local.set({
    bk_wp_url: wpUrl.replace(/\/+$/, ''),
    bk_wp_user: wpUser,
    bk_wp_app_password: wpAppPassword,
    xv_auth_mode: 'password',
    xv_active_nonce: '',
    xv_active_rest_url: '',
  });

  // Test the connection
  const result = await chrome.runtime.sendMessage({ action: 'TEST_CONNECTION' });

  saveBtn.disabled = false;
  saveBtn.textContent = 'Save & Connect';

  if (result && result.success) {
    setStatus('connected', 'Connected!');
    const siteName = result.data ? result.data.site_name : '';
    showMessage(`✓ Connected to "${siteName || wpUrl}"`, 'success');

    setTimeout(() => {
      showConnectedView({
        wpUrl,
        wpUser,
        authMode: 'password',
        siteName,
      });
    }, 800);
  } else {
    setStatus('error', 'Connection failed');
    showMessage(`✗ ${result ? result.message : 'Could not connect'}`, 'error');
  }
});

// Test Connection (manual)
testBtn.addEventListener('click', async () => {
  const wpUrl = wpUrlInput.value.trim();
  const wpUser = wpUserInput.value.trim();
  const wpAppPassword = wpAppPasswordInput.value.trim();

  if (!wpUrl || !wpUser || !wpAppPassword) {
    showMessage('Please fill in all fields before testing.', 'error');
    return;
  }

  testBtn.disabled = true;
  testBtn.textContent = 'Testing…';
  setStatus('testing', 'Testing connection…');

  await chrome.storage.local.set({
    bk_wp_url: wpUrl.replace(/\/+$/, ''),
    bk_wp_user: wpUser,
    bk_wp_app_password: wpAppPassword,
    xv_auth_mode: 'password',
  });

  const result = await chrome.runtime.sendMessage({ action: 'TEST_CONNECTION' });

  testBtn.disabled = false;
  testBtn.textContent = 'Test Connection';

  if (result && result.success) {
    setStatus('connected', 'Connected!');
    const siteName = result.data ? result.data.site_name : '';
    showMessage(`✓ Connected to "${siteName || wpUrl}"`, 'success');

    setTimeout(() => {
      showConnectedView({
        wpUrl,
        wpUser,
        authMode: 'password',
        siteName,
      });
    }, 1200);
  } else {
    setStatus('error', 'Connection failed');
    showMessage(`✗ ${result ? result.message : 'Could not connect'}`, 'error');
  }
});

// ═══════════════════════════════════════════════════════════════
// CONNECTED VIEW ACTIONS
// ═══════════════════════════════════════════════════════════════

// Re-test connection
retestBtn.addEventListener('click', async () => {
  retestBtn.disabled = true;
  retestBtn.textContent = 'Testing…';

  const result = await chrome.runtime.sendMessage({ action: 'TEST_CONNECTION' });

  retestBtn.disabled = false;
  retestBtn.innerHTML = `
    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
    Re-test`;

  if (result && result.success) {
    showMessage('✓ Connection is healthy!', 'success');
  } else {
    showMessage(`✗ ${result ? result.message : 'Connection failed'}`, 'error');

    // If session expired, offer to go back to setup
    if (result && result.needsRefresh) {
      showMessage('⚠ Session expired. Refresh your WP admin and reconnect.', 'error');
    }
  }
});

// Disconnect
disconnectBtn.addEventListener('click', async () => {
  disconnectBtn.disabled = true;
  disconnectBtn.textContent = 'Disconnecting…';

  await chrome.runtime.sendMessage({ action: 'DISCONNECT_WP_ACCOUNT' });

  disconnectBtn.disabled = false;
  disconnectBtn.innerHTML = `
    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18.36 6.64a9 9 0 1 1-12.73 0"/><line x1="12" y1="2" x2="12" y2="12"/></svg>
    Disconnect`;

  showMessage('Disconnected.', 'info');
  showSetupView();
  setStatus('idle', 'Not connected');

  // Re-scan for accounts
  await detectWPAccounts();
});

// ═══════════════════════════════════════════════════════════════
// RESCAN
// ═══════════════════════════════════════════════════════════════

accountsRefreshBtn.addEventListener('click', async () => {
  accountsRefreshBtn.classList.add('xv-spinning');
  await detectWPAccounts(false);
  setTimeout(() => accountsRefreshBtn.classList.remove('xv-spinning'), 600);
});

// ═══════════════════════════════════════════════════════════════
// UI HELPERS
// ═══════════════════════════════════════════════════════════════

function setStatus(state, text) {
  statusEl.className = `xv-status xv-status--${state}`;
  statusText.textContent = text;
}

let messageTimeout = null;
function showMessage(text, type = 'info', hide = false) {
  if (messageTimeout) clearTimeout(messageTimeout);

  if (hide || !text) {
    messageEl.hidden = true;
    return;
  }

  messageEl.textContent = text;
  messageEl.className = `xv-message xv-message--${type}`;
  messageEl.hidden = false;

  messageTimeout = setTimeout(() => {
    messageEl.hidden = true;
  }, 6000);
}

function escapeHtml(str) {
  const div = document.createElement('div');
  div.textContent = str || '';
  return div.innerHTML;
}

// ═══════════════════════════════════════════════════════════════
// INIT
// ═══════════════════════════════════════════════════════════════

init();

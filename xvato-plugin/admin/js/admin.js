/**
 * Xvato — Admin JavaScript
 *
 * Handles: theme toggle, upload form toggle, copy-to-clipboard,
 * auto-refresh for pending imports, bulk selection.
 */
(function () {
  'use strict';

  // ─── Theme Toggle ──────────────────────────────────────────
  var themeToggle = document.getElementById('xv-theme-toggle');
  var pageWrap = document.querySelector('[data-xv-theme]');

  function getStoredTheme() {
    try { return localStorage.getItem('xv-admin-theme') || 'dark'; }
    catch (e) { return 'dark'; }
  }

  function applyTheme(theme) {
    if (pageWrap) {
      pageWrap.setAttribute('data-xv-theme', theme);
    }
    try { localStorage.setItem('xv-admin-theme', theme); }
    catch (e) { /* ignore */ }
  }

  // Apply saved theme on load
  applyTheme(getStoredTheme());

  if (themeToggle) {
    themeToggle.addEventListener('click', function () {
      var current = pageWrap ? pageWrap.getAttribute('data-xv-theme') : 'dark';
      applyTheme(current === 'dark' ? 'light' : 'dark');
    });
  }

  // ─── Upload Form Toggle ─────────────────────────────────────
  var uploadToggle = document.getElementById('xv-upload-toggle');
  var uploadForm   = document.getElementById('xv-upload-form');
  var uploadCancel = document.getElementById('xv-upload-cancel');

  if (uploadToggle && uploadForm) {
    uploadToggle.addEventListener('click', function () {
      var isHidden = uploadForm.style.display === 'none' || !uploadForm.style.display;
      uploadForm.style.display = isHidden ? 'block' : 'none';

      if (isHidden) {
        var firstInput = uploadForm.querySelector('input[type="text"]');
        if (firstInput) firstInput.focus();
      }
    });
  }

  if (uploadCancel && uploadForm) {
    uploadCancel.addEventListener('click', function () {
      uploadForm.style.display = 'none';
    });
  }

  // ─── Copy to Clipboard ──────────────────────────────────────
  document.querySelectorAll('.xv-copy-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var targetId = btn.getAttribute('data-copy');
      var targetEl = document.getElementById(targetId);

      if (!targetEl) return;

      var text = targetEl.textContent.trim();

      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function () {
          showCopied(btn);
        }).catch(function () {
          fallbackCopy(text, btn);
        });
      } else {
        fallbackCopy(text, btn);
      }
    });
  });

  function fallbackCopy(text, btn) {
    var textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.style.position = 'fixed';
    textarea.style.opacity = '0';
    document.body.appendChild(textarea);
    textarea.select();
    try {
      document.execCommand('copy');
      showCopied(btn);
    } catch (e) {
      // silently fail
    }
    document.body.removeChild(textarea);
  }

  function showCopied(btn) {
    btn.classList.add('copied');
    var icon = btn.querySelector('.dashicons');
    if (icon) {
      icon.classList.remove('dashicons-clipboard');
      icon.classList.add('dashicons-yes');
    }

    setTimeout(function () {
      btn.classList.remove('copied');
      if (icon) {
        icon.classList.remove('dashicons-yes');
        icon.classList.add('dashicons-clipboard');
      }
    }, 1500);
  }

  // ─── Auto-refresh for in-progress imports ───────────────────
  var inProgressBadges = document.querySelectorAll(
    '.xv-badge--pending, .xv-badge--downloading, .xv-badge--extracting, .xv-badge--importing'
  );

  // Only auto-refresh on dashboard page (not settings)
  if (inProgressBadges.length > 0) {
    var isDashboard = window.location.search.indexOf('page=xvato') !== -1 &&
                      window.location.search.indexOf('page=xvato-settings') === -1;

    if (isDashboard) {
      setTimeout(function () {
        window.location.reload();
      }, 15000);

      var statsBar = document.querySelector('.xv-stats');
      if (statsBar) {
        var indicator = document.createElement('div');
        indicator.className = 'xv-auto-refresh';
        indicator.textContent = 'Auto-refreshing — imports in progress';
        statsBar.parentNode.insertBefore(indicator, statsBar.nextSibling);
      }
    }
  }

  // ─── Bulk Selection ────────────────────────────────────────
  var selectToggle = document.getElementById('xv-select-toggle');
  var bulkBar      = document.getElementById('xv-bulk-bar');
  var bulkForm     = document.getElementById('xv-bulk-form');
  var bulkCount    = document.getElementById('xv-bulk-count');
  var selectAll    = document.getElementById('xv-select-all');
  var bulkCancel   = document.getElementById('xv-bulk-cancel');
  var checkboxes   = document.querySelectorAll('.xv-bulk-check');

  // Show "Select" button only when there are cards
  if (selectToggle && checkboxes.length > 0) {
    selectToggle.style.display = '';
  }

  function updateBulkCount() {
    var checked = document.querySelectorAll('.xv-bulk-check:checked');
    if (bulkCount) bulkCount.textContent = checked.length;

    if (bulkBar) {
      bulkBar.style.display = checked.length > 0 ? 'block' : 'none';
    }

    if (selectAll && checkboxes.length > 0) {
      selectAll.checked = checked.length === checkboxes.length;
      selectAll.indeterminate = checked.length > 0 && checked.length < checkboxes.length;
    }
  }

  if (selectToggle) {
    selectToggle.addEventListener('click', function () {
      var grid = document.querySelector('.xv-grid');
      if (grid) {
        grid.classList.toggle('xv-selecting');
      }
      var isSelecting = grid && grid.classList.contains('xv-selecting');
      selectToggle.innerHTML = isSelecting
        ? '<span class="dashicons dashicons-no"></span> Cancel'
        : '<span class="dashicons dashicons-yes-alt"></span> Select';

      if (!isSelecting) {
        checkboxes.forEach(function (cb) { cb.checked = false; });
        updateBulkCount();
      }
    });
  }

  checkboxes.forEach(function (cb) {
    cb.addEventListener('change', updateBulkCount);
  });

  if (selectAll) {
    selectAll.addEventListener('change', function () {
      checkboxes.forEach(function (cb) {
        cb.checked = selectAll.checked;
      });
      updateBulkCount();
    });
  }

  if (bulkCancel) {
    bulkCancel.addEventListener('click', function () {
      checkboxes.forEach(function (cb) { cb.checked = false; });
      updateBulkCount();
      var grid = document.querySelector('.xv-grid');
      if (grid) grid.classList.remove('xv-selecting');
      if (selectToggle) {
        selectToggle.innerHTML = '<span class="dashicons dashicons-yes-alt"></span> Select';
      }
    });
  }

  // ─── Card hover interactions ────────────────────────────────
  document.querySelectorAll('.xv-card[data-id]').forEach(function (card) {
    var actions = card.querySelector('.xv-card-actions');
    if (actions) {
      card.setAttribute('tabindex', '0');
    }
  });

  // ─── Notice dismiss with animation ──────────────────────────
  document.querySelectorAll('.xv-notice-dismiss').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      var notice = btn.closest('.xv-notice');
      if (notice) {
        notice.classList.add('xv-dismissing');
        setTimeout(function () {
          notice.remove();
        }, 300);
      }
    });
  });

  // ─── Keyboard shortcut: Escape to close overlays ────────────
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      if (uploadForm && uploadForm.style.display !== 'none') {
        uploadForm.style.display = 'none';
      }
      var grid = document.querySelector('.xv-grid');
      if (grid && grid.classList.contains('xv-selecting')) {
        grid.classList.remove('xv-selecting');
        if (checkboxes) {
          checkboxes.forEach(function (cb) { cb.checked = false; });
        }
        updateBulkCount();
        if (selectToggle) {
          selectToggle.innerHTML = '<span class="dashicons dashicons-yes-alt"></span> Select';
        }
      }
    }
  });

})();

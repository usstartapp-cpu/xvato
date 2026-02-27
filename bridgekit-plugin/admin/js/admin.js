/**
 * BridgeKit — Admin JavaScript
 *
 * Handles: upload form toggle, copy-to-clipboard, auto-refresh for pending imports.
 */
(function () {
  'use strict';

  // ─── Upload Form Toggle ─────────────────────────────────────
  const uploadToggle = document.getElementById('bk-upload-toggle');
  const uploadForm   = document.getElementById('bk-upload-form');
  const uploadCancel = document.getElementById('bk-upload-cancel');

  if (uploadToggle && uploadForm) {
    uploadToggle.addEventListener('click', function () {
      const isHidden = uploadForm.style.display === 'none' || !uploadForm.style.display;
      uploadForm.style.display = isHidden ? 'block' : 'none';

      if (isHidden) {
        const firstInput = uploadForm.querySelector('input[type="text"]');
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
  document.querySelectorAll('.bk-copy-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      const targetId = btn.getAttribute('data-copy');
      const targetEl = document.getElementById(targetId);

      if (!targetEl) return;

      const text = targetEl.textContent.trim();

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
  // If there are cards with pending/downloading/extracting/importing status,
  // refresh the page periodically to show updated status.
  var inProgressBadges = document.querySelectorAll(
    '.bk-badge--pending, .bk-badge--downloading, .bk-badge--extracting, .bk-badge--importing'
  );

  if (inProgressBadges.length > 0) {
    var refreshTimer = setTimeout(function () {
      // Only refresh if we're on the dashboard page
      if (window.location.search.indexOf('page=bridgekit') !== -1 &&
          window.location.search.indexOf('page=bridgekit-settings') === -1) {
        window.location.reload();
      }
    }, 15000); // Refresh every 15 seconds

    // Show a subtle indicator
    var statsBar = document.querySelector('.bk-stats');
    if (statsBar) {
      var indicator = document.createElement('div');
      indicator.style.cssText = 'font-size:11px; color:#646970; text-align:center; padding:4px; background:#f6f7f7; border-radius:0 0 8px 8px; border:1px solid #c3c4c7; border-top:0; margin:-1px 0 12px;';
      indicator.textContent = '↻ Auto-refreshing — imports in progress';
      statsBar.parentNode.insertBefore(indicator, statsBar.nextSibling);
    }
  }

  // ─── Dismiss Notices ────────────────────────────────────────
  // WordPress handles this natively, but just in case:
  document.querySelectorAll('.notice.is-dismissible').forEach(function (notice) {
    var closeBtn = notice.querySelector('.notice-dismiss');
    if (!closeBtn) {
      closeBtn = document.createElement('button');
      closeBtn.type = 'button';
      closeBtn.className = 'notice-dismiss';
      closeBtn.innerHTML = '<span class="screen-reader-text">Dismiss this notice.</span>';
      notice.appendChild(closeBtn);
    }
    closeBtn.addEventListener('click', function () {
      notice.style.display = 'none';
    });
  });

  // ─── Card hover interactions ────────────────────────────────
  document.querySelectorAll('.bk-card[data-id]').forEach(function (card) {
    var actions = card.querySelector('.bk-card-actions');
    if (actions) {
      // Show actions on card focus/hover for keyboard accessibility
      card.setAttribute('tabindex', '0');
    }
  });

  // ─── Confirm dangerous actions ──────────────────────────────
  document.querySelectorAll('a[onclick*="confirm"]').forEach(function (link) {
    // Already handled via inline onclick — just ensuring they exist
  });

})();

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

  // ─── Kit Detail Page Logic ──────────────────────────────────
  var kitWrap = document.querySelector('.xv-kit-detail');
  if (kitWrap && typeof xvatoAdmin !== 'undefined') {
    initKitDetail(kitWrap);
  }

  function initKitDetail(wrap) {
    var kitId      = wrap.getAttribute('data-kit-id');
    var loader     = document.getElementById('xv-kit-loader');
    var content    = document.getElementById('xv-kit-content');
    var kitData    = null; // fetched data
    var selectedIdxs = new Set();

    // Fetch kit data
    var fd = new FormData();
    fd.append('action', 'xvato_get_kit_templates');
    fd.append('nonce', xvatoAdmin.nonce);
    fd.append('kit_id', kitId);

    fetch(xvatoAdmin.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (resp) {
        if (!resp.success) {
          loader.innerHTML = '<p style="color:var(--xv-danger);">' + (resp.data || 'Failed to load kit data.') + '</p>';
          return;
        }
        kitData = resp.data;
        loader.style.display = 'none';
        content.style.display = '';
        renderThemeSection(kitData);
        renderTemplateGrid(kitData.templates);
        renderColorsSection(kitData.global_colors);
        updateSelectedCount();
      })
      .catch(function (err) {
        loader.innerHTML = '<p style="color:var(--xv-danger);">Error: ' + err.message + '</p>';
      });

    // ── Theme Section ──
    function renderThemeSection(data) {
      var body = document.getElementById('xv-theme-body');
      var step = document.getElementById('xv-step-theme');
      var section = document.getElementById('xv-section-theme');

      if (!data.theme_slug) {
        body.innerHTML = '<div class="xv-kit-empty-msg">No specific theme required — any Elementor-compatible theme will work.</div>';
        step.textContent = '✓';
        step.classList.add('done');
        return;
      }

      var statusHtml = data.theme_active
        ? '<span class="xv-active">✓ Active</span>'
        : '<span class="xv-inactive">Not active — click to install & activate</span>';

      if (data.theme_active) {
        step.textContent = '✓';
        step.classList.add('done');
      }

      body.innerHTML =
        '<div class="xv-theme-row">' +
          '<div class="xv-theme-info">' +
            '<div class="xv-theme-icon">🎨</div>' +
            '<div>' +
              '<div class="xv-theme-name">' + escHtml(data.theme_slug) + '</div>' +
              '<div class="xv-theme-status">' + statusHtml + '</div>' +
            '</div>' +
          '</div>' +
          (data.theme_active
            ? ''
            : '<button type="button" class="xv-btn xv-btn--primary" id="xv-activate-theme-btn">' +
                '<span class="dashicons dashicons-admin-appearance"></span> Install & Activate Theme' +
              '</button>') +
        '</div>';

      if (!data.theme_active) {
        var activateBtn = document.getElementById('xv-activate-theme-btn');
        activateBtn.addEventListener('click', function () {
          activateBtn.disabled = true;
          activateBtn.innerHTML = '<span class="xv-spinner"></span> Installing…';

          var afd = new FormData();
          afd.append('action', 'xvato_activate_theme');
          afd.append('nonce', xvatoAdmin.nonce);
          afd.append('theme', data.theme_slug);

          fetch(xvatoAdmin.ajaxUrl, { method: 'POST', body: afd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
              if (res.success) {
                body.querySelector('.xv-theme-status').innerHTML = '<span class="xv-active">✓ Active — ' + escHtml(res.data.theme) + '</span>';
                activateBtn.remove();
                step.textContent = '✓';
                step.classList.add('done');
              } else {
                activateBtn.disabled = false;
                activateBtn.innerHTML = '<span class="dashicons dashicons-admin-appearance"></span> Retry';
                alert('Theme activation failed: ' + (res.data || 'Unknown error'));
              }
            })
            .catch(function () {
              activateBtn.disabled = false;
              activateBtn.innerHTML = '<span class="dashicons dashicons-admin-appearance"></span> Retry';
            });
        });
      }
    }

    // ── Templates Grid ──
    function renderTemplateGrid(templates) {
      var grid    = document.getElementById('xv-tpl-grid');
      var filters = document.getElementById('xv-tpl-filters');
      var countEl = document.getElementById('xv-tpl-count');

      if (!templates || templates.length === 0) {
        grid.innerHTML = '<div class="xv-kit-empty-msg">No templates found in this kit. The kit may need to be re-imported.</div>';
        return;
      }

      countEl.textContent = '(' + templates.length + ' templates)';

      // Collect unique types for filter buttons
      var types = {};
      templates.forEach(function (t) {
        var tp = (t.type || 'page').toLowerCase();
        types[tp] = (types[tp] || 0) + 1;
      });

      // Render filter buttons
      var filterHtml = '<button class="xv-tpl-filter-btn active" data-filter="all">All (' + templates.length + ')</button>';
      Object.keys(types).sort().forEach(function (tp) {
        filterHtml += '<button class="xv-tpl-filter-btn" data-filter="' + tp + '">' + capitalize(tp) + ' (' + types[tp] + ')</button>';
      });
      filters.innerHTML = filterHtml;

      // Render cards
      var html = '';
      templates.forEach(function (tpl) {
        var imported = tpl.imported ? ' imported' : '';
        var thumbHtml = tpl.thumb
          ? '<img src="' + escAttr(tpl.thumb) + '" alt="" loading="lazy">'
          : '<div class="xv-tpl-no-thumb">📄</div>';

        html +=
          '<div class="xv-tpl-card' + imported + '" data-idx="' + tpl.index + '" data-type="' + escAttr((tpl.type || 'page').toLowerCase()) + '">' +
            '<div class="xv-tpl-check"></div>' +
            '<div class="xv-tpl-thumb">' + thumbHtml + '</div>' +
            '<div class="xv-tpl-body">' +
              '<h4 class="xv-tpl-title" title="' + escAttr(tpl.title) + '">' + escHtml(tpl.title) + '</h4>' +
              '<span class="xv-tpl-type">' + escHtml(tpl.type || 'page') + '</span>' +
            '</div>' +
          '</div>';
      });
      grid.innerHTML = html;

      // Card click → toggle selection
      grid.querySelectorAll('.xv-tpl-card').forEach(function (card) {
        card.addEventListener('click', function () {
          var idx = parseInt(card.getAttribute('data-idx'), 10);
          if (card.classList.contains('imported')) return; // skip already imported

          if (card.classList.contains('selected')) {
            card.classList.remove('selected');
            selectedIdxs.delete(idx);
          } else {
            card.classList.add('selected');
            selectedIdxs.add(idx);
          }
          updateSelectedCount();
        });
      });

      // Select All
      var selectAllTpl = document.getElementById('xv-select-all-tpl');
      if (selectAllTpl) {
        selectAllTpl.addEventListener('change', function () {
          var visibleCards = grid.querySelectorAll('.xv-tpl-card:not(.imported):not([style*="display: none"])');
          visibleCards.forEach(function (card) {
            var idx = parseInt(card.getAttribute('data-idx'), 10);
            if (selectAllTpl.checked) {
              card.classList.add('selected');
              selectedIdxs.add(idx);
            } else {
              card.classList.remove('selected');
              selectedIdxs.delete(idx);
            }
          });
          updateSelectedCount();
        });
      }

      // Filter buttons
      filters.addEventListener('click', function (e) {
        var btn = e.target.closest('.xv-tpl-filter-btn');
        if (!btn) return;

        filters.querySelectorAll('.xv-tpl-filter-btn').forEach(function (b) { b.classList.remove('active'); });
        btn.classList.add('active');

        var filter = btn.getAttribute('data-filter');
        grid.querySelectorAll('.xv-tpl-card').forEach(function (card) {
          if (filter === 'all' || card.getAttribute('data-type') === filter) {
            card.style.display = '';
          } else {
            card.style.display = 'none';
          }
        });
      });
    }

    // ── Colors Section ──
    function renderColorsSection(colors) {
      var body   = document.getElementById('xv-colors-body');
      var applyBtn = document.getElementById('xv-apply-colors');

      if (!colors || (Array.isArray(colors) && colors.length === 0) || Object.keys(colors).length === 0) {
        body.innerHTML = '<div class="xv-kit-empty-msg">No global colours defined in this kit.</div>';
        document.getElementById('xv-section-colors').style.display = 'none';
        return;
      }

      // Normalize colors to array format
      var colorArray = [];
      if (Array.isArray(colors)) {
        colorArray = colors;
      } else {
        Object.keys(colors).forEach(function (key) {
          var c = colors[key];
          colorArray.push({
            id: c._id || c.id || key,
            title: c.title || key,
            color: c.color || c.value || c
          });
        });
      }

      if (colorArray.length === 0) {
        body.innerHTML = '<div class="xv-kit-empty-msg">No global colours defined in this kit.</div>';
        return;
      }

      applyBtn.style.display = '';

      var html = '<div class="xv-colors-grid">';
      colorArray.forEach(function (c) {
        var val = c.color || c.value || '#000';
        var title = c.title || c.id || '';
        html +=
          '<div class="xv-color-swatch">' +
            '<div class="xv-color-preview" style="background:' + escAttr(val) + ';"></div>' +
            '<div>' +
              '<div class="xv-color-label">' + escHtml(title) + '</div>' +
              '<div class="xv-color-value">' + escHtml(val) + '</div>' +
            '</div>' +
          '</div>';
      });
      html += '</div>';
      body.innerHTML = html;

      // Apply colors handler
      applyBtn.addEventListener('click', function () {
        if (!confirm('Apply these global colours to your Elementor kit? This will add them as custom colours.')) return;

        applyBtn.disabled = true;
        applyBtn.innerHTML = '<span class="xv-spinner"></span> Applying…';

        var cfd = new FormData();
        cfd.append('action', 'xvato_apply_colors');
        cfd.append('nonce', xvatoAdmin.nonce);
        colorArray.forEach(function (c, i) {
          cfd.append('colors[' + i + '][id]', c.id || '');
          cfd.append('colors[' + i + '][title]', c.title || '');
          cfd.append('colors[' + i + '][color]', c.color || c.value || '');
        });

        fetch(xvatoAdmin.ajaxUrl, { method: 'POST', body: cfd, credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (res) {
            if (res.success) {
              applyBtn.innerHTML = '<span class="dashicons dashicons-yes"></span> Applied!';
              applyBtn.classList.remove('xv-btn--primary');
              applyBtn.style.color = 'var(--xv-success)';
              applyBtn.style.borderColor = 'var(--xv-success)';
            } else {
              applyBtn.disabled = false;
              applyBtn.innerHTML = '<span class="dashicons dashicons-art"></span> Retry';
              alert('Failed: ' + (res.data || 'Unknown error'));
            }
          })
          .catch(function () {
            applyBtn.disabled = false;
            applyBtn.innerHTML = '<span class="dashicons dashicons-art"></span> Retry';
          });
      });
    }

    // ── Selected Count & Import Button ──
    function updateSelectedCount() {
      var countEl = document.getElementById('xv-selected-count');
      var importBtn = document.getElementById('xv-import-btn');
      countEl.textContent = selectedIdxs.size;
      importBtn.disabled = selectedIdxs.size === 0;
    }

    // ── Import Button Handler ──
    var importBtn = document.getElementById('xv-import-btn');
    if (importBtn) {
      importBtn.addEventListener('click', function () {
        if (selectedIdxs.size === 0) return;

        var createPages = document.getElementById('xv-create-pages').checked;

        importBtn.disabled = true;
        importBtn.innerHTML = '<span class="xv-spinner"></span> Importing…';

        var progress = document.getElementById('xv-import-progress');
        var progressBar = document.getElementById('xv-progress-bar');
        var statusEl = document.getElementById('xv-import-status');
        progress.style.display = '';
        progressBar.style.width = '20%';
        statusEl.textContent = 'Importing ' + selectedIdxs.size + ' template(s)…';

        var ifd = new FormData();
        ifd.append('action', 'xvato_import_selected');
        ifd.append('nonce', xvatoAdmin.nonce);
        ifd.append('kit_id', kitId);
        ifd.append('create_pages', createPages ? '1' : '0');
        var arr = Array.from(selectedIdxs);
        arr.forEach(function (idx) {
          ifd.append('selected[]', idx);
        });

        progressBar.style.width = '40%';

        fetch(xvatoAdmin.ajaxUrl, { method: 'POST', body: ifd, credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (res) {
            progressBar.style.width = '100%';
            if (res.success) {
              var d = res.data;
              statusEl.textContent = 'Import complete! ' + d.imported.length + ' template(s) imported.';
              statusEl.style.color = 'var(--xv-success)';
              importBtn.innerHTML = '<span class="dashicons dashicons-yes"></span> Done!';

              // Show results
              var resultsEl = document.getElementById('xv-import-results');
              resultsEl.style.display = '';

              var html = '';

              if (d.imported.length > 0) {
                html += '<h4 style="margin:0 0 10px;font-size:14px;color:var(--xv-text);">Imported Templates</h4>';
                html += '<ul class="xv-result-list">';
                d.imported.forEach(function (item) {
                  html +=
                    '<li>' +
                      '<span class="xv-result-title"><span class="xv-ok">✓</span> ' + escHtml(item.title) + '</span>' +
                    '</li>';
                });
                html += '</ul>';
              }

              if (d.created_pages && d.created_pages.length > 0) {
                html += '<h4 style="margin:16px 0 10px;font-size:14px;color:var(--xv-text);">Created Pages (Drafts)</h4>';
                html += '<ul class="xv-result-list">';
                d.created_pages.forEach(function (pg) {
                  html +=
                    '<li>' +
                      '<span class="xv-result-title"><span class="xv-ok">✓</span> ' + escHtml(pg.title) + '</span>' +
                      '<span class="xv-result-actions">' +
                        '<a href="' + escAttr(pg.edit_url) + '" target="_blank">Edit with Elementor →</a>' +
                      '</span>' +
                    '</li>';
                });
                html += '</ul>';
              }

              if (d.errors && d.errors.length > 0) {
                html += '<h4 style="margin:16px 0 10px;font-size:14px;color:var(--xv-danger);">Errors</h4>';
                html += '<ul class="xv-result-list">';
                d.errors.forEach(function (err) {
                  html += '<li><span class="xv-result-title"><span class="xv-fail">✗</span> ' + escHtml(err) + '</span></li>';
                });
                html += '</ul>';
              }

              resultsEl.innerHTML = html;

              // Mark imported cards
              d.imported.forEach(function (item) {
                var card = document.querySelector('.xv-tpl-card[data-idx="' + item.index + '"]');
                if (card) {
                  card.classList.remove('selected');
                  card.classList.add('imported');
                  selectedIdxs.delete(item.index);
                }
              });
              updateSelectedCount();

            } else {
              statusEl.textContent = 'Import failed: ' + (res.data || 'Unknown error');
              statusEl.style.color = 'var(--xv-danger)';
              importBtn.disabled = false;
              importBtn.innerHTML = '<span class="dashicons dashicons-download"></span> Retry Import';
            }
          })
          .catch(function (err) {
            progressBar.style.width = '100%';
            progressBar.style.background = 'var(--xv-danger)';
            statusEl.textContent = 'Network error: ' + err.message;
            statusEl.style.color = 'var(--xv-danger)';
            importBtn.disabled = false;
            importBtn.innerHTML = '<span class="dashicons dashicons-download"></span> Retry Import';
          });
      });
    }

    // ── Helpers ──
    function escHtml(s) {
      var d = document.createElement('div');
      d.textContent = s || '';
      return d.innerHTML;
    }

    function escAttr(s) {
      return (s || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function capitalize(s) {
      return s.charAt(0).toUpperCase() + s.slice(1);
    }
  }
  // ─── End Kit Detail ──────────────────────────────────────────

})();

/**
 * Xvato — Download Interceptor (MAIN world)
 *
 * Injected at document_start in the MAIN page world so it can
 * monkey-patch fetch() and XMLHttpRequest before Envato's own
 * scripts run. When Envato initiates a download, the real .zip
 * URL is captured and relayed to the content script via
 * window.postMessage.
 *
 * v0.3: Enhanced for new Envato app (app.envato.com):
 *  - Intercepts GraphQL mutations (download requests)
 *  - Detects response Location headers / redirect URLs
 *  - Handles signed S3/CloudFront URLs in JSON responses
 *
 * Runs on: elements.envato.com, app.envato.com
 */

(function () {
  'use strict';

  const TAG = '[Xvato:Interceptor]';

  // Patterns that indicate a download/asset URL
  const DOWNLOAD_PATTERNS = [
    /\.zip(\?|$)/i,
    /\/download\//i,
    /\/api\/.*download/i,
    /s3.*\.amazonaws\.com.*\.zip/i,
    /s3.*\.amazonaws\.com.*template/i,
    /cloudfront\.net.*\.zip/i,
    /elements-cover.*\.zip/i,
    /content-disposition.*attachment/i,
    /envato-elements.*download/i,
    /\/item\/.*\/download/i,
    /graphql.*download/i,
    /signed.*url.*\.zip/i,
  ];

  // Extra patterns specifically for URLs found in response bodies
  const RESPONSE_URL_PATTERNS = [
    /\.zip(\?|$)/i,
    /s3.*amazonaws/i,
    /cloudfront\.net/i,
    /download.*token/i,
  ];

  /**
   * Check if a URL looks like a download URL.
   */
  function isDownloadUrl(url) {
    if (!url || typeof url !== 'string') return false;
    return DOWNLOAD_PATTERNS.some((pattern) => pattern.test(url));
  }

  /**
   * Check if a URL is a potential download URL found in response.
   */
  function isResponseDownloadUrl(url) {
    if (!url || typeof url !== 'string') return false;
    return RESPONSE_URL_PATTERNS.some((pattern) => pattern.test(url));
  }

  /**
   * Check if a URL is an Envato API call (potential download trigger).
   */
  function isEnvatoApiCall(url) {
    if (!url || typeof url !== 'string') return false;
    return (
      url.includes('envato.com') &&
      (url.includes('/api/') ||
        url.includes('/graphql') ||
        url.includes('/download') ||
        url.includes('/items/'))
    );
  }

  /**
   * Check if a fetch request body contains a download-related GraphQL mutation.
   */
  function isDownloadGraphQL(body) {
    if (!body || typeof body !== 'string') return false;
    try {
      var parsed = JSON.parse(body);
      var query = parsed.query || parsed.operationName || '';
      return (
        /download/i.test(query) ||
        /download/i.test(parsed.operationName || '') ||
        /acquireLicense/i.test(query) ||
        /addToDownloads/i.test(query) ||
        /generateDownloadUrl/i.test(query) ||
        /itemDownload/i.test(query) ||
        /getDownloadLink/i.test(query)
      );
    } catch (e) {
      return /download/i.test(body);
    }
  }

  /**
   * Post a captured URL to the content script (ISOLATED world).
   */
  function relayUrl(url, source) {
    console.log(TAG, 'Captured (' + source + '):', url);
    window.postMessage(
      {
        type: 'XVATO_DOWNLOAD_URL',
        url: url,
        source: source,
        timestamp: Date.now(),
      },
      '*'
    );
  }

  /**
   * Deep-search a JSON structure for download URLs.
   */
  function extractUrlFromJson(data, depth) {
    if (depth === undefined) depth = 0;
    if (depth > 6) return null;
    if (!data || typeof data !== 'object') return null;

    // Check common download URL fields
    var urlFields = [
      'url',
      'download_url',
      'downloadUrl',
      'download',
      'file_url',
      'fileUrl',
      'href',
      'link',
      'signed_url',
      'signedUrl',
      'presigned_url',
      'presignedUrl',
      'location',
      'redirect',
      'redirectUrl',
      'redirect_url',
      'src',
      'path',
      'uri',
      'downloadLink',
      'download_link',
      'directUrl',
      'direct_url',
    ];

    for (var i = 0; i < urlFields.length; i++) {
      var field = urlFields[i];
      if (data[field] && typeof data[field] === 'string') {
        if (isResponseDownloadUrl(data[field]) || data[field].includes('.zip')) {
          return data[field];
        }
      }
    }

    // Recurse into objects/arrays
    if (Array.isArray(data)) {
      for (var j = 0; j < data.length; j++) {
        var found = extractUrlFromJson(data[j], depth + 1);
        if (found) return found;
      }
    } else {
      var keys = Object.keys(data);
      for (var k = 0; k < keys.length; k++) {
        if (typeof data[keys[k]] === 'object') {
          var found2 = extractUrlFromJson(data[keys[k]], depth + 1);
          if (found2) return found2;
        }
        // Also check string values that might be URLs even in non-standard fields
        if (typeof data[keys[k]] === 'string' && data[keys[k]].startsWith('http')) {
          if (isResponseDownloadUrl(data[keys[k]])) {
            return data[keys[k]];
          }
        }
      }
    }

    return null;
  }

  // ─── Monkey-patch fetch() ──────────────────────────────────

  var originalFetch = window.fetch;

  window.fetch = function () {
    var args = arguments;
    var input = args[0];
    var init = args[1] || {};
    var url =
      typeof input === 'string'
        ? input
        : input instanceof Request
          ? input.url
          : String(input);

    var isRelevant = isDownloadUrl(url) || isEnvatoApiCall(url);
    var isGraphQLDownload = false;

    // Check if this is a GraphQL download mutation
    if (url.includes('graphql') || url.includes('/api/')) {
      var bodyStr = null;
      if (init && init.body) {
        bodyStr = typeof init.body === 'string' ? init.body : null;
      }
      if (bodyStr && isDownloadGraphQL(bodyStr)) {
        isGraphQLDownload = true;
        isRelevant = true;
        console.log(TAG, 'GraphQL download mutation detected');
      }
    }

    // If URL itself is a direct download link
    if (isDownloadUrl(url)) {
      relayUrl(url, 'fetch-request');
    }

    return originalFetch.apply(this, args).then(function (response) {
      if (isRelevant) {
        try {
          var clone = response.clone();

          // Check for redirect to a download URL
          if (clone.redirected && isDownloadUrl(clone.url)) {
            relayUrl(clone.url, 'fetch-redirect');
          }

          // Check for Location header
          var locationHeader = clone.headers.get('location');
          if (locationHeader && isDownloadUrl(locationHeader)) {
            relayUrl(locationHeader, 'fetch-location-header');
          }

          // Check Content-Disposition header for attachment
          var contentDisp = clone.headers.get('content-disposition');
          if (contentDisp && contentDisp.includes('attachment')) {
            relayUrl(clone.url || url, 'fetch-attachment');
          }

          // Binary response (direct file download)
          var contentType = clone.headers.get('content-type') || '';
          if (
            contentType.includes('application/zip') ||
            contentType.includes('application/octet-stream')
          ) {
            relayUrl(clone.url || url, 'fetch-binary');
          }

          // Parse JSON response for download URLs (especially GraphQL responses)
          if (contentType.includes('application/json') || isGraphQLDownload) {
            clone
              .json()
              .then(function (json) {
                var downloadUrl = extractUrlFromJson(json);
                if (downloadUrl) {
                  relayUrl(downloadUrl, isGraphQLDownload ? 'fetch-graphql-json' : 'fetch-json');
                }
              })
              .catch(function () {}); // Ignore parse errors
          }
        } catch (e) {
          // Silent — don't break Envato's flow
        }
      }

      return response;
    });
  };

  // ─── Monkey-patch XMLHttpRequest ─────────────────────────

  var originalXhrOpen = XMLHttpRequest.prototype.open;
  var originalXhrSend = XMLHttpRequest.prototype.send;

  XMLHttpRequest.prototype.open = function (method, url) {
    this._xvUrl = typeof url === 'string' ? url : String(url);
    this._xvMethod = method;

    if (isDownloadUrl(this._xvUrl)) {
      relayUrl(this._xvUrl, 'xhr-open');
    }

    return originalXhrOpen.apply(this, arguments);
  };

  XMLHttpRequest.prototype.send = function () {
    var self = this;
    var body = arguments[0];

    var isRelevant = self._xvUrl && (isDownloadUrl(self._xvUrl) || isEnvatoApiCall(self._xvUrl));
    var isGraphQL = body && typeof body === 'string' && isDownloadGraphQL(body);

    if (isRelevant || isGraphQL) {
      self.addEventListener('load', function () {
        try {
          // Check for redirect via responseURL
          if (self.responseURL && isDownloadUrl(self.responseURL)) {
            relayUrl(self.responseURL, 'xhr-redirect');
          }

          // Check Content-Disposition
          var contentDisp = self.getResponseHeader('content-disposition');
          if (contentDisp && contentDisp.includes('attachment')) {
            relayUrl(self.responseURL || self._xvUrl, 'xhr-attachment');
          }

          // Parse JSON responses
          var contentType = self.getResponseHeader('content-type') || '';
          if ((contentType.includes('application/json') || isGraphQL) && self.responseText) {
            try {
              var json = JSON.parse(self.responseText);
              var downloadUrl = extractUrlFromJson(json);
              if (downloadUrl) {
                relayUrl(downloadUrl, isGraphQL ? 'xhr-graphql-json' : 'xhr-json');
              }
            } catch (e) {}
          }

          // Binary download
          if (
            contentType.includes('application/zip') ||
            contentType.includes('application/octet-stream')
          ) {
            relayUrl(self.responseURL || self._xvUrl, 'xhr-binary');
          }
        } catch (e) {}
      });
    }

    return originalXhrSend.apply(self, arguments);
  };

  // ─── Monitor <a> clicks for download links ─────────────────

  document.addEventListener(
    'click',
    function (e) {
      var anchor = e.target.closest('a[href]');
      if (anchor) {
        var href = anchor.href;
        if (isDownloadUrl(href)) {
          relayUrl(href, 'anchor-click');
        }
        if (anchor.hasAttribute('download')) {
          relayUrl(href, 'anchor-download-attr');
        }
      }
    },
    true
  );

  // ─── Monitor window.location changes (download redirects) ──

  var originalAssign = window.location.assign;
  if (originalAssign) {
    window.location.assign = function (url) {
      if (isDownloadUrl(url)) {
        relayUrl(url, 'location-assign');
      }
      return originalAssign.call(this, url);
    };
  }

  // ─── Monitor dynamically-created <a> elements ──────────────

  var originalCreateElement = document.createElement;
  document.createElement = function (tag) {
    var el = originalCreateElement.apply(this, arguments);
    if (typeof tag === 'string' && tag.toLowerCase() === 'a') {
      var origClick = el.click;
      el.click = function () {
        if (this.href && (isDownloadUrl(this.href) || this.hasAttribute('download'))) {
          relayUrl(this.href, 'dynamic-anchor-click');
        }
        return origClick.call(this);
      };
    }
    return el;
  };

  // ─── Monitor window.open for download URLs ────────────────

  var originalWindowOpen = window.open;
  window.open = function (url) {
    if (url && isDownloadUrl(String(url))) {
      relayUrl(String(url), 'window-open');
    }
    return originalWindowOpen.apply(this, arguments);
  };

  // ─── Monitor navigation to blob: URLs (download links) ────

  var origCreateObjectURL = URL.createObjectURL;
  if (origCreateObjectURL) {
    URL.createObjectURL = function (blob) {
      var result = origCreateObjectURL.call(this, blob);
      if (blob && blob.type && (blob.type.includes('zip') || blob.type.includes('octet-stream'))) {
        console.log(TAG, 'Blob URL created for ZIP-like content:', result);
      }
      return result;
    };
  }

  console.log(TAG, 'Download interceptor installed (v0.3).');
})();

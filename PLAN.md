# BridgeKit — Project Plan

> **Status:** In Development
> **Started:** 2026-02-26
> **Architecture:** Chrome Extension (Collector) ↔ WordPress Plugin (Processor)

---

## 1. Problem Statement

Envato discontinued their WordPress Extensions plugin (August 2025). Users who download
Elementor Template Kits from Envato Elements must now manually download ZIPs, extract them,
and import templates one-by-one. BridgeKit automates this entire flow.

---

## 2. Architecture Overview

```
┌─────────────────────────────────┐         ┌──────────────────────────────────┐
│   Chrome Extension (MV3)        │         │   WordPress Plugin (PHP 8.0+)    │
│   "The Collector"               │         │   "The Processor"                │
│                                 │         │                                  │
│  Content Script:                │  POST   │  REST API:                       │
│  • MutationObserver on Envato   │────────▶│  • POST /bridgekit/v1/import     │
│  • Injects "Send to WP" button  │         │  • GET  /bridgekit/v1/library    │
│  • Captures title, thumb, URL   │         │  • GET  /bridgekit/v1/status/:id │
│                                 │         │                                  │
│  Service Worker:                │         │  Importer:                       │
│  • Relays messages to WP        │         │  • Downloads ZIP server-side     │
│  • Shows notifications          │         │  • Extracts via WP_Filesystem    │
│                                 │         │  • Imports via Elementor CLI     │
│  Popup:                         │         │                                  │
│  • WP site URL config           │         │  Library (CPT):                  │
│  • Application Password entry   │         │  • bk_template_library           │
│  • Connection test button       │         │  • Search, filter, re-import     │
│                                 │         │  • Dependency checker            │
└─────────────────────────────────┘         └──────────────────────────────────┘
```

---

## 3. Tech Decisions

| Decision | Choice | Rationale |
|---|---|---|
| Extension manifest | Manifest V3 | Required for Chrome Web Store |
| Auth mechanism | WP Application Passwords | Built-in since WP 5.6, zero custom code |
| File transfer | URL forwarding (not blob streaming) | MV3 service workers can't stream large files |
| Elementor import | WP-CLI `wp elementor kit import` | Only stable, documented API |
| Elementor fallback | Internal API with version gate | For hosts without WP-CLI |
| Admin UI (MVP) | Native WP admin + Alpine.js | No build step required |
| Admin UI (v2) | @wordpress/scripts + @wordpress/components | Native WP look and feel |
| ZIP handling | WP native `unzip_file()` | Uses ZipArchive with PclZip fallback |
| Dependencies | Zero external (no Composer) | Simplifies deployment |
| Background processing | `wp_schedule_single_event()` | Async imports for large kits |

---

## 4. File Structure

### Chrome Extension (`bridgekit-extension/`)
```
bridgekit-extension/
├── manifest.json
├── icons/
│   ├── icon-16.png
│   ├── icon-48.png
│   └── icon-128.png
├── src/
│   ├── background/
│   │   └── sw.js                  # Service worker: messaging + WP POST
│   ├── content/
│   │   ├── envato-hooks.js        # MutationObserver + button injection
│   │   └── styles.css             # Injected button styles
│   ├── popup/
│   │   ├── popup.html             # Settings UI
│   │   ├── popup.js               # Settings logic
│   │   └── popup.css              # Popup styles
│   └── lib/
│       └── api.js                 # Shared WP API helper
```

### WordPress Plugin (`bridgekit-plugin/`)
```
bridgekit-plugin/
├── bridgekit.php                   # Plugin header + bootstrap
├── readme.txt                      # WP plugin readme
├── includes/
│   ├── class-bridgekit.php         # Main plugin singleton
│   ├── class-rest-api.php          # REST endpoint registration
│   ├── class-importer.php          # ZIP download + extract + Elementor import
│   ├── class-library.php           # CPT + taxonomy + metadata
│   ├── class-security.php          # Request validation + rate limiting
│   ├── class-downloader.php        # Remote file download via wp_remote_get
│   └── class-admin.php            # Admin pages + asset enqueue
├── admin/
│   ├── views/
│   │   ├── dashboard.php           # Library grid view
│   │   └── settings.php            # Connection settings page
│   ├── css/
│   │   └── admin.css               # Admin styles
│   └── js/
│       └── admin.js                # Alpine.js powered interactivity
└── assets/
    └── placeholder.png             # Fallback thumbnail
```

---

## 5. Implementation Phases

### Phase 1: Foundation (Current Sprint)
- [x] Project structure + git init
- [x] Chrome Extension: manifest.json + content script (alert on Envato pages)
- [x] Chrome Extension: popup with WP URL + API key fields
- [x] Chrome Extension: service worker message relay
- [x] WP Plugin: plugin header + activation hooks
- [x] WP Plugin: REST API endpoint (accepts test JSON, logs it)
- [x] WP Plugin: Security class (Application Password validation)
- [ ] End-to-end test: Extension → WP REST → logged response

### Phase 2: The Bridge
- [x] Content script: MutationObserver for download buttons
- [x] Content script: Inject "Send to WP" button
- [x] Content script: Capture title, thumbnail, download URL
- [x] Service worker: POST metadata to WP REST endpoint
- [x] WP Plugin: Downloader class (wp_remote_get with cookies/auth)
- [x] WP Plugin: Download ZIP from forwarded URL
- [ ] End-to-end test: Click button → file arrives on WP server

### Phase 3: The Processor
- [x] WP Plugin: Importer class (unzip_file + directory management)
- [x] WP Plugin: Parse manifest.json from Template Kit
- [x] WP Plugin: Elementor import via WP-CLI
- [x] WP Plugin: Fallback import via Elementor internal API
- [x] WP Plugin: CPT registration (bk_template_library)
- [x] WP Plugin: Save import results to library
- [x] Background processing queue for large imports

### Phase 4: The Library
- [x] Admin dashboard: grid view of imported templates
- [x] Search + filter by title, category, date
- [x] Status badges (imported, failed, pending)
- [x] Re-import button (from stored ZIP)
- [x] Dependency checker (Elementor version, Pro, theme)
- [x] Bulk actions (delete, re-import)

### Phase 5: Polish
- [x] Error handling + user-facing error messages
- [x] Rate limiting (10 imports/5-minute window)
- [x] Temp directory cleanup cron
- [x] Extension notifications (import success/failure)
- [x] Settings page with system diagnostics
- [x] Maintenance tools (clear tmp, reset failed)
- [x] Settings export/import
- [x] Multi-site compatibility (network activation + new site hooks)

---

## 6. Security Checklist

- [x] Application Password validation on every REST request
- [x] `current_user_can('manage_options')` permission check
- [x] File type validation (only .zip accepted)
- [x] Scan extracted files for .php (flag as warning)
- [x] Nonce verification on admin actions
- [x] Rate limiting on import endpoint
- [x] Temp directory outside webroot or .htaccess protected
- [x] Sanitize all input with WP sanitization functions
- [x] Escape all output with WP escaping functions

---

## 7. Commands Reference

```bash
# Git
git add -A && git commit -m "message"

# Load extension in Chrome
# chrome://extensions → Developer Mode → Load Unpacked → select bridgekit-extension/

# WP Plugin
# Symlink or copy bridgekit-plugin/ to wp-content/plugins/bridgekit/
# Activate in WP Admin → Plugins

# Elementor CLI (on WP server)
wp elementor kit import path/to/kit.zip
wp elementor library import path/to/template.json
```

---

## 8. Risk Register

| Risk | Impact | Mitigation |
|---|---|---|
| Envato changes DOM structure | Extension buttons break | Use resilient selectors + MutationObserver |
| Envato download URLs expire quickly | Import fails | WP downloads immediately on receive |
| Elementor changes internal API | Import breaks | Primary: WP-CLI (stable). Fallback: version-gated |
| Large ZIP files timeout | Import incomplete | Background processing + chunked extraction |
| Host doesn't have WP-CLI | CLI import fails | PHP-only fallback using Elementor classes |
| ToS concerns | Account risk | User initiates every action; we just automate the manual flow |

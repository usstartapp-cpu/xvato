=== BridgeKit — Envato to WordPress Importer ===
Contributors: bridgekit
Tags: envato, elementor, template-kit, import, elements
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Import Envato Elements Template Kits into WordPress with one click using the BridgeKit Chrome Extension.

== Description ==

**BridgeKit** bridges the gap between Envato Elements and your WordPress site. After Envato discontinued their official WordPress plugin, importing Template Kits became a tedious manual process. BridgeKit automates the entire workflow.

= How It Works =

1. **Install the Chrome Extension** — Adds a "Send to WordPress" button on Envato Elements pages
2. **Configure your connection** — Enter your WordPress URL and Application Password in the extension popup
3. **Click to import** — The extension sends template metadata to your WordPress site
4. **Automatic processing** — The plugin downloads the ZIP, extracts it, and imports templates into Elementor

= Features =

* One-click import from Envato Elements
* Automatic Elementor template import (WP-CLI or internal API)
* Template Library with search, filter, and status tracking
* Manual ZIP upload support
* Background processing for large template kits
* Re-import capability (keeps ZIPs for later)
* Dependency detection (Elementor Pro, themes)
* Security: rate limiting, file validation, directory protection
* System diagnostics dashboard

= Requirements =

* WordPress 6.0+
* PHP 8.0+
* Elementor (Free or Pro)
* HTTPS (required for Application Passwords)
* BridgeKit Chrome Extension

== Installation ==

1. Upload the `bridgekit` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to **BridgeKit → Settings** for setup instructions
4. Generate an Application Password at **Users → Profile**
5. Install the BridgeKit Chrome Extension
6. Configure the extension with your site URL and credentials

== Frequently Asked Questions ==

= Do I need Elementor Pro? =

No, but some Template Kits require Elementor Pro features. BridgeKit will import what it can and flag Pro-only templates.

= Is this official Envato software? =

No. BridgeKit is an independent tool. It does not bypass any Envato licensing or access controls.

= Why do I need an Application Password? =

Application Passwords are a secure WordPress authentication method (built-in since WP 5.6) that allows the Chrome Extension to communicate with your site's REST API without sharing your main password.

= Can I import without the Chrome Extension? =

Yes! You can manually upload ZIP files via the BridgeKit dashboard in WordPress admin.

= What happens if an import fails? =

Failed imports are tracked in the Library with error details. You can reset and retry them from the Settings page.

== Changelog ==

= 0.1.0 =
* Initial release
* Chrome Extension with Envato Elements integration
* WordPress REST API for receiving imports
* Elementor template import (WP-CLI + internal API fallback)
* Template Library with grid view
* Settings page with system diagnostics
* Security: rate limiting, file validation, nonce verification

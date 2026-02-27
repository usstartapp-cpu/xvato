<?php
/**
 * Plugin Name:       BridgeKit — Envato to WordPress Importer
 * Plugin URI:        https://github.com/yourusername/bridgekit
 * Description:       Receives Envato Elements Template Kits from the BridgeKit Chrome Extension and imports them into Elementor.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            BridgeKit
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       bridgekit
 */

defined( 'ABSPATH' ) || exit;

// ─── Constants ─────────────────────────────────────────────────
define( 'BRIDGEKIT_VERSION', '0.1.0' );
define( 'BRIDGEKIT_FILE', __FILE__ );
define( 'BRIDGEKIT_DIR', plugin_dir_path( __FILE__ ) );
define( 'BRIDGEKIT_URL', plugin_dir_url( __FILE__ ) );
define( 'BRIDGEKIT_SLUG', 'bridgekit' );
define( 'BRIDGEKIT_CPT', 'bk_template_library' );
define( 'BRIDGEKIT_TMP_DIR', 'bridgekit-tmp' );
define( 'BRIDGEKIT_REST_NAMESPACE', 'bridgekit/v1' );

// ─── Autoloader ────────────────────────────────────────────────
spl_autoload_register( function ( $class ) {
    $prefix = 'BridgeKit\\';
    if ( strpos( $class, $prefix ) !== 0 ) {
        return;
    }

    $relative = str_replace( $prefix, '', $class );
    $file     = BRIDGEKIT_DIR . 'includes/class-' . strtolower( str_replace( '\\', '-', $relative ) ) . '.php';

    if ( file_exists( $file ) ) {
        require_once $file;
    }
});

// ─── Bootstrap ─────────────────────────────────────────────────
function bridgekit_init() {
    $plugin = BridgeKit\BridgeKit::instance();
    $plugin->boot();
}
add_action( 'plugins_loaded', 'bridgekit_init' );

// ─── Activation / Deactivation ─────────────────────────────────
register_activation_hook( __FILE__, function () {
    // Create tmp directory
    $upload_dir = wp_upload_dir();
    $tmp_dir    = trailingslashit( $upload_dir['basedir'] ) . BRIDGEKIT_TMP_DIR;

    if ( ! file_exists( $tmp_dir ) ) {
        wp_mkdir_p( $tmp_dir );
        // Protect with .htaccess
        file_put_contents( $tmp_dir . '/.htaccess', "Deny from all\n" );
        // Add index.php for extra safety
        file_put_contents( $tmp_dir . '/index.php', "<?php // Silence is golden.\n" );
    }

    // Flush rewrite rules for CPT
    BridgeKit\Library::register_post_type();
    flush_rewrite_rules();

    // Store activation time for rate limiting
    update_option( 'bridgekit_activated', time() );
});

register_deactivation_hook( __FILE__, function () {
    flush_rewrite_rules();
    // Don't delete tmp dir on deactivation — user might reactivate
});

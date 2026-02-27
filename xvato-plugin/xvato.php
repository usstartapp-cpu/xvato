<?php
/**
 * Plugin Name:       Xvato — Envato to WordPress Importer
 * Plugin URI:        https://github.com/yourusername/xvato
 * Description:       Receives Envato Elements Template Kits from the Xvato Chrome Extension and imports them into Elementor.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Xvato
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       xvato
 */

defined( 'ABSPATH' ) || exit;

// ─── Constants ─────────────────────────────────────────────────
define( 'XVATO_VERSION', '1.0.0' );
define( 'XVATO_FILE', __FILE__ );
define( 'XVATO_DIR', plugin_dir_path( __FILE__ ) );
define( 'XVATO_URL', plugin_dir_url( __FILE__ ) );
define( 'XVATO_SLUG', 'xvato' );
define( 'XVATO_CPT', 'xv_template_library' );
define( 'XVATO_TMP_DIR', 'xvato-tmp' );
define( 'XVATO_REST_NAMESPACE', 'xvato/v1' );

// ─── Autoloader ────────────────────────────────────────────────
spl_autoload_register( function ( $class ) {
    $prefix = 'Xvato\\';
    if ( strpos( $class, $prefix ) !== 0 ) {
        return;
    }

    $relative = str_replace( $prefix, '', $class );
    $file     = XVATO_DIR . 'includes/class-' . strtolower( str_replace( '\\', '-', $relative ) ) . '.php';

    if ( file_exists( $file ) ) {
        require_once $file;
    }
});

// ─── Bootstrap ─────────────────────────────────────────────────
function xvato_init() {
    $plugin = Xvato\Xvato::instance();
    $plugin->boot();
}
add_action( 'plugins_loaded', 'xvato_init' );

// ─── Activation / Deactivation ─────────────────────────────────
register_activation_hook( __FILE__, function ( $network_wide ) {
    if ( is_multisite() && $network_wide ) {
        $sites = get_sites( [ 'fields' => 'ids', 'number' => 0 ] );
        foreach ( $sites as $site_id ) {
            switch_to_blog( $site_id );
            xvato_activate_site();
            restore_current_blog();
        }
    } else {
        xvato_activate_site();
    }
});

/**
 * Per-site activation tasks.
 */
function xvato_activate_site() {
    $upload_dir = wp_upload_dir();
    $tmp_dir    = trailingslashit( $upload_dir['basedir'] ) . XVATO_TMP_DIR;

    if ( ! file_exists( $tmp_dir ) ) {
        wp_mkdir_p( $tmp_dir );
        file_put_contents( $tmp_dir . '/.htaccess', "Deny from all\n" );
        file_put_contents( $tmp_dir . '/index.php', "<?php // Silence is golden.\n" );
    }

    // Migrate old CPT slug and meta prefix (BridgeKit → Xvato)
    xvato_maybe_migrate_legacy_data();

    Xvato\Library::register_post_type();
    flush_rewrite_rules();

    update_option( 'xvato_activated', time() );
}

/**
 * Migrate legacy BridgeKit data (CPT slug `bk_template_library` → `xv_template_library`,
 * meta key prefix `_bk_` → `_xv_`).  Runs once on activation.
 */
function xvato_maybe_migrate_legacy_data() {
    global $wpdb;

    if ( get_option( 'xvato_migrated_legacy', false ) ) {
        return;
    }

    // 1. Rename the CPT slug in wp_posts
    $wpdb->update(
        $wpdb->posts,
        [ 'post_type' => 'xv_template_library' ],
        [ 'post_type' => 'bk_template_library' ]
    );

    // 2. Rename meta keys: _bk_* → _xv_*
    $old_keys = $wpdb->get_col(
        "SELECT DISTINCT meta_key FROM {$wpdb->postmeta} WHERE meta_key LIKE '_xv_%'"
    );

    foreach ( $old_keys as $old_key ) {
        $new_key = '_xv_' . substr( $old_key, 4 ); // strip '_xv_', prepend '_xv_'
        $wpdb->update(
            $wpdb->postmeta,
            [ 'meta_key' => $new_key ],
            [ 'meta_key' => $old_key ]
        );
    }

    update_option( 'xvato_migrated_legacy', true );
}

add_action( 'wp_initialize_site', function ( $new_site ) {
    if ( ! is_plugin_active_for_network( plugin_basename( __FILE__ ) ) ) {
        return;
    }
    switch_to_blog( $new_site->blog_id );
    xvato_activate_site();
    restore_current_blog();
}, 10, 1 );

register_deactivation_hook( __FILE__, function () {
    flush_rewrite_rules();
});

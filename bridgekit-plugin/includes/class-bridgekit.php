<?php
/**
 * BridgeKit — Main Plugin Class (Singleton)
 *
 * Boots all components: REST API, Library CPT, Security, Admin, Importer.
 *
 * @package BridgeKit
 */

namespace BridgeKit;

defined( 'ABSPATH' ) || exit;

final class BridgeKit {

    private static ?BridgeKit $instance = null;
    private bool $booted = false;

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /**
     * Boot all plugin components.
     */
    public function boot(): void {
        if ( $this->booted ) {
            return;
        }
        $this->booted = true;

        // Register Custom Post Type
        Library::init();

        // Register REST API endpoints
        add_action( 'rest_api_init', [ RestAPI::class, 'register_routes' ] );

        // Add CORS headers for Chrome extension requests
        add_action( 'rest_api_init', [ self::class, 'add_cors_headers' ] );

        // Admin pages (only in admin context)
        if ( is_admin() ) {
            Admin::init();
        }

        // Schedule cleanup cron
        $this->schedule_cleanup();
    }

    /**
     * Add CORS headers to allow Chrome extension access to the REST API.
     */
    public static function add_cors_headers(): void {
        // Allow requests from Chrome extensions
        remove_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' );
        add_filter( 'rest_pre_serve_request', function ( $value ) {
            $origin = get_http_origin();

            // Allow Chrome extension origins and any origin for our specific endpoints
            header( 'Access-Control-Allow-Origin: *' );
            header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
            header( 'Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-Nonce' );
            header( 'Access-Control-Allow-Credentials: true' );
            header( 'Access-Control-Expose-Headers: X-WP-Total, X-WP-TotalPages' );

            return $value;
        });
    }

    /**
     * Schedule hourly cleanup of temp directory.
     */
    private function schedule_cleanup(): void {
        add_action( 'bridgekit_cleanup_tmp', [ $this, 'cleanup_tmp_directory' ] );

        if ( ! wp_next_scheduled( 'bridgekit_cleanup_tmp' ) ) {
            wp_schedule_event( time(), 'hourly', 'bridgekit_cleanup_tmp' );
        }
    }

    /**
     * Delete temp files older than 1 hour.
     */
    public function cleanup_tmp_directory(): void {
        $upload_dir = wp_upload_dir();
        $tmp_dir    = trailingslashit( $upload_dir['basedir'] ) . BRIDGEKIT_TMP_DIR;

        if ( ! is_dir( $tmp_dir ) ) {
            return;
        }

        $files = glob( $tmp_dir . '/*' );
        $now   = time();

        foreach ( $files as $file ) {
            if ( is_file( $file ) && basename( $file ) !== '.htaccess' && basename( $file ) !== 'index.php' ) {
                if ( $now - filemtime( $file ) > HOUR_IN_SECONDS ) {
                    wp_delete_file( $file );
                }
            }
            if ( is_dir( $file ) && basename( $file ) !== '.' && basename( $file ) !== '..' ) {
                if ( $now - filemtime( $file ) > HOUR_IN_SECONDS ) {
                    self::delete_directory( $file );
                }
            }
        }
    }

    /**
     * Recursively delete a directory.
     */
    public static function delete_directory( string $dir ): bool {
        if ( ! is_dir( $dir ) ) {
            return false;
        }

        $items = array_diff( scandir( $dir ), [ '.', '..' ] );
        foreach ( $items as $item ) {
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir( $path ) ? self::delete_directory( $path ) : wp_delete_file( $path );
        }

        return rmdir( $dir );
    }
}

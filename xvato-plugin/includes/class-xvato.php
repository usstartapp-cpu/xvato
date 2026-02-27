<?php
/**
 * Xvato — Main Plugin Class (Singleton)
 *
 * Boots all components: REST API, Library CPT, Security, Admin, Importer.
 *
 * @package Xvato
 */

namespace Xvato;

defined( 'ABSPATH' ) || exit;

final class Xvato {

    private static ?Xvato $instance = null;
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

        // Handle CORS preflight (OPTIONS) requests early — before WP processes them
        add_action( 'init', [ self::class, 'handle_preflight' ] );

        // Fix Authorization header being stripped by Apache/hosting
        add_action( 'init', [ self::class, 'fix_auth_header' ] );

        // Admin pages (only in admin context)
        if ( is_admin() ) {
            Admin::init();
        }

        // Schedule cleanup cron
        $this->schedule_cleanup();
    }

    /**
     * Fix Authorization header being stripped by Apache/shared hosting.
     * Many hosts strip the Authorization header before PHP sees it.
     */
    public static function fix_auth_header(): void {
        // If Authorization header is missing, try to recover it
        if ( ! isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
            // Check for Apache mod_rewrite pass-through
            if ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
                $_SERVER['HTTP_AUTHORIZATION'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
            }
            // Check for CGI/FastCGI workaround via .htaccess
            elseif ( isset( $_SERVER['PHP_AUTH_USER'] ) && isset( $_SERVER['PHP_AUTH_PW'] ) ) {
                $_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode( $_SERVER['PHP_AUTH_USER'] . ':' . $_SERVER['PHP_AUTH_PW'] );
            }
            // Check if passed via query string (fallback)
            elseif ( function_exists( 'getallheaders' ) ) {
                $headers = getallheaders();
                foreach ( $headers as $name => $value ) {
                    if ( strtolower( $name ) === 'authorization' ) {
                        $_SERVER['HTTP_AUTHORIZATION'] = $value;
                        break;
                    }
                }
            }
        }
    }

    /**
     * Handle CORS preflight OPTIONS requests early.
     * Chrome extension service workers send a preflight before authenticated requests.
     */
    public static function handle_preflight(): void {
        // Only intercept OPTIONS requests to the REST API
        if ( isset( $_SERVER['REQUEST_METHOD'] ) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS' ) {
            $request_uri = $_SERVER['REQUEST_URI'] ?? '';
            if ( strpos( $request_uri, '/wp-json/xvato/' ) !== false || strpos( $request_uri, 'rest_route=/xvato/' ) !== false ) {
                $origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
                header( 'Access-Control-Allow-Origin: ' . $origin );
                header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
                header( 'Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-Nonce' );
                header( 'Access-Control-Allow-Credentials: true' );
                header( 'Access-Control-Max-Age: 86400' );
                header( 'Vary: Origin' );
                header( 'Content-Length: 0' );
                header( 'Content-Type: text/plain' );
                status_header( 200 );
                exit;
            }
        }
    }

    /**
     * Add CORS headers to allow Chrome extension access to the REST API.
     */
    public static function add_cors_headers(): void {
        // Allow requests from Chrome extensions
        remove_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' );
        add_filter( 'rest_pre_serve_request', function ( $value ) {
            $origin = $_SERVER['HTTP_ORIGIN'] ?? '*';

            // Echo back the requesting origin (required when using credentials)
            header( 'Access-Control-Allow-Origin: ' . $origin );
            header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
            header( 'Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-Nonce' );
            header( 'Access-Control-Allow-Credentials: true' );
            header( 'Access-Control-Expose-Headers: X-WP-Total, X-WP-TotalPages' );
            header( 'Vary: Origin' );

            return $value;
        });

        // Ensure Application Passwords work for REST API requests
        add_filter( 'application_password_is_api_request', '__return_true' );
    }

    /**
     * Schedule hourly cleanup of temp directory.
     */
    private function schedule_cleanup(): void {
        add_action( 'xvato_cleanup_tmp', [ $this, 'cleanup_tmp_directory' ] );

        if ( ! wp_next_scheduled( 'xvato_cleanup_tmp' ) ) {
            wp_schedule_event( time(), 'hourly', 'xvato_cleanup_tmp' );
        }
    }

    /**
     * Delete temp files older than 1 hour.
     */
    public function cleanup_tmp_directory(): void {
        $upload_dir = wp_upload_dir();
        $tmp_dir    = trailingslashit( $upload_dir['basedir'] ) . XVATO_TMP_DIR;

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

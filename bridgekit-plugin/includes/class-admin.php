<?php
/**
 * BridgeKit — Admin Pages
 *
 * Registers WP Admin menu pages and enqueues assets.
 *
 * @package BridgeKit
 */

namespace BridgeKit;

defined( 'ABSPATH' ) || exit;

class Admin {

    /**
     * Initialize admin hooks.
     */
    public static function init(): void {
        add_action( 'admin_menu', [ self::class, 'register_menus' ] );
        add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ] );

        // Handle manual file upload
        add_action( 'admin_post_bridgekit_upload', [ self::class, 'handle_manual_upload' ] );

        // Handle re-import
        add_action( 'admin_post_bridgekit_reimport', [ self::class, 'handle_reimport' ] );

        // Handle delete
        add_action( 'admin_post_bridgekit_delete', [ self::class, 'handle_delete' ] );

        // Handle maintenance actions
        add_action( 'admin_post_bridgekit_clear_tmp', [ self::class, 'handle_clear_tmp' ] );
        add_action( 'admin_post_bridgekit_reset_failed', [ self::class, 'handle_reset_failed' ] );

        // Handle bulk actions
        add_action( 'admin_post_bridgekit_bulk', [ self::class, 'handle_bulk_action' ] );

        // Handle settings export/import
        add_action( 'admin_post_bridgekit_export_settings', [ self::class, 'handle_export_settings' ] );
        add_action( 'admin_post_bridgekit_import_settings', [ self::class, 'handle_import_settings' ] );
    }

    /**
     * Register admin menu pages.
     */
    public static function register_menus(): void {
        add_menu_page(
            __( 'BridgeKit', 'bridgekit' ),
            __( 'BridgeKit', 'bridgekit' ),
            'manage_options',
            'bridgekit',
            [ self::class, 'render_dashboard' ],
            'dashicons-download',
            65
        );

        add_submenu_page(
            'bridgekit',
            __( 'Template Library', 'bridgekit' ),
            __( 'Library', 'bridgekit' ),
            'manage_options',
            'bridgekit',
            [ self::class, 'render_dashboard' ]
        );

        add_submenu_page(
            'bridgekit',
            __( 'BridgeKit Settings', 'bridgekit' ),
            __( 'Settings', 'bridgekit' ),
            'manage_options',
            'bridgekit-settings',
            [ self::class, 'render_settings' ]
        );
    }

    /**
     * Enqueue admin CSS and JS.
     */
    public static function enqueue_assets( string $hook ): void {
        // Only on our pages
        if ( ! str_contains( $hook, 'bridgekit' ) ) {
            return;
        }

        wp_enqueue_style(
            'bridgekit-admin',
            BRIDGEKIT_URL . 'admin/css/admin.css',
            [],
            BRIDGEKIT_VERSION
        );

        wp_enqueue_script(
            'bridgekit-admin',
            BRIDGEKIT_URL . 'admin/js/admin.js',
            [],
            BRIDGEKIT_VERSION,
            true
        );

        // Pass data to JS
        wp_localize_script( 'bridgekit-admin', 'bridgekitAdmin', [
            'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
            'restUrl'   => rest_url( BRIDGEKIT_REST_NAMESPACE ),
            'nonce'     => wp_create_nonce( 'bridgekit_admin' ),
            'restNonce' => wp_create_nonce( 'wp_rest' ),
        ] );
    }

    /**
     * Render the main dashboard (library grid).
     */
    public static function render_dashboard(): void {
        include BRIDGEKIT_DIR . 'admin/views/dashboard.php';
    }

    /**
     * Render the settings page.
     */
    public static function render_settings(): void {
        include BRIDGEKIT_DIR . 'admin/views/settings.php';
    }

    // ─── Action Handlers ──────────────────────────────────────

    /**
     * Handle manual ZIP upload via the dashboard form.
     */
    public static function handle_manual_upload(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Unauthorized.', 'bridgekit' ) );
        }

        check_admin_referer( 'bridgekit_upload', 'bridgekit_nonce' );

        if ( empty( $_FILES['bridgekit_zip'] ) ) {
            wp_redirect( admin_url( 'admin.php?page=bridgekit&error=no_file' ) );
            exit;
        }

        $title = sanitize_text_field( $_POST['bridgekit_title'] ?? 'Manual Upload' );

        // Save the uploaded file
        $zip_path = Downloader::from_upload( $_FILES['bridgekit_zip'] );
        if ( is_wp_error( $zip_path ) ) {
            wp_redirect( admin_url( 'admin.php?page=bridgekit&error=' . urlencode( $zip_path->get_error_message() ) ) );
            exit;
        }

        // Validate it's a ZIP
        if ( ! Security::is_valid_zip( $zip_path ) ) {
            wp_delete_file( $zip_path );
            wp_redirect( admin_url( 'admin.php?page=bridgekit&error=invalid_zip' ) );
            exit;
        }

        // Create library entry
        $post_id = Library::create_entry( [
            'title'        => $title,
            'download_url' => '',
            'source_url'   => '',
            'category'     => sanitize_text_field( $_POST['bridgekit_category'] ?? '' ),
        ] );

        if ( is_wp_error( $post_id ) ) {
            wp_redirect( admin_url( 'admin.php?page=bridgekit&error=' . urlencode( $post_id->get_error_message() ) ) );
            exit;
        }

        // Store zip path and process
        update_post_meta( $post_id, '_bk_zip_path', $zip_path );

        $importer = new Importer();
        $importer->process_zip( $post_id, $zip_path );

        wp_redirect( admin_url( 'admin.php?page=bridgekit&imported=' . $post_id ) );
        exit;
    }

    /**
     * Handle re-import of a previously imported template.
     */
    public static function handle_reimport(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Unauthorized.', 'bridgekit' ) );
        }

        check_admin_referer( 'bridgekit_reimport', 'bridgekit_nonce' );

        $post_id = absint( $_GET['id'] ?? 0 );
        if ( ! $post_id ) {
            wp_redirect( admin_url( 'admin.php?page=bridgekit&error=invalid_id' ) );
            exit;
        }

        $zip_path = get_post_meta( $post_id, '_bk_zip_path', true );
        if ( ! $zip_path || ! file_exists( $zip_path ) ) {
            // Try re-downloading
            $download_url = get_post_meta( $post_id, '_bk_download_url', true );
            if ( $download_url ) {
                Library::update_status( $post_id, 'pending' );
                wp_schedule_single_event( time(), 'bridgekit_process_import', [ $post_id, $download_url ] );
                spawn_cron();
                wp_redirect( admin_url( 'admin.php?page=bridgekit&reimport=queued' ) );
                exit;
            }

            wp_redirect( admin_url( 'admin.php?page=bridgekit&error=no_zip' ) );
            exit;
        }

        // Reset status and re-process
        Library::update_status( $post_id, 'pending' );
        update_post_meta( $post_id, '_bk_import_error', '' );
        update_post_meta( $post_id, '_bk_import_log', [] );

        $importer = new Importer();
        $importer->process_zip( $post_id, $zip_path );

        wp_redirect( admin_url( 'admin.php?page=bridgekit&reimport=' . $post_id ) );
        exit;
    }

    /**
     * Handle deletion of a library entry.
     */
    public static function handle_delete(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Unauthorized.', 'bridgekit' ) );
        }

        check_admin_referer( 'bridgekit_delete', 'bridgekit_nonce' );

        $post_id = absint( $_GET['id'] ?? 0 );
        if ( ! $post_id ) {
            wp_redirect( admin_url( 'admin.php?page=bridgekit&error=invalid_id' ) );
            exit;
        }

        // Delete stored ZIP
        $zip_path = get_post_meta( $post_id, '_bk_zip_path', true );
        if ( $zip_path && file_exists( $zip_path ) ) {
            wp_delete_file( $zip_path );
        }

        // Delete the post and its meta
        wp_delete_post( $post_id, true );

        wp_redirect( admin_url( 'admin.php?page=bridgekit&deleted=1' ) );
        exit;
    }

    /**
     * Handle clearing temporary files.
     */
    public static function handle_clear_tmp(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Unauthorized.', 'bridgekit' ) );
        }

        check_admin_referer( 'bridgekit_clear_tmp', 'bridgekit_nonce' );

        $plugin = BridgeKit::instance();
        $plugin->cleanup_tmp_directory();

        // Also force-clean everything (including recent files)
        $upload_dir = wp_upload_dir();
        $tmp_dir    = trailingslashit( $upload_dir['basedir'] ) . BRIDGEKIT_TMP_DIR;

        if ( is_dir( $tmp_dir ) ) {
            $files = glob( $tmp_dir . '/*' );
            foreach ( $files as $file ) {
                if ( is_file( $file ) && ! in_array( basename( $file ), [ '.htaccess', 'index.php' ], true ) ) {
                    wp_delete_file( $file );
                }
                if ( is_dir( $file ) && ! in_array( basename( $file ), [ '.', '..' ], true ) ) {
                    BridgeKit::delete_directory( $file );
                }
            }
        }

        wp_redirect( admin_url( 'admin.php?page=bridgekit-settings&cleared=1' ) );
        exit;
    }

    /**
     * Handle resetting all failed imports to pending status.
     */
    public static function handle_reset_failed(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Unauthorized.', 'bridgekit' ) );
        }

        check_admin_referer( 'bridgekit_reset_failed', 'bridgekit_nonce' );

        $failed_posts = get_posts( [
            'post_type'      => BRIDGEKIT_CPT,
            'posts_per_page' => -1,
            'post_status'    => 'any',
            'meta_query'     => [
                [
                    'key'   => '_bk_import_status',
                    'value' => 'failed',
                ],
            ],
            'fields' => 'ids',
        ] );

        $count = 0;
        foreach ( $failed_posts as $post_id ) {
            Library::update_status( $post_id, 'pending' );
            update_post_meta( $post_id, '_bk_import_error', '' );
            Library::add_log( $post_id, 'Status reset to pending by admin.' );

            // Re-queue if we have a download URL
            $download_url = get_post_meta( $post_id, '_bk_download_url', true );
            if ( $download_url ) {
                wp_schedule_single_event( time() + $count, 'bridgekit_process_import', [ $post_id, $download_url ] );
            }

            $count++;
        }

        if ( $count > 0 ) {
            spawn_cron();
        }

        wp_redirect( admin_url( 'admin.php?page=bridgekit-settings&reset=' . $count ) );
        exit;
    }

    // ─── Bulk Actions ─────────────────────────────────────────

    /**
     * Handle bulk delete or re-import from the library grid.
     */
    public static function handle_bulk_action(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Unauthorized.', 'bridgekit' ) );
        }

        check_admin_referer( 'bridgekit_bulk', 'bridgekit_nonce' );

        $action = sanitize_key( $_POST['bulk_action'] ?? '' );
        $ids    = array_map( 'absint', (array) ( $_POST['bulk_ids'] ?? [] ) );
        $ids    = array_filter( $ids );

        if ( empty( $ids ) || ! in_array( $action, [ 'delete', 'reimport' ], true ) ) {
            wp_redirect( admin_url( 'admin.php?page=bridgekit&error=invalid_bulk' ) );
            exit;
        }

        $count = 0;

        if ( 'delete' === $action ) {
            foreach ( $ids as $post_id ) {
                $post = get_post( $post_id );
                if ( ! $post || BRIDGEKIT_CPT !== $post->post_type ) {
                    continue;
                }

                // Delete stored ZIP
                $zip_path = get_post_meta( $post_id, '_bk_zip_path', true );
                if ( $zip_path && file_exists( $zip_path ) ) {
                    wp_delete_file( $zip_path );
                }

                wp_delete_post( $post_id, true );
                $count++;
            }

            wp_redirect( admin_url( 'admin.php?page=bridgekit&bulk_deleted=' . $count ) );
            exit;
        }

        if ( 'reimport' === $action ) {
            foreach ( $ids as $post_id ) {
                $post = get_post( $post_id );
                if ( ! $post || BRIDGEKIT_CPT !== $post->post_type ) {
                    continue;
                }

                Library::update_status( $post_id, 'pending' );
                update_post_meta( $post_id, '_bk_import_error', '' );
                Library::add_log( $post_id, 'Bulk re-import queued by admin.' );

                $zip_path     = get_post_meta( $post_id, '_bk_zip_path', true );
                $download_url = get_post_meta( $post_id, '_bk_download_url', true );

                if ( $zip_path && file_exists( $zip_path ) ) {
                    wp_schedule_single_event( time() + $count, 'bridgekit_process_zip', [ $post_id, $zip_path ] );
                } elseif ( $download_url ) {
                    wp_schedule_single_event( time() + $count, 'bridgekit_process_import', [ $post_id, $download_url ] );
                }

                $count++;
            }

            if ( $count > 0 ) {
                spawn_cron();
            }

            wp_redirect( admin_url( 'admin.php?page=bridgekit&bulk_reimport=' . $count ) );
            exit;
        }
    }

    // ─── Settings Export / Import ─────────────────────────────

    /**
     * Export all BridgeKit settings as a JSON file download.
     */
    public static function handle_export_settings(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Unauthorized.', 'bridgekit' ) );
        }

        check_admin_referer( 'bridgekit_export_settings', 'bridgekit_nonce' );

        $settings = [
            'bridgekit_version'       => BRIDGEKIT_VERSION,
            'exported_at'             => current_time( 'mysql' ),
            'site_url'                => home_url(),
            'settings' => [
                'rate_limit'    => get_option( 'bridgekit_rate_limit', 10 ),
                'cleanup_hours' => get_option( 'bridgekit_cleanup_hours', 1 ),
                'auto_import'   => get_option( 'bridgekit_auto_import', '1' ),
                'keep_zip'      => get_option( 'bridgekit_keep_zip', '1' ),
            ],
        ];

        $filename = 'bridgekit-settings-' . gmdate( 'Y-m-d' ) . '.json';

        nocache_headers();
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Content-Length: ' . strlen( wp_json_encode( $settings, JSON_PRETTY_PRINT ) ) );

        echo wp_json_encode( $settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        exit;
    }

    /**
     * Import BridgeKit settings from a JSON file upload.
     */
    public static function handle_import_settings(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Unauthorized.', 'bridgekit' ) );
        }

        check_admin_referer( 'bridgekit_import_settings', 'bridgekit_nonce' );

        if ( empty( $_FILES['bridgekit_settings_file'] ) || $_FILES['bridgekit_settings_file']['error'] !== UPLOAD_ERR_OK ) {
            wp_redirect( admin_url( 'admin.php?page=bridgekit-settings&import_error=no_file' ) );
            exit;
        }

        $file = $_FILES['bridgekit_settings_file'];

        // Validate file type
        $ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
        if ( 'json' !== $ext ) {
            wp_redirect( admin_url( 'admin.php?page=bridgekit-settings&import_error=invalid_type' ) );
            exit;
        }

        // Read and parse
        $contents = file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
        $data     = json_decode( $contents, true );

        if ( ! is_array( $data ) || empty( $data['settings'] ) ) {
            wp_redirect( admin_url( 'admin.php?page=bridgekit-settings&import_error=invalid_json' ) );
            exit;
        }

        $s = $data['settings'];

        if ( isset( $s['rate_limit'] ) ) {
            update_option( 'bridgekit_rate_limit', max( 1, min( 100, absint( $s['rate_limit'] ) ) ) );
        }
        if ( isset( $s['cleanup_hours'] ) ) {
            update_option( 'bridgekit_cleanup_hours', max( 1, min( 168, absint( $s['cleanup_hours'] ) ) ) );
        }
        if ( isset( $s['auto_import'] ) ) {
            update_option( 'bridgekit_auto_import', $s['auto_import'] ? '1' : '0' );
        }
        if ( isset( $s['keep_zip'] ) ) {
            update_option( 'bridgekit_keep_zip', $s['keep_zip'] ? '1' : '0' );
        }

        wp_redirect( admin_url( 'admin.php?page=bridgekit-settings&imported_settings=1' ) );
        exit;
    }
}

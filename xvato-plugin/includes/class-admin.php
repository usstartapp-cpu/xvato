<?php
/**
 * Xvato — Admin Pages
 *
 * Registers WP Admin menu pages and enqueues assets.
 *
 * @package Xvato
 */

namespace Xvato;

defined( 'ABSPATH' ) || exit;

class Admin {

    /**
     * Initialize admin hooks.
     */
    public static function init(): void {
        add_action( 'admin_menu', [ self::class, 'register_menus' ] );
        add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ] );

        // Handle manual file upload
        add_action( 'admin_post_xvato_upload', [ self::class, 'handle_manual_upload' ] );

        // Handle re-import
        add_action( 'admin_post_xvato_reimport', [ self::class, 'handle_reimport' ] );

        // Handle delete
        add_action( 'admin_post_xvato_delete', [ self::class, 'handle_delete' ] );

        // Handle maintenance actions
        add_action( 'admin_post_xvato_clear_tmp', [ self::class, 'handle_clear_tmp' ] );
        add_action( 'admin_post_xvato_reset_failed', [ self::class, 'handle_reset_failed' ] );

        // Handle bulk actions
        add_action( 'admin_post_xvato_bulk', [ self::class, 'handle_bulk_action' ] );

        // Handle settings export/import
        add_action( 'admin_post_xvato_export_settings', [ self::class, 'handle_export_settings' ] );
        add_action( 'admin_post_xvato_import_settings', [ self::class, 'handle_import_settings' ] );
    }

    /**
     * Register admin menu pages.
     */
    public static function register_menus(): void {
        add_menu_page(
            __( 'Xvato', 'xvato' ),
            __( 'Xvato', 'xvato' ),
            'manage_options',
            'xvato',
            [ self::class, 'render_dashboard' ],
            XVATO_URL . 'assets/xvato-logo.png',
            65
        );

        add_submenu_page(
            'xvato',
            __( 'Template Library', 'xvato' ),
            __( 'Library', 'xvato' ),
            'manage_options',
            'xvato',
            [ self::class, 'render_dashboard' ]
        );

        add_submenu_page(
            'xvato',
            __( 'Xvato Settings', 'xvato' ),
            __( 'Settings', 'xvato' ),
            'manage_options',
            'xvato-settings',
            [ self::class, 'render_settings' ]
        );
    }

    /**
     * Enqueue admin CSS and JS.
     */
    public static function enqueue_assets( string $hook ): void {
        if ( ! str_contains( $hook, 'xvato' ) ) {
            return;
        }

        wp_enqueue_style(
            'xvato-admin',
            XVATO_URL . 'admin/css/admin.css',
            [],
            XVATO_VERSION
        );

        wp_enqueue_script(
            'xvato-admin',
            XVATO_URL . 'admin/js/admin.js',
            [],
            XVATO_VERSION,
            true
        );

        wp_localize_script( 'xvato-admin', 'xvatoAdmin', [
            'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
            'restUrl'   => rest_url( XVATO_REST_NAMESPACE ),
            'nonce'     => wp_create_nonce( 'xvato_admin' ),
            'restNonce' => wp_create_nonce( 'wp_rest' ),
        ] );
    }

    /**
     * Render the main dashboard (library grid).
     */
    public static function render_dashboard(): void {
        include XVATO_DIR . 'admin/views/dashboard.php';
    }

    /**
     * Render the settings page.
     */
    public static function render_settings(): void {
        include XVATO_DIR . 'admin/views/settings.php';
    }

    // ─── Action Handlers ──────────────────────────────────────

    public static function handle_manual_upload(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Unauthorized.', 'xvato' ) );
        }

        check_admin_referer( 'xvato_upload', 'xvato_nonce' );

        if ( empty( $_FILES['xvato_zip'] ) ) {
            wp_redirect( admin_url( 'admin.php?page=xvato&error=no_file' ) );
            exit;
        }

        $title = sanitize_text_field( $_POST['xvato_title'] ?? 'Manual Upload' );

        $zip_path = Downloader::from_upload( $_FILES['xvato_zip'] );
        if ( is_wp_error( $zip_path ) ) {
            wp_redirect( admin_url( 'admin.php?page=xvato&error=' . urlencode( $zip_path->get_error_message() ) ) );
            exit;
        }

        if ( ! Security::is_valid_zip( $zip_path ) ) {
            wp_delete_file( $zip_path );
            wp_redirect( admin_url( 'admin.php?page=xvato&error=invalid_zip' ) );
            exit;
        }

        $post_id = Library::create_entry( [
            'title'        => $title,
            'download_url' => '',
            'source_url'   => '',
            'category'     => sanitize_text_field( $_POST['xvato_category'] ?? '' ),
        ] );

        if ( is_wp_error( $post_id ) ) {
            wp_redirect( admin_url( 'admin.php?page=xvato&error=' . urlencode( $post_id->get_error_message() ) ) );
            exit;
        }

        update_post_meta( $post_id, '_bk_zip_path', $zip_path );

        $importer = new Importer();
        $importer->process_zip( $post_id, $zip_path );

        wp_redirect( admin_url( 'admin.php?page=xvato&imported=' . $post_id ) );
        exit;
    }

    public static function handle_reimport(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Unauthorized.', 'xvato' ) );
        }

        check_admin_referer( 'xvato_reimport', 'xvato_nonce' );

        $post_id = absint( $_GET['id'] ?? 0 );
        if ( ! $post_id ) {
            wp_redirect( admin_url( 'admin.php?page=xvato&error=invalid_id' ) );
            exit;
        }

        $zip_path = get_post_meta( $post_id, '_bk_zip_path', true );
        if ( ! $zip_path || ! file_exists( $zip_path ) ) {
            $download_url = get_post_meta( $post_id, '_bk_download_url', true );
            if ( $download_url ) {
                Library::update_status( $post_id, 'pending' );
                wp_schedule_single_event( time(), 'xvato_process_import', [ $post_id, $download_url ] );
                spawn_cron();
                wp_redirect( admin_url( 'admin.php?page=xvato&reimport=queued' ) );
                exit;
            }

            wp_redirect( admin_url( 'admin.php?page=xvato&error=no_zip' ) );
            exit;
        }

        Library::update_status( $post_id, 'pending' );
        update_post_meta( $post_id, '_bk_import_error', '' );
        update_post_meta( $post_id, '_bk_import_log', [] );

        $importer = new Importer();
        $importer->process_zip( $post_id, $zip_path );

        wp_redirect( admin_url( 'admin.php?page=xvato&reimport=' . $post_id ) );
        exit;
    }

    public static function handle_delete(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Unauthorized.', 'xvato' ) );
        }

        check_admin_referer( 'xvato_delete', 'xvato_nonce' );

        $post_id = absint( $_GET['id'] ?? 0 );
        if ( ! $post_id ) {
            wp_redirect( admin_url( 'admin.php?page=xvato&error=invalid_id' ) );
            exit;
        }

        $zip_path = get_post_meta( $post_id, '_bk_zip_path', true );
        if ( $zip_path && file_exists( $zip_path ) ) {
            wp_delete_file( $zip_path );
        }

        wp_delete_post( $post_id, true );

        wp_redirect( admin_url( 'admin.php?page=xvato&deleted=1' ) );
        exit;
    }

    public static function handle_clear_tmp(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Unauthorized.', 'xvato' ) );
        }

        check_admin_referer( 'xvato_clear_tmp', 'xvato_nonce' );

        $plugin = Xvato::instance();
        $plugin->cleanup_tmp_directory();

        $upload_dir = wp_upload_dir();
        $tmp_dir    = trailingslashit( $upload_dir['basedir'] ) . XVATO_TMP_DIR;

        if ( is_dir( $tmp_dir ) ) {
            $files = glob( $tmp_dir . '/*' );
            foreach ( $files as $file ) {
                if ( is_file( $file ) && ! in_array( basename( $file ), [ '.htaccess', 'index.php' ], true ) ) {
                    wp_delete_file( $file );
                }
                if ( is_dir( $file ) && ! in_array( basename( $file ), [ '.', '..' ], true ) ) {
                    Xvato::delete_directory( $file );
                }
            }
        }

        wp_redirect( admin_url( 'admin.php?page=xvato-settings&cleared=1' ) );
        exit;
    }

    public static function handle_reset_failed(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Unauthorized.', 'xvato' ) );
        }

        check_admin_referer( 'xvato_reset_failed', 'xvato_nonce' );

        $failed_posts = get_posts( [
            'post_type'      => XVATO_CPT,
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

            $download_url = get_post_meta( $post_id, '_bk_download_url', true );
            if ( $download_url ) {
                wp_schedule_single_event( time() + $count, 'xvato_process_import', [ $post_id, $download_url ] );
            }

            $count++;
        }

        if ( $count > 0 ) {
            spawn_cron();
        }

        wp_redirect( admin_url( 'admin.php?page=xvato-settings&reset=' . $count ) );
        exit;
    }

    // ─── Bulk Actions ─────────────────────────────────────────

    public static function handle_bulk_action(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Unauthorized.', 'xvato' ) );
        }

        check_admin_referer( 'xvato_bulk', 'xvato_nonce' );

        $action = sanitize_key( $_POST['bulk_action'] ?? '' );
        $ids    = array_map( 'absint', (array) ( $_POST['bulk_ids'] ?? [] ) );
        $ids    = array_filter( $ids );

        if ( empty( $ids ) || ! in_array( $action, [ 'delete', 'reimport' ], true ) ) {
            wp_redirect( admin_url( 'admin.php?page=xvato&error=invalid_bulk' ) );
            exit;
        }

        $count = 0;

        if ( 'delete' === $action ) {
            foreach ( $ids as $post_id ) {
                $post = get_post( $post_id );
                if ( ! $post || XVATO_CPT !== $post->post_type ) {
                    continue;
                }

                $zip_path = get_post_meta( $post_id, '_bk_zip_path', true );
                if ( $zip_path && file_exists( $zip_path ) ) {
                    wp_delete_file( $zip_path );
                }

                wp_delete_post( $post_id, true );
                $count++;
            }

            wp_redirect( admin_url( 'admin.php?page=xvato&bulk_deleted=' . $count ) );
            exit;
        }

        if ( 'reimport' === $action ) {
            foreach ( $ids as $post_id ) {
                $post = get_post( $post_id );
                if ( ! $post || XVATO_CPT !== $post->post_type ) {
                    continue;
                }

                Library::update_status( $post_id, 'pending' );
                update_post_meta( $post_id, '_bk_import_error', '' );
                Library::add_log( $post_id, 'Bulk re-import queued by admin.' );

                $zip_path     = get_post_meta( $post_id, '_bk_zip_path', true );
                $download_url = get_post_meta( $post_id, '_bk_download_url', true );

                if ( $zip_path && file_exists( $zip_path ) ) {
                    wp_schedule_single_event( time() + $count, 'xvato_process_zip', [ $post_id, $zip_path ] );
                } elseif ( $download_url ) {
                    wp_schedule_single_event( time() + $count, 'xvato_process_import', [ $post_id, $download_url ] );
                }

                $count++;
            }

            if ( $count > 0 ) {
                spawn_cron();
            }

            wp_redirect( admin_url( 'admin.php?page=xvato&bulk_reimport=' . $count ) );
            exit;
        }
    }

    // ─── Settings Export / Import ─────────────────────────────

    public static function handle_export_settings(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Unauthorized.', 'xvato' ) );
        }

        check_admin_referer( 'xvato_export_settings', 'xvato_nonce' );

        $settings = [
            'xvato_version'    => XVATO_VERSION,
            'exported_at'       => current_time( 'mysql' ),
            'site_url'          => home_url(),
            'settings' => [
                'rate_limit'    => get_option( 'xvato_rate_limit', 10 ),
                'cleanup_hours' => get_option( 'xvato_cleanup_hours', 1 ),
                'auto_import'   => get_option( 'xvato_auto_import', '1' ),
                'keep_zip'      => get_option( 'xvato_keep_zip', '1' ),
            ],
        ];

        $filename = 'xvato-settings-' . gmdate( 'Y-m-d' ) . '.json';

        nocache_headers();
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Content-Length: ' . strlen( wp_json_encode( $settings, JSON_PRETTY_PRINT ) ) );

        echo wp_json_encode( $settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        exit;
    }

    public static function handle_import_settings(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Unauthorized.', 'xvato' ) );
        }

        check_admin_referer( 'xvato_import_settings', 'xvato_nonce' );

        if ( empty( $_FILES['xvato_settings_file'] ) || $_FILES['xvato_settings_file']['error'] !== UPLOAD_ERR_OK ) {
            wp_redirect( admin_url( 'admin.php?page=xvato-settings&import_error=no_file' ) );
            exit;
        }

        $file = $_FILES['xvato_settings_file'];

        $ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
        if ( 'json' !== $ext ) {
            wp_redirect( admin_url( 'admin.php?page=xvato-settings&import_error=invalid_type' ) );
            exit;
        }

        $contents = file_get_contents( $file['tmp_name'] );
        $data     = json_decode( $contents, true );

        if ( ! is_array( $data ) || empty( $data['settings'] ) ) {
            wp_redirect( admin_url( 'admin.php?page=xvato-settings&import_error=invalid_json' ) );
            exit;
        }

        $s = $data['settings'];

        if ( isset( $s['rate_limit'] ) ) {
            update_option( 'xvato_rate_limit', max( 1, min( 100, absint( $s['rate_limit'] ) ) ) );
        }
        if ( isset( $s['cleanup_hours'] ) ) {
            update_option( 'xvato_cleanup_hours', max( 1, min( 168, absint( $s['cleanup_hours'] ) ) ) );
        }
        if ( isset( $s['auto_import'] ) ) {
            update_option( 'xvato_auto_import', $s['auto_import'] ? '1' : '0' );
        }
        if ( isset( $s['keep_zip'] ) ) {
            update_option( 'xvato_keep_zip', $s['keep_zip'] ? '1' : '0' );
        }

        wp_redirect( admin_url( 'admin.php?page=xvato-settings&imported_settings=1' ) );
        exit;
    }
}
